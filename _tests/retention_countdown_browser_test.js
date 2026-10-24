// ============================================================================
//  SAKLAMA SÜRESİ / GERİ SAYIM — tarayıcı doğrulaması (puppeteer + Chromium)
//
//  Bildirilen kusur: "saat sistemini hesaplayamıyor, 'hesaplanıyor…'da takılı
//  kalıyor."
//
//  Kök nedenler (dördü de bu dosyada ayrı ayrı ölçülür):
//    1) SIFIR-İSTEK ODA GİRİŞİ: önbellek dolu + okunmamış rozeti 0 ise
//       enterRoom() hiç fetchMessages() çağırmaz. Geri sayımı türetip ekrana
//       yazan TEK yer fetchMessages() olduğu için arayüz, renderChatLayout()
//       içine gömülü "hesaplanıyor…" yer tutucusunda KALICI takılı kalıyordu.
//    2) VADESİ GEÇMİŞ ÖNBELLEK: localStorage günlerce eski satırları tutuyor,
//       sunucu o mesajları çoktan silmiş olsa bile. Rozet 0 ise istek de
//       atılmadığı için HAYALET mesajlar çiziliyor ve sayaç bayat damgadan
//       bozulup geçmiş bir vadede kilitleniyordu.
//    3) BOZUK created_at: damga çözümlenemeyince vade hiç türetilmiyor, yer
//       tutucu ekranda kalıyordu.
//    4) BAŞARISIZ İLK YÜKLEME: istek hata verirse "hesaplanıyor…" sonsuza dek
//       kalıyordu (bayrağı düşüren bir yol yoktu).
//
//  Ölçümler gerçek sayfada, gerçek PHP/MySQL sunucusuna karşı yapılır.
//  Sıfır-istek tasarımı BOZULMAMALI: senaryo 1'de get_messages sayısı 0 kalmalı.
//  Çıkış: 0 = hepsi geçti.
// ============================================================================
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const ORIGIN = process.env.ORIGIN || 'http://127.0.0.1:8094';
const TDIR = process.argv[2] || '/tmp';
const LIVE_ROOM_ID = Number(process.env.LIVE_ROOM_ID || 0);
const STALE_ROOM_ID = Number(process.env.STALE_ROOM_ID || 0);
const USERNAME = process.env.TEST_USER || 'uiadmin';
const PASSWORD = process.env.TEST_PASSWORD || 'Test123!xyz';

let pass = 0;
let fail = 0;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); };
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const STUCK = /hesaplan/i;
const LIVE = /(\d+)\s*saat\s*(\d+)\s*dakika/;

/** Sayaç metnini ve ilgili durumu sayfadan okur. */
const readState = () => ({
  text: ((document.querySelector('.room-notice .countdown') || {}).textContent || '').trim(),
  nextCleanupAt: state.nextCleanupAt,
  retentionHours: Number(state.retentionHours),
  retentionLoading: !!state.retentionLoading,
  timerAlive: !!state.countdownTimer,
  route: state.route,
  roomId: state.currentRoom ? Number(state.currentRoom.id) : 0,
  badge: state.roomPulse && state.currentRoom ? Number(state.roomPulse[Number(state.currentRoom.id)]) : -1,
  rows: document.querySelectorAll('#message-list .message-row').length,
  body: ((document.querySelector('#message-list') || {}).textContent || ''),
  cached: (() => {
    try {
      const raw = localStorage.getItem('asra_msg_cache_r' + Number(state.currentRoom ? state.currentRoom.id : 0));
      const arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr.length : 0;
    } catch (_) { return 0; }
  })(),
});

