<?php

class Create_training_plans_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('training_plans')) {
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
                'goal' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => TRUE,
                ],
                'start_date' => [
                    'type' => 'DATE',
                    'null' => FALSE,
                ],
                'target_race_date' => [
                    'type' => 'DATE',
                    'null' => TRUE,
                ],
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => "'draft','active','archived'",
                    'null'       => FALSE,
                    'default'    => 'draft',
                ],
                'version_number' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                    'default'    => 1,
                ],
                'approved_at' => [
                    'type'    => 'DATETIME',
                    'null'    => TRUE,
                    'default' => NULL,
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
            ->add_key('status', name: 'status_idx')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('training_plans');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('training_plans');
    }
}