<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: NotificationModel
 * 
 * Automatically generated via CLI.
 */
class NotificationModel extends Model
{
    protected $table = 'notification_preferences';
    protected $primary_key = 'id';

    public function preferences_for_user($user_id, $timezone = 'Asia/Manila')
    {
        $uid = (int) $user_id;

        $row = $this->db->table('notification_preferences')
            ->select('enabled, lead_minutes, timezone')
            ->where('user_id', '=', $uid)
            ->row_array();

        if (!$row) {
            $row = array(
                'enabled' => 0,
                'lead_minutes' => 60,
                'timezone' => $timezone,
            );
        }

        $subscribed = $this->db->table('push_subscriptions')
            ->where('user_id', '=', $uid)
            ->where_null('revoked_at')
            ->count() > 0;

        $ready = function_exists('integration_ready')
            && integration_ready('push');

        return array(
            'enabled' => (bool) $row['enabled'],
            'lead_minutes' => (int) $row['lead_minutes'],
            'timezone' => (string) $row['timezone'],
            'subscribed' => $subscribed,
            'push_ready' => $ready,
            'public_key' => $ready
                ? (string) integration_value('push', 'public_key', '')
                : null,
        );
    }

    public function save_preferences($user_id, $enabled, $lead_minutes, $timezone)
    {
        $uid = (int) $user_id;
        $data = array(
            'user_id' => $uid,
            'enabled' => $enabled ? 1 : 0,
            'lead_minutes' => (int) $lead_minutes,
            'timezone' => (string) $timezone,
        );

        $existing = $this->db->table('notification_preferences')
            ->select('id')
            ->where('user_id', '=', $uid)
            ->row_array();

        if ($existing) {
            $this->db->table('notification_preferences')
                ->where('id', '=', (int) $existing['id'])
                ->update($data);

            return;
        }

        try {
            $inserted = $this->db->table('notification_preferences')
                ->insert($data);

            if ($inserted === false) {
                throw new RuntimeException('Could not save notification preferences.');
            }
        } catch (Throwable $error) {
            // Handle a concurrent request creating the same user's preferences.
            $existing = $this->db->table('notification_preferences')
                ->select('id')
                ->where('user_id', '=', $uid)
                ->row_array();

            if (!$existing) {
                throw $error;
            }

            $this->db->table('notification_preferences')
                ->where('id', '=', (int) $existing['id'])
                ->update($data);
        }
    }

    public function save_subscription($user_id, $endpoint, $endpoint_hash, array $keys)
    {
        $uid = (int) $user_id;
        $material = $this->seal_subscription_keys($uid, $keys);

        if ($material === null) {
            throw new RuntimeException('Push subscription encryption is unavailable.');
        }

        $hash = $this->binary_endpoint_hash($endpoint_hash);
        $now = gmdate('Y-m-d H:i:s');

        $data = array(
            'user_id' => $uid,
            'endpoint' => (string) $endpoint,
            'endpoint_hash' => $hash,
            'key_material' => $material,
            'created_at' => $now,
            'revoked_at' => null,
        );

        $existing = $this->db->table('push_subscriptions')
            ->select('id')
            ->where('endpoint_hash', '=', $hash)
            ->row_array();

        if ($existing) {
            $this->db->table('push_subscriptions')
                ->where('id', '=', (int) $existing['id'])
                ->update($data);

            return;
        }

        try {
            $inserted = $this->db->table('push_subscriptions')->insert($data);

            if ($inserted === false) {
                throw new RuntimeException('Could not save the push subscription.');
            }
        } catch (Throwable $error) {
            // Handle another request registering the same endpoint at the same time.
            $existing = $this->db->table('push_subscriptions')
                ->select('id')
                ->where('endpoint_hash', '=', $hash)
                ->row_array();

            if (!$existing) {
                throw $error;
            }

            $this->db->table('push_subscriptions')
                ->where('id', '=', (int) $existing['id'])
                ->update($data);
        }
    }

    public function remove_subscription($user_id, $endpoint)
    {
        $this->db->table('push_subscriptions')
            ->where('user_id', '=', (int) $user_id)
            ->where('endpoint', '=', (string) $endpoint)
            ->delete();
    }

