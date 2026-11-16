<?php

class Add_workout_type_to_activities
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
        $db = $this->_lava->db;

        if (!$this->_lava->dbforge->column_exists('activities', 'workout_type')) {
            $db->raw("
                ALTER TABLE `activities`
                ADD COLUMN `workout_type` VARCHAR(20) NULL
                AFTER `session_id`
            ");
        }
    }

    public function down()
    {
        $db = $this->_lava->db;

        if ($this->_lava->dbforge->column_exists('activities', 'workout_type')) {
            $db->raw("
                ALTER TABLE `activities`
                DROP COLUMN `workout_type`
            ");
        }
    }
}
