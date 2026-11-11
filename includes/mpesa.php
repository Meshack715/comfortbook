<?php
// includes/mpesa.php — Safaricom Daraja STK push, hardened.
//
// A payment row ends in exactly one of these states, and every state has a plain-words message for the person looking at it:
//   pending   prompt sent, waiting for the phone            success  money received AND applied (booking moved on / fee credited)
//   cancelled the person pressed Cancel on the phone         failed   wrong PIN, low balance, network, rejected, could not send
//   timeout   nobody answered the prompt in time             review   money received but it could NOT be applied automatically (short payment,
//                                                                      duplicate, receipt reuse) -> shown to staff to resolve by hand
// A late "success" is still honoured: if Safaricom confirms money after we gave up waiting, the payment is applied then.
//
// mpesa_env: 'demo' (no network; the outcome set in AI & payments is simulated), 'sandbox', or 'live'.

function mpesa_env(): string { return in_array(get_config('mpesa_env', 'demo'), ['sandbox', 'live'], true) ? get_config('mpesa_env') : 'demo'; }
function mpesa_base(): string {
    $o = trim((string)get_config('mpesa_base_url', ''));           // only for testing against a fake Safaricom
    if ($o !== '' && preg_match('#^https?://#', $o)) return rtrim($o, '/');
    return mpesa_env() === 'live' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
}
function mpesa_wait_seconds(): int { return max(60, min(600, (int)get_config('mpesa_wait_seconds', '150'))); }

/** Make sure the payment tables/columns exist. Does real work only the first time ever (so a forgotten migration cannot break payments). */
function mpesa_ensure_schema(PDO $pdo): void {
    static $done = false; if ($done) return; $done = true;
    try {
        require_once __DIR__ . '/migrations/004_payments_hardening.php';
        $t = $pdo->query("SHOW TABLES LIKE 'mpesa_payments'")->fetchColumn();
        if (!$t || !cb_col_exists($pdo, 'mpesa_payments', 'needs_review') || !cb_col_exists($pdo, 'students', 'late_fee_paid_sem')) cb_migrate_004($pdo);
    } catch (Throwable $e) { error_log('mpesa_ensure_schema: ' . $e->getMessage()); }
}

function mpesa_phone(string $raw): ?string {
    $d = preg_replace('/\D+/', '', $raw);
    if (preg_match('/^0([71]\d{8})$/', $d, $m)) return '254' . $m[1];
    if (preg_match('/^(254[71]\d{8})$/', $d, $m)) return $m[1];
    if (preg_match('/^([71]\d{8})$/', $d, $m)) return '254' . $m[1];
    return null;
}
function mpesa_phone_pretty(?string $p254, bool $mask = false): string {
    if (!$p254 || !preg_match('/^254(\d{3})(\d{3})(\d{3})$/', $p254, $m)) return (string)$p254;
    return $mask ? "0{$m[1]} *** {$m[3]}" : "0{$m[1]} {$m[2]} {$m[3]}";
}
function mpesa_callback_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = get_config('app_public_url', '') ?: ($scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . APP_BASE);
    return rtrim($base, '/') . '/api/mpesa_callback.php?k=' . hash_hmac('sha256', 'cb', (string)get_config('mpesa_callback_secret', 'x'));
}

/** HTTP helper. Returns [httpCode, decodedJson|null, curlError|null]; code 0 means we could not reach Safaricom at all. */
function mpesa_http(string $url, array $headers, ?array $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body)]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = (is_string($raw) && $raw !== '') ? json_decode($raw, true) : null;
    return [$code, is_array($j) ? $j : null, $err !== '' ? $err : null];
}

/** Technical detail goes to the AI log (the admin-only "AI log" page) and the server log, never to the person on screen. */
function mpesa_tech_log(string $level, string $what): void {
    try { require_once __DIR__ . '/ai/log.php'; ai_log($level, 'mpesa', mb_substr($what, 0, 1500)); } catch (Throwable $e) {}
    error_log('[mpesa] ' . $what);
}

