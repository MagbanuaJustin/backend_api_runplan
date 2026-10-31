<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ProfileModel
 *
 * Runner training profile, required preferred workout time, optional desired
 * weekly run frequency, and optional training location.
 */
class ProfileModel extends Model {
    protected $table = 'runner_profiles';
    protected $primary_key = 'id';

    const EXPERIENCE = ['beginner', 'intermediate', 'advanced'];
    const GOALS      = ['5k', '10k', 'half_marathon', 'marathon', 'general_fitness'];
    const DAYS       = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    const TIMES      = ['morning', 'afternoon', 'evening'];

    public function find_for_user($user_id)
    {
        $row = $this->db->table($this->table)
            ->where('user_id', (int) $user_id)
            ->get();

        return $row ? $this->present($row) : null;
    }

    public function present(array $row)
    {
        $pace = isset($row['baseline_pace']) && $row['baseline_pace'] !== null
            ? (int) $row['baseline_pace']
            : null;

        $days = trim((string) ($row['available_days'] ?? ''));

        $desired = isset($row['desired_sessions_per_week'])
            && $row['desired_sessions_per_week'] !== null
                ? (int) $row['desired_sessions_per_week']
                : null;

        $preferred_time = $row['preferred_time'] ?? null;
        if (!in_array($preferred_time, self::TIMES, true)) {
            // Existing profiles may predate the required training-time setting.
            $preferred_time = 'morning';
        }

        return [
            'id'                        => (int) $row['id'],
            'display_name'              => $row['display_name'],
            'experience'                => $row['experience'],
            'goal'                      => $row['goal'],
            'target_race_date'          => $row['target_race_date'],
            'available_days'             => $days === '' ? [] : explode(',', $days),
            'desired_sessions_per_week' => $desired,
            'preferred_time'            => $preferred_time,
            'baseline_distance_km'      => isset($row['baseline_distance_km'])
                && $row['baseline_distance_km'] !== null
                    ? (float) $row['baseline_distance_km']
                    : null,
            'baseline_pace'             => $pace,
            'baseline_pace_label'       => $pace
                ? sprintf('%d:%02d', intdiv($pace, 60), $pace % 60)
                : null,
            'location'                  => $this->present_location($row),
        ];
    }

    /**
     * Optional training location.
     *
     * Coordinates are used when available; otherwise the place label can be
     * used by the weather service.
     */
    private function present_location(array $row)
    {
        $label = isset($row['location_label']) && $row['location_label'] !== null
            ? (string) $row['location_label']
            : null;

        $lat = isset($row['latitude']) && $row['latitude'] !== null
            ? (float) $row['latitude']
            : null;

        $lon = isset($row['longitude']) && $row['longitude'] !== null
            ? (float) $row['longitude']
            : null;

        $has_coordinates = $lat !== null && $lon !== null;

        return [
            'label'           => $label,
            'latitude'        => $lat,
            'longitude'       => $lon,
            'has_coordinates' => $has_coordinates,
            'query'           => $has_coordinates
                ? sprintf('%.5F,%.5F', $lat, $lon)
                : ($label ?? ''),
        ];
    }

