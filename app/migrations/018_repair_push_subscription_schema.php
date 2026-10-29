<?php

class Repair_push_subscription_schema
{
    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('push_subscriptions')) {
            if (!$this->_lava->dbforge->column_exists('push_subscriptions', 'endpoint_hash')) {
                $this->_lava->db->raw(
                    'ALTER TABLE `push_subscriptions` ADD COLUMN `endpoint_hash` BINARY(32) NULL AFTER `endpoint`'
                );
            }

            $this->_lava->db->raw(
                'UPDATE `push_subscriptions`
                 SET `endpoint_hash` = UNHEX(SHA2(`endpoint`, 256))
                 WHERE `endpoint_hash` IS NULL'
            );

            $has_hash_index = (int) $this->_lava->db->raw(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = 'push_subscriptions'
                   AND index_name = 'endpoint_hash_unique'"
            )->fetchColumn() > 0;

            $has_old_index = (int) $this->_lava->db->raw(
                "SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = 'push_subscriptions'
                   AND index_name = 'endpoint_unique'"
            )->fetchColumn() > 0;

            if ($has_old_index) {
                $this->_lava->db->raw(
                    'ALTER TABLE `push_subscriptions` DROP INDEX `endpoint_unique`'
                );
            }

            $this->_lava->db->raw(
                'ALTER TABLE `push_subscriptions` MODIFY `endpoint` VARCHAR(2048) NOT NULL'
            );
            $this->_lava->db->raw(
                'ALTER TABLE `push_subscriptions` MODIFY `endpoint_hash` BINARY(32) NOT NULL'
            );

            if (!$has_hash_index) {
                $this->_lava->db->raw(
                    'ALTER TABLE `push_subscriptions`
                     ADD UNIQUE INDEX `endpoint_hash_unique` (`endpoint_hash`)'
                );
            }
        }

        if ($this->_lava->dbforge->table_exists('notification_jobs')) {
            if (!$this->_lava->dbforge->column_exists('notification_jobs', 'locked_at')) {
                $this->_lava->db->raw(
                    'ALTER TABLE `notification_jobs`
                     ADD COLUMN `locked_at` DATETIME NULL DEFAULT NULL AFTER `attempts`'
                );
            }

            $this->_lava->db->raw(
                "ALTER TABLE `notification_jobs`
                 MODIFY `status` ENUM('pending','processing','sent','failed','cancelled')
                 NOT NULL DEFAULT 'pending'"
            );
        }
    }

    public function down()
    {
        // Keep the endpoint hash and expanded endpoint to avoid data loss.
    }
}