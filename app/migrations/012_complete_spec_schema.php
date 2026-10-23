<?php

/**
 * Migration 012: Complete schema additions required by Project Specification v1.1
 *
 * Adds additive columns and two account-lifecycle tables (`password_reset_tokens`
 * and `account_deletion_requests`) so existing `runplandb` data is preserved
 * without breaking any existing endpoint.
 */
class Complete_spec_schema {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    private function run($sql, array $params = [])
    {
        return $this->_lava->db->raw($sql, $params);
    }

    private function has($table, $column)
    {
        return $this->_lava->dbforge->column_exists($table, $column);
    }

    private function add($table, $column, $definition)
    {
        if (!$this->has($table, $column)) {
            $this->run("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function has_index($table, $index_name)
    {
        $row = $this->run(
            'SELECT COUNT(*) AS c FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index_name]
        )->fetch(PDO::FETCH_ASSOC);

        return ((int) ($row['c'] ?? 0)) > 0;
    }

    private function id()
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'auto_increment' => TRUE, 'null' => FALSE];
    }

    private function ref($null = FALSE)
    {
        return ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => $null];
    }

    private function now()
    {
        return ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'];
    }

    public function up()
    {
        $f = $this->_lava->dbforge;

        // 1. runner_profiles: desired sessions/week + optional weather location
        $this->add('runner_profiles', 'desired_sessions_per_week', 'TINYINT UNSIGNED NULL DEFAULT NULL AFTER `preferred_time`');
        $this->add('runner_profiles', 'location_name', 'VARCHAR(120) NULL DEFAULT NULL AFTER `baseline_pace`');
        $this->add('runner_profiles', 'location_lat', 'DECIMAL(8,5) NULL DEFAULT NULL AFTER `location_name`');
        $this->add('runner_profiles', 'location_lon', 'DECIMAL(8,5) NULL DEFAULT NULL AFTER `location_lat`');

        // 2. training_plans: optional plan name + AI/template provenance
        $this->add('training_plans', 'plan_name', 'VARCHAR(120) NULL DEFAULT NULL AFTER `goal`');
        $this->add('training_plans', 'generation_source', "ENUM('template','anthropic','anthropic_fallback') NOT NULL DEFAULT 'template' AFTER `version`");

        // 3. training_sessions: preferred start time (for Calendar & reminders) + skip reason
        $this->add('training_sessions', 'planned_start_time', 'TIME NULL DEFAULT NULL AFTER `scheduled_date`');
        $this->add('training_sessions', 'skip_reason', 'VARCHAR(255) NULL DEFAULT NULL AFTER `status`');

        // 4. plan_revisions: support pending/accepted/rejected proposals per Spec Module 4 & Section 8
        if ($this->has_index('plan_revisions', 'plan_version_unique')) {
            if (!$this->has_index('plan_revisions', 'plan_version_idx')) {
                $this->run('ALTER TABLE `plan_revisions` ADD INDEX `plan_version_idx` (`plan_id`, `version`)');
            }
            $this->run('ALTER TABLE `plan_revisions` DROP INDEX `plan_version_unique`');
        }
        $this->add('plan_revisions', 'previous_version', 'INT UNSIGNED NULL DEFAULT NULL AFTER `version`');
        $this->add('plan_revisions', 'resulting_version', 'INT UNSIGNED NULL DEFAULT NULL AFTER `previous_version`');
        $this->add('plan_revisions', 'decision', "ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'accepted' AFTER `change_summary`");
        $this->add('plan_revisions', 'decision_timestamp', 'DATETIME NULL DEFAULT NULL AFTER `decision`');
        $this->add('plan_revisions', 'reason', 'VARCHAR(255) NULL DEFAULT NULL AFTER `decision_timestamp`');
        $this->add('plan_revisions', 'proposed_changes', 'JSON NULL AFTER `snapshot_json`');

        $this->run(
            'UPDATE `plan_revisions`
                SET `previous_version` = GREATEST(1, `version` - 1),
                    `resulting_version` = `version`,
                    `decision_timestamp` = `created_at`
              WHERE `resulting_version` IS NULL AND `decision` = \'accepted\''
        );

        // 5. user_identities: Google identity metadata (never raw ID tokens)
        $this->add('user_identities', 'provider_email', 'VARCHAR(255) NULL DEFAULT NULL AFTER `provider_subject`');
        $this->add('user_identities', 'email_verified', 'TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 AFTER `provider_email`');
        $this->add('user_identities', 'last_used_at', 'DATETIME NULL DEFAULT NULL AFTER `created_at`');

        // 6. integration_accounts: connection lifecycle + expiry + safe error state
        $this->add('integration_accounts', 'connection_status', "ENUM('connected','revoked','error','disconnected') NOT NULL DEFAULT 'connected' AFTER `provider`");
        $this->add('integration_accounts', 'expires_at', 'DATETIME NULL DEFAULT NULL AFTER `scopes`');
        $this->add('integration_accounts', 'last_error', 'VARCHAR(255) NULL DEFAULT NULL AFTER `expires_at`');
        $this->add('integration_accounts', 'updated_at', 'DATETIME NULL DEFAULT NULL AFTER `connected_at`');

        // 7. calendar_event_mappings: sync diagnostics
        $this->add('calendar_event_mappings', 'last_synced_at', 'DATETIME NULL DEFAULT NULL AFTER `sync_status`');
        $this->add('calendar_event_mappings', 'error_code', 'VARCHAR(80) NULL DEFAULT NULL AFTER `last_synced_at`');

        // 8. push_subscriptions & notification_jobs
        $this->add('push_subscriptions', 'driver', "VARCHAR(20) NOT NULL DEFAULT 'vapid' AFTER `user_id`");
        $this->add('push_subscriptions', 'user_agent', 'VARCHAR(255) NULL DEFAULT NULL AFTER `key_material`');
        $this->add('push_subscriptions', 'last_seen_at', 'DATETIME NULL DEFAULT NULL AFTER `created_at`');
        $this->add('notification_jobs', 'delivery_result', 'VARCHAR(255) NULL DEFAULT NULL AFTER `attempts`');

        // 9. ai_plan_requests & ai_feedback metadata
        $this->add('ai_plan_requests', 'schema_version', "VARCHAR(16) NOT NULL DEFAULT '1' AFTER `status`");
        $this->add('ai_plan_requests', 'model_name', 'VARCHAR(80) NULL DEFAULT NULL AFTER `schema_version`');
        $this->add('ai_plan_requests', 'error_category', 'VARCHAR(60) NULL DEFAULT NULL AFTER `model_name`');
        $this->add('ai_feedback', 'model_name', 'VARCHAR(80) NULL DEFAULT NULL AFTER `provider_status`');
        $this->add('ai_feedback', 'prompt_version', "VARCHAR(16) NOT NULL DEFAULT '1' AFTER `model_name`");

        // 10. password_reset_tokens (FR-01 password recovery; stores hash only)
        if (!$f->table_exists('password_reset_tokens')) {
            $f->add_field([
                'id'         => $this->id(),
                'user_id'    => $this->ref(),
                'token_hash' => ['type' => 'CHAR', 'constraint' => 64, 'null' => FALSE],
                'expires_at' => ['type' => 'DATETIME', 'null' => FALSE],
                'used_at'    => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
                'created_at' => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('token_hash', unique: TRUE, name: 'reset_token_hash_unique')
            ->add_key(['user_id', 'expires_at'], name: 'user_expires_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('password_reset_tokens');
        }

        // 11. account_deletion_requests (FR-01 / Section 9.1 privacy deletion audit)
        if (!$f->table_exists('account_deletion_requests')) {
            $f->add_field([
                'id'                       => $this->id(),
                'user_id'                  => $this->ref(TRUE),
                'status'                   => ['type' => 'ENUM', 'constraint' => "'completed','failed'", 'null' => FALSE, 'default' => 'completed'],
                'provider_cleanup_summary' => ['type' => 'JSON', 'null' => TRUE],
                'requested_at'             => $this->now(),
                'completed_at'             => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('requested_at', name: 'requested_at_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'SET NULL', 'CASCADE')
            ->create_table('account_deletion_requests');
        }
    }

    public function down()
    {
        $f = $this->_lava->dbforge;
        foreach (['account_deletion_requests', 'password_reset_tokens'] as $table) {
            if ($f->table_exists($table)) {
                $f->drop_table($table);
            }
        }

        $drops = [
            ['ai_feedback', 'prompt_version'],
            ['ai_feedback', 'model_name'],
            ['ai_plan_requests', 'error_category'],
            ['ai_plan_requests', 'model_name'],
            ['ai_plan_requests', 'schema_version'],
            ['notification_jobs', 'delivery_result'],
            ['push_subscriptions', 'last_seen_at'],
            ['push_subscriptions', 'user_agent'],
            ['push_subscriptions', 'driver'],
            ['calendar_event_mappings', 'error_code'],
            ['calendar_event_mappings', 'last_synced_at'],
            ['integration_accounts', 'updated_at'],
            ['integration_accounts', 'last_error'],
            ['integration_accounts', 'expires_at'],
            ['integration_accounts', 'connection_status'],
            ['user_identities', 'last_used_at'],
            ['user_identities', 'email_verified'],
            ['user_identities', 'provider_email'],
            ['plan_revisions', 'proposed_changes'],
            ['plan_revisions', 'reason'],
            ['plan_revisions', 'decision_timestamp'],
            ['plan_revisions', 'decision'],
            ['plan_revisions', 'resulting_version'],
            ['plan_revisions', 'previous_version'],
            ['training_sessions', 'skip_reason'],
            ['training_sessions', 'planned_start_time'],
            ['training_plans', 'generation_source'],
            ['training_plans', 'plan_name'],
            ['runner_profiles', 'location_lon'],
            ['runner_profiles', 'location_lat'],
            ['runner_profiles', 'location_name'],
            ['runner_profiles', 'desired_sessions_per_week'],
        ];

        foreach ($drops as [$table, $col]) {
            if ($this->has($table, $col)) {
                $this->run("ALTER TABLE `{$table}` DROP COLUMN `{$col}`");
            }
        }
    }
}
