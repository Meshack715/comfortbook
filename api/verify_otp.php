<?php
// api/verify_otp.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notify.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['magic_student_id'])) {
    echo json_encode(['ok'=>false, 'error'=>'Session expired.']);
    exit;
}
$sid = (int)$_SESSION['magic_student_id'];

$body      = json_decode(file_get_contents('php://input'), true) ?: [];
$bookingId = (int)($body['booking_id'] ?? 0);
$otp       = trim($body['otp'] ?? '');

$stmt = $pdo->prepare("
    SELECT * FROM bookings
    WHERE id = ? AND student_id = ? AND status = 'pending_otp'
");
$stmt->execute([$bookingId, $sid]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['ok'=>false, 'error'=>'Booking not found or already verified.']);
    exit;
}
if (strtotime($booking['otp_expires_at']) < time()) {
    echo json_encode(['ok'=>false, 'error'=>'OTP expired.']);
    exit;
}
if (!hash_equals($booking['otp_code'], $otp)) {
    echo json_encode(['ok'=>false, 'error'=>'Incorrect OTP.']);
    exit;
}

// Release — no further step
if ($booking['action'] === 'release') {
    $pdo->prepare("
        UPDATE bookings SET status='confirmed', otp_verified_at=NOW(), approved_at=NOW()
        WHERE id=?
    ")->execute([$bookingId]);
    echo json_encode(['ok'=>true, 'next'=>'done']);
    exit;
}

// Payment required? (not if the fee was already paid for this semester, e.g. collected by the secretary)
$cr = $pdo->prepare("SELECT late_fee_paid_sem FROM students WHERE id=?"); $cr->execute([$sid]);
$cr->execute([$sid]); $paidSem = (int)$cr->fetchColumn();
$feePaid = $paidSem > 0 && $paidSem === (int)$booking['semester_id'];
if ((int)$booking['penalty_amount'] > 0 && !$feePaid) {
    $pdo->prepare("UPDATE bookings SET status='pending_payment', otp_verified_at=NOW() WHERE id=?")
        ->execute([$bookingId]);
    echo json_encode(['ok'=>true, 'next'=>'payment']);
} else {
    // Free booking → pending_approval
    $pdo->prepare("UPDATE bookings SET status='pending_approval', otp_verified_at=NOW() WHERE id=?")
        ->execute([$bookingId]);
    try {
        $i = $pdo->prepare("SELECT s.full_name, s.gender, r.room_number FROM students s JOIN rooms r ON r.id=? WHERE s.id=?");
        $i->execute([$booking['room_id'], $sid]); $x = $i->fetch();
        if ($x) notify_staff($pdo, ['secretary','dean'], $x['gender'] ?? null, 'Booking awaiting approval', $x['full_name'] . ' verified a booking for room ' . $x['room_number'] . '.', '/user/approvals.php', 'approval');
    } catch (Throwable $e) {}
    try { require_once __DIR__ . '/../includes/mailer.php'; booking_emails($pdo, (int)$bookingId, 'submitted'); } catch (Throwable $e) {}
    echo json_encode(['ok'=>true, 'next'=>'done']);
}