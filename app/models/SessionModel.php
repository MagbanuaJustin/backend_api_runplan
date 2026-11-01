<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: SessionModel
 * 
 * Automatically generated via CLI.
 */
class SessionModel extends Model
{
    protected $table = 'training_sessions';
    protected $primary_key = 'id';
    protected $timestamps = true;

    public function find_for_user($user_id, $session_id)
    {
        $row = $this->db
            ->table($this->table . ' s')
            ->select(
                's.id, s.plan_id, s.scheduled_date, s.planned_start_time, s.type, ' .
                's.planned_distance_km, s.planned_duration_seconds, ' .
                's.status, s.skip_reason, s.notes'
            )
            ->join('training_plans p', 'p.id = s.plan_id')
            ->where('s.id', (int) $session_id)
            ->where('s.user_id', (int) $user_id)
            ->where('p.status', 'active')
            ->limit(1)
            ->get();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'plan_id' => (int) $row['plan_id'],
            'scheduled_date' => $row['scheduled_date'],
            'planned_start_time' => $row['planned_start_time'] ?? null,
            'type' => $row['type'],
            'planned_distance_km' => $row['planned_distance_km'] !== null
                ? (float) $row['planned_distance_km']
                : null,
            'planned_duration_seconds' => $row['planned_duration_seconds'] !== null
                ? (int) $row['planned_duration_seconds']
                : null,
            'status' => $row['status'],
            'skip_reason' => $row['skip_reason'] ?? null,
            'notes' => $row['notes'],
        ];
    }

    /**
     * Mark planned sessions older than yesterday (UTC) on active plans as missed.
     */
    public function mark_missed_for_user($user_id, ?DateTimeImmutable $today = null)
    {
        $reference = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $cutoff = $reference->modify('-1 day')->format('Y-m-d');
        $userId = (int) $user_id;

        $activePlans = $this->db
            ->table('training_plans')
            ->select('id')
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->get_all();

        $planIds = array_map('intval', array_column($activePlans ?: [], 'id'));

        if (!$planIds) {
            return;
        }

        $this->db
            ->table($this->table)
            ->where('user_id', $userId)
            ->where('status', 'planned')
            ->where('scheduled_date', '<', $cutoff)
            ->in('plan_id', $planIds)
            ->update([
                'status' => 'missed',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Reschedule a non-completed session on the active plan and record a
     * plan_revisions entry.
     *
     * @return array [session|null, [http_status, message]|null]
     */
    public function reschedule($user_id, $session_id, $new_date)
    {
        $lava = lava_instance();
        $lava->call->model('PlanModel');

        return $lava->PlanModel->reschedule_session(
            (int) $user_id,
            (int) $session_id,
            (string) $new_date
        );
    }

    /**
     * Update session status (planned or skipped) with optional skip_reason.
     * Completed sessions are protected and return false.
     *
     * @return array|false|null
     */
    public function set_status($user_id, $session_id, $status, $skip_reason = null)
    {
        $lava = lava_instance();
        $lava->call->model('PlanModel');

        return $lava->PlanModel->set_session_status(
            (int) $user_id,
            (int) $session_id,
            (string) $status,
            $skip_reason
        );
    }
}