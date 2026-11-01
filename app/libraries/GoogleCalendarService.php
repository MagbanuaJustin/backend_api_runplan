<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Library: GoogleCalendarService
 *
 * Handles Google Calendar OAuth and token requests.
 */
class GoogleCalendarService
{
    const AUTH_URI   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URI  = 'https://oauth2.googleapis.com/token';
    const REVOKE_URI = 'https://oauth2.googleapis.com/revoke';

    /** @var array|null */
    private $config;

    /** @var HttpClient */
    private $http;

    /**
     * @param array|null      $config Optional configuration override, used by tests.
     * @param HttpClient|null $http   Optional HTTP client.
     */
    public function __construct(?array $config = null, ?HttpClient $http = null)
    {
        // Do not call integration_config() here. This library may be instantiated
        // during unrelated requests, including authentication requests.
        $this->config = $config;
        $this->http = $http ?: new HttpClient(15);
    }

    /**
     * Get the Google Calendar config only when this service needs it.
     *
     * If the helper is unavailable, return an empty config rather than causing
     * unrelated requests to fail with an undefined-function fatal error.
     *
     * @return array
     */
    private function provider_config()
{
    if (is_array($this->config)) {
        return $this->config;
    }

    $integrations = function_exists('config_item')
        ? config_item('integrations')
        : null;

    $config = is_array($integrations)
        ? ($integrations['google_calendar'] ?? [])
        : [];

    $this->config = is_array($config) ? $config : [];

    return $this->config;
}

    /**
     * Return a usable timeout value.
     *
     * @return int
     */
    private function timeout()
    {
        $config = $this->provider_config();
        $timeout = (int) ($config['timeout'] ?? 15);

        return $timeout > 0 ? $timeout : 15;
    }

