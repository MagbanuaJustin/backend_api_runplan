<?php

class Create_runner_profiles_table {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->dbforge();
    }

    public function up()
    {
        if ($this->_lava->dbforge->table_exists('runner_profiles')) {
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
                'full_name' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => FALSE,
                ],
                'experience_level' => [
                    'type'       => 'ENUM',
                    'constraint' => "'beginner','intermediate','advanced'",
                    'null'       => FALSE,
                    'default'    => 'beginner',
                ],
                'goal' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => TRUE,
                ],
                'target_race_date' => [
                    'type' => 'DATE',
                    'null' => TRUE,
                ],
                'available_days' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 30,
                    'null'       => TRUE,
                ],
                'preferred_time' => [
                    'type'       => 'ENUM',
                    'constraint' => "'morning','afternoon','evening'",
                    'null'       => TRUE,
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
            ->add_key('user_id', unique: TRUE, name: 'user_id_unique')
            ->add_foreign_key('user_id', 'users', 'id', 'CASCADE', 'CASCADE')
            ->create_table('runner_profiles');
    }

    public function down()
    {
        $this->_lava->dbforge->drop_table('runner_profiles');
    }
}