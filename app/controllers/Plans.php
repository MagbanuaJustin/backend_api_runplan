<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Plans
 *
 * Plan generation, draft approval, two-step revision workflow
 * (`propose` -> `apply` / `reject`), and revision audit history.
 */
class Plans extends Controller {

    public function generate()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');
        $this->call->model('PlanModel');

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
