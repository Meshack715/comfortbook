<?php
// user/phase_control.php — semester management + activate & place
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_role(['admin']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ============================================================
// ACTIONS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- ADD SEMESTER ----
    if ($action === 'add_semester') {
        $ay    = trim($_POST['academic_year'] ?? '');
        $semNo = (int)($_POST['semester_no'] ?? 1);
        $start = $_POST['start_date'] ?? '';
        $end   = $_POST['end_date']   ?? '';

        $errors = [];
        if (!preg_match('#^\d{4}/\d{4}$#', $ay)) $errors[] = 'Academic year must be YYYY/YYYY.';
        if (!in_array($semNo, [1, 2], true))     $errors[] = 'Semester must be 1 or 2.';
        if (!$start || !$end)                    $errors[] = 'Start and end dates required.';
        if ($start >= $end)                      $errors[] = 'End must be after start.';

        if (!$errors) {
            $name = "$ay · Sem $semNo";
            try {
                $pdo->beginTransaction();
                $pdo->prepare("
                    INSERT INTO semesters (academic_year, semester_no, name, start_date, end_date, is_active)
                    VALUES (?, ?, ?, ?, ?, 0)
                ")->execute([$ay, $semNo, $name, $start, $end]);
                $newSemId = (int)$pdo->lastInsertId();

                $pdo->prepare("INSERT INTO semester_windows (semester_id, gender) VALUES (?, 'Male')")->execute([$newSemId]);
                $pdo->prepare("INSERT INTO semester_windows (semester_id, gender) VALUES (?, 'Female')")->execute([$newSemId]);

                $pdo->commit();
                audit_log($_SESSION['user_id'], null, 'semester_add', "Added: $name");
                $_SESSION['flash'] = ['type'=>'success','msg'=>"Created $name."];
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $_SESSION['flash'] = ['type'=>'danger','msg'=>friendly_error($e, 'creating that')];
            }
        } else {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>implode(' ', $errors)];
        }
        redirect(APP_BASE.'/user/phase_control.php');
    }

    // ---- ACTIVATE SEMESTER + APPLY CONFIRMED BOOKINGS ----
    if ($action === 'activate') {
        $sid = (int)($_POST['semester_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $pdo->prepare("SELECT * FROM semesters WHERE id=?");
            $stmt->execute([$sid]);
            $sem = $stmt->fetch();
            if ($sem) {
                try {
                    $pdo->beginTransaction();

                    // Deactivate others, activate this one
                    $pdo->exec("UPDATE semesters SET is_active=0");
                    $pdo->prepare("UPDATE semesters SET is_active=1 WHERE id=?")->execute([$sid]);
                    set_config('active_semester_id', (string)$sid);

                    // Pull all confirmed bookings for this semester
                    $stmt = $pdo->prepare("
                        SELECT b.id, b.room_id, b.student_id
                        FROM bookings b
                        WHERE b.semester_id = ? AND b.status = 'confirmed'
                    ");
                    $stmt->execute([$sid]);
                    $confirmedBookings = $stmt->fetchAll();

                    // Apply each one
                    $applied = 0;
                    foreach ($confirmedBookings as $b) {
                        $pdo->prepare("
                            UPDATE rooms SET status='occupied', reserved_by_student_id=NULL, reserved_at=NULL
                            WHERE id = ?
                        ")->execute([$b['room_id']]);

                        $pdo->prepare("
                            UPDATE students SET current_room_id=?, placed=1 WHERE id=?
                        ")->execute([$b['room_id'], $b['student_id']]);

                        $applied++;
                    }

                    $pdo->commit();

                    audit_log($_SESSION['user_id'], null, 'semester_activate',
                        "Activated {$sem['name']} · placed $applied students");

                    // Fire welcome emails
                    foreach ($confirmedBookings as $b) {
                        $stmt = $pdo->prepare("
                            SELECT s.email, s.full_name, r.room_number, bl.name AS block_name
                            FROM students s
                            JOIN rooms r ON r.id = ?
                            JOIN blocks bl ON bl.id = r.block_id
                            WHERE s.id = ?
                        ");
                        $stmt->execute([$b['room_id'], $b['student_id']]);
                        $row = $stmt->fetch();
                        if ($row) {
                            @send_welcome_email($row['email'], $row['full_name'], $row['room_number'], $row['block_name']);
                        }
                    }

                    $_SESSION['flash'] = [
                        'type'=>'success',
                        'msg'=>"Activated {$sem['name']} · placed $applied student".($applied===1?'':'s')." in rooms.",
                    ];
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $_SESSION['flash'] = ['type'=>'danger','msg'=>friendly_error($e, 'activate')];
                }
            }
        }
        redirect(APP_BASE.'/user/phase_control.php');
    }

    // ---- SAVE GLOBALS ----
    if ($action === 'save_globals') {
        $otp = (int)($_POST['otp_ttl_minutes'] ?? 10);
        $pen = (int)($_POST['penalty_amount'] ?? 200);
        set_config('otp_ttl_minutes', (string)max(1, $otp));
        set_config('penalty_amount',  (string)max(0, $pen));
        set_config('show_otp_in_ui', isset($_POST['show_otp_in_ui']) ? '1' : '0');
        audit_log($_SESSION['user_id'], null, 'config_save', 'Updated globals');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Settings saved.'];
        redirect(APP_BASE.'/user/phase_control.php');
    }

    // ---- RESET DEMO (current semester only) ----
    if ($action === 'reset_demo') {
        $sid = active_semester_id();
        if (!$sid) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'No active semester.'];
            redirect(APP_BASE.'/user/phase_control.php');
        }
        try {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM bookings WHERE semester_id=?")->execute([$sid]);
            $pdo->prepare("DELETE FROM magic_links WHERE semester_id=?")->execute([$sid]);
            $pdo->exec("UPDATE rooms SET status='vacant', reserved_by_student_id=NULL, reserved_at=NULL");
            $pdo->exec("UPDATE students SET phase1_action='none', placed=0, late_booker=0, current_room_id=NULL");
            $pdo->commit();
            audit_log($_SESSION['user_id'], null, 'demo_reset', 'Reset current semester');
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Demo reset for active semester.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'danger','msg'=>friendly_error($e, 'reset')];
        }
        redirect(APP_BASE.'/user/phase_control.php');
    }
}

// ============================================================
// DATA
// ============================================================
$activeSem = active_semester();
$activeSid = $activeSem ? (int)$activeSem['id'] : 0;

$semesters = $pdo->query("
    SELECT * FROM semesters
    ORDER BY academic_year DESC, semester_no DESC
")->fetchAll();

// Per-semester confirmed count (for the Activate button tooltip)
$confirmedCounts = [];
$stmt = $pdo->query("
    SELECT semester_id, COUNT(*) c
    FROM bookings
    WHERE status='confirmed'
    GROUP BY semester_id
");
foreach ($stmt as $row) $confirmedCounts[(int)$row['semester_id']] = (int)$row['c'];

// Both wings' windows for the active semester
$menWin   = $activeSid ? semester_window($activeSid, 'Male')   : null;
$womenWin = $activeSid ? semester_window($activeSid, 'Female') : null;

$menPhase   = $activeSid ? current_phase('Male')   : null;
$womenPhase = $activeSid ? current_phase('Female') : null;

// Stats per wing (scoped to active semester)
$stats = [
    'male'   => ['links'=>0, 'used'=>0, 'pending'=>0, 'confirmed'=>0],
    'female' => ['links'=>0, 'used'=>0, 'pending'=>0, 'confirmed'=>0],
];
if ($activeSid) {
    foreach (['male'=>'Male', 'female'=>'Female'] as $key => $g) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM magic_links ml
            JOIN students s ON s.id = ml.student_id
            WHERE ml.semester_id = ? AND s.gender = ?
        ");
        $stmt->execute([$activeSid, $g]);
        $stats[$key]['links'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM magic_links ml
            JOIN students s ON s.id = ml.student_id
            WHERE ml.semester_id = ? AND s.gender = ? AND ml.used_at IS NOT NULL
        ");
        $stmt->execute([$activeSid, $g]);
        $stats[$key]['used'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings b
            JOIN students s ON s.id = b.student_id
            WHERE b.semester_id = ? AND s.gender = ? AND b.status = 'pending_approval'
        ");
        $stmt->execute([$activeSid, $g]);
        $stats[$key]['pending'] = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM bookings b
            JOIN students s ON s.id = b.student_id
            WHERE b.semester_id = ? AND s.gender = ? AND b.status = 'confirmed'
        ");
        $stmt->execute([$activeSid, $g]);
        $stats[$key]['confirmed'] = (int)$stmt->fetchColumn();
    }
}

$cfg = [
    'otp_ttl_minutes' => get_config('otp_ttl_minutes', '10'),
    'penalty_amount'  => get_config('penalty_amount', '200'),
    'show_otp_in_ui'  => get_config('show_otp_in_ui', '0'),
];

function phase_badge(?int $p): string {
    return match($p) {
        1 => '<span class="badge bg-primary"><i class="fas fa-circle me-1"></i>Phase 1 Live</span>',
        2 => '<span class="badge bg-success"><i class="fas fa-circle me-1"></i>Phase 2 Live</span>',
        default => '<span class="badge bg-secondary"><i class="fas fa-circle me-1"></i>Closed</span>',
    };
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-calendar-alt text-primary me-2"></i>Semester Control</h4>
                    <p class="text-muted small mb-0">
                        Create semesters · activate one to place its confirmed bookings
                    </p>
                </div>
                <?php if ($activeSem): ?>
                    <span class="badge bg-dark fs-6">
                        <i class="fas fa-calendar-check me-1"></i><?= h($activeSem['name']) ?>
                    </span>
                <?php else: ?>
                    <span class="badge bg-warning text-dark fs-6">
                        <i class="fas fa-exclamation-triangle me-1"></i>No active semester
                    </span>
                <?php endif; ?>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Info banner -->
            <div class="alert alert-info d-flex align-items-start">
                <i class="fas fa-info-circle me-2 mt-1"></i>
                <div class="small">
                    <strong>Activating a semester</strong> does two things at once:
                    (1) it becomes the current semester, and
                    (2) all <strong>confirmed bookings</strong> for that semester are <strong>automatically placed</strong> into their rooms
                    — rooms become occupied, students become placed, and welcome emails are sent.
                    Deans' approved bookings wait until this moment.
                </div>
            </div>

            <!-- ============ SEMESTER TABLE ============ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-calendar-alt text-primary me-2"></i>Semesters
                    </h6>
                    <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addSemModal">
                        <i class="fas fa-plus-circle me-1"></i>New Semester
                    </button>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($semesters)): ?>
                        <div class="text-center py-5 text-muted small">
                            No semesters yet. Click <strong>New Semester</strong> to add one.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 align-middle">
                                <thead class="table-light">
                                    <tr>
                                        <th>Semester</th>
                                        <th>Period</th>
                                        <th class="text-center">Confirmed</th>
                                        <th>Status</th>
                                        <th class="text-end">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($semesters as $s):
                                    $isActive = ((int)$s['id'] === $activeSid);
                                    $conf     = $confirmedCounts[(int)$s['id']] ?? 0;
                                ?>
                                    <tr <?= $isActive ? 'style="background:#f0f8f4;"' : '' ?>>
                                        <td>
                                            <div class="fw-bold"><?= h($s['name']) ?></div>
                                            <div class="text-muted small">
                                                <?= h($s['academic_year']) ?> · Sem <?= (int)$s['semester_no'] ?>
                                            </div>
                                        </td>
                                        <td class="small">
                                            <?= date('M j, Y', strtotime($s['start_date'])) ?>
                                            → <?= date('M j, Y', strtotime($s['end_date'])) ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($conf > 0): ?>
                                                <span class="badge bg-success"><?= $conf ?></span>
                                            <?php else: ?>
                                                <span class="text-muted small">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($isActive): ?>
                                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>Active</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!$isActive): ?>
                                                <form method="post" class="d-inline"
                                                      onsubmit="return confirm('Activate <?= h(addslashes($s['name'])) ?>?\n\nThis will:\n• Make it the current semester\n• Place <?= $conf ?> confirmed student<?= $conf===1?'':'s' ?> into their rooms\n• Send <?= $conf ?> welcome email<?= $conf===1?'':'s' ?>\n\nThis cannot be undone.');">
                                                    <input type="hidden" name="action" value="activate">
                                                    <input type="hidden" name="semester_id" value="<?= (int)$s['id'] ?>">
                                                    <button class="btn btn-sm btn-success" <?= $conf === 0 && $isActive ? 'disabled' : '' ?>>
                                                        <i class="fas fa-play-circle me-1"></i>Activate &amp; Place
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted small">Currently active</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============ WING WINDOWS (READ-ONLY) ============ -->
            <?php if ($activeSem): ?>
            <div class="row g-3 mb-3">
                <?php foreach ([
                    ['gender'=>'Male',   'label'=>'Men’s Wing',   'icon'=>'fa-mars',  'color'=>'primary', 'win'=>$menWin,   'phase'=>$menPhase,   'stats'=>$stats['male']],
                    ['gender'=>'Female', 'label'=>'Women’s Wing', 'icon'=>'fa-venus', 'color'=>'danger',  'win'=>$womenWin, 'phase'=>$womenPhase, 'stats'=>$stats['female']],
                ] as $w): ?>
                <div class="col-lg-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas <?= $w['icon'] ?> text-<?= $w['color'] ?> me-2"></i>
                                <?= h($w['label']) ?>
                            </h6>
                            <?= phase_badge($w['phase']) ?>
                        </div>
                        <div class="card-body small">

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <div class="text-muted">Phase 1</div>
                                    <?php if ($w['win'] && $w['win']['phase1_start']): ?>
                                        <div><strong><?= date('M j, H:i', strtotime($w['win']['phase1_start'])) ?></strong></div>
                                        <div>→ <?= date('M j, H:i', strtotime($w['win']['phase1_end'])) ?></div>
                                    <?php else: ?>
                                        <div class="text-muted fst-italic">Not set</div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-6">
                                    <div class="text-muted">Phase 2</div>
                                    <?php if ($w['win'] && $w['win']['phase2_start']): ?>
                                        <div><strong><?= date('M j, H:i', strtotime($w['win']['phase2_start'])) ?></strong></div>
                                        <div>→ <?= date('M j, H:i', strtotime($w['win']['phase2_end'])) ?></div>
                                    <?php else: ?>
                                        <div class="text-muted fst-italic">Not set</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <hr>

                            <div class="row g-2 text-center">
                                <div class="col-3">
                                    <div class="fw-bold text-primary"><?= number_format($w['stats']['links']) ?></div>
                                    <div class="text-muted" style="font-size:.7rem;">Links</div>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold text-info"><?= number_format($w['stats']['used']) ?></div>
                                    <div class="text-muted" style="font-size:.7rem;">Used</div>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold text-warning"><?= number_format($w['stats']['pending']) ?></div>
                                    <div class="text-muted" style="font-size:.7rem;">Pending</div>
                                </div>
                                <div class="col-3">
                                    <div class="fw-bold text-success"><?= number_format($w['stats']['confirmed']) ?></div>
                                    <div class="text-muted" style="font-size:.7rem;">Confirmed</div>
                                </div>
                            </div>

                            <?php if ($w['win'] && $w['win']['updated_at']): ?>
                                <div class="text-muted mt-2" style="font-size:.7rem;">
                                    <i class="fas fa-history me-1"></i>
                                    Updated <?= date('M j H:i', strtotime($w['win']['updated_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-light small">
                <i class="fas fa-user-tie me-1"></i>
                Phase windows are set by the <strong>Dean of Men</strong> and
                <strong>Dean of Women</strong> separately in their
                <a href="<?= APP_BASE ?>/user/phase_windows.php">Phase Windows</a> page.
                Admin only activates the semester.
            </div>

            <!-- Danger zone -->
            <div class="card border-0 shadow-sm mb-3 border-danger border-opacity-25">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold text-danger">
                        <i class="fas fa-bomb me-2"></i>Danger Zone
                    </h6>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-2">
                        Deletes <strong>all bookings &amp; magic links</strong> for
                        <strong><?= h($activeSem['name']) ?></strong>,
                        resets rooms to vacant, clears student actions.
                    </p>
                    <form method="post" onsubmit="return confirm('Reset demo data for this semester?');">
                        <input type="hidden" name="action" value="reset_demo">
                        <button class="btn btn-danger btn-sm">
                            <i class="fas fa-trash-restore me-1"></i>Reset Demo (this semester)
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============ GLOBAL SETTINGS ============ -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white">
                    <h6 class="mb-0 fw-bold">
                        <i class="fas fa-sliders-h text-primary me-2"></i>Global Settings
                    </h6>
                </div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="save_globals">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">OTP TTL (minutes)</label>
                                <input type="number" name="otp_ttl_minutes" class="form-control"
                                       min="1" max="60" value="<?= (int)$cfg['otp_ttl_minutes'] ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Penalty (KES)</label>
                                <input type="number" name="penalty_amount" class="form-control"
                                       min="0" value="<?= (int)$cfg['penalty_amount'] ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label d-block">Dev Options</label>
                                <div class="form-check form-switch mt-2">
                                    <input class="form-check-input" type="checkbox" name="show_otp_in_ui"
                                           id="showOtp" <?= $cfg['show_otp_in_ui']==='1'?'checked':'' ?>>
                                    <label class="form-check-label" for="showOtp">Show OTP in UI</label>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <button class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Save Settings
                        </button>
                    </form>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>

<!-- Add Semester Modal -->
<div class="modal fade" id="addSemModal" tabindex="-1">
    <div class="modal-dialog"><div class="modal-content">
        <form method="post">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="fas fa-calendar-plus text-primary me-2"></i>Add Semester
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="action" value="add_semester">

                <div class="mb-3">
                    <label class="form-label small fw-bold">Academic Year</label>
                    <input type="text" name="academic_year" class="form-control"
                           placeholder="e.g. 2027/2028" pattern="\d{4}/\d{4}" required>
                    <small class="text-muted">Format: YYYY/YYYY</small>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold">Semester</label>
                    <select name="semester_no" class="form-select" required>
                        <option value="1">Semester 1</option>
                        <option value="2">Semester 2</option>
                    </select>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-bold">Start Date</label>
                        <input type="date" name="start_date" class="form-control" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-bold">End Date</label>
                        <input type="date" name="end_date" class="form-control" required>
                    </div>
                </div>

                <div class="alert alert-info small mb-0">
                    <i class="fas fa-info-circle me-1"></i>
                    Semester is created <strong>inactive</strong>. Deans set phase windows separately.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary">
                    <i class="fas fa-save me-1"></i>Create Semester
                </button>
            </div>
        </form>
    </div></div>
</div>

<?php include __DIR__ . '/partial/footer.php'; ?>