<?php
/**
 * Individual employee dashboard.
 * Shows overview details, income by month table, and income trend chart
 * for a single Zoho Books Item (employee).
 *
 * Query param: ?id=<item_id>
 */
require_once __DIR__ . '/lib/helpers.php';
require_auth();

// Sanitise the item ID — Zoho item IDs are numeric strings.
$itemId = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['id'] ?? '');
if ($itemId === '') {
    header('Location: /oms-zoho-dashboard/zoho-dashboard/index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Dashboard — Mission Agency</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>
    <link rel="stylesheet" href="/oms-zoho-dashboard/zoho-dashboard/assets/css/dashboard.css">
</head>
<body>

    <header class="site-header">
        <a href="/oms-zoho-dashboard/zoho-dashboard/index.php" class="btn-back">← Employees</a>
        <h1 id="page-title">Employee Dashboard</h1>
        <nav>
            <a href="/oms-zoho-dashboard/zoho-dashboard/auth/connect.php" class="btn-reauth">Re-authorise</a>
        </nav>
    </header>

    <main class="page-employee">

        <!-- Employee information -->
        <section class="detail-card" id="section-overview">
            <h2>Employee Information</h2>
            <div id="emp-overview"><p class="loading">Loading…</p></div>
        </section>

        <!-- Individual invoices for this item -->
        <section class="detail-card" id="section-invoices">
            <h2>Invoices</h2>
            <div id="invoices-table-wrap"><p class="loading">Loading…</p></div>
        </section>

    </main>

    <footer class="site-footer">
        <p>Data refreshed from Zoho every hour.</p>
        <button id="btn-refresh">Refresh now</button>
    </footer>

    <!-- Pass the sanitised item ID to JS -->
    <script>var ITEM_ID = <?= json_encode($itemId) ?>;</script>
    <script src="/oms-zoho-dashboard/zoho-dashboard/assets/js/tables.js"></script>
    <script src="/oms-zoho-dashboard/zoho-dashboard/assets/js/employee.js"></script>

</body>
</html>
