<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Library: AnthropicService
 *
 * Server-side connector for Anthropic Claude Messages API (Spec Sections 5.1,
 * Module 2, Module 3, TC-02, TC-03, TC-08, TC-10, TC-14, TC-15).
 *
 * Responsibilities & boundaries:
 *   1. Draft structured training-plan proposals using tool-constrained JSON
 *      output; the backend's deterministic PlanModel validator decides whether
 *      a proposal is safe to preview or falls back to the reviewed template.
 *   2. Explain backend-calculated pace, consistency, and missed-session metrics
 *      from eligible first-party (`manual`) RunPlan records only — never
 *      inventing statistics, never diagnosing medical conditions, and never
 *      accepting Strava-derived inputs.
 */
class AnthropicService {

    const PROMPT_VERSION = '1';

    /** @var HttpClient */
    private $http;

    /** @var array */
    private $cfg;

    public function __construct(?array $override_config = null, ?HttpClient $http = null)
    {
        if (!class_exists('HttpClient')) {
            require_once APP_DIR . 'libraries/HttpClient.php';
        }
        $this->cfg  = $override_config !== null ? $override_config : integration_config('anthropic');
        $timeout    = max(5, (int) ($this->cfg['timeout'] ?? 25));
        $this->http = $http ?: new HttpClient($timeout);
    }

