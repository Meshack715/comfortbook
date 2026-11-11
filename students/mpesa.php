<?php
// student/mpesa.php — paste M-Pesa reference
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
if ($booking['status'] !== 'pending_payment') {
    header('Location: book.php'); exit;
}

student_header('Payment', $STUDENT);
?>

<div class="card student-card" style="max-width:560px;margin:0 auto;">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <i class="fas fa-money-bill-wave fa-3x text-warning mb-3"></i>
            <h4 class="fw-bold mb-2">Submit M-Pesa Reference</h4>
            <p class="text-muted small mb-0">
                Room <strong><?= h($booking['room_number']) ?></strong> · <?= h($booking['block_name']) ?>
            </p>
        </div>

        <?php if ((int)$booking['penalty_amount'] > 0): ?>
            <div class="alert alert-warning">
                <div class="d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <strong>Penalty: KES <?= number_format((int)$booking['penalty_amount']) ?></strong>
                        <div class="small">You are a late booker. Pay via M-Pesa to confirm.</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="border rounded p-3 mb-3" style="background:#f8f9fa;">
            <div class="small text-muted text-uppercase mb-1">Pay to</div>
            <div class="fw-bold">UEAB Hostel · Paybill <code>247247</code></div>
            <div class="fw-bold">Account: <code>COMFORTBOOK</code></div>
            <div class="small text-muted mt-1">Amount: KES <?= number_format((int)$booking['penalty_amount'] ?: 200) ?></div>
        </div>

        <form id="mpesaForm">
            <label class="form-label fw-bold">M-Pesa Confirmation Code</label>
            <input type="text" class="form-control form-control-lg text-center mb-2"
                   id="mpesaInput" maxlength="10" placeholder="e.g. QGH7X2K9LM"
                   style="text-transform:uppercase;letter-spacing:.15rem;font-weight:700;font-family:monospace;"
                   autofocus>
            <div class="small text-muted mb-3">
                The 10-character code from your M-Pesa SMS.
            </div>

            <div id="mpesaError" class="alert alert-danger small d-none"></div>

            <button type="submit" class="btn btn-primary btn-lg w-100" id="mpesaBtn">
                <i class="fas fa-check me-1"></i>Submit Reference
            </button>
        </form>

        <div class="mt-3 small text-muted text-center">
            <a href="book.php">Cancel and go back</a>
        </div>
    </div>
</div>

<script>
document.getElementById('mpesaInput').addEventListener('input', (e) => {
    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

document.getElementById('mpesaForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const ref = document.getElementById('mpesaInput').value.trim();
    const errBox = document.getElementById('mpesaError');
    const btn = document.getElementById('mpesaBtn');
    errBox.classList.add('d-none');

    if (!/^[A-Z0-9]{10}$/.test(ref)) {
        errBox.textContent = 'M-Pesa reference must be 10 letters/numbers';
        errBox.classList.remove('d-none');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting...';

    try {
        const res = await fetch('../api/submit_mpesa.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ booking_id: <?= (int)$booking['id'] ?>, mpesa_ref: ref })
        });
        const data = await res.json();
        if (data.ok) {
            window.location = 'done.php?booking=<?= (int)$booking['id'] ?>';
        } else {
            errBox.textContent = data.error || 'Failed to submit';
            errBox.classList.remove('d-none');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Submit Reference';
        }
    } catch (err) {
        errBox.textContent = 'Network error';
        errBox.classList.remove('d-none');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Submit Reference';
    }
});
</script>

<?php student_footer(); ?>