<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once dirname(__DIR__) . '/libraries/WebPushService.php';

class NotificationModel extends Model
{
    protected $table = 'notification_preferences';
    protected $primary_key = 'id';

    public function preferences_for_user($user_id, $timezone = 'Asia/Manila')
    {
        $uid = (int) $user_id;
        $row = $this->db->raw(
            'SELECT enabled, lead_minutes, timezone FROM notification_preferences WHERE user_id = ? LIMIT 1',
            array($uid)
        )->fetch(PDO::FETCH_ASSOC);

        if (!$row) $row = array('enabled' => 0, 'lead_minutes' => 60, 'timezone' => $timezone);

        $subscribed = (int) $this->db->raw(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND revoked_at IS NULL',
            array($uid)
        )->fetchColumn() > 0;

        $ready = function_exists('integration_ready') && integration_ready('push');

        return array(
            'enabled' => (bool) $row['enabled'],
            'lead_minutes' => (int) $row['lead_minutes'],
            'timezone' => (string) $row['timezone'],
            'subscribed' => $subscribed,
            'push_ready' => $ready,
            'public_key' => $ready ? (string) integration_value('push', 'public_key', '') : null,
        );
    }

    public function save_preferences($user_id, $enabled, $lead_minutes, $timezone)
    {
        $this->db->raw(
            'INSERT INTO notification_preferences (user_id, enabled, lead_minutes, timezone)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), lead_minutes = VALUES(lead_minutes), timezone = VALUES(timezone)',
            array((int) $user_id, $enabled ? 1 : 0, (int) $lead_minutes, $timezone)
        );
    }

    public function save_subscription($user_id, $endpoint, $endpoint_hash, array $keys)
    {
        $material = $this->seal_subscription_keys((int) $user_id, $keys);
        if ($material === null) throw new RuntimeException('Push subscription encryption is unavailable.');

        $this->db->raw(
            'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, key_material, created_at, revoked_at)
             VALUES (?, ?, UNHEX(?), ?, UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), endpoint = VALUES(endpoint),
                 key_material = VALUES(key_material), created_at = UTC_TIMESTAMP(), revoked_at = NULL',
            array((int) $user_id, $endpoint, $endpoint_hash, $material)
        );
    }

    public function remove_subscription($user_id, $endpoint)
    {
        $this->db->raw(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?',
            array((int) $user_id, (string) $endpoint)
        );
    }

