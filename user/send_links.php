<?php
// user/send_links.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['dean_men', 'dean_women']);

$gender = gender_filter(); // 'Male' or 'Female'
$isAdmin = is_admin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Generate + send to ALL active students in wing
    if ($action === 'send_all') {
        $stmt = $pdo->prepare("
            SELECT id, full_name, email FROM students
            WHERE gender = ? AND is_active = 1 AND email IS NOT NULL AND email <> ''
            ORDER BY full_name
        ");
        $stmt->execute([$gender]);
        $students = $stmt->fetchAll();

        $sent = 0; $failed = 0; $errors = [];
        foreach ($students as $s) {
            // Expire any old unused links for this student
            $pdo->prepare("DELETE FROM magic_links WHERE student_id = ? AND used_at IS NULL")
                ->execute([$s['id']]);

            $raw  = bin2hex(random_bytes(32));
            $hash = hash('sha256', $raw);
            $pdo->prepare("
                INSERT INTO magic_links (student_id, token_hash, expires_at, created_by)
                VALUES (?, ?, ?, ?)
            ")->execute([
                $s['id'], $hash,
                date('Y-m-d H:i:s', strtotime('+7 days')),
                $_SESSION['user_id'],
            ]);

            $link = SITE_URL . '/student/book.php?token=' . $raw;
            if (send_magic_link_email($s['email'], $s['full_name'], $link)) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $s['email'];
            }
        }
        audit_log($_SESSION['user_id'], null, 'links_send_all', "Sent: $sent, failed: $failed ($gender)");
        $msg = "Sent to $sent student" . ($sent === 1 ? '' : 's') . ".";
        if ($failed > 0) $msg .= " Failed: $failed (".implode(', ', array_slice($errors, 0, 3)).")";
        $_SESSION['flash'] = ['type' => $failed > 0 ? 'warning' : 'success', 'msg' => $msg];
        redirect(APP_BASE . '/user/send_links.php');
    }

    // Resend to one student
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
                INSERT INTO magic_links (student_id, token_hash, expires_at, created_by)
                VALUES (?, ?, ?, ?)
            ")->execute([$sid, $hash, date('Y-m-d H:i:s', strtotime('+7 days')), $_SESSION['user_id']]);

            $link = SITE_URL . '/student/book.php?token=' . $raw;
            $ok = send_magic_link_email($s['email'], $s['full_name'], $link);
            audit_log($_SESSION['user_id'], $sid, 'links_send_one', "Resent link to {$s['reg_number']}");
            $_SESSION['flash'] = [
                'type' => $ok ? 'success' : 'danger',
                'msg'  => $ok ? 'Link sent to '.h($s['email']) : 'Failed to send email.',
            ];
        }
        redirect(APP_BASE . '/user/send_links.php');
    }
}

// ---------- STUDENT LIST ----------
$search = trim($_GET['q'] ?? '');
$filterLink = $_GET['link'] ?? ''; // '', 'sent', 'used', 'none'

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

// Stats
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
                        <?= h($gender) ?> wing · <?= number_format($total) ?> active student<?= $total===1?'':'s' ?>
                    </p>
                </div>
                <form method="post" onsubmit="return confirm('Send magic links to ALL <?= h($gender) ?> students? This emails everyone.');">
                    <input type="hidden" name="action" value="send_all">
                    <button class="btn btn-primary">
                        <i class="fas fa-paper-plane me-1"></i>Send to All (<?= $total ?>)
                    </button>
                </form>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

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
                                <tr>
                                    <td>
                                        <div class="fw-semibold"><?= h($s['full_name']) ?></div>
                                        <div class="text-muted" style="font-size:.72rem;"><?= h($s['reg_number']) ?></div>
                                    </td>
                                    <td class="small text-muted"><?= h($s['email']) ?></td>
                                    <td>
                                        <span class="badge bg-light text-dark"><?= (int)$s['link_count'] ?></span>
                                        <?php if ($s['pending_expires']): ?>
                                            <div class="text-muted" style="font-size:.7rem;">
                                                <i class="fas fa-clock me-1"></i>expires <?= date('M j', strtotime($s['pending_expires'])) ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($s['last_used']): ?>
                                            <span class="badge bg-success">Used</span>
                                        <?php elseif ($s['pending_expires']): ?>
                                            <span class="badge bg-info text-dark">Sent</span>
                                        <?php else: ?>
                                            <span class="badge bg-light text-dark">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline"
                                              onsubmit="return confirm('Send/resend magic link to <?= h(addslashes($s['full_name'])) ?>?');">
                                            <input type="hidden" name="action" value="send_one">
                                            <input type="hidden" name="student_id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-primary" title="Send / Resend">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        </form>
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