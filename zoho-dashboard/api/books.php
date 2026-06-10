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
 * Fetch all recurring invoices (pledge schedules) — active only.
 */
function books_getRecurringInvoices(string $token): array
{
    return books_recurringPaginate($token, ['filter_by' => 'Status.Active']);
}

/**
 * Fetch all recurring invoices regardless of status (used by Support tab matching).
 */
function books_getAllRecurringInvoices(string $token): array
{
    return books_recurringPaginate($token);
}

/**
 * Fetch the full detail of a single recurring invoice by ID.
 * The detail response contains the actual 'amount' (Invoice Amount) field
 * that is absent from the list endpoint.
 */
function books_getRecurringDetail(string $token, string $riId): array
{
    $cfg = get_config();
    $url = rtrim($cfg['books_api_base'], '/') . '/recurringinvoices/' . rawurlencode($riId)
         . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);
    $d = books_get($token, $url);
    return $d['recurring_invoice'] ?? [];
}

/**
 * Paginate /recurringinvoices, tolerating both response-key spellings:
 *   - 'recurring_invoices'  (v3 documented key)
 *   - 'recurringinvoices'   (alternate spelling seen in some regions)
 */
function books_recurringPaginate(string $token, array $extraParams = []): array
{
    $cfg     = get_config();
    $baseUrl = rtrim($cfg['books_api_base'], '/') . '/recurringinvoices';
    $orgId   = $cfg['books_org_id'];
    $page    = 1;
    $records = [];

    do {
        $url      = $baseUrl . '?' . http_build_query(
            array_merge(['organization_id' => $orgId, 'page' => $page], $extraParams)
        );
        $response = books_get($token, $url);

        // Zoho Books returns either 'recurring_invoices' or 'recurringinvoices'.
        $data = $response['recurring_invoices'] ?? $response['recurringinvoices'] ?? null;
        if ($data === null || !is_array($data)) {
            error_log('books_recurringPaginate: unexpected response keys: ' . implode(', ', array_keys($response)));
            break;
        }

        $records = array_merge($records, $data);
        $hasMore = $response['page_context']['has_more_page'] ?? false;
        $page++;
    } while ($hasMore);

    return $records;
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
 * Fetch a single item by ID — includes custom fields not returned by the list endpoint.
 */
function books_getItemDetail(string $token, string $itemId): array
{
    $cfg = get_config();
    $url = rtrim($cfg['books_api_base'], '/') . '/items/' . rawurlencode($itemId)
         . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);
    $d = books_get($token, $url);
    return $d['item'] ?? [];
}

/**
 * Fetch a single contact by ID — includes contact_persons and custom_fields.
 */
function books_getContactDetail(string $token, string $contactId): array
{
    $cfg = get_config();
    $url = rtrim($cfg['books_api_base'], '/') . '/contacts/' . rawurlencode($contactId)
         . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);
    $d = books_get($token, $url);
    return $d['contact'] ?? [];
}

/**
 * Find the employee family contact for an item by searching Zoho Books
 * contacts whose name matches the item's "Receipient Group Name" custom field
 * (falls back to the item name). Returns the full contact detail.
 */
function books_getEmployeeContact(string $token, string $itemId): array
{
    $cfg     = get_config();
    $baseUrl = rtrim($cfg['books_api_base'], '/');
    $orgQs   = http_build_query(['organization_id' => $cfg['books_org_id']]);

    $d    = books_get($token, "{$baseUrl}/items/" . rawurlencode($itemId) . "?{$orgQs}");
    $item = $d['item'] ?? [];

    // 1. Use the "Project Manager" tag — it holds the missionary/family name.
    $searchName = '';
    foreach ($item['tags'] ?? [] as $tag) {
        if (stripos($tag['tag_name'] ?? '', 'project manager') !== false) {
            $searchName = trim($tag['tag_option_name'] ?? '');
            break;
        }
    }
    // 2. Fall back to "Receipient Group Name" custom field.
    if ($searchName === '') {
        foreach ($item['custom_fields'] ?? [] as $cf) {
            if (stripos($cf['label'] ?? '', 'receipient group name') !== false
                || stripos($cf['label'] ?? '', 'recipient group name') !== false) {
                $searchName = trim((string)($cf['value'] ?? ''));
                break;
            }
        }
    }
    // 3. Fall back to item name.
    if ($searchName === '') $searchName = trim($item['name'] ?? '');
    if ($searchName === '') return [];

    // Use only the first word for the API search (Zoho requires exact substring
    // match, so "Kumar Ben & Christie" won't match "Kumar, Ben & Christie").
    // Levenshtein below picks the best contact from the broader result set.
    $firstWord = preg_split('/[\s,]+/', $searchName)[0] ?? $searchName;
    $url      = "{$baseUrl}/contacts?{$orgQs}&" . http_build_query(['contact_name_contains' => $firstWord]);
    $results  = books_get($token, $url);
    $contacts = $results['contacts'] ?? [];
    if (empty($contacts)) return [];

    // Pick closest match by Levenshtein distance.
    $best      = null;
    $bestScore = PHP_INT_MAX;
    $needle    = strtolower($searchName);
    foreach ($contacts as $c) {
        $score = levenshtein($needle, strtolower($c['contact_name'] ?? ''));
        if ($score < $bestScore) {
            $bestScore = $score;
            $best      = $c;
        }
    }
    if (!$best) return [];

    return books_getContactDetail($token, $best['contact_id']);
}

