<?php
// student/verify_otp.php
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
    $_SESSION['magic_flash'] = ['type'=>'danger','msg'=>'Booking not found.'];
    header('Location: book.php'); exit;
}
if ($booking['status'] !== 'pending_otp') {
    header('Location: book.php'); exit;
}

// Dev fallback — show OTP on page
$showOtp = get_config('show_otp_in_ui', '1') === '1';
$ttl     = (int)get_config('otp_ttl_minutes', '10');

student_header('Verify OTP', $STUDENT);
?>

<div class="card student-card" style="max-width:520px;margin:0 auto;">
    <div class="card-body p-4 text-center">
        <i class="fas fa-envelope-open-text fa-3x text-primary mb-3"></i>
        <h4 class="fw-bold mb-2">Enter Verification Code</h4>
        <p class="text-muted small mb-4">
            We sent a 6-digit code to <strong><?= h($STUDENT['email']) ?></strong>.
            Code expires in <?= $ttl ?> minutes.
        </p>

        <?php if ($showOtp && $booking['otp_code']): ?>
            <div class="alert alert-warning small mb-3">
                <i class="fas fa-flask me-1"></i><strong>Dev mode:</strong>
                OTP is <code style="font-size:1.1rem;"><?= h($booking['otp_code']) ?></code>
            </div>
        <?php endif; ?>

        <form id="otpForm">
            <input type="text" class="form-control form-control-lg text-center mb-3"
                   id="otpInput" maxlength="6" pattern="[0-9]{6}"
                   placeholder="000000" inputmode="numeric" autofocus
                   style="font-size:2rem;letter-spacing:.5rem;font-weight:700;">

            <div id="otpError" class="alert alert-danger small d-none"></div>

            <button type="submit" class="btn btn-primary btn-lg w-100" id="otpBtn">
                <i class="fas fa-check me-1"></i>Verify Code
            </button>
        </form>

        <div class="mt-3 small text-muted">
            <a href="book.php">Cancel and go back</a>
        </div>
    </div>
</div>

<script>
document.getElementById('otpForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const otp = document.getElementById('otpInput').value.trim();
    const errBox = document.getElementById('otpError');
    const btn = document.getElementById('otpBtn');
    errBox.classList.add('d-none');

    if (!/^\d{6}$/.test(otp)) {
        errBox.textContent = 'Enter exactly 6 digits';
        errBox.classList.remove('d-none');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying...';

    try {
        const res = await fetch('../api/verify_otp.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ booking_id: <?= (int)$booking['id'] ?>, otp: otp })
        });
        const data = await res.json();
        if (data.ok) {
            window.location = data.next === 'payment'
                ? 'mpesa.php?booking=<?= (int)$booking['id'] ?>'
                : 'done.php?booking=<?= (int)$booking['id'] ?>';
        } else {
            errBox.textContent = data.error || 'Invalid OTP';
            errBox.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Verify Code';
        }
    } catch (err) {
        errBox.textContent = 'Network error';
        errBox.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Verify Code';
    }
});
</script>

<?php student_footer(); ?>