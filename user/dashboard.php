<?php
// user/dashboard.php — role-aware
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$role      = user_role();
$gender    = gender_filter();     // null = admin (both wings)
$isAdmin   = is_admin();
$isDean    = is_dean();
$isSec     = is_secretary();

$activeSem = active_semester();
$activeSid = $activeSem ? (int)$activeSem['id'] : 0;

// ------------------------------------------------------------
// Helper: gender WHERE fragment
// ------------------------------------------------------------
function gender_where(?string $gender, string $col = 'gender', string $prefix = ' AND '): array {
    if ($gender === null) return ['', []];
    return ["{$prefix}{$col} = ?", [$gender]];
}

// ------------------------------------------------------------
// STUDENTS
// ------------------------------------------------------------
[$g, $p] = gender_where($gender);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active = 1 $g");
$stmt->execute($p);
$total_students = (int)$stmt->fetchColumn();

// Placed = confirmed booking in active semester
$placedExpr = $activeSid
    ? "EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.status='confirmed' AND b.semester_id = $activeSid)"
    : "s.placed = 1";

$gS = $gender ? ' AND s.gender = ?' : '';
$pS = $gender ? [$gender] : [];

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.is_active=1 AND $placedExpr $gS");
$stmt->execute($pS);
$placed_students = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students s WHERE s.is_active=1 AND NOT ($placedExpr) $gS");
$stmt->execute($pS);
$unplaced = (int)$stmt->fetchColumn();

// ------------------------------------------------------------
// BLOCKS
// ------------------------------------------------------------
[$g, $p] = gender_where($gender);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM blocks WHERE is_active = 1 $g");
$stmt->execute($p);
$total_blocks = (int)$stmt->fetchColumn();

// ------------------------------------------------------------
// ROOMS
// ------------------------------------------------------------
[$g, $p] = gender_where($gender);
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status='occupied'      THEN 1 ELSE 0 END) AS occupied,
        SUM(CASE WHEN status='vacant'        THEN 1 ELSE 0 END) AS vacant,
        SUM(CASE WHEN status='available_p2'  THEN 1 ELSE 0 END) AS released,
        SUM(CASE WHEN status='reserved'      THEN 1 ELSE 0 END) AS reserved
    FROM rooms
    WHERE 1=1 $g
");
$stmt->execute($p);
$rs = $stmt->fetch() ?: [];

$total_rooms    = (int)($rs['total']    ?? 0);
$occupied_rooms = (int)($rs['occupied'] ?? 0);
$vacant_rooms   = (int)($rs['vacant']   ?? 0);
$released_rooms = (int)($rs['released'] ?? 0);
$reserved_rooms = (int)($rs['reserved'] ?? 0);
$occupancy_rate = $total_rooms > 0 ? round(($occupied_rooms / $total_rooms) * 100, 1) : 0;