/**
 * Return a map of item_id => ['pm_id' => ..., 'pm_name' => ...].
 * Fetches every item's detail directly from the Zoho API (no cache).
 * Custom fields (including Project Manager) are only available in the detail endpoint.
 */
function books_getItemsPmMap(string $token): array
{
    $cfg     = get_config();
    $baseUrl = rtrim($cfg['books_api_base'], '/');
    $orgQs   = http_build_query(['organization_id' => $cfg['books_org_id']]);

    $items = books_getItems($token);
    $map   = [];

    foreach ($items as $stub) {
        $itemId = (string)($stub['item_id'] ?? '');
        if ($itemId === '') continue;

        try {
            $d    = books_get($token, "{$baseUrl}/items/" . rawurlencode($itemId) . "?{$orgQs}");
            $item = $d['item'] ?? [];
        } catch (RuntimeException $e) {
            continue;
        }

        foreach ($item['custom_fields'] ?? [] as $cf) {
            if (stripos($cf['label'] ?? '', 'project manager') !== false) {
                $pmId   = trim((string)($cf['value'] ?? ''));
                $pmName = trim((string)($cf['value_formatted'] ?? $pmId));
                if ($pmId !== '') {
                    $map[$itemId] = ['pm_id' => $pmId, 'pm_name' => $pmName];
                }
                break;
            }
        }
    }

    return $map;
}

/**
 * Fetch custom field definitions for items from Zoho Books settings.
 * Returns the full array of custom field objects (each has customfield_id, label, etc.).
 * Useful for getting field IDs when an item has no value set for that field.
 */
function books_getItemCustomFields(string $token): array
{
    $cfg         = get_config();
    $msrKeywords = ['msr', 'monthly support', 'support requirement', 'support req'];

    $normalize = function (array $cf): array {
        $id = $cf['customfield_id'] ?? $cf['field_id'] ?? '';
        return ['customfield_id' => $id, 'field_id' => $id, 'label' => $cf['label'] ?? ''];
    };

    // Ask Zoho Books settings API for item custom field definitions.
    // Zoho returns the full module map: { "item": [...], "invoice": [...], ... }
    $baseUrl = rtrim($cfg['books_api_base'], '/');
    $orgQs   = http_build_query(['organization_id' => $cfg['books_org_id']]);
    try {
        $url    = "{$baseUrl}/settings/customfields?{$orgQs}";
        $d      = books_get($token, $url);
        // Fields for items live under the 'item' key in the response.
        $fields = $d['item'] ?? $d['customfields'] ?? $d['custom_fields'] ?? [];
        foreach ($fields as $cf) {
            $lbl = strtolower($cf['label'] ?? '');
            foreach ($msrKeywords as $kw) {
                if (str_contains($lbl, $kw)) {
                    return [$normalize($cf)];
                }
            }
        }
    } catch (RuntimeException $e) {
        error_log('books_getItemCustomFields settings error: ' . $e->getMessage());
    }

    return [];
}

/**
 * Return a map of item_id => true for every item that appears in at least
 * one paid invoice. Builds the invoice index directly from the Zoho API.
 */
