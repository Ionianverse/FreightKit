/* BO Planning System – sign-in and loader.
   The tool itself is in app.bundle, encrypted with a key derived from the ID and password.
   Nothing is sent to a server: the bundle is decrypted in this browser and kept in memory only. */
(() => {
  'use strict';
  const $ = (id) => document.getElementById(id);
  const yr = $('yr'); if (yr) yr.textContent = new Date().getFullYear();
  const enc = new TextEncoder();
  const b64 = (s) => Uint8Array.from(atob(s), (c) => c.charCodeAt(0));
  let failures = 0;

  function say(msg, isError) {
    const el = $('login-status');
    el.textContent = msg;
    el.className = 'bo-login-status' + (isError ? ' is-error' : '');
  }

  async function unlock(id, password) {
    const res = await fetch('app.bundle', { cache: 'no-store' });
    if (!res.ok) throw new Error('The tool file could not be loaded.');
    const bundle = await res.json();
    if (bundle.format !== 'bo-bundle-1') throw new Error('Unknown tool file format.');
    const base = await crypto.subtle.importKey('raw', enc.encode(`${id}\n${password}`), 'PBKDF2', false, ['deriveKey']);
    const key = await crypto.subtle.deriveKey(
      { name: 'PBKDF2', hash: 'SHA-256', salt: b64(bundle.salt), iterations: bundle.iterations },
      base, { name: 'AES-GCM', length: 256 }, false, ['decrypt']
    );
    let packed;
    try {
      packed = await crypto.subtle.decrypt({ name: 'AES-GCM', iv: b64(bundle.iv), additionalData: enc.encode('bo-bundle-1') }, key, b64(bundle.data));
    } catch (_) {
      const err = new Error('ID or password is incorrect.'); err.auth = true; throw err;
    }
    const stream = new Blob([packed]).stream().pipeThrough(new DecompressionStream('deflate-raw'));
    return JSON.parse(await new Response(stream).text());
  }

  function loadScript(code, name) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(new Blob([code + `\n//# sourceURL=bo/${name}`], { type: 'text/javascript' }));
      const s = document.createElement('script');
      s.src = url;
      s.onload = () => { URL.revokeObjectURL(url); resolve(); };
      s.onerror = () => { URL.revokeObjectURL(url); reject(new Error(`Could not start ${name}.`)); };
      document.body.appendChild(s);
    });
  }

  async function mount(app) {
    const style = document.createElement('style');
    style.textContent = app.css;
    document.head.appendChild(style);
    $('app-root').innerHTML = app.html;
    $('login-card').remove();
    $('signed-in').hidden = false;
    for (const s of app.scripts) await loadScript(s.code, s.name);
  }

  $('login-form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const id = $('login-id').value.trim().toLowerCase();
    const password = $('login-password').value;
    if (!id || !password) { say('Enter the ID and password.', true); return; }
    if (!window.crypto || !crypto.subtle || typeof DecompressionStream === 'undefined') { say('This browser is too old for this tool. Use a current Chrome, Edge, Firefox or Safari.', true); return; }
    $('login-btn').disabled = true;
    say('Checking…');
    if (failures >= 3) await new Promise((r) => setTimeout(r, 2000 * (failures - 2)));
    try {
      const app = await unlock(id, password);
      $('login-password').value = '';
      await mount(app);
    } catch (err) {
      failures += err.auth ? 1 : 0;
      say(err.message || 'Could not open the tool.', true);
      $('login-password').value = '';
      $('login-password').focus();
    } finally {
      const btn = $('login-btn'); if (btn) btn.disabled = false;
    }
  });
  $('sign-out').addEventListener('click', () => window.location.reload());
  $('login-id').focus();
})();
