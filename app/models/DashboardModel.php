<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ActivityModel
 * 
 * Automatically generated via CLI.
 */
class DashboardModel extends Model {
    protected $table = 'activities';
    protected $primary_key = 'id';

    public function summary($user_id, DateTimeImmutable $today)
    {
        $uid = (int) $user_id;
        $t   = $today->format('Y-m-d');
        $ago = fn($n) => $today->modify("-{$n} days")->format('Y-m-d');

        return [
            'today'     => $t,
            'last7'     => $this->window($uid, $ago(6), $t),
            'previous7' => $this->window($uid, $ago(13), $ago(7)),
            'plan'      => $this->plan_block($uid, $today),
            'weeks'     => $this->weekly($uid, $today),
            'recent'    => $this->recent($uid),
        ];
    }

    private function pace($seconds, $km)
    {
        if ($km <= 0) {
            return [null, null];
        }
        $p = (int) round($seconds / $km);
        return [$p, sprintf('%d:%02d', intdiv($p, 60), $p % 60)];
    }

    private function window($uid, $from, $to)
    {
        $r = $this->db->raw(
            'SELECT COUNT(*) AS runs, COALESCE(SUM(distance_km), 0) AS km, COALESCE(SUM(duration_seconds), 0) AS sec
               FROM activities WHERE user_id = ? AND activity_date BETWEEN ? AND ?',
            [$uid, $from, $to]
        )->fetch(PDO::FETCH_ASSOC);

        $km  = (float) $r['km'];
        $sec = (int) $r['sec'];
        [$pace, $label] = $this->pace($sec, $km);

        return [
            'runs'                => (int) $r['runs'],
            'distance_km'         => round($km, 1),
            'duration_seconds'    => $sec,
            'pace_seconds_per_km' => $pace,
            'pace_label'          => $label,
        ];
    }

    private function plan_block($uid, DateTimeImmutable $today)
    {
        $plan = $this->db->raw(
            "SELECT id, goal, start_date, end_date FROM training_plans
              WHERE user_id = ? AND status = 'active' LIMIT 1",
            [$uid]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return null;
        }

        $t = $today->format('Y-m-d');
        $a = $this->db->raw(
            "SELECT COALESCE(SUM(status = 'completed'), 0) AS completed,
                    COALESCE(SUM(status = 'skipped'), 0)   AS skipped,
                    COALESCE(SUM(status = 'missed'), 0)    AS missed,
                    COUNT(*)                               AS due
               FROM training_sessions
              WHERE plan_id = ? AND user_id = ? AND (scheduled_date < ? OR status = 'completed')",
            [(int) $plan['id'], $uid, $t]
        )->fetch(PDO::FETCH_ASSOC);

        $next = $this->db->raw(
            "SELECT id, scheduled_date, type, planned_distance_km, planned_duration_seconds, notes
               FROM training_sessions
              WHERE plan_id = ? AND user_id = ? AND scheduled_date >= ? AND status = 'planned'
              ORDER BY scheduled_date LIMIT 1",
            [(int) $plan['id'], $uid, $t]
        )->fetch(PDO::FETCH_ASSOC);

        $utc   = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($plan['start_date'], $utc);
        $end   = new DateTimeImmutable($plan['end_date'], $utc);
        $total = intdiv((int) $start->diff($end)->days, 7) + 1;
        $since = (int) $start->diff($today)->format('%r%a');
        $due   = (int) $a['due'];

        return [
            'goal'            => $plan['goal'],
            'start_date'      => $plan['start_date'],
            'end_date'        => $plan['end_date'],
            'week'            => $since < 0 ? 0 : min($total, intdiv($since, 7) + 1),
            'total_weeks'     => $total,
            'days_left'       => (int) $today->diff($end)->format('%r%a'),
            'due'             => $due,
            'completed'       => (int) $a['completed'],
            'skipped'         => (int) $a['skipped'],
            'missed'          => (int) $a['missed'],
            'completion_rate' => $due > 0 ? (int) round(100 * (int) $a['completed'] / $due) : null,
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
        $t    = $today->format('Y-m-d');
        $from = $today->modify('-55 days')->format('Y-m-d');

        $actual = [];
        $rows = $this->db->raw(
            'SELECT FLOOR(DATEDIFF(?, activity_date) / 7) AS b, SUM(distance_km) AS km
               FROM activities WHERE user_id = ? AND activity_date BETWEEN ? AND ? GROUP BY b',
            [$t, $uid, $from, $t]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $actual[(int) $r['b']] = (float) $r['km'];
        }

        $planned = [];
        $rows = $this->db->raw(
            "SELECT FLOOR(DATEDIFF(?, s.scheduled_date) / 7) AS b, SUM(s.planned_distance_km) AS km
               FROM training_sessions s JOIN training_plans p ON p.id = s.plan_id
              WHERE s.user_id = ? AND p.status = 'active' AND s.status <> 'skipped'
                AND s.scheduled_date BETWEEN ? AND ? GROUP BY b",
            [$t, $uid, $from, $t]
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $planned[(int) $r['b']] = (float) $r['km'];
        }

        $weeks = [];
        for ($i = 7; $i >= 0; $i--) {
            $weeks[] = [
                'start'      => $today->modify('-' . (6 + 7 * $i) . ' days')->format('Y-m-d'),
                'end'        => $today->modify('-' . (7 * $i) . ' days')->format('Y-m-d'),
                'actual_km'  => round($actual[$i] ?? 0, 1),
                'planned_km' => round($planned[$i] ?? 0, 1),
            ];
        }
        return $weeks;
    }

    private function recent($uid)
    {
        $rows = $this->db->raw(
            'SELECT id, activity_date, distance_km, duration_seconds, effort, session_id
               FROM activities WHERE user_id = ? ORDER BY activity_date DESC, id DESC LIMIT 5',
            [$uid]
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(function ($r) {
            [, $label] = $this->pace((int) $r['duration_seconds'], (float) $r['distance_km']);
            return [
                'id'               => (int) $r['id'],
                'activity_date'    => $r['activity_date'],
                'distance_km'      => (float) $r['distance_km'],
                'duration_seconds' => (int) $r['duration_seconds'],
                'pace_label'       => $label,
                'effort'           => $r['effort'] !== null ? (int) $r['effort'] : null,
                'from_plan'        => $r['session_id'] !== null,
            ];
        }, $rows);
    }
}