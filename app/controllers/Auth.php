<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Auth
 *
 * Email/password registration & login, refresh-token rotation, Google Identity
 * Services Sign-In/Sign-Up + verified account linking, Google reCAPTCHA
 * verification on public account forms, and verified password recovery
 * (Spec FR-01, Module 1, Sections 5.6 & 5.7).
 */
class Auth extends Controller {

    public function register()
    {
        $this->call->library('api');
        $captcha = $this->call->library('CaptchaService');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('register_' . $ip, 5, 600);

        $input = $this->read_json();
        $this->enforce_captcha($captcha, $input, 'register', $ip);

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
            $this->SecurityEventModel->record('register_conflict', null, ['ip' => $ip]);
            $this->api->respond_error('That email is already registered.', 409);
        }

        $this->SecurityEventModel->record('register_succeeded', (int) $user['id'], ['method' => 'password']);

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
        $captcha = $this->call->library('CaptchaService');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('login_' . $ip, 10, 600);

        $input = $this->read_json();
        if ($captcha->is_required_for('login') || array_key_exists('captcha_token', $input)) {
            $this->enforce_captcha($captcha, $input, 'login', $ip);
        }

        $email    = UserModel::normalise_email($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->api->respond_error('Email and password are required.', 422);
        }

        $this->api->rate_limit('login_email_' . md5($email), 10, 600);

        $user = $this->UserModel->authenticate($email, $password);
        if (!$user) {
            $this->SecurityEventModel->record('login_failed', null, ['method' => 'password', 'ip' => $ip]);
            $this->api->respond_error('Invalid email or password.', 401);
        }
        if (!$this->UserModel->is_active($user)) {
            $this->SecurityEventModel->record('login_blocked_inactive', (int) $user['id'], ['method' => 'password']);
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->SecurityEventModel->record('login_succeeded', (int) $user['id'], ['method' => 'password']);
        $this->start_session($user);
    }

