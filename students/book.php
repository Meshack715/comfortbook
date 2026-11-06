<?php
// student/book.php — single page for the entire booking flow
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';

$phase   = current_phase($STUDENT['gender']);   // per-wing
$flash   = $_SESSION['magic_flash'] ?? null;
unset($_SESSION['magic_flash']);

// The semester this link was issued for
$targetSem = null;
if ($MAGIC_SEM_ID) {
    $stmt = $pdo->prepare("SELECT * FROM semesters WHERE id = ?");
    $stmt->execute([$MAGIC_SEM_ID]);
    $targetSem = $stmt->fetch() ?: null;
}

// The booking in progress for this semester (if any)
$activeBooking = null;
if ($MAGIC_SEM_ID) {
    $stmt = $pdo->prepare("
        SELECT b.*, r.room_number, bl.name AS block_name
        FROM bookings b
        JOIN rooms r ON r.id = b.room_id
        JOIN blocks bl ON bl.id = r.block_id
        WHERE b.student_id = ? AND b.semester_id = ?
          AND b.status IN ('pending_otp','pending_payment','pending_approval','confirmed')
        ORDER BY b.created_at DESC LIMIT 1
    ");
    $stmt->execute([$STUDENT['id'], $MAGIC_SEM_ID]);
    $activeBooking = $stmt->fetch() ?: null;
}

// If it's a confirmed booking, refresh to reflect semester activation
$isPlaced = false;
if ($activeBooking && $activeBooking['status'] === 'confirmed') {
    $isPlaced = (int)$STUDENT['placed'] === 1;
}

// Which screen?
$screen = 'closed';
if ($activeBooking) {
    $screen = $activeBooking['status'];   // pending_otp, pending_payment, pending_approval, confirmed
} elseif ($phase === 1) {
    $screen = 'choose_p1';
} elseif ($phase === 2) {
    $screen = 'choose_p2';
}

// Phase 2 available rooms (only if released or unplaced and no active booking)
$availableRooms = [];
if ($screen === 'choose_p2' && !$activeBooking) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.room_number, r.capacity, b.name AS block_name
        FROM rooms r
        JOIN blocks b ON b.id = r.block_id
        WHERE r.gender = ? AND r.status IN ('vacant','available_p2')
        ORDER BY b.name, r.room_number
    ");
    $stmt->execute([$STUDENT['gender']]);
    $availableRooms = $stmt->fetchAll();
}

// Phase 1 remaining?
$p1End = null;
$p2Start = null;
if ($MAGIC_SEM_ID && $STUDENT['gender']) {
    $w = semester_window($MAGIC_SEM_ID, $STUDENT['gender']);
    if ($w) {
        $p1End   = $w['phase1_end'];
        $p2Start = $w['phase2_start'];
    }
}

student_header('My Booking', $STUDENT);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
        <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Semester banner -->
<?php if ($targetSem): ?>
<div class="alert alert-dark d-flex align-items-center">
    <i class="fas fa-calendar-check me-2"></i>
    <div class="small flex-grow-1">
        Booking for <strong><?= h($targetSem['name']) ?></strong>
        · <?= date('M j, Y', strtotime($targetSem['start_date'])) ?>
        → <?= date('M j, Y', strtotime($targetSem['end_date'])) ?>
    </div>
</div>
<?php endif; ?>

<!-- Student summary -->
<div class="card student-card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center">
            <div>
                <div class="small text-muted text-uppercase">Hello</div>
                <h5 class="mb-0 fw-bold"><?= h($STUDENT['full_name']) ?></h5>
                <div class="small text-muted">
                    <?= h($STUDENT['reg_number']) ?> · <?= h($STUDENT['gender']) ?>
                </div>
            </div>
            <div class="text-end">
                <div class="small text-muted text-uppercase">Current Room</div>
                <div class="fw-bold" style="font-size:1.1rem;color:#003366;">
                    <?= $STUDENT['current_room_number'] ? h($STUDENT['current_room_number']) : '—' ?>
                </div>
                <div class="small text-muted">
                    <?= $STUDENT['current_block'] ? h($STUDENT['current_block']) : 'No room assigned' ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php /* ============================================================
       SCREEN: closed / not open
   ============================================================ */ ?>
