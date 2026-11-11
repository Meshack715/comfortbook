<?php
// includes/auth.php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('SESSION_TIMEOUT'))    define('SESSION_TIMEOUT', 1800);   // 30 min
if (!defined('MAX_LOGIN_ATTEMPTS')) define('MAX_LOGIN_ATTEMPTS', 5);   // per 15 min
if (!defined('LOCKOUT_SECONDS'))    define('LOCKOUT_SECONDS', 900);    // 15 min

function redirect(string $url): void {
    if (!headers_sent()) { header("Location: $url"); exit; }
    echo "<script>window.location.href=" . json_encode($url) . ";</script>"; exit;
}

function is_logged_in(): bool    { return !empty($_SESSION['user_id']); }
function user_role(): ?string    { return $_SESSION['role'] ?? null; }
function gender_scope(): ?string { return $_SESSION['gender_scope'] ?? null; }
function is_admin(): bool        { return user_role() === 'admin'; }
function is_dean(): bool         { return strpos(user_role() ?? '', 'dean') === 0; }
function is_secretary(): bool    { return strpos(user_role() ?? '', 'secretary') === 0; }

function require_login(): void {
    if (!is_logged_in()) {
        $_SESSION['login_error'] = 'Please login to continue.';
        redirect(APP_BASE . '/index.php');
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array(user_role(), $roles, true)) {
        http_response_code(403);
        ?>
        <!doctype html><html lang="en"><head><meta charset="utf-8"><title>403</title>
        <link href="<?= defined("APP_BASE") ? APP_BASE : "" ?>/assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"></head>
        <body class="bg-light"><div class="container py-5">
        <div class="card shadow-sm"><div class="card-body text-center p-5">
            <h2 class="text-danger">403 — Access Denied</h2>
            <p>Your role: <strong><?= h(user_role()) ?></strong></p>
            <p class="text-muted">You do not have permission for this page.</p>
            <a href="<?= APP_BASE ?>/user/dashboard.php" class="btn btn-primary">Back to Dashboard</a>
        </div></div></div></body></html>
        <?php
        exit;
    }
}

function set_user_session(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user_id']       = $u['id'];
    $_SESSION['username']      = $u['username'];
    $_SESSION['full_name']     = $u['full_name'];
    $_SESSION['role']          = $u['role'];
    $_SESSION['gender_scope']  = $u['gender_scope'] ?? null;
    $_SESSION['last_activity'] = time();
}

function logout(): void {
    if (!empty($_SESSION['user_id'])) {
        audit_log($_SESSION['user_id'], null, 'logout', 'User logged out');
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    session_destroy();
    redirect(APP_BASE . '/index.php');
}

// Session timeout
if (is_logged_in() && isset($_SESSION['last_activity'])) {
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        logout();
    }
    $_SESSION['last_activity'] = time();
}

function gender_filter(): ?string { return gender_scope(); }

function role_display(): string {
    return match(user_role()) {
        'admin'           => 'Administrator',
        'dean_men'        => 'Dean of Men',
        'dean_women'      => 'Dean of Women',
        'secretary_men'   => 'Secretary (Men)',
        'secretary_women' => 'Secretary (Women)',
        default           => 'User'
    };
}

// ==========================================================
// BRUTE-FORCE PROTECTION (session + IP + username key)
// ==========================================================
function login_key(string $identifier): string {
    return 'lfa_' . md5(strtolower(trim($identifier)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

function login_attempts(string $identifier): array {
    $k = login_key($identifier);
    $row = $_SESSION[$k] ?? ['count'=>0, 'first'=>0, 'locked_until'=>0];
    // Reset the window if it's older than LOCKOUT_SECONDS
    if ($row['first'] && (time() - $row['first'] > LOCKOUT_SECONDS)) {
        $row = ['count'=>0, 'first'=>0, 'locked_until'=>0];
        $_SESSION[$k] = $row;
    }
    return $row;
}

function login_is_locked(string $identifier): ?int {
    $row = login_attempts($identifier);
    if ($row['locked_until'] > time()) {
        return (int)ceil(($row['locked_until'] - time()) / 60); // minutes remaining
    }
    return null;
}

function login_record_failure(string $identifier): array {
    $k = login_key($identifier);
    $row = login_attempts($identifier);
    $row['count']++;
    if ($row['count'] === 1) $row['first'] = time();
    if ($row['count'] >= MAX_LOGIN_ATTEMPTS) {
        $row['locked_until'] = time() + LOCKOUT_SECONDS;
    }
    $_SESSION[$k] = $row;
    return $row;
}

function login_clear_attempts(string $identifier): void {
    unset($_SESSION[login_key($identifier)]);
}

// ==========================================================
// PASSWORD RESET HELPERS
// ==========================================================
function password_reset_create(int $userId): ?string {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return null;

    $raw  = bin2hex(random_bytes(32));
    $hash = password_hash($raw, PASSWORD_BCRYPT);
    $exp  = date('Y-m-d H:i:s', strtotime('+1 hour'));

    try {
        // Invalidate old unused tokens for this user
        $pdo->prepare("DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL")
            ->execute([$userId]);

        $pdo->prepare("
            INSERT INTO password_resets (user_id, token_hash, expires_at)
            VALUES (?, ?, ?)
        ")->execute([$userId, $hash, $exp]);

        return $raw;
    } catch (Throwable $e) {
        error_log('password_reset_create: ' . $e->getMessage());
        return null;
    }
}

function password_reset_find(string $raw): ?array {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo || $raw === '') return null;

    try {
        $stmt = $pdo->query("
            SELECT pr.*, u.full_name, u.username, u.email
            FROM password_resets pr
            JOIN users u ON u.id = pr.user_id
            WHERE pr.used_at IS NULL AND pr.expires_at > NOW()
            ORDER BY pr.created_at DESC
            LIMIT 30
        ");
        foreach ($stmt as $row) {
            if (password_verify($raw, $row['token_hash'])) {
                return $row;
            }
        }
    } catch (Throwable $e) {}
    return null;
}

function password_reset_mark_used(int $id): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return;
    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?")->execute([$id]);
}