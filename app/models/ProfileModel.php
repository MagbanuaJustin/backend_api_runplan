<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Model: ProfileModel
 * 
 * Automatically generated via CLI.
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
        $row = $this->db->table($this->table)->where('user_id', (int) $user_id)->get();
        return $row ? $this->present($row) : null;
    }

    public function present(array $row)
    {
        $pace = $row['baseline_pace'] !== null ? (int) $row['baseline_pace'] : null;
        $days = trim((string) $row['available_days']);

        return [
            'id'                   => (int) $row['id'],
            'display_name'         => $row['display_name'],
            'experience'           => $row['experience'],
            'goal'                 => $row['goal'],
            'target_race_date'     => $row['target_race_date'],
            'available_days'       => $days === '' ? [] : explode(',', $days),
            'preferred_time'       => $row['preferred_time'],
            'baseline_distance_km' => $row['baseline_distance_km'] !== null ? (float) $row['baseline_distance_km'] : null,
            'baseline_pace'        => $pace,
            'baseline_pace_label'  => $pace ? sprintf('%d:%02d', intdiv($pace, 60), $pace % 60) : null,
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
        $needs_date = $goal !== null && $goal !== 'general_fitness' && in_array($goal, self::GOALS, true);
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
                $clean['available_days'] = implode(',', array_values(array_intersect(self::DAYS, $days)));
            }
        }

        $time = $in['preferred_time'] ?? null;
        if ($time === null || $time === '') {
            $clean['preferred_time'] = null;
        } elseif (!in_array($time, self::TIMES, true)) {
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
            } elseif (!is_numeric($pace) || (int) $pace != $pace || (int) $pace < 120 || (int) $pace > 1500) {
                $errors['baseline_pace'] = 'Pace must be between 2:00 and 25:00 per km.';
            } else {
                $clean['baseline_pace'] = (int) $pace;
            }
        } else {
            $clean['baseline_distance_km'] = null;
            $clean['baseline_pace'] = null;
        }

        return [$clean, $errors];
    }

    public function save_for_user($user_id, array $c)
    {
        $this->db->raw(
            'INSERT INTO runner_profiles
                (user_id, display_name, experience, goal, target_race_date,
                 available_days, preferred_time, baseline_distance_km, baseline_pace)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) AS incoming
             ON DUPLICATE KEY UPDATE
                display_name = incoming.display_name,
                experience = incoming.experience,
                goal = incoming.goal,
                target_race_date = incoming.target_race_date,
                available_days = incoming.available_days,
                preferred_time = incoming.preferred_time,
                baseline_distance_km = incoming.baseline_distance_km,
                baseline_pace = incoming.baseline_pace,
                updated_at = NOW()',
            [
                (int) $user_id, $c['display_name'], $c['experience'], $c['goal'],
                $c['target_race_date'], $c['available_days'], $c['preferred_time'],
                $c['baseline_distance_km'], $c['baseline_pace'],
            ]
        );
    }
}