    /**
     * POST /api/auth/google
     *
     * Validates a Google Identity Services ID token (`credential`) on the
     * server, creates a local RunPlan account on first visit (when email is
     * unused), or authenticates the mapped account on return visits.
     *
     * Never merges silently with an existing password account by email text
     * (Spec 5.6, NFR-01, TC-11, TC-12).
     */
    public function google()
    {
        $this->call->library('api');
        $google = $this->call->library('GoogleIdentityService');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('UserIdentityModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('google_auth_' . $ip, 15, 600);

        $input      = $this->read_json();
        $credential = (string) ($input['credential'] ?? $input['id_token'] ?? '');
        $nonce      = isset($input['nonce']) ? (string) $input['nonce'] : null;

        $verified = $google->verify_id_token($credential, $nonce);
        if (!$verified['ok']) {
            $this->SecurityEventModel->record('google_login_failed', null, [
                'reason' => $verified['reason'] ?? 'invalid',
                'ip'     => $ip,
            ]);
            $this->api->respond_error($verified['error'], (int) ($verified['status'] ?? 401));
        }

        $resolved = $this->UserIdentityModel->resolve_or_create_google_user($verified['claims']);

        if ($resolved['status'] === 'link_required') {
            $this->SecurityEventModel->record('google_link_required', null, ['ip' => $ip]);
            $this->api->respond([
                'error' => 'An account with this email already exists. Sign in with your password first, then link Google in Account Settings.',
                'code'  => 'account_link_required',
            ], 409);
        }

        if ($resolved['status'] !== 'ok' || empty($resolved['user'])) {
            $this->SecurityEventModel->record('google_login_blocked', null, ['status' => $resolved['status']]);
            $this->api->respond_error('This account is not active.', 403);
        }

        $user = $resolved['user'];
        $this->SecurityEventModel->record(
            $resolved['created'] ? 'google_signup_succeeded' : 'google_login_succeeded',
            (int) $user['id'],
            ['provider' => 'google']
        );

        $this->start_session($user, $resolved['created'] ? 201 : 200);
    }

    /**
     * POST /api/auth/google/link
     *
     * Deliberate verified linking flow: caller is already authenticated as a
     * local RunPlan user AND supplies a server-verified Google credential.
     */
    public function link_google()
    {
        $this->call->library('api');
        $google = $this->call->library('GoogleIdentityService');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('UserIdentityModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('google_link_' . $user_id, 10, 600);

        $user = $this->UserModel->find_active($user_id);
        if (!$user) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $input      = $this->read_json();
        $credential = (string) ($input['credential'] ?? $input['id_token'] ?? '');
        $nonce      = isset($input['nonce']) ? (string) $input['nonce'] : null;

        $verified = $google->verify_id_token($credential, $nonce);
        if (!$verified['ok']) {
            $this->SecurityEventModel->record('google_link_failed', $user_id, [
                'reason' => $verified['reason'] ?? 'invalid',
            ]);
            $this->api->respond_error($verified['error'], (int) ($verified['status'] ?? 401));
        }

        $linked = $this->UserIdentityModel->link_google_to_user($user_id, $verified['claims']);
        if (!$linked['ok']) {
            $this->api->respond_error($linked['error'], (int) ($linked['status'] ?? 409));
        }

        $this->SecurityEventModel->record('google_link_succeeded', $user_id, ['provider' => 'google']);
        $this->api->respond([
            'data' => [
                'linked'     => true,
                'identities' => $this->UserIdentityModel->for_user($user_id),
            ],
        ]);
    }

    /**
     * DELETE /api/auth/google/link
     *
     * Unlink Google Sign-In, refusing if the account has no password set yet
     * so the runner cannot lock themselves out of their own account.
     */
    public function unlink_google()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('UserIdentityModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('DELETE');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $user = $this->UserModel->find_active($user_id);
        if (!$user) {
            $this->api->respond_error('Unauthorized', 401);
        }
        if (!$this->UserModel->has_password($user)) {
            $this->api->respond_error(
                'Set an account password before unlinking Google so you can still sign in.',
                409
            );
        }

        $removed = $this->UserIdentityModel->unlink_provider($user_id, UserIdentityModel::PROVIDER_GOOGLE);
        if ($removed) {
            $this->SecurityEventModel->record('google_unlinked', $user_id, ['provider' => 'google']);
        }

        $this->api->respond([
            'data' => [
                'unlinked'   => $removed,
                'identities' => $this->UserIdentityModel->for_user($user_id),
            ],
        ]);
    }

    /**
     * POST /api/auth/forgot-password
     *
     * Protected by rate limits + Google reCAPTCHA (`forgot_password`).
     * Always returns an identical message to avoid account enumeration.
     */
    public function forgot_password()
    {
        $this->call->library('api');
        $captcha = $this->call->library('CaptchaService');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('PasswordResetModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('forgot_pw_' . $ip, 5, 900);

        $input = $this->read_json();
        $this->enforce_captcha($captcha, $input, 'forgot_password', $ip);

        $email = UserModel::normalise_email($input['email'] ?? '');
        if ($problem = UserModel::email_problem($email)) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => ['email' => $problem]], 422);
        }

        $this->api->rate_limit('forgot_pw_email_' . md5($email), 3, 900);

        $user      = $this->UserModel->find_by_email($email);
        $raw_token = null;

        if ($user && $this->UserModel->is_active($user)) {
            $raw_token = $this->PasswordResetModel->issue_for_user((int) $user['id']);
            $this->dispatch_reset_email($email, $raw_token);
            $this->SecurityEventModel->record('password_reset_requested', (int) $user['id'], ['ip' => $ip]);
        } else {
            // Equalise response timing slightly and record without leaking existence
            hash('sha256', $email . microtime());
            $this->SecurityEventModel->record('password_reset_unknown', null, ['ip' => $ip]);
        }

        $data = [
            'message' => 'If an active account exists for that email, password recovery instructions have been sent.',
        ];

        // Development-only convenience when local mail is not running; never active in production.
        if ($raw_token !== null
            && env('APP_ENV', 'development') !== 'production'
            && env('AUTH_DEBUG_RESET_TOKEN', 'false') === 'true') {
            $data['debug_reset_token'] = $raw_token;
        }

        $this->api->respond(['data' => $data]);
    }

