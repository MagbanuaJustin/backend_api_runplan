<?php

class Create_support_data_backups
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
        $forge = $this->_lava->dbforge;
        if ($forge->table_exists('support_data_backups')) return;

        $forge->add_field([
            'id' => [
                'type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE,
                'auto_increment' => TRUE, 'null' => FALSE,
            ],
            'created_by' => [
                'type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => TRUE,
            ],
            'filename' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => FALSE],
            'size_bytes' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => TRUE, 'null' => FALSE],
            'snapshot_json' => ['type' => 'LONGTEXT', 'null' => FALSE],
            'created_at' => ['type' => 'DATETIME', 'null' => FALSE, 'default' => 'CURRENT_TIMESTAMP'],
        ])
            ->add_key('id', primary: TRUE)
            ->add_key('created_at', name: 'support_backup_created_idx')
            ->add_foreign_key('created_by', 'users', 'id', 'SET NULL', 'CASCADE')
            ->create_table('support_data_backups');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('support_data_backups', TRUE);
    }
}