<?php if ($screen === 'closed' && !$activeBooking): ?>
    <div class="card student-card">
        <div class="card-body text-center py-5">
            <i class="fas fa-clock fa-4x text-secondary mb-3"></i>
            <h4 class="fw-bold">Booking Not Open</h4>
            <p class="text-muted mb-0">
                The booking window for <?= h($STUDENT['gender']) ?> students is currently closed.
                Check back later or contact your Dean.
            </p>
        </div>
    </div>

<?php /* ============================================================
       SCREEN: Phase 1 — keep or release
   ============================================================ */ ?>
<?php elseif ($screen === 'choose_p1'): ?>
    <div class="alert alert-primary d-flex align-items-center">
        <i class="fas fa-door-open fa-2x me-3"></i>
        <div>
            <strong>Phase 1 — Keep or Release</strong>
            <div class="small">
                Keep your current room, or release it to book a new one in Phase 2.
                <?php if ($p1End): ?>
                    · Ends <?= date('M j, H:i', strtotime($p1End)) ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($STUDENT['phase1_action'] === 'none'): ?>
        <div class="card student-card">
            <div class="card-body py-5">
                <h5 class="fw-bold text-center mb-4">What would you like to do?</h5>
                <div class="row g-3 justify-content-center">
                    <div class="col-md-5">
                        <div class="p-4 border rounded-3 h-100" style="background:#f0f8f4;">
                            <i class="fas fa-lock fa-2x text-success mb-3"></i>
                            <h6 class="fw-bold">Keep My Room</h6>
                            <p class="small text-muted mb-3">
                                Room <strong><?= h($STUDENT['current_room_number']) ?></strong> stays yours for next semester.
                            </p>
                            <button class="btn btn-success w-100" id="keepBtn" onclick="submitAction('keep')">
                                <span class="btn-label"><i class="fas fa-check me-1"></i>Keep My Room</span>
                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Working...</span>
                            </button>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="p-4 border rounded-3 h-100" style="background:#eef4fb;">
                            <i class="fas fa-exchange-alt fa-2x text-primary mb-3"></i>
                            <h6 class="fw-bold">Release My Room</h6>
                            <p class="small text-muted mb-3">
                                Free up your room, book a new one in Phase 2
                                <?php if ($p2Start): ?>(starts <?= date('M j', strtotime($p2Start)) ?>)<?php endif; ?>.
                            </p>
                            <button class="btn btn-outline-primary w-100" id="releaseBtn" onclick="submitAction('release')">
                                <span class="btn-label"><i class="fas fa-exchange-alt me-1"></i>Release My Room</span>
                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Working...</span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php elseif ($STUDENT['phase1_action'] === 'keep'): ?>
        <div class="card student-card">
            <div class="card-body text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h4 class="fw-bold">Room Kept</h4>
                <p class="text-muted">You've chosen to keep <strong><?= h($STUDENT['current_room_number']) ?></strong>.</p>
                <p class="small text-muted mb-0">You don't need to do anything else. Your room is secured.</p>
            </div>
        </div>

    <?php else: /* released */ ?>
        <div class="card student-card">
            <div class="card-body text-center py-5">
                <i class="fas fa-hourglass-half fa-4x text-info mb-3"></i>
                <h4 class="fw-bold">Room Released</h4>
                <p class="text-muted">You released <strong><?= h($STUDENT['current_room_number']) ?></strong>.</p>
                <p class="small text-muted mb-0">
                    Come back in Phase 2
                    <?php if ($p2Start): ?>(starts <?= date('M j, H:i', strtotime($p2Start)) ?>)<?php endif; ?>
                    to book a new room. This link will still work.
                </p>
            </div>
        </div>
    <?php endif; ?>

<?php /* ============================================================
       SCREEN: Phase 2 — pick a room
   ============================================================ */ ?>
