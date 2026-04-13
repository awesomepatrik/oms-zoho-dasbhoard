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
    return books_paginate($token, '/recurringinvoices', 'recurringinvoices', ['filter_by' => 'Status.Active']);
}

/**
 * Fetch all paid invoices.
 */
function books_getInvoices(string $token): array
{
    return books_paginate($token, '/invoices', 'invoices', ['filter_by' => 'Status.Paid']);
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

/**
 * Fetch all items — each item represents an employee support fund.
 */
function books_getItems(string $token): array
{
    return books_paginate($token, '/items', 'items');
}

/**
 * Return a map of item_id => true for every item whose invoice cache file
 * exists on disk and contains at least one invoice record.
 *
 * Reads only local cache files — makes ZERO Zoho API calls.
 * Used by the employees list to filter without triggering rate limits.
 */
function books_getItemInvoiceStatus(string $_token): array
{
    // Prefer the global invoice index (accurate, built once for all employees).
    $indexCache = new ApiCache('books_invoice_index');
    if ($indexCache->isValid(86400)) {
        $status = [];
        foreach (array_keys($indexCache->read()) as $key) {
            // Skip name: keys — we only want item_id keys here.
            if (!str_starts_with($key, 'name:')) {
                $status[$key] = true;
            }
        }
        return $status;
    }

    // Index not yet built — fall back to per-item cache files on disk.
    $config   = get_config();
    $cacheDir = rtrim($config['cache_dir'], '/\\');
    $status   = [];
    foreach (glob($cacheDir . '/books_invoices_by_item_*.json') as $file) {
        $payload = json_decode(file_get_contents($file), true);
        if (!empty($payload['data']) && is_array($payload['data'])) {
            $base   = basename($file, '.json');
            $itemId = str_replace('books_invoices_by_item_', '', $base);
            if ($itemId !== '') {
                $status[$itemId] = true;
            }
        }
    }
    return $status;
}

/**
 * Return the set of item_ids that appear in at least one paid invoice.
 *
 * Fetches all paid invoice details in parallel batches to read line_items,
 * then returns a plain array of unique item_id strings.
 * Cached for 1 hour — first call is slow, subsequent calls are instant.
 */
function books_getItemIdsWithInvoices(string $token): array
{
    set_time_limit(120);

    $config  = get_config();
    $orgId   = $config['books_org_id'];
    $baseUrl = rtrim($config['books_api_base'], '/');

    $list = books_paginate(
        $token, '/invoices', 'invoices',
        ['filter_by' => 'Status.Paid', 'sort_column' => 'date', 'sort_order' => 'D']
    );

    if (empty($list)) {
        return [];
    }

    $itemIds = [];

    foreach (array_chunk($list, 20) as $batch) {
        $mh      = curl_multi_init();
        $handles = [];

        foreach ($batch as $stub) {
            $invoiceId = $stub['invoice_id'] ?? '';
            if ($invoiceId === '') continue;

            $ch = curl_init(
                "{$baseUrl}/invoices/" . rawurlencode($invoiceId)
                . '?' . http_build_query(['organization_id' => $orgId])
            );
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Zoho-oauthtoken ' . $token,
                    'Accept: application/json',
                ],
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($handles as $ch) {
            $decoded = json_decode(curl_multi_getcontent($ch), true);
            curl_multi_remove_handle($mh, $ch);

            foreach ($decoded['invoice']['line_items'] ?? [] as $li) {
                $id = (string)($li['item_id'] ?? '');
                if ($id !== '') {
                    $itemIds[$id] = true;
                }
            }
        }

        curl_multi_close($mh);
    }

    return array_keys($itemIds);
}

/**
 * Build (or return cached) a reverse index of item_id / item_name → invoices.
 *
 * Fetches ALL paid invoice details in parallel batches of 10, reads each
 * invoice's line_items, and maps every item_id (and item name) found to the
 * compact invoice records that contain it.
 *
 * Cost: one fetch of 683 invoice details (~3-5 min with rate-limit back-off).
 * This is paid ONCE and shared by every employee — subsequent per-employee
 * lookups just read from this cache instantly.
 *
 * Cache TTL: 24 hours.
 *
 * @return array  { "item_id" => [compact_invoice, …], "name:foo" => […], … }
 */
function books_buildInvoiceIndex(string $token): array
{
    set_time_limit(600);   // up to 10 minutes for the initial build

    $config  = get_config();
    $orgId   = $config['books_org_id'];
    $baseUrl = rtrim($config['books_api_base'], '/');

    // Reuse the all-invoices list cache if valid; otherwise re-fetch.
    $listCache = new ApiCache('books_invoices');
    $list      = $listCache->isValid(3600)
        ? $listCache->read()
        : books_paginate($token, '/invoices', 'invoices', [
            'filter_by'   => 'Status.Paid',
            'per_page'    => 200,
            'sort_column' => 'date',
            'sort_order'  => 'D',
        ]);

    if (empty($list)) {
        return [];
    }

    $index = [];

    foreach (array_chunk($list, 10) as $batch) {
        $pending = $batch;

        // Retry loop: re-send any invoice that returns 429.
        for ($attempt = 0; $attempt < 3 && !empty($pending); $attempt++) {
            if ($attempt > 0) {
                sleep(6); // back-off before retry
            }

            $mh      = curl_multi_init();
            $handles = []; // curl_handle => invoice_stub

            foreach ($pending as $stub) {
                $invoiceId = $stub['invoice_id'] ?? '';
                if ($invoiceId === '') continue;

                $ch = curl_init(
                    "{$baseUrl}/invoices/" . rawurlencode($invoiceId)
                    . '?' . http_build_query(['organization_id' => $orgId])
                );
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 20,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_HTTPHEADER     => [
                        'Authorization: Zoho-oauthtoken ' . $token,
                        'Accept: application/json',
                    ],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[spl_object_id($ch)] = ['ch' => $ch, 'stub' => $stub];
            }

            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            $pending = [];

            foreach ($handles as ['ch' => $ch, 'stub' => $stub]) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body     = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);

                if ($httpCode === 429) {
                    $pending[] = $stub; // will retry
                    continue;
                }

                if ($httpCode !== 200) continue;

                $decoded = json_decode($body, true);
                if (!isset($decoded['invoice']['line_items'])) continue;

                $inv = $decoded['invoice'];

                $record = [
                    'invoice_id'     => $inv['invoice_id']     ?? '',
                    'invoice_number' => $inv['invoice_number'] ?? '',
                    'date'           => $inv['date']           ?? '',
                    'customer_name'  => $inv['customer_name']  ?? '',
                    'total'          => $inv['total']          ?? 0,
                ];

                // Index by item_id and by lowercase item name (for fallback matching).
                $seen = [];
                foreach ($inv['line_items'] as $li) {
                    $liId   = (string)($li['item_id'] ?? '');
                    $liName = strtolower(trim($li['name'] ?? ''));

                    if ($liId !== '' && !isset($seen[$liId])) {
                        $index[$liId][] = $record;
                        $seen[$liId]    = true;
                    }
                    if ($liName !== '' && !isset($seen['n:' . $liName])) {
                        $index['name:' . $liName][] = $record;
                        $seen['n:' . $liName]       = true;
                    }
                }
            }

            curl_multi_close($mh);
        }
    }

    return $index;
}

