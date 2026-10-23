<?php

/**
 * Migration 014: optional training location on the runner profile.
 *
 * Spec 5.2 asks for advisory weather on upcoming sessions. That needs a place
 * to look up, and the cheapest correct place to keep it is the runner's own
 * profile - no new table, no new lifecycle, and it stays optional because the
 * planner must keep working for a runner who never sets it (NFR-04).
 *
 * Three nullable columns instead of one JSON blob:
 *   location_label - what the runner typed or picked, e.g. "Mamburao, PH"
 *   latitude/longitude - set either by the runner directly or by the
 *                        geocoding step when a forecast is first requested
 *
 * All three are NULL by default, so this migration cannot break an existing
 * row and `GET /api/profile` keeps its current shape for anyone who skips it.
 */
class Add_profile_location_columns {

    private $_lava;

    public function __construct()
    {
        $this->_lava = lava_instance();
        $this->_lava->call->database();
        $this->_lava->call->dbforge();
    }

    private function run($sql)
    {
        $this->_lava->db->raw($sql);
    }

    private function has_col($table, $column)
    {
        return $this->_lava->dbforge->column_exists($table, $column);
    }

    private function add_col($table, $column, $definition)
    {
        if (!$this->has_col($table, $column)) {
            $this->run("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private function drop_col($table, $column)
    {
        if ($this->has_col($table, $column)) {
            $this->run("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
        }
    }

    public function up()
    {
        // DECIMAL(8,5) holds -180.00000 .. 180.00000 with ~1 m precision,
        // which is far more than a weather lookup needs.
        $this->add_col(
            'runner_profiles',
            'location_label',
            'VARCHAR(120) NULL DEFAULT NULL AFTER `baseline_pace`'
        );
        $this->add_col(
            'runner_profiles',
            'latitude',
            'DECIMAL(8,5) NULL DEFAULT NULL AFTER `location_label`'
        );
        $this->add_col(
            'runner_profiles',
            'longitude',
            'DECIMAL(8,5) NULL DEFAULT NULL AFTER `latitude`'
        );
    }

    public function down()
    {
        $this->drop_col('runner_profiles', 'longitude');
        $this->drop_col('runner_profiles', 'latitude');
        $this->drop_col('runner_profiles', 'location_label');
    }
}
