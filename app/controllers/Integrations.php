<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Integrations
 *
 * Read-only view of which external providers this deployment can actually use,
 * so the React Settings screen can render "not configured" instead of a button
 * that fails, and so a health check can tell a missing credential apart from a
 * broken one. Spec 2.5 asks for "integration status" as an operational feature;
 * FR-05 requires every integration to report status and errors.
 *
 * Two separate flags are returned on purpose:
 *   configured - the credentials are present
 *   ready      - configured AND switched on by the operator
 * Telling them apart is what makes "the integration is down" vs "nobody
 * configured it" answerable during a demo or a viva.
 *
 * Per-user connection state (is THIS runner's calendar linked?) is a different
 * question and belongs with integration_accounts, so it is deliberately not
 * read here. This endpoint stays behind require_jwt(): which providers a
 * deployment has enabled, and which model it names, is not information to give
 * an anonymous caller.
 */
class Integrations extends Controller {

    public function status()
    {
        $this->call->library('api');
        $this->call->helper('integration');

        $this->api->require_method('GET');
        $this->api->require_jwt();

        header('Cache-Control: no-store');
        $this->api->respond(array('data' => array(
            'providers'    => integration_public_config(),
            // The core planner works with every provider off; say so explicitly
            // so the UI can reassure rather than alarm.
            'core_enabled' => true,
        )));
    }
}
