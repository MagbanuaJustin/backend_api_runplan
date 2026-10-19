<?php

class Create_audit_ai_and_cache_tables {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
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

        if (!$f->table_exists('auth_security_events')) {
            $f->add_field([
                'id'            => $this->id(),
                'user_id'       => $this->ref(TRUE),
                'event_type'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => FALSE],
                'safe_metadata' => ['type' => 'JSON', 'null' => TRUE],
                'created_at'    => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('user_id', name: 'user_id_idx')
            ->add_key(['event_type', 'created_at'], name: 'event_type_created_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'SET NULL', 'CASCADE')
            ->create_table('auth_security_events');
        }

        if (!$f->table_exists('plan_revisions')) {
            $f->add_field([
                'id'             => $this->id(),
                'plan_id'        => $this->ref(),
                'user_id'        => $this->ref(),
                'version'        => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
                'change_summary' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => FALSE],
                'snapshot_json'  => ['type' => 'JSON', 'null' => FALSE],
                'created_at'     => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['plan_id', 'version'], unique: TRUE, name: 'plan_version_unique')
            ->add_key('user_id', name: 'user_id_idx')
            ->add_foreign_key('plan_id', 'training_plans', 'id', 'CASCADE', 'CASCADE')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('plan_revisions');
        }

        if (!$f->table_exists('ai_plan_requests')) {
            $f->add_field([
                'id'              => $this->id(),
                'user_id'         => $this->ref(),
                'plan_id'         => $this->ref(TRUE),
                'provider'        => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE],
                'status'          => ['type' => 'ENUM', 'constraint' => "'pending','succeeded','failed','fallback'",
                                      'null' => FALSE, 'default' => 'pending'],
                'request_summary' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => TRUE],
                'created_at'      => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['user_id', 'created_at'], name: 'user_created_idx')
            ->add_key('plan_id', name: 'plan_id_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->add_foreign_key('plan_id', 'training_plans', 'id', 'SET NULL', 'CASCADE')
            ->create_table('ai_plan_requests');
        }

        if (!$f->table_exists('ai_feedback')) {
            $f->add_field([
                'id'               => $this->id(),
                'user_id'          => $this->ref(),
                'period_start'     => ['type' => 'DATE', 'null' => FALSE],
                'period_end'       => ['type' => 'DATE', 'null' => FALSE],
                'metrics_snapshot' => ['type' => 'JSON', 'null' => FALSE],
                'feedback_text'    => ['type' => 'TEXT', 'null' => TRUE],
                'provider_status'  => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE, 'default' => 'ok'],
                'created_at'       => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['user_id', 'period_start'], unique: TRUE, name: 'user_period_unique')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('ai_feedback');
        }

        if (!$f->table_exists('weather_cache')) {
            $f->add_field([
                'id'              => $this->id(),
                'location_key'    => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => FALSE],
                'forecast_date'   => ['type' => 'DATE', 'null' => FALSE],
                'weather_payload' => ['type' => 'JSON', 'null' => FALSE],
                'expires_at'      => ['type' => 'DATETIME', 'null' => FALSE],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['location_key', 'forecast_date'], unique: TRUE, name: 'location_date_unique')
            ->add_key('expires_at', name: 'expires_at_idx')
            ->create_table('weather_cache');
        }

        if (!$f->table_exists('settings')) {
            $f->add_field([
                'id'                  => $this->id(),
                'provider_config_key' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => FALSE],
                'enabled'             => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
                'updated_at'          => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('provider_config_key', unique: TRUE, name: 'provider_config_key_unique')
            ->create_table('settings');
        }
    }

    public function down()
    {
        foreach (['settings', 'weather_cache', 'ai_feedback', 'ai_plan_requests', 'plan_revisions', 'auth_security_events'] as $t) {
            $this->_lava->dbforge->drop_table($t);
        }
    }
}