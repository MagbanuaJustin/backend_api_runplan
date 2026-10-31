<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/HttpClient.php';

/**
 * Server-side Groq chat-completions client. Sends only allowlisted metrics;
 * the API key stays on the server and failures use the deterministic fallback.
 */
class GroqService
{
    private $config;
    private $http;

    public function __construct(?array $config = null, ?HttpClient $http = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } elseif (function_exists('integration_config')) {
            $this->config = integration_config('groq');
        } else {
            $this->config = array();
        }

        $this->http = $http ?: new HttpClient(max(3, min(30, (int) ($this->config['timeout'] ?? 20))));
    }

    public function is_ready()
    {
        $enabled = filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $key = trim((string) ($this->config['api_key'] ?? ''));
        $model = trim((string) ($this->config['model'] ?? ''));

        return $enabled === true && $key !== '' && $model !== ''
            && preg_match('/^[A-Za-z0-9._\/-]+$/', $model) === 1;
    }

    public function monthly_cap()
    {
        return max(1, (int) ($this->config['monthly_call_cap'] ?? 500));
    }

    public function model_name()
    {
        return (string) ($this->config['model'] ?? 'llama-3.3-70b-versatile');
    }

    public function generate_weekly_feedback(array $metrics)
    {
        if (!$this->is_ready()) return $this->failure('not_configured', 'Groq is disabled or not configured.');

        $system = 'You are an encouraging, safety-first distance running coach inside RunPlan. '
            . 'Write 3 to 5 concise plain-text sentences reviewing the runner\'s past 7 days based strictly '
            . 'on the provided JSON metrics, followed by 2 short actionable focus points for next week. '
            . 'Do not invent runs, use markdown headings, or give medical diagnoses.';
        $prompt = "Weekly first-party training summary (JSON):\n"
            . json_encode($this->sanitize_metrics($metrics), JSON_UNESCAPED_SLASHES);

        return $this->send_message($system, $prompt, 450);
    }

    public function generate_plan_brief(array $plan_summary)
    {
        if (!$this->is_ready()) return $this->failure('not_configured', 'Groq is disabled or not configured.');

        $allowed = array('goal', 'experience', 'start_date', 'end_date', 'weeks', 'sessions_per_week', 'total_km', 'peak_long_km');
        $safe = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $plan_summary)
                && (is_scalar($plan_summary[$key]) || $plan_summary[$key] === null)) {
                $safe[$key] = $plan_summary[$key];
            }
        }

        $system = 'You are a supportive distance running coach inside RunPlan. '
            . 'Write a 2 to 3 sentence plain-text overview for this generated training schedule. '
            . 'Do not alter distances or dates and do not provide medical advice.';
        $prompt = "Draft training block summary (JSON):\n" . json_encode($safe, JSON_UNESCAPED_SLASHES);

        return $this->send_message($system, $prompt, 260);
    }

    public function generate_chat_answer($question, array $metrics)
    {
        if (!$this->is_ready()) return $this->failure('not_configured', 'Groq is disabled or not configured.');

        $question = trim(strip_tags((string) $question));
        $question = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $question);
        if (!is_string($question) || $question === '' || mb_strlen($question) > 1000) {
            return $this->failure('invalid_request', 'Enter a question under 1000 characters.');
        }

        $system = 'You are RunPlan AI, a supportive, safety-first distance running coach. '
            . 'Answer clearly and concisely using only the training metrics provided. '
            . 'If the metrics do not answer the question, say what is missing instead of guessing. '
            . 'Do not give medical diagnoses or change the validated training plan.';
        $prompt = "Runner question:\n" . $question . "\n\nSelected first-party training metrics (JSON):\n"
            . json_encode($this->sanitize_metrics($metrics), JSON_UNESCAPED_SLASHES);

        return $this->send_message($system, $prompt, 700);
    }

    private function sanitize_metrics(array $metrics)
    {
        $allowed = array(
            'period_start', 'period_end', 'goal', 'experience', 'runs_logged', 'distance_km',
            'duration_seconds', 'avg_effort', 'longest_run_km', 'sessions_completed',
            'sessions_planned', 'sessions_skipped', 'sessions_missed',
        );
        $safe = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $metrics) && (is_scalar($metrics[$key]) || $metrics[$key] === null)) {
                $safe[$key] = $metrics[$key];
            }
        }
        return $safe;
    }

    private function send_message($system_prompt, $user_prompt, $max_tokens_cap)
    {
        $base = rtrim((string) ($this->config['base_uri'] ?? 'https://api.groq.com/openai/v1'), '/');
        $key = trim((string) ($this->config['api_key'] ?? ''));
        $model = $this->model_name();
        $tokens = min((int) $max_tokens_cap, max(64, (int) ($this->config['max_tokens'] ?? 700)));
        $timeout = max(3, min(30, (int) ($this->config['timeout'] ?? 20)));

        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $model) !== 1) {
            return $this->failure('configuration', 'The configured Groq model name is invalid.');
        }

        $payload = array(
            'model' => $model,
            'messages' => array(
                array('role' => 'system', 'content' => $system_prompt),
                array('role' => 'user', 'content' => $user_prompt),
            ),
            'temperature' => 0.4,
            'max_completion_tokens' => $tokens,
            'stream' => false,
        );
        $options = array(
            'timeout' => $timeout,
            'headers' => array('Authorization: Bearer ' . $key),
        );

        $response = $this->http->post_json($base . '/chat/completions', $payload, $options);
        if (empty($response['ok']) || !is_array($response['data'] ?? null)) {
            $this->log_provider_failure($response);
            return $this->failure('fallback', $this->safe_provider_error((int) ($response['status'] ?? 0)));
        }

        $text = $response['data']['choices'][0]['message']['content'] ?? '';
        if (!is_string($text) || trim($text) === '') {
            return $this->failure('fallback', 'Groq returned no usable text. Check the configured model.');
        }

        return array('ok' => true, 'text' => mb_substr(trim($text), 0, 4000), 'provider_status' => 'ok', 'error' => null);
    }

    private function safe_provider_error($status)
    {
        if ($status === 400 || $status === 404) return 'Groq rejected the request. Check GROQ_MODEL and the request settings.';
        if ($status === 401 || $status === 403) return 'Groq access was denied. Check that the API key is valid and the Groq API is enabled.';
        if ($status === 429) return 'Groq rate or usage limit reached. Try again later or review your Groq account limits.';
        return 'Groq is temporarily unavailable. Please try again in a moment.';
    }

    private function log_provider_failure(array $response)
    {
        $error = $response['data']['error'] ?? array();
        if (!is_array($error)) $error = array();

        $detail = isset($response['debug']) && is_string($response['debug'])
            ? preg_replace('/[\r\n\t]+/', ' ', $response['debug'])
            : '';
        $key = trim((string) ($this->config['api_key'] ?? ''));
        if ($key !== '' && $detail !== '') $detail = str_replace($key, '[redacted]', $detail);

        $diagnostic = array(
            'http_status' => (int) ($response['status'] ?? 0),
            'transport_code' => $response['error_code'] ?? null,
            'transport_detail' => substr($detail, 0, 300),
            'provider_code' => isset($error['code']) ? substr(preg_replace('/[^A-Z0-9_-]/i', '', (string) $error['code']), 0, 80) : null,
            'provider_type' => isset($error['type']) ? substr(preg_replace('/[^A-Z0-9_-]/i', '', (string) $error['type']), 0, 80) : null,
        );
        error_log('[RunPlan Groq] provider request failed: ' . json_encode($diagnostic));
    }

    private function failure($status, $message)
    {
        return array('ok' => false, 'text' => null, 'provider_status' => $status, 'error' => $message);
    }
}