// studio.js — Layout Studio. Bootstrap UI + an SVG plan drawn with the same computeLayout() the 3D viewer uses.
import { computeLayout, FAC, BED_W, BED_L } from './layout.js';
const ROOT = window.STUDIO_ROOT || '', $ = id => document.getElementById(id), esc = s => window.appEsc(s);
const NS = 'http://www.w3.org/2000/svg';
const S = { data: null, layout: null, rooms: [], del: [], floor: 1, dirty: false, vb: null, nextRoom: -1 };
const api = (body, q = '') => fetch(ROOT + '/api/layout_studio.php' + q, { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined }).then(r => r.json());
const el = (n, a = {}, p) => { const e = document.createElementNS(NS, n); for (const k in a) e.setAttribute(k, a[k]); if (p) p.appendChild(e); return e; };
const msg = (t, c = 'danger') => { $('stMsg').innerHTML = t ? '<div class="alert alert-' + c + ' alert-dismissible fade show">' + esc(t) + '<button class="btn-close" data-bs-dismiss="alert"></button></div>' : ''; };
const dirty = () => { S.dirty = true; $('stSave').disabled = false; };

function ingest(d) {
  S.data = d; S.layout = d.layout || { floors: d.block.floors, wings: [{ key: 'W1', name: 'Main wing', x: 0, z: 0, angle: 0, bend: 0, corridor: 2.2, attach: null }], facilities: [] };
  S.layout.floors = S.layout.floors || d.block.floors || 1;
  S.rooms = d.rooms.map(r => { const pos = {}; let si = 0; r.beds.forEach(b => { const k = b.kind === 'single' ? 's' + (++si) : 'b' + b.bunk; if (b.x !== null && b.z !== null && !pos[k]) pos[k] = { x: b.x, z: b.z, rot: b.rot || 0 }; }); return { ...r, pos }; });
  S.del = []; S.dirty = false; S.vb = null; $('stSave').disabled = true; $('st3d').href = ROOT + '/user/map3d.php?block=' + d.block.id;
  if (S.floor > S.layout.floors) S.floor = 1; $('stFloorCount').value = S.layout.floors; renderAll();
}
const layoutRooms = () => S.rooms.map(r => ({ id: r.id, floor: r.floor, wing: r.wing, side: r.side, slot: r.slot, w: r.w, d: r.d, number: r.number, beds: r.beds, bunks: r.bunks, singles: r.singles, bath: r.bath }));

