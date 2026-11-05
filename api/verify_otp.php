<?php
// api/verify_otp.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

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

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND student_id = ? AND status = 'pending_otp'");
$stmt->execute([$bookingId, $sid]);
$booking = $stmt->fetch();

if (!$booking) {
    echo json_encode(['ok'=>false, 'error'=>'Booking not found or already verified.']);
    exit;
}
if (strtotime($booking['otp_expires_at']) < time()) {
    echo json_encode(['ok'=>false, 'error'=>'OTP expired. Please go back and try again.']);
    exit;
}
if (!hash_equals($booking['otp_code'], $otp)) {
    echo json_encode(['ok'=>false, 'error'=>'Incorrect OTP.']);
    exit;
}

// Determine next state
if ($booking['action'] === 'release') {
    // Release action needs no further step — directly confirmed
    $pdo->prepare("UPDATE bookings SET status='confirmed', otp_verified_at=NOW(), approved_at=NOW() WHERE id=?")
        ->execute([$bookingId]);
    echo json_encode(['ok'=>true, 'next'=>'done']);
    exit;
}

// keep or book → go to payment (or done if free)
if ((int)$booking['penalty_amount'] > 0) {
    $pdo->prepare("UPDATE bookings SET status='pending_payment', otp_verified_at=NOW() WHERE id=?")
        ->execute([$bookingId]);
    echo json_encode(['ok'=>true, 'next'=>'payment']);
} else {
    // Free booking → pending_approval (secretary approves)
    $pdo->prepare("UPDATE bookings SET status='pending_approval', otp_verified_at=NOW() WHERE id=?")
        ->execute([$bookingId]);
    echo json_encode(['ok'=>true, 'next'=>'done']);
}