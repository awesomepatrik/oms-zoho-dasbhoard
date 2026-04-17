/**
 * employees.js — Master/detail employee browser.
 *
 * Left sidebar : scrollable employee list (only those with paid invoices).
 * Right panel  : Overview tab (item fields + custom fields),
 *                Transactions tab (paid invoices table), and
 *                Reports tab (income trend table, balance trend, income by month,
 *                             funding status pie).
 *
 * Depends on: jQuery, Chart.js v4
 */

'use strict';

$(function () {

    const PROXY = '/oms-zoho-dashboard/zoho-dashboard/api/proxy.php';

    let allEmployees = [];
    let selectedId   = null;
    let searchQuery  = '';
    const _charts    = {};   // active Chart.js instances keyed by canvas id

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    loadEmployees(false);

    $('#btn-refresh').on('click', function () {
        $(this).prop('disabled', true);
        loadEmployees(true).always(() => $(this).prop('disabled', false));
    });

    $('#emp-search').on('input', function () {
        searchQuery = $(this).val().trim().toLowerCase();
        renderSidebar();
    });

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    function loadEmployees(forceRefresh) {
        const suffix = forceRefresh ? '&refresh=1' : '';

        allEmployees = [];
        selectedId   = null;
        searchQuery  = '';
        $('#emp-search').val('');
        $('#emp-list').html('<p class="sidebar-status">Loading\u2026</p>');
        if (forceRefresh) {
            $('#app-detail').html('<div class="detail-empty"><p>Loading\u2026</p></div>');
        }

        // Step 1: ensure the invoice index is built (may be slow on first run).
        $('#emp-list').html('<p class="sidebar-status">Building invoice index\u2026</p>');

        return $.getJSON(PROXY + '?endpoint=books_invoice_index' + suffix)
            .then(function () {
                // Step 2: load items + status map (both instant once index is built).
                return $.when(
                    $.getJSON(PROXY + '?endpoint=books_items' + suffix),
                    $.getJSON(PROXY + '?endpoint=books_item_invoice_status'),
                );
            })
            .done(function (itemsRes, statusRes) {
                const items     = itemsRes[0].data  || [];
                const statusMap = statusRes[0].data || {};

                allEmployees = items
                    .filter(item => statusMap[String(item.item_id)])
                    .sort((a, b) => (a.name || '').localeCompare(b.name || ''));

                renderSidebar();

                const hashId = location.hash.replace('#emp-', '');
                const autoId = hashId && allEmployees.find(e => String(e.item_id) === hashId)
                    ? hashId
                    : (allEmployees[0] ? String(allEmployees[0].item_id) : null);

                if (autoId) selectEmployee(autoId);

            }).fail(function (jqXHR) {
                if (jqXHR.status === 401) {
                    window.location.href = '/oms-zoho-dashboard/zoho-dashboard/auth/connect.php';
                    return;
                }
                $('#emp-list').html('<p class="sidebar-status error-msg">Failed to load. Try refreshing.</p>');
            });
    }


    // -------------------------------------------------------------------------
    // Sidebar rendering
    // -------------------------------------------------------------------------

    function renderSidebar() {
        const $list = $('#emp-list');

        const filtered = searchQuery
            ? allEmployees.filter(e => (e.name || '').toLowerCase().includes(searchQuery))
            : allEmployees;

        const $warm = $list.find('.sidebar-warmup').detach();

        if (!filtered.length) {
            $list.html('<p class="sidebar-status">No employees found.</p>');
            $list.append($warm);
            return;
        }

        const html = filtered.map(emp => {
            const active = String(emp.item_id) === String(selectedId) ? ' is-active' : '';
            return `<div class="sidebar-item${active}" data-id="${escAttr(String(emp.item_id))}">
                <span class="sidebar-item-name">${escHtml(emp.name || '\u2014')}</span>
                <span class="sidebar-item-rate">${formatCurrency(parseFloat(emp.rate || 0))}</span>
            </div>`;
        }).join('');

        $list.html(html);
        $list.append($warm);

        $list.find('.sidebar-item').on('click', function () {
            selectEmployee($(this).data('id'));
        });

        // Scroll active item into view.
        const $active = $list.find('.is-active');
        if ($active.length) {
            const listTop  = $list.scrollTop();
            const listH    = $list.outerHeight();
            const itemTop  = $active.position().top + listTop;
            const itemH    = $active.outerHeight();
            if (itemTop < listTop || itemTop + itemH > listTop + listH) {
                $list.scrollTop(itemTop - listH / 2 + itemH / 2);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Employee selection
    // -------------------------------------------------------------------------

    function selectEmployee(itemId) {
        selectedId = String(itemId);
        history.replaceState(null, '', '#emp-' + itemId);
        renderSidebar();
        loadDetail(itemId);
    }

    // -------------------------------------------------------------------------
    // Detail panel loading
    // -------------------------------------------------------------------------

    function loadDetail(itemId) {
        // Destroy any active charts before swapping content.
        Object.keys(_charts).forEach(k => {
            _charts[k].destroy();
            delete _charts[k];
        });

        const $detail = $('#app-detail');
        $detail.html('<div class="detail-loading"><span class="spinner"></span></div>');

        $.when(
            $.getJSON(PROXY + '?endpoint=books_items'),
            $.getJSON(PROXY + '?endpoint=books_invoices_by_item&item_id=' + encodeURIComponent(itemId)),
        ).done(function (itemsRes, invoicesRes) {
            const item     = (itemsRes[0].data || []).find(i => String(i.item_id) === String(itemId));
            const invoices = invoicesRes[0].data || [];

            if (!item) {
                $detail.html('<div class="detail-empty"><p>Employee not found.</p></div>');
                return;
            }

            renderDetail($detail, item, invoices);

        }).fail(function (jqXHR) {
            if (jqXHR.status === 401) {
                window.location.href = '/oms-zoho-dashboard/zoho-dashboard/auth/connect.php';
                return;
            }
            $detail.html('<div class="detail-empty"><p class="error-msg">Failed to load. Try refreshing.</p></div>');
        });
    }

    // -------------------------------------------------------------------------
    // Detail panel rendering
    // -------------------------------------------------------------------------

    function renderDetail($detail, item, invoices) {
        const statusCls  = (item.status || '').toLowerCase() === 'active' ? 'badge-active' : 'badge-stopped';
        const statusText = capitalise(item.status || 'unknown');

        // Initials avatar
        const initials = (item.name || '?')
            .split(/\s+/).slice(0, 2)
            .map(w => w[0].toUpperCase()).join('');

        // Overview fields: core + custom
        const coreFields = [
            ['Item Type',     capitalise((item.item_type || '').replace(/_/g, ' '))],
            ['Selling Price', formatCurrency(parseFloat(item.rate || 0))],
            item.account_name ? ['Account',     item.account_name]  : null,
            item.sku          ? ['SKU / Code',  item.sku]           : null,
            item.description  ? ['Description', item.description]   : null,
        ].filter(Boolean);

        const customFields = (item.custom_fields || [])
            .filter(cf => cf.value !== '' && cf.value !== null && cf.value !== undefined)
            .map(cf => [cf.label, String(cf.value)]);

        const overviewRows = [...coreFields, ...customFields].map(([label, value]) => `
            <div class="overview-row">
                <span class="overview-label">${escHtml(label)}</span>
                <span class="overview-value">${escHtml(value || '\u2014')}</span>
            </div>`).join('');

        // Transactions
        const sortedInvoices = [...invoices]
            .filter(inv => inv.date)
            .sort((a, b) => {
                const dateDiff = new Date(b.date) - new Date(a.date);
                if (dateDiff !== 0) return dateDiff;
                // Tie-break by invoice number ascending (lower number = created earlier).
                const numA = parseInt((a.invoice_number || '').replace(/\D/g, ''), 10) || 0;
                const numB = parseInt((b.invoice_number || '').replace(/\D/g, ''), 10) || 0;
                return numA - numB;
            });

        const invoiceTotal = sortedInvoices.reduce((s, inv) => s + parseFloat(inv.total || 0), 0);

        const invoiceContent = sortedInvoices.length === 0
            ? '<p class="detail-empty-msg">No paid invoices found for this employee.</p>'
            : `<div class="txn-toolbar">
                <div class="txn-filter-group">
                    <select id="txn-field" class="txn-field-select">
                        <option value="all">All columns</option>
                        <option value="0">Invoice #</option>
                        <option value="1">Date</option>
                        <option value="2">Donor</option>
                        <option value="3">Amount</option>
                    </select>
                    <div class="txn-input-wrap">
                        <svg class="txn-search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="8.5" cy="8.5" r="5.5"/><path d="M15 15l-3-3"/>
                        </svg>
                        <input type="search" id="txn-filter" class="txn-filter-input"
                               placeholder="Search\u2026" autocomplete="off">
                    </div>
                </div>
                <span id="txn-count" class="txn-count">${sortedInvoices.length} records</span>
            </div>
            <div class="detail-table-wrap">
                <table class="data-table invoices-table">
                    <thead><tr>
                        <th>Invoice #</th><th>Date</th><th>Donor</th>
                        <th class="amount-cell">Amount</th>
                    </tr></thead>
                    <tbody>${sortedInvoices.map(inv => `
                        <tr data-amount="${parseFloat(inv.total || 0)}">
                            <td>${escHtml(inv.invoice_number || '\u2014')}</td>
                            <td>${formatDate(inv.date)}</td>
                            <td>${escHtml(inv.customer_name || '\u2014')}</td>
                            <td class="amount-cell">${formatCurrency(parseFloat(inv.total || 0))}</td>
                        </tr>`).join('')}
                    </tbody>
                    <tfoot><tr class="total-row">
                        <td colspan="3">Total</td>
                        <td class="amount-cell txn-total">${formatCurrency(invoiceTotal)}</td>
                    </tr></tfoot>
                </table>
            </div>`;

        // ----- MSR tab -----
        const allCustomFields = (item.custom_fields || [])
            .filter(cf => cf.value !== '' && cf.value !== null && cf.value !== undefined);

        // Find the MSR custom field by label.
        const msrField = allCustomFields.find(cf => {
            const lbl = (cf.label || '').toLowerCase();
            return lbl.includes('msr')
                || lbl.includes('monthly support')
                || lbl.includes('support requirement')
                || lbl.includes('support req');
        });

        // Parse the field value as a multi-line CSV.
        // Row 0 is a header row: "Living Cost,Monthly,Yearly,Yearly Multiplier,Term"
        // Data rows have quoted currency values: OMS Admin Levy,$600.00,"$7,200.00",1,"$7,200.00"
        function parseMsrCsv(raw) {
            if (!raw || !raw.trim()) return { headers: [], rows: [] };
            const lines = raw.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
            if (!lines.length) return { headers: [], rows: [] };
            function parseRow(line) {
                const cells = [];
                let inQ = false, cell = '';
                for (let i = 0; i < line.length; i++) {
                    const c = line[i];
                    if (c === '"') { inQ = !inQ; }
                    else if (c === ',' && !inQ) { cells.push(cell.trim()); cell = ''; }
                    else { cell += c; }
                }
                cells.push(cell.trim());
                return cells;
            }
            return { headers: parseRow(lines[0]), rows: lines.slice(1).map(parseRow) };
        }

        const msrRaw = msrField ? String(msrField.value || '') : '';
        const { headers: msrHeaders, rows: msrRows } = parseMsrCsv(msrRaw);

        // Locate the Monthly and Yearly columns from the header row.
        const msrMonthlyCol = msrHeaders.findIndex(h => /monthly/i.test(h) && !/multi/i.test(h));
        const msrYearlyCol  = msrHeaders.findIndex(h => /yearly/i.test(h)  && !/multi/i.test(h));

        function parseMsrAmt(str) {
            const n = parseFloat((str || '').replace(/[^0-9.]/g, ''));
            return isNaN(n) ? 0 : n;
        }

        // Totals derived from the CSV; fall back to item.rate when no parsed data.
        const msrMonthly = msrRows.length && msrMonthlyCol >= 0
            ? msrRows.reduce((s, row) => s + parseMsrAmt(row[msrMonthlyCol]), 0)
            : parseFloat(item.rate || 0);
        const msrAnnual = msrRows.length && msrYearlyCol >= 0
            ? msrRows.reduce((s, row) => s + parseMsrAmt(row[msrYearlyCol]), 0)
            : msrMonthly * 12;

        // Current-year totals for summary cards.
        const currentYearInvoices = invoices.filter(inv =>
            inv.date && new Date(inv.date).getFullYear() === new Date().getFullYear()
        );
        const msrYearReceived = currentYearInvoices.reduce(
            (s, inv) => s + parseFloat(inv.total || 0), 0
        );
        const msrBalance   = msrAnnual - msrYearReceived;
        const msrFundedPct = msrAnnual > 0
            ? Math.min(100, (msrYearReceived / msrAnnual) * 100) : 0;

        // Build the MSR breakdown table.
        const msrColCount   = msrHeaders.length || 2;
        // Only sum columns whose values look like currency (start with $).
        const msrIsCurrency = (ci) => msrRows.some(row => /^\$/.test((row[ci] || '').trim()));

        const msrTheadCells = msrHeaders.length
            ? msrHeaders.map((h, i) =>
                `<th${i > 0 ? ' class="amount-cell"' : ''}>${escHtml(h)}</th>`).join('')
            : '<th>Category</th><th class="amount-cell">Monthly</th>';

        const msrTbodyRows = msrRows.length === 0
            ? `<tr><td colspan="${msrColCount}" class="detail-empty-msg">No MSR data found for this item.</td></tr>`
            : msrRows.map(cells =>
                `<tr>${cells.map((c, i) =>
                    `<td${i > 0 ? ' class="amount-cell"' : ''}>${escHtml(c)}</td>`
                ).join('')}</tr>`).join('');

        const msrTfootCells = msrHeaders.map((_, i) => {
            if (i === 0) return '<td>Total</td>';
            if (!msrIsCurrency(i)) return '<td class="amount-cell">\u2014</td>';
            const sum = msrRows.reduce((s, row) => s + parseMsrAmt(row[i] || ''), 0);
            return `<td class="amount-cell">${escHtml(sum > 0 ? formatCurrency(sum) : '\u2014')}</td>`;
        }).join('');
        const msrTfoot = msrRows.length > 1
            ? `<tfoot><tr class="total-row">${msrTfootCells}</tr></tfoot>` : '';

        // ----- Reports tab -----
        const rpt = buildReportData(invoices);
        const currentYear = new Date().getFullYear();
        const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

        // Pre-build income trend table rows
        let cumSum = 0;
        const incomeTblRows = MONTHS.map((lbl, i) => {
            const inc = rpt.monthlyIncome[i];
            cumSum += inc;
            const incStr = inc    > 0 ? formatCurrency(inc)    : '\u2014';
            const cumStr = cumSum > 0 ? formatCurrency(cumSum) : '\u2014';
            return `<tr>
                <td>${lbl}</td>
                <td class="amount-cell">${escHtml(incStr)}</td>
                <td class="amount-cell">${escHtml(cumStr)}</td>
            </tr>`;
        }).join('');

        // ---- Build full detail HTML ----
        $detail.html(`
            <div class="detail-panel">
                <div class="detail-header">
                    <div class="detail-title-row">
                        <div class="detail-avatar">${escHtml(initials)}</div>
                        <div class="detail-title-text">
                            <h2 class="detail-name">${escHtml(item.name || '\u2014')}</h2>
                        </div>
                        <span class="badge ${statusCls}">${escHtml(statusText)}</span>
                    </div>
                    <nav class="detail-tabs">
                        <button class="tab-btn is-active" data-tab="overview">Overview</button>
                        <button class="tab-btn" data-tab="transactions">Transactions
                            <span class="tab-count">${sortedInvoices.length}</span>
                        </button>
                        <button class="tab-btn" data-tab="msr">MSR</button>
                        <button class="tab-btn" data-tab="reports">Reports</button>
                    </nav>
                </div>

                <div class="tab-pane" id="tab-overview">
                    <div class="overview-grid">${overviewRows}</div>
                </div>

                <div class="tab-pane is-hidden" id="tab-transactions">
                    ${invoiceContent}
                </div>

                <div class="tab-pane is-hidden" id="tab-msr">
                    <div class="msr-layout">

                        <div class="msr-summary-grid">
                            <div class="msr-summary-card">
                                <span class="msr-summary-label">Monthly Support Req.</span>
                                <span class="msr-summary-value">${escHtml(formatCurrency(msrMonthly))}</span>
                                <span class="msr-summary-source">
                                    ${msrField ? escHtml(msrField.label) : 'From item rate'}
                                </span>
                            </div>
                            <div class="msr-summary-card">
                                <span class="msr-summary-label">Annual Support Req.</span>
                                <span class="msr-summary-value">${escHtml(formatCurrency(msrAnnual))}</span>
                            </div>
                            <div class="msr-summary-card">
                                <span class="msr-summary-label">Received (${new Date().getFullYear()})</span>
                                <span class="msr-summary-value">${escHtml(formatCurrency(msrYearReceived))}</span>
                            </div>
                            <div class="msr-summary-card ${msrBalance > 0 ? 'msr-card-outstanding' : 'msr-card-funded'}">
                                <span class="msr-summary-label">Balance</span>
                                <span class="msr-summary-value">${escHtml(formatCurrency(Math.abs(msrBalance)))}</span>
                                <span class="msr-summary-source">${msrBalance > 0 ? 'Remaining' : 'Fully funded'}</span>
                            </div>
                            <div class="msr-summary-card msr-card-pct">
                                <span class="msr-summary-label">% Funded</span>
                                <span class="msr-summary-value">${msrFundedPct.toFixed(1)}%</span>
                                <div class="msr-progress-bar">
                                    <div class="msr-progress-fill" style="width:${Math.min(100, msrFundedPct).toFixed(1)}%"></div>
                                </div>
                            </div>
                        </div>

                        <section class="msr-fields-section">
                            <h3 class="report-title">MSR Breakdown</h3>
                            <div class="detail-table-wrap">
                                <table class="data-table msr-fields-table">
                                    <thead><tr>${msrTheadCells}</tr></thead>
                                    <tbody>${msrTbodyRows}</tbody>
                                    ${msrTfoot}
                                </table>
                            </div>
                        </section>

                    </div>
                </div>

                <div class="tab-pane is-hidden" id="tab-reports">
                    <div class="reports-layout">

                        <section class="report-section">
                            <h3 class="report-title">Income Trend \u2014 ${currentYear}</h3>
                            <div class="detail-table-wrap">
                                <table class="data-table">
                                    <thead><tr>
                                        <th>Month</th>
                                        <th class="amount-cell">Income</th>
                                        <th class="amount-cell">Cumulative</th>
                                    </tr></thead>
                                    <tbody>${incomeTblRows}</tbody>
                                    <tfoot><tr class="total-row">
                                        <td>Total</td>
                                        <td class="amount-cell">${escHtml(formatCurrency(rpt.yearTotal))}</td>
                                        <td class="amount-cell">${escHtml(formatCurrency(rpt.yearTotal))}</td>
                                    </tr></tfoot>
                                </table>
                            </div>
                        </section>

                        <div class="report-charts-row">
                            <section class="report-section">
                                <h3 class="report-title">Balance Trend</h3>
                                <p class="report-subtitle">
                                    Yearly Support Target: ${escHtml(formatCurrency(rpt.totalYearlySupport))}
                                </p>
                                <div class="report-chart-wrap">
                                    <canvas id="rpt-balance"></canvas>
                                </div>
                            </section>
                            <section class="report-section">
                                <h3 class="report-title">Income by Month</h3>
                                <div class="report-chart-wrap">
                                    <canvas id="rpt-income"></canvas>
                                </div>
                            </section>
                        </div>

                        <section class="report-section">
                            <h3 class="report-title">Funding Status</h3>
                            <div class="report-pie-row">
                                <div class="report-pie-wrap">
                                    <canvas id="rpt-funding"></canvas>
                                </div>
                                <dl class="report-pie-stats">
                                    <dt>Yearly Support Target</dt>
                                    <dd>${escHtml(formatCurrency(rpt.totalYearlySupport))}</dd>
                                    <dt>Avg Annual Income</dt>
                                    <dd>${escHtml(formatCurrency(rpt.avgAnnualIncome))}</dd>
                                    <dt>Funded</dt>
                                    <dd class="report-stat-funded">${rpt.percentFunded.toFixed(1)}%</dd>
                                    <dt>Outstanding</dt>
                                    <dd class="report-stat-outstanding">${rpt.percentOutstanding.toFixed(1)}%</dd>
                                </dl>
                            </div>
                        </section>

                    </div>
                </div>
            </div>
        `);

        // Transactions filter — runs on both input change and field change.
        function applyTxnFilter() {
            const q     = $detail.find('#txn-filter').val().trim().toLowerCase();
            const field = $detail.find('#txn-field').val();   // 'all' | '0' | '1' | '2' | '3'
            const $rows = $detail.find('.invoices-table tbody tr');

            $rows.each(function () {
                if (!q) { $(this).show(); return; }
                const cellText = field === 'all'
                    ? $(this).text().toLowerCase()
                    : $(this).find('td').eq(parseInt(field, 10)).text().toLowerCase();
                $(this).toggle(cellText.includes(q));
            });

            let filteredTotal = 0;
            $rows.filter(':visible').each(function () {
                filteredTotal += parseFloat($(this).data('amount')) || 0;
            });
            const visible = $rows.filter(':visible').length;
            const total   = $rows.length;
            $detail.find('.txn-total').text(formatCurrency(filteredTotal));
            $detail.find('#txn-count').text(
                q ? visible + '\u202fof\u202f' + total + ' records' : total + ' records'
            );
        }

        $detail.find('#txn-filter, #txn-field').on('input change', applyTxnFilter);

        // Tab switching — initialise charts lazily on first Reports click.
        let reportsReady = false;
        $detail.find('.tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            $detail.find('.tab-btn').removeClass('is-active');
            $(this).addClass('is-active');
            $detail.find('.tab-pane').addClass('is-hidden');
            $('#tab-' + tab).removeClass('is-hidden');

            if (tab === 'reports' && !reportsReady) {
                reportsReady = true;
                initReportCharts(rpt);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Report data computation
    // -------------------------------------------------------------------------

    /**
     * Compute all report metrics from the employee's paid invoices array.
     * Each invoice: { invoice_id, invoice_number, date, customer_name, total }
     */
    function buildReportData(invoices) {
        const currentYear = new Date().getFullYear();

        // ── 1. Monthly income for current year ──────────────────────────────
        const monthlyIncome = Array(12).fill(0);
        invoices
            .filter(inv => inv.date && new Date(inv.date).getFullYear() === currentYear)
            .forEach(inv => {
                const m = new Date(inv.date).getMonth();
                monthlyIncome[m] += parseFloat(inv.total || 0);
            });

        // ── 2. Cumulative income (Jan → each month, current year) ────────────
        let running = 0;
        const cumulativeIncome = monthlyIncome.map(v => { running += v; return running; });
        const yearTotal = cumulativeIncome[11];

        // ── 3. Yearly support target ─────────────────────────────────────────
        // Per donor: consistent amount = average invoice total across all invoices.
        // Yearly support per donor = consistent amount × 12.
        const donorAmts = {};
        invoices.forEach(inv => {
            const d = inv.customer_name || 'Unknown';
            if (!donorAmts[d]) donorAmts[d] = [];
            donorAmts[d].push(parseFloat(inv.total || 0));
        });
        let totalYearlySupport = 0;
        Object.values(donorAmts).forEach(amounts => {
            const avg = amounts.reduce((s, a) => s + a, 0) / amounts.length;
            totalYearlySupport += avg * 12;
        });

        // ── 4. Balance per month = Yearly Support − cumulative income ────────
        const balance = cumulativeIncome.map(c => totalYearlySupport - c);

        // ── 5. Pie chart — average annual income across recorded years ────────
        // Percent Funded    = avg annual income / Yearly Support × 100
        // Percent Outstanding = 100 − Funded
        const yearTotals = {};
        invoices.forEach(inv => {
            if (!inv.date) return;
            const yr = new Date(inv.date).getFullYear();
            yearTotals[yr] = (yearTotals[yr] || 0) + parseFloat(inv.total || 0);
        });
        const yrVals = Object.values(yearTotals);
        const avgAnnualIncome = yrVals.length > 0
            ? yrVals.reduce((s, v) => s + v, 0) / yrVals.length
            : 0;

        const percentFunded      = totalYearlySupport > 0
            ? Math.min(100, (avgAnnualIncome / totalYearlySupport) * 100)
            : 0;
        const percentOutstanding = Math.max(0, 100 - percentFunded);

        return {
            monthlyIncome,
            cumulativeIncome,
            balance,
            yearTotal,
            totalYearlySupport,
            avgAnnualIncome,
            percentFunded,
            percentOutstanding,
        };
    }

    // -------------------------------------------------------------------------
    // Chart initialisation (called lazily on first Reports tab click)
    // -------------------------------------------------------------------------

    function initReportCharts(rpt) {
        const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun',
                        'Jul','Aug','Sep','Oct','Nov','Dec'];

        const auCurrency = v => formatCurrency(v);

        const lineOpts = (datasets) => ({
            type: 'line',
            data: { labels: MONTHS, datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: {
                        ticks: { callback: auCurrency, font: { size: 11 } },
                        grid:  { color: 'rgba(0,0,0,0.05)' },
                    },
                    x: { grid: { display: false } },
                },
            },
        });

        // Balance Trend (green)
        _createChart('rpt-balance', lineOpts([{
            label: 'Balance',
            data: rpt.balance,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16,185,129,0.10)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointHoverRadius: 6,
        }]));

        // Income by Month (blue)
        _createChart('rpt-income', lineOpts([{
            label: 'Income',
            data: rpt.monthlyIncome,
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59,130,246,0.10)',
            fill: true,
            tension: 0.35,
            pointRadius: 4,
            pointHoverRadius: 6,
        }]));

        // Funding Status Pie
        _createChart('rpt-funding', {
            type: 'pie',
            data: {
                labels: ['Funded', 'Outstanding'],
                datasets: [{
                    data: [rpt.percentFunded, rpt.percentOutstanding],
                    backgroundColor: ['#3b82f6', '#e5e7eb'],
                    borderColor:     ['#2563eb', '#d1d5db'],
                    borderWidth: 1,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 16, font: { size: 13 } },
                    },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.label + ': ' + ctx.parsed.toFixed(1) + '%',
                        },
                    },
                },
            },
        });
    }

    function _createChart(canvasId, config) {
        if (_charts[canvasId]) {
            _charts[canvasId].destroy();
            delete _charts[canvasId];
        }
        const el = document.getElementById(canvasId);
        if (!el) return;
        _charts[canvasId] = new Chart(el, config);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-AU', {
            style: 'currency', currency: 'AUD', maximumFractionDigits: 0,
        }).format(amount);
    }

    function formatDate(str) {
        if (!str) return '\u2014';
        return new Date(str).toLocaleDateString('en-AU', {
            day: '2-digit', month: 'short', year: 'numeric',
        });
    }

    function capitalise(str) {
        if (!str) return '';
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    }

    function escHtml(str) {
        return $('<span>').text(String(str ?? '')).html();
    }

    function escAttr(str) {
        return String(str ?? '').replace(/"/g, '&quot;');
    }

});