/** OAuth token, cached until just before it expires (Daraja tokens live about an hour). */
function mpesa_token(bool $fresh = false): ?string {
    global $pdo; $ck = 'mpesa_tok_' . mpesa_env() . '_' . substr(md5(mpesa_base()), 0, 6);
    if (!$fresh) {
        try { $q = $pdo->prepare("SELECT config_value FROM config WHERE config_key=?"); $q->execute([$ck]); $c = json_decode((string)$q->fetchColumn(), true);
              if (is_array($c) && !empty($c['t']) && ($c['exp'] ?? 0) > time() + 60) return $c['t']; } catch (Throwable $e) {}
    }
    $k = trim((string)get_config('mpesa_consumer_key', '')); $s = trim((string)get_config('mpesa_consumer_secret', ''));
    if ($k === '' || $s === '') { mpesa_tech_log('error', 'consumer key/secret not saved'); return null; }
    [$c, $r, $err] = mpesa_http(mpesa_base() . '/oauth/v1/generate?grant_type=client_credentials', ['Authorization: Basic ' . base64_encode("$k:$s")]);
    if ($c === 200 && !empty($r['access_token'])) {
        try { set_config($ck, json_encode(['t' => $r['access_token'], 'exp' => time() + max(60, (int)($r['expires_in'] ?? 3599))])); } catch (Throwable $e) {}
        return $r['access_token'];
    }
    $GLOBALS['mpesa_token_net'] = ($c === 0);        // true = we could not reach Safaricom at all (a network problem), false = they refused our keys
    mpesa_tech_log('error', "token request failed: HTTP $c " . ($err ?: json_encode($r)));
    return null;
}
function mpesa_password(string $ts): string { return base64_encode(get_config('mpesa_shortcode', '174379') . get_config('mpesa_passkey', '') . $ts); }

/** Call a Daraja endpoint with a valid token; one automatic retry with a fresh token if Safaricom says the token is bad. */
function mpesa_api(string $path, array $body): array {
    $res = [-1, null, 'no token'];
    for ($try = 0; $try < 2; $try++) {
        $GLOBALS['mpesa_token_net'] = false; $tok = mpesa_token($try === 1);
        if (!$tok) return !empty($GLOBALS['mpesa_token_net']) ? [0, null, 'network (token)'] : [-1, null, 'no token'];
        $res = mpesa_http(mpesa_base() . $path, ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], $body);
        $bad = in_array($res[0], [401, 403], true) || (is_array($res[1]) && preg_match('/access.?token/i', (string)($res[1]['errorMessage'] ?? '')));
        if (!$bad) return $res;
    }
    return $res;
}

/* ====================================================================== what a result means, in plain words */
/** [status, friendly message] for a Safaricom result code / description. */
function mpesa_interpret(?string $code, string $raw = ''): array {
    $c = trim((string)$code);
    $map = [
        '0'    => ['success',   'Payment received.'],
        '1'    => ['failed',    'The M-Pesa balance is too low for this payment. Top up M-Pesa and try again.'],
        '17'   => ['failed',    'M-Pesa has paused payments on this number for a few minutes (too many attempts). Wait a little and try again.'],
        '26'   => ['failed',    'M-Pesa is very busy right now. Please try again in a minute.'],
        '1001' => ['failed',    'Another M-Pesa request is already open on this phone. Wait a minute, then try again.'],
        '1019' => ['timeout',   'The request expired before it was answered. Please try again.'],
        '1025' => ['failed',    'The prompt could not be sent to this phone. Check the number and try again.'],
        '1032' => ['cancelled', 'The payment was cancelled on the phone.'],
        '1037' => ['timeout',   'The phone did not answer in time, so no PIN was entered. Keep the phone on and unlocked, then try again.'],
        '2001' => ['failed',    'The wrong M-Pesa PIN was entered. Please try again and type the PIN carefully.'],
        '2006' => ['failed',    'This M-Pesa account cannot make this payment right now. Try a different number.'],
        '9999' => ['failed',    'The prompt could not be sent to this phone. Check the number and try again.'],
    ];
    if (isset($map[$c])) return $map[$c];
    if (preg_match('/cancel/i', $raw))                        return $map['1032'];
    if (preg_match('/timeout|timed out|unreachable/i', $raw)) return $map['1037'];
    if (preg_match('/insufficient|balance/i', $raw))          return $map['1'];
    if (preg_match('/pin|initiator information/i', $raw))     return $map['2001'];
    return ['failed', 'The payment did not go through and nothing was charged. Please try again.'];
}
function mpesa_still_processing(?string $code, ?array $r): bool {
    return (string)$code === '4999' || preg_match('/being processed|under processing|still.*process/i', (string)($r['errorMessage'] ?? $r['ResultDesc'] ?? '')) === 1;
}
function mpesa_demo_outcome(): string { $o = (string)get_config('mpesa_demo_outcome', 'success'); return in_array($o, ['success', 'cancelled', 'timeout', 'balance', 'wrong_pin', 'fail_send'], true) ? $o : 'success'; }

