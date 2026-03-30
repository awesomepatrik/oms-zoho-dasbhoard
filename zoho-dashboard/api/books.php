<?php
/**
 * Zoho Books API driver functions.
 *
 * Each function accepts a valid access token, calls the Zoho Books AU API,
 * handles pagination, and returns the full merged result array.
 *
 * These functions are called by api/proxy.php — never directly by the browser.
 */

require_once __DIR__ . '/../lib/helpers.php';

/**
 * Fetch all recurring invoices (pledge schedules).
 */
function books_getRecurringInvoices(string $token): array
{
    return books_paginate($token, '/recurringinvoices', 'recurringinvoices');
}

/**
 * Fetch all invoices.
 */
function books_getInvoices(string $token): array
{
    return books_paginate($token, '/invoices', 'invoices');
}

/**
 * Fetch chart-of-accounts summary.
 */
function books_getAccounts(string $token): array
{
    return books_paginate($token, '/chartofaccounts', 'chartofaccounts');
}

/**
 * Fetch all customers (donors).
 */
function books_getContacts(string $token): array
{
    return books_paginate($token, '/contacts', 'contacts');
}

// -----------------------------------------------------------------------------
// Internal helpers
// -----------------------------------------------------------------------------

/**
 * Paginate through a Zoho Books endpoint, accumulating all records.
 *
 * @param string $path     API path relative to the Books base URL, e.g. '/invoices'.
 * @param string $dataKey  The key in the response body that holds the records array.
 * @return array           Merged array of all records across all pages.
 */
function books_paginate(string $token, string $path, string $dataKey): array
{
    $config  = get_config();
    $baseUrl = rtrim($config['books_api_base'], '/') . $path;
    $orgId   = $config['books_org_id'];
    $page    = 1;
    $records = [];

    do {
        $url      = $baseUrl . '?' . http_build_query(['organization_id' => $orgId, 'page' => $page]);
        $response = books_get($token, $url);

        if (!isset($response[$dataKey]) || !is_array($response[$dataKey])) {
            // Unexpected shape — return what we have.
            break;
        }

        $records = array_merge($records, $response[$dataKey]);

        $hasMore = $response['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($hasMore);

    return $records;
}

/**
 * Perform a GET request to the Zoho Books API with Bearer token auth.
 *
 * @throws RuntimeException on cURL or HTTP errors
 */
function books_get(string $token, string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Zoho-oauthtoken ' . $token,
            'Accept: application/json',
        ],
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException("Books API cURL error: {$curlErr}");
    }

    if ($httpCode !== 200) {
        error_log("Books API HTTP {$httpCode} for URL: {$url} — Body: " . substr($body, 0, 500));
        throw new RuntimeException("Books API returned HTTP {$httpCode}.");
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('Books API returned non-JSON response.');
    }

    if (isset($decoded['code']) && $decoded['code'] !== 0) {
        $msg = $decoded['message'] ?? 'Unknown Books API error';
        throw new RuntimeException("Books API error {$decoded['code']}: {$msg}");
    }

    return $decoded;
}
