<?php
// user/profile.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$uid   = (int)$_SESSION['user_id'];
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$errors = [];

// Load current user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$me = $stmt->fetch();
if (!$me) { logout(); }

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $me['password_hash'])) $errors[] = 'Current password is incorrect.';
    if (strlen($new) < 6)  $errors[] = 'New password must be at least 6 characters.';
    if ($new !== $confirm) $errors[] = 'New passwords do not match.';

    if (!$errors) {
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($new, PASSWORD_BCRYPT), $uid]);
        audit_log($uid, null, 'password_change', 'User changed password');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Password updated.'];
        redirect(APP_BASE . '/user/profile.php');
    }
}

// Handle profile edit (email/phone/full_name)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_profile') {
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');

    if ($fullName === '') $errors[] = 'Full name required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email required.';

    if (!$errors) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?");
        $stmt->execute([$email, $uid]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'Email already in use by another account.';
        } else {
            $pdo->prepare("UPDATE users SET full_name=?, email=?, phone=? WHERE id=?")
                ->execute([$fullName, $email, $phone ?: null, $uid]);
            $_SESSION['full_name'] = $fullName;
            audit_log($uid, null, 'profile_edit', 'User updated profile');
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Profile updated.'];
            redirect(APP_BASE . '/user/profile.php');
        }
    }
    // Reload after error
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $me = $stmt->fetch();
}

// Recent activity
$stmt = $pdo->prepare("
    SELECT action, details, created_at FROM audit_log
    WHERE user_id = ? ORDER BY created_at DESC LIMIT 10
");
$stmt->execute([$uid]);
$activity = $stmt->fetchAll();
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="mt-4 mb-3">
                <h4 class="mb-1"><i class="fas fa-id-badge text-primary me-2"></i>My Profile</h4>
                <p class="text-muted small mb-0">Manage your account information</p>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
            <?php endif; ?>

            <div class="row g-3">
                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body text-center">
                            <div class="mb-3">
                                <div class="d-inline-flex align-items-center justify-content-center rounded-circle"
                                     style="width:88px;height:88px;background:linear-gradient(135deg,#003366,#002244);color:#fff;font-size:2rem;">
                                    <i class="fas fa-user"></i>
                                </div>
                            </div>
                            <h5 class="fw-bold mb-1"><?= h($me['full_name']) ?></h5>
                            <p class="text-muted small mb-2">@<?= h($me['username']) ?></p>
                            <span class="badge bg-primary"><?= h(role_display()) ?></span>
                            <?php if ($me['gender_scope']): ?>
                                <span class="badge bg-<?= $me['gender_scope']==='Male'?'primary':'danger' ?>">
                                    <?= h($me['gender_scope']) ?> Wing
                                </span>
                            <?php else: ?>
                                <span class="badge bg-dark">Both Wings</span>
                            <?php endif; ?>

                            <hr>
                            <div class="text-start small">
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="text-muted">Email</span>
                                    <span><?= h($me['email']) ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="text-muted">Phone</span>
                                    <span><?= h($me['phone'] ?: '—') ?></span>
                                </div>
                                <div class="d-flex justify-content-between mb-1">
                                    <span class="text-muted">Last login</span>
                                    <span><?= $me['last_login'] ? date('M j, H:i', strtotime($me['last_login'])) : '—' ?></span>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <span class="text-muted">Member since</span>
                                    <span><?= date('M j, Y', strtotime($me['created_at'])) ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <!-- Edit profile -->
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-user-edit text-primary me-2"></i>Edit Profile</h6></div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="edit_profile">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Full Name</label>
                                        <input type="text" name="full_name" class="form-control" required value="<?= h($me['full_name']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Username</label>
                                        <input type="text" class="form-control" value="<?= h($me['username']) ?>" disabled>
                                        <small class="text-muted">Contact admin to change.</small>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Email</label>
                                        <input type="email" name="email" class="form-control" required value="<?= h($me['email']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Phone</label>
                                        <input type="text" name="phone" class="form-control" value="<?= h($me['phone']) ?>">
                                    </div>
                                </div>
                                <hr>
                                <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Profile</button>
                            </form>
                        </div>
                    </div>

                    <!-- Change password -->
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-lock text-primary me-2"></i>Change Password</h6></div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="change_password">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Current Password</label>
                                        <input type="password" name="current_password" class="form-control" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">New Password</label>
                                        <input type="password" name="new_password" class="form-control" required minlength="6">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Confirm New</label>
                                        <input type="password" name="confirm_password" class="form-control" required>
                                    </div>
                                </div>
                                <hr>
                                <button class="btn btn-warning"><i class="fas fa-key me-1"></i>Change Password</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent activity -->
            <?php if (!empty($activity)): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-history text-primary me-2"></i>My Recent Activity</h6></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light"><tr><th>Action</th><th>Details</th><th>When</th></tr></thead>
                            <tbody>
                            <?php foreach ($activity as $a): ?>
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