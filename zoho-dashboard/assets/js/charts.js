/**
 * charts.js — Chart.js rendering functions for the Zoho Dashboard.
 *
 * All functions accept plain data arrays/objects and render into
 * the canvas elements defined in index.php.
 *
 * Depends on: Chart.js (loaded via CDN in index.php)
 */

'use strict';

const ZohoCharts = (() => {

    // Shared colour palette
    const COLOURS = {
        blue:   '#2e86c1',
        navy:   '#1a5276',
        orange: '#e67e22',
        green:  '#1e8449',
        yellow: '#d4ac0d',
        red:    '#c0392b',
        grey:   '#aab7b8',
    };

    // Destroy an existing chart on a canvas before re-drawing.
    function destroyIfExists(canvasId) {
        const existing = Chart.getChart(canvasId);
        if (existing) existing.destroy();
    }

    // -------------------------------------------------------------------------
    // Pledge status doughnut
    // -------------------------------------------------------------------------

    /**
     * Render a doughnut chart showing pledge status breakdown.
     *
     * @param {Object} counts  e.g. { active: 42, paused: 5, stopped: 3 }
     */
    function renderPledgeStatus(counts) {
        destroyIfExists('chart-pledge-status');

        const labels = Object.keys(counts).map(k => capitalise(k));
        const values = Object.values(counts);
        const bgColours = [COLOURS.green, COLOURS.yellow, COLOURS.red, COLOURS.grey];

        new Chart(document.getElementById('chart-pledge-status'), {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{
                    data: values,
                    backgroundColor: bgColours.slice(0, values.length),
                    borderWidth: 2,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        callbacks: {
                            label: ctx => ` ${ctx.label}: ${ctx.parsed} pledges`,
                        },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Income by month bar chart
    // -------------------------------------------------------------------------

    /**
     * Render a bar chart of income by month.
     *
     * @param {Array} months  Array of { label: 'Jan 2025', amount: 12345.67 }
     */
    function renderIncomeByMonth(months) {
        destroyIfExists('chart-income-month');

        new Chart(document.getElementById('chart-income-month'), {
            type: 'bar',
            data: {
                labels:   months.map(m => m.label),
                datasets: [{
                    label:           'Income (AUD)',
                    data:            months.map(m => m.amount),
                    backgroundColor: COLOURS.blue,
                    borderRadius:    4,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + formatCurrency(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: v => formatCurrency(v),
                        },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Funding % per employee horizontal bar chart
    // -------------------------------------------------------------------------

    /**
     * Render a horizontal bar chart of funding percentage per employee.
     *
     * @param {Array} employees  Array of { name: 'Jane Smith', pct: 87.5, target: 100 }
     */
    function renderFundingPerEmployee(employees) {
        destroyIfExists('chart-funding-per-employee');

        // Sort descending by pct for readability.
        const sorted = [...employees].sort((a, b) => b.pct - a.pct);

        new Chart(document.getElementById('chart-funding-per-employee'), {
            type: 'bar',
            data: {
                labels:   sorted.map(e => e.name),
                datasets: [
                    {
                        label:           'Funded %',
                        data:            sorted.map(e => Math.min(e.pct, 100)),
                        backgroundColor: sorted.map(e => e.pct >= 100 ? COLOURS.green : COLOURS.blue),
                        borderRadius:    4,
                    },
                    {
                        label:           'Target',
                        data:            sorted.map(() => 100),
                        backgroundColor: 'rgba(0,0,0,0.06)',
                        borderRadius:    4,
                    },
                ],
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ctx.datasetIndex === 0
                                ? ` ${ctx.parsed.x.toFixed(1)}% funded`
                                : ' Target: 100%',
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 120,
                        ticks: { callback: v => v + '%' },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Balance trend line chart
    // -------------------------------------------------------------------------

    /**
     * Render a line chart of balance over time.
     *
     * @param {Array} points  Array of { label: 'Jan 2025', balance: 98450.00 }
     */
    function renderBalanceTrend(points) {
        destroyIfExists('chart-balance-trend');

        new Chart(document.getElementById('chart-balance-trend'), {
            type: 'line',
            data: {
                labels:   points.map(p => p.label),
                datasets: [{
                    label:           'Balance (AUD)',
                    data:            points.map(p => p.balance),
                    borderColor:     COLOURS.navy,
                    backgroundColor: 'rgba(26, 82, 118, 0.1)',
                    fill:            true,
                    tension:         0.3,
                    pointRadius:     4,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + formatCurrency(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    y: {
                        ticks: { callback: v => formatCurrency(v) },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Utilities
    // -------------------------------------------------------------------------

    function formatCurrency(amount) {
        return new Intl.NumberFormat('en-AU', {
            style: 'currency', currency: 'AUD', maximumFractionDigits: 0,
        }).format(amount);
    }

    function capitalise(str) {
        return str.charAt(0).toUpperCase() + str.slice(1).toLowerCase();
    }

    // -------------------------------------------------------------------------
    // Income trend line chart (per-employee dashboard)
    // -------------------------------------------------------------------------

    /**
     * Render a line chart showing income trend by month for a single employee.
     *
     * @param {string} canvasId  ID of the canvas element.
     * @param {Array}  months    Array of { label: 'Jan 2025', amount: 1234.56 }
     */
    function renderIncomeTrendLine(canvasId, months) {
        destroyIfExists(canvasId);

        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        if (!months || months.length === 0) {
            const ctx = canvas.getContext('2d');
            canvas.height = 80;
            ctx.font      = '13px system-ui, sans-serif';
            ctx.fillStyle = '#6c757d';
            ctx.textAlign = 'center';
            ctx.fillText('No paid income recorded', canvas.width / 2, 40);
            return;
        }

        new Chart(canvas, {
            type: 'line',
            data: {
                labels:   months.map(m => m.label),
                datasets: [{
                    label:           'Income (AUD)',
                    data:            months.map(m => m.amount),
                    borderColor:     COLOURS.navy,
                    backgroundColor: 'rgba(26, 82, 118, 0.08)',
                    fill:            true,
                    tension:         0.3,
                    pointRadius:     4,
                    pointHoverRadius: 6,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + formatCurrency(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: v => formatCurrency(v) },
                    },
                },
            },
        });
    }

    // -------------------------------------------------------------------------
    // Per-employee income trend bar chart
    // -------------------------------------------------------------------------

    /**
     * Render a bar chart of income by month for a single employee.
     *
     * @param {string} canvasId  ID of the canvas element.
     * @param {Array}  months    Array of { label: 'Jan 2025', amount: 1234.56 }
     */
    function renderEmployeeIncomeTrend(canvasId, months) {
        destroyIfExists(canvasId);

        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        if (!months || months.length === 0) {
            const ctx = canvas.getContext('2d');
            ctx.font = '13px system-ui, sans-serif';
            ctx.fillStyle = '#6c757d';
            ctx.textAlign = 'center';
            ctx.fillText('No paid income recorded', canvas.width / 2, 60);
            return;
        }

        new Chart(canvas, {
            type: 'bar',
            data: {
                labels:   months.map(m => m.label),
                datasets: [{
                    label:           'Income (AUD)',
                    data:            months.map(m => m.amount),
                    backgroundColor: COLOURS.blue,
                    borderRadius:    4,
                }],
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + formatCurrency(ctx.parsed.y),
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: { callback: v => formatCurrency(v) },
                    },
                },
            },
        });
    }

    // Public API
    return {
        renderPledgeStatus,
        renderIncomeByMonth,
        renderFundingPerEmployee,
        renderBalanceTrend,
        renderEmployeeIncomeTrend,
        renderIncomeTrendLine,
    };

})();
