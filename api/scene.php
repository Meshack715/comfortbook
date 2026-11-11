<?php
// api/scene.php — layout + rooms + beds for the 3D viewer (scoped by who is asking)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$actor = scene_actor($pdo);
if (!$actor) { http_response_code(401); echo json_encode(['ok' => false, 'error' => 'Please sign in again.']); exit; }
session_write_close();
try { echo json_encode(['ok' => true] + scene_full($pdo, $actor), JSON_UNESCAPED_UNICODE); }
catch (Throwable $e) { error_log('scene: ' . $e->getMessage()); http_response_code(500); echo json_encode(['ok' => false, 'error' => 'Could not load the hostel.']); }
