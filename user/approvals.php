<?php
// user/approvals.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men','dean_women','secretary_men','secretary_women']);

$gender = gender_filter();
$isDean = is_dean();
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $bid    = (int)($_POST['booking_id'] ?? 0);

    if ($bid > 0) {
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

        // ---------- APPROVE ----------
        if ($action === 'approve' && $booking['status'] === 'pending_approval') {
            try {
                $pdo->beginTransaction();

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

        // ---------- CANCEL (Dean only) ----------
        if ($action === 'cancel' && $isDean && $booking['status'] === 'confirmed') {
            try {
                $pdo->beginTransaction();

                $reason = trim($_POST['reason'] ?? 'Cancelled by Dean');
                $pdo->prepare("UPDATE bookings SET status='cancelled', cancelled_reason=? WHERE id=?")
                    ->execute([$reason, $bid]);

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

$search = trim($_GET['q'] ?? '');

$where  = ' WHERE s.gender = ?';
$params = [$gender];
if ($status !== 'all') {
    $where .= ' AND b.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ? OR r.room_number LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

// Pagination
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM bookings b
    JOIN students s ON s.id = b.student_id
    JOIN rooms r    ON r.id = b.room_id
    $where
");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT b.*, s.full_name, s.email, s.reg_number, s.gender,
           r.room_number, bl.name AS block_name,
           u.full_name AS approver,
           sem.name AS semester_name
    FROM bookings b
    JOIN students s  ON s.id = b.student_id
    JOIN rooms r     ON r.id = b.room_id
    JOIN blocks bl   ON bl.id = r.block_id
    LEFT JOIN users u ON u.id = b.approved_by
    LEFT JOIN semesters sem ON sem.id = b.semester_id
    $where
    ORDER BY
        CASE b.status
            WHEN 'pending_approval' THEN 1
            WHEN 'confirmed'        THEN 2
            WHEN 'cancelled'        THEN 3
            ELSE 4
        END,
        b.created_at DESC
    LIMIT $perPage OFFSET $offset
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

$totalAll = array_sum($counts);

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

// URL helper
function app_url(array $overrides = []): string {
    $q = array_merge([
        'q'      => $_GET['q']      ?? '',
        'status' => $_GET['status'] ?? 'pending_approval',
        'page'   => $_GET['page']   ?? 1,
    ], $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'approvals.php' . ($q ? '?' . http_build_query($q) : '');
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
                        <?= h($gender) ?> wing · <?= number_format($counts['pending_approval']) ?> awaiting
                        <?php if ($activeSem): ?>
                            · active: <strong><?= h($activeSem['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <span class="badge bg-info fs-6">
                    <i class="fas fa-clipboard-list me-1"></i>
                    <?= number_format($totalAll) ?> total booking<?= $totalAll===1?'':'s' ?>
                </span>
            </div>

            <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show d-flex align-items-center auto-dismiss">
                <i class="fas fa-<?= $flash['type']==='success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <div class="flex-grow-1"><?= h($flash['msg']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Info banner -->
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
                <a href="<?= h(app_url(['status'=>'pending_approval','page'=>1])) ?>"
                   class="btn btn-sm <?= $status==='pending_approval'?'btn-info':'btn-outline-info' ?>">
                    Awaiting <span class="badge bg-light text-dark ms-1"><?= number_format($counts['pending_approval']) ?></span>
                </a>
                <a href="<?= h(app_url(['status'=>'confirmed','page'=>1])) ?>"
                   class="btn btn-sm <?= $status==='confirmed'?'btn-success':'btn-outline-success' ?>">
                    Confirmed <span class="badge bg-light text-dark ms-1"><?= number_format($counts['confirmed']) ?></span>
                </a>
                <a href="<?= h(app_url(['status'=>'cancelled','page'=>1])) ?>"
                   class="btn btn-sm <?= $status==='cancelled'?'btn-danger':'btn-outline-danger' ?>">
                    Cancelled <span class="badge bg-light text-dark ms-1"><?= number_format($counts['cancelled']) ?></span>
                </a>
                <a href="<?= h(app_url(['status'=>'all','page'=>1])) ?>"
                   class="btn btn-sm <?= $status==='all'?'btn-primary':'btn-outline-primary' ?>">
                    All <span class="badge bg-light text-dark ms-1"><?= number_format($totalAll) ?></span>
                </a>
            </div>

            <!-- Search -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end" id="filterForm">
                    <input type="hidden" name="status" value="<?= h($status) ?>">
                    <div class="col-md-8">
                        <label class="form-label small mb-1">Search</label>
                        <div class="position-relative">
                            <input type="text" name="q" id="searchInput"
                                   class="form-control form-control-sm"
                                   value="<?= h($search) ?>" placeholder="Name, reg #, room"
                                   autocomplete="off">
                            <span id="searchSpinner" class="position-absolute top-50 end-0 translate-middle-y me-2 d-none">
                                <span class="spinner-border spinner-border-sm text-primary"></span>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill" id="filterBtn">
                            <span class="btn-label"><i class="fas fa-search me-1"></i>Search</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                        </button>
                        <a href="approvals.php?status=<?= h($status) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-times"></i>
                        </a>
                    </div>
                </form>
            </div></div>

            <!-- Queue -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($bookings)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-3x mb-3 opacity-25"></i>
                        <h6>No bookings in this view</h6>
                        <p class="small mb-0">Adjust filters or switch tabs.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Room</th>
                                    <th>Semester</th>
                                    <th class="text-center">Phase</th>
                                    <th class="text-center">OTP</th>
                                    <th>M-Pesa</th>
                                    <th>Status</th>
                                    <th>Approved</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($bookings as $b): ?>
                                <tr>
                                    <td>
                                        <div class="fw-semibold small"><?= h($b['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($b['reg_number']) ?></div>
                                    </td>
                                    <td>
                                        <div class="small fw-semibold"><?= h($b['room_number']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($b['block_name']) ?></div>
                                    </td>
                                    <td class="small">
                                        <?= $b['semester_name'] ? h($b['semester_name']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="text-center"><span class="badge bg-light text-dark">P<?= (int)$b['phase'] ?></span></td>
                                    <td class="text-center">
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
                                    <td class="small text-muted">
                                        <?php if ($b['approved_at']): ?>
                                            <div><?= date('M j, H:i', strtotime($b['approved_at'])) ?></div>
                                            <div style="font-size:.7rem;">by <?= h($b['approver'] ?? '—') ?></div>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($b['status'] === 'pending_approval'): ?>
                                            <button class="btn btn-sm btn-success approve-btn"
                                                    data-bid="<?= (int)$b['id'] ?>"
                                                    data-name="<?= h($b['full_name']) ?>"
                                                    data-room="<?= h($b['room_number']) ?>"
                                                    data-block="<?= h($b['block_name']) ?>">
                                                <span class="btn-label"><i class="fas fa-check me-1"></i>Approve</span>
                                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                            </button>
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
                                            <span class="badge bg-light text-dark small" title="Placed when semester is activated">
                                                <i class="fas fa-hourglass-half me-1"></i>Awaiting start
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

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center">
                        <div class="small text-muted mb-2 mb-md-0">
                            Showing <strong><?= number_format($offset + 1) ?></strong>–<strong><?= number_format(min($offset + $perPage, $totalRows)) ?></strong>
                            of <strong><?= number_format($totalRows) ?></strong>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page <= 1 ? '#' : h(app_url(['page' => $page - 1])) ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                if ($start > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(app_url(['page' => 1])) ?>">1</a></li>
                                    <?php if ($start > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h(app_url(['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(app_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(app_url(['page' => $page + 1])) ?>">
                                        <i class="fas fa-angle-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div></div>

            <div class="pb-4"></div>
        </main>

<!-- Approve modal -->
<div class="modal fade" id="approveModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="approveForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-check-circle text-success me-2"></i>Approve Booking
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Confirm booking for <strong id="approveName"></strong> into
                    <strong id="approveRoom"></strong> (<span id="approveBlock"></span>)?
                </p>
                <div class="alert alert-info small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    A confirmation email will be sent. The student will be placed in the room when the semester starts.
                </div>
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="booking_id" id="approveBid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success" id="approveSubmit">
                    <span class="btn-label"><i class="fas fa-check me-1"></i>Approve & Email</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Approving...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="rejectForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-times-circle text-danger me-2"></i>Reject Booking
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Reject booking for <strong id="rejectName"></strong> (Room <strong id="rejectRoom"></strong>)?</p>
                <label class="form-label small fw-bold">Reason <span class="text-muted">(optional)</span></label>
                <textarea name="reason" class="form-control" rows="2" placeholder="e.g. OTP not verified / Invalid M-Pesa ref / Room no longer available"></textarea>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="booking_id" id="rejectBid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-danger" id="rejectSubmit">
                    <span class="btn-label"><i class="fas fa-times me-1"></i>Reject & Free Room</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Rejecting...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<!-- Cancel modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="cancelForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-ban text-danger me-2"></i>Cancel Confirmed Booking
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Cancel confirmed booking for <strong id="cancelName"></strong> (Room <strong id="cancelRoom"></strong>)?</p>
                <div class="alert alert-warning small">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    The room is freed and the student is unplaced. This cannot be undone.
                </div>
                <label class="form-label small fw-bold">Reason <span class="text-danger">*</span></label>
                <textarea name="reason" class="form-control" rows="2" required placeholder="Reason for cancellation"></textarea>
                <input type="hidden" name="action" value="cancel">
                <input type="hidden" name="booking_id" id="cancelBid">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Keep</button>
                <button type="submit" class="btn btn-danger" id="cancelSubmit">
                    <span class="btn-label"><i class="fas fa-ban me-1"></i>Cancel Booking</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Cancelling...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// ---------- Auto-dismiss flash ----------
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 4000);
});

// ---------- Debounced search ----------
let searchTimer = null;
const searchInput   = document.getElementById('searchInput');
const searchSpinner = document.getElementById('searchSpinner');

searchInput?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchSpinner.classList.remove('d-none');
    searchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 500);
});

// ---------- Filter spinner ----------
document.getElementById('filterForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('filterBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Approve ----------
document.querySelectorAll('.approve-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('approveBid').value         = btn.dataset.bid;
        document.getElementById('approveName').textContent  = btn.dataset.name;
        document.getElementById('approveRoom').textContent  = btn.dataset.room;
        document.getElementById('approveBlock').textContent = btn.dataset.block;
        new bootstrap.Modal(document.getElementById('approveModal')).show();
    });
});

document.getElementById('approveForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('approveSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Reject ----------
document.getElementById('rejectModal')?.addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('rejectBid').value = b.dataset.bid;
    document.getElementById('rejectName').textContent = b.dataset.name;
    document.getElementById('rejectRoom').textContent = b.dataset.room;
});

document.getElementById('rejectForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('rejectSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Cancel ----------
document.getElementById('cancelModal')?.addEventListener('show.bs.modal', e => {
    const b = e.relatedTarget;
    document.getElementById('cancelBid').value = b.dataset.bid;
    document.getElementById('cancelName').textContent = b.dataset.name;
    document.getElementById('cancelRoom').textContent = b.dataset.room;
});

document.getElementById('cancelForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('cancelSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Reset spinners on bfcache restore ----------
window.addEventListener('pageshow', () => {
    document.querySelectorAll('.btn-label').forEach(el => el.classList.remove('d-none'));
    document.querySelectorAll('.btn-spin').forEach(el => el.classList.add('d-none'));
    document.querySelectorAll('button[type="submit"]').forEach(btn => btn.disabled = false);
});
</script>