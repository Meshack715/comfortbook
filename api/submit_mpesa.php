<?php
// api/submit_mpesa.php
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
$ref       = strtoupper(trim($body['mpesa_ref'] ?? ''));

if (!preg_match('/^[A-Z0-9]{10}$/', $ref)) {
    echo json_encode(['ok'=>false, 'error'=>'Invalid M-Pesa reference format.']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND student_id = ? AND status = 'pending_payment'");
$stmt->execute([$bookingId, $sid]);
$booking = $stmt->fetch();
if (!$booking) {
    echo json_encode(['ok'=>false, 'error'=>'Booking not in payment state.']);
    exit;
}

// Duplicate check (unique key will also catch it)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE mpesa_ref = ? AND id <> ?");
$stmt->execute([$ref, $bookingId]);
if ((int)$stmt->fetchColumn() > 0) {
    echo json_encode(['ok'=>false, 'error'=>'This M-Pesa reference has already been used.']);
    exit;
}

$pdo->prepare("
    UPDATE bookings SET mpesa_ref = ?, status='pending_approval' WHERE id = ?
")->execute([$ref, $bookingId]);

audit_log(null, $sid, 'mpesa_submitted', "Ref $ref for booking #$bookingId");
echo json_encode(['ok'=>true]);