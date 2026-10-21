<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Profile
 * 
 * Automatically generated via CLI.
 */
class Profile extends Controller {

    public function show()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->ProfileModel->find_for_user((int) $payload['sub'])]);
    }

    public function save()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('ProfileModel');

        $this->api->require_method('PUT');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('profile_write_' . $user_id, 30, 60);

        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body', 400);
        }

        [$clean, $errors] = $this->ProfileModel->validate($input);
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed', 'fields' => $errors], 422);
        }

        $existed = $this->ProfileModel->find_for_user($user_id) !== null;
        $this->ProfileModel->save_for_user($user_id, $clean);

        $this->api->respond(['data' => $this->ProfileModel->find_for_user($user_id)], $existed ? 200 : 201);
    }
}