<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

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

    private function admin_id()
    {
        $id = $this->current_user_id();
        $this->require_admin($id);
        return $id;
    }

    private function input()
    {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $this->api->respond_error('Invalid JSON body.', 400);
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

        if (!in_array($category, $categories, true)) $errors['category'] = 'Choose a valid report category.';
        if ($subject === '' || mb_strlen($subject) > 160) $errors['subject'] = 'Enter a short title (up to 160 characters).';
        if ($description === '' || mb_strlen($description) > 4000) $errors['description'] = 'Describe the problem in up to 4000 characters.';
        if (mb_strlen($page_path) > 255) $page_path = mb_substr($page_path, 0, 255);
        if ($page_path !== '' && $page_path[0] !== '/') $page_path = '';
        $page_path = $page_path !== '' ? preg_replace('/[?#].*$/', '', $page_path) : null;

        if ($errors) $this->api->respond(['error' => 'Validation failed.', 'fields' => $errors], 422);

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
            if (!in_array($status, ['open', 'in_progress', 'resolved', 'closed'], true)) $errors['status'] = 'Choose a valid report status.';
            else $values['status'] = $status;
        }
        if (array_key_exists('priority', $input)) {
            $priority = (string) $input['priority'];
            if (!in_array($priority, ['low', 'normal', 'high'], true)) $errors['priority'] = 'Choose a valid priority.';
            else $values['priority'] = $priority;
        }
        if (array_key_exists('admin_note', $input)) {
            $note = trim((string) $input['admin_note']);
            if (mb_strlen($note) > 2000) $errors['admin_note'] = 'Keep the internal note under 2000 characters.';
            else $values['admin_note'] = $note === '' ? null : $note;
        }
        if (!$values) $errors['report'] = 'Provide a status, priority, or internal note to update.';
        if ($errors) $this->api->respond(['error' => 'Validation failed.', 'fields' => $errors], 422);

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

    public function runner($id = '')
    {
        $this->setup();
        $this->api->require_method('GET');
        $this->admin_id();
        if (!ctype_digit((string) $id)) $this->api->respond_error('Runner not found.', 404);
        $data = $this->SupportModel->runner_details((int) $id);
        if (!$data) $this->api->respond_error('Runner not found.', 404);
        header('Cache-Control: no-store');
        $this->api->respond(['data' => $data]);
    }

    public function update_runner_status($id = '')
    {
        $this->setup();
        $this->api->require_method('PATCH');
        $admin_id = $this->admin_id();
        if (!ctype_digit((string) $id) || !$this->SupportModel->find_runner((int) $id)) {
            $this->api->respond_error('Runner not found.', 404);
        }

        $status = (string) ($this->input()['status'] ?? '');
        if (!in_array($status, ['active', 'suspended'], true)) {
            $this->api->respond_error('Status must be active or suspended.', 422);
        }
        $target_id = (int) $id;
        $this->SupportModel->set_runner_status($target_id, $status);

        $this->call->model('SecurityEventModel');
        $this->SecurityEventModel->record(
            $status === 'suspended' ? 'admin_runner_suspended' : 'admin_runner_reactivated',
            $admin_id,
            ['target_user_id' => $target_id]
        );
        header('Cache-Control: no-store');
        $this->api->respond(['data' => ['id' => $target_id, 'status' => $status]]);
    }

    public function update_runner_password($id = '')
    {
        $this->setup();
        $this->api->require_method('PATCH');
        $admin_id = $this->admin_id();
        if (!ctype_digit((string) $id) || !$this->SupportModel->find_runner((int) $id)) {
            $this->api->respond_error('Runner not found.', 404);
        }

        $input = $this->input();
        $password = (string) ($input['new_password'] ?? '');
        $confirmation = (string) ($input['confirmation'] ?? '');
        if ($password !== $confirmation) $this->api->respond_error('Passwords do not match.', 422);
        if ($problem = UserModel::password_problem($password)) $this->api->respond_error($problem, 422);

        $target_id = (int) $id;
        $this->call->model('UserModel');
        $this->call->model('RefreshTokenModel');
        $this->call->model('SecurityEventModel');

        if (!$this->UserModel->set_password($target_id, $password)) {
            $this->api->respond_error('Could not update the password.', 500);
        }
        $revoked = $this->RefreshTokenModel->revoke_all_for_user($target_id);
        $this->call->database();
        $this->db->raw('DELETE FROM password_reset_tokens WHERE user_id = ?', [$target_id]);
        $this->SecurityEventModel->record('admin_runner_password_changed', $admin_id, [
            'target_user_id' => $target_id,
            'revoked_sessions' => (int) $revoked,
        ]);

        header('Cache-Control: no-store');
        $this->api->respond(['data' => ['updated' => true, 'revoked_sessions' => (int) $revoked]]);
    }

    public function backups()
    {
        $this->setup();
        $this->api->require_method('GET');
        $this->admin_id();
        header('Cache-Control: no-store');
        $this->api->respond(['data' => $this->SupportModel->list_backups()]);
    }

    public function create_backup()
    {
        $this->setup();
        $this->api->require_method('POST');
        $admin_id = $this->admin_id();
        try {
            $backup = $this->SupportModel->create_backup($admin_id);
        } catch (Throwable $e) {
            error_log('Support snapshot failed: ' . $e->getMessage());
            $status = strpos($e->getMessage(), '8 MB limit') !== false ? 413 : 500;
            $this->api->respond_error($status === 413 ? $e->getMessage() : 'Could not create the snapshot.', $status);
        }

        $this->call->model('SecurityEventModel');
        $this->SecurityEventModel->record('support_data_snapshot_created', $admin_id, [
            'snapshot_id' => (int) $backup['id'],
            'size_bytes' => (int) $backup['size_bytes'],
        ]);
        header('Cache-Control: no-store');
        $this->api->respond(['data' => $backup], 201);
    }

    public function download_backup($id = '')
    {
        $this->setup();
        $this->api->require_method('GET');
        $this->admin_id();
        if (!ctype_digit((string) $id)) $this->api->respond_error('Snapshot not found.', 404);
        $backup = $this->SupportModel->find_backup((int) $id);
        if (!$backup) $this->api->respond_error('Snapshot not found.', 404);
        header('Cache-Control: no-store');
        $this->api->respond(['data' => $backup]);
    }
}