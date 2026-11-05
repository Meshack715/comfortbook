<?php
// api/book_room.php — Phase 1 keep/release · Phase 2 atomic claim
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['magic_student_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'Session expired. Reopen your email link.']);
    exit;
}

$sid = (int)$_SESSION['magic_student_id'];

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
$phase  = current_phase();

// Reject if a booking already in progress
$stmt = $pdo->prepare("
    SELECT id FROM bookings WHERE student_id = ?
    AND status IN ('pending_otp','pending_payment','pending_approval','confirmed')
    LIMIT 1
");
$stmt->execute([$sid]);
if ($stmt->fetch()) {
    echo json_encode(['ok'=>false, 'error'=>'You already have a booking in progress.']);
    exit;
}

// ==========================================================
// PHASE 1 — keep or release
// ==========================================================
if ($phase === 1 && in_array($action, ['keep','release'], true)) {
    if (!$student['current_room_id']) {
        echo json_encode(['ok'=>false, 'error'=>'You have no current room to keep or release.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($action === 'keep') {
            // Reserve own room
            $pdo->prepare("
                UPDATE rooms SET status='reserved', reserved_by_student_id=?, reserved_at=NOW()
                WHERE id = ? AND status = 'occupied'
            ")->execute([$sid, $student['current_room_id']]);

            $pdo->prepare("UPDATE students SET phase1_action='keep' WHERE id = ?")->execute([$sid]);

            // Create booking
            $pdo->prepare("
                INSERT INTO bookings (student_id, room_id, phase, action, status)
                VALUES (?, ?, 1, 'keep', 'pending_otp')
            ")->execute([$sid, $student['current_room_id']]);
            $bookingId = (int)$pdo->lastInsertId();

        } else { // release
            $pdo->prepare("
                UPDATE rooms SET status='available_p2', reserved_by_student_id=NULL, reserved_at=NULL
                WHERE id = ?
            ")->execute([$student['current_room_id']]);

            $pdo->prepare("UPDATE students SET phase1_action='release', current_room_id=NULL WHERE id = ?")
                ->execute([$sid]);

            $pdo->prepare("
                INSERT INTO bookings (student_id, room_id, phase, action, status)
                VALUES (?, ?, 1, 'release', 'pending_otp')
            ")->execute([$sid, $student['current_room_id']]);
            $bookingId = (int)$pdo->lastInsertId();
        }

        // Generate OTP
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $ttl = (int)get_config('otp_ttl_minutes', '10');
        $pdo->prepare("
            UPDATE bookings SET otp_code=?, otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE) WHERE id=?
        ")->execute([$otp, $ttl, $bookingId]);

        $pdo->commit();

        @send_otp_email($student['email'], $student['full_name'], $otp);

        echo json_encode(['ok'=>true, 'booking_id'=>$bookingId]);
        exit;

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Book phase 1 error: ' . $e->getMessage());
        echo json_encode(['ok'=>false, 'error'=>'Booking failed. Try again.']);
        exit;
    }
}

// ==========================================================
// PHASE 2 — atomic room claim
// ==========================================================
if ($phase === 2 && $action === 'book' && $roomId > 0) {
    // Must have released or be unplaced
    if ($student['phase1_action'] === 'keep' || $student['placed']) {
        echo json_encode(['ok'=>false, 'error'=>'You kept your room — no new booking needed.']);
        exit;
    }

    // Load target room — must be eligible
    $stmt = $pdo->prepare("
        SELECT r.*, b.name AS block_name FROM rooms r JOIN blocks b ON b.id = r.block_id
        WHERE r.id = ? AND r.gender = ?
    ");
    $stmt->execute([$roomId, $student['gender']]);
    $room = $stmt->fetch();
    if (!$room) {
        echo json_encode(['ok'=>false, 'error'=>'Room not available or wrong wing.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // ATOMIC claim — the heart of the system
        $upd = $pdo->prepare("
            UPDATE rooms
            SET status='reserved', reserved_by_student_id=?, reserved_at=NOW()
            WHERE id = ? AND status IN ('vacant','available_p2')
        ");
        $upd->execute([$sid, $roomId]);

        if ($upd->rowCount() === 0) {
            $pdo->rollBack();
            echo json_encode(['ok'=>false, 'error'=>'Just taken! Please choose another room.']);
            exit;
        }

        // Late booker penalty
        $penalty = 0;
        if (!empty($student['late_booker'])) {
            $penalty = (int)get_config('penalty_amount', '200');
        }

        // Create booking
        $pdo->prepare("
            INSERT INTO bookings (student_id, room_id, phase, action, status, penalty_amount)
            VALUES (?, ?, 2, 'book', 'pending_otp', ?)
        ")->execute([$sid, $roomId, $penalty]);
        $bookingId = (int)$pdo->lastInsertId();

        // OTP
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
        error_log('Book phase 2 error: ' . $e->getMessage());
        echo json_encode(['ok'=>false, 'error'=>'Booking failed. Try again.']);
        exit;
    }
}

echo json_encode(['ok'=>false, 'error'=>'Invalid action or phase.']);