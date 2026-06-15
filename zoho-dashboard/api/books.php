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
    return books_paginate($token, '/items', 'items', [
        'filter_by' => 'Status.All',
        'per_page'  => 200,
    ]);
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
 * Return paid invoices for an employee's contact (customer_id).
 * Resolves item → contact, then filters GET /invoices by customer_id.
 */
function books_getInvoicesByItem(string $token, string $itemId): array
{
    $contact    = books_getEmployeeContact($token, $itemId);
    $customerId = $contact['contact_id'] ?? '';
    if ($customerId === '') return [];

    return books_paginate($token, '/invoices', 'invoices', [
        'customer_id' => $customerId,
        'filter_by'   => 'Status.Paid',
    ]);
}

/**
 * Return invoices for a specific item using GET /invoices?item_id=...
 * Follows the endpoint: /invoices?organization_id={org_id}&item_id={item_id}
 */
function books_getInvoiceTransactions(string $token, string $itemId): array
{
    if ($itemId === '') return [];

    $dateFrom = date('Y-m-d', strtotime('-12 months'));

    $invoices = books_paginate($token, '/invoices', 'invoices', [
        'item_id'    => $itemId,
        'date_start' => $dateFrom,
        'per_page'   => 200,
    ]);

    return array_values(array_filter($invoices, fn($inv) => strtolower($inv['status'] ?? '') === 'paid'));
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
