<?php

class Create_support_reports
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
        if ($forge->table_exists('support_reports')) {
            return;
        }

        $forge->add_field([
            'id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => TRUE,
                'auto_increment' => TRUE,
                'null' => FALSE,
            ],
            'user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => TRUE,
                'null' => FALSE,
            ],
            'category' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => FALSE],
            'subject' => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => FALSE],
            'description' => ['type' => 'TEXT', 'null' => FALSE],
            'page_path' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => TRUE],
            'status' => [
                'type' => 'ENUM',
                'constraint' => "'open','in_progress','resolved','closed'",
                'null' => FALSE,
                'default' => 'open',
            ],
            'priority' => [
                'type' => 'ENUM',
                'constraint' => "'low','normal','high'",
                'null' => FALSE,
                'default' => 'normal',
            ],
            'admin_note' => ['type' => 'TEXT', 'null' => TRUE],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => FALSE,
                'default' => 'CURRENT_TIMESTAMP',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => TRUE,
                'default' => NULL,
            ],
        ])
            ->add_key('id', primary: TRUE)
            ->add_key(['user_id', 'created_at'], name: 'support_report_user_created_idx')
            ->add_key(['status', 'created_at'], name: 'support_report_status_created_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('support_reports');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('support_reports', TRUE);
    }
}