<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__) . '/libraries/AnthropicService.php';

/**
 * Model: AiCoachingModel
 *
 * Persists AI / rule-based weekly coaching feedback (`ai_feedback`) and plan
 * generation audit rows (`ai_plan_requests`), enforcing:
 *   1. Strict `source = 'manual'` first-party data boundary (never non-manual runs).
 *   2. Monthly outbound Anthropic call ceiling (`ANTHROPIC_MONTHLY_CALL_CAP`).
 *   3. Automatic deterministic coaching fallback when Anthropic is disabled,
 *      over cap, or unreachable (Spec 5.1 & 7.3 / NFR-04).
 */
class AiCoachingModel extends Model {

    protected $table       = 'ai_feedback';
    protected $primary_key = 'id';

    public function latest_feedback_for_user($user_id)
    {
        $row = $this->db->raw(
            'SELECT id, period_start, period_end, metrics_snapshot,
                    feedback_text, provider_status, created_at
               FROM ai_feedback
              WHERE user_id = ?
              ORDER BY period_start DESC, id DESC
              LIMIT 1',
            array((int) $user_id)
        )->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->present_feedback($row) : null;
    }

    /**
     * Generates (or refreshes) the runner's 7-day coaching review, calling
     * Anthropic Claude when ready + under monthly cap, or falling back to
     * deterministic rule-based coaching otherwise.
     */
    public function generate_weekly_feedback($user_id, ?DateTimeImmutable $today = null, ?AnthropicService $service = null)
    {
        $uid     = (int) $user_id;
        $ref     = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client  = $service ?: new AnthropicService();
        $metrics = $this->build_weekly_metrics($uid, $ref);

        if (!$client->is_ready()) {
            $text   = $this->deterministic_feedback($metrics);
            $status = 'not_configured';
        } elseif ($this->monthly_calls_used($ref) >= $client->monthly_cap()) {
            $text   = $this->deterministic_feedback($metrics);
            $status = 'cap_reached';
        } else {
            $res = $client->generate_weekly_feedback($metrics);
            if ($res['ok'] && !empty($res['text'])) {
                $text   = $res['text'];
                $status = 'ok';
            } else {
                $text   = $this->deterministic_feedback($metrics);
                $status = 'fallback';
            }
        }

        return $this->save_feedback(
            $uid,
            $metrics['period_start'],
            $metrics['period_end'],
            $metrics,
            $text,
            $status
        );
    }

    /**
     * Records a plan generation audit entry in `ai_plan_requests` and returns
     * a concise coaching brief (`['text' => string, 'provider_status' => string]`).
     * Distances and dates remain 100% governed by `PlanModel::build()`.
     */
    public function record_plan_generation($user_id, $plan_id, array $profile, array $built, ?DateTimeImmutable $today = null, ?AnthropicService $service = null)
    {
        $uid    = (int) $user_id;
        $pid    = $plan_id !== null ? (int) $plan_id : null;
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client = $service ?: new AnthropicService();

        $sessions = $built['sessions'] ?? array();
        $total_km = 0.0;
        $peak_km  = 0.0;
        foreach ($sessions as $s) {
            $km = (float) ($s['planned_distance_km'] ?? 0);
            $total_km += $km;
            if (($s['type'] ?? '') === 'long' && $km > $peak_km) {
                $peak_km = $km;
            }
        }

        $start_dt = new DateTimeImmutable($built['plan']['start_date'], new DateTimeZone('UTC'));
        $end_dt   = new DateTimeImmutable($built['plan']['end_date'], new DateTimeZone('UTC'));
        $weeks    = max(1, (int) ceil(((int) $start_dt->diff($end_dt)->days + 1) / 7));

        $summary = array(
            'goal'              => $built['plan']['goal'],
            'experience'        => $profile['experience'] ?? 'intermediate',
            'start_date'        => $built['plan']['start_date'],
            'end_date'          => $built['plan']['end_date'],
            'weeks'             => $weeks,
            'sessions_per_week' => (int) $built['plan']['sessions_per_week'],
            'total_km'          => round($total_km, 1),
            'peak_long_km'      => round($peak_km, 1),
        );

        $summary_line = sprintf(
            'goal=%s weeks=%d runs_wk=%d total_km=%.1f',
            $summary['goal'],
            $summary['weeks'],
            $summary['sessions_per_week'],
            $summary['total_km']
        );

        $req_status      = 'fallback';
        $provider_status = 'not_configured';
        $brief_text      = $this->deterministic_plan_brief($summary);

        if ($client->is_ready() && $this->monthly_calls_used($ref) < $client->monthly_cap()) {
            $res = $client->generate_plan_brief($summary);
            if ($res['ok'] && !empty($res['text'])) {
                $req_status      = 'succeeded';
                $provider_status = 'ok';
                $brief_text      = $res['text'];
            } else {
                $req_status      = 'failed';
                $provider_status = 'fallback';
            }
        }

        $this->db->table('ai_plan_requests')->insert(array(
            'user_id'         => $uid,
            'plan_id'         => $pid,
            'provider'        => 'anthropic',
            'status'          => $req_status,
            'request_summary' => mb_substr($summary_line, 0, 500),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ));

        return array(
            'text'            => $brief_text,
            'provider_status' => $provider_status,
        );
    }

