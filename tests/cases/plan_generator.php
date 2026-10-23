<?php
/**
 * PlanModel::build() - the training-plan generator.
 *
 * These are the rules that decide whether a runner gets a safe schedule, so
 * they are the highest-value thing in the app to pin down with a test. The
 * "too close / too far" pair in particular was broken in production: the
 * too-close branch returned the too-far message and the upper bound had been
 * deleted outright, letting an arbitrarily distant race date produce a plan
 * over a year long. Each rule below now has a named test.
 */

$plan = new PlanModel();

// ---------------------------------------------------------------- guards

test('plan: general fitness ignores the race-date window entirely', function () use ($plan) {
    list($data, $errors) = $plan->build(rp_profile('general_fitness', null), rp_today());

    assert_same(array(), $errors, 'no validation errors expected');
    assert_same(rp_start()->format('Y-m-d'), $data['plan']['start_date'], 'plan starts tomorrow');
    assert_same(rp_start()->modify('+55 days')->format('Y-m-d'), $data['plan']['end_date'], '8 weeks = 56 days inclusive');
    assert_same(2, $data['plan']['sessions_per_week'], 'one session per chosen day');
});

test('plan: rejects a race that is too close, and says so accurately', function () use ($plan) {
    // 5k needs 4 weeks; 10 days is not that.
    list($data, $errors) = $plan->build(rp_profile('5k', rp_race_in(10)), rp_today());

    assert_same(null, $data, 'nothing is generated');
    assert_true(isset($errors['target_race_date']), 'error is attached to the race date');
    assert_contains('too close', $errors['target_race_date'], 'message must mention the race being too close');
    assert_contains('4 weeks', $errors['target_race_date'], 'message must quote the minimum for a 5k');
});

test('plan: rejects a race further away than MAX_WEEKS', function () use ($plan) {
    // 40 weeks out is past the 30-week ceiling.
    list($data, $errors) = $plan->build(rp_profile('5k', rp_race_in(40 * 7)), rp_today());

    assert_same(null, $data, 'nothing is generated');
    assert_true(isset($errors['target_race_date']), 'error is attached to the race date');
    assert_contains('more than 30 weeks', $errors['target_race_date']);
});

test('plan: each goal carries its own minimum week count', function () use ($plan) {
    $cases = array('5k' => 4, '10k' => 6, 'half_marathon' => 8, 'marathon' => 12);

    foreach ($cases as $goal => $min_weeks) {
        // One week short of the minimum must fail, the minimum itself must pass.
        list(, $short) = $plan->build(rp_profile($goal, rp_race_in(($min_weeks - 1) * 7 - 1)), rp_today());
        assert_true(isset($short['target_race_date']), $goal . ' should reject ' . ($min_weeks - 1) . ' weeks');

        list(, $exact) = $plan->build(rp_profile($goal, rp_race_in($min_weeks * 7 - 1)), rp_today());
        assert_same(array(), $exact, $goal . ' should accept exactly ' . $min_weeks . ' weeks');
    }
});

// ---------------------------------------------------------------- rebuild

test('rebuild: a past race date is refused with actionable advice', function () use ($plan) {
    $past = rp_today()->modify('-3 weeks')->format('Y-m-d');
    list($data, $errors) = $plan->build(rp_profile('half_marathon', $past), rp_today(), array('rebuild' => true));

    assert_same(null, $data);
    assert_contains('already passed', $errors['target_race_date']);
});

test('rebuild: deliberately NOT bound by the min/max week rules', function () use ($plan) {
    // Two weeks out: a fresh 5k plan would be refused as unsafe. A rebuild of the
    // remainder must still work, or a runner mid-plan could never correct course.
    list(, $errors_short) = $plan->build(rp_profile('5k', rp_race_in(10)), rp_today(), array('rebuild' => true));
    assert_same(array(), $errors_short, 'rebuild proceeds when the race is close');

    // 40 weeks out: past the ceiling for a new plan, but the plan already exists.
    list(, $errors_far) = $plan->build(rp_profile('5k', rp_race_in(40 * 7)), rp_today(), array('rebuild' => true));
    assert_same(array(), $errors_far, 'rebuild proceeds when the race is far');
});

test('rebuild: a recent long run sets the starting distance, capped at 90% of goal peak', function () use ($plan) {
    // A rebuild seeds L0 from the runner's recent max rather than from their
    // stated experience. 5k peak long is 10 km, so 100 km of "recent form" must
    // be clamped to 9.0 - the cap on the STARTING week, not on the peak the plan
    // may still build towards.
    $make = function ($start_long) use ($plan) {
        list($data, $errors) = $plan->build(
            rp_profile('5k', rp_race_in(8 * 7 - 1)),
            rp_today(),
            array('rebuild' => true, 'start_long' => $start_long)
        );
        assert_same(array(), $errors);
        $longs = array_values(array_filter($data['sessions'], function ($s) { return $s['type'] === 'long'; }));
        return $longs;
    };

    $longs = $make(100.0);
    assert_true($longs !== array(), 'a long run should be scheduled');
    assert_same(9.0, (float) $longs[0]['planned_distance_km'], 'first long run starts at the 90% ceiling');

    // Without recent form the plan falls back to the experience-based start
    // (intermediate = 6 km), which is what makes the two paths distinguishable.
    $fallback = $make(null);
    assert_same(6.0, (float) $fallback[0]['planned_distance_km'], 'no recent run means experience-based start');

    // And a modest recent run is used as-is, not floored up to the ceiling.
    $modest = $make(7.0);
    assert_same(7.0, (float) $modest[0]['planned_distance_km'], 'a 7 km recent long should be honoured');
});

