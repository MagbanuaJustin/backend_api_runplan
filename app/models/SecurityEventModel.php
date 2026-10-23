<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: SecurityEventModel
 *
 * Writes minimal, scrubbed audit records to `auth_security_events`
 * (Spec Section 8 & 9.1). Passwords, raw Google ID tokens, raw CAPTCHA tokens,
 * refresh tokens, and secrets are stripped by whitelist/denylist before save.
 */
class SecurityEventModel extends Model {

    protected $table       = 'auth_security_events';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    const FORBIDDEN_KEYS = [
        'password', 'password_hash', 'current_password', 'new_password',
        'token', 'access_token', 'refresh_token', 'id_token', 'credential',
        'captcha', 'captcha_token', 'recaptcha_token', 'g-recaptcha-response',
        'secret', 'api_key', 'client_secret', 'encrypted_tokens',
    ];

    public static function scrub(array $metadata)
    {
        $clean = [];
        foreach ($metadata as $k => $v) {
            $key = strtolower((string) $k);
            $blocked = false;
            foreach (self::FORBIDDEN_KEYS as $bad) {
                if ($key === $bad || strpos($key, 'password') !== false
                    || strpos($key, 'secret') !== false || strpos($key, 'token') !== false
                    || strpos($key, 'credential') !== false || strpos($key, 'captcha') !== false) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }
            if (is_scalar($v) || $v === null) {
                $clean[$key] = is_string($v) ? mb_substr($v, 0, 160) : $v;
            }
        }
        return $clean;
    }

    public function record($event_type, $user_id = null, array $metadata = [])
    {
        $type  = mb_substr(preg_replace('/[^a-z0-9_.-]/i', '_', (string) $event_type), 0, 40);
        $clean = self::scrub($metadata);

        try {
            $this->db->raw(
                'INSERT INTO auth_security_events (user_id, event_type, safe_metadata)
                 VALUES (?, ?, ?)',
                [
                    $user_id !== null ? (int) $user_id : null,
                    $type,
                    $clean ? json_encode($clean, JSON_UNESCAPED_SLASHES) : null,
                ]
            );
        } catch (Throwable $e) {
            // Audit logging must never crash the primary auth flow if a table is unavailable.
        }
    }
}
