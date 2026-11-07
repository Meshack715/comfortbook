<?php
// user/rooms.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$isAdmin   = is_admin();
$gender    = gender_filter();
$flash     = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$activeSem = active_semester();

// ---------- DELETE (admin only) — blocked if occupied ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        // Blocked if any active booking OR any physically placed student
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings
            WHERE room_id = ?
              AND status IN ('pending_otp','pending_payment','pending_approval','confirmed')
        ");
        $stmt->execute([$id]);
        $hasBookings = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM students
            WHERE current_room_id = ? AND placed = 1 AND is_active = 1
        ");
        $stmt->execute([$id]);
        $hasOccupants = (int)$stmt->fetchColumn();

        if ($hasBookings > 0 || $hasOccupants > 0) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot delete — room is currently occupied or has active bookings.'];
        } else {
            $pdo->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            audit_log($_SESSION['user_id'], null, 'room_delete', "Deleted room #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Room deleted.'];
        }
    }
    redirect(APP_BASE . '/user/rooms.php');
}

// ---------- FILTERS ----------
$filterBlock  = (int)($_GET['block_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where = ' WHERE 1=1'; $params = [];
if ($gender !== null) { $where .= ' AND r.gender = ?'; $params[] = $gender; }
if ($filterBlock > 0) { $where .= ' AND r.block_id = ?'; $params[] = $filterBlock; }
if ($search !== '') {
    $where .= ' AND (r.room_number LIKE ? OR b.name LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%";
}

// Occupancy — physical occupants per room
$occSub = "(SELECT COUNT(DISTINCT s2.id) FROM students s2
            WHERE s2.current_room_id = r.id
              AND s2.placed = 1
              AND s2.is_active = 1)";

// Pending booking count — for "can delete" check in the UI
$pendingSub = "(SELECT COUNT(*) FROM bookings b2
                WHERE b2.room_id = r.id
                  AND b2.status IN ('pending_otp','pending_payment','pending_approval','confirmed'))";

// Count total rows before pagination
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM rooms r JOIN blocks b ON b.id = r.block_id
    $where
");
$stmt->execute($params);
$totalRooms = (int)$stmt->fetchColumn();

// Pagination
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("
    SELECT r.*, b.name block_name, b.gender block_gender,
           $occSub AS occupants,
           $pendingSub AS pending_bookings
    FROM rooms r
    JOIN blocks b ON b.id = r.block_id
    $where
    ORDER BY b.gender, b.name, r.room_number
");
$stmt->execute($params);
$allRooms = $stmt->fetchAll();

// Status filter (before pagination — so counts stay accurate)
if (in_array($filterStatus, ['vacant','occupied','full','partial'], true)) {
    $allRooms = array_filter($allRooms, function ($r) use ($filterStatus) {
        $occ = (int)$r['occupants'];
        $cap = (int)$r['capacity'];
        return match($filterStatus) {
            'vacant'   => $occ === 0,
            'partial'  => $occ > 0 && $occ < $cap,
            'full'     => $occ >= $cap,
            'occupied' => $occ > 0,
            default    => true,
        };
    });
    $allRooms = array_values($allRooms);
}

$totalFiltered = count($allRooms);
$totalPages    = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;
$rooms  = array_slice($allRooms, $offset, $perPage);

// Blocks for filter dropdown
$bWhere = ' WHERE is_active = 1'; $bParams = [];
if ($gender !== null) { $bWhere .= ' AND gender = ?'; $bParams[] = $gender; }
$stmt = $pdo->prepare("SELECT id, name, gender FROM blocks $bWhere ORDER BY gender, name");
$stmt->execute($bParams);
$blocks = $stmt->fetchAll();

// Summary counts (across ALL rooms in current wing, ignoring status filter)
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN ($occSub) = 0 THEN 1 ELSE 0 END) AS vacant,
        SUM(CASE WHEN ($occSub) > 0 AND ($occSub) < r.capacity THEN 1 ELSE 0 END) AS partial,
        SUM(CASE WHEN ($occSub) >= r.capacity THEN 1 ELSE 0 END) AS full,
        SUM(CASE WHEN ($occSub) > 0 THEN 1 ELSE 0 END) AS occupied
    FROM rooms r
    JOIN blocks b ON b.id = r.block_id
    " . ($gender ? " WHERE r.gender = ?" : "") . "
