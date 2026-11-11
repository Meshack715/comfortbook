<?php
// student/book.php — single page for the entire booking flow
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';

$BS      = booking_state($pdo, $STUDENT, (int)$MAGIC_SEM_ID);   // one source of truth for "what can this student do right now"
$phase   = $BS['phase'];
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
$screen = 'status';                       // not announced / opens later / between phases / closed
$lateMode = false;
if ($activeBooking) {
    $screen = $activeBooking['status'];   // pending_otp, pending_payment, pending_approval, confirmed
} elseif ($phase === 1) {
    $screen = 'choose_p1';
} elseif ($phase === 2) {
    $screen = 'choose_p2';
} elseif ($BS['state'] === 'late') {      // windows are over: pay the late fee first, then book
    $screen = $BS['needs_fee'] ? 'late_fee' : 'choose_p2';
    $lateMode = true;
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
$p1End   = $BS['p1e'] ? date('Y-m-d H:i:s', $BS['p1e']) : null;
$p2Start = $BS['p2s'] ? date('Y-m-d H:i:s', $BS['p2s']) : null;

student_header('My Booking', $STUDENT);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
        <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Semester + booking-window status -->
<?php if ($targetSem): ?>
<div class="small text-body-secondary mb-2">
    <i class="fas fa-calendar-check me-1"></i>Booking for <strong><?= h(preg_replace('/\s+/', ' ', $targetSem['name'])) ?></strong>,
    the semester that runs <?= date('j M Y', strtotime($targetSem['start_date'])) ?> to <?= date('j M Y', strtotime($targetSem['end_date'])) ?>.
</div>
<?php endif; ?>
<?php if (!$activeBooking && $screen !== 'status'): ?>
<div class="alert alert-<?= h($BS['tone']) ?> d-flex align-items-start gap-2 py-2" role="status">
    <i class="fas <?= h($BS['icon']) ?> mt-1"></i>
    <div class="small"><strong><?= h($BS['headline']) ?></strong><br><?= h($BS['detail']) ?></div>
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
       SCREEN: status — not announced / opens later / between phases / closed
   ============================================================ */ ?>
<?php if ($screen === 'status'): ?>
    <div class="card student-card mb-3">
        <div class="card-body text-center py-4 px-3">
            <i class="fas <?= h($BS['icon']) ?> fa-3x text-<?= h($BS['tone']) ?> mb-3" aria-hidden="true"></i>
            <h1 class="h4 fw-bold mb-2"><?= h($BS['headline']) ?></h1>
            <p class="text-body-secondary mb-2"><?= h($BS['detail']) ?></p>
            <?php if ($BS['seconds_to_next'] !== null && in_array($BS['state'], ['before', 'between'], true)): ?>
                <div class="display-6 fw-bold text-primary my-3" id="cdown" data-secs="<?= (int)$BS['seconds_to_next'] ?>" aria-live="off"></div>
            <?php endif; ?>
            <?php if (in_array($BS['state'], ['between', 'closed', 'before'], true) && !empty($STUDENT['current_room_number'])): ?>
                <p class="small text-body-secondary mb-0">
                    <?php if ($STUDENT['phase1_action'] === 'keep'): ?>You chose to keep room <strong><?= h($STUDENT['current_room_number']) ?></strong>.
                    <?php elseif ($STUDENT['phase1_action'] === 'release'): ?>You released your room. Choose a new one when Phase 2 opens.
                    <?php else: ?>Your current room is <strong><?= h($STUDENT['current_room_number']) ?></strong>.<?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($BS['timeline'])): ?>
    <div class="card student-card mb-3">
        <div class="card-body">
            <h2 class="h6 fw-bold mb-3">Booking timeline for the <?= h($BS['wing']) ?></h2>
            <ul class="list-group list-group-flush">
                <?php foreach ($BS['timeline'] as $t): $c = ['done' => 'secondary', 'live' => 'success', 'upcoming' => 'primary'][$t['status']]; $l = ['done' => 'Over', 'live' => 'Open now', 'upcoming' => 'Coming up'][$t['status']]; ?>
                <li class="list-group-item px-0 d-flex justify-content-between align-items-start gap-2">
                    <div><div class="fw-semibold"><?= h($t['label']) ?></div><div class="small text-body-secondary"><?= h($t['when']) ?></div></div>
                    <span class="badge text-bg-<?= $c ?> align-self-center"><?= $l ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($BS['state'] === 'closed'): ?>
    <div class="alert alert-light border small">Missed it? Your Dean or Secretary can help you. They can send the payment prompt straight to your phone and reopen booking for you.</div>
    <?php endif; ?>
    <script>
    (function () {
        let secs = <?= (int)($BS['seconds_to_next'] ?? 0) ?>; const el = document.getElementById('cdown'), end = Date.now() + secs * 1000;
        const fmt = s => { const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (d ? d + 'd ' : '') + String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ':' + String(x).padStart(2, '0'); };
        const tick = () => { const left = Math.max(0, Math.round((end - Date.now()) / 1000)); if (el) el.textContent = fmt(left); if (left <= 0) { setTimeout(() => location.reload(), 1500); return; } setTimeout(tick, 1000); };
        if (secs > 0 && secs < 3 * 86400) tick();
        document.addEventListener('visibilitychange', () => { if (!document.hidden && secs > 0 && Date.now() > end) location.reload(); });
    })();
    </script>

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
            <strong><?= $lateMode ? 'Late booking: choose your room' : 'Phase 2 — Move Window' ?></strong>
            <div class="small">
                <?= $lateMode ? 'Your late fee is paid. Pick from the rooms that are still free.' : 'Pick from available rooms.' ?>
                <?php if ($STUDENT['phase1_action'] === 'keep'): ?>
                    <br><em>You kept your room in Phase 1, so no action is needed.</em>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($lateMode && $BS['can_keep']): ?>
        <div class="card student-card mb-3 border-success">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div><div class="fw-bold">Keep your current room</div><div class="small text-body-secondary">Room <strong><?= h($STUDENT['current_room_number']) ?></strong> can still stay yours.</div></div>
                <button class="btn btn-success" id="keepBtn" onclick="submitAction('keep')"><span class="btn-label"><i class="fas fa-check me-1"></i>Keep my room</span><span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Working...</span></button>
            </div>
        </div>
    <?php endif; ?>
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
        <div class="card student-card mb-3 text-bg-primary">
            <div class="card-body p-4 text-center">
                <i class="fas fa-cube fa-3x mb-3"></i>
                <h4 class="fw-bold">Pick your bed in 3D</h4>
                <p class="mb-3 opacity-75">Walk through the hostel, step into a room, see which bunks are free and choose your bed. <?= (int)count($availableRooms) ?> rooms have space.</p>
                <button type="button" class="btn btn-warning btn-lg" data-ai-open data-ai-prompt="Help me find and book the right bed. Ask me what I prefer, then book it for me."><i class="fas fa-wand-magic-sparkles me-2"></i>Let the assistant book it</button> <a href="explore.php" class="btn btn-outline-light btn-lg"><i class="fas fa-person-walking me-2"></i>3D explorer</a>
                <?php if ($BS['fee_paid'] && ($lateMode || $STUDENT['late_booker'])): ?><div class="mt-3"><span class="badge text-bg-success"><i class="fas fa-check me-1"></i>Late fee paid</span></div><?php elseif ($STUDENT['late_booker']): ?><div class="mt-3"><span class="badge text-bg-warning">Late booker: KES <?= (int)get_config('penalty_amount') ?> fee applies</span></div><?php endif; ?>
            </div>
        </div>
        <div class="card student-card">
            <div class="card-body">
                <button class="btn btn-link p-0 text-decoration-none" type="button" data-bs-toggle="collapse" data-bs-target="#listPick">
                    <i class="fas fa-list me-1"></i>Prefer a simple list? Choose a room instead
                </button>
                <div class="collapse mt-3" id="listPick">
                    <div class="list-group mb-3" id="roomGrid">
                        <?php foreach ($availableRooms as $r): ?>
                            <button type="button" class="list-group-item list-group-item-action d-flex justify-content-between room-tile"
                                    data-room-id="<?= (int)$r['id'] ?>" data-room-number="<?= h($r['room_number']) ?>" data-block-name="<?= h($r['block_name']) ?>">
                                <span><strong><?= h($r['room_number']) ?></strong> <span class="text-body-secondary"><?= h($r['block_name']) ?></span></span>
                                <span class="text-body-secondary small"><i class="fas fa-user-friends me-1"></i><?= (int)$r['capacity'] ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="d-flex justify-content-between align-items-center gap-2">
                        <div class="small text-body-secondary" id="selectionInfo">No room selected</div>
                        <button class="btn btn-primary" id="bookBtn" disabled>
                            <span class="btn-label"><i class="fas fa-check me-1"></i>Confirm</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Booking...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php /* ============================================================
       SCREEN: pending_otp
   ============================================================ */ ?>
