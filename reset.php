<?php
// reset.php — request a password reset link
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/mailer.php';

if (is_logged_in()) redirect(APP_BASE . '/user/dashboard.php');

$step    = 'request';
$error   = '';
$success = '';
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = $pdo->prepare("SELECT id, full_name, username FROM users WHERE email = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            $raw  = password_reset_create((int)$user['id']);
            if ($raw) {
                $link = SITE_URL . '/set_password.php?token=' . $raw;
                $name = $user['full_name'] ?: $user['username'];

                $body = "
                    <p>Hello <strong>" . h($name) . "</strong>,</p>
                    <p>We received a request to reset your ComfortBook password.</p>
                    <p style='text-align:center;margin:24px 0;'>
                        <a href='{$link}' style='background:#003366;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600;display:inline-block;'>
                            Reset Password
                        </a>
                    </p>
                    <p style='font-size:13px;color:#856404;background:#fff3cd;padding:10px;border-left:3px solid #ffc107;'>
                        <strong>Important:</strong> This link expires in <strong>1 hour</strong>.
                        If you didn't request this, you can safely ignore this email.
                    </p>
                    <p style='font-size:12px;color:#666;'>If the button doesn't work, paste this URL into your browser:<br>
                    <a href='{$link}' style='word-break:break-all;color:#003366;'>{$link}</a></p>
                ";
                @send_mail($email, $name, "ComfortBook — Password Reset", email_template("Reset Your Password", $body));
                audit_log((int)$user['id'], null, 'password_reset_request', 'Requested reset');
            }
        }

        // Same response whether or not the email exists (prevents enumeration)
        $step    = 'sent';
        $success = 'If that email is registered, a reset link has been sent. Check your inbox (and spam).';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= SITE_NAME ?> — Reset Password</title>
    <link href="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
    <link href="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/fontawesome/css/all.min.css" rel="stylesheet">
    <link href="<?= APP_BASE ?>/assets/css/styles.css" rel="stylesheet">
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
                    <h4 class="text-center fw-bold mb-1">
                        <i class="fas fa-key me-2" style="color:#003366;"></i>Reset Password
                    </h4>
                    <p class="text-center text-muted small mb-4">Enter your email to receive a reset link</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-2"></i><?= h($error) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($step === 'sent'): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?= h($success) ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="<?= APP_BASE ?>/index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>Back to Login
                            </a>
                        </div>
                    <?php else: ?>
                        <form method="post" id="resetForm">
                            <div class="mb-3">
                                <label class="form-label fw-bold">
                                    <i class="fas fa-envelope me-2" style="color:#003366;"></i>Email Address
                                </label>
                                <input type="email" name="email" class="form-control form-control-lg"
                                       placeholder="you@ueab.ac.ke" value="<?= h($email) ?>" required autofocus>
                                <div class="form-text mt-1">
                                    <i class="fas fa-info-circle me-1 text-info"></i>
                                    We'll send a reset link valid for 1 hour.
                                </div>
                            </div>
                            <div class="d-grid gap-2">
                                <button type="submit" class="btn btn-primary btn-lg" id="resetBtn"
                                        style="background:#003366;border-color:#003366;">
                                    <span class="btn-label"><i class="fas fa-paper-plane me-2"></i>Send Reset Link</span>
                                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-2"></span>Sending...</span>
                                </button>
                                <a href="<?= APP_BASE ?>/index.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-1"></i>Back to Login
                                </a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('resetForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('resetBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>
</body>
</html>