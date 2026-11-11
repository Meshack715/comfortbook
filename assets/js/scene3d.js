// scene3d.js — walkable 3D hostel. Campus view → dollhouse floor view → first-person walk.
import * as THREE from 'three';
import { mergeGeometries } from 'three/addons/utils/BufferGeometryUtils.js';
import { computeLayout, autoCampus, defaultBedSlots, inObb, statusColor, FAC, WALL_T } from './layout.js';

const D2R = Math.PI / 180, EYE = 1.62;
const C = { free: 0x22c55e, p2: 0x22c55e, held: 0xf59e0b, taken: 0xef4444, mine: 0x2563eb, wall: 0xf4efe6, slab: 0xcfc8bb, corr: 0xe5e0d6, room: 0xd7c3a4, wood: 0x8a6a45, white: 0xfafafa, grass: 0x6aa84f, sky: 0xbfe3ff };

export class Hostel3D extends EventTarget {
  constructor(el, opt = {}) {
    super();
    this.el = el; this.opt = Object.assign({ root: '', poll: 4000 }, opt);
    this.blocks = {}; this.beds = {}; this.rooms = {}; this.state = { mode: 'campus', block: null, floor: 1, room: null };
    this.keys = {}; this.vel = new THREE.Vector3(); this.joy = { x: 0, y: 0 };
    this.orbit = { target: new THREE.Vector3(), r: 120, yaw: 0.8, pitch: 0.9 };
    this.walk = { x: 0, z: 0, yaw: 0, pitch: 0, floor: 1, block: null };
    this.tween = null; this.promptsKey = ''; this.selBed = null; this.selBlock = null; this.last = performance.now();
    this._initGL();
  }

  /* ------------------------------------------------------------------ GL */
  _initGL() {
    const el = this.el;
    this.renderer = new THREE.WebGLRenderer({ antialias: true, powerPreference: 'high-performance' });
    this.renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    this.renderer.domElement.style.touchAction = 'none'; this.renderer.domElement.style.display = 'block';
    this.renderer.domElement.setAttribute('role', 'img'); this.renderer.domElement.setAttribute('aria-label', '3D view of the hostel');
    el.appendChild(this.renderer.domElement);
    this.scene = new THREE.Scene(); this.scene.background = new THREE.Color(C.sky); this.scene.fog = new THREE.Fog(C.sky, 140, 520);
    this.camera = new THREE.PerspectiveCamera(66, 1, 0.1, 900); this.camera.rotation.order = 'YXZ';
    this.scene.add(new THREE.HemisphereLight(0xffffff, 0x8a9a7a, 1.05));
    const sun = new THREE.DirectionalLight(0xfff3dd, 1.35); sun.position.set(80, 140, 50); this.scene.add(sun);
    const ground = new THREE.Mesh(new THREE.PlaneGeometry(1400, 1400), new THREE.MeshLambertMaterial({ color: C.grass }));
    ground.rotation.x = -Math.PI / 2; ground.position.y = -0.05; this.scene.add(ground); this.ground = ground;
    this.mat = {
      wall: new THREE.MeshLambertMaterial({ color: C.wall }), slab: new THREE.MeshLambertMaterial({ color: C.slab }),
      corr: new THREE.MeshLambertMaterial({ color: C.corr }), room: new THREE.MeshLambertMaterial({ color: C.room }),
      wood: new THREE.MeshLambertMaterial({ color: C.wood }), white: new THREE.MeshLambertMaterial({ color: C.white }),
      glass: new THREE.MeshBasicMaterial({ color: 0xbfe8ff }), steel: new THREE.MeshLambertMaterial({ color: 0x9aa3ae }),
      bed: { vacant: new THREE.MeshLambertMaterial({ color: C.free }), available_p2: new THREE.MeshLambertMaterial({ color: C.free }),
             reserved: new THREE.MeshLambertMaterial({ color: C.held }), occupied: new THREE.MeshLambertMaterial({ color: C.taken }), mine: new THREE.MeshLambertMaterial({ color: C.mine }) },
    };
    this.pickables = []; this.ray = new THREE.Raycaster(); this.ptr = new THREE.Vector2();
    this._bindInput(); this._ro = new ResizeObserver(() => this._resize()); this._ro.observe(el); this._resize();
    this._raf = requestAnimationFrame(t => this._frame(t));
  }
  _resize() { const w = this.el.clientWidth || 300, h = this.el.clientHeight || 300; this.renderer.setSize(w, h, false); this.renderer.domElement.style.width = '100%'; this.renderer.domElement.style.height = '100%'; this.camera.aspect = w / h; this.camera.fov = w / h < 0.8 ? 88 : 66; this.camera.updateProjectionMatrix(); }
  destroy() { cancelAnimationFrame(this._raf); clearInterval(this._poll); this._ro.disconnect(); this.renderer.dispose(); this.renderer.domElement.remove(); }