    /**
     * Collects non-identifying first-party metrics for `[today - 6 days .. today]`.
     * Explicitly enforces `source = 'manual'`.
     */
    public function build_weekly_metrics($user_id, ?DateTimeImmutable $today = null)
    {
        $uid   = (int) $user_id;
        $ref   = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $start = $ref->modify('-6 days')->format('Y-m-d');
        $end   = $ref->format('Y-m-d');

        $profile = $this->db->raw(
            'SELECT goal, experience FROM runner_profiles WHERE user_id = ? LIMIT 1',
            array($uid)
        )->fetch(PDO::FETCH_ASSOC);

        $runs = $this->db->raw(
            "SELECT COUNT(*) AS runs_logged,
                    COALESCE(SUM(distance_km), 0) AS distance_km,
                    COALESCE(SUM(duration_seconds), 0) AS duration_seconds,
                    AVG(effort) AS avg_effort,
                    COALESCE(MAX(distance_km), 0) AS longest_run_km
               FROM activities
              WHERE user_id = ?
                AND source = 'manual'
                AND activity_date BETWEEN ? AND ?",
            array($uid, $start, $end)
        )->fetch(PDO::FETCH_ASSOC);

        $sess = $this->db->raw(
            "SELECT COALESCE(SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END), 0) AS completed_cnt,
                    COALESCE(SUM(CASE WHEN s.status = 'planned'   THEN 1 ELSE 0 END), 0) AS planned_cnt,
                    COALESCE(SUM(CASE WHEN s.status = 'skipped'   THEN 1 ELSE 0 END), 0) AS skipped_cnt,
                    COALESCE(SUM(CASE WHEN s.status = 'missed'    THEN 1 ELSE 0 END), 0) AS missed_cnt
               FROM training_sessions s
               JOIN training_plans p ON p.id = s.plan_id
              WHERE s.user_id = ?
                AND p.status = 'active'
                AND s.scheduled_date BETWEEN ? AND ?",
            array($uid, $start, $end)
        )->fetch(PDO::FETCH_ASSOC);

        return array(
            'period_start'       => $start,
            'period_end'         => $end,
            'goal'               => $profile['goal'] ?? 'general_fitness',
            'experience'         => $profile['experience'] ?? 'beginner',
            'runs_logged'        => (int) ($runs['runs_logged'] ?? 0),
            'distance_km'        => round((float) ($runs['distance_km'] ?? 0), 2),
            'duration_seconds'   => (int) ($runs['duration_seconds'] ?? 0),
            'avg_effort'         => isset($runs['avg_effort']) && $runs['avg_effort'] !== null
                ? round((float) $runs['avg_effort'], 1)
                : null,
            'longest_run_km'     => round((float) ($runs['longest_run_km'] ?? 0), 2),
            'sessions_completed' => (int) ($sess['completed_cnt'] ?? 0),
            'sessions_planned'   => (int) ($sess['planned_cnt'] ?? 0),
            'sessions_skipped'   => (int) ($sess['skipped_cnt'] ?? 0),
            'sessions_missed'    => (int) ($sess['missed_cnt'] ?? 0),
        );
    }

    public function monthly_calls_used(?DateTimeImmutable $today = null)
    {
        $ref         = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $month_start = $ref->format('Y-m-01') . ' 00:00:00';

        $plan_calls = (int) $this->db->raw(
            "SELECT COUNT(*) FROM ai_plan_requests
              WHERE provider = 'anthropic'
                AND status IN ('succeeded', 'failed')
                AND created_at >= ?",
            array($month_start)
        )->fetchColumn();

        $feedback_calls = (int) $this->db->raw(
            "SELECT COUNT(*) FROM ai_feedback
              WHERE provider_status = 'ok'
                AND created_at >= ?",
            array($month_start)
        )->fetchColumn();

        return $plan_calls + $feedback_calls;
    }

