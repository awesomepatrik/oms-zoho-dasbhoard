/**
 * app.js — Main dashboard application logic.
 *
 * On DOM ready:
 *  1. Fetches data from the PHP proxy for each required endpoint.
 *  2. Transforms raw Zoho API data into chart/table-friendly shapes.
 *  3. Calls ZohoCharts and ZohoTables renderers.
 *
 * Employees are sourced from Zoho Books Items.
 * Per-employee income is derived from paid invoices whose line items
 * reference each employee's item_id.
 *
 * Depends on: jQuery, charts.js, tables.js
 */

'use strict';

$(function () {

    const PROXY = '/oms-zoho-dashboard/zoho-dashboard/api/proxy.php';

    // -------------------------------------------------------------------------
    // Boot
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
            fetchEndpoint('books_items'     + suffix),
        ).then(function (recurringRes, invoicesRes, itemsRes) {
            const recurring = recurringRes[0].data || [];
            const invoices  = invoicesRes[0].data  || [];
            const items     = itemsRes[0].data      || [];

            renderSummaryCards(recurring);
            renderPledgeStatusChart(recurring);
            renderIncomeChart(invoices);
            renderBalanceChart(invoices);
            renderUpcomingTable(recurring);
            renderEmployees(items, invoices);
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
        const active = recurring.filter(r => r.status === 'active');
        const total  = active.reduce((sum, r) => sum + parseFloat(r.amount || 0), 0);
        const count  = active.length;
        const target = 0;
        const pct    = target > 0 ? ((total / target) * 100).toFixed(1) + '%' : '—';

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
    // Income by month bar chart (agency-wide, paid invoices)
    // -------------------------------------------------------------------------

    function renderIncomeChart(invoices) {
        const byMonth = {};

        invoices
            .filter(inv => inv.status === 'paid' && inv.date)
            .forEach(inv => {
                const d      = new Date(inv.date + 'T00:00:00');
                const label  = d.toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
                byMonth[label] = (byMonth[label] || 0) + parseFloat(inv.total || 0);
            });

        const months = Object.entries(byMonth)
            .map(([label, amount]) => ({ label, amount, _date: new Date('01 ' + label) }))
            .sort((a, b) => a._date - b._date)
            .slice(-12)
            .map(({ label, amount }) => ({ label, amount }));

        ZohoCharts.renderIncomeByMonth(months);
    }

    // -------------------------------------------------------------------------
    // Balance trend line chart
    // -------------------------------------------------------------------------

    function renderBalanceChart(invoices) {
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
    // Per-employee income breakdown
    // -------------------------------------------------------------------------

    /**
     * Build per-employee income data.
     * Each Books Item = one employee.
     * Income = paid invoices whose line_items include this item's item_id.
     *
     * @param {Array} items     Zoho Books Items (employees)
     * @param {Array} invoices  All Zoho Books invoices
     * @returns {Array} Employee objects with monthly income breakdown
     */
    function buildEmployeeData(items, invoices) {
        const paidInvoices = invoices.filter(inv => inv.status === 'paid' && inv.date);

        return items.map(item => {
            const itemId  = item.item_id;
            const byMonth = {};

            paidInvoices.forEach(inv => {
                const lineItems = inv.line_items || [];
                lineItems.forEach(li => {
                    if (String(li.item_id) !== String(itemId)) return;
                    const d      = new Date(inv.date + 'T00:00:00');
                    const label  = d.toLocaleDateString('en-AU', { month: 'short', year: 'numeric' });
                    const amount = parseFloat(li.item_total || li.amount || 0);
                    byMonth[label] = (byMonth[label] || 0) + amount;
                });
            });

            const months = Object.entries(byMonth)
                .map(([label, amount]) => ({ label, amount, _date: new Date('01 ' + label) }))
                .sort((a, b) => a._date - b._date)
                .map(({ label, amount }) => ({ label, amount }));

            return {
                item_id:      item.item_id,
                name:         item.name         || 'Unnamed',
                description:  item.description  || '',
                rate:         parseFloat(item.rate || 0),
                account_name: item.account_name  || '—',
                status:       (item.status       || '').toLowerCase(),
                months,
                total: months.reduce((s, m) => s + m.amount, 0),
            };
        });
    }

    /**
     * Render per-employee cards into #employee-list.
     * Each card contains: overview details, income trend chart, income by month table.
     */
    function renderEmployees(items, invoices) {
        const $list = $('#employee-list');

        if (!items || items.length === 0) {
            $list.html('<p class="loading">No employee items found in Zoho Books.</p>');
            return;
        }

        const employees = buildEmployeeData(items, invoices);

        // Build HTML for all employee cards first, then render charts after DOM insertion.
        const html = employees.map(emp => {
            const safeId    = String(emp.item_id).replace(/\W/g, '_');
            const statusCls = emp.status === 'active' ? 'badge-active' : 'badge-stopped';

            return `
            <div class="emp-card" id="emp-${safeId}">
                <div class="emp-card-header">
                    <div class="emp-overview">
                        <div class="emp-name-row">
                            <h3 class="emp-name">${escHtml(emp.name)}</h3>
                            <span class="badge ${statusCls}">${escHtml(emp.status || 'unknown')}</span>
                        </div>
                        <dl class="emp-details">
                            ${emp.description ? `<dt>Description</dt><dd>${escHtml(emp.description)}</dd>` : ''}
                            <dt>Rate</dt><dd>${formatCurrency(emp.rate)}</dd>
                            <dt>Account</dt><dd>${escHtml(emp.account_name)}</dd>
                            <dt>Total Received</dt><dd>${formatCurrency(emp.total)}</dd>
                        </dl>
                    </div>
                    <div class="emp-chart-wrap">
                        <h4 class="emp-section-title">Income Trend</h4>
                        <canvas id="chart-emp-${safeId}" height="160"></canvas>
                    </div>
                </div>
                <div class="emp-table-wrap">
                    <h4 class="emp-section-title">Income by Month</h4>
                    <div id="table-emp-${safeId}"></div>
                </div>
            </div>`;
        }).join('');

        $list.html(html);

        // Render charts and tables after DOM is populated.
        employees.forEach(emp => {
            const safeId = String(emp.item_id).replace(/\W/g, '_');
            ZohoCharts.renderEmployeeIncomeTrend('chart-emp-' + safeId, emp.months);
            ZohoTables.renderEmployeeIncomeTable('table-emp-' + safeId, emp.months);
        });
    }

    // -------------------------------------------------------------------------
    // Shared utilities
    // -------------------------------------------------------------------------

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-AU', {
            style: 'currency', currency: 'AUD', maximumFractionDigits: 0,
        }).format(amount);
    }

    function escHtml(str) {
        return $('<span>').text(String(str ?? '')).html();
    }

});
