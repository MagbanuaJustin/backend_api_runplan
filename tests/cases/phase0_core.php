<?php
/**
 * Phase 0 core foundation tests:
 *   - UserModel validation, registration, constant-time login, safe presentation
 *   - RefreshTokenModel HMAC lookup & revocation with configurable column
 *   - ActivityModel + DashboardModel + SessionModel (source='manual' boundary,
 *     date-range filter, longest_recent_run_km, missed-session marking)
 *   - Route-level AuthMiddleware protection & CORS OPTIONS preflight coverage
 *   - HttpClient fake transport seam
 */

require_once RP_ROOT . 'app/models/UserModel.php';
require_once RP_ROOT . 'app/models/RefreshTokenModel.php';
require_once RP_ROOT . 'app/models/PasswordResetModel.php';
require_once RP_ROOT . 'app/models/SecurityEventModel.php';
require_once RP_ROOT . 'app/models/ProfileModel.php';
require_once RP_ROOT . 'app/models/ActivityModel.php';
require_once RP_ROOT . 'app/models/DashboardModel.php';
require_once RP_ROOT . 'app/models/SessionModel.php';
require_once RP_ROOT . 'app/models/AiCoachingModel.php';
require_once RP_ROOT . 'app/libraries/HttpClient.php';
require_once RP_ROOT . 'app/libraries/AnthropicService.php';
require_once RP_ROOT . 'app/libraries/OpenWeatherService.php';
require_once RP_ROOT . 'app/models/WeatherModel.php';

/**
 * Tiny in-memory SQLite adapter matching the subset of LavaLust's Database API
 * (`raw()`, `table()`, `last_id()`, `transaction()`, `commit()`, `roll_back()`)
 * used by RunPlan's models.
 */
class RpSqliteDb
{
    public $pdo;
    private $table_name = '';
    private $columns_sql = '*';
    private $wheres = array();
    private $order = '';
    private $limit_sql = '';

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->bootstrap_schema();
    }

    private function bootstrap_schema()
    {
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                email TEXT NOT NULL UNIQUE,
                password_hash TEXT,
                role TEXT NOT NULL DEFAULT 'runner',
                status TEXT NOT NULL DEFAULT 'active',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE refresh_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL,
                jti TEXT,
                expires_at TEXT NOT NULL,
                revoked_at TEXT DEFAULT NULL
            );
            CREATE TABLE training_plans (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                goal TEXT NOT NULL,
                start_date TEXT NOT NULL,
                end_date TEXT NOT NULL,
                sessions_per_week INTEGER NOT NULL DEFAULT 3,
                status TEXT NOT NULL DEFAULT 'active',
                version INTEGER NOT NULL DEFAULT 1,
                approved_at TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE training_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plan_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                scheduled_date TEXT NOT NULL,
                planned_start_time TEXT DEFAULT NULL,
                type TEXT NOT NULL,
                planned_distance_km REAL,
                planned_duration_seconds INTEGER,
                status TEXT NOT NULL DEFAULT 'planned',
                skip_reason TEXT DEFAULT NULL,
                notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE runner_profiles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL UNIQUE,
                display_name TEXT NOT NULL,
                experience TEXT NOT NULL,
                goal TEXT NOT NULL,
                target_race_date TEXT DEFAULT NULL,
                available_days TEXT NOT NULL,
                desired_sessions_per_week INTEGER DEFAULT NULL,
                preferred_time TEXT DEFAULT NULL,
                baseline_distance_km REAL DEFAULT NULL,
                baseline_pace INTEGER DEFAULT NULL,
                location_label TEXT DEFAULT NULL,
                latitude REAL DEFAULT NULL,
                longitude REAL DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE plan_revisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plan_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                version INTEGER NOT NULL,
                previous_version INTEGER DEFAULT NULL,
                resulting_version INTEGER DEFAULT NULL,
                decision TEXT NOT NULL DEFAULT 'applied',
                change_summary TEXT NOT NULL,
                reason TEXT DEFAULT NULL,
                snapshot_json TEXT NOT NULL,
                proposed_changes TEXT DEFAULT NULL,
                decision_timestamp TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE ai_plan_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                plan_id INTEGER DEFAULT NULL,
                provider TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                request_summary TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE ai_feedback (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                period_start TEXT NOT NULL,
                period_end TEXT NOT NULL,
                metrics_snapshot TEXT NOT NULL,
                feedback_text TEXT DEFAULT NULL,
                provider_status TEXT NOT NULL DEFAULT 'ok',
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (user_id, period_start)
            );
            CREATE TABLE weather_cache (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                location_key TEXT NOT NULL,
                forecast_date TEXT NOT NULL,
                weather_payload TEXT NOT NULL,
                expires_at TEXT NOT NULL,
                UNIQUE (location_key, forecast_date)
            );
            CREATE TABLE activities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_id INTEGER DEFAULT NULL,
                source TEXT NOT NULL DEFAULT 'manual',
                activity_date TEXT NOT NULL,
                distance_km REAL NOT NULL,
                duration_seconds INTEGER NOT NULL,
                effort INTEGER DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE notification_jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                session_id INTEGER NOT NULL,
                scheduled_at TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending'
            );
            CREATE TABLE password_reset_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at TEXT NOT NULL,
                used_at TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            );
            CREATE TABLE account_deletion_requests (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER DEFAULT NULL,
                email_sha256 TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'completed',
                reason TEXT DEFAULT NULL,
                requested_at TEXT NOT NULL,
                completed_at TEXT DEFAULT NULL
            );
            CREATE TABLE auth_security_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER DEFAULT NULL,
                event_type TEXT NOT NULL,
                safe_metadata TEXT DEFAULT NULL,
                created_at TEXT NOT NULL
            );
        ");
    }

    public function raw($sql, array $params = array())
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($params));
        return $stmt;
    }

    public function transaction() { $this->pdo->beginTransaction(); }
    public function commit()      { $this->pdo->commit(); }
    public function roll_back()   { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } }
    public function rollback()    { $this->roll_back(); }
    public function last_id()     { return (int) $this->pdo->lastInsertId(); }

    public function table($name)
    {
        $this->table_name  = $name;
        $this->columns_sql = '*';
        $this->wheres      = array();
        $this->order       = '';
        $this->limit_sql   = '';
        return $this;
    }

    public function select($cols)
    {
        $this->columns_sql = $cols;
        return $this;
    }

    public function where($col, $val)
    {
        $this->wheres[] = array($col, $val);
        return $this;
    }

    public function order_by($col, $dir = 'ASC')
    {
        $this->order = " ORDER BY {$col} {$dir}";
        return $this;
    }

    public function limit($n)
    {
        $this->limit_sql = ' LIMIT ' . (int) $n;
        return $this;
    }

    public function get()
    {
        list($sql, $args) = $this->select_sql(' LIMIT 1');
        $row = $this->raw($sql, $args)->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }

    public function get_all()
    {
        list($sql, $args) = $this->select_sql($this->limit_sql);
        return $this->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function insert(array $data)
    {
        $cols = array_keys($data);
        $ph   = implode(', ', array_fill(0, count($cols), '?'));
        $sql  = "INSERT INTO {$this->table_name} (" . implode(', ', $cols) . ") VALUES ({$ph})";
        $this->raw($sql, array_values($data));
        return $this->last_id();
    }

    public function update(array $data)
    {
        $sets = array();
        $args = array();
        foreach ($data as $k => $v) {
            $sets[] = "{$k} = ?";
            $args[] = $v;
        }
        list($where_sql, $w_args) = $this->where_clause();
        $sql = "UPDATE {$this->table_name} SET " . implode(', ', $sets) . $where_sql;
        return $this->raw($sql, array_merge($args, $w_args));
    }

    private function select_sql($suffix)
    {
        list($where_sql, $args) = $this->where_clause();
        return array("SELECT {$this->columns_sql} FROM {$this->table_name}{$where_sql}{$this->order}{$suffix}", $args);
    }

    private function where_clause()
    {
        if (!$this->wheres) {
            return array('', array());
        }
        $parts = array();
        $args  = array();
        foreach ($this->wheres as $w) {
            $parts[] = "{$w[0]} = ?";
            $args[]  = $w[1];
        }
        return array(' WHERE ' . implode(' AND ', $parts), $args);
    }
}

