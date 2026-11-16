
<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ActivityModel
 *
 * Automatically generated via CLI.
 */
class ActivityModel extends Model
{
    protected $table = 'activities';
    protected $primary_key = 'id';

    const ELIGIBLE_SOURCE = 'manual';

    protected $fillable = [
        'session_id',
        'activity_date',
        'distance_km',
        'duration_seconds',
        'effort',
        'notes',
        'workout_type',
        'route_data',
    ];

    /**
     * Explicit gate used by analytics and coaching summary builders.
     */
    public static function is_eligible_for_analytics(array $row)
    {
        return ($row['source'] ?? '') === self::ELIGIBLE_SOURCE;
    }

    /**
     * Return a user's activities, optionally filtered by date.
     */
    public function for_user($user_id, array $filters = [])
    {
        $query = $this->db
            ->table($this->table)
            ->where('user_id', (int) $user_id)
            ->where('source', self::ELIGIBLE_SOURCE);

        if (
            !empty($filters['from'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['from'])
        ) {
            $query->where('activity_date', '>=', (string) $filters['from']);
        }

        if (
            !empty($filters['to'])
            && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $filters['to'])
        ) {
            $query->where('activity_date', '<=', (string) $filters['to']);
        }

        $rows = $query
            ->order_by('activity_date', 'DESC')
            ->order_by('id', 'DESC')
            ->get_all();

        $rows = $rows ?: [];

        return array_map([$this, 'with_pace'], $rows);
    }

    /**
     * Add a formatted pace and normalize numeric fields.
     */
    public function with_pace(array $row)
    {
        $km = (float) $row['distance_km'];
        $seconds = (int) $row['duration_seconds'];

        $row['id'] = (int) $row['id'];
        $row['distance_km'] = $km;
        $row['duration_seconds'] = $seconds;

        $row['session_id'] = $row['session_id'] !== null
            ? (int) $row['session_id']
            : null;

        $row['effort'] = $row['effort'] !== null
            ? (int) $row['effort']
            : null;

        $row['workout_type'] = $row['workout_type'] ?? null;

        if (!empty($row['route_data']) && is_string($row['route_data'])) {
            $decodedRoute = json_decode($row['route_data'], true);
            $row['route_data'] = (json_last_error() === JSON_ERROR_NONE && is_array($decodedRoute))
                ? $decodedRoute
                : null;
        } else {
            $row['route_data'] = null;
        }

        if ($km > 0 && $seconds > 0) {
            $pace = (int) round($seconds / $km);

            $row['pace_seconds_per_km'] = $pace;
            $row['pace_label'] = sprintf(
                '%d:%02d',
                intdiv($pace, 60),
                $pace % 60
            );
        } else {
            $row['pace_seconds_per_km'] = null;
            $row['pace_label'] = null;
        }

        return $row;
    }

    /**
     * Validate activity input.
     *
     * Returns [clean_data, errors].
     */
    public function validate(array $input)
    {
        $clean = [];
        $errors = [];

        // Validate activity date.
        $date = (string) ($input['activity_date'] ?? '');
        $parsedDate = DateTime::createFromFormat('Y-m-d', $date);

        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            $errors['activity_date'] = 'Enter a valid date.';
        } else {
            $maximumDate = (new DateTime(
                'now',
                new DateTimeZone('UTC')
            ))->modify('+1 day')->format('Y-m-d');

            if ($date > $maximumDate) {
                $errors['activity_date'] = 'The date cannot be in the future.';
            } elseif ($date < '2000-01-01') {
                $errors['activity_date'] = 'That date is too far in the past.';
            } else {
                $clean['activity_date'] = $date;
            }
        }

        // Validate distance.
        $distance = $input['distance_km'] ?? null;

        if (
            !is_numeric($distance)
            || (float) $distance < 0.01
            || (float) $distance > 300
        ) {
            $errors['distance_km'] = 'Distance must be between 0.01 and 300 km.';
        } else {
            $clean['distance_km'] = round((float) $distance, 2);
        }

        // Validate duration.
        $duration = $input['duration_seconds'] ?? null;

        if (
            !is_numeric($duration)
            || (int) $duration != $duration
            || (int) $duration < 1
            || (int) $duration > 172800
        ) {
            $errors['duration_seconds'] =
                'Enter a duration between 1 second and 48 hours.';
        } else {
            $clean['duration_seconds'] = (int) $duration;
        }