/* ---------------- plan ---------------- */
function renderPlan() {
  const svg = $('stSvg'); svg.innerHTML = '';
  const L = computeLayout(S.layout, layoutRooms()); S.L = L;
  const cells = L.cells.filter(c => !c.skip && c.floor === S.floor);
  let x0 = 1e9, x1 = -1e9, z0 = 1e9, z1 = -1e9;
  const grow = (x, z, r) => { x0 = Math.min(x0, x - r); x1 = Math.max(x1, x + r); z0 = Math.min(z0, z - r); z1 = Math.max(z1, z + r); };
  L.corridor.forEach(c => grow(c.cx, c.cz, c.len)); cells.forEach(c => grow(c.cx, c.cz, Math.max(c.w, c.d) / 2 + 1));
  if (x0 > x1) { x0 = -10; x1 = 10; z0 = -10; z1 = 10; }
  if (!S.vb) S.vb = { x: x0 - 2, y: z0 - 2, w: x1 - x0 + 4, h: z1 - z0 + 4 };
  setVB(); const g = el('g', {}, svg);
  L.corridor.forEach(c => el('rect', { x: -c.len / 2, y: -c.w / 2, width: c.len, height: c.w, fill: '#e2e8f0', transform: `translate(${c.cx} ${c.cz}) rotate(${-c.rot * 180 / Math.PI})` }, g));
  cells.forEach(c => {
    const gg = el('g', { transform: `translate(${c.cx} ${c.cz}) rotate(${-c.rot * 180 / Math.PI})`, style: 'cursor:pointer', 'data-cell': c.id }, g);
    const isRoom = c.kind === 'room', col = isRoom ? '#ffffff' : '#' + (FAC[c.type] || FAC.store).color.toString(16).padStart(6, '0');
    el('rect', { x: -c.w / 2 + .05, y: -c.d / 2 + .05, width: c.w - .1, height: c.d - .1, fill: col, stroke: '#334155', 'stroke-width': .1, rx: .15 }, gg);
    const t = el('text', { 'text-anchor': 'middle', 'font-size': isRoom ? .9 : .75, y: .3, fill: '#0f172a', transform: `rotate(${c.rot * 180 / Math.PI})`, 'pointer-events': 'none' }, gg);
    t.textContent = isRoom ? c.room.number.replace(/^[A-Za-z]+-/, '') : (FAC[c.type] || {}).label || c.type;
    if (isRoom) { const n = c.room.bunks * 2 + c.room.singles; const t2 = el('text', { 'text-anchor': 'middle', 'font-size': .6, y: 1.2, fill: '#64748b', transform: `rotate(${c.rot * 180 / Math.PI})`, 'pointer-events': 'none' }, gg); t2.textContent = n + ' beds' + (c.room.bath === 'ensuite' ? ' · bath' : ''); }
    gg.addEventListener('click', () => { if (S.moved) return; isRoom ? roomForm(S.rooms.find(r => 'r' + r.id === c.id)) : facForm(S.layout.facilities.find(f => f.id === c.fid)); });
  });
  const fb = $('stFloors'); fb.innerHTML = '';
  for (let f = S.layout.floors; f >= 1; f--) { const b = document.createElement('button'); b.type = 'button'; b.className = 'btn btn-outline-dark' + (f === S.floor ? ' active' : ''); b.textContent = 'F' + f; b.onclick = () => { S.floor = f; renderPlan(); }; fb.appendChild(b); }
}
function setVB() { const v = S.vb; $('stSvg').setAttribute('viewBox', `${v.x} ${v.y} ${v.w} ${v.h}`); }
(function pan() {
  const svg = $('stSvg'); let drag = null; const pts = new Map(); let pinch = 0;
  const toWorld = (e) => { const r = svg.getBoundingClientRect(), v = S.vb, k = Math.max(v.w / r.width, v.h / r.height); return { k, r }; };
  svg.addEventListener('pointerdown', e => { svg.setPointerCapture(e.pointerId); pts.set(e.pointerId, e); S.moved = false; drag = { x: e.clientX, y: e.clientY, vb: { ...S.vb } }; });
  svg.addEventListener('pointermove', e => {
    if (!pts.has(e.pointerId)) return; pts.set(e.pointerId, e);
    if (pts.size === 2) { const [a, b] = [...pts.values()], d = Math.hypot(a.clientX - b.clientX, a.clientY - b.clientY); if (pinch) zoom(pinch / d, (a.clientX + b.clientX) / 2, (a.clientY + b.clientY) / 2); pinch = d; S.moved = true; return; }
    if (!drag) return; const { k } = toWorld(e), dx = e.clientX - drag.x, dy = e.clientY - drag.y; if (Math.abs(dx) + Math.abs(dy) > 6) S.moved = true;
    if (S.moved) { S.vb.x = drag.vb.x - dx * k; S.vb.y = drag.vb.y - dy * k; setVB(); }
  });
  const up = e => { pts.delete(e.pointerId); pinch = 0; drag = null; setTimeout(() => S.moved = false, 50); };
  svg.addEventListener('pointerup', up); svg.addEventListener('pointercancel', up);
  function zoom(f, cx, cy) { const r = svg.getBoundingClientRect(), v = S.vb, k = Math.max(v.w / r.width, v.h / r.height); const px = v.x + (cx - r.left) * k, py = v.y + (cy - r.top) * k; v.w *= f; v.h *= f; const k2 = Math.max(v.w / r.width, v.h / r.height); v.x = px - (cx - r.left) * k2; v.y = py - (cy - r.top) * k2; setVB(); }
  svg.addEventListener('wheel', e => { e.preventDefault(); zoom(e.deltaY > 0 ? 1.12 : .89, e.clientX, e.clientY); }, { passive: false });
})();

