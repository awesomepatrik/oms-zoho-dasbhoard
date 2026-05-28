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
 * Build and return the global invoice index on every call (no cache).
 * Used by books_getInvoicesByItem and books_getItemInvoiceStatus.
 */
function books_getInvoiceIndex(string $token): array
{
    return books_buildInvoiceIndex($token);
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
 * Fetches the paid invoice list filtered by item_id and date, then fetches each
 * invoice detail server-side to extract the correct per-line-item qty, price, and
 * total. Only invoices that actually contain a matching line_item are returned.
 */
function books_getInvoiceTransactions(string $token, string $itemId): array
{
    $cfg     = get_config();
    $orgId   = $cfg['books_org_id'];
    $baseUrl = rtrim($cfg['books_api_base'], '/');

    $dateFrom = date('Y-m-d', strtotime('-12 months'));

    $list = books_paginate($token, '/invoices', 'invoices', [
        'filter_by'  => 'Status.Paid',
        'item_id'    => $itemId,
        'date_start' => $dateFrom,
        'per_page'   => 200,
    ]);

    if (empty($list)) return [];

    // Fetch all invoice details in one parallel curl_multi pass.
    // Filtered list is per-employee over 12 months (typically 12-52 invoices),
    // so one batch is safe within Zoho's rate limit.
    $result  = [];
    $pending = array_values(array_filter($list, fn($s) => ($s['invoice_id'] ?? '') !== ''));

    for ($attempt = 0; $attempt < 3 && !empty($pending); $attempt++) {
        if ($attempt > 0) sleep(6);

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($pending as $stub) {
            $ch = curl_init(
                "{$baseUrl}/invoices/" . rawurlencode($stub['invoice_id'])
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
                $pending[] = $stub;
                continue;
            }

            if ($httpCode !== 200) continue;

            $decoded = json_decode($body, true);
            $inv     = $decoded['invoice'] ?? [];
            if (empty($inv)) continue;

            foreach ($inv['line_items'] ?? [] as $li) {
                if ((string)($li['item_id'] ?? '') !== (string)$itemId) continue;

                $result[] = [
                    'invoice_id'     => $inv['invoice_id']     ?? '',
                    'invoice_number' => $inv['invoice_number'] ?? '',
                    'date'           => $inv['date']           ?? '',
                    'customer_name'  => $inv['customer_name']  ?? '',
                    'status'         => $inv['status']         ?? '',
                    'quantity'       => (float)($li['quantity']   ?? 1),
                    'price'          => (float)($li['rate']        ?? 0),
                    'total'          => (float)($li['item_total']  ?? $li['rate'] ?? 0),
                ];
                break;
            }
        }

        curl_multi_close($mh);
    }

    // Sort newest first.
    usort($result, fn($a, $b) => strcmp($b['date'], $a['date']));

    return $result;
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