  /* ------------------------------------------------------------------ data */
  async load() {
    const r = await fetch(this.opt.root + '/api/scene.php', { credentials: 'same-origin' });
    if (!r.ok) throw new Error('scene ' + r.status);
    const d = await r.json(); if (!d.ok) throw new Error(d.error || 'scene');
    this.data = d; this.me = d.me; this.hold = d.me.hold;
    const layouts = {};
    d.blocks.forEach(b => {
      const rooms = d.rooms.filter(x => x.block === b.id);
      if (!b.layout) b.layout = { floors: b.floors || 3, wings: [{ key: 'W1', x: 0, z: 0, angle: 0, bend: 0, corridor: 2.2 }], facilities: [] };
      layouts[b.id] = computeLayout(b.layout, rooms);
      this.blocks[b.id] = { data: b, rooms, L: layouts[b.id] };
    });
    const camp = autoCampus(d.blocks, layouts);
    d.blocks.forEach(b => { const B = this.blocks[b.id]; B.campus = camp[b.id]; this._buildMassing(B); });
    d.rooms.forEach(r => { this.rooms[r.id] = r; r.beds.forEach(b => { this.beds[b.id] = b; b.room = r; }); });
    this._trees(); this.goCampus(true);
    this._poll = setInterval(() => this._pollState(), this.opt.poll);
    this._emit('loaded'); return d;
  }
  async _pollState() {
    if (document.hidden) return;
    try {
      const r = await fetch(this.opt.root + '/api/scene_state.php', { credentials: 'same-origin' }); if (!r.ok) return;
      const d = await r.json(); if (!d.ok) return; this.applyState(d);
    } catch (e) { /* offline: keep last picture */ }
  }
  applyState(d) {
    let changed = false; const touched = new Set();
    const prevHold = JSON.stringify(this.hold); this.hold = d.hold; this.me.hold = d.hold;
    d.beds.forEach(([id, st, mine]) => {
      const b = this.beds[id]; if (!b) return;
      if (b.status !== st || !!b.mine !== !!mine) { b.status = st; b.mine = !!mine; changed = true; touched.add(b.room.id); this._paintBed(b); }
    });
    touched.forEach(rid => this._paintRoom(this.rooms[rid]));
    if (changed || prevHold !== JSON.stringify(d.hold)) this._emit('state', { changed: [...touched], hold: d.hold });
  }
  freeIn(room) { return room.beds.filter(b => b.status === 'vacant' || b.status === 'available_p2').length; }
  blockStats(id) { const B = this.blocks[id]; let t = 0, f = 0; B.rooms.forEach(r => { t += r.beds.length; f += this.freeIn(r); }); return { total: t, free: f }; }

  /* ------------------------------------------------------------------ geometry helpers */
  _box(w, h, d, x, y, z) { const g = new THREE.BoxGeometry(w, h, d); g.translate(x, y, z); return g; }
  _xf(g, m) { g.applyMatrix4(m); return g; }
  _cellM(cx, y, cz, rot) { return new THREE.Matrix4().makeTranslation(cx, y, cz).multiply(new THREE.Matrix4().makeRotationY(rot)); }
  _merged(list, mat) { if (!list.length) return null; const g = mergeGeometries(list, false); list.forEach(x => x.dispose()); return new THREE.Mesh(g, mat); }
  _label(text, bg = '#0b2a4a', fg = '#ffffff', w = 256, h = 96) {
    const key = text + bg + fg; this._lbl = this._lbl || {};
    if (!this._lbl[key]) {
      const cv = document.createElement('canvas'); cv.width = w; cv.height = h; const g = cv.getContext('2d');
      g.fillStyle = bg; g.beginPath(); g.roundRect(4, 4, w - 8, h - 8, 18); g.fill();
      g.fillStyle = fg; g.font = 'bold 44px system-ui, sans-serif'; g.textAlign = 'center'; g.textBaseline = 'middle'; g.fillText(text, w / 2, h / 2 + 2);
      const t = new THREE.CanvasTexture(cv); t.colorSpace = THREE.SRGBColorSpace; this._lbl[key] = t;
    }
    const s = new THREE.Sprite(new THREE.SpriteMaterial({ map: this._lbl[key], depthWrite: false, transparent: true })); return s;
  }

  /* ------------------------------------------------------------------ massing (every building, cheap) */
  _buildMassing(B) {
    const L = B.L, H = L.floors * L.floorH, g = new THREE.Group(); g.position.set(B.campus.x, 0, B.campus.z); g.rotation.y = (B.campus.rot || 0) * D2R;
    const walls = [], roofs = [];
    L.corridor.forEach(c => { const m = this._cellM(c.cx, H / 2, c.cz, c.rot); walls.push(this._xf(new THREE.BoxGeometry(c.len, H, c.w), m)); roofs.push(this._xf(new THREE.BoxGeometry(c.len + .2, .5, c.w + .6), this._cellM(c.cx, H + .25, c.cz, c.rot))); });
    const seen = new Set();
    L.cells.forEach(c => { const k = [c.wing, c.side, c.slot].join('|'); if (seen.has(k)) return; seen.add(k);
      walls.push(this._xf(new THREE.BoxGeometry(c.w, H, c.d), this._cellM(c.cx, H / 2, c.cz, c.rot)));
      roofs.push(this._xf(new THREE.BoxGeometry(c.w + .5, .5, c.d + .5), this._cellM(c.cx, H + .25, c.cz, c.rot))); });
    const locked = !B.data.accessible;
    B.wallMat = new THREE.MeshLambertMaterial({ color: locked ? 0xb9bec6 : 0xf2e8d5 }); B.roofMat = new THREE.MeshLambertMaterial({ color: locked ? 0x8b929c : (B.data.gender === 'Female' ? 0xb5527a : 0x1d4e89) });
    const w = this._merged(walls, B.wallMat), r = this._merged(roofs, B.roofMat);
    [w, r].forEach(m => { if (m) { m.userData = { type: 'block', blockId: B.data.id }; g.add(m); this.pickables.push(m); } });
    const lab = this._label(B.data.name + (locked ? '  🔒' : ''), locked ? '#6b7280' : '#003366'); lab.scale.set(18, 6.75, 1); lab.position.set(L.bounds.cx, H + 6, L.bounds.cz); g.add(lab);
    B.massing = g; B.label = lab; this.scene.add(g);
  }
  _trees() {
    const trunk = this._box(.4, 2.2, .4, 0, 1.1, 0), top = new THREE.ConeGeometry(1.8, 4.6, 7); top.translate(0, 4.4, 0);
    const tm = new THREE.InstancedMesh(mergeGeometries([trunk, top]), new THREE.MeshLambertMaterial({ color: 0x3f7f3a }), 90), m = new THREE.Matrix4(); let n = 0, tries = 0;
    const ext = this._campusBounds(); let seed = 7; const rnd = () => (seed = (seed * 16807) % 2147483647) / 2147483647;
    while (n < 90 && tries++ < 900) {
      const x = ext.cx + (rnd() - .5) * (ext.w + 120), z = ext.cz + (rnd() - .5) * (ext.d + 120);
      if (Object.values(this.blocks).some(B => this._inFootprint(B, x, z, 6))) continue;
      m.makeTranslation(x, 0, z).scale(new THREE.Vector3(1, .8 + rnd() * .8, 1)); m.setPosition(x, 0, z); tm.setMatrixAt(n++, m);
    }
    tm.count = n; this.scene.add(tm);
  }
  _campusBounds() { let a = 1e9, b = -1e9, c = 1e9, d = -1e9; Object.values(this.blocks).forEach(B => { const o = B.L.bounds; a = Math.min(a, B.campus.x + o.minX); b = Math.max(b, B.campus.x + o.maxX); c = Math.min(c, B.campus.z + o.minZ); d = Math.max(d, B.campus.z + o.maxZ); }); return { cx: (a + b) / 2, cz: (c + d) / 2, w: b - a, d: d - c }; }
  _toLocal(B, x, z) { const th = (B.campus.rot || 0) * D2R, dx = x - B.campus.x, dz = z - B.campus.z; return { x: dx * Math.cos(th) - dz * Math.sin(th), z: dx * Math.sin(th) + dz * Math.cos(th) }; }
  _toWorld(B, x, z) { const th = (B.campus.rot || 0) * D2R; return { x: B.campus.x + x * Math.cos(th) + z * Math.sin(th), z: B.campus.z - x * Math.sin(th) + z * Math.cos(th) }; }
  _inFootprint(B, x, z, r = 0) { const p = this._toLocal(B, x, z); return B.L.footprint.some(o => inObb(o, p.x, p.z, -r)); }

