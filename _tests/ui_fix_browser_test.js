// ============================================================================
//  ARAYÜZ ONARIMLARI — tarayıcı doğrulaması (puppeteer + gerçek Chromium)
//
//  Kullanıcının bildirdiği üç kusur:
//    1) "Mesajı atınca görenler çalışmıyor, giremiyorum"
//       Kök neden: yerel çizilen satırın görenler butonu, dinleyici
//       kurulurken yakalanan message.id'yi kullanıyordu. Mesaj sunucuya
//       teslim edilince satırın dataset'i resmî id'ye dönüyor ama kapanış
//       eski ("local-…") kimlikte kalıyordu -> Number() = NaN -> buton
//       SESSİZCE hiçbir şey yapmıyordu.
//    2) "Mesaj temizlenme süresi çalışmıyor"
//       Kök neden: sunucu geri sayımı yalnızca odaya İLK girişte (meta=1)
//       ve odada mesaj varsa gönderiyordu; boş odaya girip yazınca sayaç
//       hiç başlamıyordu.
//    3) "Süre ekranda yarısı gözüküyor, kaydırmalı bir şey ekle"
//       Kök neden: .countdown mobilde overflow:hidden + text-overflow:ellipsis
//       olduğu için geri sayım kesiliyordu.
//
//  Ölçülenler gerçek sayfada, gerçek PHP/MySQL sunucusuna karşı.
//  Çıkış: 0 = hepsi geçti.
// ============================================================================
const puppeteer = require('puppeteer-core');
const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const ORIGIN = process.env.ORIGIN || 'http://127.0.0.1:8093';
const TDIR = process.argv[2] || '/tmp';

