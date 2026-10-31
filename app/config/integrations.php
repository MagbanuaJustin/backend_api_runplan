<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------
| EXTERNAL INTEGRATIONS
| -------------------------------------------------------------------
| Credentials are read from the environment. Secrets stay server-side.
| The core planner must keep working when optional providers are disabled.
*/

$config['integrations'] = array(
    /*
     | Groq - plan drafting, feedback, and chat
     */
    'groq' => array(
        'enabled'             => env('GROQ_ENABLED', 'false') === 'true',
        'api_key'             => env('GROQ_API_KEY'),
        'model'               => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'base_uri'            => env('GROQ_BASE_URI', 'https://api.groq.com/openai/v1'),
        'timeout'             => (int) env('GROQ_TIMEOUT', 20),
        'max_tokens'          => (int) env('GROQ_MAX_TOKENS', 700),
        'monthly_call_cap'    => (int) env('GROQ_MONTHLY_CALL_CAP', 500),
        'plan_schema_version' => '1',
    ),

    /*
     | OpenWeatherMap - weather advisories
     */
    'openweathermap' => array(
        'enabled'       => env('OWM_ENABLED', 'false') === 'true',
        'api_key'       => env('OWM_API_KEY'),
        'base_uri'      => env('OWM_BASE_URI', 'https://api.openweathermap.org'),
        'product'       => env('OWM_PRODUCT', 'forecast16'),
        'units'         => 'metric',
        'forecast_days' => (int) env('OWM_FORECAST_DAYS', 5),
        'timeout'       => (int) env('OWM_TIMEOUT', 10),
        'cache_minutes' => (int) env('OWM_CACHE_MINUTES', 60),
    ),

    /*
     | Google Calendar - opt-in event sync
     */
    'google_calendar' => array(
        'enabled'       => env('GCAL_ENABLED', 'false') === 'true',
        'client_id'     => env('GCAL_CLIENT_ID'),
        'client_secret' => env('GCAL_CLIENT_SECRET'),
        'redirect_uri'  => env('GCAL_REDIRECT_URI'),
        'scopes'        => array('https://www.googleapis.com/auth/calendar.events'),
        'token_aad'     => env('GCAL_TOKEN_AAD'),
        'timeout'       => (int) env('GCAL_TIMEOUT', 15),
        'event_length_minutes' => (int) env('GCAL_EVENT_MINUTES', 60),
        'timezone'      => env('GCAL_TIMEZONE', 'Asia/Manila'),
        'return_url'    => env('GCAL_RETURN_URL', 'http://localhost:5173/calendar'),
    ),

    /*
     | Push reminders
     */
    'push' => array(
        'enabled'             => env('PUSH_ENABLED', 'false') === 'true',
        'driver'              => env('PUSH_DRIVER', 'vapid'),
        'public_key'          => env('VAPID_PUBLIC_KEY'),
        'private_key'         => env('VAPID_PRIVATE_KEY'),
        'subject'             => env('VAPID_SUBJECT', 'mailto:runplan@localhost'),
        'worker_token'        => env('NOTIFICATION_WORKER_TOKEN'),
        'fcm_project'          => env('FCM_PROJECT_ID'),
        'fcm_credential_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'timeout'             => (int) env('PUSH_TIMEOUT', 10),
    ),

    /*
     | Google Identity Services - Sign in with Google
     */
    'google_identity' => array(
        'enabled'            => env('GOOGLE_LOGIN_ENABLED', 'false') === 'true',
        'client_id'          => env('GOOGLE_CLIENT_ID'),
        'jwks_uri'           => env('GOOGLE_JWKS_URI', 'https://www.googleapis.com/oauth2/v3/certs'),
        'issuers'            => array('https://accounts.google.com', 'accounts.google.com'),
        'timeout'            => (int) env('GOOGLE_TIMEOUT', 10),
        'jwks_cache_seconds' => 3600,
        'leeway_seconds'     => 120,
    ),

    /*
     | Google Cloud reCAPTCHA Enterprise checkbox
     */
    'recaptcha' => array(
        'enabled'           => env('RECAPTCHA_ENABLED', 'false') === 'true',
        'version'           => env('RECAPTCHA_VERSION', 'checkbox'),
        'project_id'        => env('RECAPTCHA_PROJECT_ID'),
        'api_key'            => env('RECAPTCHA_API_KEY'),
        'site_key'           => env('RECAPTCHA_SITE_KEY'),
        'allowed_hostnames' => env('RECAPTCHA_ALLOWED_HOSTNAMES', ''),
        'verify_uri'        => 'https://recaptchaenterprise.googleapis.com/v1',
        'timeout'           => (int) env('RECAPTCHA_TIMEOUT', 10),
        'require_on'        => array('register', 'login', 'google_login', 'forgot_password', 'reset_password'),
    ),

    /*
     | Strava is intentionally not configured.
     */
);