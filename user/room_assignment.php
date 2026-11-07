<?php
// user/room_assignment.php — Dean + Secretary: assign & reassign rooms
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men','dean_women','secretary_men','secretary_women']);

$gender = gender_filter();
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ============================================================
// HELPERS
// ============================================================
function room_occupancy(PDO $pdo, int $roomId): int {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM students
        WHERE current_room_id = ? AND is_active = 1
    ");
    $stmt->execute([$roomId]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
// ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---------- ASSIGN or MOVE ----------
    if ($action === 'assign') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $rid = (int)($_POST['room_id'] ?? 0);

        if ($sid > 0 && $rid > 0) {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id=? AND gender=? AND is_active=1");
            $stmt->execute([$sid, $gender]);
            $student = $stmt->fetch();

            $stmt = $pdo->prepare("
                SELECT r.*, b.name AS block_name
                FROM rooms r JOIN blocks b ON b.id = r.block_id
                WHERE r.id=? AND r.gender=?
            ");
            $stmt->execute([$rid, $gender]);
            $room = $stmt->fetch();

            if (!$student) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Student not found in your wing.'];
            } elseif (!$room) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Room not found.'];
            } else {
                $occ = room_occupancy($pdo, $rid);
                $alreadyHere = ((int)$student['current_room_id'] === $rid);

                if (!$alreadyHere && $occ >= (int)$room['capacity']) {
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Room is full ('.$occ.'/'.$room['capacity'].').'];
                } else {
                    try {
                        $pdo->beginTransaction();
                        $oldRoomId = (int)($student['current_room_id'] ?? 0);

                        if ($oldRoomId && $oldRoomId !== $rid) {
                            $pdo->prepare("UPDATE students SET current_room_id=NULL WHERE id=?")->execute([$sid]);
                            if (room_occupancy($pdo, $oldRoomId) === 0) {
                                $pdo->prepare("UPDATE rooms SET status='available_p2' WHERE id=?")->execute([$oldRoomId]);
                            }
                        }

                        $pdo->prepare("UPDATE students SET current_room_id=?, placed=1 WHERE id=?")
                            ->execute([$rid, $sid]);
                        $pdo->prepare("UPDATE rooms SET status='occupied' WHERE id=?")->execute([$rid]);

                        $pdo->commit();

                        $isMove = $oldRoomId && $oldRoomId !== $rid;
                        audit_log(
                            $_SESSION['user_id'],
                            $sid,
                            $isMove ? 'room_move' : 'room_assign',
                            ($isMove ? "Moved {$student['reg_number']} → room #$rid" : "Assigned {$student['reg_number']} → room #$rid")
                            . " ({$room['room_number']})"
                        );

                        if (!$isMove) {
                            @send_welcome_email(
                                $student['email'], $student['full_name'],
                                $room['room_number'], $room['block_name']
                            );
                        }

                        $_SESSION['flash'] = [
                            'type'=>'success',
                            'msg'=> $isMove
                                ? "Moved {$student['full_name']} to {$room['room_number']}."
                                : "Assigned {$student['full_name']} to {$room['room_number']} · welcome email sent.",
                        ];
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Assign failed: '.$e->getMessage()];
                    }
                }
            }
        }
        redirect(APP_BASE . '/user/room_assignment.php');
    }

    // ---------- UNASSIGN ----------
    if ($action === 'unassign') {
        $sid = (int)($_POST['student_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id=? AND gender=? AND is_active=1");
            $stmt->execute([$sid, $gender]);
            $student = $stmt->fetch();

            if ($student && $student['current_room_id']) {
                try {
                    $pdo->beginTransaction();
                    $roomId = (int)$student['current_room_id'];

                    $pdo->prepare("UPDATE students SET current_room_id=NULL, placed=0 WHERE id=?")->execute([$sid]);
                    if (room_occupancy($pdo, $roomId) === 0) {
                        $pdo->prepare("UPDATE rooms SET status='available_p2' WHERE id=?")->execute([$roomId]);
                    }

                    $pdo->commit();
                    audit_log($_SESSION['user_id'], $sid, 'room_unassign',
                        "Unassigned {$student['reg_number']} from room #$roomId");
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Student unassigned. Room freed if now empty.'];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Unassign failed: '.$e->getMessage()];
                }
            }
        }
        redirect(APP_BASE . '/user/room_assignment.php');
    }

    // ---------- BULK ASSIGN ----------
    if ($action === 'bulk_assign') {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                SELECT id, full_name, reg_number, email
                FROM students
                WHERE gender=? AND is_active=1 AND placed=0
                ORDER BY id
            ");
            $stmt->execute([$gender]);
            $unplaced = $stmt->fetchAll();

            $assigned = 0;
            foreach ($unplaced as $s) {
                $stmt = $pdo->prepare("
                    SELECT r.id, r.room_number, r.capacity, b.name AS block_name,
                        (SELECT COUNT(*) FROM students s2 WHERE s2.current_room_id=r.id AND s2.is_active=1) AS occ
                    FROM rooms r JOIN blocks b ON b.id = r.block_id
                    WHERE r.gender=?
                    HAVING occ < r.capacity
                    ORDER BY occ DESC, (r.capacity - occ) ASC, r.room_number ASC
                    LIMIT 1
                ");
                $stmt->execute([$gender]);
                $room = $stmt->fetch();
                if (!$room) break;

                $pdo->prepare("UPDATE students SET current_room_id=?, placed=1 WHERE id=?")
                    ->execute([$room['id'], $s['id']]);
                $pdo->prepare("UPDATE rooms SET status='occupied' WHERE id=?")->execute([$room['id']]);
                $assigned++;
            }

            $pdo->commit();
            audit_log($_SESSION['user_id'], null, 'bulk_assign', "Bulk-assigned $assigned students in $gender wing");
            $_SESSION['flash'] = ['type'=>'success','msg'=>"Bulk-assigned $assigned student".($assigned===1?'':'s')."."];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Bulk assign failed: '.$e->getMessage()];
        }
        redirect(APP_BASE . '/user/room_assignment.php');
    }
}