<?php elseif ($screen === 'pending_otp'): ?>
    <div class="card student-card mx-auto" style="max-width:520px">
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
<?php elseif (in_array($screen, ['pending_payment', 'late_fee'], true)): ?>
    <?php $payBid = $screen === 'late_fee' ? 0 : (int)$activeBooking['id']; $payAmt = $screen === 'late_fee' ? (int)$BS['fee'] : ((int)$activeBooking['penalty_amount'] ?: 200); ?>
    <div class="card student-card mx-auto" style="max-width:560px">
        <div class="card-body p-4">
            <div class="text-center mb-3">
                <i class="fas fa-mobile-screen-button fa-3x text-success mb-3" aria-hidden="true"></i>
                <h1 class="h4 fw-bold mb-1">Pay KES <?= number_format($payAmt) ?></h1>
                <?php if ($screen === 'late_fee'): ?>
                <p class="text-body-secondary small mb-0">Late booking fee. The booking windows have ended, so this must be paid before you can choose a room. Once it is paid, this page unlocks by itself.</p>
                <?php else: ?>
                <p class="text-body-secondary small mb-0">Late booking fee · Room <strong><?= h($activeBooking['room_number']) ?></strong> · <?= h($activeBooking['block_name']) ?></p>
                <?php endif; ?>
            </div>
            <form id="stkForm" novalidate>
                <label class="form-label fw-semibold" for="stkPhone">M-Pesa number</label>
                <div class="input-group input-group-lg mb-2">
                    <span class="input-group-text">+254</span>
                    <input type="tel" inputmode="tel" autocomplete="tel-national" id="stkPhone" class="form-control" placeholder="712 345 678" aria-describedby="stkHelp" required>
                </div>
                <div id="stkHelp" class="form-text mb-3">You will get a prompt on this phone. Enter your M-Pesa PIN to pay.</div>
                <button type="submit" class="btn btn-success btn-lg w-100" id="stkBtn">
                    <span class="btn-label"><i class="fas fa-paper-plane me-2"></i>Send payment prompt</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-2"></span>Sending...</span>
                </button>
            </form>
            <div id="stkLive" class="alert alert-info mt-3 d-none" role="status" aria-live="polite"></div>
            <hr>
            <?php if ($screen === 'late_fee'): ?>
            <div class="small text-body-secondary"><i class="fas fa-circle-info me-1"></i>No phone with you, or the prompt does not come? Go to the hostel Secretary with your registration number (<strong><?= h($STUDENT['reg_number']) ?></strong>). They can send the prompt to your phone for you. Once you pay, this page unlocks.</div>
            <?php else: ?>
            <button class="btn btn-link p-0 text-decoration-none small" type="button" data-bs-toggle="collapse" data-bs-target="#manualPay" aria-expanded="false" aria-controls="manualPay">Already paid by Paybill? Enter the code instead</button>
            <div class="collapse mt-3" id="manualPay">
                <div class="small text-body-secondary mb-2">Paybill <code>247247</code> · Account <code>COMFORTBOOK</code> · KES <?= number_format($payAmt) ?></div>
                <form id="mpesaForm" onsubmit="return submitMpesa(event)">
                    <label class="form-label fw-semibold" for="mpesaInput">M-Pesa confirmation code</label>
                    <input type="text" id="mpesaInput" maxlength="10" placeholder="e.g. QGH7X2K9LM" class="form-control form-control-lg text-center text-uppercase font-monospace fw-bold mb-2">
                    <div id="mpesaError" class="alert alert-danger small d-none" role="alert"></div>
                    <button type="submit" class="btn btn-outline-primary w-100" id="mpesaBtn">
                        <span class="btn-label">Submit code</span>
                        <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Submitting...</span>
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        const f = document.getElementById('stkForm'), live = document.getElementById('stkLive'), btn = document.getElementById('stkBtn'), bid = <?= (int)$payBid ?>;
        const say = (t, c, title) => { live.className = 'alert mt-3 alert-' + (c || 'info'); live.innerHTML = ''; if (title) { const b = document.createElement('div'); b.className = 'fw-semibold'; b.textContent = title; live.appendChild(b); } const m = document.createElement('div'); m.textContent = t; live.appendChild(m); };
        let timer = null, bad = 0, running = false;
        async function poll(n) {
            running = true;
            const r = await fetch('../api/mpesa_status.php?booking_id=' + bid, { credentials: 'same-origin', cache: 'no-store' }).then(x => x.json()).catch(() => null);
            if (!r) {                                    // our own connection dropped: keep trying, never fail the payment because of it
                bad++; say('Your internet connection dropped. Still checking your payment… If you already entered your PIN, your payment is safe.', 'secondary'); timer = setTimeout(() => poll(n + 1), 5000); return;
            }
            bad = 0;
            if (r.status === 'expired') { say(r.message, 'warning', r.title); running = false; return; }
            if (r.status === 'success' || (r.booking_status && r.booking_status !== 'pending_payment')) { say(r.message || 'Payment received.', 'success', 'Payment received. Thank you!'); setTimeout(() => location.reload(), 1200); return; }
            if (r.status === 'review') { say(r.message, 'warning', r.title); running = false; spin(btn, false); return; }
            if (r.status === 'none') { running = false; return; }
            if (r.final) { say(r.message, TONE[r.tone] || 'warning', r.title); spin(btn, false); btn.querySelector('.btn-label').innerHTML = '<i class="fas fa-rotate-right me-2"></i>Send the prompt again'; running = false; return; }
            say(r.message + (r.seconds_left ? ' (' + r.seconds_left + 's left)' : ''), 'info', r.title);
            if (n > 120) { say('This is taking longer than usual. If you entered your PIN, your payment will still be applied. Otherwise send the prompt again.', 'warning', 'Still waiting'); spin(btn, false); running = false; return; }
            timer = setTimeout(() => poll(n + 1), 3000);
        }
        const TONE = { success: 'success', warning: 'warning', danger: 'danger', info: 'info' };
        f.addEventListener('submit', async e => {
            e.preventDefault(); if (running && btn.disabled) return; clearTimeout(timer);
            const phone = document.getElementById('stkPhone').value.trim();
            if (phone.replace(/\D/g, '').length < 9) { say('Enter your M-Pesa number, like 0712 345 678.', 'warning'); return; }
            spin(btn, true);
            const r = await fetch('../api/mpesa_stk.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ booking_id: bid, phone }) })
                .then(x => x.json()).catch(() => ({ ok: false, message: 'Could not reach the system. Check your internet connection and try again. Nothing was charged.' }));
            if (!r.ok) { say(r.message || 'The prompt could not be sent. Nothing was charged.', 'danger'); spin(btn, false); return; }
            say(r.message, 'info', 'Check your phone'); poll(0);
        });
        // a prompt may already be open (page reloaded, or staff sent it): pick it up instead of showing a blank form
        fetch('../api/mpesa_status.php?booking_id=' + bid, { credentials: 'same-origin', cache: 'no-store' }).then(x => x.json()).then(r => { if (r && r.status === 'pending') { spin(btn, true); say(r.message, 'info', r.title); poll(0); } else if (r && r.status === 'review') say(r.message, 'warning', r.title); }).catch(() => {});
    })();
    </script>

<?php /* ============================================================
       SCREEN: pending_approval
   ============================================================ */ ?>
<?php elseif ($screen === 'pending_approval'): ?>
    <div class="card student-card mx-auto" style="max-width:560px">
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
    <div class="card student-card mx-auto" style="max-width:560px">
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
// when the assistant finishes a step (hold, code, payment) show the new state
document.addEventListener('cb:changed', e => { const r = e.detail || {}; if (r.paid || (r.changed && !r.next_card && !r.pay)) setTimeout(() => location.reload(), 4500); });
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
            method: 'POST', credentials: 'same-origin',
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
        document.querySelectorAll('.room-tile').forEach(t => t.classList.remove('active'));
        tile.classList.add('active');
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
            method: 'POST', credentials: 'same-origin',
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
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ booking_id: <?= (int)($activeBooking['id'] ?? 0) ?>, otp })
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
            method: 'POST', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ booking_id: <?= (int)($activeBooking['id'] ?? 0) ?>, mpesa_ref: ref })
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