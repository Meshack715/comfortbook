<?php
// api/book_room.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/beds.php';
require_once __DIR__ . '/../includes/notify.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['magic_student_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'Session expired. Reopen your email link.']);
    exit;
}

$sid       = (int)$_SESSION['magic_student_id'];
$semesterId = (int)($_SESSION['magic_semester_id'] ?? 0);

if ($semesterId <= 0) {
    echo json_encode(['ok'=>false, 'error'=>'No semester attached to this link.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$sid]);
$student = $stmt->fetch();
if (!$student) {
    echo json_encode(['ok'=>false, 'error'=>'Student not found.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';
$roomId = (int)($body['room_id'] ?? 0);
$bedId  = (int)($body['bed_id'] ?? 0);
beds_expire_holds($pdo);

$BS = booking_state($pdo, $student, $semesterId);   // the same rules the student page shows
$phase = $BS['phase'];                               // per-wing live phase (1, 2 or null)
$lateMode = false;
if ($phase === null) {
    if ($BS['state'] === 'late') {                   // windows are over: allowed, but only after the late fee is paid
        if ($BS['needs_fee']) {
            echo json_encode(['ok'=>false, 'needs_fee'=>true, 'fee'=>$BS['fee'], 'error'=>'The booking windows have ended. Pay the late fee of KES ' . number_format($BS['fee']) . ' first, then you can book. Open My booking to pay.']);
            exit;
        }
        $lateMode = true;
    } else {
        echo json_encode(['ok'=>false, 'error'=>$BS['headline'] . '. ' . $BS['detail']]);
        exit;
    }
}
if ($lateMode) $pdo->prepare("UPDATE students SET late_booker=1 WHERE id=?")->execute([$sid]);

// Already have a booking for this semester?
$stmt = $pdo->prepare("
    SELECT id FROM bookings
    WHERE student_id = ? AND semester_id = ?
      AND status IN ('pending_otp','pending_payment','pending_approval','confirmed')
    LIMIT 1
");
$stmt->execute([$sid, $semesterId]);
if ($stmt->fetch()) {
    echo json_encode(['ok'=>false, 'error'=>'You already have a booking for this semester.']);
    exit;
}

// ==========================================================
// PHASE 1 — keep or release
// ==========================================================
if (($phase === 1 || $lateMode) && in_array($action, ['keep','release'], true)) {
    if ($student['phase1_action'] !== 'none') { echo json_encode(['ok'=>false, 'error'=>'You already chose to ' . $student['phase1_action'] . ' your room.']); exit; }
    if (!$student['current_room_id']) {
        echo json_encode(['ok'=>false, 'error'=>'You have no current room to keep or release.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'keep') {
            $pdo->prepare("UPDATE students SET phase1_action='keep' WHERE id=?")->execute([$sid]);

            $pdo->prepare("
                INSERT INTO bookings (student_id, room_id, bed_id, semester_id, phase, action, status)
                VALUES (?, ?, ?, ?, 1, 'keep', 'pending_otp')
            ")->execute([$sid, $student['current_room_id'], $student['current_bed_id'] ?: null, $semesterId]);
        } else {
            $pdo->prepare("UPDATE students SET phase1_action='release', current_room_id=NULL, current_bed_id=NULL WHERE id=?")
                ->execute([$sid]);

            $pdo->prepare("
                INSERT INTO bookings (student_id, room_id, semester_id, phase, action, status)
                VALUES (?, ?, ?, 1, 'release', 'pending_otp')
            ")->execute([$sid, $student['current_room_id'], $semesterId]);
            beds_reconcile($pdo);     // the vacated bed returns to the Phase 2 pool
        }
        $bookingId = (int)$pdo->lastInsertId();

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int)get_config('otp_ttl_minutes', '10');
        $pdo->prepare("UPDATE bookings SET otp_code=?, otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")
            ->execute([$otp, $ttl, $bookingId]);

        $pdo->commit();

        @send_otp_email($student['email'], $student['full_name'], $otp);

        echo json_encode(['ok'=>true, 'booking_id'=>$bookingId]);
        exit;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Book phase 1: '.$e->getMessage());
        echo json_encode(['ok'=>false, 'error'=>'Booking failed.']);
        exit;
    }
}

// ==========================================================
// PHASE 2 — atomic claim of ONE BED
// ==========================================================
if (($phase === 2 || $lateMode) && $action === 'book' && ($bedId > 0 || $roomId > 0)) {
    if ($student['phase1_action'] === 'keep') {
        echo json_encode(['ok'=>false, 'error'=>'You kept your room — no new booking needed.']);
        exit;
    }
    // A room id alone (old list UI) means "the first free bed in that room"
    if ($bedId <= 0) {
        $f = $pdo->prepare("SELECT id FROM beds WHERE room_id=? AND is_active=1 AND status IN ('vacant','available_p2') ORDER BY id LIMIT 1");
        $f->execute([$roomId]);
        $bedId = (int)$f->fetchColumn();
        if ($bedId <= 0) { echo json_encode(['ok'=>false, 'error'=>'Just taken! Choose another room.']); exit; }
    }

    $stmt = $pdo->prepare("
        SELECT bd.id AS bed_id, bd.label, bd.kind, r.id AS room_id, r.room_number, r.gender, bl.name AS block_name
        FROM beds bd JOIN rooms r ON r.id = bd.room_id JOIN blocks bl ON bl.id = r.block_id
        WHERE bd.id = ? AND bd.is_active = 1 AND r.gender = ?
    ");
    $stmt->execute([$bedId, $student['gender']]);
    $bed = $stmt->fetch();
    if (!$bed) {
        echo json_encode(['ok'=>false, 'error'=>'Bed not available or wrong wing.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if (!bed_claim($pdo, $bedId, $sid)) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false, 'error'=>'Just taken! Choose another bed.', 'taken'=>true]);
            exit;
        }

        $feePaid = (int)($student['late_fee_paid_sem'] ?? 0) === $semesterId && $semesterId > 0;     // paid earlier (e.g. the secretary prompted them)
        $penalty = (!empty($student['late_booker']) && !$feePaid) ? (int)get_config('penalty_amount', '200') : 0;

        $pdo->prepare("
            INSERT INTO bookings (student_id, room_id, bed_id, semester_id, phase, action, status, penalty_amount)
            VALUES (?, ?, ?, ?, 2, 'book', 'pending_otp', ?)
        ")->execute([$sid, $bed['room_id'], $bedId, $semesterId, $penalty]);
        $bookingId = (int)$pdo->lastInsertId();
        if ($feePaid && !empty($student['late_booker'])) {       // show the fee receipt on the booking
            $rc = $pdo->prepare("SELECT receipt FROM mpesa_payments WHERE student_id=? AND purpose='late_fee' AND status='success' ORDER BY id DESC LIMIT 1"); $rc->execute([$sid]);
            if ($r0 = $rc->fetchColumn()) $pdo->prepare("UPDATE bookings SET mpesa_ref=? WHERE id=?")->execute([$r0, $bookingId]);
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int)get_config('otp_ttl_minutes', '10');
        $pdo->prepare("UPDATE bookings SET otp_code=?, otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?")
            ->execute([$otp, $ttl, $bookingId]);
        beds_sync_room($pdo, (int)$bed['room_id']);

        $pdo->commit();

        @send_otp_email($student['email'], $student['full_name'], $otp);

        echo json_encode(['ok'=>true, 'booking_id'=>$bookingId, 'ttl_seconds'=>$ttl * 60,
            'bed'=>$bed['block_name'].' '.$bed['room_number'].' · bed '.$bed['label'].' ('.bed_kind_label($bed['kind']).')']);
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Book phase 2: '.$e->getMessage());
        echo json_encode(['ok'=>false, 'error'=>'Booking failed.']);
        exit;
    }
}

echo json_encode(['ok'=>false, 'error'=>'Invalid action or phase.']);
