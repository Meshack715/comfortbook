<?php
// includes/student_actions.php — everything a student can DO from the AI chat (book a bed, confirm the code, keep/release,
// pay the late fee). Same rules as api/book_room.php, api/verify_otp.php and api/mpesa_stk.php, but callable from action cards.
require_once __DIR__ . '/beds.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/mpesa.php';

const SA_TOOLS = ['reserve_bed', 'keep_my_room', 'release_my_room', 'verify_booking_code', 'resend_booking_code', 'pay_late_fee', 'submit_mpesa_code'];

function ai_student_tool_defs(): array {
    $s = ['type' => 'string']; $st = ['student'];
    return [
        'reserve_bed' => ['d' => 'Hold ONE specific bed for the student and email them a 6-digit code (needs confirmation). Use the bed_id from find_beds / recommend_beds or from the tapped message "Book bed #123". After it runs, a card to enter the emailed code appears by itself.', 'roles' => $st, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['bed_id' => ['type' => 'integer']], 'required' => ['bed_id']]],
        'keep_my_room' => ['d' => 'Phase 1 (or late): keep the student\'s current room for next semester (needs confirmation). A code card follows.', 'roles' => $st, 'w' => true, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'release_my_room' => ['d' => 'Phase 1 (or late): release the student\'s current room so they can choose a new bed in Phase 2 (needs confirmation). A code card follows.', 'roles' => $st, 'w' => true, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'verify_booking_code' => ['d' => 'Enter the 6-digit code that was emailed, to confirm the booking (needs confirmation). The card has an empty code box the student types in; normally you do not need to call this, the card appears after reserve_bed.', 'roles' => $st, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['code' => $s]]],
        'resend_booking_code' => ['d' => 'Email a fresh 6-digit code for the booking being held (needs confirmation). Use when the student says the code did not arrive or expired.', 'roles' => $st, 'w' => true, 'p' => ['type' => 'object', 'properties' => new stdClass()]],
        'pay_late_fee' => ['d' => 'Send an M-Pesa prompt to the STUDENT\'S OWN phone for the late-booking fee (needs confirmation; the card then shows the live result). Use when my_status says the late fee must be paid, or a booking is waiting for payment. Pass phone if they gave one, else leave empty and they type it in the card.', 'roles' => $st, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['phone' => $s]]],
        'submit_mpesa_code' => ['d' => 'The student already paid by Paybill and gives the 10-character M-Pesa confirmation code (needs confirmation).', 'roles' => $st, 'w' => true,
            'p' => ['type' => 'object', 'properties' => ['mpesa_ref' => $s]]],
    ];
}

function sa_label(string $tool): ?string {
    return ['reserve_bed' => 'Hold this bed', 'keep_my_room' => 'Keep my room', 'release_my_room' => 'Release my room', 'verify_booking_code' => 'Confirm code', 'resend_booking_code' => 'Send a new code', 'pay_late_fee' => 'Send M-Pesa prompt', 'submit_mpesa_code' => 'Submit code'][$tool] ?? null;
}

function sa_student(PDO $pdo, array $ctx): ?array {
    $q = $pdo->prepare("SELECT * FROM students WHERE id=? AND is_active=1"); $q->execute([(int)($ctx['student']['id'] ?? 0)]); return $q->fetch() ?: null;
}
function sa_sem(PDO $pdo): int { $s = (int)($_SESSION['magic_semester_id'] ?? 0); return $s > 0 ? $s : booking_sem_id($pdo); }
function sa_booking(PDO $pdo, int $sid, int $sem, ?string $status = null): ?array {
    $sql = "SELECT * FROM bookings WHERE student_id=? AND semester_id=? AND status " . ($status ? "=?" : "IN ('pending_otp','pending_payment','pending_approval','confirmed')") . " ORDER BY id DESC LIMIT 1";
    $q = $pdo->prepare($sql); $q->execute($status ? [$sid, $sem, $status] : [$sid, $sem]); return $q->fetch() ?: null;
}
function sa_make_card(PDO $pdo, array $ctx, string $tool, array $args, string $summary): array {
    $pdo->prepare("INSERT INTO ai_actions (user_id,student_id,tool,args_json,summary) VALUES (?,?,?,?,?)")->execute([null, (int)$ctx['student']['id'], $tool, json_encode($args ?: new stdClass()), mb_substr($summary, 0, 300)]);
    return ai_card(ai_tool_defs()[$tool], $tool, (int)$pdo->lastInsertId(), $summary, $args);
}

