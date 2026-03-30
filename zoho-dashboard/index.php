<?php
/**
 * Zoho Dashboard — main entry point.
 * Checks for valid OAuth tokens; redirects to auth flow if missing.
 */
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/ZohoOAuth.php';

$oauth = new ZohoOAuth();
if (!$oauth->hasValidTokens()) {
    header('Location: /oms-zoho-dashboard/zoho-dashboard/auth/connect.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en-AU">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zoho Dashboard</title>

    <!-- Chart.js CDN -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js"></script>
    <!-- jQuery CDN -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"
            integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo="
            crossorigin="anonymous"></script>

    <link rel="stylesheet" href="/oms-zoho-dashboard/zoho-dashboard/assets/css/dashboard.css">
</head>
<body>
    <header class="site-header">
        <h1>Mission Agency Dashboard</h1>
        <nav>
            <a href="#pledges">Pledges</a>
            <a href="#income">Income</a>
            <a href="#employees">Employees</a>
            <a href="/oms-zoho-dashboard/zoho-dashboard/auth/connect.php" class="btn-reauth">Re-authorise</a>
        </nav>
    </header>

    <main class="dashboard-grid">

        <!-- Agency-wide summary cards -->
        <section class="card-row" id="summary">
            <div class="card" id="card-total-pledged">
                <h2>Total Pledged</h2>
                <p class="stat" id="stat-total-pledged">Loading…</p>
            </div>
            <div class="card" id="card-agency-target">
                <h2>Agency Target</h2>
                <p class="stat" id="stat-agency-target">Loading…</p>
            </div>
            <div class="card" id="card-funded-pct">
                <h2>Funded</h2>
                <p class="stat" id="stat-funded-pct">Loading…</p>
            </div>
            <div class="card" id="card-active-pledges">
                <h2>Active Pledges</h2>
                <p class="stat" id="stat-active-pledges">Loading…</p>
            </div>
        </section>

        <!-- Pledge status breakdown -->
        <section class="card" id="pledges">
            <h2>Pledge Status Breakdown</h2>
            <canvas id="chart-pledge-status" height="280"></canvas>
        </section>

        <!-- Income by month -->
        <section class="card" id="income">
            <h2>Income by Month</h2>
            <canvas id="chart-income-month" height="280"></canvas>
        </section>

        <!-- Funding % per employee -->
        <section class="card wide" id="employees">
            <h2>Funding per Employee</h2>
            <canvas id="chart-funding-per-employee" height="320"></canvas>
        </section>

        <!-- Balance trends -->
        <section class="card" id="balance">
            <h2>Balance Trend</h2>
            <canvas id="chart-balance-trend" height="280"></canvas>
        </section>

        <!-- Upcoming invoice run dates -->
        <section class="card" id="upcoming">
            <h2>Upcoming Invoice Runs</h2>
            <div id="table-upcoming-invoices">Loading…</div>
        </section>

        <!-- Employee pledge detail table -->
        <section class="card wide" id="pledge-detail">
            <h2>Employee Pledge Detail</h2>
            <div id="table-pledge-detail">Loading…</div>
        </section>

    </main>

    <footer class="site-footer">
        <p>Data refreshed from Zoho every hour. <button id="btn-refresh">Refresh now</button></p>
    </footer>

    <script src="/oms-zoho-dashboard/zoho-dashboard/assets/js/charts.js"></script>
    <script src="/oms-zoho-dashboard/zoho-dashboard/assets/js/tables.js"></script>
    <script src="/oms-zoho-dashboard/zoho-dashboard/assets/js/app.js"></script>
</body>
</html>
