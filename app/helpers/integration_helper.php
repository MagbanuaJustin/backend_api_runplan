<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Integration helper
 *
 * Shared read-side API for app/config/integrations.php.
 * Missing provider configuration is treated as not ready so optional
 * integrations do not prevent the core planner from working.
 */

if (!function_exists('integration_config')) {
    /**
     * Return the full integrations config, or one provider's config.
     *
     * @param string|null $provider
     * @return array
     */
    function integration_config($provider = null)
    {
        static $all = null;

        if ($all === null) {
            $loaded = config_item('integrations');
            $all = is_array($loaded) ? $loaded : array();
        }

        if ($provider === null) {
            return $all;
        }

        $block = $all[$provider] ?? null;
        return is_array($block) ? $block : array();
    }
}

if (!function_exists('integration_value')) {
    /**
     * Return one setting with an optional fallback.
     *
     * @param string $provider
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function integration_value($provider, $key, $default = null)
    {
        $block = integration_config($provider);
        return array_key_exists($key, $block) ? $block[$key] : $default;
    }
}

if (!function_exists('integration_credential_present')) {
    /**
     * Check whether a provider has its required credentials.
     * This does not check whether the provider is enabled.
     *
     * @param string $provider
     * @return bool
     */
    function integration_credential_present($provider)
    {
        $block = integration_config($provider);
        if (!$block) {
            return false;
        }

        $required = array(
            'groq'            => array('api_key'),
            'openweathermap'  => array('api_key'),
            'google_calendar' => array('client_id', 'client_secret', 'redirect_uri', 'token_aad'),
            'google_identity' => array('client_id'),
            'recaptcha'       => array('project_id', 'api_key', 'site_key', 'allowed_hostnames'),
            'push'            => array(),
        );

        if ($provider === 'push') {
            $needed = array('worker_token');

               if (strtolower((string) ($block['driver'] ?? 'vapid')) === 'fcm') {
                    $needed[] = 'fcm_project';
                } else {
                    $needed[] = 'public_key';
                    $needed[] = 'private_key';
               }
        } else {
            $needed = $required[$provider] ?? array();
        }

        foreach ($needed as $key) {
            $value = $block[$key] ?? null;

            if (!is_string($value) || trim($value) === '') {
                return false;
            }
        }

        if (
            $provider === 'push'
           && strlen(trim((string) ($block['worker_token'] ?? ''))) < 32
        ) {
         return false;
        }

        // This installation uses Google Cloud Fraud Defense checkbox keys.
        if (
            $provider === 'recaptcha'
            && !in_array(
                strtolower((string) ($block['version'] ?? 'checkbox')),
                array('checkbox', 'v2', 'v2_checkbox'),
                true
            )
        ) {
            return false;
        }

        return true;
    }
}

if (!function_exists('integration_ready')) {
    /**
     * A provider is ready when enabled and fully configured.
     *
     * @param string $provider
     * @return bool
     */
    function integration_ready($provider)
    {
        $block = integration_config($provider);
        if (!$block) {
            return false;
        }

        $enabled = filter_var(
            $block['enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );

        return $enabled === true && integration_credential_present($provider);
    }
}

if (!function_exists('integration_public_config')) {
    /**
     * Return safe provider configuration for the browser.
     * Do not add secrets, private keys, or API keys here.
     *
     * @return array
     */
    function integration_public_config()
    {
        return array(
            'groq' => array(
                'ready' => integration_ready('groq'),
                'enabled' => (bool) filter_var(
                    integration_value('groq', 'enabled', false),
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE
                ),
                'configured' => integration_credential_present('groq'),
                'model' => integration_value('groq', 'model'),
            ),
            'weather' => array(
                'ready' => integration_ready('openweathermap'),
                'configured' => integration_credential_present('openweathermap'),
                'product' => integration_value('openweathermap', 'product'),
                'forecast_days' => (int) integration_value(
                    'openweathermap',
                    'forecast_days',
                    0
                ),
            ),
            'google_calendar' => array(
                'ready' => integration_ready('google_calendar'),
                'configured' => integration_credential_present('google_calendar'),
                'scopes' => integration_value('google_calendar', 'scopes', array()),
            ),
            'push' => array(
                'ready' => integration_ready('push'),
                'configured' => integration_credential_present('push'),
                'driver' => integration_value('push', 'driver'),
                'public_key' => integration_value('push', 'public_key'),
            ),
            'google_identity' => array(
                'ready' => integration_ready('google_identity'),
                'configured' => integration_credential_present('google_identity'),
                'client_id' => integration_value('google_identity', 'client_id'),
            ),
            'recaptcha' => array(
                'ready' => integration_ready('recaptcha'),
                'configured' => integration_credential_present('recaptcha'),
                'version' => integration_value('recaptcha', 'version'),
                'site_key' => integration_value('recaptcha', 'site_key'),
                'assessment_api' => 'Google Cloud Fraud Defense',
                'required_on' => integration_value('recaptcha', 'require_on', array()),
            ),
            'strava' => array(
                'ready' => false,
                'configured' => false,
                'reason' => 'Disabled pending review of the current Strava API Agreement and Policy.',
            ),
        );
    }
}