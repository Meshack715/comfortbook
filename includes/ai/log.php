<?php
// includes/ai/log.php — one detailed log for everything the assistant does: every Gemini attempt (model, key tail, HTTP code, time, full error),
// skipped keys and why, every tool call, PHP warnings, exceptions and fatals. Viewed at user/ai_log.php (admin).
function ai_rid(): string { static $r = null; return $r ??= substr(bin2hex(random_bytes(4)), 0, 8); }

function ai_log_ready(): bool {
    static $ok = null; if ($ok !== null) return $ok; global $pdo; if (!isset($pdo)) return $ok = false;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS ai_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            rid CHAR(8) NOT NULL, actor VARCHAR(60) DEFAULT NULL, level VARCHAR(8) NOT NULL DEFAULT 'info', event VARCHAR(30) NOT NULL,
            model VARCHAR(60) DEFAULT NULL, key_tail VARCHAR(12) DEFAULT NULL, http_code INT DEFAULT NULL, ms INT DEFAULT NULL, detail MEDIUMTEXT,
            KEY idx_rid (rid), KEY idx_time (created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        if (random_int(1, 40) === 1) { $pdo->exec("DELETE FROM ai_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)"); $pdo->exec("DELETE FROM ai_log WHERE id < (SELECT m FROM (SELECT MAX(id) - 4000 AS m FROM ai_log) t)"); }
        return $ok = true;
    } catch (Throwable $e) { return $ok = false; }
}
/** $x may hold: model, key (full key; only the last 4 characters are stored), code, ms. Never throws. */
function ai_log(string $level, string $event, string $detail = '', array $x = []): void {
    if (!ai_log_ready()) return; global $pdo;
    try {
        $k = isset($x['key']) ? '…' . substr((string)$x['key'], -4) : ($x['key_tail'] ?? null);
        $pdo->prepare("INSERT INTO ai_log (rid,actor,level,event,model,key_tail,http_code,ms,detail) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([ai_rid(), $GLOBALS['ai_actor'] ?? null, $level, $event, $x['model'] ?? null, $k, $x['code'] ?? null, isset($x['ms']) ? (int)$x['ms'] : null, mb_substr($detail, 0, 6000)]);
    } catch (Throwable $e) { /* logging must never break the chat */ }
}