/* ====================================================================== start a payment */
/**
 * Send an STK prompt.  $o: purpose ('late_fee'|'test'), amount, phone (any format), student_id?, booking_id?, semester_id?, via ('student'|'staff'|'ai'|'admin_test'), user_id?
 * Returns ['ok'=>bool, 'payment_id'=>?int, 'message'=>string, 'duplicate'=>bool]. The message is always safe to show to anyone.
 */
function mpesa_start_payment(PDO $pdo, array $o): array {
    mpesa_ensure_schema($pdo);
    $purpose = ($o['purpose'] ?? 'late_fee') === 'test' ? 'test' : 'late_fee'; $amount = (int)($o['amount'] ?? 0); $env = mpesa_env();
    $sid = !empty($o['student_id']) ? (int)$o['student_id'] : null; $bid = !empty($o['booking_id']) ? (int)$o['booking_id'] : null;
    $uid = !empty($o['user_id']) ? (int)$o['user_id'] : null; $via = (string)($o['via'] ?? 'student'); $sem = !empty($o['semester_id']) ? (int)$o['semester_id'] : null;
    $phone = mpesa_phone((string)($o['phone'] ?? ''));
    if (!$phone) return ['ok' => false, 'message' => 'That does not look like a Safaricom number. Use a number like 0712 345 678 or 0112 345 678.'];
    if ($amount < 1) return ['ok' => false, 'message' => 'There is nothing to pay.'];
    if ($purpose === 'late_fee' && !$sem) return ['ok' => false, 'message' => 'No semester is active right now, so the fee cannot be collected yet.'];

    // a prompt for the same person was sent a moment ago: never stack a second one on the phone
    $w = $sid ? "student_id=$sid" : "student_id IS NULL AND initiated_by_user_id=" . (int)$uid;
    $ex = $pdo->query("SELECT * FROM mpesa_payments WHERE status='pending' AND purpose=" . $pdo->quote($purpose) . " AND $w AND created_at > DATE_SUB(NOW(), INTERVAL 45 SECOND) ORDER BY id DESC LIMIT 1")->fetch();
    if ($ex) {
        if ($ex['phone'] === $phone) return ['ok' => true, 'payment_id' => (int)$ex['id'], 'duplicate' => true, 'message' => 'The prompt was just sent. Check the phone and enter the M-Pesa PIN.'];
        return ['ok' => false, 'payment_id' => (int)$ex['id'], 'duplicate' => true, 'message' => 'A prompt was sent a moment ago to ' . mpesa_phone_pretty($ex['phone'], true) . '. Wait for its result (about a minute) before sending to another number.'];
    }
    $pdo->prepare("UPDATE mpesa_payments SET status='cancelled', result_desc='Replaced by a newer request' WHERE status='pending' AND purpose=? AND $w")->execute([$purpose]);
    $pdo->prepare("INSERT INTO mpesa_payments (booking_id,student_id,phone,amount,env,purpose,semester_id,via,initiated_by_user_id) VALUES (?,?,?,?,?,?,?,?,?)")
        ->execute([$bid, $sid, $phone, $amount, $env, $purpose, $sem, $via, $uid]);
    $pid = (int)$pdo->lastInsertId();
    $fail = function (string $friendly, string $raw, ?string $code = null) use ($pdo, $pid): array {
        $pdo->prepare("UPDATE mpesa_payments SET status='failed', result_desc=?, result_raw=?, result_code=?, completed_at=NOW() WHERE id=? AND status='pending'")->execute([$friendly, mb_substr($raw, 0, 250), $code, $pid]);
        return ['ok' => false, 'payment_id' => $pid, 'message' => $friendly];
    };

    if ($env === 'demo') {
        if (mpesa_demo_outcome() === 'fail_send') return $fail('The prompt could not be sent to this phone. Check the number and try again.', 'demo: fail_send', '1025');
        $pdo->prepare("UPDATE mpesa_payments SET checkout_request_id=? WHERE id=?")->execute(['demo_' . bin2hex(random_bytes(6)), $pid]);
        return ['ok' => true, 'payment_id' => $pid, 'message' => 'Demo mode: no real money moves. A test result will arrive in a few seconds.'];
    }
    $ts = date('YmdHis'); $sc = (string)get_config('mpesa_shortcode', '174379');
    $ref = $purpose === 'test' ? 'TEST' . $pid : ($bid ? 'HOSTEL' . $bid : 'FEE' . $sid);
    [$c, $r, $err] = mpesa_api('/mpesa/stkpush/v1/processrequest', [
        'BusinessShortCode' => $sc, 'Password' => mpesa_password($ts), 'Timestamp' => $ts, 'TransactionType' => 'CustomerPayBillOnline', 'Amount' => $amount,
        'PartyA' => $phone, 'PartyB' => $sc, 'PhoneNumber' => $phone, 'CallBackURL' => mpesa_callback_url(), 'AccountReference' => substr($ref, 0, 12), 'TransactionDesc' => $purpose === 'test' ? 'Test' : 'Hostel fee']);
    if ($c === -1) return $fail('The payment system is not connected right now, so no prompt was sent and nothing was charged. Please tell the technical support person.', 'no token');
    if ($c === 0)  return $fail('Could not reach M-Pesa (network problem). No prompt was sent and nothing was charged. Please try again in a moment.', 'network: ' . $err);
    if ($c === 200 && ($r['ResponseCode'] ?? '') === '0' && !empty($r['CheckoutRequestID'])) {
        $pdo->prepare("UPDATE mpesa_payments SET checkout_request_id=?, merchant_request_id=? WHERE id=?")->execute([$r['CheckoutRequestID'], $r['MerchantRequestID'] ?? null, $pid]);
        return ['ok' => true, 'payment_id' => $pid, 'message' => 'Prompt sent to ' . mpesa_phone_pretty($phone, true) . '. Enter the M-Pesa PIN on the phone.'];
    }
    $raw = (string)($r['errorMessage'] ?? $r['ResponseDescription'] ?? $r['CustomerMessage'] ?? ('HTTP ' . $c)); $ec = (string)($r['errorCode'] ?? $r['ResponseCode'] ?? $c);
    mpesa_tech_log('error', "STK push refused: HTTP $c code $ec · $raw");
    if (preg_match('/lock subscriber|in process|being processed/i', $raw)) return $fail('Another M-Pesa request is already open on that phone. Wait a minute, then try again.', $raw, '1001');
    if (preg_match('/phone|msisdn|party ?a/i', $raw))      return $fail('M-Pesa says that phone number cannot receive a prompt. Check the number and try again.', $raw, $ec);
    if (preg_match('/amount/i', $raw))                     return $fail('M-Pesa did not accept the amount. Please tell the technical support person.', $raw, $ec);
    if ($c >= 500 || $c === 429)                           return $fail('M-Pesa is busy right now. No prompt was sent and nothing was charged. Please try again in a minute.', $raw, $ec);
    if (preg_match('/credentials|passkey|shortcode|invalid|authori[sz]/i', $raw)) return $fail('The payment system settings need attention, so no prompt was sent. Nothing was charged. Please tell the technical support person.', $raw, $ec);
    return $fail('M-Pesa could not send the prompt. Nothing was charged. Please check the number and try again.', $raw, $ec);
}

