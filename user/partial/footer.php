<?php
// user/partial/footer.php
$root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($root === '' || $root === '\\') $root = '';
?>
        </main>
        <footer class="py-4 bg-light mt-auto mb-5 mb-lg-0">
            <div class="container-fluid px-4">
                <div class="d-flex align-items-center justify-content-between small text-muted">
                    <div>&copy; <?= date('Y') ?> University of Eastern Africa, Baraton · ComfortBook</div>
                    <div><i class="fas fa-shield-alt me-1"></i>All actions are logged</div>
                </div>
            </div>
        </footer>
    </div><!-- /#layoutSidenav_content -->
</div><!-- /#layoutSidenav -->
<script>document.querySelectorAll('table.table').forEach(function (t) { if (t.closest('.table-responsive')) return; var w = document.createElement('div'); w.className = 'table-responsive'; t.parentNode.insertBefore(w, t); w.appendChild(t); });</script>

<?php require_once __DIR__ . '/../../includes/shell.php'; shell_bottom_tabs(shell_nav(), basename($_SERVER['PHP_SELF']), $root, $root . '/api/ai_chat.php'); shell_scripts($root); ?>
<script>
    // One handler only (two handlers toggled twice = no change). Tapping the dimmed page closes the menu on phones.
    document.getElementById('sidebarToggle')?.addEventListener('click', function (e) {
        e.preventDefault();
        document.body.classList.toggle('sb-sidenav-toggled');
    });
    document.getElementById('layoutSidenav_content')?.addEventListener('click', function (e) {
        if (window.innerWidth < 992 && document.body.classList.contains('sb-sidenav-toggled') && e.target === this) document.body.classList.remove('sb-sidenav-toggled');
    });

    // Reset any spinning buttons when the page is restored from bfcache
window.addEventListener('pageshow', function (event) {
    // Only reset when restored from cache (event.persisted = true)
    // OR just run unconditionally — harmless on fresh loads
    document.querySelectorAll('.nav-btn').forEach(el => {
        el.dataset.loading = '';
        el.style.pointerEvents = '';
        el.style.opacity = '';
        const label = el.querySelector('.btn-label');
        const spin  = el.querySelector('.btn-spin');
        if (label) label.classList.remove('d-none');
        if (spin)  spin.classList.add('d-none');
        if (el.tagName === 'BUTTON') el.disabled = false;
    });
});

// ============================================================
// Reset any spinners when back/forward restores the page from bfcache
// ============================================================
function resetNavButtons() {
    // .nav-btn spinners
    document.querySelectorAll('.nav-btn').forEach(el => {
        el.dataset.loading = '';
        el.style.pointerEvents = '';
        el.style.opacity = '';
        const label = el.querySelector('.btn-label');
        const spin  = el.querySelector('.btn-spin');
        if (label) label.classList.remove('d-none');
        if (spin)  spin.classList.add('d-none');
        if (el.tagName === 'BUTTON') el.disabled = false;
    });

    // sidebar link spinners
    document.querySelectorAll('.sb-sidenav-menu a.nav-link').forEach(a => {
        a.classList.remove('loading');
        const spinner = a.querySelector('.side-spin');
        if (spinner) spinner.remove();
    });

    // top loading bar
    document.querySelectorAll('.sb-loading-bar').forEach(el => el.remove());
}
window.addEventListener('pageshow', resetNavButtons);
document.addEventListener('DOMContentLoaded', resetNavButtons);

// ============================================================
// Sidebar link loading — show a thin animated strip above the
// sidebar and a small spinner on the clicked link
// ============================================================
function attachSidebarLoading() {
    const sidebar = document.getElementById('layoutSidenav_nav');
    if (!sidebar) return;

    sidebar.querySelectorAll('a.nav-link[href]').forEach(link => {
        // Skip collapse togglers (they have data-bs-toggle)
        if (link.dataset.bsToggle) return;
        // Skip already-hooked links
        if (link.dataset.sideLoader) return;
        link.dataset.sideLoader = '1';

        link.addEventListener('click', function (e) {
            const href = this.getAttribute('href');
            if (!href || href.startsWith('#') || href.startsWith('javascript')) return;

            // Add top strip
            const topStrip = document.createElement('div');
            topStrip.className = 'sb-loading-bar';
            topStrip.style.cssText = `
                position:fixed; top:0; left:0; right:0; height:3px;
                background:linear-gradient(90deg,#003366,#ffc107,#003366);
                background-size:200% 100%;
                animation: sbStripMove 1.1s linear infinite;
                z-index:1100;
                box-shadow:0 1px 4px rgba(0,51,102,.35);
            `;
            document.body.appendChild(topStrip);

            // Add spinner to this link
            this.classList.add('loading');
            if (!this.querySelector('.side-spin')) {
                const s = document.createElement('span');
                s.className = 'side-spin ms-auto';
                s.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:.75rem;height:.75rem;border-width:.15em;color:#ffc107;"></span>';
                this.appendChild(s);
            }
            this.style.pointerEvents = 'none';
            this.style.opacity = '0.85';
        });
    });
}

// Add keyframes once
(function injectKeyframes() {
    if (document.getElementById('sbLoaderStyles')) return;
    const style = document.createElement('style');
    style.id = 'sbLoaderStyles';
    style.textContent = `
        @keyframes sbStripMove {
            0%   { background-position: 0% 0; }
            100% { background-position: -200% 0; }
        }
        .sb-sidenav-menu a.nav-link.loading .side-spin {
            display: inline-block;
            animation: none;
        }
    `;
    document.head.appendChild(style);
})();

document.addEventListener('DOMContentLoaded', attachSidebarLoading);

</script>
</body>
</html>