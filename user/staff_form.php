<?php
// user/staff_form.php — add + edit
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['admin']);

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;
$staff  = null;
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $staff = $stmt->fetch();
    if (!$staff) { $_SESSION['flash']=['type'=>'danger','msg'=>'Staff not found.']; redirect(APP_BASE.'/user/staff.php'); }
}

$roles = [
    'admin'           => ['label'=>'Administrator',    'scope'=>null],
    'dean_men'        => ['label'=>'Dean of Men',      'scope'=>'Male'],
    'dean_women'      => ['label'=>'Dean of Women',    'scope'=>'Female'],
    'secretary_men'   => ['label'=>'Secretary (Men)',  'scope'=>'Male'],
    'secretary_women' => ['label'=>'Secretary (Women)','scope'=>'Female'],
];

// ---------- Password generator ----------
// "CB@ueab" + 4 random alphanumeric
function generate_staff_password(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no confusing 0/O, 1/I
    $suffix = '';
    for ($i = 0; $i < 4; $i++) {
        $suffix .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'CB@ueab' . $suffix;
}

// ---------- Email helpers ----------
function send_staff_welcome(string $to, string $name, string $username, string $roleLabel, string $password): bool {
    $loginUrl = SITE_URL . '/index.php';
    $body = "
      <p>Hello <strong>" . h($name) . "</strong>,</p>
      <p>Your ComfortBook staff account has been created. Use the credentials below to sign in.</p>
      <table cellpadding='6' style='border-collapse:collapse;font-size:14px;'>
        <tr><td style='color:#666;'>Username</td><td><strong>{$username}</strong></td></tr>
        <tr><td style='color:#666;'>Role</td><td><strong>{$roleLabel}</strong></td></tr>
        <tr><td style='color:#666;'>Password</td><td>
          <span style='background:#eef4fb;border-left:3px solid #003366;padding:6px 12px;font-family:monospace;font-size:15px;letter-spacing:1px;'>{$password}</span>
        </td></tr>
      </table>
      <p style='margin-top:20px;text-align:center;'>
        <a href='{$loginUrl}' style='background:#003366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
            Sign In to ComfortBook
        </a>
      </p>
      <p style='font-size:13px;color:#856404;background:#fff3cd;padding:10px;border-left:3px solid #ffc107;margin-top:16px;'>
        <strong>Important:</strong> Please change this password on first login via <strong>My Profile</strong>.
      </p>
    ";
    return send_mail($to, $name, "ComfortBook — Your Staff Account", email_template("Welcome to ComfortBook", $body));
}

function send_password_reset_email(string $to, string $name, string $password): bool {
    $loginUrl = SITE_URL . '/index.php';
    $body = "
      <p>Hello <strong>" . h($name) . "</strong>,</p>
      <p>Your ComfortBook password has been reset by an administrator.</p>
      <p style='text-align:center;margin:24px 0;'>
        <span style='background:#eef4fb;border-left:3px solid #003366;padding:10px 18px;font-family:monospace;font-size:18px;letter-spacing:2px;font-weight:700;'>{$password}</span>
      </p>
      <p>Sign in with this password:</p>
      <p style='text-align:center;'>
        <a href='{$loginUrl}' style='background:#003366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
            Sign In Now
        </a>
      </p>
      <p style='font-size:13px;color:#856404;background:#fff3cd;padding:10px;border-left:3px solid #ffc107;'>
        <strong>Important:</strong> Change this temporary password on your next login.
      </p>
    ";
    return send_mail($to, $name, "ComfortBook — Password Reset", email_template("Your Password Was Reset", $body));
}

// ---------- Form state ----------
$errors = [];
$form = $isEdit ? [
    'username'=>$staff['username'], 'email'=>$staff['email'],
    'full_name'=>$staff['full_name'], 'role'=>$staff['role'],
    'phone'=>$staff['phone'] ?? '', 'is_active'=>(int)$staff['is_active'],
] : ['username'=>'', 'email'=>'', 'full_name'=>'', 'role'=>'dean_men', 'phone'=>'', 'is_active'=>1];

// ---------- Handle POSTs ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    // ----- RESET PASSWORD (edit only) -----
    if ($action === 'reset_password' && $isEdit) {
        $newPassword = generate_staff_password();
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($newPassword, PASSWORD_BCRYPT), $id]);

        audit_log($_SESSION['user_id'], null, 'staff_password_reset', "Reset password for staff #$id");
        $ok = send_password_reset_email($staff['email'], $staff['full_name'], $newPassword);

        $_SESSION['flash'] = [
            'type' => $ok ? 'success' : 'warning',
            'msg'  => $ok
                ? "Password reset. Sent to {$staff['email']}."
                : "Password reset, but email failed. New password: $newPassword",
        ];
        redirect(APP_BASE . '/user/staff_form.php?id=' . $id);
    }

    // ----- SAVE (create or edit) -----
    foreach (['username','email','full_name','phone'] as $k) $form[$k] = trim($_POST[$k] ?? '');
    $form['role']      = $_POST['role'] ?? 'dean_men';
    $form['is_active'] = isset($_POST['is_active']) ? 1 : 0;

    if ($form['username'] === '') $errors[] = 'Username is required.';
    if ($form['full_name'] === '') $errors[] = 'Full name is required.';
    if ($form['email'] === '' || !filter_var($form['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';
    if (!array_key_exists($form['role'], $roles)) $errors[] = 'Invalid role.';

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
            $pdo->prepare("
                UPDATE users SET username=?, email=?, full_name=?, role=?, gender_scope=?, phone=?, is_active=?
                WHERE id=?
            ")->execute([
                $form['username'], $form['email'], $form['full_name'], $form['role'], $scope,
                $form['phone'] ?: null, $form['is_active'], $id,
            ]);
            audit_log($_SESSION['user_id'], null, 'staff_edit', "Edited staff #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Staff updated.'];
            redirect(APP_BASE . '/user/staff.php');
        } else {
            // CREATE — auto-generate password and email it
            $plainPassword = generate_staff_password();

            $pdo->prepare("
                INSERT INTO users (username, email, password_hash, full_name, role, gender_scope, phone, is_active, status)
                VALUES (?,?,?,?,?,?,?,?,'active')
            ")->execute([
                $form['username'], $form['email'], password_hash($plainPassword, PASSWORD_BCRYPT),
                $form['full_name'], $form['role'], $scope, $form['phone'] ?: null, $form['is_active'],
            ]);
            $newId = (int)$pdo->lastInsertId();

            audit_log($_SESSION['user_id'], null, 'staff_add', "Added staff #$newId (password emailed)");

            $roleLabel = $roles[$form['role']]['label'];
            $ok = send_staff_welcome($form['email'], $form['full_name'], $form['username'], $roleLabel, $plainPassword);

            $_SESSION['flash'] = [
                'type' => $ok ? 'success' : 'warning',
                'msg'  => $ok
                    ? "Staff account created. Password sent to {$form['email']}."
                    : "Staff account created, but email failed. Password: $plainPassword",
            ];
            redirect(APP_BASE . '/user/staff.php');
        }
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
                        <i class="fas fa-<?= $isEdit?'edit':'user-plus' ?> text-primary me-2"></i>
                        <?= $isEdit?'Edit':'Add' ?> Staff
                    </h4>
                    <p class="text-muted small mb-0">
                        <?= $isEdit ? '#'.$id.' · '.h($staff['full_name']) : 'Create a new staff account · password auto-generated' ?>
                    </p>
                </div>
                <a href="staff.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Back
                </a>
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
                </div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm"><div class="card-body">
                        <form method="post" id="staffForm">
                            <input type="hidden" name="action" value="save">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                    <input type="text" name="full_name" class="form-control" required value="<?= h($form['full_name']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Email <span class="text-danger">*</span></label>
                                    <input type="email" name="email" class="form-control" required value="<?= h($form['email']) ?>">
                                    <?php if (!$isEdit): ?>
                                        <small class="text-muted">Credentials will be sent here.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" name="username" class="form-control" required value="<?= h($form['username']) ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Role <span class="text-danger">*</span></label>
                                    <select name="role" class="form-select" required>
                                        <?php foreach ($roles as $val => $meta): ?>
                                            <option value="<?= $val ?>" <?= $form['role']===$val?'selected':'' ?>>
                                                <?= h($meta['label']) ?><?= $meta['scope'] ? ' — '.h($meta['scope']).' wing' : ' — Both wings' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control" value="<?= h($form['phone']) ?>">
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="is_active" id="isActive" <?= $form['is_active']?'checked':'' ?>>
                                        <label class="form-check-label" for="isActive">Active (can log in)</label>
                                    </div>
                                </div>
                            </div>

                            <?php if (!$isEdit): ?>
                            <div class="alert alert-info small mt-3 mb-0">
                                <i class="fas fa-magic me-1"></i>
                                <strong>Password is auto-generated</strong> in the format <code>CB@ueabXXXX</code> and emailed to the staff member on save.
                            </div>
                            <?php endif; ?>

                            <hr>
                            <button class="btn btn-primary" id="saveBtn" type="submit">
                                <span class="btn-label">
                                    <i class="fas fa-save me-1"></i><?= $isEdit?'Save Changes':'Create & Send Email' ?>
                                </span>
                                <span class="btn-spin d-none">
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    <?= $isEdit ? 'Saving...' : 'Creating & sending email...' ?>
                                </span>
                            </button>
                            <a href="staff.php" class="btn btn-outline-secondary">Cancel</a>
                        </form>
                    </div></div>

                    <?php if ($isEdit): ?>
                    <!-- Reset password card -->
                    <div class="card border-0 shadow-sm mt-3">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-3">
                                <div class="stat-icon bg-warning-subtle text-warning flex-shrink-0">
                                    <i class="fas fa-key"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <h6 class="fw-bold mb-1">Reset Password</h6>
                                    <p class="small text-muted mb-3">
                                        Generates a new password in the format <code>CB@ueabXXXX</code>
                                        and emails it to <strong><?= h($staff['email']) ?></strong>.
                                        The staff member must change it on next login.
                                    </p>
                                    <form method="post" id="resetForm">
                                        <input type="hidden" name="action" value="reset_password">
                                        <button class="btn btn-outline-warning btn-sm" id="resetBtn">
                                            <span class="btn-label">
                                                <i class="fas fa-redo me-1"></i>Reset & Email Password
                                            </span>
                                            <span class="btn-spin d-none">
                                                <span class="spinner-border spinner-border-sm me-2"></span>
                                                Sending to <?= h($staff['email']) ?>...
                                            </span>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="col-lg-4">
                    <?php if (!$isEdit): ?>
                    <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                        <div class="card-header bg-white">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-envelope text-primary me-2"></i>What happens on save
                            </h6>
                        </div>
                        <div class="card-body small">
                            <ol class="ps-3 mb-0" style="line-height:1.9;">
                                <li>Account is created</li>
                                <li>Password auto-generated as <code class="text-nowrap">CB@ueabXXXX</code></li>
                                <li>Branded welcome email sent to their inbox</li>
                                <li>They sign in and can change password from <em>My Profile</em></li>
                            </ol>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                        <div class="card-header bg-white">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-info-circle text-primary me-2"></i>Account Info
                            </h6>
                        </div>
                        <div class="card-body small">
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Role</span>
                                <strong><?= h($roles[$staff['role']]['label'] ?? $staff['role']) ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Status</span>
                                <strong><?= $staff['is_active'] ? 'Active' : 'Inactive' ?></strong>
                            </div>
                            <div class="d-flex justify-content-between mb-1">
                                <span class="text-muted">Last login</span>
                                <strong><?= $staff['last_login'] ? date('M j, Y', strtotime($staff['last_login'])) : '—' ?></strong>
                            </div>
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Created</span>
                                <strong><?= date('M j, Y', strtotime($staff['created_at'])) ?></strong>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// Spinner on save
document.getElementById('staffForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('saveBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// Spinner on reset password
document.getElementById('resetForm')?.addEventListener('submit', (e) => {
    if (!confirm('Generate a new password and email it?')) {
        e.preventDefault();
        return;
    }
    const btn = document.getElementById('resetBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>