function books_getItemInvoiceStatus(string $token): array
{
    $index  = books_getInvoiceIndex($token);
    $status = [];
    foreach (array_keys($index) as $key) {
        if (!str_starts_with($key, 'name:') && !str_starts_with($key, 'tokens:')) {
            $status[$key] = true;
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

    $list = books_paginate($token, '/invoices', 'invoices', [
        'filter_by'   => 'Status.Paid',
        'per_page'    => 200,
        'sort_column' => 'date',
        'sort_order'  => 'D',
    ]);

    if (empty($list)) {
        return [];
    }

    $index = [];

    foreach (array_chunk($list, 3) as $batchIndex => $batch) {
        // Pause between batches — 3 invoices × 5s ≈ 18 req/min, well under rate limit.
        if ($batchIndex > 0) sleep(5);

        $pending = $batch;

        // Retry loop: re-send any invoice that returns 429 or code 44.
        for ($attempt = 0; $attempt < 3 && !empty($pending); $attempt++) {
            if ($attempt > 0) {
                sleep(15); // longer back-off before retry
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
                // Code 44 = org rate block — retry this invoice.
                if (isset($decoded['code']) && (int)$decoded['code'] === 44) {
                    $pending[] = $stub;
                    continue;
                }
                if (!isset($decoded['invoice']['line_items'])) continue;

                $inv = $decoded['invoice'];

                $record = [
                    'invoice_id'     => $inv['invoice_id']     ?? '',
                    'invoice_number' => $inv['invoice_number'] ?? '',
                    'date'           => $inv['date']           ?? '',
                    'customer_name'  => $inv['customer_name']  ?? '',
                    'total'          => $inv['total']          ?? 0,
                ];

                // Index by item_id, normalised name, and sorted-word tokens.
                $seen = [];
                foreach ($inv['line_items'] as $li) {
                    $liId     = (string)($li['item_id'] ?? '');
                    $liName   = books_normalise_name($li['name'] ?? '');
                    $liTokens = books_name_tokens($li['name'] ?? '');

                    if ($liId !== '' && !isset($seen[$liId])) {
                        $index[$liId][] = $record;
                        $seen[$liId]    = true;
                    }
                    if ($liName !== '' && !isset($seen['n:' . $liName])) {
                        $index['name:' . $liName][] = $record;
                        $seen['n:' . $liName]       = true;
                    }
                    if ($liTokens !== '' && !isset($seen['t:' . $liTokens])) {
                        $index['tokens:' . $liTokens][] = $record;
                        $seen['t:' . $liTokens]         = true;
                    }
                }
            }

            curl_multi_close($mh);
        }
    }

    return $index;
}

/**
 * Return the global invoice index, building and caching it on first call.
 * A file lock prevents concurrent builds from doubling the API load on Zoho.
 * ignore_user_abort keeps the build running even when the browser disconnects.
 */
function books_getInvoiceIndex(string $token): array
{
    require_once __DIR__ . '/../lib/ApiCache.php';
    $cache = new ApiCache('books_invoice_index');

    // Web requests only serve from cache — never auto-build.
    // Building the index requires ~1 API call per invoice and will exhaust the
    // daily 10,000-call quota. Run warm_invoice_index.php from the CLI instead.
    if ($cache->isValid(14400)) {
        return $cache->read();
    }

    return [];
}

/**
 * Return paid invoices that contain a specific item (employee).
 * Uses the Zoho Books item_id filter directly — no index required.
 */
function books_getInvoicesByItem(string $token, string $itemId): array
{
    return books_paginate($token, '/invoices', 'invoices', [
        'filter_by' => 'Status.Paid',
        'item_id'   => $itemId,
    ]);
}

/**
 * Return paid invoice transactions for a specific item over the last 12 months.
 *
 * Uses two sources, tried in order:
 *
 * 1. Invoice index cache (built via warm_invoice_index.php CLI) — covers both
 *    linked and free-text line items. Costs 0 extra API calls when warm.
 *
 * 2. Direct item_id filter — 2-5 API calls. Works for items that have a Zoho
 *    item_id set. Returns empty for free-text items (no item_id filter exists
 *    in the Zoho Books API for line item names).
 *
 * The index is never auto-built here — run warm_invoice_index.php from the CLI.
 */
function books_getInvoiceTransactions(string $token, string $itemId): array
{
    $cfg      = get_config();
    $orgId    = $cfg['books_org_id'];
    $baseUrl  = rtrim($cfg['books_api_base'], '/');
    $dateFrom = date('Y-m-d', strtotime('-12 months'));

    require_once __DIR__ . '/../lib/ApiCache.php';

    // Resolve item name for scored line-item matching (used when index is warm).
    $itemCache = new ApiCache('books_item_detail_' . $itemId);
    $itemData  = $itemCache->isValid(3600) ? $itemCache->read() : [];
    if (empty($itemData)) {
        try { $itemData = books_getItemDetail($token, $itemId); } catch (RuntimeException $e) {}
    }
    $normName = books_normalise_name($itemData['name'] ?? '');
    $tokens   = books_name_tokens($itemData['name'] ?? '');

    $invoiceIds = [];

    // --- Source 1: invoice index (warm cache, zero extra API calls) ---
    $indexCache = new ApiCache('books_invoice_index');
    if ($indexCache->isValid(14400)) {
        $index = $indexCache->read();
        $seen  = [];
        foreach (array_filter([
            $itemId,
            $normName !== '' ? 'name:' . $normName : '',
            $tokens   !== '' ? 'tokens:' . $tokens  : '',
        ]) as $key) {
            foreach ($index[$key] ?? [] as $rec) {
                $id = $rec['invoice_id'] ?? '';
                if ($id !== '' && !isset($seen[$id]) && ($rec['date'] ?? '') >= $dateFrom) {
                    $invoiceIds[] = $id;
                    $seen[$id]    = true;
                }
            }
        }
    }

    // --- Source 2: item_id filter (2-5 API calls, linked items only) ---
    if (empty($invoiceIds)) {
        $list = books_paginate($token, '/invoices', 'invoices', [
            'filter_by'  => 'Status.Paid',
            'item_id'    => $itemId,
            'date_start' => $dateFrom,
            'per_page'   => 200,
        ]);
        foreach ($list as $stub) {
            $id = $stub['invoice_id'] ?? '';
            if ($id !== '') $invoiceIds[] = $id;
        }
    }

    if (empty($invoiceIds)) return [];

    $result = [];

    // --- Serve already-cached invoice details without an API call ---
    // Shares the same cache key as the books_invoice_detail endpoint (1-hour TTL).
    $toFetch = [];
    foreach (array_unique($invoiceIds) as $invoiceId) {
        $detailCache = new ApiCache("books_invoice_detail_{$invoiceId}");
        if ($detailCache->isValid(3600)) {
            $inv = $detailCache->read();
            if (!empty($inv)) {
                $bestScore = 0;
                $bestLi    = null;
                foreach ($inv['line_items'] ?? [] as $li) {
                    $score = books_matchLineItem($li, $itemId, $normName, $tokens);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestLi    = $li;
                        if ($score === 4) break;
                    }
                }
                if ($bestLi !== null && $bestScore > 0) {
                    $result[] = [
                        'invoice_id'     => $inv['invoice_id']     ?? '',
                        'invoice_number' => $inv['invoice_number'] ?? '',
                        'date'           => $inv['date']           ?? '',
                        'customer_name'  => $inv['customer_name']  ?? '',
                        'status'         => $inv['status']         ?? '',
                        'quantity'       => (float)($bestLi['quantity']  ?? 1),
                        'price'          => (float)($bestLi['rate']       ?? 0),
                        'total'          => (float)($bestLi['item_total'] ?? $bestLi['rate'] ?? 0),
                    ];
                }
                continue;
            }
        }
        $toFetch[] = $invoiceId;
    }

    // --- Fetch details in batches of 5 (safe under Zoho's rate limit) ---
    $dailyQuotaHit = false;
    foreach (array_chunk($toFetch, 5) as $chunkIdx => $chunk) {
        if ($dailyQuotaHit) break;
        if ($chunkIdx > 0) sleep(1); // brief pause between batches

        $pending = array_values($chunk);

        for ($attempt = 0; $attempt < 2 && !empty($pending) && !$dailyQuotaHit; $attempt++) {
            if ($attempt > 0) sleep(10);

            $mh      = curl_multi_init();
            $handles = [];

            foreach ($pending as $invoiceId) {
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
                $handles[spl_object_id($ch)] = ['ch' => $ch, 'invoice_id' => $invoiceId];
            }

            do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running > 0);

            $pending = [];

            foreach ($handles as ['ch' => $ch, 'invoice_id' => $invoiceId]) {
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $body     = curl_multi_getcontent($ch);
                curl_multi_remove_handle($mh, $ch);

                if ($httpCode === 429) {
                    $bodyJson = json_decode($body, true);
                    if (isset($bodyJson['code']) && (int)$bodyJson['code'] === 45) {
                        $dailyQuotaHit = true; // daily limit — stop all fetching
                    } else {
                        $pending[] = $invoiceId; // transient throttle — retry
                    }
                    continue;
                }
                if ($httpCode !== 200) continue;

                $decoded = json_decode($body, true);
                // Code 44 = org rate block — queue for retry.
                if (isset($decoded['code']) && (int)$decoded['code'] === 44) {
                    $pending[] = $invoiceId;
                    continue;
                }

                $inv = $decoded['invoice'] ?? [];
                if (empty($inv)) continue;

                // Cache this invoice detail so future lookups (same or other employees) skip the API call.
                (new ApiCache("books_invoice_detail_{$invoiceId}"))->write($inv);

                $bestScore = 0;
                $bestLi    = null;
                foreach ($inv['line_items'] ?? [] as $li) {
                    $score = books_matchLineItem($li, $itemId, $normName, $tokens);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestLi    = $li;
                        if ($score === 4) break;
                    }
                }

                if ($bestLi === null || $bestScore === 0) continue;

                $result[] = [
                    'invoice_id'     => $inv['invoice_id']     ?? '',
                    'invoice_number' => $inv['invoice_number'] ?? '',
                    'date'           => $inv['date']           ?? '',
                    'customer_name'  => $inv['customer_name']  ?? '',
                    'status'         => $inv['status']         ?? '',
                    'quantity'       => (float)($bestLi['quantity']  ?? 1),
                    'price'          => (float)($bestLi['rate']       ?? 0),
                    'total'          => (float)($bestLi['item_total'] ?? $bestLi['rate'] ?? 0),
                ];
            }

            curl_multi_close($mh);
        }
    }

    usort($result, fn($a, $b) => strcmp($b['date'], $a['date']));
    return $result;
}

