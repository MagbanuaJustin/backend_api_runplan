<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: PlanModel
 * 
 * Automatically generated via CLI.
 */


class PlanModel extends Model {
    protected $table = 'training_plans';
    protected $primary_key = 'id';
    
    
    const RACE_KM      = ['5k' => 5.0, '10k' => 10.0, 'half_marathon' => 21.1, 'marathon' => 42.2];
    const MIN_WEEKS    = ['5k' => 4, '10k' => 6, 'half_marathon' => 8, 'marathon' => 12];
    const MAX_WEEKS    = 30;
    const PEAK_LONG    = ['5k' => 10.0, '10k' => 14.0, 'half_marathon' => 20.0, 'marathon' => 32.0, 'general_fitness' => 12.0];
    const TAPER        = ['5k' => [0.6], '10k' => [0.6], 'half_marathon' => [0.7, 0.5],
                          'marathon' => [0.75, 0.6, 0.45], 'general_fitness' => []];
    const MAX_SESSIONS = ['beginner' => 4, 'intermediate' => 5, 'advanced' => 6];
    const START_LONG   = ['beginner' => 4.0, 'intermediate' => 6.0, 'advanced' => 8.0];
    const EASY_PACE    = ['beginner' => 480, 'intermediate' => 390, 'advanced' => 330]; // seconds per km
    const MAX_EASY     = ['beginner' => 5.0, 'intermediate' => 7.0, 'advanced' => 10.0];
    const RACE_PACE    = ['5k' => 0.92, '10k' => 0.95, 'half_marathon' => 1.0, 'marathon' => 1.05];
    const DOW          = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    const NOTES        = [
        'easy'     => 'Easy, conversational pace.',
        'long'     => 'Long run: steady and relaxed. Walk breaks are fine.',
        'tempo'    => 'Warm up, then a comfortably hard effort for the main part.',
        'interval' => 'Short fast efforts with easy jogging in between.',
        'race'     => 'Race day. Start easy and enjoy it.',
    ];

