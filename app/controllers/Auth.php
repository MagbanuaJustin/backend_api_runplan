<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Auth
 *
 * Core first-party email/password authentication, password recovery, and
 * rotating HttpOnly refresh sessions. All persistence and validation rules
 * live in Models (zero controller-level SQL).
 */
class Auth extends Controller {

    public function register()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');
        $this->call->model('SecurityEventModel');

        $this->api->require_method('POST');
        $this->api->rate_limit('register_' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 5, 600);

        $input    = $this->read_json();
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

        $this->SecurityEventModel->record('account_registered', (int) $user['id'], ['method' => 'password']);

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

        $input    = $this->read_json();
        $email    = UserModel::normalise_email($input['email'] ?? '');
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->api->respond_error('Email and password are required.', 422);
        }

        $this->api->rate_limit('login_email_' . md5($email), 10, 600);

        $user = $this->UserModel->authenticate($email, $password);
        if (!$user) {
            $this->SecurityEventModel->record('login_failed', null, ['reason' => 'invalid_credentials']);
            $this->api->respond_error('Invalid email or password.', 401);
        }
        if (!$this->UserModel->is_active($user)) {
            $this->SecurityEventModel->record('login_blocked', (int) $user['id'], ['reason' => 'inactive']);
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->SecurityEventModel->record('login_succeeded', (int) $user['id'], ['method' => 'password']);
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
        $email = UserModel::normalise_email($input['email'] ?? '');

        if ($problem = UserModel::email_problem($email)) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => ['email' => $problem]], 422);
        }

        $this->api->rate_limit('forgot_email_' . md5($email), 5, 600);

        // Anti-enumeration: identical neutral message regardless of account existence
        $data = [
            'ok'      => true,
            'message' => 'If an account matches that email, password reset instructions have been generated.',
        ];

        $user = $this->UserModel->find_by_email($email);
        if ($user && $this->UserModel->is_active($user)) {
            $issued = $this->PasswordResetModel->issue((int) $user['id']);
            $this->SecurityEventModel->record('password_reset_requested', (int) $user['id'], [
                'ttl_minutes' => $issued['ttl_minutes'],
            ]);

            if (env('APP_ENV', 'development') === 'development' && env('PASSWORD_RESET_DEV_PREVIEW', 'true') === 'true') {
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

        $input    = $this->read_json();
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
            $this->SecurityEventModel->record('password_reset_failed', null, ['reason' => 'invalid_or_expired']);
            $this->api->respond_error('That reset link is invalid or has expired. Request a new one.', 400);
        }

        $uid  = (int) $consumed['user_id'];
        $user = $this->UserModel->find_active($uid);
        if (!$user) {
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->UserModel->set_password($uid, $password);
        $revoked = $this->RefreshTokenModel->revoke_all_for_user($uid);
        $this->clear_refresh_cookie();

        $this->SecurityEventModel->record('password_reset_completed', $uid, [
            'revoked_sessions' => $revoked,
        ]);

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

        // Rotate-on-use: revoke the presented refresh token before issuing a new pair
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
