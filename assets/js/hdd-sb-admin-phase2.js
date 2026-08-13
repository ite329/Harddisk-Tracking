/* harddisk_delivery_web - SB Admin 2 Phase 2 enhancement */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') {
            fn();
        } else {
            document.addEventListener('DOMContentLoaded', fn);
        }
    }

    ready(function () {
        document.body.classList.add('hdd-sb-admin-phase2');

        // Add SB Admin-friendly classes to existing module cards without touching PHP logic.
        var customCards = document.querySelectorAll('.request-card, .assign-card, .asset-card, .server-card, .km-card, .branch-card, .dashboard-card, .kpi-card, .stat-card');
        customCards.forEach(function (el) {
            if (!el.classList.contains('card')) {
                el.classList.add('card');
            }
        });

        // Normalize table containers.
        document.querySelectorAll('.table-responsive, .table-wrap, .table-scroll').forEach(function (el) {
            el.classList.add('hdd2-table-wrap');
        });

        // Keep small action buttons compact across modules.
        document.querySelectorAll('td .btn, .action-cell .btn, .action-buttons .btn').forEach(function (btn) {
            btn.classList.add('btn-sm');
        });

        // Improve selected row visibility.
        document.querySelectorAll('tr.table-primary').forEach(function (row) {
            row.setAttribute('aria-current', 'true');
        });
    });
})();
