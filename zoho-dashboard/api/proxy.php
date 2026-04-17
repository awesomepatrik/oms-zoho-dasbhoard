<?php
/**
 * Central API proxy — the only endpoint the browser calls.
 *
 * Usage:
 *   GET proxy.php?endpoint=<key>
 *   GET proxy.php?endpoint=<key>&refresh=1          (bypass cache)
 *   GET proxy.php?endpoint=books_invoices_by_item&item_id=<id>
 *
 * Parameterised endpoints declare a 'param' key in the whitelist.
 * The value is read from $_GET, sanitised, appended to the cache key,
 * and passed as the second argument to the driver function.
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
// Standard:      'key' => ['fn' => '<function>', 'ttl' => <seconds>]
// Parameterised: add   'param' => '<GET key>'  — value validated + forwarded
// ---------------------------------------------------------------------------
const ENDPOINTS = [
    'books_invoices'           => ['fn' => 'books_getInvoices',          'ttl' => 3600],
    'books_recurring'          => ['fn' => 'books_getRecurringInvoices', 'ttl' => 3600],
    'books_accounts'           => ['fn' => 'books_getAccounts',          'ttl' => 7200],
    'books_items'                => ['fn' => 'books_getItems',             'ttl' => 7200],
    'books_item_detail'          => ['fn' => 'books_getItemDetail',        'ttl' => 7200, 'param' => 'item_id'],
    'books_item_invoice_status'  => ['fn' => 'books_getItemInvoiceStatus', 'ttl' => 300],
    'books_invoice_index'        => ['fn' => 'books_getInvoiceIndex',      'ttl' => 86400],
    'books_contacts'           => ['fn' => 'books_getContacts',          'ttl' => 3600],
    'books_invoices_by_item'   => ['fn' => 'books_getInvoicesByItem',    'ttl' => 3600, 'param' => 'item_id'],
    'crm_contacts'             => ['fn' => 'crm_getContacts',            'ttl' => 3600],
    'crm_employees'            => ['fn' => 'crm_getEmployees',           'ttl' => 3600],
    'crm_accounts'             => ['fn' => 'crm_getAccounts',            'ttl' => 3600],
];

// ---------------------------------------------------------------------------
// Validate request
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

$endpointKey = $_GET['endpoint'] ?? '';

if ($endpointKey === '' || !array_key_exists($endpointKey, ENDPOINTS)) {
    error_out('Invalid or missing endpoint.', 400);
}

$spec         = ENDPOINTS[$endpointKey];
$forceRefresh = !empty($_GET['refresh']);

// Resolve optional parameter for parameterised endpoints.
$param = '';
if (!empty($spec['param'])) {
    $param = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET[$spec['param']] ?? '');
    if ($param === '') {
        error_out("Missing required parameter: {$spec['param']}.", 400);
    }
}

// ---------------------------------------------------------------------------
// Serve from cache if valid
// ---------------------------------------------------------------------------

// Cache key includes the param value so each item gets its own cache file.
$cacheKey = $param !== '' ? "{$endpointKey}_{$param}" : $endpointKey;
$cache    = new ApiCache($cacheKey);

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

// Expose the force-refresh flag globally so deep helper functions
// (e.g. books_getInvoiceIndex) can bypass their own internal caches.
$GLOBALS['books_force_refresh'] = $forceRefresh;

try {
    $data = $param !== '' ? $fn($token, $param) : $fn($token);
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
