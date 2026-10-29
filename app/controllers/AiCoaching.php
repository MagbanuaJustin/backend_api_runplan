<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: AiCoaching
 *
 * Exposes weekly training review endpoints (`GET /api/ai/feedback` and
 * `POST /api/ai/feedback`) and private, unsaved chat (`POST /api/ai/chat`).
 * Uses Google Gemini when enabled + configured, with deterministic feedback
 * fallback when the provider is unavailable.
 */
class AiCoaching extends Controller {

    public function show()
    {
        $this->call->library('api');
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('AiCoachingModel');

        $this->api->require_method('GET');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $pub = integration_public_config();

        header('Cache-Control: no-store');
        $this->api->respond(array(
            'data' => array(
                'feedback'  => $this->AiCoachingModel->latest_feedback_for_user($user_id),
                'gemini' => $pub['gemini'] ?? array('ready' => false, 'configured' => false),
            ),
        ));
    }

    public function generate()
    {
        $this->call->library('api');
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('AiCoachingModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('ai_feedback_' . $user_id, 10, 3600);

        $feedback = $this->AiCoachingModel->generate_weekly_feedback($user_id);
        $pub      = integration_public_config();

        $this->api->respond(array(
            'data' => array(
                'feedback'  => $feedback,
                'gemini' => $pub['gemini'] ?? array('ready' => false, 'configured' => false),
            ),
        ), 201);
    }

    public function chat()
    {
        $this->call->library('api');
        $this->call->helper('integration');
        $this->call->database();
        $this->call->model('AiCoachingModel');

        $this->api->require_method('POST');
        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];
        $this->api->rate_limit('ai_chat_' . $user_id, 20, 3600);

        $input = $this->read_json();
        $question = trim((string) ($input['message'] ?? ''));
        if ($question === '' || mb_strlen($question) > 1000) {
            $this->api->respond_error('Enter a question under 1000 characters.', 422);
        }

        $result = $this->AiCoachingModel->answer_chat_question($user_id, $question);
        if (empty($result['ok']) || empty($result['text'])) {
            $status = ($result['provider_status'] ?? '') === 'cap_reached' ? 429
                : (($result['provider_status'] ?? '') === 'invalid_request' ? 422 : 503);
            $this->api->respond_error($result['error'] ?? 'Gemini is temporarily unavailable.', $status);
        }

        $public = integration_public_config();
        $this->api->respond(array('data' => array(
            'answer' => $result['text'],
            'gemini' => $public['gemini'] ?? array('ready' => false, 'configured' => false),
        )));
    }

    private function read_json()
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return array();
        }

        $input = json_decode($raw, true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body.', 400);
        }
        return $input;
    }
}
