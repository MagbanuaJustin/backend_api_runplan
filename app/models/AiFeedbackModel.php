<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: AiFeedbackModel
 *
 * Persists first-party metric snapshots and validated Anthropic training
 * explanations in `ai_feedback` (Spec Module 3, Section 5.1 & 8, TC-14, TC-15).
 */
class AiFeedbackModel extends Model {

    protected $table       = 'ai_feedback';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    const DISCLAIMER = 'Informational training summary based only on your logged RunPlan activities. Not medical advice or a substitute for a qualified coach or clinician.';

    public function monthly_count(?DateTimeImmutable $now = null)
    {
        $ref         = $now ?: new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $month_start = $ref->format('Y-m-01 00:00:00');

        $row = $this->db->raw(
            "SELECT COUNT(*) AS c FROM ai_feedback WHERE provider_status = 'ok' AND created_at >= ?",
            [$month_start]
        )->fetch(PDO::FETCH_ASSOC);

        return (int) ($row['c'] ?? 0);
    }

    public function find_for_period($user_id, $period_start)
    {
        $row = $this->db->raw(
            'SELECT * FROM ai_feedback WHERE user_id = ? AND period_start = ? LIMIT 1',
            [(int) $user_id, (string) $period_start]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->present($row) : null;
    }

    public function save_feedback(
        $user_id,
        $period_start,
        $period_end,
        array $metrics_snapshot,
        ?array $feedback_struct,
        $provider_status = 'ok',
        $model_name = null,
        $prompt_version = '1'
    ) {
        $uid       = (int) $user_id;
        $snap_json = json_encode($metrics_snapshot, JSON_UNESCAPED_SLASHES);
        $fb_json   = $feedback_struct !== null
            ? json_encode($feedback_struct, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
        $p_status  = mb_substr((string) $provider_status, 0, 30);
        $m_name    = $model_name !== null ? mb_substr((string) $model_name, 0, 80) : null;
        $p_ver     = mb_substr((string) $prompt_version, 0, 16);

        $existing = $this->db->raw(
            'SELECT id FROM ai_feedback WHERE user_id = ? AND period_start = ? LIMIT 1',
            [$uid, (string) $period_start]
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $this->db->raw(
                'UPDATE ai_feedback
                    SET period_end = ?, metrics_snapshot = ?, feedback_text = ?,
                        provider_status = ?, model_name = ?, prompt_version = ?,
                        created_at = CURRENT_TIMESTAMP
                  WHERE id = ?',
                [(string) $period_end, $snap_json, $fb_json, $p_status, $m_name, $p_ver, (int) $existing['id']]
            );
            $id = (int) $existing['id'];
        } else {
            $this->db->raw(
                'INSERT INTO ai_feedback
                    (user_id, period_start, period_end, metrics_snapshot, feedback_text,
                     provider_status, model_name, prompt_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$uid, (string) $period_start, (string) $period_end, $snap_json, $fb_json, $p_status, $m_name, $p_ver]
            );
            $id = (int) $this->db->last_id();
        }

        $saved = $this->db->raw('SELECT * FROM ai_feedback WHERE id = ? LIMIT 1', [$id])->fetch(PDO::FETCH_ASSOC);
        return $saved ? $this->present($saved) : null;
    }

    public function present(array $row)
    {
        $metrics  = json_decode((string) ($row['metrics_snapshot'] ?? '{}'), true);
        $feedback = null;

        if (!empty($row['feedback_text'])) {
            $decoded = json_decode((string) $row['feedback_text'], true);
            if (is_array($decoded)) {
                $feedback = $decoded;
            } else {
                $feedback = ['headline' => 'Training summary', 'cautious_takeaway' => (string) $row['feedback_text']];
            }
        }

        return [
            'id'              => (int) $row['id'],
            'period_start'    => $row['period_start'],
            'period_end'      => $row['period_end'],
            'provider_status' => $row['provider_status'],
            'model_name'      => $row['model_name'] ?? null,
            'prompt_version'  => $row['prompt_version'] ?? '1',
            'created_at'      => $row['created_at'],
            'metrics'         => is_array($metrics) ? $metrics : [],
            'feedback'        => $feedback,
            'disclaimer'      => self::DISCLAIMER,
        ];
    }
}