/* ---------------- forms ---------------- */
const field = (id, label, val, type = 'text', extra = '') => `<div class="col-6"><label class="form-label small mb-1" for="${id}">${label}</label><input id="${id}" type="${type}" class="form-control" value="${esc(val ?? '')}" ${extra}></div>`;
const sel = (id, label, opts, val) => `<div class="col-6"><label class="form-label small mb-1" for="${id}">${label}</label><select id="${id}" class="form-select">${opts.map(([v, t]) => `<option value="${esc(v)}" ${String(v) === String(val) ? 'selected' : ''}>${esc(t)}</option>`).join('')}</select></div>`;
const wingOpts = () => S.layout.wings.map(w => [w.key, w.name || w.key]);
const floorOpts = (all) => (all ? [['all', 'Every floor']] : []).concat(Array.from({ length: S.layout.floors }, (_, i) => [i + 1, 'Floor ' + (i + 1)]));
const v = id => $(id).value;

function roomForm(r) {
  const isNew = !r; r = r || { id: S.nextRoom--, number: '', floor: S.floor, wing: S.layout.wings[0].key, side: 'A', slot: 0, w: 4, d: 5, bunks: 2, singles: 0, bath: 'none', beds: [], pos: {} };
  const n = document.createElement('div'); n.className = 'row g-2';
  n.innerHTML = field('rfNum', 'Room number', r.number) + sel('rfFloor', 'Floor', floorOpts(), r.floor) + sel('rfWing', 'Wing', wingOpts(), r.wing) + sel('rfSide', 'Side of corridor', [['A', 'Side A'], ['B', 'Side B']], r.side)
    + field('rfSlot', 'Position (slot)', r.slot, 'number', 'min="0"') + sel('rfBath', 'Bathroom', [['none', 'None (shared on floor)'], ['ensuite', 'Inside room']], r.bath)
    + field('rfW', 'Width (m)', r.w, 'number', 'step="0.1" min="2.4" max="9"') + field('rfD', 'Depth (m)', r.d, 'number', 'step="0.1" min="2.8" max="9"')
    + field('rfBunks', 'Double-decker bunks', r.bunks, 'number', 'min="0" max="4"') + field('rfSingles', 'Single beds', r.singles, 'number', 'min="0" max="6"')
    + '<div class="col-12 d-flex gap-2"><button type="button" class="btn btn-sm btn-outline-secondary" id="rfL">◀ Move earlier</button><button type="button" class="btn btn-sm btn-outline-secondary" id="rfR">Move later ▶</button><button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="rfBeds"><i class="fas fa-bed me-1"></i>Arrange beds</button></div>';
  const read = () => Object.assign(r, { number: v('rfNum').trim(), floor: +v('rfFloor'), wing: v('rfWing'), side: v('rfSide'), slot: Math.max(0, +v('rfSlot') || 0), bath: v('rfBath'), w: +v('rfW') || 4, d: +v('rfD') || 5, bunks: Math.max(0, +v('rfBunks') || 0), singles: Math.max(0, +v('rfSingles') || 0) });
  const b0 = r.bunks + ':' + r.singles + ':' + r.w + ':' + r.d; const commit = () => { read(); if (!r.number) return appToast('Give the room a number.', 'warning'); if (r.bunks * 2 + r.singles < 1) return appToast('A room needs at least one bed.', 'warning'); if (S.rooms.some(x => x !== r && x.number === r.number)) return appToast('That room number already exists.', 'warning'); if (b0 !== r.bunks + ':' + r.singles + ':' + r.w + ':' + r.d) r.pos = {}; if (isNew && !S.rooms.includes(r)) { S.rooms.push(r); } S.floor = r.floor; dirty(); AppSheet.hide(); S.vb = null; renderAll(); };
  const slotMove = dir => { read(); const mates = S.rooms.filter(x => x !== r && x.floor === r.floor && x.wing === r.wing && x.side === r.side).sort((a, b) => a.slot - b.slot); const nb = dir < 0 ? [...mates].reverse().find(x => x.slot <= r.slot) : mates.find(x => x.slot >= r.slot); if (!nb) { r.slot = Math.max(0, r.slot + dir); } else { const t = nb.slot; if (t === r.slot) r.slot += dir; else { nb.slot = r.slot; r.slot = t; } } $('rfSlot').value = r.slot; dirty(); renderPlan(); };
  const actions = [{ label: isNew ? 'Add room' : 'Done', onClick: commit }];
  if (!isNew) actions.unshift({ label: 'Delete', cls: 'btn-outline-danger', onClick: () => { if (!confirm('Delete room ' + r.number + '?')) return; S.rooms = S.rooms.filter(x => x !== r); if (r.id > 0) S.del.push(r.id); dirty(); AppSheet.hide(); renderAll(); } });
  AppSheet.show({ title: isNew ? 'New room' : 'Room ' + r.number, node: n, actions });
  $('rfL').onclick = () => slotMove(-1); $('rfR').onclick = () => slotMove(1); $('rfBeds').onclick = () => { read(); r.pos = r.pos || {}; bedEditor(r, isNew, commit); };
}

