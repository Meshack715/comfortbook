<?php
// user/room_form.php — add + edit in one page
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/beds.php';
require_role(['admin']);

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$room   = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM rooms WHERE id = ?");
    $stmt->execute([$id]);
    $room = $stmt->fetch();
    if (!$room) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Room not found.'];
        redirect('rooms.php');
    }
}

$allBlocks = $pdo->query("
    SELECT id, name, gender FROM blocks WHERE is_active = 1
    ORDER BY gender, name
")->fetchAll();

$errors = [];
$form = $isEdit ? [
    'block_id'    => (int)$room['block_id'],
    'room_number' => $room['room_number'],
    'capacity'    => (int)$room['capacity'],
    'bunks'       => (int)$room['bunks'], 'singles' => (int)$room['singles'], 'bath_type' => $room['bath_type'] ?: 'none',
    'status'      => $room['status'],
    'notes'       => $room['notes'] ?? '',
] : [
    'block_id'    => (int)($_GET['block_id'] ?? 0),
    'room_number' => '',
    'capacity'    => 4,
    'bunks'       => 2, 'singles' => 0, 'bath_type' => 'none',
    'status'      => 'vacant',
    'notes'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['block_id']    = (int)($_POST['block_id'] ?? 0);
    $form['room_number'] = trim($_POST['room_number'] ?? '');
    $form['bunks']       = max(0, min(4, (int)($_POST['bunks'] ?? 0)));
    $form['singles']     = max(0, min(6, (int)($_POST['singles'] ?? 0)));
    $form['bath_type']   = ($_POST['bath_type'] ?? 'none') === 'ensuite' ? 'ensuite' : 'none';
    $form['capacity']    = $form['bunks'] * 2 + $form['singles'];
    $form['status']      = $_POST['status'] ?? 'vacant';
    $form['notes']       = trim($_POST['notes'] ?? '');

    $block = null;
    foreach ($allBlocks as $b) {
        if ((int)$b['id'] === $form['block_id']) { $block = $b; break; }
    }

    if (!$block)                                         $errors[] = 'Please select a valid building.';
    if ($form['room_number'] === '')                     $errors[] = 'Room number is required.';
    if ($form['capacity'] < 1) $errors[] = 'A room needs at least one bed.';
    if (!in_array($form['status'], ['vacant','available_p2','reserved','occupied'], true)) {
        $errors[] = 'Invalid status.';
    }

    // Uniqueness within block
    if (!$errors) {
        $sql    = "SELECT COUNT(*) FROM rooms WHERE block_id = ? AND room_number = ?";
        $params = [$form['block_id'], $form['room_number']];
        if ($isEdit) { $sql .= " AND id <> ?"; $params[] = $id; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'This room number already exists in that building.';
        }
    }

    // Prevent marking occupied with no confirmed booking (sanity)
    if ($isEdit && $form['status'] === 'occupied' && $room['status'] !== 'occupied') {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE room_id = ? AND status = 'confirmed'
        ");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() === 0) {
            $errors[] = 'Cannot mark as occupied — no confirmed booking exists for this room.';
        }
    }

    if (!$errors && $block) {
        $gender = $block['gender']; // room inherits block gender

        if ($isEdit) {
            $pdo->prepare("
                UPDATE rooms
                SET block_id = ?, room_number = ?, capacity = ?, gender = ?, status = ?, notes = ?, bunks = ?, singles = ?, bath_type = ?
                WHERE id = ?
            ")->execute([
                $form['block_id'], $form['room_number'], $form['capacity'],
                $gender, $form['status'], $form['notes'] ?: null, $form['bunks'], $form['singles'], $form['bath_type'], $id,
            ]);
            beds_ensure_for_room($pdo, $id); beds_reconcile($pdo);
            audit_log($_SESSION['user_id'], null, 'room_edit', "Edited room #$id: {$block['name']} {$form['room_number']}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Room updated.'];
        } else {
            $pdo->prepare("
                INSERT INTO rooms (block_id, room_number, capacity, gender, status, notes, bunks, singles, bath_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $form['block_id'], $form['room_number'], $form['capacity'],
                $gender, $form['status'], $form['notes'] ?: null, $form['bunks'], $form['singles'], $form['bath_type'],
            ]);
            $newId = (int)$pdo->lastInsertId(); beds_ensure_for_room($pdo, $newId); beds_reconcile($pdo);
            audit_log($_SESSION['user_id'], null, 'room_add', "Added room #$newId: {$block['name']} {$form['room_number']}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Room created.'];
        }
        redirect('rooms.php');
    }
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>

<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>

    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">

            <div class="d-flex justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1">
                        <i class="fas fa-<?= $isEdit ? 'edit' : 'plus-circle' ?> text-primary me-2"></i>
                        <?= $isEdit ? 'Edit Room' : 'Add Room' ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <?= $isEdit ? '#'.$id.' · '.h($room['room_number']) : 'Create a new room' ?>
                    </p>
                </div>
                <a href="rooms.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <form method="post">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Building <span class="text-danger">*</span></label>
                                <select name="block_id" class="form-select" required>
                                    <option value="">— Select building —</option>
                                    <?php foreach ($allBlocks as $b): ?>
                                        <option value="<?= (int)$b['id'] ?>"
                                                <?= $form['block_id'] === (int)$b['id'] ? 'selected' : '' ?>>
                                            <?= h($b['name']) ?> (<?= h($b['gender']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Room gender is inherited from the building.</small>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Room Number <span class="text-danger">*</span></label>
                                <input type="text" name="room_number" class="form-control" required
                                       value="<?= h($form['room_number']) ?>" placeholder="e.g., A-101">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Double-decker bunks</label>
                                <input type="number" name="bunks" class="form-control" min="0" max="4" value="<?= (int)$form['bunks'] ?>">
                            </div>
                            <div class="col-6 col-md-2">
                                <label class="form-label">Single beds</label>
                                <input type="number" name="singles" class="form-control" min="0" max="6" value="<?= (int)$form['singles'] ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Bathroom</label>
                                <select name="bath_type" class="form-select"><option value="none" <?= $form['bath_type'] === 'none' ? 'selected' : '' ?>>On the floor</option><option value="ensuite" <?= $form['bath_type'] === 'ensuite' ? 'selected' : '' ?>>In the room</option></select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Status</label>
                                <select name="status" class="form-select">
                                    <?php foreach ([
                                        'vacant'       => 'Vacant',
                                        'available_p2' => 'Released (Phase 2)',
                                        'reserved'     => 'Reserved',
                                        'occupied'     => 'Occupied',
                                    ] as $val => $label): ?>
                                        <option value="<?= $val ?>" <?= $form['status'] === $val ? 'selected' : '' ?>>
                                            <?= $label ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Notes</label>
                                <input type="text" name="notes" class="form-control"
                                       value="<?= h($form['notes']) ?>" placeholder="Optional">
                            </div>
                        </div>

                        <hr>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary">
                                <i class="fas fa-save me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Room' ?>
                            </button>
                            <a href="rooms.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>

<?php include __DIR__ . '/partial/footer.php'; ?>