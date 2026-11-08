<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Sessions
 *
 * Automatically generated via CLI.
 */
class Sessions extends Controller
{
    public function update($id = '')
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('SessionModel');

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

        $has_date = array_key_exists('scheduled_date', $input);
        $has_status = array_key_exists('status', $input);

        $workout_fields = [
            'type',
            'planned_distance_km',
            'planned_duration_seconds',
            'planned_start_time',
            'notes',
        ];
        $workout_changes = array_intersect_key($input, array_flip($workout_fields));
        $has_workout_changes = !empty($workout_changes);

        // A request may perform only one operation: reschedule, change status, or edit workout details.
        $operation_count = (int) $has_date
            + (int) $has_status
            + (int) $has_workout_changes;

        if ($operation_count > 1) {
            $this->api->respond([
                'error' => 'Validation failed',
                'fields' => [
                    'request' => 'Update the date, status, or workout details in separate requests.',
                ],
            ], 422);
        }

        // Edit workout details without changing its scheduled date or status.
        if ($has_workout_changes) {
            [$session, $err] = $this->SessionModel->edit($user_id, $id, $workout_changes);

            if ($err) {
                if ($err[0] === 422) {
                    $this->api->respond([
                        'error' => 'Validation failed',
                        'fields' => ['workout' => $err[1]],
                    ], 422);
                }
                $this->api->respond_error($err[1], $err[0]);
            }

            $this->api->respond(['data' => $session]);
        }

        // Move to another day.
        if ($has_date) {
            [$session, $err] = $this->SessionModel->reschedule(
                $user_id,
                $id,
                (string) $input['scheduled_date']
            );

            if ($err) {
                if ($err[0] === 422) {
                    $this->api->respond([
                        'error' => 'Validation failed',
                        'fields' => ['scheduled_date' => $err[1]],
                    ], 422);
                }
                $this->api->respond_error($err[1], $err[0]);
            }

            $this->api->respond(['data' => $session]);
        }

        // Skip or un-skip.
        $status = $input['status'] ?? null;
        if (!in_array($status, ['planned', 'skipped'], true)) {
            $this->api->respond([
                'error' => 'Validation failed',
                'fields' => ['status' => 'Use planned or skipped.'],
            ], 422);
        }

        $skip_reason = $input['skip_reason'] ?? null;
        if (
            $skip_reason !== null
            && (!is_scalar($skip_reason) || mb_strlen(trim((string) $skip_reason)) > 255)
        ) {
            $this->api->respond([
                'error' => 'Validation failed',
                'fields' => ['skip_reason' => 'Keep the skip reason under 255 characters.'],
            ], 422);
        }

        $result = $this->SessionModel->set_status($user_id, $id, $status, $skip_reason);
        if ($result === null) {
            $this->api->respond_error('Session not found.', 404);
        }
        if ($result === false) {
            $this->api->respond_error('A completed run cannot be changed here.', 409);
        }

        $this->api->respond(['data' => $result]);
    }
}
