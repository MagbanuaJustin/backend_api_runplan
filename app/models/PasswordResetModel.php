<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: PasswordResetModel
 * 
 * Automatically generated via CLI.
 */
class PasswordResetModel extends Model
{
    protected $table = 'password_reset_tokens';
    protected $primary_key = 'id';
    protected $timestamps = false;

    const TTL_MINUTES = 30;

    /**
     * Compute a 64-character hex HMAC for a raw reset token.
     */
    public function hash($raw_token)
    {
        $key = (string) (
            config_item('refresh_token_key')
            ?: config_item('jwt_secret')
            ?: 'runplan-reset-fallback-key'
        );

        return hash_hmac('sha256', (string) $raw_token, $key);
    }

    /**
     * Issue a fresh single-use token and invalidate prior unused tokens.
     *
     * @param int $user_id
     * @return array{token:string,expires_at:string,ttl_minutes:int}
     */
    public function issue($user_id)
    {
        $uid = (int) $user_id;
        $now_utc = gmdate('Y-m-d H:i:s');
        $expires_at = gmdate(
            'Y-m-d H:i:s',
            time() + (self::TTL_MINUTES * 60)
        );

        $raw_token = bin2hex(random_bytes(32));
        $token_hash = $this->hash($raw_token);

        $this->db->transaction();

        try {
            // Invalidate previous unused tokens for this user.
            $this->db->table($this->table)
                ->where('user_id', $uid)
                ->where_null('used_at')
                ->update([
                    'used_at' => $now_utc,
                ]);

            $this->db->table($this->table)->insert([
                'user_id' => $uid,
                'token_hash' => $token_hash,
                'expires_at' => $expires_at,
                'used_at' => null,
                'created_at' => $now_utc,
            ]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return [
            'token' => $raw_token,
            'expires_at' => $expires_at,
            'ttl_minutes' => self::TTL_MINUTES,
        ];
    }

    /**
     * Find a token that exists, is unused, and has not expired.
     *
     * @param string $raw_token
     * @return array|null
     */
    public function find_valid($raw_token)
    {
        $raw_token = trim((string) $raw_token);

        if ($raw_token === '' || strlen($raw_token) > 256) {
            return null;
        }

        $now_utc = gmdate('Y-m-d H:i:s');

        $row = $this->db->table($this->table)
            ->select('id, user_id, expires_at, created_at')
            ->where('token_hash', $this->hash($raw_token))
            ->where_null('used_at')
            ->where('expires_at', '>', $now_utc)
            ->limit(1)
            ->row_array();

        return $row ?: null;
    }

    /**
     * Atomically validate and mark a token as used so it cannot be replayed.
     *
     * @param string $raw_token
     * @return array|null
     */
    public function consume($raw_token)
    {
        $row = $this->find_valid($raw_token);

        if (!$row) {
            return null;
        }

        $now_utc = gmdate('Y-m-d H:i:s');

        $this->db->table($this->table)
            ->where('id', (int) $row['id'])
            ->where_null('used_at')
            ->where('expires_at', '>', $now_utc)
            ->update([
                'used_at' => $now_utc,
            ]);

        // The conditional update ensures concurrent requests cannot reuse it.
        if ((int) $this->db->row_count() !== 1) {
            return null;
        }

        return $row;
    }

    /**
     * Remove expired tokens and used tokens older than 24 hours.
     *
     * @return int Number of removed rows
     */
    public function purge_stale()
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - 86400);

        $this->db->table($this->table)
            ->grouped(function ($query) use ($cutoff) {
                $query->where('expires_at', '<', $cutoff)
                    ->or_where('used_at', '<', $cutoff);
            })
            ->delete();

        return (int) $this->db->row_count();
    }
}