<?php
// user/collect_payment.php — staff prompt a student for the late fee (no email link needed), and anyone on staff can
// send a KES 1 test to prove M-Pesa works end to end. Admin, deans and secretaries. Bootstrap only.
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payments_staff.php';
require_role(['admin', 'dean_men', 'dean_women', 'secretary_men', 'secretary_women']);
mpesa_ensure_schema($pdo);
$isAdmin = is_admin(); $canClose = !is_secretary(); $fee = (int)get_config('penalty_amount', '200');
$wing = gender_filter();
$prefill = trim((string)($_GET['q'] ?? ''));
$myPhone = ''; try { $q = $pdo->prepare("SELECT phone FROM users WHERE id=?"); $q->execute([$_SESSION['user_id']]); $myPhone = (string)$q->fetchColumn(); } catch (Throwable $e) { /* users.phone may not exist */ }
?>
<?php include __DIR__ . '/partial/header.php'; ?>
<div id="layoutSidenav" class="d-lg-flex">
    <?php include __DIR__ . '/partial/sidebar.php'; ?>
    <div id="layoutSidenav_content" class="col-lg-9 col-xl-10">
        <main class="container-fluid px-3 px-lg-4 pb-5 pb-lg-0">

            <div class="d-flex flex-wrap justify-content-between align-items-center mt-4 mb-3 gap-2">
                <div>
                    <h4 class="mb-1"><i class="fas fa-mobile-screen-button text-success me-2"></i>Collect payment</h4>
                    <p class="text-muted small mb-0"><?= $wing ? h($wing) . ' wing · ' : 'All wings · ' ?>Late fee KES <?= number_format($fee) ?> · the student gets an M-Pesa prompt on their phone</p>
                </div>
            </div>

            <div id="health" class="mb-3"></div>

            <div class="row g-3">
                <div class="col-xl-7">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-1">1. Find the student</h2>
                            <p class="small text-body-secondary">Type a registration number, email or name. The student does not need their email link.</p>
                            <form id="findForm" class="input-group mb-2" role="search">
                                <label class="visually-hidden" for="findQ">Registration number, email or name</label>
                                <input id="findQ" class="form-control" placeholder="e.g. SKIADA2411" autocomplete="off" value="<?= h($prefill) ?>">
                                <button class="btn btn-primary" id="findBtn"><i class="fas fa-search me-1"></i>Find</button>
                            </form>
                            <div id="found" class="list-group mb-3" aria-live="polite"></div>

                            <div id="pick" hidden>
                                <hr>
                                <h2 class="h6 mb-2">2. Send the prompt</h2>
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <div class="lh-sm"><div class="fw-bold" id="pName"></div><div class="small text-body-secondary" id="pMeta"></div></div>
                                    <div class="text-end"><div class="fs-4 fw-bold text-success" id="pAmt"></div><div class="small text-body-secondary">late fee</div></div>
                                </div>
                                <div class="d-flex flex-wrap gap-1 my-2" id="pBadges"></div>
                                <div class="alert alert-secondary py-2 small mb-3" id="pReason"></div>
                                <label class="form-label fw-semibold" for="pPhone">M-Pesa number to prompt</label>
                                <div class="input-group input-group-lg mb-1">
                                    <span class="input-group-text">+254</span>
                                    <input type="tel" inputmode="tel" id="pPhone" class="form-control" placeholder="712 345 678">
                                </div>
                                <div class="form-text mb-3">The student must have this phone with them. They enter their M-Pesa PIN to pay.</div>
                                <div class="d-flex flex-wrap gap-2">
                                    <button class="btn btn-success btn-lg flex-grow-1" id="pSend"><i class="fas fa-paper-plane me-2"></i><span>Send M-Pesa prompt</span></button>
                                    <button class="btn btn-outline-secondary" id="pMark" type="button" hidden></button>
                                </div>
                                <div id="pLive" class="mt-3" hidden></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-5">
                    <div class="card border-0 shadow-sm mb-3">
                        <div class="card-body">
                            <h2 class="h6 mb-1"><i class="fas fa-vial text-primary me-1"></i>Test that M-Pesa works</h2>
                            <p class="small text-body-secondary">Sends a prompt of <strong>KES 1</strong> to the phone you enter (use your own). Pay it to prove the whole chain works: the prompt arrives, the payment is received and the system records it.</p>
                            <label class="form-label fw-semibold" for="tPhone">Your M-Pesa number</label>
                            <div class="input-group mb-2"><span class="input-group-text">+254</span><input type="tel" inputmode="tel" id="tPhone" class="form-control" placeholder="712 345 678" value="<?= h($myPhone) ?>"></div>
                            <button class="btn btn-primary w-100" id="tSend"><i class="fas fa-paper-plane me-2"></i>Send KES 1 test</button>
                            <div id="tLive" class="mt-3" hidden></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-warning shadow-sm mb-3" id="reviewCard" hidden>
                <div class="card-header bg-warning-subtle fw-semibold"><i class="fas fa-triangle-exclamation me-1"></i>Payments that need a person to look at them</div>
                <ul class="list-group list-group-flush" id="reviewList"></ul>
            </div>

            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-body d-flex justify-content-between align-items-center"><span class="fw-semibold">Recent payments</span><button class="btn btn-sm btn-outline-secondary" id="refreshBtn"><i class="fas fa-rotate"></i></button></div>
                <div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>When</th><th>Who</th><th class="text-end">KES</th><th>Result</th><th>Receipt</th></tr></thead>
                    <tbody id="recentBody"><tr><td colspan="5" class="text-center text-body-secondary py-3">Loading…</td></tr></tbody>
                </table></div>
            </div>
        </main>