/**
 * Score how well a line item matches a target item.
 *
 * 4 = exact item_id match (definitive)
 * 3 = exact normalised name match
 * 2 = all sorted-word tokens match (word-order insensitive)
 * 1 = majority token overlap (handles partial name variations)
 * 0 = no match
 */
function books_matchLineItem(array $li, string $itemId, string $normName, string $tokens): int
{
    if ($itemId !== '' && (string)($li['item_id'] ?? '') === $itemId) {
        return 4;
    }
    $liNorm = books_normalise_name($li['name'] ?? '');
    if ($normName !== '' && $liNorm === $normName) {
        return 3;
    }
    $liTokens = books_name_tokens($li['name'] ?? '');
    if ($tokens !== '' && $liTokens !== '' && $liTokens === $tokens) {
        return 2;
    }
    if ($tokens !== '' && $liTokens !== '') {
        $tArr    = explode('|', $tokens);
        $liArr   = explode('|', $liTokens);
        $overlap = count(array_intersect($tArr, $liArr));
        $minLen  = min(count($tArr), count($liArr));
        if ($minLen > 0 && $overlap >= (int)ceil($minLen / 2)) {
            return 1;
        }
    }
    return 0;
}

function books_getInvoiceDetail(string $token, string $invoiceId): array
{
    $cfg = get_config();
    $url = rtrim($cfg['books_api_base'], '/') . '/invoices/' . rawurlencode($invoiceId)
         . '?' . http_build_query(['organization_id' => $cfg['books_org_id']]);
    $d = books_get($token, $url);
    return $d['invoice'] ?? [];
}