  /* ------------------------------------------------------------------ detail (built when you go inside) */
  _buildDetail(B) {
    if (B.detail) return B.detail;
    const L = B.L, T = WALL_T, WH = L.floorH - 0.2, root = new THREE.Group(); root.position.copy(B.massing.position); root.rotation.y = B.massing.rotation.y;
    const floors = {};
    for (let f = 1; f <= L.floors; f++) {
      const y0 = (f - 1) * L.floorH, fg = new THREE.Group(); fg.userData.floor = f;
      const slabC = [], slabR = [], walls = [], frames = [], glass = [], steel = [];
      L.corridor.forEach(c => { const m = this._cellM(c.cx, y0 - .1, c.cz, c.rot); slabC.push(this._xf(new THREE.BoxGeometry(c.len, .2, c.w), m)); });
      L.cells.filter(c => c.floor === f).forEach(c => {
        const m = this._cellM(c.cx, y0, c.cz, c.rot), w = c.w, d = c.d, isRoom = c.kind === 'room';
        const cfg = this._cellCfg(c), dW = cfg.door;
        slabR.push(this._xf(this._box(w, .2, d, 0, -.1, 0), m.clone()));
        const add = (g) => walls.push(this._xf(g, m.clone()));
        add(this._box(w, WH, T, 0, WH / 2, d / 2 - T / 2));                                         // back wall (or opening for exit)
        add(this._box(T, WH, d, -(w / 2 - T / 2), WH / 2, 0)); add(this._box(T, WH, d, w / 2 - T / 2, WH / 2, 0));
        const seg = (w - dW) / 2; add(this._box(seg, WH, T, -(dW / 2 + seg / 2), WH / 2, -d / 2 + T / 2)); add(this._box(seg, WH, T, dW / 2 + seg / 2, WH / 2, -d / 2 + T / 2));
        add(this._box(dW, WH - 2.15, T, 0, 2.15 + (WH - 2.15) / 2, -d / 2 + T / 2));                // lintel
        if (c.type === 'exit') { walls.pop(); walls.pop(); walls.pop(); walls.pop(); /* remove last 4 → rebuild back wall with opening */
          const bs = (w - 2.2) / 2; add(this._box(bs, WH, T, -(1.1 + bs / 2), WH / 2, d / 2 - T / 2)); add(this._box(bs, WH, T, 1.1 + bs / 2, WH / 2, d / 2 - T / 2)); add(this._box(2.2, WH - 2.3, T, 0, 2.3 + (WH - 2.3) / 2, d / 2 - T / 2));
          add(this._box(T, WH, d, -(w / 2 - T / 2), WH / 2, 0)); add(this._box(T, WH, d, w / 2 - T / 2, WH / 2, 0)); }
        if (isRoom) {
          glass.push(this._xf(this._box(Math.min(1.8, w - 1), 1.1, .03, 0, 1.55, d / 2 - T - .02), m.clone()));          // window
          frames.push(this._xf(this._box(.9, .05, .5, 0, 2.15 + .05, -d / 2 + T / 2), m.clone()));
        } else this._facilityProps(c, m, frames, steel);
      });
      const parts = [[slabC, this.mat.corr], [slabR, this.mat.slab], [walls, this.mat.wall], [frames, this.mat.wood], [glass, this.mat.glass], [steel, this.mat.steel]];
      parts.forEach(([arr, mat]) => { const me = this._merged(arr, mat); if (me) fg.add(me); });
      // top slab = roof of the floor below the next one (and the building roof)
      const roof = []; L.corridor.forEach(c => roof.push(this._xf(new THREE.BoxGeometry(c.len + .1, .2, c.w + .1), this._cellM(c.cx, y0 + L.floorH - .1, c.cz, c.rot))));
      L.cells.filter(c => c.floor === f).forEach(c => roof.push(this._xf(new THREE.BoxGeometry(c.w, .2, c.d), this._cellM(c.cx, y0 + L.floorH - .1, c.cz, c.rot))));
      const rm = this._merged(roof, f === L.floors ? B.roofMat : this.mat.slab); if (rm) { rm.userData.ceiling = true; fg.add(rm); fg.userData.ceiling = rm; }
      // rooms: tinted floor mat, door plate, label, beds
      L.cells.filter(c => c.floor === f).forEach(c => {
        const cg = new THREE.Group(); cg.position.set(c.cx, y0, c.cz); cg.rotation.y = c.rot; fg.add(cg);
        if (c.kind === 'room') this._buildRoom(B, c, cg, f);
        else { const lab = this._label((FAC[c.type] || {}).label || c.type, '#475569'); lab.scale.set(1.5, .56, 1); lab.position.set(0, 2.6, -c.d / 2 - .1); cg.add(lab); }
      });
      root.add(fg); floors[f] = fg;
    }
    B.detail = { root, floors }; B.detail.root.visible = false; this.scene.add(root); return B.detail;
  }
  _cellCfg(c) { const m = { stairs: 2.2, wash: 1.4, lounge: 2.0, exit: 2.2, pairbath: .9 }; return { door: Math.min(c.w - .6, m[c.type] || 1.1) }; }
  _facilityProps(c, m, frames, steel) {
    const w = c.w, d = c.d, mm = () => m.clone();
    if (c.type === 'stairs') { const n = 14, sw = w - .8; for (let i = 0; i < n; i++) frames.push(this._xf(this._box(sw, .22 * (i + 1), (d - 1.9) / n, 0, .11 * (i + 1), -d / 2 + 1.9 + (i + .5) * (d - 1.9) / n), mm())); steel.push(this._xf(this._box(.05, 1, d - 1.8, -(sw / 2) - .05, 1, 1), mm())); }
    else if (c.type === 'wash' || c.type === 'pairbath') { const n = Math.max(2, Math.floor((w - .6) / 1.0)); for (let i = 0; i < n; i++) steel.push(this._xf(this._box(.05, 2, 1.6, -w / 2 + .5 + i * ((w - 1) / n), 1, d / 2 - 1.0), mm())); steel.push(this._xf(this._box(w - .8, .12, .5, 0, .9, -d / 2 + 1.1), mm())); }
    else if (c.type === 'lounge') { for (let i = 0; i < 3; i++) frames.push(this._xf(this._box(2, .5, .8, -w / 2 + 1.6 + i * 2.2, .25, d / 2 - 1.1), mm())); frames.push(this._xf(this._box(1.4, .45, 1.4, 0, .22, 0), mm())); }
    else if (c.type === 'office' || c.type === 'dean') { frames.push(this._xf(this._box(1.6, .75, .8, 0, .37, d / 2 - 1.2), mm())); }
  }
  _buildRoom(B, c, cg, f) {
    const r = c.room, T = WALL_T, w = c.w, d = c.d;
    const tile = new THREE.Mesh(new THREE.BoxGeometry(w - .5, .05, d - .5), new THREE.MeshLambertMaterial({ color: 0x22c55e })); tile.position.set(0, .03, 0);
    tile.userData = { type: 'room', roomId: r.id, blockId: B.data.id }; cg.add(tile); this.pickables.push(tile); r._tile = tile;
    const plate = new THREE.Mesh(new THREE.BoxGeometry(.95, .3, .06), new THREE.MeshLambertMaterial({ color: 0x22c55e })); plate.position.set(0, 2.4, -d / 2 + T / 2 - .02); cg.add(plate); r._plate = plate;
    const lab = this._label(r.number.replace(/^[A-Za-z]+-/, ''), '#0f172a'); lab.scale.set(.62, .23, 1); lab.position.set(0, 2.42, -d / 2 - .06); cg.add(lab);
    if (c.room.bath === 'ensuite') { const wc = this._box(1.4, .02, 1.4, 0, .06, 0); }
    // beds
    const slots = defaultBedSlots({ w, d, beds: r.beds }); let bunkIdx = {}; const frames = [];
    r.beds.forEach(bd => {
      const bn = bd.kind === 'single' ? 'S' + bd.label : bd.bunk; if (bunkIdx[bn] === undefined) bunkIdx[bn] = Object.keys(bunkIdx).length;
    });
    const placedBunk = {}, singles = r.beds.filter(b => b.kind === 'single');
    r.beds.forEach(bd => {
      const key = bd.kind === 'single' ? 'S' + bd.label : bd.bunk, idx = bunkIdx[key];
      const slot = (bd.x !== null && bd.z !== null) ? { x: bd.x, z: bd.z, rot: (bd.rot || 0) * D2R } : { ...slots[idx] || { x: 0, z: 0, rot: 0 }, rot: 0 };
      const upper = bd.kind === 'upper', yTop = upper ? 1.42 : .42;
      const mm = new THREE.Mesh(new THREE.BoxGeometry(.84, .14, 1.9), this.mat.bed.vacant); mm.position.set(slot.x, yTop, slot.z); mm.rotation.y = slot.rot || 0;
      mm.userData = { type: 'bed', bedId: bd.id, roomId: r.id, blockId: B.data.id }; cg.add(mm); this.pickables.push(mm); bd._mesh = mm;
      const G = new THREE.Matrix4().makeTranslation(slot.x, 0, slot.z).multiply(new THREE.Matrix4().makeRotationY(slot.rot || 0));
      // pillow at the head (back wall side)
      frames.push(this._xf(this._box(.6, .1, .3, 0, yTop + .12, .75), G.clone()));
      if (!placedBunk[key]) { placedBunk[key] = 1; const top = bd.kind === 'single' ? 0.5 : 1.95;
        [[-.45, -.95], [.45, -.95], [-.45, .95], [.45, .95]].forEach(([px, pz]) => frames.push(this._xf(this._box(.06, top, .06, px, top / 2, pz), G.clone())));
        [-.45, .45].forEach(px => { frames.push(this._xf(this._box(.05, .08, 1.9, px, .32, 0), G.clone())); if (bd.kind !== 'single') frames.push(this._xf(this._box(.05, .08, 1.9, px, 1.32, 0), G.clone())); });
        if (bd.kind !== 'single') frames.push(this._xf(this._box(.05, .3, 1.9, -.45, 1.55, 0), G.clone())); }
      this._paintBed(bd);
    });
    const fm = this._merged(frames, this.mat.wood); if (fm) cg.add(fm);
    this._paintRoom(r);
  }

