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

    /** Find an external identity by provider and the provider's stable subject. */
    public function find_identity($provider, $subject)
    {
        $provider = strtolower(trim((string) $provider));
        $subject  = trim((string) $subject);

        if ($provider === '' || strlen($provider) > 30 || $subject === '' || strlen($subject) > 191) {
            return null;
        }

        $identity = $this->db->table('user_identities')
            ->where('provider', $provider)
            ->where('provider_subject', $subject)
            ->get();

        return $identity ?: null;
    }

    /** Attach a provider identity once; never move it between user accounts. */
    public function attach_google_identity($user_id, $subject)
    {
        $user_id = (int) $user_id;
        $subject = trim((string) $subject);

        if ($user_id < 1 || $subject === '' || strlen($subject) > 191) {
            return false;
        }

        $by_subject = $this->find_identity('google', $subject);
        if ($by_subject) {
            return (int) $by_subject['user_id'] === $user_id;
        }

        $by_user = $this->db->table('user_identities')
            ->where('user_id', $user_id)
            ->where('provider', 'google')
            ->get();
        if ($by_user) {
            return hash_equals((string) $by_user['provider_subject'], $subject);
        }

        try {
            $inserted = $this->db->table('user_identities')->insert([
                'user_id'          => $user_id,
                'provider'         => 'google',
                'provider_subject' => $subject,
            ]);
            return (int) $inserted > 0;
        } catch (\PDOException $e) {
            // A parallel sign-in may have inserted the same identity first.
            $existing = $this->find_identity('google', $subject);
            if ($existing) {
                return (int) $existing['user_id'] === $user_id;
            }
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
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

    /** Create a passwordless runner account and bind its Google identity atomically. */
    public function create_google_account($email, $subject)
    {
        $email   = self::normalise_email($email);
        $subject = trim((string) $subject);

        if (
            self::email_problem($email) !== null
            || $subject === ''
            || strlen($subject) > 191
            || $this->find_by_email($email)
            || $this->find_identity('google', $subject)
        ) {
            return null;
        }

        $this->db->transaction();
        try {
            $inserted = $this->db->table($this->table)->insert([
                'email'         => $email,
                'password_hash' => null,
                'role'          => self::ROLE_RUNNER,
                'status'        => 'active',
            ]);
            $user_id = (int) $this->db->last_id();

            if ((int) $inserted < 1 || $user_id < 1) {
                throw new \RuntimeException('Google account creation did not insert a user.');
            }

            $identity_inserted = $this->db->table('user_identities')->insert([
                'user_id'          => $user_id,
                'provider'         => 'google',
                'provider_subject' => $subject,
            ]);

            if ((int) $identity_inserted < 1) {
                throw new \RuntimeException('Google account identity could not be stored.');
            }

            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->roll_back();
            if ($e->getCode() === '23000') {
                return null;
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }

        return $this->find($user_id) ?: [
            'id'            => $user_id,
            'email'         => $email,
            'password_hash' => null,
            'role'          => self::ROLE_RUNNER,
            'status'        => 'active',
        ];
    }

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
