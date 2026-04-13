/**
 * employee.js — Individual employee dashboard (employee.php).
 *
 * Reads ITEM_ID (set by PHP), fetches Books Items + paid invoices,
 * then renders:
 *   1. Employee overview (information from the Books Item record)
 *   2. Invoices — table of individual paid invoices for this item
 *
 * Depends on: jQuery, tables.js
 * Requires: window.ITEM_ID (set inline by employee.php)
 */

'use strict';

$(function () {

    const PROXY   = '/oms-zoho-dashboard/zoho-dashboard/api/proxy.php';
    const ITEM_ID = String(window.ITEM_ID || '');

    if (!ITEM_ID) {
        window.location.href = '/oms-zoho-dashboard/zoho-dashboard/index.php';
        return;
    }

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

        const invoiceEndpoint = PROXY + '?endpoint=books_invoices_by_item&item_id=' + encodeURIComponent(ITEM_ID) + suffix;

        return $.when(
            $.getJSON(PROXY + '?endpoint=books_items' + suffix),
            $.getJSON(invoiceEndpoint),
        ).then(function (itemsRes, invoicesRes) {
            const items    = itemsRes[0].data    || [];
            const invoices = invoicesRes[0].data || [];

            const item = items.find(i => String(i.item_id) === ITEM_ID);

            if (!item) {
                showError('Employee not found. The item may have been deleted in Zoho Books.');
                return;
            }

            // Update page title.
            document.title = (item.name || 'Employee') + ' — Mission Agency Dashboard';
            $('#page-title').text(item.name || 'Employee Dashboard');

            const invoiceRows = buildInvoiceRows(invoices);

            renderOverview(item);
            ZohoTables.renderInvoicesTable('invoices-table-wrap', invoiceRows);

        }).fail(function (jqXHR) {
            if (jqXHR.status === 401) {
                window.location.href = '/oms-zoho-dashboard/zoho-dashboard/auth/connect.php';
                return;
            }
            showError('Failed to load employee data. Please try refreshing.');
        });
    }

    // -------------------------------------------------------------------------
    // Build individual invoice rows for this item
    // -------------------------------------------------------------------------

    /**
     * Return one row per invoice, sorted newest first.
     * Amount is the sum of line items matching this item_id (falls back
     * to invoice total if line_items are absent in the response).
     */
    /**
     * Build invoice rows from the server-filtered invoice list.
     * The server already filters by item_id + paid status, so all
     * returned invoices belong to this employee.
     */
    function buildInvoiceRows(invoices) {
        return invoices
            .filter(inv => inv.date)
            .map(inv => ({
                invoice_number: inv.invoice_number || '',
                date:           inv.date,
                customer_name:  inv.customer_name  || '',
                amount:         parseFloat(inv.total || 0),
            }))
            .sort((a, b) => new Date(b.date) - new Date(a.date));
    }

    // -------------------------------------------------------------------------
    // Render employee overview
    // -------------------------------------------------------------------------

    function renderOverview(item) {
        const statusCls  = (item.status || '').toLowerCase() === 'active' ? 'badge-active' : 'badge-stopped';
        const statusText = item.status || 'unknown';

        const rows = [
            item.description  ? ['Description', escHtml(item.description)]                          : null,
            item.rate         ? ['Rate',         formatCurrency(parseFloat(item.rate || 0))]         : null,
            item.account_name ? ['Account',      escHtml(item.account_name)]                         : null,
            item.item_type    ? ['Type',          escHtml(capitalise(item.item_type.replace(/_/g, ' ')))] : null,
            item.sku          ? ['SKU',           escHtml(item.sku)]                                 : null,
        ].filter(Boolean);

        const tableRows = rows.map(([label, value]) => `
            <tr>
                <th>${label}</th>
                <td>${value}</td>
            </tr>
        `).join('');

        $('#emp-overview').html(`
            <div class="overview-header">
                <span class="overview-name">${escHtml(item.name || '—')}</span>
                <span class="badge ${statusCls}">${escHtml(statusText)}</span>
            </div>
            <table class="overview-table">
                <tbody>${tableRows}</tbody>
            </table>
        `);
    }

    // -------------------------------------------------------------------------
    // Error state
    // -------------------------------------------------------------------------

    function showError(msg) {
        $('#emp-overview, #invoices-table-wrap').html(`<p class="error-msg">${escHtml(msg)}</p>`);
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-AU', {
            style: 'currency', currency: 'AUD', maximumFractionDigits: 0,
        }).format(amount);
    }

    function escHtml(str) {
        return $('<span>').text(String(str ?? '')).html();
    }

    function capitalise(str) {
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    }

});
