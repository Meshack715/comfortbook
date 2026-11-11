<?php
// POST {id, args:{field:value}} — the person changed details on an action card BEFORE pressing its button.
// Validates the edits, rewrites the pending action and returns its new one-line summary. Executing it is still api/ai_confirm.php (with its code/password step-up).
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/ai/chat.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$ctx = ai_actor_ctx($pdo); if (!$ctx) { http_response_code(401); echo json_encode(['ok' => false, 'message' => 'Please sign in.']); exit; }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
[$col, $oid] = ai_owner($ctx);
$q = $pdo->prepare("SELECT id, tool, args_json FROM ai_actions WHERE id=? AND status='pending' AND $col=?"); $q->execute([(int)($in['id'] ?? 0), $oid]); $row = $q->fetch();
if (!$row) { echo json_encode(['ok' => false, 'message' => 'That action is no longer waiting. Ask me again.']); exit; }
$tools = ai_tools_for($ctx); $def = $tools[$row['tool']] ?? null;
if (!$def || empty($def['w'])) { echo json_encode(['ok' => false, 'message' => 'That action is not available for your role.']); exit; }
$args = ai_apply_edits($def, (array)json_decode($row['args_json'], true), (array)($in['args'] ?? []));
[$sum, $err] = ai_prepare_write($pdo, $ctx, $row['tool'], $args);
if ($err) { echo json_encode(['ok' => false, 'message' => $err]); exit; }
$pdo->prepare("UPDATE ai_actions SET args_json=?, summary=? WHERE id=? AND status='pending'")->execute([json_encode($args, JSON_UNESCAPED_UNICODE), mb_substr($sum, 0, 300), $row['id']]);
echo json_encode(['ok' => true, 'summary' => $sum]);