<?php elseif ($screen === 'choose_p2'): ?>
    <div class="alert alert-success d-flex align-items-center">
        <i class="fas fa-door-closed fa-2x me-3"></i>
        <div>
            <strong>Phase 2 — Move Window</strong>
            <div class="small">
                Pick from available rooms.
                <?php if ($STUDENT['phase1_action'] === 'keep'): ?>
                    <br><em>You kept your room in Phase 1, so no action is needed.</em>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($STUDENT['phase1_action'] === 'keep'): ?>
        <div class="card student-card">
            <div class="card-body text-center py-5">
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h4 class="fw-bold">You're All Set</h4>
                <p class="text-muted">You kept <strong><?= h($STUDENT['current_room_number']) ?></strong> in Phase 1.</p>
                <p class="small text-muted mb-0">No further action needed.</p>
            </div>
        </div>

    <?php elseif (empty($availableRooms)): ?>
        <div class="card student-card">
            <div class="card-body text-center py-5">
                <i class="fas fa-bed fa-4x text-secondary mb-3"></i>
                <h4 class="fw-bold">No Rooms Available</h4>
                <p class="text-muted mb-0">All rooms are taken. Contact your Dean for manual placement.</p>
            </div>
        </div>

    <?php else: ?>
        <div class="card student-card">
            <div class="card-body">
                <h5 class="fw-bold mb-3">
                    <i class="fas fa-bed me-2 text-primary"></i>Choose a Room
                    <span class="badge bg-light text-dark ms-2"><?= count($availableRooms) ?> available</span>
                </h5>
                <p class="small text-muted mb-3">
                    Tap a room. Confirm with OTP sent to your email.
                    <?php if ($STUDENT['late_booker']): ?>
                        <br><span class="badge bg-warning text-dark">Late booker — KES <?= (int)get_config('penalty_amount') ?> penalty applies</span>
                    <?php endif; ?>
                </p>

                <div class="room-grid mb-4" id="roomGrid">
                    <?php foreach ($availableRooms as $r): ?>
                        <div class="room-tile"
                             data-room-id="<?= (int)$r['id'] ?>"
                             data-room-number="<?= h($r['room_number']) ?>"
                             data-block-name="<?= h($r['block_name']) ?>">
                            <div class="room-num"><?= h($r['room_number']) ?></div>
                            <div class="room-blk"><?= h($r['block_name']) ?></div>
                            <div class="room-cap"><i class="fas fa-user-friends me-1"></i><?= (int)$r['capacity'] ?> person</div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-content-between align-items-center">
                    <div class="small text-muted" id="selectionInfo">No room selected</div>
                    <button class="btn btn-primary btn-lg" id="bookBtn" disabled>
                        <span class="btn-label"><i class="fas fa-check me-1"></i>Confirm Booking</span>
                        <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Booking...</span>
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php /* ============================================================
       SCREEN: pending_otp
   ============================================================ */ ?>
<?php elseif ($screen === 'pending_otp'): ?>
    <div class="card student-card" style="max-width:520px;margin:0 auto;">
        <div class="card-body p-4 text-center">
            <i class="fas fa-envelope-open-text fa-3x text-primary mb-3"></i>
            <h4 class="fw-bold mb-2">Enter Verification Code</h4>
            <p class="text-muted small mb-4">
                We sent a 6-digit code to <strong><?= h($STUDENT['email']) ?></strong>.
                Expires in <?= (int)get_config('otp_ttl_minutes', 10) ?> minutes.
            </p>

            <?php if (get_config('show_otp_in_ui', '1') === '1' && $activeBooking['otp_code']): ?>
                <div class="alert alert-warning small mb-3">
                    <i class="fas fa-flask me-1"></i><strong>Dev:</strong>
                    OTP is <code style="font-size:1.1rem;"><?= h($activeBooking['otp_code']) ?></code>
                </div>
            <?php endif; ?>

            <form id="otpForm" onsubmit="return submitOtp(event)">
                <input type="text" id="otpInput" maxlength="6" pattern="\d{6}"
                       inputmode="numeric" autofocus placeholder="000000"
                       class="form-control form-control-lg text-center mb-3"
                       style="font-size:2rem;letter-spacing:.5rem;font-weight:700;">

                <div id="otpError" class="alert alert-danger small d-none"></div>

                <button type="submit" class="btn btn-primary btn-lg w-100" id="otpBtn">
                    <span class="btn-label"><i class="fas fa-check me-1"></i>Verify Code</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Verifying...</span>
                </button>
            </form>
        </div>
    </div>

<?php /* ============================================================
       SCREEN: pending_payment
   ============================================================ */ ?>
