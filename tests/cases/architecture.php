<?php
/**
 * Architecture rules.
 *
 * These exist because the problems they check for have already happened once
 * here, and a reviewer noticing them in a diff is not a control. They are all
 * cheap, source-level checks - no database, no network.
 */

/** Strip block and line comments so a mention in prose is not a violation. */
if (!function_exists('rp_strip_comments')) {
    function rp_strip_comments($source)
    {
        $source = preg_replace('#/\*.*?\*/#s', ' ', (string) $source);
        $source = preg_replace('#(^|\s)(//|\#)([^\n]*)#', ' ', $source);
        return $source;
    }
}

if (!function_exists('rp_php_files')) {
    /** @return array list of absolute paths for *.php under a relative dir */
    function rp_php_files($relative_dir)
    {
        $out = array();
        $dir = RP_ROOT . $relative_dir;
        if (!is_dir($dir)) {
            return $out;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (strtolower($file->getExtension()) === 'php') {
                $out[] = $file->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}

// ---------------------------------------------------------------------
// 1. Controllers must not talk to the database
// ---------------------------------------------------------------------
test('architecture: no controller runs SQL or queries the builder directly', function () {
    // Controllers own transport: read input, check auth/rate limit, call a
    // model, shape the reply. SQL in a controller cannot be reused by another
    // entry point, which is how Auth.php ended up re-implementing the refresh
    // token lookup the framework already performed.
    $forbidden = '/\$this\s*->\s*(db|RefreshTokenModel\s*->\s*db)\b|->\s*(raw|query|transaction|commit|rollback)\s*\(|\$this\s*->\s*db\s*->\s*table\s*\(/';

    $offences = array();
    foreach (rp_php_files('app/controllers') as $file) {
        $code = rp_strip_comments(file_get_contents($file));
        if (preg_match_all($forbidden, $code, $m)) {
            foreach ($m[0] as $hit) {
                $offences[] = basename($file) . ': ' . trim($hit);
            }
        }
    }

    assert_same(array(), $offences, 'controllers must go through a model');
});

// ---------------------------------------------------------------------
// 2. Routes must resolve
// ---------------------------------------------------------------------
test('architecture: every route maps to a controller method that exists', function () {
    $routes = file_get_contents(RP_ROOT . 'app/config/routes.php');
    preg_match_all('/\$router\s*->\s*(get|post|put|patch|delete)\s*\(\s*\'([^\']+)\'\s*,\s*\'(\w+)::(\w+)\'\s*\)/', $routes, $m, PREG_SET_ORDER);

    assert_true(count($m) > 0, 'no routes were parsed - did routes.php change shape?');

    $missing = array();
    $seen = array();
    foreach ($m as $route) {
        $key = strtoupper($route[1]) . ' ' . $route[2];
        if (isset($seen[$key])) {
            $missing[] = 'duplicate registration for ' . $key;
        }
        $seen[$key] = true;

        $file = RP_ROOT . 'app/controllers/' . $route[3] . '.php';
        if (!is_file($file)) {
            $missing[] = $key . ' -> ' . $route[3] . '.php does not exist';
            continue;
        }
        if (!preg_match('/function\s+' . preg_quote($route[4], '/') . '\s*\(/', file_get_contents($file))) {
            $missing[] = $key . ' -> ' . $route[3] . '::' . $route[4] . '() does not exist';
        }
    }

    assert_same(array(), $missing);
});

// ---------------------------------------------------------------------
// 3. Raw SQL stays parameterised
// ---------------------------------------------------------------------
test('architecture: raw SQL never interpolates a value', function () {
    // Every query in this project uses ? placeholders, and that is what keeps
    // 40-odd raw() calls safe. Table-name interpolation of $this->... is the
    // one accepted exception (it is config, and Api.php restricts it to a bare
    // identifier). Anything else - a user id, a date, a sort column - is a bug.
    $offences = array();

    foreach (array_merge(rp_php_files('app/models'), rp_php_files('app/controllers')) as $file) {
        $code = file_get_contents($file);
        if (!preg_match_all('/->\s*raw\s*\(\s*(["\'])(.*?)(?<!\\\\)\1/s', $code, $hits, PREG_SET_ORDER)) {
            continue;
        }
        foreach ($hits as $hit) {
            $sql = $hit[2];
            if ($hit[1] !== '"') {
                continue;                     // single-quoted: no interpolation at all
            }
            // Strip the allowed form: {$this->something}
            $rest = preg_replace('/\{\$this->[a-z_]+\}/i', '', $sql);
            if (strpos($rest, '$') !== false) {
                $offences[] = basename($file) . ': ' . substr(trim($sql), 0, 90);
            }
        }
    }

    assert_same(array(), $offences, 'use a ? placeholder instead of interpolating');
});

// ---------------------------------------------------------------------
// 4. Integration config cannot leak a secret to the browser
// ---------------------------------------------------------------------
test('architecture: the public integration config exposes no credentials', function () {
    $helper = RP_ROOT . 'app/helpers/integration_helper.php';
    assert_true(is_file($helper), 'integration_helper.php is missing');

    $source = file_get_contents($helper);
    $start = strpos($source, 'function integration_public_config');
    assert_true($start !== false, 'integration_public_config() must exist');

    $body = substr($source, $start);
    $next = strpos($body, "\n}\n");
    if ($next !== false) {
        $body = substr($body, 0, $next);
    }

    // Only the browser-safe, non-secret half of each provider block may appear.
    $forbidden = array('api_key', 'client_secret', 'private_key', 'service_account', "'secret'", 'token_aad', 'password');
    $leaks = array();
    foreach ($forbidden as $needle) {
        if (stripos($body, $needle) !== false) {
            $leaks[] = $needle;
        }
    }

    assert_same(array(), $leaks, 'never return these from integration_public_config()');
});

test('architecture: every provider in config has a status entry', function () {
    $config = file_get_contents(RP_ROOT . 'app/config/integrations.php');
    $helper = file_get_contents(RP_ROOT . 'app/helpers/integration_helper.php');

    preg_match_all("/^    '([a-z_]+)' => array\(/m", $config, $m);
    $providers = $m[1];
    assert_count(6, $providers, 'expected the six spec integrations');

    $missing = array();
    foreach ($providers as $provider) {
        if (strpos($helper, "'" . $provider . "'") === false) {
            $missing[] = $provider;
        }
    }
    assert_same(array(), $missing, 'integration_helper.php does not know about these providers');
});

// ---------------------------------------------------------------------
// 5. Secrets stay out of the repository
// ---------------------------------------------------------------------
test('architecture: .env is ignored and only the template is committed', function () {
    $ignore = file_get_contents(RP_ROOT . '.gitignore');
    $lines = array_map('trim', preg_split('/\R/', $ignore));
    assert_true(in_array('.env', $lines, true), '.env must be gitignored');
    assert_false(in_array('.env.example', $lines, true), '.env.example must stay committed');

    // The template must document a key for every provider, or someone will
    // configure half an integration and chase it for an afternoon.
    $example = file_get_contents(RP_ROOT . '.env.example');
    foreach (array('ANTHROPIC_API_KEY', 'OWM_API_KEY', 'GCAL_CLIENT_ID', 'GOOGLE_CLIENT_ID', 'RECAPTCHA_SITE_KEY', 'PUSH_ENABLED') as $key) {
        assert_contains($key, $example, '.env.example is missing ' . $key);
    }
});

// ---------------------------------------------------------------------
// 6. No debug output on a response path
// ---------------------------------------------------------------------
test('architecture: no stray debug output in app code', function () {
    $offences = array();
    foreach (array_merge(rp_php_files('app/controllers'), rp_php_files('app/models'), rp_php_files('app/libraries')) as $file) {
        $code = rp_strip_comments(file_get_contents($file));
        if (preg_match('/\b(var_dump|print_r|error_log|dd)\s*\(/', $code, $m)) {
            $offences[] = basename($file) . ': ' . $m[0];
        }
    }
    // A JSON API must not emit anything outside the envelope: one stray
    // var_dump turns a valid response into unparseable output and the React
    // client reports "the server sent an unexpected reply".
    assert_same(array(), $offences);
});