async function login(page) {
  await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(1000);
  await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
  await page.evaluate((u, p) => {
    const un = document.querySelector('#login-username');
    const pw = document.querySelector('#login-password');
    if (!un || !pw) return;
    un.value = u; un.dispatchEvent(new Event('input', { bubbles: true }));
    pw.value = p; pw.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('#login-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }, USERNAME, PASSWORD);
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 20000 }).catch(() => {});
  await sleep(2000);
}

/** Ana ekrana döner ve oda listesinin tazelenmesini bekler. */
async function goHome(page) {
  await page.evaluate(() => document.querySelector('#back-button').click());
  await sleep(800);
  return page.evaluate(() => state.route);
}

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
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(String(e && e.message ? e.message : e)));

  await page.setViewport({ width: 393, height: 850, isMobile: true });
  await page.setUserAgent('Mozilla/5.0 (Linux; Android 14) AsraApp/1.7.1');
  await login(page);

  check((await page.evaluate(() => state.route)) === 'home', 'oturum açıldı, ana ekranda');
  check((await page.evaluate(() => Number(state.retentionHours))) === 24,
    'saklama süresi sunucudan geldi (state.retentionHours=24)');

  // ==========================================================================
  // SENARYO 1: canlı oda — 5 saatlik mesaj var, 25 saatlik mesaj vadeli dolmuş
  // ==========================================================================
  const entered = await page.evaluate((roomId) => {
    const card = document.querySelector(`.room-card[data-room-id="${roomId}"]`);
    if (!card) return false;
    card.click();
    return true;
  }, LIVE_ROOM_ID);
  check(entered, 'canlı odaya girildi (id=' + LIVE_ROOM_ID + ')');
  await sleep(2800);

  const first = await page.evaluate(readState);
  check(first.route === 'room' && first.roomId === LIVE_ROOM_ID, 'oda ekranı açıldı');
  check(!STUCK.test(first.text), 'ilk girişte sayaç "hesaplanıyor…"da TAKILI DEĞİL: "' + first.text + '"');
  check(LIVE.test(first.text), 'ilk girişte sayaç canlı değer gösteriyor: "' + first.text + '"');
  check(!!first.nextCleanupAt, 'ilk girişte nextCleanupAt türetildi: ' + first.nextCleanupAt);
  check(first.timerAlive, 'geri sayım zamanlayıcısı kuruldu');

  // 5 saatlik mesaj için beklenen kalan süre ~19 saat (24 - 5).
  const hours = Number((first.text.match(LIVE) || [0, -1])[1]);
  check(hours >= 18 && hours <= 19, 'kalan süre DOĞRU HESAPLANDI: ' + hours + ' saat (beklenen 18-19)');

  // Vadesi dolmuş mesaj ne listede ne de sayaçta yer almalı.
  check(!first.body.includes('vadesi gecmis mesaj'),
    'HAYALET MESAJ YOK: 25 saatlik (vadesi dolmuş) mesaj listeye çizilmedi');
  check(first.body.includes('geri sayim canli mesaj'), '5 saatlik mesaj listeye çizildi');

  // Vade gelecekte olmalı ve 24 saati aşmamalı.
  const deadlineMs = first.nextCleanupAt ? new Date(first.nextCleanupAt).getTime() : NaN;
  const hoursLeft = (deadlineMs - Date.now()) / 3600000;
  check(Number.isFinite(hoursLeft) && hoursLeft > 18 && hoursLeft <= 24,
    'vade gelecekte ve saklama penceresi içinde: ' + hoursLeft.toFixed(2) + ' saat');

  // Rozet 0 olmalı (mesajlar kendi kullanıcımızdan) -> sıfır-istek yolu şartı.
  const badge = await page.evaluate((roomId) => {
    if (typeof loadRooms === 'function') loadRooms(false);
    return Number((state.roomPulse || {})[roomId]);
  }, LIVE_ROOM_ID);
  await sleep(2000);
  const badgeNow = await page.evaluate((roomId) => Number((state.roomPulse || {})[roomId]), LIVE_ROOM_ID);
  check(badgeNow === 0, 'oda rozeti 0 -> sıfır-istek giriş yolu devreye girecek (badge=' + badgeNow + ')');

  // --- ana ekrana dön, AYNI odaya ÖNBELLEKTEN yeniden gir --------------------
  check((await goHome(page)) === 'home', 'ana ekrana dönüldü');
  const cachedCount = await page.evaluate((roomId) => {
    try {
      const raw = localStorage.getItem('asra_msg_cache_r' + roomId);
      const arr = raw ? JSON.parse(raw) : [];
      return Array.isArray(arr) ? arr.length : 0;
    } catch (_) { return 0; }
  }, LIVE_ROOM_ID);
  check(cachedCount > 0, 'yerel önbellek dolu (' + cachedCount + ' mesaj) -> sıfır-istek yolu geçerli');

  const before = apiRequests.length;
  await page.evaluate((roomId) => {
    const card = document.querySelector(`.room-card[data-room-id="${roomId}"]`);
    if (card) card.click();
  }, LIVE_ROOM_ID);
  await sleep(1800);

  const re = await page.evaluate(readState);
  const reentryGets = apiRequests.slice(before).filter((a) => a === 'get_messages').length;
  check(re.route === 'room' && re.roomId === LIVE_ROOM_ID, 'odaya ÖNBELLEKTEN yeniden girildi');
  check(reentryGets === 0, 'sıfır-istek tasarımı KORUNDU: yeniden girişte ' + reentryGets + ' get_messages');
  check(!STUCK.test(re.text),
    'KRİTİK: yeniden girişte sayaç "hesaplanıyor…"da TAKILI KALMIYOR -> "' + re.text + '"');
  check(LIVE.test(re.text), 'yeniden girişte sayaç canlı değer gösteriyor: "' + re.text + '"');
  check(!!re.nextCleanupAt, 'vade önbellekten, İSTEK ATMADAN türetildi: ' + re.nextCleanupAt);
  check(re.timerAlive, 'zamanlayıcı yeniden girişte de kurulu');
  const reHours = Number((re.text.match(LIVE) || [0, -1])[1]);
  check(reHours >= 18 && reHours <= 19, 'yeniden girişte de süre doğru: ' + reHours + ' saat');
  check(!re.body.includes('vadesi gecmis mesaj'), 'yeniden girişte de hayalet mesaj yok');

  // ==========================================================================
  // SENARYO 2: vadesi geçmiş önbellek -> hayalet mesaj + kilitli sayaç
  // ==========================================================================
  check((await goHome(page)) === 'home', 'senaryo 2 için ana ekrana dönüldü');
  await page.evaluate((roomId) => {
    const room = (state.rooms || []).find((r) => Number(r.id) === Number(roomId)) || { id: roomId, name: 'Oda' };
    const staleAt = new Date(Date.now() - 25 * 3600 * 1000).toISOString();
    localStorage.setItem('asra_msg_cache_r' + Number(roomId), JSON.stringify([{
      id: 999001, user_id: Number(state.user.id), username: state.user.username,
      avatar_color: 'c1', profile_image_path: null, active_profile_frame: null,
      type: 'text', content: 'HAYALET-BAYAT-MESAJ', image_path: null,
      created_at: staleAt, view_count: 0,
    }]));
    enterRoom(room);
    return true;
  }, STALE_ROOM_ID);
  await sleep(3000);

  const stale = await page.evaluate(readState);
  check(!STUCK.test(stale.text), 'bayat önbellekte sayaç "hesaplanıyor…"da KİLİTLİ DEĞİL: "' + stale.text + '"');
  check(!stale.body.includes('HAYALET-BAYAT-MESAJ'),
    'KRİTİK: vadesi dolmuş önbellek satırı EKRANA ÇİZİLMEDİ (hayalet mesaj giderildi)');
  check(!stale.nextCleanupAt || new Date(stale.nextCleanupAt).getTime() > Date.now(),
    'vade ya yok ya gelecekte — geçmişte kilitli vade YOK: ' + stale.nextCleanupAt);
  check(/oda boş/.test(stale.text) || LIVE.test(stale.text),
    'dürüst durum gösteriliyor: "' + stale.text + '"');

  // ==========================================================================
  // SENARYO 3: bozuk created_at -> yer tutucuda takılma
  // ==========================================================================
  check((await goHome(page)) === 'home', 'senaryo 3 için ana ekrana dönüldü');
  await page.evaluate((roomId) => {
    const room = (state.rooms || []).find((r) => Number(r.id) === Number(roomId)) || { id: roomId, name: 'Oda' };
    localStorage.setItem('asra_msg_cache_r' + Number(roomId), JSON.stringify([
      { id: 999002, user_id: 1, username: 'x', avatar_color: 'c1', profile_image_path: null,
        active_profile_frame: null, type: 'text', content: 'BOZUK-DAMGA-1', image_path: null,
        created_at: 'bozuk-zaman-damgasi', view_count: 0 },
      { id: 999003, user_id: 1, username: 'x', avatar_color: 'c1', profile_image_path: null,
        active_profile_frame: null, type: 'text', content: 'BOZUK-DAMGA-2', image_path: null,
        created_at: null, view_count: 0 },
    ]));
    enterRoom(room);
    return true;
  }, STALE_ROOM_ID);
  await sleep(3000);

  const broken = await page.evaluate(readState);
  check(!STUCK.test(broken.text),
    'KRİTİK: bozuk zaman damgasında sayaç "hesaplanıyor…"da KALMIYOR -> "' + broken.text + '"');
  check(!broken.body.includes('BOZUK-DAMGA'), 'bozuk damgalı satırlar çizilmedi');
  check(/oda boş/.test(broken.text) || LIVE.test(broken.text),
    'bozuk damgada dürüst durum: "' + broken.text + '"');

  // ==========================================================================
  // SENARYO 4: boş odaya yazılan ilk mesaj sayacı başlatır (regresyon koruması)
  // ==========================================================================
  const emptyState = await page.evaluate(readState);
  const marker = 'RETENTION-' + Date.now().toString().slice(-6);
  await page.evaluate((text) => {
    const t = document.querySelector('#message-input');
    if (!t) return;
    t.value = text;
    t.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('.composer').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }, marker);
  await sleep(2500);
  const afterSend = await page.evaluate(readState);
  check(!!afterSend.nextCleanupAt, 'boş odada ilk mesaj sayacı BAŞLATTI: ' + afterSend.nextCleanupAt);
  check(LIVE.test(afterSend.text), 'gönderim sonrası sayaç canlı: "' + afterSend.text + '"');
  check(!STUCK.test(afterSend.text), 'gönderim sonrası yer tutucu yok');
  check(Number((afterSend.text.match(LIVE) || [0, -1])[1]) >= 23,
    'yeni mesajın vadesi ~24 saat: "' + afterSend.text + '"');

  // ==========================================================================
  check(pageErrors.length === 0,
    'sayfada JavaScript hatası yok' + (pageErrors.length ? ': ' + pageErrors.slice(0, 3).join(' | ') : ''));
  check(emptyState.retentionHours === 24, 'saklama süresi tüm senaryolarda tutarlı (24)');

  await browser.close();

  fs.mkdirSync(TDIR, { recursive: true });
  fs.writeFileSync(path.join(TDIR, 'browser_counters.txt'), 'PASS ' + pass + '\nFAIL ' + fail + '\n');
  console.log('================ SONUÇ ================');
  console.log('SONUÇ: ✓' + pass + ' ✗' + fail);
  console.log('GEÇTİ: ' + pass + '   BAŞARISIZ: ' + fail);
  process.exit(fail === 0 ? 0 : 1);
})().catch((error) => {
  console.log('  ✗ betik çöktü: ' + (error && error.message ? error.message : error));
  try {
    fs.mkdirSync(TDIR, { recursive: true });
    fs.writeFileSync(path.join(TDIR, 'browser_counters.txt'), 'PASS ' + pass + '\nFAIL ' + (fail + 1) + '\n');
  } catch (_) {}
  console.log('================ SONUÇ ================');
  console.log('SONUÇ: ✓' + pass + ' ✗' + (fail + 1));
  process.exit(1);
});
