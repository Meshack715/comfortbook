<?php
// user/phase_windows.php — Dean sets own wing's phase windows
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['dean_men','dean_women']);

$gender = gender_filter();
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

// ---- Live countdown info ----
$liveEnd   = null;
$liveStart = null;
if ($currentPhase && $win) {
    $liveStart = $currentPhase === 1 ? $win['phase1_start'] : $win['phase2_start'];
    $liveEnd   = $currentPhase === 1 ? $win['phase1_end']   : $win['phase2_end'];
}
$remainingText = '';
if ($liveEnd) {
    $diff = strtotime($liveEnd) - time();
    if ($diff > 0) {
        $days  = floor($diff / 86400);
        $hours = floor(($diff % 86400) / 3600);
        $mins  = floor(($diff % 3600) / 60);
        if ($days > 0)       $remainingText = "$days day" . ($days===1?'':'s') . " $hours hr remaining";
        elseif ($hours > 0)  $remainingText = "$hours hr $mins min remaining";
        else                 $remainingText = "$mins min remaining";
    }
}
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

            <!-- Flash toast -->
            <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show d-flex align-items-center auto-dismiss">
                <i class="fas fa-<?= $flash['type']==='success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
                <div class="flex-grow-1"><?= h($flash['msg']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>

            <!-- Live phase banner (only when a phase is active) -->
            <?php if ($currentPhase): ?>
            <div class="alert alert-<?= $phaseColor ?> d-flex align-items-center">
                <i class="fas fa-broadcast-tower fa-2x me-3"></i>
                <div class="flex-grow-1">
                    <strong><?= h($phaseLabel) ?> — running now</strong>
                    <div class="small">
                        Started <?= date('M j, H:i', strtotime($liveStart)) ?>
                        · Ends <?= date('M j, H:i', strtotime($liveEnd)) ?>
                        <?php if ($remainingText): ?>
                            · <strong><?= h($remainingText) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
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
                        <div class="card-header bg-white d-flex justify-content-between align-items-center">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-sliders-h text-primary me-2"></i>Set Phase Dates
                            </h6>
                            <div class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary quick-start"
                                        data-what="p1" data-offset="0">
                                    <i class="fas fa-play me-1"></i>Start P1 now
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary quick-start"
                                        data-what="p1" data-offset="60">
                                    +1 hr
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <form method="post" id="phaseForm" novalidate>
                                <h6 class="text-primary mb-2">
                                    <i class="fas fa-door-open me-1"></i>Phase 1 — Keep / Release
                                </h6>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label small">Start</label>
                                        <input type="datetime-local" name="phase1_start" id="p1s" class="form-control"
                                               value="<?= h(dt_local($win['phase1_start'] ?? null)) ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">End</label>
                                        <input type="datetime-local" name="phase1_end" id="p1e" class="form-control"
                                               value="<?= h(dt_local($win['phase1_end'] ?? null)) ?>">
                                    </div>
                                </div>
                                <div class="form-text small mb-3" id="p1Hint"></div>

                                <h6 class="text-success mb-2">
                                    <i class="fas fa-door-closed me-1"></i>Phase 2 — Move Window
                                </h6>
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <label class="form-label small">Start</label>
                                        <input type="datetime-local" name="phase2_start" id="p2s" class="form-control"
                                               value="<?= h(dt_local($win['phase2_start'] ?? null)) ?>">
                                    </div>
                                    <div class="col-6">
                                        <label class="form-label small">End</label>
                                        <input type="datetime-local" name="phase2_end" id="p2e" class="form-control"
                                               value="<?= h(dt_local($win['phase2_end'] ?? null)) ?>">
                                    </div>
                                </div>
                                <div class="form-text small mb-3" id="p2Hint"></div>

                                <div id="formErrors" class="alert alert-danger d-none small mb-3"></div>

                                <hr>
                                <button class="btn btn-primary" id="saveBtn" type="submit">
                                    <span class="btn-label"><i class="fas fa-save me-1"></i>Save Windows</span>
                                    <span class="btn-spin d-none">
                                        <span class="spinner-border spinner-border-sm me-2"></span>Saving...
                                    </span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card border-0 shadow-sm sticky-top" style="top:80px;">
                        <div class="card-header bg-white">
                            <h6 class="mb-0 fw-bold">
                                <i class="fas fa-info-circle text-info me-2"></i>
                                Current Windows
                            </h6>
                        </div>
                        <div class="card-body small">

                            <?php if ($win && $win['phase1_start']): ?>
                                <div class="p-2 rounded mb-2" style="background:#eef4fb;border-left:3px solid #0d6efd;">
                                    <div class="text-uppercase small text-muted">Phase 1</div>
                                    <div class="fw-semibold">
                                        <?= date('M j, H:i', strtotime($win['phase1_start'])) ?>
                                        → <?= date('M j, H:i', strtotime($win['phase1_end'])) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="p-2 rounded mb-2 text-muted" style="background:#f8f9fa;">
                                    <i class="fas fa-hourglass me-1"></i>Phase 1 not set
                                </div>
                            <?php endif; ?>

                            <?php if ($win && $win['phase2_start']): ?>
                                <div class="p-2 rounded mb-2" style="background:#f0f8f4;border-left:3px solid #198754;">
                                    <div class="text-uppercase small text-muted">Phase 2</div>
                                    <div class="fw-semibold">
                                        <?= date('M j, H:i', strtotime($win['phase2_start'])) ?>
                                        → <?= date('M j, H:i', strtotime($win['phase2_end'])) ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="p-2 rounded mb-2 text-muted" style="background:#f8f9fa;">
                                    <i class="fas fa-hourglass me-1"></i>Phase 2 not set
                                </div>
                            <?php endif; ?>

                            <?php if ($win && $win['updated_at']): ?>
                                <hr>
                                <div class="text-muted small">
                                    <i class="fas fa-history me-1"></i>
                                    Last updated: <?= date('M j, Y H:i', strtotime($win['updated_at'])) ?>
                                </div>
                            <?php endif; ?>

                            <hr>
                            <div class="text-muted small">
                                <i class="fas fa-info-circle me-1"></i>
                                Times are in <strong>Africa/Nairobi (EAT)</strong>.
                                Students see the phase status on their booking page in real-time.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>

<script>
// ---------- Auto-dismiss flash ----------
document.querySelectorAll('.auto-dismiss').forEach(el => {
    setTimeout(() => bootstrap.Alert.getOrCreateInstance(el).close(), 4000);
});

// ---------- Reset spinners on back/forward (bfcache) ----------
window.addEventListener('pageshow', () => {
    const btn = document.getElementById('saveBtn');
    if (!btn) return;
    btn.querySelector('.btn-label')?.classList.remove('d-none');
    btn.querySelector('.btn-spin')?.classList.add('d-none');
    btn.disabled = false;
});

// ---------- Quick-start helpers ----------
function formatLocal(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

document.querySelectorAll('.quick-start').forEach(btn => {
    btn.addEventListener('click', () => {
        const offsetMin = parseInt(btn.dataset.offset || '0');
        const now = new Date(Date.now() + offsetMin * 60000);
        // Default: start now (or +N min), end +3 days for P1, +2 days for P2
        const endP1 = new Date(now.getTime() + 3 * 86400000);
        const p2Start = new Date(endP1.getTime() + 60000);      // 1 min after P1 ends
        const p2End = new Date(p2Start.getTime() + 2 * 86400000);

        document.getElementById('p1s').value = formatLocal(now);
        document.getElementById('p1e').value = formatLocal(endP1);
        document.getElementById('p2s').value = formatLocal(p2Start);
        document.getElementById('p2e').value = formatLocal(p2End);
        validate();
    });
});

// ---------- Live validation ----------
const p1s = document.getElementById('p1s');
const p1e = document.getElementById('p1e');
const p2s = document.getElementById('p2s');
const p2e = document.getElementById('p2e');
const errBox = document.getElementById('formErrors');

function val(el) { return el.value ? new Date(el.value).getTime() : 0; }

function validate() {
    const errs = [];
    if (val(p1s) && val(p1e) && val(p1s) >= val(p1e)) errs.push('Phase 1 end must be after start.');
    if (val(p2s) && val(p2e) && val(p2s) >= val(p2e)) errs.push('Phase 2 end must be after start.');
    if (val(p1e) && val(p2s) && val(p2s) < val(p1e))  errs.push('Phase 2 must start after Phase 1 ends.');

    if (errs.length) {
        errBox.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>' + errs.join('<br>');
        errBox.classList.remove('d-none');
    } else {
        errBox.classList.add('d-none');
    }

    // Hints
    const d1 = document.getElementById('p1Hint');
    if (val(p1s) && val(p1e)) {
        const days = ((val(p1e) - val(p1s)) / 86400000).toFixed(1);
        d1.textContent = `Phase 1 duration: ${days} day${days === '1.0' ? '' : 's'}`;
    } else d1.textContent = '';

    const d2 = document.getElementById('p2Hint');
    if (val(p2s) && val(p2e)) {
        const days = ((val(p2e) - val(p2s)) / 86400000).toFixed(1);
        d2.textContent = `Phase 2 duration: ${days} day${days === '1.0' ? '' : 's'}`;
    } else d2.textContent = '';

    return errs.length === 0;
}

[p1s, p1e, p2s, p2e].forEach(el => el?.addEventListener('change', validate));
validate();

// ---------- Save button spinner ----------
document.getElementById('phaseForm')?.addEventListener('submit', function (e) {
    if (!validate()) {
        e.preventDefault();
        window.scrollTo({ top: 0, behavior: 'smooth' });
        return;
    }

    const btn = document.getElementById('saveBtn');
    btn.querySelector('.btn-label').classList.add('d-none');
    btn.querySelector('.btn-spin').classList.remove('d-none');
    btn.disabled = true;
});
</script>