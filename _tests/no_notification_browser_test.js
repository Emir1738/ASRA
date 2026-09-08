// ============================================================================
//  Bildirim sistemi kaldırıldı + sıfır-hit boşta düzeni — tarayıcı doğrulaması
//  (puppeteer + gerçek Chromium + gerçek PHP/MySQL sunucusu)
//
//  Ölçülenler (gerçek sayfada, gerçek sunucuya karşı):
//    • Notification.requestPermission / new Notification HİÇ çağrılmaz
//    • navigator.serviceWorker.register HİÇ çağrılmaz
//    • AsraNative.notify / notifyRooms / requestNotifications HİÇ çağrılmaz
//    • register_push* / poll_push isteği HİÇ gitmez
//    • Oda nabzı zamanlayıcısı kurulur ve 60 sn boşta ritmiyle kendini yineler
//    • Periyodik mesaj yoklaması YOK: state.pollTimer boşta null kalır
//    • BOŞTA ODA get_messages İSTEMEZ (yalnızca get_rooms gider)
//    • Başka kullanıcı mesaj yazınca rozet değişir → TEK get_messages gider
//      ve mesaj ekranda görünür (değişim dedektörü gerçekten çalışıyor)
//
//  Zamanlayıcılar testte 12× hızlandırılır (60 sn → 5 sn) ama istenen gecikme
//  değerleri GERÇEK hâliyle kaydedilir; yani "60 sn" iddiası ölçülür, uydurulmaz.
//
//  Çıkış: 0 = hepsi geçti. Ayrıca <tdir>/browser_counters.txt içine
//  "PASS n" / "FAIL n" satırları yazar (bash suite toplamına eklenir).
// ============================================================================
const puppeteer = require('puppeteer-core');
const { execSync } = require('child_process');
const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8090/auth.php';

