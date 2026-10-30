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
$router->options('/api/{a?}/{b?}/{c?}/{d?}/{e?}', 'Health::preflight');
$router->get('/migrate/run', 'Migrate::run');

$router->post('/api/auth/register', 'Auth::register');
$router->post('/api/auth/login', 'Auth::login');
$router->post('/api/auth/google', 'Auth::google');
$router->post('/api/auth/refresh', 'Auth::refresh');
$router->post('/api/auth/logout', 'Auth::logout');
$router->post('/api/auth/forgot-password', 'Auth::forgot_password');
$router->post('/api/auth/reset-password', 'Auth::reset_password');

$router->get('/api/auth/me', 'Auth::me')->middleware('auth');

$router->get('/api/account', 'Account::show')->middleware('auth');
$router->post('/api/account/password', 'Account::change_password')->middleware('auth');
$router->delete('/api/account', 'Account::destroy')->middleware('auth');
$router->post('/api/account/delete', 'Account::destroy')->middleware('auth');

$router->get('/api/activities', 'Activities::index')->middleware('auth');
$router->get('/api/activities/{id}', 'Activities::show')->middleware('auth');
$router->post('/api/activities', 'Activities::store')->middleware('auth');
$router->put('/api/activities/{id}', 'Activities::update')->middleware('auth');
$router->delete('/api/activities/{id}', 'Activities::destroy')->middleware('auth');

$router->get('/api/profile', 'Profile::show')->middleware('auth');
$router->put('/api/profile', 'Profile::save')->middleware('auth');

$router->post('/api/plans/generate', 'Plans::generate')->middleware('auth');
$router->post('/api/plans/manual', 'Plans::manual')->middleware('auth');
$router->get('/api/plans/current', 'Plans::current')->middleware('auth');
$router->post('/api/plans/{id}/approve', 'Plans::approve')->middleware('auth');
$router->delete('/api/plans/{id}', 'Plans::discard')->middleware('auth');
$router->post('/api/plans/{id}/discard', 'Plans::discard')->middleware('auth');
$router->post('/api/plans/{id}/rebuild', 'Plans::rebuild')->middleware('auth');
$router->post('/api/plans/{id}/revisions/propose', 'Plans::propose_revision')->middleware('auth');
$router->post('/api/plans/{id}/revisions/{rev_id}/apply', 'Plans::apply_revision')->middleware('auth');
$router->post('/api/plans/{id}/revisions/{rev_id}/reject', 'Plans::reject_revision')->middleware('auth');
$router->get('/api/plans/{id}/revisions', 'Plans::revisions')->middleware('auth');

$router->patch('/api/sessions/{id}', 'Sessions::update')->middleware('auth');
$router->get('/api/dashboard', 'Dashboard::index')->middleware('auth');

$router->get('/api/ai/feedback', 'AiCoaching::show')->middleware('auth');
$router->post('/api/ai/feedback', 'AiCoaching::generate')->middleware('auth');
$router->post('/api/ai/chat', 'AiCoaching::chat')->middleware('auth');

$router->get('/api/integrations', 'Integrations::status')->middleware('auth');
$router->get('/api/integrations/status', 'Integrations::status')->middleware('auth');
$router->get('/api/weather/forecast', 'Weather::forecast')->middleware('auth');

$router->get('/api/notifications/preferences', 'Notifications::preferences')->middleware('auth');
$router->put('/api/notifications/preferences', 'Notifications::save_preferences')->middleware('auth');
$router->post('/api/notifications/subscriptions', 'Notifications::subscribe')->middleware('auth');
$router->post('/api/notifications/subscriptions/remove', 'Notifications::unsubscribe')->middleware('auth');
$router->post('/api/notifications/dispatch', 'Notifications::dispatch');

$router->get('/api/calendar/status', 'CalendarSync::status')->middleware('auth');
$router->get('/api/calendar/connect', 'CalendarSync::connect')->middleware('auth');
$router->post('/api/calendar/disconnect', 'CalendarSync::disconnect')->middleware('auth');
$router->post('/api/calendar/sync-enabled', 'CalendarSync::toggle')->middleware('auth');
$router->get('/calendar/callback', 'CalendarSync::callback');
$router->post('/api/calendar/sync', 'CalendarEvents::sync')->middleware('auth');

// Runner problem reports and admin-only support operations.
$router->get('/api/support/reports', 'Support::reports')->middleware('auth');
$router->post('/api/support/reports', 'Support::create_report')->middleware('auth');
$router->patch('/api/support/reports/{id}', 'Support::update_report')->middleware('auth');
$router->get('/api/support/console', 'Support::console')->middleware('auth');
$router->get('/api/support/system', 'Support::system')->middleware('auth');