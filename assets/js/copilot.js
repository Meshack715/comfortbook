/* copilot.js — the ComfortBook AI workspace. Bootstrap classes only (no custom CSS).
   Phone: full-screen app. Desktop: large two-pane workspace (chat history | conversation).
   Features: many chats (new / rename / delete / search), copy + share a message or a whole chat, tap-to-answer choices,
   in-chat forms, sideways-scrolling tables (+CSV/PDF), charts, layout cards, editable action cards, memory panel, voice input,
   Stop button, edit-my-message, reply-to-an-answer, Try again. */
(function () {
  'use strict';
  const root = (window.CB && CB.root) || '';
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SUGGEST = {
    student: [['fa-bed', 'Find me a bed', 'Recommend a lower bunk on a quiet floor'], ['fa-list-check', 'What is next?', 'Where am I in the booking process'], ['fa-hourglass-half', 'My hold', 'Show my booking status and timer'], ['fa-circle-question', 'How does this work?', 'Explain phases, fees and approval'], ['fa-graduation-cap', 'Quiz me', 'Test me on the booking rules with 5 quick questions']],
    secretary: [['fa-sun', 'Daily briefing', 'What needs my attention today'], ['fa-circle-check', 'Pending approvals', 'Show everything waiting for me'], ['fa-user-slash', 'Unplaced students', 'Who still has no bed'], ['fa-bullhorn', 'Remind students', 'Message those who have not paid'], ['fa-file-pdf', 'Make a report', 'Create a PDF report of my wing: occupancy and who is unplaced']],
    dean: [['fa-sun', 'Daily briefing', 'Occupancy, approvals and issues'], ['fa-user-slash', 'Who is unplaced?', 'Students without a bed'], ['fa-coins', 'Payments overview', 'Late-booking fees and receipts'], ['fa-chart-line', 'Occupancy', 'Chart how full each hostel is'], ['fa-file-pdf', 'Make a report', 'Create a PDF report of occupancy and unplaced students']],
    admin: [['fa-sun', 'Daily briefing', 'System-wide summary for today'], ['fa-coins', 'Payments overview', 'M-Pesa fees collected and pending'], ['fa-clipboard-list', 'Recent audit activity', 'What changed lately and who did it'], ['fa-drafting-compass', 'Layout overview', 'Hostels, wings and presets'], ['fa-file-pdf', 'Make a report', 'Create a PDF report: occupancy, payments and approvals']]
  };
  const call = (path, body, qs) => fetch(root + '/api/' + path + (qs ? '?' + qs : ''), { method: body ? 'POST' : 'GET', credentials: 'same-origin', headers: body ? { 'Content-Type': 'application/json' } : {}, body: body ? JSON.stringify(body) : undefined }).then(r => r.json());
  const chatApi = (body, qs) => call('ai_chat.php', body, qs);
  /* send a message and read the live progress lines ({step:…}) until the final answer object arrives */
  async function chatStream(body, onStep, signal) {
    let r; try { r = await fetch(root + '/api/ai_chat.php', { method: 'POST', credentials: 'same-origin', signal, headers: { 'Content-Type': 'application/json', 'X-CB-Stream': '1' }, body: JSON.stringify(body) }); }
    catch (e) { return e && e.name === 'AbortError' ? { ok: false, aborted: true } : { ok: false, error: 'No connection to the server. Check your internet and try again.', retry: true }; }
    const rd = r.body && r.body.getReader ? r.body.getReader() : null; let buf = '', final = null;
    const eat = line => { line = line.trim(); if (!line) return; let o; try { o = JSON.parse(line); } catch (_) { return; } if (o.step) onStep(o.step); else if ('ok' in o) final = o; };
    try {
      if (rd) { const dec = new TextDecoder(); for (;;) { const { done, value } = await rd.read(); if (done) break; buf += dec.decode(value, { stream: true }); let i; while ((i = buf.indexOf('\n')) >= 0) { eat(buf.slice(0, i)); buf = buf.slice(i + 1); } } eat(buf); }
      else { (await r.text()).split('\n').forEach(eat); }
    } catch (e) { if (e && e.name === 'AbortError') return { ok: false, aborted: true }; }
    if (!final) return { ok: false, retry: true, error: 'The connection dropped before the answer arrived (HTTP ' + r.status + '). Nothing was lost.' };
    return final;
  }
  const toast = (m, t) => (window.appToast ? appToast(m, t) : 0);

  /* tiny, safe markdown: **bold**, *italic*, `code`, lists, headings, line breaks */
  function md(src) {
    const inline = t => esc(t).replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    const out = []; let list = null; const close = () => { if (list) { out.push('</' + list + '>'); list = null; } };
    const L = String(src || '').split(/\r?\n/), isRow = l => /^\s*\|.*\|\s*$/.test(l || ''), isSep = l => /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(l || '');
    const cells = l => l.trim().replace(/^\||\|$/g, '').split('|').map(c => c.trim());
    for (let i = 0; i < L.length; i++) {
      const line = L[i]; let m;
      if (isRow(line)) {                                   /* a markdown table, even when the model put blank lines between its rows */
        const rows = []; let j = i; while (j < L.length && (isRow(L[j]) || !L[j].trim())) { if (L[j].trim()) rows.push(L[j]); j++; }
        if (rows.length >= 2 && isSep(rows[1])) { close(); const h = cells(rows[0]), body = rows.slice(2).map(cells); out.push(tableHtml(h, body)); i = j - 1; continue; }
      }
      if ((m = line.match(/^\s*[-*•]\s+(.*)/))) { if (list !== 'ul') { close(); out.push('<ul class="ps-3 mb-2">'); list = 'ul'; } out.push('<li>' + inline(m[1]) + '</li>'); }
      else if ((m = line.match(/^\s*\d+[.)]\s+(.*)/))) { if (list !== 'ol') { close(); out.push('<ol class="ps-3 mb-2">'); list = 'ol'; } out.push('<li>' + inline(m[1]) + '</li>'); }
      else if ((m = line.match(/^#{1,4}\s+(.*)/))) { close(); out.push('<p class="fw-semibold mb-1">' + inline(m[1]) + '</p>'); }
      else if (!line.trim()) close();
      else { close(); out.push('<p class="mb-2">' + inline(line) + '</p>'); }
    }
    close(); return out.join('');
  }
  /* table markup shared by the chat text and the table block: numbers right-aligned, first column stays put while you swipe */
  function tableHtml(cols, rows) {
    const num = cols.map((_, i) => { const v = rows.map(r => String(r[i] == null ? '' : r[i]).trim()).filter(Boolean); return v.length && v.every(x => /^[-+]?(KES\s*)?\d[\d,.\s]*%?$/i.test(x)); });
    const tone = t => /^(success|successful|paid|confirmed|approved|ready)$/i.test(t) ? ' cbai-good' : /^(failed|rejected|cancelled|canceled|invalid|error|expired)$/i.test(t) ? ' cbai-bad' : '';
    return '<div class="cbai-tw"><div class="cbai-tscroll" tabindex="0" role="region" aria-label="Table, scroll sideways"><table class="cbai-tbl"><thead><tr>' + cols.map((c, i) => '<th scope="col" class="' + (num[i] ? 'n' : '') + '">' + esc(c) + '</th>').join('') + '</tr></thead><tbody>' +
      rows.map(r => '<tr>' + cols.map((_, i) => '<td class="' + (num[i] ? 'n' : '') + tone(String(r[i] == null ? '' : r[i])) + '">' + esc(r[i] == null ? '' : r[i]) + '</td>').join('') + '</tr>').join('') + '</tbody></table></div><div class="cbai-hint"><i class="fas fa-left-right"></i> Swipe sideways to see every column</div></div>';
  }
  /* shadows at the edges + the swipe hint, wired after the table is in the page */
  function wireScroll(root_) {
    root_.querySelectorAll('.cbai-tw').forEach(w => {
      if (w._w) return; w._w = 1; const sc = w.querySelector('.cbai-tscroll'), hint = w.querySelector('.cbai-hint');
      const upd = () => { const max = sc.scrollWidth - sc.clientWidth; w.classList.toggle('can', max > 4); w.classList.toggle('sl', sc.scrollLeft > 4); w.classList.toggle('sr', sc.scrollLeft < max - 4); if (sc.scrollLeft > 24 && hint) hint.style.display = 'none'; };
      sc.addEventListener('scroll', upd, { passive: true }); new ResizeObserver(upd).observe(sc); setTimeout(upd, 60);
    });
  }
  const orb = size => '<span class="cbai-orb" style="--s:' + size + 'px" aria-hidden="true"><i class="fas fa-wand-magic-sparkles"></i></span>';
  const clock = d => (d ? new Date(String(d).replace(' ', 'T')) : new Date()).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  function ago(d) {
    const t = new Date(String(d).replace(' ', 'T')), n = new Date(); if (isNaN(t)) return '';
    if (t.toDateString() === n.toDateString()) return clock(d);
    const y = new Date(n); y.setDate(n.getDate() - 1); if (t.toDateString() === y.toDateString()) return 'Yesterday';
    return t.toLocaleDateString([], { day: 'numeric', month: 'short' });
  }
  async function copyText(t) {
    try { await navigator.clipboard.writeText(t); } catch (_) { const a = document.createElement('textarea'); a.value = t; document.body.appendChild(a); a.select(); try { document.execCommand('copy'); } catch (e) { } a.remove(); }
    toast('Copied', 'success');
  }
  async function shareText(title, text) {
    if (navigator.share) { try { await navigator.share({ title, text }); return; } catch (e) { if (e && e.name === 'AbortError') return; } }
    copyText(text);
  }

  let el = null;
  const state = { loaded: false, kind: 'student', name: '', assistant: '', live: true, busy: false, conv: 0, convs: [], msgs: [], facts: 0, ctl: null, editFrom: 0, replyTo: '' };
  const $ = s => el.querySelector(s);
  const title = () => state.assistant || 'ComfortBook AI';

  /* ---------------------------------------------------------------- shell: a full-screen workspace (phone and desktop) */
  function build() {
    if (!document.getElementById('cbAiCss')) { const l = document.createElement('link'); l.id = 'cbAiCss'; l.rel = 'stylesheet'; l.href = root + '/assets/css/cb-ai.css?v=' + ((window.CB && CB.v) || 7); document.head.appendChild(l); }
    el = document.createElement('div');
    el.className = 'cbai'; el.id = 'cbAi'; el.setAttribute('role', 'dialog'); el.setAttribute('aria-modal', 'true'); el.setAttribute('aria-labelledby', 'cbAiTitle');
    el.innerHTML = `
<div class="cbai-aurora" aria-hidden="true"><i></i><i></i><i></i></div>
<div class="cbai-scrim" id="cbHistScrim"></div>
<aside class="cbai-side" id="cbHist" aria-label="Chat history">
  <div class="cbai-brand"><img src="${root}/assets/logo/ueab.png" alt=""><div><b>ComfortBook</b><small>UEAB hostel assistant</small></div></div>
  <button type="button" class="cbai-newbtn" id="cbHistNew"><i class="fas fa-plus"></i>New chat</button>
  <div class="cbai-search"><i class="fas fa-magnifying-glass"></i><input id="cbHistQ" type="search" placeholder="Search chats" autocomplete="off" aria-label="Search chats"></div>
  <div class="cbai-label">Recent</div>
  <div class="cbai-list" id="cbHistList"></div>
  <button type="button" class="cbai-memcard" id="cbAiMem2"><i class="fas fa-brain"></i><span><b>What I remember</b><small>Your preferences &amp; my name</small></span></button>
</aside>
<main class="cbai-main">
  <header class="cbai-head">
    <button type="button" class="cbai-ico cbai-sm-only" id="cbAiHistBtn" aria-label="Chat history"><i class="fas fa-bars-staggered"></i></button>
    <span id="cbAiOrb"></span>
    <div class="cbai-sp"><h2 id="cbAiTitle" class="text-truncate">ComfortBook AI</h2><div class="cbai-stat" id="cbAiStatus" role="status" aria-live="polite"></div></div>
    <button type="button" class="cbai-pill" id="cbAiNew"><i class="fas fa-pen-to-square"></i><span>New chat</span></button>
    <button type="button" class="cbai-ico cbai-opt" id="cbAiMem" aria-label="What I remember"><i class="fas fa-brain"></i></button>
    <div class="dropdown">
      <button type="button" class="cbai-ico" data-bs-toggle="dropdown" aria-label="Chat options" aria-expanded="false"><i class="fas fa-ellipsis-vertical"></i></button>
      <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-4 p-2">
        <li><button class="dropdown-item rounded-3" type="button" data-m="rename"><i class="fas fa-pen fa-fw me-2"></i>Rename chat</button></li>
        <li><button class="dropdown-item rounded-3" type="button" data-m="copy"><i class="fas fa-copy fa-fw me-2"></i>Copy chat</button></li>
        <li><button class="dropdown-item rounded-3" type="button" data-m="share"><i class="fas fa-share-nodes fa-fw me-2"></i>Share chat</button></li>
        <li><button class="dropdown-item rounded-3 d-sm-none" type="button" data-m="memory"><i class="fas fa-brain fa-fw me-2"></i>What I remember</button></li>
        <li><hr class="dropdown-divider"></li>
        <li><button class="dropdown-item rounded-3 text-danger" type="button" data-m="delete"><i class="fas fa-trash fa-fw me-2"></i>Delete chat</button></li>
      </ul>
    </div>
    <button type="button" class="cbai-ico" id="cbAiClose" aria-label="Close assistant"><i class="fas fa-xmark"></i></button>
  </header>
  <div class="cbai-scroll" id="cbAiScroll" tabindex="0" aria-label="Conversation"><div class="cbai-thread" id="cbAiThread" aria-live="polite"></div></div>
  <button type="button" class="cbai-fab" id="cbAiFab" aria-label="Scroll to latest"><i class="fas fa-arrow-down"></i></button>
  <div class="cbai-dock">
    <div class="cbai-chips" id="cbAiChips" role="list" aria-label="Suggestions"></div>
    <div class="cbai-ctx" id="cbAiCtx" hidden></div>
    <form class="cbai-form" id="cbAiForm"><label class="visually-hidden" for="cbAiIn">Message</label>
      <textarea id="cbAiIn" rows="1" maxlength="1500" placeholder="Ask anything, or tell me what to do…"></textarea>
      <button type="button" class="cbai-mic d-none" id="cbAiMic" aria-label="Speak"><i class="fas fa-microphone"></i></button>
      <button class="cbai-send" id="cbAiSend" aria-label="Send"><i class="fas fa-arrow-up"></i></button></form>
    <div class="cbai-mode" id="cbAiMode"></div>
  </div>
  <div class="cbai-panel" id="cbAiPanel"></div>
</main>`;
    document.body.appendChild(el);
    const scroll = $('#cbAiScroll'), fab = $('#cbAiFab'), inp = $('#cbAiIn');
    scroll.addEventListener('scroll', () => { fab.style.display = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 140 ? 'none' : 'block'; });
    fab.onclick = () => down(true);
    inp.addEventListener('input', () => { inp.style.height = 'auto'; inp.style.height = Math.min(140, inp.scrollHeight) + 'px'; });
    inp.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !('ontouchstart' in window)) { e.preventDefault(); $('#cbAiForm').requestSubmit(); } });
    $('#cbAiForm').addEventListener('submit', e => { e.preventDefault(); if (state.busy) return stop(); send(inp.value); });
    $('#cbAiNew').onclick = $('#cbHistNew').onclick = newChat;
    $('#cbAiHistBtn').onclick = () => histPanel(true);
    $('#cbHistScrim').onclick = () => histPanel(false);
    $('#cbHistQ').addEventListener('input', renderHist);
    $('#cbAiMem').onclick = $('#cbAiMem2').onclick = () => { histPanel(false); memoryPanel(); };
    $('#cbAiClose').onclick = close;
    el.querySelectorAll('[data-m]').forEach(b => b.addEventListener('click', () => menu(b.dataset.m)));
    el.addEventListener('keydown', e => { if (e.key === 'Escape') { if (state.busy) return stop(); if (state.editFrom || state.replyTo) return ctxClear(); if ($('#cbAiPanel').classList.contains('on')) closePanel(); else if (el.classList.contains('side-open')) histPanel(false); else close(); } });
    initVoice(); setHead();
  }
  function close() { el.classList.remove('on'); document.documentElement.style.overflow = ''; document.body.style.overflow = ''; }

  const down = smooth => { const s = $('#cbAiScroll'); s.scrollTo({ top: s.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }); };
  const status = (txt, busy) => { const b = $('#cbAiStatus'); b.classList.toggle('busy', !!busy); b.innerHTML = '<span class="cbai-dot"></span><span>' + esc(txt) + '</span>'; };
  function setHead() { $('#cbAiTitle').textContent = title(); $('#cbAiOrb').innerHTML = orb(40); status('Ready'); }
  function histPanel(open) { el.classList.toggle('side-open', !!open); }
  function panel(titleTxt, node) {
    const p = $('#cbAiPanel');
    p.innerHTML = '<div class="ph"><button type="button" class="cbai-ico" aria-label="Back" data-back><i class="fas fa-arrow-left"></i></button><h3>' + esc(titleTxt) + '</h3></div><div class="pb" data-body></div>';
    p.querySelector('[data-body]').appendChild(node); p.classList.add('on');
    p.querySelector('[data-back]').onclick = closePanel;
  }
  function closePanel() { const p = $('#cbAiPanel'); p.classList.remove('on'); p.innerHTML = ''; }

  /* ---------------------------------------------------------------- stop / edit / reply */
  function sendMode(busy) { const b = $('#cbAiSend'); b.classList.toggle('stop', !!busy); b.setAttribute('aria-label', busy ? 'Stop' : 'Send'); b.innerHTML = busy ? '<i class="fas fa-stop"></i>' : '<i class="fas fa-arrow-up"></i>'; }
  function stop() { if (state.ctl) state.ctl.abort(); }
  function ctxShow() {
    const c = $('#cbAiCtx'); if (!state.editFrom && !state.replyTo) { c.hidden = true; c.innerHTML = ''; return; }
    c.hidden = false; c.innerHTML = '<i class="fas ' + (state.editFrom ? 'fa-pen' : 'fa-reply') + '"></i><span><b>' + (state.editFrom ? 'Editing your message' : 'Replying to this answer') + '</b><small>' + esc(state.editFrom ? 'Sending replaces it and everything after it.' : state.replyTo.slice(0, 140)) + '</small></span><button type="button" aria-label="Cancel"><i class="fas fa-xmark"></i></button>';
    c.querySelector('button').onclick = ctxClear;
  }
  function ctxClear() { state.editFrom = 0; state.replyTo = ''; ctxShow(); }
  function startEdit(id, text) { if (state.busy) return; state.editFrom = id; state.replyTo = ''; const i = $('#cbAiIn'); i.value = text; i.dispatchEvent(new Event('input')); ctxShow(); i.focus(); }
  function startReply(text) { if (state.busy) return; state.replyTo = String(text).replace(/\s+/g, ' ').trim(); state.editFrom = 0; ctxShow(); $('#cbAiIn').focus(); }
  /* remove a message and everything after it (used by edit and Try again) */
  function cutFrom(id) {
    const t = $('#cbAiThread'), a = t.querySelector('[data-mid="' + id + '"]'); if (!a) return;
    const kids = [...t.children], i = kids.indexOf(a); kids.slice(i).forEach(k => k.remove());
    const n = state.msgs.findIndex(m => m.id === id); if (n >= 0) state.msgs.length = n;
  }


  function renderHist() {
    const q = ($('#cbHistQ').value || '').toLowerCase(), box = $('#cbHistList');
    const list = state.convs.filter(c => !q || c.title.toLowerCase().includes(q));
    if (!list.length) { box.innerHTML = '<div class="cbai-empty">' + (q ? 'No chats match.' : 'Your chats will show up here.') + '</div>'; return; }
    box.innerHTML = list.map(c => '<div class="cbai-row' + (c.id === state.conv ? ' act' : '') + '" role="button" tabindex="0" data-id="' + c.id + '"><div class="flex-grow-1" style="min-width:0"><b>' + esc(c.title) + '</b><small>' + esc(ago(c.updated_at)) + '</small></div><button type="button" class="cbai-x" data-del aria-label="Delete chat"><i class="fas fa-trash-can"></i></button></div>').join('');
    box.querySelectorAll('[data-id]').forEach(r => {
      const id = +r.dataset.id; let armed = 0;
      r.addEventListener('click', e => {
        if (e.target.closest('[data-del]')) {
          e.stopPropagation(); const b = e.target.closest('[data-del]');
          if (!armed) { armed = 1; b.className = 'cbai-x arm'; b.innerHTML = 'Delete?'; setTimeout(() => { armed = 0; if (b.isConnected) renderHist(); }, 2800); return; }
          return removeChat(id);
        }
        openChat(id); histPanel(false);
      });
    });
  }
  async function removeChat(id) {
    await chatApi({ action: 'delete', id }).catch(() => 0);
    state.convs = state.convs.filter(c => c.id !== id);
    if (state.conv === id) { state.conv = 0; state.msgs = []; const next = state.convs[0]; if (next) return openChat(next.id); showWelcome(); }
    renderHist(); toast('Chat deleted');
  }
  async function openChat(id) {
    if (id === state.conv && state.msgs.length) return;
    $('#cbAiThread').innerHTML = '<div class="text-center py-5 text-body-secondary"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>';
    const h = await chatApi(null, 'conv=' + id).catch(() => null); if (!h || !h.ok) return;
    applyHome(h, true);
  }
  function newChat() { if (state.busy) return; state.conv = 0; state.msgs = []; showWelcome(); renderHist(); histPanel(false); closePanel(); $('#cbAiIn').focus(); }

  /* ---------------------------------------------------------------- chat menu */
  async function menu(m) {
    const cur = state.convs.find(c => c.id === state.conv);
    if (m === 'memory') return memoryPanel();
    if (m !== 'copy' && m !== 'share' && !cur) return toast('Start a chat first', 'warning');
    if (m === 'copy' || m === 'share') {
      if (!state.msgs.length) return toast('Nothing to ' + m + ' yet', 'warning');
      const txt = (cur ? cur.title + '\n\n' : '') + state.msgs.map(x => (x.role === 'user' ? 'Me: ' : title() + ': ') + x.text).join('\n\n');
      return m === 'copy' ? copyText(txt) : shareText(cur ? cur.title : title(), txt);
    }
    if (m === 'delete') {
      const d = document.createElement('div');
      d.innerHTML = '<p class="mb-3">Delete <strong>' + esc(cur.title) + '</strong>? This cannot be undone.</p><div class="d-flex gap-2"><button class="btn btn-danger rounded-pill px-4" data-ok>Delete</button><button class="btn btn-outline-secondary rounded-pill px-4" data-no>Keep</button></div>';
      d.querySelector('[data-no]').onclick = closePanel; d.querySelector('[data-ok]').onclick = () => { closePanel(); removeChat(cur.id); };
      return panel('Delete chat', d);
    }
    if (m === 'rename') {
      const d = document.createElement('form');
      d.innerHTML = '<label class="form-label small" for="cbRen">Chat name</label><input id="cbRen" class="form-control mb-3" maxlength="90" required><button class="btn btn-primary rounded-pill px-4">Save</button>';
      d.querySelector('input').value = cur.title;
      d.onsubmit = async e => { e.preventDefault(); const t = d.querySelector('input').value.trim(); if (!t) return; await chatApi({ action: 'rename', id: cur.id, title: t }).catch(() => 0); cur.title = t; renderHist(); closePanel(); };
      panel('Rename chat', d); setTimeout(() => d.querySelector('input').focus(), 50);
    }
  }

  /* ---------------------------------------------------------------- memory */
  async function memoryPanel() {
    const box = document.createElement('div'); box.innerHTML = '<div class="text-center text-body-secondary py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
    panel('What I remember about you', box);
    const r = await chatApi(null, 'memory=1').catch(() => null); const items = (r && r.items) || [];
    const label = { assistant_name: 'My name', user_nickname: 'I call you', fact: '' };
    box.innerHTML = '<p class="text-body-secondary small">I pick these up from our chats and use them to personalise my help. Remove anything that is wrong or that you would rather I forget.</p>' +
      (items.length ? '<ul class="list-group mb-3">' + items.map(i => '<li class="list-group-item d-flex align-items-start gap-2"><span class="flex-grow-1">' + (label[i.kind] ? '<span class="badge text-bg-warning me-2">' + label[i.kind] + '</span>' : '') + esc(i.fact) + '</span><button class="btn btn-sm btn-outline-danger border-0" data-id="' + i.id + '" aria-label="Forget this"><i class="fas fa-xmark"></i></button></li>').join('') + '</ul><button class="btn btn-outline-danger btn-sm rounded-pill" data-clear>Forget everything</button>' :
        '<div class="text-center text-body-secondary py-4"><i class="fas fa-brain fs-2 mb-2 d-block opacity-50"></i>Nothing yet. Tell me how you like things (quiet floor, lower bunk) or give me a name.</div>');
    box.querySelectorAll('[data-id]').forEach(b => b.onclick = async () => { await chatApi({ action: 'memory_delete', id: +b.dataset.id }); b.closest('li').remove(); });
    const c = box.querySelector('[data-clear]'); if (c) c.onclick = async () => { await chatApi({ action: 'memory_clear' }); state.facts = 0; state.assistant = ''; setHead(); memoryPanel(); };
  }

  /* ---------------------------------------------------------------- messages */
  function tools(text) {
    const t = document.createElement('span'); t.className = 'cbai-tools';
    t.innerHTML = '<button type="button" data-a="copy" aria-label="Copy reply"><i class="fas fa-copy"></i></button><button type="button" data-a="share" aria-label="Share reply"><i class="fas fa-share-nodes"></i></button><button type="button" data-a="reply" aria-label="Reply to this answer"><i class="fas fa-reply"></i></button>';
    t.querySelector('[data-a=copy]').onclick = () => copyText(text); t.querySelector('[data-a=share]').onclick = () => shareText(title(), text); t.querySelector('[data-a=reply]').onclick = () => startReply(text); return t;
  }
  function bubble(who, text, blocks, at, id, opt) {
    opt = opt || {}; const d = document.createElement('div'); if (id) d.dataset.mid = id;
    if (who === 'user') {
      const q = String(text).match(/^↪ ([\s\S]*?)\n\n([\s\S]*)$/), body = q ? q[2] : text;
      d.className = 'cbai-u'; d.innerHTML = (q ? '<div class="cbai-quote"><i class="fas fa-reply"></i>' + esc(q[1]) + '</div>' : '') + '<div class="b">' + esc(body) + '</div><small>' + clock(at) + '<span class="cbai-tools"><button type="button" data-a="edit" aria-label="Edit this message"><i class="fas fa-pen"></i></button><button type="button" data-a="copy" aria-label="Copy"><i class="fas fa-copy"></i></button></span></small>';
      d.querySelector('[data-a=edit]').onclick = () => startEdit(+d.dataset.mid || 0, body); d.querySelector('[data-a=copy]').onclick = () => copyText(body);
      if (!id) d.querySelector('[data-a=edit]').hidden = true;
    } else {
      d.className = 'cbai-a' + (opt.err ? ' cbai-err' : '');
      d.innerHTML = orb(34) + '<div class="w"><div class="b">' + md(text) + '</div><div class="cbai-extra" data-extra></div><div data-foot><small>' + clock(at) + '</small></div></div>';
      if (!opt.err) d.querySelector('[data-foot]').appendChild(tools(text));
      const ex = d.querySelector('[data-extra]'); (blocks || []).forEach(b => { const n = block(b); if (n) ex.appendChild(n); });
      if (opt.retry) { const r = document.createElement('button'); r.type = 'button'; r.className = 'btn btn-primary btn-sm rounded-pill mt-2'; r.innerHTML = '<i class="fas fa-rotate-right me-1"></i>Try again'; r.onclick = () => { r.disabled = true; opt.retry(); }; d.querySelector('.w').appendChild(r); }
      wireScroll(d);
    }
    $('#cbAiThread').appendChild(d); down(); return d;
  }
  /* Live "thinking" panel: steps appear as the server works, then fold into a one-line summary above the answer. */
  function thinking() {
    const d = document.createElement('div'); d.className = 'cbai-a'; d.setAttribute('role', 'status');
    d.innerHTML = orb(34) + '<div class="w"><div class="cbai-think open"><button type="button" class="hd" aria-expanded="true"><span class="spin"></span><span class="ttl">Thinking…</span><i class="fas fa-chevron-down ms-auto"></i></button><ol class="steps"></ol></div></div>';
    const box = d.querySelector('.cbai-think'), list = d.querySelector('.steps'), ttl = d.querySelector('.ttl'), t0 = Date.now(); let n = 0, slow = 0, last = 'Thinking';
    const tick = setInterval(() => { const sec = Math.round((Date.now() - t0) / 1000); ttl.textContent = last + '… ' + sec + 's'; if (sec >= 8 && !slow) { slow = 1; const li = document.createElement('li'); li.className = 'step'; li.innerHTML = '<i class="fas fa-circle-notch"></i><span><b>Taking a little longer, switching to a faster connection…</b></span>'; list.appendChild(li); } }, 1000);
    box.querySelector('.hd').onclick = () => { box.classList.toggle('open'); box.querySelector('.hd').setAttribute('aria-expanded', box.classList.contains('open')); };
    $('#cbAiThread').appendChild(d); down();
    return {
      el: d,
      step(e) {
        const prev = list.lastElementChild; if (prev) prev.classList.add('ok');
        const li = document.createElement('li'); li.className = e.kind || 'step';
        li.innerHTML = '<i class="fas ' + (e.kind === 'thought' ? 'fa-lightbulb' : e.kind === 'tool' ? 'fa-magnifying-glass' : 'fa-circle-notch') + '"></i><span><b>' + esc(e.label) + '</b>' + (e.detail && e.kind === 'tool' ? '<small>' + esc(e.detail) + '</small>' : '') + '</span>';
        list.appendChild(li); n++; last = e.kind === 'thought' ? 'Thinking' : e.label; ttl.textContent = last + '…'; down();
      },
      finish() { clearInterval(tick); const s = Math.max(1, Math.round((Date.now() - t0) / 1000)); list.querySelectorAll('li').forEach(l => l.classList.add('ok')); box.classList.remove('open'); box.classList.add('done'); ttl.textContent = n > 1 ? 'Thought for ' + s + 's · ' + n + ' steps' : 'Thought for ' + s + 's'; box.querySelector('.spin').outerHTML = '<i class="fas fa-circle-check text-success"></i>'; box.querySelector('.hd').setAttribute('aria-expanded', 'false'); },
      remove() { clearInterval(tick); d.remove(); }
    };
  }

  /* ---- interactive chart: switch bars / line / donut, tap a point for details, hide items with the eye, sort high-to-low ---- */
  function chartBlock(b) {
    const w = document.createElement('div'); w.className = 'card';
    const pal = ['#003366', '#ffc107', '#4aa3ff', '#198754', '#dc3545', '#6f42c1', '#fd7e14', '#20c997', '#0a4a8f', '#d99a00', '#6c757d', '#0dcaf0'];
    const st = { shape: b.shape || 'bars', hide: new Set(), focus: -1, sort: false };
    const fmt = v => (+v).toLocaleString() + (b.unit ? ' ' + b.unit : '');
    const draw = () => {
      let idx = b.labels.map((_, i) => i); if (st.sort) idx.sort((a, c) => b.values[c] - b.values[a]);
      const vis = idx.filter(i => !st.hide.has(i)), tot = vis.reduce((a, i) => a + b.values[i], 0) || 1, max = Math.max(...vis.map(i => b.values[i]), 1);
      if (st.focus >= 0 && st.hide.has(st.focus)) st.focus = -1;
      const f = st.focus, col = i => pal[i % pal.length];
      const readout = f >= 0 ? '<b>' + esc(b.labels[f]) + '</b> · ' + fmt(b.values[f]) + ' · ' + Math.round(b.values[f] / tot * 100) + '% of shown' : 'Tap a ' + (st.shape === 'bars' ? 'bar' : st.shape === 'line' ? 'point' : 'slice') + ' to see details';
      let body;
      const legend = idx.map(i => '<div class="cbai-lg' + (st.hide.has(i) ? ' off' : '') + (f === i ? ' foc' : '') + '" data-i="' + i + '"><span class="sw" style="background:' + col(i) + '"></span><span class="me-auto text-truncate">' + esc(b.labels[i]) + '</span><b>' + fmt(b.values[i]) + '</b><button type="button" class="eye" data-eye="' + i + '" aria-label="' + (st.hide.has(i) ? 'Show ' : 'Hide ') + esc(b.labels[i]) + '"><i class="fas ' + (st.hide.has(i) ? 'fa-eye-slash' : 'fa-eye') + '"></i></button></div>').join('');
      if (st.shape === 'donut') {
        const R = 15.9155; let off = 25;
        const arcs = vis.map(i => { const p = b.values[i] / tot * 100, a = '<circle data-i="' + i + '" class="cbai-arc" r="' + R + '" cx="21" cy="21" fill="none" stroke="' + col(i) + '" stroke-width="' + (f === i ? 7.6 : 6) + '" stroke-dasharray="' + p + ' ' + (100 - p) + '" stroke-dashoffset="' + off + '"><title>' + esc(b.labels[i]) + ': ' + fmt(b.values[i]) + '</title></circle>'; off -= p; return a; }).join('');
        body = '<div class="d-flex align-items-center gap-3 flex-wrap"><svg viewBox="0 0 42 42" width="170" height="170" role="img" aria-label="' + esc(b.title) + '"><circle r="' + R + '" cx="21" cy="21" fill="none" stroke="#e9eef5" stroke-width="6"/>' + arcs + '<text x="21" y="22.4" text-anchor="middle" font-size="5" font-weight="800" fill="#003366">' + (f >= 0 ? (+b.values[f]).toLocaleString() : tot.toLocaleString()) + '</text><text x="21" y="27" text-anchor="middle" font-size="2.6" fill="#5b6b7e">' + esc(f >= 0 ? b.labels[f].slice(0, 16) : 'total') + '</text></svg><div class="flex-grow-1" style="min-width:180px">' + legend + '</div></div>';
      } else if (st.shape === 'line') {
        const W = 300, H = 140, P = 16, n = vis.length, x = k => P + (n < 2 ? (W - 2 * P) / 2 : k * (W - 2 * P) / (n - 1)), y = v => H - P - v / max * (H - 2 * P - 6);
        const pts = vis.map((i, k) => x(k) + ',' + y(b.values[i])).join(' ');
        body = '<svg viewBox="0 0 ' + W + ' ' + (H + 18) + '" width="100%" role="img" aria-label="' + esc(b.title) + '"><defs><linearGradient id="cbg" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#4aa3ff" stop-opacity=".45"/><stop offset="1" stop-color="#4aa3ff" stop-opacity="0"/></linearGradient></defs><line x1="' + P + '" x2="' + (W - P) + '" y1="' + (H - P) + '" y2="' + (H - P) + '" stroke="#dbe4f0"/>' + (n ? '<polygon points="' + x(0) + ',' + (H - P) + ' ' + pts + ' ' + x(n - 1) + ',' + (H - P) + '" fill="url(#cbg)"/><polyline points="' + pts + '" fill="none" stroke="#003366" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>' : '') + vis.map((i, k) => (f === i ? '<line x1="' + x(k) + '" x2="' + x(k) + '" y1="' + y(b.values[i]) + '" y2="' + (H - P) + '" stroke="#ffc107" stroke-dasharray="3 3"/>' : '') + '<circle data-i="' + i + '" cx="' + x(k) + '" cy="' + y(b.values[i]) + '" r="' + (f === i ? 6 : 4) + '" fill="#ffc107" stroke="#003366" stroke-width="1.6" style="cursor:pointer"><title>' + esc(b.labels[i]) + ': ' + fmt(b.values[i]) + '</title></circle>' + (n <= 8 || k % 2 === 0 ? '<text x="' + x(k) + '" y="' + (H + 10) + '" text-anchor="middle" font-size="8" fill="#5b6b7e">' + esc(b.labels[i].slice(0, 9)) + '</text>' : '')).join('') + '</svg><div class="mt-2">' + legend + '</div>';
      } else {
        body = idx.map(i => '<div class="cbai-lg bar' + (st.hide.has(i) ? ' off' : '') + (f === i ? ' foc' : '') + '" data-i="' + i + '"><div class="d-flex justify-content-between small mb-1"><span class="text-truncate pe-2">' + esc(b.labels[i]) + '</span><span><b>' + fmt(b.values[i]) + '</b><button type="button" class="eye" data-eye="' + i + '" aria-label="' + (st.hide.has(i) ? 'Show ' : 'Hide ') + esc(b.labels[i]) + '"><i class="fas ' + (st.hide.has(i) ? 'fa-eye-slash' : 'fa-eye') + '"></i></button></span></div><div class="progress" role="progressbar" aria-label="' + esc(b.labels[i]) + '" aria-valuenow="' + b.values[i] + '" aria-valuemin="0" aria-valuemax="' + max + '"><div class="progress-bar" style="width:' + (st.hide.has(i) ? 0 : Math.max(2, Math.round(b.values[i] / max * 100))) + '%;background:' + col(i) + ' !important"></div></div></div>').join('');
      }
      w.innerHTML = '<div class="card-header bg-body d-flex align-items-center flex-wrap gap-2 py-2"><span class="fw-semibold small me-auto"><i class="fas fa-chart-simple text-primary me-1"></i>' + esc(b.title) + '</span><div class="btn-group btn-group-sm" role="group" aria-label="Chart type">' + [['bars', 'fa-chart-bar', 'Bars'], ['line', 'fa-chart-line', 'Line'], ['donut', 'fa-chart-pie', 'Donut']].map(t => '<button type="button" class="btn btn-outline-primary' + (st.shape === t[0] ? ' active' : '') + '" data-shape="' + t[0] + '" aria-label="' + t[2] + '"><i class="fas ' + t[1] + '"></i></button>').join('') + '<button type="button" class="btn btn-outline-primary' + (st.sort ? ' active' : '') + '" data-sort aria-label="Sort high to low"><i class="fas fa-arrow-down-wide-short"></i></button></div></div><div class="card-body p-3"><div class="cbai-readout small mb-2" aria-live="polite">' + readout + '</div>' + body + '</div>';
      w.querySelectorAll('[data-shape]').forEach(x => x.onclick = () => { st.shape = x.dataset.shape; draw(); });
      w.querySelector('[data-sort]').onclick = () => { st.sort = !st.sort; draw(); };
      w.querySelectorAll('[data-i]').forEach(x => x.addEventListener('click', e => { if (e.target.closest('[data-eye]')) return; st.focus = +x.dataset.i === st.focus ? -1 : +x.dataset.i; draw(); }));
      w.querySelectorAll('[data-eye]').forEach(x => x.onclick = e => { e.stopPropagation(); const i = +x.dataset.eye; st.hide.has(i) ? st.hide.delete(i) : st.hide.add(i); draw(); });
    };
    draw(); return w;
  }

  /* ---- guided questions: one at a time, tap to answer. Quiz mode (correct answers given) marks each answer and scores at the end ---- */
  function stepsBlock(b) {
    const w = document.createElement('div'); w.className = 'card cbai-steps'; const N = b.qs.length, ans = [];
    let k = 0, locked = false;
    const ordinal = i => 'Question ' + (i + 1) + ' of ' + N;
    const finish = () => {
      if (b.quiz) {
        const ok = b.qs.filter((q, i) => q.ans && ans[i] === q.ans).length, tot = b.qs.filter(q => q.ans).length, pct = Math.round(ok / (tot || 1) * 100);
        const miss = b.qs.map((q, i) => ({ q, i })).filter(x => x.q.ans && ans[x.i] !== x.q.ans);
        w.innerHTML = '<div class="card-body p-3 text-center"><div class="cbai-score" style="--p:' + pct + '"><b>' + ok + '/' + tot + '</b><small>' + pct + '%</small></div><div class="fw-bold fs-5 mt-2">' + (pct >= 80 ? 'Excellent work!' : pct >= 50 ? 'Good effort, nearly there' : 'Let us go over it together') + '</div><div class="small text-body-secondary">' + esc(b.title) + '</div>' +
          (miss.length ? '<div class="text-start mt-3"><div class="fw-semibold small mb-2">Worth another look</div>' + miss.map(x => '<div class="cbai-miss"><b>' + esc(x.q.q) + '</b><div class="small"><span class="text-danger">Your answer: ' + esc(ans[x.i] || '-') + '</span> · <span class="text-success">Correct: ' + esc(x.q.ans) + '</span></div>' + (x.q.why ? '<div class="small text-body-secondary">' + esc(x.q.why) + '</div>' : '') + '</div>').join('') + '</div>' : '') + '</div>';
        const act = document.createElement('div'); act.className = 'd-flex flex-wrap gap-2 justify-content-center px-3 pb-3';
        act.innerHTML = (miss.length ? '<button class="btn btn-primary btn-sm rounded-pill" data-explain><i class="fas fa-lightbulb me-1"></i>Explain what I missed</button>' : '') + '<button class="btn btn-outline-primary btn-sm rounded-pill" data-again><i class="fas fa-rotate me-1"></i>Quiz me again</button>';
        w.appendChild(act); const ex = act.querySelector('[data-explain]'), ag = act.querySelector('[data-again]');
        if (ex) ex.onclick = () => send('Explain the questions I missed in the quiz: ' + miss.map(x => x.q.q).join('; '));
        ag.onclick = () => send('Quiz me again with 3 new questions');
      } else {
        w.innerHTML = '<div class="card-body p-3"><div class="fw-bold mb-1"><i class="fas fa-circle-check text-success me-1"></i>All done: check your answers</div><ul class="list-group list-group-flush mb-3">' + b.qs.map((q, i) => '<li class="list-group-item px-0 small"><span class="text-body-secondary">' + esc(q.q) + '</span><br><b>' + esc(ans[i] || '-') + '</b></li>').join('') + '</ul><div class="d-flex gap-2"><button class="btn btn-primary rounded-pill px-4" data-go>Submit</button><button class="btn btn-outline-secondary rounded-pill" data-redo>Change answers</button></div></div>';
        w.querySelector('[data-go]').onclick = e => { e.target.disabled = true; w.querySelector('[data-redo]').disabled = true; send(b.title + ' — my answers: ' + b.qs.map((q, i) => q.q + ' → ' + (ans[i] || '-')).join('; ')); };
        w.querySelector('[data-redo]').onclick = () => { k = 0; ans.length = 0; show(); };
      }
    };
    const show = () => {
      const q = b.qs[k]; locked = false; const picked = new Set();
      w.innerHTML = '<div class="progress" style="height:6px;border-radius:0" role="progressbar" aria-label="Progress" aria-valuenow="' + k + '" aria-valuemin="0" aria-valuemax="' + N + '"><div class="progress-bar" style="width:' + (k / N * 100) + '%"></div></div><div class="card-body p-3">' +
        (k === 0 && b.intro ? '<div class="small text-body-secondary mb-2">' + esc(b.intro) + '</div>' : '') +
        '<div class="d-flex justify-content-between small text-body-secondary mb-1"><span>' + (b.quiz ? '<i class="fas fa-graduation-cap me-1"></i>' : '') + esc(b.title) + '</span><span>' + ordinal(k) + '</span></div><div class="fw-semibold fs-6 mb-3">' + esc(q.q) + '</div>' +
        '<div class="d-grid gap-2" data-o>' + q.opts.map((o, i) => '<button type="button" class="btn btn-outline-primary text-start rounded-4 py-2 px-3 cbai-opt" data-v="' + i + '"><span class="cbai-key">' + 'ABCDEF'[i] + '</span>' + esc(o) + '</button>').join('') + '</div>' +
        '<div data-fb class="mt-3"></div><div class="d-flex justify-content-between mt-3">' + (k > 0 && !b.quiz ? '<button class="btn btn-link btn-sm text-body-secondary p-0" data-back><i class="fas fa-arrow-left me-1"></i>Back</button>' : '<span></span>') + (q.multi ? '<button class="btn btn-primary btn-sm rounded-pill px-3" data-ok disabled>Next</button>' : '<span></span>') + '</div></div>';
      const next = () => { k++; k >= N ? finish() : show(); };
      const reveal = val => {
        locked = true; ans[k] = val;
        if (b.quiz && q.ans) {
          const right = val === q.ans;
          w.querySelectorAll('[data-v]').forEach(x => { const t = q.opts[+x.dataset.v]; x.disabled = true; if (t === q.ans) { x.classList.replace('btn-outline-primary', 'btn-success'); } else if (q.multi ? val.split(', ').includes(t) : t === val) x.classList.replace('btn-outline-primary', 'btn-danger'); });
          w.querySelector('[data-fb]').innerHTML = '<div class="cbai-fb ' + (right ? 'good' : 'bad') + '"><b><i class="fas ' + (right ? 'fa-circle-check' : 'fa-circle-xmark') + ' me-1"></i>' + (right ? 'Correct!' : 'Not quite. The answer is ' + esc(q.ans)) + '</b>' + (q.why ? '<div class="small mt-1">' + esc(q.why) + '</div>' : '') + '</div><button class="btn btn-primary rounded-pill px-4 mt-3" data-next>' + (k === N - 1 ? 'See my score' : 'Next question') + ' <i class="fas fa-arrow-right ms-1"></i></button>';
          w.querySelector('[data-next]').onclick = next; w.querySelector('[data-next]').focus();
        } else setTimeout(next, 220);
      };
      w.querySelectorAll('[data-v]').forEach(x => x.onclick = () => {
        if (locked) return; const t = q.opts[+x.dataset.v];
        if (!q.multi) { x.classList.replace('btn-outline-primary', 'btn-primary'); reveal(t); }
        else { picked.has(t) ? picked.delete(t) : picked.add(t); x.classList.toggle('btn-outline-primary'); x.classList.toggle('btn-primary'); w.querySelector('[data-ok]').disabled = !picked.size; }
      });
      const ok = w.querySelector('[data-ok]'); if (ok) ok.onclick = () => { if (!locked && picked.size) reveal([...picked].join(', ')); };
      const bk = w.querySelector('[data-back]'); if (bk) bk.onclick = () => { k--; show(); };
    };
    show(); return w;
  }

  /* ---- a document the assistant made ---- */
  function fileBlock(b) {
    const w = document.createElement('div'); w.className = 'card cbai-file'; const pdf = b.fmt === 'pdf', kb = b.size > 1048576 ? (b.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b.size / 1024)) + ' KB', url = root + '/api/ai_doc.php?id=' + b.id;
    w.innerHTML = '<div class="card-body d-flex align-items-center gap-3 p-3"><span class="cbai-fi ' + (pdf ? 'pdf' : 'doc') + '"><i class="fas ' + (pdf ? 'fa-file-pdf' : 'fa-file-word') + '"></i></span><div class="me-auto" style="min-width:0"><div class="fw-semibold text-truncate">' + esc(b.title || b.name) + '</div><div class="small text-body-secondary">' + (pdf ? 'PDF' : 'Word') + ' · ' + (b.land ? 'landscape' : 'A4 portrait') + ' · ' + kb + ' · ' + esc(b.name) + '</div></div>' + (pdf ? '<a class="btn btn-outline-primary btn-sm rounded-pill" href="' + url + '&view=1" target="_blank" rel="noopener">Preview</a>' : '') + '<a class="btn btn-primary btn-sm rounded-pill" href="' + url + '" download><i class="fas fa-download me-1"></i>Download</a></div>';
    return w;
  }

  /* ---- hostel layout: one designed card per building ---- */
  function layoutBlock(b) {
    const w = document.createElement('div'); w.className = 'cbai-lay';
    const tot = b.blocks.reduce((a, x) => ({ rooms: a.rooms + x.rooms, beds: a.beds + x.beds }), { rooms: 0, beds: 0 });
    w.innerHTML = '<div class="cbai-sttl"><i class="fas fa-drafting-compass me-1"></i>' + esc(b.title || 'Hostel layout') + ' <span class="text-body-secondary fw-normal">· ' + b.blocks.length + ' buildings · ' + tot.rooms + ' rooms · ' + tot.beds + ' beds</span></div><div class="cbai-lgrid">' +
      b.blocks.map(x => {
        const per = Math.max(1, Math.round(x.rooms / Math.max(1, x.floors))), g = String(x.gender || '').toLowerCase(), fem = g.startsWith('f');
        const floors = Array.from({ length: x.floors }, (_, i) => '<div class="fl" style="--w:' + (62 + (i * 6) % 30) + '%"><span>Floor ' + (x.floors - i) + '</span><em>~' + per + ' rooms</em></div>').join('');
        return '<div class="cbai-bld ' + (fem ? 'f' : 'm') + '"><div class="hd"><span class="ic"><i class="fas fa-building"></i></span><b>' + esc(x.name) + '</b><span class="gp">' + (fem ? 'Female' : 'Male') + '</span></div>' +
          '<div class="big"><b>' + x.beds + '</b><span>beds</span></div><div class="stk">' + floors + '</div>' +
          '<div class="mini"><div><b>' + x.floors + '</b><small>floors</small></div><div><b>' + x.rooms + '</b><small>rooms</small></div><div><b>' + x.bunks + '</b><small>bunks</small></div><div><b>' + x.singles + '</b><small>singles</small></div></div>' +
          '<div class="ft"><i class="fas fa-bath"></i> ' + (x.ensuite ? x.ensuite + ' ensuite rooms' : 'Shared bathrooms') + '</div></div>';
      }).join('') + '</div>';
    return w;
  }

  /* rich blocks the assistant can draw */
  function block(b) {
    const w = document.createElement('div');
    if (b.t === 'choices') {
      w.className = 'card border-primary-subtle shadow-sm'; const multi = !!b.multi, picked = new Set();
      w.innerHTML = '<div class="card-body p-3"><div class="fw-semibold mb-2"><i class="fas fa-hand-pointer text-primary me-1"></i>' + esc(b.q) + '</div><div class="d-flex flex-wrap gap-2" data-o></div>' + (multi ? '<button class="btn btn-primary btn-sm rounded-pill mt-3" data-done disabled>Done</button>' : '') + '</div>';
      const box = w.querySelector('[data-o]');
      const lock = () => { w.querySelectorAll('button').forEach(x => x.disabled = true); };
      b.opts.forEach(o => { const x = document.createElement('button'); x.type = 'button'; x.className = 'btn btn-outline-primary rounded-pill'; x.textContent = o;
        x.onclick = () => { if (!multi) { x.className = 'btn btn-primary rounded-pill'; lock(); send(o); } else { picked.has(o) ? picked.delete(o) : picked.add(o); x.classList.toggle('active'); w.querySelector('[data-done]').disabled = !picked.size; } }; box.appendChild(x); });
      const dn = w.querySelector('[data-done]'); if (dn) dn.onclick = () => { lock(); send([...picked].join(', ')); };
    } else if (b.t === 'table') {
      w.className = 'card shadow-sm';
      w.innerHTML = '<div class="card-header bg-body d-flex align-items-center gap-2 py-2"><span class="fw-semibold small me-auto text-truncate">' + esc(b.title || 'Table') + '</span><span class="badge text-bg-light border">' + b.rows.length + ' rows</span><button class="btn btn-sm btn-outline-secondary py-0" data-csv><i class="fas fa-download me-1"></i>CSV</button><button class="btn btn-sm btn-outline-danger py-0" data-pdf><i class="fas fa-file-pdf me-1"></i>PDF</button></div>' + tableHtml(b.cols, b.rows);
      w.querySelector('[data-csv]').onclick = () => {
        const q = v => '"' + String(v).replace(/"/g, '""') + '"', csv = [b.cols, ...b.rows].map(r => r.map(q).join(',')).join('\n');
        const a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' })); a.download = (b.title || 'table').replace(/[^\w]+/g, '_') + '.csv'; a.click(); setTimeout(() => URL.revokeObjectURL(a.href), 2000);
      };
      w.querySelector('[data-pdf]').onclick = () => send('Make a PDF of "' + (b.title || 'this table') + '" with the same data');
      wireScroll(w);
    } else if (b.t === 'layout') {
      return layoutBlock(b);
    } else if (b.t === 'stats') {
      w.className = 'cbai-stats';
      w.innerHTML = (b.title ? '<div class="cbai-sttl">' + esc(b.title) + '</div>' : '') + '<div class="row g-2">' + b.items.map(i => '<div class="col-6 col-md"><div class="cbai-stat-tile"><small>' + esc(i.label) + '</small><b>' + esc(i.value) + '</b>' + (i.hint ? '<em>' + esc(i.hint) + '</em>' : '') + '</div></div>').join('') + '</div>';
    } else if (b.t === 'chart') { return chartBlock(b);
    } else if (b.t === 'steps') { return stepsBlock(b);
    } else if (b.t === 'file') { return fileBlock(b);
    } else if (b.t === 'form') {
      w.className = 'card border-primary-subtle shadow-sm';
      const f = document.createElement('form'); f.className = 'card-body p-3';
      f.innerHTML = '<div class="fw-semibold mb-1"><i class="fas fa-pen-to-square text-primary me-1"></i>' + esc(b.title) + '</div>' + (b.intro ? '<p class="small text-body-secondary mb-3">' + esc(b.intro) + '</p>' : '<div class="mb-2"></div>') +
        b.fields.map((x, i) => { const id = 'cbf' + Date.now() + i, req = x.required ? ' required' : '', ph = x.placeholder ? ' placeholder="' + esc(x.placeholder) + '"' : '';
          const ctl = x.type === 'select' ? '<select class="form-select" id="' + id + '" name="' + esc(x.name) + '"' + req + '><option value="">Choose…</option>' + x.options.map(o => '<option' + (o === x.value ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>'
            : x.type === 'textarea' ? '<textarea class="form-control" rows="3" id="' + id + '" name="' + esc(x.name) + '"' + req + ph + '>' + esc(x.value) + '</textarea>'
              : '<input class="form-control" id="' + id + '" name="' + esc(x.name) + '" type="' + (x.type === 'number' ? 'number' : x.type === 'date' ? 'date' : 'text') + '" value="' + esc(x.value) + '"' + req + ph + '>';
          return '<div class="mb-2"><label class="form-label small mb-1" for="' + id + '">' + esc(x.label || x.name) + (x.required ? ' <span class="text-danger">*</span>' : '') + '</label>' + ctl + '</div>'; }).join('') +
        '<button class="btn btn-primary rounded-pill px-4 mt-2">' + esc(b.submit || 'Send') + '</button>';
      f.onsubmit = e => { e.preventDefault(); const parts = b.fields.map(x => { const v = f.elements[x.name] && f.elements[x.name].value.trim(); return v ? (x.label || x.name) + ': ' + v : ''; }).filter(Boolean);
        f.querySelectorAll('input,select,textarea,button').forEach(x => x.disabled = true); send(b.title + ' — ' + parts.join('; ')); };
      w.appendChild(f);
    } else if (b.t === 'link') {
      w.innerHTML = '<a class="btn btn-primary rounded-pill" href="' + esc(b.href) + '"><i class="fas ' + esc(b.icon || 'fa-arrow-right') + ' me-2"></i>' + esc(b.label) + '<i class="fas fa-arrow-right ms-2"></i></a>';
    } else if (b.t === 'note') {
      w.innerHTML = '<span class="badge text-bg-light border fw-normal text-body-secondary py-2 px-3"><i class="fas ' + esc(b.icon || 'fa-brain') + ' me-1"></i>' + esc(b.text) + '</span>';
      if (b.kind === 'assistant_name' && b.val) { state.assistant = b.val; setHead(); }
    } else return null;
    return w;
  }

  /* an action the assistant prepared: details already filled in, editable, then ONE press. Sensitive ones also ask for a code or password. */
  function card(c) {
    const d = document.createElement('div'); d.className = 'cbai-a';
    const fields = (c.fields || []).map((f, i) => { const id = 'cbact' + c.id + '_' + i;
      const ctl = f.type === 'select' ? '<select class="form-select form-select-sm" id="' + id + '" data-f="' + esc(f.name) + '">' + f.options.map(o => '<option' + (o === f.value ? ' selected' : '') + '>' + esc(o) + '</option>').join('') + '</select>'
        : f.type === 'textarea' ? '<textarea class="form-control form-control-sm" rows="2" id="' + id + '" data-f="' + esc(f.name) + '">' + esc(f.value) + '</textarea>'
          : '<input class="form-control form-control-sm" id="' + id + '" data-f="' + esc(f.name) + '" type="' + (f.type === 'number' ? 'number' : 'text') + '" value="' + esc(f.value) + '">';
      return '<div class="col-12 col-sm-6' + (f.type === 'textarea' ? ' col-sm-12' : '') + '"><label class="form-label small mb-1" for="' + id + '">' + esc(f.label) + '</label>' + ctl + '</div>'; }).join('');
    d.innerHTML = '<div class="flex-shrink-0" style="width:34px"></div><div class="card cbai-act flex-grow-1" style="max-width:100%"><div class="card-body p-3"><div class="d-flex align-items-center gap-2 mb-2"><span class="badge text-bg-warning"><i class="fas fa-bolt me-1" aria-hidden="true"></i>Ready when you are</span>' + (c.sens ? '<span class="badge text-bg-light border"><i class="fas fa-shield-halved me-1"></i>' + (c.sens === 'password' ? 'asks for your password' : 'asks for an emailed code') + '</span>' : '') + '</div>' +
      '<p class="mb-2" data-sum>' + esc(c.summary) + '</p>' + (fields ? '<div class="row g-2 mb-3" data-fields>' + fields + '</div>' : '') + '<div class="d-flex gap-2 flex-wrap" data-btns><button type="button" class="btn btn-success rounded-pill px-3" data-d="confirm"><i class="fas fa-check me-1" aria-hidden="true"></i>' + esc(c.btn || 'Confirm') + '</button><button type="button" class="btn btn-outline-secondary rounded-pill px-3" data-d="cancel">Cancel</button></div><div class="small text-danger mt-2" data-err hidden></div></div></div>';
    const body = () => d.querySelector('.card-body');
    const finish = r => {
      body().innerHTML = '<div class="d-flex gap-2 align-items-start ' + (r.ok ? 'text-success' : 'text-danger') + '" role="status"><i class="fas ' + (r.ok ? 'fa-circle-check' : 'fa-circle-xmark') + ' mt-1" aria-hidden="true"></i><span>' + esc(r.message || (r.ok ? 'Done.' : 'Could not do that.')) + '</span></div>' + (r.pay ? '<div class="cbai-pay mt-2"></div>' : ''); down();
      if (r.pay) livePay(body().querySelector('.cbai-pay'), r.pay);
      if (r.next_card) card(r.next_card);
      document.dispatchEvent(new CustomEvent('cb:changed', { detail: r }));
    };
    // live result of an M-Pesa prompt, right inside the card: waiting -> paid / cancelled / wrong PIN / no answer ...
    const livePay = (box, pay) => {
      const id = pay.id, stu = !!pay.student;
      let n = 0, off = 0;
      const draw = v => { const t = { success: 'success', warning: 'warning', danger: 'danger' }[v.tone] || 'info', ic = { success: 'fa-circle-check', warning: 'fa-circle-exclamation', danger: 'fa-circle-xmark', info: 'fa-spinner fa-spin' }[t];
        box.innerHTML = '<div class="alert alert-' + t + ' py-2 mb-0 small" role="status" aria-live="polite"><i class="fas ' + ic + ' me-2"></i><b>' + esc(v.title) + '.</b> ' + esc(v.message) + '</div>'; down(); };
      const tick = async () => {
        const v = await (stu ? call('mpesa_status.php', null, 'booking_id=' + (pay.booking_id || 0)) : call('staff_payment.php', null, 'action=status&id=' + id)).catch(() => null);
        if (v && stu && (v.status === 'success' || (v.booking_status && v.booking_status !== 'pending_payment' && v.status !== 'none'))) { draw({ tone: 'success', title: 'Payment received', message: v.message || 'Thank you! Your booking is now waiting for approval. We emailed you and the hostel office.' }); document.dispatchEvent(new CustomEvent('cb:changed', { detail: { paid: true } })); return; }
        if (v && stu && v.status === 'none') { draw({ tone: 'info', title: 'Waiting for the prompt', message: 'Check your phone.' }); }
        if (!v) { off++; box.innerHTML = '<div class="alert alert-secondary py-2 mb-0 small">Connection problem. Still checking…</div>'; }
        else if (v.ok === false) { draw({ tone: 'warning', title: 'Could not check', message: v.message || 'Open Collect payment to see the result.' }); return; }
        else { off = 0; draw(v); if (v.final) return; }
        if (++n < 120) setTimeout(tick, off ? 5000 : 3000); else draw({ tone: 'warning', title: 'Still waiting', message: 'No result yet. If the student paid, it updates by itself; see Collect payment.' });
      };
      tick();
    };
    const ask = r => {
      const pw = r.need === 'password';
      body().innerHTML = '<div class="d-flex gap-2 mb-2" role="status"><i class="fas fa-shield-halved text-warning mt-1" aria-hidden="true"></i><span>' + esc(r.message) + '</span></div><form class="d-flex gap-2"><label class="visually-hidden" for="stepup' + c.id + '">' + (pw ? 'Your password' : '6-digit code') + '</label><input id="stepup' + c.id + '" class="form-control" type="' + (pw ? 'password' : 'text') + '" ' + (pw ? 'autocomplete="current-password"' : 'inputmode="numeric" maxlength="6" autocomplete="one-time-code"') + ' placeholder="' + (pw ? 'Your password' : '6-digit code') + '" required><button class="btn btn-primary">Verify</button></form>';
      const f = body().querySelector('form'); f.querySelector('input').focus();
      f.addEventListener('submit', async ev => { ev.preventDefault(); const code = f.querySelector('input').value; f.querySelector('button').disabled = true; const x = await call('ai_confirm.php', { id: c.id, decision: 'confirm', code }).catch(() => ({ message: 'Network problem.' })); if (x.need) ask(x); else finish(x); });
    };
    d.addEventListener('click', async e => {
      const b = e.target.closest('button[data-d]'); if (!b) return; const err = d.querySelector('[data-err]'); err.hidden = true;
      const all = d.querySelectorAll('button, input, select, textarea'); all.forEach(x => x.disabled = true);
      if (b.dataset.d === 'confirm') {
        const edits = {}; d.querySelectorAll('[data-f]').forEach(x => { edits[x.dataset.f] = x.value; });
        if (Object.keys(edits).length) {                    /* save what they changed (re-checked by the server) before the real action runs */
          const ed = await call('ai_action_edit.php', { id: c.id, args: edits }).catch(() => ({ ok: false, message: 'Network problem.' }));
          if (!ed.ok) { all.forEach(x => x.disabled = false); err.textContent = ed.message || 'Could not use those details.'; err.hidden = false; return; }
          d.querySelector('[data-sum]').textContent = ed.summary;
        }
      }
      const r = await call('ai_confirm.php', { id: c.id, decision: b.dataset.d }).catch(() => ({ ok: false, message: 'Network problem.' }));
      if (r.need) ask(r); else finish(r);
    });
    $('#cbAiThread').appendChild(d); down();
  }

  /* ---------------------------------------------------------------- welcome + chips */
  function showWelcome() {
    const t = $('#cbAiThread'); const sug = SUGGEST[state.kind] || SUGGEST.student;
    const role = { student: 'your bed and booking', secretary: 'approvals and placements', dean: 'your hostels and students', admin: 'the whole system' }[state.kind] || 'ComfortBook';
    t.innerHTML = '<div class="cbai-hero">' + orb(84) + '<h1>' + (state.name ? 'Hi ' + esc(state.name) + ', ' : '') + 'how can I help?</h1><p>I can look things up, draw tables and charts, fill forms with you and do things for you. Anything that changes data asks you to confirm first. Ask me about ' + esc(role) + '.</p></div>' +
      '<div class="cbai-sug">' + sug.map((s, i) => '<button type="button" data-ask="' + i + '"><span class="ic"><i class="fas ' + s[0] + '" aria-hidden="true"></i></span><span><b>' + esc(s[1]) + '</b><span class="s">' + esc(s[2]) + '</span></span></button>').join('') + '</div>';
    t.querySelectorAll('[data-ask]').forEach(b => b.addEventListener('click', () => { const s = sug[+b.dataset.ask]; send(s[1] + ' — ' + s[2]); }));
  }
  function chips() {
    const c = $('#cbAiChips'); c.innerHTML = '';
    (SUGGEST[state.kind] || SUGGEST.student).forEach(s => { const b = document.createElement('button'); b.type = 'button'; b.setAttribute('role', 'listitem'); b.innerHTML = '<i class="fas ' + s[0] + '" aria-hidden="true"></i>' + esc(s[1]); b.onclick = () => send(s[1] + ' — ' + s[2]); c.appendChild(b); });
  }

  /* ---------------------------------------------------------------- load + send */
  function applyHome(h, keepConvs) {
    Object.assign(state, { kind: h.kind, name: h.name || '', assistant: h.assistant || '', live: !!h.live, loaded: true, facts: h.facts || 0, conv: h.active || 0 });
    if (!keepConvs || h.convs) state.convs = h.convs || state.convs;
    setHead(); chips(); renderHist();
    $('#cbAiMode').innerHTML = h.live ? 'AI can make mistakes · changes always ask you to confirm' : '<span class="text-warning-emphasis"><i class="fas fa-triangle-exclamation me-1" aria-hidden="true"></i>Basic mode — an admin needs to add a working Gemini key (Admin → AI &amp; payments)</span>';
    const t = $('#cbAiThread'); t.innerHTML = ''; state.msgs = [];
    if (!h.history.length && !(h.cards || []).length) showWelcome();
    else { h.history.forEach(m => { state.msgs.push({ id: m.id, role: m.role === 'user' ? 'user' : 'model', text: m.content }); bubble(m.role === 'user' ? 'user' : 'model', m.content, m.blocks, m.at, m.id); }); (h.cards || []).forEach(card); }
    down();
  }
  async function render() {
    $('#cbAiThread').innerHTML = '<div class="text-center py-5 text-body-secondary"><span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Loading…</div>';
    const h = await chatApi(null).catch(() => null);
    if (!h || !h.ok) { $('#cbAiThread').innerHTML = '<div class="alert alert-warning" role="alert">' + esc((h && h.error) || 'Please sign in to use the assistant.') + '</div>'; return; }
    applyHome(h);
  }
  async function send(text, o) {
    text = (text || '').trim(); if (!text || state.busy) return; o = o || {};
    let edit = o.editFrom || state.editFrom || 0; const quote = state.replyTo; ctxClear();
    const inp = $('#cbAiIn'); inp.value = ''; inp.style.height = 'auto'; closePanel();
    if ($('#cbAiThread').querySelector('[data-ask]')) $('#cbAiThread').innerHTML = '';
    if (edit) cutFrom(edit);
    state.busy = true; sendMode(true); const ctl = new AbortController(); state.ctl = ctl;
    const shown = quote ? '↪ ' + quote.slice(0, 220) + '\n\n' + text : text;
    state.msgs.push({ role: 'user', text: shown }); const ub = bubble('user', shown); const wait = thinking(); status('Thinking…', true);
    const r = await chatStream({ message: text, conv_id: state.conv, page: location.pathname.split('/').pop(), edit_from: edit, reply_to: quote }, st => { wait.step(st); status(st.kind === 'thought' ? 'Thinking…' : st.label, true); }, ctl.signal);
    state.busy = false; state.ctl = null; sendMode(false); status('Ready');
    if (r.aborted) { wait.remove(); const n = document.createElement('div'); n.className = 'cbai-stopped'; n.innerHTML = '<i class="fas fa-circle-stop me-1"></i>Stopped. Edit your message or ask something else.'; $('#cbAiThread').appendChild(n); down(); return; }
    if (r.ok && !r.failed) wait.finish(); else wait.remove();
    if (r.uid) { ub.dataset.mid = r.uid; const eb = ub.querySelector('[data-a=edit]'); if (eb) eb.hidden = false; const m = state.msgs[state.msgs.length - 1]; if (m) m.id = r.uid; }
    const bad = !r.ok || r.failed, reply = r.ok ? r.reply : (r.error || 'Something went wrong.');
    if (!bad) state.msgs.push({ id: r.mid, role: 'model', text: reply });
    bubble('model', reply, r.ok ? r.blocks : [], null, r.mid, { err: bad, retry: bad && r.retry !== false ? () => send(text, { editFrom: r.uid || 0 }) : null }); (r.cards || []).forEach(card);
    if (r.ok && r.conv) { state.conv = r.conv.id; const ex = state.convs.find(c => c.id === r.conv.id); if (ex) { ex.title = r.conv.title; ex.updated_at = new Date().toISOString(); state.convs = [ex, ...state.convs.filter(c => c !== ex)]; } else state.convs.unshift({ id: r.conv.id, title: r.conv.title, updated_at: new Date().toISOString() }); renderHist(); }
    if (window.innerWidth >= 992) inp.focus();
  }

  /* voice input where the browser supports it */
  function initVoice() {
    const SR = window.SpeechRecognition || window.webkitSpeechRecognition; if (!SR) return;
    const btn = $('#cbAiMic'); btn.classList.remove('d-none'); let rec = null;
    btn.onclick = () => {
      if (rec) { rec.stop(); return; }
      rec = new SR(); rec.lang = navigator.language || 'en-KE'; rec.interimResults = true;
      btn.classList.add('rec');
      rec.onresult = e => { $('#cbAiIn').value = [...e.results].map(r => r[0].transcript).join(' '); $('#cbAiIn').dispatchEvent(new Event('input')); };
      rec.onend = () => { rec = null; btn.classList.remove('rec'); };
      rec.onerror = e => { rec = null; btn.classList.remove('rec'); toast(e && e.error === 'not-allowed' ? 'Microphone permission is off. Allow it in your browser settings.' : 'Could not use the microphone.', 'warning'); };
      rec.start();
    };
  }

  async function open(prompt) {
    if (!el) build();
    el.classList.add('on'); document.documentElement.style.overflow = 'hidden'; document.body.style.overflow = 'hidden';
    if (!state.loaded) await render();
    if (typeof prompt === 'string' && prompt) send(prompt); else if (window.innerWidth >= 992) $('#cbAiIn').focus();
  }
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-ai-open]'); if (!t) return; e.preventDefault();
    const more = document.getElementById('moreSheet'); if (more && window.bootstrap) { const o = bootstrap.Offcanvas.getInstance(more); if (o) o.hide(); }
    open(t.getAttribute('data-ai-prompt') || undefined);
  });
  document.addEventListener('keydown', e => { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'j') { e.preventDefault(); open(); } });
  window.cbAssistant = { open };
})();
