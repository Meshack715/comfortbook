<?php
// POST {booking_id, phone} — the student asks for an M-Pesa prompt for their late-booking fee.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mpesa.php';
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['magic_student_id'])) { echo json_encode(['ok' => false, 'message' => 'Your session ended. Open your booking link again to continue.']); exit; }
$sid = (int)$_SESSION['magic_student_id']; $in = json_decode(file_get_contents('php://input'), true) ?: [];
try {
    mpesa_ensure_schema($pdo);
    if (!mpesa_phone((string)($in['phone'] ?? ''))) { echo json_encode(['ok' => false, 'message' => 'Enter a valid Safaricom number like 0712 345 678 or 0112 345 678.']); exit; }
    $st = $pdo->prepare("SELECT * FROM students WHERE id=?"); $st->execute([$sid]); $stu = $st->fetch();
    $feeOnly = (int)($in['booking_id'] ?? 0) === 0;                     // stand-alone late fee (windows over): pay first, then book
    $q = $pdo->prepare("SELECT * FROM bookings WHERE id=? AND student_id=? AND status='pending_payment'"); $q->execute([(int)($in['booking_id'] ?? 0), $sid]); $b = $q->fetch();
    if ($feeOnly) {
        $bs = booking_state($pdo, $stu, (int)($_SESSION['magic_semester_id'] ?? 0));
        if ($bs['fee_paid']) { echo json_encode(['ok' => false, 'message' => 'Your late fee is already paid. Refresh the page to choose your room.']); exit; }
        if (!$bs['needs_fee']) { echo json_encode(['ok' => false, 'message' => 'No late fee is due right now. Refresh the page to see where booking stands.']); exit; }
        $n = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE student_id=? AND purpose='late_fee' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $n->execute([$sid]);
        if ((int)$n->fetchColumn() >= 6) { echo json_encode(['ok' => false, 'message' => 'Too many tries in a short time. Wait a few minutes, or ask the Secretary to send the prompt.']); exit; }
        session_write_close();
        $r = mpesa_start_payment($pdo, ['purpose' => 'late_fee', 'amount' => (int)$bs['fee'], 'phone' => (string)$in['phone'], 'student_id' => $sid, 'booking_id' => null, 'semester_id' => (int)$bs['target']['id'], 'via' => 'student']);
        echo json_encode(['ok' => (bool)$r['ok'], 'message' => $r['message'], 'payment_id' => $r['payment_id'] ?? null]); exit;
    }
    if (!$b) { echo json_encode(['ok' => false, 'message' => 'This booking is not waiting for payment any more. Refresh the page to see where it stands.']); exit; }
    $n = $pdo->prepare("SELECT COUNT(*) FROM mpesa_payments WHERE student_id=? AND purpose='late_fee' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"); $n->execute([$sid]);
    if ((int)$n->fetchColumn() >= 6) { echo json_encode(['ok' => false, 'message' => 'Too many tries in a short time. Wait a few minutes, or use the Paybill option below.']); exit; }
    session_write_close();
    $r = mpesa_start($pdo, $b, (string)$in['phone']);
    echo json_encode(['ok' => (bool)$r['ok'], 'message' => $r['message'], 'payment_id' => $r['payment_id'] ?? null]);
} catch (Throwable $e) {
    mpesa_tech_log('error', friendly_error($e, 'student prompt'));
    echo json_encode(['ok' => false, 'message' => 'Something went wrong while sending the prompt. Nothing was charged. Please try again in a moment.']);
}
