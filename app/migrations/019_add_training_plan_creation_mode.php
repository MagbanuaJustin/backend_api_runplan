<?php

class Add_training_plan_creation_mode
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
        if (!$this->_lava->dbforge->column_exists('training_plans', 'creation_mode')) {
            $this->_lava->db->raw(
                "ALTER TABLE `training_plans`
                 ADD COLUMN `creation_mode` ENUM('generated','manual')
                 NOT NULL DEFAULT 'generated' AFTER `goal`"
            );
        }
    }

    public function down()
    {
        // Keep the creation mode so rolling back code cannot erase plan metadata.
    }
}
