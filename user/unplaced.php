<?php
// user/unplaced.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men','dean_women','secretary_men','secretary_women']);

$gender = gender_filter();
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Manually assign a room to an unplaced student
    if ($action === 'assign') {
        $sid  = (int)($_POST['student_id'] ?? 0);
        $rid  = (int)($_POST['room_id'] ?? 0);

        if ($sid > 0 && $rid > 0) {
            // Load student (wing-scoped)
            $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND gender = ?");
            $stmt->execute([$sid, $gender]);
            $student = $stmt->fetch();

            // Load room (must be in wing, must be free)
            $stmt = $pdo->prepare("
                SELECT * FROM rooms WHERE id = ? AND gender = ?
                AND status IN ('vacant','available_p2')
            ");
            $stmt->execute([$rid, $gender]);
            $room = $stmt->fetch();

            if (!$student) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Student not found.'];
            } elseif (!$room) {
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Room not available.'];
            } else {
                try {
                    $pdo->beginTransaction();

                    // Free old room if any
                    if ($student['current_room_id']) {
                        $pdo->prepare("
                            UPDATE rooms SET status='available_p2'
                            WHERE id = ? AND id <> ?
                        ")->execute([$student['current_room_id'], $rid]);
                    }

                    // Mark room occupied
                    $pdo->prepare("
                        UPDATE rooms SET status='occupied', reserved_by_student_id=NULL, reserved_at=NULL
                        WHERE id = ?
                    ")->execute([$rid]);

                    // Update student
                    $pdo->prepare("
                        UPDATE students SET current_room_id=?, placed=1 WHERE id = ?
                    ")->execute([$rid, $sid]);

                    // Create a "manual" confirmed booking for record
                    $pdo->prepare("
                        INSERT INTO bookings (student_id, room_id, phase, action, status, approved_by, approved_at)
                        VALUES (?, ?, 2, 'book', 'confirmed', ?, NOW())
                    ")->execute([$sid, $rid, $_SESSION['user_id']]);

                    $pdo->commit();

                    audit_log($_SESSION['user_id'], $sid, 'manual_assign',
                        "Assigned {$student['reg_number']} to room #$rid ({$room['room_number']})");

                    // Welcome email
                    $stmt = $pdo->prepare("SELECT name FROM blocks WHERE id = ?");
                    $stmt->execute([$room['block_id']]);
                    $blockName = $stmt->fetchColumn() ?: '';

                    @send_welcome_email($student['email'], $student['full_name'], $room['room_number'], $blockName);

                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Room assigned · welcome email sent.'];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>'Assign failed: '.$e->getMessage()];
                }
            }
        }
        redirect('unplaced.php');
    }
}

// ---------- UNPLACED LIST ----------
$search = trim($_GET['q'] ?? '');
$where = ' WHERE s.gender = ? AND s.is_active = 1 AND s.placed = 0';
$params = [$gender];
if ($search !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ? OR s.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT s.*, r.room_number AS old_room
    FROM students s
    LEFT JOIN rooms r ON r.id = s.current_room_id
    $where
    ORDER BY s.full_name
");
$stmt->execute($params);
$unplaced = $stmt->fetchAll();

// Available rooms in wing
$stmt = $pdo->prepare("
    SELECT r.id, r.room_number, r.capacity, r.status, b.name block_name
    FROM rooms r JOIN blocks b ON b.id = r.block_id
    WHERE r.gender = ? AND r.status IN ('vacant','available_p2')
    ORDER BY b.name, r.room_number
");
$stmt->execute([$gender]);
$availableRooms = $stmt->fetchAll();
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-user-slash text-warning me-2"></i>Unplaced Students</h4>
                    <p class="text-muted small mb-0"><?= h($gender) ?> wing · <?= count($unplaced) ?> student<?= count($unplaced)===1?'':'s' ?></p>
                </div>
                <span class="badge bg-info"><?= count($availableRooms) ?> rooms available</span>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Help banner -->
            <div class="alert alert-info d-flex align-items-start">
                <i class="fas fa-info-circle me-2 mt-1"></i>
                <div class="small">
                    These students either <strong>released their room and didn't book</strong> in Phase 2,
                    or were <strong>cancelled</strong> after being placed.
                    Assign manually below or ask them to book again.
                </div>
            </div>

            <!-- Search -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-9"><label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Name, reg #, email"></div>
                    <div class="col-md-3 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i>Search</button>
                        <a href="unplaced.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <!-- List -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($unplaced)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-check-circle fa-3x mb-3 text-success opacity-50"></i>
                        <h6>All students are placed</h6>
                        <p class="small mb-0">Nothing to resolve.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Contact</th>
                                    <th>Old Room</th>
                                    <th>Phase 1</th>
                                    <th class="text-end">Assign Room</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($unplaced as $s): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($s['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;">
                                            <?= h($s['reg_number']) ?> · Year <?= (int)$s['year_of_study'] ?>
                                        </div>
                                    </td>
                                    <td class="small text-muted"><?= h($s['email']) ?></td>
                                    <td class="small">
                                        <?= $s['old_room'] ? h($s['old_room']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td>
                                        <?= match($s['phase1_action']) {
                                            'keep'    => '<span class="badge bg-success">Kept</span>',
                                            'release' => '<span class="badge bg-info text-dark">Released</span>',
                                            default   => '<span class="badge bg-light text-dark">—</span>',
                                        } ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if (empty($availableRooms)): ?>
                                            <span class="small text-muted">No rooms available</span>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-primary"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#assignModal"
                                                    data-sid="<?= (int)$s['id'] ?>"
                                                    data-name="<?= h($s['full_name']) ?>"
                                                    data-old="<?= h($s['old_room'] ?? 'none') ?>">
                                                <i class="fas fa-bed me-1"></i>Assign
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div></div>

            <div class="pb-4"></div>
        </main>

<!-- Assign modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h5 class="modal-title">Assign Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Assign a room to <strong id="assignName"></strong>.</p>
                <p class="small text-muted">Old room: <span id="assignOld"></span> (will be freed if different).</p>

                <label class="form-label">Room <span class="text-danger">*</span></label>
                <select name="room_id" class="form-select" required>
                    <option value="">— Select a room —</option>
                    <?php foreach ($availableRooms as $r): ?>
                        <option value="<?= (int)$r['id'] ?>">
                            <?= h($r['room_number']) ?> · <?= h($r['block_name']) ?>
                            (<?= $r['status'] === 'available_p2' ? 'released' : 'vacant' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <input type="hidden" name="action" value="assign">
                <input type="hidden" name="student_id" id="assignSid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Assign Room</button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>
<script>
document.getElementById('assignModal')?.addEventListener('show.bs.modal', function (e) {
    const b = e.relatedTarget;
    document.getElementById('assignSid').value = b.dataset.sid;
    document.getElementById('assignName').textContent = b.dataset.name;
    document.getElementById('assignOld').textContent = b.dataset.old;
});
</script>