    private function save_feedback($user_id, $period_start, $period_end, array $metrics, $text, $provider_status)
    {
        $now_utc = gmdate('Y-m-d H:i:s');
        $json    = json_encode($metrics, JSON_UNESCAPED_SLASHES);

        $existing = $this->db->raw(
            'SELECT id FROM ai_feedback WHERE user_id = ? AND period_start = ? LIMIT 1',
            array((int) $user_id, $period_start)
        )->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $id = (int) $existing['id'];
            $this->db->raw(
                'UPDATE ai_feedback
                    SET period_end = ?,
                        metrics_snapshot = ?,
                        feedback_text = ?,
                        provider_status = ?,
                        created_at = ?
                  WHERE id = ? AND user_id = ?',
                array($period_end, $json, $text, $provider_status, $now_utc, $id, (int) $user_id)
            );
        } else {
            $this->db->table('ai_feedback')->insert(array(
                'user_id'          => (int) $user_id,
                'period_start'     => $period_start,
                'period_end'       => $period_end,
                'metrics_snapshot' => $json,
                'feedback_text'    => $text,
                'provider_status'  => $provider_status,
                'created_at'       => $now_utc,
            ));
            $id = (int) $this->db->last_id();
        }

        $row = $this->db->raw(
            'SELECT id, period_start, period_end, metrics_snapshot,
                    feedback_text, provider_status, created_at
               FROM ai_feedback WHERE id = ? LIMIT 1',
            array($id)
        )->fetch(PDO::FETCH_ASSOC);

        return $this->present_feedback($row);
    }

    private function present_feedback(array $row)
    {
        $decoded = json_decode((string) ($row['metrics_snapshot'] ?? ''), true);
        return array(
            'id'              => (int) $row['id'],
            'period_start'    => $row['period_start'],
            'period_end'      => $row['period_end'],
            'metrics'         => is_array($decoded) ? $decoded : array(),
            'feedback_text'   => $row['feedback_text'],
            'provider_status' => $row['provider_status'],
            'created_at'      => $row['created_at'],
        );
    }

    private function deterministic_feedback(array $m)
    {
        $runs    = (int) $m['runs_logged'];
        $km      = (float) $m['distance_km'];
        $longest = (float) $m['longest_run_km'];
        $skipped = (int) $m['sessions_skipped'];
        $missed  = (int) $m['sessions_missed'];
        $effort  = $m['avg_effort'];

        if ($runs === 0) {
            $opening = 'No manual runs were logged between ' . $m['period_start'] . ' and ' . $m['period_end'] . '.';
            $tips    = 'Focus next week on completing 2 short, conversational-pace runs first rather than trying to make up missed mileage all at once.';
            if ($missed > 0 || $skipped > 0) {
                $tips .= ' Consider proposing a plan revision on the Plan page if your weekly availability has shifted.';
            }
            return $opening . ' ' . $tips;
        }

        $summary = sprintf(
            'Over the past 7 days (%s to %s), you logged %d %s totaling %.1f km, with a longest effort of %.1f km.',
            $m['period_start'],
            $m['period_end'],
            $runs,
            $runs === 1 ? 'run' : 'runs',
            $km,
            $longest
        );

        $adherence = '';
        if ($missed > 0 || $skipped > 0) {
            $adherence = sprintf(
                ' You had %d skipped and %d missed scheduled %s — resist doubling up workouts to compensate.',
                $skipped,
                $missed,
                ($skipped + $missed) === 1 ? 'session' : 'sessions'
            );
        } else {
            $adherence = ' Consistency looked steady with zero missed workouts on your active schedule.';
        }

        $load_note = ' Keep easy days genuinely relaxed so you arrive fresh for your next long run.';
        if ($effort !== null && $effort >= 7.5) {
            $load_note = ' Your average perceived effort (' . $effort . '/10) ran fairly high, so dial back easy-day pace by 15–20 sec/km next week for better recovery.';
        }

        return $summary . $adherence . $load_note;
    }

    private function deterministic_plan_brief(array $s)
    {
        $goal_label = str_replace('_', ' ', (string) $s['goal']);
        return sprintf(
            'Built a %d-week %s progression at %d runs/week (%.1f km total volume, peaking with a %.1f km long run) with built-in cutback recovery every 4th week.',
            (int) $s['weeks'],
            $goal_label,
            (int) $s['sessions_per_week'],
            (float) $s['total_km'],
            (float) $s['peak_long_km']
        );
    }
}
