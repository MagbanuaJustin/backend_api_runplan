<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: SessionModel
 *
 * Dedicated data-access boundary for `training_sessions` on a runner's active
 * plan: lookup, overdue missed-session marking, rescheduling, and status
 * transitions (`planned` <-> `skipped` with optional `skip_reason`).
 */
class SessionModel extends Model {

    protected $table       = 'training_sessions';
    protected $primary_key = 'id';
    protected $timestamps  = true;

    public function find_for_user($user_id, $session_id)
    {
        $row = $this->db->raw(
            "SELECT s.id, s.plan_id, s.scheduled_date, s.planned_start_time, s.type,
                    s.planned_distance_km, s.planned_duration_seconds,
                    s.status, s.skip_reason, s.notes
               FROM training_sessions s
               JOIN training_plans p ON p.id = s.plan_id
              WHERE s.id = ? AND s.user_id = ? AND p.status = 'active'
              LIMIT 1",
            [(int) $session_id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'id'                       => (int) $row['id'],
            'plan_id'                  => (int) $row['plan_id'],
            'scheduled_date'           => $row['scheduled_date'],
            'planned_start_time'       => $row['planned_start_time'] ?? null,
            'type'                     => $row['type'],
            'planned_distance_km'      => $row['planned_distance_km'] !== null ? (float) $row['planned_distance_km'] : null,
            'planned_duration_seconds' => $row['planned_duration_seconds'] !== null ? (int) $row['planned_duration_seconds'] : null,
            'status'                   => $row['status'],
            'skip_reason'              => $row['skip_reason'] ?? null,
            'notes'                    => $row['notes'],
        ];
    }

    /**
     * Marks planned sessions older than yesterday (UTC) on the active plan as
     * `missed`. Uses portable ISO-date comparison so MySQL and SQLite tests
     * behave identically.
     */
    public function mark_missed_for_user($user_id, ?DateTimeImmutable $today = null)
    {
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $cutoff = $ref->modify('-1 day')->format('Y-m-d');

        $this->db->raw(
            "UPDATE training_sessions
                SET status = 'missed', updated_at = CURRENT_TIMESTAMP
              WHERE user_id = ?
                AND status = 'planned'
                AND scheduled_date < ?
                AND plan_id IN (
                    SELECT id FROM training_plans WHERE user_id = ? AND status = 'active'
                )",
            [(int) $user_id, $cutoff, (int) $user_id]
        );
    }

    /**
     * Reschedules a non-completed session on the active plan and records a
     * `plan_revisions` entry.
     *
     * @return array [session|null, [http_status, message]|null]
     */
    public function reschedule($user_id, $session_id, $new_date)
    {
        $lava = lava_instance();
        $lava->call->model('PlanModel');
        return $lava->PlanModel->reschedule_session((int) $user_id, (int) $session_id, (string) $new_date);
    }

    /**
     * Updates session status (`planned` or `skipped`) with optional `skip_reason`.
     * Completed sessions are protected and return `false`.
     *
     * @return array|false|null
     */
    public function set_status($user_id, $session_id, $status, $skip_reason = null)
    {
        $lava = lava_instance();
        $lava->call->model('PlanModel');
        return $lava->PlanModel->set_session_status((int) $user_id, (int) $session_id, (string) $status, $skip_reason);
    }
}
