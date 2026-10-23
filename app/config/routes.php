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
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
*/
$router->get('/', 'Welcome::index');
$router->get('/api/health', 'Health::index');
$router->options('/api/{a?}/{b?}/{c?}/{d?}', 'Health::preflight');
$router->get('/migrate/run', 'Migrate::run');

// Public auth endpoints (cookie/credentials bootstrap)
$router->post('/api/auth/register', 'Auth::register');
$router->post('/api/auth/login', 'Auth::login');
$router->post('/api/auth/refresh', 'Auth::refresh');
$router->post('/api/auth/logout', 'Auth::logout');

// Protected endpoints (AuthMiddleware gate + controller require_jwt() defense-in-depth)
$router->get('/api/auth/me', 'Auth::me')->middleware('auth');

$router->get('/api/activities', 'Activities::index')->middleware('auth');
$router->get('/api/activities/{id}', 'Activities::show')->middleware('auth');
$router->post('/api/activities', 'Activities::store')->middleware('auth');
$router->put('/api/activities/{id}', 'Activities::update')->middleware('auth');
$router->delete('/api/activities/{id}', 'Activities::destroy')->middleware('auth');

$router->get('/api/profile', 'Profile::show')->middleware('auth');
$router->put('/api/profile', 'Profile::save')->middleware('auth');

$router->post('/api/plans/generate', 'Plans::generate')->middleware('auth');
$router->get('/api/plans/current', 'Plans::current')->middleware('auth');
$router->post('/api/plans/{id}/approve', 'Plans::approve')->middleware('auth');
$router->post('/api/plans/{id}/rebuild', 'Plans::rebuild')->middleware('auth');
$router->get('/api/plans/{id}/revisions', 'Plans::revisions')->middleware('auth');

$router->patch('/api/sessions/{id}', 'Sessions::update')->middleware('auth');
$router->get('/api/dashboard', 'Dashboard::index')->middleware('auth');

$router->get('/api/integrations', 'Integrations::status')->middleware('auth');
$router->get('/api/integrations/status', 'Integrations::status')->middleware('auth');
