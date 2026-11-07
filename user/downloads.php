<?php
// user/downloads.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$isAdmin = is_admin();
$gender  = gender_filter();

$activeSid = active_semester_id();
$activeSem = active_semester();

// ---------- counts ----------
function safe_count(PDO $pdo, string $sql, array $p = []): int {
    try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

$gS = $gender ? " AND gender = ?" : "";
$gP = $gender ? [$gender] : [];

$countStudents = safe_count($pdo, "SELECT COUNT(*) FROM students WHERE is_active=1 $gS", $gP);
$countRooms    = safe_count($pdo, "SELECT COUNT(*) FROM rooms WHERE 1=1" . ($gender ? " AND gender=?" : ""), $gP);
$countBlocks   = safe_count($pdo, "SELECT COUNT(*) FROM blocks WHERE is_active=1" . ($gender ? " AND gender=?" : ""), $gP);

$placedExpr = $activeSid
    ? "EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.status='confirmed' AND b.semester_id = " . (int)$activeSid . ")"
    : "s.placed = 1";
$gStud = $gender ? " AND s.gender = ?" : "";
$pStud = $gender ? [$gender] : [];

$countConfirmed = safe_count($pdo,
    "SELECT COUNT(*) FROM students s WHERE s.is_active=1 AND $placedExpr $gStud", $pStud);
$countUnplaced  = safe_count($pdo,
    "SELECT COUNT(*) FROM students s WHERE s.is_active=1 AND NOT ($placedExpr) $gStud", $pStud);

$countStaff = $isAdmin ? safe_count($pdo, "SELECT COUNT(*) FROM users") : 0;

$rq = $pdo->prepare("SELECT COUNT(*) total,
    SUM(CASE WHEN status='occupied' THEN 1 ELSE 0 END) occupied
    FROM rooms WHERE 1=1" . ($gender ? " AND gender=?" : ""));
$rq->execute($gP);
$rs = $rq->fetch() ?: ['total'=>0,'occupied'=>0];
$occupancy_rate = (int)$rs['total'] > 0 ? round(($rs['occupied'] / $rs['total']) * 100, 1) : 0;

// ---------- card renderer ----------
function download_card(
    string $title, string $subtitle, string $icon, string $iconClass,
    string $type, string $badge, int $count = 0,
    bool $csv = true, bool $pdf = true
): void {
    $base   = APP_BASE;
    $empty  = $count <= 0;
?>
    <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex flex-column">
                <div class="d-flex justify-content-between mb-2">
                    <div class="stat-icon <?= $iconClass ?>">
                        <i class="fas <?= $icon ?>"></i>
                    </div>
                    <span class="badge bg-light text-dark align-self-start"><?= h($badge) ?></span>
                </div>
                <h6 class="fw-bold mt-2 mb-1"><?= h($title) ?></h6>
                <p class="text-muted small flex-grow-1 mb-3"><?= h($subtitle) ?></p>
                <div class="d-flex gap-2">
                    <?php if ($csv): ?>
                        <a href="<?= $empty ? 'javascript:void(0)' : h($base . '/api/generate_report.php?type=' . $type . '&format=csv') ?>"
                           class="btn btn-outline-primary btn-sm flex-fill download-btn <?= $empty ? 'disabled' : '' ?>"
                           data-fmt="csv"
                           <?= $empty ? 'title="No data to export" aria-disabled="true"' : '' ?>>
                            <span class="btn-label"><i class="fas fa-file-csv me-1"></i>CSV</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                        </a>
                    <?php endif; ?>
                    <?php if ($pdf): ?>
                        <a href="<?= $empty ? 'javascript:void(0)' : h($base . '/api/generate_report.php?type=' . $type . '&format=pdf') ?>"
                           target="<?= $empty ? '' : '_blank' ?>"
                           class="btn btn-outline-danger btn-sm flex-fill download-btn <?= $empty ? 'disabled' : '' ?>"
                           data-fmt="pdf"
                           <?= $empty ? 'title="No data to export" aria-disabled="true"' : '' ?>>
                            <span class="btn-label"><i class="fas fa-file-pdf me-1"></i>PDF</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-download text-primary me-2"></i>Downloads</h4>
                    <p class="text-muted small mb-0">
                        <?= $gender ? h($gender).' wing only' : 'All wings (admin)' ?>
                        <?php if ($activeSem): ?>
                            · <strong><?= h($activeSem['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="badge bg-info">
                    <i class="fas fa-shield-alt me-1"></i>
                    <?= $isAdmin ? 'Full system access' : 'Scoped to your wing' ?>
                </span>
            </div>

            <!-- Info banner -->
            <div class="alert alert-info d-flex align-items-start mb-3">
                <i class="fas fa-info-circle me-2 mt-1"></i>
                <div class="small">
                    <strong>Scope:</strong>
                    <?= $isAdmin ? 'Admin downloads every wing and every report.' : 'Deans and Secretaries only see their own wing.' ?>
                    &nbsp;·&nbsp;
                    <strong>CSV</strong> — Excel-ready raw data.
                    <strong>PDF</strong> — UEAB-branded, printable report.
                    <?php if ($activeSid): ?>
                        &nbsp;·&nbsp; Statistics reflect the <strong>active semester</strong>.
                    <?php endif; ?>
                </div>
            </div>

            <!-- Stats strip -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-2" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-primary"><?= number_format($countStudents) ?></div>
                        <div class="small text-muted text-uppercase" style="font-size:.7rem;">Students</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-2" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-success"><?= number_format($countRooms) ?></div>
                        <div class="small text-muted text-uppercase" style="font-size:.7rem;">Rooms</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-2" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-info"><?= number_format($countBlocks) ?></div>
                        <div class="small text-muted text-uppercase" style="font-size:.7rem;">Buildings</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-2" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-warning"><?= number_format($countConfirmed) ?></div>
                        <div class="small text-muted text-uppercase" style="font-size:.7rem;">Placed</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-2" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-danger"><?= number_format($countUnplaced) ?></div>
                        <div class="small text-muted text-uppercase" style="font-size:.7rem;">Unplaced</div>
                    </div></div>
                </div>
            </div>

            <!-- ============ STUDENTS & PLACEMENT ============ -->
            <h6 class="fw-bold text-uppercase text-muted small mb-2 mt-3">
                <i class="fas fa-user-graduate me-1"></i>Students &amp; Placement
            </h6>
            <div class="row g-3 mb-2">
                <?php download_card(
                    'Students Roster',
                    'All students with reg #, email, gender, year, current room, and placement status.',
                    'fa-users', 'bg-primary-subtle text-primary',
                    'students', number_format($countStudents).' records', $countStudents
                ); ?>

                <?php download_card(
                    'Unplaced Students',
                    'Students with no confirmed placement this semester — for follow-up.',
                    'fa-user-slash', 'bg-danger-subtle text-danger',
                    'unplaced', number_format($countUnplaced).' unplaced', $countUnplaced
                ); ?>

                <?php download_card(
                    'Confirmed Bookings',
                    'Every confirmed booking for the semester — student, room, approver, penalty.',
                    'fa-check-circle', 'bg-warning-subtle text-warning',
                    'bookings', number_format($countConfirmed).' confirmed', $countConfirmed
                ); ?>
            </div>

            <!-- ============ ROOMS & BUILDINGS ============ -->
            <h6 class="fw-bold text-uppercase text-muted small mb-2 mt-3">
                <i class="fas fa-building me-1"></i>Rooms &amp; Buildings
            </h6>
            <div class="row g-3 mb-2">
                <?php download_card(
                    'Rooms Inventory',
                    'All rooms with building, gender, capacity, and current status.',
                    'fa-bed', 'bg-success-subtle text-success',
                    'rooms', number_format($countRooms).' rooms', $countRooms
                ); ?>

                <?php download_card(
                    'Buildings',
                    'All hostel blocks with gender, room counts, and occupancy breakdown.',
                    'fa-building', 'bg-info-subtle text-info',
                    'blocks', number_format($countBlocks).' blocks', $countBlocks
                ); ?>

                <?php download_card(
                    'Occupancy Report',
                    'Per-building room counts, occupied/vacant/released, and occupancy percentages.',
                    'fa-chart-pie', 'bg-success-subtle text-success',
                    'occupancy', $occupancy_rate.'% occupied', $countRooms
                ); ?>
            </div>

            <!-- ============ ADMIN ONLY ============ -->
            <?php if ($isAdmin): ?>
            <h6 class="fw-bold text-uppercase text-muted small mb-2 mt-3">
                <i class="fas fa-lock me-1"></i>Administration (Admin Only)
            </h6>
            <div class="row g-3 mb-2">
                <?php download_card(
                    'Staff Directory',
                    'All staff accounts with role, email, wing scope, and account status.',
                    'fa-users-cog', 'bg-dark text-white',
                    'staff', number_format($countStaff).' staff', $countStaff
                ); ?>
            </div>
            <?php endif; ?>

            <!-- Footer strip -->
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-body d-flex flex-wrap align-items-center gap-3 small text-muted">
                    <div><i class="fas fa-file-csv text-primary me-1"></i><strong>CSV</strong> — raw data, Excel-ready</div>
                    <div><i class="fas fa-file-pdf text-danger me-1"></i><strong>PDF</strong> — branded, printable, official</div>
                    <div class="ms-auto"><i class="fas fa-clock me-1"></i>All exports logged to audit trail</div>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// ============================================================
// Download button spinner
//   CSV → server sends Content-Disposition: attachment
//   PDF → opens in new tab
//   Both: spin for 1.2s then reset (JS can't detect real completion)
// ============================================================
document.querySelectorAll('.download-btn').forEach(btn => {
    if (btn.classList.contains('disabled')) return;

    btn.addEventListener('click', function (e) {
        if (this.classList.contains('disabled')) { e.preventDefault(); return; }

        const label = this.querySelector('.btn-label');
        const spin  = this.querySelector('.btn-spin');
        const fmt   = this.dataset.fmt;

        if (label) label.classList.add('d-none');
        if (spin)  spin.classList.remove('d-none');
        this.style.pointerEvents = 'none';

        // Reset after a short delay — download triggers asynchronously
        const reset = () => {
            if (label) label.classList.remove('d-none');
            if (spin)  spin.classList.add('d-none');
            this.style.pointerEvents = '';
        };
        setTimeout(reset, fmt === 'pdf' ? 1800 : 1200);
    });
});

// ============================================================
// Reset spinners on bfcache restore (back button)
// ============================================================
window.addEventListener('pageshow', () => {
    document.querySelectorAll('.download-btn').forEach(btn => {
        const label = btn.querySelector('.btn-label');
        const spin  = btn.querySelector('.btn-spin');
        if (label) label.classList.remove('d-none');
        if (spin)  spin.classList.add('d-none');
        btn.style.pointerEvents = '';
    });
});
</script>