let pass = 0;
let fail = 0;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); };
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });
  const page = await browser.newPage();
  const apiRequests = [];
  page.on('request', (req) => {
    const m = String(req.url()).match(/action=([a-z_]+)/);
    if (m) apiRequests.push(m[1]);
  });
  await page.setViewport({ width: 393, height: 850, isMobile: true });
  await page.setUserAgent('Mozilla/5.0 (Linux; Android 14) AsraApp/1.7.1');
  await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(1200);

  // --- giriş ---------------------------------------------------------------
  await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
  await page.evaluate(() => {
    const u = document.querySelector('#login-username');
    const p = document.querySelector('#login-password');
    if (!u || !p) return;
    u.value = 'uiadmin'; u.dispatchEvent(new Event('input', { bubbles: true }));
    p.value = 'Test123!xyz'; p.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('#login-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 20000 }).catch(() => {});
  await sleep(2000);

  check((await page.evaluate(() => state.route)) === 'home', 'oturum açıldı, ana ekranda');
  check((await page.evaluate(() => Number(state.retentionHours))) === 24,
    'saklama süresi SUNUCUDAN geldi (state.retentionHours=24)');

  // --- odaya gir (önceden boşaltıldı -> boş oda senaryosu) -----------------
  const roomId = await page.evaluate(() => {
    const card = Array.from(document.querySelectorAll('.room-card')).find((el) => el.dataset.roomId !== '0');
    if (!card) return 0;
    const id = Number(card.dataset.roomId || 0);
    card.click();
    return id;
  });
  await sleep(2500);
  const entered = await page.evaluate(() => ({ route: state.route, id: state.currentRoom ? Number(state.currentRoom.id) : 0 }));
  check(entered.route === 'room' && entered.id === roomId, 'odaya girildi (id=' + entered.id + ')');

  const emptyRoom = await page.evaluate(() => ({
    nextCleanupAt: state.nextCleanupAt,
    text: (document.querySelector('.room-notice .countdown') || {}).textContent || '',
  }));
  check(emptyRoom.nextCleanupAt === null, 'boş odada geri sayım başlangıçta yok (dürüst durum)');
  check(/oda boş/.test(emptyRoom.text), 'boş odada uydurma sayaç yerine "oda boş" yazıyor');

  // --- mesaj gönder ve GÖRENLER'e tıkla -----------------------------------
  const marker = 'UIFIX-' + Date.now().toString().slice(-6);
  await page.evaluate((text) => {
    const t = document.querySelector('#message-input');
    t.value = text;
    t.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('.composer').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }, marker);
  // outbox sunucuya iletilip satır resmî id'ye dönene kadar bekle
  let reconciled = null;
  for (let i = 0; i < 25; i += 1) {
    await sleep(400);
    reconciled = await page.evaluate((text) => {
      const rows = Array.from(document.querySelectorAll('#message-list .message-row'));
      const row = rows.find((r) => (r.textContent || '').includes(text));
      if (!row) return null;
      const btn = row.querySelector('.viewers-button');
      return { msgId: row.dataset.msgId || '', label: btn ? btn.textContent : '', hasBtn: !!btn };
    }, marker);
    if (reconciled && /^\d+$/.test(reconciled.msgId)) break;
  }
  check(!!reconciled && reconciled.hasBtn, 'gönderilen satırda görenler butonu var');
  check(!!reconciled && /^\d+$/.test(reconciled.msgId || ''),
    'satır sunucu kimliğine döndü (msgId=' + (reconciled ? reconciled.msgId : 'yok') + ')');

  apiRequests.length = 0;
  const clicked = await page.evaluate((text) => {
    const rows = Array.from(document.querySelectorAll('#message-list .message-row'));
    const row = rows.find((r) => (r.textContent || '').includes(text));
    const btn = row && row.querySelector('.viewers-button');
    if (!btn) return false;
    btn.click();
    return true;
  }, marker);
  check(clicked, 'görenler butonuna tıklandı');
  await sleep(2200);

  const modal = await page.evaluate(() => ({
    open: !document.querySelector('#modal-backdrop').hidden,
    title: (document.querySelector('#modal-title') || {}).textContent || '',
    body: (document.querySelector('#modal-content') || {}).textContent || '',
  }));
  check(modal.open && modal.title === 'Görenler', 'GÖRENLER penceresi AÇILDI (başlık: "' + modal.title + '")');
  check(apiRequests.includes('get_message_views'), 'get_message_views isteği gerçekten gitti');
  check(/okundu bilgisi|Görünür|uiadmin/i.test(modal.body), 'pencere içerik döndürdü');

  // --- geri sayım başladı mı + kaydırılabilir mi --------------------------
  await page.evaluate(() => { document.querySelector('#modal-close').click(); });
  const retention = await page.evaluate(() => {
    const el = document.querySelector('.room-notice .countdown');
    const cs = getComputedStyle(el);
    const before = el.scrollLeft;
    el.scrollLeft = 9999;
    const after = el.scrollLeft;
    el.scrollLeft = 0;
    return {
      nextCleanupAt: state.nextCleanupAt,
      text: el.textContent || '',
      overflowX: cs.overflowX,
      scrollWidth: el.scrollWidth,
      clientWidth: el.clientWidth,
      before, after,
    };
  });
  check(!!retention.nextCleanupAt, 'boş odada yazılan ilk mesaj geri sayımı BAŞLATTI');
  check(/\d+ saat \d+ dakika/.test(retention.text), 'geri sayım canlı değer gösteriyor: "' + retention.text.trim() + '"');
  check(retention.overflowX === 'auto' || retention.overflowX === 'scroll',
    'geri sayım hapı kaydırılabilir (overflow-x=' + retention.overflowX + ')');
  const fits = retention.scrollWidth <= retention.clientWidth;
  check(fits || retention.after > retention.before,
    fits ? 'metin kutuya sığıyor, kırpılma yok (' + retention.scrollWidth + '<=' + retention.clientWidth + ')'
         : 'çekince kayıyor: scrollLeft ' + retention.before + ' -> ' + retention.after);

  await browser.close();
  require('fs').writeFileSync(TDIR + '/browser_counters.txt', 'PASS ' + pass + '\nFAIL ' + fail + '\n');
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
  console.error('HATA:', e && e.message ? e.message : e);
  require('fs').writeFileSync(TDIR + '/browser_counters.txt', 'PASS ' + pass + '\nFAIL ' + (fail + 1) + '\n');
  process.exit(1);
});
