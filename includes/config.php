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
define('SITE_URL',  'http://localhost/comfortbook');   // change on live

// ---- APP BASE ----
if (!defined('APP_BASE')) {
    define('APP_BASE', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'));
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

function current_phase(): ?int {
    $now = date('Y-m-d H:i:s');
    if ($now >= get_config('phase1_start') && $now <= get_config('phase1_end')) return 1;
    if ($now >= get_config('phase2_start') && $now <= get_config('phase2_end')) return 2;
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
        $pdo->prepare("INSERT INTO audit_log (user_id, student_id, action, details, ip, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([
                $user_id, $student_id, $action, $details,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
    } catch (Throwable $e) {}
}