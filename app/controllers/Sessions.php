<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Sessions
 * 
 * Automatically generated via CLI.
 */
class Sessions extends Controller {

    public function update($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PlanModel');

        $this->api->require_method('PATCH');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('session_write_' . $user_id, 60, 60);

        if (!ctype_digit((string) $id)) {
            $this->api->respond_error('Session not found.', 404);
        }

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        $status = $input['status'] ?? null;
        if (!in_array($status, ['planned', 'skipped'], true)) {
            $this->api->respond(['error' => 'Validation failed',
                'fields' => ['status' => 'Use planned or skipped.']], 422);
        }

        $result = $this->PlanModel->set_session_status($user_id, $id, $status);
        if ($result === null) {
            $this->api->respond_error('Session not found.', 404);
        }
        if ($result === false) {
            $this->api->respond_error('A completed run cannot be changed here.', 409);
        }

        $this->api->respond(['data' => $result]);
    }
}