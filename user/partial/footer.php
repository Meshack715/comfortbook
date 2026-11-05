<?php
// user/partial/footer.php
$root = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
if ($root === '' || $root === '\\') $root = '';
?>
        </main>
        <footer class="py-4 bg-light mt-auto">
            <div class="container-fluid px-4">
                <div class="d-flex align-items-center justify-content-between small text-muted">
                    <div>&copy; <?= date('Y') ?> University of Eastern Africa, Baraton · ComfortBook</div>
                    <div><i class="fas fa-shield-alt me-1"></i>All actions are logged</div>
                </div>
            </div>
        </footer>
    </div><!-- /#layoutSidenav_content -->
</div><!-- /#layoutSidenav -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $root ?>../../assets/js/scripts.js"></script>
<script>
    document.getElementById('sidebarToggle')?.addEventListener('click', function (e) {
        e.preventDefault();
        document.body.classList.toggle('sb-sidenav-toggled');
    });
</script>
</body>
</html>