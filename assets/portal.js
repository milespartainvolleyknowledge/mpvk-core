/* MPVK Portal — vanilla JS, mobile-first. */
(function () {
  var app = document.getElementById('mpvk-app');
  var REST = app.dataset.rest.replace(/\/$/, '');
  var NONCE = app.dataset.nonce;
  var LOGOUT = app.dataset.logout;
  var VAPID = app.dataset.vapid || '';

  function b64ToUint8(base64) {
    var pad = '='.repeat((4 - base64.length % 4) % 4);
    var b = (base64 + pad).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b); var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }
  function pushSupported() {
    return VAPID && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  }
  function enablePush() {
    if (!pushSupported()) { alert('Notifications aren’t supported on this device/browser.'); return; }
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') return;
      navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(VAPID) });
      }).then(function (sub) {
        return api('/push/subscribe', { method: 'POST', body: JSON.stringify({ subscription: sub.toJSON() }) });
      }).then(function () {
        try { localStorage.setItem('mpvk_push', '1'); } catch (e) {}
        var btn = document.getElementById('mpvk-bell'); if (btn) btn.textContent = '🔔';
      }).catch(function (e) { console.warn('push subscribe failed', e); });
    });
  }
  window.__mpvkEnablePush = enablePush;

  var S = { me: null, tab: 'cal', clients: [], client: 0, month: null, cache: {} };

  function api(path, opts) {
    opts = opts || {};
    opts.headers = Object.assign({ 'X-WP-Nonce': NONCE, 'Content-Type': 'application/json' }, opts.headers || {});
    opts.credentials = 'same-origin';
    return fetch(REST + path, opts).then(function (r) {
      return r.json().then(function (j) {
        if (!r.ok) {
          // Stale REST nonce (cookie still valid, page nonce expired after ~12-24h open):
          // reload once to get a fresh server-rendered nonce instead of dead-ending.
          if (r.status === 403 && j && j.code === 'rest_cookie_invalid_nonce' && !window.__mpvkReloaded) {
            window.__mpvkReloaded = true;
            location.reload();
          }
          throw new Error((j && j.message) || ('HTTP ' + r.status));
        }
        return j;
      });
    });
  }
  function h(tag, attrs, kids) {
    var el = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), attrs[k]);
      else el.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) el.appendChild(c); });
    return el;
  }
  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  // Local calendar date — NOT toISOString() (that's UTC and shifts the day near midnight
  // in every non-UTC timezone). Server stores plain DATE strings, so match local wall-clock.
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function fmtDate(d) { return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  function monthName(d) { return d.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }); }

  // ---------- shell ----------
  function shell(inner) {
    app.innerHTML = '';
    var isCoach = S.me.tier !== 'client';
    var topRight = [];
    if (pushSupported()) {
      var subscribed = false; try { subscribed = localStorage.getItem('mpvk_push') === '1'; } catch (e) {}
      topRight.push(h('button', { id: 'mpvk-bell', class: 'bell', title: 'Notifications', text: subscribed ? '🔔' : '🔕', onclick: enablePush }));
    }
    topRight.push(h('a', { href: LOGOUT, text: 'Log out' }));
    app.appendChild(h('div', { class: 'mpvk-top' }, [
      h('span', { class: 'who', text: S.me.name + ' · ' + (S.me.tier === 'client' ? 'Athlete' : (S.me.tier === 'org' ? 'Coach' : 'Admin')) }),
      h('div', { class: 'top-actions' }, topRight)
    ]));
    var main = h('div', { class: 'mpvk-main' });
    main.appendChild(inner);
    app.appendChild(main);
    var tabs = h('div', { class: 'mpvk-tabs' });
    [['dash', '⌂', 'Home'], ['cal', '▦', 'Calendar'], ['msg', '✉', 'Messages']].forEach(function (t) {
      var b = h('button', { class: S.tab === t[0] ? 'on' : '', onclick: function () { S.tab = t[0]; render(); } }, [
        h('span', { class: 'ic', text: t[1] }), h('span', { text: t[2] })
      ]);
      if (t[0] === 'msg' && S.me.unread > 0) b.appendChild(h('span', { class: 'mpvk-badge', text: String(S.me.unread) }));
      tabs.appendChild(b);
    });
    app.appendChild(tabs);
  }

  function switcher(onChange) {
    if (S.me.tier === 'client' || !S.clients.length) return null;
    var wrap = h('div', { class: 'switcher' });
    S.clients.forEach(function (c) {
      wrap.appendChild(h('button', {
        class: S.client === c.id ? 'on' : '', text: c.name,
        onclick: function () { S.client = c.id; onChange(); }
      }));
    });
    return wrap;
  }

  // ---------- dashboard ----------
  function viewDash() {
    var box = h('div');
    var today = fmtDate(new Date());
    var who = S.me.tier === 'client' ? S.me.id : S.client;
    box.appendChild(h('div', { class: 'card' }, [
      h('h2', { text: 'Today' }),
      h('div', { class: 'spin', text: 'Loading…', id: 'dash-today' })
    ]));
    shell(h('div', null, [switcher(function () { render(); }), box]));
    if (!who) { document.getElementById('dash-today').textContent = 'Pick a client above.'; return; }
    api('/calendar?client_id=' + who + '&start=' + today + '&end=' + today).then(function (r) {
      var el = document.getElementById('dash-today'); el.classList.remove('spin'); el.innerHTML = '';
      if (!r.workouts.length) { el.textContent = 'Rest day — nothing scheduled.'; return; }
      r.workouts.forEach(function (w) {
        el.appendChild(h('div', { class: 'w day-list', onclick: function () { openWorkout(w.id); } }, [
          h('div', { class: 'w' }, [h('span', { text: w.title }), h('span', { class: 'pill ' + w.status, text: w.status })])
        ]));
      });
    }).catch(function (e) { document.getElementById('dash-today').textContent = e.message; });
  }

  // ---------- calendar ----------
  function viewCal() {
    if (!S.month) { S.month = new Date(); S.month.setDate(1); }
    var who = S.me.tier === 'client' ? S.me.id : S.client;
    var box = h('div');
    box.appendChild(h('div', { class: 'cal-head' }, [
      h('button', { class: 'btn sec small', text: '‹', onclick: function () { S.month.setMonth(S.month.getMonth() - 1); render(); } }),
      h('b', { text: monthName(S.month) }),
      h('button', { class: 'btn sec small', text: '›', onclick: function () { S.month.setMonth(S.month.getMonth() + 1); render(); } })
    ]));
    var grid = h('div', { class: 'cal-grid', id: 'cal-grid' });
    ['S', 'M', 'T', 'W', 'T', 'F', 'S'].forEach(function (d) { grid.appendChild(h('div', { class: 'dow', text: d })); });
    box.appendChild(grid);
    box.appendChild(h('div', { id: 'day-detail' }));
    shell(h('div', null, [switcher(function () { render(); }), box]));
    if (!who) { grid.appendChild(h('div', { text: 'Pick a client above.' })); return; }

    var first = new Date(S.month), last = new Date(S.month.getFullYear(), S.month.getMonth() + 1, 0);
    api('/calendar?client_id=' + who + '&start=' + fmtDate(first) + '&end=' + fmtDate(last)).then(function (r) {
      var byDate = {};
      r.workouts.forEach(function (w) { (byDate[w.workout_date] = byDate[w.workout_date] || []).push(w); });
      var todayStr = fmtDate(new Date());
      for (var i = 0; i < first.getDay(); i++) grid.appendChild(h('div', { class: 'cal-day off' }));
      for (var d = 1; d <= last.getDate(); d++) {
        var ds = S.month.getFullYear() + '-' + pad2(S.month.getMonth() + 1) + '-' + pad2(d);
        var cell = h('div', { class: 'cal-day' + (ds === todayStr ? ' today' : ''), onclick: (function (ds2) { return function () { dayDetail(ds2, byDate[ds2] || []); }; })(ds) }, [h('span', { text: String(d) })]);
        if (byDate[ds]) {
          var dots = h('div', { class: 'dots' });
          byDate[ds].forEach(function (w) { dots.appendChild(h('span', { class: 'dot ' + w.status })); });
          cell.appendChild(dots);
        }
        grid.appendChild(cell);
      }
    }).catch(function (e) { box.appendChild(h('div', { class: 'card', text: e.message })); });
  }
  function dayDetail(ds, ws) {
    var el = document.getElementById('day-detail'); el.innerHTML = '';
    var card = h('div', { class: 'card day-list' }, [h('h2', { text: ds })]);
    if (!ws.length) card.appendChild(h('div', { class: 'muted', text: 'Nothing scheduled.' }));
    ws.forEach(function (w) {
      card.appendChild(h('div', { class: 'w', onclick: function () { openWorkout(w.id); } }, [
        h('span', { text: w.title }), h('span', { class: 'pill ' + w.status, text: w.status })
      ]));
    });
    el.appendChild(card);
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ---------- workout detail (MVP: check off each exercise as a whole line) ----------
  function rxLine(ex) {
    // "4×8 Squats @ 35% 1RM · tempo 21X1 · rest 2-3 min"
    var setsReps = [ex.prescribed_sets, ex.prescribed_reps].filter(Boolean).join('×');
    var head = (setsReps ? setsReps + ' ' : '') + ex.exercise_name + (ex.prescribed_load ? ' @ ' + ex.prescribed_load : '');
    var extra = [ex.prescribed_tempo && 'tempo ' + ex.prescribed_tempo, ex.prescribed_rest && 'rest ' + ex.prescribed_rest].filter(Boolean).join(' · ');
    return { head: head, extra: extra };
  }
  function openWorkout(id) {
    var canCheck = S.me.tier === 'client' || S.me.tier === 'admin';
    api('/workouts/' + id).then(function (r) {
      var w = r.workout;
      var total = r.exercises.length;
      function doneCount() { return r.exercises.filter(function (e) { return !!e.completed_at; }).length; }
      var box = h('div');
      box.appendChild(h('button', { class: 'btn sec small', text: '‹ Back', onclick: render }));
      var card = h('div', { class: 'card' });
      var head = h('div', null, [
        h('h2', { text: w.title }),
        h('div', null, [h('span', { class: 'pill ' + w.status, id: 'w-pill', text: w.status }), h('span', { class: 'muted', text: '  ' + w.workout_date })]),
        w.notes ? h('p', { class: 'muted', text: w.notes }) : null,
        h('div', { class: 'progress', id: 'w-progress', text: doneCount() + ' / ' + total + ' done' })
      ]);
      card.appendChild(head);

      function refreshHead() {
        var d = doneCount();
        var st = d === 0 ? 'planned' : (d >= total ? 'completed' : 'partial');
        var pill = document.getElementById('w-pill');
        if (pill) { pill.className = 'pill ' + st; pill.textContent = st; }
        var pr = document.getElementById('w-progress');
        if (pr) pr.textContent = d + ' / ' + total + ' done';
      }

      r.exercises.forEach(function (ex) {
        var line = rxLine(ex);
        var checkbox = h('div', { class: 'check' + (ex.completed_at ? ' on' : ''), text: ex.completed_at ? '✓' : '' });
        var row = h('div', { class: 'exrow' + (ex.completed_at ? ' done' : '') }, [
          checkbox,
          h('div', { class: 'exbody' }, [
            h('div', { class: 'exhead', text: line.head }),
            line.extra ? h('div', { class: 'exextra', text: line.extra }) : null,
            ex.cues ? h('div', { class: 'cues', text: ex.cues }) : null
          ])
        ]);
        if (canCheck) {
          row.style.cursor = 'pointer';
          row.addEventListener('click', function () {
            if (row.__busy) return; row.__busy = true;
            api('/exercises/' + ex.id + '/complete', { method: 'POST' }).then(function (res) {
              ex.completed_at = res.done ? 'now' : null;
              row.classList.toggle('done', res.done);
              checkbox.classList.toggle('on', res.done);
              checkbox.textContent = res.done ? '✓' : '';
              refreshHead();
              row.__busy = false;
            }).catch(function (e) { alert(e.message); row.__busy = false; });
          });
        }
        card.appendChild(row);
      });
      if (!canCheck) card.appendChild(h('p', { class: 'muted', style: 'margin-top:1rem', text: 'Coach view — the athlete checks these off as they train.' }));
      box.appendChild(card);
      shell(box);
    }).catch(function (e) { alert(e.message); });
  }

  // ---------- messages (iMessage-style: live poll, reactions, quoted replies, media) ----------
  var REACTIONS = ['👍', '❤️', '🔥', '💪', '😂', '👀', '✅'];

  function fmtTime(s) {
    // server sends 'YYYY-MM-DD HH:MM:SS' (UTC) or ISO — show local HH:MM
    var iso = s.indexOf('T') > -1 ? s : s.replace(' ', 'T') + 'Z';
    var d = new Date(iso);
    return isNaN(d) ? s : d.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' });
  }

  function bubble(m, who) {
    var mine = m.sender_user_id == S.me.id;
    var wrap = h('div', { class: 'msgwrap ' + (mine ? 'me' : 'them'), 'data-id': m.id });
    var b = h('div', { class: 'msg ' + (mine ? 'me' : 'them') });
    // quoted reply preview
    if (m.reply_to) {
      b.appendChild(h('div', { class: 'quote', text: (m.reply_to.body || '') }));
    }
    // attachment — served via the authenticated REST route, so append the REST nonce.
    if (m.attachment_url) {
      var au = m.attachment_url + (m.attachment_url.indexOf('?') > -1 ? '&' : '?') + '_wpnonce=' + encodeURIComponent(NONCE);
      if (m.attachment_type === 'video') {
        b.appendChild(h('video', { class: 'att', src: au, controls: 'controls', playsinline: 'playsinline', preload: 'metadata' }));
      } else {
        b.appendChild(h('img', { class: 'att', src: au, loading: 'lazy', onclick: function (e) { e.stopPropagation(); window.open(au, '_blank'); } }));
      }
    }
    if (m.body) b.appendChild(h('div', { class: 'body', text: m.body }));
    b.appendChild(h('span', { class: 't', text: fmtTime(m.created_at) + (mine && m.read_at ? ' · Read' : '') }));
    // tap a bubble → action sheet (react / reply)
    b.addEventListener('click', function (e) { e.stopPropagation(); openSheet(m, who); });
    wrap.appendChild(b);
    // reactions row
    var rx = h('div', { class: 'rxrow' });
    (m.reactions || []).forEach(function (r) {
      rx.appendChild(h('button', { class: 'rxchip' + (r.mine ? ' mine' : ''), text: r.emoji + ' ' + r.count, onclick: function (e) { e.stopPropagation(); react(m, r.emoji, who); } }));
    });
    wrap.appendChild(rx);
    return wrap;
  }

  function openSheet(m, who) {
    closeSheet();
    var sheet = h('div', { class: 'sheet', id: 'sheet', onclick: function (e) { if (e.target.id === 'sheet') closeSheet(); } });
    var inner = h('div', { class: 'sheet-in' });
    var row = h('div', { class: 'sheet-rx' });
    REACTIONS.forEach(function (emo) {
      row.appendChild(h('button', { class: 'sheet-emo', text: emo, onclick: function () { react(m, emo, who); closeSheet(); } }));
    });
    inner.appendChild(row);
    inner.appendChild(h('button', { class: 'sheet-act', text: '↩︎  Reply', onclick: function () { setReply(m); closeSheet(); } }));
    inner.appendChild(h('button', { class: 'sheet-act cancel', text: 'Cancel', onclick: closeSheet }));
    sheet.appendChild(inner);
    app.appendChild(sheet);
  }
  function closeSheet() { var s = document.getElementById('sheet'); if (s) s.remove(); }

  function react(m, emoji, who) {
    api('/messages/' + m.id + '/react', { method: 'POST', body: JSON.stringify({ emoji: emoji }) })
      .then(function () { pollNow(who); }).catch(function (e) { alert(e.message); });
  }

  function setReply(m) {
    S.replyTo = m;
    var bar = document.getElementById('replybar');
    if (bar) {
      bar.style.display = 'flex';
      bar.querySelector('.rtext').textContent = 'Replying: ' + (m.body ? m.body.slice(0, 60) : '[' + (m.attachment_type || 'attachment') + ']');
    }
  }
  function clearReply() {
    S.replyTo = null;
    var bar = document.getElementById('replybar');
    if (bar) bar.style.display = 'none';
  }

  function viewMsg() {
    stopPoll();
    var who = S.me.tier === 'client' ? S.me.id : S.client;
    var box = h('div');
    var sw = switcher(function () { render(); });
    if (sw) box.appendChild(sw);
    box.appendChild(h('div', { class: 'msgs', id: 'msgs' }));
    shell(box);
    if (!who) { document.getElementById('msgs').appendChild(h('div', { class: 'card', text: 'Pick a client above.' })); return; }

    // composer: reply bar + attach + textarea + send
    var replybar = h('div', { class: 'replybar', id: 'replybar', style: 'display:none' }, [
      h('span', { class: 'rtext' }),
      h('button', { class: 'rx', text: '✕', onclick: clearReply })
    ]);
    var fileInput = h('input', { type: 'file', accept: 'image/*,video/*', style: 'display:none' });
    var attachBtn = h('button', { class: 'attbtn', text: '＋', title: 'Photo or video', onclick: function () { fileInput.click(); } });
    var ta = h('textarea', { placeholder: 'Message…', rows: '1' });
    var send = h('button', { class: 'btn', text: 'Send' });
    var pendingAttach = null;

    fileInput.addEventListener('change', function () {
      var f = fileInput.files[0]; if (!f) return;
      attachBtn.textContent = '…'; attachBtn.disabled = true;
      var fd = new FormData(); fd.append('file', f);
      // NOTE: don't set Content-Type; browser sets multipart boundary.
      fetch(REST + '/messages/attachment?client_id=' + who, { method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': NONCE }, body: fd })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'upload failed'); return j; }); })
        .then(function (j) { pendingAttach = j; attachBtn.textContent = j.type === 'video' ? '🎬' : '🖼'; attachBtn.disabled = false; })
        .catch(function (e) { alert(e.message); attachBtn.textContent = '＋'; attachBtn.disabled = false; });
    });

    function doSend() {
      var body = ta.value.trim();
      if (!body && !pendingAttach) return;
      send.disabled = true;
      var payload = { client_id: who, body: body };
      if (pendingAttach) payload.attachment_id = pendingAttach.attachment_id;
      if (S.replyTo) payload.reply_to_id = S.replyTo.id;
      api('/messages?client_id=' + who, { method: 'POST', body: JSON.stringify(payload) })
        .then(function () {
          ta.value = ''; ta.style.height = 'auto'; pendingAttach = null; attachBtn.textContent = '＋';
          send.disabled = false; clearReply(); pollNow(who);
        })
        .catch(function (e) { alert(e.message); send.disabled = false; });
    }
    send.addEventListener('click', doSend);
    ta.addEventListener('input', function () { ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px'; });

    var composer = h('div', { class: 'composer-wrap' }, [
      replybar,
      h('div', { class: 'composer' }, [attachBtn, fileInput, ta, send])
    ]);
    app.appendChild(composer);

    S.lastId = 0;
    loadMsgs(who, true);
    // live polling ~4s
    S.pollTimer = setInterval(function () { pollNow(who); }, 4000);
  }

  function renderThread(who, messages, replace) {
    var list = document.getElementById('msgs'); if (!list) return;
    if (replace) { list.innerHTML = ''; S.seen = {}; }
    if (replace && !messages.length) { list.appendChild(h('div', { class: 'muted', text: 'No messages yet. Say hi!' })); return; }
    var nearBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 160);
    messages.forEach(function (m) {
      if (S.seen[m.id]) return; S.seen[m.id] = true;
      var empty = list.querySelector('.muted'); if (empty) empty.remove();
      list.appendChild(bubble(m, who));
      if (m.id > S.lastId) S.lastId = m.id;
    });
    if (replace || nearBottom) window.scrollTo(0, document.body.scrollHeight);
  }

  function applyState(state) {
    // update reactions + read receipts on existing bubbles without a full re-render
    state.forEach(function (s) {
      var wrap = document.querySelector('.msgwrap[data-id="' + s.id + '"]'); if (!wrap) return;
      var rx = wrap.querySelector('.rxrow'); if (!rx) return;
      rx.innerHTML = '';
      (s.reactions || []).forEach(function (r) {
        var m = { id: s.id };
        rx.appendChild(h('button', { class: 'rxchip' + (r.mine ? ' mine' : ''), text: r.emoji + ' ' + r.count,
          onclick: function (e) { e.stopPropagation(); react(m, r.emoji, S.msgWho); } }));
      });
      if (s.read) { var t = wrap.querySelector('.msg.me .t'); if (t && t.textContent.indexOf('Read') < 0) t.textContent += ' · Read'; }
    });
  }

  function loadMsgs(who, replace) {
    S.msgWho = who;
    S.gen = (S.gen || 0) + 1; var gen = S.gen; // generation guards against stale responses
    api('/messages?client_id=' + who).then(function (r) {
      if (gen !== S.gen || who !== S.msgWho) return; // thread switched mid-flight — drop
      S.lastId = r.last_id || 0;
      renderThread(who, r.messages, replace);
      refreshUnread();
    }).catch(function (e) { if (replace) alert(e.message); });
  }
  function pollNow(who) {
    who = who || S.msgWho;
    if (document.hidden) return; // don't poll a backgrounded tab
    var gen = S.gen;
    api('/messages/poll?client_id=' + who + '&since=' + (S.lastId || 0)).then(function (r) {
      if (gen !== S.gen || who !== S.msgWho) return; // stale response from a previous thread — ignore
      if (r.messages && r.messages.length) renderThread(who, r.messages, false);
      if (r.state) applyState(r.state);
      if (r.last_id) S.lastId = Math.max(S.lastId, r.last_id);
      refreshUnread();
    }).catch(function () {});
  }
  function refreshUnread() {
    api('/me').then(function (me) {
      if (S.me.unread !== me.unread) { S.me.unread = me.unread; var b = document.querySelector('.mpvk-tabs button:nth-child(3) .mpvk-badge'); if (b && !me.unread) b.remove(); }
    }).catch(function () {});
  }

  function stopPoll() { if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = null; } closeSheet(); }

  // ---------- boot ----------
  function render() {
    stopPoll();
    if (S.tab === 'dash') viewDash();
    else if (S.tab === 'msg') viewMsg();
    else viewCal();
  }
  api('/me').then(function (me) {
    S.me = me;
    if (me.tier !== 'client') {
      return api('/org/clients').then(function (cs) { S.clients = cs; if (cs.length) S.client = cs[0].id; });
    }
  }).then(function () { S.tab = 'dash'; render(); })
    .catch(function (e) { app.innerHTML = '<div class="card" style="margin:2rem">Error: ' + esc(e.message) + '</div>'; });
})();
