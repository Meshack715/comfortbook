<?php
// POST {id, decision: 'confirm'|'cancel'} — only the person who the action was prepared for can decide.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scene_data.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/ai/chat.php';
header('Content-Type: application/json');
$ctx = ai_actor_ctx($pdo); if (!$ctx) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
$in = json_decode(file_get_contents('php://input'), true);
$col = $ctx['student'] ? 'student_id' : 'user_id'; $me = $ctx['student'] ? (int)$ctx['student']['id'] : $ctx['uid'];
$q = $pdo->prepare("SELECT * FROM ai_actions WHERE id=? AND $col=? AND status='pending' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"); $q->execute([(int)($in['id'] ?? 0), $me]); $a = $q->fetch();
if (!$a) { echo json_encode(['ok' => false, 'message' => 'That action expired or was already handled.']); exit; }
if (($in['decision'] ?? '') !== 'confirm') { $pdo->prepare("UPDATE ai_actions SET status='cancelled', decided_at=NOW() WHERE id=?")->execute([$a['id']]); echo json_encode(['ok' => true, 'message' => 'Cancelled.']); exit; }
$tools = ai_tools_for($ctx);
if (empty($tools[$a['tool']]['w'])) { echo json_encode(['ok' => false, 'message' => 'Not allowed for your role.']); exit; }
// ---- step-up verification for sensitive tools ----
$sens = $tools[$a['tool']]['sens'] ?? null;
if ($sens && $ctx['uid']) {
    $given = trim((string)($in['code'] ?? ''));
    $u = $pdo->prepare("SELECT email, full_name, password_hash FROM users WHERE id=?"); $u->execute([$ctx['uid']]); $me = $u->fetch();
    if ($given === '') {
        if ($sens === 'password') { echo json_encode(['ok' => false, 'need' => 'password', 'message' => 'This is a very sensitive action. Enter your password to continue.']); exit; }
        if (!empty($a['step_expires']) && strtotime($a['step_expires']) > time() + 240) { echo json_encode(['ok' => false, 'need' => 'code', 'message' => 'A code was just sent. Check your email.']); exit; }
        $code = (string)random_int(100000, 999999);
        $pdo->prepare("UPDATE ai_actions SET step_hash=?, step_expires=DATE_ADD(NOW(), INTERVAL 5 MINUTE), step_tries=0 WHERE id=?")->execute([hash('sha256', $code . $a['id']), $a['id']]);
        require_once __DIR__ . '/../includes/mailer.php';
        $sent = @send_mail($me['email'], $me['full_name'], 'Your ComfortBook confirmation code', "<p>Your code to confirm <strong>" . htmlspecialchars($a['summary']) . "</strong> is:</p><p style=\"font-size:28px;letter-spacing:6px\"><strong>$code</strong></p><p>It works for 5 minutes. If you did not ask for this, ignore it and tell the admin.</p>");
        $mask = preg_replace('/(?<=.).(?=[^@]*@)/', '*', $me['email']);
        echo json_encode(['ok' => false, 'need' => 'code', 'message' => $sent ? "We emailed a 6-digit code to $mask." : 'The code email could not be sent. Ask the admin to check email settings.']); exit;
    }
    if ($sens === 'password') {
        $key = 'ai_pw_' . $ctx['uid']; $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
        if ($_SESSION[$key] > 5) { echo json_encode(['ok' => false, 'need' => 'password', 'message' => 'Too many wrong passwords. Try again later.']); exit; }
        if (!password_verify($given, $me['password_hash'])) { echo json_encode(['ok' => false, 'need' => 'password', 'message' => 'That password is not right.']); exit; }
        unset($_SESSION[$key]);
    } else {
        if (empty($a['step_hash']) || strtotime((string)$a['step_expires']) < time()) { echo json_encode(['ok' => false, 'need' => 'code', 'message' => 'The code expired. Tap Confirm to get a new one.']); exit; }
        if ((int)$a['step_tries'] >= 3) { echo json_encode(['ok' => false, 'message' => 'Too many wrong codes. Ask the assistant again.']); $pdo->prepare("UPDATE ai_actions SET status='cancelled' WHERE id=?")->execute([$a['id']]); exit; }
        if (!hash_equals($a['step_hash'], hash('sha256', $given . $a['id']))) { $pdo->prepare("UPDATE ai_actions SET step_tries=step_tries+1 WHERE id=?")->execute([$a['id']]); echo json_encode(['ok' => false, 'need' => 'code', 'message' => 'That code is not right.']); exit; }
    }
}
$cl = $pdo->prepare("UPDATE ai_actions SET status='done', decided_at=NOW() WHERE id=? AND status='pending'"); $cl->execute([$a['id']]);
if (!$cl->rowCount()) { echo json_encode(['ok' => false, 'message' => 'Already handled.']); exit; }
try { $r = ai_execute_write($pdo, $ctx, $a['tool'], json_decode($a['args_json'], true) ?: []); }
catch (Throwable $e) { require_once __DIR__ . '/../includes/mpesa.php'; mpesa_tech_log('error', 'ai_confirm ' . ($a['tool'] ?? '') . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine()); $r = ['ok' => false, 'message' => 'That did not work and nothing was changed. Please try again; if it keeps happening, tell the technical support person.']; $pdo->prepare("UPDATE ai_actions SET status='failed' WHERE id=?")->execute([$a['id']]); }
$pdo->prepare("UPDATE ai_actions SET result_json=? WHERE id=?")->execute([json_encode($r), $a['id']]);
echo json_encode($r);
