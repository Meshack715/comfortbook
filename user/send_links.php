<?php
// user/send_links.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men', 'dean_women']);

$gender = gender_filter();               // 'Male' or 'Female'
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Which semester do these links belong to?
// Rule: links belong to the NEXT semester after the active one.
// If no next semester exists, fall back to the active one.
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
    // No active → try the earliest non-active semester
    $stmt = $pdo->query("SELECT * FROM semesters ORDER BY start_date ASC LIMIT 1");
    $bookingSem = $stmt->fetch() ?: null;
}

// Phase gate — dean can only send when their OWN wing's phase is live
$myPhase = current_phase($gender);
$canSend = $myPhase !== null && $bookingSem !== null;

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!$canSend) {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot send links — your phase window is not active.'];
        redirect(APP_BASE.'/user/send_links.php');
    }

    // ---------- SEND ALL (JSON progress) ----------
    if ($action === 'send_all_start') {
        // Just kick off — return the list of students to process
        header('Content-Type: application/json');
        $stmt = $pdo->prepare("
            SELECT id, full_name, email FROM students
            WHERE gender = ? AND is_active = 1 AND email IS NOT NULL AND email <> ''
            ORDER BY full_name
        ");
        $stmt->execute([$gender]);
        $students = $stmt->fetchAll();

        echo json_encode([
            'ok'         => true,
            'students'   => $students,
            'semester'   => ['id'=>$bookingSem['id'], 'name'=>$bookingSem['name']],
        ]);
        exit;
    }

    // Send one (called per row from JS)
    if ($action === 'send_one_ajax') {
        header('Content-Type: application/json');
        $sid = (int)($_POST['student_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND gender = ?");
        $stmt->execute([$sid, $gender]);
        $s = $stmt->fetch();
        if (!$s) { echo json_encode(['ok'=>false, 'error'=>'Student not found']); exit; }

        // wipe any old unused link
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

        echo json_encode([
            'ok'    => $ok,
            'email' => $s['email'],
            'name'  => $s['full_name'],
        ]);
        exit;
    }

    // ---------- RESEND SINGLE (classic POST) ----------
    if ($action === 'send_one') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND gender = ?");
        $stmt->execute([$sid, $gender]);
        $s = $stmt->fetch();
        if ($s) {
            $pdo->prepare("DELETE FROM magic_links WHERE student_id = ? AND used_at IS NULL")->execute([$sid]);
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
            $ok = send_magic_link_email($s['email'], $s['full_name'], $link);
            audit_log($_SESSION['user_id'], $sid, 'links_send_one', "Resent link to {$s['reg_number']}");
            $_SESSION['flash'] = [
                'type' => $ok ? 'success' : 'danger',
                'msg'  => $ok ? 'Link sent to '.h($s['email']) : 'Failed to send email.',
            ];
        }
        redirect(APP_BASE.'/user/send_links.php');
    }
}

// ---------- LIST ----------
$search = trim($_GET['q'] ?? '');
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

$stmt = $pdo->prepare("
    SELECT s.id, s.reg_number, s.full_name, s.email, s.phase1_action, s.placed,
        (SELECT COUNT(*) FROM magic_links ml WHERE ml.student_id = s.id) link_count,
        (SELECT used_at FROM magic_links ml WHERE ml.student_id = s.id AND ml.used_at IS NOT NULL ORDER BY used_at DESC LIMIT 1) last_used,
        (SELECT expires_at FROM magic_links ml WHERE ml.student_id = s.id AND ml.used_at IS NULL ORDER BY created_at DESC LIMIT 1) pending_expires
    FROM students s
    $where
    ORDER BY s.full_name
");
$stmt->execute($params);
$students = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM students WHERE gender = ? AND is_active = 1");
$stmt->execute([$gender]);
$total = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT s.id) FROM students s
    JOIN magic_links ml ON ml.student_id = s.id
    WHERE s.gender = ? AND s.is_active = 1
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
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-paper-plane text-primary me-2"></i>Send Magic Links</h4>
                    <p class="text-muted small mb-0">
                        <?= h($gender) ?> wing · <?= number_format($total) ?> student<?= $total===1?'':'s' ?>
                        <?php if ($bookingSem): ?>
                            · booking for <strong><?= h($bookingSem['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <button class="btn btn-primary" id="sendAllBtn" <?= $canSend ? '' : 'disabled' ?>
                        data-bs-toggle="<?= $canSend ? '' : 'tooltip' ?>"
                        title="<?= $canSend ? '' : 'Your phase window is not active yet' ?>">
                    <i class="fas fa-paper-plane me-1"></i>
                    Send to All (<?= $total ?>)
                </button>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
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

            <!-- Live progress panel (hidden until send starts) -->
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
                <div class="col-4">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-primary"><?= number_format($total) ?></div>
                        <div class="small text-muted text-uppercase">Students</div>
                    </div></div>
                </div>
                <div class="col-4">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-info"><?= number_format($withLink) ?></div>
                        <div class="small text-muted text-uppercase">With Link</div>
                    </div></div>
                </div>
                <div class="col-4">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-success"><?= number_format($used) ?></div>
                        <div class="small text-muted text-uppercase">Used</div>
                    </div></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-6"><label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Name, reg #, email"></div>
                    <div class="col-md-4"><label class="form-label small mb-1">Link Status</label>
                        <select name="link" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="sent" <?= $filterLink==='sent'?'selected':'' ?>>Link Sent</option>
                            <option value="used" <?= $filterLink==='used'?'selected':'' ?>>Link Used</option>
                            <option value="none" <?= $filterLink==='none'?'selected':'' ?>>No Link</option>
                        </select></div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter"></i></button>
                        <a href="send_links.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <!-- Table -->
            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($students)): ?>
                    <div class="text-center py-5 text-muted"><i class="fas fa-user-graduate fa-3x mb-3 opacity-25"></i><h6>No students found</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Email</th>
                                    <th>Links</th>
                                    <th>Status</th>
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
                                    <td>
                                        <span class="badge bg-light text-dark link-count"><?= (int)$s['link_count'] ?></span>
                                        <?php if ($s['pending_expires']): ?>
                                            <div class="text-muted" style="font-size:.7rem;">
                                                <i class="fas fa-clock me-1"></i>expires <?= date('M j', strtotime($s['pending_expires'])) ?>
                                            </div>
                                        <?php endif; ?>
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
                                    <td class="text-end">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary send-one-btn"
                                                data-sid="<?= (int)$s['id'] ?>"
                                                data-name="<?= h($s['full_name']) ?>"
                                                data-email="<?= h($s['email']) ?>"
                                                <?= $canSend ? '' : 'disabled' ?>>
                                            <span class="spin d-none"><span class="spinner-border spinner-border-sm"></span></span>
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
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
<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
const SEND_ALL_START = 'send_all_start';
const SEND_ONE_AJAX  = 'send_one_ajax';
const CSRF_URL       = 'send_links.php';

// ---------- Send-one (row-level spinner) ----------
document.querySelectorAll('.send-one-btn').forEach(btn => {
    btn.addEventListener('click', async function () {
        if (!confirm('Send magic link to ' + this.dataset.name + '?')) return;

        const row = this.closest('tr');
        const spin = this.querySelector('.spin');
        const icon = this.querySelector('.fa-paper-plane');
        const email = this.dataset.email;
        const sid = this.dataset.sid;

        spin.classList.remove('d-none');
        icon.classList.add('d-none');
        this.disabled = true;

        // Show live text in status cell
        const statusCell = row.querySelector('.status-cell');
        const prevStatus = statusCell.innerHTML;
        statusCell.innerHTML = '<span class="text-primary small">Sending to ' + email + '...</span>';

        try {
            const fd = new FormData();
            fd.append('action', SEND_ONE_AJAX);
            fd.append('student_id', sid);

            const res = await fetch(CSRF_URL, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.ok) {
                statusCell.innerHTML = '<span class="badge bg-info text-dark">Sent</span>';
                row.querySelector('.link-count').textContent =
                    (parseInt(row.querySelector('.link-count').textContent) || 0) + 1;
            } else {
                statusCell.innerHTML = '<span class="badge bg-danger">Failed</span>';
                setTimeout(() => { statusCell.innerHTML = prevStatus; }, 2000);
            }
        } catch (e) {
            statusCell.innerHTML = '<span class="badge bg-danger">Error</span>';
            setTimeout(() => { statusCell.innerHTML = prevStatus; }, 2000);
        }

        spin.classList.add('d-none');
        icon.classList.remove('d-none');
        this.disabled = false;
    });
});

// ---------- Send All (batch with progress) ----------
document.getElementById('sendAllBtn')?.addEventListener('click', async function () {
    if (!confirm('Send magic links to ALL students in your wing? This emails everyone.')) return;

    this.disabled = true;
    this.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';

    const panel = document.getElementById('progressPanel');
    panel.classList.remove('d-none');

    const title   = document.getElementById('progressTitle');
    const detail  = document.getElementById('progressDetail');
    const counter = document.getElementById('progressCount');
    const bar     = document.getElementById('progressBar');
    const log     = document.getElementById('progressLog');

    // 1. Kick off — get the list
    let students = [];
    let semesterName = '';
    try {
        const fd = new FormData();
        fd.append('action', SEND_ALL_START);
        const res = await fetch(CSRF_URL, { method: 'POST', body: fd });
        const data = await res.json();
        if (!data.ok) throw new Error('Failed to start');
        students = data.students || [];
        semesterName = data.semester?.name || '';
    } catch (e) {
        title.textContent = 'Failed to start';
        this.disabled = false;
        this.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to All';
        return;
    }

    title.textContent = 'Sending to ' + students.length + ' students';
    detail.textContent = 'Booking for ' + semesterName;

    let ok = 0, failed = 0;

    // 2. Loop send one at a time
    for (let i = 0; i < students.length; i++) {
        const s = students[i];

        detail.textContent = 'Sending to ' + s.email + '...';

        try {
            const fd = new FormData();
            fd.append('action', SEND_ONE_AJAX);
            fd.append('student_id', s.id);
            const res = await fetch(CSRF_URL, { method: 'POST', body: fd });
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

        // Row-level UI update
        const row = document.querySelector('tr[data-sid="' + s.id + '"]');
        if (row) {
            const statusCell = row.querySelector('.status-cell');
            if (statusCell) {
                statusCell.innerHTML = failed > ok
                    ? '<span class="badge bg-danger">Failed</span>'
                    : '<span class="badge bg-info text-dark">Sent</span>';
            }
            const lc = row.querySelector('.link-count');
            if (lc) lc.textContent = (parseInt(lc.textContent) || 0) + 1;
        }

        // Small delay so server doesn't choke (SMTP rate limits)
        await new Promise(r => setTimeout(r, 200));
    }

    title.textContent = 'Done — ' + ok + ' sent, ' + failed + ' failed';
    detail.textContent = ok + ' students now have a link for ' + semesterName;
    bar.classList.add('bg-success');
    bar.style.width = '100%';

    this.disabled = false;
    this.innerHTML = '<i class="fas fa-paper-plane me-1"></i>Send to All';

    setTimeout(() => location.reload(), 2500);
});
</script>