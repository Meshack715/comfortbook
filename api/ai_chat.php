<?php
// GET  ?conv=ID           → chats list, that chat's messages (default: newest), assistant name
// GET  ?memory=1          → what the assistant remembers
// POST {action:new|delete|rename|memory_delete|memory_clear|message}
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/ai/chat.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (get_config('ai_enabled', '1') !== '1') { echo json_encode(['ok' => false, 'error' => 'Assistant is turned off.']); exit; }
$ctx = ai_actor_ctx($pdo); if (!$ctx) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in.']); exit; }
ai_ensure_schema($pdo);
[$col, $id] = ai_owner($ctx);
$GLOBALS['ai_actor'] = $ctx['kind'] . '#' . ($ctx['student']['id'] ?? $ctx['uid']);
ini_set('display_errors', '0');                                       // a stray warning must never corrupt the JSON the browser is waiting for
set_error_handler(function ($no, $str, $file, $line) { ai_log('warn', 'php_warning', "$str  (" . basename($file) . ":$line)"); return true; });


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if (!empty($_GET['memory'])) { echo json_encode(['ok' => true, 'items' => ai_memory_list($pdo, $ctx)]); exit; }
    $convs = ai_conv_list($pdo, $ctx);
    $active = (int)($_GET['conv'] ?? 0);
    if (!ai_conv_owned($pdo, $ctx, $active)) $active = $convs ? (int)$convs[0]['id'] : 0;
    $hist = [];
    if ($active) {
        $q = $pdo->prepare("SELECT id,role,content,blocks,created_at FROM ai_messages WHERE conv_id=? ORDER BY id DESC LIMIT 80"); $q->execute([$active]);
        foreach (array_reverse($q->fetchAll()) as $m) $hist[] = ['id' => (int)$m['id'], 'role' => $m['role'], 'content' => $m['content'], 'blocks' => $m['blocks'] ? json_decode($m['blocks'], true) : [], 'at' => $m['created_at']];
    }
    $pend = $pdo->prepare("SELECT id,summary FROM ai_actions WHERE $col=? AND status='pending' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY id"); $pend->execute([$id]);
    if ($ctx['student']) $nm = (string)($ctx['student']['full_name'] ?? ''); else { $u = $pdo->prepare("SELECT full_name FROM users WHERE id=?"); $u->execute([$ctx['uid']]); $nm = (string)$u->fetchColumn(); }
    $mem = ai_memory_names($pdo, $ctx);
    $first = $mem['nickname'] ?: (preg_split('/\s+/', trim($nm))[0] ?? '');
    echo json_encode(['ok' => true, 'name' => $first, 'assistant' => $mem['assistant'], 'facts' => count($mem['facts']) + ($mem['assistant'] ? 1 : 0) + ($mem['nickname'] ? 1 : 0),
        'kind' => $ctx['kind'], 'live' => gemini_enabled(), 'convs' => $convs, 'active' => $active, 'history' => $hist, 'cards' => $pend->fetchAll()]); exit;
}

$in = json_decode(file_get_contents('php://input'), true) ?: [];
$act = (string)($in['action'] ?? 'message');
switch ($act) {
    case 'new': case 'reset':
        echo json_encode(['ok' => true, 'active' => 0]); exit;            // a chat row is only created when the first message is sent
    case 'delete':
        ai_conv_delete($pdo, $ctx, (int)($in['id'] ?? 0)); echo json_encode(['ok' => true]); exit;
    case 'rename':
        $cid = (int)($in['id'] ?? 0); $t = mb_substr(trim((string)($in['title'] ?? '')), 0, 90);
        if ($t !== '' && ai_conv_owned($pdo, $ctx, $cid)) $pdo->prepare("UPDATE ai_conversations SET title=? WHERE id=?")->execute([$t, $cid]);
        echo json_encode(['ok' => true, 'title' => $t]); exit;
    case 'memory_delete':
        $pdo->prepare("DELETE FROM ai_memory WHERE id=? AND $col=?")->execute([(int)($in['id'] ?? 0), $id]); echo json_encode(['ok' => true]); exit;
    case 'memory_clear':
        $pdo->prepare("DELETE FROM ai_memory WHERE $col=?")->execute([$id]); echo json_encode(['ok' => true]); exit;
}
$msg = trim((string)($in['message'] ?? ''));
$quote = trim(preg_replace('/\s+/', ' ', (string)($in['reply_to'] ?? '')));       // "reply to this answer": the quoted text travels with the message
if ($msg !== '' && $quote !== '') $msg = '↪ ' . mb_substr($quote, 0, 220) . "\n\n" . $msg;
if ($msg === '') { echo json_encode(['ok' => false, 'error' => 'Say something first.']); exit; }
$n = $pdo->prepare("SELECT COUNT(*) FROM ai_messages WHERE $col=? AND role='user' AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)"); $n->execute([$id]);
if ((int)$n->fetchColumn() >= 12) { echo json_encode(['ok' => false, 'error' => 'Slow down a little and try again in a minute.']); exit; }
$ctx['page'] = preg_replace('/[^a-z0-9_.\-]/i', '', substr((string)($in['page'] ?? ''), 0, 60));
session_write_close();
@set_time_limit(110);

// Streaming: the browser sends X-CB-Stream:1 and gets one JSON object per line (progress steps first, the answer last).
$stream = ($_SERVER['HTTP_X_CB_STREAM'] ?? '') === '1';
$line = function (array $o) { echo json_encode($o, JSON_UNESCAPED_UNICODE) . "\n"; if (ob_get_level()) @ob_flush(); @flush(); };
if ($stream) {
    header('Content-Type: application/x-ndjson'); header('X-Accel-Buffering: no'); @ini_set('zlib.output_compression', '0');
    while (ob_get_level()) @ob_end_flush();
    echo str_repeat(' ', 1024) . "\n"; @flush();                      // pushes past proxy buffers so progress shows live
    $GLOBALS['ai_emit'] = $line;
    ignore_user_abort(true);                                            // we notice the Stop button ourselves and shut down cleanly
    $GLOBALS['ai_tick'] = function () { echo "{\"ping\":1}\n"; if (ob_get_level()) @ob_flush(); @flush(); if (connection_aborted()) $GLOBALS['ai_aborted'] = true; };
}
$out = function (array $o) use ($stream, $line) { if ($stream) $line($o); else echo json_encode($o, JSON_UNESCAPED_UNICODE); };
$fail = function (string $why, string $detail) use ($ctx, $out) {
    ai_log('error', 'exception', $detail);
    $out(['ok' => false, 'rid' => ai_rid(), 'error' => 'The assistant ran into a problem and nothing was changed. Please try again. If it keeps happening, tell the technical support person and quote reference ' . ai_rid() . '.']);
};
register_shutdown_function(function () use ($fail) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) $fail('Server error', $e['message'] . ' in ' . basename($e['file']) . ':' . $e['line']);
});
try {
    $r = ai_chat($pdo, $ctx, mb_substr($msg, 0, 1800), (int)($in['conv_id'] ?? 0), ['edit_from' => (int)($in['edit_from'] ?? 0)]);
    if (!empty($r['aborted'])) exit;
    $out(['ok' => true] + $r);
} catch (Throwable $e) {
    $fail('Server error', get_class($e) . ': ' . $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine() . "\n" . mb_substr($e->getTraceAsString(), 0, 900));
}
