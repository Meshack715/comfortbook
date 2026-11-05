<?php
// includes/auth.php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) session_start();

if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', 1800); // 30 min

function redirect(string $url): void {
    if (!headers_sent()) { header("Location: $url"); exit; }
    echo "<script>window.location.href=" . json_encode($url) . ";</script>"; exit;
}

function is_logged_in(): bool { return !empty($_SESSION['user_id']); }
function user_role(): ?string  { return $_SESSION['role'] ?? null; }
function gender_scope(): ?string { return $_SESSION['gender_scope'] ?? null; }
function is_admin(): bool      { return user_role() === 'admin'; }
function is_dean(): bool       { return strpos(user_role() ?? '', 'dean') === 0; }
function is_secretary(): bool  { return strpos(user_role() ?? '', 'secretary') === 0; }

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
        <!doctype html><html><head><meta charset="utf-8"><title>403</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet"></head>
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

// Gender filter for queries — null = admin (both), 'Male'/'Female' = scoped
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