<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Auth extends Controller {

    public function register()
    {
        $this->call->library('api');
        $this->call->database();

        $this->api->require_method('POST');
        $this->api->rate_limit('register_' . ($_SERVER['REMOTE_ADDR'] ?? 'x'), 5, 600);

        // Raw JSON on purpose: body() would HTML-encode the password.
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $email    = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $errors['email'] = 'Enter a valid email address.';
        }
        if (strlen($password) < 8) {
            $errors['password'] = 'Use at least 8 characters.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'Use at most 72 characters.';
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $exists = $this->db->table('users')->where('email', $email)->get();
        if ($exists) {
            $this->api->respond_error('That email is already registered.', 409);
        }

        $this->db->table('users')->insert([
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => 'runner',
        ]);

        $this->api->respond([
            'data' => ['id' => (int) $this->db->last_id(), 'email' => $email, 'role' => 'runner'],
        ], 201);
    }

    public function login()
    {
        $this->call->library('api');
        $this->call->database();

        $this->api->require_method('POST');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'x';
        $this->api->rate_limit('login_' . $ip, 10, 600);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $email    = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');

        if ($email === '' || $password === '') {
            $this->api->respond_error('Email and password are required.', 422);
        }

        $this->api->rate_limit('login_email_' . md5($email), 10, 600);

        $user = $this->db->table('users')->where('email', $email)->get();

        if ($user && !empty($user['password_hash'])) {
            $ok = password_verify($password, $user['password_hash']);
        } else {
            password_hash($password, PASSWORD_DEFAULT); // same cost, hides unknown emails
            $ok = false;
        }

        if (!$ok) {
            $this->api->respond_error('Invalid email or password.', 401);
        }
        if ($user['status'] !== 'active') {
            $this->api->respond_error('This account is not active.', 403);
        }

        $this->start_session($user);
    }

    public function refresh()
    {
        $this->call->library('api');
        $this->call->database();

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

        $hashed = hash_hmac('sha256', $token, config_item('refresh_token_key'));
        $row = $this->db->raw(
            'SELECT id FROM refresh_tokens WHERE token = ? AND expires_at > NOW() LIMIT 1',
            [$hashed]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->clear_refresh_cookie();
            $this->api->respond_error('Session expired.', 401);
        }

        $user = $this->db->table('users')->where('id', (int) $payload['sub'])->get();
        if (!$user || $user['status'] !== 'active') {
            $this->clear_refresh_cookie();
            $this->api->respond_error('Session expired.', 401);
        }

        $this->api->revoke_refresh_token($token);   // rotation: old token dies here
        $this->start_session($user);
    }

        public function me()
    {
        $this->call->library('api');
        $this->call->database();

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $user = $this->db->table('users')->where('id', (int) $payload['sub'])->get();
        if (!$user || $user['status'] !== 'active') {
            $this->api->respond_error('Unauthorized', 401);
        }

        header('Cache-Control: no-store');
        $this->api->respond([
            'data' => [
                'id'    => (int) $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
            ],
        ]);
    }

    public function logout()
    {
        $this->call->library('api');
        $this->call->database();

        $this->api->require_method('POST');

        $token = (string) ($_COOKIE['rp_refresh'] ?? '');
        if ($token !== '') {
            $this->api->revoke_refresh_token($token);   // dies on the server, not just in the browser
        }
        $this->clear_refresh_cookie();

        $this->api->respond(['data' => ['ok' => true]]);
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
                'user'         => [
                    'id'    => (int) $user['id'],
                    'email' => $user['email'],
                    'role'  => $user['role'],
                ],
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