// ============================================================
// DATA
// ============================================================
$search = trim($_GET['q'] ?? '');

// Unplaced students
$uWhere = ' WHERE s.gender=? AND s.is_active=1 AND s.placed=0';
$uParams = [$gender];
if ($search !== '') {
    $uWhere .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ?)';
    $uParams[] = "%$search%"; $uParams[] = "%$search%";
}
$stmt = $pdo->prepare("
    SELECT s.*, r.room_number AS old_room
    FROM students s LEFT JOIN rooms r ON r.id = s.current_room_id
    $uWhere ORDER BY s.full_name
");
$stmt->execute($uParams);
$unplaced = $stmt->fetchAll();

// Placed students
$pWhere = ' WHERE s.gender=? AND s.is_active=1 AND s.placed=1';
$pParams = [$gender];
if ($search !== '') {
    $pWhere .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ?)';
    $pParams[] = "%$search%"; $pParams[] = "%$search%";
}
$stmt = $pdo->prepare("
    SELECT s.*, r.room_number, r.capacity, b.name AS block_name
    FROM students s
    JOIN rooms r ON r.id = s.current_room_id
    JOIN blocks b ON b.id = r.block_id
    $pWhere ORDER BY b.name, r.room_number, s.full_name
");
$stmt->execute($pParams);
$placed = $stmt->fetchAll();

// All rooms with occupancy
$stmt = $pdo->prepare("
    SELECT r.*, b.name AS block_name,
        (SELECT COUNT(*) FROM students s WHERE s.current_room_id=r.id AND s.is_active=1) AS occ
    FROM rooms r JOIN blocks b ON b.id = r.block_id
    WHERE r.gender=?
    ORDER BY b.name, r.room_number
");
$stmt->execute([$gender]);
$allRooms = $stmt->fetchAll();

$availableRooms = [];
$fullRooms = 0;
foreach ($allRooms as $r) {
    if ((int)$r['occ'] < (int)$r['capacity']) $availableRooms[] = $r;
    else $fullRooms++;
}

// Recent activity
$stmt = $pdo->prepare("
    SELECT al.action, al.details, al.created_at, u.full_name AS by_name
    FROM audit_log al
    LEFT JOIN users u ON u.id = al.user_id
    WHERE al.action IN ('room_assign','room_move','room_unassign','bulk_assign','manual_assign')
      AND (al.student_id IN (SELECT id FROM students WHERE gender=?)
           OR al.details LIKE ?)
    ORDER BY al.created_at DESC LIMIT 8
");
$stmt->execute([$gender, "%$gender%"]);
$recentActivity = $stmt->fetchAll();
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-random text-primary me-2"></i>Room Assignment</h4>
                    <p class="text-muted small mb-0">
                        <?= h($gender) ?> wing ·
                        <?= count($unplaced) ?> unplaced ·
                        <?= count($availableRooms) ?> rooms with space
                    </p>
                </div>
                <div class="d-flex gap-2 mt-2 mt-md-0 align-items-center">
                    <form method="post" id="bulkForm">
                        <input type="hidden" name="action" value="bulk_assign">
                        <button type="submit"
                                class="btn btn-success btn-sm bulk-btn"
                                <?= (empty($unplaced) || empty($availableRooms)) ? 'disabled' : '' ?>
                                data-count="<?= count($unplaced) ?>">
                            <span class="btn-label"><i class="fas fa-bolt me-1"></i>Bulk Auto-Assign</span>
                            <span class="btn-spin d-none">
                                <span class="spinner-border spinner-border-sm me-1"></span>
                                Assigning <?= count($unplaced) ?>...
                            </span>
                        </button>
                    </form>
                    <form method="get" class="d-flex gap-2" id="searchForm">
                        <div class="position-relative">
                            <input type="text" name="q" id="searchInput"
                                   class="form-control form-control-sm"
                                   value="<?= h($search) ?>" placeholder="Search name or reg #"
                                   autocomplete="off">
                            <span id="searchSpinner" class="position-absolute top-50 end-0 translate-middle-y me-2 d-none">
                                <span class="spinner-border spinner-border-sm text-primary"></span>
                            </span>
                        </div>
                        <?php if ($search): ?>
                        <a href="room_assignment.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Flash toast -->
            <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show d-flex align-items-center auto-dismiss">
                <i class="fas fa-<?= $flash['type']==='success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <div class="flex-grow-1"><?= h($flash['msg']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- KPI strip -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-warning"><?= count($unplaced) ?></div>
                        <div class="small text-muted">Unplaced</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-success"><?= count($availableRooms) ?></div>
                        <div class="small text-muted">Rooms w/ Space</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-primary"><?= count($placed) ?></div>
                        <div class="small text-muted">Placed</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-secondary"><?= $fullRooms ?></div>
                        <div class="small text-muted">Full Rooms</div>
                    </div></div>
                </div>
            </div>

            <div class="row g-3">

                <!-- Unplaced -->
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-user-slash text-warning me-2"></i>Unplaced Students
                            </h6>
                            <span class="badge bg-warning text-dark"><?= count($unplaced) ?></span>
                        </div>
                        <div class="card-body p-0" style="max-height:65vh;overflow-y:auto;">
                            <?php if (empty($unplaced)): ?>
                                <div class="text-center py-5 text-muted small">
                                    <i class="fas fa-check-circle fa-2x mb-2 text-success opacity-50"></i>
                                    <p class="mb-0">All <?= h($gender) ?> students are placed</p>
                                </div>
                            <?php else: ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($unplaced as $s): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="me-2">
                                                    <div class="fw-semibold small"><?= h($s['full_name']) ?></div>
                                                    <div class="text-muted" style="font-size:.72rem;">
                                                        <?= h($s['reg_number']) ?>
                                                        <?php if ($s['old_room']): ?>
                                                            · was <?= h($s['old_room']) ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <button class="btn btn-sm btn-primary flex-shrink-0 assign-btn"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#assignModal"
                                                        data-sid="<?= (int)$s['id'] ?>"
                                                        data-name="<?= h($s['full_name']) ?>"
                                                        data-mode="assign">
                                                    <span class="btn-label"><i class="fas fa-bed me-1"></i>Assign</span>
                                                </button>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Available Rooms -->
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-bed text-success me-2"></i>Rooms with Space
                            </h6>
                            <span class="badge bg-success"><?= count($availableRooms) ?></span>
                        </div>
                        <div class="card-body p-0" style="max-height:65vh;overflow-y:auto;">
                            <?php if (empty($availableRooms)): ?>
                                <div class="text-center py-5 text-muted small">
                                    <i class="fas fa-bed fa-2x mb-2 opacity-25"></i>
                                    <p class="mb-0">No rooms with free space</p>
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover mb-0 align-middle">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Room</th>
                                                <th>Building</th>
                                                <th class="text-center">Filled</th>
                                                <th style="width:35%;">Occupancy</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($availableRooms as $r):
                                            $occ = (int)$r['occ']; $cap = (int)$r['capacity'];
                                            $pct = $cap > 0 ? round(($occ / $cap) * 100) : 0;
                                            $barColor = $pct >= 75 ? 'warning' : 'success';
                                        ?>
                                            <tr>
                                                <td class="fw-semibold"><?= h($r['room_number']) ?></td>
                                                <td class="small text-muted"><?= h($r['block_name']) ?></td>
                                                <td class="text-center small"><?= $occ ?> / <?= $cap ?></td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="progress flex-fill" style="height:6px;">
                                                            <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                                                        </div>
                                                        <span class="small text-nowrap"><?= $cap - $occ ?> free</span>
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
                </div>
            </div>

            <!-- Placed students -->
            <?php if (!empty($placed)): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-check-circle text-primary me-2"></i>Currently Placed
                    </h6>
                    <span class="badge bg-primary"><?= count($placed) ?></span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive" style="max-height:50vh;overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0 align-middle">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Student</th>
                                    <th>Room</th>
                                    <th>Building</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($placed as $s): ?>
                                <tr>
                                    <td>
                                        <div class="small fw-semibold"><?= h($s['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.7rem;"><?= h($s['reg_number']) ?></div>
                                    </td>
                                    <td class="small"><?= h($s['room_number']) ?></td>
                                    <td class="small text-muted"><?= h($s['block_name']) ?></td>
                                    <td class="text-end">
                                        <button class="btn btn-sm btn-outline-primary move-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#assignModal"
                                                data-sid="<?= (int)$s['id'] ?>"
                                                data-name="<?= h($s['full_name']) ?>"
                                                data-mode="move">
                                            <span class="btn-label"><i class="fas fa-exchange-alt me-1"></i>Move</span>
                                        </button>
                                        <button class="btn btn-sm btn-outline-warning unassign-btn"
                                                data-sid="<?= (int)$s['id'] ?>"
                                                data-name="<?= h($s['full_name']) ?>"
                                                data-room="<?= h($s['room_number']) ?>">
                                            <span class="btn-label"><i class="fas fa-unlink"></i></span>
                                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Recent activity -->
            <?php if (!empty($recentActivity)): ?>
            <div class="card border-0 shadow-sm mt-3 mb-4">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-history text-primary me-2"></i>Recent Assignment Activity
                    </h6>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="table-light"><tr><th>Action</th><th>Details</th><th>By</th><th>When</th></tr></thead>
                            <tbody>
                            <?php foreach ($recentActivity as $a): ?>
                                <tr>
                                    <td><span class="badge bg-light text-dark"><?= h($a['action']) ?></span></td>
                                    <td class="small text-muted"><?= h(mb_substr($a['details'] ?? '', 0, 70)) ?></td>
                                    <td class="small text-muted"><?= h($a['by_name'] ?? '—') ?></td>
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

<!-- Assign / Move modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post" id="assignForm">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalTitle">Assign Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    <i class="fas fa-user me-1"></i>
                    <strong id="assignName"></strong>
                </p>

                <?php if (empty($availableRooms)): ?>
                    <div class="alert alert-warning mb-0">No rooms with space in your wing.</div>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:50vh;overflow-y:auto;">
                        <table class="table table-sm align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th></th>
                                    <th>Room</th>
                                    <th>Building</th>
                                    <th class="text-center">Occupied</th>
                                    <th class="text-center">Free</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($availableRooms as $r):
                                $occ = (int)$r['occ']; $cap = (int)$r['capacity'];
                            ?>
                                <tr>
                                    <td><input type="radio" name="room_id" value="<?= (int)$r['id'] ?>" required></td>
                                    <td class="fw-semibold"><?= h($r['room_number']) ?></td>
                                    <td class="small text-muted"><?= h($r['block_name']) ?></td>
                                    <td class="text-center small"><?= $occ ?> / <?= $cap ?></td>
                                    <td class="text-center small text-success"><?= $cap - $occ ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="student_id" id="assignSid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary" id="assignSubmit" <?= empty($availableRooms) ? 'disabled' : '' ?>>
                    <span class="btn-label"><i class="fas fa-check me-1"></i>Confirm</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Assigning...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<!-- Unassign modal -->
<div class="modal fade" id="unassignModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="unassignForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-unlink text-warning me-2"></i>Unassign Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">
                    Remove <strong id="unassignName"></strong> from
                    <strong id="unassignRoom"></strong>?
                </p>
                <div class="alert alert-warning small mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    The student becomes unplaced. The room is freed if nobody else occupies it.
                </div>
                <input type="hidden" name="action" value="unassign">
                <input type="hidden" name="student_id" id="unassignSid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning" id="unassignSubmit">
                    <span class="btn-label"><i class="fas fa-unlink me-1"></i>Unassign</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Removing...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// ---------- Auto-dismiss flash ----------
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 4000);
});

// ---------- Debounced search ----------
let searchTimer = null;
const searchInput   = document.getElementById('searchInput');
const searchSpinner = document.getElementById('searchSpinner');

searchInput?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchSpinner.classList.remove('d-none');
    searchTimer = setTimeout(() => document.getElementById('searchForm').submit(), 500);
});

