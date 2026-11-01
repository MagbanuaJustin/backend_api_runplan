<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Welcome
 * 
 * Automatically generated via CLI.
 */
class Welcome extends Controller {
	public function index() {
		$this->call->view('welcome_page');
	}
}
?>