function facForm(f) {
  const isNew = !f; f = f || { id: 'f' + Date.now().toString(36).slice(-6), floor: S.floor, wing: S.layout.wings[0].key, side: 'A', slot: 100, type: 'store', w: 3 };
  const n = document.createElement('div'); n.className = 'row g-2';
  n.innerHTML = sel('ffType', 'Type', Object.entries(FAC).map(([k, o]) => [k, o.label]), f.type) + sel('ffFloor', 'Floor', floorOpts(true), f.floor) + sel('ffWing', 'Wing', wingOpts(), f.wing) + sel('ffSide', 'Side', [['A', 'Side A'], ['B', 'Side B']], f.side) + field('ffSlot', 'Position (slot)', f.slot, 'number', 'min="0"') + field('ffW', 'Width (m)', f.w, 'number', 'step="0.1" min="1.5" max="12"');
  AppSheet.show({ title: isNew ? 'New facility' : (FAC[f.type] || {}).label, node: n, actions: [
    ...(isNew ? [] : [{ label: 'Delete', cls: 'btn-outline-danger', onClick: () => { S.layout.facilities = S.layout.facilities.filter(x => x !== f); dirty(); AppSheet.hide(); renderAll(); } }]),
    { label: isNew ? 'Add' : 'Done', onClick: () => { Object.assign(f, { type: v('ffType'), floor: v('ffFloor') === 'all' ? 'all' : +v('ffFloor'), wing: v('ffWing'), side: v('ffSide'), slot: Math.max(0, +v('ffSlot') || 0), w: +v('ffW') || 3 }); if (isNew) S.layout.facilities.push(f); dirty(); AppSheet.hide(); S.vb = null; renderAll(); } }] });
}

