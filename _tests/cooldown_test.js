// 5 saniyelik gönderim cooldown E2E (gerçek Chromium, puppeteer-core)
// Kullanım: node cooldown_test.js
// Beklenen davranış:
//  1) Gönderimden sonra buton kilitli + üzerinde 5..4..3..2..1 geri sayımı
//  2) Süre bitmeden yazı girilse bile gönderilemez (0 istek, 0 yeni satır)
//  3) Süre bitince buton eski hâline döner, tekrar gönderilebilir
const puppeteer = require('puppeteer-core');
const crypto = require('crypto');

const URL = 'http://127.0.0.1:8090/';

(async () => {
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 393, height: 850, isMobile: true });
  await page.evaluateOnNewDocument(() => {
    window.__NTFY_BASE__ = 'http://127.0.0.1:8090/ntfy';
    window.__NTFY_INTERVAL_MS__ = 60000;
    window.__fetchCalls = [];
    const orig = window.fetch;
    window.fetch = function (input, init) {
      try { window.__fetchCalls.push(String(input)); } catch (e) {}
      return orig.apply(this, arguments);
    };
  });

  let pass = 0, fail = 0;
  const ok = (d) => { pass++; console.log('  ✓ ' + d); };
  const bad = (d) => { fail++; console.log('  ✗ ' + d); };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));
  const hostCalls = () => page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);

  await page.goto(URL, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await wait(2000);

  // Giriş
  await page.evaluate(() => {
    const i = document.querySelector('#invite-form input[name="code"]');
    i.value = 'DAVETDAVETJSJEJSNFJSNDJSDNFH';
    i.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('#invite-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  await page.waitForSelector('#login-username', { timeout: 20000 });
  await page.evaluate(() => {
    const u = document.querySelector('#login-username');
    const p = document.querySelector('#login-password');
    u.value = 'uiadmin'; u.dispatchEvent(new Event('input', { bubbles: true }));
    p.value = 'Test123!xyz'; p.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('#login-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 20000 });
  await wait(2500);
  await page.evaluate(() => { enterRoom(state.rooms[0]); });
  await wait(2500);

  const submitInfo = () => page.evaluate(() => {
    const form = document.querySelector('form:has(#message-input)') || document.querySelector('#message-input').closest('form');
    const b = form.querySelector('button[type="submit"]');
    return { disabled: b.disabled, text: b.textContent.trim(), html: b.innerHTML, guard: state.sendCooldownActive };
  });

  const rows = () => page.evaluate(() => document.querySelectorAll('#message-list .message-row').length);
  const send = (text) => page.evaluate((t) => {
    const ta = document.querySelector('#message-input');
    const form = ta.closest('form');
    ta.value = t;
    ta.dispatchEvent(new Event('input', { bubbles: true }));
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }, text);

  console.log('== 5s cooldown E2E ==');

  // 1) İlk gönderim
  const h0 = await hostCalls();
  const r0 = await rows();
  await send('cooldown-1');
  await wait(300);
  const s0 = await submitInfo();
  const h1 = await hostCalls();
  (h1 - h0) === 1 ? ok('1. gönderim: 1 istek') : bad('1. gönderim ' + (h1 - h0) + ' istek');
  (await rows()) === r0 + 1 ? ok('1. gönderim yerel çizildi') : bad('1. mesaj çizilmedi');
  s0.disabled ? ok('gönderimden sonra buton KİLİTLİ') : bad('buton kilitli değil!');
  s0.text === '5' ? ok('buton üzerinde "5" geri sayımı') : bad('geri sayım başlangıcı: "' + s0.text + '"');
  s0.guard ? ok('guard (sendCooldownActive) aktif') : bad('guard aktif değil');

  // 2) Geri sayım 5..4..3..2..1
  const seen = ['5'];
  for (let s = 1; s <= 4; s++) {
    await wait(1050);
    const si = await submitInfo();
    seen.push(si.text);
    si.disabled ? ok('t+' + s + 'sn: kilitli ("' + si.text + '")') : bad('t+' + s + 'sn: kilit açılmış ("' + si.text + '")');
  }
  JSON.stringify(seen) === JSON.stringify(['5', '4', '3', '2', '1']) ? ok('geri sayım sırası 5-4-3-2-1 doğru') : bad('sırası yanlış: ' + seen.join(','));

  // 3) Süre bitmeden yazı gir + gönder dene -> ENGELLENSİN (0 istek, 0 satır)
  //    (t≈4.3sn, hâlâ cooldown içinde)
  const hb = await hostCalls();
  const rb = await rows();
  await send('BU-GITMEMELI');
  await wait(800);
  const ha = await hostCalls();
  (ha - hb) === 0 ? ok('cooldown içinde yazı girildi: 0 istek (engellendi)') : bad('cooldown içinde ' + (ha - hb) + ' istek gitti!');
  (await rows()) === rb ? ok('cooldown içinde yeni satır çizilmedi') : bad('cooldown içinde satır çizildi!');
  const stillBlocked = await page.evaluate(() => document.querySelector('#message-list .message-row:last-child .message-text') ? document.querySelector('#message-list').textContent.includes('BU-GITMEMELI') : false);
  !stillBlocked ? ok('"BU-GITMEMELI" ekranda YOK (tamamen engellendi)') : bad('engellenen mesaj ekranda!');

  // 4) Süre bitince buton eski hâline döner
  await wait(1200); // t≈5.5sn
  const sEnd = await submitInfo();
  !sEnd.disabled ? ok('5sn dolunca buton AÇILDI') : bad('5sn dolunca hâlâ kilitli');
  !sEnd.guard ? ok('guard sıfırlandı') : bad('guard sıfırlanmadı');
  sEnd.html !== '1' && sEnd.html.length > 1 ? ok('buton içeriği eski hâline döndü') : bad('içerik eski hâline dönmedi: ' + sEnd.html);

  // 5) Süre bitince tekrar gönderilebilir
  const h2 = await hostCalls();
  const r2 = await rows();
  await send('cooldown-2');
  await wait(1000);
  const h3 = await hostCalls();
  (h3 - h2) === 1 ? ok('süre bitince tekrar gönderim: 1 istek') : bad('tekrar gönderim ' + (h3 - h2) + ' istek');
  (await rows()) === r2 + 1 ? ok('2. mesaj yerel çizildi') : bad('2. mesaj çizilmedi');
  const visible2 = await page.evaluate(() => document.querySelector('#message-list').textContent.includes('cooldown-2'));
  visible2 ? ok('"cooldown-2" ekranda') : bad('"cooldown-2" ekranda yok');

  // 6) İki mesaj arasında cooldown yoksa (süre bitmiş) art arda 2. gönderim
  //    cooldown yeniden başlar: buton tekrar kilitli
  const sAgain = await submitInfo();
  sAgain.disabled ? ok('2. gönderimden sonra cooldown YENİDEN başladı') : bad('2. gönderimde cooldown başlamadı');

  await browser.close();
  console.log('\nSONUÇ: ✓' + pass + ' ✗' + fail);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('HATA:', e.message); process.exit(2); });
