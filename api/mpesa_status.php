<?php
// GET ?booking_id= — the student's page polls this for the result of the latest payment request.
// Always answers in plain words; never raw errors.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mpesa.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['magic_student_id'])) { echo json_encode(['status' => 'expired', 'final' => true, 'tone' => 'warning', 'title' => 'Session ended', 'message' => 'Your session ended. Open your booking link again; if you already paid, your payment is safe and will show there.']); exit; }
$sid = (int)$_SESSION['magic_student_id']; session_write_close();
try {
    mpesa_ensure_schema($pdo);
    $bid = (int)($_GET['booking_id'] ?? 0);
    $sql = "SELECT * FROM mpesa_payments WHERE student_id=? AND purpose='late_fee' AND " . ($bid > 0 ? "booking_id=?" : "booking_id IS NULL AND ?=0") . " ORDER BY id DESC LIMIT 1";
    $q = $pdo->prepare($sql); $q->execute([$sid, $bid]); $p = $q->fetch();
    if (!$p) { echo json_encode(['status' => 'none', 'final' => true, 'tone' => 'info', 'title' => '', 'message' => '']); exit; }
    if ($p['status'] === 'pending') { mpesa_query($pdo, $p); $q->execute([$sid, $bid]); $p = $q->fetch(); }
    $b = $pdo->prepare("SELECT status FROM bookings WHERE id=? AND student_id=?"); $b->execute([$bid, $sid]); $bs = (string)$b->fetchColumn();
    $v = mpesa_view($p, 'student'); $v['booking_status'] = $bs;
    echo json_encode($v);
    mpesa_sweep($pdo);
} catch (Throwable $e) {
    mpesa_tech_log('error', friendly_error($e, 'status poll'));
    echo json_encode(['status' => 'pending', 'final' => false, 'tone' => 'info', 'title' => 'Still checking', 'message' => 'Still checking your payment. Please keep this page open.']);
}
