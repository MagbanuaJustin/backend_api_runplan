<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Auth
 *
 * Core first-party email/password authentication, Google sign-in, password
 * recovery, and rotating HttpOnly refresh sessions. Persistence and
 * validation rules live in Models.
 */
class Auth extends Controller
{
    public function register()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $this->api->rate_limit('register_' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 5, 600);

        $input = $this->read_json();
        $this->verify_recaptcha($input);

        $email    = UserModel::normalise_email($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if ($problem = UserModel::email_problem($email)) {
            $errors['email'] = $problem;
        }
        if ($problem = UserModel::password_problem($password)) {
            $errors['password'] = $problem;
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $user = $this->UserModel->create_password_account($email, $password);
        if (!$user) {
            $this->api->respond_error('That email is already registered.', 409);
        }

        $this->SecurityEventModel->record(
            'account_registered',
            (int) $user['id'],
            ['method' => 'password']
        );

        $this->api->respond([
            'data' => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ], 201);
    }

    public function login()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('login_' . $ip, 10, 600);

        $input = $this->read_json();
        $this->verify_recaptcha($input);

        $email    = UserModel::normalise_email($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->api->respond_error('Email and password are required.', 422);
        }

        $this->api->rate_limit('login_email_' . md5($email), 10, 600);

        $user = $this->UserModel->authenticate($email, $password);
        if (!$user) {
            $this->SecurityEventModel->record(
                'login_failed',
                null,
                ['reason' => 'invalid_credentials']
            );
            $this->api->respond_error('Invalid email or password.', 401);
        }

        if (!$this->UserModel->is_active($user)) {
            $this->SecurityEventModel->record(
                'login_blocked',
                (int) $user['id'],
                ['reason' => 'inactive']
            );
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->SecurityEventModel->record(
            'login_succeeded',
            (int) $user['id'],
            ['method' => 'password']
        );

        $this->start_session($user);
    }

    /**
     * Verify a Google Identity Services ID token, then sign in or create a
     * runner account and issue RunPlan's regular session.
     */
    public function google()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');

        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('google_login_' . $ip, 10, 600);

        $input = $this->read_json();
        $this->verify_recaptcha($input);

        $credential = trim((string) ($input['credential'] ?? ''));

        if ($credential === '' || strlen($credential) > 16384) {
            $this->api->respond_error('A valid Google sign-in credential is required.', 422);
        }

        $client_id = trim((string) env('GOOGLE_CLIENT_ID', ''));
        if ($client_id === '') {
            $this->api->respond_error('Google sign-in is not configured.', 503);
        }

        // Load Composer packages for the Google ID-token verifier.
        $composer_autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
        if (!is_file($composer_autoload)) {
            $this->api->respond_error('Google sign-in is unavailable on this server.', 503);
        }

        require_once $composer_autoload;

        if (!class_exists(\Google\Client::class)) {
            $this->api->respond_error('Google sign-in is unavailable on this server.', 503);
        }

        try {
            $google_client = new \Google\Client(['client_id' => $client_id]);
            $claims = $google_client->verifyIdToken($credential);
        } catch (Throwable $e) {
            $this->api->respond_error('Google sign-in is temporarily unavailable.', 503);
        }

        if (!is_array($claims)) {
            $this->SecurityEventModel->record(
                'login_failed',
                null,
                ['reason' => 'invalid_google_token']
            );
            $this->api->respond_error('Google sign-in could not be verified.', 401);
        }

        // Google\Client verifies the signature. These checks also bind the
        // token to this RunPlan web client and reject expired or wrong-issuer tokens.
        $audience = $claims['aud'] ?? null;
        $audience_valid = $audience === $client_id
            || (is_array($audience) && in_array($client_id, $audience, true));

        $issuer_valid = in_array(
            (string) ($claims['iss'] ?? ''),
            ['accounts.google.com', 'https://accounts.google.com'],
            true
        );

        $expires_at = (int) ($claims['exp'] ?? 0);

        if (!$audience_valid || !$issuer_valid || $expires_at <= time()) {
            $this->SecurityEventModel->record(
                'login_failed',
                null,
                ['reason' => 'invalid_google_token']
            );
            $this->api->respond_error('Google sign-in could not be verified.', 401);
        }

        $email = UserModel::normalise_email($claims['email'] ?? '');
        $subject = trim((string) ($claims['sub'] ?? ''));
        $email_verified = filter_var(
            $claims['email_verified'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );

        if (
            !$email_verified
            || UserModel::email_problem($email) !== null
            || $subject === ''
            || strlen($subject) > 191
        ) {
            $this->SecurityEventModel->record(
                'login_failed',
                null,
                ['reason' => 'invalid_google_profile']
            );
            $this->api->respond_error('Google did not provide a verified account.', 401);
        }

        $this->api->rate_limit('google_login_email_' . md5($email), 10, 600);

        // Google is authoritative for Gmail addresses and verified Workspace
        // addresses. Do not silently link other existing accounts by email.
        $email_domain = substr(strrchr($email, '@') ?: '', 1);
        $hosted_domain = strtolower(trim((string) ($claims['hd'] ?? '')));
        $authoritative_email = str_ends_with($email, '@gmail.com')
            || ($hosted_domain !== '' && $hosted_domain === $email_domain);

        $created = false;
        $identity = $this->UserModel->find_identity('google', $subject);

        if ($identity) {
            $user = $this->UserModel->find((int) $identity['user_id']);
        } else {
            $user = $this->UserModel->find_by_email($email);

            if ($user) {
                if (!$authoritative_email) {
                    $this->api->respond_error(
                        'This account uses another sign-in method. Sign in with that method.',
                        409
                    );
                }

                if (!$this->UserModel->is_active($user)) {
                    $this->SecurityEventModel->record(
                        'login_blocked',
                        (int) $user['id'],
                        ['reason' => 'inactive', 'method' => 'google']
                    );
                    $this->api->respond_error('This account is not active.', 403);
                }

                if (!$this->UserModel->attach_google_identity((int) $user['id'], $subject)) {
                    $this->api->respond_error(
                        'This Google account cannot be linked to this RunPlan account.',
                        409
                    );
                }
            } else {
                $user = $this->UserModel->create_google_account($email, $subject);

                if ($user) {
                    $created = true;
                } else {
                    // Another request may have created the account or identity
                    // after the first lookups. Resolve that race safely.
                    $identity = $this->UserModel->find_identity('google', $subject);

                    if ($identity) {
                        $user = $this->UserModel->find((int) $identity['user_id']);
                    } else {
                        $user = $this->UserModel->find_by_email($email);

                        if (
                            $user
                            && $authoritative_email
                            && $this->UserModel->is_active($user)
                            && $this->UserModel->attach_google_identity((int) $user['id'], $subject)
                        ) {
                            // The account was created by a concurrent request.
                        } else {
                            $this->api->respond_error(
                                'This Google account cannot be used for this RunPlan account.',
                                409
                            );
                        }
                    }
                }
            }
        }

        if (!$user) {
            $this->api->respond_error('Google sign-in could not be completed.', 401);
        }

        if (!$this->UserModel->is_active($user)) {
            $this->SecurityEventModel->record(
                'login_blocked',
                (int) $user['id'],
                ['reason' => 'inactive', 'method' => 'google']
            );
            $this->api->respond_error('This account is not active.', 403);
        }

        if ($created) {
            $this->SecurityEventModel->record(
                'account_registered',
                (int) $user['id'],
                ['method' => 'google']
            );
        }

        $this->SecurityEventModel->record(
            'login_succeeded',
            (int) $user['id'],
            ['method' => 'google']
        );

        $this->start_session($user);
    }

    public function forgot_password()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('PasswordResetModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('forgot_' . $ip, 8, 600);

        $input = $this->read_json();
        $this->verify_recaptcha($input);

        $email = UserModel::normalise_email($input['email'] ?? '');

        if ($problem = UserModel::email_problem($email)) {
            $this->api->respond([
                'error'  => 'Validation failed',
                'fields' => ['email' => $problem],
            ], 422);
        }

        $this->api->rate_limit('forgot_email_' . md5($email), 5, 600);

        // Keep the response the same whether or not the account exists.
        $data = [
            'ok'      => true,
            'message' => 'If an account matches that email, password reset instructions have been generated.',
        ];

        $user = $this->UserModel->find_by_email($email);
        if ($user && $this->UserModel->is_active($user)) {
            $issued = $this->PasswordResetModel->issue((int) $user['id']);
            $this->SecurityEventModel->record(
                'password_reset_requested',
                (int) $user['id'],
                ['ttl_minutes' => $issued['ttl_minutes']]
            );

            // Only allow the token preview with explicit development settings.
            if (
                strtolower((string) env('APP_ENV', 'production')) === 'development'
                && strtolower((string) env('PASSWORD_RESET_DEV_PREVIEW', 'false')) === 'true'
            ) {
                $data['dev_reset'] = [
                    'token'              => $issued['token'],
                    'reset_path'         => '/reset-password?token=' . rawurlencode($issued['token']),
                    'expires_at_utc'     => $issued['expires_at'],
                    'expires_in_minutes' => $issued['ttl_minutes'],
                ];
            }
        } else {
            hash_hmac('sha256', random_bytes(32), 'timing-pad');
        }

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $data]);
    }

    public function reset_password()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('PasswordResetModel');
        $this->call->model('RefreshTokenModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $this->api->rate_limit('reset_' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 10, 600);

        $input = $this->read_json();
        $this->verify_recaptcha($input);

        $token    = trim((string) ($input['token'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if ($token === '') {
            $errors['token'] = 'A password reset token is required.';
        }
        if ($problem = UserModel::password_problem($password)) {
            $errors['password'] = $problem;
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $consumed = $this->PasswordResetModel->consume($token);
        if (!$consumed) {
            $this->SecurityEventModel->record(
                'password_reset_failed',
                null,
                ['reason' => 'invalid_or_expired']
            );
            $this->api->respond_error(
                'That reset link is invalid or has expired. Request a new one.',
                400
            );
        }

        $uid  = (int) $consumed['user_id'];
        $user = $this->UserModel->find_active($uid);
        if (!$user) {
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->UserModel->set_password($uid, $password);
        $revoked = $this->RefreshTokenModel->revoke_all_for_user($uid);
        $this->clear_refresh_cookie();

        $this->SecurityEventModel->record(
            'password_reset_completed',
            $uid,
            ['revoked_sessions' => $revoked]
        );

        header('Cache-Control: no-store');
        $this->api->respond([
            'data' => [
                'ok'      => true,
                'message' => 'Your password has been updated. Sign in with your new password.',
            ],
        ]);
    }

    public function refresh()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('RefreshTokenModel');

        $this->api->require_method('POST');
        $this->api->rate_limit('refresh_' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 30, 600);

        $token = (string) ($_COOKIE['rp_refresh'] ?? '');
        if ($token === '') {
            $this->api->respond_error('Not signed in.', 401);
        }

        $payload = $this->api->validate_jwt($token);
        if (!$payload || ($payload['type'] ?? '') !== 'refresh') {
            $this->clear_refresh_cookie();
            $this->api->respond_error('Session expired.', 401);
        }

        if (!$this->RefreshTokenModel->find_valid($token)) {
            $this->clear_refresh_cookie();
            $this->api->respond_error('Session expired.', 401);
        }

        $user = $this->UserModel->find_active((int) $payload['sub']);
        if (!$user) {
            $this->clear_refresh_cookie();
            $this->api->respond_error('Session expired.', 401);
        }

        // Rotate-on-use: revoke the presented refresh token before issuing a new pair.
        $this->RefreshTokenModel->revoke($token);
        $this->start_session($user);
    }

    public function me()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $user = $this->UserModel->find_active((int) $payload['sub']);
        if (!$user) {
            $this->api->respond_error('Unauthorized', 401);
        }

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->UserModel->present($user)]);
    }

    public function logout()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('RefreshTokenModel');

        $this->api->require_method('POST');

        $token = (string) ($_COOKIE['rp_refresh'] ?? '');
        if ($token !== '') {
            $this->RefreshTokenModel->revoke($token);
        }

        $this->clear_refresh_cookie();
        $this->api->respond(['data' => ['ok' => true]]);
    }

    private function read_json()
    {
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        return $input;
    }

    /**
     * Verify a Google Cloud Fraud Defense checkbox response token by
     * creating a server-side assessment. The API key is restricted to the
     * reCAPTCHA Enterprise API and is never sent to the browser.
     */
    private function verify_recaptcha(array $input)
    {
        $enabled = strtolower(trim((string) env('RECAPTCHA_ENABLED', 'false'))) === 'true';

        if (!$enabled) {
            return;
        }

        $version = strtolower(trim((string) env('RECAPTCHA_VERSION', 'checkbox')));
        if (!in_array($version, array('checkbox', 'v2', 'v2_checkbox'), true)) {
            $this->api->respond_error('The server must use a reCAPTCHA checkbox key.', 503);
        }

        $project_id = trim((string) env('RECAPTCHA_PROJECT_ID', ''));
        $api_key = trim((string) env('RECAPTCHA_API_KEY', ''));
        $site_key = trim((string) env('RECAPTCHA_SITE_KEY', ''));
        $allowed_hostnames = array_values(array_filter(array_map(
            static function ($hostname) {
                return strtolower(trim($hostname));
            },
            explode(',', (string) env('RECAPTCHA_ALLOWED_HOSTNAMES', ''))
        )));

        if ($project_id === '' || $api_key === '' || $site_key === '' || !$allowed_hostnames) {
            $this->api->respond_error('Human verification is not configured on the server.', 503);
        }

        $token = trim((string) ($input['recaptcha_token'] ?? ''));

        if ($token === '' || strlen($token) > 8192) {
            $this->api->respond_error('Please complete human verification and try again.', 422);
        }

        if (!function_exists('curl_init')) {
            $this->api->respond_error('Human verification is unavailable on this server.', 503);
        }

        $timeout = max(3, min(20, (int) env('RECAPTCHA_TIMEOUT', 10)));
        $endpoint = 'https://recaptchaenterprise.googleapis.com/v1/projects/'
            . rawurlencode($project_id)
            . '/assessments?key=' . rawurlencode($api_key);
        $request_body = json_encode(array(
            'event' => array(
                'token'          => $token,
                'siteKey'        => $site_key,
                'userAgent'      => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1024),
                'userIpAddress'  => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            ),
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($request_body)) {
            $this->api->respond_error('Human verification is temporarily unavailable.', 503);
        }

        $curl = curl_init($endpoint);

        if ($curl === false) {
            $this->api->respond_error('Human verification is temporarily unavailable.', 503);
        }

        curl_setopt_array($curl, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $request_body,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $response_body = curl_exec($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if (
            !is_string($response_body)
            || $http_status < 200
            || $http_status >= 300
        ) {
            $this->api->respond_error('Human verification is temporarily unavailable.', 503);
        }

        $verification = json_decode($response_body, true);

        if (!is_array($verification)) {
            $this->api->respond_error('Human verification failed. Please try again.', 422);
        }

        $token_properties = $verification['tokenProperties'] ?? array();
        if (!is_array($token_properties) || empty($token_properties['valid'])) {
            $this->api->respond_error('Human verification failed. Please try again.', 422);
        }

        $verified_hostname = strtolower(trim((string) ($token_properties['hostname'] ?? '')));

        if (
            $verified_hostname === ''
            || !$allowed_hostnames
            || !in_array($verified_hostname, $allowed_hostnames, true)
        ) {
            $this->api->respond_error('Human verification could not be confirmed for this site.', 422);
        }
    }

    private function start_session($user)
    {
        $tokens = $this->api->issue_tokens([
            'id'   => (int) $user['id'],
            'role' => $user['role'],
        ]);

        $this->set_refresh_cookie($tokens['refresh_token']);
        header('Cache-Control: no-store');

        $this->api->respond([
            'data' => [
                'access_token' => $tokens['access_token'],
                'token_type'   => 'Bearer',
                'expires_in'   => $tokens['expires_in'],
                'user'         => $this->UserModel->present($user),
            ],
        ]);
    }

    private function is_https()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    /**
     * Scope the refresh cookie to the API auth path, including any local
     * subdirectory prefix (for example /runplan_lavalust_api/public).
     *
     * @return string
     */
    private function refresh_cookie_path()
    {
        $request_path = parse_url(
            (string) ($_SERVER['REQUEST_URI'] ?? ''),
            PHP_URL_PATH
        );
        $request_path = is_string($request_path) ? $request_path : '';
        $marker = '/api/auth/';
        $position = strpos($request_path, $marker);

        if ($position !== false) {
            return rtrim(substr($request_path, 0, $position), '/') . '/api/auth';
        }

        return '/api/auth';
    }

    private function set_refresh_cookie($token)
    {
        setcookie('rp_refresh', $token, [
            'expires'  => time() + (int) config_item('refresh_token_expiration'),
            'path'     => $this->refresh_cookie_path(),
            'secure'   => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clear_refresh_cookie()
    {
        setcookie('rp_refresh', '', [
            'expires'  => time() - 3600,
            'path'     => $this->refresh_cookie_path(),
            'secure'   => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}