/**
 * Return recurring invoices that contain a specific item (employee).
 *
 * Fetches the full list of recurring invoices, then fetches each detail record
 * directly to read line_items and filter by item_id.
 * Each returned record carries the per-line-item amount as the monthly pledge.
 */
function books_getRecurringByItem(string $token, string $itemId): array
{
    $cfg     = get_config();
    $orgId   = $cfg['books_org_id'];
    $baseUrl = rtrim($cfg['books_api_base'], '/');

    $list   = books_paginate($token, '/recurringinvoices', 'recurringinvoices');
    $result = [];

    foreach ($list as $stub) {
        $riId = $stub['recurring_invoice_id'] ?? '';
        if ($riId === '') continue;

        $url = "{$baseUrl}/recurringinvoices/" . rawurlencode($riId)
             . '?' . http_build_query(['organization_id' => $orgId]);
        try {
            $d = books_get($token, $url);
        } catch (RuntimeException $e) {
            error_log("books_getRecurringByItem: failed to fetch {$riId}: " . $e->getMessage());
            continue;
        }
        $ri = $d['recurring_invoice'] ?? [];
        if (empty($ri)) continue;

        foreach ($ri['line_items'] ?? [] as $li) {
            if ((string)($li['item_id'] ?? '') !== (string)$itemId) continue;

            $liAmt    = (float)($li['item_total'] ?? $li['rate'] ?? $ri['amount'] ?? 0);
            $result[] = [
                'recurring_invoice_id' => $ri['recurring_invoice_id'] ?? '',
                'recurrence_name'      => $ri['recurrence_name']      ?? '',
                'customer_name'        => $ri['customer_name']        ?? '',
                'amount'               => $liAmt,
                'status'               => $ri['status']               ?? '',
                'next_invoice_date'    => $ri['next_invoice_date']    ?? '',
                'start_date'           => $ri['start_date']           ?? '',
            ];
            break; // one entry per recurring invoice per employee
        }
    }

    return $result;
}

