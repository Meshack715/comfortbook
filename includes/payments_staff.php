<?php
// includes/payments_staff.php — staff-side fee collection. Used by the "Collect payment" page, its API and the AI assistant,
// so all three follow exactly the same rules:
//   * secretaries/deans only reach students of their own wing; admins reach everyone
//   * the fee is never collected twice for a semester
//   * every prompt is limited (per student and per staff member) and written to the audit log
require_once __DIR__ . '/mpesa.php';
require_once __DIR__ . '/notify.php';

/** The signed-in staff member as a small array, or null. */
function pay_actor_session(): ?array {
    $role = (string)($_SESSION['role'] ?? ''); if (empty($_SESSION['user_id']) || $role === '') return null;
    $kind = $role === 'admin' ? 'admin' : (str_starts_with($role, 'dean') ? 'dean' : (str_starts_with($role, 'secretary') ? 'secretary' : ''));
    if ($kind === '') return null;
    return ['uid' => (int)$_SESSION['user_id'], 'role' => $role, 'kind' => $kind, 'gender' => $kind === 'admin' ? null : ($_SESSION['gender_scope'] ?? null), 'name' => (string)($_SESSION['full_name'] ?? '')];
}
function pay_actor_from_ctx(array $ctx): ?array {
    if (empty($ctx['uid']) || !in_array($ctx['kind'] ?? '', ['admin', 'dean', 'secretary'], true)) return null;
    return ['uid' => (int)$ctx['uid'], 'role' => (string)$ctx['role'], 'kind' => $ctx['kind'], 'gender' => $ctx['kind'] === 'admin' ? null : ($ctx['gender'] ?? null), 'name' => (string)($_SESSION['full_name'] ?? '')];
}

