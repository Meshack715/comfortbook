<?php
// includes/migrations/001_immersive.php — idempotent upgrade: beds, layouts, push, notifications, AI.
// Run:  php bin/migrate.php      (or sign in as admin and open /user/upgrade.php)
require_once __DIR__ . '/../beds.php';
require_once __DIR__ . '/../layout_presets.php';

function cb_col_exists(PDO $pdo, string $t, string $c): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?");
    $q->execute([$t, $c]); return (bool)$q->fetchColumn();
}
function cb_add_col(PDO $pdo, string $t, string $c, string $ddl, array &$log): void {
    if (!cb_col_exists($pdo, $t, $c)) { $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $ddl"); $log[] = "added $t.$c"; }
}

function cb_migrate_001(PDO $pdo): array {
    $log = [];
    // ---- room geometry ----
    cb_add_col($pdo, 'rooms', 'floor_no', "TINYINT UNSIGNED NOT NULL DEFAULT 1", $log);
    cb_add_col($pdo, 'rooms', 'wing_key', "VARCHAR(12) DEFAULT NULL", $log);
    cb_add_col($pdo, 'rooms', 'side', "ENUM('A','B') NOT NULL DEFAULT 'A'", $log);
    cb_add_col($pdo, 'rooms', 'slot', "SMALLINT NOT NULL DEFAULT 0", $log);
    cb_add_col($pdo, 'rooms', 'width', "DECIMAL(4,1) NOT NULL DEFAULT 4.0", $log);
    cb_add_col($pdo, 'rooms', 'depth', "DECIMAL(4,1) NOT NULL DEFAULT 5.0", $log);
    cb_add_col($pdo, 'rooms', 'bunks', "TINYINT UNSIGNED NOT NULL DEFAULT 0", $log);
    cb_add_col($pdo, 'rooms', 'singles', "TINYINT UNSIGNED NOT NULL DEFAULT 0", $log);
    cb_add_col($pdo, 'rooms', 'bath_type', "ENUM('none','ensuite') NOT NULL DEFAULT 'none'", $log);
    cb_add_col($pdo, 'blocks', 'layout_json', "LONGTEXT DEFAULT NULL", $log);
    cb_add_col($pdo, 'blocks', 'floors', "TINYINT UNSIGNED NOT NULL DEFAULT 3", $log);

    // ---- beds ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS beds (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        room_id INT UNSIGNED NOT NULL,
        label VARCHAR(8) NOT NULL,
        kind ENUM('lower','upper','single') NOT NULL DEFAULT 'single',
        bunk_no TINYINT UNSIGNED DEFAULT NULL,
        pos_x DECIMAL(5,2) DEFAULT NULL, pos_z DECIMAL(5,2) DEFAULT NULL, rot SMALLINT DEFAULT NULL,
        status ENUM('vacant','available_p2','reserved','occupied') NOT NULL DEFAULT 'vacant',
        prev_status VARCHAR(16) DEFAULT NULL,
        student_id INT UNSIGNED DEFAULT NULL,
        reserved_by_student_id INT UNSIGNED DEFAULT NULL,
        reserved_at DATETIME DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_room_label (room_id, label),
        KEY idx_status (status), KEY idx_student (student_id),
        CONSTRAINT fk_beds_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    cb_add_col($pdo, 'students', 'current_bed_id', "INT UNSIGNED DEFAULT NULL", $log);
    cb_add_col($pdo, 'bookings', 'bed_id', "INT UNSIGNED DEFAULT NULL", $log);

    // ---- push + notifications + AI ----
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL,
        endpoint VARCHAR(500) NOT NULL, p256dh VARCHAR(200) NOT NULL, auth VARCHAR(80) NOT NULL,
        user_agent VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_ok_at DATETIME DEFAULT NULL, fail_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        UNIQUE KEY uq_endpoint (endpoint(255)), KEY idx_user (user_id), KEY idx_student (student_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL, role_scope VARCHAR(30) DEFAULT NULL,
        title VARCHAR(140) NOT NULL, body VARCHAR(400) NOT NULL, url VARCHAR(255) DEFAULT NULL, kind VARCHAR(30) DEFAULT 'info',
        read_at DATETIME DEFAULT NULL, pushed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id, read_at), KEY idx_student (student_id, read_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_messages (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL,
        role VARCHAR(10) NOT NULL, content MEDIUMTEXT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id, id), KEY idx_student (student_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_actions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL,
        tool VARCHAR(60) NOT NULL, args_json TEXT NOT NULL, summary VARCHAR(300) NOT NULL,
        status ENUM('pending','done','cancelled','failed') NOT NULL DEFAULT 'pending',
        result_json TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_key_state (
        model VARCHAR(60) NOT NULL, key_idx TINYINT UNSIGNED NOT NULL, day DATE NOT NULL, used INT UNSIGNED NOT NULL DEFAULT 0,
        cooldown_until DATETIME DEFAULT NULL, dead TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (model, key_idx, day)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // ---- backfill ----
    foreach (['ai_enabled' => '1', 'gemini_key_1' => '', 'gemini_key_2' => '', 'gemini_key_3' => '', 'vapid_public' => '', 'vapid_private' => ''] as $k => $v) {
        $pdo->prepare("INSERT IGNORE INTO config (config_key, config_value) VALUES (?,?)")->execute([$k, $v]);
    }
    foreach ($pdo->query("SELECT id FROM rooms")->fetchAll(PDO::FETCH_COLUMN) as $rid) beds_ensure_for_room($pdo, (int)$rid);
    $log[] = 'beds ensured for all rooms';
    // keep the Phase-2 pool: beds of rooms that were flagged available_p2 stay in that pool
    $pdo->exec("UPDATE beds b JOIN rooms r ON r.id=b.room_id SET b.status='available_p2' WHERE r.status='available_p2' AND b.status='vacant'");

    $defaults = ['New Men' => 'new_men', 'Old Men' => 'straight', 'Annex' => 'l_shape', 'Box' => 'u_shape'];
    foreach ($pdo->query("SELECT id, name FROM blocks WHERE layout_json IS NULL")->fetchAll() as $b) {
        layout_apply_preset($pdo, (int)$b['id'], $defaults[$b['name']] ?? 'straight', 3);
        $log[] = "default layout for block {$b['name']}";
    }
    require_once __DIR__ . '/../webpush.php'; wp_vapid_keys($pdo); $log[] = 'push (VAPID) keys ready';
    beds_reconcile($pdo);
    $log[] = 'beds reconciled with students and bookings';
    return $log;
}