function rp_use_sqlite()
{
    $db = new RpSqliteDb();
    $GLOBALS['rp_lava']->db = $db;
    return $db;
}

// ---------------------------------------------------------------------
// 1. UserModel & RefreshTokenModel
// ---------------------------------------------------------------------
test('phase0: UserModel validates email/password and never exposes password_hash', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    rp_use_sqlite();
    $users = new UserModel();

    assert_same('Use at least 8 characters.', UserModel::password_problem('short'));
    assert_same(null, UserModel::password_problem('strong-pass-123'));
    assert_same('Enter a valid email address.', UserModel::email_problem('not-an-email'));
    assert_same('runner@example.com', UserModel::normalise_email('  Runner@Example.COM '));

    $created = $users->create_password_account('Runner@Example.com', 'correct-horse-99');
    assert_true(is_array($created), 'account should be created');
    assert_same(null, $users->create_password_account('runner@example.com', 'another-pass-99'), 'duplicate email rejected');

    assert_same(null, $users->authenticate('runner@example.com', 'wrong-password'));
    assert_same(null, $users->authenticate('nobody@example.com', 'correct-horse-99'));

    $authed = $users->authenticate('RUNNER@example.com', 'correct-horse-99');
    assert_true(is_array($authed), 'valid credentials authenticate');

    $public = $users->present($authed);
    assert_false(array_key_exists('password_hash', $public), 'password_hash must never be returned');
    assert_true($public['has_password'], 'has_password flag should be true');
});

