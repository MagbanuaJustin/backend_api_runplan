<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: DashboardModel
 * 
 * Automatically generated via CLI.
 */
class DashboardModel extends Model
{
    protected $table = 'activities';
    protected $primary_key = 'id';

    const ELIGIBLE_SOURCE = 'manual';

    public function summary($user_id, DateTimeImmutable $today)
    {
        $uid = (int) $user_id;
        $t   = $today->format('Y-m-d');
        $ago = function ($days) use ($today) {
            return $today->modify("-{$days} days")->format('Y-m-d');
        };

        return [
            'today'     => $t,
            'last7'     => $this->window($uid, $ago(6), $t),
            'previous7' => $this->window($uid, $ago(13), $ago(7)),
            'plan'      => $this->plan_block($uid, $today),
            'weeks'     => $this->weekly($uid, $today),
            'recent'    => $this->recent($uid),
        ];
    }

    /**
     * Deterministic summary for AI coaching feedback.
     *
     * Contains aggregate RunPlan numbers only. It excludes personal
     * identifiers and non-manual activity sources.
     */
    public function coaching_snapshot($user_id, DateTimeImmutable $today, $days = 28)
    {
        $uid        = (int) $user_id;
        $days       = max(14, min(90, (int) $days));
        $half       = intdiv($days, 2);
        $end_str    = $today->format('Y-m-d');
        $start_str  = $today->modify('-' . ($days - 1) . ' days')->format('Y-m-d');
        $mid_end    = $today->modify('-' . $half . ' days')->format('Y-m-d');
        $recent_beg = $today->modify('-' . ($half - 1) . ' days')->format('Y-m-d');

        $overall = $this->window($uid, $start_str, $end_str);
        $prior   = $this->window($uid, $start_str, $mid_end);
        $recent  = $this->window($uid, $recent_beg, $end_str);

        $pace_trend     = 'insufficient_data';
        $pace_delta_sec = null;

        if (
            $prior['runs'] >= 2
            && $recent['runs'] >= 2
            && $prior['pace_seconds_per_km'] !== null
            && $recent['pace_seconds_per_km'] !== null
        ) {
            $pace_delta_sec = $recent['pace_seconds_per_km']
                - $prior['pace_seconds_per_km'];

            if ($pace_delta_sec <= -8) {
                $pace_trend = 'faster';
            } elseif ($pace_delta_sec >= 8) {
                $pace_trend = 'slower';
            } else {
                $pace_trend = 'steady';
            }
        }

        $skipped_treatment = 'Skipped sessions remain in the scheduled-due denominator (completion_rate = completed / scheduled_due * 100) so deliberate skips are distinguished from unlogged missed runs without inflating adherence.';

        $adherence = [
            'has_active_plan'   => false,
            'goal'              => null,
            'scheduled_due'     => 0,
            'completed'         => 0,
            'skipped'           => 0,
            'missed'            => 0,
            'completion_rate'   => null,
            'skipped_treatment' => $skipped_treatment,
        ];

        $plan = $this->db
            ->table('training_plans')
            ->select('id, goal')
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->limit(1)
            ->row_array();

        if ($plan) {
            $session_rows = $this->db
                ->table('training_sessions')
                ->select('status')
                ->where('plan_id', (int) $plan['id'])
                ->where('user_id', $uid)
                ->where('scheduled_date', '>=', $start_str)
                ->grouped(function ($query) use ($end_str) {
                    $query->where('scheduled_date', '<=', $end_str)
                        ->or_where('status', '=', 'completed');
                })
                ->result_array();

            $counts    = $this->count_session_statuses($session_rows);
            $due       = $counts['due'];
            $completed = $counts['completed'];

            $adherence = [
                'has_active_plan'   => true,
                'goal'              => $plan['goal'],
                'scheduled_due'     => $due,
                'completed'         => $completed,
                'skipped'           => $counts['skipped'],
                'missed'            => $counts['missed'],
                'completion_rate'   => $due > 0
                    ? (int) round(100 * $completed / $due)
                    : null,
                'skipped_treatment' => $skipped_treatment,
            ];
        }

        return [
            'period_start'    => $start_str,
            'period_end'      => $end_str,
            'window_days'     => $days,
            'eligible_source' => self::ELIGIBLE_SOURCE,
            'totals'          => $overall,
            'pace_comparison' => [
                'prior_half'   => $prior,
                'recent_half'  => $recent,
                'trend'        => $pace_trend,
                'delta_sec_km' => $pace_delta_sec,
            ],
            'adherence' => $adherence,
        ];
    }

