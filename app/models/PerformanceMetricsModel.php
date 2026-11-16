<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: PerformanceMetricsModel
 */
class PerformanceMetricsModel extends Model
{
    protected $table = 'activities';
    protected $primary_key = 'id';

    const ELIGIBLE_SOURCE = 'manual';

    /**
     * Build the performance summary for a selected date range.
     */
    public function summary($user_id, $range = '30d')
    {
        $uid = (int) $user_id;

        // Respect the PHP timezone configured by the application.
        $timezone = new DateTimeZone(date_default_timezone_get());
        $today = new DateTimeImmutable('today', $timezone);
        $end = $today->format('Y-m-d');

        switch ($range) {
            case '7d':
                $start = $today->modify('-6 days');
                break;
            case '30d':
                $start = $today->modify('-29 days');
                break;
            case '90d':
                $start = $today->modify('-89 days');
                break;
            case 'all':
                $start = null;
                break;
            default:
                $range = '30d';
                $start = $today->modify('-29 days');
        }

        // Retrieve only this user's eligible activities.
        $query = $this->db
            ->table($this->table)
            ->select('id, session_id, workout_type, activity_date, distance_km, duration_seconds, effort')
            ->where('user_id', $uid)
            ->where('source', self::ELIGIBLE_SOURCE);

        if ($start !== null) {
            $query->where('activity_date', '>=', $start->format('Y-m-d'));
        }

        $rows = $query
            ->where('activity_date', '<=', $end)
            ->order_by('activity_date', 'ASC')
            ->get_all();
        $rows = $rows ?: [];

        // Resolve workout types through the linked training session.
        // Activities without a session link cannot be reliably categorized.
        $session_rows = $this->db
            ->table('training_sessions')
            ->select('id, type')
            ->where('user_id', $uid)
            ->get_all();
        $session_types = [];
        foreach (($session_rows ?: []) as $session_row) {
            $session_types[(int) $session_row['id']] = (string) $session_row['type'];
        }

        $workout_categories = [
            'endurance' => [],
            'speed' => [],
            'recovery' => [],
        ];
        $unclassified_workout_runs = 0;

        // For all-time data, begin at the first recorded eligible activity.
        $period_start = $range === 'all'
            ? (!empty($rows) ? $rows[0]['activity_date'] : null)
            : $start->format('Y-m-d');

        $total_runs = count($rows);
        $total_distance = 0;
        $total_duration = 0;
        $total_effort = 0;
        $effort_count = 0;
        $longest_run = 0;
        $active_days = [];

        $distance_trends = [];
        $pace_trends = [];

        // Pre-create every week/month in the selected period so inactive
        // periods are returned as zero-value buckets instead of disappearing.
        if ($period_start !== null) {
            $cursor = new DateTimeImmutable($period_start, $timezone);
            $last = new DateTimeImmutable($end, $timezone);

            if ($range === 'all') {
                $cursor = $cursor->modify('first day of this month');
                $last = $last->modify('first day of this month');
                $step = 'first day of next month';
            } else {
                $cursor = $cursor->modify('monday this week');
                $last = $last->modify('monday this week');
                $step = '+1 week';
            }

            while ($cursor <= $last) {
                if ($range === 'all') {
                    $bucket_key = $cursor->format('Y-m');
                    $bucket_label = $cursor->format('M Y');
                } else {
                    $bucket_key = $cursor->format('o-W');
                    $bucket_label = 'Week ' . $cursor->format('W, o');
                }

                $distance_trends[$bucket_key] = [
                    'period' => $bucket_label,
                    'distance_km' => 0,
                    'runs' => 0,
                ];
                $pace_trends[$bucket_key] = [
                    'period' => $bucket_label,
                    'total_duration_seconds' => 0,
                    'total_distance_km' => 0,
                ];

                foreach ($workout_categories as $category => $unused) {
                    $workout_categories[$category][$bucket_key] = [
                        'period' => $bucket_label,
                        'distance_km' => 0,
                        'runs' => 0,
                        'total_duration_seconds' => 0,
                        'total_distance_km' => 0,
                    ];
                }

                $cursor = $cursor->modify($step);
            }
        }

        foreach ($rows as $row) {
            $date = $row['activity_date'];
            $distance = (float) $row['distance_km'];
            $duration = (int) $row['duration_seconds'];
            $effort = $row['effort'] !== null ? (int) $row['effort'] : null;

            $total_distance += $distance;
            $total_duration += $duration;
            $longest_run = max($longest_run, $distance);

            if ($effort !== null) {
                $total_effort += $effort;
                $effort_count++;
            }

            $active_days[$date] = true;
            $date_object = new DateTimeImmutable($date, $timezone);

            if ($range === 'all') {
                $bucket_key = $date_object->format('Y-m');
            } else {
                $bucket_key = $date_object->format('o-W');
            }

            // The bucket was pre-created above; this guard protects against
            // unexpected date values without creating malformed trend entries.
            if (!isset($distance_trends[$bucket_key])) {
                continue;
            }

            $distance_trends[$bucket_key]['distance_km'] += $distance;
            $distance_trends[$bucket_key]['runs']++;
            $pace_trends[$bucket_key]['total_duration_seconds'] += $duration;
            $pace_trends[$bucket_key]['total_distance_km'] += $distance;

            $session_id = isset($row['session_id']) ? (int) $row['session_id'] : 0;

            // Prefer the type saved directly on the activity.
            // Fall back to the linked session for older activities.
            $workout_type = !empty($row['workout_type'])
                ? (string) $row['workout_type']
                : ($session_id > 0 ? ($session_types[$session_id] ?? null) : null);

            // Agreed workout-type mapping:
            // long -> endurance; tempo/threshold/interval -> speed;
            // easy/recovery -> recovery.
            $category = null;
            if ($workout_type === 'long') {
                $category = 'endurance';
            } elseif (in_array($workout_type, ['tempo', 'threshold', 'interval'], true)) {
                $category = 'speed';
            } elseif (in_array($workout_type, ['easy', 'recovery'], true)) {
                $category = 'recovery';
            }

            if ($category !== null && isset($workout_categories[$category][$bucket_key])) {
                $workout_categories[$category][$bucket_key]['distance_km'] += $distance;
                $workout_categories[$category][$bucket_key]['runs']++;
                $workout_categories[$category][$bucket_key]['total_duration_seconds'] += $duration;
                $workout_categories[$category][$bucket_key]['total_distance_km'] += $distance;
            } else {
                // Includes standalone activities without a linked workout type,
                // plus race/rest types which are not yet assigned to a category.
                $unclassified_workout_runs++;
            }
        }

        $average_pace_seconds = null;
        $average_pace_label = null;
        if ($total_distance > 0 && $total_duration > 0) {
            $average_pace_seconds = (int) round($total_duration / $total_distance);
            $average_pace_label = $this->format_pace($average_pace_seconds);
        }

        foreach ($distance_trends as &$trend) {
            $trend['distance_km'] = round($trend['distance_km'], 2);
        }
        unset($trend);

        foreach ($pace_trends as &$trend) {
            $km = $trend['total_distance_km'];
            $seconds = $trend['total_duration_seconds'];
            $pace = $km > 0 ? (int) round($seconds / $km) : null;

            $trend['pace_seconds_per_km'] = $pace;
            $trend['pace_label'] = $pace !== null ? $this->format_pace($pace) : null;
            unset($trend['total_duration_seconds'], $trend['total_distance_km']);
        }
        unset($trend);

        foreach ($workout_categories as &$category_trends) {
            foreach ($category_trends as &$trend) {
                $km = $trend['total_distance_km'];
                $seconds = $trend['total_duration_seconds'];
                $pace = $km > 0 ? (int) round($seconds / $km) : null;

                $trend['distance_km'] = round($trend['distance_km'], 2);
                $trend['pace_seconds_per_km'] = $pace;
                $trend['pace_label'] = $pace !== null ? $this->format_pace($pace) : null;
                unset($trend['total_duration_seconds'], $trend['total_distance_km']);
            }
            unset($trend);
            $category_trends = array_values($category_trends);
        }
        unset($category_trends);

        $period_days = $period_start !== null
            ? (int) (new DateTimeImmutable($period_start, $timezone))->diff($today)->days + 1
            : 0;
        $weeks_in_period = $period_days > 0 ? $period_days / 7 : 0;
        $average_runs_per_week = $weeks_in_period > 0
            ? round($total_runs / $weeks_in_period, 2)
            : 0;

        $adherence = $this->plan_adherence($uid, $period_start, $end);

        return [
            'range' => $range,
            'period' => [
                'start' => $period_start,
                'end' => $end,
                'days' => $period_days,
            ],
            'totals' => [
                'total_runs' => $total_runs,
                'total_distance_km' => round($total_distance, 2),
                'total_duration_seconds' => $total_duration,
                'average_pace_seconds_per_km' => $average_pace_seconds,
                'average_pace_label' => $average_pace_label,
                'longest_run_km' => round($longest_run, 2),
                'average_effort' => $effort_count > 0
                    ? round($total_effort / $effort_count, 1)
                    : null,
            ],
            'consistency' => [
                'active_days' => count($active_days),
                'average_runs_per_week' => $average_runs_per_week,
            ],
            'distance_trends' => array_values($distance_trends),
            'pace_trends' => array_values($pace_trends),
            'endurance_trends' => $workout_categories['endurance'],
            'speed_trends' => $workout_categories['speed'],
            'recovery_trends' => $workout_categories['recovery'],
            'unclassified_workout_runs' => $unclassified_workout_runs,
            'adherence' => $adherence,
            'eligible_source' => self::ELIGIBLE_SOURCE,
        ];
    }

