<?php
/**
 * Central API proxy — the only endpoint the browser calls.
 *
 * Every request goes directly to the Zoho API — no server-side caching.
 *
 * Usage:
 *   GET proxy.php?endpoint=<key>
 *   GET proxy.php?endpoint=books_invoices_by_item&item_id=<id>
 *
 * Parameterised endpoints declare a 'param' key in the whitelist.
 * The value is read from $_GET, sanitised, and passed as the second
 * argument to the driver function.
 *
 * The browser never sees Zoho URLs, credentials, or tokens.
 * Only whitelisted endpoint keys are accepted.
 */

require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/ZohoOAuth.php';
require_once __DIR__ . '/books.php';
require_once __DIR__ . '/crm.php';

Auth::requireLoginApi();

/**
 * Item list scoped to the current user: only items whose custom "Status"
 * field is exactly "Active" are included, for everyone; staff additionally
 * only see items whose "Recipient Group Email" custom field matches their
 * own login email.
 */
function books_getItemsForCurrentUser(string $token): array
{
    $items = books_filterActiveItems(books_getItems($token));
    $me    = Auth::user();
    if (!$me || $me['role'] !== 'staff') {
        return $items;
    }
    return books_filterItemsByRecipientEmail($items, $me['email']);
}

// ---------------------------------------------------------------------------
// Endpoint whitelist
// Standard:      'key' => ['fn' => '<function>']
// Parameterised: add   'param' => '<GET key>'
// Cached:        add   'ttl'   => <seconds>
// ---------------------------------------------------------------------------
const ENDPOINTS = [
    'books_invoices'             => ['fn' => 'books_getInvoices'],
    'books_recurring'            => ['fn' => 'books_getRecurringInvoices'],
    'books_recurring_all'        => ['fn' => 'books_getAllRecurringInvoices'],
    'books_accounts'             => ['fn' => 'books_getAccounts'],
    'books_items'                => ['fn' => 'books_getItemsForCurrentUser'],
    'books_item_detail'          => ['fn' => 'books_getItemDetail',          'param' => 'item_id'],
    'books_item_customfields'    => ['fn' => 'books_getItemCustomFields'],
    'books_all_item_customfields'=> ['fn' => 'books_getAllItemCustomFields'],
    'books_contact_detail'       => ['fn' => 'books_getContactDetail',       'param' => 'contact_id'],
    'books_employee_contact'     => ['fn' => 'books_getEmployeeContact',     'param' => 'item_id'],
    'books_items_pm_map'         => ['fn' => 'books_getItemsPmMap'],
    'books_contacts'             => ['fn' => 'books_getContacts'],
    'books_invoices_by_item'     => ['fn' => 'books_getInvoicesByItem',      'param' => 'item_id'],
    'books_invoice_detail'       => ['fn' => 'books_getInvoiceDetail',       'param' => 'invoice_id'],
    'books_invoice_transactions' => ['fn' => 'books_getInvoiceTransactions', 'param' => 'item_id'],
    'books_recurring_by_item'    => ['fn' => 'books_getRecurringByItem',     'param' => 'item_id'],
    'books_recurring_detail'     => ['fn' => 'books_getRecurringDetail',     'param' => 'recurring_invoice_id'],
    'books_support_balance'      => ['fn' => 'books_getSupportAccountBalance','param' => 'item_id'],
    'crm_contacts'               => ['fn' => 'crm_getContacts'],
    'crm_employees'              => ['fn' => 'crm_getEmployees'],
    'crm_accounts'               => ['fn' => 'crm_getAccounts'],
];

// ---------------------------------------------------------------------------
// Special: item image proxy (returns raw image, not JSON)
// ---------------------------------------------------------------------------

if (($_GET['endpoint'] ?? '') === 'books_item_image') {
    $itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['item_id'] ?? '');
    if ($itemId === '') { http_response_code(400); exit; }

    try {
        $oauth = new ZohoOAuth();
        $token = $oauth->getValidAccessToken();
        $cfg   = get_config();
        $url   = rtrim($cfg['books_api_base'], '/') . '/items/' . rawurlencode($itemId) . '/image'
               . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Zoho-oauthtoken ' . $token],
        ]);
        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode !== 200) { http_response_code(404); exit; }

        $rawHeaders  = substr($response, 0, $headerSize);
        $body        = substr($response, $headerSize);
        $contentType = 'image/jpeg';
        foreach (explode("\r\n", $rawHeaders) as $hLine) {
            if (stripos($hLine, 'content-type:') === 0) {
                $contentType = trim(substr($hLine, 13));
                break;
            }
        }

        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=3600');
        echo $body;
    } catch (Throwable $e) {
        http_response_code(500);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Special: contact image proxy (returns raw image, not JSON)
// ---------------------------------------------------------------------------

if (($_GET['endpoint'] ?? '') === 'books_contact_image') {
    $contactId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['contact_id'] ?? '');
    if ($contactId === '') { http_response_code(400); exit; }

    try {
        $oauth = new ZohoOAuth();
        $token = $oauth->getValidAccessToken();
        $cfg   = get_config();
        $url   = rtrim($cfg['books_api_base'], '/') . '/contacts/' . rawurlencode($contactId) . '/image'
               . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Zoho-oauthtoken ' . $token],
        ]);
        $response   = curl_exec($ch);
        $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($httpCode !== 200) { http_response_code(404); exit; }

        $rawHeaders  = substr($response, 0, $headerSize);
        $body        = substr($response, $headerSize);
        $contentType = 'image/jpeg';
        foreach (explode("\r\n", $rawHeaders) as $hLine) {
            if (stripos($hLine, 'content-type:') === 0) {
                $contentType = trim(substr($hLine, 13));
                break;
            }
        }

        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=3600');
        echo $body;
    } catch (Throwable $e) {
        http_response_code(500);
    }
    exit;
}

// ---------------------------------------------------------------------------
// Validate request
// ---------------------------------------------------------------------------

header('Content-Type: application/json; charset=utf-8');

$endpointKey = $_GET['endpoint'] ?? '';

if ($endpointKey === '' || !array_key_exists($endpointKey, ENDPOINTS)) {
    error_out('Invalid or missing endpoint.', 400);
}

$spec = ENDPOINTS[$endpointKey];

// Resolve optional parameter for parameterised endpoints.
$param = '';
if (!empty($spec['param'])) {
    $param = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET[$spec['param']] ?? '');
    if ($param === '') {
        error_out("Missing required parameter: {$spec['param']}.", 400);
    }
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
    $data = $param !== '' ? $fn($token, $param) : $fn($token);
} catch (RuntimeException $e) {
    error_log("Proxy upstream error [{$endpointKey}]: " . $e->getMessage());
    json_response(['error' => 'upstream_error'], 502);
}

json_response(['source' => 'api', 'data' => $data]);
