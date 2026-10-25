<?php
define('PREVENT_DIRECT_ACCESS', TRUE);
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
 * @copyright Copyright 2020 (https://ronmarasigan.github.io)
 * @since Version 1
 * @link https://lavalust.pinoywap.org
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
 *---------------------------------------------------------------
 * SYSTEM DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * This variable must contain the name of your "scheme" directory.
 * Set the path if it is not in the same directory as this file.
 * 
 * NO TRAILING SLASH!
 */
	$system_path 			= 'scheme';

/*
 *---------------------------------------------------------------
 * APPLICATION DIRECTORY NAME
 *---------------------------------------------------------------
 *
 * If you want this front controller to use a different "app"
 * directory than the default one you can set its name here.
 *
 * NO TRAILING SLASH!
 */
	$application_folder 	= 'app';

/*
 *---------------------------------------------------------------
 * APPLICATION DIRECTORY NAME
 *---------------------------------------------------------------
 * This let you set up your public folder where css, js and other public,
 * files will be visible
 */
	$public_folder			= 'public';

/*
 * ------------------------------------------------------
 * Define Application Constants
 * ------------------------------------------------------
 */
define('ROOT_DIR',  dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('SYSTEM_DIR', ROOT_DIR . $system_path . DIRECTORY_SEPARATOR);
define('APP_DIR', ROOT_DIR . $application_folder . DIRECTORY_SEPARATOR);
define('PUBLIC_DIR', $public_folder);

// ---- NEW: minimal .env loader ----
if (!function_exists('env')) {
    function env($key, $default = '') {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}
$env_file = ROOT_DIR . '.env';
if (is_readable($env_file)) {
    foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim(trim($value), "\"'");
        putenv("$name=$value"); // real server vars win
    }
}

// ---- Security headers (the API only returns JSON) ----
header_remove('X-Powered-By');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
if (env('APP_ENV', 'development') === 'production') {
    // Not in development: LavaLust's debug error pages use inline styles
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
}
if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')) {
    header('Strict-Transport-Security: max-age=31536000');
}

// ---- JSON error & exception handlers for /api/* routes ----
// LavaLust's default debug handlers render HTML with HTTP 200 on uncaught
// exceptions or PHP warnings/deprecations, which causes the React SPA client
// to report "The server sent an unexpected reply (status 200)".
if (!function_exists('rp_is_api_request')) {
    function rp_is_api_request() {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? '');
        return strpos($uri, '/api/') !== false || substr($uri, -4) === '/api';
    }
}

if (!function_exists('rp_send_api_json_error')) {
    function rp_send_api_json_error($status_code, $public_message, $dev_detail = null) {
        // Drop anything already printed (a notice, a deprecation banner, half a
        // stack trace) so the client receives one parseable JSON document.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            $origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
            $allowed = array_filter(array_map('trim', explode(',', env('CORS_ORIGINS', 'http://localhost:5173'))));
            if ($origin !== '' && in_array($origin, $allowed, true)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Credentials: true');
            }
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code((int) $status_code);
        }

        $is_dev  = strtolower(env('APP_ENV', 'development')) === 'development';
        $message = ($is_dev && is_string($dev_detail) && $dev_detail !== '')
            ? $dev_detail
            : $public_message;

        echo json_encode(array(
            'error'  => $message,
            'status' => (int) $status_code,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('_exception_handler')) {
    function _exception_handler($e) {
        if (function_exists('config_item') && (config_item('log_threshold') == 1 || config_item('log_threshold') == 3)) {
            $logger = load_class('logger', 'kernel');
            $logger->log('error', get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
        }
        if (rp_is_api_request()) {
            $detail = get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
            rp_send_api_json_error(500, 'Internal server error. Please try again.', $detail);
        }
        if (function_exists('config_item') && strtolower((string) config_item('ENVIRONMENT')) === 'development') {
            $exception = load_class('Errors', 'kernel');
            $exception->show_exception($e);
        }
        http_response_code(500);
        exit;
    }
}

if (!function_exists('_error_handler')) {
    function _error_handler($severity, $errstr, $errfile, $errline) {
        // Respect @ suppression and ignore non-fatal deprecation notices (e.g. PHP 8.5)
        if (!(error_reporting() & $severity)) {
            return false;
        }
        if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
            return true;
        }

        if (function_exists('config_item') && (config_item('log_threshold') == 1 || config_item('log_threshold') == 3)) {
            $logger = load_class('logger', 'kernel');
            $logger->log('error', (string) $severity, $errstr, $errfile, $errline);
        }

        if (rp_is_api_request()) {
            $detail = 'PHP error: ' . $errstr . ' (' . basename($errfile) . ':' . $errline . ')';
            rp_send_api_json_error(500, 'Internal server error. Please try again.', $detail);
        }

        if (function_exists('config_item') && strtolower((string) config_item('ENVIRONMENT')) === 'development') {
            $error = load_class('Errors', 'kernel');
            $error->show_php_error((string) $severity, $errstr, $errfile, $errline);
        }
        return true;
    }
}

/*
 * Buffer /api/* output for the whole request.
 *
 * Why: one notice printed before the JSON body makes json_decode() fail on the
 * client, which then reports "The server sent an unexpected reply (status 200)"
 * instead of naming the real problem. With the buffer in place every response
 * path - Api::respond(), the handlers above, even a fatal error caught in the
 * shutdown handler - can discard the stray bytes and emit clean JSON.
 */
if (rp_is_api_request() && ob_get_level() === 0) {
    ob_start();
}

/*
 * ------------------------------------------------------
 * Setup done? Then Hurray!
 * ------------------------------------------------------
 */
require_once SYSTEM_DIR . 'kernel/LavaLust.php';
?>
