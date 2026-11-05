<?php
// user/phase_control.php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_role(['admin']);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- ACTIONS ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save custom dates
    if ($action === 'save_dates') {
        foreach (['phase1_start','phase1_end','phase2_start','phase2_end'] as $k) {
            $v = trim($_POST[$k] ?? '');
            if ($v !== '') {
                // Convert "2026-01-14T00:00" to "2026-01-14 00:00:00"
                $v = str_replace('T', ' ', $v);
                if (strlen($v) === 16) $v .= ':00';
                set_config($k, $v);
            }
        }
        $otp = (int)($_POST['otp_ttl_minutes'] ?? 10);
        $pen = (int)($_POST['penalty_amount'] ?? 200);
        set_config('otp_ttl_minutes', (string)max(1, $otp));
        set_config('penalty_amount',  (string)max(0, $pen));
        set_config('show_otp_in_ui', isset($_POST['show_otp_in_ui']) ? '1' : '0');
        audit_log($_SESSION['user_id'], null, 'config_save', 'Updated phase/config');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Configuration saved.'];
        redirect(APP_BASE . '/user/phase_control.php');
    }

    // Start Phase 1 now (3 days)
    if ($action === 'start_phase1') {
        set_config('phase1_start', date('Y-m-d H:i:s'));
        set_config('phase1_end',   date('Y-m-d 23:59:59', strtotime('+2 days')));
        set_config('phase2_start', date('Y-m-d 00:00:00', strtotime('+3 days')));
        set_config('phase2_end',   date('Y-m-d 23:59:59', strtotime('+5 days')));
        audit_log($_SESSION['user_id'], null, 'phase1_start', 'Phase 1 started');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Phase 1 started (3 days).'];
        redirect(APP_BASE . '/phase_control.php');
    }

    // Start Phase 2 now (3 days)
    if ($action === 'start_phase2') {
        set_config('phase2_start', date('Y-m-d H:i:s'));
        set_config('phase2_end',   date('Y-m-d 23:59:59', strtotime('+2 days')));
        audit_log($_SESSION['user_id'], null, 'phase2_start', 'Phase 2 started');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'Phase 2 started (3 days).'];
        redirect(APP_BASE . '/phase_control.php');
    }

    // Close all phases
    if ($action === 'close_all') {
        $past = date('Y-m-d H:i:s', strtotime('-1 day'));
        set_config('phase1_end', $past);
        set_config('phase2_end', $past);
        audit_log($_SESSION['user_id'], null, 'phases_close', 'All phases closed');
        $_SESSION['flash'] = ['type'=>'success','msg'=>'All phases closed.'];
        redirect(APP_BASE . '/phase_control.php');
    }

    // Reset demo — wipe bookings, reset rooms/students
    if ($action === 'reset_demo') {
        try {
            $pdo->beginTransaction();
            $pdo->exec("DELETE FROM bookings");
            $pdo->exec("DELETE FROM magic_links");
            $pdo->exec("UPDATE rooms SET status='vacant', reserved_by_student_id=NULL, reserved_at=NULL");
            $pdo->exec("UPDATE students SET phase1_action='none', placed=0, late_booker=0");
            $pdo->commit();
            audit_log($_SESSION['user_id'], null, 'demo_reset', 'Demo reset');
            $_SESSION['flash'] = ['type'=>'success','msg'=>'Demo reset — all bookings cleared.'];
        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['type'=>'danger','msg'=>'Reset failed: '.$e->getMessage()];
        }
        redirect(APP_BASE . '/user/phase_control.php');
    }
}

