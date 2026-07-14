/* MPVK passkey login — injected on wp-login.php when passkeys are enabled. */
(function () {
  if (!window.PublicKeyCredential || !navigator.credentials) return;
  var slot = document.getElementById('mpvk-passkey-slot');
  if (!slot || !window.MPVK_PK) return;

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
  function post(path, body) {
    return fetch(MPVK_PK.rest + path, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    }).then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.message || 'HTTP ' + r.status); return j; }); });
  }

  var note = document.createElement('div');
  note.style.cssText = 'color:#a00;font-size:13px;margin-top:6px;display:none';

  var btn = document.createElement('button');
  btn.type = 'button';
  btn.textContent = '🔒 Sign in with Face ID / passkey';
  btn.style.cssText = 'width:100%;padding:10px;font-size:14px;font-weight:600;cursor:pointer;' +
    'border:1.5px solid #2C5F63;border-radius:6px;background:#fff;color:#2C5F63';

  btn.addEventListener('click', function () {
    btn.disabled = true; note.style.display = 'none';
    post('/passkey/login/options').then(function (opts) {
      var pk = opts.publicKey;
      pk.challenge = b64uToBuf(pk.challenge);
      (pk.allowCredentials || []).forEach(function (c) { c.id = b64uToBuf(c.id); });
      return navigator.credentials.get({ publicKey: pk }).then(function (cred) {
        return post('/passkey/login', {
          request_id: opts.request_id,
          credential: {
            id: cred.id,
            rawId: bufToB64u(cred.rawId),
            response: {
              clientDataJSON: bufToB64u(cred.response.clientDataJSON),
              authenticatorData: bufToB64u(cred.response.authenticatorData),
              signature: bufToB64u(cred.response.signature),
              userHandle: cred.response.userHandle ? bufToB64u(cred.response.userHandle) : ''
            }
          }
        });
      });
    }).then(function (r) {
      window.location.href = r.redirect || '/';
    }).catch(function (e) {
      btn.disabled = false;
      if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return; // user cancelled — stay quiet
      note.textContent = (e && e.message) || 'Passkey sign-in failed — use your password below.';
      note.style.display = 'block';
    });
  });

  slot.appendChild(btn);
  slot.appendChild(note);
})();
