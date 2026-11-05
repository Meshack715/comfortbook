<?php
// user/downloads.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$isAdmin = is_admin();
$gender  = gender_filter();

// ---------- counts for badges ----------
function safe_count(PDO $pdo, string $sql, array $p = []): int {
    try { $s = $pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

$gS = $gender ? " AND gender = ?" : "";
$gP = $gender ? [$gender] : [];

$countStudents  = safe_count($pdo, "SELECT COUNT(*) FROM students WHERE is_active=1 $gS", $gP);
$countRooms     = safe_count($pdo, "SELECT COUNT(*) FROM rooms WHERE 1=1" . ($gender ? " AND gender=?" : ""), $gP);
$countBlocks    = safe_count($pdo, "SELECT COUNT(*) FROM blocks WHERE is_active=1" . ($gender ? " AND gender=?" : ""), $gP);
$countConfirmed = safe_count($pdo,
    "SELECT COUNT(*) FROM bookings b JOIN students s ON s.id=b.student_id WHERE b.status='confirmed'" .
    ($gender ? " AND s.gender=?" : ""), $gP);
$countUnplaced  = safe_count($pdo,
    "SELECT COUNT(*) FROM students WHERE is_active=1 AND placed=0 $gS", $gP);
$countStaff     = $isAdmin ? safe_count($pdo, "SELECT COUNT(*) FROM users") : 0;

// Occupancy %
$rooms = $pdo->prepare("SELECT COUNT(*) total,
    SUM(CASE WHEN status='occupied' THEN 1 ELSE 0 END) occupied
    FROM rooms WHERE 1=1" . ($gender ? " AND gender=?" : ""));
$rooms->execute($gP);
$rs = $rooms->fetch() ?: ['total'=>0,'occupied'=>0];
$occupancy_rate = (int)$rs['total'] > 0 ? round(($rs['occupied'] / $rs['total']) * 100, 1) : 0;

// ---------- helper to render a card ----------
function download_card(string $title, string $subtitle, string $icon, string $iconClass,
                       string $type, string $badge, bool $csv = true, bool $pdf = true): void {
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
                        <a href="api/generate_report.php?type=<?= h($type) ?>&format=csv"
                           class="btn btn-outline-primary btn-sm flex-fill">
                            <i class="fas fa-file-csv me-1"></i>CSV
                        </a>
                    <?php endif; ?>
                    <?php if ($pdf): ?>
                        <a href="api/generate_report.php?type=<?= h($type) ?>&format=pdf"
                           target="_blank" class="btn btn-outline-danger btn-sm flex-fill">
                            <i class="fas fa-file-pdf me-1"></i>PDF
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
                        · CSV exports or branded PDF reports
                    </p>
                </div>
                <span class="badge bg-info">
                    <i class="fas fa-shield-alt me-1"></i>
                    Scoped to your role
                </span>
            </div>

            <!-- Info banner -->
            <div class="alert alert-info d-flex align-items-start mb-3">
                <i class="fas fa-info-circle me-2 mt-1"></i>
                <div class="small">
                    <strong>Scope:</strong>
                    Deans and Secretaries download only their wing.
                    Admin downloads everything.
                    <strong>CSV</strong> opens in Excel/Sheets.
                    <strong>PDF</strong> is UEAB-branded, ready to print.
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
                    'students', number_format($countStudents).' records'
                ); ?>

                <?php download_card(
                    'Unplaced Students',
                    'Students with no confirmed placement — for follow-up and manual assignment.',
                    'fa-user-slash', 'bg-danger-subtle text-danger',
                    'unplaced', number_format($countUnplaced).' unplaced'
                ); ?>

                <?php download_card(
                    'Confirmed Bookings',
                    'Every confirmed booking with student, room, approver, and penalty.',
                    'fa-check-circle', 'bg-warning-subtle text-warning',
                    'bookings', number_format($countConfirmed).' confirmed'
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
                    'rooms', number_format($countRooms).' rooms'
                ); ?>

                <?php download_card(
                    'Buildings',
                    'All hostel blocks with gender, room counts, and occupancy breakdown.',
                    'fa-building', 'bg-info-subtle text-info',
                    'blocks', number_format($countBlocks).' blocks'
                ); ?>

                <?php download_card(
                    'Occupancy Report',
                    'Per-building room counts, occupied/vacant/released, and occupancy percentages.',
                    'fa-chart-pie', 'bg-success-subtle text-success',
                    'occupancy', $occupancy_rate.'% occupied'
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
                    'staff', number_format($countStaff).' staff'
                ); ?>
            </div>
            <?php endif; ?>

            <!-- ============ FOOTNOTE ============ -->
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