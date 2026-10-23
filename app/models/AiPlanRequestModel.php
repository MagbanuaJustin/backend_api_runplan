<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: AiPlanRequestModel
 *
 * Tracks structured plan-generation calls to Anthropic (`ai_plan_requests`)
 * without storing sensitive prompt text, and enforces the monthly call ceiling
 * configured in app/config/integrations.php (Spec Section 5.1 & 8).
 */
class AiPlanRequestModel extends Model {

    protected $table       = 'ai_plan_requests';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    public function monthly_count($provider = 'anthropic', ?DateTimeImmutable $now = null)
    {
        $ref         = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $month_start = $ref->format('Y-m-01 00:00:00');

        $row = $this->db->raw(
            'SELECT COUNT(*) AS c FROM ai_plan_requests WHERE provider = ? AND created_at >= ?',
            [(string) $provider, $month_start]
        )->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['c'] ?? 0);
    }

    public function create_request($user_id, $provider, $summary, $model_name = null, $schema_version = '1')
    {
        $this->db->raw(
            "INSERT INTO ai_plan_requests
                (user_id, plan_id, provider, status, schema_version, model_name, request_summary)
             VALUES (?, NULL, ?, 'pending', ?, ?, ?)",
            [
                (int) $user_id,
                mb_substr((string) $provider, 0, 30),
                mb_substr((string) $schema_version, 0, 16),
                $model_name !== null ? mb_substr((string) $model_name, 0, 80) : null,
                $summary !== null ? mb_substr((string) $summary, 0, 500) : null,
            ]
        );
        return (int) $this->db->last_id();
    }

    public function mark_outcome($request_id, $status, $plan_id = null, $error_category = null)
    {
        $allowed = ['pending', 'succeeded', 'failed', 'fallback'];
        $status  = in_array($status, $allowed, true) ? $status : 'failed';

        $this->db->raw(
            'UPDATE ai_plan_requests
                SET status = ?, plan_id = ?, error_category = ?
              WHERE id = ?',
            [
                $status,
                $plan_id !== null ? (int) $plan_id : null,
                $error_category !== null ? mb_substr((string) $error_category, 0, 60) : null,
                (int) $request_id,
            ]
        );
    }
}
