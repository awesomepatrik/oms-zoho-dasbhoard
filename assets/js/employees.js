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

    const PROXY = '/oms-zoho-dashboard/api/proxy.php';

    let allEmployees      = [];
    let pmMap             = {};   // item_id => { pm_id, pm_name }
    let selectedId        = null;
    let searchQuery       = '';
    let pmFilter          = '';

    const _charts    = {};         // active Chart.js instances keyed by canvas id
    const _cache     = new Map(); // session cache: url → jQuery promise

    // Return a cached promise for url, creating and caching it on first call.
    // Failed requests are evicted so the next call retries rather than re-using
    // a rejected promise.
    function apiGet(url) {
        if (!_cache.has(url)) {
            const req = $.getJSON(url);
            req.fail(function () { _cache.delete(url); });
            _cache.set(url, req);
        }
        return _cache.get(url);
    }

    // -------------------------------------------------------------------------
    // Boot
    // -------------------------------------------------------------------------

    loadEmployees(false);

    $('#btn-refresh').on('click', function () {
        $(this).prop('disabled', true);
        _cache.clear(); // force a full refresh from server
        loadEmployees().always(() => $(this).prop('disabled', false));
    });

    $('#emp-search').on('input', function () {
        searchQuery = $(this).val().trim().toLowerCase();
        renderSidebar();
    });

    $('#pm-filter').on('change', function () {
        pmFilter = $(this).val();
        renderSidebar();
    });

    // -------------------------------------------------------------------------
    // Data loading
    // -------------------------------------------------------------------------

    function loadEmployees() {
        allEmployees = [];
        pmMap        = {};
        selectedId   = null;
        searchQuery  = '';
        pmFilter     = '';
        $('#emp-search').val('');
        $('#pm-filter').val('');
        $('#emp-list').html('<p class="sidebar-status">Loading\u2026</p>');

        // Load only the items list — renders the sidebar immediately.
        return apiGet(PROXY + '?endpoint=books_items')
            .done(function (itemsRes) {
                allEmployees = (itemsRes.data || [])
                    .sort((a, b) => (a.name || '').localeCompare(b.name || ''));

                renderSidebar();

                // Auto-select from URL hash, or first employee.
                const hashId = location.hash.replace('#emp-', '');
                const autoId = hashId && allEmployees.find(e => String(e.item_id) === hashId)
                    ? hashId
                    : (allEmployees[0] ? String(allEmployees[0].item_id) : null);

                if (autoId) selectEmployee(autoId);

            }).fail(function (jqXHR) {
                if (jqXHR.status === 401) {
                    window.location.href = '/oms-zoho-dashboard/auth/connect.php';
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

        const filtered = allEmployees.filter(emp => {
            if (searchQuery && !(emp.name || '').toLowerCase().includes(searchQuery)) return false;
            if (pmFilter) {
                const pm = pmMap[String(emp.item_id)] || {};
                if ((pm.pm_id || '') !== pmFilter) return false;
            }
            return true;
        });

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

    function loadDetail(itemId, forceRefresh) {
        // Evict this item's cached responses so the next fetch goes back to the
        // server (e.g. after an MSR save that invalidated the server-side cache).
        if (forceRefresh) {
            const idEnc = encodeURIComponent(itemId);
            for (const key of _cache.keys()) {
                if (key.includes(idEnc)) _cache.delete(key);
            }
        }

        // Destroy any active charts before swapping content.
        Object.keys(_charts).forEach(k => {
            _charts[k].destroy();
            delete _charts[k];
        });

        const $detail = $('#app-detail');
        $detail.html('<div class="detail-loading"><span class="spinner"></span></div>');

        const transactionsReq = apiGet(
            PROXY + '?endpoint=books_invoice_transactions&item_id=' + encodeURIComponent(itemId)
        );

        $.when(
            apiGet(PROXY + '?endpoint=books_item_detail&item_id='     + encodeURIComponent(itemId)),
            apiGet(PROXY + '?endpoint=books_invoices_by_item&item_id=' + encodeURIComponent(itemId)),
        ).done(function (itemDetailRes, invoicesRes) {
            const item     = itemDetailRes[0].data || null;
            const invoices = invoicesRes[0].data   || [];

            if (!item) {
                $detail.html('<div class="detail-empty"><p>Employee not found.</p></div>');
                return;
            }

            // Fetch cfDefs + PM contact in parallel — both non-fatal.
            // PM contact ID comes from the "Project Manager" lookup custom field on the item.
            const pmCf = (item.custom_fields || []).find(f => (f.label || '').toLowerCase() === 'project manager');
            const pmId = pmCf ? String(pmCf.value || '').trim() : '';

            // Evict PM contact cache too when force-refreshing.
            if (forceRefresh && pmId) {
                _cache.delete(PROXY + '?endpoint=books_contact_detail&contact_id=' + encodeURIComponent(pmId));
            }

            const cfDefsReq = apiGet(PROXY + '?endpoint=books_item_customfields')
                .then(function (r) { return r; }, function () { return { data: [] }; });
            const contactReq = pmId
                ? apiGet(PROXY + '?endpoint=books_contact_detail&contact_id=' + encodeURIComponent(pmId))
                    .then(function (r) { return r; }, function () { return { data: {} }; })
                : $.when({ data: {} });

            $.when(cfDefsReq, contactReq).done(function (cfDefsRes, contactRes) {
                const cfDefs  = Array.isArray(cfDefsRes && cfDefsRes.data) ? cfDefsRes.data : [];
                const contact = (contactRes && contactRes.data) || {};
                renderDetail($detail, item, invoices, cfDefs, contact, transactionsReq);
            });

        }).fail(function (jqXHR) {
            if (jqXHR.status === 401) {
                window.location.href = '/oms-zoho-dashboard/auth/connect.php';
                return;
            }
            $detail.html('<div class="detail-empty"><p class="error-msg">Failed to load. Try refreshing.</p></div>');
        });
    }

    // -------------------------------------------------------------------------
    // Detail panel rendering
    // -------------------------------------------------------------------------

    function renderDetail($detail, item, invoices, cfDefs, contact, transactionsReq) {
        const statusCls  = (item.status || '').toLowerCase() === 'active' ? 'badge-active' : 'badge-stopped';
        const statusText = capitalise(item.status || 'unknown');

        // Initials avatar
        const initials = (item.name || '?')
            .split(/\s+/).slice(0, 2)
            .map(w => w[0].toUpperCase()).join('');

        // ── Overview — family/missionary contact profile ─────────────────────
        const cCfs    = (contact && contact.custom_fields)   || [];
        const persons = (contact && contact.contact_persons) || [];

        // Get a custom field value by label (case-insensitive).
        function cfGet(label) {
            const cf = cCfs.find(f => (f.label || '').toLowerCase() === label.toLowerCase());
            return cf ? String(cf.value || '') : '';
        }

        // Strip HTML tags and split by newline.
        // <br> tags are converted to \n before stripping so they act as separators.
        function toLines(raw) {
            if (!raw) return [];
            let text = raw;
            if (raw.includes('<')) {
                text = raw.replace(/<br\s*\/?>/gi, '\n');
                const tmp = document.createElement('div');
                tmp.innerHTML = text;
                text = tmp.textContent || '';
            }
            return text.split(/\r?\n/).map(s => s.trim()).filter(Boolean);
        }

        function multiRow(label, lines) {
            if (!lines.length) return '';
            return lines.map((v, i) =>
                `<tr><td class="ov-lbl">${escHtml(i === 0 ? label : '')}</td><td class="ov-val">${escHtml(v)}</td></tr>`
            ).join('');
        }

        function singleRow(label, value) {
            if (!value) return '';
            return `<tr><td class="ov-lbl">${escHtml(label)}</td><td class="ov-val">${escHtml(value)}</td></tr>`;
        }

        const nameLines  = toLines(cfGet('Name(s)'));
        const childLines = toLines(cfGet('Children(s)'));
        // Emails: merge Contact Persons emails + Email(s) custom field lines, deduplicated.
        const personEmails = persons.map(p => p.email).filter(Boolean);
        const cfEmails     = toLines(cfGet('Email(s)'));
        const emailLines   = [...new Set([...personEmails, ...cfEmails])];

        const infoBody = [
            multiRow('Name',        nameLines),
            multiRow('Child',       childLines),
            singleRow('Category',   cfGet('Category')),
            singleRow('Field',      cfGet('Field')),
            singleRow('Connection', cfGet('Connection')),
            multiRow('Email',       emailLines),
        ].join('');

        const emergencyBody = [
            singleRow('Name',  cfGet('Emergency Contact Name')),
            singleRow('Phone', cfGet('Emergency Contact Phone')),
            singleRow('Email', cfGet('Emergency Contact Email')),
        ].join('');

        const hasContact  = contact && contact.contact_id;
        const itemImageUrl = PROXY + '?endpoint=books_item_image&item_id=' + encodeURIComponent(item.item_id || '');
        const contactImageUrl = hasContact
            ? PROXY + '?endpoint=books_contact_image&contact_id=' + encodeURIComponent(contact.contact_id)
            : '';
        const contactName = (contact && contact.contact_name) || '';

        const overviewRows = !hasContact
            ? `<p class="detail-empty-msg">No Project Manager assigned to this item.</p>`
            : `<div class="ov-card">
                <div class="ov-layout">
                    <div class="ov-left">
                        <img class="ov-photo" src="${escAttr(contactImageUrl)}" alt=""
                             onerror="this.style.display='none';this.nextElementSibling.style.display=''">
                        <div class="ov-photo-fallback" style="display:none">${escHtml((contactName || item.name || '?')[0].toUpperCase())}</div>
                        <div class="ov-profile-name">${escHtml(contactName)}</div>
                        <span class="ov-profile-badge">Project Manager</span>
                    </div>
                    <div class="ov-right">
                        <div class="ov-section">
                            <div class="ov-section-title">Information</div>
                            <table class="ov-table">
                                <tbody>${infoBody || '<tr><td colspan="2" class="detail-empty-msg">No information found.</td></tr>'}</tbody>
                            </table>
                        </div>
                        ${emergencyBody ? `<div class="ov-section">
                            <div class="ov-section-title">Emergency Contact</div>
                            <table class="ov-table">
                                <tbody>${emergencyBody}</tbody>
                            </table>
                        </div>` : ''}
                    </div>
                </div>
            </div>`;


        // ----- MSR tab -----
        function isMsrField(cf) {
            const lbl = (cf.label || '').toLowerCase();
            return lbl.includes('msr')
                || lbl.includes('monthly support')
                || lbl.includes('support requirement')
                || lbl.includes('support req');
        }
        // Find MSR field in the item's own custom_fields first (has the value).
        // Fall back to the org-level custom field definitions (gives us the ID
        // even when the item has no value set — Zoho omits empty fields).
        const msrField = (item.custom_fields || []).find(isMsrField)
                      || (Array.isArray(cfDefs) ? cfDefs : []).find(isMsrField)
                      || null;

        // Parse the MSR field value.
        // Zoho stores it as HTML: <div><p>header row</p><p>data row</p>…</div>
        // Each <p> contains one CSV row; currency values may be quoted: "$7,200.00"
        // Format (after user edit in Zoho):
        //   "Living Cost,,,,"          ← LC section label
        //   "Item,Monthly,Yearly,..."  ← LC column headers
        //   ...data rows...
        //   "Extras,,,,"               ← Extras section label
        //   "Item,Amount,,,"           ← Extras column headers
        //   ...data rows...
        function parseMsrCsv(raw) {
            if (!raw || !raw.trim()) return { lcHeaders: [], lcRows: [], exHeaders: [], exRows: [] };

            let lines;
            if (raw.includes('<')) {
                const tmp = document.createElement('div');
                tmp.innerHTML = raw;
                lines = Array.from(tmp.querySelectorAll('p')).map(p => p.textContent.trim());
            } else {
                lines = raw.split(/\r?\n/).map(l => l.trim());
            }

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

            const parsed = lines.map(parseRow);
            const isBlank = r => r.every(c => c === '');
            const isSection = (r, label) =>
                r[0].toLowerCase().includes(label.toLowerCase()) && r.slice(1).every(c => c === '');

            // Find section header indices
            const lcLabelIdx = parsed.findIndex(r => isSection(r, 'living cost'));
            const exLabelIdx = parsed.findIndex(r => isSection(r, 'extras'));

            // Headers row immediately follows the section label
            const lcHeaders = lcLabelIdx >= 0 ? parsed[lcLabelIdx + 1] || [] : [];
            const exHeaders = exLabelIdx >= 0 ? parsed[exLabelIdx + 1] || [] : [];

            // Data rows: between header row and next section label, non-blank
            const lcDataEnd = exLabelIdx >= 0 ? exLabelIdx : parsed.length;
            const lcRows = parsed
                .slice(lcLabelIdx >= 0 ? lcLabelIdx + 2 : 0, lcDataEnd)
                .filter(r => !isBlank(r));

            const exRows = exLabelIdx >= 0
                ? parsed.slice(exLabelIdx + 2).filter(r => !isBlank(r))
                : [];

            return { lcHeaders, lcRows, exHeaders, exRows };
        }

        function parseMsrAmt(str) {
            const n = parseFloat((str || '').replace(/[^0-9.]/g, ''));
            return isNaN(n) ? 0 : n;
        }

        const msrRaw = msrField ? String(msrField.value || '') : '';
        const { lcHeaders, lcRows, exHeaders, exRows: extrasRows } = parseMsrCsv(msrRaw);
        const livingRows = lcRows;

        // Term column = last column of LC headers; Amount column from Extras headers.
        const msrTermCol   = lcHeaders.length > 0 ? lcHeaders.length - 1 : 4;
        const exAmountCol  = exHeaders.findIndex(h => /amount/i.test(h));
        const exAmtIdx     = exAmountCol >= 0 ? exAmountCol : 1;

        const lcTermTotal  = livingRows.reduce((s, r) => s + parseMsrAmt(r[msrTermCol] || ''), 0);
        const exTermTotal  = extrasRows.reduce((s, r) => s + parseMsrAmt(r[exAmtIdx] || ''), 0);
        const msrGrandTotal = lcTermTotal + exTermTotal;
        const msrMonthlyRequired = msrGrandTotal > 0 ? msrGrandTotal / 12 : parseFloat(item.rate || 0);

        // Column count driven by LC headers (the wider section).
        const lcColCount = lcHeaders.length || 5;

        // Living Cost column headers row.
        const lcColHeaders = lcHeaders.length
            ? lcHeaders.map((h, i) =>
                `<th${i > 0 ? ' class="amount-cell"' : ''}>${escHtml(h)}</th>`).join('')
            : '<th>Item</th><th class="amount-cell">Monthly</th><th class="amount-cell">Yearly</th><th class="amount-cell">Multiplier</th><th class="amount-cell">Term</th>';

        // Living Cost data rows (class used by edit mode).
        const lcDataRows = livingRows.map(cells =>
            `<tr class="lc-data-row">${cells.map((c, i) =>
                `<td${i > 0 ? ' class="amount-cell"' : ''}>${escHtml(c)}</td>`
            ).join('')}</tr>`).join('');

        // Extras data rows — use Extras-specific Amount column index.
        const exDataRows = extrasRows.map(cells =>
            `<tr class="ex-data-row">
                <td>${escHtml(cells[0] || '')}</td>
                <td class="amount-cell">${escHtml(cells[exAmtIdx] || '')}</td>
                ${Array(lcColCount - 2).fill('<td></td>').join('')}
            </tr>`).join('');


        // ----- Reports tab -----
        const rpt = buildReportData(invoices, msrMonthlyRequired);
        // Pre-build income trend table rows
        const incomeTblRows = buildIncomeTblRows(rpt);

        // ---- Build full detail HTML ----
        $detail.html(`
            <div class="detail-panel">
                <div class="detail-header">
                    <div class="detail-title-row">
                        <div class="detail-avatar">
                            <img class="detail-avatar-img" src="${escAttr(itemImageUrl)}" alt="" onerror="this.remove()">
                            ${escHtml(initials)}
                        </div>
                        <div class="detail-title-text">
                            <h2 class="detail-name">${escHtml(item.name || '\u2014')}</h2>
                        </div>
                        <span class="badge ${statusCls}">${escHtml(statusText)}</span>
                    </div>
                    <nav class="detail-tabs">
                        <button class="tab-btn is-active" data-tab="overview">Overview</button>
                        <button class="tab-btn" data-tab="transactions">Transactions
                            <span class="tab-count" id="txn-tab-count"></span>
                        </button>
                        <button class="tab-btn" data-tab="msr">MSR</button>
                        <button class="tab-btn" data-tab="support">Support</button>
                        <button class="tab-btn" data-tab="flow">Flow
                            <span class="tab-count" id="flow-tab-count"></span>
                        </button>
                    </nav>
                </div>

                <div class="tab-pane" id="tab-overview">
                    <div class="ov-kpi-bar">
                        <div class="ov-kpi-card">
                            <span class="ov-kpi-label">Monthly MSR</span>
                            <span class="ov-kpi-value">${escHtml(formatCurrency(msrMonthlyRequired))}</span>
                        </div>
                        <div class="ov-kpi-card">
                            <span class="ov-kpi-label">Monthly Pledges</span>
                            <span class="ov-kpi-value" id="ov-pledges-total">&#8230;</span>
                        </div>
                        <div class="ov-kpi-card">
                            <span class="ov-kpi-label">Monthly Avg Income</span>
                            <span class="ov-kpi-value" id="ov-monthly-avg">${escHtml(formatCurrency(rpt.monthlyAvg))}</span>
                        </div>
                        <div class="ov-kpi-card">
                            <span class="ov-kpi-label">Avg Deficit</span>
                            <span class="ov-kpi-value" id="ov-avg-deficit">&#8230;</span>
                        </div>
                    </div>
                    <div class="ov-wrap">${overviewRows}</div>
                    <div id="ov-attachments" class="ov-attachments-strip"></div>
                    <div class="reports-layout">

                        <section class="report-section">
                            <h3 class="report-title">Income Trend \u2014 Last 12 Months</h3>
                            <div class="detail-table-wrap">
                                <table class="data-table" id="ov-income-tbl">
                                    <thead><tr>
                                        <th>Month</th>
                                        <th class="amount-cell">Income</th>
                                        <th class="amount-cell">Cumulative</th>
                                    </tr></thead>
                                    <tbody>${incomeTblRows}</tbody>
                                    <tfoot><tr class="total-row">
                                        <td>Total</td>
                                        <td class="amount-cell" id="ov-income-total">${escHtml(formatCurrency(rpt.yearTotal))}</td>
                                        <td class="amount-cell" id="ov-income-cumtotal">${escHtml(formatCurrency(rpt.yearTotal))}</td>
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
                                    <dt>Last 12 Months Income</dt>
                                    <dd id="ov-funding-income">${escHtml(formatCurrency(rpt.yearTotal))}</dd>
                                    <dt>Funded</dt>
                                    <dd id="ov-funding-pct" class="report-stat-funded">${rpt.percentFunded.toFixed(1)}%</dd>
                                    <dt>Outstanding</dt>
                                    <dd id="ov-outstanding-pct" class="report-stat-outstanding">${rpt.percentOutstanding.toFixed(1)}%</dd>
                                </dl>
                            </div>
                        </section>

                    </div>
                </div>

                <div class="tab-pane is-hidden" id="tab-transactions"></div>

                <div class="tab-pane is-hidden" id="tab-msr">
                    <div class="msr-layout" data-lc-col-count="${lcColCount}">

                        <div class="msr-toolbar">
                            <button id="btn-msr-edit" class="btn-msr-action">Edit</button>
                            <button id="btn-msr-save" class="btn-msr-action btn-msr-save is-hidden">Save</button>
                            <button id="btn-msr-cancel" class="btn-msr-action btn-msr-cancel is-hidden">Cancel</button>
                            <button id="btn-msr-refresh" class="btn-msr-action btn-msr-refresh" title="Reload from Zoho Books">&#8635; Refresh</button>
                            <span id="msr-save-status" class="msr-save-status"></span>
                        </div>

                        <div class="detail-table-wrap">
                            <table class="data-table msr-fields-table">
                                <tbody>
                                    <!-- Living Cost section -->
                                    <tr class="msr-section-header">
                                        <td colspan="${lcColCount}">Living Cost</td>
                                    </tr>
                                    <tr class="msr-col-header">
                                        ${lcColHeaders}
                                    </tr>
                                    ${lcDataRows || `<tr class="msr-placeholder-row"><td colspan="${lcColCount}" class="detail-empty-msg">No data.</td></tr>`}
                                    <tr class="lc-total-row total-row">
                                        <td>Total</td>
                                        ${lcHeaders.slice(1, msrTermCol).map(() => '<td></td>').join('')}
                                        <td class="amount-cell lc-term-total">${escHtml(formatCurrency(lcTermTotal))}</td>
                                    </tr>

                                    <!-- Extras section -->
                                    <tr class="msr-section-header">
                                        <td colspan="${lcColCount}">Extras</td>
                                    </tr>
                                    <tr class="msr-col-header">
                                        <th>Item</th>
                                        <th class="amount-cell">Amount</th>
                                        ${Array(lcColCount - 2).fill('<th></th>').join('')}
                                    </tr>
                                    ${extrasRows.length > 0 ? exDataRows : `<tr class="msr-placeholder-row"><td colspan="${lcColCount}" class="detail-empty-msg">No extras.</td></tr>`}
                                    <tr class="ex-total-row total-row">
                                        <td>Total</td>
                                        <td class="amount-cell ex-term-total">${escHtml(formatCurrency(exTermTotal))}</td>
                                        ${Array(lcColCount - 2).fill('<td></td>').join('')}
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="msr-monthly-card">
                            <span class="msr-summary-label">Monthly Support Required</span>
                            <span class="msr-summary-value msr-monthly-required">${escHtml(formatCurrency(msrMonthlyRequired))}</span>
                            <span class="msr-summary-source">
                                Living Cost ${escHtml(formatCurrency(lcTermTotal))}
                                + Extras ${escHtml(formatCurrency(exTermTotal))}
                                = ${escHtml(formatCurrency(msrGrandTotal))} \u00f7 12
                            </span>
                        </div>

                    </div>
                </div>

                <div class="tab-pane is-hidden" id="tab-support"></div>

                <div class="tab-pane is-hidden" id="tab-flow"></div>
            </div>
        `);

        // ── MSR Edit mode ────────────────────────────────────────────────────

        function msrEnterEdit() {
            const $layout = $detail.find('.msr-layout');
            const colCount = parseInt($layout.data('lcColCount')) || 5;

            // Toggle buttons
            $layout.find('#btn-msr-edit').addClass('is-hidden');
            $layout.find('#btn-msr-save, #btn-msr-cancel').removeClass('is-hidden');
            $layout.find('#msr-save-status').text('');

            // Make LC data cells editable — Item (0), Monthly (1), Multiplier (3) only.
            // Yearly (2) and Term (4) are computed automatically and must not be edited.
            $layout.find('tr.lc-data-row').each(function () {
                const $tds = $(this).find('td');
                $tds.eq(0).attr('contenteditable', 'true').addClass('msr-cell-edit');
                $tds.eq(1).attr('contenteditable', 'true').addClass('msr-cell-edit');
                $tds.eq(2).addClass('msr-cell-computed');
                $tds.eq(3).attr('contenteditable', 'true').addClass('msr-cell-edit');
                $tds.eq(4).addClass('msr-cell-computed');
            });

            // Make Extras data cells editable (only item + amount — first 2 cols)
            $layout.find('tr.ex-data-row').each(function () {
                $(this).find('td').eq(0).attr('contenteditable', 'true').addClass('msr-cell-edit');
                $(this).find('td').eq(1).attr('contenteditable', 'true').addClass('msr-cell-edit');
            });

            // Add delete button cell to each data row
            $layout.find('tr.lc-data-row, tr.ex-data-row').each(function () {
                $(this).append('<td class="msr-del-cell"><button type="button" class="btn-msr-del-row" title="Delete row">&times;</button></td>');
            });

            // Remove any previously inserted add-row buttons before adding fresh ones.
            $layout.find('tr.msr-add-row-tr').remove();

            // Add "Add Row" rows after each section's total row
            const addLcRow = `<tr class="msr-add-row-tr lc-add-tr">
                <td colspan="${colCount + 1}">
                    <button type="button" class="btn-msr-add-row" data-section="lc">+ Add Living Cost Row</button>
                </td></tr>`;
            const addExRow = `<tr class="msr-add-row-tr ex-add-tr">
                <td colspan="${colCount + 1}">
                    <button type="button" class="btn-msr-add-row" data-section="ex">+ Add Extras Row</button>
                </td></tr>`;
            $layout.find('tr.lc-total-row').after(addLcRow);
            $layout.find('tr.ex-total-row').after(addExRow);

            // Widen the delete column on total rows too (just a blank)
            $layout.find('tr.lc-total-row, tr.ex-total-row').append('<td></td>');
        }

        function msrCancelEdit() {
            loadDetail(selectedId);
        }

        function msrSerialize() {
            const $layout  = $detail.find('.msr-layout');
            const colCount = parseInt($layout.data('lcColCount')) || 5;
            // Use closure variables — avoids broken JSON-in-HTML-attribute encoding.
            const hdLC = lcHeaders.length ? lcHeaders : ['Item','Monthly','Yearly','Yearly Multiplier','Term'];
            const hdEX = exHeaders.length ? exHeaders : ['Item','Amount'];

            function csvCell(val) {
                val = String(val || '').trim();
                return val.includes(',') ? '"' + val.replace(/"/g, '""') + '"' : val;
            }
            function csvRow(cells) { return cells.map(csvCell).join(','); }
            function pad(cells, len) {
                const r = [...cells];
                while (r.length < len) r.push('');
                return r.slice(0, len);
            }

            const lcRows = [];
            $layout.find('tr.lc-data-row').each(function () {
                const cells = $(this).find('td:not(.msr-del-cell)').map(function () {
                    return $(this).text().trim();
                }).get();
                if (cells.some(c => c !== '')) lcRows.push(cells);
            });

            const exRows = [];
            $layout.find('tr.ex-data-row').each(function () {
                const desc = $(this).find('td:not(.msr-del-cell)').eq(0).text().trim();
                const amt  = $(this).find('td:not(.msr-del-cell)').eq(1).text().trim();
                if (desc || amt) exRows.push([desc, amt]);
            });

            const lines = [];
            lines.push(csvRow(pad(['Living Cost'], colCount)));
            lines.push(csvRow(pad(hdLC, colCount)));
            lcRows.forEach(r => lines.push(csvRow(pad(r, colCount))));
            lines.push(csvRow(Array(colCount).fill('')));
            lines.push(csvRow(pad(['Extras'], colCount)));
            lines.push(csvRow(pad(hdEX, colCount)));
            exRows.forEach(r => lines.push(csvRow(pad(r, colCount))));

            return lines.join('\n');
        }

        function msrSave() {
            const $layout  = $detail.find('.msr-layout');
            const $status  = $layout.find('#msr-save-status');
            // Use closure variables for item ID and MSR field ID.
            const itemId   = String(item.item_id || '');
            const fieldId  = String(msrField ? (msrField.customfield_id || msrField.field_id || '') : '');

            if (!itemId || !fieldId) {
                $status.text('Cannot save: MSR custom field not found on this item in Zoho Books.').addClass('msr-status-err');
                return;
            }

            const value    = msrSerialize();

            $layout.find('#btn-msr-save').prop('disabled', true).text('Saving\u2026');
            $status.text('').removeClass('msr-status-ok msr-status-err');

            $.ajax({
                url:         '/oms-zoho-dashboard/api/update_msr.php',
                type:        'POST',
                contentType: 'application/json',
                data:        JSON.stringify({ item_id: itemId, field_id: fieldId, value }),
            }).done(function (res) {
                if (res.success) {
                    $status.text('Saved.').addClass('msr-status-ok');
                    loadDetail(selectedId);
                } else {
                    $status.text('Error: ' + (res.message || 'unknown')).addClass('msr-status-err');
                    $layout.find('#btn-msr-save').prop('disabled', false).text('Save');
                }
            }).fail(function (jqXHR) {
                let msg = 'Save failed. Try again.';
                try { msg = JSON.parse(jqXHR.responseText).message || msg; } catch (e) {}
                $status.text(msg).addClass('msr-status-err');
                $layout.find('#btn-msr-save').prop('disabled', false).text('Save');
            });
        }

        // Unbind any stale handlers from a previous renderDetail call before rebinding.
        $detail.off('input.msr click.msr');

        // Live computation: Yearly = Monthly × 12, Term = Yearly × Multiplier
        $detail.on('input.msr', 'tr.lc-data-row td[contenteditable]', function () {
            const $tds       = $(this).closest('tr').find('td:not(.msr-del-cell)');
            const monthly    = parseMsrAmt($tds.eq(1).text());
            const multiplier = Math.max(1, parseFloat($tds.eq(3).text().replace(/[^0-9.]/g, '')) || 1);
            const yearly     = monthly * 12;
            const term       = yearly * multiplier;
            $tds.eq(2).text(formatCurrency(yearly));
            $tds.eq(4).text(formatCurrency(term));
        });

        $detail.on('click.msr', '#btn-msr-edit',    msrEnterEdit);
        $detail.on('click.msr', '#btn-msr-save',    msrSave);
        $detail.on('click.msr', '#btn-msr-cancel',  msrCancelEdit);
        $detail.on('click.msr', '#btn-msr-refresh', function () {
            loadDetail(selectedId, true);
        });

        $detail.on('click.msr', '.btn-msr-del-row', function () {
            $(this).closest('tr').remove();
        });

        $detail.on('click.msr', '.btn-msr-add-row', function () {
            const section  = $(this).data('section');
            const $layout  = $detail.find('.msr-layout');
            const colCount = parseInt($layout.data('lcColCount')) || 5;
            if (section === 'lc') {
                // cols: 0=Item(edit), 1=Monthly(edit), 2=Yearly(computed), 3=Multiplier(edit), 4=Term(computed)
                const cols = Array(colCount).fill(0).map((_, i) => {
                    const amtCls  = i > 0 ? ' amount-cell' : '';
                    const computed = i === 2 || i === 4;
                    return computed
                        ? `<td class="${amtCls} msr-cell-computed"></td>`
                        : `<td class="${amtCls} msr-cell-edit" contenteditable="true"></td>`;
                }).join('');
                const $row = $(`<tr class="lc-data-row">${cols}<td class="msr-del-cell"><button type="button" class="btn-msr-del-row" title="Delete row">&times;</button></td></tr>`);
                $(this).closest('tr').before($row);
                // Remove "No data" placeholder now that a real row exists.
                $detail.find('.msr-layout tr.msr-placeholder-row').first().remove();
                $row.find('td').first().focus();
            } else {
                const $row = $(`<tr class="ex-data-row">
                    <td contenteditable="true" class="msr-cell-edit"></td>
                    <td class="amount-cell msr-cell-edit" contenteditable="true"></td>
                    ${Array(colCount - 2).fill('<td></td>').join('')}
                    <td class="msr-del-cell"><button type="button" class="btn-msr-del-row" title="Delete row">&times;</button></td>
                </tr>`);
                $(this).closest('tr').before($row);
                // Remove "No extras" placeholder now that a real row exists.
                $detail.find('.msr-layout tr.msr-placeholder-row').last().remove();
                $row.find('td').first().focus();
            }
        });

        // Initialise charts with fast stub data — overview is visible immediately.
        initReportCharts(rpt);

        // Populate Monthly Pledges and Avg Deficit KPIs in the Overview bar.
        loadOvPledgesTotal(item, rpt.monthlyAvg, msrMonthlyRequired);

        // Populate the Overview attachments strip.
        loadOvAttachments(item.item_id);

        // Load transactions immediately when employee is selected.
        // When resolved, also re-draw Overview charts with item-filtered data.
        const $txnPane = $('#tab-transactions');
        $txnPane.html('<div class="detail-loading"><span class="spinner"></span></div>');
        transactionsReq
            .done(function (txnRes) {
                const cutoff = new Date();
                cutoff.setMonth(cutoff.getMonth() - 12);
                const transactions = (txnRes.data || []).filter(function (inv) {
                    return inv.date && new Date(inv.date) >= cutoff;
                });
                renderTransactionsTab($txnPane, transactions);

                // Re-draw Balance Trend and Income by Month with item-specific figures.
                if (!document.getElementById('rpt-balance')) return;
                const rptAccurate = buildReportData(transactions, msrMonthlyRequired);
                initReportCharts(rptAccurate);
                $('#ov-income-tbl tbody').html(buildIncomeTblRows(rptAccurate));
                $('#ov-income-total, #ov-income-cumtotal').text(formatCurrency(rptAccurate.yearTotal));
                $('#ov-funding-income').text(formatCurrency(rptAccurate.yearTotal));
                $('#ov-funding-pct').text(rptAccurate.percentFunded.toFixed(1) + '%');
                $('#ov-outstanding-pct').text(rptAccurate.percentOutstanding.toFixed(1) + '%');
                $('#ov-monthly-avg').text(formatCurrency(rptAccurate.monthlyAvg));
                const deficit = (msrMonthlyRequired || 0) - rptAccurate.monthlyAvg;
                $('#ov-avg-deficit').text(formatCurrency(deficit))
                    .toggleClass('kpi-deficit', deficit > 0)
                    .toggleClass('kpi-surplus', deficit <= 0);
            })
            .fail(function () { $txnPane.html('<p class="detail-empty-msg error-msg">Failed to load transactions. Try refreshing.</p>'); });

        // Tab switching — support and flow load lazily on first click.
        let supportReady = false;
        let flowReady    = false;
        $detail.find('.tab-btn').on('click', function () {
            const tab = $(this).data('tab');
            $detail.find('.tab-btn').removeClass('is-active');
            $(this).addClass('is-active');
            $detail.find('.tab-pane').addClass('is-hidden');
            $('#tab-' + tab).removeClass('is-hidden');

            if (tab === 'support' && !supportReady) {
                supportReady = true;
                renderSupportTab($('#tab-support'), item, msrMonthlyRequired);
            }
            if (tab === 'flow' && !flowReady) {
                flowReady = true;
                renderFlowTab($('#tab-flow'), item, contact);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Support tab — recurring pledges for this employee
    // -------------------------------------------------------------------------

    /**
     * Extract significant tokens from a name string for fuzzy matching.
     * Strips stop-words, punctuation, and common recurring-invoice filler words,
     * then returns an array of lowercase words >= 3 chars.
     *
     * e.g. "Donovan to Kumar"        → ["donovan", "kumar"]
     *      "Kumar Ben & Christie"    → ["kumar", "ben", "christie"]
     *      "Smith - Monthly Support" → ["smith"]
     */
    function supportTokens(str) {
        const stop = new Set([
            'to', 'and', 'or', 'the', 'of', 'for', 'in', 'a', 'an',
            'mr', 'mrs', 'ms', 'dr', 'rev',
            'monthly', 'support', 'pledge', 'donation', 'contribution',
            'fund', 'appeal', 'giving', 'from', 'by', 'with', 'at',
            'requirement', 'req',
        ]);
        return (str || '')
            .toLowerCase()
            .replace(/[^a-z\s]/g, ' ')
            .split(/\s+/)
            .filter(w => w.length >= 3 && !stop.has(w));
    }

    /**
     * Returns true when empName and riName share at least one significant token
     * (length >= 3, not a stop-word).  Handles cases like:
     *   "Kumar Ben & Christie"  ↔  "Donovan to Kumar"   → shared: "kumar"
     *   "Smith John"            ↔  "John & Mary Smith"  → shared: "smith"
     */
    /**
     * Returns true when empName and riName share at least one significant token.
     *
     * Two passes:
     *  1. Exact token match — "Kumar" in both names.
     *  2. Substring match   — employee token found inside a concatenated recurrence
     *                         word, e.g. "kumar" inside "CookforKumar" (≥4 chars only
     *                         to avoid false positives on short words like "ben").
     */
    function supportMatch(empName, riName) {
        const empTokens = supportTokens(empName);
        const riTokens  = supportTokens(riName);
        const empSet    = new Set(empTokens);
        const riRaw     = riName.toLowerCase();

        // Pass 1 — exact token match
        if (riTokens.some(t => empSet.has(t))) return true;

        // Pass 2 — employee token is a substring of a recurrence token (camelCase / "forX" patterns)
        for (const et of empTokens) {
            if (et.length < 4) continue;
            if (riRaw.includes(et)) return true;
        }

        return false;
    }

    /**
     * Format Zoho recurring invoice frequency into a readable label.
     * Uses repeat_every + recurrence_frequency fields from the API response.
     */
    function formatFrequency(ri) {
        const freq  = (ri.recurrence_frequency || '').toLowerCase();
        const every = parseInt(ri.repeat_every || 1, 10);

        if (freq === 'months' || freq === 'month') {
            if (every === 1)  return 'Monthly';
            if (every === 2)  return 'Every 2 Months';
            if (every === 3)  return 'Quarterly';
            if (every === 6)  return 'Half-Yearly';
            if (every === 12) return 'Annual';
            return 'Every ' + every + ' Months';
        }
        if (freq === 'weeks' || freq === 'week') {
            return every === 1 ? 'Weekly' : 'Every ' + every + ' Weeks';
        }
        if (freq === 'years' || freq === 'year') {
            return every === 1 ? 'Annual' : 'Every ' + every + ' Years';
        }
        if (freq === 'days' || freq === 'day') {
            return every === 1 ? 'Daily' : 'Every ' + every + ' Days';
        }
        return freq ? (freq.charAt(0).toUpperCase() + freq.slice(1)) : '\u2014';
    }

    function calcMonthlyPledge(r) {
        const amt   = r.invoiceAmount || 0;
        const freq  = (r.recurrence_frequency || '').toLowerCase();
        const every = parseInt(r.repeat_every || 1, 10) || 1;
        if (freq === 'months' || freq === 'month') return amt / every;
        if (freq === 'weeks'  || freq === 'week')  return (amt / every) * (52 / 12);
        if (freq === 'years'  || freq === 'year')  return amt / (every * 12);
        if (freq === 'days'   || freq === 'day')   return (amt / every) * (365 / 12);
        return amt;
    }

    function renderTransactionsTab($pane, transactions) {
        const rows_data = transactions || [];

        if (rows_data.length === 0) {
            $('#txn-tab-count').text('0');
            $pane.html('<p class="detail-empty-msg">No paid invoices found for this employee.</p>');
            return;
        }

        $('#txn-tab-count').text(rows_data.length);

        const STATUS_CLASS = {
            paid: 'txn-badge-paid', draft: 'txn-badge-draft', sent: 'txn-badge-sent',
            overdue: 'txn-badge-overdue', void: 'txn-badge-void',
            partially_paid: 'txn-badge-partial',
        };
        function statusBadge(status) {
            const s   = (status || '').toLowerCase();
            const cls = STATUS_CLASS[s] || 'txn-badge-other';
            const lbl = s === 'partially_paid' ? 'Partial'
                      : s ? s.charAt(0).toUpperCase() + s.slice(1) : '—';
            return s ? `<span class="txn-badge ${cls}">${escHtml(lbl)}</span>` : '—';
        }

        let sortCol = 'date';
        let sortDir = -1; // -1 = newest first

        function sortedData() {
            return [...rows_data].sort((a, b) => {
                if (sortCol === 'total') return sortDir * ((a.total || 0) - (b.total || 0));
                const av = sortCol === 'date' ? (a.date || '')
                         : sortCol === 'num'  ? (a.invoice_number || '')
                         :                      (a.customer_name  || '');
                const bv = sortCol === 'date' ? (b.date || '')
                         : sortCol === 'num'  ? (b.invoice_number || '')
                         :                      (b.customer_name  || '');
                return sortDir * av.localeCompare(bv);
            });
        }

        function buildRows(data) {
            return data.map(inv => `<tr data-amount="${inv.total || 0}">
                <td data-raw="${escHtml(inv.date || '')}">${formatDate(inv.date)}</td>
                <td>${escHtml(inv.invoice_number || '—')}</td>
                <td>${escHtml(inv.customer_name || '—')}</td>
                <td>${statusBadge(inv.status)}</td>
                <td class="amount-cell">${formatCurrency(inv.total || 0)}</td>
            </tr>`).join('');
        }

        const total = rows_data.reduce((s, inv) => s + (inv.total || 0), 0);

        $pane.html(`
            <div class="txn-toolbar">
                <div class="txn-filter-group">
                    <select id="txn-field" class="txn-field-select">
                        <option value="all">All columns</option>
                        <option value="0">Date</option>
                        <option value="1">Invoice #</option>
                        <option value="2">Customer</option>
                        <option value="3">Status</option>
                    </select>
                    <div class="txn-input-wrap">
                        <svg class="txn-search-icon" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="8.5" cy="8.5" r="5.5"/><path d="M15 15l-3-3"/>
                        </svg>
                        <input type="search" id="txn-filter" class="txn-filter-input"
                               placeholder="Search…" autocomplete="off">
                    </div>
                </div>
                <div class="txn-toolbar-right">
                    <span id="txn-count" class="txn-count">${rows_data.length} records</span>
                    <button id="txn-export" class="txn-export-btn" title="Export visible rows as CSV">
                        <svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14" aria-hidden="true">
                            <path d="M10 3v10M6 9l4 4 4-4M4 16h12"/>
                        </svg>
                        Export CSV
                    </button>
                </div>
            </div>
            <div class="detail-table-wrap">
                <table class="data-table invoices-table">
                    <thead><tr>
                        <th class="txn-th-sort" data-sort="date">Date</th>
                        <th class="txn-th-sort" data-sort="num">Invoice #</th>
                        <th class="txn-th-sort" data-sort="cust">Customer Name</th>
                        <th>Status</th>
                        <th class="amount-cell txn-th-sort" data-sort="total">Total</th>
                    </tr></thead>
                    <tbody>${buildRows(sortedData())}</tbody>
                    <tfoot><tr class="total-row">
                        <td colspan="4">Total</td>
                        <td class="amount-cell txn-total">${formatCurrency(total)}</td>
                    </tr></tfoot>
                </table>
            </div>
        `);

        function updateSortHeaders() {
            $pane.find('.txn-th-sort').each(function () {
                const col = $(this).data('sort');
                $(this).toggleClass('sort-asc',  col === sortCol && sortDir ===  1)
                       .toggleClass('sort-desc', col === sortCol && sortDir === -1);
            });
        }
        updateSortHeaders();

        $pane.find('.txn-th-sort').on('click', function () {
            const col = $(this).data('sort');
            sortDir = col === sortCol ? -sortDir : (col === 'date' ? -1 : 1);
            sortCol = col;
            updateSortHeaders();
            $pane.find('.invoices-table tbody').html(buildRows(sortedData()));
            applyTxnFilter();
        });

        function applyTxnFilter() {
            const q     = $pane.find('#txn-filter').val().trim().toLowerCase();
            const field = $pane.find('#txn-field').val();
            const $rows = $pane.find('.invoices-table tbody tr');
            $rows.each(function () {
                if (!q) { $(this).show(); return; }
                const cellText = field === 'all'
                    ? $(this).text().toLowerCase()
                    : $(this).find('td').eq(parseInt(field, 10)).text().toLowerCase();
                $(this).toggle(cellText.includes(q));
            });
            let filteredTotal = 0;
            $rows.filter(':visible').each(function () { filteredTotal += parseFloat($(this).data('amount')) || 0; });
            const visible = $rows.filter(':visible').length;
            const count   = $rows.length;
            $pane.find('.txn-total').text(formatCurrency(filteredTotal));
            $pane.find('#txn-count').text(q ? visible + ' of ' + count + ' records' : count + ' records');
        }
        $pane.find('#txn-filter, #txn-field').on('input change', applyTxnFilter);

        $pane.find('#txn-export').on('click', function () {
            const $rows = $pane.find('.invoices-table tbody tr:visible');
            const lines = [['Date', 'Invoice #', 'Customer Name', 'Status', 'Quantity', 'Price', 'Total']];
            $rows.each(function () {
                const tds = $(this).find('td');
                lines.push([
                    tds.eq(0).attr('data-raw') || tds.eq(0).text().trim(),
                    tds.eq(1).text().trim(),
                    tds.eq(2).text().trim(),
                    tds.eq(3).text().trim(),
                    tds.eq(4).text().trim(),
                    tds.eq(5).text().trim(),
                    parseFloat($(this).data('amount') || 0).toFixed(2),
                ]);
            });
            const csv  = lines.map(r => r.map(v => '"' + String(v).replace(/"/g, '""') + '"').join(',')).join('\r\n');
            const blob = new Blob([csv], { type: 'text/csv' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href     = url;
            a.download = 'transactions.csv';
            a.click();
            URL.revokeObjectURL(url);
        });
    }

    function loadOvPledgesTotal(item, monthlyAvg, msrMonthly) {
        const $pledges = $('#ov-pledges-total');
        const $deficit = $('#ov-avg-deficit');

        // Avg Deficit = Monthly MSR − Monthly Avg Income (always computed immediately).
        const deficit = (msrMonthly || 0) - monthlyAvg;
        $deficit.text(formatCurrency(deficit))
            .toggleClass('kpi-deficit', deficit > 0)
            .toggleClass('kpi-surplus', deficit <= 0);

        apiGet(PROXY + '?endpoint=books_recurring_all')
            .done(function (res) {
                const matched = (res.data || []).filter(ri => supportMatch(item.name, ri.recurrence_name));
                if (matched.length === 0) {
                    $pledges.text(formatCurrency(0));
                    return;
                }

                const enriched = matched.map(r => Object.assign({}, r, { invoiceAmount: 0 }));
                let remaining  = matched.length;

                matched.forEach(function (r, i) {
                    apiGet(PROXY + '?endpoint=books_recurring_detail&recurring_invoice_id=' + encodeURIComponent(r.recurring_invoice_id))
                        .done(function (res2) {
                            const d = res2.data || {};
                            enriched[i].invoiceAmount = parseFloat(d.amount || d.sub_total || 0);
                        })
                        .always(function () {
                            if (--remaining === 0) {
                                const bestMap = {};
                                enriched.forEach(r => {
                                    const key = r.customer_name || '—';
                                    if (!bestMap[key] || calcMonthlyPledge(r) > calcMonthlyPledge(bestMap[key])) {
                                        bestMap[key] = r;
                                    }
                                });
                                const pledgesTotal = Object.values(bestMap).reduce((s, r) => s + calcMonthlyPledge(r), 0);
                                $pledges.text(formatCurrency(pledgesTotal));
                            }
                        });
                });
            })
            .fail(function () { $pledges.text('—'); });
    }

    function renderSupportTab($pane, item, msrMonthly) {
        $pane.html('<div class="detail-loading"><span class="spinner"></span></div>');

        apiGet(PROXY + '?endpoint=books_recurring_all')
            .done(function (res) {
                const all = res.data || [];

                const matched = all.filter(function (ri) {
                    return supportMatch(item.name, ri.recurrence_name);
                });

                if (matched.length === 0) {
                    $pane.html('<p class="detail-empty-msg">No recurring pledges found for this employee.</p>');
                    return;
                }

                // The list endpoint returns total:0 — fetch each detail in parallel
                // to get the real Invoice Amount field.
                $pane.html('<div class="detail-loading"><span class="spinner"></span></div>');

                const enriched = matched.map(r => Object.assign({}, r, { invoiceAmount: 0 }));
                let remaining  = matched.length;

                function renderSupportTable() {
                    // For each customer name keep only the row with the highest monthly pledge.
                    const bestMap = {};
                    enriched.forEach(r => {
                        const key = r.customer_name || '\u2014';
                        if (!bestMap[key] || calcMonthlyPledge(r) > calcMonthlyPledge(bestMap[key])) {
                            bestMap[key] = r;
                        }
                    });
                    const deduped = Object.values(bestMap)
                        .sort((a, b) => (a.customer_name || '').localeCompare(b.customer_name || ''));

                    const monthlyTotal = deduped.reduce((s, r) => s + calcMonthlyPledge(r), 0);
                    const totalPct     = msrMonthly > 0 ? (monthlyTotal / msrMonthly) * 100 : 0;
                    const shortfall    = (msrMonthly || 0) - monthlyTotal;

                    const rows = deduped.map(r => {
                        const mp  = calcMonthlyPledge(r);
                        const yp  = mp * 12;
                        const pct = msrMonthly > 0 ? (mp / msrMonthly) * 100 : 0;
                        return `<tr>
                        <td>${escHtml(r.customer_name || '\u2014')}</td>
                        <td class="amount-cell">${formatCurrency(r.invoiceAmount)}</td>
                        <td>${escHtml(formatFrequency(r))}</td>
                        <td class="amount-cell">${formatCurrency(mp)}</td>
                        <td class="amount-cell">${formatCurrency(yp)}</td>
                        <td class="amount-cell">${pct.toFixed(1)}%</td>
                    </tr>`;
                    }).join('');

                    $pane.html(`
                        <div class="detail-table-wrap support-table-wrap">
                            <table class="data-table support-table">
                                <thead><tr>
                                    <th>Customer Name</th>
                                    <th class="amount-cell">Amount</th>
                                    <th>Frequency</th>
                                    <th class="amount-cell">Monthly Pledge</th>
                                    <th class="amount-cell">Yearly Pledge</th>
                                    <th class="amount-cell">% of MSR</th>
                                </tr></thead>
                                <tbody>${rows}</tbody>
                                <tfoot><tr class="total-row">
                                    <td colspan="3">Total</td>
                                    <td class="amount-cell">${formatCurrency(monthlyTotal)}</td>
                                    <td class="amount-cell">${formatCurrency(monthlyTotal * 12)}</td>
                                    <td class="amount-cell">${totalPct.toFixed(1)}%</td>
                                </tr></tfoot>
                            </table>
                        </div>
                        <div class="support-summary-bar">
                            <div class="support-kpi-card ${shortfall > 0 ? 'kpi-deficit' : 'kpi-surplus'}">
                                <span class="support-kpi-label">Monthly Shortfall</span>
                                <span class="support-kpi-value">${escHtml(formatCurrency(shortfall))}</span>
                                <span class="support-kpi-sub">MSR ${escHtml(formatCurrency(msrMonthly || 0))} &minus; Pledges ${escHtml(formatCurrency(monthlyTotal))}</span>
                            </div>
                            <div class="support-kpi-card kpi-surplus">
                                <span class="support-kpi-label">Percent Funded</span>
                                <span class="support-kpi-value">${totalPct.toFixed(1)}%</span>
                                <span class="support-kpi-sub">of Monthly Support Required</span>
                            </div>
                            <div class="support-kpi-card kpi-deficit">
                                <span class="support-kpi-label">Percent Outstanding</span>
                                <span class="support-kpi-value">${Math.max(0, 100 - totalPct).toFixed(1)}%</span>
                                <span class="support-kpi-sub">remaining to be raised</span>
                            </div>
                        </div>
                    `);
                }

                matched.forEach(function (r, i) {
                    apiGet(PROXY + '?endpoint=books_recurring_detail&recurring_invoice_id=' + encodeURIComponent(r.recurring_invoice_id))
                        .done(function (res) {
                            const detail = res.data || {};
                            enriched[i].invoiceAmount = parseFloat(detail.amount || detail.sub_total || 0);
                        })
                        .always(function () {
                            if (--remaining === 0) renderSupportTable();
                        });
                });
            })
            .fail(function () {
                $pane.html('<p class="detail-empty-msg error-msg">Failed to load support data. Try refreshing.</p>');
            });
    }

    // -------------------------------------------------------------------------
    // Flow tab — file uploads and attachment display
    // -------------------------------------------------------------------------

    const ATTACHMENTS_URL              = '/oms-zoho-dashboard/api/attachments.php';
    const UPLOADS_BASE                 = '/oms-zoho-dashboard/uploads/';
    const ZOHO_CONTACT_ATTACHMENTS_URL = '/oms-zoho-dashboard/api/zoho_contact_attachments.php';
    const ZOHO_CONTACT_UPLOAD_URL      = '/oms-zoho-dashboard/api/zoho_contact_upload.php';
    const ZOHO_CONTACT_DELETE_URL      = '/oms-zoho-dashboard/api/zoho_contact_delete_attachment.php';
    const ZOHO_ATTACHMENT_FILE_URL     = '/oms-zoho-dashboard/api/zoho_attachment_file.php';

    function zohoFileTypeMime(fileType) {
        const t = (fileType || '').toLowerCase();
        if (t === 'jpg' || t === 'jpeg') return 'image/jpeg';
        if (t === 'png')  return 'image/png';
        if (t === 'gif')  return 'image/gif';
        if (t === 'webp') return 'image/webp';
        if (t === 'pdf')  return 'application/pdf';
        return 'application/octet-stream';
    }

    function attFileUrl(itemId, fileId) {
        return UPLOADS_BASE + encodeURIComponent(itemId) + '/' + encodeURIComponent(fileId);
    }

    function attIcon(mime) {
        if (mime.startsWith('image/'))  return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="flow-file-icon"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
        if (mime === 'application/pdf') return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="flow-file-icon"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M9 13h6M9 17h4"/></svg>';
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="flow-file-icon"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>';
    }

    // ── Flow Details CSV helpers ──────────────────────────────────────────────

    function parseFlowCsv(raw) {
        if (!raw) return [];
        // Zoho Books multiline fields may return HTML — normalise to plain text
        let text = raw;
        if (text.includes('<')) {
            text = text.replace(/<br\s*\/?>/gi, '\n')
                       .replace(/<\/p>/gi, '\n')
                       .replace(/<\/div>/gi, '\n');
            const tmp = document.createElement('div');
            tmp.innerHTML = text;
            text = tmp.textContent || tmp.innerText || '';
        }
        const rows = [];
        const lines = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');
        for (let i = 0; i < lines.length; i++) {
            if (i === lines.length - 1 && lines[i].trim() === '') break;
            const line = lines[i];
            const comma = line.indexOf(',');
            if (comma === -1) {
                rows.push([line, '']);
            } else {
                rows.push([line.slice(0, comma), line.slice(comma + 1)]);
            }
        }
        return rows;
    }

    function serializeFlowCsv(rows) {
        return rows.map(function (r) {
            if (r[0] === '' && r[1] === '') return '';
            return r[0] + ',' + r[1];
        }).join('\n');
    }

    function renderFlowTab($pane, item, contact) {
        const pmCf   = (item.custom_fields || []).find(f => (f.label || '').toLowerCase() === 'project manager');
        const pmId   = pmCf ? String(pmCf.value || '').trim() : '';
        const pmName = pmId ? (contact && (contact.contact_name || contact.display_name || '')) || 'Project Manager' : '';

        // Flow Details custom field (CSV stored on the item itself)
        const flowCf    = (item.custom_fields || []).find(function (f) {
            const lbl = (f.label || '').toLowerCase();
            return lbl.includes('flow') && lbl.includes('detail');
        });
        let flowFldId = flowCf ? String(flowCf.customfield_id || flowCf.field_id || '').trim() : '';
        const flowCsv   = flowCf ? String(flowCf.value || '').trim() : '';

        if (!pmId && !flowCf) {
            $('#flow-tab-count').text('');
        }

        // Build Flow Details table HTML — always shown so rows/sections can be added
        const flowDetailsHtml = (function () {
            const rows = parseFlowCsv(flowCsv);
            // Section header: col2 empty AND col1 is a plain name (letters/spaces/& only)
            const namePattern = /^[A-Za-z\s&']+$/;
            const grip    = `<td class="fd-drag-col"><span class="fd-drag-handle" title="Drag to reorder"><svg width="8" height="14" viewBox="0 0 8 14" fill="currentColor"><circle cx="2" cy="2" r="1.4"/><circle cx="6" cy="2" r="1.4"/><circle cx="2" cy="7" r="1.4"/><circle cx="6" cy="7" r="1.4"/><circle cx="2" cy="12" r="1.4"/><circle cx="6" cy="12" r="1.4"/></svg></span></td>`;
            const actions = `<td class="fd-row-actions"><button class="fd-del-row-btn" title="Delete">&times;</button></td>`;
            const tbodyHtml = rows.map(function (r) {
                // Blank row → thin spacer
                if (r[0].trim() === '' && r[1].trim() === '') {
                    return '<tr class="fd-spacer-row"><td colspan="4"></td></tr>';
                }
                // Section header (person name)
                if (r[1] === '' && r[0].trim() !== '' && namePattern.test(r[0].trim())) {
                    return `<tr class="fd-header-row">${grip}<td class="fd-header-cell" colspan="2" contenteditable="true">${escHtml(r[0])}</td>${actions}</tr>`;
                }
                // Data row — right-align purely numeric values
                const valClass = /^[\d,./\-]+$/.test(r[1].trim()) ? ' class="fd-val-num"' : '';
                return `<tr>${grip}<td contenteditable="true">${escHtml(r[0])}</td><td contenteditable="true"${valClass}>${escHtml(r[1])}</td>${actions}</tr>`;
            }).join('');
            return `<div class="flow-details-wrap">
                <div class="flow-details-hd">
                    <span class="flow-details-title">Flow Details</span>
                    <div class="flow-details-actions">
                        <button class="fd-add-section" type="button">+ Section</button>
                        <button class="fd-add-row" type="button">+ Row</button>
                        <button class="fd-save" type="button">Save</button>
                    </div>
                </div>
                <div class="flow-details-body">
                    <table class="fd-table">
                        <tbody>${tbodyHtml}</tbody>
                    </table>
                </div>
                <div class="fd-save-status"></div>
            </div>`;
        })();

        const fileUploadHtml = pmId ? `
            <div class="flow-uploader-panel">
                <div class="flow-section-hd">
                    <span class="flow-section-title">${escHtml(pmName)}</span>
                    <span class="flow-section-count"></span>
                </div>
                <div class="flow-upload-zone flow-dropzone">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" class="flow-zone-icon">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <p class="flow-zone-label">Drop files here or
                        <label for="flow-file-pm" class="flow-zone-link">click to upload</label>
                    </p>
                    <p class="flow-zone-hint">Images, PDF, Word, Excel &bull; Max 10 MB each</p>
                    <input type="file" id="flow-file-pm" class="flow-file-input" multiple
                           accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx,.xls,.xlsx,.txt,.csv"
                           style="display:none">
                </div>
                <div class="flow-progress" style="display:none"></div>
                <div class="flow-list">
                    <div class="detail-loading"><span class="spinner"></span></div>
                </div>
            </div>` : '';

        $pane.html(`<div class="flow-section">${flowDetailsHtml}${fileUploadHtml}</div>`);

        const $section = $pane.find('.flow-section');

        // ── Flow Details event handlers ──────────────────────────────────────
        {
            const newGrip    = '<td class="fd-drag-col"><span class="fd-drag-handle" title="Drag to reorder"><svg width="8" height="14" viewBox="0 0 8 14" fill="currentColor"><circle cx="2" cy="2" r="1.4"/><circle cx="6" cy="2" r="1.4"/><circle cx="2" cy="7" r="1.4"/><circle cx="6" cy="7" r="1.4"/><circle cx="2" cy="12" r="1.4"/><circle cx="6" cy="12" r="1.4"/></svg></span></td>';
            const newActions = '<td class="fd-row-actions"><button class="fd-del-row-btn" title="Delete">&times;</button></td>';

            $section.on('click', '.fd-add-row', function () {
                const $tbody = $section.find('.fd-table tbody');
                $tbody.append('<tr>' + newGrip + '<td contenteditable="true"></td><td contenteditable="true"></td>' + newActions + '</tr>');
                $tbody.find('tr:last-child td[contenteditable]').first().focus();
            });

            $section.on('click', '.fd-add-section', function () {
                const $tbody = $section.find('.fd-table tbody');
                $tbody.append('<tr class="fd-header-row">' + newGrip + '<td class="fd-header-cell" colspan="2" contenteditable="true"></td>' + newActions + '</tr>');
                $tbody.find('tr:last-child td[contenteditable]').first().focus();
            });

            $section.on('click', '.fd-del-row-btn', function () {
                $(this).closest('tr').remove();
            });

            // ── Drag-and-drop row reordering ─────────────────────────────────
            let $dragRow = null;

            $section.on('mousedown', '.fd-drag-handle', function () {
                $(this).closest('tr').attr('draggable', 'true');
            });

            $section.on('dragstart', '.fd-table tr', function (e) {
                $dragRow = $(this);
                e.originalEvent.dataTransfer.effectAllowed = 'move';
                e.originalEvent.dataTransfer.setData('text/plain', '');
                setTimeout(function () { $dragRow && $dragRow.addClass('fd-dragging'); }, 0);
            });

            $section.on('dragover', '.fd-table tr', function (e) {
                if (!$dragRow || this === $dragRow[0]) return;
                e.preventDefault();
                const midY = this.getBoundingClientRect().top + this.getBoundingClientRect().height / 2;
                const below = e.originalEvent.clientY > midY;
                $(this).toggleClass('fd-drop-above', !below).toggleClass('fd-drop-below', below);
            });

            $section.on('dragleave', '.fd-table tr', function () {
                $(this).removeClass('fd-drop-above fd-drop-below');
            });

            $section.on('drop', '.fd-table tr', function (e) {
                if (!$dragRow || this === $dragRow[0]) return;
                e.preventDefault();
                const midY = this.getBoundingClientRect().top + this.getBoundingClientRect().height / 2;
                if (e.originalEvent.clientY > midY) {
                    $dragRow.insertAfter(this);
                } else {
                    $dragRow.insertBefore(this);
                }
                $(this).removeClass('fd-drop-above fd-drop-below');
            });

            $section.on('dragend', '.fd-table tr', function () {
                $(this).removeClass('fd-dragging').removeAttr('draggable');
                $section.find('.fd-drop-above, .fd-drop-below').removeClass('fd-drop-above fd-drop-below');
                $dragRow = null;
            });

            $section.on('click', '.fd-save', function () {
                const $btn    = $(this).prop('disabled', true).text('Saving…');
                const $status = $section.find('.fd-save-status');

                function doSave(fieldId) {
                    const rows = [];
                    $section.find('.fd-table tbody tr').each(function () {
                        if ($(this).hasClass('fd-spacer-row')) { rows.push(['', '']); return; }
                        const $tds = $(this).find('td[contenteditable]');
                        if ($(this).hasClass('fd-header-row')) {
                            rows.push([$tds.first().text(), '']);
                        } else {
                            rows.push([$tds.eq(0).text(), $tds.eq(1).text()]);
                        }
                    });
                    const csvValue = serializeFlowCsv(rows);
                    $.ajax({
                        url: '/oms-zoho-dashboard/api/update_msr.php',
                        type: 'POST', contentType: 'application/json',
                        data: JSON.stringify({ item_id: item.item_id, field_id: fieldId, value: csvValue }),
                    })
                    .done(function (res) {
                        if (res.success) {
                            $btn.text('Save');
                            $status.text('Saved').addClass('fd-status-ok').removeClass('fd-status-err');
                            setTimeout(function () { $status.text('').removeClass('fd-status-ok'); }, 2000);
                        } else {
                            $btn.text('Save');
                            $status.text(res.error || 'Save failed').addClass('fd-status-err').removeClass('fd-status-ok');
                        }
                    })
                    .fail(function (jqXHR) {
                        let msg = 'Save failed';
                        try { msg = (JSON.parse(jqXHR.responseText).error) || msg; } catch (e) {}
                        $btn.text('Save');
                        $status.text(msg).addClass('fd-status-err').removeClass('fd-status-ok');
                    })
                    .always(function () { $btn.prop('disabled', false); });
                }

                if (flowFldId) {
                    doSave(flowFldId);
                } else {
                    // Field ID unknown — look it up from the org-level custom field definitions
                    $.getJSON('/oms-zoho-dashboard/api/proxy.php?endpoint=books_all_item_customfields')
                        .done(function (res) {
                            const found = (res.data || []).find(function (f) {
                                const lbl = (f.label || '').toLowerCase();
                                return lbl.includes('flow') && lbl.includes('detail');
                            });
                            if (found && found.customfield_id) {
                                flowFldId = String(found.customfield_id).trim();
                                doSave(flowFldId);
                            } else {
                                $btn.prop('disabled', false).text('Save');
                                $status.text('Flow Details custom field not found in Zoho Books settings.')
                                       .addClass('fd-status-err').removeClass('fd-status-ok');
                            }
                        })
                        .fail(function () {
                            $btn.prop('disabled', false).text('Save');
                            $status.text('Could not look up custom field. Please try again.')
                                   .addClass('fd-status-err').removeClass('fd-status-ok');
                        });
                }
            });
        }

        // ── File attachments ─────────────────────────────────────────────────
        function fetchAndRender() {
            $section.find('.flow-list').html('<div class="detail-loading"><span class="spinner"></span></div>');
            $.getJSON(ZOHO_CONTACT_ATTACHMENTS_URL + '?contact_id=' + encodeURIComponent(pmId))
                .done(function (res) { renderList(res.documents || []); })
                .fail(function (jqXHR) {
                    let msg = 'Failed to load attachments.';
                    try { msg = (JSON.parse(jqXHR.responseText).error) || msg; } catch (e) {}
                    $section.find('.flow-list').html('<p class="flow-empty error-msg">' + escHtml(msg) + '</p>');
                });
        }

        function renderList(files) {
            const $list = $section.find('.flow-list');
            $section.find('.flow-section-count').text(files.length || '');
            $('#flow-tab-count').text(files.length || '');
            if (!files.length) { $list.html(''); return; }
            $list.html(files.map(function (f) {
                const mime    = zohoFileTypeMime(f.file_type);
                const url     = ZOHO_ATTACHMENT_FILE_URL
                                + '?contact_id='  + encodeURIComponent(pmId)
                                + '&document_id=' + encodeURIComponent(f.document_id);
                const isImage = mime.startsWith('image/');
                const preview = isImage
                    ? `<img src="${escAttr(url)}" class="flow-thumb" alt="${escAttr(f.file_name)}" loading="lazy">`
                    : `<div class="flow-icon-wrap">${attIcon(mime)}</div>`;
                return `<div class="flow-card" data-id="${escAttr(f.document_id)}">
                    <a href="${escAttr(url)}" target="_blank" class="flow-preview">${preview}</a>
                    <div class="flow-info">
                        <span class="flow-name" title="${escAttr(f.file_name)}">${escHtml(f.file_name)}</span>
                        <span class="flow-meta">${f.uploaded_time ? escHtml(formatDate(f.uploaded_time)) : ''}${f.file_size ? ' &bull; ' + escHtml(f.file_size) : ''}</span>
                    </div>
                    <button class="flow-del-btn" data-id="${escAttr(f.document_id)}" title="Delete">&times;</button>
                </div>`;
            }).join(''));
            // error event doesn't bubble so delegate won't work — bind directly after DOM insert
            $list.find('img.flow-thumb').on('error', function () {
                $(this).closest('.flow-preview').html('<div class="flow-icon-wrap">' + attIcon('image/jpeg') + '</div>');
            });
        }

        function uploadFiles(fileList) {
            const files = Array.from(fileList);
            if (!files.length) return;
            const $prog = $section.find('.flow-progress').show().html('');
            let pending = files.length;

            files.forEach(function (file) {
                const $row = $(`<div class="flow-prog-row">
                    <span class="flow-prog-name">${escHtml(file.name)}</span>
                    <span class="flow-prog-status">Uploading…</span>
                </div>`);
                $prog.append($row);

                const fd = new FormData();
                fd.append('contact_id', pmId);
                fd.append('file', file);

                $.ajax({ url: ZOHO_CONTACT_UPLOAD_URL, type: 'POST', data: fd, processData: false, contentType: false })
                    .done(function (res) {
                        if (res.success) {
                            $row.find('.flow-prog-status').text('Done').addClass('flow-prog-ok');
                        } else {
                            $row.find('.flow-prog-status').text(res.error || 'Failed').addClass('flow-prog-err');
                        }
                    })
                    .fail(function (jqXHR) {
                        let msg = 'Failed';
                        try { msg = (JSON.parse(jqXHR.responseText).error) || msg; } catch (e) {}
                        $row.find('.flow-prog-status').text(msg).addClass('flow-prog-err');
                    })
                    .always(function () {
                        if (--pending === 0) {
                            setTimeout(function () { $prog.hide().html(''); fetchAndRender(); }, 1200);
                        }
                    });
            });
        }

        if (pmId) {
            fetchAndRender();

            $section.on('change', '.flow-file-input', function () {
                uploadFiles(this.files);
                this.value = '';
            });

            $section.find('.flow-dropzone')
                .on('click', function (e) {
                    if ($(e.target).closest('label, input[type=file]').length) return;
                    $section.find('.flow-file-input').trigger('click');
                })
                .on('dragover dragenter', function (e) {
                    e.preventDefault();
                    $(this).addClass('flow-drop-active');
                })
                .on('dragleave drop', function (e) {
                    e.preventDefault();
                    $(this).removeClass('flow-drop-active');
                    if (e.type === 'drop') uploadFiles(e.originalEvent.dataTransfer.files);
                });

            $section.on('click', '.flow-del-btn', function () {
                const docId = $(this).data('id');
                if (!confirm('Delete this attachment?')) return;
                $.ajax({
                    url: ZOHO_CONTACT_DELETE_URL, type: 'POST', contentType: 'application/json',
                    data: JSON.stringify({ contact_id: pmId, document_id: docId }),
                })
                .done(function () { fetchAndRender(); })
                .fail(function (jqXHR) {
                    let msg = 'Failed to delete. Please try again.';
                    try { msg = (JSON.parse(jqXHR.responseText).error) || msg; } catch (e) {}
                    alert(msg);
                });
            });
        }
    }

    function loadOvAttachments(itemId) {
        const $strip = $('#ov-attachments');
        if (!$strip.length) return;
        $.getJSON(ATTACHMENTS_URL + '?item_id=' + encodeURIComponent(itemId))
            .done(function (res) {
                const files = (res.attachments || []).slice(0, 8);
                if (!files.length) { $strip.empty(); return; }
                const items = files.map(function (f) {
                    const url     = attFileUrl(itemId, f.id);
                    const isImage = f.mime.startsWith('image/');
                    if (isImage) {
                        return `<a href="${escAttr(url)}" target="_blank" class="ov-att-thumb">
                            <img src="${escAttr(url)}" alt="${escAttr(f.original_name)}" loading="lazy">
                        </a>`;
                    }
                    return `<a href="${escAttr(url)}" target="_blank" class="ov-att-file" title="${escAttr(f.original_name)}">
                        ${attIcon(f.mime)}
                        <span>${escHtml(f.original_name.length > 22 ? f.original_name.slice(0, 20) + '…' : f.original_name)}</span>
                    </a>`;
                }).join('');
                $strip.html(`
                    <div class="ov-att-header">
                        <span class="ov-att-title">Attachments</span>
                        <span class="ov-att-count">${files.length}</span>
                    </div>
                    <div class="ov-att-grid">${items}</div>
                `);
            });
    }

    // -------------------------------------------------------------------------
    // Report data computation
    // -------------------------------------------------------------------------

    /**
     * Compute all report metrics from the employee's paid invoices array.
     * Each invoice: { invoice_id, invoice_number, date, customer_name, total }
     */
    function buildIncomeTblRows(rpt) {
        let cumSum = 0;
        return rpt.monthLabels.map(function (lbl, i) {
            const inc = rpt.monthlyIncome[i];
            cumSum += inc;
            const incStr = inc    > 0 ? formatCurrency(inc)    : '—';
            const cumStr = cumSum > 0 ? formatCurrency(cumSum) : '—';
            return '<tr><td>' + lbl + '</td>'
                + '<td class="amount-cell">' + escHtml(incStr) + '</td>'
                + '<td class="amount-cell">' + escHtml(cumStr) + '</td></tr>';
        }).join('');
    }

    function buildReportData(invoices, msrMonthly) {
        const MONTH_ABBR = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        const now = new Date();

        // ── 1. Monthly income — last 12 rolling months (oldest → newest) ────
        const buckets = [];
        for (let i = 11; i >= 0; i--) {
            const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
            buckets.push({ year: d.getFullYear(), month: d.getMonth() });
        }
        const monthlyIncome = Array(12).fill(0);
        invoices.forEach(function (inv) {
            if (!inv.date) return;
            const d = new Date(inv.date);
            const idx = buckets.findIndex(function (b) {
                return b.year === d.getFullYear() && b.month === d.getMonth();
            });
            if (idx >= 0) monthlyIncome[idx] += parseFloat(inv.total || 0);
        });
        const monthLabels = buckets.map(function (b) {
            return MONTH_ABBR[b.month] + (b.year !== now.getFullYear() ? ' \'' + String(b.year).slice(2) : '');
        });

        // ── 2. Cumulative income (Jan → each month, current year) ────────────
        let running = 0;
        const cumulativeIncome = monthlyIncome.map(v => { running += v; return running; });
        const yearTotal = cumulativeIncome[11];

        // ── 3. Yearly support target — from MSR Monthly Support Required × 12 ──
        const totalYearlySupport = (msrMonthly || 0) * 12;

        // ── 4. Balance per month = Yearly Support − cumulative income ────────
        const balance = cumulativeIncome.map(c => totalYearlySupport - c);

        // ── 5. Pie chart — last 12 months income vs MSR yearly target ────────
        const percentFunded      = totalYearlySupport > 0
            ? Math.min(100, (yearTotal / totalYearlySupport) * 100)
            : 0;
        const percentOutstanding = Math.max(0, 100 - percentFunded);

        const monthsWithData = monthlyIncome.filter(v => v > 0).length;
        const monthlyAvg     = monthsWithData > 0 ? yearTotal / monthsWithData : 0;

        return {
            monthLabels,
            monthlyIncome,
            cumulativeIncome,
            balance,
            yearTotal,
            monthlyAvg,
            totalYearlySupport,
            percentFunded,
            percentOutstanding,
        };
    }

    // -------------------------------------------------------------------------
    // Chart initialisation (called lazily on first Reports tab click)
    // -------------------------------------------------------------------------

    function initReportCharts(rpt) {
        const auCurrency = v => formatCurrency(v);

        const lineOpts = (datasets) => ({
            type: 'line',
            data: { labels: rpt.monthLabels, datasets },
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
