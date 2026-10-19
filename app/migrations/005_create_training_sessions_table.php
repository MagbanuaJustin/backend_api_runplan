<?php

class Create_training_sessions_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('training_sessions')) {
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
                'plan_id' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => FALSE,
                ],
                'scheduled_date' => [
                    'type' => 'DATE',
                    'null' => FALSE,
                ],
                'workout_type' => [
                    'type'       => 'ENUM',
                    'constraint' => "'easy','tempo','interval','long','recovery','rest'",
                    'null'       => FALSE,
                    'default'    => 'easy',
                ],
                'planned_distance_km' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '5,2',
                    'unsigned'   => TRUE,
                    'null'       => TRUE,
                ],
                'planned_duration_min' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => TRUE,
                    'null'       => TRUE,
                ],
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => "'planned','completed','skipped','missed'",
                    'null'       => FALSE,
                    'default'    => 'planned',
                ],
                'notes' => [
                    'type' => 'TEXT',
                    'null' => TRUE,
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
            ->add_key('plan_id', name: 'plan_id_idx')
            ->add_key('scheduled_date', name: 'scheduled_date_idx')
            ->add_foreign_key('plan_id', 'training_plans', 'id', 'CASCADE', 'CASCADE')
            ->create_table('training_sessions');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('training_sessions');
    }
}