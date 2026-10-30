<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SupportModel extends Model
{
    protected $table = 'support_reports';
    protected $primary_key = 'id';

    public function role_for_user($user_id)
    {
        $row = $this->db->raw(
            "SELECT role FROM users WHERE id = ? AND status = 'active' LIMIT 1",
            [(int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);
        return $row['role'] ?? null;
    }

    public function list_reports($user_id, $is_admin)
    {
        $sql = "SELECT r.id, r.user_id, u.email, r.category, r.subject, r.description,
                       r.page_path, r.status, r.priority, r.admin_note, r.created_at, r.updated_at
                  FROM support_reports r
                  LEFT JOIN users u ON u.id = r.user_id";
        $args = [];
        if (!$is_admin) {
            $sql .= ' WHERE r.user_id = ?';
            $args[] = (int) $user_id;
        }
        $sql .= ' ORDER BY r.created_at DESC, r.id DESC LIMIT 200';
        return $this->db->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find_report($id)
    {
        return $this->db->raw(
            'SELECT * FROM support_reports WHERE id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
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
        if (!$clean) return false;
        $clean['updated_at'] = gmdate('Y-m-d H:i:s');
        $this->db->table('support_reports')->where('id', (int) $id)->update($clean);
        return true;
    }

    public function console_data()
    {
        $count = function ($sql) {
            return (int) $this->db->raw($sql)->fetchColumn();
        };

        $totals = [
            'open_reports' => $count("SELECT COUNT(*) FROM support_reports WHERE status IN ('open','in_progress')"),
            'reports' => $count('SELECT COUNT(*) FROM support_reports'),
            'active_runners' => $count("SELECT COUNT(*) FROM users WHERE role = 'runner' AND status = 'active'"),
            'users' => $count('SELECT COUNT(*) FROM users'),
            'runs' => $count('SELECT COUNT(*) FROM activities'),
            'plans' => $count('SELECT COUNT(*) FROM training_plans'),
        ];

        $users = $this->db->raw(
            'SELECT id, email, role, status, created_at, updated_at
               FROM users ORDER BY created_at DESC, id DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $activity = $this->db->raw(
            "SELECT 'report' AS kind, r.id, r.created_at, u.email, r.subject, NULL AS event_type, NULL AS metadata
               FROM support_reports r LEFT JOIN users u ON u.id = r.user_id
             UNION ALL
             SELECT 'security' AS kind, e.id, e.created_at, u.email, NULL AS subject, e.event_type,
                    e.safe_metadata AS metadata
               FROM auth_security_events e LEFT JOIN users u ON u.id = e.user_id
             ORDER BY created_at DESC LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($activity as &$item) {
            if (!empty($item['metadata']) && is_string($item['metadata'])) {
                $decoded = json_decode($item['metadata'], true);
                $item['metadata'] = is_array($decoded) ? $decoded : [];
            } else {
                $item['metadata'] = [];
            }
        }
        unset($item);

        return [
            'totals' => $totals,
            'users' => $users,
            'activity' => $activity,
            'data_safety' => [
                'runner_profiles' => $count('SELECT COUNT(*) FROM runner_profiles'),
                'sessions' => $count('SELECT COUNT(*) FROM training_sessions'),
                'reports' => $count('SELECT COUNT(*) FROM support_reports'),
                'security_events' => $count('SELECT COUNT(*) FROM auth_security_events'),
            ],
        ];
    }

    public function find_runner($user_id)
    {
        return $this->db->raw(
            "SELECT id, email, role, status, created_at, updated_at
               FROM users WHERE id = ? AND role = 'runner' LIMIT 1",
            [(int) $user_id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function runner_details($user_id)
    {
        $user = $this->find_runner($user_id);
        if (!$user) return null;

        $profile = $this->db->raw(
            'SELECT * FROM runner_profiles WHERE user_id = ? LIMIT 1',
            [(int) $user_id]
        )->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($profile && isset($profile['available_days'])) {
            $days = trim((string) $profile['available_days']);
            $profile['available_days'] = $days === '' ? [] : explode(',', $days);
        }

        $plans = $this->db->raw(
            'SELECT id, goal, start_date, end_date, status, version, approved_at, created_at, updated_at
               FROM training_plans WHERE user_id = ? ORDER BY id DESC',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $sessions = $this->db->raw(
            'SELECT id, plan_id, scheduled_date, planned_start_time, type, planned_distance_km,
                    planned_duration_seconds, status, skip_reason, notes, created_at
               FROM training_sessions WHERE user_id = ? ORDER BY scheduled_date DESC, id DESC LIMIT 1000',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($plans as &$plan) {
            $plan['sessions'] = array_values(array_filter($sessions, function ($session) use ($plan) {
                return (int) $session['plan_id'] === (int) $plan['id'];
            }));
        }
        unset($plan);

        $activities = $this->db->raw(
            'SELECT id, session_id, activity_date, distance_km, duration_seconds, effort, notes, source, created_at
               FROM activities WHERE user_id = ? ORDER BY activity_date DESC, id DESC LIMIT 1000',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $integrations = $this->db->raw(
            'SELECT provider, scopes, connected_at FROM integration_accounts
              WHERE user_id = ? ORDER BY provider',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reports = $this->db->raw(
            'SELECT id, category, subject, description, page_path, status, priority, admin_note, created_at
               FROM support_reports WHERE user_id = ? ORDER BY created_at DESC LIMIT 100',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $revisions = $this->db->raw(
            'SELECT id, plan_id, version, previous_version, resulting_version, decision,
                    change_summary, reason, decision_timestamp, created_at
               FROM plan_revisions WHERE user_id = ? ORDER BY created_at DESC LIMIT 200',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

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
        if (!$user) return false;
        if ($user['status'] === $status) return true;

        $this->db->transaction();
        try {
            $this->db->raw(
                'UPDATE users SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role = ?',
                [(string) $status, (int) $user_id, 'runner']
            );
            if ($status === 'suspended') {
                $this->db->raw('DELETE FROM refresh_tokens WHERE user_id = ?', [(int) $user_id]);
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
        $queries = [
            'users' => "SELECT id,email,role,status,created_at,updated_at FROM users ORDER BY id",
            'runner_profiles' => 'SELECT * FROM runner_profiles ORDER BY id',
            'training_plans' => 'SELECT * FROM training_plans ORDER BY id',
            'training_sessions' => 'SELECT * FROM training_sessions ORDER BY id',
            'activities' => 'SELECT * FROM activities ORDER BY id',
            'plan_revisions' => 'SELECT * FROM plan_revisions ORDER BY id',
            'support_reports' => 'SELECT * FROM support_reports ORDER BY id',
            'notification_preferences' => 'SELECT * FROM notification_preferences ORDER BY id',
            'notification_jobs' => 'SELECT * FROM notification_jobs ORDER BY id',
            'calendar_event_mappings' => 'SELECT * FROM calendar_event_mappings ORDER BY id',
            'integration_accounts' => 'SELECT user_id,provider,scopes,connected_at FROM integration_accounts ORDER BY id',
            'ai_plan_requests' => 'SELECT * FROM ai_plan_requests ORDER BY id',
            'ai_feedback' => 'SELECT * FROM ai_feedback ORDER BY id',
            'auth_security_events' => 'SELECT * FROM auth_security_events ORDER BY id',
            'settings' => 'SELECT * FROM settings ORDER BY id',
        ];

        $snapshot = [
            'format' => 'runplan-data-snapshot-v1',
            'created_at_utc' => gmdate('c'),
            'tables' => [],
            'excluded' => [
                'password hashes', 'refresh and password reset tokens', 'OAuth token ciphertext',
                'Google identity subjects', 'push subscription endpoints and keys', 'server environment secrets',
            ],
        ];
        foreach ($queries as $table => $sql) {
            $snapshot['tables'][$table] = $this->db->raw($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return $snapshot;
    }

    public function create_backup($created_by)
    {
        $snapshot = $this->backup_snapshot();
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $size = strlen($json);
        if ($size > 8000000) {
            throw new RuntimeException('Snapshot is larger than the 8 MB limit. Download/export smaller data sets instead.');
        }

        $filename = 'runplan-snapshot-' . gmdate('Ymd-His') . '.json';
        $this->db->table('support_data_backups')->insert([
            'created_by' => (int) $created_by,
            'filename' => $filename,
            'size_bytes' => $size,
            'snapshot_json' => $json,
        ]);
        $id = (int) $this->db->last_id();

        $ids = $this->db->raw(
            'SELECT id FROM support_data_backups ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach (array_slice($ids, 14) as $old_id) {
            $this->db->raw('DELETE FROM support_data_backups WHERE id = ?', [(int) $old_id]);
        }
        return ['id' => $id, 'filename' => $filename, 'size_bytes' => $size];
    }

    public function list_backups()
    {
        return $this->db->raw(
            'SELECT b.id, b.filename, b.size_bytes, b.created_at, u.email AS created_by_email
               FROM support_data_backups b LEFT JOIN users u ON u.id = b.created_by
              ORDER BY b.id DESC LIMIT 14'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function find_backup($id)
    {
        $row = $this->db->raw(
            'SELECT filename, snapshot_json FROM support_data_backups WHERE id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $snapshot = json_decode((string) $row['snapshot_json'], true);
        if (!is_array($snapshot)) return null;
        return ['filename' => $row['filename'], 'snapshot' => $snapshot];
    }
}