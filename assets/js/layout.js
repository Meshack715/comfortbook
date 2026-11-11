// layout.js — turns a block's layout JSON + its rooms into plan geometry.
// Shared by the 3D viewer and the Layout Studio so both always agree.
// World: x east, z south (three.js), y up. Angles in degrees in JSON, radians inside.
const D2R = Math.PI / 180;
export const WALL_T = 0.16, DOOR_W = 1.1, BED_W = 0.95, BED_L = 2.0;

export const FAC = {
  stairs:   { label: 'Stairs', depth: 5.0, color: 0xb8c0cc, walk: true },
  wash:     { label: 'Bathrooms & toilets', depth: 5.0, color: 0x7fc4d9, walk: true },
  pairbath: { label: 'Shared bathroom', depth: 5.0, color: 0x9fd4e3, walk: true },
  lounge:   { label: "Students lounge", depth: 7.0, color: 0xe9c97a, walk: true },
  office:   { label: 'Office', depth: 5.0, color: 0xd9b8a0, walk: true },
  dean:     { label: 'Dean office', depth: 5.0, color: 0xd9a8a0, walk: true },
  exit:     { label: 'Entrance', depth: 3.0, color: 0xa7d7a0, walk: true },
  store:    { label: 'Store', depth: 5.0, color: 0xc9c9c9, walk: true },
  laundry:  { label: 'Laundry', depth: 5.0, color: 0xb9c4e8, walk: true },
};

const rad = d => d * D2R;
const obb = (cx, cz, rot, hx, hz) => ({ cx, cz, cos: Math.cos(rot), sin: Math.sin(rot), hx, hz });
export function inObb(o, x, z, r = 0) {
  const dx = x - o.cx, dz = z - o.cz;
  const lx = dx * o.cos + dz * o.sin, lz = -dx * o.sin + dz * o.cos;
  return Math.abs(lx) <= o.hx - r && Math.abs(lz) <= o.hz - r;
}

/** Sample a wing's centre line every `ds` metres, with its own bend. */
function samplePath(start, heading0, len, bendDeg, ds = 0.5) {
  const pts = []; let x = start.x, z = start.z, h = heading0;
  const n = Math.max(2, Math.ceil(len / ds)), step = len / n, dh = rad(bendDeg) / n;
  pts.push({ s: 0, x, z, h });
  for (let i = 1; i <= n; i++) {
    h += dh * 0.5; x += Math.cos(h) * step; z += Math.sin(h) * step; h += dh * 0.5;
    pts.push({ s: i * step, x, z, h });
  }
  return pts;
}
function at(pts, s) {
  if (s <= 0) return pts[0]; const last = pts[pts.length - 1];
  if (s >= last.s) return { s, x: last.x + Math.cos(last.h) * (s - last.s), z: last.z + Math.sin(last.h) * (s - last.s), h: last.h };
  const step = pts[1].s, i = Math.min(pts.length - 2, Math.floor(s / step)), a = pts[i], b = pts[i + 1], t = (s - a.s) / (b.s - a.s);
  return { s, x: a.x + (b.x - a.x) * t, z: a.z + (b.z - a.z) * t, h: a.h + (b.h - a.h) * t };
}

/** Default bed positions (room-local, origin = room centre, +z toward the back wall). */
export function defaultBedSlots(room) {
  const w = room.w, d = room.d, out = [];
  const nBunk = room.beds ? room.beds.filter(b => b.kind === 'lower').length : 0;
  const nSingle = room.beds ? room.beds.filter(b => b.kind === 'single').length : 0;
  const total = nBunk + nSingle, xs = [-(w / 2 - 0.12 - BED_W / 2), (w / 2 - 0.12 - BED_W / 2)];
  for (let i = 0; i < total; i++) {
    const row = Math.floor(i / 2), col = i % 2;
    out.push({ x: xs[col], z: d / 2 - 0.12 - BED_L / 2 - row * (BED_L + 0.15), rot: 0 });
  }
  return out;
}

