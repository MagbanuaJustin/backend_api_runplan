<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: SupportModel
 * 
 * Automatically generated via CLI.
 */
class SupportModel extends Model
{
    protected $table = 'support_reports';
    protected $primary_key = 'id';

    private function count_rows($table, array $filters = [])
    {
        $query = $this->db
            ->table($table)
            ->select_count('id', 'total');

        if ($filters) {
            $query->where($filters);
        }

        $row = $query->get();

        return (int) ($row['total'] ?? 0);
    }

    private function sort_rows_descending(array &$rows, $date_field)
    {
        usort($rows, function ($a, $b) use ($date_field) {
            $date_order = strcmp(
                (string) ($b[$date_field] ?? ''),
                (string) ($a[$date_field] ?? '')
            );

            if ($date_order !== 0) {
                return $date_order;
            }

            return (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0);
        });
    }

    private function decode_metadata($metadata)
    {
        if (!is_string($metadata) || $metadata === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function role_for_user($user_id)
    {
        $row = $this->db
            ->table('users')
            ->select('role')
            ->where('id', (int) $user_id)
            ->where('status', 'active')
            ->limit(1)
            ->get();

        return $row['role'] ?? null;
    }

    public function list_reports($user_id, $is_admin)
    {
        $query = $this->db
            ->table('support_reports r')
            ->select(
                'r.id, r.user_id, u.email, r.category, r.subject, r.description,
                 r.page_path, r.status, r.priority, r.admin_note, r.created_at, r.updated_at'
            )
            ->left_join('users u', 'u.id = r.user_id');

        if (!$is_admin) {
            $query->where('r.user_id', (int) $user_id);
        }

        $reports = $query
            ->order_by('r.created_at', 'DESC')
            ->limit(200)
            ->get_all();

        $reports = $reports ?: [];
        $this->sort_rows_descending($reports, 'created_at');

        return $reports;
    }

    public function find_report($id)
    {
        return $this->db
            ->table('support_reports')
            ->where('id', (int) $id)
            ->limit(1)
            ->get() ?: null;
    }

    public function create_report($user_id, array $data)
    {
        $this->db->table('support_reports')->insert([
            'user_id' => (int) $user_id,
            'category' => $data['category'],
            'subject' => $data['subject'],
            'description' => $data['description'],
            'page_path' => $data['page_path'],
            'status' => 'open',
            'priority' => 'normal',
        ]);

        return (int) $this->db->last_id();
    }

    public function update_report($id, array $values)
    {
        $allowed = ['status', 'priority', 'admin_note'];
        $clean = array_intersect_key($values, array_flip($allowed));

        if (!$clean) {
            return false;
        }

        $clean['updated_at'] = gmdate('Y-m-d H:i:s');

        $this->db
            ->table('support_reports')
            ->where('id', (int) $id)
            ->update($clean);

        return true;
    }

    public function console_data()
    {
        $open_reports = $this->db
            ->table('support_reports')
            ->select_count('id', 'total')
            ->in('status', ['open', 'in_progress'])
            ->get();

        $totals = [
            'open_reports' => (int) ($open_reports['total'] ?? 0),
            'reports' => $this->count_rows('support_reports'),
            'active_runners' => $this->count_rows('users', [
                'role' => 'runner',
                'status' => 'active',
            ]),
            'users' => $this->count_rows('users'),
            'runs' => $this->count_rows('activities'),
            'plans' => $this->count_rows('training_plans'),
        ];

        $users = $this->db
            ->table('users')
            ->select('id, email, role, status, created_at, updated_at')
            ->order_by('created_at', 'DESC')
            ->limit(200)
            ->get_all();

        $users = $users ?: [];
        $this->sort_rows_descending($users, 'created_at');

        $report_events = $this->db
            ->table('support_reports r')
            ->select('r.id, r.created_at, u.email, r.subject')
            ->left_join('users u', 'u.id = r.user_id')
            ->order_by('r.created_at', 'DESC')
            ->limit(30)
            ->get_all() ?: [];

        $security_events = $this->db
            ->table('auth_security_events e')
            ->select('e.id, e.created_at, u.email, e.event_type, e.safe_metadata')
            ->left_join('users u', 'u.id = e.user_id')
            ->order_by('e.created_at', 'DESC')
            ->limit(30)
            ->get_all() ?: [];

        $activity = [];

        foreach ($report_events as $event) {
            $activity[] = [
                'kind' => 'report',
                'id' => (int) $event['id'],
                'created_at' => $event['created_at'],
                'email' => $event['email'] ?? null,
                'subject' => $event['subject'] ?? null,
                'event_type' => null,
                'metadata' => [],
            ];
        }

        foreach ($security_events as $event) {
            $activity[] = [
                'kind' => 'security',
                'id' => (int) $event['id'],
                'created_at' => $event['created_at'],
                'email' => $event['email'] ?? null,
                'subject' => null,
                'event_type' => $event['event_type'] ?? null,
                'metadata' => $this->decode_metadata($event['safe_metadata'] ?? null),
            ];
        }

        $this->sort_rows_descending($activity, 'created_at');
        $activity = array_slice($activity, 0, 30);

        return [
            'totals' => $totals,
            'users' => $users,
            'activity' => $activity,
            'data_safety' => [
                'runner_profiles' => $this->count_rows('runner_profiles'),
                'sessions' => $this->count_rows('training_sessions'),
                'reports' => $totals['reports'],
                'security_events' => $this->count_rows('auth_security_events'),
            ],
        ];
    }

    public function find_runner($user_id)
    {
        return $this->db
            ->table('users')
            ->select('id, email, role, status, created_at, updated_at')
            ->where('id', (int) $user_id)
            ->where('role', 'runner')
            ->limit(1)
            ->get() ?: null;
    }

    public function runner_details($user_id)
    {
        $user = $this->find_runner($user_id);

        if (!$user) {
            return null;
        }

        $profile = $this->db
            ->table('runner_profiles')
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->get() ?: null;

        if ($profile && isset($profile['available_days'])) {
            $days = trim((string) $profile['available_days']);
            $profile['available_days'] = $days === '' ? [] : explode(',', $days);
        }

        $plans = $this->db
            ->table('training_plans')
            ->select('id, goal, start_date, end_date, status, version, approved_at, created_at, updated_at')
            ->where('user_id', (int) $user_id)
            ->order_by('id', 'DESC')
            ->get_all() ?: [];

        $sessions = $this->db
            ->table('training_sessions')
            ->select(
                'id, plan_id, scheduled_date, planned_start_time, type, planned_distance_km,
                 planned_duration_seconds, status, skip_reason, notes, created_at'
            )
            ->where('user_id', (int) $user_id)
            ->order_by('scheduled_date', 'DESC')
            ->limit(1000)
            ->get_all() ?: [];

        $this->sort_rows_descending($sessions, 'scheduled_date');

        foreach ($plans as &$plan) {
            $plan['sessions'] = array_values(array_filter($sessions, function ($session) use ($plan) {
                return (int) $session['plan_id'] === (int) $plan['id'];
            }));
        }
        unset($plan);

        $activities = $this->db
            ->table('activities')
            ->select('id, session_id, activity_date, distance_km, duration_seconds, effort, notes, source, created_at')
            ->where('user_id', (int) $user_id)
            ->order_by('activity_date', 'DESC')
            ->limit(1000)
            ->get_all() ?: [];

        $this->sort_rows_descending($activities, 'activity_date');

        $integrations = $this->db
            ->table('integration_accounts')
            ->select('provider, scopes, connected_at')
            ->where('user_id', (int) $user_id)
            ->order_by('provider', 'ASC')
            ->get_all() ?: [];

        $reports = $this->db
            ->table('support_reports')
            ->select('id, category, subject, description, page_path, status, priority, admin_note, created_at')
            ->where('user_id', (int) $user_id)
            ->order_by('created_at', 'DESC')
            ->limit(100)
            ->get_all() ?: [];

        $this->sort_rows_descending($reports, 'created_at');

        $revisions = $this->db
            ->table('plan_revisions')
            ->select(
                'id, plan_id, version, previous_version, resulting_version, decision,
                 change_summary, reason, decision_timestamp, created_at'
            )
            ->where('user_id', (int) $user_id)
            ->order_by('created_at', 'DESC')
            ->limit(200)
            ->get_all() ?: [];

        $this->sort_rows_descending($revisions, 'created_at');

        return [
            'user' => $user,
            'profile' => $profile,
            'plans' => $plans,
            'sessions' => $sessions,
            'activities' => $activities,
            'integrations' => $integrations,
            'reports' => $reports,
            'revisions' => $revisions,
        ];
    }

    public function set_runner_status($user_id, $status)
    {
        $user = $this->find_runner($user_id);

        if (!$user) {
            return false;
        }

        if ($user['status'] === $status) {
            return true;
        }

        $this->db->transaction();

        try {
            $this->db
                ->table('users')
                ->where('id', (int) $user_id)
                ->where('role', 'runner')
                ->update([
                    'status' => (string) $status,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            if ($status === 'suspended') {
                $this->db
                    ->table('refresh_tokens')
                    ->where('user_id', (int) $user_id)
                    ->delete();
            }

            $this->db->commit();

            return true;
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    private function backup_snapshot()
    {
        // Explicit allowlist. Password hashes, OAuth tokens, push secrets,
        // refresh/reset tokens, identity subjects, and server secrets are omitted.
        $tables = [
            'users',
            'runner_profiles',
            'training_plans',
            'training_sessions',
            'activities',
            'plan_revisions',
            'support_reports',
            'notification_preferences',
            'notification_jobs',
            'calendar_event_mappings',
            'integration_accounts',
            'ai_plan_requests',
            'ai_feedback',
            'auth_security_events',
            'settings',
        ];

        $snapshot = [
            'format' => 'runplan-data-snapshot-v1',
            'created_at_utc' => gmdate('c'),
            'tables' => [],
            'excluded' => [
                'password hashes',
                'refresh and password reset tokens',
                'OAuth token ciphertext',
                'Google identity subjects',
                'push subscription endpoints and keys',
                'server environment secrets',
            ],
        ];

        foreach ($tables as $table) {
            $query = $this->db->table($table);

            if ($table === 'users') {
                $query->select('id, email, role, status, created_at, updated_at');
            } elseif ($table === 'integration_accounts') {
                $query->select('user_id, provider, scopes, connected_at');
            }

            $snapshot['tables'][$table] = $query
                ->order_by('id', 'ASC')
                ->get_all() ?: [];
        }

        return $snapshot;
    }

    public function create_backup($created_by)
    {
        $snapshot = $this->backup_snapshot();
        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
                | JSON_PRETTY_PRINT
                | JSON_THROW_ON_ERROR
        );

        $size = strlen($json);

        if ($size > 8000000) {
            throw new RuntimeException(
                'Snapshot is larger than the 8 MB limit. Download/export smaller data sets instead.'
            );
        }

        $filename = 'runplan-snapshot-' . gmdate('Ymd-His') . '.json';

        $this->db->table('support_data_backups')->insert([
            'created_by' => (int) $created_by,
            'filename' => $filename,
            'size_bytes' => $size,
            'snapshot_json' => $json,
        ]);

        $id = (int) $this->db->last_id();

        $backup_rows = $this->db
            ->table('support_data_backups')
            ->select('id')
            ->order_by('id', 'DESC')
            ->get_all() ?: [];

        $backup_ids = array_column($backup_rows, 'id');
        $old_ids = array_slice($backup_ids, 14);

        if ($old_ids) {
            $this->db
                ->table('support_data_backups')
                ->in('id', array_map('intval', $old_ids))
                ->delete();
        }

        return [
            'id' => $id,
            'filename' => $filename,
            'size_bytes' => $size,
        ];
    }

    public function list_backups()
    {
        return $this->db
            ->table('support_data_backups b')
            ->select('b.id, b.filename, b.size_bytes, b.created_at, u.email AS created_by_email')
            ->left_join('users u', 'u.id = b.created_by')
            ->order_by('b.id', 'DESC')
            ->limit(14)
            ->get_all() ?: [];
    }

    public function find_backup($id)
    {
        $row = $this->db
            ->table('support_data_backups')
            ->select('filename, snapshot_json')
            ->where('id', (int) $id)
            ->limit(1)
            ->get();

        if (!$row) {
            return null;
        }

        $snapshot = json_decode((string) $row['snapshot_json'], true);

        if (!is_array($snapshot)) {
            return null;
        }

        return [
            'filename' => $row['filename'],
            'snapshot' => $snapshot,
        ];
    }
}