    // ------------------------------------------------------------------
    // Generator. Returns [result, errors]
    // ------------------------------------------------------------------
    public function build(array $p, DateTimeImmutable $today, array $opts = [])
    {
        $utc   = new DateTimeZone('UTC');
        $goal  = $p['goal'];
        $exp   = $p['experience'];
        $start = $today->modify('+1 day');
        $race  = null;
        $rebuild = !empty($opts['rebuild']);

        if ($goal === 'general_fitness') {
            $weeks = 8;
            $end   = $start->modify('+55 days');
        } else {
            $race  = new DateTimeImmutable($p['target_race_date'], $utc);
            $days  = (int) $start->diff($race)->format('%r%a');
            $weeks = (int) ceil(($days + 1) / 7);

            if ($rebuild) {
                // A rebuild must never be blocked by the "too close" rule: a runner whose race
                // is weeks away still needs their remaining schedule rebuilt.
                if ($weeks < 1) {
                    return [null, ['target_race_date' =>
                        'Your race date has already passed. Update it in your profile first.']];
                }
            } else {
                if ($weeks < self::MIN_WEEKS[$goal]) {
                    return [null, ['target_race_date' =>
                        'Your race is too close for this goal. A safe plan needs at least '
                        . self::MIN_WEEKS[$goal] . ' weeks.']];
                }
                if ($weeks > self::MAX_WEEKS) {
                    return [null, ['target_race_date' =>
                        'Your race is more than ' . self::MAX_WEEKS
                        . ' weeks away. Come back closer to the date, or choose general fitness.']];
                }
            }
            $end = $race;
        }

        // ---- training days, spread out, always including the last chosen day ----
        $avail = array_values($p['available_days']);
        $count = count($avail);
        $n     = min($count, self::MAX_SESSIONS[$exp]);
        $chosen = [];
        for ($i = 0; $i < $n; $i++) {
            $idx = $n === 1 ? 0 : (int) round($i * ($count - 1) / ($n - 1));
            $chosen[] = $avail[$idx];
        }
        $chosen_dow = array_values(array_unique(array_map(fn($d) => self::DOW[$d], $chosen)));
        $long_dow   = end($chosen_dow);

        $quality_dow = null;
        if ($exp !== 'beginner' && count($chosen_dow) >= 3) {
            $cand = $chosen_dow[(int) floor((count($chosen_dow) - 1) / 2)];
            if ($cand !== $long_dow && abs($cand - $long_dow) > 1) {
                $quality_dow = $cand;
            }
        }

        // ---- distances and pace ----
        $peak_target = self::PEAK_LONG[$goal];
        $base_km     = $p['baseline_distance_km'] ?? null;
        if ($rebuild && !empty($opts['start_long'])) {
            $L0 = round(max(3.0, min((float) $opts['start_long'], 0.9 * $peak_target)), 1);
        } else {
            $L0 = $base_km ? (float) $base_km : self::START_LONG[$exp];
            $L0 = round(max(3.0, min($L0, 0.7 * $peak_target)), 1);
        }

        $easy_pace = !empty($p['baseline_pace'])
            ? (int) round($p['baseline_pace'] * 1.10)
            : self::EASY_PACE[$exp];
        $easy_pace = (float) max(240, min(720, $easy_pace));

        // ---- week-by-week long run ----
        $taper_mult  = $race ? self::TAPER[$goal] : [];
        $build_weeks = $weeks - count($taper_mult);
        $week     = [];
        $prev     = $L0;
        $achieved = 0.0;

        // Reach the peak at the END of the build phase, never faster than ~10% a week
        $prog_total = 0;
        for ($w = 1; $w <= $build_weeks; $w++) {
            if ($w % 4 !== 0) { $prog_total++; }
        }
        $step = $prog_total > 1 ? ($peak_target - $L0) / ($prog_total - 1) : 0;
        $k = 0;

        for ($w = 1; $w <= $build_weeks; $w++) {
            if ($w % 4 === 0) {
                $week[$w] = ['long' => max(3.0, round($prev * 0.75, 1)), 'kind' => 'cutback'];
                continue;
            }
            $k++;
            if ($k === 1) {
                $long = $L0;
            } else {
                $linear = $L0 + $step * ($k - 1);
                $long = min($peak_target, round($prev * 1.10 + 0.4, 1), round($linear, 1));
            }
            $prev = $long;
            $achieved = max($achieved, $long);
            $week[$w] = ['long' => $long, 'kind' => 'build'];
        }
        foreach ($taper_mult as $i => $mult) {
            $week[$build_weeks + $i + 1] = ['long' => max(3.0, round($achieved * $mult, 1)), 'kind' => 'taper'];
        }

        if ($rebuild) {
            $achieved = max($achieved, $L0);
        }

        $warnings = [];
        if ($achieved < 0.85 * $peak_target) {
            $warnings[] = 'Your longest run peaks at ' . $achieved
                . ' km because of the time available. A later start date would allow more.';
        }
        if (empty($p['baseline_distance_km'])) {
            $warnings[] = 'No recent run was entered, so pace and starting distance are estimated from your experience.';
        }

        // ---- sessions ----
        $sessions = [];
        $race_eve = $race ? $race->modify('-1 day') : null;

        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $w    = intdiv((int) $start->diff($date)->days, 7) + 1;
            $info = $week[$w] ?? null;
            if ($info === null) {
                break;
            }

            if ($race && $date == $race) {
                $sessions[] = $this->make_session($date, 'race', self::RACE_KM[$goal],
                    $easy_pace * self::RACE_PACE[$goal]);
                continue;
            }
            if ($race_eve && $date == $race_eve) {
                continue; // rest the day before the race
            }

            $dow = (int) $date->format('N');
            if (!in_array($dow, $chosen_dow, true)) {
                continue;
            }

            $final = ($race !== null && $w === $weeks);
            $long  = $info['long'];
            $factor = ($info['kind'] === 'taper') ? 0.3 : 0.4;
            $easy   = max(3.0, min(self::MAX_EASY[$exp], round($long * $factor, 1)));

            if ($dow === $long_dow && !$final) {
                $sessions[] = $this->make_session($date, 'long', $long, $easy_pace * 1.03);
            } elseif ($dow === $quality_dow && $info['kind'] === 'build' && $w >= 3) {
                $type = ($exp === 'advanced' && $w % 2 === 0) ? 'interval' : 'tempo';
                $sessions[] = $this->make_session($date, $type, max(3.0, round($easy * 0.9, 1)),
                    $easy_pace * ($type === 'tempo' ? 0.88 : 0.82));
            } else {
                $km = $final ? 3.0 : $easy;
                $sessions[] = $this->make_session($date, 'easy', $km, $easy_pace);
            }
        }

