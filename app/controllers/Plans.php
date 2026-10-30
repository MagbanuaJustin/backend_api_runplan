<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Plans
 *
 * Plan generation, draft approval, two-step revision workflow
 * (`propose` -> `apply` / `reject`), and revision audit history.
 */
class Plans extends Controller {

    private function is_iso_date($value)
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        return $date !== false
            && $date->format('Y-m-d') === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    public function manual()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_manual_' . $user_id, 10, 3600);

        $raw_body = file_get_contents('php://input');
        $input = ($raw_body !== false && trim($raw_body) !== '') ? json_decode($raw_body, true) : null;
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $errors = [];
        $goal = isset($input['goal']) && is_scalar($input['goal']) ? trim((string) $input['goal']) : '';
        if ($goal === '' || mb_strlen($goal) > 100) {
            $errors['goal'] = 'Enter a goal using 1 to 100 characters.';
        }

        $start_date = $input['start_date'] ?? null;
        $end_date = $input['end_date'] ?? null;
        $start_valid = $this->is_iso_date($start_date);
        $end_valid = $this->is_iso_date($end_date);
        if (!$start_valid) {
            $errors['start_date'] = 'Enter a valid plan start date.';
        } elseif ($start_date < (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d')) {
            $errors['start_date'] = 'The plan start date cannot be in the past.';
        }
        if (!$end_valid) {
            $errors['end_date'] = 'Enter a valid plan end date.';
        } elseif ($start_valid && $end_date < $start_date) {
            $errors['end_date'] = 'The end date must be on or after the start date.';
        }

        $sessions_per_week = filter_var($input['sessions_per_week'] ?? null, FILTER_VALIDATE_INT);
        if ($sessions_per_week === false || $sessions_per_week < 2 || $sessions_per_week > 7) {
            $errors['sessions_per_week'] = 'Choose between 2 and 7 sessions per week.';
        }

        $input_sessions = $input['sessions'] ?? null;
        if (!is_array($input_sessions) || count($input_sessions) < 2 || count($input_sessions) > 500) {
            $errors['sessions'] = 'Add between 2 and 500 sessions.';
            $input_sessions = [];
        }

        $allowed_types = ['easy', 'tempo', 'interval', 'long', 'race', 'recovery'];
        $sessions = [];
        $seen_dates = [];
        $weekly_counts = [];

        foreach ($input_sessions as $index => $session) {
            $field = 'sessions.' . $index . '.';
            if (!is_array($session)) {
                $errors[$field . 'scheduled_date'] = 'Enter a valid session.';
                continue;
            }

            $scheduled_date = $session['scheduled_date'] ?? null;
            $date_valid = $this->is_iso_date($scheduled_date);
            if (!$date_valid) {
                $errors[$field . 'scheduled_date'] = 'Enter a valid session date.';
            } elseif ($start_valid && $end_valid && ($scheduled_date < $start_date || $scheduled_date > $end_date)) {
                $errors[$field . 'scheduled_date'] = 'The session date must be within the plan dates.';
            }
            if ($date_valid) {
                if (isset($seen_dates[$scheduled_date])) {
                    $errors[$field . 'scheduled_date'] = 'Only one session can be scheduled per date.';
                }
                $seen_dates[$scheduled_date] = true;
                $week = DateTimeImmutable::createFromFormat('!Y-m-d', $scheduled_date, new DateTimeZone('UTC'))->format('o-W');
                $weekly_counts[$week] = ($weekly_counts[$week] ?? 0) + 1;
            }

            $type = isset($session['type']) && is_scalar($session['type']) ? (string) $session['type'] : '';
            if (!in_array($type, $allowed_types, true)) {
                $errors[$field . 'type'] = 'Choose a valid workout type.';
            }

            $distance_raw = $session['planned_distance_km'] ?? null;
            $distance = is_numeric($distance_raw) ? round((float) $distance_raw, 2) : 0.0;
            if ($distance < 0.01 || $distance > 100) {
                $errors[$field . 'planned_distance_km'] = 'Distance must be between 0.01 and 100 km.';
            }

            $pace = isset($session['target_pace']) && is_scalar($session['target_pace'])
                ? trim((string) $session['target_pace'])
                : '';
            if (!preg_match('/^(\d{1,2}):([0-5]\d)$/D', $pace, $pace_parts)
                || ((int) $pace_parts[1] * 60 + (int) $pace_parts[2]) < 1) {
                $errors[$field . 'target_pace'] = 'Enter pace as minutes:seconds per km, for example 7:30.';
                $pace_seconds = 0;
            } else {
                $pace_seconds = (int) $pace_parts[1] * 60 + (int) $pace_parts[2];
            }

            $time = isset($session['planned_start_time']) && is_scalar($session['planned_start_time'])
                ? trim((string) $session['planned_start_time'])
                : '';
            if ($time !== '' && !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $time)) {
                $errors[$field . 'planned_start_time'] = 'Enter a valid start time.';
            } elseif ($time !== '' && strlen($time) === 5) {
                $time .= ':00';
            }

            $notes = isset($session['notes']) && is_scalar($session['notes']) ? trim((string) $session['notes']) : '';
            if (mb_strlen($notes) > 2000) {
                $errors[$field . 'notes'] = 'Keep notes under 2,000 characters.';
            }

            $sessions[] = [
                'scheduled_date' => $scheduled_date,
                'planned_start_time' => $time !== '' ? $time : null,
                'type' => $type,
                'planned_distance_km' => $distance,
                'planned_duration_seconds' => (int) round($distance * $pace_seconds),
                'notes' => $notes !== '' ? $notes : null,
            ];
        }

