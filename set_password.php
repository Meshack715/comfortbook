<?php
// set_password.php — set new password using reset token
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) redirect(APP_BASE . '/user/dashboard.php');

$raw   = $_GET['token'] ?? $_POST['token'] ?? '';
$reset = $raw !== '' ? password_reset_find($raw) : null;

$error = '';
$done  = false;

// Invalid token
if (!$reset) {
    ?>
    <!doctype html><html><head><meta charset="utf-8"><title>Invalid Link</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    </head><body class="bg-light"><div class="container py-5">
    <div class="card shadow-sm border-0" style="max-width:520px;margin:auto;">
      <div class="card-body text-center p-5">
        <i class="fas fa-link-slash fa-3x text-danger mb-3"></i>
        <h3>Invalid or Expired Link</h3>
        <p class="text-muted">This password reset link has expired or is invalid.</p>
        <a href="<?= APP_BASE ?>/reset.php" class="btn btn-primary">Request a New Link</a>
        <a href="<?= APP_BASE ?>/index.php" class="btn btn-outline-secondary">Back to Login</a>
      </div>
    </div></div></body></html>
    <?php
    exit;
}

// Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                ->execute([password_hash($new, PASSWORD_BCRYPT), (int)$reset['user_id']]);
            password_reset_mark_used((int)$reset['id']);
            audit_log((int)$reset['user_id'], null, 'password_reset_done', 'Password reset completed');
            $done = true;
        } catch (Throwable $e) {
            $error = 'Could not update password. Try again.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= SITE_NAME ?> — Set Password</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="<?= APP_BASE ?>/assets/css/ueab-theme.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-8 col-lg-6 col-xl-5">

            <div class="card shadow-lg border-0 mb-4 overflow-hidden">
                <div class="card-body p-5 text-center" style="background: linear-gradient(135deg,#003366 0%,#002244 100%);">
                    <div class="mb-4">
                        <img src="<?= APP_BASE ?>/assets/logo/ueab.png" alt="UEAB"
                             class="rounded-circle border border-3 border-light shadow"
                             style="width:100px;height:100px;object-fit:cover;">
                    </div>
                    <h2 class="text-white fw-bold mb-1" style="font-size:1.25rem;">UNIVERSITY OF EASTERN AFRICA, BARATON</h2>
                    <p class="text-white-50 mb-0">ComfortBook — Hostel Booking System</p>
                    <div class="d-flex justify-content-center mt-3">
                        <div style="width:50px;height:3px;background:#ffc107;border-radius:3px;"></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-lg border-0">
                <div class="card-body p-4">

                    <?php if ($done): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                            <h4 class="fw-bold">Password Updated</h4>
                            <p class="text-muted mb-4">You can now sign in with your new password.</p>
                            <a href="<?= APP_BASE ?>/index.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-1"></i>Sign In
                            </a>
                        </div>
                    <?php else: ?>
                        <h4 class="text-center fw-bold mb-1">
                            <i class="fas fa-lock me-2" style="color:#003366;"></i>Set New Password
                        </h4>
                        <p class="text-center text-muted small mb-4">
                            For <strong><?= h($reset['full_name'] ?: $reset['username']) ?></strong>
                        </p>

                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-center">
                                <i class="fas fa-exclamation-circle me-2"></i><?= h($error) ?>
                            </div>
                        <?php endif; ?>

                        <form method="post" id="setForm">
                            <input type="hidden" name="token" value="<?= h($raw) ?>">

                            <div class="form-floating mb-3">
                                <input type="password" class="form-control" id="new_password" name="new_password"
                                       placeholder="New password" required minlength="6" autofocus>
                                <label for="new_password"><i class="fas fa-lock text-primary me-2"></i>New Password</label>
                            </div>

                            <div class="form-floating mb-3">
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password"
                                       placeholder="Confirm password" required>
                                <label for="confirm_password"><i class="fas fa-lock text-primary me-2"></i>Confirm Password</label>
                            </div>

                            <div class="small text-muted mb-3">
                                <i class="fas fa-info-circle me-1"></i>Minimum 6 characters.
                            </div>

                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg" id="setBtn"
                                        style="background:#003366;border-color:#003366;">
                                    <span class="btn-label"><i class="fas fa-save me-2"></i>Update Password</span>
                                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-2"></span>Updating...</span>
                                </button>
                                <a href="<?= APP_BASE ?>/index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Cancel
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('setForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('setBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>
</body>
</html>