test('phase0: RefreshTokenModel matches HMAC tokens and revokes cleanly', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db = rp_use_sqlite();
    $tokens = new RefreshTokenModel();

    $raw_token = 'sample-refresh-jwt-token-value';
    $db->raw(
        "INSERT INTO refresh_tokens (user_id, token_hash, jti, expires_at) VALUES (?, ?, ?, '2099-01-01 00:00:00')",
        array(7, $tokens->hash($raw_token), 'jti-1')
    );

    $found = $tokens->find_valid($raw_token);
    assert_true(is_array($found), 'unexpired hashed refresh token should resolve');
    assert_same(7, (int) $found['user_id']);

    $tokens->revoke($raw_token);
    assert_same(null, $tokens->find_valid($raw_token), 'revoked token must not validate');
});

// ---------------------------------------------------------------------
// 2. First-party data boundary (source='manual'), filters & sessions
// ---------------------------------------------------------------------
test('phase0: ActivityModel and DashboardModel exclude non-manual sources and filter dates', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db = rp_use_sqlite();
    $activities = new ActivityModel();
    $dashboard  = new DashboardModel();
    $sessions   = new SessionModel();

    // Seed active plan with one overdue planned session and one upcoming session
    $db->raw(
        "INSERT INTO training_plans (id, user_id, goal, start_date, end_date, sessions_per_week, status)
         VALUES (1, 42, '10k', '2026-10-01', '2026-12-01', 3, 'active')"
    );
    $db->raw(
        "INSERT INTO training_sessions (id, plan_id, user_id, scheduled_date, type, planned_distance_km, status)
         VALUES (10, 1, 42, '2026-10-18', 'easy', 5.0, 'planned'),
                (11, 1, 42, '2026-10-25', 'long', 10.0, 'planned')"
    );

    // Log two manual runs and inject one restricted external ('strava') row
    $activities->create_for_user(42, array(
        'activity_date'    => '2026-10-15',
        'distance_km'      => 6.0,
        'duration_seconds' => 1980,
        'effort'           => 3,
        'notes'            => 'Easy loop',
    ));
    $activities->create_for_user(42, array(
        'activity_date'    => '2026-10-21',
        'distance_km'      => 12.0,
        'duration_seconds' => 3900,
        'effort'           => 4,
        'notes'            => 'Steady progression',
    ));
    $db->raw(
        "INSERT INTO activities (user_id, source, activity_date, distance_km, duration_seconds)
         VALUES (42, 'strava', '2026-10-22', 32.0, 9600)"
    );

    // Non-manual 32 km run must be excluded everywhere (Spec 5.5 / D9)
    $all_manual = $activities->for_user(42);
    assert_count(2, $all_manual, 'only manual activities are returned');

    $filtered = $activities->for_user(42, array('from' => '2026-10-20', 'to' => '2026-10-23'));
    assert_count(1, $filtered, 'date-range filter scopes manual runs');
    assert_same(12.0, $filtered[0]['distance_km']);

    $longest = $activities->longest_recent_run_km(42, 21, rp_today());
    assert_same(12.0, $longest, 'longest_recent_run_km must ignore strava 32 km row');

    // Overdue session (2026-10-18) becomes missed; upcoming session (2026-10-25) stays planned
    $sessions->mark_missed_for_user(42, rp_today());
    assert_same('missed', $sessions->find_for_user(42, 10)['status']);
    assert_same('planned', $sessions->find_for_user(42, 11)['status']);

    $summary = $dashboard->summary(42, rp_today());
    assert_same(12.0, $summary['last7']['distance_km'], 'dashboard last7 excludes non-manual runs');
    assert_same(1, $summary['plan']['missed']);
});

// ---------------------------------------------------------------------
// 3. Route-level AuthMiddleware & CORS preflight
// ---------------------------------------------------------------------
test('phase0: protected API routes attach auth middleware and OPTIONS preflight exists', function () {
    $routes = file_get_contents(RP_ROOT . 'app/config/routes.php');
    assert_contains("\$router->options('/api/{a?}/{b?}/{c?}/{d?}', 'Health::preflight')", $routes);

    $public_paths = array(
        '/',
        '/api/health',
        '/migrate/run',
        '/api/auth/register',
        '/api/auth/login',
        '/api/auth/refresh',
        '/api/auth/logout',
        '/api/auth/forgot-password',
        '/api/auth/reset-password',
    );

    preg_match_all('/^\$router->(get|post|put|patch|delete)\(\'([^\']+)\',[^\n]+$/m', $routes, $lines, PREG_SET_ORDER);
    assert_true(count($lines) >= 15, 'expected full core route table');

    $unprotected = array();
    foreach ($lines as $line) {
        $path = $line[2];
        if (in_array($path, $public_paths, true)) {
            continue;
        }
        if (strpos($line[0], "->middleware('auth')") === false) {
            $unprotected[] = strtoupper($line[1]) . ' ' . $path;
        }
    }

    assert_same(array(), $unprotected, 'every non-public route must carry ->middleware(\'auth\')');
});