");
$stmt->execute($gender ? [$gender] : []);
$counts = $stmt->fetch() ?: ['total'=>0,'vacant'=>0,'partial'=>0,'full'=>0,'occupied'=>0];

// Helpers
function semester_status_badge(int $occ, int $cap): string {
    if ($occ === 0)   return '<span class="badge bg-success">Vacant</span>';
    if ($occ >= $cap) return '<span class="badge bg-danger">Full</span>';
    return '<span class="badge bg-warning text-dark">' . $occ . '/' . $cap . '</span>';
}

function rooms_url(array $overrides = []): string {
    $q = array_merge([
        'q'        => $_GET['q']        ?? '',
        'block_id' => $_GET['block_id'] ?? '',
        'status'   => $_GET['status']   ?? '',
        'page'     => $_GET['page']     ?? 1,
    ], $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'rooms.php' . ($q ? '?' . http_build_query($q) : '');
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-bed text-primary me-2"></i>Rooms</h4>
                    <p class="text-muted small mb-0">
                        <?= number_format($totalFiltered) ?> result<?= $totalFiltered===1?'':'s' ?>
                        <?= $gender ? ' · '.h($gender).' wing' : ' · All wings' ?>
                        <?php if ($activeSem): ?>
                            · occupancy as of <strong><?= h($activeSem['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($isAdmin): ?>
                <a href="room_form.php" class="btn btn-primary btn-sm add-btn">
                    <span class="btn-label"><i class="fas fa-plus-circle me-1"></i>Add Room</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Opening...</span>
                </a>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show d-flex align-items-center auto-dismiss">
                <i class="fas fa-<?= $flash['type']==='success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <div class="flex-grow-1"><?= h($flash['msg']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Stats strip -->
            <div class="row g-2 mb-3">
                <div class="col-6 col-md-2-4" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-primary"><?= number_format((int)$counts['total']) ?></div>
                        <div class="small text-muted text-uppercase">Total</div>
                    </div></div>
                </div>
                <div class="col" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-success"><?= number_format((int)$counts['vacant']) ?></div>
                        <div class="small text-muted text-uppercase">Vacant</div>
                    </div></div>
                </div>
                <div class="col" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-warning"><?= number_format((int)$counts['partial']) ?></div>
                        <div class="small text-muted text-uppercase">Partial</div>
                    </div></div>
                </div>
                <div class="col" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-danger"><?= number_format((int)$counts['full']) ?></div>
                        <div class="small text-muted text-uppercase">Full</div>
                    </div></div>
                </div>
                <div class="col" style="flex:0 0 20%;max-width:20%;">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-2">
                        <div class="fs-4 fw-bold text-info"><?= number_format((int)$counts['occupied']) ?></div>
                        <div class="small text-muted text-uppercase">Occupied</div>
                    </div></div>
                </div>
            </div>

            <!-- Status chips -->
            <div class="d-flex flex-wrap gap-2 mb-3">
                <a href="<?= h(rooms_url(['status'=>'', 'page'=>1])) ?>"
                   class="btn btn-sm <?= $filterStatus===''?'btn-primary':'btn-outline-primary' ?>">
                    All <span class="badge bg-light text-dark ms-1"><?= number_format((int)$counts['total']) ?></span>
                </a>
                <a href="<?= h(rooms_url(['status'=>'vacant', 'page'=>1])) ?>"
                   class="btn btn-sm <?= $filterStatus==='vacant'?'btn-success':'btn-outline-success' ?>">
                    Vacant <span class="badge bg-light text-dark ms-1"><?= number_format((int)$counts['vacant']) ?></span>
                </a>
                <a href="<?= h(rooms_url(['status'=>'partial', 'page'=>1])) ?>"
                   class="btn btn-sm <?= $filterStatus==='partial'?'btn-warning':'btn-outline-warning' ?>">
                    Partial <span class="badge bg-light text-dark ms-1"><?= number_format((int)$counts['partial']) ?></span>
                </a>
                <a href="<?= h(rooms_url(['status'=>'full', 'page'=>1])) ?>"
                   class="btn btn-sm <?= $filterStatus==='full'?'btn-danger':'btn-outline-danger' ?>">
                    Full <span class="badge bg-light text-dark ms-1"><?= number_format((int)$counts['full']) ?></span>
                </a>
                <a href="<?= h(rooms_url(['status'=>'occupied', 'page'=>1])) ?>"
                   class="btn btn-sm <?= $filterStatus==='occupied'?'btn-info':'btn-outline-info' ?>">
                    Occupied <span class="badge bg-light text-dark ms-1"><?= number_format((int)$counts['occupied']) ?></span>
                </a>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end" id="filterForm">
                        <div class="col-md-5">
                            <label class="form-label small mb-1">Search</label>
                            <div class="position-relative">
                                <input type="text" name="q" id="searchInput"
                                       class="form-control form-control-sm"
                                       value="<?= h($search) ?>" placeholder="Room or building"
                                       autocomplete="off">
                                <span id="searchSpinner" class="position-absolute top-50 end-0 translate-middle-y me-2 d-none">
                                    <span class="spinner-border spinner-border-sm text-primary"></span>
                                </span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1">Building</label>
                            <select name="block_id" class="form-select form-select-sm auto-submit">
                                <option value="0">All</option>
                                <?php foreach ($blocks as $b): ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= $filterBlock===(int)$b['id']?'selected':'' ?>>
                                        <?= h($b['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 d-flex gap-2">
                            <button class="btn btn-primary btn-sm flex-fill" id="filterBtn">
                                <span class="btn-label"><i class="fas fa-filter me-1"></i>Filter</span>
                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                            </button>
                            <a href="rooms.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-times"></i>
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="card border-0 shadow-sm">
                <div class="card-body p-0">
                    <?php if (empty($rooms)): ?>
                        <div class="text-center py-5 text-muted">
                            <i class="fas fa-bed fa-3x mb-3 opacity-25"></i>
                            <h6>No rooms found</h6>
                            <p class="small mb-0">Adjust filters or add a room.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Room</th>
                                        <th>Building</th>
                                        <th>Wing</th>
                                        <th class="text-center">Capacity</th>
                                        <th style="width:28%;">Occupancy</th>
                                        <th>Status</th>
                                        <?php if ($isAdmin): ?><th class="text-end">Actions</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rooms as $r):
                                    $occ = (int)$r['occupants'];
                                    $cap = (int)$r['capacity'];
                                    $pct = $cap > 0 ? round(($occ / $cap) * 100) : 0;
                                    $barColor = $pct >= 100 ? 'danger' : ($pct > 0 ? 'warning' : 'success');
                                    $hasBookings = (int)$r['pending_bookings'] > 0;
                                    $canDelete   = ($occ === 0 && !$hasBookings);
                                ?>
                                    <tr>
                                        <td class="fw-semibold"><?= h($r['room_number']) ?></td>
                                        <td class="small"><?= h($r['block_name']) ?></td>
                                        <td>
                                            <?= $r['block_gender']==='Male'
                                                ? '<span class="badge bg-primary"><i class="fas fa-mars"></i></span>'
                                                : '<span class="badge bg-danger"><i class="fas fa-venus"></i></span>' ?>
                                        </td>
                                        <td class="text-center"><?= $cap ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-fill" style="height:6px;min-width:80px;">
                                                    <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                                                </div>
                                                <span class="small text-nowrap"><?= $occ ?> / <?= $cap ?></span>
                                            </div>
                                        </td>
                                        <td><?= semester_status_badge($occ, $cap) ?></td>
                                        <?php if ($isAdmin): ?>
                                        <td class="text-end">
                                            <a href="room_form.php?id=<?= (int)$r['id'] ?>"
                                               class="btn btn-sm btn-outline-primary edit-btn">
                                                <span class="btn-label"><i class="fas fa-edit"></i></span>
                                                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                            </a>
                                            <?php if ($canDelete): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-danger delete-btn"
                                                        data-id="<?= (int)$r['id'] ?>"
                                                        data-room="<?= h($r['room_number']) ?>"
                                                        data-building="<?= h($r['block_name']) ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-secondary"
                                                        data-bs-toggle="tooltip"
                                                        title="<?= $hasBookings ? 'Has active bookings' : 'Room is occupied' ?>">
                                                    <i class="fas fa-lock"></i>
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
                                Showing <strong><?= number_format($offset + 1) ?></strong>–<strong><?= number_format(min($offset + $perPage, $totalFiltered)) ?></strong>
                                of <strong><?= number_format($totalFiltered) ?></strong> room<?= $totalFiltered === 1 ? '' : 's' ?>
                            </div>
                            <nav>
                                <ul class="pagination pagination-sm mb-0">
                                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page <= 1 ? '#' : h(rooms_url(['page' => $page - 1])) ?>">
                                            <i class="fas fa-angle-left"></i>
                                        </a>
                                    </li>
                                    <?php
                                    $start = max(1, $page - 2);
                                    $end   = min($totalPages, $page + 2);
                                    if ($start > 1): ?>
                                        <li class="page-item"><a class="page-link" href="<?= h(rooms_url(['page' => 1])) ?>">1</a></li>
                                        <?php if ($start > 2): ?>
                                            <li class="page-item disabled"><span class="page-link">…</span></li>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php for ($i = $start; $i <= $end; $i++): ?>
                                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                            <a class="page-link" href="<?= h(rooms_url(['page' => $i])) ?>"><?= $i ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <?php if ($end < $totalPages): ?>
                                        <?php if ($end < $totalPages - 1): ?>
                                            <li class="page-item disabled"><span class="page-link">…</span></li>
                                        <?php endif; ?>
                                        <li class="page-item"><a class="page-link" href="<?= h(rooms_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                                    <?php endif; ?>
                                    <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                        <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(rooms_url(['page' => $page + 1])) ?>">
                                            <i class="fas fa-angle-right"></i>
                                        </a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>

<!-- Delete confirm modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post" id="deleteForm">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-trash text-danger me-2"></i>Delete Room
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">
                    Delete <strong id="deleteRoom"></strong>
                    from <strong id="deleteBuilding"></strong>?
                </p>
                <div class="alert alert-warning small mb-0">
                    <i class="fas fa-exclamation-triangle me-1"></i>
                    This action cannot be undone. If the room has bookings or occupants, the system will refuse.
                </div>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-danger" id="deleteSubmit">
                    <span class="btn-label"><i class="fas fa-trash me-1"></i>Delete Room</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Deleting...</span>
                </button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// Init Bootstrap tooltips (for locked delete buttons)
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el);
});

// Auto-dismiss flash toasts
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 4000);
});

