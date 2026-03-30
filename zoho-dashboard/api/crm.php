<?php
/**
 * Zoho CRM API driver functions.
 *
 * Each function accepts a valid access token, calls the Zoho CRM AU API,
 * handles pagination, and returns the full merged result array.
 *
 * These functions are called by api/proxy.php — never directly by the browser.
 */

require_once __DIR__ . '/../lib/helpers.php';

/**
 * Fetch all CRM contacts.
 */
function crm_getContacts(string $token): array
{
    return crm_paginate($token, '/Contacts', 'data');
}

/**
 * Fetch all CRM users (staff/employees).
 */
function crm_getEmployees(string $token): array
{
    return crm_paginate($token, '/users', 'users', ['type' => 'AllUsers']);
}

/**
 * Fetch all CRM accounts.
 */
function crm_getAccounts(string $token): array
{
    return crm_paginate($token, '/Accounts', 'data');
}

// -----------------------------------------------------------------------------
// Internal helpers
// -----------------------------------------------------------------------------

/**
 * Paginate through a Zoho CRM v3 endpoint, accumulating all records.
 *
 * CRM pagination: pass ?page=N&per_page=200. Response includes
 * info.more_records (bool) to indicate further pages.
 *
 * @param string $path     API path relative to CRM base URL, e.g. '/Contacts'.
 * @param string $dataKey  The key in the response body holding the records array.
 * @return array           Merged array of all records across all pages.
 */
function crm_paginate(string $token, string $path, string $dataKey, array $extraParams = []): array
{
    $config  = get_config();
    $baseUrl = rtrim($config['crm_api_base'], '/') . $path;
    $page    = 1;
    $records = [];

    do {
        $url      = $baseUrl . '?' . http_build_query(array_merge(['page' => $page, 'per_page' => 200], $extraParams));
        $response = crm_get($token, $url);

        if (!isset($response[$dataKey]) || !is_array($response[$dataKey])) {
            break;
        }

        $records = array_merge($records, $response[$dataKey]);

        $hasMore = $response['info']['more_records'] ?? false;
        $page++;
    } while ($hasMore);

    return $records;
}

/**
 * Perform a GET request to the Zoho CRM API with Bearer token auth.
 *
 * @throws RuntimeException on cURL or HTTP errors
 */
function crm_get(string $token, string $url): array
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
        throw new RuntimeException("CRM API cURL error: {$curlErr}");
    }

    // CRM returns 204 No Content when a module has zero records.
    if ($httpCode === 204) {
        return [];
    }

    if ($httpCode !== 200) {
        error_log("CRM API HTTP {$httpCode} for URL: {$url} — Body: " . substr($body, 0, 500));
        throw new RuntimeException("CRM API returned HTTP {$httpCode}.");
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('CRM API returned non-JSON response.');
    }

    return $decoded;
}
