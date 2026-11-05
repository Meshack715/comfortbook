<?php
// user/staff_form.php — add + edit
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$id = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$staff = null;
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $staff = $stmt->fetch();
    if (!$staff) { $_SESSION['flash']=['type'=>'danger','msg'=>'Staff not found.']; redirect(APP_BASE.'/user/staff.php'); }
}

$roles = [
    'admin'           => ['label'=>'Administrator',   'scope'=>null],
    'dean_men'        => ['label'=>'Dean of Men',     'scope'=>'Male'],
    'dean_women'      => ['label'=>'Dean of Women',   'scope'=>'Female'],
    'secretary_men'   => ['label'=>'Secretary (Men)', 'scope'=>'Male'],
    'secretary_women' => ['label'=>'Secretary (Women)','scope'=>'Female'],
];

$errors = [];
$form = $isEdit ? [
    'username'=>$staff['username'], 'email'=>$staff['email'],
    'full_name'=>$staff['full_name'], 'role'=>$staff['role'],
    'phone'=>$staff['phone'] ?? '', 'is_active'=>(int)$staff['is_active'],
] : ['username'=>'', 'email'=>'', 'full_name'=>'', 'role'=>'dean_men', 'phone'=>'', 'is_active'=>1];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['username','email','full_name','phone'] as $k) $form[$k] = trim($_POST[$k] ?? '');
    $form['role']      = $_POST['role'] ?? 'dean_men';
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;
    $password          = $_POST['password'] ?? '';
    $password2         = $_POST['password2'] ?? '';

    if ($form['username'] === '') $errors[] = 'Username is required.';
    if ($form['full_name'] === '') $errors[] = 'Full name is required.';
    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (!array_key_exists($form['role'], $roles)) $errors[] = 'Invalid role.';
    if (!$isEdit && $password === '') $errors[] = 'Password required for new account.';
    if ($password !== '' && strlen($password) < 6) $errors[] = 'Password must be at least 6 chars.';
    if ($password !== '' && $password !== $password2) $errors[] = 'Passwords do not match.';

    if (!$errors) {
        $sql = "SELECT COUNT(*) FROM users WHERE username = ?"; $p = [$form['username']];
        if ($isEdit) { $sql .= " AND id <> ?"; $p[] = $id; }
        $stmt = $pdo->prepare($sql); $stmt->execute($p);
        if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Username already taken.';

        $sql = "SELECT COUNT(*) FROM users WHERE email = ?"; $p = [$form['email']];
        if ($isEdit) { $sql .= " AND id <> ?"; $p[] = $id; }
        $stmt = $pdo->prepare($sql); $stmt->execute($p);
        if ((int)$stmt->fetchColumn() > 0) $errors[] = 'Email already exists.';
    }

    if (!$errors) {
        $scope = $roles[$form['role']]['scope'];
        if ($isEdit) {
            if ($password !== '') {
                $pdo->prepare("
                    UPDATE users SET username=?, email=?, full_name=?, role=?, gender_scope=?, phone=?,
                        is_active=?, password_hash=?
                    WHERE id=?
                ")->execute([
                    $form['username'], $form['email'], $form['full_name'], $form['role'], $scope,
                    $form['phone'] ?: null, $form['is_active'],
                    password_hash($password, PASSWORD_BCRYPT), $id,
                ]);
            } else {
                $pdo->prepare("
                    UPDATE users SET username=?, email=?, full_name=?, role=?, gender_scope=?, phone=?, is_active=?
                    WHERE id=?
                ")->execute([
                    $form['username'], $form['email'], $form['full_name'], $form['role'], $scope,
                    $form['phone'] ?: null, $form['is_active'], $id,
                ]);
            }
            audit_log($_SESSION['user_id'], null, 'staff_edit', "Edited staff #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Staff updated.'];
        } else {
            $pdo->prepare("
                INSERT INTO users (username, email, password_hash, full_name, role, gender_scope, phone, is_active)
                VALUES (?,?,?,?,?,?,?,?)
            ")->execute([
                $form['username'], $form['email'], password_hash($password, PASSWORD_BCRYPT),
                $form['full_name'], $form['role'], $scope, $form['phone'] ?: null, $form['is_active'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            audit_log($_SESSION['user_id'], null, 'staff_add', "Added staff #$newId");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Staff account created.'];
        }
        redirect(APP_BASE . '/user/staff.php');
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
                    <h4 class="mb-1"><i class="fas fa-<?= $isEdit?'edit':'user-plus' ?> text-primary me-2"></i><?= $isEdit?'Edit':'Add' ?> Staff</h4>
                    <p class="text-muted small mb-0"><?= $isEdit?'#'.$id.' · '.h($staff['full_name']):'Create a new staff account' ?></p>
                </div>
                <a href="staff.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-arrow-left me-1"></i>Back</a>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="card border-0 shadow-sm"><div class="card-body">
                <form method="post">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required value="<?= h($form['full_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?= h($form['email']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required value="<?= h($form['username']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Role <span class="text-danger">*</span></label>
                            <select name="role" class="form-select" required>
                                <?php foreach ($roles as $val => $meta): ?>
                                    <option value="<?= $val ?>" <?= $form['role']===$val?'selected':'' ?>>
                                        <?= h($meta['label']) ?><?= $meta['scope'] ? ' — '.h($meta['scope']).' wing' : ' — Both wings' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= h($form['phone']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">
                                Password <?= $isEdit ? '<small class="text-muted">(leave blank to keep current)</small>' : '<span class="text-danger">*</span>' ?>
                            </label>
                            <input type="password" name="password" class="form-control" <?= $isEdit?'':'required' ?>>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="password2" class="form-control">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $form['is_active']?'checked':'' ?>>
                                <label class="form-check-label" for="isActive">Active (can log in)</label>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <button class="btn btn-primary"><i class="fas fa-save me-1"></i><?= $isEdit?'Save Changes':'Create' ?></button>
                    <a href="staff.php" class="btn btn-outline-secondary">Cancel</a>
                </form>
            </div></div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>