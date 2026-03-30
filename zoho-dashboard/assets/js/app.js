/**
 * app.js — Main dashboard application logic.
 *
 * On DOM ready:
 *  1. Fetches data from the PHP proxy for each required endpoint.
 *  2. Transforms raw Zoho API data into chart/table-friendly shapes.
 *  3. Calls ZohoCharts and ZohoTables renderers.
 *
 * All Zoho API calls go through /oms-zoho-dashboard/zoho-dashboard/api/proxy.php.
 * The browser never calls Zoho directly.
 *
 * Depends on: jQuery, charts.js, tables.js
 */

'use strict';

$(function () {

    const PROXY = '/oms-zoho-dashboard/zoho-dashboard/api/proxy.php';

    // -------------------------------------------------------------------------
    // Boot: load all data sets in parallel
    // -------------------------------------------------------------------------

    loadDashboard(false);

    $('#btn-refresh').on('click', function () {
        $(this).prop('disabled', true).text('Refreshing…');
        loadDashboard(true).always(() => {
            $(this).prop('disabled', false).text('Refresh now');
        });
    });

    function loadDashboard(forceRefresh) {
        const suffix = forceRefresh ? '&refresh=1' : '';

        return $.when(
            fetchEndpoint('books_recurring' + suffix),
            fetchEndpoint('books_invoices'  + suffix),
            fetchEndpoint('crm_employees'   + suffix),
        ).then(function (recurringRes, invoicesRes, employeesRes) {
            const recurring = recurringRes[0].data  || [];
            const invoices  = invoicesRes[0].data   || [];
            const employees = employeesRes[0].data  || [];

            renderSummaryCards(recurring);
            renderPledgeStatusChart(recurring);
            renderIncomeChart(invoices);
            renderEmployeeChartAndTable(recurring, employees);
            renderUpcomingTable(recurring);
            renderBalanceChart(invoices);
        }).fail(function (jqXHR) {
            if (jqXHR.status === 401) {
                window.location.href = '/zoho-dashboard/auth/connect.php';
            }
        });
    }

    // -------------------------------------------------------------------------
    // Fetch helper
    // -------------------------------------------------------------------------

    function fetchEndpoint(endpointWithParams) {
        // Strip any suffix from the key for the query string.
        return $.getJSON(PROXY + '?endpoint=' + endpointWithParams)
            .fail(function (jqXHR) {
                if (jqXHR.status === 401) {
                    window.location.href = '/zoho-dashboard/auth/connect.php';
                }
            });
    }

    // -------------------------------------------------------------------------
    // Summary cards
    // -------------------------------------------------------------------------

    function renderSummaryCards(recurring) {
        const active  = recurring.filter(r => r.status === 'active');
        const total   = active.reduce((sum, r) => sum + parseFloat(r.amount || 0), 0);
        const count   = active.length;

        // Target is a future configuration value — placeholder for now.
        const target  = 0;
        const pct     = target > 0 ? ((total / target) * 100).toFixed(1) + '%' : '—';

        $('#stat-total-pledged').text(formatCurrency(total));
        $('#stat-agency-target').text(target > 0 ? formatCurrency(target) : 'Not set');
        $('#stat-funded-pct').text(pct);
        $('#stat-active-pledges').text(count);
    }

    // -------------------------------------------------------------------------
    // Pledge status doughnut
    // -------------------------------------------------------------------------

    function renderPledgeStatusChart(recurring) {
        const counts = {};
        recurring.forEach(r => {
            const s = (r.status || 'unknown').toLowerCase();
            counts[s] = (counts[s] || 0) + 1;
        });
        ZohoCharts.renderPledgeStatus(counts);
    }

    // -------------------------------------------------------------------------
    // Income by month bar chart
    // -------------------------------------------------------------------------

    function renderIncomeChart(invoices) {
        // Aggregate paid invoices by month.
        const byMonth = {};

        invoices
            .filter(inv => inv.status === 'paid' && inv.date)
            .forEach(inv => {
                const d      = new Date(inv.date + 'T00:00:00');
                const label  = d.toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
                const amount = parseFloat(inv.total || 0);
                byMonth[label] = (byMonth[label] || 0) + amount;
            });

        // Sort chronologically (keys are 'MMM YYYY').
        const months = Object.entries(byMonth)
            .map(([label, amount]) => ({ label, amount, _date: new Date('01 ' + label) }))
            .sort((a, b) => a._date - b._date)
            .slice(-12)   // Last 12 months.
            .map(({ label, amount }) => ({ label, amount }));

        ZohoCharts.renderIncomeByMonth(months);
    }

    // -------------------------------------------------------------------------
    // Funding per employee (chart + table)
    // -------------------------------------------------------------------------

    function renderEmployeeChartAndTable(recurring, crmEmployees) {
        // Build a map of employee name -> pledge stats from recurring invoices.
        // The recurring invoice customer_name is used as the join key.
        const empMap = {};

        recurring.forEach(r => {
            const name   = r.customer_name || 'Unknown';
            const status = (r.status || 'unknown').toLowerCase();
            const amount = parseFloat(r.amount || 0);

            if (!empMap[name]) {
                empMap[name] = { name, pledged: 0, active: 0, paused: 0, stopped: 0, target: 0 };
            }

            empMap[name].pledged += amount;
            if (empMap[name][status] !== undefined) {
                empMap[name][status]++;
            }
        });

        // TODO: join with CRM employee records to get targets when CRM
        // employee module is configured with a target field.
        // For now, target defaults to 0 (shown as 'Not set' in tables).

        const employees = Object.values(empMap).map(emp => ({
            ...emp,
            pct: emp.target > 0 ? (emp.pledged / emp.target) * 100 : 0,
        }));

        ZohoCharts.renderFundingPerEmployee(employees);
        ZohoTables.renderPledgeDetail(employees);
    }

    // -------------------------------------------------------------------------
    // Upcoming invoice runs table
    // -------------------------------------------------------------------------

    function renderUpcomingTable(recurring) {
        const upcoming = recurring
            .filter(r => r.next_invoice_date)
            .map(r => ({
                customer_name:     r.customer_name     || '—',
                recurrence_name:   r.recurrence_name   || '—',
                next_invoice_date: r.next_invoice_date,
                amount:            parseFloat(r.amount || 0),
                status:            (r.status || 'unknown').toLowerCase(),
            }));

        ZohoTables.renderUpcomingInvoices(upcoming);
    }

    // -------------------------------------------------------------------------
    // Balance trend line chart
    // -------------------------------------------------------------------------

    function renderBalanceChart(invoices) {
        // Running total of paid invoice amounts by month as a proxy for balance trend.
        const byMonth = {};

        invoices
            .filter(inv => inv.status === 'paid' && inv.date)
            .forEach(inv => {
                const d      = new Date(inv.date + 'T00:00:00');
                const label  = d.toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
                byMonth[label] = (byMonth[label] || 0) + parseFloat(inv.total || 0);
            });

        const points = Object.entries(byMonth)
            .map(([label, balance]) => ({ label, balance, _date: new Date('01 ' + label) }))
            .sort((a, b) => a._date - b._date)
            .slice(-12)
            .map(({ label, balance }) => ({ label, balance }));

        ZohoCharts.renderBalanceTrend(points);
    }

    // -------------------------------------------------------------------------
    // Shared utilities
    // -------------------------------------------------------------------------

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-AU', {
            style: 'currency', currency: 'AUD', maximumFractionDigits: 0,
        }).format(amount);
    }

});