// ------------------------------------------------------------
// BOOKINGS
// ------------------------------------------------------------
[$g, $p] = gender_where($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM bookings b
    JOIN students s ON s.id = b.student_id
    WHERE b.status = 'pending_approval' $g
");
$stmt->execute($p);
$pending_approvals = (int)$stmt->fetchColumn();

[$g, $p] = gender_where($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM bookings b
    JOIN students s ON s.id = b.student_id
    WHERE b.status = 'confirmed' $g
");
$stmt->execute($p);
$confirmed_bookings = (int)$stmt->fetchColumn();

// Penalty revenue
[$g, $p] = gender_where($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(b.penalty_amount),0) FROM bookings b
    JOIN students s ON s.id = b.student_id
    WHERE b.status = 'confirmed' AND b.penalty_amount > 0 $g
");
$stmt->execute($p);
$penalty_revenue = (int)$stmt->fetchColumn();

// ------------------------------------------------------------
// PHASE (per-wing for dean/sec, strongest across both for admin)
// ------------------------------------------------------------
$phase = current_phase($gender);
$phaseLabel = match($phase) {
    1 => 'Phase 1 — Keep or Release',
    2 => 'Phase 2 — Move Window',
    default => 'Closed (between phases)',
};
$phaseColor = match($phase) {
    1 => 'primary', 2 => 'success', default => 'secondary'
};

// Wing windows (for the banner)
$myWindow = null;
if ($activeSid && $gender) {
    $myWindow = semester_window($activeSid, $gender);
}

// ------------------------------------------------------------
// RECENT BOOKINGS (last 6)
// ------------------------------------------------------------
[$g, $p] = gender_where($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT b.id, b.status, b.phase, b.action, b.created_at, b.penalty_amount,
           s.full_name AS student_name, s.reg_number,
           r.room_number, bl.name AS block_name
    FROM bookings b
    JOIN students s ON s.id = b.student_id
    JOIN rooms r    ON r.id = b.room_id
    JOIN blocks bl  ON bl.id = r.block_id
    WHERE 1=1 $g
    ORDER BY b.created_at DESC
    LIMIT 6
");
$stmt->execute($p);
$recent = $stmt->fetchAll();

// ------------------------------------------------------------
// ADMIN: recent audit
// ------------------------------------------------------------
$audit = [];
if ($isAdmin) {
    $audit = $pdo->query("SELECT * FROM audit_log ORDER BY created_at DESC LIMIT 6")->fetchAll();
}

// ------------------------------------------------------------
// ADMIN: per-wing windows summary for dashboard
// ------------------------------------------------------------
$adminWings = [];
if ($isAdmin && $activeSid) {
    foreach (['Male', 'Female'] as $g) {
        $w = semester_window($activeSid, $g);
        $adminWings[$g] = [
            'window' => $w,
            'phase'  => current_phase($g),
        ];
    }
}

// ------------------------------------------------------------
// Greeting + status badge helper
// ------------------------------------------------------------
$hour = (int)date('H');
$greet = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

function h_status(string $status): string {
    return match($status) {
        'pending_otp'      => '<span class="badge bg-secondary">OTP</span>',
        'pending_payment'  => '<span class="badge bg-warning text-dark">Payment</span>',
        'pending_approval' => '<span class="badge bg-info text-dark">Awaiting</span>',
        'confirmed'        => '<span class="badge bg-success">Confirmed</span>',
        'cancelled'        => '<span class="badge bg-danger">Cancelled</span>',
        default            => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
}

function phase_badge(?int $p): string {
    return match($p) {
        1 => '<span class="badge bg-primary"><i class="fas fa-circle me-1"></i>Phase 1 Live</span>',
        2 => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Phase 2 Live</span>',
        default => '<span class="badge bg-secondary"><i class="fas fa-circle me-1"></i>Closed</span>',
    };
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>

<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>

    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <!-- ============ HEADER ============ -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1">
                        <?= $greet ?>, <?= h($_SESSION['full_name'] ?? 'User') ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <i class="far fa-calendar-alt me-1"></i><?= date('l, F j, Y') ?>
                        <?php if ($gender): ?>
                            · <span class="badge bg-primary"><?= h($gender) ?> Wing</span>
                        <?php else: ?>
                            · <span class="badge bg-dark">Both Wings</span>
                        <?php endif; ?>
                        <?php if ($activeSem): ?>
                            · <span class="badge bg-info text-dark"><?= h($activeSem['name']) ?></span>
                        <?php else: ?>
                            · <span class="badge bg-warning text-dark">No active semester</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="d-flex gap-2 mt-2 mt-md-0">
                    <?php if ($isDean): ?>
                        <a href="phase_windows.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-clock me-1"></i>Phase Windows
                        </a>
                        <a href="send_links.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-paper-plane me-1"></i>Send Links
                        </a>
                    <?php endif; ?>
                    <?php if ($isDean || $isSec): ?>
                        <a href="approvals.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-check-circle me-1"></i>Approvals
                            <?php if ($pending_approvals > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $pending_approvals ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                    <?php if ($isAdmin): ?>
                        <a href="phase_control.php" class="btn btn-primary btn-sm">
                            <i class="fas fa-calendar-alt me-1"></i>Semester Control
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ PHASE BANNER ============ -->
            <div class="alert alert-<?= $phaseColor ?> d-flex align-items-center mb-3">
                <i class="fas fa-clock fa-2x me-3"></i>
                <div class="flex-grow-1">
                    <strong><?= h($phaseLabel) ?></strong>
                    <div class="small">
                        <?php if ($gender && $myWindow): ?>
                            <!-- Dean / Secretary — own wing's window -->
                            <?php if ($phase === 1): ?>
                                <strong>Phase 1:</strong>
                                <?= date('M j, H:i', strtotime($myWindow['phase1_start'])) ?>
                                → <?= date('M j, H:i', strtotime($myWindow['phase1_end'])) ?>
                                · students can keep or release
                            <?php elseif ($phase === 2): ?>
                                <strong>Phase 2:</strong>
                                <?= date('M j, H:i', strtotime($myWindow['phase2_start'])) ?>
                                → <?= date('M j, H:i', strtotime($myWindow['phase2_end'])) ?>
                                · released + vacant rooms open
                            <?php else: ?>
                                <?= $gender ?> wing phase window is closed. Deans set windows in <a href="phase_windows.php">Phase Windows</a>.
                            <?php endif; ?>
                        <?php elseif ($isAdmin): ?>
                            <!-- Admin — summary across wings -->
                            <?php if (!$activeSem): ?>
                                No active semester. <a href="phase_control.php" class="alert-link">Activate one</a>.
                            <?php else: ?>
                                Deans set their own wing windows — see below.
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($isAdmin): ?>
                    <a href="phase_control.php" class="btn btn-sm btn-<?= $phaseColor ?>">
                        <i class="fas fa-cog me-1"></i>Manage
                    </a>
                <?php elseif ($isDean): ?>
                    <a href="phase_windows.php" class="btn btn-sm btn-<?= $phaseColor ?>">
                        <i class="fas fa-cog me-1"></i>Set Windows
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($isAdmin && $activeSem): ?>
            <!-- ============ ADMIN: WING WINDOW SUMMARY ============ -->
            <div class="row g-3 mb-3">
                <?php foreach ([
                    ['gender'=>'Male',   'label'=>'Men’s Wing',   'icon'=>'fa-mars',  'color'=>'primary'],
                    ['gender'=>'Female', 'label'=>'Women’s Wing', 'icon'=>'fa-venus', 'color'=>'danger'],
                ] as $w):
                    $data = $adminWings[$w['gender']] ?? null;
                    $win  = $data['window'] ?? null;
                    $ph   = $data['phase']  ?? null;
                ?>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas <?= $w['icon'] ?> text-<?= $w['color'] ?> me-2"></i><?= h($w['label']) ?>
                            </h6>
                            <?= phase_badge($ph) ?>
                        </div>
                        <div class="card-body small">
                            <div class="row g-2">
                                <div class="col-6">
                                    <div class="text-muted">Phase 1</div>
                                    <?php if ($win && $win['phase1_start']): ?>
                                        <div><strong><?= date('M j, H:i', strtotime($win['phase1_start'])) ?></strong></div>
                                        <div>→ <?= date('M j, H:i', strtotime($win['phase1_end'])) ?></div>
                                    <?php else: ?>
                                        <div class="text-muted fst-italic">Not set</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted">Phase 2</div>
                                    <?php if ($win && $win['phase2_start']): ?>
                                        <div><strong><?= date('M j, H:i', strtotime($win['phase2_start'])) ?></strong></div>
                                        <div>→ <?= date('M j, H:i', strtotime($win['phase2_end'])) ?></div>
                                    <?php else: ?>
                                        <div class="text-muted fst-italic">Not set</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ============ PRIMARY STAT CARDS ============ -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small text-uppercase">Students</div>
                                    <div class="fs-3 fw-bold text-primary"><?= number_format($total_students) ?></div>
                                </div>
                                <div class="stat-icon bg-primary-subtle text-primary">
                                    <i class="fas fa-user-graduate"></i>
                                </div>
                            </div>
                            <div class="small text-muted"><?= $placed_students ?> placed · <?= $unplaced ?> unplaced</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small text-uppercase">Occupancy</div>
                                    <div class="fs-3 fw-bold text-success"><?= $occupancy_rate ?>%</div>
                                </div>
                                <div class="stat-icon bg-success-subtle text-success">
                                    <i class="fas fa-bed"></i>
                                </div>
                            </div>
                            <div class="small text-muted"><?= $occupied_rooms ?> occupied · <?= $vacant_rooms ?> vacant</div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small text-uppercase">Pending</div>
                                    <div class="fs-3 fw-bold text-warning"><?= number_format($pending_approvals) ?></div>
                                </div>
                                <div class="stat-icon bg-warning-subtle text-warning">
                                    <i class="fas fa-clock"></i>
                                </div>
                            </div>
                            <a href="approvals.php" class="small text-decoration-none">Review <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between">
                                <div>
                                    <div class="text-muted small text-uppercase">Unplaced</div>
                                    <div class="fs-3 fw-bold text-danger"><?= number_format($unplaced) ?></div>
                                </div>
                                <div class="stat-icon bg-danger-subtle text-danger">
                                    <i class="fas fa-user-slash"></i>
                                </div>
                            </div>
                            <a href="unplaced.php" class="small text-decoration-none">Resolve <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ SECONDARY STATS (Dean / Admin) ============ -->
            <?php if ($isDean || $isAdmin): ?>
            <div class="row g-3 mb-3">
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Confirmed</div>
                            <div class="fs-3 fw-bold text-success"><?= number_format($confirmed_bookings) ?></div>
                            <div class="small text-muted">For this semester</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Released</div>
                            <div class="fs-3 fw-bold text-info"><?= number_format($released_rooms) ?></div>
                            <div class="small text-muted">Available for moves</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Reserved</div>
                            <div class="fs-3 fw-bold text-primary"><?= number_format($reserved_rooms) ?></div>
                            <div class="small text-muted">Awaiting approval</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="text-muted small text-uppercase">Penalty Revenue</div>
                            <div class="fs-3 fw-bold text-warning">KES <?= number_format($penalty_revenue) ?></div>
                            <div class="small text-muted">From late bookers</div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============ RECENT BOOKINGS + QUICK ACTIONS ============ -->
            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-history text-primary me-2"></i>Recent Bookings
                            </h6>
                            <a href="approvals.php" class="small text-decoration-none">View all</a>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($recent)): ?>
                                <div class="text-center py-5 text-muted">
                                    <i class="fas fa-inbox fa-2x mb-2 opacity-50"></i>
                                    <p class="small mb-0">No bookings yet.</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0 align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Student</th>
                                                <th>Room</th>
                                                <th>Phase</th>
                                                <th>Status</th>
                                                <th>When</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($recent as $r): ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold small"><?= h($r['student_name']) ?></div>
                                                    <div class="text-muted" style="font-size:.72rem;"><?= h($r['reg_number']) ?></div>
                                                </td>
                                                <td>
                                                    <div class="small"><?= h($r['room_number']) ?></div>
                                                    <div class="text-muted" style="font-size:.72rem;"><?= h($r['block_name']) ?></div>
                                                </td>
                                                <td><span class="badge bg-light text-dark">P<?= (int)$r['phase'] ?></span></td>
                                                <td><?= h_status($r['status']) ?></td>
                                                <td class="small text-muted"><?= date('M j, H:i', strtotime($r['created_at'])) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-bolt text-warning me-2"></i>Quick Actions
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <?php if ($isAdmin): ?>
                                    <a href="student_form.php" class="btn btn-outline-primary btn-sm text-start">
                                        <i class="fas fa-user-plus me-2"></i>Add Student
                                    </a>
                                    <a href="building_form.php" class="btn btn-outline-primary btn-sm text-start">
                                        <i class="fas fa-building me-2"></i>Add Building
                                    </a>
                                    <a href="room_form.php" class="btn btn-outline-primary btn-sm text-start">
                                        <i class="fas fa-bed me-2"></i>Add Room
                                    </a>
                                    <a href="staff_form.php" class="btn btn-outline-primary btn-sm text-start">
                                        <i class="fas fa-user-tie me-2"></i>Add Staff
                                    </a>
                                    <a href="phase_control.php" class="btn btn-outline-warning btn-sm text-start">
                                        <i class="fas fa-calendar-alt me-2"></i>Semester Control
                                    </a>
                                <?php elseif ($isDean): ?>
                                    <a href="phase_windows.php" class="btn btn-outline-primary btn-sm text-start">
                                        <i class="fas fa-clock me-2"></i>Set Phase Windows
                                    </a>
                                    <a href="send_links.php" class="btn btn-primary btn-sm text-start">
                                        <i class="fas fa-paper-plane me-2"></i>Send Magic Links
                                    </a>
                                    <a href="approvals.php" class="btn btn-outline-primary btn-sm text-start">
                                        <i class="fas fa-check-circle me-2"></i>Review Approvals
                                    </a>
                                    <a href="room_assignment.php" class="btn btn-outline-info btn-sm text-start">
                                        <i class="fas fa-random me-2"></i>Room Assignment
                                    </a>
                                    <a href="unplaced.php" class="btn btn-outline-warning btn-sm text-start">
                                        <i class="fas fa-user-slash me-2"></i>Unplaced Students
                                    </a>
                                    <a href="reports.php" class="btn btn-outline-secondary btn-sm text-start">
                                        <i class="fas fa-chart-bar me-2"></i>View Reports
                                    </a>
                                <?php else: ?>
                                    <a href="approvals.php" class="btn btn-primary btn-sm text-start">
                                        <i class="fas fa-check-circle me-2"></i>Review Approvals
                                    </a>
                                    <a href="room_assignment.php" class="btn btn-outline-info btn-sm text-start">
                                        <i class="fas fa-random me-2"></i>Room Assignment
                                    </a>
                                    <a href="unplaced.php" class="btn btn-outline-warning btn-sm text-start">
                                        <i class="fas fa-user-slash me-2"></i>Unplaced Students
                                    </a>
                                    <a href="students.php" class="btn btn-outline-secondary btn-sm text-start">
                                        <i class="fas fa-user-graduate me-2"></i>Browse Students
                                    </a>
                                <?php endif; ?>
                                <a href="downloads.php" class="btn btn-outline-secondary btn-sm text-start">
                                    <i class="fas fa-download me-2"></i>Downloads
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ ADMIN: RECENT ACTIVITY ============ -->
            <?php if ($isAdmin && !empty($audit)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-shield-alt text-primary me-2"></i>Recent Activity
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light">
                                <tr><th>Action</th><th>Details</th><th>When</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($audit as $a): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark"><?= h($a['action']) ?></span></td>
                                    <td class="small text-muted"><?= h(mb_substr($a['details'] ?? '', 0, 80)) ?></td>
                                    <td class="small text-muted"><?= date('M j, H:i', strtotime($a['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="pb-4"></div>

        </main>

<?php include __DIR__ . '/partial/footer.php'; ?>