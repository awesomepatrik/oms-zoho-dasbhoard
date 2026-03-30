/**
 * tables.js — jQuery table renderers for the Zoho Dashboard.
 *
 * Depends on: jQuery (loaded via CDN in index.php)
 */

'use strict';

const ZohoTables = (() => {

    // -------------------------------------------------------------------------
    // Upcoming invoice runs table
    // -------------------------------------------------------------------------

    /**
     * Render the upcoming recurring invoice run dates table.
     *
     * @param {Array} invoices  Array of {
     *   customer_name: string,
     *   recurrence_name: string,
     *   next_invoice_date: string,  // 'YYYY-MM-DD'
     *   amount: number,
     *   status: string,             // 'active' | 'paused' | 'stopped'
     * }
     */
    function renderUpcomingInvoices(invoices) {
        const $container = $('#table-upcoming-invoices');

        if (!invoices || invoices.length === 0) {
            $container.html('<p class="loading">No upcoming invoices found.</p>');
            return;
        }

        // Sort by next_invoice_date ascending.
        const sorted = [...invoices].sort((a, b) =>
            new Date(a.next_invoice_date) - new Date(b.next_invoice_date)
        );

        const rows = sorted.map(inv => `
            <tr>
                <td>${escHtml(inv.customer_name)}</td>
                <td>${escHtml(inv.recurrence_name)}</td>
                <td>${formatDate(inv.next_invoice_date)}</td>
                <td>${formatCurrency(inv.amount)}</td>
                <td><span class="badge badge-${escHtml(inv.status)}">${escHtml(inv.status)}</span></td>
            </tr>
        `).join('');

        $container.html(`
            <table class="data-table" id="tbl-upcoming">
                <thead>
                    <tr>
                        <th data-col="customer_name">Donor</th>
                        <th data-col="recurrence_name">Schedule</th>
                        <th data-col="next_invoice_date">Next Run</th>
                        <th data-col="amount">Amount</th>
                        <th data-col="status">Status</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `);

        attachSortHandlers('#tbl-upcoming', sorted, renderUpcomingInvoices);
    }

    // -------------------------------------------------------------------------
    // Employee pledge detail table
    // -------------------------------------------------------------------------

    /**
     * Render the employee pledge detail table.
     *
     * @param {Array} employees  Array of {
     *   name: string,
     *   target: number,
     *   pledged: number,
     *   pct: number,
     *   active: number,
     *   paused: number,
     *   stopped: number,
     * }
     */
    function renderPledgeDetail(employees) {
        const $container = $('#table-pledge-detail');

        if (!employees || employees.length === 0) {
            $container.html('<p class="loading">No employee data found.</p>');
            return;
        }

        const sorted = [...employees].sort((a, b) => b.pct - a.pct);

        const rows = sorted.map(emp => {
            const pct     = emp.pct.toFixed(1);
            const barFill = Math.min(emp.pct, 100).toFixed(1);
            const overCls = emp.pct >= 100 ? ' over-target' : '';

            return `
                <tr>
                    <td>${escHtml(emp.name)}</td>
                    <td>${formatCurrency(emp.target)}</td>
                    <td>${formatCurrency(emp.pledged)}</td>
                    <td>
                        <div class="progress-wrap">
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill${overCls}" style="width:${barFill}%"></div>
                            </div>
                            <span class="progress-label">${pct}%</span>
                        </div>
                    </td>
                    <td>${emp.active}</td>
                    <td>${emp.paused}</td>
                    <td>${emp.stopped}</td>
                </tr>
            `;
        }).join('');

        $container.html(`
            <table class="data-table" id="tbl-pledge-detail">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Target</th>
                        <th>Pledged</th>
                        <th>Funded</th>
                        <th>Active</th>
                        <th>Paused</th>
                        <th>Stopped</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `);
    }

    // -------------------------------------------------------------------------
    // Sortable column helper
    // -------------------------------------------------------------------------

    /**
     * Attach click-to-sort handlers to table header cells.
     * Clicking a header re-sorts the source data and re-renders the table.
     *
     * @param {string}   tableSelector  jQuery selector for the table.
     * @param {Array}    data           Source data array (objects).
     * @param {Function} renderFn       The render function to call after sorting.
     */
    function attachSortHandlers(tableSelector, data, renderFn) {
        let sortCol = null;
        let sortAsc = true;

        $(tableSelector + ' thead th').on('click', function () {
            const col = $(this).data('col');
            if (!col) return;

            if (sortCol === col) {
                sortAsc = !sortAsc;
            } else {
                sortCol = col;
                sortAsc = true;
            }

            const sorted = [...data].sort((a, b) => {
                const va = a[col], vb = b[col];
                if (va === vb) return 0;
                if (typeof va === 'number') return sortAsc ? va - vb : vb - va;
                return sortAsc
                    ? String(va).localeCompare(String(vb))
                    : String(vb).localeCompare(String(va));
            });

            renderFn(sorted);
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

    function formatDate(iso) {
        if (!iso) return '—';
        const d = new Date(iso + 'T00:00:00');
        return d.toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function escHtml(str) {
        return $('<span>').text(String(str ?? '')).html();
    }

    // Public API
    return {
        renderUpcomingInvoices,
        renderPledgeDetail,
    };

})();