<script>
(function () {
    const root = <?= json_encode(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\')) ?>, CAN_CLOSE = <?= json_encode($canClose) ?>;
    const $ = s => document.querySelector(s);
    const esc = t => String(t == null ? '' : t).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const api = (o, post) => fetch(root + '/api/staff_payment.php' + (post ? '' : '?' + new URLSearchParams(o)), {
        method: post ? 'POST' : 'GET', credentials: 'same-origin', headers: post ? { 'Content-Type': 'application/json', 'X-Requested-With': 'cb' } : {}, body: post ? JSON.stringify(o) : undefined
    }).then(r => r.json()).catch(() => ({ ok: false, message: 'Could not reach the system. Check the internet connection and try again.', offline: true }));
    const TONE = { success: 'success', warning: 'warning', danger: 'danger', info: 'info' };

    /* ---- live result of one prompt: polls until it ends, never gives up silently ---- */
    function watch(box, id, done) {
        let stop = false, n = 0, offline = 0;
        const draw = v => {
            const t = TONE[v.tone] || 'info', ico = { success: 'fa-circle-check', warning: 'fa-circle-exclamation', danger: 'fa-circle-xmark', info: 'fa-spinner fa-spin' }[t];
            box.hidden = false;
            box.innerHTML = '<div class="alert alert-' + t + ' mb-0" role="status" aria-live="polite"><div class="d-flex gap-2"><i class="fas ' + ico + ' mt-1"></i><div class="flex-grow-1"><div class="fw-semibold">' + esc(v.title) + '</div><div class="small">' + esc(v.message) + '</div>' +
                (v.status === 'pending' ? '<div class="small mt-2"><button type="button" class="btn btn-sm btn-outline-dark" data-stop>Stop waiting</button></div>' : '') + '</div></div></div>';
            box.querySelector('[data-stop]')?.addEventListener('click', async () => { stop = true; const r = await api({ action: 'stop', id }, true); if (r.title) draw(r); done && done(r); });
        };
        const tick = async () => {
            if (stop) return; const v = await api({ action: 'status', id });
            if (v.offline) { offline++; box.hidden = false; box.innerHTML = '<div class="alert alert-secondary mb-0">Connection problem. Still checking the payment…</div>'; }
            else if (v.ok === false) { draw({ tone: 'warning', title: 'Could not check', message: v.message || 'Try again in a moment.', status: 'x' }); return; }
            else { offline = 0; draw(v); if (v.final) { done && done(v); return; } }
            if (++n > 120) { draw({ tone: 'warning', title: 'Still waiting', message: 'The result has not come back yet. If the student paid, it will update by itself. Check Recent payments in a minute.', status: 'x' }); return; }
            setTimeout(tick, offline ? 5000 : 3000);
        };
        tick(); return () => { stop = true; };
    }

    /* ---- recent payments + needs attention + health ---- */
    const badge = s => '<span class="badge text-bg-' + ({ success: 'success', pending: 'info', review: 'warning', failed: 'danger', cancelled: 'secondary', timeout: 'secondary' }[s] || 'secondary') + '">' + ({ success: 'Paid', pending: 'Waiting', review: 'Needs attention', failed: 'Failed', cancelled: 'Cancelled', timeout: 'No answer' }[s] || s) + '</span>';
    const health = h => { if (!h) return; $('#health').innerHTML = '<div class="alert alert-' + (h.ready ? 'success' : 'warning') + ' py-2 small mb-0"><ul class="list-unstyled mb-0">' + h.items.map(i => '<li><i class="fas ' + (i.ok ? 'fa-circle-check text-success' : 'fa-triangle-exclamation text-warning') + ' me-2"></i>' + esc(i.text) + '</li>').join('') + '</ul></div>'; };
    async function refresh() {
        const r = await api({ action: 'recent' }); if (!r.ok) return; health(r.health);
        $('#recentBody').innerHTML = r.recent.length ? r.recent.map(p => '<tr><td class="small text-nowrap">' + esc(p.at.slice(5, 16)) + '</td><td class="small">' + esc(p.who) + (p.demo ? ' <span class="badge text-bg-light border">demo</span>' : '') + '</td><td class="text-end">' + p.amount + '</td><td>' + badge(p.status) + '<div class="small text-body-secondary">' + esc(p.status === 'success' ? '' : p.message) + '</div></td><td class="small font-monospace">' + esc(p.receipt || '') + '</td></tr>').join('')
            : '<tr><td colspan="5" class="text-center text-body-secondary py-3">No payments yet.</td></tr>';
        $('#reviewCard').hidden = !r.review.length;
        $('#reviewList').innerHTML = r.review.map(p => '<li class="list-group-item"><div class="d-flex justify-content-between gap-2"><div><div class="fw-semibold">' + esc(p.who) + ' · KES ' + p.amount + '</div><div class="small">' + esc(p.note || p.message) + '</div><div class="small text-body-secondary font-monospace">' + esc(p.receipt || '') + '</div></div>' +
            (CAN_CLOSE ? '<button class="btn btn-sm btn-outline-success align-self-start" data-resolve="' + p.id + '">Mark handled</button>' : '') + '</div></li>').join('');
    }
    document.addEventListener('click', async e => { const b = e.target.closest('[data-resolve]'); if (!b) return; const note = prompt('What was done about it? (optional)') ; if (note === null) return; b.disabled = true; const r = await api({ action: 'resolve', id: b.dataset.resolve, note }, true); if (!r.ok) alert(r.message); refresh(); });
    $('#refreshBtn').addEventListener('click', refresh);

    /* ---- find a student ---- */
    let cur = null, stopWatch = null;
    async function find(q) {
        const box = $('#found'); if (q.trim().length < 2) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="list-group-item text-body-secondary"><span class="spinner-border spinner-border-sm me-2"></span>Searching…</div>';
        const r = await api({ action: 'lookup', q });
        if (!r.ok) { box.innerHTML = '<div class="list-group-item text-danger">' + esc(r.message || 'Search failed.') + '</div>'; return; }
        health(r.health);
        if (!r.students.length) { box.innerHTML = '<div class="list-group-item text-body-secondary">No student matches that. Check the registration number or email.</div>'; return; }
        box.innerHTML = r.students.map((s, i) => '<button type="button" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-2" data-i="' + i + '"><span><span class="fw-semibold">' + esc(s.name) + '</span><br><small class="text-body-secondary">' + esc(s.reg) + ' · ' + esc(s.wing) + '</small></span><span>' +
            (s.paid ? '<span class="badge text-bg-success">Fee paid</span>' : s.due ? '<span class="badge text-bg-warning">Owes KES ' + s.amount + '</span>' : '<span class="badge text-bg-light border">No fee due</span>') + '</span></button>').join('');
        box.querySelectorAll('[data-i]').forEach(b => b.addEventListener('click', () => choose(r.students[+b.dataset.i])));
        if (r.students.length === 1) choose(r.students[0]);
    }
    function choose(s) {
        cur = s; stopWatch && stopWatch(); $('#pLive').hidden = true; $('#pick').hidden = false;
        $('#pName').textContent = s.name; $('#pMeta').textContent = s.reg + ' · ' + s.email + (s.booking ? ' · booking: ' + s.booking : ''); $('#pAmt').textContent = 'KES ' + s.amount;
        $('#pBadges').innerHTML = (s.active ? '' : '<span class="badge text-bg-danger">Account not active</span>') + (s.late_booker ? '<span class="badge text-bg-warning">Late booker</span>' : '') + (s.paid ? '<span class="badge text-bg-success">Fee paid</span>' : '');
        $('#pReason').textContent = s.reason; $('#pReason').className = 'alert py-2 small mb-3 alert-' + (s.paid ? 'success' : s.due ? 'warning' : 'secondary');
        $('#pPhone').value = s.phone ? s.phone.replace(/^\+?254/, '0') : '';
        const can = s.active && !s.paid && (s.due || s.can_mark);
        $('#pSend').disabled = !can; $('#pSend span').textContent = !can ? (s.paid ? 'Fee already paid' : 'Nothing to collect') : (s.due ? 'Send M-Pesa prompt' : 'Mark as late booker & send prompt');
        $('#pMark').hidden = true;
        if (s.due && !s.paid) { $('#pMark').hidden = !s.late_booker; $('#pMark').textContent = 'Not a late booker: clear flag'; }
        $('#pick').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    $('#findForm').addEventListener('submit', e => { e.preventDefault(); find($('#findQ').value); });
    let dt; $('#findQ').addEventListener('input', () => { clearTimeout(dt); dt = setTimeout(() => find($('#findQ').value), 450); });
    $('#pMark').addEventListener('click', async () => { if (!cur || !confirm('Clear the late-booker flag for ' + cur.name + '?')) return; const r = await api({ action: 'mark_late', student_id: cur.id, value: 0 }, true); alert(r.message || 'Done.'); find($('#findQ').value); });

    /* ---- send ---- */
    async function send(btn, fn, live, onDone) {
        btn.disabled = true; const lbl = btn.innerHTML; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending…';
        const r = await fn(); btn.innerHTML = lbl; btn.disabled = false;
        if (!r.payment_id) { live.hidden = false; live.innerHTML = '<div class="alert alert-danger mb-0" role="alert"><i class="fas fa-circle-xmark me-2"></i>' + esc(r.message || 'Could not send the prompt.') + '</div>'; return; }
        if (!r.ok) { live.hidden = false; live.innerHTML = '<div class="alert alert-warning mb-0" role="alert">' + esc(r.message) + '</div>'; }
        btn.disabled = true; const stop = watch(live, r.payment_id, v => { btn.disabled = false; refresh(); onDone && onDone(v); }); return stop;
    }
    $('#pSend').addEventListener('click', async () => {
        if (!cur) return; const m = cur.due ? '' : 'This marks ' + cur.name + ' as a late booker and prompts KES ' + cur.amount + '. Continue?'; if (m && !confirm(m)) return;
        stopWatch = await send($('#pSend'), () => api({ action: 'send', student_id: cur.id, phone: $('#pPhone').value }, true), $('#pLive'), v => { if (v.status === 'success') find($('#findQ').value); });
    });
    $('#tSend').addEventListener('click', async () => { localStorage.setItem('cb_test_phone', $('#tPhone').value); await send($('#tSend'), () => api({ action: 'test', phone: $('#tPhone').value }, true), $('#tLive')); });
    if (!$('#tPhone').value) $('#tPhone').value = localStorage.getItem('cb_test_phone') || '';
    refresh(); setInterval(() => { if (!document.hidden) refresh(); }, 30000);
    if ($('#findQ').value) find($('#findQ').value);
})();
</script>
<?php include __DIR__ . '/partial/footer.php'; ?>
