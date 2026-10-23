<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ActivityModel
 *
 * Manual first-party running activities.
 *
 * Spec 5.5 / TC-10 / TC-15 data boundary: only first-party RunPlan records
 * (`source = 'manual'`) are eligible for analytics, plan adaptation, or
 * Anthropic AI prompts. Even while Strava remains disabled pending policy
 * review, every read path that feeds metrics or AI enforces ELIGIBLE_SOURCE.
 */
class ActivityModel extends Model {
    protected $table = 'activities';
    protected $primary_key = 'id';

    const ELIGIBLE_SOURCE = 'manual';

    protected $fillable = [
        'session_id', 'activity_date', 'distance_km',
        'duration_seconds', 'effort', 'notes',
    ];

    /**
     * Explicit gate used by analytics and AI summary builders (TC-10, TC-15).
     */
    public static function is_eligible_for_analytics(array $row)
    {
        return ($row['source'] ?? '') === self::ELIGIBLE_SOURCE;
    }

    public function for_user($user_id, array $filters = [])
    {
        $sql  = 'SELECT * FROM activities WHERE user_id = ? AND source = ?';
        $args = [(int) $user_id, self::ELIGIBLE_SOURCE];

        if (!empty($filters['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])) {
            $sql   .= ' AND activity_date >= ?';
            $args[] = (string) $filters['from'];
        }
        if (!empty($filters['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])) {
            $sql   .= ' AND activity_date <= ?';
            $args[] = (string) $filters['to'];
        }

        $sql .= ' ORDER BY activity_date DESC, id DESC';
        $rows = $this->db->raw($sql, $args)->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'with_pace'], $rows);
    }

    public function with_pace(array $row)
    {
        $km  = (float) $row['distance_km'];
        $sec = (int) $row['duration_seconds'];

        $row['id']               = (int) $row['id'];
        $row['distance_km']      = $km;
        $row['duration_seconds'] = $sec;
        $row['session_id']       = $row['session_id'] !== null ? (int) $row['session_id'] : null;
        $row['effort']           = $row['effort'] !== null ? (int) $row['effort'] : null;

        if ($km > 0 && $sec > 0) {
            $pace = (int) round($sec / $km);
            $row['pace_seconds_per_km'] = $pace;
            $row['pace_label'] = sprintf('%d:%02d', intdiv($pace, 60), $pace % 60);
        } else {
            $row['pace_seconds_per_km'] = null;
            $row['pace_label'] = null;
        }
        return $row;
    }

    // Returns [clean_data, errors]. Reused by store (POST) and update (PUT).
    public function validate(array $in)
    {
        $clean  = [];
        $errors = [];

        $date = (string) ($in['activity_date'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            $errors['activity_date'] = 'Enter a valid date.';
        } else {
            $max = (new DateTime('now', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
            if ($date > $max) {
                $errors['activity_date'] = 'The date cannot be in the future.';
            } elseif ($date < '2000-01-01') {
                $errors['activity_date'] = 'That date is too far in the past.';
            } else {
                $clean['activity_date'] = $date;
            }
        }

        $km = $in['distance_km'] ?? null;
        if (!is_numeric($km) || (float) $km < 0.01 || (float) $km > 300) {
            $errors['distance_km'] = 'Distance must be between 0.01 and 300 km.';
        } else {
            $clean['distance_km'] = round((float) $km, 2);
        }

        $sec = $in['duration_seconds'] ?? null;
        if (!is_numeric($sec) || (int) $sec != $sec || (int) $sec < 1 || (int) $sec > 172800) {
            $errors['duration_seconds'] = 'Enter a duration between 1 second and 48 hours.';
        } else {
            $clean['duration_seconds'] = (int) $sec;
        }

        // Plausibility: only when both numbers are valid
        if (isset($clean['distance_km'], $clean['duration_seconds'])) {
            $pace = $clean['duration_seconds'] / $clean['distance_km'];
            if ($pace < 120) {
                $errors['duration_seconds'] = 'That pace is faster than 2:00 /km. Check the distance and time.';
            } elseif ($pace > 1500) {
                $errors['duration_seconds'] = 'That pace is slower than 25:00 /km. Check the distance and time.';
            }
        }

        $effort = $in['effort'] ?? null;
        if ($effort === null || $effort === '') {
            $clean['effort'] = null;
        } elseif (filter_var($effort, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]) === false) {
            $errors['effort'] = 'Effort must be a whole number from 1 to 10.';
        } else {
            $clean['effort'] = (int) $effort;
        }

        $notes = $in['notes'] ?? null;
        if ($notes === null || trim((string) $notes) === '') {
            $clean['notes'] = null;
        } elseif (mb_strlen((string) $notes) > 1000) {
            $errors['notes'] = 'Notes can be at most 1000 characters.';
        } else {
            $clean['notes'] = trim((string) $notes);
        }

        return [$clean, $errors];
    }

    public function find_for_user($user_id, $id)
    {
        $row = $this->db->raw(
            'SELECT * FROM activities WHERE id = ? AND user_id = ? LIMIT 1',
            [(int) $id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->with_pace($row) : null;
    }

    /**
     * Longest first-party run in the last N days, used to seed a rebuilt plan
     * from what the runner actually did instead of what they once declared.
     *
     * Strictly scoped to ELIGIBLE_SOURCE (`manual`) per Spec 5.5 / TC-10.
     *
     * @param int                    $user_id
     * @param int                    $days
     * @param DateTimeImmutable|null $today
     * @return float|null
     */
    public function longest_recent_run_km($user_id, $days = 21, ?DateTimeImmutable $today = null)
    {
        $ref    = $today ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));
        $cutoff = $ref->modify('-' . max(1, (int) $days) . ' days')->format('Y-m-d');

        $row = $this->db->raw(
            'SELECT MAX(distance_km) AS d FROM activities
              WHERE user_id = ? AND source = ? AND activity_date >= ?',
            [(int) $user_id, self::ELIGIBLE_SOURCE, $cutoff]
        )->fetch(PDO::FETCH_ASSOC);

        return ($row && $row['d'] !== null) ? (float) $row['d'] : null;
    }

    // Returns the new activity, or null if the session was completed meanwhile
    public function create_for_user($user_id, array $clean, $session_id = null)
    {
        $row = array_merge($clean, ['user_id' => (int) $user_id, 'source' => self::ELIGIBLE_SOURCE]);
        if ($session_id !== null) {
            $row['session_id'] = (int) $session_id;
        }

        $this->db->transaction();
        $this->db->table($this->table)->insert($row);
        $id = (int) $this->db->last_id();

        if ($session_id !== null) {
            $done = $this->db->raw(
                "UPDATE training_sessions SET status = 'completed', updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND user_id = ? AND status <> 'completed'",
                [(int) $session_id, (int) $user_id]
            );
            if ($done->rowCount() === 0) {
                $this->db->roll_back();
                return null;
            }
            // Cancel any pending reminder job for a session now completed
            $this->db->raw(
                "UPDATE notification_jobs SET status = 'cancelled'
                  WHERE session_id = ? AND user_id = ? AND status = 'pending'",
                [(int) $session_id, (int) $user_id]
            );
        }
        $this->db->commit();

        return $this->find_for_user($user_id, $id);
    }

    public function delete_for_user($user_id, $id)
    {
        $found = $this->db->raw(
            'SELECT session_id FROM activities WHERE id = ? AND user_id = ?',
            [(int) $id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);
        if (!$found) {
            return;
        }

        $this->db->transaction();
        $this->db->raw('DELETE FROM activities WHERE id = ? AND user_id = ?', [(int) $id, (int) $user_id]);
        if ($found['session_id'] !== null) {
            $this->db->raw(
                "UPDATE training_sessions SET status = 'planned', updated_at = CURRENT_TIMESTAMP
                  WHERE id = ? AND user_id = ? AND status = 'completed'",
                [(int) $found['session_id'], (int) $user_id]
            );
        }
        $this->db->commit();
    }

    public function update_for_user($user_id, $id, array $clean)
    {
        $this->db->raw(
            'UPDATE activities
                SET activity_date = ?, distance_km = ?, duration_seconds = ?,
                    effort = ?, notes = ?, updated_at = CURRENT_TIMESTAMP
              WHERE id = ? AND user_id = ?',
            [
                $clean['activity_date'], $clean['distance_km'], $clean['duration_seconds'],
                $clean['effort'], $clean['notes'], (int) $id, (int) $user_id,
            ]
        );
    }

    public function linkable_session($user_id, $session_id)
    {
        if (!ctype_digit((string) $session_id)) {
            return null;
        }
        $row = $this->db->raw(
            "SELECT s.id, s.status FROM training_sessions s
               JOIN training_plans p ON p.id = s.plan_id
              WHERE s.id = ? AND s.user_id = ? AND p.status = 'active' LIMIT 1",
            [(int) $session_id, (int) $user_id]
        )->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
