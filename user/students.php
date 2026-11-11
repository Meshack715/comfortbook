<?php
// user/students.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$isAdmin = is_admin();
$gender  = gender_filter();
$flash   = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$activeSid = active_semester_id();

// ---------- DEACTIVATE (admin only) ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    $id     = (int)($_POST['id'] ?? 0);
    $reason = $_POST['reason'] ?? 'other';
    $note   = trim($_POST['note'] ?? '');

    if ($id > 0 && in_array($reason, ['deferred','suspended','withdrawn','other'], true)) {
        // Refuse if student still has active bookings
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE student_id = ? AND status IN ('pending_otp','pending_payment','pending_approval','confirmed')");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot deactivate — student has active bookings.'];
        } else {
            $pdo->prepare("
                UPDATE students
                SET status='inactive', is_active=0, placed=0, current_room_id=NULL,
                    inactive_reason=?, inactive_note=?, inactive_at=NOW(), inactive_by=?
                WHERE id=?
            ")->execute([$reason, $note ?: null, $_SESSION['user_id'], $id]);

            // Free any room they were occupying
            $pdo->prepare("
                UPDATE rooms SET status='available_p2'
                WHERE id NOT IN (
                    SELECT DISTINCT current_room_id FROM students
                    WHERE current_room_id IS NOT NULL AND is_active = 1
                )
            ")->execute();

            audit_log($_SESSION['user_id'], $id, 'student_deactivate', "Deactivated #$id — $reason: $note");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Student deactivated.'];
        }
    }
    redirect(APP_BASE.'/user/students.php');
}

// ---------- REACTIVATE (admin only) ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reactivate') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("
            UPDATE students
            SET status='active', is_active=1,
                inactive_reason=NULL, inactive_note=NULL, inactive_at=NULL, inactive_by=NULL
            WHERE id=?
        ")->execute([$id]);
        audit_log($_SESSION['user_id'], $id, 'student_reactivate', "Reactivated #$id");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Student reactivated.'];
    }
    redirect(APP_BASE.'/user/students.php');
}

// ---------- FILTERS ----------
$filterGender = $_GET['gender'] ?? '';
$filterBlock  = (int)($_GET['block_id'] ?? 0);
$filterPlaced = $_GET['placed'] ?? '';
$filterStatus = $_GET['status'] ?? 'active';       // active | inactive | all
$search       = trim($_GET['q'] ?? '');

// Only admin can view inactive/all; others forced to active
if (!$isAdmin) {
    $filterStatus = 'active';
}
if (!in_array($filterStatus, ['active','inactive','all'], true)) {
    $filterStatus = 'active';
}

// Placed this semester = confirmed booking for active semester
$placedExpr = $activeSid
    ? "EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.status='confirmed' AND b.semester_id = $activeSid)"
    : "s.placed = 1";

// Base WHERE
$where  = ' WHERE 1=1';
$params = [];

// Status scope
if ($filterStatus === 'active') {
    $where .= " AND s.is_active = 1";
} elseif ($filterStatus === 'inactive') {
    $where .= " AND s.is_active = 0";
}
// 'all' → no status clause