    public function dispatch_due($limit = 100)
    {
        $this->reconcile_jobs();
        $sender = new WebPushService();

        if (!$sender->is_ready()) {
            return array('sent' => 0, 'failed' => 0, 'cancelled' => 0, 'ready' => false);
        }

        $limit = max(1, min(250, (int) $limit));
        $this->db->transaction();

        $jobs = $this->db->raw(
            "SELECT j.id AS job_id, j.user_id, j.session_id, j.attempts,
                    s.scheduled_date, s.planned_start_time, s.type, s.planned_distance_km,
                    np.lead_minutes, np.timezone
               FROM notification_jobs j
               JOIN training_sessions s ON s.id = j.session_id AND s.user_id = j.user_id
               JOIN training_plans p ON p.id = s.plan_id AND p.user_id = s.user_id AND p.status = 'active'
               JOIN notification_preferences np ON np.user_id = j.user_id AND np.enabled = 1
              WHERE j.status = 'pending' AND j.scheduled_at <= UTC_TIMESTAMP()
                AND s.status = 'planned' AND s.planned_start_time IS NOT NULL
              ORDER BY j.scheduled_at, j.id LIMIT " . $limit . ' FOR UPDATE'
        )->fetchAll(PDO::FETCH_ASSOC) ?: array();

        foreach ($jobs as $job) {
            $this->db->raw(
                "UPDATE notification_jobs SET status = 'processing', locked_at = UTC_TIMESTAMP()
                 WHERE id = ? AND status = 'pending'",
                array((int) $job['job_id'])
            );
        }

        $this->db->commit();
        $sent = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $subs = $this->db->raw(
                'SELECT id, endpoint, key_material FROM push_subscriptions WHERE user_id = ? AND revoked_at IS NULL',
                array((int) $job['user_id'])
            )->fetchAll(PDO::FETCH_ASSOC) ?: array();

            $start = new DateTimeImmutable(
                $job['scheduled_date'] . ' ' . $job['planned_start_time'],
                new DateTimeZone($this->valid_timezone($job['timezone']))
            );

            $ttl = max(0, $start->getTimestamp() - time());
            if ($ttl === 0) {
                $this->db->raw(
                    "UPDATE notification_jobs SET status = 'cancelled', locked_at = NULL WHERE id = ? AND status = 'processing'",
                    array((int) $job['job_id'])
                );
                continue;
            }

            $distance = $job['planned_distance_km'] !== null
                ? ' (' . rtrim(rtrim(number_format((float) $job['planned_distance_km'], 1), '0'), '.') . ' km)'
                : '';

            $payload = array(
                'title' => 'Your run is coming up',
                'body' => ucfirst((string) $job['type']) . ' run starts at ' . $start->format('g:i A') . $distance . '.',
                'url' => '/calendar',
                'tag' => 'runplan-session-' . (int) $job['session_id'],
            );

            $delivered = false;

            foreach ($subs as $sub) {
                $keys = $this->open_subscription_keys((int) $job['user_id'], (string) $sub['key_material']);

                if (!is_array($keys)) {
                    $this->remove_subscription($job['user_id'], $sub['endpoint']);
                    continue;
                }

                $result = $sender->send($sub['endpoint'], $keys, $payload, $ttl);

                if ($result['expired']) {
                    $this->remove_subscription($job['user_id'], $sub['endpoint']);
                }
                if ($result['ok']) $delivered = true;
            }

            if ($delivered) {
                $this->db->raw(
                    "UPDATE notification_jobs SET status = 'sent', attempts = attempts + 1,
                     sent_at = UTC_TIMESTAMP(), locked_at = NULL
                     WHERE id = ? AND status = 'processing'",
                    array((int) $job['job_id'])
                );
                $sent++;
            } else {
                $attempts = (int) $job['attempts'] + 1;
                $retry_at = time() + min(5, 2 ** min(3, $attempts)) * 60;

                if ($attempts < 4 && $retry_at < $start->getTimestamp()) {
                    $this->db->raw(
                        "UPDATE notification_jobs SET status = 'pending', attempts = ?,
                         locked_at = NULL, scheduled_at = ?
                         WHERE id = ? AND status = 'processing'",
                        array($attempts, gmdate('Y-m-d H:i:s', $retry_at), (int) $job['job_id'])
                    );
                } else {
                    $this->db->raw(
                        "UPDATE notification_jobs SET status = 'failed', attempts = ?, locked_at = NULL
                         WHERE id = ? AND status = 'processing'",
                        array($attempts, (int) $job['job_id'])
                    );
                    $failed++;
                }
            }
        }

