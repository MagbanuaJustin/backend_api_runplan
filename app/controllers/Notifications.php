<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Notifications extends Controller
{
    public function preferences()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('NotificationModel');
        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $timezone = $this->valid_timezone($_GET['timezone'] ?? 'Asia/Manila');
        header('Cache-Control: no-store');
        $this->api->respond(array(
            'data' => $this->NotificationModel->preferences_for_user((int) $payload['sub'], $timezone)
        ));
    }

    public function save_preferences()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('NotificationModel');
        $this->api->require_method('PUT');
        $payload = $this->api->require_jwt();
        $input = json_decode(file_get_contents('php://input'), true);

        if (!is_array($input)) $this->api->respond_error('Invalid JSON body', 400);

        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $lead = filter_var($input['lead_minutes'] ?? 60, FILTER_VALIDATE_INT);
        $timezone = $this->valid_timezone($input['timezone'] ?? 'Asia/Manila', false);
        $errors = array();

        if ($enabled === null) $errors['enabled'] = 'Choose whether run reminders are enabled.';
        if ($lead === false || !in_array((int) $lead, array(30, 60), true)) {
            $errors['lead_minutes'] = 'Choose 30 or 60 minutes before your run.';
        }
        if ($timezone === null) $errors['timezone'] = 'Choose a valid device timezone.';
        if ($errors) $this->api->respond(array('error' => 'Validation failed', 'fields' => $errors), 422);

        if ($enabled) {
            $current = $this->NotificationModel->preferences_for_user((int) $payload['sub'], $timezone);
            if (empty($current['subscribed'])) {
                $this->api->respond_error('Allow browser notifications on this device before enabling reminders.', 409);
            }
            if (!integration_ready('push')) {
                $this->api->respond_error('Push reminders are not configured by the server yet.', 503);
            }
        }

        $this->NotificationModel->save_preferences((int) $payload['sub'], $enabled, (int) $lead, $timezone);
        $this->api->respond(array(
            'data' => $this->NotificationModel->preferences_for_user((int) $payload['sub'], $timezone)
        ));
    }

    public function subscribe()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('NotificationModel');
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();

        if (!integration_ready('push')) {
            $this->api->respond_error('Push reminders are not configured by the server yet.', 503);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $endpoint = is_array($input) ? trim((string) ($input['endpoint'] ?? '')) : '';
        $keys = is_array($input) ? ($input['keys'] ?? null) : null;
        $url = parse_url($endpoint);
        $host = strtolower((string) ($url['host'] ?? ''));

        $valid_host = $host === 'fcm.googleapis.com'
            || $host === 'push.services.mozilla.com'
            || str_ends_with($host, '.push.services.mozilla.com')
            || $host === 'push.apple.com'
            || str_ends_with($host, '.push.apple.com')
            || $host === 'notify.windows.com'
            || str_ends_with($host, '.notify.windows.com');

        if (!is_array($input) || strlen($endpoint) > 2048
            || strtolower((string) ($url['scheme'] ?? '')) !== 'https'
            || !$valid_host || !is_array($keys)) {
            $this->api->respond_error('Invalid browser push subscription.', 422);
        }

        $p256dh = (string) ($keys['p256dh'] ?? '');
        $auth = (string) ($keys['auth'] ?? '');
        $public_raw = $this->decode_base64url($p256dh);
        $auth_raw = $this->decode_base64url($auth);

        if (strlen($public_raw) !== 65 || $public_raw[0] !== "\x04" || strlen($auth_raw) < 16) {
            $this->api->respond_error('Invalid browser push encryption keys.', 422);
        }

        $this->NotificationModel->save_subscription(
            (int) $payload['sub'],
            $endpoint,
            hash('sha256', $endpoint),
            array('p256dh' => $p256dh, 'auth' => $auth)
        );

        $this->api->respond(array('data' => array('subscribed' => true)), 201);
    }

    public function unsubscribe()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('NotificationModel');
        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();

        $input = json_decode(file_get_contents('php://input'), true);
        $endpoint = is_array($input) ? trim((string) ($input['endpoint'] ?? '')) : '';

        if ($endpoint === '' || strlen($endpoint) > 2048) {
            $this->api->respond_error('Invalid subscription endpoint.', 422);
        }

        $this->NotificationModel->remove_subscription((int) $payload['sub'], $endpoint);
        $this->api->respond(array('data' => array('subscribed' => false)));
    }

    public function dispatch()
    {
        $this->call->library('api');
        $this->api->require_method('POST');

        $configured = (string) integration_value('push', 'worker_token', '');
        $headers = function_exists('getallheaders') ? getallheaders() : array();
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? ($headers['Authorization'] ?? $headers['authorization'] ?? ''));
        $provided = preg_match('/^Bearer\s+(.+)$/i', $header, $match) ? trim($match[1]) : '';

        if ($configured === '' || $provided === '' || !hash_equals($configured, $provided)) {
            $this->api->respond_error('Unauthorized', 401);
        }

        $this->call->database();
        $this->call->model('NotificationModel');
        header('Cache-Control: no-store');
        $this->api->respond(array('data' => $this->NotificationModel->dispatch_due(100)));
    }

    private function valid_timezone($value, $fallback = true)
    {
        if (!is_string($value) || $value === '' || strlen($value) > 64) {
            return $fallback ? 'Asia/Manila' : null;
        }

        try {
            $zone = new DateTimeZone($value);
            return $zone->getName();
        } catch (Throwable $e) {
            return $fallback ? 'Asia/Manila' : null;
        }
    }

    private function decode_base64url($value)
    {
        $value = strtr($value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }
}