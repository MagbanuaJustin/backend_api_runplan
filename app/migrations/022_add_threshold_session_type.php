
<?php

class Add_threshold_session_type {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
    }

    public function up()
    {
        $this->_lava->db->raw(
            "ALTER TABLE `training_sessions` MODIFY `type`
             ENUM('easy','tempo','interval','long','recovery','rest','race','threshold')
             NOT NULL DEFAULT 'easy'"
        );
    }

    public function down()
    {
        $this->_lava->db->raw(
            "DELETE FROM `training_sessions` WHERE `type` = 'threshold'"
        );

        $this->_lava->db->raw(
            "ALTER TABLE `training_sessions` MODIFY `type`
             ENUM('easy','tempo','interval','long','recovery','rest','race')
             NOT NULL DEFAULT 'easy'"
        );
    }
}
