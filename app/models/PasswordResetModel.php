<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: PasswordResetModel
 *
 * Single-use, short-lived password recovery tokens (`password_reset_tokens`).
 *
 * Only `hash('sha256', $raw_token)` is stored in the database; the raw CSPRNG
 * token is returned once to the caller for out-of-band email delivery.
 */
class PasswordResetModel extends Model {

    protected $table       = 'password_reset_tokens';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    const TTL_MINUTES = 30;

    public static function hash($raw_token)
    {
        return hash('sha256', (string) $raw_token);
    }

    /**
     * Issue a fresh reset token for $user_id, invalidating any unused earlier
     * reset tokens for that user.
     *
     * @return string 64-character hex raw token (for email link only)
     */
    public function issue_for_user($user_id, $ttl_minutes = self::TTL_MINUTES)
    {
        $uid        = (int) $user_id;
        $raw_token  = bin2hex(random_bytes(32));
        $token_hash = self::hash($raw_token);
        $expires_at = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . max(5, (int) $ttl_minutes) . ' minutes')
            ->format('Y-m-d H:i:s');

        $this->db->raw(
            'UPDATE password_reset_tokens SET used_at = CURRENT_TIMESTAMP
              WHERE user_id = ? AND used_at IS NULL',
            [$uid]
        );

        $this->db->raw(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
             VALUES (?, ?, ?)',
            [$uid, $token_hash, $expires_at]
        );

        return $raw_token;
    }

    /**
     * Consume a valid, unexpired, single-use reset token and return its user_id,
     * or null when missing/expired/already used.
     *
     * @param string $raw_token
     * @return int|null
     */
    public function consume($raw_token)
    {
        $raw_token = trim((string) $raw_token);
        if (!preg_match('/^[a-f0-9]{64}$/i', $raw_token)) {
            return null;
        }

        $now_utc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $hash    = self::hash($raw_token);

        $row = $this->db->raw(
            'SELECT id, user_id FROM password_reset_tokens
              WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? LIMIT 1',
            [$hash, $now_utc]
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $updated = $this->db->raw(
            'UPDATE password_reset_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL',
            [$now_utc, (int) $row['id']]
        );
        if (!$updated || $updated->rowCount() === 0) {
            return null;
        }

        return (int) $row['user_id'];
    }
}
