<?php
// includes/explore_ui.php — the 3D explorer screen (HUD = Bootstrap utilities only). Used by students and staff.
function explore_render(string $mode): void {
    $root = shell_root(); $canEdit = $mode === 'staff' && function_exists('is_admin') && (is_admin() || is_dean()); ?>
<div class="position-relative w-100 bg-info-subtle" id="stage" style="height:calc(100dvh - 56px)">
  <div id="gl" class="position-absolute top-0 start-0 w-100 h-100"></div>

  <div id="loading" class="position-absolute top-50 start-50 translate-middle text-center">
    <div class="spinner-border text-primary" role="status"></div><div class="small mt-2">Building the hostel…</div>
  </div>
  <div id="nogl" class="position-absolute top-50 start-50 translate-middle text-center d-none p-4 bg-body rounded-4 shadow">
    <i class="fas fa-triangle-exclamation text-warning fs-3"></i><p class="mb-2 mt-2">3D is not available on this device.</p>
    <a class="btn btn-primary" href="<?= $root ?><?= $mode === 'student' ? '/student/book.php' : '/user/rooms.php' ?>">Use the list view</a>
  </div>

  <!-- top-left: where am I -->
  <div class="position-absolute top-0 start-0 m-2 d-flex flex-column gap-2" style="max-width:70%">
    <div class="badge text-bg-dark text-start fs-6 fw-normal text-wrap" id="where"><i class="fas fa-location-dot me-1"></i>Campus</div>
    <div id="holdChip" class="badge text-bg-warning text-start fs-6 d-none"><i class="fas fa-hourglass-half me-1"></i><span id="holdTxt"></span></div>
  </div>

  <!-- top-right: controls -->
  <div class="position-absolute top-0 end-0 m-2 d-flex flex-column gap-2 align-items-end">
    <div class="btn-group shadow-sm" role="group" aria-label="View">
      <button class="btn btn-light btn-sm" id="bCampus" title="Campus view"><i class="fas fa-map"></i><span class="d-none d-md-inline ms-1">Campus</span></button>
      <button class="btn btn-light btn-sm" id="bPlan" title="Floor view"><i class="fas fa-layer-group"></i><span class="d-none d-md-inline ms-1">Floor view</span></button>
      <button class="btn btn-light btn-sm" id="bWalk" title="Walk"><i class="fas fa-person-walking"></i><span class="d-none d-md-inline ms-1">Walk</span></button>
    </div>
    <div class="btn-group-vertical shadow-sm d-none" id="floorBtns" role="group" aria-label="Floors"></div>
    <button class="btn btn-light btn-sm shadow-sm" id="bLegend" title="Colour guide"><i class="fas fa-circle-info"></i></button>
    <?php if ($canEdit): ?><a class="btn btn-warning btn-sm shadow-sm" id="bEdit" href="<?= $root ?>/user/layout_studio.php" title="Edit this building: move rooms, change shape, rearrange beds"><i class="fas fa-compass-drafting"></i><span class="d-none d-md-inline ms-1">Edit layout</span></a><?php endif; ?>
  </div>

  <!-- bottom: contextual prompts -->
  <div class="position-absolute bottom-0 start-50 translate-middle-x mb-5 mb-lg-3 pb-5 pb-lg-0 d-flex gap-2 flex-wrap justify-content-center" id="prompts" style="max-width:92%"></div>

  <!-- help line -->
  <div class="position-absolute bottom-0 end-0 m-2 mb-5 mb-lg-2 pb-5 pb-lg-0 small text-bg-dark bg-opacity-50 rounded px-2 py-1 d-none d-lg-block" id="help">Drag to look · W A S D / arrows to walk · Shift to run · click a bed to pick it</div>
</div>

<template id="legendTpl">
  <ul class="list-unstyled mb-0">
    <li class="d-flex align-items-center gap-2 mb-2"><span class="badge rounded-pill text-bg-success">&nbsp;</span>Vacant — all beds free</li>
    <li class="d-flex align-items-center gap-2 mb-2"><span class="badge rounded-pill" style="background:#84cc16">&nbsp;</span>Several beds free</li>
    <li class="d-flex align-items-center gap-2 mb-2"><span class="badge rounded-pill text-bg-warning">&nbsp;</span>Only 1–2 beds left / bed on hold</li>
    <li class="d-flex align-items-center gap-2 mb-2"><span class="badge rounded-pill text-bg-danger">&nbsp;</span>Full / bed taken</li>
    <li class="d-flex align-items-center gap-2 mb-2"><span class="badge rounded-pill text-bg-primary">&nbsp;</span>Your bed</li>
    <li class="small text-body-secondary mt-3">Bunks: <strong>lower</strong> and <strong>upper</strong> beds are separate. Tap a bed to pick it. Use the stairs to change floor and the entrance to go outside to another building.</li>
  </ul>
</template>

<script type="importmap">{"imports":{"three":"<?= $root ?>/assets/vendor/three/three.module.js","three/addons/utils/BufferGeometryUtils.js":"<?= $root ?>/assets/vendor/three/BufferGeometryUtils.js"}}</script>
<script type="module">
import { Hostel3D } from '<?= $root ?>/assets/js/scene3d.js';
const CAN_EDIT = <?= json_encode($canEdit) ?>, ROOT = <?= json_encode($root) ?>, MODE = <?= json_encode($mode) ?>;
const $ = id => document.getElementById(id), esc = window.appEsc;
const gl = $('gl'); let V;
try { const t = document.createElement('canvas'); if (!(t.getContext('webgl2') || t.getContext('webgl'))) throw 0; V = new Hostel3D(gl, { root: ROOT }); }
catch (e) { $('loading').classList.add('d-none'); $('nogl').classList.remove('d-none'); throw e; }
window.__V = V;

const kindName = { lower: 'Lower bunk', upper: 'Upper bunk', single: 'Single bed' };
const stName = s => ({ vacant: 'Vacant', available_p2: 'Vacant', reserved: 'On hold', occupied: 'Taken' }[s] || s);
const stBadge = b => b.mine ? 'primary' : ({ vacant: 'success', available_p2: 'success', reserved: 'warning', occupied: 'danger' }[b.status] || 'secondary');

function where() {
  const s = V.state; const eb = document.getElementById('bEdit'); if (eb) eb.href = ROOT + '/user/layout_studio.php' + (s.block !== null ? '?block=' + s.block : ''); let t = '<i class="fas fa-location-dot me-1"></i>';
  if (s.block === null) t += s.mode === 'walk' ? 'Walking on campus' : 'Campus';
  else { const B = V.blocks[s.block].data; t += esc(B.name) + ' · Floor ' + s.floor; if (s.room) t += ' · Room ' + esc(V.rooms[s.room].number); }
  $('where').innerHTML = t;
  const fb = $('floorBtns'), B = s.block !== null ? V.blocks[s.block] : null;
  fb.classList.toggle('d-none', !B); fb.innerHTML = '';
  if (B) for (let f = B.L.floors; f >= 1; f--) { const b = document.createElement('button'); b.className = 'btn btn-sm ' + (f === s.floor ? 'btn-primary' : 'btn-light'); b.textContent = f; b.setAttribute('aria-label', 'Floor ' + f); b.onclick = () => V.setFloor(f); fb.appendChild(b); }
  $('help').classList.toggle('d-none', s.mode !== 'walk' || window.innerWidth < 992);
}
V.addEventListener('mode', where);
V.addEventListener('prompts', e => {
  const box = $('prompts'); box.innerHTML = '';
  e.detail.forEach(p => { const b = document.createElement('button'); b.className = 'btn btn-warning btn-lg shadow fw-semibold'; b.innerHTML = '<i class="fas ' + p.icon + ' me-2"></i>' + esc(p.label); b.onclick = () => V.act(p.id); box.appendChild(b); });
});
$('bCampus').onclick = () => V.goCampus();
$('bPlan').onclick = () => { const id = V.state.block ?? Object.values(V.blocks).find(B => B.data.accessible)?.data.id; if (id) V.showFloor(id, V.state.floor || 1); };
$('bWalk').onclick = () => { if (V.state.block !== null) { V.walkIntoRoom(V.state.room || V.blocks[V.state.block].rooms.find(r => r.floor === V.state.floor)?.id); } else V.walkCampus(); };
$('bLegend').onclick = () => AppSheet.show({ title: 'Colour guide', node: $('legendTpl').content.cloneNode(true) });

/* ---------- hold timer ---------- */
let holdTick; function holdUI() {
  clearInterval(holdTick); const h = V.hold || {}, chip = $('holdChip');
  if (MODE !== 'student' || !h.expires) { chip.classList.add('d-none'); return; }
  const off = (V.data.now || 0) - Math.floor(Date.now() / 1000);
  const upd = () => { const left = h.expires - (Math.floor(Date.now() / 1000) + off); if (left <= 0) { chip.classList.add('d-none'); clearInterval(holdTick); return; } chip.classList.remove('d-none'); $('holdTxt').textContent = 'Bed on hold · enter your code in ' + Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0'); };
  upd(); holdTick = setInterval(upd, 1000);
}
V.addEventListener('state', holdUI);
document.addEventListener('cb:changed', () => { try { V._pollState(); } catch (e) {} });

/* ---------- picking ---------- */
V.addEventListener('pick', e => {
  const d = e.detail;
  if (d.type === 'block') {
    const s = d.stats, pct = s.total ? Math.round((s.total - s.free) / s.total * 100) : 0;
    AppSheet.show({ title: d.block.name, html: '<p class="mb-2"><span class="badge text-bg-' + (d.block.accessible ? 'success' : 'secondary') + '">' + (d.block.accessible ? 'Open to you' : 'Not your wing') + '</span> <span class="badge text-bg-light border">' + d.block.floors + ' floors</span></p>' +
      (d.block.accessible ? '<div class="progress mb-2" role="progressbar" style="height:10px"><div class="progress-bar" style="width:' + pct + '%"></div></div><p class="small text-body-secondary">' + s.free + ' of ' + s.total + ' beds are free.</p>' : '<p class="small text-body-secondary">You can see this building from outside. Only students of this wing can go inside.</p>'),
      actions: d.block.accessible ? [{ label: 'Walk inside', onClick: () => { AppSheet.hide(); V.enterBlock(d.block.id); } }, { label: 'Floor view', cls: 'btn-outline-primary', onClick: () => { AppSheet.hide(); V.showFloor(d.block.id, 1); } }].concat(CAN_EDIT ? [{ label: 'Edit layout', cls: 'btn-warning', onClick: () => { location.href = ROOT + '/user/layout_studio.php?block=' + d.block.id; } }] : []) : (CAN_EDIT ? [{ label: 'Edit layout', cls: 'btn-warning', onClick: () => { location.href = ROOT + '/user/layout_studio.php?block=' + d.block.id; } }] : []) });
  } else if (d.type === 'room') {
    const r = d.room, free = V.freeIn(r);
    let h = '<p class="small text-body-secondary mb-2">Floor ' + r.floor + ' · ' + r.beds.length + ' beds · <strong>' + free + ' free</strong>' + (r.bath === 'ensuite' ? ' · own bathroom' : '') + '</p><ul class="list-group">';
    r.beds.forEach(b => { h += '<li class="list-group-item d-flex justify-content-between align-items-center"><span>Bed ' + b.label + ' · ' + kindName[b.kind] + (b.who ? '<br><small class="text-body-secondary">' + esc(b.who) + '</small>' : '') + '</span><span class="badge text-bg-' + stBadge(b) + '">' + (b.mine ? 'Yours' : stName(b.status)) + '</span></li>'; });
    AppSheet.show({ title: 'Room ' + r.number, html: h + '</ul>', actions: [{ label: 'Walk into this room', onClick: () => { AppSheet.hide(); V.walkIntoRoom(r.id); } }] });
  } else if (d.type === 'bed') bedSheet(d.bed, d.room, d.block);
});

function bedSheet(b, r, blk) {
  V.selectBed(b.id); V.lookAtBed(b.id);
  const free = b.status === 'vacant' || b.status === 'available_p2';
  const info = '<p class="mb-1"><span class="badge text-bg-' + stBadge(b) + '">' + (b.mine ? 'Your bed' : stName(b.status)) + '</span></p>' +
    '<p class="mb-1">' + esc(blk.name) + ' · Room <strong>' + esc(r.number) + '</strong> · Floor ' + r.floor + '</p><p class="mb-1">Bed <strong>' + b.label + '</strong> — ' + kindName[b.kind] + '</p><p class="small text-body-secondary mb-0">' + V.freeIn(r) + ' of ' + r.beds.length + ' beds free in this room.' + (b.who ? '<br>' + esc(b.who) : '') + '</p>';
  const acts = [];
  if (MODE === 'student' && free) acts.push({ label: 'Book this bed', onClick: (btn) => book(b, r, blk, btn) });
  AppSheet.show({ title: 'Bed ' + b.label + ' · ' + kindName[b.kind], html: info, actions: acts });
  document.querySelectorAll('.offcanvas.show, .modal.show').forEach(el => el.addEventListener('hidden.bs.offcanvas', () => V.selectBed(null), { once: true }));
}

async function book(b, r, blk, btn) {
  btn.disabled = true;
  try {
    const res = await fetch(ROOT + '/api/book_room.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ phase: 2, action: 'book', bed_id: b.id }) });
    const j = await res.json();
    if (!j.ok) { btn.disabled = false; appToast(j.error || 'Could not book that bed.', 'danger'); V._pollState(); return; }
    V._pollState(); otpSheet(j);
  } catch (e) { btn.disabled = false; appToast('Network problem. Try again.', 'danger'); }
}
function otpSheet(j) {
  const n = document.createElement('div');
  n.innerHTML = '<p class="mb-2">Bed held: <strong>' + esc(j.bed) + '</strong></p><p class="small text-body-secondary">We sent a 6-digit code to your email. Enter it before the timer runs out to keep the bed.</p>' +
    '<label class="form-label" for="otpIn">Verification code</label><input id="otpIn" class="form-control form-control-lg text-center fs-3" inputmode="numeric" maxlength="6" autocomplete="one-time-code">' +
    '<div class="text-danger small mt-2 d-none" id="otpErr"></div>';
  AppSheet.show({ title: 'Confirm your booking', node: n, actions: [{ label: 'Verify & book', onClick: async (btn) => {
    const otp = n.querySelector('#otpIn').value.trim(); btn.disabled = true;
    const res = await fetch(ROOT + '/api/verify_otp.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ booking_id: j.booking_id, otp }) }); const k = await res.json();
    if (k.ok) { AppSheet.hide(); appToast('Verified! Waiting for approval.', 'success'); setTimeout(() => location.href = ROOT + '/student/book.php', 900); }
    else { btn.disabled = false; const e = n.querySelector('#otpErr'); e.textContent = k.error || 'Wrong code.'; e.classList.remove('d-none'); } } }] });
}

V.load().then(() => { $('loading').classList.add('d-none'); where(); holdUI(); }).catch(e => { $('loading').innerHTML = '<div class="text-danger">' + esc(e.message) + '</div>'; });
</script>
<?php }