// ---------- STATS ----------
$stats = [
    'bookings_total'    => (int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn(),
    'bookings_pending'  => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending_approval'")->fetchColumn(),
    'bookings_confirmed'=> (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status='confirmed'")->fetchColumn(),
    'links_generated'   => (int)$pdo->query("SELECT COUNT(*) FROM magic_links")->fetchColumn(),
    'links_used'        => (int)$pdo->query("SELECT COUNT(*) FROM magic_links WHERE used_at IS NOT NULL")->fetchColumn(),
];

$cfg = [
    'phase1_start'      => get_config('phase1_start', ''),
    'phase1_end'        => get_config('phase1_end',   ''),
    'phase2_start'      => get_config('phase2_start', ''),
    'phase2_end'        => get_config('phase2_end',   ''),
    'otp_ttl_minutes'   => get_config('otp_ttl_minutes', '10'),
    'penalty_amount'    => get_config('penalty_amount', '200'),
    'show_otp_in_ui'    => get_config('show_otp_in_ui', '0'),
];

$phase = current_phase();
$phaseLabel = match($phase) {
    1 => 'Phase 1 — Live',
    2 => 'Phase 2 — Live',
    default => 'Closed',
};
$phaseColor = match($phase) { 1=>'primary', 2=>'success', default=>'secondary' };

// Format for datetime-local input
function dt_local(string $v): string {
    if (!$v) return '';
    return date('Y-m-d\TH:i', strtotime($v));
}
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content">
        <main class="container-fluid px-4">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3">
                <div>
                    <h4 class="mb-1"><i class="fas fa-clock text-primary me-2"></i>Phase Control</h4>
                    <p class="text-muted small mb-0">Manage booking windows, OTP, and demo state</p>
                </div>
                <span class="badge bg-<?= $phaseColor ?> fs-6">
                    <i class="fas fa-circle me-1"></i><?= h($phaseLabel) ?>
                </span>
            </div>

            <?php if ($flash): ?>
                <div class="alert alert-<?= h($flash['type']) ?> alert-dismissible fade show">
                    <?= h($flash['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Status card -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3 text-center">
                        <div class="col-6 col-md-3">
                            <div class="fs-3 fw-bold text-primary"><?= number_format($stats['links_generated']) ?></div>
                            <div class="small text-muted text-uppercase">Links Sent</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="fs-3 fw-bold text-info"><?= number_format($stats['links_used']) ?></div>
                            <div class="small text-muted text-uppercase">Links Used</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="fs-3 fw-bold text-warning"><?= number_format($stats['bookings_pending']) ?></div>
                            <div class="small text-muted text-uppercase">Pending</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <div class="fs-3 fw-bold text-success"><?= number_format($stats['bookings_confirmed']) ?></div>
                            <div class="small text-muted text-uppercase">Confirmed</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick actions -->
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-play text-primary me-2"></i>Quick Start</h6></div>
                        <div class="card-body">
                            <p class="small text-muted">Reset the timeline and open a phase immediately.</p>

                            <form method="post" class="d-inline" onsubmit="return confirm('Start Phase 1 now for 3 days?');">
                                <input type="hidden" name="action" value="start_phase1">
                                <button class="btn btn-primary btn-sm">
                                    <i class="fas fa-play me-1"></i>Start Phase 1 (3 days)
                                </button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Start Phase 2 now for 3 days?');">
                                <input type="hidden" name="action" value="start_phase2">
                                <button class="btn btn-success btn-sm">
                                    <i class="fas fa-forward me-1"></i>Start Phase 2 (3 days)
                                </button>
                            </form>
                            <form method="post" class="d-inline" onsubmit="return confirm('Close both phases now?');">
                                <input type="hidden" name="action" value="close_all">
                                <button class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-stop me-1"></i>Close All
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 shadow-sm h-100 border-danger border-opacity-25">
                        <div class="card-header bg-white"><h6 class="mb-0 fw-bold text-danger"><i class="fas fa-bomb me-2"></i>Danger Zone</h6></div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">
                                Wipes bookings, magic links, resets all rooms to vacant, and clears student actions.
                                Users and inventory stay intact. <strong>Use before a fresh demo.</strong>
                            </p>
                            <form method="post" onsubmit="return confirm('This deletes ALL bookings and resets rooms. Continue?');">
                                <input type="hidden" name="action" value="reset_demo">
                                <button class="btn btn-danger btn-sm">
                                    <i class="fas fa-trash-restore me-1"></i>Reset Demo
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Config form -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white"><h6 class="mb-0 fw-bold"><i class="fas fa-sliders-h text-primary me-2"></i>Configure Timeline</h6></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="save_dates">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Phase 1 Start</label>
                                <input type="datetime-local" name="phase1_start" class="form-control"
                                       value="<?= h(dt_local($cfg['phase1_start'])) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phase 1 End</label>
                                <input type="datetime-local" name="phase1_end" class="form-control"
                                       value="<?= h(dt_local($cfg['phase1_end'])) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phase 2 Start</label>
                                <input type="datetime-local" name="phase2_start" class="form-control"
                                       value="<?= h(dt_local($cfg['phase2_start'])) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Phase 2 End</label>
                                <input type="datetime-local" name="phase2_end" class="form-control"
                                       value="<?= h(dt_local($cfg['phase2_end'])) ?>">
                            </div>
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
                                    <input class="form-check-input" type="checkbox" name="show_otp_in_ui" id="showOtp"
                                           <?= $cfg['show_otp_in_ui'] === '1' ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="showOtp">Show OTP in UI (demo fallback)</label>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <button class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Configuration</button>
                    </form>
                </div>
            </div>

            <div class="pb-4"></div>
        </main>
<?php include __DIR__ . '/partial/footer.php'; ?>