// ---------------------------------------------------------------------
// 4. HttpClient fake seam
// ---------------------------------------------------------------------
test('phase0: HttpClient::fake records outbound JSON requests without network I/O', function () {
    HttpClient::fake(array(
        array('ok' => true, 'status' => 200, 'body' => '{"status":"ok","items":[1,2]}'),
    ));

    try {
        $client = new HttpClient(10);
        $res = $client->post_json('https://example.test/v1/ping', array('hello' => 'world'));

        assert_true($res['ok']);
        assert_same(200, $res['status']);
        assert_same('ok', $res['data']['status']);

        $sent = HttpClient::recorded();
        assert_count(1, $sent);
        assert_same('POST', $sent[0]['method']);
        assert_same('{"hello":"world"}', $sent[0]['body']);
    } finally {
        HttpClient::stop_faking();
    }
});

// ---------------------------------------------------------------------
// 5. Phase 1 account recovery, security event redaction & deletion
// ---------------------------------------------------------------------
test('phase1: PasswordResetModel enforces HMAC hashing, latest-only issue, and single-use consume', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db     = rp_use_sqlite();
    $users  = new UserModel();
    $resets = new PasswordResetModel();

    $user   = $users->create_password_account('recover@example.com', 'initial-pass-99');
    $uid    = (int) $user['id'];

    $first  = $resets->issue($uid);
    $second = $resets->issue($uid);

    // Raw token must never appear in the DB
    $stored = $db->raw('SELECT token_hash FROM password_reset_tokens')->fetchAll(PDO::FETCH_COLUMN);
    assert_false(in_array($second['token'], $stored, true), 'raw reset token must never be stored');

    // Issuing a second token invalidates the first
    assert_same(null, $resets->find_valid($first['token']), 'superseded token must not validate');
    assert_true(is_array($resets->find_valid($second['token'])), 'latest token validates');

    // Consuming succeeds once and rejects replay
    $consumed = $resets->consume($second['token']);
    assert_same($uid, (int) $consumed['user_id']);
    assert_same(null, $resets->consume($second['token']), 'consumed token cannot be replayed');
});

test('phase1: SecurityEventModel redacts secrets and UserModel::delete_account_and_data records receipt', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db     = rp_use_sqlite();
    $users  = new UserModel();
    $events = new SecurityEventModel();

    $user = $users->create_password_account('delete-me@example.com', 'goodbye-pass-88');
    $uid  = (int) $user['id'];

    assert_true($users->verify_password($user, 'goodbye-pass-88'));
    assert_false($users->verify_password($user, 'wrong-pass-88'));

    $events->record('password_changed', $uid, array(
        'revoked_sessions' => 2,
        'password'         => 'SHOULD_BE_REDACTED',
        'email'            => 'delete-me@example.com',
    ));

    $recent = $events->recent_for_user($uid, 5);
    assert_count(1, $recent);
    assert_same('password_changed', $recent[0]['event_type']);
    assert_same(array('revoked_sessions' => 2), $recent[0]['metadata']);

    $summary = $users->account_summary($uid);
    assert_same('delete-me@example.com', $summary['email']);
    assert_same(0, $summary['data_counts']['activities']);

    assert_true($users->delete_account_and_data($uid, 'Finished marathon block'));
    assert_same(false, $users->find($uid), 'user row must be removed');

    $receipt = $db->raw('SELECT * FROM account_deletion_requests LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    assert_same('completed', $receipt['status']);
    assert_same('Finished marathon block', $receipt['reason']);
    assert_same(hash('sha256', 'delete-me@example.com'), $receipt['email_sha256']);
});

// ---------------------------------------------------------------------
// 6. Phase 2 scheduling preferences, skip_reason & propose/apply/reject
// ---------------------------------------------------------------------
test('phase2: desired_sessions_per_week, planned_start_time, and skip_reason persist cleanly', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    rp_use_sqlite();
    $profiles = new ProfileModel();
    $plans    = new PlanModel();

    list($clean, $errs) = $profiles->validate(array(
        'display_name'              => 'Runner Two',
        'experience'                => 'intermediate',
        'goal'                      => '10k',
        'target_race_date'          => rp_race_in(70),
        'available_days'            => array('mon', 'tue', 'thu', 'sat', 'sun'),
        'desired_sessions_per_week' => 3,
        'preferred_time'            => 'morning',
        'baseline_distance_km'      => 6.0,
        'baseline_pace'             => 360,
    ));
    assert_same(array(), $errs);
    $profiles->save_for_user(15, $clean);

    $saved_profile = $profiles->find_for_user(15);
    assert_same(3, $saved_profile['desired_sessions_per_week']);

    list($built, $build_errs) = $plans->build($saved_profile, rp_today());
    assert_same(array(), $build_errs);
    assert_same(3, $built['plan']['sessions_per_week'], 'desired_sessions_per_week caps 5 available days to 3');
    assert_same('06:30:00', $built['sessions'][0]['planned_start_time'], 'morning maps to 06:30:00');

    $plan_id  = $plans->create_draft(15, $built['plan'], $built['sessions']);
    $approved = $plans->approve_for_user(15, $plan_id);
    $first_id = $approved['sessions'][0]['id'];

    $skipped = $plans->set_session_status(15, $first_id, 'skipped', 'Tight calf muscle');
    assert_same('skipped', $skipped['status']);
    assert_same('Tight calf muscle', $skipped['skip_reason']);

    $restored = $plans->set_session_status(15, $first_id, 'planned');
    assert_same('planned', $restored['status']);
    assert_same(null, $restored['skip_reason']);
});

