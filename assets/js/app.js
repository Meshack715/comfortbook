/* app.js — shared behaviour (no styling). Sheets: bottom sheet on phones, modal on desktop. */
(function () {
  'use strict';
  const mq = window.matchMedia('(max-width: 991.98px)');
  const root = (window.CB && CB.root) || '';
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  /* ---------- toasts ---------- */
  function toast(msg, type) {
    let host = document.getElementById('appToasts');
    if (!host) { host = document.createElement('div'); host.id = 'appToasts'; host.className = 'toast-container position-fixed top-0 end-0 p-3'; document.body.appendChild(host); }
    const el = document.createElement('div');
    el.className = 'toast align-items-center text-bg-' + (type || 'dark') + ' border-0';
    el.setAttribute('role', 'status');
    el.innerHTML = '<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button></div>';
    el.querySelector('.toast-body').textContent = msg;
    host.appendChild(el);
    const t = new bootstrap.Toast(el, { delay: 4200 }); t.show();
    el.addEventListener('hidden.bs.toast', () => el.remove());
  }

  /* ---------- sheet: offcanvas-bottom on phones, centred modal on desktop ---------- */
  let sheetEls = null;
  function ensureSheet() {
    if (sheetEls) return sheetEls;
    const m = document.createElement('div');
    m.className = 'modal fade'; m.tabIndex = -1;
    m.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"></div><div class="modal-footer d-none"></div></div></div>';
    const o = document.createElement('div');
    o.className = 'offcanvas offcanvas-bottom rounded-top-4 h-auto'; o.tabIndex = -1;
    o.setAttribute('style', 'max-height:88vh');
    o.innerHTML = '<div class="d-flex justify-content-center pt-2"><span class="bg-secondary-subtle rounded-pill" style="width:42px;height:5px"></span></div><div class="offcanvas-header pb-0"><h5 class="offcanvas-title"></h5><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div><div class="offcanvas-body"></div><div class="px-3 pb-3 d-none"></div>';
    document.body.append(m, o);
    sheetEls = { m, o, bm: new bootstrap.Modal(m), bo: new bootstrap.Offcanvas(o) };
    return sheetEls;
  }
  /** AppSheet.show({title, html | node, actions:[{label, cls, onClick}]}) */
  let openedAt = 0;
  /* The tap that opens a sheet is followed by a synthetic click that lands on the new backdrop and would close it at once. */
  ['mousedown','mouseup','click'].forEach(t => document.addEventListener(t, e => { if (Date.now() - openedAt < 700 && e.target.classList && (e.target.classList.contains('offcanvas-backdrop') || e.target.classList.contains('modal'))) { e.stopPropagation(); e.preventDefault(); } }, true));
  const AppSheet = {
    show(opt) {
      openedAt = Date.now();
      const s = ensureSheet(), phone = mq.matches;
      const box = phone ? s.o : s.m;
      box.querySelector(phone ? '.offcanvas-title' : '.modal-title').textContent = opt.title || '';
      const body = box.querySelector(phone ? '.offcanvas-body' : '.modal-body');
      body.innerHTML = '';
      if (opt.node) body.appendChild(opt.node); else body.innerHTML = opt.html || '';
      const foot = phone ? box.lastElementChild : box.querySelector('.modal-footer');
      foot.innerHTML = ''; foot.classList.toggle('d-none', !(opt.actions && opt.actions.length));
      foot.classList.toggle('d-flex', !!(opt.actions && opt.actions.length)); foot.classList.add('gap-2');
      (opt.actions || []).forEach(a => {
        const b = document.createElement('button'); b.type = 'button';
        b.className = 'btn ' + (a.cls || 'btn-primary') + (phone ? ' flex-fill btn-lg' : '');
        b.textContent = a.label; b.addEventListener('click', () => a.onClick && a.onClick(b, AppSheet));
        foot.appendChild(b);
      });
      (phone ? s.bo : s.bm).show();
      return { body, foot };
    },
    hide() { const s = ensureSheet(); s.bm.hide(); s.bo.hide(); }
  };

  /* ---------- keep spinners from sticking after back/forward ---------- */
  window.addEventListener('pageshow', e => { if (e.persisted) document.querySelectorAll('button[disabled][data-was-busy]').forEach(b => { b.disabled = false; }); });

  /* ---------- notification centre ---------- */
  document.addEventListener('click', async e => {
    const t = e.target.closest('[data-notif-open]'); if (!t) return; e.preventDefault();
    const r = await fetch(root + '/api/notifications.php', { credentials: 'same-origin' }).then(x => x.json()).catch(() => null);
    const items = (r && r.items) || [];
    const html = items.length ? '<div class="list-group list-group-flush">' + items.map(i => '<a class="list-group-item list-group-item-action' + (i.read_at ? '' : ' fw-semibold') + '" href="' + root + esc(i.url || '#') + '"><div>' + esc(i.title) + '</div><div class="small text-body-secondary fw-normal">' + esc(i.body) + '</div><div class="small text-body-secondary fw-normal">' + esc(i.created_at) + '</div></a>').join('') + '</div>' : '<p class="text-body-secondary mb-0">No notifications yet.</p>';
    AppSheet.show({ title: 'Notifications', html });
    fetch(root + '/api/notifications.php', { method: 'POST', credentials: 'same-origin' });
  });

  window.AppSheet = AppSheet; window.appToast = toast; window.appEsc = esc;
})();