    /**
     * Format pace as minutes:seconds per kilometer.
     */
    private function format_pace($seconds)
    {
        return sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * Aggregate adherence across all approved plans overlapping the period.
     * Archived plans are included; drafts are excluded because they were
     * never approved as training plans.
     */
    private function plan_adherence($uid, $period_start, $period_end)
    {
        if ($period_start === null) {
            $plans = $this->db
                ->table('training_plans')
                ->select('id, goal, start_date, end_date, status')
                ->where('user_id', $uid)
                ->order_by('start_date', 'ASC')
                ->get_all();
        } else {
            $plans = $this->db
                ->table('training_plans')
                ->select('id, goal, start_date, end_date, status')
                ->where('user_id', $uid)
                ->where('start_date', '<=', $period_end)
                ->where('end_date', '>=', $period_start)
                ->order_by('start_date', 'ASC')
                ->get_all();
        }
        $plans = $plans ?: [];

        $counts = [
            'scheduled_due' => 0,
            'completed' => 0,
            'skipped' => 0,
            'missed' => 0,
        ];
        $plan_summaries = [];
        $has_active_plan = false;

        foreach ($plans as $plan) {
            // PlanModel archives the previous approved plan when a new one
            // is approved. Draft plans are not part of adherence reporting.
            if (!in_array($plan['status'] ?? '', ['active', 'archived'], true)) {
                continue;
            }

            $from = $period_start !== null
                ? max($period_start, $plan['start_date'])
                : $plan['start_date'];
            $to = min($period_end, $plan['end_date']);

            if ($from > $to) {
                continue;
            }

            if (($plan['status'] ?? '') === 'active') {
                $has_active_plan = true;
            }

            $sessions = $this->db
                ->table('training_sessions')
                ->select('status')
                ->where('plan_id', (int) $plan['id'])
                ->where('user_id', $uid)
                ->where('scheduled_date', '>=', $from)
                ->where('scheduled_date', '<=', $to)
                ->result_array();
            $sessions = $sessions ?: [];

            $plan_counts = [
                'scheduled_due' => count($sessions),
                'completed' => 0,
                'skipped' => 0,
                'missed' => 0,
            ];

            foreach ($sessions as $session) {
                $status = $session['status'] ?? '';
                if (array_key_exists($status, $plan_counts) && $status !== 'scheduled_due') {
                    $plan_counts[$status]++;
                }
            }

            foreach ($counts as $key => $value) {
                $counts[$key] += $plan_counts[$key];
            }

            $plan_summaries[] = [
                'plan_id' => (int) $plan['id'],
                'goal' => $plan['goal'],
                'status' => $plan['status'],
                'start_date' => $plan['start_date'],
                'end_date' => $plan['end_date'],
                'scheduled_due' => $plan_counts['scheduled_due'],
                'completed' => $plan_counts['completed'],
                'skipped' => $plan_counts['skipped'],
                'missed' => $plan_counts['missed'],
                'completion_rate' => $plan_counts['scheduled_due'] > 0
                    ? round(100 * $plan_counts['completed'] / $plan_counts['scheduled_due'], 1)
                    : null,
            ];
        }

        return [
            'has_plan' => !empty($plan_summaries),
            'has_active_plan' => $has_active_plan,
            'goal' => count($plan_summaries) === 1 ? $plan_summaries[0]['goal'] : null,
            'plans' => $plan_summaries,
            'scheduled_due' => $counts['scheduled_due'],
            'completed' => $counts['completed'],
            'skipped' => $counts['skipped'],
            'missed' => $counts['missed'],
            'completion_rate' => $counts['scheduled_due'] > 0
                ? round(100 * $counts['completed'] / $counts['scheduled_due'], 1)
                : null,
        ];
    }
}