/** Students matching a registration number, email or name, inside the actor's wing. Exact matches come first. */
function pay_find_students(PDO $pdo, array $a, string $q, int $limit = 6): array {
    $q = trim($q); if (mb_strlen($q) < 2) return [];
    $like = '%' . addcslashes($q, '%_\\') . '%'; $w = ''; $p = [$q, $q, $like, $like, $like];
    if ($a['gender']) { $w = ' AND s.gender=?'; $p[] = $a['gender']; }
    $st = $pdo->prepare("SELECT s.* FROM students s WHERE (s.reg_number=? OR s.email=? OR s.reg_number LIKE ? OR s.email LIKE ? OR s.full_name LIKE ?) $w
        ORDER BY (s.reg_number=? OR s.email=?) DESC, s.full_name LIMIT " . (int)$limit);
    $st->execute(array_merge(array_slice($p, 0, 5), $a['gender'] ? [$a['gender']] : [], [$q, $q]));
    return $st->fetchAll();
}
function pay_student_in_scope(PDO $pdo, array $a, int $sid): ?array {
    $st = $pdo->prepare("SELECT * FROM students WHERE id=?" . ($a['gender'] ? " AND gender=?" : '')); $st->execute($a['gender'] ? [$sid, $a['gender']] : [$sid]);
    return $st->fetch() ?: null;
}

/** What this student owes right now, and why. */
function pay_fee_status(PDO $pdo, array $s): array {
    $sem = booking_sem_id($pdo); $fee = (int)get_config('penalty_amount', '200');
    $bk = null;
    if ($sem) { $q = $pdo->prepare("SELECT id, status, penalty_amount, mpesa_ref FROM bookings WHERE student_id=? AND semester_id=? AND status IN ('pending_otp','pending_payment','pending_approval','confirmed') ORDER BY id DESC LIMIT 1"); $q->execute([$s['id'], $sem]); $bk = $q->fetch() ?: null; }
    $r = ['semester_id' => $sem, 'amount' => $fee, 'paid' => false, 'due' => false, 'can_mark' => false, 'booking' => $bk, 'reason' => '', 'booking_id' => null];
    if (!$sem)                                                                    { $r['reason'] = 'No semester is active, so no fee can be collected yet.'; return $r; }
    if ((int)($s['late_fee_paid_sem'] ?? 0) === $sem)                              { $r['paid'] = true; $r['reason'] = 'Late fee already paid for this semester' . (!empty($s['late_fee_paid_at']) ? ' (' . date('j M, H:i', strtotime($s['late_fee_paid_at'])) . ')' : '') . '.'; return $r; }
    if ($bk && $bk['status'] === 'pending_payment' && (int)$bk['penalty_amount'] > 0) { $r['due'] = true; $r['amount'] = (int)$bk['penalty_amount']; $r['booking_id'] = (int)$bk['id']; $r['reason'] = 'Booking is waiting for the late fee.'; return $r; }
    if ($bk && in_array($bk['status'], ['pending_approval', 'confirmed'], true))   { $r['reason'] = 'Already has a booking in this semester, so there is no fee to collect.'; return $r; }
    $bs = booking_state($pdo, $s, $sem);
    if ($bs['state'] === 'late')                                                  { $r['due'] = true; $r['reason'] = 'The booking windows have ended, so the late fee must be paid before this student can book.'; return $r; }
    if (!empty($s['late_booker']))                                                { $r['due'] = true; $r['reason'] = 'Marked as a late booker. The fee must be paid before booking a room.'; return $r; }
    $r['can_mark'] = $fee > 0; $r['reason'] = 'Not marked as a late booker.'; return $r;
}

function pay_phone_default(array $s): string { return (string)($s['phone'] ?? ''); }

/** Count prompts sent recently, so nobody can flood a phone. */
function pay_too_many(PDO $pdo, ?int $studentId, int $uid): ?string {
    if ($studentId) { $n = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE student_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $n->execute([$studentId]);
        if ((int)$n->fetchColumn() >= 6) return 'Several prompts were already sent to this student in the last few minutes. Wait about 10 minutes, then try again.'; }
    $n = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE initiated_by_user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $n->execute([$uid]);
    return (int)$n->fetchColumn() >= 40 ? 'Too many prompts were sent in a short time. Please wait a few minutes.' : null;
}

/**
 * Check everything WITHOUT sending, so the assistant's confirm card and the page can show exactly what will happen.
 * $q: a registration number, email or name. Returns ['ok'=>bool,'error'=>?string,'student'=>?array,'fee'=>?array,'phone'=>?string(254..),'mark_late'=>bool]
 */
function pay_prepare_fee(PDO $pdo, array $a, string $q, string $phoneRaw = '', ?int $studentId = null): array {
    mpesa_ensure_schema($pdo);
    if ($studentId) { $s = pay_student_in_scope($pdo, $a, $studentId); $hits = $s ? [$s] : []; } else $hits = pay_find_students($pdo, $a, $q, 5);
    if (!$hits) return ['ok' => false, 'error' => 'I could not find that student in your wing. Check the registration number or email.'];
    if (count($hits) > 1 && strcasecmp($hits[0]['reg_number'], trim($q)) !== 0 && strcasecmp($hits[0]['email'], trim($q)) !== 0)
        return ['ok' => false, 'error' => 'More than one student matches. Use the registration number. Matches: ' . implode('; ', array_map(fn($x) => "{$x['full_name']} ({$x['reg_number']})", array_slice($hits, 0, 4))) . '.'];
    $s = $hits[0]; $fee = pay_fee_status($pdo, $s); $r = ['ok' => false, 'student' => $s, 'fee' => $fee, 'mark_late' => false];
    if ((int)$s['is_active'] !== 1) { $r['error'] = "{$s['full_name']}'s account is not active, so a payment cannot be collected. Ask the administrator."; return $r; }
    if ($fee['paid'])  { $r['error'] = "{$s['full_name']} has already paid the late fee for this semester. Nothing more to collect."; return $r; }
    if (!$fee['due'] && !$fee['can_mark']) { $r['error'] = $fee['reason']; return $r; }
    if ($fee['amount'] < 1) { $r['error'] = 'The late fee is set to KES 0, so there is nothing to collect.'; return $r; }
    $ph = trim($phoneRaw) !== '' ? mpesa_phone($phoneRaw) : mpesa_phone(pay_phone_default($s));
    if (!$ph) { $r['error'] = trim($phoneRaw) === '' && trim(pay_phone_default($s)) === '' ? "There is no phone number on file for {$s['full_name']}. Enter the M-Pesa number to send the prompt to." : 'That does not look like a Safaricom number. Use a number like 0712 345 678 or 0112 345 678.'; return $r; }
    $r['ok'] = true; $r['phone'] = $ph; $r['mark_late'] = !$fee['due']; return $r;
}

/** Send the late-fee prompt for one student. Returns ['ok','message','payment_id'?]. */
function pay_send_fee(PDO $pdo, array $a, string $q, string $phoneRaw, string $via = 'staff', ?int $studentId = null): array {
    try {
        $c = pay_prepare_fee($pdo, $a, $q, $phoneRaw, $studentId);
        if (!$c['ok']) return ['ok' => false, 'message' => $c['error']];
        $s = $c['student']; $fee = $c['fee'];
        if ($m = pay_too_many($pdo, (int)$s['id'], $a['uid'])) return ['ok' => false, 'message' => $m];
        if ($c['mark_late']) { $pdo->prepare("UPDATE students SET late_booker=1 WHERE id=?")->execute([$s['id']]); audit_log($a['uid'], (int)$s['id'], 'late_booker_marked', "Marked {$s['reg_number']} as a late booker while collecting the fee" . ($via === 'ai' ? ' (via assistant)' : '')); }
        $r = mpesa_start_payment($pdo, ['purpose' => 'late_fee', 'amount' => $fee['amount'], 'phone' => $c['phone'], 'student_id' => (int)$s['id'], 'booking_id' => $fee['booking_id'],
            'semester_id' => $fee['semester_id'], 'via' => $via, 'user_id' => $a['uid']]);
        if (!empty($r['payment_id']) || $r['ok']) audit_log($a['uid'], (int)$s['id'], 'mpesa_prompt_sent', "Late-fee prompt KES {$fee['amount']} to " . mpesa_phone_pretty($c['phone'], true) . ($via === 'ai' ? ' (via assistant)' : '') . (mpesa_env() === 'demo' ? ' (DEMO)' : ''));
        return ['ok' => (bool)$r['ok'], 'message' => $r['message'], 'payment_id' => $r['payment_id'] ?? null, 'student' => $s['full_name'], 'reg' => $s['reg_number']];
    } catch (Throwable $e) {
        mpesa_tech_log('error', 'pay_send_fee: ' . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        return ['ok' => false, 'message' => 'Something went wrong while sending the prompt. Nothing was charged. Please try again in a moment.'];
    }
}

/** KES 1 test to a number the staff member types (normally their own phone), to prove the whole M-Pesa chain works. */
function pay_prepare_test(array $a, string $phoneRaw): array {
    $ph = mpesa_phone($phoneRaw); if (!$ph) return ['ok' => false, 'error' => 'Enter the phone number to send the test to, like 0712 345 678 or 0112 345 678.'];
    return ['ok' => true, 'phone' => $ph, 'amount' => 1];
}
function pay_send_test(PDO $pdo, array $a, string $phoneRaw, string $via = 'admin_test'): array {
    try {
        $c = pay_prepare_test($a, $phoneRaw); if (!$c['ok']) return ['ok' => false, 'message' => $c['error']];
        if ($m = pay_too_many($pdo, null, $a['uid'])) return ['ok' => false, 'message' => $m];
        $r = mpesa_start_payment($pdo, ['purpose' => 'test', 'amount' => 1, 'phone' => $c['phone'], 'via' => $via, 'user_id' => $a['uid']]);
        audit_log($a['uid'], null, 'mpesa_test_sent', 'KES 1 test prompt to ' . mpesa_phone_pretty($c['phone'], true) . (mpesa_env() === 'demo' ? ' (DEMO)' : ''));
        return ['ok' => (bool)$r['ok'], 'message' => $r['message'], 'payment_id' => $r['payment_id'] ?? null];
    } catch (Throwable $e) {
        mpesa_tech_log('error', 'pay_send_test: ' . $e->getMessage());
        return ['ok' => false, 'message' => 'Something went wrong while sending the test. Nothing was charged. Please try again in a moment.'];
    }
}

/** May this staff member see this payment? */
function pay_visible(PDO $pdo, array $a, array $p): bool {
    if ($a['kind'] === 'admin') return true;
    if ((int)$p['initiated_by_user_id'] === $a['uid']) return true;
    if (!$p['student_id']) return false;
    return (bool)pay_student_in_scope($pdo, $a, (int)$p['student_id']);
}

/** Rows for tables: recent payments inside the staff member's reach, with the plain-words status. */
function pay_recent(PDO $pdo, array $a, int $limit = 15, bool $reviewOnly = false): array {
    mpesa_ensure_schema($pdo); mpesa_sweep($pdo);
    $w = []; $p = [];
    if ($reviewOnly) $w[] = "(m.needs_review=1)";
    if ($a['gender']) { $w[] = "(s.gender=? OR m.initiated_by_user_id=? OR (m.student_id IS NULL AND m.via='callback'))"; array_push($p, $a['gender'], $a['uid']); }
    $st = $pdo->prepare("SELECT m.*, s.full_name, s.reg_number FROM mpesa_payments m LEFT JOIN students s ON s.id=m.student_id " . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . " ORDER BY m.id DESC LIMIT " . (int)$limit);
    $st->execute($p); $out = [];
    foreach ($st->fetchAll() as $m) {
        $v = mpesa_view($m, 'staff'); $v['who'] = $m['student_id'] ? "{$m['full_name']} ({$m['reg_number']})" : ($m['purpose'] === 'test' ? 'Test payment' : 'Unknown payer');
        $v['purpose'] = $m['purpose']; $v['at'] = $m['created_at']; $v['needs_review'] = (int)$m['needs_review']; $v['note'] = $m['review_note']; $v['demo'] = $m['env'] === 'demo'; $out[] = $v;
    }
    return $out;
}

/** The "is M-Pesa ready?" checklist in plain words. */
function pay_health(PDO $pdo): array {
    $env = mpesa_env(); $keys = trim((string)get_config('mpesa_consumer_key', '')) !== '' && trim((string)get_config('mpesa_consumer_secret', '')) !== '' && trim((string)get_config('mpesa_passkey', '')) !== '';
    $url = (string)get_config('app_public_url', ''); $https = str_starts_with($url, 'https://');
    $items = [['ok' => true, 'text' => $env === 'demo' ? 'Demo mode: prompts are simulated and no real money moves.' : ($env === 'live' ? 'Live mode: real money moves.' : 'Sandbox mode: Safaricom test environment.')]];
    if ($env !== 'demo') {
        $items[] = ['ok' => $keys, 'text' => $keys ? 'Safaricom keys are saved.' : 'Safaricom keys are missing. Ask the technical person to add them in AI & payments.'];
        $items[] = ['ok' => $https, 'text' => $https ? 'The public site address is set, so Safaricom can report results back.' : 'The public https site address is not set. Without it, results cannot come back to the system.'];
    }
    $q = $pdo->query("SELECT COUNT(*) FROM mpesa_payments WHERE needs_review=1")->fetchColumn(); if ((int)$q) $items[] = ['ok' => false, 'text' => "$q payment(s) need a person to look at them (see the list below)."];
    return ['env' => $env, 'ready' => !array_filter($items, fn($i) => !$i['ok']), 'items' => $items];
}
