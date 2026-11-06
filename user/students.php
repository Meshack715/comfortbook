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

// ---------- DELETE (admin only) ----------
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE student_id = ? AND status IN ('pending_otp','pending_payment','pending_approval','confirmed')");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Cannot delete — student has active bookings.'];
        } else {
            $pdo->prepare("DELETE FROM students WHERE id = ?")->execute([$id]);
            audit_log($_SESSION['user_id'], null, 'student_delete', "Deleted student #$id");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Student deleted.'];
        }
    }
    redirect(APP_BASE.'/user/students.php');
}

// ---------- FILTERS ----------
$filterGender = $_GET['gender'] ?? '';
$filterBlock  = (int)($_GET['block_id'] ?? 0);
$filterPlaced = $_GET['placed'] ?? '';
$search       = trim($_GET['q'] ?? '');

// Placed in current semester = has a confirmed booking for the active semester
$placedExpr = $activeSid
    ? "EXISTS (SELECT 1 FROM bookings b WHERE b.student_id = s.id AND b.status='confirmed' AND b.semester_id = $activeSid)"
    : "s.placed = 1";

$where  = ' WHERE s.is_active = 1';
$params = [];
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

$stmt = $pdo->prepare("
    SELECT s.*, r.room_number, b.name AS block_name,
        $placedExpr AS placed_now
    FROM students s
    LEFT JOIN rooms r ON r.id = s.current_room_id
    LEFT JOIN blocks b ON b.id = r.block_id
    $where
    ORDER BY s.gender, s.full_name
    LIMIT 500
");
$stmt->execute($params);
$students = $stmt->fetchAll();

// Blocks for filter
$bWhere = ' WHERE is_active = 1'; $bP = [];
if ($gender !== null) { $bWhere .= ' AND gender = ?'; $bP[] = $gender; }
$stmt = $pdo->prepare("SELECT id, name, gender FROM blocks $bWhere ORDER BY gender, name");
$stmt->execute($bP); $blocks = $stmt->fetchAll();

// ---------- STATS (current semester) ----------
$statsWhere = ' WHERE is_active = 1'; $statsP = [];
if ($gender !== null) { $statsWhere .= ' AND gender = ?'; $statsP[] = $gender; }
$stmt = $pdo->prepare("
    SELECT COUNT(*) total,
        SUM(CASE WHEN $placedExpr THEN 1 ELSE 0 END) placed,
        SUM(CASE WHEN NOT ($placedExpr) THEN 1 ELSE 0 END) unplaced
    FROM students s
    $statsWhere
");
$stmt->execute($statsP);
$stats = $stmt->fetch() ?: ['total'=>0,'placed'=>0,'unplaced'=>0];
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-user-graduate text-primary me-2"></i>Students</h4>
                    <p class="text-muted small mb-0">
                        <?= count($students) ?> result<?= count($students)===1?'':'s' ?>
                        <?= $gender ? ' · '.h($gender).' wing' : ' · All wings' ?>
                        <?php if ($activeSid): ?>
                            · placed for <strong><?= h(active_semester()['name']) ?></strong>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if ($isAdmin): ?>
                <div class="d-flex gap-2">
                    <a href="student_import.php" class="btn btn-outline-primary btn-sm"><i class="fas fa-file-csv me-1"></i>Import CSV</a>
                    <a href="student_form.php" class="btn btn-primary btn-sm"><i class="fas fa-user-plus me-1"></i>Add Student</a>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="row g-3 mb-3">
                <div class="col-4">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-primary"><?= number_format((int)$stats['total']) ?></div>
                        <div class="small text-muted text-uppercase">Total</div>
                    </div></div>
                </div>
                <div class="col-4">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-success"><?= number_format((int)$stats['placed']) ?></div>
                        <div class="small text-muted text-uppercase">Placed (this sem)</div>
                    </div></div>
                </div>
                <div class="col-4">
                    <div class="card border-0 shadow-sm"><div class="card-body text-center">
                        <div class="fs-3 fw-bold text-danger"><?= number_format((int)$stats['unplaced']) ?></div>
                        <div class="small text-muted text-uppercase">Unplaced</div>
                    </div></div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3"><div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-md-3"><label class="form-label small mb-1">Search</label>
                        <input type="text" name="q" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Name, reg #, email"></div>
                    <?php if ($gender === null): ?>
                    <div class="col-md-2"><label class="form-label small mb-1">Gender</label>
                        <select name="gender" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="Male" <?= $filterGender==='Male'?'selected':'' ?>>Male</option>
                            <option value="Female" <?= $filterGender==='Female'?'selected':'' ?>>Female</option>
                        </select></div>
                    <?php endif; ?>
                    <div class="col-md-3"><label class="form-label small mb-1">Building</label>
                        <select name="block_id" class="form-select form-select-sm">
                            <option value="0">All</option>
                            <?php foreach ($blocks as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $filterBlock===(int)$b['id']?'selected':'' ?>>
                                    <?= h($b['name']) ?> (<?= h($b['gender']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select></div>
                    <div class="col-md-2"><label class="form-label small mb-1">Status</label>
                        <select name="placed" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="1" <?= $filterPlaced==='1'?'selected':'' ?>>Placed</option>
                            <option value="0" <?= $filterPlaced==='0'?'selected':'' ?>>Unplaced</option>
                        </select></div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary btn-sm flex-fill"><i class="fas fa-filter"></i></button>
                        <a href="students.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i></a>
                    </div>
                </form>
            </div></div>

            <div class="card border-0 shadow-sm"><div class="card-body p-0">
                <?php if (empty($students)): ?>
                    <div class="text-center py-5 text-muted"><i class="fas fa-user-graduate fa-3x mb-3 opacity-25"></i><h6>No students found</h6></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Student</th>
                                    <th>Gender</th>
                                    <th>Current Room</th>
                                    <th>Year</th>
                                    <th>Phase 1</th>
                                    <th>Placed (this sem)</th>
                                    <?php if ($isAdmin): ?><th class="text-end">Actions</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($students as $s): ?>
                                <tr>
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
                                    <td class="small"><?= (int)$s['year_of_study'] ?></td>
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
                                    <td class="text-end">
                                        <a href="student_form.php?id=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fas fa-edit"></i></a>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Delete this student?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                    <?php endif; ?>
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