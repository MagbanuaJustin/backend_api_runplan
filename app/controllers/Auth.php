<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Auth
 *
 * Core first-party email/password authentication and rotating HttpOnly refresh
 * sessions. All persistence and validation rules live in UserModel and
 * RefreshTokenModel (zero controller-level SQL).
 */
class Auth extends Controller {

    public function register()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('UserModel');

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
            $this->api->respond_error('Invalid email or password.', 401);
        }
        if (!$this->UserModel->is_active($user)) {
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->start_session($user);
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