  /* ------------------------------------------------------------------ painting */
  _paintBed(b) {
    if (!b._mesh) return; const sel = this.selBed === b.id;
    b._mesh.material = b.mine ? this.mat.bed.mine : (this.mat.bed[b.status] || this.mat.bed.vacant);
    b._mesh.scale.set(sel ? 1.12 : 1, sel ? 1.8 : 1, sel ? 1.04 : 1);
  }
  _paintRoom(r) {
    if (!r || !r._tile) return; const free = this.freeIn(r), col = statusColor(free, r.beds.length);
    r._tile.material.color.setHex(col); r._plate.material.color.setHex(col);
  }

  /* ------------------------------------------------------------------ visibility / modes */
  _setBlockVisible(id, mode) {   // mode: 'massing' | 'detail'
    Object.values(this.blocks).forEach(B => { const on = B.data.id === id && mode === 'detail'; B.massing.visible = !on; if (B.detail) B.detail.root.visible = on; });
  }
  _cut(B, n, walking) {          // n = highest visible floor (null = all)
    const d = B.detail; if (!d) return;
    for (const f in d.floors) { const g = d.floors[f], show = n === null || Number(f) <= n; g.visible = show; if (g.userData.ceiling) g.userData.ceiling.visible = (n === null) || walking || Number(f) < n; }
  }
  goCampus(instant) {
    this.state = { mode: 'campus', block: null, floor: 1, room: null }; this._setBlockVisible(null, 'massing'); Object.values(this.blocks).forEach(B => { B.label.visible = true; });
    const b = this._campusBounds(); this.orbit.target.set(b.cx, 6, b.cz); const r = Math.max(b.w, b.d) * .85 + 40;
    this._flyOrbit({ target: new THREE.Vector3(b.cx, 6, b.cz), r, yaw: .7, pitch: .85 }, instant ? 0 : 1.1); this.selBlock = null; this._emit('mode', this.state);
  }
  showFloor(blockId, floor = 1) {
    const B = this.blocks[blockId]; if (!B || !B.data.accessible) return; this._buildDetail(B); this._setBlockVisible(blockId, 'detail');
    B.label.visible = false; floor = Math.max(1, Math.min(B.L.floors, floor)); this._cut(B, floor, false);
    this.state = { mode: 'floor', block: blockId, floor, room: null };
    const o = B.L.bounds, c = this._toWorld(B, o.cx, o.cz); const r = Math.max(o.w, o.d) * .62 + 10;
    this._flyOrbit({ target: new THREE.Vector3(c.x, (floor - 1) * B.L.floorH + 1, c.z), r, yaw: this.orbit.yaw, pitch: 1.0 }, .9); this._emit('mode', this.state);
  }
  focusRoom(roomId) {
    const r = this.rooms[roomId]; if (!r) return; const B = this.blocks[r.block]; if (this.state.block !== r.block || this.state.floor !== r.floor) this.showFloor(r.block, r.floor);
    const c = B.L.cells.find(x => x.id === 'r' + r.id), w = this._toWorld(B, c.cx, c.cz);
    this._flyOrbit({ target: new THREE.Vector3(w.x, (r.floor - 1) * B.L.floorH + 1, w.z), r: 11, yaw: this.orbit.yaw, pitch: 1.15 }, .9); this.state.room = roomId; this._emit('mode', this.state);
  }
  walkIntoRoom(roomId) {
    const r = this.rooms[roomId]; if (!r) return; const B = this.blocks[r.block]; this._buildDetail(B); this._setBlockVisible(r.block, 'detail'); B.label.visible = false;
    const c = B.L.cells.find(x => x.id === 'r' + r.id); if (!c) return;
    const p = { x: c.front.x + c.back.x * 0.55, z: c.front.z + c.back.z * 0.55 }, w = this._toWorld(B, p.x, p.z);
    const dir = this._toWorld(B, p.x + c.back.x, p.z + c.back.z); const yaw = Math.atan2(-(dir.x - w.x), -(dir.z - w.z));
    this._enterWalk(B, r.floor, w.x, w.z, yaw, -0.18);
  }
  enterBlock(blockId) {
    const B = this.blocks[blockId]; if (!B || !B.data.accessible) return; this._buildDetail(B); this._setBlockVisible(blockId, 'detail'); B.label.visible = false;
    const e = B.L.entrance, w = this._toWorld(B, e.x + e.dirX * 2.2, e.z + e.dirZ * 2.2), hd = (B.L.entrance.heading || 0), d = this._toWorld(B, e.x + e.dirX * 2.2 - Math.cos(hd), e.z + e.dirZ * 2.2 - Math.sin(hd));
    this._enterWalk(B, 1, w.x, w.z, Math.atan2(-(d.x - w.x), -(d.z - w.z)));
  }
  _enterWalk(B, floor, x, z, yaw, pitch = -0.05) {
    this.state = { mode: 'walk', block: B.data.id, floor, room: null }; this._cut(B, null, true);
    this.walk = { x, z, yaw, pitch, floor, block: B.data.id };
    this._flyWalkCam(x, z, yaw, floor, B, .9); this._emit('mode', this.state);
  }
  goOutside(blockId) {
    const B = this.blocks[blockId || this.state.block]; if (!B) return; const e = B.L.entrance, o = this._toWorld(B, e.outX, e.outZ), t = this._toWorld(B, e.x, e.z);
    this.state = { mode: 'walk', block: null, floor: 1, room: null }; this._setBlockVisible(null, 'massing'); Object.values(this.blocks).forEach(x => { x.label.visible = true; });
    const yaw = Math.atan2(-(o.x - t.x), -(o.z - t.z)); this.walk = { x: o.x, z: o.z, yaw, pitch: -0.08, floor: 1, block: null };
    { const f = this._nearestFree(o.x, o.z); this.walk.x = f.x; this.walk.z = f.z; o.x = f.x; o.z = f.z; }
    this._flyWalkCam(o.x, o.z, yaw, 1, null, .8); this._emit('mode', this.state);
  }
  walkCampus() { const b = this._campusBounds(); this.state = { mode: 'walk', block: null, floor: 1, room: null }; this._setBlockVisible(null, 'massing'); const B = Object.values(this.blocks).find(x => x.data.accessible) || Object.values(this.blocks)[0], e = B.L.entrance, o = this._toWorld(B, e.outX, e.outZ), t = this._toWorld(B, e.x, e.z); this.walk = { x: o.x, z: o.z, yaw: Math.atan2(-(t.x - o.x), -(t.z - o.z)), pitch: -.08, floor: 1, block: null }; this._flyWalkCam(o.x, o.z, this.walk.yaw, 1, null, .8); this._emit('mode', this.state); }
  stairs(dir) {
    const B = this.blocks[this.walk.block]; if (!B) return; const nf = this.walk.floor + dir; if (nf < 1 || nf > B.L.floors) return;
    this.walk.floor = nf; this.state.floor = nf; this._flyWalkCam(this.walk.x, this.walk.z, this.walk.yaw, nf, B, .8); this._emit('mode', this.state);
  }
  setFloor(n) { const b = this.state.block; if (b === null) return; if (this.state.mode === 'floor') this.showFloor(b, n); else { const B = this.blocks[b], nf = Math.max(1, Math.min(B.L.floors, n)); /* teleport to stairs landing on that floor */ const s = B.L.cells.find(c => c.type === 'stairs' && c.floor === nf); if (s) { const w = this._toWorld(B, s.front.x + s.back.x * .8, s.front.z + s.back.z * .8); this._enterWalk(B, nf, w.x, w.z, this.walk.yaw); } } }
  overview() { if (this.state.block === null) return this.goCampus(); this.showFloor(this.state.block, this.state.floor); }
  selectBed(id) { const old = this.selBed; this.selBed = id; if (old && this.beds[old]) this._paintBed(this.beds[old]); if (id && this.beds[id]) this._paintBed(this.beds[id]); }
  bedWorld(b) { if (!b._mesh) return null; const v = new THREE.Vector3(); b._mesh.getWorldPosition(v); return v; }
  lookAtBed(id) { const b = this.beds[id]; if (!b) return; const r = b.room, B = this.blocks[r.block]; if (!B.detail) return; const v = this.bedWorld(b); if (!v) return;
    if (this.state.mode === 'walk') { const dx = v.x - this.walk.x, dz = v.z - this.walk.z; this.walk.yaw = Math.atan2(-dx, -dz); this.walk.pitch = Math.atan2(v.y - (this.camera.position.y), Math.hypot(dx, dz)) * .6; } }