/** Can this student start a booking action right now? Returns [phaseOrLate, errorMessage]. */
function sa_gate(PDO $pdo, array $stu, int $sem, bool $needRoomChoice): array {
    beds_expire_holds($pdo);
    $BS = booking_state($pdo, $stu, $sem);
    if ($BS['phase'] !== null) return [['BS' => $BS, 'late' => false], null];
    if ($BS['state'] === 'late') {
        if ($BS['needs_fee']) return [null, 'The booking windows have ended, so the late fee of KES ' . number_format($BS['fee']) . ' must be paid first. I can send the M-Pesa prompt to your phone right here: just tell me your number.'];
        return [['BS' => $BS, 'late' => true], null];
    }
    return [null, $BS['headline'] . '. ' . $BS['detail']];
}

function sa_prepare(PDO $pdo, array $ctx, string $name, array $a): array {
    $stu = sa_student($pdo, $ctx); if (!$stu) return [null, 'Student account not found.'];
    $sem = sa_sem($pdo); $sid = (int)$stu['id'];
    switch ($name) {
        case 'reserve_bed': case 'keep_my_room': case 'release_my_room':
            if (sa_booking($pdo, $sid, $sem)) return [null, 'You already have a booking in progress for this semester. Check its status first.'];
            [$g, $err] = sa_gate($pdo, $stu, $sem, true); if ($err) return [null, $err];
            if ($name === 'reserve_bed') {
                if (($g['BS']['phase'] ?? null) === 1) return [null, 'Phase 1 is only for keeping or releasing your current room. Choosing a new bed opens in Phase 2.'];
                if ($stu['phase1_action'] === 'keep') return [null, 'You kept your room in Phase 1, so no new bed is needed.'];
                $q = $pdo->prepare("SELECT bd.id, bd.label, bd.kind, bd.status, r.room_number, r.floor_no, bl.name block FROM beds bd JOIN rooms r ON r.id=bd.room_id JOIN blocks bl ON bl.id=r.block_id WHERE bd.id=? AND bd.is_active=1 AND r.gender=?");
                $q->execute([(int)($a['bed_id'] ?? 0), $stu['gender']]); $b = $q->fetch();
                if (!$b) return [null, 'That bed is not available in your wing. Let me find you another one.'];
                if (!in_array($b['status'], ['vacant', 'available_p2'], true)) return [null, 'That bed was just taken. Let me find you another one.'];
                return ["Hold {$b['block']} · Room {$b['room_number']} · Floor {$b['floor_no']} · Bed {$b['label']} (" . bed_kind_label($b['kind']) . ") for you and email you a 6-digit code to confirm", null];
            }
            if (!$stu['current_room_id']) return [null, 'You have no current room to keep or release.'];
            if ($stu['phase1_action'] !== 'none') return [null, 'You already chose to ' . $stu['phase1_action'] . ' your room.'];
            if (!$g['late'] && ($g['BS']['phase'] ?? null) !== 1) return [null, 'Keeping or releasing is only possible in Phase 1.'];
            return [$name === 'keep_my_room' ? 'Keep your current room for next semester (a 6-digit code will be emailed to confirm)' : 'Release your current room so you can choose a new bed in Phase 2 (a 6-digit code will be emailed to confirm)', null];
        case 'verify_booking_code': case 'resend_booking_code':
            return sa_booking($pdo, $sid, $sem, 'pending_otp') ? [$name === 'verify_booking_code' ? 'Confirm your booking with the 6-digit code from your email' : 'Email you a fresh 6-digit code', null] : [null, 'You have no booking waiting for a code.'];
        case 'pay_late_fee': {
            $b = sa_booking($pdo, $sid, $sem, 'pending_payment'); $BS = booking_state($pdo, $stu, $sem);
            $amt = $b ? ((int)$b['penalty_amount'] ?: (int)$BS['fee']) : ($BS['needs_fee'] ? (int)$BS['fee'] : 0);
            if ($amt < 1) return [null, $BS['fee_paid'] ? 'Your late fee is already paid, nothing more to pay.' : 'No fee is due from you right now.'];
            $ph = trim((string)($a['phone'] ?? '')); if ($ph !== '' && !mpesa_phone($ph)) return [null, 'That does not look like a Safaricom number. Use something like 0712 345 678.'];
            return ["Send an M-Pesa prompt for KES " . number_format($amt) . " (late booking fee) to " . ($ph !== '' ? mpesa_phone_pretty(mpesa_phone($ph)) : 'the phone number you enter below'), null];
        }
        case 'submit_mpesa_code':
            if (!sa_booking($pdo, $sid, $sem, 'pending_payment')) return [null, 'No booking is waiting for payment.'];
            return ['Submit your M-Pesa confirmation code for the late fee', null];
    }
    return [null, 'unknown action'];
}

