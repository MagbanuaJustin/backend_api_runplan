<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: AiCoaching
 *
 * Exposes weekly training review endpoints (`GET /api/ai/feedback` and
 * `POST /api/ai/feedback`). Uses Anthropic Claude when enabled + configured,
 * and falls back cleanly to deterministic rule-based coaching otherwise.
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
                'anthropic' => $pub['anthropic'] ?? array('ready' => false, 'configured' => false),
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
                'anthropic' => $pub['anthropic'] ?? array('ready' => false, 'configured' => false),
            ),
        ), 201);
    }
}