// ---------- Bulk auto-assign spinner + confirm ----------
document.getElementById('bulkForm')?.addEventListener('submit', function (e) {
    const btn = this.querySelector('.bulk-btn');
    const count = btn.dataset.count || '0';
    if (!confirm(`Auto-assign ALL ${count} unplaced students to available rooms?`)) {
        e.preventDefault();
        return;
    }
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Assign modal context ----------
document.getElementById('assignModal')?.addEventListener('show.bs.modal', function (e) {
    const btn = e.relatedTarget;
    document.getElementById('assignSid').value = btn.dataset.sid;
    document.getElementById('assignName').textContent = btn.dataset.name;
    document.getElementById('assignModalTitle').textContent =
        btn.dataset.mode === 'move' ? 'Move to Another Room' : 'Assign Room';
});

// ---------- Assign submit spinner ----------
document.getElementById('assignForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('assignSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Unassign modal context ----------
document.querySelectorAll('.unassign-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('unassignSid').value = btn.dataset.sid;
        document.getElementById('unassignName').textContent = btn.dataset.name;
        document.getElementById('unassignRoom').textContent = btn.dataset.room;
        new bootstrap.Modal(document.getElementById('unassignModal')).show();
    });
});

// ---------- Unassign submit spinner ----------
document.getElementById('unassignForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('unassignSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>