<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__) . '/libraries/GroqService.php';

/**
 * Model: AiCoachingModel
 *
 * Persists AI / rule-based weekly coaching feedback (`ai_feedback`) and plan
 * generation audit rows (`ai_plan_requests`), enforcing:
 *   1. Strict `source = 'manual'` first-party data boundary (never non-manual runs).
 *   2. Monthly outbound Groq call ceiling (`GROQ_MONTHLY_CALL_CAP`).
 *   3. Automatic deterministic coaching fallback when Groq is disabled,
 *      over cap, or unreachable (Spec 5.1 & 7.3 / NFR-04).
 */
class AiCoachingModel extends Model {

    protected $table       = 'ai_feedback';
    protected $primary_key = 'id';

    public function latest_feedback_for_user($user_id)
    {
        $row = $this->db->table('ai_feedback')
            ->select('id, period_start, period_end, metrics_snapshot, feedback_text, provider_status, created_at')
            ->where('user_id', (int) $user_id)
            ->order_by('period_start', 'DESC')
            ->limit(1)
            ->get();

        return is_array($row) ? $this->present_feedback($row) : null;
    }
    public function generate_weekly_feedback($user_id, ?DateTimeImmutable $today = null, ?GroqService $service = null)
    {

        $uid     = (int) $user_id;
        $ref     = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client  = $service ?: new GroqService();
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
    public function answer_chat_question($user_id, $question, ?DateTimeImmutable $today = null, ?GroqService $service = null)
    {

        $uid = (int) $user_id;
        $question = trim((string) $question);
        if ($question === '' || mb_strlen($question) > 1000) {
            return array('ok' => false, 'text' => null, 'provider_status' => 'invalid_request', 'error' => 'Enter a question under 1000 characters.');
        }

        $ref = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client = $service ?: new GroqService();
        if (!$client->is_ready()) {
            return array('ok' => false, 'text' => null, 'provider_status' => 'not_configured', 'error' => 'Groq is disabled or not configured.');
        }
        if ($this->monthly_calls_used($ref) >= $client->monthly_cap()) {
            return array('ok' => false, 'text' => null, 'provider_status' => 'cap_reached', 'error' => 'The monthly Groq call limit has been reached.');
        }

        $metrics = $this->build_weekly_metrics($uid, $ref);
        $result = $client->generate_chat_answer($question, $metrics);

        // Keep a countable audit entry, but never store the chat text.
        if (($result['provider_status'] ?? '') !== 'invalid_request') {
            try {
                $this->db->table('ai_plan_requests')->insert(array(
                    'user_id' => $uid,
                    'plan_id' => null,
                    'provider' => 'groq',
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
    public function record_plan_generation($user_id, $plan_id, array $profile, array $built, ?DateTimeImmutable $today = null, ?GroqService $service = null)
    {
        $uid    = (int) $user_id;
        $pid    = $plan_id !== null ? (int) $plan_id : null;
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $client = $service ?: new GroqService();

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

        try {
            $this->db->table('ai_plan_requests')->insert(array(
                'user_id'         => $uid,
                'plan_id'         => $pid,
                'provider'        => 'groq',
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

        $profile = $this->db->table('runner_profiles')
            ->select('goal, experience')
            ->where('user_id', $uid)
            ->limit(1)
            ->get();

        $runs = $this->db->table('activities')
            ->select('distance_km, duration_seconds, effort')
            ->where(array('user_id' => $uid, 'source' => 'manual'))
            ->between('activity_date', $start, $end)
            ->get_all();

        $runs_logged = count($runs);
        $distance_km = 0.0;
        $duration_seconds = 0;
        $longest_run_km = 0.0;
        $effort_total = 0.0;
        $effort_count = 0;

        foreach ($runs as $run) {
            $distance = (float) ($run['distance_km'] ?? 0);
            $distance_km += $distance;
            $duration_seconds += (int) ($run['duration_seconds'] ?? 0);
            $longest_run_km = max($longest_run_km, $distance);

            if (isset($run['effort']) && $run['effort'] !== '' && is_numeric($run['effort'])) {
                $effort_total += (float) $run['effort'];
                $effort_count++;
            }
        }

        $completed = 0;
        $planned = 0;
        $skipped = 0;
        $missed = 0;

        $active_plans = $this->db->table('training_plans')
            ->select('id')
            ->where(array('user_id' => $uid, 'status' => 'active'))
            ->get_all();

        foreach ($active_plans as $plan) {
            $sessions = $this->db->table('training_sessions')
                ->select('status')
                ->where(array('user_id' => $uid, 'plan_id' => (int) $plan['id']))
                ->between('scheduled_date', $start, $end)
                ->get_all();

            foreach ($sessions as $session) {
                switch ($session['status'] ?? '') {
                    case 'completed':
                        $completed++;
                        break;
                    case 'planned':
                        $planned++;
                        break;
                    case 'skipped':
                        $skipped++;
                        break;
                    case 'missed':
                        $missed++;
                        break;
                }
            }
        }

        return array(
            'period_start'       => $start,
            'period_end'         => $end,
            'goal'               => $profile['goal'] ?? 'general_fitness',
            'experience'         => $profile['experience'] ?? 'beginner',
            'runs_logged'        => $runs_logged,
            'distance_km'        => round($distance_km, 2),
            'duration_seconds'   => $duration_seconds,
            'avg_effort'         => $effort_count > 0 ? round($effort_total / $effort_count, 1) : null,
            'longest_run_km'     => round($longest_run_km, 2),
            'sessions_completed' => $completed,
            'sessions_planned'   => $planned,
            'sessions_skipped'   => $skipped,
            'sessions_missed'    => $missed,
        );
    }
    public function monthly_calls_used(?DateTimeImmutable $today = null)
    {
        $ref = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $month_start = $ref->format('Y-m-01') . ' 00:00:00';

        $successful_plan_calls = $this->db->table('ai_plan_requests')
            ->where(array('provider' => 'groq', 'status' => 'succeeded'))
            ->where('created_at', '>=', $month_start)
            ->count();

        $failed_plan_calls = $this->db->table('ai_plan_requests')
            ->where(array('provider' => 'groq', 'status' => 'failed'))
            ->where('created_at', '>=', $month_start)
            ->count();

        $feedback_calls = $this->db->table('ai_feedback')
            ->where('provider_status', 'ok')
            ->where('created_at', '>=', $month_start)
            ->count();

        return (int) $successful_plan_calls + (int) $failed_plan_calls + (int) $feedback_calls;
    }
    private function save_feedback($user_id, $period_start, $period_end, array $metrics, $text, $provider_status)
    {
        $now_utc = gmdate('Y-m-d H:i:s');
        $json = json_encode($metrics, JSON_UNESCAPED_SLASHES);
        $uid = (int) $user_id;

        $existing = $this->db->table('ai_feedback')
            ->select('id')
            ->where(array('user_id' => $uid, 'period_start' => $period_start))
            ->limit(1)
            ->get();

        $values = array(
            'period_end' => $period_end,
            'metrics_snapshot' => $json,
            'feedback_text' => $text,
            'provider_status' => $provider_status,
            'created_at' => $now_utc,
        );

        if (is_array($existing)) {
            $id = (int) $existing['id'];
            $this->db->table('ai_feedback')
                ->where(array('id' => $id, 'user_id' => $uid))
                ->update($values);
        } else {
            $this->db->table('ai_feedback')->insert(array_merge($values, array(
                'user_id' => $uid,
                'period_start' => $period_start,
            )));
            $id = (int) $this->db->last_id();
        }

        $row = $this->db->table('ai_feedback')
            ->select('id, period_start, period_end, metrics_snapshot, feedback_text, provider_status, created_at')
            ->where(array('id' => $id, 'user_id' => $uid))
            ->limit(1)
            ->get();

        if (!is_array($row)) {
            throw new RuntimeException('Could not reload the saved coaching feedback.');
        }

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