// ---------------------------------------------------------------- schedule

test('schedule: sessions land only on chosen days, plus race day', function () use ($plan) {
    $race = rp_race_in(8 * 7 - 1);
    list($data, $errors) = $plan->build(rp_profile('5k', $race), rp_today());
    assert_same(array(), $errors);

    $allowed = array(2, 6);   // tue, sat
    foreach ($data['sessions'] as $s) {
        $dow = (int) (new DateTimeImmutable($s['scheduled_date']))->format('N');
        assert_true(
            in_array($dow, $allowed, true) || $s['type'] === 'race',
            $s['scheduled_date'] . ' (' . $s['type'] . ') is outside the runner\'s days'
        );
    }
});

test('schedule: race day is the last session and the day before it is a rest day', function () use ($plan) {
    // The plan starts on a Saturday and the chosen days are Tue/Sat, so putting
    // the race on a Sunday makes the day before it a scheduled long-run day.
    // If that rest rule ever regresses, a runner tapers into the race on tired
    // legs and nothing else in the output looks wrong.
    $race_date = rp_race_in(50);
    assert_same('7', (new DateTimeImmutable($race_date))->format('N'), 'fixture must place the race on a Sunday');

    list($data) = $plan->build(rp_profile('5k', $race_date), rp_today());

    $last = end($data['sessions']);
    assert_same('race', $last['type'], 'plan ends on the race');
    assert_same($race_date, $last['scheduled_date']);
    assert_same(5.0, (float) $last['planned_distance_km'], 'a 5k is 5 km');

    $eve = (new DateTimeImmutable($race_date))->modify('-1 day')->format('Y-m-d');
    $on_eve = array_values(array_filter($data['sessions'], function ($s) use ($eve) { return $s['scheduled_date'] === $eve; }));
    assert_same(array(), $on_eve, 'the day before a race is always rest, even when it is a training day');
});

test('schedule: nothing is placed outside the plan window', function () use ($plan) {
    list($data) = $plan->build(rp_profile('half_marathon', rp_race_in(12 * 7 - 1)), rp_today());

    $start = rp_start()->format('Y-m-d');
    $end = $data['plan']['end_date'];
    foreach ($data['sessions'] as $s) {
        $d = $s['scheduled_date'];
        assert_true($d >= $start, $d . ' is before start ' . $start);
        assert_true($d <= $end, $d . ' is after end ' . $end);
    }
});

test('schedule: the taper unloads before the race', function () use ($plan) {
    // Marathon tapers over 3 weeks, so the final long run must be clearly shorter
    // than the peak. A plan without tapering is the failure mode this guards.
    list($data) = $plan->build(rp_profile('marathon', rp_race_in(16 * 7 - 1)), rp_today());

    $longs = array_values(array_filter($data['sessions'], function ($s) { return $s['type'] === 'long'; }));
    assert_true(count($longs) >= 3, 'a 16-week marathon needs several long runs');

    $distances = array_map(function ($s) { return (float) $s['planned_distance_km']; }, $longs);
    $peak = max($distances);
    $final = end($distances);
    assert_true($final < $peak, 'last long run (' . $final . ' km) should be below the peak (' . $peak . ' km)');
});

test('schedule: every session has sane, self-consistent numbers', function () use ($plan) {
    list($data) = $plan->build(rp_profile('10k', rp_race_in(10 * 7 - 1)), rp_today());

    foreach ($data['sessions'] as $s) {
        $km = (float) $s['planned_distance_km'];
        $sec = (int) $s['planned_duration_seconds'];

        assert_true($km >= 1.0, $s['scheduled_date'] . ' distance too small: ' . $km);
        assert_true($km <= 45.0, $s['scheduled_date'] . ' distance too large: ' . $km);
        assert_true($sec > 0, $s['scheduled_date'] . ' needs a positive duration');

        $pace = $sec / $km;   // seconds per km
        assert_true($pace > 150 && $pace < 900, $s['scheduled_date'] . ' implausible pace: ' . round($pace) . ' s/km');
        assert_true(isset(PlanModel::NOTES[$s['type']]), 'unknown session type ' . $s['type']);
    }
});

test('schedule: a late start is flagged instead of silently producing a weak plan', function () use ($plan) {
    // Plenty of weeks, but only 3 of them of build-up before a marathon taper:
    // the peak will miss the target and the runner must be told.
    list($data, $errors) = $plan->build(rp_profile('marathon', rp_race_in(13 * 7 - 1)), rp_today());

    assert_same(array(), $errors, 'still a legal plan');
    assert_true($data['warnings'] !== array(), 'a warning is expected');

    $has_peak_warning = false;
    foreach ($data['warnings'] as $w) {
        if (strpos($w, 'peaks at') !== false) { $has_peak_warning = true; }
    }
    assert_true($has_peak_warning, 'warnings should mention the reduced peak: ' . json_encode($data['warnings']));
});
