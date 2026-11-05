<?php
// student/book.php — magic link landing
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';

$phase = current_phase();
$flash = $_SESSION['magic_flash'] ?? null;
unset($_SESSION['magic_flash']);

// Already has active booking?
$stmt = $pdo->prepare("
    SELECT b.*, r.room_number, bl.name AS block_name
    FROM bookings b
    JOIN rooms r ON r.id = b.room_id
    JOIN blocks bl ON bl.id = r.block_id
    WHERE b.student_id = ? AND b.status IN ('pending_otp','pending_payment','pending_approval','confirmed')
    ORDER BY b.created_at DESC LIMIT 1
");
$stmt->execute([$STUDENT['id']]);
$activeBooking = $stmt->fetch();

// Phase 2 available rooms (only if released or unplaced)
$availableRooms = [];
if ($phase === 2 && $STUDENT['phase1_action'] === 'release' || ($phase === 2 && !$STUDENT['placed'])) {
    $stmt = $pdo->prepare("
        SELECT r.id, r.room_number, r.capacity, b.name AS block_name, b.id AS block_id
        FROM rooms r
        JOIN blocks b ON b.id = r.block_id
        WHERE r.gender = ? AND r.status IN ('vacant','available_p2')
        ORDER BY b.name, r.room_number
    ");
    $stmt->execute([$STUDENT['gender']]);
    $availableRooms = $stmt->fetchAll();
}

student_header('My Booking', $STUDENT);
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
        <?= h($flash['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Student summary card -->
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

<?php if ($activeBooking): ?>
    <!-- Already has a booking in progress -->
    <div class="card student-card">
        <div class="card-body text-center py-5">
            <?php
            $icon = match($activeBooking['status']) {
                'pending_otp'      => ['fa-key',        'secondary', 'OTP Verification Needed'],
                'pending_payment'  => ['fa-money-bill', 'warning',   'Payment Reference Needed'],
                'pending_approval' => ['fa-hourglass-half','info',   'Awaiting Approval'],
                'confirmed'        => ['fa-check-circle','success',  'Booking Confirmed'],
                default            => ['fa-info-circle','primary',   'Booking In Progress'],
            };
            ?>
            <i class="fas <?= $icon[0] ?> fa-4x text-<?= $icon[1] ?> mb-3"></i>
            <h4 class="fw-bold"><?= h($icon[2]) ?></h4>
            <p class="text-muted mb-3">
                Room <strong><?= h($activeBooking['room_number']) ?></strong>
                · <?= h($activeBooking['block_name']) ?>
                <?php if ($activeBooking['penalty_amount'] > 0): ?>
                    · <span class="badge bg-warning text-dark">KES <?= (int)$activeBooking['penalty_amount'] ?> penalty</span>
                <?php endif; ?>
            </p>

            <?php if ($activeBooking['status'] === 'pending_otp'): ?>
                <a href="verify_otp.php?booking=<?= (int)$activeBooking['id'] ?>" class="btn btn-primary">
                    <i class="fas fa-key me-1"></i>Enter OTP Code
                </a>
            <?php elseif ($activeBooking['status'] === 'pending_payment'): ?>
                <a href="mpesa.php?booking=<?= (int)$activeBooking['id'] ?>" class="btn btn-primary">
                    <i class="fas fa-money-bill me-1"></i>Submit M-Pesa Reference
                </a>
            <?php elseif ($activeBooking['status'] === 'pending_approval'): ?>
                <a href="done.php" class="btn btn-outline-primary">
                    <i class="fas fa-info-circle me-1"></i>View Status
                </a>
            <?php else: ?>
                <a href="done.php" class="btn btn-success">
                    <i class="fas fa-check me-1"></i>View Confirmation
                </a>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($phase === null): ?>
    <div class="card student-card">
        <div class="card-body text-center py-5">
            <i class="fas fa-clock fa-4x text-secondary mb-3"></i>
            <h4 class="fw-bold">Booking Not Open</h4>
            <p class="text-muted mb-0">The booking window is currently closed. Check back later or contact your Dean.</p>
        </div>
    </div>

<?php elseif ($phase === 1): ?>
    <!-- ==================== PHASE 1 ==================== -->
    <div class="alert alert-primary d-flex align-items-center">
        <i class="fas fa-door-open fa-2x me-3"></i>
        <div>
            <strong>Phase 1 — Keep or Release</strong>
            <div class="small">
                Keep your current room, or release it to book a new one in Phase 2.
                Ends <?= date('M j, H:i', strtotime(get_config('phase1_end'))) ?>.
            </div>
        </div>
    </div>

    <?php if ($STUDENT['phase1_action'] === 'none'): ?>
        <div class="card student-card">
            <div class="card-body text-center py-5">
                <h5 class="fw-bold mb-4">What would you like to do?</h5>
                <div class="row g-3 justify-content-center">
                    <div class="col-md-5">
                        <div class="p-4 border rounded-3 h-100" style="background:#f0f8f4;">
                            <i class="fas fa-lock fa-2x text-success mb-3"></i>
                            <h6 class="fw-bold">Keep My Room</h6>
                            <p class="small text-muted mb-3">
                                Room <strong><?= h($STUDENT['current_room_number']) ?></strong>
                                stays yours for next semester.
                            </p>
                            <button class="btn btn-success w-100"
                                    onclick="submitAction('keep')">
                                <i class="fas fa-check me-1"></i>Keep My Room
                            </button>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <div class="p-4 border rounded-3 h-100" style="background:#eef4fb;">
                            <i class="fas fa-exchange-alt fa-2x text-primary mb-3"></i>
                            <h6 class="fw-bold">Release My Room</h6>
                            <p class="small text-muted mb-3">
                                Free up your room and book a new one in Phase 2 (starts <?= date('M j', strtotime(get_config('phase2_start'))) ?>).
                            </p>
                            <button class="btn btn-outline-primary w-100"
                                    onclick="submitAction('release')">
                                <i class="fas fa-exchange-alt me-1"></i>Release My Room
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
                <p class="text-muted">
                    You released <strong><?= h($STUDENT['current_room_number']) ?></strong>.
                </p>
                <p class="small text-muted">
                    Come back in Phase 2 (starts <?= date('M j, H:i', strtotime(get_config('phase2_start'))) ?>)
                    to book a new room. This link will still work.
                </p>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($phase === 2): ?>
    <!-- ==================== PHASE 2 ==================== -->
    <div class="alert alert-success d-flex align-items-center">
        <i class="fas fa-door-closed fa-2x me-3"></i>
        <div>
            <strong>Phase 2 — Move Window</strong>
            <div class="small">
                Pick from available rooms. Ends <?= date('M j, H:i', strtotime(get_config('phase2_end'))) ?>.
                <?php if ($STUDENT['phase1_action'] === 'none'): ?>
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
                <p class="small text-muted mb-0">No further action needed. Welcome to the next semester.</p>
            </div>
        </div>

    <?php elseif (empty($availableRooms)): ?>
        <div class="card student-card">
            <div class="card-body text-center py-5">
                <i class="fas fa-bed fa-4x text-secondary mb-3"></i>
                <h4 class="fw-bold">No Rooms Available</h4>
                <p class="text-muted mb-0">All rooms are taken. Please contact your Dean for manual placement.</p>
            </div>
        </div>

    <?php else: ?>
        <div class="card student-card">
            <div class="card-body">
                <h5 class="fw-bold mb-3">
                    <i class="fas fa-bed me-2 text-primary"></i>
                    Choose a Room
                    <span class="badge bg-light text-dark ms-2"><?= count($availableRooms) ?> available</span>
                </h5>
                <p class="small text-muted mb-3">
                    Tap a room to select. You'll confirm with an OTP sent to your email.
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
                        <i class="fas fa-check me-1"></i>Confirm Booking
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<script>
const BOOK_API = '../api/book_room.php';
let selectedRoomId = null;

document.querySelectorAll('.room-tile').forEach(tile => {
    tile.addEventListener('click', () => {
        document.querySelectorAll('.room-tile').forEach(t => t.classList.remove('selected'));
        tile.classList.add('selected');
        selectedRoomId = tile.dataset.roomId;

        const info = document.getElementById('selectionInfo');
        if (info) info.innerHTML = 'Selected: <strong>' + tile.dataset.roomNumber + '</strong> · ' + tile.dataset.blockName;
        const btn = document.getElementById('bookBtn');
        if (btn) btn.disabled = false;
    });
});

document.getElementById('bookBtn')?.addEventListener('click', async () => {
    if (!selectedRoomId) return;
    const btn = document.getElementById('bookBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Booking...';

    try {
        const res = await fetch(BOOK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'book', room_id: selectedRoomId })
        });
        const data = await res.json();
        if (data.ok) {
            window.location = 'verify_otp.php?booking=' + data.booking_id;
        } else {
            alert(data.error || 'Booking failed');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-check me-1"></i>Confirm Booking';
            location.reload();
        }
    } catch (err) {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check me-1"></i>Confirm Booking';
    }
});

async function submitAction(action) {
    if (!confirm(action === 'keep' ? 'Keep your current room?' : 'Release your room? You will pick a new one in Phase 2.')) return;

    try {
        const res = await fetch(BOOK_API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: action })
        });
        const data = await res.json();
        if (data.ok) {
            window.location = 'verify_otp.php?booking=' + data.booking_id;
        } else {
            alert(data.error || 'Action failed');
        }
    } catch (err) {
        alert('Network error.');
    }
}
</script>

<?php student_footer(); ?>