<?php elseif ($screen === 'pending_payment'): ?>
    <div class="card student-card" style="max-width:560px;margin:0 auto;">
        <div class="card-body p-4">
            <div class="text-center mb-4">
                <i class="fas fa-money-bill-wave fa-3x text-warning mb-3"></i>
                <h4 class="fw-bold mb-2">Submit M-Pesa Reference</h4>
                <p class="text-muted small mb-0">
                    Room <strong><?= h($activeBooking['room_number']) ?></strong>
                    · <?= h($activeBooking['block_name']) ?>
                </p>
            </div>

            <?php if ((int)$activeBooking['penalty_amount'] > 0): ?>
                <div class="alert alert-warning d-flex align-items-center">
                    <i class="fas fa-exclamation-triangle fa-2x me-3"></i>
                    <div>
                        <strong>Penalty: KES <?= number_format((int)$activeBooking['penalty_amount']) ?></strong>
                        <div class="small">Late booker. Pay via M-Pesa.</div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="border rounded p-3 mb-3" style="background:#f8f9fa;">
                <div class="small text-muted text-uppercase mb-1">Pay to</div>
                <div class="fw-bold">UEAB Hostel · Paybill <code>247247</code></div>
                <div class="fw-bold">Account: <code>COMFORTBOOK</code></div>
                <div class="small text-muted mt-1">Amount: KES <?= number_format((int)$activeBooking['penalty_amount'] ?: 200) ?></div>
            </div>

            <form id="mpesaForm" onsubmit="return submitMpesa(event)">
                <label class="form-label fw-bold">M-Pesa Confirmation Code</label>
                <input type="text" id="mpesaInput" maxlength="10" placeholder="e.g. QGH7X2K9LM"
                       class="form-control form-control-lg text-center mb-2"
                       style="text-transform:uppercase;letter-spacing:.15rem;font-weight:700;font-family:monospace;"
                       autofocus>
                <div class="small text-muted mb-3">10-character code from your M-Pesa SMS.</div>

                <div id="mpesaError" class="alert alert-danger small d-none"></div>

                <button type="submit" class="btn btn-primary btn-lg w-100" id="mpesaBtn">
                    <span class="btn-label"><i class="fas fa-check me-1"></i>Submit Reference</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Submitting...</span>
                </button>
            </form>
        </div>
    </div>

<?php /* ============================================================
       SCREEN: pending_approval
   ============================================================ */ ?>
<?php elseif ($screen === 'pending_approval'): ?>
    <div class="card student-card" style="max-width:560px;margin:0 auto;">
        <div class="card-body p-5 text-center">
            <i class="fas fa-hourglass-half fa-4x text-info mb-3"></i>
            <h4 class="fw-bold">Waiting for Approval</h4>
            <p class="text-muted mb-2">
                Your booking for <strong><?= h($activeBooking['room_number']) ?></strong>
                (<?= h($activeBooking['block_name']) ?>) is waiting for the Secretary to approve.
            </p>
            <p class="small text-muted mb-0">
                You'll get an email once it's confirmed. The room will be assigned when the semester starts.
            </p>
        </div>
    </div>

<?php /* ============================================================
       SCREEN: confirmed
   ============================================================ */ ?>
<?php elseif ($screen === 'confirmed'): ?>
    <div class="card student-card" style="max-width:560px;margin:0 auto;">
        <div class="card-body p-5 text-center">
            <?php if ($isPlaced): ?>
                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                <h4 class="fw-bold">Room Assigned!</h4>
                <div class="p-3 rounded mb-3" style="background:#f0f8f4;border-left:4px solid #198754;text-align:left;">
                    <div class="small text-muted text-uppercase">Your Room</div>
                    <div class="fw-bold" style="font-size:1.4rem;color:#003366;"><?= h($activeBooking['room_number']) ?></div>
                    <div class="small text-muted"><?= h($activeBooking['block_name']) ?></div>
                </div>
                <p class="text-muted small mb-0">
                    Welcome to the semester! Check your email for move-in details.
                </p>
            <?php else: ?>
                <i class="fas fa-check-circle fa-4x text-primary mb-3"></i>
                <h4 class="fw-bold">Booking Confirmed</h4>
                <p class="text-muted mb-2">
                    Your room <strong><?= h($activeBooking['room_number']) ?></strong>
                    (<?= h($activeBooking['block_name']) ?>) is reserved for you.
                </p>
                <div class="alert alert-info small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    You'll receive a welcome email with move-in details when the semester officially starts.
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<script>
// ---------- Shared spinner toggle ----------
function spin(btn, on) {
    if (!btn) return;
    const label = btn.querySelector('.btn-label');
    const spin  = btn.querySelector('.btn-spin');
    if (on) {
        if (label) label.classList.add('d-none');
        if (spin)  spin.classList.remove('d-none');
        btn.disabled = true;
    } else {
        if (label) label.classList.remove('d-none');
        if (spin)  spin.classList.add('d-none');
        btn.disabled = false;
    }
}

