<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Middleware: AuthMiddleware
 *
 * Route-level JWT gate. Verifies the Bearer token via Api::require_jwt() before
 * entering a protected controller action, while controllers also call
 * require_jwt() for defense-in-depth and access to the decoded claims.
 */
class AuthMiddleware {

    public function handle(Closure $next)
    {
        $lava = lava_instance();
        $lava->call->library('api');
        $lava->api->require_jwt();

        return $next();
    }
}
