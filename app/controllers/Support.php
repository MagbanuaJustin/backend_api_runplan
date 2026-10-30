<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/** Authenticated support endpoints. Admin-only data is checked against the DB role. */
class Support extends Controller
{
    private function setup()
    {
        $this->call->library('api');
        $this->call->database();
        $this->call->model('SupportModel');
    }

    private function current_user_id()
    {
        $payload = $this->api->require_jwt();
        return (int) ($payload['sub'] ?? 0);
    }

    private function require_admin($user_id)
    {
        if ($this->SupportModel->role_for_user($user_id) !== 'admin') {
            $this->api->respond_error('Administrator access is required.', 403);
        }
    }

    private function input()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $this->api->respond_error('Invalid JSON body.', 400);
        }
        return $input;
    }

    private function system_snapshot()
    {
        $this->call->helper('integration');
        return [
            'api' => ['status' => 'ok'],
            'database' => ['status' => 'connected'],
            'providers' => integration_public_config(),
            'core_enabled' => true,
            'checked_at' => gmdate('c'),
        ];
    }

    public function reports()
    {
        $this->setup();
        $this->api->require_method('GET');
        $user_id = $this->current_user_id();
        $is_admin = $this->SupportModel->role_for_user($user_id) === 'admin';
        $reports = $this->SupportModel->list_reports($user_id, $is_admin);

        if (!$is_admin) {
            foreach ($reports as &$report) unset($report['admin_note']);
            unset($report);
        }

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $reports]);
    }

    public function create_report()
    {
        $this->setup();
        $this->api->require_method('POST');
        $user_id = $this->current_user_id();
        $this->api->rate_limit('support_report_' . $user_id, 10, 3600);
        $input = $this->input();

        $categories = ['bug', 'run_tracking', 'calendar', 'training_plan', 'account', 'other'];
        $category = strtolower(trim((string) ($input['category'] ?? '')));
        $subject = trim((string) ($input['subject'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $page_path = trim((string) ($input['page_path'] ?? ''));
        $errors = [];

        if (!in_array($category, $categories, true)) {
            $errors['category'] = 'Choose a valid report category.';
        }
        if ($subject === '' || mb_strlen($subject) > 160) {
            $errors['subject'] = 'Enter a short title (up to 160 characters).';
        }
        if ($description === '' || mb_strlen($description) > 4000) {
            $errors['description'] = 'Describe the problem in up to 4000 characters.';
        }

        if (mb_strlen($page_path) > 255) {
            $page_path = mb_substr($page_path, 0, 255);
        }

        // Only store an app-relative path, never a query string that could contain tokens.
        if ($page_path !== '' && $page_path[0] !== '/') {
            $page_path = '';
        }
        $page_path = $page_path !== '' ? preg_replace('/[?#].*$/', '', $page_path) : null;

        if ($errors) {
            $this->api->respond(['error' => 'Validation failed.', 'fields' => $errors], 422);
        }

        $id = $this->SupportModel->create_report($user_id, [
            'category' => $category,
            'subject' => $subject,
            'description' => $description,
            'page_path' => $page_path,
        ]);

        header('Cache-Control: no-store');
        $this->api->respond(['data' => ['id' => $id, 'status' => 'open']], 201);
    }

    public function update_report($id = '')
    {
        $this->setup();
        $this->api->require_method('PATCH');
        $user_id = $this->current_user_id();
        $this->require_admin($user_id);

        if (!ctype_digit((string) $id) || !$this->SupportModel->find_report($id)) {
            $this->api->respond_error('Report not found.', 404);
        }

        $input = $this->input();
        $values = [];
        $errors = [];

        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) {
                $errors['status'] = 'Choose a valid report status.';
            } else {
                $values['status'] = $status;
            }
        }

        if (array_key_exists('priority', $input)) {
            $priority = (string) $input['priority'];
            if (!in_array($priority, ['low', 'normal', 'high'], true)) {
                $errors['priority'] = 'Choose a valid priority.';
            } else {
                $values['priority'] = $priority;
            }
        }

        if (array_key_exists('admin_note', $input)) {
            $note = trim((string) $input['admin_note']);
            if (mb_strlen($note) > 2000) {
                $errors['admin_note'] = 'Keep the internal note under 2000 characters.';
            } else {
                $values['admin_note'] = $note === '' ? null : $note;
            }
        }

        if (!$values) {
            $errors['report'] = 'Provide a status, priority, or internal note to update.';
        }
        if ($errors) {
            $this->api->respond(['error' => 'Validation failed.', 'fields' => $errors], 422);
        }

        $this->SupportModel->update_report((int) $id, $values);
        header('Cache-Control: no-store');
        $this->api->respond(['data' => ['updated' => true]]);
    }

    public function console()
    {
        $this->setup();
        $this->api->require_method('GET');
        $user_id = $this->current_user_id();
        $this->require_admin($user_id);

        $data = $this->SupportModel->console_data();
        $data['system'] = $this->system_snapshot();

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $data]);
    }

    public function system()
    {
        $this->setup();
        $this->api->require_method('GET');
        $user_id = $this->current_user_id();
        $this->require_admin($user_id);

        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->system_snapshot()]);
    }
}