if ($gender !== null) {
    $where .= ' AND s.gender = ?'; $params[] = $gender;
} elseif (in_array($filterGender, ['Male','Female'], true)) {
    $where .= ' AND s.gender = ?'; $params[] = $filterGender;
}
if ($filterBlock > 0) { $where .= ' AND r.block_id = ?'; $params[] = $filterBlock; }
if ($filterPlaced === '1')  $where .= " AND $placedExpr";
if ($filterPlaced === '0')  $where .= " AND NOT ($placedExpr)";
if ($search !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ? OR s.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

// ---------- PAGINATION ----------
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM students s
    LEFT JOIN rooms r ON r.id = s.current_room_id
    LEFT JOIN blocks b ON b.id = r.block_id
    $where
");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ---------- LIST ----------
$stmt = $pdo->prepare("
    SELECT s.*, r.room_number, b.name AS block_name,
        $placedExpr AS placed_now
    FROM students s
    LEFT JOIN rooms r ON r.id = s.current_room_id
    LEFT JOIN blocks b ON b.id = r.block_id
    $where
    ORDER BY s.is_active DESC, s.gender, s.full_name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Blocks for filter
$bWhere = ' WHERE is_active = 1'; $bP = [];
if ($gender !== null) { $bWhere .= ' AND gender = ?'; $bP[] = $gender; }
$stmt = $pdo->prepare("SELECT id, name, gender FROM blocks $bWhere ORDER BY gender, name");
$stmt->execute($bP);
$blocks = $stmt->fetchAll();

// ---------- STATS ----------
$statsWhere = ' WHERE 1=1'; $statsP = [];
if ($gender !== null) { $statsWhere .= ' AND s.gender = ?'; $statsP[] = $gender; }

$stmt = $pdo->prepare("
    SELECT
        SUM(CASE WHEN s.is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN s.is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
        SUM(CASE WHEN s.is_active = 1 AND $placedExpr THEN 1 ELSE 0 END) AS placed_count,
        SUM(CASE WHEN s.is_active = 1 AND NOT ($placedExpr) THEN 1 ELSE 0 END) AS unplaced_count
    FROM students s
    $statsWhere
");
$stmt->execute($statsP);
$stats = $stmt->fetch() ?: [];

// URL helper that keeps existing filters but replaces page
function students_url(array $overrides = []): string {
    $q = array_merge([
        'q'        => $_GET['q']        ?? '',
        'gender'   => $_GET['gender']   ?? '',
        'block_id' => $_GET['block_id'] ?? '',
        'placed'   => $_GET['placed']   ?? '',
        'status'   => $_GET['status']   ?? 'active',
        'page'     => $_GET['page']     ?? 1,
    ], $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'students.php' . ($q ? '?' . http_build_query($q) : '');
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">

            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-user-graduate text-primary me-2"></i>Students</h4>
                    <p class="text-muted small mb-0">
                        <?= number_format($totalRows) ?> result<?= $totalRows === 1 ? '' : 's' ?>
                        <?= $gender ? ' · '.h($gender).' wing' : ' · All wings' ?>
                        <?php if ($filterStatus === 'inactive'): ?>
                            · <span class="badge bg-warning text-dark">Inactive</span>
                        <?php elseif ($filterStatus === 'all'): ?>
                            · <span class="badge bg-info text-dark">All statuses</span>
                        <?php endif; ?>
                        <?php if ($activeSid): ?>
                            · <strong><?= h(active_semester()['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($isAdmin): ?>
                <div class="d-flex gap-2">
                    <a href="student_import.php" class="btn btn-outline-primary btn-sm">
                        <i class="fas fa-file-csv me-1"></i>Import CSV
                    </a>
                    <a href="student_form.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-user-plus me-1"></i>Add Student
                    </a>
                </div>
                <?php endif; ?>
            </div>

            <!-- Flash message as auto-dismissing toast -->
            <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show d-flex align-items-center auto-dismiss">
                <i class="fas fa-<?= $flash['type']==='success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <div class="flex-grow-1"><?= h($flash['msg']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-primary"><?= number_format((int)($stats['active_count'] ?? 0)) ?></div>
                        <div class="small text-muted text-uppercase">Active</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-warning"><?= number_format((int)($stats['inactive_count'] ?? 0)) ?></div>
                        <div class="small text-muted text-uppercase">Inactive</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-success"><?= number_format((int)($stats['placed_count'] ?? 0)) ?></div>
                        <div class="small text-muted text-uppercase">Placed (this sem)</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-danger"><?= number_format((int)($stats['unplaced_count'] ?? 0)) ?></div>
                        <div class="small text-muted text-uppercase">Unplaced</div>
                    </div></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end" id="filterForm">
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Search</label>
                        <div class="position-relative">
                            <input type="text" name="q" id="searchInput"
                                   class="form-control form-control-sm"
                                   value="<?= h($search) ?>" placeholder="Name, reg #, email"
                                   autocomplete="off">
                            <span id="searchSpinner"
                                  class="position-absolute top-50 end-0 translate-middle-y me-2 d-none">
                                <span class="spinner-border spinner-border-sm text-primary"></span>
                            </span>
                        </div>
                    </div>

                    <?php if ($gender === null): ?>
                    <div class="col-md-2">
                        <label class="form-label small mb-1">Gender</label>
                        <select name="gender" class="form-select form-select-sm auto-submit">
                            <option value="">All</option>
                            <option value="Male" <?= $filterGender==='Male'?'selected':'' ?>>Male</option>
                            <option value="Female" <?= $filterGender==='Female'?'selected':'' ?>>Female</option>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label class="form-label small mb-1">Building</label>
                        <select name="block_id" class="form-select form-select-sm auto-submit">
                            <option value="0">All</option>
                            <?php foreach ($blocks as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $filterBlock===(int)$b['id']?'selected':'' ?>>
                                    <?= h($b['name']) ?> (<?= h($b['gender']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm auto-submit">
                            <option value="active"   <?= $filterStatus==='active'?'selected':'' ?>>Active only</option>
                            <?php if ($isAdmin): ?>
                                <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive only</option>
                                <option value="all"      <?= $filterStatus==='all'?'selected':'' ?>>All</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label small mb-1">Placed</label>
                        <select name="placed" class="form-select form-select-sm auto-submit">
                            <option value="">All</option>
                            <option value="1" <?= $filterPlaced==='1'?'selected':'' ?>>Yes</option>
                            <option value="0" <?= $filterPlaced==='0'?'selected':'' ?>>No</option>
                        </select>
                    </div>

                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill" id="filterBtn">
                            <span class="btn-label"><i class="fas fa-filter me-1"></i>Filter</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                        </button>
                        <a href="students.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <!-- Table -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($students)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-user-graduate fa-3x mb-3 opacity-25"></i>
                        <h6>No students found</h6>
                        <p class="small mb-0">Adjust filters or add a student.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Gender</th>
                                    <th>Current Room</th>
                                    <th class="text-center">Year</th>
                                    <th>Phase 1</th>
                                    <th>Placed</th>
                                    <?php if ($isAdmin): ?>
                                        <th>Status</th>
                                        <th class="text-end">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $s):
                                $inactive = (int)$s['is_active'] === 0;
                            ?>
                                <tr class="<?= $inactive ? 'table-warning' : '' ?>">
                                    <td>
                                        <div class="fw-semibold"><?= h($s['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($s['reg_number']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($s['gender'] === 'Male'): ?>
                                            <span class="badge bg-primary"><i class="fas fa-mars"></i></span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fas fa-venus"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small">
                                        <?= $s['room_number'] ? h($s['room_number']).' · '.h($s['block_name']) : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="text-center small"><?= (int)$s['year_of_study'] ?></td>
                                    <td>
                                        <?= match($s['phase1_action']) {
                                            'keep'    => '<span class="badge bg-success">Kept</span>',
                                            'release' => '<span class="badge bg-info text-dark">Released</span>',
                                            default   => '<span class="badge bg-light text-dark">—</span>',
                                        } ?>
                                    </td>
                                    <td>
                                        <?= $s['placed_now']
                                            ? '<span class="badge bg-success">Yes</span>'
                                            : '<span class="badge bg-warning text-dark">No</span>' ?>
                                    </td>
                                    <?php if ($isAdmin): ?>
                                    <td>
                                        <?php if ($inactive): ?>
                                            <span class="badge bg-secondary" title="<?= h($s['inactive_note'] ?? '') ?>">
                                                <i class="fas fa-ban me-1"></i>
                                                <?= h(ucfirst($s['inactive_reason'] ?? 'inactive')) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($inactive): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-success reactivate-btn"
                                                    data-id="<?= (int)$s['id'] ?>"
                                                    data-name="<?= h($s['full_name']) ?>">
                                                <span class="btn-label"><i class="fas fa-rotate-right me-1"></i>Reactivate</span>
                                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                            </button>
                                        <?php else: ?>
                                            <a href="student_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-warning deactivate-btn"
                                                    data-id="<?= (int)$s['id'] ?>"
                                                    data-name="<?= h($s['full_name']) ?>">
                                                <i class="fas fa-user-slash"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
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
                            of <strong><?= number_format($totalRows) ?></strong> student<?= $totalRows === 1 ? '' : 's' ?>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <!-- Prev -->
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page <= 1 ? '#' : h(students_url(['page' => $page - 1])) ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>

                                <!-- Page numbers -->
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                if ($start > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(students_url(['page' => 1])) ?>">1</a></li>
                                    <?php if ($start > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>

                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h(students_url(['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(students_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>

                                <!-- Next -->
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(students_url(['page' => $page + 1])) ?>">
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

<!-- Deactivate modal -->
<div class="modal fade" id="deactivateModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="deactivateForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-user-slash text-warning me-2"></i>Deactivate Student
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Deactivate <strong id="deactivateName"></strong>?
                    Their room will be freed and they'll be excluded from booking.
                </p>

                <label class="form-label fw-bold small">Reason <span class="text-danger">*</span></label>
                <select name="reason" class="form-select mb-3" required>
                    <option value="deferred">Deferred</option>
                    <option value="suspended">Suspended</option>
                    <option value="withdrawn">Withdrawn</option>
                    <option value="other">Other</option>
                </select>

                <label class="form-label fw-bold small">Note (optional)</label>
                <textarea name="note" class="form-control" rows="2" placeholder="e.g. Deferred to 2027/2028 · Sem 1"></textarea>

                <input type="hidden" name="action" value="deactivate">
                <input type="hidden" name="id" id="deactivateId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-warning" id="deactivateSubmit">
                    <span class="btn-label"><i class="fas fa-user-slash me-1"></i>Deactivate</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Deactivating...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// ---------- Auto-dismiss flash messages ----------
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => {
        const alert = bootstrap.Alert.getOrCreateInstance(el);
        alert.close();
    }, 4000);
});

// ---------- Auto-submit on select change (filters) ----------
document.querySelectorAll('.auto-submit').forEach(sel => {
    sel.addEventListener('change', () => {
        document.getElementById('filterForm').submit();
    });
});

// ---------- Debounced search ----------
let searchTimer = null;
const searchInput   = document.getElementById('searchInput');
const searchSpinner = document.getElementById('searchSpinner');

searchInput?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchSpinner.classList.remove('d-none');
    searchTimer = setTimeout(() => {
        document.getElementById('filterForm').submit();
    }, 500);
});

// ---------- Filter button spinner ----------
document.getElementById('filterForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('filterBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Deactivate ----------
document.querySelectorAll('.deactivate-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('deactivateId').value   = btn.dataset.id;
        document.getElementById('deactivateName').textContent = btn.dataset.name;
        new bootstrap.Modal(document.getElementById('deactivateModal')).show();
    });
});

document.getElementById('deactivateForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('deactivateSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Reactivate ----------
document.querySelectorAll('.reactivate-btn').forEach(btn => {
    btn.addEventListener('click', function () {
        // Simple confirm — user-friendly, not window.confirm
        if (!confirmReactivate(btn.dataset.name)) return;

        const label = this.querySelector('.btn-label');
        const spin  = this.querySelector('.btn-spin');
        label.classList.add('d-none');
        spin.classList.remove('d-none');
        this.disabled = true;

        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="id" value="${btn.dataset.id}">
        `;
        document.body.appendChild(form);
        form.submit();
    });
});

// Minimal visual confirm using Bootstrap modal-like flow without extra HTML
function confirmReactivate(name) {
    return confirm(`Reactivate ${name}? They'll be able to book a room again.`);
}
</script>