#!/usr/bin/env php
<?php
/**
 * RunPlan test runner - zero dependencies.
 *
 *   php tests/run.php            run everything
 *   php tests/run.php plan       run cases whose name contains "plan"
 *
 * There is no vendor/ directory in this project and the framework is vendored
 * rather than installed, so the tests deliberately do not need PHPUnit or
 * Composer: the plan generator is pure date/arithmetic code and a plain
 * assertion is enough to pin it down. If PHPUnit is added later, each case file
 * can be wrapped without rewriting the assertions.
 *
 * What this protects, concretely:
 *   - PlanModel::build()'s minimum/maximum week rules. A regression here either
 *     blocks a runner who has plenty of time or green-lights a plan years long.
 *   - The rebuild path staying exempt from the "too close" rule, which is the
 *     whole point of being able to fix a plan mid-cycle.
 *   - Layering: no controller is allowed to run SQL. That rule has been broken
 *     before, so it is now enforced by a test instead of by review comments.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "tests/run.php must be run from the command line.\n");
    exit(2);
}

define('RP_ROOT', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('PREVENT_DIRECT_ACCESS', true);

// Minimal framework surface. PlanModel only needs to exist as a subclass of
// Model and to not touch the database, which build() does not.
if (!function_exists('config_item')) {
    function config_item($item) { return null; }
}
require_once RP_ROOT . 'scheme/kernel/Model.php';
require_once RP_ROOT . 'app/models/PlanModel.php';

// ---------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------
$GLOBALS['rp_tests'] = array();
$GLOBALS['rp_current'] = null;

function test($name, callable $fn)
{
    $GLOBALS['rp_tests'][] = array($name, $fn);
}

function rp_fail($message)
{
    throw new RuntimeException($message);
}

function assert_same($expected, $actual, $label = '')
{
    if ($expected !== $actual) {
        rp_fail(sprintf(
            '%sexpected %s, got %s',
            $label === '' ? '' : $label . ': ',
            json_encode($expected),
            json_encode($actual)
        ));
    }
}

function assert_true($condition, $label = 'expected true')
{
    if ($condition !== true) {
        rp_fail($label);
    }
}

function assert_false($condition, $label = 'expected false')
{
    if ($condition !== false) {
        rp_fail($label);
    }
}

function assert_contains($needle, $haystack, $label = '')
{
    if (strpos((string) $haystack, (string) $needle) === false) {
        rp_fail(sprintf(
            '%sexpected to find %s in: %s',
            $label === '' ? '' : $label . ': ',
            json_encode((string) $needle),
            json_encode((string) $haystack)
        ));
    }
}

function assert_count($expected, $value, $label = '')
{
    $n = is_array($value) ? count($value) : -1;
    if ($n !== $expected) {
        rp_fail(sprintf('%sexpected %d items, got %d', $label === '' ? '' : $label . ': ', $expected, $n));
    }
}

/** A profile array in the shape PlanModel::build() expects. */
function rp_profile($goal, $race_date, array $overrides = array())
{
    return array_merge(array(
        'goal'                => $goal,
        'experience'          => 'intermediate',
        'target_race_date'    => $race_date,
        'available_days'      => array('tue', 'sat'),
        'baseline_distance_km' => null,
        'baseline_pace'       => null,
    ), $overrides);
}

/** Fixed "today" so the suite is deterministic and does not rot. */
function rp_today()
{
    return new DateTimeImmutable('2026-10-23', new DateTimeZone('UTC'));
}

/** start_date is always today + 1 day. */
function rp_start()
{
    return rp_today()->modify('+1 day');
}

/** Race date N days after the plan starts. */
function rp_race_in($days_from_start)
{
    return rp_start()->modify('+' . (int) $days_from_start . ' days')->format('Y-m-d');
}

// ---------------------------------------------------------------------
// Cases
// ---------------------------------------------------------------------
foreach (glob(__DIR__ . '/cases/*.php') ?: array() as $case_file) {
    require $case_file;
}

// ---------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------
$filter = $argv[1] ?? null;
$passed = 0;
$failed = array();
$skipped = 0;

foreach ($GLOBALS['rp_tests'] as $entry) {
    list($name, $fn) = $entry;

    if ($filter !== null && stripos($name, $filter) === false) {
        $skipped++;
        continue;
    }

    $GLOBALS['rp_current'] = $name;
    try {
        $fn();
        $passed++;
        echo "  ok    " . $name . "\n";
    } catch (Throwable $e) {
        $failed[] = array($name, $e->getMessage());
        echo "  FAIL  " . $name . "\n        " . $e->getMessage() . "\n";
    }
}

echo "\n";
$total = $passed + count($failed);
if ($failed) {
    echo sprintf("%d of %d assertions failed (%d skipped by filter).\n", count($failed), $total, $skipped);
    exit(1);
}
echo sprintf("All %d tests passed%s.\n", $passed, $skipped ? ' (' . $skipped . ' filtered out)' : '');
exit(0);
