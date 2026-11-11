<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
header('Content-Type: application/json');
$actor = scene_actor($pdo); if (!$actor) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
$in = json_decode(file_get_contents('php://input'), true);
$ep = $in['endpoint'] ?? ''; $p256 = $in['keys']['p256dh'] ?? ''; $auth = $in['keys']['auth'] ?? '';
if (!preg_match('#^https://#', $ep) || !$p256 || !$auth || strlen($ep) > 500) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'Bad subscription']); exit; }
$uid = $actor['mode'] === 'staff' ? $actor['user_id'] : null; $sid = $actor['mode'] === 'student' ? (int)$actor['student']['id'] : null;
$pdo->prepare("INSERT INTO push_subscriptions (user_id,student_id,endpoint,p256dh,auth,user_agent) VALUES (?,?,?,?,?,?)
               ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), student_id=VALUES(student_id), p256dh=VALUES(p256dh), auth=VALUES(auth), fail_count=0")
    ->execute([$uid, $sid, $ep, $p256, $auth, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250)]);
echo json_encode(['ok' => true]);
