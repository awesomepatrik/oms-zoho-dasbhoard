<?php
/**
 * Employees list page — two-panel master/detail layout.
 */
require_once __DIR__ . '/lib/helpers.php';
require_auth();
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employees — Mission Agency Dashboard</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="/oms-zoho-dashboard/zoho-dashboard/assets/css/dashboard.css">
</head>
<body class="app-body">

    <header class="site-header">
        <h1>One Mission Society</h1>
        <nav>
            <a href="/oms-zoho-dashboard/zoho-dashboard/auth/connect.php" class="btn-reauth">Re-authorise</a>
        </nav>
    </header>

    <div class="app-layout">

        <!-- ── Sidebar ───────────────────────────────────────────── -->
        <aside class="app-sidebar">
            <div class="sidebar-header">
                <div class="sidebar-title-row">
                    <span class="sidebar-title">All Items</span>
                    <button id="btn-refresh" class="sidebar-refresh" title="Refresh data">&#8635;</button>
                </div>
                <input type="search" id="emp-search"
                       class="sidebar-search"
                       placeholder="Search…"
                       autocomplete="off">
            </div>
            <div id="emp-list">
                <p class="sidebar-status">Loading…</p>
            </div>
        </aside>

        <!-- ── Detail panel ──────────────────────────────────────── -->
        <div class="app-detail" id="app-detail">
            <div class="detail-empty">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2">
                    <path d="M20 7H4a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z"/>
                    <path d="M16 3H8a2 2 0 0 0-2 2v2h12V5a2 2 0 0 0-2-2z"/>
                </svg>
                <p>Select an employee to view their details</p>
            </div>
        </div>

    </div>

    <script src="/oms-zoho-dashboard/zoho-dashboard/assets/js/employees.js"></script>

</body>
</html>