        if ($sessions_per_week !== false) {
            foreach ($weekly_counts as $count) {
                if ($count > $sessions_per_week) {
                    $errors['sessions_per_week'] = 'A week cannot contain more sessions than the selected maximum.';
                    break;
                }
            }
        }

        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $plan = [
            'goal' => $goal,
            'start_date' => $start_date,
            'end_date' => $end_date,
            'sessions_per_week' => (int) $sessions_per_week,
        ];
        $plan_id = $this->PlanModel->create_draft($user_id, $plan, $sessions, 'manual');
        $data = $this->PlanModel->find_for_user($user_id, $plan_id);
        if (!$data) {
            $this->api->respond_error('The manual plan could not be saved. Please try again.', 500);
        }

        $this->api->respond(['data' => $data], 201);
    }

    public function generate()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');
        $this->call->model('AiCoachingModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_generate_' . $user_id, 10, 3600);

        $profile = $this->ProfileModel->find_for_user($user_id);
        if (!$profile) {
            $this->api->respond([
                'error'  => 'Complete your profile first.',
                'fields' => ['profile' => 'Save your profile before generating a plan.'],
            ], 422);
        }

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        [$built, $errors] = $this->PlanModel->build($profile, $today);
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $plan_id = $this->PlanModel->create_draft($user_id, $built['plan'], $built['sessions']);
        $data    = $this->PlanModel->find_for_user($user_id, $plan_id);
        if (!$data) {
            $this->api->respond_error('The plan could not be saved. Please try again.', 500);
        }
        $data['warnings'] = $built['warnings'];
        $data['ai_brief'] = $this->AiCoachingModel->record_plan_generation($user_id, $plan_id, $profile, $built, $today);

        $this->api->respond(['data' => $data], 201);
    }

    public function current()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->PlanModel->current_for_user((int) $payload['sub'])]);
    }

    public function approve($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_approve_' . $user_id, 20, 600);

        $plan = ctype_digit((string) $id) ? $this->PlanModel->approve_for_user($user_id, $id) : null;
        if (!$plan) {
            $this->api->respond_error('Draft plan not found.', 404);
        }

        $this->api->respond(['data' => $plan]);
    }

    public function discard($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['DELETE', 'POST'], true)) {
            $this->api->respond_error('Method not allowed', 405);
        }
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_discard_' . $user_id, 30, 600);

        $ok = ctype_digit((string) $id) ? $this->PlanModel->discard_draft_for_user($user_id, $id) : false;
        if (!$ok) {
            $this->api->respond_error('Draft plan not found.', 404);
        }

        $this->api->respond(['data' => ['discarded' => true]]);
    }

    public function propose_revision($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');
        $this->call->model('ActivityModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_propose_' . $user_id, 20, 3600);

        if (!ctype_digit((string) $id)) {
            $this->api->respond_error('Active plan not found.', 404);
        }

        $raw_body = file_get_contents('php://input');
        $input    = ($raw_body !== false && trim($raw_body) !== '') ? json_decode($raw_body, true) : [];
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $reason = $input['reason'] ?? null;
        if ($reason !== null && (!is_scalar($reason) || mb_strlen(trim((string) $reason)) > 255)) {
            $this->api->respond([
                'error'  => 'Validation failed',
                'fields' => ['reason' => 'Keep the revision reason under 255 characters.'],
            ], 422);
        }

        $profile = $this->ProfileModel->find_for_user($user_id);
        if (!$profile) {
            $this->api->respond([
                'error'  => 'Complete your profile first.',
                'fields' => ['profile' => 'Save your profile before rebuilding your plan.'],
            ], 422);
        }

        $recent = $this->ActivityModel->longest_recent_run_km($user_id, 21);
        $today  = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        [$proposal, $err] = $this->PlanModel->propose_rebuild($user_id, $id, $profile, $today, $recent, $reason);

        if ($err === 'not_found') {
            $this->api->respond_error('Active plan not found.', 404);
        }
        if ($err) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $err], 422);
        }

        $this->api->respond(['data' => $proposal], 201);
    }

    public function apply_revision($id = '', $rev_id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_apply_' . $user_id, 20, 3600);

        if (!ctype_digit((string) $id) || !ctype_digit((string) $rev_id)) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        [$data, $err] = $this->PlanModel->apply_revision($user_id, $id, $rev_id);
        if ($err === 'not_found' || !$data) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        $this->api->respond(['data' => $data]);
    }

    public function reject_revision($id = '', $rev_id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_reject_' . $user_id, 30, 3600);

        if (!ctype_digit((string) $id) || !ctype_digit((string) $rev_id)) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        $rejected = $this->PlanModel->reject_revision($user_id, $id, $rev_id);
        if (!$rejected) {
            $this->api->respond_error('Pending revision not found.', 404);
        }

        $this->api->respond(['data' => $rejected]);
    }

    public function rebuild($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');
        $this->call->model('ActivityModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('plan_rebuild_' . $user_id, 10, 3600);

        if (!ctype_digit((string) $id)) {
            $this->api->respond_error('Active plan not found.', 404);
        }

        $raw_body = file_get_contents('php://input');
        $input    = ($raw_body !== false && trim($raw_body) !== '') ? json_decode($raw_body, true) : [];
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }
        $reason = isset($input['reason']) && is_scalar($input['reason']) ? (string) $input['reason'] : null;

        $profile = $this->ProfileModel->find_for_user($user_id);
        if (!$profile) {
            $this->api->respond([
                'error'  => 'Complete your profile first.',
                'fields' => ['profile' => 'Save your profile before rebuilding your plan.'],
            ], 422);
        }

        // Longest run of the last 3 weeks: the rebuilt plan continues from what the runner really did
        $recent = $this->ActivityModel->longest_recent_run_km($user_id, 21);

        $today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
        [$data, $err] = $this->PlanModel->rebuild_remaining($user_id, $id, $profile, $today, $recent, $reason);

        if ($err === 'not_found') {
            $this->api->respond_error('Active plan not found.', 404);
        }
        if ($err) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $err], 422);
        }
        $this->api->respond(['data' => $data]);
    }

    public function revisions($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        $list = ctype_digit((string) $id)
            ? $this->PlanModel->revisions_for_user((int) $payload['sub'], $id)
            : null;
        if ($list === null) {
            $this->api->respond_error('Plan not found.', 404);
        }

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $list]);
    }
}
