
(function () {
    var sidebar = document.getElementById('sidebar');
    var content = document.getElementById('content');
    var topbar = document.getElementById('topbar');
    var toggleBtn = document.getElementById('toggleBtn');
    var mobileBtn = document.getElementById('mobileBtn');
    var overlay = document.getElementById('overlay');

    function toggleDesktop() {
        if (!sidebar || !content || !topbar) return;
        sidebar.classList.toggle('collapsed');
        content.classList.toggle('full');
        topbar.classList.toggle('full');
        document.body.classList.toggle('inapp-collapsed');
    }

    function openMobile() {
        if (sidebar) sidebar.classList.add('mobile-show');
        if (overlay) overlay.classList.add('show');
    }

    function closeMobile() {
        if (sidebar) sidebar.classList.remove('mobile-show');
        if (overlay) overlay.classList.remove('show');
    }

    if (toggleBtn) toggleBtn.addEventListener('click', toggleDesktop);
    if (mobileBtn) mobileBtn.addEventListener('click', openMobile);
    if (overlay) overlay.addEventListener('click', closeMobile);

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') closeMobile();
    });
})();
