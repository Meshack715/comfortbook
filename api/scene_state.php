<?php
// api/scene_state.php — tiny live update used for polling
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$actor = scene_actor($pdo);
if (!$actor) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
session_write_close();
try { echo json_encode(['ok' => true] + scene_state($pdo, $actor)); }
catch (Throwable $e) { http_response_code(500); echo json_encode(['ok' => false]); }
