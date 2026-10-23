<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: RefreshTokenModel
 *
 * Read/invalidation side of the refresh-token table that the framework's Api
 * library writes to in issue_tokens().
 *
 * Both Api.php and this model read `refresh_token_table`,
 * `refresh_token_column`, and `refresh_token_key` from app/config/api.php so
 * schema configuration lives in one place.
 */
class RefreshTokenModel extends Model {

    protected $table        = 'refresh_tokens';
    protected $primary_key  = 'id';
    protected $timestamps   = false;
    protected $token_column = 'token_hash';

    public function __construct()
    {
        parent::__construct();

        $table = config_item('refresh_token_table') ?: $this->table;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $table) === 1) {
            $this->table = (string) $table;
        }

        $col = config_item('refresh_token_column') ?: $this->token_column;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $col) === 1) {
            $this->token_column = (string) $col;
        }
    }

    /**
     * HMAC a raw token exactly the way Api.php does, so a token issued by the
     * framework can be matched here. The raw token is never stored.
     *
     * @param string $token
     * @return string 64-char hex
     */
    public function hash($token)
    {
        return hash_hmac('sha256', (string) $token, (string) config_item('refresh_token_key'));
    }

    /**
     * Is this raw refresh token present, unexpired and live?
     *
     * @param string $token
     * @return array|null
     */
    public function find_valid($token)
    {
        if ((string) $token === '') {
            return null;
        }
        $row = $this->db->raw(
            "SELECT id, user_id, expires_at, jti, revoked_at FROM {$this->table}
              WHERE {$this->token_column} = ? AND revoked_at IS NULL AND expires_at > CURRENT_TIMESTAMP LIMIT 1",
            array($this->hash($token))
        )->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Rotate-on-use: the presented token dies whether or not the caller then
     * succeeds in getting a new pair.
     *
     * @param string $token
     * @return void
     */
    public function revoke($token)
    {
        if ((string) $token === '') {
            return;
        }
        $this->db->raw(
            "DELETE FROM {$this->table} WHERE {$this->token_column} = ?",
            array($this->hash($token))
        );
    }

    /**
     * Drop every stored token for a user - used by password reset and by
     * account deletion so no prior session survives.
     *
     * @param int $user_id
     * @return int rows removed
     */
    public function revoke_all_for_user($user_id)
    {
        $stmt = $this->db->raw(
            "DELETE FROM {$this->table} WHERE user_id = ?",
            array((int) $user_id)
        );
        return $stmt ? (int) $stmt->rowCount() : 0;
    }

    /**
     * Housekeeping for expired tokens.
     *
     * @return int rows removed
     */
    public function purge_expired()
    {
        $stmt = $this->db->raw("DELETE FROM {$this->table} WHERE expires_at < CURRENT_TIMESTAMP");
        return $stmt ? (int) $stmt->rowCount() : 0;
    }
}
