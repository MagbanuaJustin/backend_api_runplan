<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Controller: Downloads
 *
 * Serves release files only through authenticated API routes.
 */
class Downloads extends Controller
{
    public function android()
    {
        $this->call->library('api');
        $this->api->require_method('GET');

        // The auth route middleware also checks that the account is active.
        // Require the JWT here as well before sending a private file.
        $this->api->require_jwt();

        $apk_path = dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'private-downloads'
            . DIRECTORY_SEPARATOR . 'runplan-android.apk';

        if (!is_file($apk_path) || !is_readable($apk_path)) {
            $this->api->respond_error(
                'The Android app package is not available yet.',
                503
            );
        }

        $file_size = filesize($apk_path);
        $file = @fopen($apk_path, 'rb');

        if ($file === false || $file_size === false) {
            if (is_resource($file)) {
                fclose($file);
            }

            $this->api->respond_error(
                'The Android app package could not be opened.',
                503
            );
        }

        // Prevent any buffered framework output from being prepended to the APK.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/vnd.android.package-archive');
        header('Content-Disposition: attachment; filename="RunPlan-Android.apk"');
        header('Content-Length: ' . (string) $file_size);
        header('Cache-Control: private, no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        fpassthru($file);
        fclose($file);
        exit;
    }
}