test('phase2: propose_rebuild leaves sessions untouched until apply_revision and supports reject_revision', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db    = rp_use_sqlite();
    $plans = new PlanModel();

    $profile = array(
        'display_name'              => 'Runner Three',
        'experience'                => 'intermediate',
        'goal'                      => '10k',
        'target_race_date'          => rp_race_in(70),
        'available_days'            => array('tue', 'thu', 'sat'),
        'desired_sessions_per_week' => 3,
        'preferred_time'            => 'evening',
        'baseline_distance_km'      => 5.0,
        'baseline_pace'             => 360,
    );

    list($built) = $plans->build($profile, rp_today());
    $plan_id     = $plans->create_draft(21, $built['plan'], $built['sessions']);
    $approved    = $plans->approve_for_user(21, $plan_id);
    $v_before    = $approved['plan']['version'];

    // Mark first session completed — must survive any rebuild
    $first_id = $approved['sessions'][0]['id'];
    $db->raw("UPDATE training_sessions SET status = 'completed' WHERE id = ?", array($first_id));

    // 1. Propose -> Reject: plan version and sessions do not change
    list($prop1, $err1) = $plans->propose_rebuild(21, $plan_id, $profile, rp_today(), 9.0, 'Testing cancel');
    assert_same(null, $err1);
    assert_same('pending', $prop1['decision']);
    assert_same(1, $prop1['preserved_completed']);

    $rejected = $plans->reject_revision(21, $plan_id, $prop1['id']);
    assert_same('rejected', $rejected['decision']);
    assert_same($v_before, $plans->find_for_user(21, $plan_id)['plan']['version']);

    // 2. Propose -> Apply: bumps version, preserves completed run, records applied decision
    list($prop2, $err2) = $plans->propose_rebuild(21, $plan_id, $profile, rp_today(), 9.0, 'Bump volume after 9km run');
    assert_same(null, $err2);

    list($applied, $apply_err) = $plans->apply_revision(21, $plan_id, $prop2['id']);
    assert_same(null, $apply_err);
    assert_same($v_before + 1, $applied['plan']['version']);

    $completed = array_values(array_filter($applied['sessions'], function ($s) {
        return $s['status'] === 'completed';
    }));
    assert_count(1, $completed, 'completed session must be preserved across apply_revision');

    $history = $plans->revisions_for_user(21, $plan_id);
    assert_count(3, $history, 'approved + rejected + applied all appear in audit history');

    // 3. New draft can be discarded without touching the active plan
    $draft2_id = $plans->create_draft(21, $built['plan'], $built['sessions']);
    assert_false($plans->discard_draft_for_user(21, $plan_id), 'active plan must never be deleted by discard_draft_for_user');
    assert_true($plans->discard_draft_for_user(21, $draft2_id), 'draft plan discards cleanly');
    $current = $plans->current_for_user(21);
    assert_same(null, $current['draft']);
    assert_same($plan_id, $current['active']['plan']['id']);
});

// ---------------------------------------------------------------------
// 7. Phase 3 AnthropicService privacy boundary, monthly cap & fallback
// ---------------------------------------------------------------------
test('phase3: AnthropicService sends only manual non-PII metrics and stores Claude feedback', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db = rp_use_sqlite();
    $coaching = new AiCoachingModel();

    $db->raw(
        "INSERT INTO runner_profiles (user_id, display_name, experience, goal, available_days)
         VALUES (33, 'Secret Name', 'intermediate', 'half_marathon', 'tue,thu,sat')"
    );
    // Manual run (must be included) + non-manual run (must NEVER be sent to AI)
    $db->raw(
        "INSERT INTO activities (user_id, source, activity_date, distance_km, duration_seconds, effort)
         VALUES (33, 'manual', '2026-10-21', 8.5, 2700, 6),
                (33, 'strava', '2026-10-22', 99.0, 9999, 9)"
    );

    HttpClient::fake(array(
        array(
            'ok'     => true,
            'status' => 200,
            'body'   => json_encode(array(
                'content' => array(
                    array('type' => 'text', 'text' => 'Solid 8.5 km aerobic run this week. Keep easy pace relaxed.'),
                ),
            )),
        ),
    ));

    $live_service = new AnthropicService(array(
        'enabled'          => true,
        'api_key'          => 'sk-ant-test-only-secret-key',
        'model'            => 'claude-sonnet-4-5',
        'monthly_call_cap' => 10,
    ));

    $saved = $coaching->generate_weekly_feedback(33, rp_today(), $live_service);
    $recorded = HttpClient::recorded();
    HttpClient::stop_faking();

    assert_count(1, $recorded);
    assert_false(strpos($recorded[0]['body'], 'Secret Name') !== false, 'runner display_name must never be sent');
    assert_false(strpos($recorded[0]['body'], '99') !== false, 'non-manual strava distance must never be sent');
    assert_contains('8.5', $recorded[0]['body'], 'manual distance must be included');
    assert_same('ok', $saved['provider_status']);
    assert_same('Solid 8.5 km aerobic run this week. Keep easy pace relaxed.', $saved['feedback_text']);
});

