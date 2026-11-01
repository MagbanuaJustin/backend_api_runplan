<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Library: OAuthState
 * 
 * Automatically generated via CLI.
 */
class OAuthState {

    /** @var string Prefix, so a future format change is detectable. */
    const PREFIX = 'v1';

    /** @var int Seconds a flow may take from "connect" to "callback". */
    const TTL = 600;

    /** @var string|null HMAC key, or null when no secret is configured. */
    private $key;

    /**
     * @param string|null $secret Shared secret. Omit to use GCAL_TOKEN_AAD.
     *                           The string is SHA-256 hashed with a purpose label
     *                           before use, so the same secret can safely sign
     *                           two different things.
     */
     public function __construct($secret = null)
    {
        $value = $secret === null ? '' : (string) $secret;
        $value = trim($value);

        $this->key = $value === '' ? null : hash('sha256', 'runplan.oauth.state|' . $value, true);
    }

    /**
     * @return bool
     */
    public function is_ready()
    {
        return $this->key !== null;
    }

    /**
     * Build a state value for one user.
     *
     * @param int      $user_id
     * @param int|null $ttl  seconds, defaults to self::TTL
     * @param int|null $now  unix time, injectable for tests
     * @return string|null null when no secret is configured or the id is invalid
     */
    public function issue($user_id, $ttl = self::TTL, $now = null)
    {
        $uid = (int) $user_id;
        if ($this->key === null || $uid <= 0) {
            return null;
        }

        $exp   = (int) ($now === null ? time() : $now) + max(60, (int) $ttl);
        $nonce = bin2hex(random_bytes(8));
        $body  = self::PREFIX . '.' . $uid . '.' . $exp . '.' . $nonce;

        return $body . '.' . $this->sign($body);
    }

    /**
     * Validate a state value.
     *
     * @param mixed    $state value from the callback query string
     * @param int|null $now   unix time, injectable for tests
     * @return int|null the user id it was issued for, or null when it is not valid
     */
    public function verify($state, $now = null)
    {
        if ($this->key === null || !is_string($state) || $state === '') {
            return null;
        }

        $parts = explode('.', $state);
        if (count($parts) !== 5 || $parts[0] !== self::PREFIX) {
            return null;
        }

        list(, $uid_raw, $exp_raw, $nonce, $signature) = $parts;

        // Both must be plain digits: this rejects "12e3", "+12" and " 12".
        if (!ctype_digit($uid_raw) || !ctype_digit($exp_raw) || !ctype_xdigit($nonce)) {
            return null;
        }

        $body = self::PREFIX . '.' . $uid_raw . '.' . $exp_raw . '.' . $nonce;
        if (!hash_equals($this->sign($body), strtolower($signature))) {
            return null;
        }

        if ((int) $exp_raw < (int) ($now === null ? time() : $now)) {
            return null;
        }

        $uid = (int) $uid_raw;
        return $uid > 0 ? $uid : null;
    }

    /**
     * @param string $body
     * @return string 32 hex characters
     */
    private function sign($body)
    {
        return substr(hash_hmac('sha256', $body, (string) $this->key), 0, 32);
    }
}
