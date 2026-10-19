<?php

class Create_notification_tables {

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

        if (!$f->table_exists('notification_preferences')) {
            $f->add_field([
                'id'           => $this->id(),
                'user_id'      => $this->ref(),
                'enabled'      => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
                'lead_minutes' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 60],
                'quiet_hours'  => ['type' => 'VARCHAR', 'constraint' => 11, 'null' => TRUE],
                'timezone'     => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => FALSE, 'default' => 'UTC'],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('user_id', unique: TRUE, name: 'user_id_unique')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('notification_preferences');
        }

        if (!$f->table_exists('push_subscriptions')) {
            $f->add_field([
                'id'           => $this->id(),
                'user_id'      => $this->ref(),
                'endpoint'     => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => FALSE],
                'key_material' => ['type' => 'TEXT', 'null' => FALSE],
                'created_at'   => $this->now(),
                'revoked_at'   => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('endpoint', unique: TRUE, name: 'endpoint_unique')
            ->add_key('user_id', name: 'user_id_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('push_subscriptions');
        }

        if (!$f->table_exists('notification_jobs')) {
            $f->add_field([
                'id'           => $this->id(),
                'user_id'      => $this->ref(),
                'session_id'   => $this->ref(),
                'scheduled_at' => ['type' => 'DATETIME', 'null' => FALSE],
                'status'       => ['type' => 'ENUM', 'constraint' => "'pending','sent','failed','cancelled'",
                                   'null' => FALSE, 'default' => 'pending'],
                'attempts'     => ['type' => 'TINYINT', 'constraint' => 3, 'unsigned' => TRUE, 'null' => FALSE, 'default' => 0],
                'sent_at'      => ['type' => 'DATETIME', 'null' => TRUE, 'default' => NULL],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('session_id', unique: TRUE, name: 'session_id_unique')
            ->add_key(['status', 'scheduled_at'], name: 'status_scheduled_idx')
            ->add_key('user_id', name: 'user_id_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->add_foreign_key('session_id', 'training_sessions', 'id', 'CASCADE', 'CASCADE')
            ->create_table('notification_jobs');
        }
    }

    public function down()
    {
        foreach (['notification_jobs', 'push_subscriptions', 'notification_preferences'] as $t) {
            $this->_lava->dbforge->drop_table($t);
        }
    }
}