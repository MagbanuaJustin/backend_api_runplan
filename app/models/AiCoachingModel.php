<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__) . '/libraries/GeminiService.php';

/**
 * Model: AiCoachingModel
 *
 * Persists AI / rule-based weekly coaching feedback (`ai_feedback`) and plan
 * generation audit rows (`ai_plan_requests`), enforcing:
 *   1. Strict `source = 'manual'` first-party data boundary (never non-manual runs).
 *   2. Monthly outbound Gemini call ceiling (`GEMINI_MONTHLY_CALL_CAP`).
 *   3. Automatic deterministic coaching fallback when Gemini is disabled,
 *      over cap, or unreachable (Spec 5.1 & 7.3 / NFR-04).
 */
class AiCoachingModel extends Model {

    protected $table       = 'ai_feedback';
    protected $primary_key = 'id';

    /** @var bool|null true = tables verified, false = schema unusable */
    private static $schema_ok = null;

    /**
     * Verifies (and, on a fresh or restored database, creates) the two AI
     * audit tables before they are queried.
     *
     * Why this exists: the dashboard's coaching card is the only screen that
     * touches `ai_feedback` / `ai_plan_requests`. On a deployment where
     * migration 008 was never applied, that card used to take the whole card
     * down with an opaque server error. Checking once per request is cheap and
     * keeps the core dashboard working with no AI tables at all (NFR-04).
     */
    private function ensure_tables()
    {
        if (self::$schema_ok === true) {
            return;
        }

        try {
            $this->db->raw('SELECT 1 FROM ai_feedback LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            $this->db->raw('SELECT 1 FROM ai_plan_requests LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            self::$schema_ok = true;
            return;
        } catch (Throwable $e) {
            self::$schema_ok = false;
        }

        // Mirrors migration 008. Every statement is CREATE TABLE IF NOT EXISTS,
        // so re-running it on a healthy database is a no-op.
        try {
            $this->db->raw(
                'CREATE TABLE IF NOT EXISTS ai_plan_requests (
                    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT(11) UNSIGNED NOT NULL,
                    plan_id INT(11) UNSIGNED NULL DEFAULT NULL,
                    provider VARCHAR(30) NOT NULL,
                    status ENUM(\'pending\',\'succeeded\',\'failed\',\'fallback\') NOT NULL DEFAULT \'pending\',
                    request_summary VARCHAR(500) NULL DEFAULT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY user_created_idx (user_id, created_at),
                    KEY plan_id_idx (plan_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $this->db->raw(
                'CREATE TABLE IF NOT EXISTS ai_feedback (
                    id INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id INT(11) UNSIGNED NOT NULL,
                    period_start DATE NOT NULL,
                    period_end DATE NOT NULL,
                    metrics_snapshot JSON NOT NULL,
                    feedback_text TEXT NULL,
                    provider_status VARCHAR(30) NOT NULL DEFAULT \'ok\',
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY user_period_unique (user_id, period_start)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );

            $this->db->raw('SELECT 1 FROM ai_feedback LIMIT 1')->fetch(PDO::FETCH_ASSOC);
            self::$schema_ok = true;
        } catch (Throwable $e) {
            // Non-MySQL driver or no CREATE privilege: callers degrade to an
            // unsaved, in-memory coaching review instead of failing the page.
            self::$schema_ok = false;
        }
    }

    public function latest_feedback_for_user($user_id)
    {
        $this->ensure_tables();

        try {
            $row = $this->db->raw(
                'SELECT id, period_start, period_end, metrics_snapshot,
                        feedback_text, provider_status, created_at
                   FROM ai_feedback
                  WHERE user_id = ?
                  ORDER BY period_start DESC, id DESC
                  LIMIT 1',
                array((int) $user_id)
            )->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return null;
        }

        return $row ? $this->present_feedback($row) : null;
    }

    /**
     * Generates (or refreshes) the runner's 7-day coaching review, calling
     * Google Gemini when ready + under monthly cap, or falling back to
     * deterministic rule-based coaching otherwise.
     */
    public function generate_weekly_feedback($user_id, ?DateTimeImmutable $today = null, ?GeminiService $service = null)
    {
        $this->ensure_tables();

        $uid     = (int) $user_id;
        $ref     = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client  = $service ?: new GeminiService();
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

        return $this->persist_or_degrade(
            $uid,
            $metrics,
            $text,
            $status
        );
    }

    /**
     * Answers an unsaved chat question using only a short window of this
     * runner's first-party training metrics. Message contents are not logged.
     */
    public function answer_chat_question($user_id, $question, ?DateTimeImmutable $today = null, ?GeminiService $service = null)
    {
        $this->ensure_tables();

        $uid = (int) $user_id;
        $question = trim((string) $question);
        if ($question === '' || mb_strlen($question) > 1000) {
            return array('ok' => false, 'text' => null, 'provider_status' => 'invalid_request', 'error' => 'Enter a question under 1000 characters.');
        }

        $ref = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client = $service ?: new GeminiService();
        if (!$client->is_ready()) {
            return array('ok' => false, 'text' => null, 'provider_status' => 'not_configured', 'error' => 'Google Gemini is disabled or not configured.');
        }
        if ($this->monthly_calls_used($ref) >= $client->monthly_cap()) {
            return array('ok' => false, 'text' => null, 'provider_status' => 'cap_reached', 'error' => 'The monthly Gemini call limit has been reached.');
        }

        $metrics = $this->build_weekly_metrics($uid, $ref);
        $result = $client->generate_chat_answer($question, $metrics);

        // Keep a countable audit entry, but never store the chat text.
        if (($result['provider_status'] ?? '') !== 'invalid_request') {
            try {
                $this->db->table('ai_plan_requests')->insert(array(
                    'user_id' => $uid,
                    'plan_id' => null,
                    'provider' => 'gemini',
                    'status' => !empty($result['ok']) ? 'succeeded' : 'failed',
                    'request_summary' => 'kind=chat chars=' . (int) mb_strlen($question),
                    'created_at' => gmdate('Y-m-d H:i:s'),
                ));
            } catch (Throwable $e) {
                // Chat still works if the optional audit write is unavailable.
            }
        }

        return $result;
    }

    /**
     * Persists the review, or returns it unsaved (`persisted = false`) when the
     * database cannot store it. A missing table must never turn a readable
     * coaching card into "the server sent an unexpected reply".
     */
    private function persist_or_degrade($user_id, array $metrics, $text, $provider_status)
    {
        try {
            return $this->save_feedback(
                $user_id,
                $metrics['period_start'],
                $metrics['period_end'],
                $metrics,
                $text,
                $provider_status
            );
        } catch (Throwable $e) {
            return array(
                'id'              => null,
                'period_start'    => $metrics['period_start'],
                'period_end'      => $metrics['period_end'],
                'metrics'         => $metrics,
                'feedback_text'   => $text,
                'provider_status' => $provider_status,
                'persisted'       => false,
                'persist_error'   => $this->describe_error($e),
                'created_at'      => gmdate('Y-m-d H:i:s'),
            );
        }
    }

    /**
     * A short, non-secret reason for a failed write. Only surfaced while
     * APP_ENV=development, because it can contain a table/column name.
     */
    private function describe_error(Throwable $e)
    {
        $env = function_exists('env') ? (string) env('APP_ENV', 'development') : 'development';
        if (strtolower($env) !== 'development') {
            return null;
        }
        return mb_substr(get_class($e) . ': ' . $e->getMessage(), 0, 240);
    }

    /**
     * Records a plan generation audit entry in `ai_plan_requests` and returns
     * a concise coaching brief (`['text' => string, 'provider_status' => string]`).
     * Distances and dates remain 100% governed by `PlanModel::build()`.
     */
    public function record_plan_generation($user_id, $plan_id, array $profile, array $built, ?DateTimeImmutable $today = null, ?GeminiService $service = null)
    {
        $uid    = (int) $user_id;
        $pid    = $plan_id !== null ? (int) $plan_id : null;
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client = $service ?: new GeminiService();

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

        $this->ensure_tables();

        try {
            $this->db->table('ai_plan_requests')->insert(array(
                'user_id'         => $uid,
                'plan_id'         => $pid,
                'provider'        => 'gemini',
                'status'          => $req_status,
                'request_summary' => mb_substr($summary_line, 0, 500),
                'created_at'      => gmdate('Y-m-d H:i:s'),
            ));
        } catch (Throwable $e) {
            // The audit row is diagnostic only: plan generation already
            // succeeded and must not be rolled back by a logging failure.
        }

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

        try {
            $plan_calls = (int) $this->db->raw(
                "SELECT COUNT(*) FROM ai_plan_requests
                  WHERE provider = 'gemini'
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
        } catch (Throwable $e) {
            // No readable audit tables means no recorded calls, not a failure.
            return 0;
        }

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
            'persisted'       => true,
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