  /* ------------------------------------------------------------------ camera tweens */
  _flyOrbit(to, dur) {
    const from = { target: this.orbit.target.clone(), r: this.orbit.r, yaw: this.orbit.yaw, pitch: this.orbit.pitch };
    this.tween = { t: 0, dur: Math.max(0.0001, dur), kind: 'orbit', from, to }; this.walkActive = false;
    if (dur === 0) { this.orbit = { target: to.target.clone(), r: to.r, yaw: to.yaw, pitch: to.pitch }; this.tween = null; }
  }
  _flyWalkCam(x, z, yaw, floor, B, dur) {
    const y = ((floor - 1) * (B ? B.L.floorH : 3.2)) + EYE; this.tween = { t: 0, dur, kind: 'walk', from: { p: this.camera.position.clone(), yaw: this.camera.rotation.y, pitch: this.camera.rotation.x }, to: { p: new THREE.Vector3(x, y, z), yaw, pitch: this.walk.pitch } }; this.walkActive = false;
  }

  /* ------------------------------------------------------------------ input */
  _bindInput() {
    const cv = this.renderer.domElement, pts = new Map(); this._pts = pts; let moved = 0, t0 = 0, startPos = null, pinch = 0;
    cv.addEventListener('pointerdown', e => { cv.setPointerCapture(e.pointerId); pts.set(e.pointerId, { x: e.clientX, y: e.clientY, sx: e.clientX, sy: e.clientY, side: e.clientX < cv.getBoundingClientRect().left + cv.clientWidth * .45 ? 'L' : 'R' }); moved = 0; t0 = performance.now(); startPos = { x: e.clientX, y: e.clientY };
      if (pts.size === 2) { const [a, b] = [...pts.values()]; pinch = Math.hypot(a.x - b.x, a.y - b.y); } });
    cv.addEventListener('pointermove', e => {
      const p = pts.get(e.pointerId); if (!p) return; const dx = e.clientX - p.x, dy = e.clientY - p.y; p.x = e.clientX; p.y = e.clientY; moved += Math.abs(dx) + Math.abs(dy);
      if (this.state.mode === 'walk') {
        if (p.side === 'L' && e.pointerType === 'touch') { const jx = (p.x - p.sx) / 55, jy = (p.y - p.sy) / 55; this.joy.x = Math.max(-1, Math.min(1, jx)); this.joy.y = Math.max(-1, Math.min(1, jy)); this._joyKnob(this.joy.x, this.joy.y); }
        else { this.walk.yaw += dx * .0045; this.walk.pitch = Math.max(-1.2, Math.min(1.2, this.walk.pitch + dy * .0035)); this.tween = null; }
      } else if (pts.size === 2) {
        const [a, b] = [...pts.values()], d = Math.hypot(a.x - b.x, a.y - b.y); this.orbit.r = Math.max(8, Math.min(420, this.orbit.r * (pinch / Math.max(1, d)))); pinch = d; this.tween = null;
      } else if (e.buttons === 2 || e.shiftKey) { const k = this.orbit.r * .0016, c = Math.cos(this.orbit.yaw), s = Math.sin(this.orbit.yaw); this.orbit.target.x -= (dx * c + dy * s) * k; this.orbit.target.z -= (-dx * s + dy * c) * k; this.tween = null; }
      else { this.orbit.yaw -= dx * .006; this.orbit.pitch = Math.max(.12, Math.min(1.5, this.orbit.pitch + dy * .005)); this.tween = null; }
    });
    const up = e => {
      const p = pts.get(e.pointerId); pts.delete(e.pointerId); if (p && p.side === 'L' && e.pointerType === 'touch') { this.joy.x = this.joy.y = 0; this._joyKnob(0, 0); }
      if (p && moved < 8 && performance.now() - t0 < 450) this._pick(e.clientX, e.clientY);
    };
    cv.addEventListener('pointerup', up); cv.addEventListener('pointercancel', up); cv.addEventListener('contextmenu', e => e.preventDefault());
    cv.addEventListener('wheel', e => { e.preventDefault(); if (this.state.mode === 'walk') return; this.orbit.r = Math.max(8, Math.min(420, this.orbit.r * (1 + Math.sign(e.deltaY) * .1))); this.tween = null; }, { passive: false });
    this._kd = e => { if (['INPUT', 'TEXTAREA', 'SELECT'].includes((e.target.tagName || ''))) return; this.keys[e.key.toLowerCase()] = true; if (e.key.startsWith('Arrow')) e.preventDefault(); };
    this._ku = e => { this.keys[e.key.toLowerCase()] = false; }; window.addEventListener('keydown', this._kd); window.addEventListener('keyup', this._ku);
    this._buildJoystick();
  }
  _buildJoystick() {
    if (!('ontouchstart' in window) && !(navigator.maxTouchPoints > 0)) return;
    const base = document.createElement('div'); base.className = 'position-absolute bottom-0 start-0 m-4 mb-5 rounded-circle border border-2 border-light bg-dark bg-opacity-25 d-none'; base.style.cssText = 'width:112px;height:112px;pointer-events:none';
    const knob = document.createElement('div'); knob.className = 'position-absolute top-50 start-50 rounded-circle bg-light bg-opacity-75'; knob.style.cssText = 'width:46px;height:46px;margin:-23px 0 0 -23px';
    base.appendChild(knob); this.el.appendChild(base); this._joyBase = base; this._joyKnobEl = knob;
  }
  _joyKnob(x, y) { if (this._joyKnobEl) this._joyKnobEl.style.transform = `translate(${x * 34}px,${y * 34}px)`; }
  _pick(cx, cy) {
    const r = this.renderer.domElement.getBoundingClientRect(); this.ptr.set(((cx - r.left) / r.width) * 2 - 1, -((cy - r.top) / r.height) * 2 + 1); this.ray.setFromCamera(this.ptr, this.camera);
    const vis = this.pickables.filter(m => { let o = m; while (o) { if (o.visible === false) return false; o = o.parent; } return true; });
    const hit = this.ray.intersectObjects(vis, false)[0]; if (!hit) return; const u = hit.object.userData;
    if (u.type === 'bed') { if (this.state.mode !== 'walk' && this.state.mode !== 'floor') return; this._emit('pick', { type: 'bed', bed: this.beds[u.bedId], room: this.rooms[u.roomId], block: this.blocks[u.blockId].data }); }
    else if (u.type === 'room') this._emit('pick', { type: 'room', room: this.rooms[u.roomId], block: this.blocks[u.blockId].data });
    else if (u.type === 'block' && this.state.mode === 'campus') { this.selBlock = u.blockId; this._emit('pick', { type: 'block', block: this.blocks[u.blockId].data, stats: this.blockStats(u.blockId) }); }
  }

