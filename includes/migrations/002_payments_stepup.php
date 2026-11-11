<?php
// 002: M-Pesa STK push payments + step-up verification for sensitive assistant actions.
require_once __DIR__ . '/001_immersive.php';
function cb_migrate_002(PDO $pdo): array {
    $log = [];
    $pdo->exec("CREATE TABLE IF NOT EXISTS mpesa_payments (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id INT UNSIGNED NOT NULL, student_id INT UNSIGNED NOT NULL,
        phone VARCHAR(15) NOT NULL, amount INT UNSIGNED NOT NULL, checkout_request_id VARCHAR(80) DEFAULT NULL, merchant_request_id VARCHAR(80) DEFAULT NULL,
        status ENUM('pending','success','failed','cancelled') NOT NULL DEFAULT 'pending', receipt VARCHAR(20) DEFAULT NULL, result_desc VARCHAR(255) DEFAULT NULL,
        env VARCHAR(10) NOT NULL DEFAULT 'sandbox', created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, completed_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_checkout (checkout_request_id), KEY idx_booking (booking_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    cb_add_col($pdo, 'ai_actions', 'step_hash', "VARCHAR(64) DEFAULT NULL", $log);
    cb_add_col($pdo, 'ai_actions', 'step_expires', "DATETIME DEFAULT NULL", $log);
    cb_add_col($pdo, 'ai_actions', 'step_tries', "TINYINT UNSIGNED NOT NULL DEFAULT 0", $log);
    foreach (['mpesa_env' => 'demo', 'mpesa_consumer_key' => '', 'mpesa_consumer_secret' => '', 'mpesa_shortcode' => '174379', 'mpesa_passkey' => '', 'mpesa_callback_secret' => bin2hex(random_bytes(16))] as $k => $v)
        $pdo->prepare("INSERT IGNORE INTO config (config_key, config_value) VALUES (?,?)")->execute([$k, $v]);
    try { $pdo->exec("ALTER TABLE ai_key_state MODIFY key_idx BIGINT UNSIGNED NOT NULL"); } catch (Throwable $e) {}
    $pdo->exec("ALTER TABLE config MODIFY config_value TEXT NOT NULL");   // long API key lists no longer get cut at 255 characters
    $log[] = 'payments + step-up ready';
    return $log;
}
