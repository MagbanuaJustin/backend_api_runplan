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

    public function verify_password($user, $password)
    {
        if ($this->has_password($user)) {
            return password_verify((string) $password, $user['password_hash']);
        }
        password_hash((string) $password, PASSWORD_DEFAULT); // equalise cost
        return false;
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
     * Build a safe overview of the user's account and stored first-party data
     * counts for GET /api/account.
     */
    public function account_summary($user_id)
    {
        $uid  = (int) $user_id;
        $user = $this->find_active($uid);
        if (!$user) {
            return null;
        }

        $plans = (int) $this->db->raw(
            'SELECT COUNT(*) FROM training_plans WHERE user_id = ?',
            [$uid]
        )->fetchColumn();

        $sessions = (int) $this->db->raw(
            'SELECT COUNT(*) FROM training_sessions WHERE user_id = ?',
            [$uid]
        )->fetchColumn();

        $activities = (int) $this->db->raw(
            'SELECT COUNT(*) FROM activities WHERE user_id = ?',
            [$uid]
        )->fetchColumn();

        $summary = $this->present($user);
        $summary['status']     = (string) ($user['status'] ?? 'active');
        $summary['created_at'] = $user['created_at'] ?? null;
        $summary['updated_at'] = $user['updated_at'] ?? null;
        $summary['data_counts'] = [
            'plans'      => $plans,
            'sessions'   => $sessions,
            'activities' => $activities,
        ];

        return $summary;
    }

    /**
     * Permanently delete a runner's account and cascade-remove all first-party
     * training data while recording a non-PII deletion receipt.
     *
     * @param int         $user_id
     * @param string|null $reason
     * @return bool
     */
    public function delete_account_and_data($user_id, $reason = null)
    {
        $uid  = (int) $user_id;
        $user = $this->find($uid);
        if (!$user) {
            return false;
        }

        $clean_reason = null;
        if ($reason !== null && trim((string) $reason) !== '') {
            $clean_reason = mb_substr(trim((string) $reason), 0, 255);
        }

        $now_utc      = gmdate('Y-m-d H:i:s');
        $email_sha256 = hash('sha256', self::normalise_email($user['email']));

        $this->db->transaction();
        try {
            $this->db->table('account_deletion_requests')->insert([
                'user_id'      => $uid,
                'email_sha256' => $email_sha256,
                'status'       => 'completed',
                'reason'       => $clean_reason,
                'requested_at' => $now_utc,
                'completed_at' => $now_utc,
            ]);

            // Explicit token cleanup (defense-in-depth alongside FK ON DELETE CASCADE)
            $this->db->raw('DELETE FROM refresh_tokens WHERE user_id = ?', [$uid]);
            $this->db->raw('DELETE FROM password_reset_tokens WHERE user_id = ?', [$uid]);

            // Cascades to runner_profiles, training_plans, training_sessions,
            // activities, plan_revisions, etc., and sets user_id = NULL on audit tables.
            $this->db->raw('DELETE FROM users WHERE id = ?', [$uid]);

            $this->db->commit();
            return true;
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
