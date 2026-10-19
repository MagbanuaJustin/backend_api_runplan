<?php

class Create_activities_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('activities')) {
            return;
        }

        $this->_lava->dbforge
            ->add_field([
                'id' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => TRUE,
                    'auto_increment' => TRUE,
                    'null'           => FALSE,
                ],
                'user_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'session_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => TRUE,
                    'default'    => NULL,
                ],
                'activity_date' => [
                    'type' => 'DATE',
                    'null' => FALSE,
                ],
                'distance_km' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '6,2',
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'duration_seconds' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'effort' => [
                    'type'       => 'TINYINT',
                    'constraint' => 2,
                    'unsigned'   => TRUE,
                    'null'       => TRUE,
                ],
                'notes' => [
                    'type' => 'TEXT',
                    'null' => TRUE,
                ],
                'source' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 20,
                    'null'       => FALSE,
                    'default'    => 'manual',
                ],
                'created_at' => [
                    'type'    => 'DATETIME',
                    'null'    => FALSE,
                    'default' => 'CURRENT_TIMESTAMP',
                ],
                'updated_at' => [
                    'type'    => 'DATETIME',
                    'null'    => TRUE,
                    'default' => NULL,
                ],
            ])
            ->add_key('id', primary: TRUE)
            ->add_key('user_id', name: 'user_id_idx')
            ->add_key('activity_date', name: 'activity_date_idx')
            ->add_key('session_id', name: 'session_id_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->add_foreign_key('session_id', 'training_sessions', 'id', 'SET NULL', 'CASCADE')
            ->create_table('activities');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('activities');
    }
}