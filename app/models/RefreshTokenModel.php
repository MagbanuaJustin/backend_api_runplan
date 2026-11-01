<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: RefreshTokenModel
 * 
 * Automatically generated via CLI.
 */
class RefreshTokenModel extends Model
{
    protected $table = 'refresh_tokens';
    protected $primary_key = 'id';
    protected $timestamps = false;
    protected $token_column = 'token_hash';

    public function __construct()
    {
        parent::__construct();

        $table = config_item('refresh_token_table') ?: $this->table;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $table) === 1) {
            $this->table = (string) $table;
        }

        $column = config_item('refresh_token_column') ?: $this->token_column;
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $column) === 1) {
            $this->token_column = (string) $column;
        }
    }

    /**
     * HMAC a raw token exactly the way Api.php does, so a token issued by the
     * framework can be matched here.
     *
     * @param string $token
     * @return string 64-character hexadecimal hash
     */
    public function hash($token)
    {
        return hash_hmac(
            'sha256',
            (string) $token,
            (string) config_item('refresh_token_key')
        );
    }

    /**
     * Find a token that is unrevoked and unexpired.
     *
     * The expiry comparison uses UTC, matching tokens created with UTC timestamps.
     *
     * @param string $token
     * @return array|null
     */
    public function find_valid($token)
    {
        $token = (string) $token;

        if ($token === '' || strlen($token) > 256) {
            return null;
        }

        $row = $this->db
            ->table($this->table)
            ->select('id, user_id, expires_at, jti, revoked_at')
            ->where($this->token_column, $this->hash($token))
            ->where_null('revoked_at')
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))
            ->limit(1)
            ->get();

        return $row ?: null;
    }

    /**
     * Revoke the presented token.
     *
     * @param string $token
     * @return void
     */
    public function revoke($token)
    {
        $token = (string) $token;

        if ($token === '' || strlen($token) > 256) {
            return;
        }

        $this->db
            ->table($this->table)
            ->where($this->token_column, $this->hash($token))
            ->delete();
    }

    /**
     * Remove every stored token for a user.
     *
     * @param int $user_id
     * @return int rows removed
     */
    public function revoke_all_for_user($user_id)
    {
        $deleted = $this->db
            ->table($this->table)
            ->where('user_id', (int) $user_id)
            ->delete();

        return (int) ($deleted ?: 0);
    }

    /**
     * Remove expired tokens.
     *
     * @return int rows removed
     */
    public function purge_expired()
    {
        $deleted = $this->db
            ->table($this->table)
            ->where('expires_at', '<', gmdate('Y-m-d H:i:s'))
            ->delete();

        return (int) ($deleted ?: 0);
    }
}