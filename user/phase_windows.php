<?php
// user/phase_windows.php — Dean sets own wing's phase windows
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['dean_men','dean_women']);

$gender = gender_filter();   // 'Male' or 'Female'
$sem    = active_semester();
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if (!$sem) {
    $_SESSION['flash'] = ['type'=>'danger','msg'=>'No active semester. Contact admin.'];
    redirect(APP_BASE.'/user/dashboard.php');
}

// ---- SAVE ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1s = trim($_POST['phase1_start'] ?? '');
    $p1e = trim($_POST['phase1_end']   ?? '');
    $p2s = trim($_POST['phase2_start'] ?? '');
    $p2e = trim($_POST['phase2_end']   ?? '');

    $toDb = fn($v) => $v === '' ? null : str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '');

    $p1s = $toDb($p1s); $p1e = $toDb($p1e);
    $p2s = $toDb($p2s); $p2e = $toDb($p2e);

    $errors = [];
    if ($p1s && $p1e && $p1s >= $p1e) $errors[] = 'Phase 1 end must be after start.';
    if ($p2s && $p2e && $p2s >= $p2e) $errors[] = 'Phase 2 end must be after start.';
    if ($p1e && $p2s && $p2s < $p1e)  $errors[] = 'Phase 2 must start after Phase 1 ends.';

    if (!$errors) {
        try {
            $pdo->prepare("
                INSERT INTO semester_windows
                    (semester_id, gender, phase1_start, phase1_end, phase2_start, phase2_end, updated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    phase1_start = VALUES(phase1_start),
                    phase1_end   = VALUES(phase1_end),
                    phase2_start = VALUES(phase2_start),
                    phase2_end   = VALUES(phase2_end),
                    updated_by   = VALUES(updated_by)
            ")->execute([(int)$sem['id'], $gender, $p1s, $p1e, $p2s, $p2e, $_SESSION['user_id']]);

            audit_log($_SESSION['user_id'], null, 'phase_windows_save',
                "Set $gender windows for {$sem['name']}");
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Phase windows saved.'];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Save failed: '.$e->getMessage()];
        }
    } else {
        $_SESSION['flash'] = ['type'=>'danger','msg'=>implode(' ', $errors)];
    }
    redirect(APP_BASE.'/user/phase_windows.php');
}

// ---- LOAD ----
$win = semester_window((int)$sem['id'], $gender);

function dt_local(?string $v): string {
    return $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
}

$currentPhase = current_phase($gender);
$phaseLabel = match($currentPhase) { 1=>'Phase 1 — Live', 2=>'Phase 2 — Live', default=>'Closed' };
$phaseColor = match($currentPhase) { 1=>'primary', 2=>'success', default=>'secondary' };
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1">
                        <i class="fas fa-clock text-primary me-2"></i>
                        Phase Windows — <?= h($gender) ?> Wing
                    </h4>
                    <p class="text-muted small mb-0">
                        Active semester: <strong><?= h($sem['name']) ?></strong>
                    </p>
                </div>
                <span class="badge bg-<?= $phaseColor ?> fs-6">
                    <i class="fas fa-circle me-1"></i><?= h($phaseLabel) ?>
                </span>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="alert alert-info d-flex align-items-start">
                <i class="fas fa-info-circle me-2 mt-1"></i>
                <div class="small">
                    You are setting the booking window for <strong><?= h($gender) ?></strong> students only.
                    The <?= $gender === 'Male' ? 'Dean of Women' : 'Dean of Men' ?>
                    manages the other wing separately.
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-sliders-h text-primary me-2"></i>Set Phase Dates
                            </h6>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <h6 class="text-primary mb-2">Phase 1 — Keep / Release</h6>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label small">Start</label>
                                        <input type="datetime-local" name="phase1_start" class="form-control"
                                               value="<?= h(dt_local($win['phase1_start'] ?? null)) ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">End</label>
                                        <input type="datetime-local" name="phase1_end" class="form-control"
                                               value="<?= h(dt_local($win['phase1_end'] ?? null)) ?>">
                                    </div>
                                </div>

                                <h6 class="text-success mb-2">Phase 2 — Move Window</h6>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label small">Start</label>
                                        <input type="datetime-local" name="phase2_start" class="form-control"
                                               value="<?= h(dt_local($win['phase2_start'] ?? null)) ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">End</label>
                                        <input type="datetime-local" name="phase2_end" class="form-control"
                                               value="<?= h(dt_local($win['phase2_end'] ?? null)) ?>">
                                    </div>
                                </div>

                                <hr>
                                <button class="btn btn-primary">
                                    <i class="fas fa-save me-1"></i>Save Windows
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header bg-white">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-info-circle text-info me-2"></i>
                                Current State
                            </h6>
                        </div>
                        <div class="card-body small">
                            <?php if ($win && $win['phase1_start']): ?>
                                <div class="mb-2">
                                    <strong>Phase 1:</strong><br>
                                    <?= date('M j, H:i', strtotime($win['phase1_start'])) ?>
                                    → <?= date('M j, H:i', strtotime($win['phase1_end'])) ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-2">Phase 1: not set</p>
                            <?php endif; ?>

                            <?php if ($win && $win['phase2_start']): ?>
                                <div class="mb-2">
                                    <strong>Phase 2:</strong><br>
                                    <?= date('M j, H:i', strtotime($win['phase2_start'])) ?>
                                    → <?= date('M j, H:i', strtotime($win['phase2_end'])) ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-2">Phase 2: not set</p>
                            <?php endif; ?>

                            <?php if ($win && $win['updated_at']): ?>
                                <hr>
                                <div class="text-muted">
                                    Last updated: <?= date('M j, Y H:i', strtotime($win['updated_at'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>