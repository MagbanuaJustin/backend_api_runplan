<?php

class Create_identity_and_integration_tables {

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

        if (!$f->table_exists('user_identities')) {
            $f->add_field([
                'id'               => $this->id(),
                'user_id'          => $this->ref(),
                'provider'         => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE],
                'provider_subject' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => FALSE],
                'created_at'       => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['provider', 'provider_subject'], unique: TRUE, name: 'provider_subject_unique')
            ->add_key(['user_id', 'provider'], unique: TRUE, name: 'user_provider_unique')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('user_identities');
        }

        if (!$f->table_exists('integration_accounts')) {
            $f->add_field([
                'id'               => $this->id(),
                'user_id'          => $this->ref(),
                'provider'         => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE],
                'encrypted_tokens' => ['type' => 'TEXT', 'null' => FALSE],
                'scopes'           => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => TRUE],
                'connected_at'     => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key(['user_id', 'provider'], unique: TRUE, name: 'user_provider_unique')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('integration_accounts');
        }

        if (!$f->table_exists('calendar_event_mappings')) {
            $f->add_field([
                'id'                => $this->id(),
                'user_id'           => $this->ref(),
                'session_id'        => $this->ref(),
                'provider_event_id' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE],
                'sync_status'       => ['type' => 'ENUM', 'constraint' => "'pending','synced','failed'",
                                        'null' => FALSE, 'default' => 'pending'],
                'updated_at'        => $this->now(),
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('session_id', unique: TRUE, name: 'session_id_unique')
            ->add_key('user_id', name: 'user_id_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->add_foreign_key('session_id', 'training_sessions', 'id', 'CASCADE', 'CASCADE')
            ->create_table('calendar_event_mappings');
        }
    }

    public function down()
    {
        foreach (['calendar_event_mappings', 'integration_accounts', 'user_identities'] as $t) {
            $this->_lava->dbforge->drop_table($t);
        }
    }
}