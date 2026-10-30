<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class AuthMiddleware
{
    public function handle(Closure $next)
    {
        $lava = lava_instance();
        $lava->call->library('api');
        $payload = $lava->api->require_jwt();

        $user_id = (int) ($payload['sub'] ?? 0);
        if ($user_id < 1) {
            $lava->api->respond_error('Authentication required.', 401);
        }

        $lava->call->database();
        $lava->call->model('UserModel');
        if (!$lava->UserModel->find_active($user_id)) {
            $lava->api->respond_error('This account is suspended or inactive.', 403);
        }

        return $next();
    }
}