    private function pace($seconds, $km)
    {
        if ($km <= 0 || $seconds <= 0) {
            return [null, null];
        }

        $pace_seconds = (int) round($seconds / $km);

        return [
            $pace_seconds,
            sprintf(
                '%d:%02d',
                intdiv($pace_seconds, 60),
                $pace_seconds % 60
            ),
        ];
    }

    /**
     * Summarize manual activities in a date window.
     */
    private function window($uid, $from, $to)
    {
        $row = $this->db
            ->table('activities')
            ->select_count('id', 'runs')
            ->select_sum('distance_km', 'km')
            ->select_sum('duration_seconds', 'sec')
            ->where('user_id', $uid)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->between('activity_date', $from, $to)
            ->row_array();

        $km  = (float) ($row['km'] ?? 0);
        $sec = (int) ($row['sec'] ?? 0);

        [$pace, $label] = $this->pace($sec, $km);

        return [
            'runs'                => (int) ($row['runs'] ?? 0),
            'distance_km'         => round($km, 1),
            'duration_seconds'    => $sec,
            'pace_seconds_per_km' => $pace,
            'pace_label'          => $label,
        ];
    }

    /**
     * Count statuses in already-filtered training session rows.
     */
    private function count_session_statuses(array $rows)
    {
        $counts = [
            'due'       => count($rows),
            'completed' => 0,
            'skipped'   => 0,
            'missed'    => 0,
        ];

        foreach ($rows as $row) {
            $status = $row['status'] ?? '';

            if (array_key_exists($status, $counts) && $status !== 'due') {
                $counts[$status]++;
            }
        }

        return $counts;
    }

    private function plan_block($uid, DateTimeImmutable $today)
    {
        $plan = $this->db
            ->table('training_plans')
            ->select('id, goal, start_date, end_date')
            ->where('user_id', $uid)
            ->where('status', 'active')
            ->limit(1)
            ->row_array();

        if (!$plan) {
            return null;
        }

        $t = $today->format('Y-m-d');

        $session_rows = $this->db
            ->table('training_sessions')
            ->select('status')
            ->where('plan_id', (int) $plan['id'])
            ->where('user_id', $uid)
            ->grouped(function ($query) use ($t) {
                $query->where('scheduled_date', '<', $t)
                    ->or_where('status', '=', 'completed');
            })
            ->result_array();

        $counts = $this->count_session_statuses($session_rows);

        $next = $this->db
            ->table('training_sessions')
            ->select('id, scheduled_date, type, planned_distance_km, planned_duration_seconds, notes')
            ->where('plan_id', (int) $plan['id'])
            ->where('user_id', $uid)
            ->where('scheduled_date', '>=', $t)
            ->where('status', 'planned')
            ->order_by('scheduled_date', 'ASC')
            ->limit(1)
            ->row_array();

        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($plan['start_date'], $utc);
        $end   = new DateTimeImmutable($plan['end_date'], $utc);
        $total = intdiv((int) $start->diff($end)->days, 7) + 1;
        $since = (int) $start->diff($today)->format('%r%a');
        $due   = $counts['due'];

        return [
            'id'              => (int) $plan['id'],
            'goal'            => $plan['goal'],
            'start_date'      => $plan['start_date'],
            'end_date'        => $plan['end_date'],
            'week'            => $since < 0
                ? 0
                : min($total, intdiv($since, 7) + 1),
            'total_weeks'     => $total,
            'days_left'       => (int) $today->diff($end)->format('%r%a'),
            'due'             => $due,
            'completed'       => $counts['completed'],
            'skipped'         => $counts['skipped'],
            'missed'          => $counts['missed'],
            'completion_rate' => $due > 0
                ? (int) round(100 * $counts['completed'] / $due)
                : null,
            'next_session'    => $next ? [
                'id'                       => (int) $next['id'],
                'scheduled_date'           => $next['scheduled_date'],
                'type'                     => $next['type'],
                'planned_distance_km'      => (float) $next['planned_distance_km'],
                'planned_duration_seconds' => (int) $next['planned_duration_seconds'],
                'notes'                    => $next['notes'],
            ] : null,
        ];
    }