    public function is_ready()
    {
        $enabled = filter_var($this->cfg['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $key     = trim((string) ($this->cfg['api_key'] ?? ''));
        return $enabled && $key !== '';
    }

    public function model_name()
    {
        return (string) ($this->cfg['model'] ?? 'claude-sonnet-4-5');
    }

    public function monthly_cap()
    {
        return max(1, (int) ($this->cfg['monthly_call_cap'] ?? 500));
    }

    /**
     * Request a structured training-plan proposal from Claude.
     *
     * Accepts ONLY sanitized planning constraints (no email, no display_name,
     * no third-party activity data).
     *
     * @param array $constraints
     * @return array ['ok' => true, 'proposal' => [...], 'model' => string]
     *            or ['ok' => false, 'category' => string, 'error' => string]
     */
    public function propose_plan(array $constraints)
    {
        if ($this->contains_restricted_source($constraints)) {
            return [
                'ok'       => false,
                'category' => 'restricted_source',
                'error'    => 'External restricted activity data cannot be sent to the AI service.',
            ];
        }

        if (!$this->is_ready()) {
            return [
                'ok'       => false,
                'category' => 'not_configured',
                'error'    => 'Anthropic AI plan drafting is not enabled on this server.',
            ];
        }

        $safe_payload = [
            'goal'                 => (string) ($constraints['goal'] ?? ''),
            'experience'           => (string) ($constraints['experience'] ?? ''),
            'start_date'           => (string) ($constraints['start_date'] ?? ''),
            'end_date'             => (string) ($constraints['end_date'] ?? ''),
            'available_days'       => array_values((array) ($constraints['available_days'] ?? [])),
            'sessions_per_week'    => (int) ($constraints['sessions_per_week'] ?? 3),
            'preferred_time'       => $constraints['preferred_time'] ?? null,
            'baseline_distance_km' => $constraints['baseline_distance_km'] ?? null,
            'baseline_pace_sec_km' => $constraints['baseline_pace'] ?? null,
            'peak_long_cap_km'     => (float) ($constraints['peak_long_cap_km'] ?? 32.0),
            'race_distance_km'     => $constraints['race_distance_km'] ?? null,
        ];

        $tool = [
            'name'         => 'propose_training_plan',
            'description'  => 'Return a structured running schedule strictly within the supplied dates, allowed training days, workout types, and distance caps.',
            'input_schema' => [
                'type'       => 'object',
                'required'   => ['plan_name', 'sessions'],
                'properties' => [
                    'plan_name' => [
                        'type'        => 'string',
                        'description' => 'Concise descriptive title for the training plan (max 100 chars).',
                    ],
                    'sessions' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'required'   => ['scheduled_date', 'type', 'planned_distance_km', 'planned_duration_seconds', 'notes'],
                            'properties' => [
                                'scheduled_date'           => ['type' => 'string', 'description' => 'YYYY-MM-DD within [start_date, end_date].'],
                                'type'                     => ['type' => 'string', 'enum' => ['easy', 'tempo', 'interval', 'long', 'recovery', 'race']],
                                'planned_distance_km'      => ['type' => 'number'],
                                'planned_duration_seconds' => ['type' => 'integer'],
                                'notes'                    => ['type' => 'string'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $system = 'You are an academic running-schedule assistant for RunPlan AI. '
            . 'Produce a cautious, periodized running plan (base -> build with periodic cutback weeks -> taper) '
            . 'strictly obeying the JSON tool schema and constraints: '
            . '1) Place non-race sessions ONLY on available_days and at most sessions_per_week per week. '
            . '2) Never place a workout on the day immediately before end_date when race_distance_km is set, and place type="race" on end_date with exact race_distance_km. '
            . '3) Keep every distance >= 1.0 km and <= peak_long_cap_km (except marathon race day 42.2 km), with realistic paces between 180 and 840 seconds/km. '
            . '4) Do not give medical advice or claim outcome guarantees.';

        $res = $this->call_messages(
            $system,
            'Draft a structured training plan for these validated runner constraints: ' . json_encode($safe_payload, JSON_UNESCAPED_SLASHES),
            $tool,
            max(1024, (int) ($this->cfg['max_tokens'] ?? 3000))
        );

        if (!$res['ok']) {
            return $res;
        }

        $input = $res['tool_input'];
        if (empty($input['sessions']) || !is_array($input['sessions'])) {
            return [
                'ok'       => false,
                'category' => 'schema_invalid',
                'error'    => 'AI plan response omitted the sessions list.',
            ];
        }

        $plan_name = trim((string) ($input['plan_name'] ?? ''));
        if ($plan_name === '') {
            $plan_name = ucfirst(str_replace('_', ' ', $safe_payload['goal'])) . ' Training Plan';
        }

        return [
            'ok'       => true,
            'model'    => $res['model'],
            'proposal' => [
                'plan_name' => mb_substr($plan_name, 0, 120),
                'sessions'  => $input['sessions'],
            ],
        ];
    }

    /**
     * Request cautious AI training feedback explaining backend-calculated
     * first-party RunPlan metrics (Spec Module 3 & Section 5.1, TC-14, TC-15).
     *
     * @param array $snapshot Output of DashboardModel::coaching_snapshot()
     * @return array ['ok' => true, 'feedback' => [...], 'model' => string, 'prompt_version' => string]
     *            or ['ok' => false, 'category' => string, 'error' => string]
     */
    public function explain_training_metrics(array $snapshot)
    {
        // Hard boundary check (Spec 5.5, TC-10, TC-15): reject non-manual or tainted snapshots.
        if (($snapshot['eligible_source'] ?? '') !== 'manual' || $this->contains_restricted_source($snapshot)) {
            return [
                'ok'       => false,
                'category' => 'restricted_source',
                'error'    => 'Only first-party manual RunPlan records are eligible for AI coaching feedback.',
            ];
        }

        if (!$this->is_ready()) {
            return [
                'ok'       => false,
                'category' => 'not_configured',
                'error'    => 'AI coaching feedback is not enabled on this server.',
            ];
        }

        $tool = [
            'name'         => 'training_feedback',
            'description'  => 'Explain the supplied backend-calculated running metrics clearly and cautiously without inventing numbers.',
            'input_schema' => [
                'type'       => 'object',
                'required'   => [
                    'headline',
                    'pace_observation',
                    'consistency_observation',
                    'missed_sessions_observation',
                    'cautious_takeaway',
                ],
                'properties' => [
                    'headline'                    => ['type' => 'string'],
                    'pace_observation'            => ['type' => 'string'],
                    'consistency_observation'     => ['type' => 'string'],
                    'missed_sessions_observation' => ['type' => 'string'],
                    'cautious_takeaway'           => ['type' => 'string'],
                ],
            ],
        ];

        $system = 'You are RunPlan AI\'s informational training feedback assistant. '
            . 'STRICT RULES: '
            . '1) Treat the supplied JSON numbers as the sole source of truth. NEVER calculate new totals or invent distances, durations, paces, or session counts not in the input. '
            . '2) If pace_comparison.trend is "insufficient_data" or totals.runs is 0, explicitly state that there are not enough logged runs yet to infer a pace trend. '
            . '3) Explain scheduled vs completed adherence, note missed sessions, and mention how skipped sessions are treated per adherence.skipped_treatment. '
            . '4) Keep guidance general and cautious. NEVER diagnose injuries/illness, NEVER prescribe medical treatment, and NEVER guarantee race outcomes.';

        $res = $this->call_messages(
            $system,
            'Explain this runner\'s first-party RunPlan metric summary: ' . json_encode($snapshot, JSON_UNESCAPED_SLASHES),
            $tool,
            1024
        );

        if (!$res['ok']) {
            return $res;
        }

        $validated = self::validate_feedback_payload($res['tool_input']);
        if ($validated === null) {
            return [
                'ok'       => false,
                'category' => 'schema_invalid',
                'error'    => 'AI feedback response failed safety or schema validation.',
            ];
        }

        return [
            'ok'             => true,
            'model'          => $res['model'],
            'prompt_version' => self::PROMPT_VERSION,
            'feedback'       => $validated,
        ];
    }

    /**
     * Validate structured feedback fields returned by Claude.
     */
    public static function validate_feedback_payload($input)
    {
        if (!is_array($input)) {
            return null;
        }

        $fields = [
            'headline'                    => 160,
            'pace_observation'            => 450,
            'consistency_observation'     => 450,
            'missed_sessions_observation' => 450,
            'cautious_takeaway'           => 400,
        ];

        // Disallow medical diagnosis / guaranteed race-outcome claims (Spec 2.6, 4)
        $forbidden_phrases = '/\b(diagnosed?\s+with|medical\s+diagnosis|guaranteed?\s+to\s+finish|guaranteed?\s+pr)\b/i';

        $clean = [];
        foreach ($fields as $key => $max_len) {
            if (!isset($input[$key]) || !is_string($input[$key])) {
                return null;
            }
            $val = trim(preg_replace('/\s+/u', ' ', $input[$key]));
            if (mb_strlen($val) < 5 || mb_strlen($val) > $max_len) {
                return null;
            }
            if (preg_match($forbidden_phrases, $val)) {
                return null;
            }
            $clean[$key] = $val;
        }

        return $clean;
    }

    private function contains_restricted_source(array $payload)
    {
        $encoded = strtolower((string) json_encode($payload));
        return strpos($encoded, '"strava"') !== false || strpos($encoded, '"source":"strava"') !== false;
    }

    private function call_messages($system_prompt, $user_message, array $tool, $max_tokens)
    {
        $base = rtrim((string) ($this->cfg['base_uri'] ?? 'https://api.anthropic.com'), '/');
        $key  = trim((string) ($this->cfg['api_key'] ?? ''));
        $ver  = (string) ($this->cfg['api_version'] ?? '2023-06-01');
        $mdl  = $this->model_name();

        $res = $this->http->post_json($base . '/v1/messages', [
            'model'       => $mdl,
            'max_tokens'  => $max_tokens,
            'system'      => $system_prompt,
            'tools'       => [$tool],
            'tool_choice' => ['type' => 'tool', 'name' => $tool['name']],
            'messages'    => [
                ['role' => 'user', 'content' => $user_message],
            ],
        ], [
            'headers' => [
                'x-api-key: ' . $key,
                'anthropic-version: ' . $ver,
            ],
        ]);

        if (!$res['ok'] || !is_array($res['data'])) {
            $category = ($res['error_code'] ?? '') === 'timeout' ? 'timeout' : 'provider_error';
            return [
                'ok'       => false,
                'category' => $category,
                'error'    => 'The AI coaching service is temporarily unavailable.',
            ];
        }

        $blocks = $res['data']['content'] ?? [];
        if (!is_array($blocks)) {
            return [
                'ok'       => false,
                'category' => 'schema_invalid',
                'error'    => 'The AI coaching service returned an unreadable response.',
            ];
        }

        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_use'
                && ($block['name'] ?? '') === $tool['name']
                && isset($block['input']) && is_array($block['input'])) {
                return [
                    'ok'         => true,
                    'model'      => (string) ($res['data']['model'] ?? $mdl),
                    'tool_input' => $block['input'],
                ];
            }
        }

        return [
            'ok'       => false,
            'category' => 'schema_invalid',
            'error'    => 'The AI service did not return structured data.',
        ];
    }
}
