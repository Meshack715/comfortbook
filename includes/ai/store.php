<?php
// includes/ai/store.php — chats and memory storage for the assistant (one owner = one student OR one staff user).

function ai_owner(array $ctx): array { return $ctx['student'] ? ['student_id', (int)$ctx['student']['id']] : ['user_id', (int)$ctx['uid']]; }

function ai_ensure_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        if (!$pdo->query("SHOW TABLES LIKE 'ai_conversations'")->fetchColumn() || !$pdo->query("SHOW COLUMNS FROM ai_messages LIKE 'conv_id'")->fetchColumn()) {
            require_once __DIR__ . '/../migrations/003_ai_workspace.php'; cb_migrate_003($pdo);
        }
    } catch (Throwable $e) { /* the chat still works in basic mode if this fails */ }
}

function ai_conv_create(PDO $pdo, array $ctx, string $title = 'New chat'): int {
    [$col, $id] = ai_owner($ctx);
    $pdo->prepare("INSERT INTO ai_conversations ($col,title) VALUES (?,?)")->execute([$id, mb_substr($title, 0, 90)]);
    return (int)$pdo->lastInsertId();
}
function ai_conv_owned(PDO $pdo, array $ctx, int $cid): bool {
    if ($cid <= 0) return false; [$col, $id] = ai_owner($ctx);
    $q = $pdo->prepare("SELECT 1 FROM ai_conversations WHERE id=? AND $col=?"); $q->execute([$cid, $id]); return (bool)$q->fetchColumn();
}
/** Older single-thread messages become one "Earlier chat" so nobody loses history. */
function ai_conv_adopt(PDO $pdo, array $ctx): void {
    [$col, $id] = ai_owner($ctx);
    $q = $pdo->prepare("SELECT COUNT(*) FROM ai_messages WHERE $col=? AND conv_id IS NULL"); $q->execute([$id]);
    if ((int)$q->fetchColumn() === 0) return;
    $cid = ai_conv_create($pdo, $ctx, 'Earlier chat');
    $pdo->prepare("UPDATE ai_messages SET conv_id=? WHERE $col=? AND conv_id IS NULL")->execute([$cid, $id]);
}
function ai_conv_list(PDO $pdo, array $ctx): array {
    ai_conv_adopt($pdo, $ctx); [$col, $id] = ai_owner($ctx);
    $q = $pdo->prepare("SELECT c.id, c.title, c.updated_at, (SELECT COUNT(*) FROM ai_messages m WHERE m.conv_id=c.id) AS n FROM ai_conversations c WHERE c.$col=? ORDER BY c.updated_at DESC, c.id DESC LIMIT 60");
    $q->execute([$id]); $rows = $q->fetchAll();
    foreach ($rows as $r) if ((int)$r['n'] === 0) { /* empty drafts are hidden from the list */ }
    return array_values(array_filter($rows, fn($r) => (int)$r['n'] > 0));
}
function ai_conv_delete(PDO $pdo, array $ctx, int $cid): void {
    if (!ai_conv_owned($pdo, $ctx, $cid)) return;
    $pdo->prepare("DELETE FROM ai_messages WHERE conv_id=?")->execute([$cid]);
    $pdo->prepare("DELETE FROM ai_conversations WHERE id=?")->execute([$cid]);
}
function ai_memory_list(PDO $pdo, array $ctx): array {
    [$col, $id] = ai_owner($ctx);
    $q = $pdo->prepare("SELECT id, kind, fact, created_at FROM ai_memory WHERE $col=? ORDER BY (kind='fact'), id DESC"); $q->execute([$id]); return $q->fetchAll();
}
/** A name must be a NAME: never a sentence like "The user calls me 'who goes you'". Takes the quoted part if there is one, else at most 3 short words. */
function ai_clean_name(string $s): string {
    $s = trim($s);
    if (preg_match('/["“”\'‘’`]\s*([^"“”\'‘’`]{1,40}?)\s*["“”\'‘’`]/u', $s, $m)) $s = $m[1];
    $s = preg_replace('/^(the user|they|he|she|i|you)\s+(calls?|named?|wants? to call|likes? to call|prefers? to call)\s+(me|you|them|it|the assistant)?\s*/iu', '', $s);
    $s = trim(preg_replace('/[^\p{L}\p{N} \-\.]/u', '', $s));
    $w = preg_split('/\s+/', $s, -1, PREG_SPLIT_NO_EMPTY);
    return mb_substr(implode(' ', array_slice($w, 0, 3)), 0, 24);
}
function ai_memory_names(PDO $pdo, array $ctx): array {
    $out = ['assistant' => '', 'nickname' => '', 'facts' => []];
    foreach (ai_memory_list($pdo, $ctx) as $m) {
        if ($m['kind'] === 'assistant_name' && !$out['assistant']) $out['assistant'] = ai_clean_name($m['fact']);
        elseif ($m['kind'] === 'user_nickname' && !$out['nickname']) $out['nickname'] = ai_clean_name($m['fact']);
        elseif ($m['kind'] === 'fact') $out['facts'][] = $m['fact'];
    }
    return $out;
}