test('phase3: AiCoachingModel falls back cleanly when Anthropic is disabled, over cap, or offline', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    rp_use_sqlite();
    $coaching = new AiCoachingModel();

    // 1. Disabled / unconfigured -> deterministic coaching with not_configured
    $off_service = new AnthropicService(array('enabled' => false, 'api_key' => ''));
    $res1 = $coaching->generate_weekly_feedback(44, rp_today(), $off_service);
    assert_same('not_configured', $res1['provider_status']);
    assert_contains('No manual runs were logged', $res1['feedback_text']);

    // 2. Network/HTTP 503 failure -> deterministic fallback without throwing
    HttpClient::fake(array(
        array('ok' => false, 'status' => 503, 'body' => '{"error":"overloaded"}'),
    ));
    $live_service = new AnthropicService(array(
        'enabled'          => true,
        'api_key'          => 'sk-ant-test-only',
        'monthly_call_cap' => 5,
    ));
    $res2 = $coaching->generate_weekly_feedback(44, rp_today(), $live_service);
    HttpClient::stop_faking();

    assert_same('fallback', $res2['provider_status']);
    assert_true(strlen((string) $res2['feedback_text']) > 20);
});



test('phase3: coaching review degrades to an unsaved answer when the AI tables are missing', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db = rp_use_sqlite();
    $db->raw('DROP TABLE ai_feedback');

    $coaching = new AiCoachingModel();
    $off_service = new AnthropicService(array('enabled' => false, 'api_key' => ''));

    // A deployment that never applied migration 008 must still render a review
    // instead of failing the whole dashboard card (NFR-04).
    $res = $coaching->generate_weekly_feedback(55, rp_today(), $off_service);
    assert_same(false, $res['persisted']);
    assert_same(null, $res['id']);
    assert_true(strlen((string) $res['feedback_text']) > 20);

    assert_same(null, $coaching->latest_feedback_for_user(55), 'no stored review behind a missing table');
});


// ---------------------------------------------------------------------
// Phase 4 - optional training location on the runner profile
// ---------------------------------------------------------------------
test('phase4: profile location is validated, saved, and never wiped by an older form', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db = rp_use_sqlite();
    $profiles = new ProfileModel();

    $base = array(
        'display_name'   => 'Weather Tester',
        'experience'     => 'intermediate',
        'goal'           => 'general_fitness',
        'available_days' => array('mon', 'wed', 'sat'),
        'preferred_time' => 'morning',
    );

    // 1. A label alone is accepted (the geocoder resolves it later).
    list($clean, $errors) = $profiles->validate($base + array('location_label' => '  Mamburao,   PH '));
    assert_same(array(), $errors);
    assert_same('Mamburao, PH', $clean['location_label'], 'label is trimmed and inner spaces collapsed');
    assert_same(null, $clean['latitude'], 'no coordinates requested means none stored');

    // 2. Half a coordinate is a typo, not a location.
    list(, $errors) = $profiles->validate($base + array('latitude' => 13.22));
    assert_same('Enter both latitude and longitude, or leave both empty.', $errors['longitude']);

    // 3. Out of range numbers are refused with the real range.
    list(, $errors) = $profiles->validate($base + array('latitude' => 91, 'longitude' => 300));
    assert_contains('-90 and 90', $errors['latitude']);
    assert_contains('-180 and 180', $errors['longitude']);

    // 4. A form that predates weather support sends no location keys at all.
    list($clean, $errors) = $profiles->validate($base);
    assert_same(array(), $errors);
    assert_false(array_key_exists('location_label', $clean), 'untouched location must stay untouched');

    // 5. Saving a location, then saving the old form again, keeps it.
    $profiles->save_for_user(71, $profiles->validate($base + array(
        'location_label' => 'Mamburao, PH',
        'latitude'       => 13.2233,
        'longitude'      => 120.5956,
    ))[0]);

    $saved = $profiles->find_for_user(71);
    assert_same('Mamburao, PH', $saved['location']['label']);
    assert_true($saved['location']['has_coordinates']);
    assert_same(13.2233, $saved['location']['latitude']);
    assert_same('13.22330,120.59560', $saved['location']['query'], 'coordinates win over the label');

    $profiles->save_for_user(71, $profiles->validate($base)[0]);
    $unchanged = $profiles->find_for_user(71);
    assert_same('Mamburao, PH', $unchanged['location']['label'], 'an older form must not erase the location');

    // 6. Clearing is explicit and works when the client asks for it.
    $profiles->save_for_user(71, $profiles->validate($base + array(
        'location_label' => '',
        'latitude'       => '',
        'longitude'      => '',
    ))[0]);
    $cleared = $profiles->find_for_user(71);
    assert_same(null, $cleared['location']['label']);
    assert_same(false, $cleared['location']['has_coordinates']);
    assert_same('', $cleared['location']['query'], 'nothing to query when no location is set');
});

