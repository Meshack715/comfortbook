<?php
// index.php — staff login (root)
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → dashboard
if (is_logged_in()) {
    redirect(APP_BASE . '/user/dashboard.php');
}

$error = '';
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        $error = 'Please enter both username/email and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, full_name, role, gender_scope, is_active
            FROM users WHERE username = ? OR email = ? LIMIT 1
        ");
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || !$user['is_active']) {
            $error = 'Invalid credentials or account disabled.';
        } elseif (!password_verify($password, $user['password_hash'])) {
            $error = 'Invalid credentials.';
        } else {
            set_user_session($user);
            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
            audit_log($user['id'], null, 'login', 'Successful login');
            redirect(APP_BASE . '/user/dashboard.php');
        }
    }
}

if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= SITE_NAME ?> — Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="assets/css/ueab-theme.css" rel="stylesheet">
    <link href="assets/css/styles.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-8 col-lg-6 col-xl-5">

            <!-- University header -->
            <div class="card shadow-lg border-0 mb-4 overflow-hidden">
                <div class="card-body p-5 text-center"
                     style="background: linear-gradient(135deg,#003366 0%,#002244 100%);">
                    <div class="mb-4">
                        <img src="<?= APP_BASE ?>/assets/logo/ueab.png"
                             alt="UEAB" class="rounded-circle border border-3 border-light shadow"
                             style="width:100px;height:100px;object-fit:cover;">
                    </div>
                    <h2 class="text-white fw-bold mb-1" style="font-size:1.25rem;">
                        UNIVERSITY OF EASTERN AFRICA, BARATON
                    </h2>
                    <p class="text-white-50 mb-0">ComfortBook — Hostel Booking System</p>
                    <div class="d-flex justify-content-center mt-3">
                        <div style="width:50px;height:3px;background:#ffc107;border-radius:3px;"></div>
                    </div>
                </div>
            </div>

            <!-- Login card -->
            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <?= h($error) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success d-flex align-items-center">
                            <i class="fas fa-check-circle me-2"></i>
                            <?= h($success) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" novalidate>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="identifier" name="identifier"
                                   placeholder="Username or email"
                                   value="<?= h($_POST['identifier'] ?? '') ?>" required>
                            <label for="identifier"><i class="fas fa-user text-primary me-2"></i>Username or Email</label>
                        </div>

                        <div class="form-floating mb-3 position-relative">
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Password" required>
                            <label for="password"><i class="fas fa-lock text-primary me-2"></i>Password</label>
                            <button type="button"
                                    class="btn btn-outline-secondary position-absolute end-0 top-50 translate-middle-y me-2"
                                    style="border:none;background:transparent;z-index:10;"
                                    id="togglePassword">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>

                        <div class="d-grid gap-2 mt-4">
                            <button type="submit" class="btn btn-primary btn-lg"
                                    style="background:#003366;border-color:#003366;">
                                Sign In
                            </button>
                        </div>
                    </form>

                    <hr class="my-4">

                    <div class="text-center">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Students: check your email for your personalized booking link.
                        </small>
                    </div>
                </div>

                <div class="card-footer bg-white border-0 pb-4 text-center">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1" style="color:#003366;"></i>
                        Secure University System · All actions are logged
                    </small><br>
                    <small class="text-muted">&copy; <?= date('Y') ?> University of Eastern Africa, Baraton</small>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePassword').addEventListener('click', function () {
    const p = document.getElementById('password');
    const i = document.getElementById('toggleIcon');
    const show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    i.classList.toggle('fa-eye', !show);
    i.classList.toggle('fa-eye-slash', show);
});
</script>
</body>
</html>