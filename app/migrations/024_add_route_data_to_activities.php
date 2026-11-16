<?php

class Add_route_data_to_activities
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
        if (!$this->_lava->dbforge->column_exists('activities', 'route_data')) {
            $this->_lava->db->raw("
                ALTER TABLE `activities`
                ADD COLUMN `route_data` MEDIUMTEXT NULL
                AFTER `workout_type`
            " );
        }
    }

    public function down()
    {
        if ($this->_lava->dbforge->column_exists('activities', 'route_data')) {
            $this->_lava->db->raw("
                ALTER TABLE `activities`
                DROP COLUMN `route_data`
            " );
        }
    }
}