// -----------------------------------------------------------------------------
// Internal helpers
// -----------------------------------------------------------------------------

/**
 * Normalise an item/line-item name for reliable index matching.
 *
 * Handles the most common variations seen in Zoho Books exports:
 *  - Mixed case          → lowercase
 *  - Leading/trailing whitespace → trimmed
 *  - Multiple spaces     → single space
 *  - " & " / " and "    → normalised to " & " (canonical form)
 *  - Full-width spaces / non-breaking spaces → regular space
 */
function books_normalise_name(string $name): string
{
    $name = mb_strtolower(trim($name));
    // Collapse all whitespace variants to a single space.
    $name = preg_replace('/[\s\
    x{00A0}\x{200B}]+/u', ' ', $name);
    // Normalise "and" (whole word, surrounded by spaces) to "&".
    $name = preg_replace('/\band\b/', '&', $name);
    // Collapse any spaces that now surround "&"  →  " & ".
    $name = preg_replace('/\s*&\s*/', ' & ', $name);
    return trim($name);
}

/**
 * Reduce a name to a canonical sorted-word token string.
 *
 * Strips all punctuation, removes common stop-words, sorts the remaining
 * words alphabetically, and joins with "|".  This lets us match names
 * regardless of word order or minor punctuation differences.
 *
 * e.g. "Kumar, Ben and Christie"  → "ben|christie|kumar"
 *      "Ben Kumar & Christie"     → "ben|christie|kumar"
 *      "Kumar Ben & Christie"     → "ben|christie|kumar"
 */
function books_name_tokens(string $name): string
{
    static $stopWords = ['and', 'or', 'the', 'of', 'for', 'in', 'a', 'an', 'mr', 'mrs', 'ms', 'dr'];
    $name = mb_strtolower($name);
    preg_match_all('/[a-z]{2,}/u', $name, $m);
    $words = array_filter($m[0], fn($w) => !in_array($w, $stopWords));
    sort($words);
    return implode('|', array_values($words));
}

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

        if ($httpCode === 429) {
            // Read the body to distinguish per-minute throttle from daily quota exhaustion.
            $bodyPreview = substr($body, 0, 300);
            $bodyJson    = json_decode($body, true);
            $zohoCode    = isset($bodyJson['code']) ? (int)$bodyJson['code'] : 0;

            // Code 45 = daily 10,000-call quota exhausted. No point retrying.
            if ($zohoCode === 45) {
                error_log("Books API code 45 (daily quota exhausted) for URL: {$url} — failing immediately.");
                throw new RuntimeException("Books API daily call quota exhausted (code 45). Try again tomorrow.");
            }

            // Transient per-minute throttle — retry twice with short back-off.
            if ($attempt < 2) {
                $wait = $attempt === 0 ? 10 : 20;
                error_log("Books API 429 for URL: {$url} — retrying after {$wait}s (attempt " . ($attempt + 1) . ")");
                sleep($wait);
                $attempt++;
                continue;
            }

            error_log("Books API HTTP 429 for URL: {$url} — Body: {$bodyPreview}");
            throw new RuntimeException("Books API returned HTTP 429.");
        }

        if ($httpCode !== 200) {
            error_log("Books API HTTP {$httpCode} for URL: {$url} — Body: " . substr($body, 0, 500));
            throw new RuntimeException("Books API returned HTTP {$httpCode}.");
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Books API returned non-JSON response.');
        }

        // Code 44 = org-level per-minute rate block. One short retry for user-facing calls.
        if (isset($decoded['code']) && (int)$decoded['code'] === 44 && $attempt < 1) {
            error_log("Books API code 44 (org rate limit) for URL: {$url} — retrying after 10s (attempt " . ($attempt + 1) . ")");
            sleep(10);
            $attempt++;
            continue;
        }

        if (isset($decoded['code']) && $decoded['code'] !== 0) {
            $msg = $decoded['message'] ?? 'Unknown Books API error';
            throw new RuntimeException("Books API error {$decoded['code']}: {$msg}");
        }

        return $decoded;

    } while (true);
}