  /* ------------------------------------------------------------------ frame loop */
  _emit(name, detail) { this.dispatchEvent(new CustomEvent(name, { detail })); }
  _nearestFree(x, z) {            // if a spawn point lands inside a wall or footprint, slide to the closest open spot
    if (this._canStand(x, z)) return { x, z };
    for (let r = .4; r <= 16; r += .4) for (let i = 0; i < 24; i++) { const a = i * Math.PI / 12, px = x + Math.cos(a) * r, pz = z + Math.sin(a) * r; if (this._canStand(px, pz)) return { x: px, z: pz }; }
    return { x, z };
  }
  _canStand(x, z) {
    const r = .28, w = this.walk;
    if (w.block !== null && this.blocks[w.block]) { const B = this.blocks[w.block], p = this._toLocal(B, x, z); const L = B.L.walk[w.floor], inAny = (px, pz) => L.some(o => inObb(o, px, pz, 0));
      for (let i = 0; i < 8; i++) { const a = i * Math.PI / 4, q = this._toLocal(B, x + Math.cos(a) * r, z + Math.sin(a) * r); if (!inAny(q.x, q.z)) return false; }
      return inAny(p.x, p.z); }
    if (Math.abs(x) > 300 || Math.abs(z) > 300) return false;
    return !Object.values(this.blocks).some(B => this._inFootprint(B, x, z, r));
  }
  _frame(now) {
    this._raf = requestAnimationFrame(t => this._frame(t)); const dt = Math.min(.05, (now - this.last) / 1000); this.last = now;
    const tw = this.tween;
    if (tw) {
      tw.t += dt / tw.dur; const k = Math.min(1, tw.t), e = k < .5 ? 2 * k * k : 1 - Math.pow(-2 * k + 2, 2) / 2;
      if (tw.kind === 'orbit') { this.orbit.target.lerpVectors(tw.from.target, tw.to.target, e); this.orbit.r = tw.from.r + (tw.to.r - tw.from.r) * e; this.orbit.yaw = tw.from.yaw + (tw.to.yaw - tw.from.yaw) * e; this.orbit.pitch = tw.from.pitch + (tw.to.pitch - tw.from.pitch) * e; }
      else { this.camera.position.lerpVectors(tw.from.p, tw.to.p, e); this.camera.rotation.y = tw.from.yaw + (this._wrap(tw.to.yaw - tw.from.yaw)) * e; this.camera.rotation.x = tw.from.pitch + (tw.to.pitch - tw.from.pitch) * e; }
      if (k >= 1) { this.tween = null; if (tw.kind === 'walk') this.walkActive = true; }
    }
    if (this.state.mode === 'walk') {
      if (this._joyBase) this._joyBase.classList.toggle('d-none', false);
      if (this.walkActive) this._walkStep(dt);
      if (this.walkActive) { const B = this.blocks[this.walk.block]; this.camera.position.set(this.walk.x, ((this.walk.floor - 1) * (B ? B.L.floorH : 3.2)) + EYE, this.walk.z); this.camera.rotation.y = this.walk.yaw; this.camera.rotation.x = this.walk.pitch; }
      this._prompts();
    } else {
      if (this._joyBase) this._joyBase.classList.add('d-none');
      if (!(tw && tw.kind === 'walk')) { const o = this.orbit, cp = Math.cos(o.pitch); this.camera.position.set(o.target.x + Math.sin(o.yaw) * cp * o.r, o.target.y + Math.sin(o.pitch) * o.r, o.target.z + Math.cos(o.yaw) * cp * o.r); this.camera.lookAt(o.target); }
      if (this.promptsKey) { this.promptsKey = ''; this._emit('prompts', []); }
    }
    this.renderer.render(this.scene, this.camera);
  }
  _wrap(a) { while (a > Math.PI) a -= 2 * Math.PI; while (a < -Math.PI) a += 2 * Math.PI; return a; }
  _walkStep(dt) {
    const k = this.keys; let fx = (k['w'] || k['arrowup'] ? 1 : 0) - (k['s'] || k['arrowdown'] ? 1 : 0) - this.joy.y, sx = (k['d'] ? 1 : 0) - (k['a'] ? 1 : 0) + this.joy.x;
    if (k['arrowleft']) this.walk.yaw += dt * 1.8; if (k['arrowright']) this.walk.yaw -= dt * 1.8; if (k['q']) this.walk.yaw += dt * 1.5; if (k['e']) this.walk.yaw -= dt * 1.5;
    const sp = (k['shift'] ? 4.6 : 2.5), mag = Math.hypot(fx, sx) || 1, w = this.walk, s = Math.sin(w.yaw), c = Math.cos(w.yaw);
    const tx = (-s * fx + c * sx) / Math.max(1, mag) * sp, tz = (-c * fx - s * sx) / Math.max(1, mag) * sp;
    this.vel.x += (tx - this.vel.x) * Math.min(1, dt * 9); this.vel.z += (tz - this.vel.z) * Math.min(1, dt * 9);   // glide
    const nx = w.x + this.vel.x * dt, nz = w.z + this.vel.z * dt;
    if (!this._canStand(w.x, w.z)) { const f = this._nearestFree(w.x, w.z); w.x = f.x; w.z = f.z; return; }
    this._autoTransit(nx, nz);
    if (this._canStand(nx, nz)) { w.x = nx; w.z = nz; } else if (this._canStand(nx, w.z)) { w.x = nx; this.vel.z = 0; } else if (this._canStand(w.x, nz)) { w.z = nz; this.vel.x = 0; } else this.vel.set(0, 0, 0);
  }
  /* Walk through the front door, into a door from outside, or onto the stairs: no button needed (the buttons still work). */
  _autoTransit(nx, nz) {
    const now = performance.now(); if (now < (this._transitUntil || 0)) return;
    const w = this.walk, sp = Math.hypot(this.vel.x, this.vel.z); if (sp < .6) return;
    if (w.block !== null && this.blocks[w.block]) {
      const B = this.blocks[w.block], p = this._toLocal(B, nx, nz), e = B.L.entrance, v = this._toLocal(B, nx + this.vel.x, nz + this.vel.z), dx = v.x - p.x, dz = v.z - p.z;
      if (e && w.floor === 1 && Math.hypot(p.x - e.trigX, p.z - e.trigZ) < 1.3 && (dx * -e.dirX + dz * -e.dirZ) / sp > .5) { this._transitUntil = now + 2000; this.goOutside(); return; }
      const st = B.L.cells.find(c => c.type === 'stairs' && c.floor === w.floor && Math.hypot(p.x - c.front.x, p.z - c.front.z) < 1.6 && ((p.x - c.front.x) * c.back.x + (p.z - c.front.z) * c.back.z) > .5);
      if (st) { this._transitUntil = now + 2200; this.stairs(w.floor < B.L.floors ? 1 : -1); }
    } else {
      for (const B of Object.values(this.blocks)) { if (!B.data.accessible) continue; const e = B.L.entrance, t = this._toWorld(B, e.outX + e.dirX * 1.6, e.outZ + e.dirZ * 1.6), d = Math.hypot(nx - t.x, nz - t.z); if (d < 3.2) { const tx = t.x - nx, tz = t.z - nz; if ((tx * this.vel.x + tz * this.vel.z) / (d * sp + 1e-6) > .6) { this._transitUntil = now + 2000; this.enterBlock(B.data.id); return; } } }
    }
  }
  _prompts() {
    const w = this.walk, out = [];
    if (w.block !== null && this.blocks[w.block]) {
      const B = this.blocks[w.block], p = this._toLocal(B, w.x, w.z), e = B.L.entrance;
      if (e && w.floor === 1 && Math.hypot(p.x - e.trigX, p.z - e.trigZ) < 3.0) out.push({ id: 'exit', icon: 'fa-door-open', label: 'Go outside' });
      const st = B.L.cells.find(c => c.type === 'stairs' && c.floor === w.floor && Math.hypot(p.x - c.front.x, p.z - c.front.z) < 3.2);
      if (st) { if (w.floor < B.L.floors) out.push({ id: 'up', icon: 'fa-stairs', label: 'Go up a floor' }); if (w.floor > 1) out.push({ id: 'down', icon: 'fa-arrow-down', label: 'Go down a floor' }); }
      const room = B.L.cells.find(c => c.kind === 'room' && c.floor === w.floor && Math.hypot(p.x - c.cx, p.z - c.cz) < Math.min(c.w, c.d) / 2 - .2);
      if (room && this.state.room !== room.room.id) { this.state.room = room.room.id; this._emit('mode', this.state); } else if (!room && this.state.room) { this.state.room = null; this._emit('mode', this.state); }
    } else {
      Object.values(this.blocks).forEach(B => { if (!B.data.accessible) return; const e = B.L.entrance, o = this._toWorld(B, e.outX, e.outZ); if (Math.hypot(w.x - o.x, w.z - o.z) < 6) out.push({ id: 'enter:' + B.data.id, icon: 'fa-door-closed', label: 'Enter ' + B.data.name }); });
    }
    const key = out.map(o => o.id).join(','); if (key !== this.promptsKey) { this.promptsKey = key; this._emit('prompts', out); }
  }
  act(id) {
    if (id === 'exit') this.goOutside(); else if (id === 'up') this.stairs(1); else if (id === 'down') this.stairs(-1); else if (id.startsWith('enter:')) this.enterBlock(Number(id.slice(6)));
  }
}
