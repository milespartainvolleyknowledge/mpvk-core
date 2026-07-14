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
  function toast(msg, ms) {
    var old = document.getElementById('mpvk-toast'); if (old) old.remove();
    var t = h('div', { id: 'mpvk-toast', class: 'toast', text: msg });
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('show'); }, 10);
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 400); }, ms || 5000);
  }
  function isIOS() { return /iPad|iPhone|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }
  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
  }
  function setBell(on) {
    var btn = document.getElementById('mpvk-bell'); if (btn) btn.textContent = on ? '🔔' : '🔕';
    try { localStorage.setItem('mpvk_push', on ? '1' : '0'); } catch (e) {}
  }
  // Reflect the TRUE subscription state (not just a local flag) whenever the shell renders.
  function syncBell() {
    if (!pushSupported()) return;
    navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription(); })
      .then(function (sub) { setBell(!!sub); }).catch(function () {});
  }
  function enablePush() {
    if (!pushSupported()) {
      toast(isIOS()
        ? 'Notifications need iOS 16.4+ and the installed app (Share → Add to Home Screen, then open from the icon).'
        : 'Notifications aren’t supported in this browser.');
      return;
    }
    // iOS only allows notifications from the INSTALLED app, not the Safari tab.
    if (isIOS() && !isStandalone()) {
      toast('Install the app first: tap Share → Add to Home Screen, then open it from the icon and tap the bell again.', 7000);
      return;
    }
    if (Notification.permission === 'denied') {
      toast(isIOS()
        ? 'Notifications were blocked earlier. Delete the app icon from your home screen, re-add it (Share → Add to Home Screen), open it, and allow when asked.'
        : 'Notifications are blocked for this site — allow them in your browser’s site settings, then tap the bell again.', 8000);
      return;
    }
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { toast('You didn’t allow notifications — tap the bell to try again.'); return; }
      navigator.serviceWorker.ready.then(function (reg) {
        return reg.pushManager.getSubscription().then(function (existing) {
          return existing || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToUint8(VAPID) });
        });
      }).then(function (sub) {
        return api('/push/subscribe', { method: 'POST', body: JSON.stringify({ subscription: sub.toJSON() }) });
      }).then(function () {
        setBell(true);
        toast('Notifications are on 🎉 You’ll get a buzz for new messages.');
      }).catch(function (e) {
        var msg = (e && e.message) || 'unknown error';
        if (msg.indexOf('registered to another account') > -1) {
          toast('This phone’s notifications are linked to a different login. Log in as that account, or ask your coach to reset it.', 8000);
        } else {
          toast('Couldn’t turn on notifications: ' + msg, 8000);
        }
        console.warn('push subscribe failed', e);
      });
    });
  }
  window.__mpvkEnablePush = enablePush;

  var S = { me: null, tab: 'cal', clients: [], client: 0, month: null, cache: {} };
  var PASSKEYS = app.dataset.passkeys === '1';

  // ---------- passkey enrollment (Face ID / fingerprint) ----------
  function b64uToBuf(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4);
    var b = (s + pad).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(b); var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr.buffer;
  }
  function bufToB64u(buf) {
    var bytes = new Uint8Array(buf), s = '';
    for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function passkeySupported() {
    return PASSKEYS && window.PublicKeyCredential && navigator.credentials;
  }
  function enrollPasskey(btn) {
    btn.disabled = true;
    api('/passkey/register/options', { method: 'POST' }).then(function (opts) {
      var pk = opts.publicKey;
      pk.challenge = b64uToBuf(pk.challenge);
      pk.user.id = b64uToBuf(pk.user.id);
      (pk.excludeCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
      return navigator.credentials.create({ publicKey: pk });
    }).then(function (cred) {
      return api('/passkey/register', { method: 'POST', body: JSON.stringify({
        label: navigator.platform || 'This device',
        credential: {
          id: cred.id,
          rawId: bufToB64u(cred.rawId),
          response: {
            clientDataJSON: bufToB64u(cred.response.clientDataJSON),
            attestationObject: bufToB64u(cred.response.attestationObject)
          }
        }
      }) });
    }).then(function () {
      try { localStorage.setItem('mpvk_pk_done', '1'); } catch (e) {}
      toast('Face ID login is set up ✓ Next time, tap "Sign in with Face ID" on the login screen.');
      var card = document.getElementById('pk-card'); if (card) card.remove();
    }).catch(function (e) {
      btn.disabled = false;
      if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return; // user cancelled
      if (e && e.name === 'InvalidStateError') { // already registered on this device
        try { localStorage.setItem('mpvk_pk_done', '1'); } catch (err) {}
        toast('This device already has a passkey for your account ✓');
        var card = document.getElementById('pk-card'); if (card) card.remove();
        return;
      }
      toast('Couldn’t set up Face ID: ' + ((e && e.message) || 'unknown error'), 7000);
    });
  }
  function passkeyCard() {
    if (!passkeySupported()) return null;
    var done = false; try { done = localStorage.getItem('mpvk_pk_done') === '1'; } catch (e) {}
    if (done) return null;
    var btn = h('button', { class: 'btn', text: 'Set up Face ID login' });
    btn.addEventListener('click', function () { enrollPasskey(btn); });
    return h('div', { class: 'card', id: 'pk-card' }, [
      h('h2', { text: '🔒 Log in with Face ID' }),
      h('p', { class: 'muted', text: 'One tap instead of a password, and you stay signed in longer on this device.' }),
      btn
    ]);
  }

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
      syncBell();
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
    var tabDefs = [['dash', '⌂', 'Home'], ['cal', '▦', 'Calendar'], ['msg', '✉', 'Messages']];
    if (isCoach) { tabDefs.push(['prog', '◱', 'Programs']); tabDefs.push(['lib', '𝄜', 'Library']); }
    tabDefs.forEach(function (t) {
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
    var pk = passkeyCard(); if (pk) box.appendChild(pk);
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

  // ---------- workout detail (full: weights, RPE, circuits, opt-out, tap-in video) ----------
  var UNIT_LABEL = { reps: 'reps', sec: 'sec', each_side: 'ea side', each: 'each' };
  function rxLine(ex) {
    if (ex.entry_type === 'text') return { head: ex.exercise_name || 'Note', extra: '' };
    var unit = UNIT_LABEL[ex.rep_unit] || 'reps';
    var setsReps = '';
    if (ex.prescribed_sets && ex.prescribed_reps) setsReps = ex.prescribed_sets + ' × ' + ex.prescribed_reps + ' ' + unit;
    else if (ex.prescribed_reps) setsReps = ex.prescribed_reps + ' ' + unit;
    else if (ex.prescribed_sets) setsReps = ex.prescribed_sets + ' sets';
    var loadTxt = '';
    if (ex.prescribed_load) {
      if (ex.load_mode === 'rpe') loadTxt = 'RPE ' + ex.prescribed_load;
      else if (ex.load_mode === 'percent') loadTxt = ex.prescribed_load + '% 1RM';
      else if (ex.load_mode === 'bw') loadTxt = 'bodyweight';
      else loadTxt = ex.prescribed_load;
    } else if (ex.load_mode === 'bw') loadTxt = 'bodyweight';
    var head = ex.exercise_name + (setsReps ? '  ·  ' + setsReps : '') + (loadTxt ? '  ·  ' + loadTxt : '');
    var extra = [ex.prescribed_tempo && 'tempo ' + ex.prescribed_tempo, ex.prescribed_rest && 'rest ' + ex.prescribed_rest].filter(Boolean).join(' · ');
    return { head: head, extra: extra };
  }
  function setCount(ex) { var n = parseInt(ex.prescribed_sets, 10); return (n > 0 && n <= 20) ? n : 1; }
  function logsBySet(ex) { var m = {}; (ex.logs || []).forEach(function (l) { m[l.set_number] = l; }); return m; }

  function weightInputs(ex, onLogged) {
    // Athlete enters WEIGHT (+ optional RPE) per set. Sets/reps are fixed — not editable here.
    if (ex.load_mode === 'bw' || ex.load_mode === 'none' || ex.entry_type === 'text') return null;
    var wrap = h('div', { class: 'setgrid' });
    var n = setCount(ex);
    var prior = logsBySet(ex);
    for (var i = 1; i <= n; i++) {
      (function (setNo) {
        var l = prior[setNo] || {};
        var wt = h('input', { class: 'wt', type: 'text', inputmode: 'decimal', placeholder: ex.load_mode === 'percent' ? 'kg/lb' : 'wt', value: l.actual_load || '' });
        var rpe = h('input', { class: 'wt rpe', type: 'text', inputmode: 'decimal', placeholder: 'RPE', value: (l.rpe != null ? l.rpe : '') });
        var ok = h('button', { class: 'setok', text: '✓' });
        function save() {
          if (!wt.value.trim() && !rpe.value.trim()) return;
          ok.textContent = '…';
          api('/exercises/' + ex.id + '/weight', { method: 'POST', body: JSON.stringify({ set_number: setNo, load: wt.value.trim(), rpe: rpe.value.trim() }) })
            .then(function () { ok.textContent = '✓'; ok.classList.add('saved'); if (onLogged) onLogged(); })
            .catch(function (e) { ok.textContent = '✓'; toast(e.message); });
        }
        ok.addEventListener('click', save);
        wt.addEventListener('blur', save);
        wrap.appendChild(h('div', { class: 'setrow' }, [
          h('span', { class: 'setno', text: 'Set ' + setNo }), wt,
          h('span', { class: 'setx', text: (ex.prescribed_reps || '') + ' ' + (UNIT_LABEL[ex.rep_unit] || '') }),
          rpe, ok
        ]));
      })(i);
    }
    return wrap;
  }

  function openExerciseDetail(ex) {
    var sheet = h('div', { class: 'sheet', id: 'exsheet', onclick: function (e) { if (e.target.id === 'exsheet') e.target.remove(); } });
    var inner = h('div', { class: 'sheet-in', style: 'max-height:88vh;overflow-y:auto' });
    inner.appendChild(h('h2', { text: ex.exercise_name, style: 'margin:.2rem 0 .6rem' }));
    inner.appendChild(h('div', { class: 'spin', text: 'Loading…', id: 'exd-body' }));
    sheet.appendChild(inner);
    app.appendChild(sheet);
    api('/exercises/' + ex.id + '/detail').then(function (d) {
      var body = document.getElementById('exd-body'); if (!body) return;
      body.classList.remove('spin'); body.innerHTML = '';
      if (d.video) {
        body.appendChild(h('div', { class: 'vidwrap' }, [
          h('iframe', { src: d.video, sandbox: 'allow-scripts allow-same-origin allow-presentation allow-popups', allow: 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen', allowfullscreen: 'true', loading: 'lazy', referrerpolicy: 'strict-origin-when-cross-origin' })
        ]));
      } else if (d.video_link) {
        body.appendChild(h('a', { class: 'btn sec small', href: d.video_link, target: '_blank', rel: 'noopener noreferrer nofollow', text: '▶ Open form video' }));
      }
      if (d.directions) body.appendChild(h('div', { class: 'exd-sec' }, [h('b', { text: 'Directions' }), h('p', { text: d.directions })]));
      if (d.notes) body.appendChild(h('div', { class: 'exd-sec' }, [h('b', { text: "Coach's note" }), h('p', { text: d.notes })]));
      if (!d.video && !d.directions && !d.notes) body.appendChild(h('p', { class: 'muted', text: 'No extra directions for this one.' }));
      // history
      var hist = h('div', { class: 'exd-sec' }, [h('b', { text: 'Your history' }), h('div', { class: 'muted', text: 'Loading…', id: 'exd-hist' })]);
      body.appendChild(hist);
      api('/exercises/' + ex.id + '/history').then(function (r) {
        var el = document.getElementById('exd-hist'); if (!el) return;
        if (!r.history.length) { el.textContent = 'No weights logged yet — this is your first time.'; return; }
        el.innerHTML = '';
        r.history.slice(0, 8).forEach(function (day) {
          var sets = day.sets.map(function (s) { return s.load + (s.rpe ? ' @' + s.rpe : ''); }).join(', ');
          el.appendChild(h('div', { class: 'histrow' }, [h('span', { class: 'histdate', text: day.date }), h('span', { text: sets })]));
        });
      }).catch(function () {});
    }).catch(function (e) { var b = document.getElementById('exd-body'); if (b) { b.classList.remove('spin'); b.textContent = e.message; } });
  }

  // Pre-workout readiness check-in (joint/tendon health) — shown before the exercises.
  function readinessCard(r, wid, canLog, onDone) {
    var areas = r.checkin_areas || [];
    var existing = (r.checkin && r.checkin.scores) || {};
    var card = h('div', { class: 'card readiness' });
    card.appendChild(h('h2', { text: '🩹 How do you feel today?' }));
    card.appendChild(h('p', { class: 'muted', text: 'Slide each area — 10 feels great, 0 is pain. Your coach sees this before you train.' }));
    var vals = {};
    areas.forEach(function (a) {
      var start = (existing[a] != null) ? existing[a] : 7;
      vals[a] = start;
      var num = h('span', { class: 'rnum', text: String(start) });
      var slider = h('input', { type: 'range', min: '0', max: '10', step: '1', value: String(start), class: 'rslider' });
      slider.addEventListener('input', function () { vals[a] = parseInt(slider.value, 10); num.textContent = slider.value; num.className = 'rnum' + (vals[a] <= 3 ? ' bad' : (vals[a] <= 6 ? ' mid' : '')); });
      card.appendChild(h('div', { class: 'rrow' }, [h('span', { class: 'rlabel', text: a }), slider, num]));
    });
    var note = h('input', { type: 'text', placeholder: 'Anything to flag? (optional)', class: 'rnote' });
    if (r.checkin && r.checkin.note) note.value = r.checkin.note;
    card.appendChild(note);
    if (canLog) {
      var save = h('button', { class: 'btn', text: r.checkin ? 'Update & continue' : 'Start workout →' });
      save.addEventListener('click', function () {
        save.disabled = true;
        api('/workouts/' + wid + '/checkin', { method: 'POST', body: JSON.stringify({ scores: vals, note: note.value.trim() }) })
          .then(function (res) {
            r.checkin = { scores: vals, overall: res.overall, note: note.value.trim() };
            if (res.flags && res.flags.length) toast('Flagged for your coach: ' + res.flags.join(', '));
            onDone();
          })
          .catch(function (e) { toast(e.message); save.disabled = false; });
      });
      card.appendChild(save);
    }
    return card;
  }
  function readinessSummary(r) {
    if (!r.checkin) return null;
    var chips = h('div', { class: 'rsum' });
    Object.keys(r.checkin.scores).forEach(function (a) {
      var v = r.checkin.scores[a];
      chips.appendChild(h('span', { class: 'rchip' + (v <= 3 ? ' bad' : (v <= 6 ? ' mid' : '')), text: a + ' ' + v }));
    });
    var card = h('div', { class: 'card' }, [
      h('div', { style: 'display:flex;justify-content:space-between;align-items:center' }, [
        h('b', { text: 'Readiness ' + r.checkin.overall + '/10' }),
        h('button', { class: 'btn sec small', text: 'Edit', onclick: function () { S.editReadiness = true; render(); } })
      ]),
      chips,
      r.checkin.note ? h('p', { class: 'muted', text: r.checkin.note }) : null
    ]);
    return card;
  }

  function openWorkout(id) {
    var canLog = S.me.tier === 'client' || S.me.tier === 'admin';
    api('/workouts/' + id).then(function (r) {
      var w = r.workout;
      var exs = r.exercises;
      S.curWorkout = id;
      // Gate: athletes do the readiness check-in first (unless already done, or they hit Edit).
      var needCheckin = canLog && (r.checkin_areas || []).length && (!r.checkin || S.editReadiness);
      if (needCheckin) {
        var gbox = h('div');
        gbox.appendChild(h('button', { class: 'btn sec small', text: '‹ Back', onclick: function () { S.editReadiness = false; render(); } }));
        gbox.appendChild(h('div', { class: 'card' }, [h('h2', { text: w.title }), h('span', { class: 'muted', text: w.workout_date })]));
        gbox.appendChild(readinessCard(r, id, canLog, function () { S.editReadiness = false; openWorkout(id); }));
        shell(gbox);
        return;
      }
      var total = exs.filter(function (e) { return e.entry_type !== 'text'; }).length;
      function doneCount() { return exs.filter(function (e) { return e.entry_type !== 'text' && (e.completed_at || e.skipped_at); }).length; }
      var box = h('div');
      box.appendChild(h('button', { class: 'btn sec small', text: '‹ Back', onclick: render }));
      var card = h('div', { class: 'card' });
      card.appendChild(h('h2', { text: w.title }));
      card.appendChild(h('div', null, [h('span', { class: 'pill ' + w.status, id: 'w-pill', text: w.status }), h('span', { class: 'muted', text: '  ' + w.workout_date })]));
      if (w.notes) card.appendChild(h('p', { class: 'muted', text: w.notes }));
      card.appendChild(h('div', { class: 'progress', id: 'w-progress', text: doneCount() + ' / ' + total + ' done' }));
      function refreshHead() {
        var d = doneCount();
        var st = d === 0 ? 'planned' : (d >= total ? 'completed' : 'partial');
        var pill = document.getElementById('w-pill'); if (pill) { pill.className = 'pill ' + st; pill.textContent = st; }
        var pr = document.getElementById('w-progress'); if (pr) pr.textContent = d + ' / ' + total + ' done';
      }
      box.appendChild(card);
      var rs = readinessSummary(r); if (rs) box.appendChild(rs);

      // group consecutive circuit members (same circuit_group) into one card
      var i = 0;
      while (i < exs.length) {
        var ex = exs[i];
        if (ex.entry_type === 'text') {
          box.appendChild(h('div', { class: 'card textblock' }, [
            ex.exercise_name && ex.exercise_name !== 'Note' ? h('b', { text: ex.exercise_name }) : null,
            h('p', { text: ex.block_text || ex.notes || '' })
          ]));
          i++; continue;
        }
        if (ex.circuit_group) {
          var grp = ex.circuit_group, members = [];
          while (i < exs.length && exs[i].circuit_group === grp && exs[i].entry_type !== 'text') { members.push(exs[i]); i++; }
          var cc = h('div', { class: 'card circuit' }, [h('div', { class: 'circuit-head', text: 'Circuit ' + grp + (members[0].prescribed_sets ? ' · ' + members[0].prescribed_sets + ' rounds' : '') })]);
          members.forEach(function (m) { cc.appendChild(exerciseRow(m)); });
          box.appendChild(cc);
        } else {
          box.appendChild(h('div', { class: 'card' }, [exerciseRow(ex)]));
          i++;
        }
      }

      function exerciseRow(ex) {
        var line = rxLine(ex);
        var check = h('div', { class: 'check' + (ex.completed_at ? ' on' : '') + (ex.skipped_at ? ' skip' : ''), text: ex.completed_at ? '✓' : (ex.skipped_at ? '–' : '') });
        var head = h('div', { class: 'exhead', text: line.head });
        var info = h('button', { class: 'exinfo', text: 'ⓘ', title: 'Directions & video' });
        info.addEventListener('click', function (e) { e.stopPropagation(); openExerciseDetail(ex); });
        var top = h('div', { class: 'exrow-top' }, [check, h('div', { class: 'exbody' }, [head, line.extra ? h('div', { class: 'exextra', text: line.extra }) : null]), info]);
        var row = h('div', { class: 'exrow2' + (ex.completed_at ? ' done' : '') + (ex.skipped_at ? ' skipped' : '') }, [top]);

        if (canLog) {
          // tapping the check toggles complete
          check.style.cursor = 'pointer';
          check.addEventListener('click', function (e) {
            e.stopPropagation();
            if (ex.skipped_at) return;
            api('/exercises/' + ex.id + '/complete', { method: 'POST' }).then(function (res) {
              ex.completed_at = res.done ? 'now' : null;
              row.classList.toggle('done', res.done); check.classList.toggle('on', res.done); check.textContent = res.done ? '✓' : '';
              refreshHead();
            }).catch(function (er) { toast(er.message); });
          });
          var wi = weightInputs(ex, function () {
            if (!ex.completed_at) { check.classList.add('on'); check.textContent = '✓'; ex.completed_at = 'now'; row.classList.add('done'); refreshHead(); }
          });
          if (wi) row.appendChild(wi);
          if (parseInt(ex.is_optional, 10)) {
            var opt = h('button', { class: 'optout', text: ex.skipped_at ? 'Opted out — undo' : 'Opt out' });
            opt.addEventListener('click', function (e) {
              e.stopPropagation();
              api('/exercises/' + ex.id + '/skip', { method: 'POST' }).then(function (res) {
                ex.skipped_at = res.skipped ? 'now' : null;
                row.classList.toggle('skipped', res.skipped);
                check.classList.toggle('skip', res.skipped); check.textContent = res.skipped ? '–' : (ex.completed_at ? '✓' : '');
                opt.textContent = res.skipped ? 'Opted out — undo' : 'Opt out';
                refreshHead();
              }).catch(function (er) { toast(er.message); });
            });
            row.appendChild(opt);
          }
        }
        return row;
      }

      if (!canLog) box.appendChild(h('p', { class: 'muted', style: 'margin:0 0 5rem;padding:0 .2rem', text: 'Coach view — the athlete logs weights and checks these off.' }));
      shell(box);
    }).catch(function (e) { toast(e.message); });
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
      if (S.aiDraftId) { payload.draft_id = S.aiDraftId; S.aiDraftId = null; }
      api('/messages?client_id=' + who, { method: 'POST', body: JSON.stringify(payload) })
        .then(function () {
          ta.value = ''; ta.style.height = 'auto'; pendingAttach = null; attachBtn.textContent = '＋';
          send.disabled = false; clearReply(); hideDraft(); pollNow(who);
        })
        .catch(function (e) { alert(e.message); send.disabled = false; });
    }
    send.addEventListener('click', doSend);
    ta.addEventListener('input', function () {
      ta.style.height = 'auto'; ta.style.height = Math.min(120, ta.scrollHeight) + 'px';
      // typing heartbeat — at most one ping every 3s while keys are moving
      var now = Date.now();
      if (ta.value.trim() && (!S.lastTypingPing || now - S.lastTypingPing > 3000)) {
        S.lastTypingPing = now;
        api('/messages/typing?client_id=' + who, { method: 'POST' }).catch(function () {});
      }
    });

    var draftCard = h('div', { class: 'draftcard', id: 'draftcard', style: 'display:none' });
    var composer = h('div', { class: 'composer-wrap' }, [
      draftCard,
      replybar,
      h('div', { class: 'composer' }, [attachBtn, fileInput, ta, send])
    ]);
    app.appendChild(composer);
    S.aiDraftId = null;
    S.composerTa = ta;

    S.lastId = 0;
    loadMsgs(who, true);
    if (S.me.tier !== 'client') fetchDraft(who);
    // live polling ~4s
    S.pollTimer = setInterval(function () { pollNow(who); }, 4000);
  }

  // ---------- AI draft replies (coach only) ----------
  function hideDraft() {
    var c = document.getElementById('draftcard'); if (c) { c.style.display = 'none'; c.innerHTML = ''; }
  }
  function fetchDraft(who) {
    if (S.me.tier === 'client') return;
    var gen = S.gen;
    api('/ai/draft?client_id=' + who).then(function (r) {
      if (gen !== S.gen || who !== S.msgWho) return;
      var c = document.getElementById('draftcard'); if (!c) return;
      if (!r.draft) { hideDraft(); return; }
      if (S.shownDraftId === r.draft.id && c.style.display !== 'none') return; // already showing
      S.shownDraftId = r.draft.id;
      c.innerHTML = '';
      c.appendChild(h('div', { class: 'draft-label', text: '🤖 Suggested reply' }));
      c.appendChild(h('div', { class: 'draft-body', text: r.draft.body }));
      c.appendChild(h('div', { class: 'draft-actions' }, [
        h('button', { class: 'btn small', text: 'Use', onclick: function () {
          var ta = S.composerTa; if (!ta) return;
          ta.value = r.draft.body; ta.dispatchEvent(new Event('input'));
          S.aiDraftId = r.draft.id;
          hideDraft(); ta.focus();
        } }),
        h('button', { class: 'btn sec small', text: 'Dismiss', onclick: function () {
          api('/ai/draft/' + r.draft.id + '/dismiss', { method: 'POST' }).catch(function () {});
          S.aiDraftId = null; hideDraft();
        } })
      ]));
      c.style.display = 'block';
    }).catch(function () {});
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
  function showTyping(on) {
    var list = document.getElementById('msgs'); if (!list) return;
    var tip = document.getElementById('typing');
    if (on && !tip) {
      tip = h('div', { id: 'typing', class: 'msgwrap them' }, [
        h('div', { class: 'msg them typing' }, [
          h('span', { class: 'tdot' }), h('span', { class: 'tdot' }), h('span', { class: 'tdot' })
        ])
      ]);
      var nearBottom = (window.innerHeight + window.scrollY) >= (document.body.scrollHeight - 160);
      list.appendChild(tip);
      if (nearBottom) window.scrollTo(0, document.body.scrollHeight);
    } else if (!on && tip) {
      tip.remove();
    }
  }
  function pollNow(who) {
    who = who || S.msgWho;
    if (document.hidden) return; // don't poll a backgrounded tab
    var gen = S.gen;
    api('/messages/poll?client_id=' + who + '&since=' + (S.lastId || 0)).then(function (r) {
      if (gen !== S.gen || who !== S.msgWho) return; // stale response from a previous thread — ignore
      showTyping(false); // remove before appending so dots always sit under the newest bubble
      var gotNew = r.messages && r.messages.length;
      if (gotNew) renderThread(who, r.messages, false);
      if (r.state) applyState(r.state);
      if (r.last_id) S.lastId = Math.max(S.lastId, r.last_id);
      showTyping(!!r.typing);
      if (gotNew && S.me.tier !== 'client') setTimeout(function () { fetchDraft(who); }, 2500);
      refreshUnread();
    }).catch(function () {});
  }
  function refreshUnread() {
    api('/me').then(function (me) {
      if (S.me.unread !== me.unread) { S.me.unread = me.unread; var b = document.querySelector('.mpvk-tabs button:nth-child(3) .mpvk-badge'); if (b && !me.unread) b.remove(); }
    }).catch(function () {});
  }

  function stopPoll() { if (S.pollTimer) { clearInterval(S.pollTimer); S.pollTimer = null; } closeSheet(); }

  // ---------- exercise library (coach) ----------
  var LIB_CATS = ['strength', 'plyo', 'skill', 'mobility', 'prehab', 'conditioning'];
  function libForm(ex, onDone) {
    var f = {};
    function field(label, name, type, value, opts) {
      var input;
      if (type === 'select') {
        input = h('select', { style: 'width:100%;padding:.5rem;border:1px solid var(--bronze);border-radius:8px;background:#fff' });
        opts.forEach(function (o) {
          var el = h('option', { value: o, text: o || '—' });
          if (o === value) el.selected = true;
          input.appendChild(el);
        });
      } else if (type === 'textarea') {
        input = h('textarea', { rows: '2', style: 'width:100%;padding:.5rem;border:1px solid var(--bronze);border-radius:8px;font:inherit' });
        input.value = value || '';
      } else {
        input = h('input', { type: 'text', style: 'width:100%;padding:.5rem;border:1px solid var(--bronze);border-radius:8px' });
        input.value = value || '';
      }
      f[name] = input;
      return h('label', { style: 'display:block;margin:.45rem 0;font-size:.8rem;color:var(--bronze)', text: label }, [input]);
    }
    var box = h('div', { class: 'card' }, [
      h('h2', { text: ex ? 'Edit exercise' : 'New exercise' }),
      field('Name', 'name', 'text', ex && ex.name),
      field('Category', 'category', 'select', (ex && ex.category) || 'strength', LIB_CATS),
      field('Level', 'level', 'select', (ex && ex.level) || 'beginner', ['beginner', 'intermediate', 'advanced']),
      field('Equipment', 'equipment', 'text', ex && ex.equipment),
      field('Coaching cues', 'cues', 'textarea', ex && ex.cues),
      field('Demo video URL (optional)', 'video_url', 'text', ex && ex.video_url)
    ]);
    var actions = h('div', { style: 'display:flex;gap:.5rem;margin-top:.6rem' });
    var save = h('button', { class: 'btn', text: 'Save' });
    save.addEventListener('click', function () {
      var payload = {};
      Object.keys(f).forEach(function (k) { payload[k] = f[k].value; });
      if (!payload.name.trim()) { toast('Name is required.'); return; }
      save.disabled = true;
      var call = ex ? api('/library/' + ex.id, { method: 'PATCH', body: JSON.stringify(payload) })
                    : api('/library', { method: 'POST', body: JSON.stringify(payload) });
      call.then(function () { toast('Saved ✓'); onDone(); })
          .catch(function (e) { toast(e.message, 6000); save.disabled = false; });
    });
    actions.appendChild(save);
    if (ex) {
      var del = h('button', { class: 'btn sec', text: 'Delete' });
      del.addEventListener('click', function () {
        if (del.textContent !== 'Really delete?') { del.textContent = 'Really delete?'; return; }
        api('/library/' + ex.id, { method: 'DELETE' }).then(function () { toast('Deleted.'); onDone(); })
          .catch(function (e) { toast(e.message, 6000); });
      });
      actions.appendChild(del);
    }
    actions.appendChild(h('button', { class: 'btn sec', text: 'Cancel', onclick: onDone }));
    box.appendChild(actions);
    return box;
  }
  function viewLib(editing) {
    var box = h('div');
    if (editing !== undefined) {
      box.appendChild(libForm(editing, function () { viewLib(); }));
      shell(box);
      return;
    }
    var search = h('input', { type: 'search', placeholder: 'Search exercises…', style: 'width:100%;padding:.6rem .8rem;border:1px solid var(--bronze);border-radius:999px;margin-bottom:.6rem;background:#fff' });
    var chips = h('div', { class: 'switcher' });
    var listEl = h('div', { id: 'lib-list' }, [h('div', { class: 'spin', text: 'Loading…' })]);
    var cat = S.libCat || '';
    ([''].concat(LIB_CATS)).forEach(function (c) {
      chips.appendChild(h('button', { class: cat === c ? 'on' : '', text: c || 'all', onclick: function () { S.libCat = c; viewLib(); } }));
    });
    var addBtn = h('button', { class: 'btn', text: '＋ Add exercise', onclick: function () { viewLib(null); } });
    box.appendChild(h('div', { style: 'display:flex;gap:.5rem;align-items:center;justify-content:space-between;margin-bottom:.6rem' }, [
      h('h2', { text: 'Exercise Library', style: 'margin:0;font-size:1.1rem' }), addBtn
    ]));
    box.appendChild(search);
    box.appendChild(chips);
    box.appendChild(listEl);
    shell(box);

    var t;
    function load() {
      var qs = '?q=' + encodeURIComponent(search.value.trim()) + (S.libCat ? '&category=' + encodeURIComponent(S.libCat) : '');
      api('/library' + qs).then(function (r) {
        listEl.innerHTML = '';
        if (!r.exercises.length) {
          var empty = h('div', { class: 'card' }, [
            h('p', { class: 'muted', text: search.value ? 'No matches.' : 'Your library is empty. Load the volleyball starter set (45 curated exercises with cues) and prune it to taste — or add your own.' })
          ]);
          if (!search.value && !S.libCat) {
            var seedBtn = h('button', { class: 'btn', text: 'Load starter set' });
            seedBtn.addEventListener('click', function () {
              seedBtn.disabled = true;
              api('/library/seed-starter', { method: 'POST' })
                .then(function (r2) { toast('Added ' + r2.added + ' exercises ✓'); load(); })
                .catch(function (e) { toast(e.message, 6000); seedBtn.disabled = false; });
            });
            empty.appendChild(seedBtn);
          }
          listEl.appendChild(empty);
          return;
        }
        var byCat = {};
        r.exercises.forEach(function (ex) { (byCat[ex.category || 'other'] = byCat[ex.category || 'other'] || []).push(ex); });
        Object.keys(byCat).forEach(function (c) {
          var card = h('div', { class: 'card' }, [h('h2', { text: c + ' · ' + byCat[c].length })]);
          byCat[c].forEach(function (ex) {
            var row = h('div', { class: 'ex', style: 'cursor:pointer' }, [
              h('b', { text: ex.name + (ex.level ? '  ·  ' + ex.level : '') }),
              ex.cues ? h('div', { class: 'cues', text: ex.cues }) : null,
              ex.equipment ? h('div', { class: 'rx', text: ex.equipment }) : null
            ]);
            row.addEventListener('click', function () { viewLib(ex); });
            card.appendChild(row);
          });
          listEl.appendChild(card);
        });
      }).catch(function (e) { listEl.innerHTML = ''; listEl.appendChild(h('div', { class: 'card', text: e.message })); });
    }
    search.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 300); });
    load();
  }

  // ---------- coach: programs (build via prompt, assign, copy, analyze) ----------
  function viewPrograms() {
    if (S.openProgram) { openProgram(S.openProgram); return; }
    var box = h('div');
    box.appendChild(h('h2', { text: 'Programs', style: 'margin:.2rem 0 .6rem;font-size:1.15rem' }));

    // prompt-build
    var pb = h('div', { class: 'card' }, [h('h2', { text: '✨ Build from a prompt' })]);
    var ta = h('textarea', { rows: '3', placeholder: 'e.g. Build a 4-week vertical block for a 16U outside hitter, 3 lifts/week, full gym, deload week 4.', style: 'width:100%;padding:.6rem;border:1px solid var(--bronze);border-radius:10px;font:inherit' });
    var gen = h('button', { class: 'btn', text: 'Generate draft' });
    gen.addEventListener('click', function () {
      if (!ta.value.trim()) { toast('Describe the program first.'); return; }
      gen.disabled = true; gen.textContent = 'Building…';
      api('/programs/generate', { method: 'POST', body: JSON.stringify({ prompt: ta.value.trim() }) })
        .then(function (r) { toast('Draft "' + r.title + '" created (' + r.days + ' days) ✓'); S.openProgram = r.id; viewPrograms(); })
        .catch(function (e) { toast(e.message, 7000); gen.disabled = false; gen.textContent = 'Generate draft'; });
    });
    pb.appendChild(ta); pb.appendChild(gen);
    pb.appendChild(h('p', { class: 'muted', text: 'Creates an editable draft — nothing reaches an athlete until you assign it.' }));
    box.appendChild(pb);

    // analyze
    var an = h('div', { class: 'card' }, [h('h2', { text: '📈 Analyze an athlete' })]);
    var sel = h('select', { style: 'width:100%;padding:.5rem;border:1px solid var(--bronze);border-radius:8px;background:#fff;margin-bottom:.4rem' });
    S.clients.forEach(function (c) { sel.appendChild(h('option', { value: c.id, text: c.name })); });
    var aq = h('input', { type: 'text', placeholder: 'Ask… (blank = full trend summary)', style: 'width:100%;padding:.55rem;border:1px solid var(--bronze);border-radius:8px;margin-bottom:.4rem' });
    var ab = h('button', { class: 'btn sec', text: 'Analyze' });
    var out = h('div', { id: 'an-out' });
    ab.addEventListener('click', function () {
      ab.disabled = true; ab.textContent = 'Thinking…'; out.innerHTML = '';
      api('/programs/analyze', { method: 'POST', body: JSON.stringify({ client_id: sel.value, prompt: aq.value.trim() }) })
        .then(function (r) {
          ab.disabled = false; ab.textContent = 'Analyze';
          out.appendChild(h('div', { class: 'analysis' }, [
            h('div', { class: 'muted', text: r.stats.completed + '/' + r.stats.scheduled + ' completed · ' + r.stats.missed + ' missed (6 wks)' }),
            h('p', { text: r.analysis })
          ]));
        })
        .catch(function (e) { ab.disabled = false; ab.textContent = 'Analyze'; toast(e.message, 7000); });
    });
    an.appendChild(sel); an.appendChild(aq); an.appendChild(ab); an.appendChild(out);
    box.appendChild(an);

    // list
    var listCard = h('div', { class: 'card' }, [h('h2', { text: 'Your programs' }), h('div', { id: 'prog-list', class: 'spin', text: 'Loading…' })]);
    box.appendChild(listCard);
    shell(box);
    api('/programs').then(function (r) {
      var el = document.getElementById('prog-list'); el.classList.remove('spin'); el.innerHTML = '';
      if (!r.programs.length) { el.appendChild(h('p', { class: 'muted', text: 'No programs yet — build one above.' })); return; }
      r.programs.forEach(function (p) {
        var row = h('div', { class: 'ex', style: 'cursor:pointer' }, [
          h('b', { text: p.title }),
          h('div', { class: 'rx', text: [p.status, p.weeks + 'wk', p.days_per_week + '/wk', p.goal].filter(Boolean).join(' · ') })
        ]);
        row.addEventListener('click', function () { S.openProgram = p.id; viewPrograms(); });
        el.appendChild(row);
      });
    }).catch(function (e) { var el = document.getElementById('prog-list'); if (el) { el.classList.remove('spin'); el.textContent = e.message; } });
  }

  function openProgram(id) {
    var box = h('div');
    box.appendChild(h('button', { class: 'btn sec small', text: '‹ Programs', onclick: function () { S.openProgram = null; viewPrograms(); } }));
    box.appendChild(h('div', { class: 'spin', text: 'Loading…', id: 'prog-detail' }));
    shell(box);
    api('/programs/' + id).then(function (r) {
      var host = document.getElementById('prog-detail'); host.classList.remove('spin'); host.innerHTML = '';
      var p = r.program;
      host.appendChild(h('h2', { text: p.title, style: 'margin:.2rem 0' }));
      host.appendChild(h('div', { class: 'muted', text: [p.status, p.goal, p.athlete_level, p.weeks + ' weeks'].filter(Boolean).join(' · ') }));

      // assign + copy
      var actions = h('div', { class: 'card' }, [h('h2', { text: 'Assign to an athlete' })]);
      var sel = h('select', { style: 'width:100%;padding:.5rem;border:1px solid var(--bronze);border-radius:8px;background:#fff;margin-bottom:.4rem' });
      S.clients.forEach(function (c) { sel.appendChild(h('option', { value: c.id, text: c.name })); });
      var date = h('input', { type: 'date', value: fmtDate(new Date()), style: 'width:100%;padding:.5rem;border:1px solid var(--bronze);border-radius:8px;margin-bottom:.4rem' });
      var asg = h('button', { class: 'btn', text: 'Assign — put on their calendar' });
      asg.addEventListener('click', function () {
        asg.disabled = true;
        api('/programs/' + id + '/assign', { method: 'POST', body: JSON.stringify({ client_id: sel.value, start_date: date.value }) })
          .then(function (rr) { toast('Assigned — ' + rr.workouts_created + ' sessions added ✓'); asg.disabled = false; })
          .catch(function (e) { toast(e.message, 7000); asg.disabled = false; });
      });
      var cpy = h('button', { class: 'btn sec', text: 'Make a copy to customize', style: 'margin-top:.5rem' });
      cpy.addEventListener('click', function () {
        api('/programs/' + id + '/copy', { method: 'POST' }).then(function (rr) { toast('Copied ✓'); S.openProgram = rr.id; viewPrograms(); }).catch(function (e) { toast(e.message); });
      });
      actions.appendChild(sel); actions.appendChild(date); actions.appendChild(asg); actions.appendChild(cpy);
      host.appendChild(actions);

      // ---- smart spreadsheet grid (editable, Superset-Sheets-style) ----
      host.appendChild(h('p', { class: 'muted', text: 'Tap any cell to edit. Changes save automatically.' }));
      r.days.forEach(function (d) { host.appendChild(gridDay(id, d)); });

      // add a day
      var addDay = h('button', { class: 'btn sec', text: '＋ Add a day', style: 'margin:.4rem 0 5rem' });
      addDay.addEventListener('click', function () {
        var lastWeek = r.days.length ? r.days[r.days.length - 1].week_index : 1;
        var dayNums = r.days.filter(function (x) { return x.week_index === lastWeek; }).map(function (x) { return x.day_index; });
        var nextDay = dayNums.length ? Math.max.apply(null, dayNums) + 1 : 1;
        api('/programs/' + id + '/exercise', { method: 'POST', body: JSON.stringify({ week_index: lastWeek, day_index: nextDay, exercise_name: 'New exercise', sets: '3', reps: '8' }) })
          .then(function () { openProgram(id); }).catch(function (e) { toast(e.message); });
      });
      host.appendChild(addDay);
    }).catch(function (e) { var el = document.getElementById('prog-detail'); if (el) { el.classList.remove('spin'); el.textContent = e.message; } });
  }

  // one day = a spreadsheet block: header row + editable exercise rows + add-row
  var LOAD_MODES_UI = ['weight', 'rpe', 'percent', 'bw', 'none'];
  var REP_UNITS_UI = ['reps', 'sec', 'each_side', 'each'];
  function gridDay(pid, d) {
    var card = h('div', { class: 'card gridcard' });
    card.appendChild(h('div', { class: 'gridhead' }, [
      h('b', { text: 'W' + d.week_index + ' · D' + d.day_index }),
      d.block_label ? h('span', { class: 'blockchip', text: d.block_label }) : null,
      d.title ? h('span', { class: 'muted', text: d.title }) : null
    ]));
    var table = h('div', { class: 'sheet' });
    table.appendChild(h('div', { class: 'srow shdr' }, [
      h('span', { class: 'c-ex', text: 'Exercise' }), h('span', { class: 'c-s', text: 'Sets' }),
      h('span', { class: 'c-r', text: 'Reps' }), h('span', { class: 'c-u', text: 'Unit' }),
      h('span', { class: 'c-lm', text: 'Load' }), h('span', { class: 'c-lv', text: 'Val' }),
      h('span', { class: 'c-x', text: '' })
    ]));
    (d.exercises || []).forEach(function (ex) { table.appendChild(gridRow(pid, d, ex)); });
    card.appendChild(table);
    var add = h('button', { class: 'gridadd', text: '＋ exercise' });
    add.addEventListener('click', function () {
      api('/programs/' + pid + '/exercise', { method: 'POST', body: JSON.stringify({ week_index: d.week_index, day_index: d.day_index, exercise_name: 'New exercise', sets: '3', reps: '8' }) })
        .then(function (res) {
          var ex = { id: res.exercise_id, exercise_name: 'New exercise', prescribed_sets: '3', prescribed_reps: '8', rep_unit: 'reps', load_mode: 'weight', prescribed_load: '', entry_type: 'sets', is_optional: 0, circuit_group: '' };
          table.appendChild(gridRow(pid, d, ex));
        }).catch(function (e) { toast(e.message); });
    });
    card.appendChild(add);
    return card;
  }
  function gridRow(pid, d, ex) {
    // debounced save of the whole row
    var saveT;
    function save() {
      clearTimeout(saveT);
      saveT = setTimeout(function () {
        var payload = {
          week_index: d.week_index, day_index: d.day_index, exercise_id: ex.id,
          exercise_name: ex.exercise_name, entry_type: ex.entry_type || 'sets',
          sets: ex.prescribed_sets, reps: ex.prescribed_reps, rep_unit: ex.rep_unit,
          load_mode: ex.load_mode, load: ex.prescribed_load, circuit_group: ex.circuit_group || '',
          is_optional: parseInt(ex.is_optional, 10) ? 1 : 0
        };
        api('/programs/' + pid + '/exercise', { method: 'POST', body: JSON.stringify(payload) }).catch(function (e) { toast(e.message); });
      }, 500);
    }
    function txt(val, cls, key, ph) {
      var i = h('input', { class: 'cell ' + cls, value: val || '', placeholder: ph || '' });
      i.addEventListener('input', function () { ex[key] = i.value; save(); });
      return i;
    }
    function sel(val, cls, key, opts) {
      var s = h('select', { class: 'cell ' + cls });
      opts.forEach(function (o) { var e = h('option', { value: o, text: o }); if (o === val) e.selected = true; s.appendChild(e); });
      s.addEventListener('change', function () { ex[key] = s.value; save(); });
      return s;
    }
    var row = h('div', { class: 'srow' }, [
      txt(ex.exercise_name, 'c-ex', 'exercise_name', 'exercise'),
      txt(ex.prescribed_sets, 'c-s', 'prescribed_sets'),
      txt(ex.prescribed_reps, 'c-r', 'prescribed_reps'),
      sel(ex.rep_unit || 'reps', 'c-u', 'rep_unit', REP_UNITS_UI),
      sel(ex.load_mode || 'weight', 'c-lm', 'load_mode', LOAD_MODES_UI),
      txt(ex.prescribed_load, 'c-lv', 'prescribed_load'),
      h('button', { class: 'c-x del', text: '×', title: 'Delete', onclick: function () {
        api('/programs/exercise/' + ex.id, { method: 'DELETE' }).then(function () { row.remove(); }).catch(function (e) { toast(e.message); });
      } })
    ]);
    return row;
  }

  // ---------- boot ----------
  function render() {
    stopPoll();
    if (S.tab === 'dash') viewDash();
    else if (S.tab === 'msg') viewMsg();
    else if (S.tab === 'lib') viewLib();
    else if (S.tab === 'prog') viewPrograms();
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
