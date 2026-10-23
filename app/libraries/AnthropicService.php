<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once __DIR__ . '/HttpClient.php';

/**
 * AnthropicService
 *
 * Server-side Anthropic Messages API client built strictly atop `HttpClient`.
 * Enforces RunPlan's privacy boundary (Spec 5.1):
 *   - API key never leaves the server.
 *   - Only sanitized, non-identifying first-party training metrics are sent.
 *   - Never throws; returns a structured status so `AiCoachingModel` can
 *     cleanly fall back to deterministic coaching when Anthropic is disabled,
 *     over cap, or unreachable.
 */
class AnthropicService {

    /** @var array */
    private $config;

    /** @var HttpClient */
    private $http;

    public function __construct(?array $config = null, ?HttpClient $http = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } elseif (function_exists('integration_config')) {
            $this->config = integration_config('anthropic');
        } else {
            $this->config = array();
        }

        $timeout    = max(3, (int) ($this->config['timeout'] ?? 25));
        $this->http = $http ?: new HttpClient($timeout);
    }

    public function is_ready()
    {
        $enabled = filter_var($this->config['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $key     = trim((string) ($this->config['api_key'] ?? ''));
        return $enabled === true && $key !== '';
    }

    public function monthly_cap()
    {
        return max(1, (int) ($this->config['monthly_call_cap'] ?? 500));
    }

    public function model_name()
    {
        return (string) ($this->config['model'] ?? 'claude-sonnet-4-5');
    }

    /**
     * Request concise weekly running coaching feedback from Claude using ONLY
     * sanitized first-party metrics.
     *
     * @param array $metrics Whitelisted summary metrics (never PII or non-manual runs)
     * @return array {ok: bool, text: ?string, provider_status: string, error: ?string}
     */
    public function generate_weekly_feedback(array $metrics)
    {
        if (!$this->is_ready()) {
            return array(
                'ok'              => false,
                'text'            => null,
                'provider_status' => 'not_configured',
                'error'           => 'Anthropic Claude is disabled or not configured.',
            );
        }

        $safe_payload = $this->sanitize_metrics($metrics);

        $system = 'You are an encouraging, safety-first distance running coach inside RunPlan. '
            . 'Write 3 to 5 concise plain-text sentences reviewing the runner\'s past 7 days based strictly '
            . 'on the provided JSON metrics, followed by 2 short actionable focus points for next week. '
            . 'Do not invent runs that are not in the data, do not use markdown headings, and do not give medical diagnoses.';

        $user_prompt = "Weekly first-party training summary (JSON):\n"
            . json_encode($safe_payload, JSON_UNESCAPED_SLASHES);

        return $this->send_message($system, $user_prompt, 450);
    }

    /**
     * Request a short personalized plan overview note when generating a draft
     * plan. Distances and dates remain 100% governed by `PlanModel::build()`.
     *
     * @param array $plan_summary Whitelisted plan + profile summary
     * @return array {ok: bool, text: ?string, provider_status: string, error: ?string}
     */
    public function generate_plan_brief(array $plan_summary)
    {
        if (!$this->is_ready()) {
            return array(
                'ok'              => false,
                'text'            => null,
                'provider_status' => 'not_configured',
                'error'           => 'Anthropic Claude is disabled or not configured.',
            );
        }

        $allowed_keys = array(
            'goal', 'experience', 'start_date', 'end_date',
            'weeks', 'sessions_per_week', 'total_km', 'peak_long_km',
        );
        $safe = array();
        foreach ($allowed_keys as $k) {
            if (array_key_exists($k, $plan_summary) && (is_scalar($plan_summary[$k]) || $plan_summary[$k] === null)) {
                $safe[$k] = $plan_summary[$k];
            }
        }

        $system = 'You are a supportive distance running coach inside RunPlan. '
            . 'Write a 2 to 3 sentence plain-text coaching overview for this newly generated training schedule. '
            . 'Do not alter distances or dates and do not provide medical advice.';

        $user_prompt = "Draft training block summary (JSON):\n"
            . json_encode($safe, JSON_UNESCAPED_SLASHES);

        return $this->send_message($system, $user_prompt, 260);
    }

    /**
     * Strips anything outside the explicitly allowed non-PII training fields.
     */
    public function sanitize_metrics(array $metrics)
    {
        $allowed = array(
            'period_start', 'period_end', 'goal', 'experience',
            'runs_logged', 'distance_km', 'duration_seconds',
            'avg_effort', 'longest_run_km',
            'sessions_completed', 'sessions_planned', 'sessions_skipped', 'sessions_missed',
        );

        $clean = array();
        foreach ($allowed as $key) {
            if (array_key_exists($key, $metrics) && (is_scalar($metrics[$key]) || $metrics[$key] === null)) {
                $clean[$key] = $metrics[$key];
            }
        }
        return $clean;
    }

    private function send_message($system_prompt, $user_prompt, $max_tokens_cap)
    {
        $base_uri    = rtrim((string) ($this->config['base_uri'] ?? 'https://api.anthropic.com'), '/');
        $api_key     = trim((string) ($this->config['api_key'] ?? ''));
        $api_version = (string) ($this->config['api_version'] ?? '2023-06-01');
        $max_tokens  = min((int) $max_tokens_cap, max(128, (int) ($this->config['max_tokens'] ?? 3000)));
        $timeout     = max(3, (int) ($this->config['timeout'] ?? 25));

        $res = $this->http->post_json(
            $base_uri . '/v1/messages',
            array(
                'model'      => $this->model_name(),
                'max_tokens' => $max_tokens,
                'system'     => $system_prompt,
                'messages'   => array(
                    array('role' => 'user', 'content' => $user_prompt),
                ),
            ),
            array(
                'timeout' => $timeout,
                'headers' => array(
                    'x-api-key: ' . $api_key,
                    'anthropic-version: ' . $api_version,
                ),
            )
        );

        if (!$res['ok'] || !is_array($res['data'])) {
            return array(
                'ok'              => false,
                'text'            => null,
                'provider_status' => 'fallback',
                'error'           => $res['error'] ?? 'Anthropic request failed.',
            );
        }

        $text = $this->extract_text($res['data']);
        if ($text === '') {
            return array(
                'ok'              => false,
                'text'            => null,
                'provider_status' => 'fallback',
                'error'           => 'Anthropic returned an empty coaching message.',
            );
        }

        return array(
            'ok'              => true,
            'text'            => $text,
            'provider_status' => 'ok',
            'error'           => null,
        );
    }

    private function extract_text(array $data)
    {
        if (empty($data['content']) || !is_array($data['content'])) {
            return '';
        }

        $parts = array();
        foreach ($data['content'] as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text']) && is_string($block['text'])) {
                $trimmed = trim($block['text']);
                if ($trimmed !== '') {
                    $parts[] = $trimmed;
                }
            }
        }

        return mb_substr(implode("\n\n", $parts), 0, 2000);
    }
}