/**
 * Return (or trigger a build of) the global invoice index.
 * Used by books_getInvoicesByItem and books_getItemInvoiceStatus.
 */
function books_getInvoiceIndex(string $token): array
{
    $cache = new ApiCache('books_invoice_index');
    if ($cache->isValid(86400)) {   // 24-hour TTL
        return $cache->read();
    }

    $index = books_buildInvoiceIndex($token);
    $cache->write($index);
    return $index;
}

/**
 * Return paid invoices that contain a specific item (employee).
 *
 * Reads from the global invoice index (built once, shared across all employees).
 * First call after cache expiry triggers the index build (~3-5 min for 683 invoices);
 * all subsequent calls across all employees are instant (from cache).
 */
function books_getInvoicesByItem(string $token, string $itemId): array
{
    // Resolve item name from items cache (zero extra API calls).
    $itemName   = '';
    $itemsCache = new ApiCache('books_items');
    if ($itemsCache->isValid(7200)) {
        foreach ($itemsCache->read() as $it) {
            if ((string)($it['item_id'] ?? '') === (string)$itemId) {
                $itemName = strtolower(trim($it['name'] ?? ''));
                break;
            }
        }
    }
    if ($itemName === '') {
        $cfg      = get_config();
        $d        = books_get($token, rtrim($cfg['books_api_base'], '/') . '/items/' . rawurlencode($itemId) . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]));
        $itemName = strtolower(trim($d['item']['name'] ?? ''));
    }

    $index   = books_getInvoiceIndex($token);
    $matched = $index[(string)$itemId] ?? [];
    if (empty($matched) && $itemName !== '') {
        $matched = $index['name:' . $itemName] ?? [];
    }

    return $matched;
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
function books_paginate(string $token, string $path, string $dataKey, array $extraParams = []): array
{
    $config  = get_config();
    $baseUrl = rtrim($config['books_api_base'], '/') . $path;
    $orgId   = $config['books_org_id'];
    $page    = 1;
    $records = [];

    do {
        $url = $baseUrl . '?' . http_build_query(
            array_merge(['organization_id' => $orgId, 'page' => $page], $extraParams)
        );
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
 * Retries once after a 5-second back-off on HTTP 429 (rate limited).
 *
 * @throws RuntimeException on cURL or HTTP errors
 */
function books_get(string $token, string $url): array
{
    $attempt = 0;

    do {
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

        if ($httpCode === 429 && $attempt < 4) {
            // Rate limited — back off with increasing delays (10s, 20s, 40s, 60s).
            $delays = [10, 20, 40, 60];
            $wait   = $delays[$attempt];
            $attemptNum = $attempt + 1;
            error_log("Books API 429 rate limit for URL: {$url} — retrying after {$wait} s (attempt {$attemptNum})");
            sleep($wait);
            $attempt++;
            continue;
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

    } while (true);
}