function sa_execute(PDO $pdo, array $ctx, string $name, array $a): array {
    $stu = sa_student($pdo, $ctx); if (!$stu) return ['ok' => false, 'message' => 'Student account not found.'];
    $sem = sa_sem($pdo); $sid = (int)$stu['id'];
    $retry = function (string $msg, array $args = []) use ($pdo, $ctx, $name) { $d = ai_tool_defs()[$name]; return ['ok' => false, 'message' => $msg, 'next_card' => ai_card($d, $name, sa_new_action($pdo, $ctx, $name, $args, sa_label($name) ?: 'Try again'), 'Try again: ' . strtolower(sa_label($name) ?: $name), $args)]; };
    try {
        switch ($name) {
            case 'reserve_bed': case 'keep_my_room': case 'release_my_room': {
                [$p, $err] = sa_prepare($pdo, $ctx, $name, $a); if ($err) return ['ok' => false, 'message' => $err];
                $r = sa_start_booking($pdo, $stu, $sem, $name, (int)($a['bed_id'] ?? 0)); if (!$r['ok']) return $r;
                $r['next_card'] = sa_make_card($pdo, $ctx, 'verify_booking_code', ['code' => ''], 'Type the 6-digit code we emailed to ' . $stu['email']);
                return $r;
            }
            case 'resend_booking_code': {
                $b = sa_booking($pdo, $sid, $sem, 'pending_otp'); if (!$b) return ['ok' => false, 'message' => 'You have no booking waiting for a code.'];
                $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); $ttl = (int)get_config('otp_ttl_minutes', '10');
                $pdo->prepare("UPDATE bookings SET otp_code=?, otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")->execute([$otp, $ttl, $b['id']]);
                @send_otp_email($stu['email'], $stu['full_name'], $otp);
                return ['ok' => true, 'message' => 'A fresh code is on its way to ' . $stu['email'] . '.', 'next_card' => sa_make_card($pdo, $ctx, 'verify_booking_code', ['code' => ''], 'Type the 6-digit code we emailed to ' . $stu['email'])];
            }
            case 'verify_booking_code': {
                $b = sa_booking($pdo, $sid, $sem, 'pending_otp'); if (!$b) return ['ok' => false, 'message' => 'Booking not found or already verified.'];
                $code = trim((string)($a['code'] ?? ''));
                if (!preg_match('/^\d{6}$/', $code)) return $retry('Enter exactly 6 digits.');
                if (strtotime($b['otp_expires_at']) < time()) return $retry('That code expired. Ask me to send a new one.');
                if (!hash_equals((string)$b['otp_code'], $code)) return $retry('That code is not right. Check your email and try again.');
                return sa_after_verify($pdo, $ctx, $stu, $b);
            }
            case 'pay_late_fee': {
                $phone = trim((string)($a['phone'] ?? '')); if (!mpesa_phone($phone)) return $retry('Enter a valid Safaricom number like 0712 345 678. Nothing was charged.');
                $r = sa_start_payment($pdo, $stu, $sem, $phone); if (!$r['ok']) return $r['retry'] ? $retry($r['message'], ['phone' => $phone]) : $r;
                return $r;
            }
            case 'submit_mpesa_code': {
                $b = sa_booking($pdo, $sid, $sem, 'pending_payment'); if (!$b) return ['ok' => false, 'message' => 'No booking is waiting for payment.'];
                $ref = strtoupper(trim((string)($a['mpesa_ref'] ?? ''))); if (!preg_match('/^[A-Z0-9]{10}$/', $ref)) return $retry('An M-Pesa code is 10 letters and numbers, like QGH7X2K9LM.');
                $d = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE mpesa_ref=? AND id<>?"); $d->execute([$ref, $b['id']]); if ((int)$d->fetchColumn() > 0) return ['ok' => false, 'message' => 'That code was already used.'];
                $pdo->prepare("UPDATE bookings SET mpesa_ref=?, status='pending_approval' WHERE id=?")->execute([$ref, $b['id']]);
                audit_log(null, $sid, 'mpesa_submitted', "Ref $ref for booking #{$b['id']} (via assistant)");
                sa_tell_staff($pdo, $stu, (int)$b['id']);
                return ['ok' => true, 'message' => 'Code received. Your booking is now waiting for approval, and we emailed you and the hostel Secretary.'];
            }
        }
    } catch (Throwable $e) {
        error_log('sa_execute ' . $name . ': ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine());
        return ['ok' => false, 'message' => 'That did not work and nothing was changed. Please try again.'];
    }
    return ['ok' => false, 'message' => 'Unknown action.'];
}

