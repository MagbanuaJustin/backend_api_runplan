<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Library: CaptchaService
 *
 * Server-side Google reCAPTCHA verification (Spec Section 5.7, 9.1, 9.3, TC-13).
 *
 * Supports `v3` (score + action), `v2` (checkbox), and `enterprise`
 * (assessments API). Never stores raw CAPTCHA tokens.
 *
 * Fail-closed rule (Spec 5.7): when reCAPTCHA is enabled for an action, a
 * missing, invalid, expired, low-score, wrong-action, or unreachable check
 * MUST reject the request rather than silently treating it as verified.
 */
class CaptchaService {

    /** @var HttpClient */
    private $http;

    /** @var array */
    private $cfg;

    public function __construct(?array $override_config = null, ?HttpClient $http = null)
    {
        if (!class_exists('HttpClient')) {
            require_once APP_DIR . 'libraries/HttpClient.php';
        }
        $this->cfg  = $override_config !== null ? $override_config : integration_config('recaptcha');
        $timeout    = (int) ($this->cfg['timeout'] ?? 10);
        $this->http = $http ?: new HttpClient($timeout);
    }

    /**
     * Is CAPTCHA verification active for this action?
     */
    public function is_required_for($action)
    {
        $enabled = filter_var($this->cfg['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return false;
        }
        $require_on = $this->cfg['require_on'] ?? ['register', 'forgot_password'];
        return is_array($require_on) && in_array($action, $require_on, true);
    }

    /**
     * Verify a CAPTCHA token for a given logical action (`register`, `forgot_password`, `login`).
     *
     * Returns:
     *   ['ok' => true,  'skipped' => bool, 'score' => ?float]
     *   ['ok' => false, 'status' => int, 'error' => string, 'reason' => string]
     */
    public function verify($token, $action, $remote_ip = null)
    {
        $enabled = filter_var($this->cfg['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            return ['ok' => true, 'skipped' => true, 'score' => null];
        }

        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 4096) {
            return [
                'ok'     => false,
                'status' => 422,
                'error'  => 'Complete the security check and try again.',
                'reason' => 'missing_token',
            ];
        }

        $version       = strtolower((string) ($this->cfg['version'] ?? 'v3'));
        $expected_act  = (string) (($this->cfg['actions'][$action] ?? null) ?: $action);
        $min_score     = (float) ($this->cfg['min_score'] ?? 0.5);

        if ($version === 'enterprise') {
            return $this->verify_enterprise($token, $expected_act, $min_score);
        }

        return $this->verify_standard($token, $version, $expected_act, $min_score, $remote_ip);
    }

    private function verify_standard($token, $version, $expected_action, $min_score, $remote_ip)
    {
        $secret = trim((string) ($this->cfg['secret'] ?? ''));
        if ($secret === '') {
            // Fail closed when enabled without server credentials (Spec 5.7)
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Security verification is temporarily unavailable. Please try again shortly.',
                'reason' => 'misconfigured',
            ];
        }

        $fields = [
            'secret'   => $secret,
            'response' => $token,
        ];
        if (is_string($remote_ip) && $remote_ip !== '' && filter_var($remote_ip, FILTER_VALIDATE_IP)) {
            $fields['remoteip'] = $remote_ip;
        }

        $uri = (string) ($this->cfg['verify_uri'] ?? 'https://www.google.com/recaptcha/api/siteverify');
        $res = $this->http->request($uri, [
            'method'  => 'POST',
            'headers' => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            'body'    => http_build_query($fields, '', '&', PHP_QUERY_RFC3986),
        ]);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Could not verify the security check right now. Please try again.',
                'reason' => 'provider_unreachable',
            ];
        }

        $data = $res['data'];
        if (empty($data['success'])) {
            return [
                'ok'     => false,
                'status' => 422,
                'error'  => 'The security check expired or was invalid. Please try again.',
                'reason' => 'rejected',
            ];
        }

        $score = isset($data['score']) && is_numeric($data['score']) ? (float) $data['score'] : null;

        if ($version === 'v3') {
            $returned_action = (string) ($data['action'] ?? '');
            if ($returned_action === '' || $returned_action !== $expected_action) {
                return [
                    'ok'     => false,
                    'status' => 422,
                    'error'  => 'Security check did not match this form. Refresh and try again.',
                    'reason' => 'action_mismatch',
                ];
            }
            if ($score === null || $score < $min_score) {
                return [
                    'ok'     => false,
                    'status' => 422,
                    'error'  => 'Automated request check failed. Please retry.',
                    'reason' => 'low_score',
                ];
            }
        }

        return ['ok' => true, 'skipped' => false, 'score' => $score];
    }

    private function verify_enterprise($token, $expected_action, $min_score)
    {
        $api_key    = trim((string) ($this->cfg['secret'] ?? ''));
        $site_key   = trim((string) ($this->cfg['site_key'] ?? ''));
        $project_id = trim((string) ($this->cfg['project_id'] ?? ''));

        if ($api_key === '' || $site_key === '' || $project_id === '') {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Security verification is temporarily unavailable. Please try again shortly.',
                'reason' => 'misconfigured',
            ];
        }

        $url = 'https://recaptchaenterprise.googleapis.com/v1/projects/'
             . rawurlencode($project_id) . '/assessments?key=' . rawurlencode($api_key);

        $res = $this->http->post_json($url, [
            'event' => [
                'token'          => $token,
                'siteKey'        => $site_key,
                'expectedAction' => $expected_action,
            ],
        ]);

        if (!$res['ok'] || !is_array($res['data'])) {
            return [
                'ok'     => false,
                'status' => 503,
                'error'  => 'Could not verify the security check right now. Please try again.',
                'reason' => 'provider_unreachable',
            ];
        }

        $props = $res['data']['tokenProperties'] ?? [];
        if (empty($props['valid'])) {
            return [
                'ok'     => false,
                'status' => 422,
                'error'  => 'The security check expired or was invalid. Please try again.',
                'reason' => 'rejected',
            ];
        }
        if (($props['action'] ?? '') !== $expected_action) {
            return [
                'ok'     => false,
                'status' => 422,
                'error'  => 'Security check did not match this form. Refresh and try again.',
                'reason' => 'action_mismatch',
            ];
        }

        $score = isset($res['data']['riskAnalysis']['score'])
            ? (float) $res['data']['riskAnalysis']['score']
            : null;
        if ($score === null || $score < $min_score) {
            return [
                'ok'     => false,
                'status' => 422,
                'error'  => 'Automated request check failed. Please retry.',
                'reason' => 'low_score',
            ];
        }

        return ['ok' => true, 'skipped' => false, 'score' => $score];
    }
}
