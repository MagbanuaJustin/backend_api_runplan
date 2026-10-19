<?php

class Align_schema_with_approved_erd {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    private function run($sql)
    {
        $this->_lava->db->raw($sql);
    }

    private function has($table, $column)
    {
        return $this->_lava->dbforge->column_exists($table, $column);
    }

    private function rename($table, $old, $new)
    {
        if ($this->has($table, $old) && !$this->has($table, $new)) {
            $this->run("ALTER TABLE `$table` RENAME COLUMN `$old` TO `$new`");
            return true;
        }
        return false;
    }

    private function add($table, $column, $definition)
    {
        if (!$this->has($table, $column)) {
            $this->run("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    }

    public function up()
    {
        // ---- runner_profiles ----
        $this->rename('runner_profiles', 'full_name', 'display_name');
        $this->rename('runner_profiles', 'experience_level', 'experience');
        $this->add('runner_profiles', 'baseline_distance_km', 'DECIMAL(6,2) UNSIGNED NULL AFTER `preferred_time`');
        $this->add('runner_profiles', 'baseline_pace', 'INT UNSIGNED NULL AFTER `baseline_distance_km`'); // seconds per km

        // ---- training_plans ----
        $this->rename('training_plans', 'version_number', 'version');
        $this->rename('training_plans', 'target_race_date', 'end_date');
        $this->add('training_plans', 'sessions_per_week', 'TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `end_date`');

        // ---- training_sessions ----
        $this->rename('training_sessions', 'workout_type', 'type');
        if ($this->rename('training_sessions', 'planned_duration_min', 'planned_duration_seconds')) {
            $this->run('UPDATE `training_sessions` SET `planned_duration_seconds` = `planned_duration_seconds` * 60');
        }
        if (!$this->has('training_sessions', 'user_id')) {
            $this->run('ALTER TABLE `training_sessions` ADD COLUMN `user_id` INT UNSIGNED NULL AFTER `plan_id`');
            $this->run('UPDATE `training_sessions` s JOIN `training_plans` p ON p.id = s.plan_id SET s.user_id = p.user_id');
            $this->run('ALTER TABLE `training_sessions` MODIFY `user_id` INT UNSIGNED NOT NULL');
            $this->run('ALTER TABLE `training_sessions` ADD KEY `user_id_idx` (`user_id`)');
            $this->run('ALTER TABLE `training_sessions` ADD CONSTRAINT `training_sessions_user_fk`
                        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }

        // ---- refresh_tokens ----
        if ($this->has('refresh_tokens', 'token')) {
            $this->run('ALTER TABLE `refresh_tokens` RENAME COLUMN `token` TO `token_hash`');
            $this->run('ALTER TABLE `refresh_tokens` MODIFY `token_hash` CHAR(64) NOT NULL');
            $this->run('ALTER TABLE `refresh_tokens` ADD UNIQUE KEY `token_hash_unique` (`token_hash`)');
            $this->add('refresh_tokens', 'revoked_at', 'DATETIME NULL DEFAULT NULL AFTER `expires_at`');
            $this->run('DELETE FROM `refresh_tokens` WHERE `user_id` NOT IN (SELECT `id` FROM `users`)');
            $this->run('ALTER TABLE `refresh_tokens` ADD CONSTRAINT `refresh_tokens_user_fk`
                        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE');
        }
    }

    public function down()
    {
        $this->run('ALTER TABLE `refresh_tokens` DROP FOREIGN KEY `refresh_tokens_user_fk`');
        $this->run('ALTER TABLE `refresh_tokens` DROP INDEX `token_hash_unique`');
        $this->run('ALTER TABLE `refresh_tokens` DROP COLUMN `revoked_at`');
        $this->run('ALTER TABLE `refresh_tokens` MODIFY `token_hash` TEXT NOT NULL');
        $this->run('ALTER TABLE `refresh_tokens` RENAME COLUMN `token_hash` TO `token`');

        $this->run('ALTER TABLE `training_sessions` DROP FOREIGN KEY `training_sessions_user_fk`');
        $this->run('ALTER TABLE `training_sessions` DROP COLUMN `user_id`');
        $this->run('UPDATE `training_sessions` SET `planned_duration_seconds` = `planned_duration_seconds` DIV 60');
        $this->run('ALTER TABLE `training_sessions` RENAME COLUMN `planned_duration_seconds` TO `planned_duration_min`');
        $this->run('ALTER TABLE `training_sessions` RENAME COLUMN `type` TO `workout_type`');

        $this->run('ALTER TABLE `training_plans` DROP COLUMN `sessions_per_week`');
        $this->run('ALTER TABLE `training_plans` RENAME COLUMN `end_date` TO `target_race_date`');
        $this->run('ALTER TABLE `training_plans` RENAME COLUMN `version` TO `version_number`');

        $this->run('ALTER TABLE `runner_profiles` DROP COLUMN `baseline_pace`');
        $this->run('ALTER TABLE `runner_profiles` DROP COLUMN `baseline_distance_km`');
        $this->run('ALTER TABLE `runner_profiles` RENAME COLUMN `experience` TO `experience_level`');
        $this->run('ALTER TABLE `runner_profiles` RENAME COLUMN `display_name` TO `full_name`');
    }
}