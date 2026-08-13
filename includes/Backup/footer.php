            </main>
        </div>

        <footer class="sticky-footer bg-white hdd-footer">
            <div class="container my-auto">
                <div class="copyright text-center my-auto">
                    <span>IT Support System &copy; <?php echo date('Y'); ?></span>
                </div>
            </div>
        </footer>
    </div>
</div>

<a class="scroll-to-top rounded" href="#page-top" aria-label="Scroll to top">
    <span>⌃</span>
</a>

<?php $baseUrl = $baseUrl ?? '/harddisk_delivery_web'; ?>
<script src="<?php echo $baseUrl; ?>/assets/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo $baseUrl; ?>/assets/js/app.js"></script>
<script>
(function () {
    var body = document.body;
    var sidebar = document.getElementById('accordionSidebar');
    var sidebarToggle = document.getElementById('sidebarToggle');
    var sidebarToggleTop = document.getElementById('sidebarToggleTop');
    var scrollTopButton = document.querySelector('.scroll-to-top');

    function toggleSidebar() {
        if (!sidebar) {
            return;
        }

        sidebar.classList.toggle('toggled');
        body.classList.toggle('sidebar-toggled');
    }

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', toggleSidebar);
    }

    if (sidebarToggleTop) {
        sidebarToggleTop.addEventListener('click', toggleSidebar);
    }

    if (sidebar) {
        sidebar.querySelectorAll('.hdd-nav-link').forEach(function (link) {
            link.addEventListener('click', function () {
                if (window.innerWidth <= 767.98) {
                    sidebar.classList.remove('toggled');
                    body.classList.remove('sidebar-toggled');
                }
            });
        });
    }

    if (scrollTopButton) {
        window.addEventListener('scroll', function () {
            if (window.pageYOffset > 120) {
                scrollTopButton.style.display = 'inline-flex';
            } else {
                scrollTopButton.style.display = 'none';
            }
        });
    }
})();
</script>
</body>
</html>
