<?php
// Staff payment API (admin, deans, secretaries). JSON in, JSON out. Every message is plain words.
//   GET  ?action=lookup&q=      find a student (registration number, email or name) and what they owe
//   GET  ?action=status&id=     live result of one prompt
//   GET  ?action=recent         recent payments + the ones that need a person
//   POST {action:send, student_id, phone}      prompt the student for the late fee
//   POST {action:test, phone}                  KES 1 test prompt, to prove the whole chain works
//   POST {action:stop, id}                     stop waiting for a prompt (a late success is still applied)
//   POST {action:mark_late, student_id, value} set/clear the late-booker flag
//   POST {action:resolve, id, note}            a person has handled a "needs attention" payment
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments_staff.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$say = function (array $o, int $code = 200) { http_response_code($code); echo json_encode($o, JSON_UNESCAPED_UNICODE); exit; };
$a = pay_actor_session(); if (!$a) $say(['ok' => false, 'message' => 'Please sign in again.'], 401);
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && empty($_SERVER['HTTP_X_REQUESTED_WITH'])) $say(['ok' => false, 'message' => 'Request not accepted.'], 400);
$in = $post ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET; $act = (string)($in['action'] ?? '');
session_write_close();
try {
    mpesa_ensure_schema($pdo);
    switch ($act) {
        case 'lookup': {
            $out = [];
            foreach (pay_find_students($pdo, $a, (string)($in['q'] ?? ''), 6) as $s) {
                $f = pay_fee_status($pdo, $s);
                $lp = $pdo->prepare("SELECT * FROM mpesa_payments WHERE student_id=? ORDER BY id DESC LIMIT 1"); $lp->execute([$s['id']]); $last = $lp->fetch();
                $out[] = ['id' => (int)$s['id'], 'name' => $s['full_name'], 'reg' => $s['reg_number'], 'email' => $s['email'], 'phone' => (string)($s['phone'] ?? ''), 'wing' => $s['gender'] === 'Male' ? "Men's wing" : "Women's wing",
                    'active' => (int)$s['is_active'] === 1, 'late_booker' => (int)$s['late_booker'] === 1, 'amount' => $f['amount'], 'paid' => $f['paid'], 'due' => $f['due'], 'can_mark' => $f['can_mark'], 'reason' => $f['reason'],
                    'booking' => $f['booking'] ? str_replace('_', ' ', $f['booking']['status']) : null, 'last' => $last ? mpesa_view($last, 'staff') : null];
            }
            $say(['ok' => true, 'students' => $out, 'health' => pay_health($pdo)]);
        }
        case 'send': {
            $r = pay_send_fee($pdo, $a, '', (string)($in['phone'] ?? ''), 'staff', (int)($in['student_id'] ?? 0));
            $say($r);
        }
        case 'test': $say(pay_send_test($pdo, $a, (string)($in['phone'] ?? ''), 'admin_test'));
        case 'status': {
            $q = $pdo->prepare("SELECT * FROM mpesa_payments WHERE id=?"); $q->execute([(int)($in['id'] ?? 0)]); $p = $q->fetch();
            if (!$p || !pay_visible($pdo, $a, $p)) $say(['ok' => false, 'message' => 'That payment could not be found.'], 404);
            if ($p['status'] === 'pending') { mpesa_query($pdo, $p); $q->execute([(int)$in['id']]); $p = $q->fetch(); }
            mpesa_sweep($pdo);
            $v = mpesa_view($p, 'staff'); $v['ok'] = true; $v['test'] = $p['purpose'] === 'test'; $say($v);
        }
        case 'stop': {
            $q = $pdo->prepare("SELECT * FROM mpesa_payments WHERE id=?"); $q->execute([(int)($in['id'] ?? 0)]); $p = $q->fetch();
            if (!$p || !pay_visible($pdo, $a, $p)) $say(['ok' => false, 'message' => 'That payment could not be found.'], 404);
            if ($p['status'] === 'pending') $pdo->prepare("UPDATE mpesa_payments SET status='cancelled', result_desc='Stopped waiting', completed_at=NOW() WHERE id=? AND status='pending'")->execute([$p['id']]);
            audit_log($a['uid'], $p['student_id'] ? (int)$p['student_id'] : null, 'mpesa_stopped', "Stopped waiting for prompt #{$p['id']}");
            $q->execute([$p['id']]); $say(['ok' => true] + mpesa_view($q->fetch(), 'staff'));
        }
        case 'mark_late': {
            $s = pay_student_in_scope($pdo, $a, (int)($in['student_id'] ?? 0)); if (!$s) $say(['ok' => false, 'message' => 'Student not found in your wing.']);
            $v = !empty($in['value']) ? 1 : 0; $pdo->prepare("UPDATE students SET late_booker=? WHERE id=?")->execute([$v, $s['id']]);
            audit_log($a['uid'], (int)$s['id'], $v ? 'late_booker_marked' : 'late_booker_cleared', ($v ? 'Marked' : 'Cleared') . " {$s['reg_number']} as late booker");
            $say(['ok' => true, 'message' => $v ? "{$s['full_name']} is now marked as a late booker." : "{$s['full_name']} is no longer a late booker."]);
        }
        case 'resolve': {
            if ($a['kind'] === 'secretary') $say(['ok' => false, 'message' => 'Only a dean or the administrator can close a payment that needs attention.'], 403);
            $q = $pdo->prepare("SELECT * FROM mpesa_payments WHERE id=?"); $q->execute([(int)($in['id'] ?? 0)]); $p = $q->fetch();
            if (!$p || !pay_visible($pdo, $a, $p)) $say(['ok' => false, 'message' => 'That payment could not be found.'], 404);
            $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 150);
            $pdo->prepare("UPDATE mpesa_payments SET needs_review=0, review_note=CONCAT(COALESCE(review_note,''), ' · Handled by ', ?, IF(?<>'', CONCAT(': ', ?), '')) WHERE id=?")->execute([$a['name'] ?: 'staff', $note, $note, $p['id']]);
            audit_log($a['uid'], $p['student_id'] ? (int)$p['student_id'] : null, 'mpesa_review_closed', "Payment #{$p['id']} {$p['receipt']} marked handled" . ($note ? ": $note" : ''));
            $say(['ok' => true, 'message' => 'Marked as handled.']);
        }
        case 'recent': $say(['ok' => true, 'recent' => pay_recent($pdo, $a, 12), 'review' => pay_recent($pdo, $a, 20, true), 'health' => pay_health($pdo)]);
    }
    $say(['ok' => false, 'message' => 'Unknown request.'], 400);
} catch (Throwable $e) {
    mpesa_tech_log('error', 'staff_payment ' . $act . ': ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
    $say(['ok' => false, 'message' => 'Something went wrong. Nothing was charged. Please try again in a moment.'], 500);
}
