<?php
// user/reports.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['dean_men','dean_women']);

$gender = gender_filter();

function gw(?string $g, string $col='gender', string $pre=' AND '): array {
    return $g === null ? ['', []] : ["{$pre}{$col} = ?", [$g]];
}

// ---------- KPI counts ----------
[$g,$p] = gw($gender);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active=1 $g");
$stmt->execute($p); $totalStudents = (int)$stmt->fetchColumn();

[$g,$p] = gw($gender);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active=1 AND placed=1 $g");
$stmt->execute($p); $placedStudents = (int)$stmt->fetchColumn();

[$g,$p] = gw($gender);
$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE is_active=1 AND placed=0 $g");
$stmt->execute($p); $unplacedStudents = (int)$stmt->fetchColumn();

[$g,$p] = gw($gender);
$stmt = $pdo->prepare("
    SELECT COUNT(*) total,
        SUM(CASE WHEN status='occupied'     THEN 1 ELSE 0 END) occupied,
        SUM(CASE WHEN status='vacant'       THEN 1 ELSE 0 END) vacant,
        SUM(CASE WHEN status='available_p2' THEN 1 ELSE 0 END) released,
        SUM(CASE WHEN status='reserved'     THEN 1 ELSE 0 END) reserved
    FROM rooms WHERE 1=1 $g
");
$stmt->execute($p); $rooms = $stmt->fetch() ?: [];
$totalRooms    = (int)($rooms['total']    ?? 0);
$occupiedRooms = (int)($rooms['occupied'] ?? 0);
$vacantRooms   = (int)($rooms['vacant']   ?? 0);
$releasedRooms = (int)($rooms['released'] ?? 0);
$reservedRooms = (int)($rooms['reserved'] ?? 0);
$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

// Phase 1 actions
[$g,$p] = gw($gender);
$stmt = $pdo->prepare("
    SELECT phase1_action, COUNT(*) c FROM students
    WHERE is_active=1 $g GROUP BY phase1_action
");
$stmt->execute($p);
$p1 = ['none'=>0,'keep'=>0,'release'=>0];
foreach ($stmt as $row) $p1[$row['phase1_action']] = (int)$row['c'];

// Bookings by status
[$g,$p] = gw($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT b.status, COUNT(*) c
    FROM bookings b JOIN students s ON s.id = b.student_id
    WHERE 1=1 $g GROUP BY b.status
");
$stmt->execute($p);
$bByStatus = ['pending_approval'=>0,'confirmed'=>0,'cancelled'=>0];
foreach ($stmt as $row) $bByStatus[$row['status']] = (int)$row['c'];

// Penalty revenue
[$g,$p] = gw($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(b.penalty_amount),0)
    FROM bookings b JOIN students s ON s.id = b.student_id
    WHERE b.status='confirmed' $g
");
$stmt->execute($p); $penaltyRevenue = (int)$stmt->fetchColumn();

[$g,$p] = gw($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM bookings b JOIN students s ON s.id = b.student_id
    WHERE b.penalty_amount > 0 $g
");
$stmt->execute($p); $penaltyCount = (int)$stmt->fetchColumn();

// Occupancy per block
[$g,$p] = gw($gender, 'b.gender');
$stmt = $pdo->prepare("
    SELECT b.id, b.name, b.gender,
        COUNT(r.id) total_rooms,
        SUM(CASE WHEN r.status='occupied' THEN 1 ELSE 0 END) occupied,
        SUM(CASE WHEN r.status='vacant'   THEN 1 ELSE 0 END) vacant,
        SUM(CASE WHEN r.status='available_p2' THEN 1 ELSE 0 END) released,
        SUM(CASE WHEN r.status='reserved' THEN 1 ELSE 0 END) reserved
    FROM blocks b LEFT JOIN rooms r ON r.block_id = b.id
    WHERE b.is_active=1 $g
    GROUP BY b.id, b.name, b.gender
    ORDER BY b.name
");
$stmt->execute($p);
$perBlock = $stmt->fetchAll();

// Recent confirmed
[$g,$p] = gw($gender, 's.gender');
$stmt = $pdo->prepare("
    SELECT b.id, b.approved_at, s.full_name, s.reg_number,
           r.room_number, bl.name block_name, b.penalty_amount, u.full_name approver
    FROM bookings b
    JOIN students s ON s.id = b.student_id
    JOIN rooms r ON r.id = b.room_id
    JOIN blocks bl ON bl.id = r.block_id
    LEFT JOIN users u ON u.id = b.approved_by
    WHERE b.status='confirmed' $g
    ORDER BY b.approved_at DESC LIMIT 10
");
$stmt->execute($p); $recentConfirmed = $stmt->fetchAll();
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-chart-bar text-primary me-2"></i>Reports</h4>
                    <p class="text-muted small mb-0"><?= h($gender) ?> wing · <?= date('l, F j, Y') ?></p>
                </div>
                <a href="downloads.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-download me-1"></i>Download CSV
                </a>
            </div>

            <!-- Primary KPIs -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase">Students</div>
                        <div class="fs-3 fw-bold text-primary"><?= number_format($totalStudents) ?></div>
                        <div class="small text-success"><?= $placedStudents ?> placed</div>
                        <div class="small text-danger"><?= $unplacedStudents ?> unplaced</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase">Occupancy</div>
                        <div class="fs-3 fw-bold text-success"><?= $occupancyRate ?>%</div>
                        <div class="small text-muted"><?= $occupiedRooms ?>/<?= $totalRooms ?> rooms</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase">Confirmed</div>
                        <div class="fs-3 fw-bold text-info"><?= number_format($bByStatus['confirmed']) ?></div>
                        <div class="small text-warning"><?= $bByStatus['pending_approval'] ?> awaiting</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body">
                        <div class="text-muted small text-uppercase">Penalty</div>
                        <div class="fs-3 fw-bold text-warning">KES <?= number_format($penaltyRevenue) ?></div>
                        <div class="small text-muted"><?= $penaltyCount ?> late booker<?= $penaltyCount===1?'':'s' ?></div>
                    </div></div>
                </div>
            </div>

            <!-- Phase 1 actions + Room states -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-exchange-alt text-primary me-2"></i>Phase 1 Actions</h6></div>
                        <div class="card-body">
                            <div class="row g-2 text-center">
                                <div class="col-4"><div class="p-3 bg-success-subtle rounded">
                                    <div class="fs-3 fw-bold text-success"><?= $p1['keep'] ?></div>
                                    <div class="small">Kept</div>
                                </div></div>
                                <div class="col-4"><div class="p-3 bg-info-subtle rounded">
                                    <div class="fs-3 fw-bold text-info"><?= $p1['release'] ?></div>
                                    <div class="small">Released</div>
                                </div></div>
                                <div class="col-4"><div class="p-3 bg-light rounded">
                                    <div class="fs-3 fw-bold"><?= $p1['none'] ?></div>
                                    <div class="small">No Action</div>
                                </div></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-bed text-primary me-2"></i>Room States</h6></div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-6"><div class="d-flex justify-content-between small"><span>Occupied</span><strong class="text-primary"><?= $occupiedRooms ?></strong></div></div>
                                <div class="col-6"><div class="d-flex justify-content-between small"><span>Vacant</span><strong class="text-success"><?= $vacantRooms ?></strong></div></div>
                                <div class="col-6"><div class="d-flex justify-content-between small"><span>Released (P2)</span><strong class="text-info"><?= $releasedRooms ?></strong></div></div>
                                <div class="col-6"><div class="d-flex justify-content-between small"><span>Reserved</span><strong class="text-warning"><?= $reservedRooms ?></strong></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Per block occupancy -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-building text-primary me-2"></i>Occupancy by Building</h6></div>
                <div class="card-body p-0">
                    <?php if (empty($perBlock)): ?>
                        <div class="text-center py-4 text-muted small">No buildings in this wing.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr><th>Building</th><th class="text-center">Rooms</th><th class="text-center">Occupied</th><th class="text-center">Free</th><th style="width:35%;">Occupancy</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($perBlock as $b):
                                    $occ = (int)$b['occupied'];
                                    $tot = (int)$b['total_rooms'];
                                    $free = (int)$b['vacant'] + (int)$b['released'];
                                    $pct = $tot > 0 ? round(($occ / $tot) * 100, 1) : 0;
                                    $color = $pct >= 80 ? 'danger' : ($pct >= 50 ? 'warning' : 'success');
                                ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h($b['name']) ?></td>
                                        <td class="text-center"><?= $tot ?></td>
                                        <td class="text-center text-primary"><?= $occ ?></td>
                                        <td class="text-center text-success"><?= $free ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-fill" style="height:8px;">
                                                    <div class="progress-bar bg-<?= $color ?>" style="width:<?= $pct ?>%"></div>
                                                </div>
                                                <span class="small fw-semibold"><?= $pct ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Recent confirmations -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-check-circle text-success me-2"></i>Recent Confirmations</h6>
                    <a href="approvals.php?status=confirmed" class="small text-decoration-none">View all</a>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recentConfirmed)): ?>
                        <div class="text-center py-4 text-muted small">No confirmed bookings yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0 align-middle">
                                <thead class="table-light"><tr><th>Student</th><th>Room</th><th>Approved By</th><th>When</th><th>Penalty</th></tr></thead>
                                <tbody>
                                <?php foreach ($recentConfirmed as $r): ?>
                                    <tr>
                                        <td><div class="small fw-semibold"><?= h($r['full_name']) ?></div><div class="text-muted" style="font-size:.7rem;"><?= h($r['reg_number']) ?></div></td>
                                        <td class="small"><?= h($r['room_number']) ?> · <?= h($r['block_name']) ?></td>
                                        <td class="small text-muted"><?= h($r['approver'] ?? '—') ?></td>
                                        <td class="small text-muted"><?= $r['approved_at'] ? date('M j, H:i', strtotime($r['approved_at'])) : '—' ?></td>
                                        <td><?= (int)$r['penalty_amount'] > 0 ? '<span class="badge bg-warning text-dark">KES '.number_format($r['penalty_amount']).'</span>' : '<span class="text-muted small">—</span>' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>