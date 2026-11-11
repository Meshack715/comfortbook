<?php
// 004: payments hardening — every way a payment can end is stored; staff can collect fees; test payments; callback log.
require_once __DIR__ . '/002_payments_stepup.php';
function cb_migrate_004(PDO $pdo): array {
    $log = [];
    cb_migrate_002($pdo);   // make sure the base table exists (safe to repeat)
    // a payment no longer has to belong to a booking (a student can pay the fee before booking; admin can run a test)
    try { $pdo->exec("ALTER TABLE mpesa_payments MODIFY booking_id INT UNSIGNED NULL, MODIFY student_id INT UNSIGNED NULL"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE mpesa_payments MODIFY status ENUM('pending','success','failed','cancelled','timeout','review') NOT NULL DEFAULT 'pending'"); } catch (Throwable $e) {}
    cb_add_col($pdo, 'mpesa_payments', 'purpose', "VARCHAR(12) NOT NULL DEFAULT 'late_fee'", $log);
    cb_add_col($pdo, 'mpesa_payments', 'semester_id', "INT UNSIGNED DEFAULT NULL", $log);
    cb_add_col($pdo, 'mpesa_payments', 'via', "VARCHAR(12) NOT NULL DEFAULT 'student'", $log);
    cb_add_col($pdo, 'mpesa_payments', 'initiated_by_user_id', "INT UNSIGNED DEFAULT NULL", $log);
    cb_add_col($pdo, 'mpesa_payments', 'result_code', "VARCHAR(12) DEFAULT NULL", $log);
    cb_add_col($pdo, 'mpesa_payments', 'result_raw', "VARCHAR(255) DEFAULT NULL", $log);
    cb_add_col($pdo, 'mpesa_payments', 'confirmed_via', "VARCHAR(10) DEFAULT NULL", $log);
    cb_add_col($pdo, 'mpesa_payments', 'needs_review', "TINYINT(1) NOT NULL DEFAULT 0", $log);
    cb_add_col($pdo, 'mpesa_payments', 'review_note', "VARCHAR(255) DEFAULT NULL", $log);
    cb_add_col($pdo, 'mpesa_payments', 'last_check_at', "DATETIME DEFAULT NULL", $log);
    try { $pdo->exec("ALTER TABLE mpesa_payments ADD KEY idx_student (student_id, id)"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE mpesa_payments ADD KEY idx_review (needs_review, status)"); } catch (Throwable $e) {}
    // the fee a student has already paid for a semester (so they are never charged twice)
    cb_add_col($pdo, 'students', 'late_fee_paid_sem', "INT UNSIGNED DEFAULT NULL", $log);
    cb_add_col($pdo, 'students', 'late_fee_paid_at', "DATETIME DEFAULT NULL", $log);
    // every message Safaricom sends us, kept so a "missing" payment can always be traced
    $pdo->exec("CREATE TABLE IF NOT EXISTS mpesa_callbacks (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        checkout_request_id VARCHAR(80) DEFAULT NULL, result_code VARCHAR(12) DEFAULT NULL, matched TINYINT(1) NOT NULL DEFAULT 0, payload TEXT,
        KEY idx_checkout (checkout_request_id), KEY idx_time (received_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    foreach (['mpesa_demo_outcome' => 'success', 'mpesa_wait_seconds' => '150'] as $k => $v)
        $pdo->prepare("INSERT IGNORE INTO config (config_key, config_value) VALUES (?,?)")->execute([$k, $v]);
    $log[] = 'payments hardening ready';
    return $log;
}