    // Returns [clean_data, errors]
    public function validate(array $in)
    {
        $clean  = [];
        $errors = [];

        $name = preg_replace('/\s+/u', ' ', trim((string) ($in['display_name'] ?? '')));
        if ($name === '' || mb_strlen($name) > 50 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            $errors['display_name'] = 'Enter a name of 1 to 50 characters.';
        } else {
            $clean['display_name'] = $name;
        }

        $exp = $in['experience'] ?? null;
        if (!in_array($exp, self::EXPERIENCE, true)) {
            $errors['experience'] = 'Choose beginner, intermediate or advanced.';
        } else {
            $clean['experience'] = $exp;
        }

        $goal = $in['goal'] ?? null;
        if (!in_array($goal, self::GOALS, true)) {
            $errors['goal'] = 'Choose one of the listed goals.';
        } else {
            $clean['goal'] = $goal;
        }

        $date = $in['target_race_date'] ?? null;
        $needs_date = $goal !== null
            && $goal !== 'general_fitness'
            && in_array($goal, self::GOALS, true);

        if ($date === null || $date === '') {
            if ($needs_date) {
                $errors['target_race_date'] = 'Enter your race date.';
            } else {
                $clean['target_race_date'] = null;
            }
        } else {
            $d = DateTime::createFromFormat('Y-m-d', (string) $date);
            $utc = new DateTimeZone('UTC');
            $min = (new DateTime('now', $utc))->modify('-1 day')->format('Y-m-d');
            $max = (new DateTime('now', $utc))->modify('+730 days')->format('Y-m-d');

            if (!$d || $d->format('Y-m-d') !== $date) {
                $errors['target_race_date'] = 'Enter a valid date.';
            } elseif ($date < $min || $date > $max) {
                $errors['target_race_date'] = 'Choose a date within the next two years.';
            } else {
                $clean['target_race_date'] = $date;
            }
        }

        $valid_days = [];
        $days = $in['available_days'] ?? null;

        if (!is_array($days)) {
            $errors['available_days'] = 'Choose the days you can train.';
        } else {
            $days = array_values(array_unique(array_map('strval', $days)));

            if (array_diff($days, self::DAYS)) {
                $errors['available_days'] = 'Use only the days mon to sun.';
            } elseif (count($days) < 2) {
                $errors['available_days'] = 'Choose at least 2 days.';
            } else {
                $valid_days = array_values(array_intersect(self::DAYS, $days));
                $clean['available_days'] = implode(',', $valid_days);
            }
        }

        $desired = $in['desired_sessions_per_week'] ?? null;

        if ($desired === null || $desired === '') {
            $clean['desired_sessions_per_week'] = null;
        } elseif (filter_var($desired, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 2, 'max_range' => 6],
        ]) === false) {
            $errors['desired_sessions_per_week'] = 'Choose between 2 and 6 sessions per week.';
        } elseif (!empty($valid_days) && (int) $desired > count($valid_days)) {
            $errors['desired_sessions_per_week'] =
                'Desired sessions per week cannot exceed your selected available days.';
        } else {
            $clean['desired_sessions_per_week'] = (int) $desired;
        }

        // Training time is required for every saved profile.
        $time = $in['preferred_time'] ?? null;
        if (!in_array($time, self::TIMES, true)) {
            $errors['preferred_time'] = 'Choose morning, afternoon or evening.';
        } else {
            $clean['preferred_time'] = $time;
        }

        $km   = $in['baseline_distance_km'] ?? null;
        $pace = $in['baseline_pace'] ?? null;
        $has_km   = !($km === null || $km === '');
        $has_pace = !($pace === null || $pace === '');

        if ($has_km || $has_pace) {
            if (!$has_km) {
                $errors['baseline_distance_km'] = 'Enter the distance of your recent run.';
            } elseif (!is_numeric($km) || (float) $km < 0.5 || (float) $km > 100) {
                $errors['baseline_distance_km'] = 'Distance must be between 0.5 and 100 km.';
            } else {
                $clean['baseline_distance_km'] = round((float) $km, 2);
            }

            if (!$has_pace) {
                $errors['baseline_pace'] = 'Enter the pace of your recent run.';
            } elseif (
                !is_numeric($pace)
                || (int) $pace != $pace
                || (int) $pace < 120
                || (int) $pace > 1500
            ) {
                $errors['baseline_pace'] = 'Pace must be between 2:00 and 25:00 per km.';
            } else {
                $clean['baseline_pace'] = (int) $pace;
            }
        } else {
            $clean['baseline_distance_km'] = null;
            $clean['baseline_pace'] = null;
        }

        [$location, $location_errors] = $this->validate_location($in);
        $clean  = array_merge($clean, $location);
        $errors = array_merge($errors, $location_errors);

        return [$clean, $errors];
    }

    /**
     * Optional training location.
     *
     * - If no location fields are sent, existing location data is preserved.
     * - If all location fields are empty, the location is cleared.
     * - Latitude and longitude must be provided together.
     */
    private function validate_location(array $in)
    {
        $clean  = [];
        $errors = [];

        $touched = array_key_exists('location_label', $in)
            || array_key_exists('latitude', $in)
            || array_key_exists('longitude', $in);

        if (!$touched) {
            return [$clean, $errors];
        }

        $label = $in['location_label'] ?? null;

        if ($label === null || (is_string($label) && trim($label) === '')) {
            $clean['location_label'] = null;
        } elseif (!is_scalar($label)) {
            $errors['location_label'] = 'Enter a city or leave the location empty.';
        } else {
            $label = preg_replace('/\s+/u', ' ', trim((string) $label));

            if (preg_match('/[\x00-\x1F\x7F]/', $label)) {
                $errors['location_label'] = 'Remove unusual characters from the location.';
            } elseif (mb_strlen($label) < 2) {
                $errors['location_label'] = 'Enter at least 2 characters for the location.';
            } elseif (mb_strlen($label) > 120) {
                $errors['location_label'] = 'Keep the location under 120 characters.';
            } else {
                $clean['location_label'] = $label;
            }
        }

        $has_lat = array_key_exists('latitude', $in)
            && !($in['latitude'] === null || $in['latitude'] === '');

        $has_lon = array_key_exists('longitude', $in)
            && !($in['longitude'] === null || $in['longitude'] === '');

        if (!$has_lat && !$has_lon) {
            $clean['latitude'] = null;
            $clean['longitude'] = null;
            return [$clean, $errors];
        }

        if ($has_lat !== $has_lon) {
            $errors[$has_lat ? 'longitude' : 'latitude'] =
                'Enter both latitude and longitude, or leave both empty.';
            return [$clean, $errors];
        }

        if (
            !is_numeric($in['latitude'])
            || (float) $in['latitude'] < -90
            || (float) $in['latitude'] > 90
        ) {
            $errors['latitude'] = 'Latitude must be a number between -90 and 90.';
        }

        if (
            !is_numeric($in['longitude'])
            || (float) $in['longitude'] < -180
            || (float) $in['longitude'] > 180
        ) {
            $errors['longitude'] = 'Longitude must be a number between -180 and 180.';
        }

        if (!isset($errors['latitude']) && !isset($errors['longitude'])) {
            $clean['latitude'] = round((float) $in['latitude'], 5);
            $clean['longitude'] = round((float) $in['longitude'], 5);
        }

        return [$clean, $errors];
    }

    public function save_for_user($user_id, array $c)
    {
        $uid = (int) $user_id;
        $existing = $this->db->table($this->table)
            ->where('user_id', $uid)
            ->get();

        // Keep stored location values when this request does not include them.
        $location = [
            'location_label' => $existing['location_label'] ?? null,
            'latitude'       => $existing['latitude'] ?? null,
            'longitude'      => $existing['longitude'] ?? null,
        ];

        foreach (array_keys($location) as $key) {
            if (array_key_exists($key, $c)) {
                $location[$key] = $c[$key];
            }
        }

        $values = [
            'display_name'              => $c['display_name'],
            'experience'                => $c['experience'],
            'goal'                      => $c['goal'],
            'target_race_date'          => $c['target_race_date'],
            'available_days'             => $c['available_days'],
            'desired_sessions_per_week' => $c['desired_sessions_per_week'] ?? null,
            'preferred_time'            => $c['preferred_time'],
            'baseline_distance_km'      => $c['baseline_distance_km'],
            'baseline_pace'             => $c['baseline_pace'],
            'location_label'            => $location['location_label'],
            'latitude'                  => $location['latitude'],
            'longitude'                 => $location['longitude'],
        ];

        if ($existing) {
            $values['updated_at'] = gmdate('Y-m-d H:i:s');

            $this->db->table($this->table)
                ->where('user_id', $uid)
                ->update($values);

            return;
        }

        $values['user_id'] = $uid;
        $this->db->table($this->table)->insert($values);
    }
}