export function computeLayout(layout, rooms) {
  const floors = Math.max(1, layout.floors || 1), floorH = layout.floor_h || 3.2;
  const wings = {}, order = [];
  (layout.wings || []).forEach(w => { wings[w.key] = { ...w, c: w.corridor || 2.2 }; });

  // ---- cells per floor ----
  const cells = [];
  rooms.forEach(r => cells.push({ id: 'r' + r.id, kind: 'room', type: 'room', floor: r.floor, wing: r.wing, side: r.side, slot: r.slot, w: r.w, d: r.d, room: r }));
  (layout.facilities || []).forEach(f => {
    const fl = f.floor === 'all' ? Array.from({ length: floors }, (_, i) => i + 1) : [Number(f.floor)];
    fl.forEach(n => { if (n <= floors) cells.push({ id: f.id + '@' + n, fid: f.id, kind: 'fac', type: f.type, floor: n, wing: f.wing, side: f.side, slot: f.slot, w: f.w || 3, d: f.d || (FAC[f.type] || FAC.store).depth, fac: f }); });
  });

  // ---- slot offsets (aligned across floors so stairs/bathrooms stack vertically) ----
  const lenOf = {};
  for (const wk in wings) {
    for (const side of ['A', 'B']) {
      const mine = cells.filter(c => c.wing === wk && c.side === side);
      const slots = [...new Set(mine.map(c => c.slot))].sort((a, b) => a - b);
      const width = {}; mine.forEach(c => { width[c.slot] = Math.max(width[c.slot] || 0, c.w); });
      let off = 0; const offs = {}; slots.forEach(s => { offs[s] = off; off += width[s]; });
      mine.forEach(c => { c.off = offs[c.slot]; });
      lenOf[wk] = Math.max(lenOf[wk] || 0, off);
    }
    lenOf[wk] = Math.max(lenOf[wk] || 0, 8);
  }

  // ---- place wings (attach chains) ----
  const placed = {};
  const place = key => {
    if (placed[key]) return placed[key]; const w = wings[key]; if (!w) return null;
    let start = { x: w.x || 0, z: w.z || 0 }, h0 = rad(w.angle || 0);
    if (w.attach && wings[w.attach.to]) {
      const p = place(w.attach.to), q = w.attach.at === 'start' ? p.pts[0] : p.pts[p.pts.length - 1];
      start = { x: q.x, z: q.z }; h0 = q.h + rad(w.attach.angle || 0);
    }
    const pts = samplePath(start, h0, lenOf[key], w.bend || 0);
    return (placed[key] = { key, pts, len: lenOf[key], c: w.c, wing: w });
  };
  Object.keys(wings).forEach(k => { place(k); order.push(k); });

  // ---- cell world transforms ----
  cells.forEach(c => {
    const p = placed[c.wing]; if (!p) { c.skip = true; return; }
    const m = at(p.pts, c.off + c.w / 2), sign = c.side === 'A' ? 1 : -1;
    const nx = -Math.sin(m.h) * sign, nz = Math.cos(m.h) * sign;          // back direction
    c.cx = m.x + nx * (p.c / 2 + c.d / 2); c.cz = m.z + nz * (p.c / 2 + c.d / 2);
    const ux = Math.cos(m.h) * sign, uz = Math.sin(m.h) * sign;           // local +x
    c.rot = -Math.atan2(uz, ux);
    c.heading = m.h; c.back = { x: nx, z: nz };
    c.front = { x: m.x + nx * (p.c / 2), z: m.z + nz * (p.c / 2) };      // door point on corridor edge
  });

  // ---- corridors ----
  const corridor = [];
  order.forEach(k => {
    const p = placed[k];
    for (let s = 0; s < p.len; s += 1) {
      const e = Math.min(p.len, s + 1), a = at(p.pts, s), b = at(p.pts, e), m = at(p.pts, (s + e) / 2);
      corridor.push({ wing: k, cx: (a.x + b.x) / 2, cz: (a.z + b.z) / 2, rot: -m.h, len: Math.hypot(b.x - a.x, b.z - a.z) + 0.12, w: p.c });
    }
  });

  // ---- walkable rectangles per floor ----
  const walk = {};
  for (let f = 1; f <= floors; f++) {
    const list = [];
    corridor.forEach(c => list.push(obb(c.cx, c.cz, c.rot, c.len / 2, c.w / 2)));
    cells.filter(c => c.floor === f && !c.skip && (c.kind === 'room' || (FAC[c.type] || {}).walk)).forEach(c => {
      if (c.type === 'stairs') {                                           // only the landing in front of the steps
        const lz = -c.d / 2 + 0.95, ca0 = Math.cos(c.rot), sa0 = Math.sin(c.rot);
        list.push(obb(c.cx + lz * sa0, c.cz + lz * ca0, c.rot, c.w / 2 - WALL_T, 0.95));
      } else list.push(obb(c.cx, c.cz, c.rot, c.w / 2 - WALL_T, c.d / 2 - WALL_T));
      // door connector (local frame: front wall at z = -d/2)
      const ca = Math.cos(c.rot), sa = Math.sin(c.rot);
      const lx = 0, lz = -c.d / 2;                                           // local → world
      const wx = c.cx + lx * ca + lz * sa, wz = c.cz - lx * sa + lz * ca;
      list.push(obb(wx, wz, c.rot, DOOR_W / 2 - 0.05, 0.5));
    });
    walk[f] = list;
  }

  // ---- bounds, footprint, entrance ----
  let minX = Infinity, maxX = -Infinity, minZ = Infinity, maxZ = -Infinity;
  const grow = (x, z) => { minX = Math.min(minX, x); maxX = Math.max(maxX, x); minZ = Math.min(minZ, z); maxZ = Math.max(maxZ, z); };
  corridor.forEach(c => grow(c.cx, c.cz)); cells.forEach(c => { if (!c.skip) { grow(c.cx - c.w / 2 - c.d / 2, c.cz - c.d / 2 - c.w / 2); grow(c.cx + c.w / 2 + c.d / 2, c.cz + c.d / 2 + c.w / 2); } });
  if (!isFinite(minX)) { minX = maxX = minZ = maxZ = 0; }
  const footprint = [];
  corridor.forEach(c => footprint.push(obb(c.cx, c.cz, c.rot, c.len / 2 + 0.3, c.w / 2 + 0.3)));
  cells.filter(c => c.floor === 1 && !c.skip).forEach(c => footprint.push(obb(c.cx, c.cz, c.rot, c.w / 2 + 0.25, c.d / 2 + 0.25)));

  let entrance = null;
  const ex = cells.find(c => c.type === 'exit' && c.floor === 1 && !c.skip);
  if (ex) {
    const outX = ex.cx + ex.back.x * (ex.d / 2 + 1.6), outZ = ex.cz + ex.back.z * (ex.d / 2 + 1.6);
    entrance = { heading: ex.heading, x: ex.cx, z: ex.cz, outX, outZ, dirX: -ex.back.x, dirZ: -ex.back.z, trigX: ex.cx + ex.back.x * (ex.d / 2 - 0.2), trigZ: ex.cz + ex.back.z * (ex.d / 2 - 0.2) };
  } else if (order.length) {
    const p = placed[order[0]], a = p.pts[0], b = p.pts[1], h = a.h;
    entrance = { x: a.x, z: a.z, outX: a.x - Math.cos(h) * 3, outZ: a.z - Math.sin(h) * 3, dirX: Math.cos(h), dirZ: Math.sin(h), trigX: a.x - Math.cos(h) * 0.8, trigZ: a.z - Math.sin(h) * 0.8 };
  }
  return { floors, floorH, wings: placed, order, cells: cells.filter(c => !c.skip), corridor, walk, bounds: { minX, maxX, minZ, maxZ, cx: (minX + maxX) / 2, cz: (minZ + maxZ) / 2, w: maxX - minX, d: maxZ - minZ }, footprint, entrance };
}

/** Automatic campus positions when the admin has not placed buildings yet. */
export function autoCampus(blocks, layouts) {
  const out = {}; let x = 0, rowZ = 0, rowH = 0;
  blocks.forEach((b, i) => {
    const c = layouts[b.id].bounds, camp = (b.layout && b.layout.campus) || {};
    if (camp.x || camp.z) { out[b.id] = { x: camp.x, z: camp.z, rot: camp.rot || 0 }; return; }
    const w = c.w + 18, d = c.d + 18;
    if (i > 0 && i % 2 === 0) { x = 0; rowZ += rowH; rowH = 0; }
    out[b.id] = { x: x - c.cx, z: rowZ - c.cz + d / 2, rot: camp.rot || 0, auto: true };
    x += w; rowH = Math.max(rowH, d);
  });
  return out;
}

export function statusColor(free, cap) {
  if (cap <= 0) return 0x9ca3af;
  if (free === 0) return 0xef4444;       // full
  if (free === cap) return 0x22c55e;     // all vacant
  if (free <= 2) return 0xf59e0b;        // 1–2 beds left
  return 0x84cc16;                       // several beds left
}
