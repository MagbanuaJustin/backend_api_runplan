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
require_once RP_ROOT . 'app/libraries/HttpClient.php';

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