// ---------- Phase 1 actions ----------
async function submitAction(action) {
    const isKeep = action === 'keep';
    if (!confirm(isKeep ? 'Keep your current room?' : 'Release your room? You will book a new one in Phase 2.')) return;

    const btn = document.getElementById(isKeep ? 'keepBtn' : 'releaseBtn');
    spin(btn, true);

    try {
        const res = await fetch('../api/book_room.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action })
        });
        const data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Action failed');
            spin(btn, false);
        }
    } catch (e) {
        alert('Network error');
        spin(btn, false);
    }
}

// ---------- Phase 2 — room tiles + book ----------
let selectedRoomId = null;
document.querySelectorAll('.room-tile').forEach(tile => {
    tile.addEventListener('click', () => {
        document.querySelectorAll('.room-tile').forEach(t => t.classList.remove('selected'));
        tile.classList.add('selected');
        selectedRoomId = tile.dataset.roomId;
        document.getElementById('selectionInfo').innerHTML =
            'Selected: <strong>' + tile.dataset.roomNumber + '</strong> · ' + tile.dataset.blockName;
        document.getElementById('bookBtn').disabled = false;
    });
});

document.getElementById('bookBtn')?.addEventListener('click', async () => {
    if (!selectedRoomId) return;
    const btn = document.getElementById('bookBtn');
    spin(btn, true);

    try {
        const res = await fetch('../api/book_room.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'book', room_id: selectedRoomId })
        });
        const data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            alert(data.error || 'Booking failed');
            location.reload();
        }
    } catch (e) {
        alert('Network error');
        spin(btn, false);
    }
});

// ---------- OTP submit ----------
async function submitOtp(e) {
    e.preventDefault();
    const otp = document.getElementById('otpInput').value.trim();
    const errBox = document.getElementById('otpError');
    const btn = document.getElementById('otpBtn');
    errBox.classList.add('d-none');

    if (!/^\d{6}$/.test(otp)) {
        errBox.textContent = 'Enter exactly 6 digits';
        errBox.classList.remove('d-none');
        return false;
    }

    spin(btn, true);

    try {
        const res = await fetch('../api/verify_otp.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ booking_id: <?= (int)$activeBooking['id'] ?>, otp })
        });
        const data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            errBox.textContent = data.error || 'Invalid OTP';
            errBox.classList.remove('d-none');
            spin(btn, false);
        }
    } catch (e) {
        errBox.textContent = 'Network error';
        errBox.classList.remove('d-none');
        spin(btn, false);
    }
    return false;
}

// ---------- M-Pesa submit ----------
document.getElementById('mpesaInput')?.addEventListener('input', (e) => {
    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

async function submitMpesa(e) {
    e.preventDefault();
    const ref = document.getElementById('mpesaInput').value.trim();
    const errBox = document.getElementById('mpesaError');
    const btn = document.getElementById('mpesaBtn');
    errBox.classList.add('d-none');

    if (!/^[A-Z0-9]{10}$/.test(ref)) {
        errBox.textContent = 'M-Pesa reference must be 10 letters/numbers';
        errBox.classList.remove('d-none');
        return false;
    }

    spin(btn, true);

    try {
        const res = await fetch('../api/submit_mpesa.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ booking_id: <?= (int)$activeBooking['id'] ?>, mpesa_ref: ref })
        });
        const data = await res.json();
        if (data.ok) {
            location.reload();
        } else {
            errBox.textContent = data.error || 'Failed to submit';
            errBox.classList.remove('d-none');
            spin(btn, false);
        }
    } catch (e) {
        errBox.textContent = 'Network error';
        errBox.classList.remove('d-none');
        spin(btn, false);
    }
    return false;
}
</script>

<?php student_footer(); ?>