<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: PerformanceMetrics
 * 
 * Automatically generated via CLI.
 */
class PerformanceMetrics extends Controller
{
    public function index()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('PerformanceMetricsModel');

        $this->api->require_method('GET');

        $payload = $this->api->require_jwt();
        $user_id = (int) $payload['sub'];

        $this->api->rate_limit('performance_metrics_' . $user_id, 60, 60);

        $range = (string) ($_GET['range'] ?? '30d');

        $allowed_ranges = ['7d', '30d', '90d', 'all'];

        if (!in_array($range, $allowed_ranges, true)) {
            $this->api->respond([
                'error' => 'Invalid date range.'
            ], 400);
            return;
        }

        header('Cache-Control: no-store');

        $data = $this->PerformanceMetricsModel->summary(
            $user_id,
            $range
        );

        $this->api->respond([
            'data' => $data
        ]);
    }
}