<?php
// user/staff.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- DEACTIVATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deactivate') {
    $id     = (int)($_POST['id'] ?? 0);
    $reason = $_POST['reason'] ?? 'other';
    $note   = trim($_POST['note'] ?? '');

    if ($id > 0 && in_array($reason, ['resigned','suspended','retired','other'], true)) {
        $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $target = $stmt->fetch();

        if (!$target) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Staff not found.'];
        } elseif ($target['role'] === 'admin') {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Admin accounts cannot be deactivated.'];
        } elseif ($id === (int)$_SESSION['user_id']) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'You cannot deactivate your own account.'];
        } else {
            $pdo->prepare("
                UPDATE users SET status='inactive', is_active=0,
                    inactive_reason=?, inactive_note=?, inactive_at=NOW(), inactive_by=?
                WHERE id=?
            ")->execute([$reason, $note ?: null, $_SESSION['user_id'], $id]);
            audit_log($_SESSION['user_id'], null, 'staff_deactivate', "Deactivated staff #$id — $reason: $note");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Staff account deactivated.'];
        }
    }
    redirect(APP_BASE . '/user/staff.php');
}

// ---------- REACTIVATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reactivate') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("
            UPDATE users SET status='active', is_active=1,
                inactive_reason=NULL, inactive_note=NULL, inactive_at=NULL, inactive_by=NULL
            WHERE id=?
        ")->execute([$id]);
        audit_log($_SESSION['user_id'], null, 'staff_reactivate', "Reactivated staff #$id");
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Staff account reactivated.'];
    }
    redirect(APP_BASE . '/user/staff.php');
}

// ---------- FILTERS ----------
$filterRole   = $_GET['role']   ?? '';
$filterStatus = $_GET['status'] ?? 'active';
$search       = trim($_GET['q'] ?? '');

if (!in_array($filterStatus, ['active','inactive','all'], true)) {
    $filterStatus = 'active';
}

$where  = " WHERE 1=1";
$params = [];

if ($filterStatus === 'active')        $where .= " AND is_active = 1";
elseif ($filterStatus === 'inactive')  $where .= " AND is_active = 0";

