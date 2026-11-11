<?php
// 003: AI workspace — many chats per person, memory the assistant keeps, rich blocks stored with each reply.
require_once __DIR__ . '/001_immersive.php';
function cb_migrate_003(PDO $pdo): array {
    $log = [];
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_conversations (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL,
        title VARCHAR(90) NOT NULL DEFAULT 'New chat',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id, updated_at), KEY idx_student (student_id, updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ai_memory (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED DEFAULT NULL, student_id INT UNSIGNED DEFAULT NULL,
        kind VARCHAR(20) NOT NULL DEFAULT 'fact', fact VARCHAR(240) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_user (user_id, kind), KEY idx_student (student_id, kind)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    cb_add_col($pdo, 'ai_messages', 'conv_id', "BIGINT UNSIGNED DEFAULT NULL", $log);
    cb_add_col($pdo, 'ai_messages', 'blocks', "MEDIUMTEXT DEFAULT NULL", $log);
    try { $pdo->exec("ALTER TABLE ai_messages ADD KEY idx_conv (conv_id, id)"); } catch (Throwable $e) {}
    $log[] = 'AI workspace (chats, memory, rich blocks) ready';
    return $log;
}