/** Student page entry point: the fee for one booking. */
function mpesa_start(PDO $pdo, array $booking, string $phone): array {
    return mpesa_start_payment($pdo, ['purpose' => 'late_fee', 'amount' => (int)$booking['penalty_amount'], 'phone' => $phone, 'student_id' => (int)$booking['student_id'],
        'booking_id' => (int)$booking['id'], 'semester_id' => (int)($booking['semester_id'] ?: (active_semester_id() ?? 0)), 'via' => 'student']);
}

/* ====================================================================== finish a payment (callback or query), idempotent */
/**
 * Apply a final result. $outcome: success | failed | cancelled | timeout.
 * Safe to call any number of times for the same payment (Safaricom retries callbacks).
 * A success that arrives AFTER we had already given up (timeout / cancelled / replaced) is still applied.
 * Returns ['status'=>final status, 'applied'=>bool].
 */
function mpesa_finish(PDO $pdo, int $paymentId, string $outcome, ?string $receipt, string $friendly, ?int $paidAmount = null, ?string $code = null, ?string $raw = null, string $via = 'callback'): array {
    $pdo->beginTransaction();
    try {
        $q = $pdo->prepare("SELECT * FROM mpesa_payments WHERE id=? FOR UPDATE"); $q->execute([$paymentId]); $p = $q->fetch();
        if (!$p) { $pdo->commit(); return ['status' => 'none', 'applied' => false]; }
        $was = $p['status'];

        if ($was === 'success') {                       // already paid. The only job left: swap our placeholder reference for the real receipt
            if ($outcome === 'success' && $receipt && $p['confirmed_via'] === 'query' && $p['receipt'] !== $receipt) {
                $pdo->prepare("UPDATE mpesa_payments SET receipt=?, confirmed_via='callback' WHERE id=?")->execute([$receipt, $paymentId]);
                $pdo->prepare("UPDATE bookings SET mpesa_ref=? WHERE mpesa_ref=?")->execute([$receipt, $p['receipt']]);
            }
            $pdo->commit(); return ['status' => 'success', 'applied' => true];
        }
        if ($was === 'review') { $pdo->commit(); return ['status' => 'review', 'applied' => false]; }

        if ($outcome !== 'success') {                   // a real failure only counts while we are still waiting
            if ($was === 'pending') {
                $pdo->prepare("UPDATE mpesa_payments SET status=?, result_desc=?, result_code=?, result_raw=?, completed_at=NOW() WHERE id=?")
                    ->execute([$outcome, mb_substr($friendly, 0, 250), $code, mb_substr((string)$raw, 0, 250), $paymentId]);
            }
            $pdo->commit();
            if ($was === 'pending') mpesa_after($pdo, $p, $outcome, $friendly, null, false);
            return ['status' => $was === 'pending' ? $outcome : $was, 'applied' => false];
        }

        /* ---------- money arrived ---------- */
        $required = (int)$p['amount']; $late = $was !== 'pending'; $note = ''; $review = 0; $final = 'success';
        if (!$receipt) $receipt = 'Q' . strtoupper(substr(md5($paymentId . '|' . $p['checkout_request_id']), 0, 9));   // confirmed by Safaricom's status check; the real receipt replaces this when the callback lands
        if ($paidAmount !== null && $paidAmount < $required) { $final = 'review'; $review = 1; $note = "Paid KES $paidAmount but the fee is KES $required. Nothing was applied; decide whether to collect the balance or refund."; }
        if ($final === 'success') {
            $d = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE receipt=? AND id<>?"); $d->execute([$receipt, $paymentId]);
            if ((int)$d->fetchColumn() > 0) { $final = 'review'; $review = 1; $note = "Receipt $receipt was already used for another payment. Check it before applying."; }
        }
        $applied = false; $moved = 0; $bookingApplied = false; $credited = false;
        if ($final === 'success' && $p['purpose'] === 'late_fee' && $p['student_id']) {
            $sem = (int)($p['semester_id'] ?: booking_sem_id($pdo)); $sid = (int)$p['student_id'];
            $st = $pdo->prepare("SELECT late_fee_paid_sem FROM students WHERE id=? FOR UPDATE"); $st->execute([$sid]); $paidSem = (int)$st->fetchColumn();
            $already = $sem > 0 && $paidSem === $sem;
            if ($p['booking_id']) {
                $u = $pdo->prepare("UPDATE bookings SET mpesa_ref=?, status='pending_approval' WHERE id=? AND status='pending_payment'"); $u->execute([$receipt, $p['booking_id']]);
                $bookingApplied = $u->rowCount() > 0;
            }
            if ($bookingApplied) {
                $applied = true; if (!$already) $credited = true;
                if ($already) { $review = 1; $note = 'The late fee had already been paid earlier, so this payment is a duplicate. A refund of KES ' . $required . ' may be due.'; }
            } elseif ($already) {
                $final = 'review'; $review = 1; $note = 'The late fee for this semester was already paid, so this payment is a duplicate. A refund of KES ' . $required . ' is due.';
            } else {
                $credited = true; $applied = true;
                if ($p['booking_id']) { $review = 1; $note = 'The booking was no longer waiting for payment. The fee was credited to the student for this semester instead.'; }
                else { $m = $pdo->prepare("UPDATE bookings SET mpesa_ref=?, status='pending_approval' WHERE student_id=? AND status='pending_payment'"); $m->execute([$receipt, $sid]); $moved = $m->rowCount(); }
            }
            if ($credited && $sem > 0) $pdo->prepare("UPDATE students SET late_fee_paid_sem=?, late_fee_paid_at=NOW() WHERE id=?")->execute([$sem, $sid]);
        } elseif ($final === 'success') $applied = true;   // a test payment has nothing to apply
        $desc = $final === 'review' ? 'Money was received but needs the hostel office to confirm it.' : 'Payment received.';
        $pdo->prepare("UPDATE mpesa_payments SET status=?, receipt=?, result_desc=?, result_code=?, result_raw=?, completed_at=NOW(), confirmed_via=?, needs_review=?, review_note=? WHERE id=?")
            ->execute([$final, $receipt, $desc, $code ?? '0', mb_substr((string)$raw, 0, 250), $via, $review, $note !== '' ? mb_substr($note, 0, 250) : null, $paymentId]);
        $pdo->commit();
        $p['receipt'] = $receipt;
        mpesa_after($pdo, $p, $final, $desc, $note, $bookingApplied || $moved > 0, $late);
        if ($final === 'success' && ($bookingApplied || $moved > 0) && !empty($p['student_id'])) { try { require_once __DIR__ . '/notify.php'; booking_emails_latest($pdo, (int)$p['student_id'], 'pending_approval', 'submitted'); } catch (Throwable $e) {} }
        return ['status' => $final, 'applied' => $applied];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mpesa_tech_log('error', "finish #$paymentId failed: " . get_class($e) . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        return ['status' => 'error', 'applied' => false];
    }
}

/** Messages and audit trail after a payment reaches its final state. Never throws. */
function mpesa_after(PDO $pdo, array $p, string $status, string $friendly, ?string $note, bool $awaitingApproval, bool $late = false): void {
    try {
        require_once __DIR__ . '/notify.php';
        $sid = $p['student_id'] ? (int)$p['student_id'] : null; $uid = $p['initiated_by_user_id'] ? (int)$p['initiated_by_user_id'] : null;
        $s = null; if ($sid) { $i = $pdo->prepare("SELECT id, full_name, reg_number, gender FROM students WHERE id=?"); $i->execute([$sid]); $s = $i->fetch() ?: null; }
        $who = $s ? "{$s['full_name']} ({$s['reg_number']})" : 'the test number';
        $tag = ($p['env'] ?? '') === 'demo' ? ' (DEMO)' : '';
        if ($status === 'success') {
            if ($s) notify_student($pdo, $sid, 'Payment received', "KES {$p['amount']} received ({$p['receipt']}). " . ($awaitingApproval ? 'Your booking is waiting for approval.' : 'You can now choose your room.'), '/student/book.php', 'payment');
            if ($s && $awaitingApproval) notify_staff($pdo, ['secretary', 'dean'], $s['gender'], 'Paid booking awaiting approval', "{$s['full_name']} paid KES {$p['amount']}.", '/user/approvals.php', 'approval');
            if ($uid) notify_user($pdo, $uid, 'Payment received', "$who paid KES {$p['amount']} ({$p['receipt']}).", '/user/collect_payment.php', 'payment');
            audit_log($uid, $sid, $p['purpose'] === 'test' ? 'mpesa_test_ok' : 'mpesa_paid', "M-Pesa {$p['receipt']} KES {$p['amount']}" . ($p['booking_id'] ? " for booking #{$p['booking_id']}" : '') . ($late ? ' (arrived after the wait ended)' : '') . $tag . ($note ? " · $note" : ''));
            if ($note) notify_staff($pdo, ['dean', 'secretary', 'admin'], $s['gender'] ?? null, 'Payment needs a look', "$who: $note", '/user/collect_payment.php', 'payment');
        } elseif ($status === 'review') {
            if ($s) notify_student($pdo, $sid, 'Payment received: please see the office', "We received KES {$p['amount']} ({$p['receipt']}) but the hostel office must confirm it. Please visit the secretary.", '/student/book.php', 'payment');
            notify_staff($pdo, ['dean', 'secretary', 'admin'], $s['gender'] ?? null, 'Payment needs attention', "$who: $note", '/user/collect_payment.php', 'payment');
            audit_log($uid, $sid, 'mpesa_review', "M-Pesa {$p['receipt']} KES {$p['amount']}: $note");
        } else {
            if ($s && $p['purpose'] !== 'test') notify_student($pdo, $sid, 'Payment not completed', $friendly, '/student/book.php', 'payment');
            if ($uid) notify_user($pdo, $uid, 'Payment not completed', "$who: $friendly", '/user/collect_payment.php', 'payment');
            audit_log($uid, $sid, 'mpesa_' . $status, "M-Pesa KES {$p['amount']} $status: $friendly$tag");
        }
    } catch (Throwable $e) { error_log('mpesa_after: ' . $e->getMessage()); }
}

/* ====================================================================== ask Safaricom (used when the callback is late or never comes) */
function mpesa_query(PDO $pdo, array $p): void {
    if ($p['status'] !== 'pending') return;
    $age = time() - strtotime($p['created_at']);
    if ($p['env'] === 'demo') {                          // simulated outcomes so every message can be tried without money
        if ($age < 6) return;
        $o = mpesa_demo_outcome(); $ref = 'DEMO' . strtoupper(substr(md5($p['id'] . $p['checkout_request_id']), 0, 6));
        $codes = ['cancelled' => '1032', 'timeout' => '1037', 'balance' => '1', 'wrong_pin' => '2001'];
        if ($o === 'success') mpesa_finish($pdo, (int)$p['id'], 'success', $ref, 'Demo payment approved', (int)$p['amount'], '0', 'demo', 'callback');
        elseif (isset($codes[$o])) { [$s, $m] = mpesa_interpret($codes[$o]); mpesa_finish($pdo, (int)$p['id'], $s, null, $m, null, $codes[$o], 'demo'); }
        return;
    }
    $code = ''; $r = null; $hardCap = mpesa_wait_seconds() + 90;
    if ($p['checkout_request_id'] && $age >= 10 && (!$p['last_check_at'] || time() - strtotime($p['last_check_at']) >= 8)) {
        $pdo->prepare("UPDATE mpesa_payments SET last_check_at=NOW() WHERE id=?")->execute([$p['id']]);
        $ts = date('YmdHis');
        [$c, $r, $err] = mpesa_api('/mpesa/stkpushquery/v1/query', ['BusinessShortCode' => get_config('mpesa_shortcode', '174379'), 'Password' => mpesa_password($ts), 'Timestamp' => $ts, 'CheckoutRequestID' => $p['checkout_request_id']]);
        $code = is_array($r) && isset($r['ResultCode']) ? (string)$r['ResultCode'] : '';
        if ($c === 200 && $code !== '') {
            if (mpesa_still_processing($code, $r)) { /* keep waiting */ }
            elseif ($code === '0') {                     // paid. The receipt number only comes in the callback, so give it a moment, then trust the status check
                if ($age >= 25) mpesa_finish($pdo, (int)$p['id'], 'success', null, 'Payment received.', (int)$p['amount'], '0', 'status check', 'query');
            } else { [$s, $m] = mpesa_interpret($code, (string)($r['ResultDesc'] ?? '')); mpesa_finish($pdo, (int)$p['id'], $s, null, $m, null, $code, (string)($r['ResultDesc'] ?? ''), 'query'); }
        } elseif ($c > 0) {
            mpesa_tech_log('warn', "status check for #{$p['id']} gave HTTP $c " . json_encode($r));   // an unclear answer never fails the payment: the callback may still arrive
        }
    }
    if ($age >= mpesa_wait_seconds()) {                  // nobody answered: stop waiting. A late success is still applied if it ever arrives.
        $q = $pdo->prepare("SELECT status FROM mpesa_payments WHERE id=?"); $q->execute([$p['id']]);
        if ($q->fetchColumn() === 'pending' && ($age >= $hardCap || !mpesa_still_processing($code, $r))) {
            [$s, $m] = mpesa_interpret('1037'); mpesa_finish($pdo, (int)$p['id'], 'timeout', null, $m, null, '1037', 'no answer within ' . $age . 's', 'query');
        }
    }
}

/** Settle abandoned prompts (nobody has the page open). Called from busy places; cheap when there is nothing to do. */
function mpesa_sweep(PDO $pdo): void {
    static $ran = false; if ($ran) return; $ran = true;
    try {
        mpesa_ensure_schema($pdo);
        $q = $pdo->query("SELECT * FROM mpesa_payments WHERE status='pending' AND created_at < DATE_SUB(NOW(), INTERVAL 20 SECOND) AND (last_check_at IS NULL OR last_check_at < DATE_SUB(NOW(), INTERVAL 15 SECOND)) ORDER BY id LIMIT 6");
        foreach ($q->fetchAll() as $p) mpesa_query($pdo, $p);
    } catch (Throwable $e) { error_log('mpesa_sweep: ' . $e->getMessage()); }
}

/* ====================================================================== what to show on screen */
/** Normalised, plain-words view of a payment row. $viewer: 'student' (talks to the payer) or 'staff' (talks about the student). */
function mpesa_view(array $p, string $viewer = 'student'): array {
    $st = $p['status']; $age = max(0, time() - strtotime($p['created_at'])); $wait = mpesa_wait_seconds();
    $you = $viewer === 'student'; $ph = mpesa_phone_pretty($p['phone'], true);
    $v = ['id' => (int)$p['id'], 'status' => $st, 'final' => $st !== 'pending', 'receipt' => $p['receipt'], 'amount' => (int)$p['amount'], 'phone' => $ph, 'seconds_left' => max(0, $wait - $age), 'retry' => false, 'tone' => 'info', 'title' => '', 'message' => ''];
    switch ($st) {
        case 'pending': $v['title'] = 'Waiting for the phone'; $v['message'] = $you ? "A prompt was sent to $ph. Open it, enter your M-Pesa PIN and wait here." : "A prompt was sent to $ph. Ask the student to check the phone and enter their M-Pesa PIN."; break;
        case 'success': $v['tone'] = 'success'; $v['title'] = 'Payment received'; $v['message'] = 'KES ' . number_format((int)$p['amount']) . ' paid' . ($p['receipt'] ? " (receipt {$p['receipt']})." : '.'); break;
        case 'cancelled': $v['tone'] = 'warning'; $v['retry'] = true; $v['title'] = 'Payment cancelled'; $v['message'] = ($p['result_desc'] ?: 'The payment was cancelled.') . ' Nothing was charged.'; break;
        case 'timeout': $v['tone'] = 'warning'; $v['retry'] = true; $v['title'] = 'No answer from the phone'; $v['message'] = ($p['result_desc'] ?: 'The prompt timed out.') . ' Nothing was charged. If money was taken after all, it is applied automatically.'; break;
        case 'review': $v['tone'] = 'warning'; $v['title'] = 'Payment received: office to confirm';
            $v['message'] = $you ? 'We received your money, but the hostel office must confirm it before your booking can move on. Please see the secretary' . ($p['receipt'] ? " and quote receipt {$p['receipt']}." : '.') : (($p['review_note'] ?: 'Money was received but could not be applied automatically.') . ($p['receipt'] ? " Receipt {$p['receipt']}." : '')); break;
        default: $v['tone'] = 'danger'; $v['retry'] = true; $v['title'] = 'Payment not completed'; $v['message'] = ($p['result_desc'] ?: 'The payment did not go through.') . ' Nothing was charged.';
    }
    return $v;
}
