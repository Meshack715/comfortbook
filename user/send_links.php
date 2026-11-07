<?php
// user/send_links.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men', 'dean_women']);

$gender = gender_filter();
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- Which semester do these links belong to? (next after active) ----
$activeSem = active_semester();
$bookingSem = null;
if ($activeSem) {
    $stmt = $pdo->prepare("
        SELECT * FROM semesters
        WHERE (academic_year > ? OR (academic_year = ? AND semester_no > ?))
        ORDER BY academic_year ASC, semester_no ASC
        LIMIT 1
    ");
    $stmt->execute([
        $activeSem['academic_year'],
        $activeSem['academic_year'],
        (int)$activeSem['semester_no'],
    ]);
    $bookingSem = $stmt->fetch() ?: $activeSem;
} else {
    $stmt = $pdo->query("SELECT * FROM semesters ORDER BY start_date ASC LIMIT 1");
    $bookingSem = $stmt->fetch() ?: null;
}

$myPhase = current_phase($gender);
$canSend = $myPhase !== null && $bookingSem !== null;

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$canSend) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot send links — your phase window is not active.'];
        redirect(APP_BASE.'/user/send_links.php');
    }

    // ---- START SEND-ALL (returns list) ----
    if ($action === 'send_all_start') {
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("
            SELECT id, full_name, email FROM students
            WHERE gender = ? AND is_active = 1 AND email IS NOT NULL AND email <> ''
            ORDER BY full_name
        ");
        $stmt->execute([$gender]);
        echo json_encode([
            'ok'       => true,
            'students' => $stmt->fetchAll(),
            'semester' => ['id'=>$bookingSem['id'], 'name'=>$bookingSem['name']],
        ]);
        exit;
    }

    // ---- SEND ONE (AJAX) ----
    if ($action === 'send_one_ajax') {
        header('Content-Type: application/json');
        $sid = (int)($_POST['student_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND gender = ?");
        $stmt->execute([$sid, $gender]);
        $s = $stmt->fetch();
        if (!$s) { echo json_encode(['ok'=>false, 'error'=>'Student not found']); exit; }

        $pdo->prepare("DELETE FROM magic_links WHERE student_id = ? AND used_at IS NULL")
            ->execute([$sid]);

        $raw  = bin2hex(random_bytes(32));
        $hash = hash('sha256', $raw);
        $pdo->prepare("
            INSERT INTO magic_links (student_id, semester_id, token_hash, expires_at, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            $sid, (int)$bookingSem['id'], $hash,
            date('Y-m-d H:i:s', strtotime('+7 days')),
            $_SESSION['user_id'],
        ]);

        $link = SITE_URL . '/student/book.php?token=' . $raw;
        $ok   = send_magic_link_email($s['email'], $s['full_name'], $link);

        if ($ok) {
            audit_log($_SESSION['user_id'], $sid, 'link_send_one', "Sent link to {$s['reg_number']} (sem {$bookingSem['name']})");
        }

        echo json_encode(['ok' => $ok, 'email' => $s['email'], 'name' => $s['full_name']]);
        exit;
    }
}

// ---------- FILTERS ----------
$search     = trim($_GET['q'] ?? '');
$filterLink = $_GET['link'] ?? '';

$where = " WHERE s.gender = ? AND s.is_active = 1"; $params = [$gender];
if ($search !== '') {
    $where .= ' AND (s.full_name LIKE ? OR s.reg_number LIKE ? OR s.email LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}
if ($filterLink === 'sent') {
    $where .= " AND EXISTS (SELECT 1 FROM magic_links ml WHERE ml.student_id = s.id AND ml.used_at IS NULL AND ml.expires_at > NOW())";
} elseif ($filterLink === 'used') {
    $where .= " AND EXISTS (SELECT 1 FROM magic_links ml WHERE ml.student_id = s.id AND ml.used_at IS NOT NULL)";
} elseif ($filterLink === 'none') {
    $where .= " AND NOT EXISTS (SELECT 1 FROM magic_links ml WHERE ml.student_id = s.id)";
}

// ---------- PAGINATION ----------
$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students s $where");
$stmt->execute($params);
$totalRows = (int)$stmt->fetchColumn();

$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

// ---------- ROWS ----------
$stmt = $pdo->prepare("
    SELECT s.id, s.reg_number, s.full_name, s.email, s.phase1_action, s.placed,
        (SELECT COUNT(*) FROM magic_links ml WHERE ml.student_id = s.id) link_count,
        (SELECT used_at FROM magic_links ml WHERE ml.student_id = s.id AND ml.used_at IS NOT NULL ORDER BY used_at DESC LIMIT 1) last_used,
        (SELECT expires_at FROM magic_links ml WHERE ml.student_id = s.id AND ml.used_at IS NULL ORDER BY created_at DESC LIMIT 1) pending_expires
    FROM students s
    $where
    ORDER BY s.full_name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// ---------- STATS ----------
$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE gender = ? AND is_active = 1");
$stmt->execute([$gender]);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id) FROM students s
    JOIN magic_links ml ON ml.student_id = s.id
    WHERE s.gender = ? AND s.is_active = 1 AND ml.used_at IS NULL AND ml.expires_at > NOW()
");
$stmt->execute([$gender]);
$withLink = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id) FROM students s
    JOIN magic_links ml ON ml.student_id = s.id
    WHERE s.gender = ? AND s.is_active = 1 AND ml.used_at IS NOT NULL
");
$stmt->execute([$gender]);
$used = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM students s
    WHERE s.gender = ? AND s.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM magic_links ml WHERE ml.student_id = s.id)
");
$stmt->execute([$gender]);
$noLink = (int)$stmt->fetchColumn();

// ---------- URL helper ----------
function sl_url(array $overrides = []): string {
    $q = array_merge([
        'q'    => $_GET['q']    ?? '',
        'link' => $_GET['link'] ?? '',
        'page' => $_GET['page'] ?? 1,
    ], $overrides);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null);
    return 'send_links.php' . ($q ? '?' . http_build_query($q) : '');
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
                    <h4 class="mb-1"><i class="fas fa-paper-plane text-primary me-2"></i>Send Magic Links</h4>
                    <p class="text-muted small mb-0">
                        <?= h($gender) ?> wing · <?= number_format($totalRows) ?> result<?= $totalRows===1?'':'s' ?>
                        <?php if ($bookingSem): ?>
                            · booking for <strong><?= h($bookingSem['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <button class="btn btn-primary" id="sendAllBtn"
                        data-count="<?= $total ?>"
                        <?= $canSend ? '' : 'disabled' ?>>
                    <span class="btn-label"><i class="fas fa-paper-plane me-1"></i>Send to All (<?= $total ?>)</span>
                    <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Starting...</span>
                </button>
            </div>

            <!-- Flash toast -->
            <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show d-flex align-items-center auto-dismiss">
                <i class="fas fa-<?= $flash['type']==='success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <div class="flex-grow-1"><?= h($flash['msg']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <?php if (!$canSend): ?>
            <div class="alert alert-warning d-flex align-items-start">
                <i class="fas fa-lock me-2 mt-1"></i>
                <div class="small">
                    <strong>Sending disabled.</strong>
                    Your wing's phase window is not active.
                    Set dates in <a href="<?= APP_BASE ?>/user/phase_windows.php">Phase Windows</a> to enable.
                </div>
            </div>
            <?php endif; ?>

            <!-- Progress panel -->
            <div id="progressPanel" class="card border-0 shadow-sm mb-3 d-none">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div>
                            <strong id="progressTitle">Sending...</strong>
                            <div class="small text-muted" id="progressDetail">Starting...</div>
                        </div>
                        <div class="fs-4 fw-bold text-primary" id="progressCount">0/0</div>
                    </div>
                    <div class="progress mb-2" style="height:8px;">
                        <div class="progress-bar" id="progressBar" style="width:0%"></div>
                    </div>
                    <div class="small text-muted" id="progressLog"></div>
                </div>
            </div>

            <!-- Stats -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-primary"><?= number_format($total) ?></div>
                        <div class="small text-muted text-uppercase">Students</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-info"><?= number_format($withLink) ?></div>
                        <div class="small text-muted text-uppercase">Pending Links</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-success"><?= number_format($used) ?></div>
                        <div class="small text-muted text-uppercase">Used</div>
                    </div></div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm h-100"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-danger"><?= number_format($noLink) ?></div>
                        <div class="small text-muted text-uppercase">No Link</div>
                    </div></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end" id="filterForm">
                    <div class="col-md-6">
                        <label class="form-label small mb-1">Search</label>
                        <div class="position-relative">
                            <input type="text" name="q" id="searchInput"
                                   class="form-control form-control-sm"
                                   value="<?= h($search) ?>" placeholder="Name, reg #, email"
                                   autocomplete="off">
                            <span id="searchSpinner" class="position-absolute top-50 end-0 translate-middle-y me-2 d-none">
                                <span class="spinner-border spinner-border-sm text-primary"></span>
                            </span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small mb-1">Link Status</label>
                        <select name="link" class="form-select form-select-sm auto-submit">
                            <option value="">All</option>
                            <option value="sent" <?= $filterLink==='sent'?'selected':'' ?>>Link Sent (pending)</option>
                            <option value="used" <?= $filterLink==='used'?'selected':'' ?>>Link Used</option>
                            <option value="none" <?= $filterLink==='none'?'selected':'' ?>>No Link</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill" id="filterBtn">
                            <span class="btn-label"><i class="fas fa-filter me-1"></i>Filter</span>
                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                        </button>
                        <a href="send_links.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <!-- Table -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($students)): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-user-graduate fa-3x mb-3 opacity-25"></i>
                        <h6>No students found</h6>
                        <p class="small mb-0">Adjust filters or try a different search.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Email</th>
                                    <th class="text-center">Links</th>
                                    <th>Status</th>
                                    <th>Sent / Used</th>
                                    <th class="text-end">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $s): ?>
                                <tr data-sid="<?= (int)$s['id'] ?>" data-name="<?= h($s['full_name']) ?>">
                                    <td>
                                        <div class="fw-semibold"><?= h($s['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($s['reg_number']) ?></div>
                                    </td>
                                    <td class="small text-muted"><?= h($s['email']) ?></td>
                                    <td class="text-center">
                                        <span class="badge bg-light text-dark link-count"><?= (int)$s['link_count'] ?></span>
                                    </td>
                                    <td class="status-cell">
                                        <?php if ($s['last_used']): ?>
                                            <span class="badge bg-success">Used</span>
                                        <?php elseif ($s['pending_expires']): ?>
                                            <span class="badge bg-info text-dark">Sent</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?php if ($s['last_used']): ?>
                                            <i class="fas fa-check text-success me-1"></i>
                                            <?= date('M j, H:i', strtotime($s['last_used'])) ?>
                                        <?php elseif ($s['pending_expires']): ?>
                                            <i class="far fa-clock me-1"></i>
                                            exp <?= date('M j', strtotime($s['pending_expires'])) ?>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary send-one-btn"
                                                data-sid="<?= (int)$s['id'] ?>"
                                                data-name="<?= h($s['full_name']) ?>"
                                                data-email="<?= h($s['email']) ?>"
                                                <?= $canSend ? '' : 'disabled' ?>>
                                            <span class="btn-label"><i class="fas fa-paper-plane"></i></span>
                                            <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                        </button>
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
                            of <strong><?= number_format($totalRows) ?></strong> student<?= $totalRows === 1 ? '' : 's' ?>
                        </div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page <= 1 ? '#' : h(sl_url(['page' => $page - 1])) ?>">
                                        <i class="fas fa-angle-left"></i>
                                    </a>
                                </li>
                                <?php
                                $start = max(1, $page - 2);
                                $end   = min($totalPages, $page + 2);
                                if ($start > 1): ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(sl_url(['page' => 1])) ?>">1</a></li>
                                    <?php if ($start > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <?php for ($i = $start; $i <= $end; $i++): ?>
                                    <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= h(sl_url(['page' => $i])) ?>"><?= $i ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php if ($end < $totalPages): ?>
                                    <?php if ($end < $totalPages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">…</span></li>
                                    <?php endif; ?>
                                    <li class="page-item"><a class="page-link" href="<?= h(sl_url(['page' => $totalPages])) ?>"><?= $totalPages ?></a></li>
                                <?php endif; ?>
                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page >= $totalPages ? '#' : h(sl_url(['page' => $page + 1])) ?>">
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

<!-- Send one confirm modal -->
<div class="modal fade" id="sendOneModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-paper-plane text-primary me-2"></i>Send Magic Link
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <p class="mb-2">
                Send a personalized booking link to <strong id="sendOneName"></strong>?
            </p>
            <p class="small text-muted mb-0">
                <i class="fas fa-envelope me-1"></i>
                <span id="sendOneEmail"></span>
            </p>
            <div class="alert alert-info small mt-3 mb-0">
                <i class="fas fa-info-circle me-1"></i>
                Any unused link for this student will be replaced.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="sendOneConfirm">
                <span class="btn-label"><i class="fas fa-paper-plane me-1"></i>Send Now</span>
                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Sending...</span>
            </button>
        </div>
    </div></div>
</div>

<!-- Send all confirm modal -->
<div class="modal fade" id="sendAllModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title">
                <i class="fas fa-paper-plane text-primary me-2"></i>Send to All Students
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
            <p class="mb-3">
                Send magic links to <strong id="sendAllCount"></strong> <?= h($gender) ?> student<?= $total===1?'':'s' ?>?
            </p>
            <div class="alert alert-warning small mb-0">
                <i class="fas fa-exclamation-triangle me-1"></i>
                This will email <strong>every active <?= h($gender) ?> student</strong>.
                Unused existing links will be replaced.
                <br>
                <?php if ($bookingSem): ?>
                    <strong>Booking semester:</strong> <?= h($bookingSem['name']) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
            <button class="btn btn-primary" id="sendAllConfirm">
                <span class="btn-label"><i class="fas fa-paper-plane me-1"></i>Send to All</span>
                <span class="btn-spin d-none"><span class="spinner-border spinner-border-sm me-1"></span>Starting...</span>
            </button>
        </div>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
const SEND_ALL_START = 'send_all_start';
const SEND_ONE_AJAX  = 'send_one_ajax';
const AJAX_URL       = 'send_links.php';

// ---------- Auto-dismiss flash ----------
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 4000);
});

// ---------- Auto-submit filters ----------
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

// ---------- Filter spinner ----------
document.getElementById('filterForm')?.addEventListener('submit', () => {
    const btn = document.getElementById('filterBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});

// ---------- Send ONE ----------
let currentSendRow = null;

document.querySelectorAll('.send-one-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        currentSendRow = btn.closest('tr');
        document.getElementById('sendOneName').textContent  = btn.dataset.name;
        document.getElementById('sendOneEmail').textContent = btn.dataset.email;
        document.getElementById('sendOneConfirm').dataset.sid = btn.dataset.sid;

        new bootstrap.Modal(document.getElementById('sendOneModal')).show();
    });
});

document.getElementById('sendOneConfirm')?.addEventListener('click', async function () {
    const sid  = this.dataset.sid;
    const row  = currentSendRow;
    const modal = bootstrap.Modal.getInstance(document.getElementById('sendOneModal'));

    // Button spinner
    this.querySelector('.btn-label').classList.add('d-none');
    this.querySelector('.btn-spin').classList.remove('d-none');
    this.disabled = true;

    // Row state
    const statusCell = row.querySelector('.status-cell');
    const prevStatus = statusCell.innerHTML;
    statusCell.innerHTML = '<span class="text-primary small">Sending...</span>';

    try {
        const fd = new FormData();
        fd.append('action', SEND_ONE_AJAX);
        fd.append('student_id', sid);

        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.ok) {
            statusCell.innerHTML = '<span class="badge bg-info text-dark">Sent</span>';
            const lc = row.querySelector('.link-count');
            if (lc) lc.textContent = (parseInt(lc.textContent) || 0) + 1;
            modal?.hide();
        } else {
            statusCell.innerHTML = '<span class="badge bg-danger">Failed</span>';
            setTimeout(() => { statusCell.innerHTML = prevStatus; }, 2500);
        }
    } catch (e) {
        statusCell.innerHTML = '<span class="badge bg-danger">Error</span>';
        setTimeout(() => { statusCell.innerHTML = prevStatus; }, 2500);
    }

    this.querySelector('.btn-label').classList.remove('d-none');
    this.querySelector('.btn-spin').classList.add('d-none');
    this.disabled = false;
});

// ---------- Send ALL ----------
document.getElementById('sendAllBtn')?.addEventListener('click', function () {
    document.getElementById('sendAllCount').textContent = this.dataset.count || '0';
    new bootstrap.Modal(document.getElementById('sendAllModal')).show();
});

document.getElementById('sendAllConfirm')?.addEventListener('click', async function () {
    const sendBtn = document.getElementById('sendAllConfirm');

    sendBtn.querySelector('.btn-label').classList.add('d-none');
    sendBtn.querySelector('.btn-spin').classList.remove('d-none');
    sendBtn.disabled = true;

    // Hide modal after starting
    setTimeout(() => {
        bootstrap.Modal.getInstance(document.getElementById('sendAllModal'))?.hide();
    }, 400);

    // Show progress panel
    const panel = document.getElementById('progressPanel');
    panel.classList.remove('d-none');

    const title   = document.getElementById('progressTitle');
    const detail  = document.getElementById('progressDetail');
    const counter = document.getElementById('progressCount');
    const bar     = document.getElementById('progressBar');
    const log     = document.getElementById('progressLog');

    // 1. Get list of students
    let students = [];
    let semesterName = '';
    try {
        const fd = new FormData();
        fd.append('action', SEND_ALL_START);
        const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error('Failed to start');
        students = data.students || [];
        semesterName = data.semester?.name || '';
    } catch (e) {
        title.textContent = 'Failed to start';
        sendBtn.querySelector('.btn-label').classList.remove('d-none');
        sendBtn.querySelector('.btn-spin').classList.add('d-none');
        sendBtn.disabled = false;
        return;
    }

    title.textContent = 'Sending to ' + students.length + ' students';
    detail.textContent = 'Booking for ' + semesterName;

    let ok = 0, failed = 0;

    // 2. Loop send
    for (let i = 0; i < students.length; i++) {
        const s = students[i];
        detail.textContent = 'Sending to ' + s.email + '...';

        try {
            const fd = new FormData();
            fd.append('action', SEND_ONE_AJAX);
            fd.append('student_id', s.id);
            const res  = await fetch(AJAX_URL, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                ok++;
                log.innerHTML = '<i class="fas fa-check text-success me-1"></i>' + s.full_name + ' — sent';
            } else {
                failed++;
                log.innerHTML = '<i class="fas fa-times text-danger me-1"></i>' + s.full_name + ' — failed';
            }
        } catch (e) {
            failed++;
        }

        const done = i + 1;
        counter.textContent = done + '/' + students.length;
        bar.style.width = (done / students.length * 100) + '%';

        // Update row if visible
        const row = document.querySelector('tr[data-sid="' + s.id + '"]');
        if (row) {
            const sc = row.querySelector('.status-cell');
            if (sc) sc.innerHTML = '<span class="badge bg-info text-dark">Sent</span>';
            const lc = row.querySelector('.link-count');
            if (lc) lc.textContent = (parseInt(lc.textContent) || 0) + 1;
        }

        await new Promise(r => setTimeout(r, 200));
    }

    title.textContent = 'Done — ' + ok + ' sent, ' + failed + ' failed';
    detail.textContent = ok + ' students now have a link for ' + semesterName;
    bar.classList.add('bg-success');
    bar.style.width = '100%';

    setTimeout(() => location.reload(), 2000);
});
</script>