if (in_array($filterRole, ['admin','dean_men','dean_women','secretary_men','secretary_women'], true)) {
    $where .= ' AND role = ?'; $params[] = $filterRole;
}
if ($search !== '') {
    $where .= ' AND (username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

// ---------- PAGINATION ----------
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("SELECT COUNT(*) FROM users $where");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare("
    SELECT * FROM users $where
    ORDER BY is_active DESC, role, full_name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$staff = $stmt->fetchAll();

// ---------- STATS ----------
$stmt = $pdo->query("
    SELECT
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS inactive_count,
        SUM(CASE WHEN role LIKE 'dean%' AND is_active = 1 THEN 1 ELSE 0 END) AS deans,
        SUM(CASE WHEN role LIKE 'secretary%' AND is_active = 1 THEN 1 ELSE 0 END) AS secretaries
    FROM users
");
$stats = $stmt->fetch() ?: [];

// ---------- HELPERS ----------
function role_badge(string $r): string {
    return match($r) {
        'admin'           => '<span class="badge bg-dark">Admin</span>',
        'dean_men'        => '<span class="badge bg-primary">Dean · Men</span>',
        'dean_women'      => '<span class="badge bg-danger">Dean · Women</span>',
        'secretary_men'   => '<span class="badge bg-info text-dark">Sec · Men</span>',
        'secretary_women' => '<span class="badge bg-warning text-dark">Sec · Women</span>',
        default           => '<span class="badge bg-light text-dark">' . h($r) . '</span>',
    };
}

function staff_url(array $overrides = []): string {
    $q = array_merge([
        'q'      => $_GET['q']      ?? '',
        'role'   => $_GET['role']   ?? '',
        'status' => $_GET['status'] ?? 'active',
        'page'   => $_GET['page']   ?? 1,
    ], $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'staff.php' . ($q ? '?' . http_build_query($q) : '');
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <!-- Header -->
            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-users-cog text-primary me-2"></i>Staff Accounts</h4>
                    <p class="text-muted small mb-0">
                        <?= number_format($totalRows) ?> account<?= $totalRows === 1 ? '' : 's' ?>
                        <?php if ($filterStatus === 'inactive'): ?>
                            · <span class="badge bg-warning text-dark">Inactive</span>
                        <?php elseif ($filterStatus === 'all'): ?>
                            · <span class="badge bg-info text-dark">All statuses</span>
                        <?php endif; ?>
                    </p>
                </div>
                <a href="staff_form.php" class="btn btn-primary btn-sm">
                    <i class="fas fa-user-plus me-1"></i>Add Staff
                </a>
            </div>

            <!-- Flash as auto-dismiss toast -->
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
                        <div class="fs-3 fw-bold text-success"><?= number_format((int)($stats['deans'] ?? 0)) ?></div>
                        <div class="small text-muted text-uppercase">Deans</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-info"><?= number_format((int)($stats['secretaries'] ?? 0)) ?></div>
                        <div class="small text-muted text-uppercase">Secretaries</div>
                    </div></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end" id="filterForm">
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Search</label>
                        <div class="position-relative">
                            <input type="text" name="q" id="searchInput"
                                   class="form-control form-control-sm"
                                   value="<?= h($search) ?>" placeholder="Name, username, email"
                                   autocomplete="off">
                            <span id="searchSpinner" class="position-absolute top-50 end-0 translate-middle-y me-2 d-none">
                                <span class="spinner-border spinner-border-sm text-primary"></span>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Role</label>
                        <select name="role" class="form-select form-select-sm auto-submit">
                            <option value="">All roles</option>
                            <?php foreach (['admin'=>'Admin','dean_men'=>'Dean · Men','dean_women'=>'Dean · Women','secretary_men'=>'Sec · Men','secretary_women'=>'Sec · Women'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= $filterRole===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small mb-1">Status</label>
                        <select name="status" class="form-select form-select-sm auto-submit">
                            <option value="active"   <?= $filterStatus==='active'?'selected':'' ?>>Active only</option>
                            <option value="inactive" <?= $filterStatus==='inactive'?'selected':'' ?>>Inactive only</option>
                            <option value="all"      <?= $filterStatus==='all'?'selected':'' ?>>All</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill" id="filterBtn">
                            <span class="btn-label"><i class="fas fa-filter me-1"></i>Filter</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                        </button>
                        <a href="staff.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <!-- Table -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($staff)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-users-cog fa-3x mb-3 opacity-25"></i>
                        <h6>No staff found</h6>
                        <p class="small mb-0">Adjust filters or add a new staff account.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle" id="staffTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Username</th>
                                    <th>Email</th>
                                    <th>Role</th>
                                    <th>Status</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($staff as $u):
                                $inactive = (int)$u['is_active'] === 0;
                            ?>
                                <tr class="<?= $inactive ? 'table-warning' : '' ?>">
                                    <td class="fw-semibold"><?= h($u['full_name']) ?></td>
                                    <td class="small"><?= h($u['username']) ?></td>
                                    <td class="small text-muted"><?= h($u['email']) ?></td>
                                    <td><?= role_badge($u['role']) ?></td>
                                    <td>
                                        <?php if ($inactive): ?>
                                            <span class="badge bg-secondary" title="<?= h($u['inactive_note'] ?? '') ?>">
                                                <i class="fas fa-ban me-1"></i>
                                                <?= h(ucfirst($u['inactive_reason'] ?? 'inactive')) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <?php if ($inactive): ?>
                                            <button type="button"
                                                    class="btn btn-sm btn-outline-success reactivate-btn"
                                                    data-id="<?= (int)$u['id'] ?>"
                                                    data-name="<?= h($u['full_name']) ?>">
                                                <span class="btn-label"><i class="fas fa-rotate-right me-1"></i>Reactivate</span>
                                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                            </button>
                                        <?php else: ?>
                                            <a href="staff_form.php?id=<?= (int)$u['id'] ?>"
                                               class="btn btn-sm btn-outline-primary edit-btn"
                                               data-name="<?= h($u['full_name']) ?>">
                                                <span class="btn-label"><i class="fas fa-edit"></i></span>
                                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                            </a>
                                            <?php if ($u['role'] !== 'admin' && (int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-warning deactivate-btn"
                                                        data-id="<?= (int)$u['id'] ?>"
                                                        data-name="<?= h($u['full_name']) ?>">
                                                    <i class="fas fa-user-slash"></i>
                                                </button>
                                            <?php endif; ?>
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
                            of <strong><?= number_format($totalRows) ?></strong> account<?= $totalRows === 1 ? '' : 's' ?>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page <= 1 ? '#' : h(staff_url(['page' => $page - 1])) ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                if ($start > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(staff_url(['page' => 1])) ?>">1</a></li>
                                    <?php if ($start > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h(staff_url(['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(staff_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(staff_url(['page' => $page + 1])) ?>">
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
                    <i class="fas fa-user-slash text-warning me-2"></i>Deactivate Staff
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Deactivate <strong id="deactivateName"></strong>?
                    They will lose access immediately but their account stays for history.
                </p>

                <label class="form-label fw-bold small">Reason <span class="text-danger">*</span></label>
                <select name="reason" class="form-select mb-3" required>
                    <option value="resigned">Resigned</option>
                    <option value="suspended">Suspended</option>
                    <option value="retired">Retired</option>
                    <option value="other">Other</option>
                </select>

                <label class="form-label fw-bold small">Note (optional)</label>
                <textarea name="note" class="form-control" rows="2" placeholder="e.g. Left the university in Nov 2026"></textarea>

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

<!-- Reactivate modal -->
<div class="modal fade" id="reactivateModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="reactivateForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-rotate-right text-success me-2"></i>Reactivate Staff
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    Reactivate <strong id="reactivateName"></strong>?
                    They'll be able to log in again.
                </p>
                <input type="hidden" name="action" value="reactivate">
                <input type="hidden" name="id" id="reactivateId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success" id="reactivateSubmit">
                    <span class="btn-label"><i class="fas fa-check me-1"></i>Reactivate</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Reactivating...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// ---------- Auto-dismiss flash toasts ----------
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => {
        bootstrap.Alert.getOrCreateInstance(el).close();
    }, 4000);
});

// ---------- Auto-submit on select change ----------
document.querySelectorAll('.auto-submit').forEach(sel => {
    sel.addEventListener('change', () => document.getElementById('filterForm').submit());
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

// ---------- Filter button spinner ----------
document.getElementById('filterForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('filterBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Edit row — soft fade + spinner + navigate ----------
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        const row = this.closest('tr');
        const href = this.getAttribute('href');

        const label = this.querySelector('.btn-label');
        const spin  = this.querySelector('.btn-spin');
        label.classList.add('d-none');
        spin.classList.remove('d-none');

        row.style.transition = 'opacity .15s';
        row.style.opacity = '0.5';

        setTimeout(() => { window.location = href; }, 150);
    });
});

// ---------- Deactivate ----------
document.querySelectorAll('.deactivate-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('deactivateId').value = btn.dataset.id;
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
    btn.addEventListener('click', () => {
        document.getElementById('reactivateId').value = btn.dataset.id;
        document.getElementById('reactivateName').textContent = btn.dataset.name;
        new bootstrap.Modal(document.getElementById('reactivateModal')).show();
    });
});

document.getElementById('reactivateForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('reactivateSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>