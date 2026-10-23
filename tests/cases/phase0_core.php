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
    private $wheres = array();
    private $order = '';

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
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE training_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                plan_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                scheduled_date TEXT NOT NULL,
                type TEXT NOT NULL,
                planned_distance_km REAL,
                planned_duration_seconds INTEGER,
                status TEXT NOT NULL DEFAULT 'planned',
                notes TEXT,
                created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT DEFAULT CURRENT_TIMESTAMP
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
        $this->table_name = $name;
        $this->wheres = array();
        $this->order = '';
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

    public function get()
    {
        list($sql, $args) = $this->select_sql(' LIMIT 1');
        $row = $this->raw($sql, $args)->fetch(PDO::FETCH_ASSOC);
        return $row ?: false;
    }

    public function get_all()
    {
        list($sql, $args) = $this->select_sql('');
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
        return array("SELECT * FROM {$this->table_name}{$where_sql}{$this->order}{$suffix}", $args);
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
