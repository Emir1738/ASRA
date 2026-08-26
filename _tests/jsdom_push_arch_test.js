// Push mimarisi E2E (gerçek Chromium + jsdom-uzantısız, puppeteer-core)
// Kullanım: node jsdom_push_arch_test.js [native|browser]
// Gereksinim: node_modules'ta puppeteer-core (NODE_PATH ile)
const puppeteer = require('puppeteer-core');

const NATIVE = process.argv[2] !== 'browser';
const URL = 'http://127.0.0.1:8090/';
const NTFY_BASE = 'http://127.0.0.1:8090/ntfy';

(async () => {
  const browser = await puppeteer.launch({ executablePath: '/tmp/chrome/chrome/linux-152.0.7977.64/chrome-linux64/chrome', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 393, height: 850, isMobile: true });

  if (NATIVE) {
    await page.evaluateOnNewDocument(() => {
      window.AsraNative = {
        isNativeApp: () => true,
        getDeviceId: () => 'asra.testdevice000000',
        setWatching: () => {},
        setNtfyChannel: (v) => { (window.__ntfyChannelCalls = window.__ntfyChannelCalls || []).push(v); },
        notifyRooms: () => {},
        notify: () => {},
        requestNotifications: () => {},
        openRoom: () => {},
      };
    });
  }
  // evaluateOnNewDocument govunde YALNIZCA literal/kargi deger: moduel
  // degiskenleri sayfada ReferenceError verir (enjekte edilen script kesilir)
  const ntfyIntervalMs = NATIVE ? 2000 : 6000;
  await page.evaluateOnNewDocument((intervalMs) => {
    window.__NTFY_BASE__ = 'http://127.0.0.1:8090/ntfy';
    window.__NTFY_INTERVAL_MS__ = intervalMs;
    window.__fetchCalls = [];
    const orig = window.fetch;
    window.fetch = function (input, init) {
      try { window.__fetchCalls.push(String(input)); } catch (e) {}
      return orig.apply(this, arguments);
    };
  }, ntfyIntervalMs);

  await page.goto(URL, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await new Promise((r) => setTimeout(r, 2500));

  let pass = 0, fail = 0;
  const ok = (d) => { pass++; console.log('  ✓ ' + d); };
  const bad = (d) => { fail++; console.log('  ✗ ' + d); };
  const wait = (ms) => new Promise((r) => setTimeout(r, ms));

  console.log(NATIVE ? '== NATIVE MOD (uygulama/WebView) ==' : '== BROWSER MOD (saf tarayıcı) ==');

  // Giriş: davet + login (robust)
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
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 20000 }).catch(() => {});
  await wait(3000);

  const inApp = await page.evaluate(() => !document.querySelector('#app-shell').hidden);
  inApp ? ok('uygulamaya girildi') : bad('uygulamaya girilemedi');
  if (!inApp) { await browser.close(); process.exit(1); }

  const timers = await page.evaluate(() => ({
    pollTimer: state.pollTimer,
    roomTimer: state.roomNotificationTimer,
    heartbeat: state.heartbeatTimer,
  }));

  if (NATIVE) {
    timers.pollTimer == null && timers.roomTimer == null && timers.heartbeat == null
      ? ok('NATIVE: periyodik yoklama ZER0 (mesaj/oda/heartbeat timer\'i yok)')
      : bad('NATIVE: beklenmeyen timer: ' + JSON.stringify(timers));

    await page.evaluate(() => { enterRoom(state.rooms[0]); });
    await wait(3500);
    const callsBefore = await page.evaluate(() => window.__fetchCalls.slice());

    // 1) AKTİF odaya __asraPush (detayli payload) -> SUNUCUYA SIFIR ISTEK,
    //    mesaj yerel render edilir (en kucuk hal)
    const hostCountBefore1 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    await page.evaluate(() => {
      window.__asraPush({ rid: state.currentRoom.id, rn: 'Asra Genel', tx: 'push-test-1', mid: 99001, u: 'testsender', t: new Date().toISOString(), full: 'yerel-render-test' });
    });
    await wait(2500);
    const rendered = await page.evaluate(() => (document.querySelector('#message-list') || { textContent: '' }).textContent.includes('yerel-render-test'));
    const hostCountAfter1 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    rendered ? ok('NATIVE: push geldi -> mesaj YEREL RENDER edildi') : bad('NATIVE: yerel render yok');
    hostCountAfter1 === hostCountBefore1 ? ok('NATIVE: yerel renderda sunucuya SIFIR istek') : bad('NATIVE: yerel renderda ' + (hostCountAfter1 - hostCountBefore1) + ' istek gitti!');

    // 2) Başka odaya push -> yerel unread +1, get_rooms YOK
    const otherRoomId = await page.evaluate(() => state.rooms.find((r) => r.id !== state.currentRoom.id).id);
    const unreadBefore = await page.evaluate((rid) => Number(state.rooms.find((r) => r.id === rid).unread_count || 0), otherRoomId);
    await page.evaluate((rid) => { window.__asraPush({ rid, rn: 'X', tx: 'push-test-2' }); }, otherRoomId);
    await wait(1500);
    const unreadAfter = await page.evaluate((rid) => Number(state.rooms.find((r) => r.id === rid).unread_count || 0), otherRoomId);
    unreadAfter === unreadBefore + 1 ? ok('NATIVE: başka odaya push -> yerel unread +1 (istek yok)') : bad('NATIVE: unread artmadı (' + unreadBefore + '->' + unreadAfter + ')');
    const callsAfterUnread = await page.evaluate(() => window.__fetchCalls.slice());
    const newRoomCalls = callsAfterUnread.filter((c) => c.includes('get_rooms') && callsBefore.indexOf(c) === -1);
    newRoomCalls.length === 0 ? ok('NATIVE: unread güncellemesi get_rooms yemedi') : bad('NATIVE: yeni get_rooms: ' + newRoomCalls.length);

    // 3) Sistem olayı (rid=0) -> session tazelendi (push oncesi/sonrasi sayim)
    const sessBefore = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('action=session')).length);
    await page.evaluate(() => { window.__asraPush({ rid: 0, rn: 'Sistem', tx: 'kabul edildin' }); });
    await wait(2500);
    const sessAfter = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('action=session')).length);
    sessAfter > sessBefore ? ok('NATIVE: sistem olayı -> session tazelendi') : bad('NATIVE: session tazelenmedi');

    // 4) ntfy DOĞRUDAN KANAL (eski APK senaryosu)
    await wait(5500); // 5sn coalesce penceresi
    const hostBefore = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    const ntfyTopic = 'asra' + require('crypto').createHash('sha256').update('asra.testdevice000000').digest('hex').slice(0, 20);
    await page.evaluate((base, topic, rid) => {
      fetch(base + '/' + topic, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain', 'Title': 'Asra Genel' },
        body: JSON.stringify({ rn: 'Asra Genel', rid: rid, sid: 99, tx: 'ntfy-direct-test', mid: 99002, u: 'ntfysender', t: new Date().toISOString(), full: 'ntfy-yerel-render' }),
      }).catch(() => {});
    }, NTFY_BASE, ntfyTopic, await page.evaluate(() => state.currentRoom.id));
    await wait(6000);
    const hostAfter = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    const ntfyPolls = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('/ntfy/') && c.includes('/json')).length);
    const ntfyRendered = await page.evaluate(() => (document.querySelector('#message-list') || { textContent: '' }).textContent.includes('ntfy-yerel-render'));
    ntfyPolls >= 1 ? ok('NATIVE: ntfy doğrudan dinleme aktif') : bad('NATIVE: ntfy doğrudan dinleme yok');
    ntfyRendered ? ok('NATIVE: ntfy push\'u geldi -> YEREL RENDER (eski APK uyumlu)') : bad('NATIVE: ntfy yerel render yok');
    hostAfter === hostBefore ? ok('NATIVE: ntfy pushunda sunucuya SIFIR istek') : bad('NATIVE: ntfy pushunda ' + (hostAfter - hostBefore) + ' istek gitti!');

    // 5b) ARKADA AÇIK KALMA ÖNLEMİ
    const pollsHidden0 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('/ntfy/') && c.includes('/json')).length);
    await page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { get: () => 'hidden', configurable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await wait(5000);
    const pollsHidden1 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('/ntfy/') && c.includes('/json')).length);
    pollsHidden1 <= pollsHidden0 ? ok('NATIVE: gizlenince ntfy dinlemesi KESILDI') : bad('NATIVE: gizliyken ntfy istegi (' + pollsHidden0 + '->' + pollsHidden1 + ')');
    const chanCalls = await page.evaluate(() => window.__ntfyChannelCalls || []);
    chanCalls.includes(false) ? ok('NATIVE: gizlenince native\'e setNtfyChannel(false) gitti') : bad('NATIVE: false sinyali gitmedi');
    await page.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { get: () => 'visible', configurable: true });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    await wait(5000);
    const pollsVis = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('/ntfy/') && c.includes('/json')).length);
    pollsVis > pollsHidden1 ? ok('NATIVE: görününce ntfy dinlemesi yeniden kuruldu') : bad('NATIVE: görününce dinleme kalmadı');

    // 6a) ODAYA GIR: birinci giris onbelleksiz (1 istek, dolar); ikinci
    //     giris yerel onbellegten -> 0 host isteği
    const hostRoom0 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    await page.evaluate(() => { enterRoom(state.rooms.find((r) => r.id !== state.currentRoom.id)); });
    await wait(2500);
    const hostRoom1 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    (hostRoom1 - hostRoom0) === 1 ? ok('ODAYA GIR (ilk): 1 istek (onbellek dolumu)') : bad('ilk oda girisi ' + (hostRoom1 - hostRoom0) + ' istek');
    await page.evaluate(() => { enterRoom(state.rooms[0]); }); // once girilmis oda -> onbellek
    await wait(2500);
    const hostRoom2 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    hostRoom2 === hostRoom1 ? ok('ODAYA GIR (ikinci): 0 istek (yerel onbellek)') : bad('ikinci oda girisi ' + (hostRoom2 - hostRoom1) + ' istek gitti!');
    const cachedVisible = await page.evaluate(() => (document.querySelector('#message-list') || { textContent: '' }).textContent.includes('yerel-render-test'));
    cachedVisible ? ok('onbellekten cizilen odada push mesajlari gorunur') : bad('onbellek icerigi cizilmedi');

    // 6b) MESAJ GONDER: tam 1 istek (gonderinin kendisi); ek fetch YOK
    const hostSend0 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    await page.evaluate(() => {
      const ta = document.querySelector('#message-input');
      ta.value = 'sifir-test-mesaji';
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.closest('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    });
    await wait(2500);
    const hostSend1 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    (hostSend1 - hostSend0) === 1 ? ok('MESAJ GONDER: tam 1 istek (gonderinin kendisi)') : bad('mesaj gonderimi ' + (hostSend1 - hostSend0) + ' istek!');
    const sentVisible = await page.evaluate(() => (document.querySelector('#message-list') || { textContent: '' }).textContent.includes('sifir-test-mesaji'));
    sentVisible ? ok('gonderilen mesaj yerel cizildi (ek fetch yok)') : bad('gonderilen mesaj cizilmedi');

    // 6) 10 sn: host'a periyodik istek YOK (ntfy sorguları harici servis, sayılmaz)
    const snap = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    await wait(10000);
    const after10 = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('auth.php')).length);
    after10 === snap ? ok('NATIVE: 10 sn de hosta sifir periyodik istek') : bad('NATIVE: 10 sn de ' + (after10 - snap) + ' periyodik host istegi!');
  } else {
    timers.pollTimer == null && timers.roomTimer == null && timers.heartbeat == null
      ? ok('BROWSER: periyodik host yoklamasi SIFIR (zamanlayici yok)')
      : bad('BROWSER: beklenmeyen timer: ' + JSON.stringify(timers));
    await wait(4000); // ntfy kanali baslatsin + register_push gitsin
    const bNtfyPolls = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('/ntfy/') && c.includes('/json')).length);
    bNtfyPolls >= 1 ? ok('BROWSER: ntfy kanali aktif (gercek zamanli, dis servis)') : bad('BROWSER: ntfy kanali yok');
    const bRegistered = await page.evaluate(() => window.__fetchCalls.some((c) => c.includes('action=register_push')));
    bRegistered ? ok('BROWSER: ntfy konusu sunucuya kaydedildi (register_push)') : bad('BROWSER: register_push gitmedi');
    // BURST CATCH-UP: 6 push bir tikte toplanir -> yerel render + TEK get_rooms
    const webDevId = await page.evaluate(() => localStorage.getItem('asra_web_device'));
    const webTopic = 'asra' + require('crypto').createHash('sha256').update(String(webDevId)).digest('hex').slice(0, 20);
    const roomsBefore = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('action=get_rooms')).length);
    await page.evaluate((base, topic) => {
      for (let i = 0; i < 6; i++) {
        fetch(base + '/' + topic, { method: 'POST', headers: { 'Content-Type': 'text/plain', 'Title': 'Asra Genel' }, body: JSON.stringify({ rn: 'Asra Genel', rid: 2, sid: 98, tx: 'burst-' + i }) }).catch(() => {});
      }
    }, NTFY_BASE, webTopic);
    await wait(9000); // tik (6sn) + debounce (1.5sn)
    const roomsAfter = await page.evaluate(() => window.__fetchCalls.filter((c) => c.includes('action=get_rooms')).length);
    (roomsAfter - roomsBefore) === 1 ? ok('BROWSER: burst catch-up -> TEK tazeleme istegi (self-heal)') : bad('BROWSER: burst tazeleme istegi ' + (roomsAfter - roomsBefore) + ' (beklenen 1)');
  }

  console.log('\nSONUÇ: ✓' + pass + ' ✗' + fail);
  await browser.close();
  process.exit(fail === 0 ? 0 : 1);
})().catch((e) => { console.error('HATA:', e.stack || e.message); process.exit(3); });