    public function dispatch_due($limit = 100)
    {
        $cancelled = $this->reconcile_jobs();
        $sender = new WebPushService();

        if (!$sender->is_ready()) {
            return array(
                'sent' => 0,
                'failed' => 0,
                'cancelled' => $cancelled,
                'ready' => false,
            );
        }

        $limit = max(1, min(250, (int) $limit));
        $nowUtc = gmdate('Y-m-d H:i:s');
        $jobs = array();

        /*
         * Reserve jobs with a conditional update. The status check prevents two
         * workers from both claiming and sending the same pending notification.
         */
        $this->db->transaction();

        try {
            $candidates = $this->db->table('notification_jobs')
                ->inner_join(
                    'training_sessions',
                    'training_sessions.id = notification_jobs.session_id'
                    . ' AND training_sessions.user_id = notification_jobs.user_id'
                )
                ->inner_join(
                    'training_plans',
                    'training_plans.id = training_sessions.plan_id'
                    . ' AND training_plans.user_id = training_sessions.user_id'
                )
                ->inner_join(
                    'notification_preferences',
                    'notification_preferences.user_id = notification_jobs.user_id'
                )
                ->select(
                    'notification_jobs.id AS job_id, '
                    . 'notification_jobs.user_id, '
                    . 'notification_jobs.session_id, '
                    . 'notification_jobs.attempts, '
                    . 'training_sessions.scheduled_date, '
                    . 'training_sessions.planned_start_time, '
                    . 'training_sessions.type, '
                    . 'training_sessions.planned_distance_km, '
                    . 'notification_preferences.lead_minutes, '
                    . 'notification_preferences.timezone'
                )
                ->where('notification_jobs.status', '=', 'pending')
                ->where('notification_jobs.scheduled_at', '<=', $nowUtc)
                ->where('training_sessions.status', '=', 'planned')
                ->where_not_null('training_sessions.planned_start_time')
                ->where('training_plans.status', '=', 'active')
                ->where('notification_preferences.enabled', '=', 1)
                ->order_by('notification_jobs.scheduled_at', 'ASC')
                ->order_by('notification_jobs.id', 'ASC')
                ->limit($limit)
                ->result_array();

            foreach ($candidates ?: array() as $job) {
                $this->db->table('notification_jobs')
                    ->where('id', '=', (int) $job['job_id'])
                    ->where('status', '=', 'pending')
                    ->update(array(
                        'status' => 'processing',
                        'locked_at' => $nowUtc,
                    ));

                if ($this->db->row_count() > 0) {
                    $jobs[] = $job;
                }
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        $sent = 0;
        $failed = 0;

        foreach ($jobs as $job) {
            $subs = $this->db->table('push_subscriptions')
                ->select('id, endpoint, key_material')
                ->where('user_id', '=', (int) $job['user_id'])
                ->where_null('revoked_at')
                ->result_array();

            try {
                $zone = new DateTimeZone($this->valid_timezone($job['timezone']));
                $start = new DateTimeImmutable(
                    $job['scheduled_date'] . ' ' . $job['planned_start_time'],
                    $zone
                );
            } catch (Throwable $error) {
                $this->update_job(
                    (int) $job['job_id'],
                    array('status' => 'cancelled', 'locked_at' => null),
                    'processing'
                );
                $cancelled++;
                continue;
            }

            $ttl = max(0, $start->getTimestamp() - time());

            if ($ttl === 0) {
                $this->update_job(
                    (int) $job['job_id'],
                    array('status' => 'cancelled', 'locked_at' => null),
                    'processing'
                );
                $cancelled++;
                continue;
            }

            $distance = $job['planned_distance_km'] !== null
                ? ' (' . rtrim(
                    rtrim(number_format((float) $job['planned_distance_km'], 1), '0'),
                    '.'
                ) . ' km)'
                : '';

            $payload = array(
                'title' => 'Your run is coming up',
                'body' => ucfirst((string) $job['type'])
                    . ' run starts at '
                    . $start->format('g:i A')
                    . $distance
                    . '.',
                'url' => '/calendar',
                'tag' => 'runplan-session-' . (int) $job['session_id'],
            );

            $delivered = false;

            foreach ($subs ?: array() as $sub) {
                $keys = $this->open_subscription_keys(
                    (int) $job['user_id'],
                    (string) $sub['key_material']
                );

                if (!is_array($keys)) {
                    $this->remove_subscription($job['user_id'], $sub['endpoint']);
                    continue;
                }

                $result = $sender->send($sub['endpoint'], $keys, $payload, $ttl);

                if (!empty($result['expired'])) {
                    $this->remove_subscription($job['user_id'], $sub['endpoint']);
                }

                if (!empty($result['ok'])) {
                    $delivered = true;
                }
            }

            if ($delivered) {
                $this->update_job(
                    (int) $job['job_id'],
                    array(
                        'status' => 'sent',
                        'attempts' => (int) $job['attempts'] + 1,
                        'sent_at' => gmdate('Y-m-d H:i:s'),
                        'locked_at' => null,
                    ),
                    'processing'
                );

                $sent++;
                continue;
            }

            $attempts = (int) $job['attempts'] + 1;
            $retryAt = time() + min(5, 2 ** min(3, $attempts)) * 60;

            if ($attempts < 4 && $retryAt < $start->getTimestamp()) {
                $this->update_job(
                    (int) $job['job_id'],
                    array(
                        'status' => 'pending',
                        'attempts' => $attempts,
                        'locked_at' => null,
                        'scheduled_at' => gmdate('Y-m-d H:i:s', $retryAt),
                    ),
                    'processing'
                );
            } else {
                $this->update_job(
                    (int) $job['job_id'],
                    array(
                        'status' => 'failed',
                        'attempts' => $attempts,
                        'locked_at' => null,
                    ),
                    'processing'
                );

                $failed++;
            }
        }

        return array(
            'sent' => $sent,
            'failed' => $failed,
            'cancelled' => $cancelled,
            'ready' => true,
        );
    }

    private function reconcile_jobs()
    {
        $invalidJobs = $this->db->table('notification_jobs')
            ->left_join(
                'training_sessions',
                'training_sessions.id = notification_jobs.session_id'
                . ' AND training_sessions.user_id = notification_jobs.user_id'
            )
            ->left_join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
                . ' AND training_plans.user_id = training_sessions.user_id'
                . " AND training_plans.status = 'active'"
            )
            ->left_join(
                'notification_preferences',
                'notification_preferences.user_id = notification_jobs.user_id'
                . ' AND notification_preferences.enabled = 1'
            )
            ->select('notification_jobs.id AS job_id')
            ->where('notification_jobs.status', '=', 'pending')
            ->grouped(function ($query) {
                $query->where_null('training_sessions.id')
                    ->or_where('training_sessions.status', '<>', 'planned')
                    ->or_where_null('training_sessions.planned_start_time')
                    ->or_where_null('training_plans.id')
                    ->or_where_null('notification_preferences.user_id');
            })
            ->result_array();

        $cancelled = 0;
        $invalidIds = array();

        foreach ($invalidJobs ?: array() as $row) {
            $invalidIds[] = (int) $row['job_id'];
        }

        if ($invalidIds) {
            $this->db->table('notification_jobs')
                ->in('id', $invalidIds)
                ->where('status', '=', 'pending')
                ->update(array('status' => 'cancelled'));

            $cancelled += (int) $this->db->row_count();
        }

        $staleBefore = gmdate('Y-m-d H:i:s', time() - 600);

        $this->db->table('notification_jobs')
            ->where('status', '=', 'processing')
            ->where('locked_at', '<', $staleBefore)
            ->update(array(
                'status' => 'pending',
                'locked_at' => null,
            ));

        $rows = $this->db->table('notification_preferences')
            ->inner_join(
                'training_plans',
                'training_plans.user_id = notification_preferences.user_id'
            )
            ->inner_join(
                'training_sessions',
                'training_sessions.plan_id = training_plans.id'
                . ' AND training_sessions.user_id = notification_preferences.user_id'
            )
            ->select(
                'notification_preferences.user_id, '
                . 'notification_preferences.lead_minutes, '
                . 'notification_preferences.timezone, '
                . 'training_sessions.id AS session_id, '
                . 'training_sessions.scheduled_date, '
                . 'training_sessions.planned_start_time'
            )
            ->where('notification_preferences.enabled', '=', 1)
            ->where('training_plans.status', '=', 'active')
            ->where('training_sessions.status', '=', 'planned')
            ->where_not_null('training_sessions.planned_start_time')
            ->result_array();

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        foreach ($rows ?: array() as $row) {
            try {
                $zone = new DateTimeZone($this->valid_timezone($row['timezone']));
                $runAt = new DateTimeImmutable(
                    $row['scheduled_date'] . ' ' . $row['planned_start_time'],
                    $zone
                );
            } catch (Throwable $error) {
                continue;
            }

            $scheduled = $runAt
                ->modify('-' . (int) $row['lead_minutes'] . ' minutes')
                ->setTimezone(new DateTimeZone('UTC'));

            $existing = $this->find_job_for_session((int) $row['session_id']);

            if ($existing && $existing['status'] === 'sent') {
                continue;
            }

            if ($scheduled <= $now) {
                if (
                    $existing
                    && $existing['status'] === 'pending'
                    && $runAt <= $now
                ) {
                    $this->update_job(
                        (int) $existing['id'],
                        array('status' => 'cancelled'),
                        'pending'
                    );

                    $cancelled += (int) $this->db->row_count();
                }

                continue;
            }

            $this->persist_job(
                (int) $row['user_id'],
                (int) $row['session_id'],
                $scheduled->format('Y-m-d H:i:s'),
                $existing
            );
        }

        return $cancelled;
    }

    private function persist_job($user_id, $session_id, $scheduled_at, $existing)
    {
        $data = array(
            'user_id' => (int) $user_id,
            'session_id' => (int) $session_id,
            'scheduled_at' => (string) $scheduled_at,
            'status' => 'pending',
            'attempts' => 0,
            'sent_at' => null,
            'locked_at' => null,
        );

        if ($existing) {
            if (!in_array($existing['status'], array('pending', 'cancelled'), true)) {
                return;
            }

            $this->db->table('notification_jobs')
                ->where('id', '=', (int) $existing['id'])
                ->where('status', '=', $existing['status'])
                ->update($data);

            return;
        }

        try {
            $inserted = $this->db->table('notification_jobs')->insert($data);

            if ($inserted === false) {
                throw new RuntimeException('Could not create the notification job.');
            }
        } catch (Throwable $error) {
            // Handle another worker creating a job for this session concurrently.
            $existing = $this->find_job_for_session($session_id);

            if (!$existing) {
                throw $error;
            }

            if (in_array($existing['status'], array('pending', 'cancelled'), true)) {
                $this->db->table('notification_jobs')
                    ->where('id', '=', (int) $existing['id'])
                    ->where('status', '=', $existing['status'])
                    ->update($data);
            }
        }
    }

    private function find_job_for_session($session_id)
    {
        return $this->db->table('notification_jobs')
            ->select('id, status, scheduled_at')
            ->where('session_id', '=', (int) $session_id)
            ->row_array();
    }

    private function update_job($job_id, array $data, $expected_status)
    {
        return $this->db->table('notification_jobs')
            ->where('id', '=', (int) $job_id)
            ->where('status', '=', (string) $expected_status)
            ->update($data);
    }

    private function binary_endpoint_hash($endpoint_hash)
    {
        $hash = (string) $endpoint_hash;

        if (!preg_match('/\A[a-f0-9]{64}\z/i', $hash)) {
            throw new InvalidArgumentException('The push endpoint hash is invalid.');
        }

        $binary = hex2bin($hash);

        if ($binary === false) {
            throw new InvalidArgumentException('The push endpoint hash is invalid.');
        }

        return $binary;
    }

    private function valid_timezone($timezone)
    {
        try {
            new DateTimeZone((string) $timezone);

            return (string) $timezone;
        } catch (Throwable $error) {
            return 'Asia/Manila';
        }
    }

    private function seal_subscription_keys($user_id, array $keys)
    {
        $secret = (string) integration_value('push', 'worker_token', '');

        if (strlen($secret) < 32 || !function_exists('openssl_encrypt')) {
            return null;
        }

        $key = hash_hmac(
            'sha256',
            'runplan.push_subscriptions.encryption.v1',
            $secret,
            true
        );
        $iv = random_bytes(12);
        $tag = '';
        $plain = json_encode($keys, JSON_UNESCAPED_SLASHES);

        if ($plain === false) {
            return null;
        }

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

        if ($cipher === false || strlen($tag) !== 16) {
            return null;
        }

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

        if (strlen($secret) < 32 || !function_exists('openssl_decrypt')) {
            return null;
        }

        $decoded = base64_decode((string) $blob, true);

        if ($decoded === false) {
            return null;
        }

        $envelope = json_decode($decoded, true);

        if (!is_array($envelope) || (int) ($envelope['v'] ?? 0) !== 1) {
            return null;
        }

        $iv = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $cipher = base64_decode((string) ($envelope['ct'] ?? ''), true);

        if (
            $iv === false
            || $tag === false
            || $cipher === false
            || strlen($iv) !== 12
            || strlen($tag) !== 16
        ) {
            return null;
        }

        $key = hash_hmac(
            'sha256',
            'runplan.push_subscriptions.encryption.v1',
            $secret,
            true
        );

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            'runplan.push_subscriptions.user.' . (int) $user_id
        );

        if ($plain === false) {
            return null;
        }

        $keys = json_decode($plain, true);

        return is_array($keys) ? $keys : null;
    }
}