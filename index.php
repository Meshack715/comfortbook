<?php
require_once __DIR__ . '/includes/shell.php';
// index.php — staff login
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(APP_BASE . '/user/dashboard.php');
}

$error   = '';
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']);

$showSuccessScreen = false;
$welcomeName       = '';
$welcomeRole       = '';
$dashboardUrl      = APP_BASE . '/user/dashboard.php';

// ---------- LOCKOUT STATE ----------
$lockedUntil = 0;                 // unix ts
$lockedIdent = trim($_POST['identifier'] ?? '');
if ($lockedIdent !== '') {
    $row = login_attempts($lockedIdent);
    if ($row['locked_until'] > time()) {
        $lockedUntil = (int)$row['locked_until'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$lockedUntil) {
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';

    if (!$identifier || !$password) {
        $error = 'Please enter both username/email and password.';
    } else {
        $mins = login_is_locked($identifier);
        if ($mins !== null) {
            // Lockout hit mid-request — flip state
            $lockedUntil = time() + ($mins * 60);
            $lockedIdent = $identifier;
        } else {
            $stmt = $pdo->prepare("
                SELECT id, username, email, password_hash, full_name, role, gender_scope, is_active
                FROM users WHERE username = ? OR email = ? LIMIT 1
            ");
            $stmt->execute([$identifier, $identifier]);
            $user = $stmt->fetch();

            if (!$user || !$user['is_active']) {
                $error = 'Invalid credentials or account disabled.';
                $row   = login_record_failure($identifier);
                if (!empty($row['locked_until']) && $row['locked_until'] > time()) {
                    $lockedUntil = (int)$row['locked_until'];
                    $lockedIdent = $identifier;
                    $error       = '';
                }
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Invalid credentials.';
                $row   = login_record_failure($identifier);
                if (!empty($row['locked_until']) && $row['locked_until'] > time()) {
                    $lockedUntil = (int)$row['locked_until'];
                    $lockedIdent = $identifier;
                    $error       = '';
                }
            } else {
                login_clear_attempts($identifier);
                set_user_session($user);
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                audit_log($user['id'], null, 'login', 'Successful login');

                $showSuccessScreen = true;
                $welcomeName       = $user['full_name'];
                $welcomeRole       = role_display();
            }
        }
    }
}

if (isset($_SESSION['login_error'])) {
    $error = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

$attempts_left = null;
if (!empty($_POST['identifier']) && !$showSuccessScreen && !$lockedUntil) {
    $row = login_attempts(trim($_POST['identifier']));
    if ($row['count'] > 0 && $row['count'] < MAX_LOGIN_ATTEMPTS) {
        $attempts_left = MAX_LOGIN_ATTEMPTS - $row['count'];
    }
}

$roleSummary = match($welcomeRole) {
    'Administrator' => 'Manage semesters, buildings, rooms, students and staff — you have full system access.',
    'Dean of Men', 'Dean of Women' => 'Set your wing\'s phase windows, send magic links, and review approvals for your wing.',
    'Secretary (Men)', 'Secretary (Women)' => 'Review pending approvals, resolve unplaced students, and manage rooms for your wing.',
    default => 'Welcome to ComfortBook.',
};
$roleIcon = match($welcomeRole) {
    'Administrator' => 'fa-user-shield',
    'Dean of Men', 'Dean of Women' => 'fa-user-tie',
    'Secretary (Men)', 'Secretary (Women)' => 'fa-clipboard-check',
    default => 'fa-user',
};

$lockMinutes = $lockedUntil ? (int)ceil(($lockedUntil - time()) / 60) : 0;
?>
<!doctype html>
<html lang="en" <?= shell_theme_attr() ?>>
<head>
    <?php shell_head_tags($showSuccessScreen ? 'Welcome' : ($lockedUntil ? 'Locked' : 'Login'), APP_BASE); ?>
    <link href="<?= APP_BASE ?>/assets/css/styles.css" rel="stylesheet">
    <link href="<?= APP_BASE ?>/assets/css/ueab-theme.css" rel="stylesheet">
</head>
<body class="bg-light">

<div class="container">
    <div class="row justify-content-center align-items-center min-vh-100">
        <div class="col-md-8 col-lg-6 col-xl-5">

            <?php if ($showSuccessScreen): ?>

            <!-- ============================================================
                 SUCCESS SCREEN
                 ============================================================ -->
            <div class="card shadow-lg border-0 overflow-hidden">
                <div class="card-body p-5 text-center">
                    <?php
                    $parts = preg_split('/\s+/', trim($welcomeName));
                    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . substr($parts[count($parts) - 1] ?? '', 0, 1));
                    ?>
                    <div class="mb-3">
                        <div class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center fw-bold fs-2 shadow" style="width:84px;height:84px"><?= h($initials ?: 'U') ?></div>
                    </div>
                    <h3 class="fw-bold mb-1">Welcome, <?= h($welcomeName) ?>!</h3>
                    <p class="text-muted small mb-4">
                        <i class="fas <?= h($roleIcon) ?> me-1"></i><?= h($welcomeRole) ?>
                    </p>
                    <div class="alert alert-light text-start small mb-4 border-start border-3 border-primary">
                        <i class="fas fa-circle-info text-primary me-1"></i>
                        <?= h($roleSummary) ?>
                    </div>
                    <div class="mb-2">
                        <div class="small text-muted">
                            Redirecting to your dashboard in
                            <strong id="countdown" class="text-primary">3</strong>
                            second<span id="plural">s</span>...
                        </div>
                    </div>
                    <div class="progress mb-4" style="height:6px"><div class="progress-bar bg-success" id="cdBar" style="width:100%"></div></div>
                    <a href="<?= h($dashboardUrl) ?>" class="btn btn-primary">
                        <i class="fas fa-arrow-right me-1"></i>Go Now
                    </a>
                </div>
                <div class="card-footer bg-white border-0 pb-4 text-center">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1 text-primary"></i>
                        Secure session started · Actions are logged
                    </small>
                </div>
            </div>

            <script>
                (function () {
                    let n = 3;
                    const el  = document.getElementById('countdown');
                    const plu = document.getElementById('plural');
                    const t = setInterval(() => {
                        n--;
                        if (n <= 0) {
                            clearInterval(t);
                            el.textContent = '0';
                            plu.textContent = '';
                            window.location = <?= json_encode($dashboardUrl) ?>;
                            return;
                        }
                        el.textContent = n;
                        plu.textContent = n === 1 ? '' : 's';
                        document.getElementById('cdBar').style.width = (n / 3 * 100) + '%';
                    }, 1000);
                })();
            </script>

            <?php elseif ($lockedUntil): ?>

            <!-- ============================================================
                 LOCKOUT SCREEN — form is fully hidden
                 ============================================================ -->
            <div class="card shadow-lg border-0 overflow-hidden">
                <div class="card-body p-5 text-center">
                    <div class="mb-4">
                        <div class="rounded-circle bg-danger text-white d-inline-flex align-items-center justify-content-center shadow" style="width:100px;height:100px">
                            <i class="fas fa-lock fa-3x"></i>
                        </div>
                    </div>

                    <h3 class="fw-bold mb-1">Account Temporarily Locked</h3>
                    <p class="text-muted small mb-4">
                        Too many failed sign-in attempts.
                        <?php if ($lockedIdent !== ''): ?>
                            <br>Identifier: <strong><?= h($lockedIdent) ?></strong>
                        <?php endif; ?>
                    </p>

                    <div class="mb-3">
                        <div class="small text-muted text-uppercase mb-2">Try again in</div>
                        <div class="display-5 fw-bold text-danger font-monospace" id="lockCount">--:--</div>
                    </div>

                    <div class="progress mb-4" style="height:8px">
                        <div id="lockBar" class="progress-bar bg-danger" style="width:100%;"></div>
                    </div>

                    <div class="alert alert-light small mb-4 border-start border-3 border-warning">
                        <i class="fas fa-circle-info text-warning me-1"></i>
                        For your security, sign-in has been paused for a short time.
                        Do not keep retrying — the lockout will only get longer.
                    </div>

                    <a href="<?= APP_BASE ?>/reset.php" class="btn btn-outline-primary btn-sm me-2">
                        <i class="fas fa-key me-1"></i>Reset Password
                    </a>
                    <button class="btn btn-outline-secondary btn-sm" id="retryBtn" disabled>
                        <i class="fas fa-redo me-1"></i>Try Again
                    </button>
                </div>
                <div class="card-footer bg-white border-0 pb-4 text-center">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1 text-primary"></i>
                        ComfortBook · UEAB Hostel Booking
                    </small>
                </div>
            </div>

            <script>
                (function () {
                    const until    = <?= (int)$lockedUntil ?> * 1000;   // ms
                    const start    = Date.now();
                    const totalMs  = until - start;
                    const el       = document.getElementById('lockCount');
                    const bar      = document.getElementById('lockBar');
                    const retryBtn = document.getElementById('retryBtn');
                    let lastSec    = -1;

                    function tick() {
                        const remaining = Math.max(0, until - Date.now());

                        // Format MM:SS
                        const totalSec = Math.ceil(remaining / 1000);
                        if (totalSec !== lastSec) {
                            lastSec = totalSec;
                            const m = Math.floor(totalSec / 60);
                            const s = totalSec % 60;
                            el.textContent =
                                String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
                            bar.style.width = ((remaining / totalMs) * 100) + '%';
                        }

                        if (remaining <= 0) {
                            el.textContent = '00:00';
                            el.classList.replace('text-danger','text-success');
                            bar.style.width = '0%';
                            retryBtn.disabled = false;
                            retryBtn.classList.remove('btn-outline-secondary');
                            retryBtn.classList.add('btn-success');
                            return;
                        }
                        requestAnimationFrame(tick);
                    }
                    requestAnimationFrame(tick);

                    retryBtn.addEventListener('click', () => {
                        window.location = '<?= APP_BASE ?>/index.php';
                    });
                })();
            </script>

            <?php else: ?>

            <!-- ============================================================
                 NORMAL LOGIN SCREEN
                 ============================================================ -->
            <div class="card shadow-lg border-0 mb-4 overflow-hidden">
                <div class="card-body p-5 text-center bg-primary">
                    <div class="mb-4">
                        <img src="<?= APP_BASE ?>/assets/logo/ueab.png"
                             alt="UEAB" class="rounded-circle border border-3 border-light shadow object-fit-cover" width="100" height="100">
                    </div>
                    <h2 class="text-white fw-bold mb-1 fs-5">
                        UNIVERSITY OF EASTERN AFRICA, BARATON
                    </h2>
                    <p class="text-white mb-0">ComfortBook — Hostel Booking System</p>
                    <div class="d-flex justify-content-center mt-3">
                        <div class="bg-warning rounded-pill" style="width:50px;height:3px"></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-lg border-0">
                <div class="card-body p-4">
                    <h4 class="text-center fw-bold mb-1">
                        <i class="fas fa-lock me-2 text-primary"></i>Staff Login
                    </h4>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-center">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <div>
                                <?= h($error) ?>
                                <?php if ($attempts_left !== null): ?>
                                    <div class="small mt-1">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> remaining.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php if ($success): ?>
                        <div class="alert alert-success d-flex align-items-center">
                            <i class="fas fa-check-circle me-2"></i><?= h($success) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" id="loginForm" novalidate>
                        <div class="form-floating mb-3">
                            <input type="text" class="form-control" id="identifier" name="identifier"
                                   placeholder="Username or email"
                                   value="<?= h($_POST['identifier'] ?? '') ?>" required autofocus>
                            <label for="identifier"><i class="fas fa-user text-primary me-2"></i>Username or Email</label>
                        </div>

                        <div class="form-floating mb-3 position-relative">
                            <input type="password" class="form-control" id="password" name="password"
                                   placeholder="Password" required>
                            <label for="password"><i class="fas fa-lock text-primary me-2"></i>Password</label>
                            <button type="button"
                                    class="btn btn-link text-body-secondary position-absolute end-0 top-50 translate-middle-y me-2 border-0"
                                    id="togglePassword" aria-label="Show or hide password">
                                <i class="fas fa-eye" id="toggleIcon"></i>
                            </button>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="remember" name="remember">
                                <label class="form-check-label small" for="remember">Remember me</label>
                            </div>
                            <a href="<?= APP_BASE ?>/reset.php" class="small text-decoration-none">
                                <i class="fas fa-key me-1"></i>Forgot password?
                            </a>
                        </div>

                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary btn-lg" id="loginBtn">
                                <span class="btn-label"><i class="fas fa-sign-in-alt me-2"></i>Sign In</span>
                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-2"></span>Signing in...</span>
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
                        <i class="fas fa-shield-alt me-1 text-primary"></i>
                        Secure University System · All actions are logged
                    </small><br>
                    <small class="text-muted">&copy; <?= date('Y') ?> University of Eastern Africa, Baraton</small>
                </div>
            </div>

            <?php endif; ?>

        </div>
    </div>
</div>

<script src="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script>
document.getElementById('togglePassword')?.addEventListener('click', function () {
    const p = document.getElementById('password');
    const i = document.getElementById('toggleIcon');
    const show = p.type === 'password';
    p.type = show ? 'text' : 'password';
    i.classList.toggle('fa-eye', !show);
    i.classList.toggle('fa-eye-slash', show);
});

document.getElementById('loginForm')?.addEventListener('submit', function () {
    const btn = document.getElementById('loginBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>
</body>
</html>