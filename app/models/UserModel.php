<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: UserModel
 *
 * Accounts, password policy, anti-enumeration authentication, and account
 * deletion lifecycle.
 */
class UserModel extends Model {

    protected $table       = 'users';
    protected $primary_key = 'id';
    protected $timestamps  = true;

    const ROLE_RUNNER = 'runner';

    /** Password rules, single source of truth so register/reset cannot drift. */
    const PASSWORD_MIN = 8;
    const PASSWORD_MAX = 72;   // bcrypt silently truncates past this

    public static function password_problem($password)
    {
        $len = strlen((string) $password);
        if ($len < self::PASSWORD_MIN) {
            return 'Use at least ' . self::PASSWORD_MIN . ' characters.';
        }
        if ($len > self::PASSWORD_MAX) {
            return 'Use at most ' . self::PASSWORD_MAX . ' characters.';
        }
        return null;
    }

    public static function email_problem($email)
    {
        $email = (string) $email;
        if (strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Enter a valid email address.';
        }
        return null;
    }

    public static function normalise_email($email)
    {
        return strtolower(trim((string) $email));
    }

    // ------------------------------------------------------------------
    // Lookups
    // ------------------------------------------------------------------

    public function find_by_email($email)
    {
        return $this->db->table($this->table)
            ->where('email', self::normalise_email($email))
            ->get();
    }

    public function find_active($user_id)
    {
        $user = $this->db->table($this->table)
            ->where('id', (int) $user_id)
            ->where('status', 'active')
            ->get();
        return $user ?: null;
    }

    public function is_active($user)
    {
        return is_array($user) && ($user['status'] ?? '') === 'active';
    }

    public function has_password($user)
    {
        return is_array($user) && !empty($user['password_hash']);
    }

    // ------------------------------------------------------------------
    // Writes
    // ------------------------------------------------------------------

    public function create_password_account($email, $password)
    {
        $email = self::normalise_email($email);

        if ($this->find_by_email($email)) {
            return null;
        }

        $this->db->table($this->table)->insert([
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => self::ROLE_RUNNER,
            'status'        => 'active',
        ]);

        $id = (int) $this->db->last_id();
        return $this->find($id) ?: [
            'id'            => $id,
            'email'         => $email,
            'password_hash' => 'set',
            'role'          => self::ROLE_RUNNER,
            'status'        => 'active',
        ];
    }

    public function authenticate($email, $password)
    {
        $user = $this->find_by_email($email);

        if ($user && !empty($user['password_hash'])) {
            $ok = password_verify((string) $password, $user['password_hash']);
        } else {
            password_hash((string) $password, PASSWORD_DEFAULT);   // equalise cost
            $ok = false;
        }

        if (!$ok) {
            return null;
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->db->table($this->table)
                ->where('id', (int) $user['id'])
                ->update(['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
        }

        return $user;
    }

    public function set_password($user_id, $password)
    {
        if (self::password_problem($password) !== null) {
            return false;
        }
        $this->db->raw(
            'UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?',
            [password_hash($password, PASSWORD_DEFAULT), (int) $user_id]
        );
        return true;
    }

    /**
     * Permanently delete a runner's account and all cascading first-party data,
     * recording a non-PII completion entry in `account_deletion_requests`.
     */
    public function delete_account($user_id, array $provider_cleanup_summary = [])
    {
        $uid = (int) $user_id;

        $this->db->transaction();
        try {
            $this->db->raw(
                "INSERT INTO account_deletion_requests
                    (user_id, status, provider_cleanup_summary, completed_at)
                 VALUES (?, 'completed', ?, CURRENT_TIMESTAMP)",
                [$uid, json_encode($provider_cleanup_summary, JSON_UNESCAPED_SLASHES)]
            );
            $this->db->raw('DELETE FROM users WHERE id = ?', [$uid]);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }
    }

    // ------------------------------------------------------------------
    // Presentation
    // ------------------------------------------------------------------

    public function present($user)
    {
        return [
            'id'           => (int) $user['id'],
            'email'        => $user['email'],
            'role'         => $user['role'],
            'has_password' => $this->has_password($user),
        ];
    }
}