/* ---------------- bed arranger ---------------- */
function defaultPos(r) { const items = bedItems(r), xs = [-(r.w / 2 - .12 - BED_W / 2), (r.w / 2 - .12 - BED_W / 2)], out = {}; items.forEach((k, i) => { out[k] = { x: xs[i % 2], z: r.d / 2 - .12 - BED_L / 2 - Math.floor(i / 2) * (BED_L + .15), rot: 0 }; }); return out; }
const bedItems = r => [...Array.from({ length: r.bunks }, (_, i) => 'b' + (i + 1)), ...Array.from({ length: r.singles }, (_, i) => 's' + (i + 1))];
function bedEditor(r, isNew, commit) {
  const def = defaultPos(r), pos = {}; bedItems(r).forEach(k => pos[k] = { ...(r.pos[k] || def[k]) });
  const n = document.createElement('div'); const sc = 60;
  n.innerHTML = `<p class="small text-body-secondary">Drag a bed to place it. Dark end is the pillow. Tap a bed, then use rotate. The green door is at the top.</p><svg id="beSvg" class="w-100 bg-body-tertiary rounded border" style="touch-action:none;max-height:55vh" viewBox="${-r.w / 2 - .3} ${-r.d / 2 - .3} ${r.w + .6} ${r.d + .6}"></svg><div class="d-flex gap-2 mt-2"><button class="btn btn-sm btn-outline-secondary" id="beRot"><i class="fas fa-rotate-right me-1"></i>Rotate 90°</button><button class="btn btn-sm btn-outline-secondary" id="beReset">Reset to default</button></div>`;
  AppSheet.show({ title: 'Beds in ' + (r.number || 'new room'), node: n, actions: [{ label: 'Back', cls: 'btn-outline-secondary', onClick: () => roomForm(S.rooms.includes(r) ? r : null) }, { label: 'Use this arrangement', onClick: () => { r.pos = pos; if (S.rooms.includes(r)) { dirty(); renderPlan(); AppSheet.hide(); } else { commitWith(r, pos, commit); } } }] });
  const svg = n.querySelector('#beSvg'); let cur = bedItems(r)[0];
  const draw = () => { svg.innerHTML = ''; el('rect', { x: -r.w / 2, y: -r.d / 2, width: r.w, height: r.d, fill: '#fff', stroke: '#334155', 'stroke-width': .06 }, svg); el('rect', { x: -.5, y: -r.d / 2 - .04, width: 1, height: .08, fill: '#16a34a' }, svg);
    bedItems(r).forEach(k => { const p = pos[k], g = el('g', { transform: `translate(${p.x} ${p.z}) rotate(${-p.rot})`, style: 'cursor:grab', 'data-k': k }, svg); el('rect', { x: -BED_W / 2, y: -BED_L / 2, width: BED_W, height: BED_L, rx: .08, fill: k === cur ? '#93c5fd' : '#dbeafe', stroke: '#1d4ed8', 'stroke-width': .05 }, g); el('rect', { x: -BED_W / 2 + .08, y: BED_L / 2 - .4, width: BED_W - .16, height: .3, rx: .06, fill: '#1e3a8a' }, g); const t = el('text', { 'text-anchor': 'middle', y: -.2, 'font-size': .32, fill: '#0f172a', 'pointer-events': 'none' }, g); t.textContent = k[0] === 'b' ? 'Bunk ' + k.slice(1) : 'Bed ' + k.slice(1); }); };
  draw(); let d = null;
  svg.addEventListener('pointerdown', e => { const g = e.target.closest('[data-k]'); if (!g) return; cur = g.dataset.k; svg.setPointerCapture(e.pointerId); const m = svg.getScreenCTM().inverse(), pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; const w = pt.matrixTransform(m); d = { k: cur, dx: pos[cur].x - w.x, dz: pos[cur].z - w.y }; draw(); });
  svg.addEventListener('pointermove', e => { if (!d) return; const m = svg.getScreenCTM().inverse(), pt = svg.createSVGPoint(); pt.x = e.clientX; pt.y = e.clientY; const w = pt.matrixTransform(m), p = pos[d.k], q = Math.abs(p.rot % 180) === 90 ? [BED_L, BED_W] : [BED_W, BED_L]; p.x = Math.max(-r.w / 2 + q[0] / 2, Math.min(r.w / 2 - q[0] / 2, Math.round((w.x + d.dx) * 20) / 20)); p.z = Math.max(-r.d / 2 + q[1] / 2, Math.min(r.d / 2 - q[1] / 2, Math.round((w.y + d.dz) * 20) / 20)); draw(); });
  svg.addEventListener('pointerup', () => d = null);
  n.querySelector('#beRot').onclick = () => { pos[cur].rot = (pos[cur].rot + 90) % 360; draw(); };
  n.querySelector('#beReset').onclick = () => { bedItems(r).forEach(k => pos[k] = { ...def[k] }); draw(); };
}
function commitWith(r, pos, commit) { commit(); r.pos = pos; }