// ---------------------------------------------------------------------
// Phase 4 step 2 - OpenWeatherMap advisory forecast + cache
// ---------------------------------------------------------------------

/** A 3-hourly /forecast reply: one hot day, then one wet day (UTC+8). */
function rp_owm_forecast_payload()
{
    return array(
        'city' => array('timezone' => 28800),   // UTC+8: 01:00 UTC is 09:00 local
        'list' => array(
            array(
                'dt'      => strtotime('2026-10-23 01:00:00 UTC'),
                'main'    => array('temp' => 30, 'feels_like' => 33.0, 'temp_min' => 29, 'temp_max' => 31),
                'weather' => array(array('main' => 'Clear', 'description' => 'clear sky', 'icon' => '01d')),
                'wind'    => array('speed' => 4.0),
                'pop'     => 0.1,
            ),
            array(
                'dt'      => strtotime('2026-10-23 04:00:00 UTC'),
                'main'    => array('temp' => 33, 'feels_like' => 40.2, 'temp_min' => 32, 'temp_max' => 34),
                'weather' => array(array('main' => 'Clear', 'description' => 'clear sky', 'icon' => '01d')),
                'wind'    => array('speed' => 5.0),
                'pop'     => 0.2,
            ),
            array(
                'dt'      => strtotime('2026-10-24 01:00:00 UTC'),
                'main'    => array('temp' => 27, 'feels_like' => 30.0, 'temp_min' => 26, 'temp_max' => 28),
                'weather' => array(array('main' => 'Rain', 'description' => 'light rain', 'icon' => '10d')),
                'wind'    => array('speed' => 9.0),
                'rain'    => array('3h' => 6.2),
                'pop'     => 0.85,
            ),
            array(
                'dt'      => strtotime('2026-10-24 04:00:00 UTC'),
                'main'    => array('temp' => 26, 'feels_like' => 29.0, 'temp_min' => 25, 'temp_max' => 27),
                'weather' => array(array('main' => 'Rain', 'description' => 'moderate rain', 'icon' => '10d')),
                'wind'    => array('speed' => 7.0),
                'rain'    => array('3h' => 3.1),
                'pop'     => 0.9,
            ),
        ),
    );
}

function rp_owm_service(array $overrides = array())
{
    return new OpenWeatherService(array_merge(array(
        'enabled'       => true,
        'api_key'       => 'owm-test-key',
        'product'       => 'forecast16',
        'forecast_days' => 5,
        'cache_minutes' => 60,
        'timeout'       => 5,
    ), $overrides));
}

test('phase4: OpenWeatherService folds 3-hourly data into one advisory row per local day', function () {
    $service = rp_owm_service();
    assert_true($service->is_ready());
    assert_same(5, $service->horizon_days());

    HttpClient::fake(array(
        array('ok' => true, 'status' => 200, 'body' => json_encode(rp_owm_forecast_payload())),
    ));
    $res = $service->daily_forecast(13.2233, 120.5956);
    HttpClient::stop_faking();

    assert_true($res['ok'], 'a 200 forecast must be usable');
    assert_count(2, $res['days'], 'two local days, not four 3-hour slots');

    $hot = $res['days'][0];
    assert_same('2026-10-23', $hot['date'], 'local date uses the city offset, not UTC');
    assert_same(29.0, $hot['temp_min_c']);
    assert_same(34.0, $hot['temp_max_c']);
    assert_same(18.0, $hot['wind_max_kmh'], 'wind is converted m/s -> km/h');
    assert_same(20, $hot['rain_probability']);
    assert_same('clear', $hot['condition']);

    $hot_kinds = array_column($hot['advisories'], 'kind');
    assert_true(in_array('heat', $hot_kinds, true), 'feels-like 40 C must raise a heat note');
    assert_same('warning', $hot['advisories'][0]['level'], 'worst note comes first');

    $wet = $res['days'][1];
    assert_same('2026-10-24', $wet['date']);
    assert_same(9.3, $wet['rain_mm'], 'rain accumulates across the day');
    assert_same(90, $wet['rain_probability']);
    assert_same('rain', $wet['condition']);

    $wet_kinds = array_column($wet['advisories'], 'kind');
    assert_true(in_array('rain', $wet_kinds, true));
    assert_true(in_array('wind', $wet_kinds, true), '32 km/h is worth a note');
});

