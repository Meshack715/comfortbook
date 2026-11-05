<?php
// user/room_form.php — add + edit in one page
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
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
        redirect(APP_BASE . '/user/rooms.php');
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
    'status'      => $room['status'],
    'notes'       => $room['notes'] ?? '',
] : [
    'block_id'    => (int)($_GET['block_id'] ?? 0),
    'room_number' => '',
    'capacity'    => 4,
    'status'      => 'vacant',
    'notes'       => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['block_id']    = (int)($_POST['block_id'] ?? 0);
    $form['room_number'] = trim($_POST['room_number'] ?? '');
    $form['capacity']    = (int)($_POST['capacity'] ?? 1);
    $form['status']      = $_POST['status'] ?? 'vacant';
    $form['notes']       = trim($_POST['notes'] ?? '');

    $block = null;
    foreach ($allBlocks as $b) {
        if ((int)$b['id'] === $form['block_id']) { $block = $b; break; }
    }

    if (!$block)                                         $errors[] = 'Please select a valid building.';
    if ($form['room_number'] === '')                     $errors[] = 'Room number is required.';
    if ($form['capacity'] < 1 || $form['capacity'] > 20) $errors[] = 'Capacity must be 1–20.';
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
                SET block_id = ?, room_number = ?, capacity = ?, gender = ?, status = ?, notes = ?
                WHERE id = ?
            ")->execute([
                $form['block_id'], $form['room_number'], $form['capacity'],
                $gender, $form['status'], $form['notes'] ?: null, $id,
            ]);
            audit_log($_SESSION['user_id'], null, 'room_edit', "Edited room #$id: {$block['name']} {$form['room_number']}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Room updated.'];
        } else {
            $pdo->prepare("
                INSERT INTO rooms (block_id, room_number, capacity, gender, status, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([
                $form['block_id'], $form['room_number'], $form['capacity'],
                $gender, $form['status'], $form['notes'] ?: null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($_SESSION['user_id'], null, 'room_add', "Added room #$newId: {$block['name']} {$form['room_number']}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Room created.'];
        }
        redirect(APP_BASE . '/user/rooms.php');
    }
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>

<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>

    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

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
                            <div class="col-md-3">
                                <label class="form-label">Capacity <span class="text-danger">*</span></label>
                                <input type="number" name="capacity" class="form-control" required
                                       min="1" max="20" value="<?= (int)$form['capacity'] ?>">
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