function sa_new_action(PDO $pdo, array $ctx, string $tool, array $args, string $summary): int {
    $pdo->prepare("INSERT INTO ai_actions (user_id,student_id,tool,args_json,summary) VALUES (?,?,?,?,?)")->execute([null, (int)$ctx['student']['id'], $tool, json_encode($args ?: new stdClass()), mb_substr($summary, 0, 300)]);
    return (int)$pdo->lastInsertId();
}

function sa_otp(PDO $pdo, int $bookingId): string {
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT); $ttl = (int)get_config('otp_ttl_minutes', '10');
    $pdo->prepare("UPDATE bookings SET otp_code=?, otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")->execute([$otp, $ttl, $bookingId]); return $otp;
}

function sa_start_booking(PDO $pdo, array $stu, int $sem, string $tool, int $bedId): array {
    $sid = (int)$stu['id']; $ttl = (int)get_config('otp_ttl_minutes', '10');
    [$g, $err] = sa_gate($pdo, $stu, $sem, true); if ($err) return ['ok' => false, 'message' => $err];
    if ($g['late']) $pdo->prepare("UPDATE students SET late_booker=1 WHERE id=?")->execute([$sid]);
    $pdo->beginTransaction();
    try {
        if ($tool === 'keep_my_room' || $tool === 'release_my_room') {
            $keep = $tool === 'keep_my_room';
            $pdo->prepare("UPDATE students SET phase1_action=? " . ($keep ? '' : ', current_room_id=NULL, current_bed_id=NULL ') . "WHERE id=?")->execute([$keep ? 'keep' : 'release', $sid]);
            $pdo->prepare("INSERT INTO bookings (student_id, room_id, bed_id, semester_id, phase, action, status) VALUES (?, ?, ?, ?, 1, ?, 'pending_otp')")->execute([$sid, $stu['current_room_id'], $keep ? ($stu['current_bed_id'] ?: null) : null, $sem, $keep ? 'keep' : 'release']);
            $bid = (int)$pdo->lastInsertId(); $otp = sa_otp($pdo, $bid); $pdo->commit();
            if (!$keep) beds_reconcile($pdo);
            @send_otp_email($stu['email'], $stu['full_name'], $otp);
            return ['ok' => true, 'message' => ($keep ? 'Room kept for now.' : 'Room released for now.') . " I emailed a 6-digit code to {$stu['email']}. Enter it below within $ttl minutes to finish.", 'booking_id' => $bid];
        }
        $st = $pdo->prepare("SELECT bd.id AS bed_id, bd.label, bd.kind, r.id AS room_id, r.room_number, bl.name AS block_name FROM beds bd JOIN rooms r ON r.id=bd.room_id JOIN blocks bl ON bl.id=r.block_id WHERE bd.id=? AND bd.is_active=1 AND r.gender=?");
        $st->execute([$bedId, $stu['gender']]); $bed = $st->fetch();
        if (!$bed) { $pdo->rollBack(); return ['ok' => false, 'message' => 'Bed not available or wrong wing.']; }
        if (!bed_claim($pdo, $bedId, $sid)) { $pdo->rollBack(); return ['ok' => false, 'message' => 'Just taken! Ask me for another bed.']; }
        $feePaid = (int)($stu['late_fee_paid_sem'] ?? 0) === $sem && $sem > 0;
        $late = !empty($stu['late_booker']) || $g['late']; $penalty = ($late && !$feePaid) ? (int)get_config('penalty_amount', '200') : 0;
        $pdo->prepare("INSERT INTO bookings (student_id, room_id, bed_id, semester_id, phase, action, status, penalty_amount) VALUES (?, ?, ?, ?, 2, 'book', 'pending_otp', ?)")->execute([$sid, $bed['room_id'], $bedId, $sem, $penalty]);
        $bid = (int)$pdo->lastInsertId();
        if ($feePaid && $late) { $rc = $pdo->prepare("SELECT receipt FROM mpesa_payments WHERE student_id=? AND purpose='late_fee' AND status='success' ORDER BY id DESC LIMIT 1"); $rc->execute([$sid]); if ($r0 = $rc->fetchColumn()) $pdo->prepare("UPDATE bookings SET mpesa_ref=? WHERE id=?")->execute([$r0, $bid]); }
        $otp = sa_otp($pdo, $bid); beds_sync_room($pdo, (int)$bed['room_id']); $pdo->commit();
        @send_otp_email($stu['email'], $stu['full_name'], $otp);
        return ['ok' => true, 'message' => "Bed held: {$bed['block_name']} Room {$bed['room_number']} · Bed {$bed['label']}. I emailed a 6-digit code to {$stu['email']}. Enter it below within $ttl minutes to keep the bed.", 'booking_id' => $bid];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function sa_tell_staff(PDO $pdo, array $stu, int $bookingId): void {
    try {
        $i = $pdo->prepare("SELECT r.room_number FROM bookings bk JOIN rooms r ON r.id=bk.room_id WHERE bk.id=?"); $i->execute([$bookingId]);
        notify_staff($pdo, ['secretary', 'dean'], $stu['gender'] ?? null, 'Booking awaiting approval', $stu['full_name'] . ' finished a booking for room ' . $i->fetchColumn() . '.', '/user/approvals.php', 'approval');
    } catch (Throwable $e) {}
    booking_emails($pdo, $bookingId, 'submitted');
}

function sa_after_verify(PDO $pdo, array $ctx, array $stu, array $b): array {
    $sid = (int)$stu['id']; $bid = (int)$b['id'];
    if ($b['action'] === 'release') {
        $pdo->prepare("UPDATE bookings SET status='confirmed', otp_verified_at=NOW(), approved_at=NOW() WHERE id=?")->execute([$bid]);
        @send_mail($stu['email'], $stu['full_name'], 'ComfortBook — Room released', email_template('Room Released', '<p>Hello <strong>' . h($stu['full_name']) . '</strong>,</p><p>Your room has been released. You can choose a new bed when Phase 2 opens, or ask the assistant to find one.</p>'));
        return ['ok' => true, 'message' => 'Verified. Your room is released. When Phase 2 opens, ask me and I will find you a new bed.'];
    }
    $cr = $pdo->prepare("SELECT late_fee_paid_sem FROM students WHERE id=?"); $cr->execute([$sid]); $paidSem = (int)$cr->fetchColumn();
    $feePaid = $paidSem > 0 && $paidSem === (int)$b['semester_id'];
    if ((int)$b['penalty_amount'] > 0 && !$feePaid) {
        $pdo->prepare("UPDATE bookings SET status='pending_payment', otp_verified_at=NOW() WHERE id=?")->execute([$bid]);
        return ['ok' => true, 'message' => 'Code accepted. One last step: the late booking fee of KES ' . number_format((int)$b['penalty_amount']) . '.',
            'next_card' => sa_make_card($pdo, $ctx, 'pay_late_fee', ['phone' => ''], 'Send an M-Pesa prompt for KES ' . number_format((int)$b['penalty_amount']) . ' to the phone number you enter')];
    }
    $pdo->prepare("UPDATE bookings SET status='pending_approval', otp_verified_at=NOW() WHERE id=?")->execute([$bid]);
    sa_tell_staff($pdo, $stu, $bid);
    return ['ok' => true, 'message' => 'Verified. Your booking is now waiting for the Secretary to approve it. We emailed you and the hostel office, and you will get another email when it is decided.'];
}

function sa_start_payment(PDO $pdo, array $stu, int $sem, string $phone): array {
    $sid = (int)$stu['id']; mpesa_ensure_schema($pdo);
    $n = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE student_id=? AND purpose='late_fee' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $n->execute([$sid]);
    if ((int)$n->fetchColumn() >= 6) return ['ok' => false, 'retry' => false, 'message' => 'Too many tries in a short time. Wait a few minutes, or ask the Secretary to send the prompt.'];
    $b = sa_booking($pdo, $sid, $sem, 'pending_payment');
    if ($b) { $r = mpesa_start($pdo, $b, $phone); $bid = (int)$b['id']; }
    else {
        $BS = booking_state($pdo, $stu, $sem);
        if ($BS['fee_paid']) return ['ok' => false, 'retry' => false, 'message' => 'Your late fee is already paid. Ask me to find you a bed.'];
        if (!$BS['needs_fee']) return ['ok' => false, 'retry' => false, 'message' => 'No late fee is due right now.'];
        $r = mpesa_start_payment($pdo, ['purpose' => 'late_fee', 'amount' => (int)$BS['fee'], 'phone' => $phone, 'student_id' => $sid, 'booking_id' => null, 'semester_id' => (int)$BS['target']['id'], 'via' => 'student']); $bid = 0;
    }
    if (empty($r['ok'])) return ['ok' => false, 'retry' => true, 'message' => $r['message'] ?? 'The prompt could not be sent. Nothing was charged.'];
    return ['ok' => true, 'message' => $r['message'], 'pay' => ['id' => (int)($r['payment_id'] ?? 0), 'student' => true, 'booking_id' => $bid]];
}