test('phase4: OpenWeatherService degrades cleanly when unconfigured, unmatched, or offline', function () {
    // 1. Kill switch off -> not_configured, and not a single request is made.
    HttpClient::fake(array());
    $off = new OpenWeatherService(array('enabled' => false, 'api_key' => ''));
    assert_false($off->is_ready());
    $res = $off->daily_forecast(13.2, 120.5);
    assert_same('not_configured', $res['error_code']);
    assert_same(0, count(HttpClient::recorded()), 'a disabled provider must not call out');
    HttpClient::stop_faking();

    // 2. A coordinate string is a location; no geocoding request needed.
    HttpClient::fake(array());
    $geo = rp_owm_service()->geocode('13.22330,120.59560');
    assert_true($geo['ok']);
    assert_same(13.2233, $geo['latitude']);
    assert_same(0, count(HttpClient::recorded()));
    HttpClient::stop_faking();

    // 3. A typo is a no_match, not an exception.
    HttpClient::fake(array(array('ok' => true, 'status' => 200, 'body' => '[]')));
    $geo = rp_owm_service()->geocode('Xyzzyville');
    assert_same('no_match', $geo['error_code']);
    HttpClient::stop_faking();

    // 4. Provider down -> unavailable.
    HttpClient::fake(array(array('ok' => false, 'status' => 503, 'body' => '{"cod":503}')));
    $res = rp_owm_service()->daily_forecast(13.2, 120.5);
    assert_false($res['ok']);
    assert_same('unavailable', $res['error_code']);
    HttpClient::stop_faking();
});

test('phase4: WeatherModel caches the forecast, remembers coordinates, and never touches the schedule', function () {
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        return;
    }
    $db = rp_use_sqlite();
    $weather = new WeatherModel();
    $service = rp_owm_service();

    // A runner who typed a city but never gave coordinates.
    $db->raw(
        "INSERT INTO runner_profiles (user_id, display_name, experience, goal, available_days, location_label)
         VALUES (81, 'Weather Runner', 'intermediate', 'half_marathon', 'tue,thu,sun', 'Mamburao, PH')"
    );
    // A runner who never set a location at all.
    $db->raw(
        "INSERT INTO runner_profiles (user_id, display_name, experience, goal, available_days)
         VALUES (82, 'No Location', 'beginner', '5k', 'mon,wed')"
    );
    $db->raw(
        "INSERT INTO training_sessions (plan_id, user_id, scheduled_date, type, planned_distance_km, status)
         VALUES (1, 81, '2026-10-24', 'long', 18, 'planned')"
    );

    HttpClient::fake(array(
        array('ok' => true, 'status' => 200, 'body' => json_encode(array(
            array('name' => 'Mamburao', 'lat' => 13.2233, 'lon' => 120.5956, 'country' => 'PH', 'state' => 'Mimaropa'),
        ))),
        array('ok' => true, 'status' => 200, 'body' => json_encode(rp_owm_forecast_payload())),
    ));

    $first = $weather->forecast_for_user(81, rp_today(), $service);
    $after_first = count(HttpClient::recorded());

    assert_same('ok', $first['status']);
    assert_same(false, $first['cached']);
    assert_same(2, $after_first, 'one geocode + one forecast call');
    assert_same('Mamburao, Mimaropa, PH', $first['location']['label']);
    assert_same(true, $first['location']['has_coordinates']);
    assert_count(2, $first['days']);
    assert_same(false, $first['mutates_schedule'], 'weather is advisory only');

    // Coordinates are remembered so the next load only needs the forecast.
    $stored = $db->raw('SELECT latitude, longitude FROM runner_profiles WHERE user_id = 81')->fetch(PDO::FETCH_ASSOC);
    assert_same(13.2233, (float) $stored['latitude']);
    assert_same(120.5956, (float) $stored['longitude']);

    // Second load is served from weather_cache: zero extra requests.
    $second = $weather->forecast_for_user(81, rp_today(), $service);
    assert_same('ok', $second['status']);
    assert_same(true, $second['cached']);
    assert_same($after_first, count(HttpClient::recorded()), 'a cached forecast must not call the provider');

    // The schedule is untouched by any of this.
    $session = $db->raw('SELECT scheduled_date, status, planned_distance_km FROM training_sessions WHERE user_id = 81')->fetch(PDO::FETCH_ASSOC);
    assert_same('2026-10-24', $session['scheduled_date']);
    assert_same('planned', $session['status']);
    assert_same(18.0, (float) $session['planned_distance_km']);

    // Badge helper returns notes for the dates it is asked about.
    $notes = $weather->advisories_for_dates(81, array('2026-10-24', '2026-11-30'), rp_today(), $service);
    assert_count(1, $notes, 'only dates inside the horizon come back');
    assert_contains('rain', strtolower(json_encode($notes['2026-10-24']['advisories'])));

    // Provider down but we already have a forecast -> stale, not broken.
    $db->raw("UPDATE weather_cache SET expires_at = '2000-01-01 00:00:00'");
    HttpClient::fake(array(array('ok' => false, 'status' => 503, 'body' => '{"cod":503}')));
    $stale = $weather->forecast_for_user(81, rp_today(), $service);
    assert_same('stale_cache', $stale['status']);
    assert_same(true, $stale['cached']);
    assert_count(2, $stale['days'], 'the last known forecast is still served');

    // No location -> nothing to look up, nothing requested.
    HttpClient::fake(array());
    $none = $weather->forecast_for_user(82, rp_today(), $service);
    assert_same('no_location', $none['status']);
    assert_count(0, $none['days']);
    assert_same(0, count(HttpClient::recorded()));

    HttpClient::stop_faking();
});
