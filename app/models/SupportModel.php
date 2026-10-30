<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Runner problem reports and privacy-conscious support-console summaries.
 */
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

        return $row ? (string) $row['role'] : null;
    }

    public function list_reports($user_id, $is_admin = false, $limit = 100)
    {
        $limit = max(1, min(200, (int) $limit));
        $where = $is_admin ? '' : 'WHERE r.user_id = ?';
        $params = $is_admin ? [] : [(int) $user_id];

        return $this->db->raw(
            "SELECT r.id, r.user_id, u.email, r.category, r.subject, r.description,
                    r.page_path, r.status, r.priority, r.admin_note, r.created_at, r.updated_at
               FROM support_reports r
               JOIN users u ON u.id = r.user_id
               {$where}
              ORDER BY r.created_at DESC, r.id DESC
              LIMIT {$limit}",
            $params
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function create_report($user_id, array $values)
    {
        $this->db->table($this->table)->insert([
            'user_id' => (int) $user_id,
            'category' => $values['category'],
            'subject' => $values['subject'],
            'description' => $values['description'],
            'page_path' => $values['page_path'],
            'status' => 'open',
            'priority' => 'normal',
        ]);

        return (int) $this->db->last_id();
    }

    public function find_report($id)
    {
        $row = $this->db->raw(
            'SELECT id FROM support_reports WHERE id = ? LIMIT 1',
            [(int) $id]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function update_report($id, array $values)
    {
        $sets = [];
        $params = [];

        foreach (['status', 'priority', 'admin_note'] as $field) {
            if (array_key_exists($field, $values)) {
                $sets[] = "`{$field}` = ?";
                $params[] = $values[$field];
            }
        }

        if (!$sets) return;

        $sets[] = '`updated_at` = CURRENT_TIMESTAMP';
        $params[] = (int) $id;

        $this->db->raw(
            'UPDATE support_reports SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        );
    }

    public function console_data()
    {
        $accounts = $this->db->raw(
            "SELECT COUNT(*) AS users,
                    COALESCE(SUM(role = 'runner' AND status = 'active'), 0) AS active_runners
               FROM users"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $reports = $this->db->raw(
            "SELECT COUNT(*) AS reports,
                    COALESCE(SUM(status IN ('open','in_progress')), 0) AS open_reports
               FROM support_reports"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $runs = (int) $this->db->raw(
            "SELECT COUNT(*) FROM activities WHERE source = 'manual'"
        )->fetchColumn();

        $plans = (int) $this->db->raw(
            'SELECT COUNT(*) FROM training_plans'
        )->fetchColumn();

        $sessions = (int) $this->db->raw(
            'SELECT COUNT(*) FROM training_sessions'
        )->fetchColumn();

        $profiles = (int) $this->db->raw(
            'SELECT COUNT(*) FROM runner_profiles'
        )->fetchColumn();

        $security_events = (int) $this->db->raw(
            'SELECT COUNT(*) FROM auth_security_events'
        )->fetchColumn();

        $users = $this->db->raw(
            'SELECT id, email, role, status, created_at
               FROM users ORDER BY created_at DESC, id DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'totals' => [
                'users' => (int) ($accounts['users'] ?? 0),
                'active_runners' => (int) ($accounts['active_runners'] ?? 0),
                'reports' => (int) ($reports['reports'] ?? 0),
                'open_reports' => (int) ($reports['open_reports'] ?? 0),
                'runs' => $runs,
                'plans' => $plans,
                'sessions' => $sessions,
            ],
            'users' => $users,
            'activity' => $this->recent_activity(),
            'data_safety' => [
                'runner_profiles' => $profiles,
                'sessions' => $sessions,
                'reports' => (int) ($reports['reports'] ?? 0),
                'security_events' => $security_events,
            ],
        ];
    }

    private function recent_activity($limit = 40)
    {
        $events = $this->db->raw(
            "SELECT e.id, e.user_id, u.email, e.event_type, e.safe_metadata, e.created_at
               FROM auth_security_events e
               LEFT JOIN users u ON u.id = e.user_id
              ORDER BY e.created_at DESC, e.id DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $reports = $this->db->raw(
            "SELECT r.id, r.user_id, u.email, r.subject, r.created_at
               FROM support_reports r JOIN users u ON u.id = r.user_id
              ORDER BY r.created_at DESC, r.id DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];

        foreach ($events as $event) {
            $items[] = [
                'id' => (int) $event['id'],
                'kind' => 'security',
                'event_type' => (string) $event['event_type'],
                'email' => $event['email'] !== null ? (string) $event['email'] : null,
                'metadata' => !empty($event['safe_metadata'])
                    ? (json_decode($event['safe_metadata'], true) ?: [])
                    : [],
                'created_at' => (string) $event['created_at'],
            ];
        }

        foreach ($reports as $report) {
            $items[] = [
                'id' => (int) $report['id'],
                'kind' => 'report',
                'email' => (string) $report['email'],
                'subject' => (string) $report['subject'],
                'created_at' => (string) $report['created_at'],
            ];
        }

        usort($items, function ($a, $b) {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return array_slice($items, 0, $limit);
    }
}