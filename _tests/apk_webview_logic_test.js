// APK WebView'e enjekte edilen kodun gerçek JS davranışı (Node VM).
// Android WebView/izin diyaloglarının yerini TUTMAZ; saf yönlendirme mantığını sınar.
'use strict';
const fs = require('fs');
const vm = require('vm');
const assert = require('assert/strict');
const path = require('path');
const root = path.resolve(__dirname, '..');
const src = fs.readFileSync(path.join(root, 'asra-apk-build/app/src/main/java/app/asra/client/AsraInject.kt'), 'utf8');
const page = fs.readFileSync(path.join(root, 'asra-site-fix/index.html'), 'utf8');
let pass = 0;
function test(title, fn) {
  try { fn(); pass++; console.log(`✓ ${title}`); }
  catch (e) { console.error(`✗ ${title}: ${e.stack}`); process.exitCode = 1; }
}
function kotlinScript(name) {
  const pattern = new RegExp(`\\bconst val ${name} = """([\\s\\S]*?)"""`);
  const found = src.match(pattern);
  assert.ok(found, `${name} not found`);
  return found[1];
}
const backCode = kotlinScript('BACK_SCRIPT');
const injectCode = kotlinScript('SCRIPT');

function makeDom(flags) {
  const clicks = [];
  const nodes = {};
  for (const [id, hidden] of Object.entries(flags)) {
    nodes[id] = { hidden, click() { clicks.push(id); }, classList: { contains: (s) => s === 'active' && id === 'register-tab' && !hidden } };
  }
  return { clicks, document: { getElementById: (id) => nodes[id] || null } };
}
function back(flags) {
  const env = makeDom(flags);
  const answer = vm.runInNewContext(backCode, { document: env.document });
  return [answer, env.clicks];
}

test('SPA: modal kapatma > menü > ekran > çıkış sırası', () => {
  const f = { 'modal-backdrop': false, 'modal-close': false, 'account-menu': false, 'menu-button': false, 'app-shell': false, 'back-button': false };
  assert.deepEqual(back(f), ['handled', ['modal-close']]);
  f['modal-backdrop'] = true;
  assert.deepEqual(back(f), ['handled', ['menu-button']]);
  f['account-menu'] = true;
  assert.deepEqual(back(f), ['handled', ['back-button']]);
});
test('SPA: ana sayfada çıkış; kayıtta login sekmesine dön', () => {
  assert.deepEqual(back({ 'app-shell': false, 'back-button': true, 'auth-screen': true }), ['native', []]);
  assert.deepEqual(back({ 'auth-screen': false, 'register-tab': false, 'login-tab': false }), ['handled', ['login-tab']]);
});
test('Enjekte edilen köprü site bağlamında çalışıyor', () => {
  const calls = []; const rooms = [];
  const ctx = { URL, Headers, Request, location: new URL('https://asra.42web.io/'),
    fetch(input, init) { calls.push({input, init}); return Promise.resolve({ok: true}); },
    AsraNative: { getDeviceId() { return 'fake-device'; } },
    state: { rooms: [{id: 12, name: 'Test room'}] }, enterRoom(room) { rooms.push(room.id); } };
  ctx.window = ctx;
  vm.runInNewContext(injectCode, ctx);
  assert.equal(typeof ctx.asraOpenRoom, 'function');
  ctx.asraOpenRoom(12); ctx.asraOpenRoom(0);
  assert.equal(rooms.join(','), '12');
  ctx.fetch('auth.php?action=session', {headers: { 'X-Original': 'present' }});
  assert.equal(calls[0].init.headers.get('X-Original'), 'present');
  assert.equal(calls[0].init.headers.get('X-Asra-Device-Id'), 'fake-device');
  const original = new Request('https://asra.42web.io/auth.php', { headers: { 'X-From-Request': 'yes' }});
  ctx.fetch(original);
  assert.equal(calls[1].init.headers.get('X-From-Request'), 'yes');
  assert.equal(calls[1].init.headers.get('X-Asra-Device-Id'), 'fake-device');
  const extInit = { headers: { 'X-External': 'yes' }, credentials: 'omit' };
  ctx.fetch('https://eba.gov.tr/visit', extInit);
  assert.equal(calls[2].init, extInit, 'external fetch must be unchanged');
  assert.equal(calls[2].init.headers['X-Asra-Device-Id'], undefined);
  assert.equal(calls[2].init.credentials, 'omit');
  // URL nesnesi Request'ten farklıdır: input.url undefined idi ve eski kod
  // dış URL'yi yanlışlıkla yerel sayıp cihaz kimliğini sızdırabiliyordu.
  ctx.fetch(new URL('https://eba.gov.tr/ders'));
  assert.equal(calls[3].init, undefined, 'external URL object must be unchanged');
  ctx.fetch(new URL('https://asra.42web.io/auth.php'));
  assert.equal(calls[4].init.headers.get('X-Asra-Device-Id'), 'fake-device');
});
test('Ürün sayfası enjekte edilen geri seçicilerini barındırıyor', () => {
  ['modal-backdrop', 'modal-close', 'account-menu', 'menu-button', 'app-shell', 'back-button', 'auth-screen', 'register-tab', 'login-tab']
    .forEach((id) => assert.ok(page.includes(`id="${id}"`), `${id} missing`));
});
console.log(`SONUÇ: ✓${pass} ✗${process.exitCode ? 1 : 0}`);
