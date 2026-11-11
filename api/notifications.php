<?php
// GET = latest notifications for me; POST {read:true} marks all read.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$actor = scene_actor($pdo); if (!$actor) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
$col = $actor['mode'] === 'staff' ? 'user_id' : 'student_id'; $id = $actor['mode'] === 'staff' ? $actor['user_id'] : (int)$actor['student']['id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') { $pdo->prepare("UPDATE notifications SET read_at=NOW() WHERE $col=? AND read_at IS NULL")->execute([$id]); echo json_encode(['ok' => true]); exit; }
$q = $pdo->prepare("SELECT id,title,body,url,kind,read_at,created_at FROM notifications WHERE $col=? ORDER BY id DESC LIMIT 25"); $q->execute([$id]);
$u = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE $col=? AND read_at IS NULL"); $u->execute([$id]);
echo json_encode(['ok' => true, 'unread' => (int)$u->fetchColumn(), 'items' => $q->fetchAll()]);