        return [[
            'plan' => [
                'goal'              => $goal,
                'start_date'        => $start->format('Y-m-d'),
                'end_date'          => $end->format('Y-m-d'),
                'sessions_per_week' => count($chosen_dow),
            ],
            'sessions' => $sessions,
            'warnings' => $warnings,
        ], []];
    }

    private function make_session(DateTimeImmutable $date, $type, $km, $pace)
    {
        return [
            'scheduled_date'           => $date->format('Y-m-d'),
            'type'                     => $type,
            'planned_distance_km'      => round($km, 2),
            'planned_duration_seconds' => (int) round($km * $pace),
            'notes'                    => self::NOTES[$type],
        ];
    }

    // ------------------------------------------------------------------
    // Database
    // ------------------------------------------------------------------
    public function create_draft($user_id, array $plan, array $sessions)
    {
        $user_id = (int) $user_id;
        $this->db->transaction();

        $this->db->raw("DELETE FROM training_plans WHERE user_id = ? AND status = 'draft'", [$user_id]);

        $this->db->table($this->table)->insert([
            'user_id'           => $user_id,
            'goal'              => $plan['goal'],
            'start_date'        => $plan['start_date'],
            'end_date'          => $plan['end_date'],
            'sessions_per_week' => $plan['sessions_per_week'],
            'status'            => 'draft',
            'version'           => 1,
        ]);
        $plan_id = (int) $this->db->last_id();

        $rows = [];
        $args = [];
        foreach ($sessions as $s) {
            $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($args, $plan_id, $user_id, $s['scheduled_date'], $s['type'],
                $s['planned_distance_km'], $s['planned_duration_seconds'], 'planned', $s['notes']);
        }
        $this->db->raw(
            'INSERT INTO training_sessions
               (plan_id, user_id, scheduled_date, type, planned_distance_km,
                planned_duration_seconds, status, notes)
             VALUES ' . implode(', ', $rows),
            $args
        );

        $this->db->commit();
        return $plan_id;
    }

    public function find_for_user($user_id, $plan_id)
    {
        $row = $this->db->raw(
            'SELECT * FROM training_plans WHERE id = ? AND user_id = ? LIMIT 1',
            [(int) $plan_id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->with_sessions($row) : null;
    }

    public function current_for_user($user_id)
    {
        $this->mark_missed_for_user($user_id);
        $out  = ['active' => null, 'draft' => null];
        $rows = $this->db->raw(
            "SELECT * FROM training_plans WHERE user_id = ? AND status IN ('active','draft') ORDER BY id DESC",
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            if ($out[$r['status']] === null) {
                $out[$r['status']] = $this->with_sessions($r);
            }
        }
        return $out;
    }

    public function approve_for_user($user_id, $plan_id)
    {
        $user_id = (int) $user_id;
        $plan_id = (int) $plan_id;

        $draft = $this->db->raw(
            "SELECT id, version FROM training_plans WHERE id = ? AND user_id = ? AND status = 'draft' LIMIT 1",
            [$plan_id, $user_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$draft) {
            return null;
        }

        $this->db->transaction();
        $this->db->raw(
            "UPDATE training_plans SET status = 'archived', updated_at = NOW()
              WHERE user_id = ? AND status = 'active'", [$user_id]);
        $this->db->raw(
            "UPDATE training_plans SET status = 'active', approved_at = NOW(), updated_at = NOW()
              WHERE id = ? AND user_id = ?", [$plan_id, $user_id]);
        $this->add_revision($user_id, $plan_id, (int) $draft['version'], 'Plan approved');
        $this->db->commit();

        return $this->find_for_user($user_id, $plan_id);
    }

    public function mark_missed_for_user($user_id)
    {
        $this->db->raw(
            "UPDATE training_sessions s
               JOIN training_plans p ON p.id = s.plan_id
                SET s.status = 'missed', s.updated_at = NOW()
              WHERE s.user_id = ? AND p.status = 'active' AND s.status = 'planned'
                AND s.scheduled_date < DATE_SUB(UTC_DATE(), INTERVAL 1 DAY)",
            [(int) $user_id]
        );
    }

    // Returns the updated session, null if not found, false if it is already completed
    public function set_session_status($user_id, $session_id, $status)
    {
        $row = $this->db->raw(
            "SELECT s.id, s.status FROM training_sessions s
               JOIN training_plans p ON p.id = s.plan_id
              WHERE s.id = ? AND s.user_id = ? AND p.status = 'active' LIMIT 1",
            [(int) $session_id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }
        if ($row['status'] === 'completed') {
            return false;
        }

        $this->db->raw(
            "UPDATE training_sessions SET status = ?, updated_at = NOW()
              WHERE id = ? AND user_id = ? AND status <> 'completed'",
            [$status, (int) $session_id, (int) $user_id]
        );

        $s = $this->db->raw(
            'SELECT id, scheduled_date, type, planned_distance_km, planned_duration_seconds, status, notes
               FROM training_sessions WHERE id = ? AND user_id = ?',
            [(int) $session_id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        return $this->present_session($s);
    }

    private function present_session(array $s)
    {
        return [
            'id'                       => (int) $s['id'],
            'scheduled_date'           => $s['scheduled_date'],
            'type'                     => $s['type'],
            'planned_distance_km'      => $s['planned_distance_km'] !== null ? (float) $s['planned_distance_km'] : null,
            'planned_duration_seconds' => $s['planned_duration_seconds'] !== null ? (int) $s['planned_duration_seconds'] : null,
            'status'                   => $s['status'],
            'notes'                    => $s['notes'],
        ];
    }

        // ---------- revisions ----------
    private function add_revision($user_id, $plan_id, $version, $summary)
    {
        $snapshot = $this->db->raw(
            'SELECT id, scheduled_date, type, planned_distance_km, planned_duration_seconds, status
               FROM training_sessions WHERE plan_id = ? AND user_id = ? ORDER BY scheduled_date',
            [(int) $plan_id, (int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        $this->db->raw(
            'INSERT INTO plan_revisions (plan_id, user_id, version, change_summary, snapshot_json)
             VALUES (?, ?, ?, ?, ?)',
            [(int) $plan_id, (int) $user_id, (int) $version, mb_substr($summary, 0, 255), json_encode($snapshot)]
        );
    }

    private function bump_version($user_id, $plan_id, $summary)
    {
        $this->db->raw(
            'UPDATE training_plans SET version = version + 1, updated_at = NOW() WHERE id = ? AND user_id = ?',
            [(int) $plan_id, (int) $user_id]
        );
        $v = (int) $this->db->raw(
            'SELECT version FROM training_plans WHERE id = ? AND user_id = ?',
            [(int) $plan_id, (int) $user_id]
        )->fetchColumn();
        $this->add_revision($user_id, $plan_id, $v, $summary);
        return $v;
    }

    private function insert_sessions($plan_id, $user_id, array $sessions)
    {
        if (!$sessions) {
            return;
        }
        $rows = [];
        $args = [];
        foreach ($sessions as $s) {
            $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
            array_push($args, (int) $plan_id, (int) $user_id, $s['scheduled_date'], $s['type'],
                $s['planned_distance_km'], $s['planned_duration_seconds'], 'planned', $s['notes']);
        }
        $this->db->raw(
            'INSERT INTO training_sessions
               (plan_id, user_id, scheduled_date, type, planned_distance_km,
                planned_duration_seconds, status, notes)
             VALUES ' . implode(', ', $rows),
            $args
        );
    }

    public function revisions_for_user($user_id, $plan_id)
    {
        $owned = $this->db->raw(
            'SELECT id FROM training_plans WHERE id = ? AND user_id = ? LIMIT 1',
            [(int) $plan_id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$owned) {
            return null;
        }

        $rows = $this->db->raw(
            'SELECT version, change_summary, created_at FROM plan_revisions
              WHERE plan_id = ? AND user_id = ? ORDER BY version DESC',
            [(int) $plan_id, (int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn($r) => [
            'version'    => (int) $r['version'],
            'summary'    => $r['change_summary'],
            'created_at' => $r['created_at'],
        ], $rows);
    }

    // Returns [session, null] or [null, [http_status, message]]
    public function reschedule_session($user_id, $session_id, $new_date)
    {
        $uid = (int) $user_id;
        $utc = new DateTimeZone('UTC');

        $s = $this->db->raw(
            "SELECT s.id, s.plan_id, s.scheduled_date, s.type, s.status, p.goal, p.start_date, p.end_date
               FROM training_sessions s JOIN training_plans p ON p.id = s.plan_id
              WHERE s.id = ? AND s.user_id = ? AND p.status = 'active' LIMIT 1",
            [(int) $session_id, $uid]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$s) {
            return [null, [404, 'Session not found.']];
        }
        if ($s['status'] === 'completed') {
            return [null, [409, 'A completed run cannot be moved.']];
        }
        if ($s['type'] === 'race') {
            return [null, [422, 'Race day cannot be moved. Change your race date in your profile and rebuild the plan.']];
        }

        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $new_date, $utc);
        if (!$d || $d->format('Y-m-d') !== $new_date) {
            return [null, [422, 'Enter a valid date.']];
        }

        $min  = max((new DateTimeImmutable('today', $utc))->modify('-1 day')->format('Y-m-d'), $s['start_date']);
        $last = $s['goal'] === 'general_fitness'
            ? $s['end_date']
            : (new DateTimeImmutable($s['end_date'], $utc))->modify('-2 days')->format('Y-m-d');

        if ($new_date < $min || $new_date > $last) {
            return [null, [422, "Choose a date between $min and $last."]];
        }
        if ($new_date === $s['scheduled_date']) {
            return [null, [422, 'That is already the date of this session.']];
        }

        $busy = $this->db->raw(
            'SELECT id FROM training_sessions WHERE plan_id = ? AND scheduled_date = ? LIMIT 1',
            [(int) $s['plan_id'], $new_date]
        )->fetch(PDO::FETCH_ASSOC);
        if ($busy) {
            return [null, [422, 'Another session is already planned on that day.']];
        }

        $this->db->transaction();
        $this->db->raw(
            "UPDATE training_sessions SET scheduled_date = ?, status = 'planned', updated_at = NOW()
              WHERE id = ? AND user_id = ?",
            [$new_date, (int) $session_id, $uid]
        );
        $this->bump_version($uid, $s['plan_id'],
            'Moved ' . $s['type'] . ' run from ' . $s['scheduled_date'] . ' to ' . $new_date);
        $this->db->commit();

        $row = $this->db->raw(
            'SELECT id, scheduled_date, type, planned_distance_km, planned_duration_seconds, status, notes
               FROM training_sessions WHERE id = ? AND user_id = ?',
            [(int) $session_id, $uid]
        )->fetch(PDO::FETCH_ASSOC);

        return [$this->present_session($row), null];
    }

    // Returns [data, null], [null, 'not_found'] or [null, [field => message]]
    public function rebuild_remaining($user_id, $plan_id, array $profile, DateTimeImmutable $today, $recent_long)
    {
        $uid = (int) $user_id;
        $pid = (int) $plan_id;

        $plan = $this->db->raw(
            "SELECT id FROM training_plans WHERE id = ? AND user_id = ? AND status = 'active' LIMIT 1",
            [$pid, $uid]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return [null, 'not_found'];
        }

        [$built, $errors] = $this->build($profile, $today, ['rebuild' => true, 'start_long' => $recent_long]);
        if ($errors) {
            return [null, $errors];
        }

        $start = $built['plan']['start_date'];
        $taken = array_column($this->db->raw(
            "SELECT scheduled_date FROM training_sessions
              WHERE plan_id = ? AND user_id = ? AND scheduled_date >= ? AND status = 'completed'",
            [$pid, $uid, $start]
        )->fetchAll(PDO::FETCH_ASSOC), 'scheduled_date');

        $sessions = array_values(array_filter(
            $built['sessions'],
            fn($s) => !in_array($s['scheduled_date'], $taken, true)
        ));

        $this->db->transaction();
        $this->db->raw(
            "DELETE FROM training_sessions
              WHERE plan_id = ? AND user_id = ? AND scheduled_date >= ? AND status <> 'completed'",
            [$pid, $uid, $start]
        );
        $this->insert_sessions($pid, $uid, $sessions);
        $this->db->raw(
            'UPDATE training_plans SET goal = ?, end_date = ?, sessions_per_week = ?, updated_at = NOW()
              WHERE id = ? AND user_id = ?',
            [$built['plan']['goal'], $built['plan']['end_date'], $built['plan']['sessions_per_week'], $pid, $uid]
        );
        $this->bump_version($uid, $pid, 'Rebuilt the remaining plan from ' . $start . ' using your profile and recent runs');
        $this->db->commit();

        $data = $this->find_for_user($uid, $pid);
        $data['warnings'] = $built['warnings'];
        return [$data, null];
    }

    private function with_sessions(array $r)
    {
        $sessions = $this->db->raw(
            'SELECT id, scheduled_date, type, planned_distance_km, planned_duration_seconds, status, notes
               FROM training_sessions WHERE plan_id = ? AND user_id = ? ORDER BY scheduled_date',
            [(int) $r['id'], (int) $r['user_id']]
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'plan' => [
                'id'                => (int) $r['id'],
                'goal'              => $r['goal'],
                'start_date'        => $r['start_date'],
                'end_date'          => $r['end_date'],
                'sessions_per_week' => (int) $r['sessions_per_week'],
                'status'            => $r['status'],
                'version'           => (int) $r['version'],
                'approved_at'       => $r['approved_at'],
            ],
            'sessions' => array_map([$this, 'present_session'], $sessions),
        ];
    }
}