    /**
     * Whether Google Calendar is enabled and has all required credentials.
     *
     * @return bool
     */
    public function is_ready()
    {
        $config = $this->provider_config();
        $enabled = filter_var(
            $config['enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        if ($enabled !== true) {
            return false;
        }

        foreach (array('client_id', 'client_secret', 'redirect_uri') as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the configured calendar scopes.
     *
     * @return array
     */
    public function scopes()
    {
        $config = $this->provider_config();
        $scopes = $config['scopes'] ?? array();

        if (!is_array($scopes) || !$scopes) {
            $scopes = array('https://www.googleapis.com/auth/calendar.events');
        }

        return array_values(array_filter(array_map('strval', $scopes)));
    }

    /**
     * Get the configured event length.
     *
     * @return int
     */
    public function event_minutes()
    {
        $config = $this->provider_config();
        $minutes = (int) ($config['event_length_minutes'] ?? 60);

        return $minutes > 0 ? $minutes : 60;
    }

    /**
     * Build the Google OAuth authorization URL.
     *
     * Returns an empty string if the required settings are not available.
     *
     * @param string $state
     * @return string
     */
    public function authorize_url($state)
    {
        $config = $this->provider_config();

        $clientId = trim((string) ($config['client_id'] ?? ''));
        $redirectUri = trim((string) ($config['redirect_uri'] ?? ''));

        if ($clientId === '' || $redirectUri === '') {
            return '';
        }

        $params = array(
            'client_id'              => $clientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => implode(' ', $this->scopes()),
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => (string) $state,
        );

        return self::AUTH_URI . '?' . http_build_query(
            $params,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    /**
     * Exchange an authorization code for OAuth tokens.
     *
     * @param string $code
     * @return array
     */
    public function exchange_code($code)
    {
        $code = trim((string) $code);

        if ($code === '') {
            return $this->failure(
                'denied',
                'The Google consent screen was closed before access was granted.'
            );
        }

        if (!$this->is_ready()) {
            return $this->failure(
                'unavailable',
                'Google Calendar is not configured.'
            );
        }

        $config = $this->provider_config();

        return $this->token_request(array(
            'code'          => $code,
            'client_id'     => (string) $config['client_id'],
            'client_secret' => (string) $config['client_secret'],
            'redirect_uri'  => (string) $config['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ));
    }

    /**
     * Refresh an existing Google OAuth token.
     *
     * @param string $refresh_token
     * @param array  $previous
     * @return array
     */
    public function refresh($refresh_token, array $previous = array())
    {
        $token = trim((string) $refresh_token);

        if ($token === '') {
            return $this->failure(
                'revoked',
                'This Google connection no longer has a refresh token.'
            );
        }

        if (!$this->is_ready()) {
            return $this->failure(
                'unavailable',
                'Google Calendar is not configured.'
            );
        }

        $config = $this->provider_config();

        return $this->token_request(array(
            'refresh_token' => $token,
            'client_id'     => (string) $config['client_id'],
            'client_secret' => (string) $config['client_secret'],
            'grant_type'    => 'refresh_token',
        ), $previous);
    }

    /**
     * Revoke a Google OAuth token.
     *
     * @param string $token
     * @return bool
     */
    public function revoke($token)
    {
        $token = trim((string) $token);

        if ($token === '') {
            return true;
        }

        $res = $this->http->request(
            self::REVOKE_URI . '?token=' . rawurlencode($token),
            array(
                'method'  => 'POST',
                'headers' => array(
                    'Content-Type: application/x-www-form-urlencoded',
                ),
                'body'    => '',
                'timeout' => $this->timeout(),
            )
        );

        return (bool) ($res['ok'] ?? false);
    }

    /**
     * Send an OAuth token request to Google.
     *
     * @param array $form
     * @param array $previous
     * @return array
     */
    private function token_request(array $form, array $previous = array())
    {
        $res = $this->http->request(self::TOKEN_URI, array(
            'method'  => 'POST',
            'headers' => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ),
            'body'    => http_build_query($form, '', '&'),
            'timeout' => $this->timeout(),
        ));

        $data = is_array($res['data'] ?? null) ? $res['data'] : array();

        if (empty($res['ok'])) {
            $errorCode = $this->map_error($res, $data);

            return $this->failure(
                $errorCode,
                $this->message_for($errorCode)
            );
        }

        if (empty($data['access_token'])) {
            return $this->failure(
                'unavailable',
                'Google did not return an access token.'
            );
        }

        return array(
            'ok'     => true,
            'tokens' => $this->normalise($data, $previous),
        );
    }

    /**
     * Map Google or transport errors to app error codes.
     *
     * @param array $res
     * @param array $data
     * @return string
     */
    private function map_error(array $res, array $data)
    {
        $status = (int) ($res['status'] ?? $res['http_status'] ?? 0);
        $providerError = strtolower((string) ($data['error'] ?? ''));

        if ($providerError === 'invalid_grant' || $providerError === 'invalid_request') {
            return 'revoked';
        }

        if ($providerError === 'invalid_client' || $status === 401 || $status === 403) {
            return 'unauthorized';
        }

        if ($status === 429) {
            return 'rate_limited';
        }

        return 'unavailable';
    }

    /**
     * Convert an error code into a user-facing message.
     *
     * @param string $code
     * @return string
     */
    private function message_for($code)
    {
        switch ((string) $code) {
            case 'revoked':
                return 'The Google Calendar connection has expired. Please reconnect it.';

            case 'unauthorized':
                return 'Google rejected the Calendar credentials. Please reconnect the integration.';

            case 'rate_limited':
                return 'Google Calendar is temporarily rate limited. Please try again later.';

            default:
                return 'Google Calendar is temporarily unavailable. Please try again later.';
        }
    }

    /**
     * Return a normalized failure response.
     *
     * @param string $code
     * @param string $message
     * @return array
     */
    private function failure($code, $message)
    {
        return array(
            'ok'         => false,
            'error_code' => (string) $code,
            'error'      => (string) $message,
        );
    }

    /**
     * Normalize Google's OAuth token response.
     *
     * @param array $data
     * @param array $previous
     * @return array
     */
    private function normalise(array $data, array $previous = array())
    {
        $expiresIn = max(0, (int) ($data['expires_in'] ?? 0));

        return array(
            'access_token'  => (string) $data['access_token'],
            'expires_at'    => gmdate('Y-m-d H:i:s', time() + $expiresIn),
            'token_type'    => (string) ($data['token_type'] ?? 'Bearer'),
            'refresh_token' => (string) (
                $data['refresh_token']
                ?? $previous['refresh_token']
                ?? ''
            ),
            'scope'         => (string) (
                $data['scope']
                ?? $previous['scope']
                ?? implode(' ', $this->scopes())
            ),
        );
    }
}