// Auto-submit select filters
document.querySelectorAll('.auto-submit').forEach(sel => {
    sel.addEventListener('change', () => document.getElementById('filterForm').submit());
});

// Debounced search
let searchTimer = null;
const searchInput   = document.getElementById('searchInput');
const searchSpinner = document.getElementById('searchSpinner');

searchInput?.addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchSpinner.classList.remove('d-none');
    searchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 500);
});

// Filter button spinner
document.getElementById('filterForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('filterBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// Add Room button spinner
document.querySelector('.add-btn')?.addEventListener('click', function () {
    this.querySelector('.btn-label').classList.add('d-none');
    this.querySelector('.btn-spin').classList.remove('d-none');
});

// Edit button spinner + soft row fade
document.querySelectorAll('.edit-btn').forEach(btn => {
    btn.addEventListener('click', function (e) {
        e.preventDefault();
        const href = this.getAttribute('href');
        const row  = this.closest('tr');

        this.querySelector('.btn-label').classList.add('d-none');
        this.querySelector('.btn-spin').classList.remove('d-none');
        row.style.transition = 'opacity .15s';
        row.style.opacity = '0.5';

        setTimeout(() => { window.location = href; }, 120);
    });
});

// Delete modal open
document.querySelectorAll('.delete-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('deleteId').value = btn.dataset.id;
        document.getElementById('deleteRoom').textContent = btn.dataset.room;
        document.getElementById('deleteBuilding').textContent = btn.dataset.building;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    });
});

// Delete modal submit spinner
document.getElementById('deleteForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('deleteSubmit');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>