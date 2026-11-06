<?php
// user/approvals.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men','dean_women','secretary_men','secretary_women']);

$gender  = gender_filter();
$isDean  = is_dean();
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $bid    = (int)($_POST['booking_id'] ?? 0);

    if ($bid > 0) {
        // Load booking (scoped to wing)
        $stmt = $pdo->prepare("
            SELECT b.*, s.full_name, s.email, s.gender, s.reg_number,
                   r.room_number, r.id AS room_id, bl.name AS block_name
            FROM bookings b
            JOIN students s ON s.id = b.student_id
            JOIN rooms r    ON r.id = b.room_id
            JOIN blocks bl  ON bl.id = r.block_id
            WHERE b.id = ? AND s.gender = ?
        ");
        $stmt->execute([$bid, $gender]);
        $booking = $stmt->fetch();

        if (!$booking) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Booking not found or outside your wing.'];
            redirect(APP_BASE . '/user/approvals.php');
        }

        // ---------- APPROVE (confirm only — do NOT assign room yet) ----------
        if ($action === 'approve' && $booking['status'] === 'pending_approval') {
            try {
                $pdo->beginTransaction();

                // Target semester = booking's own semester, else next-up semester
                $targetSem = (int)($booking['semester_id'] ?? 0);
                if ($targetSem <= 0) {
                    $targetSem = active_semester_id() ?? 0;
                }

                $pdo->prepare("
                    UPDATE bookings
                    SET status='confirmed',
                        approved_by=?,
                        approved_at=NOW(),
                        semester_id=?
                    WHERE id = ?
                ")->execute([$_SESSION['user_id'], $targetSem, $bid]);

                $pdo->commit();

                audit_log($_SESSION['user_id'], $booking['student_id'], 'booking_approve',
                    "Confirmed booking #$bid for {$booking['reg_number']} → {$booking['room_number']} (sem #$targetSem)");

                // Confirmation email — NOT the "welcome to your room" one
                @send_confirmation_email(
                    $booking['email'],
                    $booking['full_name'],
                    $booking['room_number'],
                    $booking['block_name']
                );

                $_SESSION['flash'] = [
                    'type'=>'success',
                    'msg'=>'Booking confirmed · email sent. Room assignment happens when the semester is activated.',
                ];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Approve failed: '.$e->getMessage()];
            }
            redirect(APP_BASE . '/user/approvals.php');
        }

        // ---------- REJECT ----------
        if ($action === 'reject') {
            try {
                $pdo->beginTransaction();

                $reason = trim($_POST['reason'] ?? 'Rejected by staff');
                $pdo->prepare("
                    UPDATE bookings SET status='cancelled', cancelled_reason=?
                    WHERE id = ?
                ")->execute([$reason, $bid]);

                // Free the room if it was reserved
                $pdo->prepare("
                    UPDATE rooms
                    SET status = CASE WHEN phase = 1 THEN 'occupied' ELSE 'available_p2' END,
                        reserved_by_student_id = NULL,
                        reserved_at = NULL
                    WHERE id = ?
                ")->execute([$booking['room_id']]);

                $pdo->commit();

                audit_log($_SESSION['user_id'], $booking['student_id'], 'booking_reject',
                    "Rejected booking #$bid: $reason");

                $_SESSION['flash'] = ['type'=>'success','msg'=>'Booking rejected and room freed.'];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Reject failed: '.$e->getMessage()];
            }
            redirect(APP_BASE . '/user/approvals.php');
        }

        // ---------- CANCEL (Dean only, confirmed bookings) ----------
        if ($action === 'cancel' && $isDean && $booking['status'] === 'confirmed') {
            try {
                $pdo->beginTransaction();

                $reason = trim($_POST['reason'] ?? 'Cancelled by Dean');
                $pdo->prepare("UPDATE bookings SET status='cancelled', cancelled_reason=? WHERE id=?")
                    ->execute([$reason, $bid]);

                // Free the room (only if no one else occupies it in that semester)
                $pdo->prepare("
                    UPDATE rooms SET status='available_p2', reserved_by_student_id=NULL, reserved_at=NULL
                    WHERE id=?
                      AND NOT EXISTS (
                          SELECT 1 FROM bookings b2
                          WHERE b2.room_id = rooms.id
                            AND b2.status='confirmed'
                            AND b2.id <> ?
                      )
                ")->execute([$booking['room_id'], $bid]);

                // If the semester is already active, un-place the student
                $activeSid = active_semester_id();
                if ($activeSid && (int)$booking['semester_id'] === (int)$activeSid) {
                    $pdo->prepare("UPDATE students SET placed=0, current_room_id=NULL WHERE id=?")
                        ->execute([$booking['student_id']]);
                }

                $pdo->commit();
                audit_log($_SESSION['user_id'], $booking['student_id'], 'booking_cancel', "Dean cancelled booking #$bid");
                $_SESSION['flash'] = ['type'=>'success','msg'=>'Confirmed booking cancelled. Room freed.'];
            } catch (Throwable $e) {
                $pdo->rollBack();
                $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cancel failed: '.$e->getMessage()];
            }
            redirect(APP_BASE . '/user/approvals.php');
        }
    }
}

// ---------- QUEUE ----------
$status = $_GET['status'] ?? 'pending_approval';
if (!in_array($status, ['pending_approval','confirmed','cancelled','all'], true)) {
    $status = 'pending_approval';
}

$where  = ' WHERE s.gender = ?';
$params = [$gender];
if ($status !== 'all') {
    $where .= ' AND b.status = ?';
    $params[] = $status;
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ? OR r.room_number LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$stmt = $pdo->prepare("
    SELECT b.*, s.full_name, s.email, s.reg_number, s.gender,
           r.room_number, bl.name AS block_name,
           u.full_name AS approver,
           sem.name AS semester_name
    FROM bookings b
    JOIN students s  ON s.id = b.student_id
    JOIN rooms r     ON r.id = b.room_id
    JOIN blocks bl   ON bl.id = r.block_id
    LEFT JOIN users u   ON u.id = b.approved_by
    LEFT JOIN semesters sem ON sem.id = b.semester_id
    $where
    ORDER BY b.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Counts
$stmt = $pdo->prepare("
    SELECT b.status, COUNT(*) c
    FROM bookings b JOIN students s ON s.id = b.student_id
    WHERE s.gender = ? GROUP BY b.status
");
$stmt->execute([$gender]);
$counts = ['pending_approval'=>0,'confirmed'=>0,'cancelled'=>0];
foreach ($stmt as $row) $counts[$row['status']] = (int)$row['c'];

$activeSem = active_semester();

function status_badge(string $s): string {
    return match($s) {
        'pending_otp'      => '<span class="badge bg-secondary">OTP</span>',
        'pending_payment'  => '<span class="badge bg-warning text-dark">Payment</span>',
        'pending_approval' => '<span class="badge bg-info text-dark">Awaiting</span>',
        'confirmed'        => '<span class="badge bg-success">Confirmed</span>',
        'cancelled'        => '<span class="badge bg-danger">Cancelled</span>',
        default            => '<span class="badge bg-light text-dark">' . h($s) . '</span>',
    };
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-check-circle text-primary me-2"></i>Approvals</h4>
                    <p class="text-muted small mb-0">
                        <?= h($gender) ?> wing · <?= $counts['pending_approval'] ?> awaiting
                        <?php if ($activeSem): ?>
                            · active: <strong><?= h($activeSem['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Info about the new flow -->
            <div class="alert alert-info d-flex align-items-start">
                <i class="fas fa-info-circle me-2 mt-1"></i>
                <div class="small">
                    <strong>Approve</strong> marks the booking as confirmed for its semester and sends a
                    <em>confirmation</em> email.
                    The student is <strong>physically placed</strong> into the room only when an admin
                    <strong>activates the semester</strong> in Semester Control — that's when the welcome email fires.
                </div>
            </div>

            <!-- Status chips -->
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a href="approvals.php?status=pending_approval" class="btn btn-sm <?= $status==='pending_approval'?'btn-info':'btn-outline-info' ?>">
                    Awaiting <span class="badge bg-light text-dark ms-1"><?= $counts['pending_approval'] ?></span>
                </a>
                <a href="approvals.php?status=confirmed" class="btn btn-sm <?= $status==='confirmed'?'btn-success':'btn-outline-success' ?>">
                    Confirmed <span class="badge bg-light text-dark ms-1"><?= $counts['confirmed'] ?></span>
                </a>
                <a href="approvals.php?status=cancelled" class="btn btn-sm <?= $status==='cancelled'?'btn-danger':'btn-outline-danger' ?>">
                    Cancelled <span class="badge bg-light text-dark ms-1"><?= $counts['cancelled'] ?></span>
                </a>
                <a href="approvals.php?status=all" class="btn btn-sm <?= $status==='all'?'btn-primary':'btn-outline-primary' ?>">
                    All
                </a>
            </div>

            <!-- Search -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <input type="hidden" name="status" value="<?= h($status) ?>">
                    <div class="col-md-8"><label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Name, reg #, room"></div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-search me-1"></i>Search</button>
                        <a href="approvals.php?status=<?= h($status) ?>" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <!-- Queue -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($bookings)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                        <h6>No bookings in this view</h6>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Room</th>
                                    <th>Semester</th>
                                    <th>Phase</th>
                                    <th>OTP</th>
                                    <th>M-Pesa</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($b['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($b['reg_number']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?= h($b['room_number']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($b['block_name']) ?></div>
                                    </td>
                                    <td class="small">
                                        <?= $b['semester_name'] ? h($b['semester_name']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td><span class="badge bg-light text-dark">P<?= (int)$b['phase'] ?></span></td>
                                    <td>
                                        <?php if ($b['otp_verified_at']): ?>
                                            <span class="badge bg-success" title="<?= h($b['otp_verified_at']) ?>"><i class="fas fa-check"></i></span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($b['mpesa_ref']): ?>
                                            <code class="small"><?= h($b['mpesa_ref']) ?></code>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= status_badge($b['status']) ?></td>
                                    <td class="text-end">
                                        <?php if ($b['status'] === 'pending_approval'): ?>
                                            <form method="post" class="d-inline"
                                                  onsubmit="return confirm('Confirm booking for <?= h(addslashes($b['full_name'])) ?> into <?= h($b['room_number']) ?>? The student will be placed in the room when the semester is activated.');">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                                <button class="btn btn-sm btn-success">
                                                    <i class="fas fa-check me-1"></i>Approve
                                                </button>
                                            </form>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#rejectModal"
                                                    data-bid="<?= (int)$b['id'] ?>"
                                                    data-name="<?= h($b['full_name']) ?>"
                                                    data-room="<?= h($b['room_number']) ?>">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        <?php elseif ($b['status'] === 'confirmed' && $isDean): ?>
                                            <button class="btn btn-sm btn-outline-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#cancelModal"
                                                    data-bid="<?= (int)$b['id'] ?>"
                                                    data-name="<?= h($b['full_name']) ?>"
                                                    data-room="<?= h($b['room_number']) ?>">
                                                <i class="fas fa-ban"></i> Cancel
                                            </button>
                                        <?php elseif ($b['status'] === 'confirmed'): ?>
                                            <span class="badge bg-light text-dark small"
                                                  title="Placed when semester is activated">
                                                <i class="fas fa-hourglass-half me-1"></i>Awaiting semester start
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div></div>

            <div class="pb-4"></div>
        </main>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h5 class="modal-title">Reject Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Reject booking for <strong id="rejectName"></strong> (Room <strong id="rejectRoom"></strong>)?</p>
                <label class="form-label">Reason (optional)</label>
                <input type="text" name="reason" class="form-control" placeholder="e.g., OTP not verified / Invalid M-Pesa ref">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="booking_id" id="rejectBid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger">Reject &amp; Free Room</button>
            </div>
        </form>
    </div></div>
</div>

<!-- Cancel modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h5 class="modal-title">Cancel Confirmed Booking</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Cancel confirmed booking for <strong id="cancelName"></strong> (Room <strong id="cancelRoom"></strong>)?</p>
                <p class="small text-muted">The room is freed and the student is unplaced.</p>
                <label class="form-label">Reason</label>
                <input type="text" name="reason" class="form-control" required placeholder="Reason for cancel">
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="booking_id" id="cancelBid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep</button>
                <button class="btn btn-danger">Cancel Booking</button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>
<script>
document.getElementById('rejectModal')?.addEventListener('show.bs.modal', function (e) {
    const b = e.relatedTarget;
    document.getElementById('rejectBid').value = b.dataset.bid;
    document.getElementById('rejectName').textContent = b.dataset.name;
    document.getElementById('rejectRoom').textContent = b.dataset.room;
});
document.getElementById('cancelModal')?.addEventListener('show.bs.modal', function (e) {
    const b = e.relatedTarget;
    document.getElementById('cancelBid').value = b.dataset.bid;
    document.getElementById('cancelName').textContent = b.dataset.name;
    document.getElementById('cancelRoom').textContent = b.dataset.room;
});
</script>