        // Check pace only when both distance and duration are valid.
        if (
            isset($clean['distance_km'])
            && isset($clean['duration_seconds'])
        ) {
            $pace = $clean['duration_seconds'] / $clean['distance_km'];

            if ($pace < 120) {
                $errors['duration_seconds'] =
                    'That pace is faster than 2:00 /km. Check the distance and time.';
            } elseif ($pace > 1500) {
                $errors['duration_seconds'] =
                    'That pace is slower than 25:00 /km. Check the distance and time.';
            }
        }

        // Validate effort.
        $effort = $input['effort'] ?? null;

        if ($effort === null || $effort === '') {
            $clean['effort'] = null;
        } elseif (
            filter_var(
                $effort,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 10]]
            ) === false
        ) {
            $errors['effort'] = 'Effort must be a whole number from 1 to 10.';
        } else {
            $clean['effort'] = (int) $effort;
        }

        // Validate notes.
        $notes = $input['notes'] ?? null;

        if ($notes === null || trim((string) $notes) === '') {
            $clean['notes'] = null;
        } elseif (mb_strlen((string) $notes) > 1000) {
            $errors['notes'] = 'Notes can be at most 1000 characters.';
        } else {
            $clean['notes'] = trim((string) $notes);
        }

        // Validate workout type.
        $workoutType = $input['workout_type'] ?? null;

        $allowedWorkoutTypes = [
            'easy',
            'long',
            'tempo',
            'threshold',
            'interval',
            'recovery',
            'race',
        ];

        if ($workoutType === null || $workoutType === '') {
            $clean['workout_type'] = null;
        } elseif (
            !is_string($workoutType)
            || !in_array($workoutType, $allowedWorkoutTypes, true)
        ) {
            $errors['workout_type'] = 'Select a valid workout type.';
        } else {
            $clean['workout_type'] = $workoutType;
        }

        // Validate and encode optional GPS route data.
        $routeData = $input['route_data'] ?? null;

        if ($routeData === null || $routeData === '') {
            $clean['route_data'] = null;
        } elseif (
            !is_array($routeData)
            || !isset($routeData['segments'])
            || !is_array($routeData['segments'])
        ) {
            $errors['route_data'] = 'The GPS route data is invalid.';
        } else {
            $cleanSegments = [];
            $pointCount = 0;
            $routeIsValid = true;

            foreach ($routeData['segments'] as $segment) {
                if (!is_array($segment)) {
                    $routeIsValid = false;
                    break;
                }

                $cleanSegment = [];

                foreach ($segment as $point) {
                    $pointCount++;

                    if (
                        !is_array($point)
                        || !isset($point['latitude'], $point['longitude'])
                        || !is_numeric($point['latitude'])
                        || !is_numeric($point['longitude'])
                    ) {
                        $routeIsValid = false;
                        break 2;
                    }

                    $latitude = (float) $point['latitude'];
                    $longitude = (float) $point['longitude'];

                    if (
                        $latitude < -90 || $latitude > 90
                        || $longitude < -180 || $longitude > 180
                    ) {
                        $routeIsValid = false;
                        break 2;
                    }

                    $cleanPoint = [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ];

                    if (isset($point['timestamp']) && is_numeric($point['timestamp'])) {
                        $cleanPoint['timestamp'] = (int) $point['timestamp'];
                    }

                    if (
                        array_key_exists('accuracy', $point)
                        && $point['accuracy'] !== null
                        && is_numeric($point['accuracy'])
                        && (float) $point['accuracy'] >= 0
                    ) {
                        $cleanPoint['accuracy'] = (float) $point['accuracy'];
                    } else {
                        $cleanPoint['accuracy'] = null;
                    }

                    $cleanSegment[] = $cleanPoint;
                }

                if ($cleanSegment) {
                    $cleanSegments[] = $cleanSegment;
                }
            }

            if (!$routeIsValid || $pointCount > 15000) {
                $errors['route_data'] = 'The GPS route contains invalid data or too many points.';
            } else {
                $encodedRoute = json_encode(
                    ['segments' => $cleanSegments],
                    JSON_UNESCAPED_SLASHES
                );

                if ($encodedRoute === false) {
                    $errors['route_data'] = 'The GPS route could not be processed.';
                } else {
                    $clean['route_data'] = $encodedRoute;
                }
            }
        }

        return [$clean, $errors];
    }

    /**
     * Find one activity owned by a user.
     */
    public function find_for_user($user_id, $id)
    {
        $row = $this->db
            ->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->row_array();

        return $row ? $this->with_pace($row) : null;
    }

    /**
     * Return the longest first-party run in the last N days.
     */
    public function longest_recent_run_km(
        $user_id,
        $days = 21,
        ?DateTimeImmutable $today = null
    ) {
        $referenceDate = $today
            ?: new DateTimeImmutable('today', new DateTimeZone('UTC'));

        $cutoff = $referenceDate
            ->modify('-' . max(1, (int) $days) . ' days')
            ->format('Y-m-d');

        $row = $this->db
            ->table($this->table)
            ->select_max('distance_km', 'd')
            ->where('user_id', (int) $user_id)
            ->where('source', self::ELIGIBLE_SOURCE)
            ->where('activity_date', '>=', $cutoff)
            ->row_array();

        return ($row && $row['d'] !== null)
            ? (float) $row['d']
            : null;
    }

    /**
     * Create an activity and, if linked, complete its training session.
     *
     * Returns the created activity, or null if the linked session could not
     * be completed.
     */
    public function create_for_user($user_id, array $clean, $session_id = null)
    {
        $row = array_merge(
            $clean,
            [
                'user_id' => (int) $user_id,
                'source' => self::ELIGIBLE_SOURCE,
            ]
        );

        if ($session_id !== null) {
            $row['session_id'] = (int) $session_id;
        }

        $this->db->transaction();

        try {
            $this->db
                ->table($this->table)
                ->insert($row);

            $activityId = (int) $this->db->last_id();

            if ($session_id !== null) {
                $this->db
                    ->table('training_sessions')
                    ->where('id', (int) $session_id)
                    ->where('user_id', (int) $user_id)
                    ->where('status', '<>', 'completed')
                    ->update([
                        'status' => 'completed',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);

                if ($this->db->row_count() === 0) {
                    $this->db->roll_back();
                    return null;
                }

                // Cancel any pending reminder for the completed session.
                $this->db
                    ->table('notification_jobs')
                    ->where('session_id', (int) $session_id)
                    ->where('user_id', (int) $user_id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                    ]);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }

        return $this->find_for_user($user_id, $activityId);
    }

    /**
     * Delete an activity and return its linked session to planned status.
     */
    public function delete_for_user($user_id, $id)
    {
        $found = $this->db
            ->table($this->table)
            ->select('session_id')
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->limit(1)
            ->row_array();

        if (!$found) {
            return;
        }

        $this->db->transaction();

        try {
            $this->db
                ->table($this->table)
                ->where('id', (int) $id)
                ->where('user_id', (int) $user_id)
                ->delete();

            if ($this->db->row_count() === 0) {
                $this->db->roll_back();
                return;
            }

            if ($found['session_id'] !== null) {
                $this->db
                    ->table('training_sessions')
                    ->where('id', (int) $found['session_id'])
                    ->where('user_id', (int) $user_id)
                    ->where('status', 'completed')
                    ->update([
                        'status' => 'planned',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    /**
     * Update an activity owned by a user.
     */
    public function update_for_user($user_id, $id, array $clean)
    {
        $this->db
            ->table($this->table)
            ->where('id', (int) $id)
            ->where('user_id', (int) $user_id)
            ->update([
                'activity_date' => $clean['activity_date'],
                'distance_km' => $clean['distance_km'],
                'duration_seconds' => $clean['duration_seconds'],
                'effort' => $clean['effort'],
                'notes' => $clean['notes'],
                'workout_type' => $clean['workout_type'] ?? null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    /**
     * Find a session from the user's active plan that can be linked to a run.
     */
    public function linkable_session($user_id, $session_id)
    {
        if (!ctype_digit((string) $session_id)) {
            return null;
        }

        $row = $this->db
            ->table('training_sessions')
            ->select('training_sessions.id, training_sessions.status')
            ->inner_join(
                'training_plans',
                'training_plans.id = training_sessions.plan_id'
            )
            ->where('training_sessions.id', (int) $session_id)
            ->where('training_sessions.user_id', (int) $user_id)
            ->where('training_plans.status', 'active')
            ->limit(1)
            ->row_array();

        return $row ?: null;
    }
}
