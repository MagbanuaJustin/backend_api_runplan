<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Migrate
 * 
 * Automatically generated via CLI.
 */
class Migrate extends Controller {
    public function __construct() {
        parent::__construct();
        if (PHP_SAPI !== 'cli') {   // browsers get nothing
            http_response_code(404);
            exit;
        }
    }

    public function run() {
        $this->call->library('migration');
        $this->migration->migrate();
    }
}