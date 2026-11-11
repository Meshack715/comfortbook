<?php
// Download a document the assistant made for the signed-in person (PDF or Word). ?id=…  (&view=1 shows PDFs in the browser)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/ai/chat.php';
$ctx = ai_actor_ctx($pdo); if (!$ctx) { http_response_code(401); exit('Please sign in.'); }
ai_files_ready($pdo); [$col, $oid] = ai_owner($ctx);
$q = $pdo->prepare("SELECT name,mime,data FROM ai_files WHERE id=? AND $col=?"); $q->execute([(int)($_GET['id'] ?? 0), $oid]); $f = $q->fetch();
if (!$f) { http_response_code(404); exit('That file is no longer available. Ask the assistant to make it again.'); }
header('Content-Type: ' . $f['mime']); header('Content-Length: ' . strlen($f['data'])); header('Cache-Control: private, max-age=0');
header('Content-Disposition: ' . (!empty($_GET['view']) && $f['mime'] === 'application/pdf' ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $f['name']) . '"');
echo $f['data'];
