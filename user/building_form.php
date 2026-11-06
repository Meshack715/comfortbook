<?php
// user/building_form.php — add + edit in one page
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$id      = (int)($_GET['id'] ?? 0);
$isEdit  = $id > 0;
$block   = null;

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM blocks WHERE id = ?");
    $stmt->execute([$id]);
    $block = $stmt->fetch();
    if (!$block) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Building not found.'];
        redirect('buildings.php');
    }
}

$errors = [];
$form = $isEdit ? [
    'name'        => $block['name'],
    'gender'      => $block['gender'],
    'description' => $block['description'] ?? '',
    'is_active'   => (int)$block['is_active'],
] : [
    'name'        => '',
    'gender'      => 'Male',
    'description' => '',
    'is_active'   => 1,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['name']        = trim($_POST['name'] ?? '');
    $form['gender']      = $_POST['gender'] ?? 'Male';
    $form['description'] = trim($_POST['description'] ?? '');
    $form['is_active']   = isset($_POST['is_active']) ? 1 : 0;

    if ($form['name'] === '')                                 $errors[] = 'Name is required.';
    if (!in_array($form['gender'], ['Male', 'Female'], true)) $errors[] = 'Gender is required.';

    // Can't switch gender if rooms already exist (edit mode only)
    if ($isEdit && $form['gender'] !== $block['gender']) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM rooms WHERE block_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'Cannot change gender — this building already has rooms.';
        }
    }

    // Name uniqueness within gender
    if (!$errors) {
        $sql    = "SELECT COUNT(*) FROM blocks WHERE name = ? AND gender = ?";
        $params = [$form['name'], $form['gender']];
        if ($isEdit) { $sql .= " AND id <> ?"; $params[] = $id; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'A building with this name already exists in the ' . $form['gender'] . ' wing.';
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare("
                UPDATE blocks SET name = ?, gender = ?, description = ?, is_active = ?
                WHERE id = ?
            ")->execute([$form['name'], $form['gender'], $form['description'] ?: null, $form['is_active'], $id]);
            audit_log($_SESSION['user_id'], null, 'block_edit', "Edited block #$id: {$form['name']}");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Building updated.'];
        } else {
            $pdo->prepare("
                INSERT INTO blocks (name, gender, description, is_active)
                VALUES (?, ?, ?, ?)
            ")->execute([$form['name'], $form['gender'], $form['description'] ?: null, $form['is_active']]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($_SESSION['user_id'], null, 'block_add', "Added block #$newId: {$form['name']} ({$form['gender']})");
            $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Building created.'];
        }
        redirect('buildings.php');
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
                        <?= $isEdit ? 'Edit Building' : 'Add Building' ?>
                    </h4>
                    <p class="text-muted small mb-0">
                        <?= $isEdit ? '#'.$id.' · '.h($block['name']) : 'Create a new hostel block' ?>
                    </p>
                </div>
                <a href="buildings.php" class="btn btn-outline-secondary btn-sm">
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
                                <label class="form-label">Building Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required
                                       value="<?= h($form['name']) ?>" placeholder="e.g., Male Block A">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Gender <span class="text-danger">*</span></label>
                                <select name="gender" class="form-select" required>
                                    <option value="Male"   <?= $form['gender'] === 'Male'   ? 'selected' : '' ?>>Male</option>
                                    <option value="Female" <?= $form['gender'] === 'Female' ? 'selected' : '' ?>>Female</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Description</label>
                                <textarea name="description" rows="3" class="form-control"
                                          placeholder="Optional notes"><?= h($form['description']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" id="isActive"
                                           <?= $form['is_active'] ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="isActive">Active (visible for booking)</label>
                                </div>
                            </div>
                        </div>

                        <hr>
                        <div class="d-flex gap-2">
                            <button class="btn btn-primary">
                                <i class="fas fa-save me-1"></i><?= $isEdit ? 'Save Changes' : 'Create Building' ?>
                            </button>
                            <a href="buildings.php" class="btn btn-outline-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>

<?php include __DIR__ . '/partial/footer.php'; ?>