        return array('sent' => $sent, 'failed' => $failed, 'cancelled' => 0, 'ready' => true);
    }

    private function reconcile_jobs()
    {
        $cancelled = $this->db->raw(
            "UPDATE notification_jobs j
              LEFT JOIN training_sessions s ON s.id = j.session_id AND s.user_id = j.user_id
              LEFT JOIN training_plans p ON p.id = s.plan_id AND p.user_id = s.user_id AND p.status = 'active'
              LEFT JOIN notification_preferences np ON np.user_id = j.user_id AND np.enabled = 1
                 SET j.status = 'cancelled'
               WHERE j.status = 'pending'
                 AND (s.id IS NULL OR s.status <> 'planned' OR s.planned_start_time IS NULL
                      OR p.id IS NULL OR np.user_id IS NULL)"
        );

        $this->db->raw(
            "UPDATE notification_jobs SET status = 'pending', locked_at = NULL
             WHERE status = 'processing' AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)"
        );

        $rows = $this->db->raw(
            "SELECT np.user_id, np.lead_minutes, np.timezone, s.id AS session_id,
                    s.scheduled_date, s.planned_start_time
               FROM notification_preferences np
               JOIN training_plans p ON p.user_id = np.user_id AND p.status = 'active'
               JOIN training_sessions s ON s.plan_id = p.id AND s.user_id = np.user_id
              WHERE np.enabled = 1 AND s.status = 'planned' AND s.planned_start_time IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC) ?: array();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($rows as $row) {
            try {
                $zone = new DateTimeZone($this->valid_timezone($row['timezone']));
                $run_at = new DateTimeImmutable($row['scheduled_date'] . ' ' . $row['planned_start_time'], $zone);
            } catch (Throwable $e) {
                continue;
            }

            $scheduled = $run_at->modify('-' . (int) $row['lead_minutes'] . ' minutes')
                ->setTimezone(new DateTimeZone('UTC'));

            $existing = $this->db->raw(
                'SELECT id, status, scheduled_at FROM notification_jobs WHERE session_id = ? LIMIT 1',
                array((int) $row['session_id'])
            )->fetch(PDO::FETCH_ASSOC);

            if ($existing && $existing['status'] === 'sent') continue;

            if ($scheduled <= $now) {
                if ($existing && $existing['status'] === 'pending' && $run_at <= $now) {
                    $this->db->raw(
                        "UPDATE notification_jobs SET status = 'cancelled' WHERE id = ? AND status = 'pending'",
                        array((int) $existing['id'])
                    );
                }
                continue;
            }

            $utc_value = $scheduled->format('Y-m-d H:i:s');

            if (!$existing) {
                $this->db->raw(
                    "INSERT INTO notification_jobs (user_id, session_id, scheduled_at, status, attempts)
                     VALUES (?, ?, ?, 'pending', 0)",
                    array((int) $row['user_id'], (int) $row['session_id'], $utc_value)
                );
            } elseif (in_array($existing['status'], array('pending', 'cancelled'), true)) {
                $this->db->raw(
                    "UPDATE notification_jobs SET user_id = ?, scheduled_at = ?, status = 'pending',
                     attempts = 0, sent_at = NULL WHERE id = ?",
                    array((int) $row['user_id'], $utc_value, (int) $existing['id'])
                );
            }
        }

        return $cancelled ? (int) $cancelled->rowCount() : 0;
    }

    private function valid_timezone($timezone)
    {
        try {
            new DateTimeZone((string) $timezone);
            return (string) $timezone;
        } catch (Throwable $e) {
            return 'Asia/Manila';
        }
    }

    private function seal_subscription_keys($user_id, array $keys)
    {
        $secret = (string) integration_value('push', 'worker_token', '');
        if (strlen($secret) < 32 || !function_exists('openssl_encrypt')) return null;

        $key = hash_hmac('sha256', 'runplan.push_subscriptions.encryption.v1', $secret, true);
        $iv = random_bytes(12);
        $tag = '';
        $plain = json_encode($keys, JSON_UNESCAPED_SLASHES);

        if ($plain === false) return null;

        $cipher = openssl_encrypt(
            $plain,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'runplan.push_subscriptions.user.' . (int) $user_id,
            16
        );

        if ($cipher === false || strlen($tag) !== 16) return null;

        return base64_encode(json_encode(array(
            'v' => 1,
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'ct' => base64_encode($cipher),
        )));
    }

    private function open_subscription_keys($user_id, $blob)
    {
        $secret = (string) integration_value('push', 'worker_token', '');
        if (strlen($secret) < 32 || !function_exists('openssl_decrypt')) return null;

        $envelope = json_decode((string) base64_decode($blob, true), true);
        if (!is_array($envelope) || (int) ($envelope['v'] ?? 0) !== 1) return null;

        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $cipher = base64_decode((string) ($envelope['ct'] ?? ''), true);

        if ($iv === false || $tag === false || $cipher === false || strlen($iv) !== 12 || strlen($tag) !== 16) {
            return null;
        }

        $key = hash_hmac('sha256', 'runplan.push_subscriptions.encryption.v1', $secret, true);
        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'runplan.push_subscriptions.user.' . (int) $user_id
        );

        if ($plain === false) return null;
        $keys = json_decode($plain, true);

        return is_array($keys) ? $keys : null;
    }
}