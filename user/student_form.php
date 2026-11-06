<?php
// user/student_form.php — add + edit + auto-pick (self-contained, no API)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$student = null;
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    if (!$student) { $_SESSION['flash']=['type'=>'danger','msg'=>'Student not found.']; redirect('students.php'); }
}

// ------------------------------------------------------------
// AUTO-PICK: best room for a gender (packed-first algorithm)
// ------------------------------------------------------------
function pick_best_room(PDO $pdo, string $gender, ?int $excludeRoom = null): ?array {
    $sql = "
        SELECT r.id, r.room_number, r.capacity, b.name AS block_name,
            (SELECT COUNT(*) FROM students s WHERE s.current_room_id = r.id AND s.is_active = 1) AS occ
        FROM rooms r
        JOIN blocks b ON b.id = r.block_id
        WHERE r.gender = :gender
        HAVING occ < r.capacity
        ORDER BY occ DESC, (r.capacity - occ) ASC, r.room_number ASC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':gender' => $gender]);
    $room = $stmt->fetch();

    if (!$room && $excludeRoom !== null) {
        // fallback: no available room, use the existing one (edit case)
        $stmt = $pdo->prepare("
            SELECT r.id, r.room_number, r.capacity, b.name AS block_name,
                (SELECT COUNT(*) FROM students s WHERE s.current_room_id = r.id AND s.is_active = 1) AS occ
            FROM rooms r JOIN blocks b ON b.id = r.block_id
            WHERE r.id = ?
        ");
        $stmt->execute([$excludeRoom]);
        $room = $stmt->fetch() ?: null;
    }

    return $room ?: null;
}

