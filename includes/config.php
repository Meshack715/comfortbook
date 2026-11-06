<?php
// includes/config.php
if (session_status() === PHP_SESSION_NONE) session_start();
date_default_timezone_set('Africa/Nairobi');

// ---- DATABASE ----
$DB_HOST = '127.0.0.1';
$DB_NAME = 'comfortbook_db';
$DB_USER = 'root';
$DB_PASS = '';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
];

try {
    $pdo = new PDO("mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4", $DB_USER, $DB_PASS, $options);
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ---- SMTP (Brevo) ----
define('SMTP_HOST',       'smtp-relay.brevo.com');
define('SMTP_USERNAME',   '97b8dc001@smtp-brevo.com');
define('SMTP_PASSWORD',   'TjpsE6mtCfkJ9d5I');
define('SMTP_PORT',       587);
define('SMTP_FROM_EMAIL', 'pantherzulu7@gmail.com');
define('SMTP_FROM_NAME',  'ComfortBook — UEAB Hostel');

// ---- SYSTEM ----
define('SITE_NAME', 'ComfortBook');

// ==========================================================
// ROOT PATHS
// ==========================================================
// Filesystem root — use for require, file_exists, etc.
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__));
}

// URL prefix for project root — "/comfortbook" locally, "" on live root
// Works whether the current script is in /, /user/, /api/, /student/, /includes/
if (!defined('APP_BASE')) {
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir    = str_replace('\\', '/', dirname($script));

    if (preg_match('#/(user|api|student|includes)(/|$)#', $dir)) {
        $dir = preg_replace('#/(user|api|student|includes)$#', '', $dir);
    }

    if ($dir === '/' || $dir === '\\' || $dir === '.') $dir = '';

    define('APP_BASE', rtrim($dir, '/'));
}

// Full site URL — useful for emails, magic links
if (!defined('SITE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    define('SITE_URL', $scheme . '://' . $host . APP_BASE);
}

// ==========================================================
// CONFIG HELPERS
// ==========================================================
function get_config(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $pdo = $GLOBALS['pdo'] ?? null;
        if ($pdo) {
            try {
                foreach ($pdo->query("SELECT config_key, config_value FROM config") as $r) {
                    $cache[$r['config_key']] = $r['config_value'];
                }
            } catch (Throwable $e) {}
        }
    }
    return $cache[$key] ?? $default;
}

function set_config(string $key, string $value): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return;
    $pdo->prepare("INSERT INTO config (config_key, config_value) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)")
        ->execute([$key, $value]);
}

// ==========================================================
// SEMESTER HELPERS
// ==========================================================
function active_semester(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;

    $sid = (int)get_config('active_semester_id', 0);
    if ($sid <= 0) { $cache = null; return null; }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) { $cache = null; return null; }

    try {
        $stmt = $pdo->prepare("SELECT * FROM semesters WHERE id = ? LIMIT 1");
        $stmt->execute([$sid]);
        $cache = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $cache = null;
    }
    return $cache;
}

function active_semester_id(): ?int {
    $s = active_semester();
    return $s ? (int)$s['id'] : null;
}

function semester_window(int $semesterId, string $gender): ?array {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return null;
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM semester_windows
            WHERE semester_id = ? AND gender = ?
            LIMIT 1
        ");
        $stmt->execute([$semesterId, $gender]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ==========================================================
// PHASE — per-wing, derived from active semester's window
// ==========================================================
function current_phase(?string $gender = null): ?int {
    $sem = active_semester();
    if (!$sem) return null;

    if ($gender === null) {
        $m = current_phase('Male');
        $f = current_phase('Female');
        if ($m === 2 || $f === 2) return 2;
        if ($m === 1 || $f === 1) return 1;
        return null;
    }

    if (!in_array($gender, ['Male','Female'], true)) return null;

    $w = semester_window((int)$sem['id'], $gender);
    if (!$w) return null;

    $now = date('Y-m-d H:i:s');
    if ($w['phase1_start'] && $w['phase1_end'] && $now >= $w['phase1_start'] && $now <= $w['phase1_end']) return 1;
    if ($w['phase2_start'] && $w['phase2_end'] && $now >= $w['phase2_start'] && $now <= $w['phase2_end']) return 2;
    return null;
}

// ==========================================================
// GENERAL HELPERS
// ==========================================================
function h($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function audit_log(?int $user_id, ?int $student_id, string $action, string $details = ''): void {
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo) return;
    try {
        $pdo->prepare("
            INSERT INTO audit_log (user_id, student_id, semester_id, action, details, ip, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $user_id,
            $student_id,
            function_exists('active_semester_id') ? active_semester_id() : null,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {}
}