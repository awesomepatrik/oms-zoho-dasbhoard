<?php
/**
 * Shared utilities and path constants.
 */

// Absolute path to the outside-web-root config directory.
define('CONFIG_DIR', 'C:/xampp/htdocs/oms-zoho-dashboard/zoho-dashboard-config');
define('CONFIG_FILE', CONFIG_DIR . '/config.php');

/**
 * Load and return the master config array.
 */
function get_config(): array
{
    static $config = null;
    if ($config === null) {
        if (!file_exists(CONFIG_FILE)) {
            error_out('Configuration file not found. See CONFIG_FILE in lib/helpers.php.', 500);
        }
        $config = require CONFIG_FILE;
    }
    return $config;
}

/**
 * Send a JSON response and exit.
 */
function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Send a JSON error response and exit.
 */
function error_out(string $message, int $status = 400): void
{
    json_response(['error' => $message], $status);
}

/**
 * Check that valid OAuth tokens exist; redirect to auth flow if not.
 * Call this at the top of pages that require authentication.
 */
function require_auth(): void
{
    require_once __DIR__ . '/ZohoOAuth.php';
    $oauth = new ZohoOAuth();
    if (!$oauth->hasValidTokens()) {
        header('Location: /oms-zoho-dashboard/zoho-dashboard/auth/connect.php');
        exit;
    }
}