function list_alternative_rooms(PDO $pdo, string $gender, int $bestId, int $limit = 8): array {
    $sql = "
        SELECT r.id, r.room_number, r.capacity, b.name AS block_name,
            (SELECT COUNT(*) FROM students s WHERE s.current_room_id = r.id AND s.is_active = 1) AS occ
        FROM rooms r
        JOIN blocks b ON b.id = r.block_id
        WHERE r.gender = :gender AND r.id <> :bestId
        HAVING occ < r.capacity
        ORDER BY occ DESC, (r.capacity - occ) ASC, r.room_number ASC
        LIMIT :lim
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':gender', $gender);
    $stmt->bindValue(':bestId', $bestId, PDO::PARAM_INT);
    $stmt->bindValue(':lim',    $limit,  PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// ------------------------------------------------------------
// FORM STATE
// ------------------------------------------------------------
$errors = [];
$form = $isEdit ? [
    'reg_number'=>$student['reg_number'], 'full_name'=>$student['full_name'],
    'email'=>$student['email'], 'phone'=>$student['phone'] ?? '',
    'gender'=>$student['gender'], 'year_of_study'=>(int)$student['year_of_study'],
    'course'=>$student['course'] ?? '', 'is_active'=>(int)$student['is_active'],
    'current_room_id'=>(int)($student['current_room_id'] ?? 0),
] : [
    'reg_number'=>'', 'full_name'=>'', 'email'=>'', 'phone'=>'',
    'gender'=>'', 'year_of_study'=>1, 'course'=>'', 'is_active'=>1,
    'current_room_id'=>0,
];

// ------------------------------------------------------------
// HANDLE POSTS
//   - action=autopick_gender  → auto-pick a room, show it, no save
//   - action=save             → persist student
//   - action=change_room      → set room id from modal picker
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // keep posted values
    foreach (['reg_number','full_name','email','phone','course'] as $k) $form[$k] = trim($_POST[$k] ?? '');
    $form['gender']          = $_POST['gender'] ?? '';
    $form['year_of_study']   = (int)($_POST['year_of_study'] ?? 1);
    $form['current_room_id'] = (int)($_POST['current_room_id'] ?? 0);
    $form['is_active']       = isset($_POST['is_active']) ? 1 : 0;

    // ----- AUTO-PICK (no save) -----
    if ($action === 'autopick_gender' && in_array($form['gender'], ['Male','Female'], true)) {
        $best = pick_best_room($pdo, $form['gender']);
        $form['current_room_id'] = $best ? (int)$best['id'] : 0;
        // fall through to render
    }

    // ----- SAVE -----
    elseif ($action === 'save') {
        if ($form['reg_number'] === '') $errors[] = 'Reg number is required.';
        if ($form['full_name'] === '')  $errors[] = 'Full name is required.';
        if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!in_array($form['gender'], ['Male','Female'], true)) $errors[] = 'Please select gender.';
        if ($form['year_of_study'] < 1 || $form['year_of_study'] > 6) $errors[] = 'Year 1–6.';

        // Room sanity
        if (!$errors && $form['current_room_id'] > 0) {
            $stmt = $pdo->prepare("
                SELECT r.gender, r.capacity,
                    (SELECT COUNT(*) FROM students s WHERE s.current_room_id=r.id AND s.is_active=1) occ
                FROM rooms r WHERE r.id = ?
            ");
            $stmt->execute([$form['current_room_id']]);
            $room = $stmt->fetch();
            if (!$room)                                          $errors[] = 'Selected room not found.';
            elseif ($room['gender'] !== $form['gender'])         $errors[] = 'Room is for '.$room['gender'].' students.';
            elseif ((int)$room['occ'] >= (int)$room['capacity']) $errors[] = 'Selected room is full.';
        }

        // Uniqueness
        if (!$errors) {
            $sql = "SELECT COUNT(*) FROM students WHERE reg_number=?"; $p = [$form['reg_number']];
            if ($isEdit) { $sql .= " AND id<>?"; $p[] = $id; }
            $stmt = $pdo->prepare($sql); $stmt->execute($p);
            if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Reg number already exists.';

            $sql = "SELECT COUNT(*) FROM students WHERE email=?"; $p = [$form['email']];
            if ($isEdit) { $sql .= " AND id<>?"; $p[] = $id; }
            $stmt = $pdo->prepare($sql); $stmt->execute($p);
            if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Email already exists.';
        }

        if (!$errors) {
            $roomId = $form['current_room_id'] ?: null;
            try {
                $pdo->beginTransaction();

                if ($isEdit) {
                    $oldRoom = (int)($student['current_room_id'] ?? 0);
                    if ($oldRoom && $oldRoom !== (int)$roomId) {
                        $pdo->prepare("
                            UPDATE rooms SET status='available_p2'
                            WHERE id=? AND NOT EXISTS (
                                SELECT 1 FROM students WHERE current_room_id=? AND id<>?
                            )
                        ")->execute([$oldRoom, $oldRoom, $id]);
                    }

                    $pdo->prepare("
                        UPDATE students SET reg_number=?, full_name=?, email=?, phone=?, gender=?,
                            year_of_study=?, course=?, current_room_id=?, placed=?, is_active=?
                        WHERE id=?
                    ")->execute([
                        $form['reg_number'], $form['full_name'], $form['email'], $form['phone'] ?: null,
                        $form['gender'], $form['year_of_study'], $form['course'] ?: null,
                        $roomId, $roomId ? 1 : 0, $form['is_active'], $id,
                    ]);
                    if ($roomId) $pdo->prepare("UPDATE rooms SET status='occupied' WHERE id=?")->execute([$roomId]);
                    audit_log($_SESSION['user_id'], $id, 'student_edit', "Edited student #$id");
                    $_SESSION['flash'] = ['type'=>'success','msg'=>'Student updated.'];

                } else {
                    $pdo->prepare("
                        INSERT INTO students (reg_number, full_name, email, phone, gender,
                            year_of_study, course, current_room_id, placed, is_active)
                        VALUES (?,?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $form['reg_number'], $form['full_name'], $form['email'], $form['phone'] ?: null,
                        $form['gender'], $form['year_of_study'], $form['course'] ?: null,
                        $roomId, $roomId ? 1 : 0, $form['is_active'],
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    if ($roomId) $pdo->prepare("UPDATE rooms SET status='occupied' WHERE id=?")->execute([$roomId]);
                    audit_log($_SESSION['user_id'], $newId, 'student_add', "Added student #$newId");
                    $_SESSION['flash'] = [
                        'type'=>'success',
                        'msg'=> $roomId ? 'Student created and assigned to a room.' : 'Student created (no room assigned).',
                    ];
                }

                $pdo->commit();
                redirect('students.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Save failed: '.$e->getMessage();
            }
        }
    }

    // ----- CHANGE ROOM (from modal) -----
    elseif ($action === 'change_room') {
        // just keep the posted room id — form re-renders with it
        // (validation happens on save)
    }
}

// ------------------------------------------------------------
// CURRENT AUTO-PICK PREVIEW (for the right card)
// ------------------------------------------------------------
$bestRoom = null;
if (in_array($form['gender'], ['Male','Female'], true)) {
    if ($form['current_room_id'] > 0) {
        // Show the currently selected room
        $stmt = $pdo->prepare("
            SELECT r.id, r.room_number, r.capacity, b.name AS block_name,
                (SELECT COUNT(*) FROM students s WHERE s.current_room_id=r.id AND s.is_active=1) AS occ
            FROM rooms r JOIN blocks b ON b.id = r.block_id
            WHERE r.id = ?
        ");
        $stmt->execute([$form['current_room_id']]);
        $bestRoom = $stmt->fetch() ?: null;
    } else {
        $bestRoom = pick_best_room($pdo, $form['gender']);
        if ($bestRoom) $form['current_room_id'] = (int)$bestRoom['id'];
    }
}

$alternatives = [];
if ($bestRoom && in_array($form['gender'], ['Male','Female'], true)) {
    $alternatives = list_alternative_rooms($pdo, $form['gender'], (int)$bestRoom['id']);
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-<?= $isEdit?'edit':'user-plus' ?> text-primary me-2"></i><?= $isEdit?'Edit':'Add' ?> Student</h4>
                    <p class="text-muted small mb-0"><?= $isEdit?'#'.$id.' · '.h($student['full_name']):'Register a new student · room auto-picked' ?></p>
                </div>
                <a href="students.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm"><div class="card-body">
                        <form method="post" id="studentForm">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Reg Number <span class="text-danger">*</span></label>
                                    <input type="text" name="reg_number" class="form-control" required value="<?= h($form['reg_number']) ?>" placeholder="SJUNOD2611">
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required value="<?= h($form['full_name']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" required value="<?= h($form['email']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?= h($form['phone']) ?>">
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Gender <span class="text-danger">*</span></label>
                                    <select name="gender" class="form-select" required id="genderSelect">
                                        <option value="">— Select gender —</option>
                                        <option value="Male"   <?= $form['gender']==='Male'?'selected':'' ?>>Male</option>
                                        <option value="Female" <?= $form['gender']==='Female'?'selected':'' ?>>Female</option>
                                    </select>
                                    <small class="text-muted">Picking a gender auto-assigns a room.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Year <span class="text-danger">*</span></label>
                                    <input type="number" name="year_of_study" class="form-control" required min="1" max="6" value="<?= (int)$form['year_of_study'] ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Course</label>
                                    <input type="text" name="course" class="form-control" value="<?= h($form['course']) ?>">
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $form['is_active']?'checked':'' ?>>
                                        <label class="form-check-label" for="isActive">Active</label>
                                    </div>
                                </div>
                            </div>

                            <input type="hidden" name="current_room_id" id="roomIdInput" value="<?= (int)$form['current_room_id'] ?>">
                            <input type="hidden" name="action" id="actionInput" value="save">

                            <hr>
                            <button class="btn btn-primary" onclick="document.getElementById('actionInput').value='save';">
                                <i class="fas fa-save me-1"></i><?= $isEdit?'Save Changes':'Create Student' ?>
                            </button>
                            <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
                        </form>
                    </div></div>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold"><i class="fas fa-magic text-primary me-2"></i>Auto-Picked Room</h6>
                            <?php if ($bestRoom && !empty($alternatives)): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                    data-bs-toggle="modal" data-bs-target="#changeRoomModal">
                                <i class="fas fa-random me-1"></i>Change
                            </button>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <?php if (!$form['gender']): ?>
                                <div class="text-center text-muted py-3">
                                    <i class="fas fa-bed fa-2x mb-2 opacity-25"></i>
                                    <p class="small mb-0">Select a gender to auto-pick a room</p>
                                </div>
                            <?php elseif ($bestRoom): ?>
                                <div class="text-center">
                                    <div class="stat-icon bg-success-subtle text-success mx-auto mb-2">
                                        <i class="fas fa-bed"></i>
                                    </div>
                                    <div class="fs-3 fw-bold text-primary"><?= h($bestRoom['room_number']) ?></div>
                                    <div class="small text-muted"><?= h($bestRoom['block_name']) ?></div>
                                </div>
                                <hr>
                                <div class="small">
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Capacity</span><strong><?= (int)$bestRoom['capacity'] ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="text-muted">Occupied</span><strong><?= (int)$bestRoom['occ'] ?></strong>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Free spaces</span>
                                        <strong class="text-success"><?= (int)$bestRoom['capacity'] - (int)$bestRoom['occ'] ?></strong>
                                    </div>
                                </div>
                                <div class="alert alert-info small mt-3 mb-0">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Packed to fill rooms already in use.
                                </div>
                            <?php else: ?>
                                <div class="alert alert-warning small mb-0">
                                    No rooms available for <?= h($form['gender']) ?> students.
                                    The student will be created <strong>unplaced</strong>.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>

<!-- Change room modal -->
<?php if ($bestRoom && !empty($alternatives)): ?>
<div class="modal fade" id="changeRoomModal" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h5 class="modal-title">Choose a Different Room</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Pick a room and click Select:</p>
                <div class="table-responsive" style="max-height:50vh;overflow-y:auto;">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th></th><th>Room</th><th>Building</th><th class="text-center">Occupied</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach (array_merge([$bestRoom], $alternatives) as $r): ?>
                            <tr>
                                <td><input type="radio" name="current_room_id" value="<?= (int)$r['id'] ?>"
                                           <?= (int)$r['id'] === (int)$bestRoom['id'] ? 'checked' : '' ?>></td>
                                <td class="fw-semibold"><?= h($r['room_number']) ?></td>
                                <td class="small text-muted"><?= h($r['block_name']) ?></td>
                                <td class="text-center small"><?= (int)$r['occ'] ?> / <?= (int)$r['capacity'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Preserve all current form fields -->
                <input type="hidden" name="action" value="change_room">
                <input type="hidden" name="reg_number"     value="<?= h($form['reg_number']) ?>">
                <input type="hidden" name="full_name"      value="<?= h($form['full_name']) ?>">
                <input type="hidden" name="email"          value="<?= h($form['email']) ?>">
                <input type="hidden" name="phone"          value="<?= h($form['phone']) ?>">
                <input type="hidden" name="gender"         value="<?= h($form['gender']) ?>">
                <input type="hidden" name="year_of_study"  value="<?= (int)$form['year_of_study'] ?>">
                <input type="hidden" name="course"         value="<?= h($form['course']) ?>">
                <?php if ($form['is_active']): ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="fas fa-check me-1"></i>Select Room</button>
            </div>
        </form>
    </div></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// When gender changes: POST to same page with action=autopick_gender
// so PHP re-picks a room. Preserve other form values.
document.getElementById('genderSelect')?.addEventListener('change', function () {
    document.getElementById('actionInput').value = 'autopick_gender';
    document.getElementById('studentForm').submit();
});
</script>