    /**
     * POST /api/auth/reset-password
     *
     * Consumes a verified single-use recovery token, updates the password hash,
     * and revokes all active refresh tokens for that account.
     */
    public function reset_password()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('PasswordResetModel');
        $this->call->model('RefreshTokenModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('reset_pw_' . $ip, 10, 900);

        $input    = $this->read_json();
        $token    = trim((string) ($input['token'] ?? ''));
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if ($token === '') {
            $errors['token'] = 'Recovery token is required.';
        }
        if ($problem = UserModel::password_problem($password)) {
            $errors['password'] = $problem;
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $user_id = $this->PasswordResetModel->consume($token);
        if ($user_id === null) {
            $this->SecurityEventModel->record('password_reset_rejected', null, ['ip' => $ip]);
            $this->api->respond_error('This recovery link is invalid or has expired. Request a new one.', 400);
        }

        $user = $this->UserModel->find_active($user_id);
        if (!$user) {
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->UserModel->set_password($user_id, $password);
        $this->RefreshTokenModel->revoke_all_for_user($user_id);
        $this->clear_refresh_cookie();

        $this->SecurityEventModel->record('password_reset_completed', $user_id, ['ip' => $ip]);

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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function read_json()
    {
        // Raw JSON on purpose: body() would HTML-encode passwords/tokens.
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }
        return $input;
    }

    private function enforce_captcha(CaptchaService $captcha, array $input, $action, $ip)
    {
        if (!$captcha->is_required_for($action) && !array_key_exists('captcha_token', $input)) {
            return;
        }
        $token = (string) ($input['captcha_token'] ?? $input['g_recaptcha_response'] ?? '');
        $check = $captcha->verify($token, $action, $ip);
        if (!$check['ok']) {
            $this->SecurityEventModel->record('captcha_rejected', null, [
                'action' => $action,
                'reason' => $check['reason'] ?? 'failed',
            ]);
            $status = (int) ($check['status'] ?? 422);
            if ($status === 422) {
                $this->api->respond([
                    'error'  => $check['error'],
                    'fields' => ['captcha_token' => $check['error']],
                ], 422);
            }
            $this->api->respond_error($check['error'], $status);
        }
    }

    private function dispatch_reset_email($email, $raw_token)
    {
        if (env('SMTP_ENABLED', 'false') !== 'true') {
            return;
        }
        $frontend_base = rtrim((string) env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $link = $frontend_base . '/reset-password?token=' . rawurlencode($raw_token);

        try {
            $this->call->library('email');
            $this->email->sender(
                env('MAIL_FROM_ADDRESS', 'no-reply@runplan.local'),
                env('MAIL_FROM_NAME', 'RunPlan AI')
            );
            $this->email->recipient($email);
            $this->email->subject('Reset your RunPlan AI password');
            $this->email->email_content(
                "You requested a password reset for your RunPlan AI account.\n\n"
                . "Open this link within 30 minutes to choose a new password:\n"
                . $link . "\n\n"
                . "If you did not request this, you can safely ignore this email.",
                'plain'
            );
            $this->email->send();
        } catch (Throwable $e) {
            // Mail transport failure must not expose internal details to caller
        }
    }

    private function start_session($user, $http_code = 200)
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
        ], $http_code);
    }

    private function is_https()
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private function set_refresh_cookie($token)
    {
        setcookie('rp_refresh', $token, [
            'expires'  => time() + (int) config_item('refresh_token_expiration'),
            'path'     => '/api/auth',
            'secure'   => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clear_refresh_cookie()
    {
        setcookie('rp_refresh', '', [
            'expires'  => time() - 3600,
            'path'     => '/api/auth',
            'secure'   => $this->is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