const TDIR = process.argv[2] || '/tmp';
let pass = 0;
let fail = 0;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); }
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); }
const check = (cond, m) => (cond ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

// Dışarıdan mesaj yazacak ikinci kullanıcı (sayfa dışında, curl ile).
const SENDER = 'pulse_sender_' + Date.now().toString().slice(-6);
function sh(cmd) {
  return execSync(cmd, { encoding: 'utf8' }).trim();
}
function makeSender() {
  const jar = `${TDIR}/jar_${SENDER}.txt`;
  const ua = `E2E-Pulse/${SENDER}`;
  sh(`rm -f '${jar}'`);
  sh(`curl -s -A '${ua}' -c '${jar}' '${BASE}?action=session' -o '${TDIR}/ps.json'`);
  const csrf = JSON.parse(sh(`cat '${TDIR}/ps.json'`)).csrf_token || '';
  sh(`curl -s -A '${ua}' -b '${jar}' -c '${jar}' -H 'X-CSRF-Token: ${csrf}' -H 'Content-Type: application/json' -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' '${BASE}?action=check_invite_code' -o /dev/null`);
  sh(`curl -s -A '${ua}' -b '${jar}' -c '${jar}' -H 'X-CSRF-Token: ${csrf}' -H 'Content-Type: application/json' -d '{"username":"${SENDER}","password":"Test123!xyz","password_confirm":"Test123!xyz"}' '${BASE}?action=register' -o /dev/null`);
  sh(`sudo mysql -N asra_test -e "UPDATE users SET approval_status='approved' WHERE username='${SENDER}'" >/dev/null 2>&1`);
  sh(`curl -s -A '${ua}' -b '${jar}' -c '${jar}' -H 'X-CSRF-Token: ${csrf}' -H 'Content-Type: application/json' -d '{"username":"${SENDER}","password":"Test123!xyz"}' '${BASE}?action=login' -o /dev/null`);
  const csrf2 = JSON.parse(sh(`curl -s -A '${ua}' -b '${jar}' -c '${jar}' '${BASE}?action=session'`)).csrf_token || '';
  return { jar, ua, csrf: csrf2 };
}
function senderPost(sender, action, body) {
  return sh(`curl -s -A '${sender.ua}' -b '${sender.jar}' -c '${sender.jar}' -H 'X-CSRF-Token: ${sender.csrf}' -H 'Content-Type: application/json' -d '${body}' '${BASE}?action=${action}'`);
}

const HOOK = () => {
  window.__asraProbe = {
    notifPermission: 0, notifCtor: 0, swRegister: 0,
    nativeNotify: 0, nativeNotifyRooms: 0, nativeRequestNotifications: 0,
    timeouts: [],      // istenen gerçek gecikmeler (ms)
  };

  // Testte YALNIZCA boşta nabız aralığı hızlandırılır: 60000 ms -> 12000 ms.
  // Sayfada 60000 ms isteyen TEK zamanlayıcı startRoomPulseWatcher'ın boşta
  // turudur (doğrulandı: ROOM_PULSE_IDLE_MS); başka hiçbir zamanlayıcı bu
  // değeri kullanmaz, dolayısıyla ölçekleme yalnızca onu etkiler.
  //
  // 12000 ms seçilme nedeni: dakikada 5 tur => 5 arka plan isteği. İstemcinin
  // arka plan bütçesi 6 istek/60 sn olduğu için hızlandırma bütçeyi AŞMAZ ve
  // 60 sn'lik soğumayı tetiklemez (12× hızda aşıyor, test yanlış negatif veriyor).
  //
  // NOT: Sabitler pencere özelliği olarak EZİLEMEZ — sayfadaki `const
  // ROOM_PULSE_IDLE_MS` bildirimi pencere özelliğini gölgeler (ölçüldü).
  // İstenen gecikme GERÇEK hâliyle kaydedilir; üretim ritminin kanıtı budur.
  const realSetTimeout = window.setTimeout.bind(window);
  window.setTimeout = function (fn, delay, ...rest) {
    const d = Number(delay);
    if (Number.isFinite(d) && d >= 1000) window.__asraProbe.timeouts.push(d);
    // Boşta ritmin üç kademesi de hızlandırılır; kaydedilen değer GERÇEK
    // istenen gecikmedir, yani üretim ritminin kanıtı bozulmaz.
    const SCALED = { 60000: 12000, 120000: 12000, 180000: 12000 };
    return realSetTimeout(fn, SCALED[d] || delay, ...rest);
  };

  const RealNotification = window.Notification;
  function FakeNotification(title, options) {
    window.__asraProbe.notifCtor += 1;
    try { return new RealNotification(title, options); } catch (_) { return {}; }
  }
  FakeNotification.permission = (RealNotification && RealNotification.permission) || 'default';
  FakeNotification.requestPermission = function () {
    window.__asraProbe.notifPermission += 1;
    return Promise.resolve('denied');
  };
  try { Object.defineProperty(window, 'Notification', { value: FakeNotification, configurable: true }); } catch (_) {}

  try {
    if (navigator.serviceWorker) {
      const realRegister = navigator.serviceWorker.register.bind(navigator.serviceWorker);
      navigator.serviceWorker.register = function (url, opts) {
        window.__asraProbe.swRegister += 1;
        return realRegister(url, opts);
      };
    }
  } catch (_) {}

  window.AsraNative = {
    isNativeApp: () => true,
    getDeviceId: () => 'asra.nonotif000001',
    openRoom: () => {},
    notify: () => { window.__asraProbe.nativeNotify += 1; },
    notifyRooms: () => { window.__asraProbe.nativeNotifyRooms += 1; },
    requestNotifications: () => { window.__asraProbe.nativeRequestNotifications += 1; },
    setWatching: () => {},
  };

  // NOT: API istekleri sayfa düzeyinde DEĞİL, tarayıcı düzeyinde sayılır
  // (page.on('request')). Sayfa içi fetch sarmalayıcısı bazı durumlarda
  // devre dışı kalabildiği için ölçüm oraya bırakılmaz.
};

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    args: ['--no-sandbox', '--disable-dev-shm-usage'],
  });
  const page = await browser.newPage();
  // API istekleri tarayıcı düzeyinde kaydedilir: fetch/XHR fark etmez ve
  // sayfa betiğinin kancayı bozması mümkün değildir.
  const apiRequests = [];
  const pushRequests = [];
  page.on('request', (req) => {
    const url = String(req.url());
    const m = url.match(/action=([a-z_]+)/);
    if (m) apiRequests.push({ action: m[1], at: Date.now() });
    if (/register_push|poll_push/.test(url)) pushRequests.push(url);
  });
  await page.setViewport({ width: 393, height: 850, isMobile: true });
  await page.setUserAgent('Mozilla/5.0 (Linux; Android 14) AsraApp/1.7.1');
  await page.evaluateOnNewDocument(HOOK);
  await page.goto('http://127.0.0.1:8090/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(1500);

  // Giriş (referans kullanıcı)
  await page.evaluate(() => {
    const i = document.querySelector('#invite-form input[name="code"]');
    if (i) {
      i.value = 'DAVETDAVETJSJEJSNFJSNDJSDNFH';
      i.dispatchEvent(new Event('input', { bubbles: true }));
      document.querySelector('#invite-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }
  });
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
  await sleep(2500);

  const probeHome = await page.evaluate(() => ({
    probe: window.__asraProbe,
    roomTimerArmed: typeof state !== 'undefined' && !!state.roomNotificationTimer,
    route: typeof state !== 'undefined' ? state.route : null,
    cards: document.querySelectorAll('.room-card').length,
    pulseTable: typeof state !== 'undefined' && state.roomPulse ? Object.keys(state.roomPulse).length : 0,
  }));
  check(probeHome.route === 'home', 'oturum açıldı, rota=home (gelen: ' + probeHome.route + ')');
  check(probeHome.cards > 0, 'ana ekranda oda kartları çizildi (' + probeHome.cards + ')');
  check(probeHome.roomTimerArmed, 'oda nabzı zamanlayıcısı kuruldu');
  check(probeHome.pulseTable > 0, 'oda nabzı tablosu doldu (' + probeHome.pulseTable + ' oda)');

  // Odaya gir (ilk kart). Oda daha önce hiç açılmadı -> tek istekle önbellek dolar.
  const roomId = await page.evaluate(() => {
    const card = document.querySelector('.room-card');
    if (!card) return 0;
    const id = Number(card.dataset.roomId || 0);
    card.click();
    return id;
  });
  await sleep(2500);
  const inRoom = await page.evaluate(() => ({
    route: state.route,
    pollTimer: state.pollTimer,
    pulseTimer: !!state.roomNotificationTimer,
    roomId: state.currentRoom ? Number(state.currentRoom.id) : 0,
    cached: (localStorage.getItem('asra_msg_cache_r' + (state.currentRoom ? state.currentRoom.id : 0)) || '').length,
    pulseKeys: state.roomPulse ? Object.keys(state.roomPulse).length : 0,
  }));
  check(inRoom.route === 'room', 'odaya girildi (rota=' + inRoom.route + ')');
  check(inRoom.roomId === roomId && roomId > 0, 'doğru oda açıldı (id=' + inRoom.roomId + ')');
  check(inRoom.pollTimer === null, 'periyodik mesaj yoklaması ZAMANLANMADI (pollTimer=null)');
  check(inRoom.pulseTimer, 'oda nabzı zamanlayıcısı oda içinde de çalışıyor');
  check(inRoom.cached >= 2, 'mesaj önbelleği yazıldı (' + inRoom.cached + ' bayt)');
  check(inRoom.pulseKeys > 0, 'oda nabzı tablosu oda girişinde korunuyor (' + inRoom.pulseKeys + ' oda)');

  // Boşta ritim gerçekten 60 sn mi? (zamanlayıcıya istenen gecikme kaydedilir)
  const idleCadence = await page.evaluate(() => {
    const t = window.__asraProbe.timeouts;
    return { has60000: t.indexOf(60000) >= 0, max: t.length ? Math.max.apply(null, t) : 0 };
  });
  check(idleCadence.has60000, 'boşta nabız ritmi 60000 ms olarak kuruldu (testte 12000 ms\'e ölçeklendi; ölçülen en büyük: ' + idleCadence.max + ' ms)');

  // ---- ÖNCE KARARLI DURUMA GEL (deterministik) ----
  // Odaya girişteki TEK SEFERLİK yakalama isteği burada tüketilir. Zaman
  // tabanlı bekleme YARIŞ üretir (uçuş hâlindeki istek ölçüm penceresine
  // taşar), bu yüzden yerleşme doğrudan nabız tazelemesiyle sürülür:
  // oda tamamen okunana (rozet 0) ve artık get_messages gitmeyene kadar.
  let settled = false;
  for (let i = 0; i < 10 && !settled; i += 1) {
    apiRequests.length = 0;
    await page.evaluate(async () => { await refreshRoomPulse(false); await refreshRoomPulse(false); });
    await sleep(2000); // uçuş hâlindeki isteklerin karaya çıkması için
    const after = await page.evaluate(() => Number(state.roomUnreadPulse));
    if (after === 0 && apiRequests.filter((r) => r.action === 'get_messages').length === 0) settled = true;
  }
  check(settled, 'oda kararlı duruma geldi (rozet 0, artık get_messages gitmiyor)');

  // ---- BOŞTA: get_messages HİÇ gitmemeli, yalnızca get_rooms ----
  apiRequests.length = 0;
  await sleep(15000); // test ölçeğinde ~2 nabız turu (gerçekte ~2,5 dk)
  const idle = await page.evaluate(() => ({ pollTimer: state.pollTimer }));
  const idleActions = apiRequests.map((r) => r.action);
  const idleGetMessages = idleActions.filter((a) => a === 'get_messages').length;
  const idleGetRooms = idleActions.filter((a) => a === 'get_rooms').length;
  check(idleGetMessages === 0, 'BOŞTA oda get_messages İSTEMEDİ (giden: ' + idleGetMessages + ')');
  check(idleGetRooms > 0, 'boşta yalnızca get_rooms gitti (' + idleGetRooms + ' istek)');
  check(idle.pollTimer === null, 'boşta geçen sürede periyodik yoklama kurulmadı');

  // ---- DEĞİŞİM DEDEKTÖRÜ: başka kullanıcı yazınca TEK get_messages ----
  const sender = makeSender();
  const marker = 'PULSE-' + Date.now().toString().slice(-6);
  const sentRaw = senderPost(sender, 'send_message', JSON.stringify({ room_id: inRoom.roomId, message: marker }));
  let sentOk = false;
  try { sentOk = JSON.parse(sentRaw).ok === true || Number(JSON.parse(sentRaw).message_id) > 0; } catch (_) { sentOk = false; }
  check(sentOk, 'ikinci kullanıcı odaya mesaj yazdı (' + marker + ')');

  apiRequests.length = 0;
  // Nabız turu mesajı fark edene kadar bekle (5× ölçekte 60 sn -> 12 sn; pay bırak).
  let detected = null;
  for (let i = 0; i < 40; i += 1) {
    await sleep(1000);
    const gm = apiRequests.filter((r) => r.action === 'get_messages').length;
    if (gm > 0) {
      // YARIŞ DÜZELTMESİ: puppeteer'ın 'request' olayı isteğin GÖNDERİLDİĞİ
      // anda tetiklenir; yanıtın işlenip satırın çizilmesi ise biraz sonra
      // olur. Eski sürüm tam o anda TEK örneklem aldığı için suite, yanıt
      // henüz çizilmediyse "mesaj ekranda çizilmedi" diye YANLIŞ yere
      // kalıyordu (orijinal kodda bile 3 koşuda 2 kez). İstek sayısı ve giden
      // istek listesi TESPİT ANINDA dondurulur (dedektörün tek istek attığı
      // iddiası gevşemez), çizim ise kısa bir süre boyunca beklenir.
      detected = { getMessages: gm, reqs: apiRequests.slice(), shown: false };
      for (let k = 0; k < 30; k += 1) {
        if (await page.evaluate((mk) => document.body.innerText.indexOf(mk) >= 0, marker)) {
          detected.shown = true;
          break;
        }
        await sleep(200);
      }
      break;
    }
  }
  check(!!detected, 'rozet değişince dedektör get_messages tetikledi');
  if (detected) {
    check(detected.getMessages === 1, 'dedektör TEK get_messages attı (giden: ' + detected.getMessages + ')');
    check(detected.shown, 'gelen mesaj ekranda çizildi (' + marker + ')');
    const others = detected.reqs.map((r) => r.action).filter((a) => a !== 'get_messages' && a !== 'get_rooms');
    check(others.length === 0, 'dedektör turu başka istek üretmedi (' + (others.join(',') || 'yok') + ')');
  } else {
    bad('dedektör get_messages üretmedi');
    bad('mesaj ekranda görünmedi');
    bad('ek istek kontrolü yapılamadı');
  }

  // ---- AKTİF SOHBET RİTMİ: mesaj gönderilince nabız 10 sn'ye inmeli ----
  await page.evaluate(() => {
    window.__asraProbe.timeouts.length = 0;
    notifyRoomActivity();
    // İzleyiciyi yeniden kur: bir sonraki tur hızlı ritimle planlanır.
    // (Yeniden kurmadan eski 12 sn'lik turun dolması beklenirdi.)
    startRoomPulseWatcher();
  });
  await sleep(1500);
  const active = await page.evaluate(() => ({
    has10000: window.__asraProbe.timeouts.indexOf(10000) >= 0,
    until: Number(state.roomPulseUntil) > Date.now(),
    timeouts: window.__asraProbe.timeouts.slice(),
  }));
  check(active.until, 'notifyRoomActivity hızlı ritim penceresini açtı');
  check(active.has10000, 'hızlı ritim 10000 ms olarak kuruldu (gözlenen: ' + active.timeouts.join(',') + ')');

  // ---- BOŞTA SEYRELTME: ritim 60 -> 120 -> 180 sn'ye kademeli iner ----
  // Hiçbir şey değişmeyen açık bir sekme saatlerce 1 istek/dk yemesin diye.
  async function armedDelayFor(quietTicks) {
    await page.evaluate((q) => {
      window.__asraProbe.timeouts.length = 0;
      state.roomPulseUntil = 0;        // hızlı pencere kapalı => boşta ritim
      state.roomPulseQuietTicks = q;
      startRoomPulseWatcher();
    }, quietTicks);
    await sleep(250);
    return page.evaluate(() => window.__asraProbe.timeouts.slice());
  }
  const d0 = await armedDelayFor(0);
  check(d0.indexOf(60000) >= 0, 'boşta ilk ritim 60000 ms (gözlenen: ' + d0.join(',') + ')');
  const d6 = await armedDelayFor(6);
  check(d6.indexOf(120000) >= 0, 'sakin turlardan sonra 120000 ms (gözlenen: ' + d6.join(',') + ')');
  const d12 = await armedDelayFor(12);
  check(d12.indexOf(180000) >= 0, 'boşta tavan 180000 ms (gözlenen: ' + d12.join(',') + ')');
  const quietReset = await page.evaluate(() => {
    state.roomPulseQuietTicks = 12;
    notifyRoomActivity();
    return Number(state.roomPulseQuietTicks);
  });
  check(quietReset === 0, 'etkinlik seyreltme sayacını sıfırladı (' + quietReset + ')');

  // Ana ekrana dön
  await page.evaluate(() => { state.currentRoom = null; renderHome(); });
  await sleep(1500);

  const final = await page.evaluate(() => ({
    probe: window.__asraProbe,
    homeTimer: !!state.roomNotificationTimer,
  }));
  check(final.probe.notifPermission === 0, 'Notification.requestPermission çağrılmadı');
  check(final.probe.notifCtor === 0, 'new Notification çağrılmadı');
  check(final.probe.swRegister === 0, 'servis işçisi kaydedilmedi');
  check(final.probe.nativeNotify === 0, 'AsraNative.notify çağrılmadı');
  check(final.probe.nativeNotifyRooms === 0, 'AsraNative.notifyRooms çağrılmadı');
  check(final.probe.nativeRequestNotifications === 0, 'AsraNative.requestNotifications çağrılmadı');
  check(pushRequests.length === 0, 'register_push/poll_push isteği gitmedi');
  check(final.homeTimer, 'ana ekrana dönünce nabız zamanlayıcısı yeniden kuruldu');

  await browser.close();
  require('fs').writeFileSync(TDIR + '/browser_counters.txt', 'PASS ' + pass + '\nFAIL ' + fail + '\n');
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => {
  console.error('HATA:', e && e.message ? e.message : e);
  require('fs').writeFileSync(TDIR + '/browser_counters.txt', 'PASS ' + pass + '\nFAIL ' + (fail + 1) + '\n');
  process.exit(1);
});