/* ---------------- wings & facilities tabs ---------------- */
function renderWings() {
  const box = $('stWings'); box.innerHTML = '';
  S.layout.wings.forEach((w, i) => {
    const c = document.createElement('div'); c.className = 'col-12 col-lg-6';
    const others = S.layout.wings.filter(x => x !== w).map(x => [x.key, x.name || x.key]);
    c.innerHTML = `<div class="card shadow-sm"><div class="card-body"><div class="d-flex mb-2"><strong class="me-auto">${esc(w.name || w.key)} <span class="badge text-bg-secondary">${esc(w.key)}</span></strong><button class="btn btn-sm btn-outline-danger" data-rm="${i}" ${S.layout.wings.length < 2 ? 'disabled' : ''}>Remove</button></div><div class="row g-2">`
      + field('wn' + i, 'Name', w.name) + sel('wa' + i, 'Starts', [['', 'Free-standing'], ...others.map(([k, t]) => [k, 'Joined to ' + t])], w.attach ? w.attach.to : '')
      + sel('wat' + i, 'Joined at', [['end', 'End of that wing'], ['start', 'Start of that wing']], w.attach ? w.attach.at : 'end') + field('waa' + i, 'Turn angle (°)', w.attach ? w.attach.angle : w.angle, 'number', 'step="5"')
      + field('wb' + i, 'Curve (° bend)', w.bend, 'number', 'step="5" min="-180" max="180"') + field('wc' + i, 'Corridor width (m)', w.corridor, 'number', 'step="0.1" min="1.5" max="4"')
      + (w.attach ? '' : field('wx' + i, 'Position X (m)', w.x, 'number') + field('wz' + i, 'Position Z (m)', w.z, 'number'))
      + '</div></div></div>'; box.appendChild(c);
    const upd = () => { w.name = $('wn' + i).value; const to = $('wa' + i).value, ang = +$('waa' + i).value || 0; if (to) { w.attach = { to, at: $('wat' + i).value, angle: ang }; } else { w.attach = null; w.angle = ang; w.x = +($('wx' + i)?.value) || w.x || 0; w.z = +($('wz' + i)?.value) || w.z || 0; } w.bend = +$('wb' + i).value || 0; w.corridor = +$('wc' + i).value || 2.2; dirty(); S.vb = null; renderPlan(); };
    c.querySelectorAll('input,select').forEach(x => x.addEventListener('change', () => { upd(); if (x.id === 'wa' + i) renderWings(); }));
    c.querySelector('[data-rm]').onclick = () => { if (S.rooms.some(r => r.wing === w.key) || S.layout.facilities.some(f => f.wing === w.key)) return appToast('Move its rooms and facilities to another wing first.', 'warning'); S.layout.wings.splice(i, 1); S.layout.wings.forEach(x => { if (x.attach && x.attach.to === w.key) x.attach = null; }); dirty(); renderAll(); };
  });
}
function renderFacs() {
  const b = $('stFacList'); b.innerHTML = '';
  S.layout.facilities.forEach(f => { const a = document.createElement('button'); a.className = 'list-group-item list-group-item-action d-flex justify-content-between'; a.innerHTML = `<span>${esc((FAC[f.type] || {}).label || f.type)}</span><span class="text-body-secondary small">${f.floor === 'all' ? 'Every floor' : 'Floor ' + f.floor} · wing ${esc(f.wing)} · side ${f.side}</span>`; a.onclick = () => facForm(f); b.appendChild(a); });
  if (!S.layout.facilities.length) b.innerHTML = '<div class="list-group-item text-body-secondary">No facilities yet. Add stairs, bathrooms, a lounge or an entrance.</div>';
}
function renderAll() { renderPlan(); renderWings(); renderFacs(); }