    private function weekly($uid, DateTimeImmutable $today)
    {
        $utc  = new DateTimeZone('UTC');
        $t    = $today->format('Y-m-d');
        $from = $today->modify('-55 days')->format('Y-m-d');

        $actual_km  = [];
        $actual_sec = [];
        $actual_cnt = [];

        $rows = $this->db
            ->table('activities')
            ->select('activity_date, distance_km, duration_seconds')
            ->where('user_id', $uid)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->between('activity_date', $from, $t)
            ->result_array();

        foreach ($rows as $row) {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $row['activity_date'],
                $utc
            );

            if (!$date) {
                continue;
            }

            $bucket = intdiv((int) $date->diff($today)->days, 7);

            if ($bucket < 0 || $bucket > 7) {
                continue;
            }

            $actual_km[$bucket] = ($actual_km[$bucket] ?? 0.0)
                + (float) $row['distance_km'];
            $actual_sec[$bucket] = ($actual_sec[$bucket] ?? 0)
                + (int) $row['duration_seconds'];
            $actual_cnt[$bucket] = ($actual_cnt[$bucket] ?? 0) + 1;
        }

        $planned = [];

        $rows = $this->db
            ->table('training_sessions')
            ->select('training_sessions.scheduled_date, training_sessions.planned_distance_km')
            ->inner_join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
            )
            ->where('training_sessions.user_id', $uid)
            ->where('training_plans.status', 'active')
            ->where('training_sessions.status', '<>', 'skipped')
            ->between('training_sessions.scheduled_date', $from, $t)
            ->result_array();

        foreach ($rows as $row) {
            $date = DateTimeImmutable::createFromFormat(
                '!Y-m-d',
                $row['scheduled_date'],
                $utc
            );

            if (!$date) {
                continue;
            }

            $bucket = intdiv((int) $date->diff($today)->days, 7);

            if ($bucket >= 0 && $bucket <= 7) {
                $planned[$bucket] = ($planned[$bucket] ?? 0.0)
                    + (float) $row['planned_distance_km'];
            }
        }

        $weeks = [];

        for ($i = 7; $i >= 0; $i--) {
            $km  = $actual_km[$i] ?? 0.0;
            $sec = $actual_sec[$i] ?? 0;

            [$pace_seconds, $pace_label] = $this->pace($sec, $km);

            $weeks[] = [
                'start'                      => $today->modify('-' . (6 + 7 * $i) . ' days')->format('Y-m-d'),
                'end'                        => $today->modify('-' . (7 * $i) . ' days')->format('Y-m-d'),
                'actual_km'                  => round($km, 1),
                'planned_km'                 => round($planned[$i] ?? 0.0, 1),
                'runs'                       => $actual_cnt[$i] ?? 0,
                'actual_duration_seconds'    => $sec,
                'actual_pace_seconds_per_km' => $pace_seconds,
                'actual_pace_label'          => $pace_label,
            ];
        }

        return $weeks;
    }

    private function recent($uid)
    {
        $rows = $this->db
            ->table('activities')
            ->select('id, activity_date, distance_km, duration_seconds, effort, session_id')
            ->where('user_id', (int) $uid)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->order_by('activity_date', 'DESC')
            ->order_by('id', 'DESC')
            ->limit(5)
            ->result_array();

        return array_map(function ($row) {
            [, $label] = $this->pace(
                (int) $row['duration_seconds'],
                (float) $row['distance_km']
            );

            return [
                'id'               => (int) $row['id'],
                'activity_date'    => $row['activity_date'],
                'distance_km'      => (float) $row['distance_km'],
                'duration_seconds' => (int) $row['duration_seconds'],
                'pace_label'       => $label,
                'effort'           => $row['effort'] !== null
                    ? (int) $row['effort']
                    : null,
                'from_plan'        => $row['session_id'] !== null,
            ];
        }, $rows);
    }
}