<?php
/**
 * CLI script — build the global invoice index cache.
 *
 * Run this from the command line (never via browser):
 *   php warm_invoice_index.php
 *
 * The index maps each invoice's line items (by item_id, normalised name, and
 * tokens) to invoice stubs. It is used by the Transactions tab to find paid
 * invoices for employees whose items are entered as free text in Zoho Books.
 *
 * This script makes ~1 API call per invoice.  With ~500 invoices and the
 * 3-per-batch / 5s-sleep pacing that equals roughly 150 calls over ~10 min.
 * Run it overnight or when the dashboard is not actively in use.
 *
 * The resulting cache file is valid for 4 hours (14 400 s).  Run again to
 * refresh.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script must be run from the command line.\n");
}

require_once __DIR__ . '/zoho-dashboard/lib/helpers.php';
require_once __DIR__ . '/zoho-dashboard/lib/ZohoOAuth.php';
require_once __DIR__ . '/zoho-dashboard/lib/ApiCache.php';
require_once __DIR__ . '/zoho-dashboard/api/books.php';

echo "Obtaining Zoho access token...\n";
$oauth = new ZohoOAuth();
$token = $oauth->getValidAccessToken();
echo "Token OK.\n\n";

$lockFile = sys_get_temp_dir() . '/zoho_invoice_index.lock';
$lock     = @fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    if ($lock) fclose($lock);
    echo "Another process is already building the index. Exiting.\n";
    exit(1);
}

echo "Building invoice index (batch 3, 5 s between batches)...\n";
echo "This will take several minutes — do not interrupt.\n\n";

$start = microtime(true);
$index = books_buildInvoiceIndex($token);

if (empty($index)) {
    echo "ERROR: Index came back empty — not writing cache.\n";
    flock($lock, LOCK_UN);
    fclose($lock);
    exit(1);
}

$cache = new ApiCache('books_invoice_index');
$cache->write($index);

flock($lock, LOCK_UN);
fclose($lock);

$elapsed = round(microtime(true) - $start);
$keys    = count($index);
echo "Done in {$elapsed}s — indexed {$keys} invoice/line-item combinations.\n";
echo "Cache valid for 4 hours.\n";
