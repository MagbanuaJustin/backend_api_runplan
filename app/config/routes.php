<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
$router->get('/', 'Welcome::index');
$router->get('/api/health', 'Health::index');
$router->options('/api/{a?}/{b?}/{c?}/{d?}', 'Health::preflight');
$router->get('/migrate/run', 'Migrate::run');

// Auth & identity (email/password, Google Sign-In/link, recovery)
$router->post('/api/auth/register', 'Auth::register');
$router->post('/api/auth/login', 'Auth::login');
$router->post('/api/auth/google', 'Auth::google');
$router->post('/api/auth/google/link', 'Auth::link_google');
$router->delete('/api/auth/google/link', 'Auth::unlink_google');
$router->post('/api/auth/forgot-password', 'Auth::forgot_password');
$router->post('/api/auth/reset-password', 'Auth::reset_password');
$router->post('/api/auth/refresh', 'Auth::refresh');
$router->get('/api/auth/me', 'Auth::me');
$router->post('/api/auth/logout', 'Auth::logout');

// Activities
$router->get('/api/activities', 'Activities::index');
$router->get('/api/activities/{id}', 'Activities::show');
$router->post('/api/activities', 'Activities::store');
$router->delete('/api/activities/{id}', 'Activities::destroy');
$router->put('/api/activities/{id}', 'Activities::update');

// Profile, Plans, Sessions, Dashboard, Integrations
$router->get('/api/profile', 'Profile::show');
$router->put('/api/profile', 'Profile::save');
$router->post('/api/plans/generate', 'Plans::generate');
$router->get('/api/plans/current', 'Plans::current');
$router->post('/api/plans/{id}/approve', 'Plans::approve');
$router->post('/api/plans/{id}/rebuild', 'Plans::rebuild');
$router->get('/api/plans/{id}/revisions', 'Plans::revisions');
$router->patch('/api/sessions/{id}', 'Sessions::update');
$router->get('/api/dashboard', 'Dashboard::index');
$router->get('/api/integrations', 'Integrations::status');
