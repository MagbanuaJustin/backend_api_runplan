<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: UserIdentityModel
 *
 * Federated OAuth/OIDC identity mappings (`user_identities`).
 *
 * Enforces Spec Section 5.6, NFR-01, TC-11, and TC-12:
 *   - Provider subject (`sub`) is mapped uniquely per provider.
 *   - First-time verified Google sign-in creates a local account ONLY when no
 *     existing local account already owns that email address.
 *   - Existing password accounts are NEVER merged automatically by email text;
 *     linking requires an authenticated session via link_to_user().
 *   - Raw ID tokens are never stored.
 */
class UserIdentityModel extends Model {

    protected $table       = 'user_identities';
    protected $primary_key = 'id';
    protected $timestamps  = false;

    const PROVIDER_GOOGLE = 'google';

    public function find_by_subject($provider, $subject)
    {
        $row = $this->db->raw(
            'SELECT * FROM user_identities WHERE provider = ? AND provider_subject = ? LIMIT 1',
            [(string) $provider, (string) $subject]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function for_user($user_id)
    {
        $rows = $this->db->raw(
            'SELECT provider, provider_email, email_verified, created_at, last_used_at
               FROM user_identities WHERE user_id = ? ORDER BY created_at ASC',
            [(int) $user_id]
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn($r) => [
            'provider'       => $r['provider'],
            'provider_email' => $r['provider_email'] ?? null,
            'email_verified' => (bool) ($r['email_verified'] ?? false),
            'linked_at'      => $r['created_at'],
            'last_used_at'   => $r['last_used_at'] ?? null,
        ], $rows);
    }

    /**
     * Resolve an existing mapped Google identity or create a new federated
     * RunPlan account when the email is unused.
     *
     * @param array $claims Verified claims from GoogleIdentityService (`sub`, `email`, `email_verified`)
     * @return array ['status' => 'ok'|'link_required'|'inactive', 'user' => ?array, 'created' => bool]
     */
    public function resolve_or_create_google_user(array $claims)
    {
        $sub   = (string) $claims['sub'];
        $email = strtolower(trim((string) $claims['email']));

        $existing_map = $this->find_by_subject(self::PROVIDER_GOOGLE, $sub);
        if ($existing_map) {
            $this->db->raw(
                'UPDATE user_identities SET last_used_at = CURRENT_TIMESTAMP, provider_email = ? WHERE id = ?',
                [$email, (int) $existing_map['id']]
            );
            $user = $this->db->raw(
                'SELECT * FROM users WHERE id = ? LIMIT 1',
                [(int) $existing_map['user_id']]
            )->fetch(PDO::FETCH_ASSOC);

            if (!$user || ($user['status'] ?? '') !== 'active') {
                return ['status' => 'inactive', 'user' => null, 'created' => false];
            }
            return ['status' => 'ok', 'user' => $user, 'created' => false];
        }

        // Check whether a local account already owns this email address.
        // Spec 5.6 / NFR-01: NEVER silently merge on matching email string!
        $email_owner = $this->db->raw(
            'SELECT id FROM users WHERE email = ? LIMIT 1',
            [$email]
        )->fetch(PDO::FETCH_ASSOC);

        if ($email_owner) {
            return ['status' => 'link_required', 'user' => null, 'created' => false];
        }

        $this->db->transaction();
        try {
            $this->db->table('users')->insert([
                'email'         => $email,
                'password_hash' => null,
                'role'          => 'runner',
                'status'        => 'active',
            ]);
            $user_id = (int) $this->db->last_id();

            $this->db->raw(
                'INSERT INTO user_identities
                    (user_id, provider, provider_subject, provider_email, email_verified, last_used_at)
                 VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP)',
                [$user_id, self::PROVIDER_GOOGLE, $sub, $email]
            );
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->roll_back();
            throw $e;
        }

        $user = $this->db->raw('SELECT * FROM users WHERE id = ? LIMIT 1', [$user_id])->fetch(PDO::FETCH_ASSOC);
        return ['status' => 'ok', 'user' => $user, 'created' => true];
    }

    /**
     * Deliberate verified linking for an already-authenticated RunPlan user.
     *
     * @return array ['ok' => true] or ['ok' => false, 'status' => int, 'error' => string]
     */
    public function link_google_to_user($user_id, array $claims)
    {
        $uid   = (int) $user_id;
        $sub   = (string) $claims['sub'];
        $email = strtolower(trim((string) $claims['email']));

        $by_sub = $this->find_by_subject(self::PROVIDER_GOOGLE, $sub);
        if ($by_sub) {
            if ((int) $by_sub['user_id'] === $uid) {
                $this->db->raw(
                    'UPDATE user_identities SET last_used_at = CURRENT_TIMESTAMP, provider_email = ? WHERE id = ?',
                    [$email, (int) $by_sub['id']]
                );
                return ['ok' => true];
            }
            return [
                'ok'     => false,
                'status' => 409,
                'error'  => 'That Google account is already linked to another RunPlan account.',
            ];
        }

        $by_user = $this->db->raw(
            'SELECT id FROM user_identities WHERE user_id = ? AND provider = ? LIMIT 1',
            [$uid, self::PROVIDER_GOOGLE]
        )->fetch(PDO::FETCH_ASSOC);

        if ($by_user) {
            return [
                'ok'     => false,
                'status' => 409,
                'error'  => 'Your account already has a Google identity linked. Unlink it first to switch.',
            ];
        }

        $this->db->raw(
            'INSERT INTO user_identities
                (user_id, provider, provider_subject, provider_email, email_verified, last_used_at)
             VALUES (?, ?, ?, ?, 1, CURRENT_TIMESTAMP)',
            [$uid, self::PROVIDER_GOOGLE, $sub, $email]
        );

        return ['ok' => true];
    }

    public function unlink_provider($user_id, $provider)
    {
        $stmt = $this->db->raw(
            'DELETE FROM user_identities WHERE user_id = ? AND provider = ?',
            [(int) $user_id, (string) $provider]
        );
        return $stmt ? $stmt->rowCount() > 0 : false;
    }
}
