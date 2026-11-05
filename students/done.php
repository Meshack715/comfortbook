<?php
// student/done.php — success / status page
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';

$bookingId = (int)($_GET['booking'] ?? 0);

$stmt = $pdo->prepare("
    SELECT b.*, r.room_number, bl.name AS block_name
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN blocks bl ON bl.id = r.block_id
    WHERE b.id = ? AND b.student_id = ?
");
$stmt->execute([$bookingId, $STUDENT['id']]);
$booking = $stmt->fetch();

if (!$booking) {
    // Try latest booking
    $stmt = $pdo->prepare("
        SELECT b.*, r.room_number, bl.name AS block_name
        FROM bookings b JOIN rooms r ON r.id = b.room_id
        JOIN blocks bl ON bl.id = r.block_id
        WHERE b.student_id = ? ORDER BY b.created_at DESC LIMIT 1
    ");
    $stmt->execute([$STUDENT['id']]);
    $booking = $stmt->fetch();
}

if (!$booking) {
    $_SESSION['magic_flash'] = ['type'=>'info','msg'=>'No booking found.'];
    header('Location: book.php'); exit;
}

student_header('Booking Status', $STUDENT);

$status = $booking['status'];
?>

<div class="card student-card" style="max-width:560px;margin:0 auto;">
    <div class="card-body p-5 text-center">

        <?php if ($status === 'pending_approval'): ?>
            <i class="fas fa-hourglass-half fa-4x text-info mb-3"></i>
            <h4 class="fw-bold">Waiting for Approval</h4>
            <p class="text-muted mb-2">
                Your booking for <strong><?= h($booking['room_number']) ?></strong>
                (<?= h($booking['block_name']) ?>) is waiting for your Secretary to approve.
            </p>
            <p class="small text-muted mb-4">
                You'll get a welcome email once it's approved. No further action needed.
            </p>

        <?php elseif ($status === 'confirmed'): ?>
            <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
            <h4 class="fw-bold">Booking Confirmed!</h4>
            <div class="p-3 rounded mb-3" style="background:#f0f8f4;border-left:4px solid #198754;text-align:left;">
                <div class="small text-muted text-uppercase">Your Room</div>
                <div class="fw-bold" style="font-size:1.4rem;color:#003366;"><?= h($booking['room_number']) ?></div>
                <div class="small text-muted"><?= h($booking['block_name']) ?></div>
            </div>
            <p class="text-muted small mb-0">
                Welcome to the next semester! Check your email for details.
            </p>

        <?php elseif ($status === 'cancelled'): ?>
            <i class="fas fa-times-circle fa-4x text-danger mb-3"></i>
            <h4 class="fw-bold">Booking Cancelled</h4>
            <p class="text-muted small mb-0">
                <?= h($booking['cancelled_reason'] ?: 'Contact your Dean for more information.') ?>
            </p>

        <?php else: ?>
            <i class="fas fa-info-circle fa-4x text-primary mb-3"></i>
            <h4 class="fw-bold">Booking In Progress</h4>
            <p class="text-muted small">Status: <?= h($status) ?></p>
        <?php endif; ?>

        <hr class="my-4">
        <a href="book.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back to Home
        </a>
    </div>
</div>

<?php student_footer(); ?>