/* ---------------- toolbar ---------------- */
document.querySelectorAll('#stTabs [data-tab]').forEach(b => b.onclick = () => { document.querySelectorAll('#stTabs .nav-link').forEach(x => x.classList.toggle('active', x === b)); document.querySelectorAll('[data-pane]').forEach(p => p.classList.toggle('d-none', p.dataset.pane !== b.dataset.tab)); });
$('stAddRoom').onclick = () => roomForm(null); $('stAddFac').onclick = () => facForm(null);
$('stAddWing').onclick = () => { const keys = S.layout.wings.map(w => w.key); let i = 1; while (keys.includes('W' + i)) i++; const last = S.layout.wings[S.layout.wings.length - 1]; S.layout.wings.push({ key: 'W' + i, name: 'Wing ' + i, x: 0, z: 0, angle: 0, bend: 0, corridor: 2.2, attach: last ? { to: last.key, at: 'end', angle: 90 } : null }); dirty(); renderAll(); };
$('stFloorCount').onchange = e => { const n = Math.max(1, Math.min(8, +e.target.value || 1)); if (n < S.layout.floors && S.rooms.some(r => r.floor > n)) { e.target.value = S.layout.floors; return appToast('Move rooms off the upper floors first.', 'warning'); } S.layout.floors = n; if (S.floor > n) S.floor = n; dirty(); renderPlan(); };
$('stBlock').onchange = () => load($('stBlock').value);
$('stPreset').onclick = () => {
  const P = S.data.presets, n = document.createElement('div');
  n.innerHTML = '<p class="small text-body-secondary">Starting points. Applying one re-arranges the existing rooms into that shape (nobody is moved out of their bed). You can then change anything.</p><div class="list-group" id="psl"></div><div class="mt-3"><label class="form-label small">Floors</label><input id="psF" type="number" min="1" max="6" class="form-control" value="' + S.layout.floors + '"></div>';
  let pick = null; const lst = n.querySelector('#psl');
  Object.entries(P).forEach(([k, p]) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'list-group-item list-group-item-action'; b.innerHTML = `<div class="fw-semibold">${esc(p.name)}</div><div class="small text-body-secondary">${esc(p.desc)}</div>`; b.onclick = () => { pick = k; lst.querySelectorAll('button').forEach(x => x.classList.toggle('active', x === b)); }; lst.appendChild(b); });
  AppSheet.show({ title: 'Default designs', node: n, actions: [{ label: 'Apply design', onClick: async () => { if (!pick) return appToast('Choose a design first.', 'warning'); if (S.dirty && !confirm('Unsaved changes will be lost. Continue?')) return; const r = await api({ action: 'preset', block_id: S.data.block.id, preset: pick, floors: +$('psF').value || 3 }); if (!r.ok) return appToast(r.error, 'danger'); AppSheet.hide(); ingest(r); appToast('Design applied and saved.', 'success'); } }] });
};
$('stSave').onclick = async () => {
  const b = $('stSave'); b.disabled = true;
  const rooms = S.rooms.map(r => ({ id: r.id > 0 ? r.id : 0, number: r.number, floor: r.floor, wing: r.wing, side: r.side, slot: r.slot, w: r.w, d: r.d, bunks: r.bunks, singles: r.singles, bath: r.bath, beds: r.pos }));
  const r = await api({ action: 'save', block_id: S.data.block.id, layout: S.layout, rooms, delete: S.del }).catch(() => ({ ok: false, error: 'Network problem.' }));
  if (!r.ok) { b.disabled = false; return msg(r.error); } msg(''); ingest(r); appToast('Layout saved.', 'success');
};
window.addEventListener('beforeunload', e => { if (S.dirty) { e.preventDefault(); e.returnValue = ''; } });

async function load(id) {
  if (S.dirty && !confirm('Unsaved changes will be lost. Continue?')) { $('stBlock').value = S.data.block.id; return; }
  const d = await api(null, id ? '?block=' + id : '').catch(() => null);
  if (!d || !d.ok) return msg((d && d.error) || 'Could not load.');
  const sb = $('stBlock'); sb.innerHTML = d.blocks.map(b => `<option value="${b.id}">${esc(b.name)} (${b.gender})</option>`).join(''); sb.value = d.block.id; ingest(d);
}
load(new URLSearchParams(location.search).get('block'));
