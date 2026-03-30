<?php
/**
 * Central API proxy — the only endpoint the browser calls.
 *
 * Usage: GET /zoho-dashboard/api/proxy.php?endpoint=<key>
 *        GET /zoho-dashboard/api/proxy.php?endpoint=<key>&refresh=1  (bypass cache)
 *
 * The browser never sees Zoho URLs, credentials, or tokens.
 * Only whitelisted endpoint keys are accepted.
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';
require_once __DIR__ . '/../lib/ApiCache.php';
require_once __DIR__ . '/books.php';
require_once __DIR__ . '/crm.php';

// ---------------------------------------------------------------------------
// Endpoint whitelist
//
// Format: 'endpoint_key' => ['driver' => 'books'|'crm', 'fn' => '<function>', 'ttl' => <seconds>]
// ---------------------------------------------------------------------------
const ENDPOINTS = [
    'books_invoices'           => ['driver' => 'books', 'fn' => 'books_getInvoices',           'ttl' => 3600],
    'books_recurring'          => ['driver' => 'books', 'fn' => 'books_getRecurringInvoices',   'ttl' => 3600],
    'books_accounts'           => ['driver' => 'books', 'fn' => 'books_getAccounts',            'ttl' => 7200],
    'books_contacts'           => ['driver' => 'books', 'fn' => 'books_getContacts',            'ttl' => 3600],
    'crm_contacts'             => ['driver' => 'crm',   'fn' => 'crm_getContacts',              'ttl' => 3600],
    'crm_employees'            => ['driver' => 'crm',   'fn' => 'crm_getEmployees',             'ttl' => 3600],
    'crm_accounts'             => ['driver' => 'crm',   'fn' => 'crm_getAccounts',              'ttl' => 3600],
];

// ---------------------------------------------------------------------------
// Validate request
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

$endpointKey = $_GET['endpoint'] ?? '';

if ($endpointKey === '' || !array_key_exists($endpointKey, ENDPOINTS)) {
    error_out('Invalid or missing endpoint.', 400);
}

$spec        = ENDPOINTS[$endpointKey];
$forceRefresh = !empty($_GET['refresh']);

// ---------------------------------------------------------------------------
// Serve from cache if valid
// ---------------------------------------------------------------------------

$cache = new ApiCache($endpointKey);

if (!$forceRefresh && $cache->isValid($spec['ttl'])) {
    json_response([
        'source' => 'cache',
        'age'    => $cache->age(),
        'data'   => $cache->read(),
    ]);
}

// ---------------------------------------------------------------------------
// Fetch from Zoho API
// ---------------------------------------------------------------------------

try {
    $oauth = new ZohoOAuth();
    $token = $oauth->getValidAccessToken();
} catch (ZohoAuthException $e) {
    error_log('Proxy auth error: ' . $e->getMessage());
    json_response(['error' => 'auth_required'], 401);
}

$fn = $spec['fn'];

try {
    $data = $fn($token);
} catch (RuntimeException $e) {
    error_log("Proxy upstream error [{$endpointKey}]: " . $e->getMessage());
    json_response(['error' => 'upstream_error'], 502);
}

// ---------------------------------------------------------------------------
// Write to cache and respond
// ---------------------------------------------------------------------------

$cache->write($data);

json_response([
    'source' => 'api',
    'age'    => 0,
    'data'   => $data,
]);
