// ============================================================================
//  Gerçek hit ölçümü (puppeteer + Chromium + gerçek PHP/MySQL sunucusu)
//
//  Kullanıcı giriş yapar, odaya girer ve ekrana DOKUNMADAN bekler.
//  Bu süre boyunca sunucuya giden TÜM auth.php istekleri tarayıcı düzeyinde
//  sayılır (fetch/XHR fark etmez). Zamanlayıcılar GERÇEK hızında çalışır;
//  hiçbir hızlandırma uygulanmaz — ölçülen sayı üretimdeki sayıdır.
//
//  Kullanım: node _tests/hit_olcum.js [oda-saniye] [ana-ekran-saniye]
// ============================================================================
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const ORIGIN = process.env.ORIGIN || 'http://127.0.0.1:8090';

const ROOM_SECONDS = Number(process.argv[2] || 150);
const HOME_SECONDS = Number(process.argv[3] || 150);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function tally(label, reqs, seconds) {
  const byAction = {};
  reqs.forEach((a) => { byAction[a] = (byAction[a] || 0) + 1; });
  const total = reqs.length;
  const perMin = (total / seconds) * 60;
  console.log(`\n### ${label} — ${seconds} sn`);
  Object.keys(byAction).sort().forEach((k) => console.log(`    ${k.padEnd(20)} ${byAction[k]}`));
  console.log(`    TOPLAM               ${total} istek  =>  ${perMin.toFixed(2)} istek/dk`);
  console.log(`    saatlik              ${(perMin * 60).toFixed(0)}   günlük (24s) ${(perMin * 1440).toFixed(0)}`);
  return { total, perMin, byAction };
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 393, height: 850, isMobile: true });
  await page.setUserAgent('Mozilla/5.0 (Linux; Android 14) AsraApp/1.7.1');

  let recording = false;
  const reqs = [];
  page.on('request', (r) => {
    const m = String(r.url()).match(/auth\.php\?action=([a-z_]+)/);
    if (m && recording) reqs.push(m[1]);
  });

  await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(1200);
  await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
  await page.evaluate(() => {
    const u = document.querySelector('#login-username');
    const p = document.querySelector('#login-password');
    u.value = 'uiadmin'; u.dispatchEvent(new Event('input', { bubbles: true }));
    p.value = 'Test123!xyz'; p.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('#login-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 20000 }).catch(() => {});
  await sleep(2000);

  // Odaya gir, önbellek dolsun (bu giriş isteği ölçüme DAHİL DEĞİL).
  await page.evaluate(() => { const c = Array.from(document.querySelectorAll('.room-card')).find((el) => el.dataset.roomId !== '0'); if (c) c.click(); });
  await sleep(3000);

  // ---- 1) ODA BOŞTA ----
  reqs.length = 0; recording = true;
  const t0 = Date.now();
  await sleep(ROOM_SECONDS * 1000);
  recording = false;
  tally('ODA BOŞTA (ekran görünür, hiç dokunulmadı)', reqs.slice(), (Date.now() - t0) / 1000);

  // ---- 2) ANA EKRAN ----
  await page.evaluate(() => { state.currentRoom = null; renderHome(); });
  await sleep(2000);
  reqs.length = 0; recording = true;
  const t1 = Date.now();
  await sleep(HOME_SECONDS * 1000);
  recording = false;
  tally('ANA EKRAN (ekran görünür, hiç dokunulmadı)', reqs.slice(), (Date.now() - t1) / 1000);

  // ---- 3) EKRAN GİZLİ ----
  const cdp = await page.target().createCDPSession();
  await cdp.send('Emulation.setPageScaleFactor', { pageScaleFactor: 1 }).catch(() => {});
  await page.evaluate(() => {
    Object.defineProperty(document, 'visibilityState', { value: 'hidden', configurable: true });
    Object.defineProperty(document, 'hidden', { value: true, configurable: true });
    document.dispatchEvent(new Event('visibilitychange'));
  });
  await page.evaluate(() => { const c = Array.from(document.querySelectorAll('.room-card')).find((el) => el.dataset.roomId !== '0'); if (c) c.click(); });
  await sleep(2000);
  reqs.length = 0; recording = true;
  const t2 = Date.now();
  await sleep(70000);
  recording = false;
  tally('ODA + EKRAN GİZLİ (arka plan)', reqs.slice(), (Date.now() - t2) / 1000);

  await browser.close();
})().catch((e) => { console.error('HATA:', e && e.message ? e.message : e); process.exit(1); });
