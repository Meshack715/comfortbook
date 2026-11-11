/* pwa.js — service worker, install button, push notifications. */
(function () {
  const root = (window.CB && CB.root) || '';
  if ('serviceWorker' in navigator) window.addEventListener('load', () => navigator.serviceWorker.register(root + '/sw.js', { scope: root + '/' }).catch(() => {}));

  /* ---- install ---- */
  let deferred = null;
  const showInstall = on => document.querySelectorAll('[data-pwa-install]').forEach(b => b.classList.toggle('d-none', !on));
  window.addEventListener('beforeinstallprompt', e => { e.preventDefault(); deferred = e; showInstall(true); });
  window.addEventListener('appinstalled', () => { deferred = null; showInstall(false); window.appToast && appToast('Installed. Open ComfortBook from your home screen.', 'success'); });
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-pwa-install]'); if (!b) return;
    if (deferred) { deferred.prompt(); deferred.userChoice.finally(() => { deferred = null; showInstall(false); }); }
  });
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.matchMedia('(display-mode: standalone)').matches;
  if (ios) document.addEventListener('DOMContentLoaded', () => { document.querySelectorAll('[data-pwa-install]').forEach(b => { b.classList.remove('d-none'); b.addEventListener('click', () => window.AppSheet && AppSheet.show({ title: 'Install on iPhone', html: '<p>Tap the <strong>Share</strong> button in Safari, then <strong>Add to Home Screen</strong>.</p>' })); }); });

  /* ---- push ---- */
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  const u8 = s => { const p = '='.repeat((4 - s.length % 4) % 4), b = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from([...b].map(c => c.charCodeAt(0))); };
  async function sub() {
    const reg = await navigator.serviceWorker.ready; let s = await reg.pushManager.getSubscription();
    if (!s) s = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: u8(CB.vapid) });
    const r = await fetch(root + '/api/push_subscribe.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(s.toJSON()) });
    return r.ok;
  }
  async function refresh() {
    const btn = document.getElementById('pushBtn'); if (!btn || !supported || !CB.vapid) return;
    btn.classList.remove('d-none');
    const on = Notification.permission === 'granted' && !!(await (await navigator.serviceWorker.ready).pushManager.getSubscription());
    btn.innerHTML = '<i class="fas ' + (on ? 'fa-bell' : 'fa-bell-slash') + '"></i>'; btn.title = on ? 'Notifications are on' : 'Turn on notifications';
    btn.onclick = async () => {
      if (on) { window.AppSheet && AppSheet.show({ title: 'Notifications', html: '<p class="mb-0">Notifications are <strong>on</strong> for this device. You will be told about approvals, bed holds and updates.</p>' }); return; }
      if (Notification.permission === 'denied') { window.appToast && appToast('Notifications are blocked in your browser settings for this site.', 'warning'); return; }
      const p = await Notification.requestPermission(); if (p === 'granted' && await sub()) { window.appToast && appToast('Notifications turned on.', 'success'); refresh(); }
    };
  }
  window.addEventListener('load', () => { if (supported) { navigator.serviceWorker.ready.then(refresh); if (Notification.permission === 'granted') sub().catch(() => {}); } });
})();
