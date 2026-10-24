// BİLGİ ODASI iyileştirme — tarayıcı doğrulaması (elle koşulur: info_ui_smoke_test.sh)
// Kullanım: node info_ui_smoke.js <origin> <kullanıcı> <şifre> [çıktı-dizini]
const puppeteer = require('puppeteer-core');
const fs = require('node:fs');
const path = require('node:path');
const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const injected = fs.readFileSync(path.join(__dirname, '../asra-apk-build/app/src/main/java/app/asra/client/AsraInject.kt'), 'utf8');
const BACK_CODE = injected.match(/const val BACK_SCRIPT = """([\s\S]*?)"""/)[1];
const ORIGIN = process.argv[2] || 'http://127.0.0.1:8096';
const USER = process.argv[3] || 'uismoke';
const PW = process.argv[4] || 'Test123!xyz';
const SHOTDIR = process.argv[5] || '/tmp/info-ui-shots';

let pass = 0, fail = 0, jsErrors = 0;
let browser;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); };
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  // Temiz bir makinede eski ekran görüntüsü klasörüne bağımlı olma.
  fs.mkdirSync(SHOTDIR, { recursive: true });
  browser = await puppeteer.launch({
    executablePath: CHROME, headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });
  const page = await browser.newPage();
  page.on('pageerror', (e) => { jsErrors += 1; console.log('PAGE_ERROR ' + e.message); });
  page.on('console', (m) => {
    if (m.type() === 'error') { jsErrors += 1; console.log('PAGE_ERROR [console] ' + m.text()); }
  });
  page.on('response', async (r) => {
    const u = String(r.url());
    const am = u.match(/action=([a-z_]+)/);
    if (am && am[1] === 'info_form_reply_like') {
      let b = '';
      try { b = (await r.text()).slice(0, 160); } catch (e) {}
      console.log('LIKE_LOG ' + r.status() + ' ' + b);
    }
  });
  await page.setViewport({ width: 900, height: 900 });
  await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) InfoUI/1.0');
  await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(1200);

  // --- giriş ---
  await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
  await page.evaluate((u, p) => {
    const un = document.querySelector('#login-username');
    const pw = document.querySelector('#login-password');
    if (un) { un.value = u; un.dispatchEvent(new Event('input', { bubbles: true })); }
    if (pw) { pw.value = p; pw.dispatchEvent(new Event('input', { bubbles: true })); }
    const f = document.querySelector('#login-form');
    if (f) f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }, USER, PW);
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 25000 }).catch(() => {});
  let routeOk = false;
  for (let i = 0; i < 30; i++) {
    routeOk = await page.evaluate(() => typeof state !== 'undefined' && state.route === 'home');
    if (routeOk) break;
    await sleep(1000);
  }
  check(routeOk, 'oturum açıldı, ana ekran (home)');

  // --- 1) Ana ekran: Bilgi Odası kartında ⭐ + özel sınıf, kalp YOK ---
  const card = await page.evaluate(() => {
    const c = Array.from(document.querySelectorAll('.room-card')).find((el) => el.dataset.roomId === '0');
    if (!c) return null;
    return {
      text: c.textContent.replace(/\s+/g, ' ').trim(),
      infoClass: c.classList.contains('room-card-info'),
      star: !!c.querySelector('.room-card-star'),
      like: !!document.querySelector('#info-room-like, .info-room-like'),
    };
  });
  check(!!card && card.text.includes('Bilgi Odası'), 'ana ekranda Bilgi Odası kartı var');
  check(!!card && card.infoClass, 'Bilgi Odası kartı özel sınıf taşıyor (room-card-info)');
  check(!!card && card.star, 'Bilgi Odası kartında ⭐ yıldızı var');
  check(card && card.like === false, 'sayfada oda kalbi (info-room-like) YOK');
  await page.screenshot({ path: SHOTDIR + '/1-kart.png' });

  // --- 2) Bilgi Odası ekranı ---
  await page.evaluate(() => {
    const c = Array.from(document.querySelectorAll('.room-card')).find((el) => el.dataset.roomId === '0');
    if (c) c.click();
  });
  await page.waitForFunction(() => typeof state !== 'undefined' && state.route === 'info', { timeout: 15000 }).catch(() => {});
  await sleep(800);
  const h = await page.evaluate(() => ({
    h1: document.querySelector('.page-heading h1') ? document.querySelector('.page-heading h1').textContent : '',
    cats: Array.from(document.querySelectorAll('.info-cat')).map((el) => el.dataset.cat),
    noLike: !document.querySelector('#info-room-like, .info-room-like'),
  }));
  check(h.h1.trim().includes('⭐') && h.h1.includes('Bilgi Odası'), 'Bilgi Odası başlığı ⭐ ile görünüyor');
  check(['haber', 'kaynak', 'sohbet', 'ders', 'calisma', 'form'].every((c2) => h.cats.includes(c2)),
    '6 kategori sekmesi duruyor: ' + h.cats.join(','));
  check(h.noLike, 'Bilgi Odası başlığında kalp butonu yok');

  // APK'nın Android geri köprüsünü GERÇEK site DOM'unda çalıştır:
  // önce menü, sonra modal, sonra Bilgi Odası -> ana ekran.
  await page.evaluate(() => document.querySelector('#menu-button').click());
  const menuBack = await page.evaluate(BACK_CODE);
  check(menuBack === 'handled' && await page.evaluate(() => document.querySelector('#account-menu').hidden),
    'Android geri: açık menüyü kapatır');
  await page.evaluate(() => {
    document.querySelector('#menu-button').click();
    document.querySelector('[data-menu-action="refresh"]').click();
  });
  const modalBack = await page.evaluate(BACK_CODE);
  check(modalBack === 'handled' && await page.evaluate(() => document.querySelector('#modal-backdrop').hidden),
    'Android geri: modali kapatır');
  const infoBack = await page.evaluate(BACK_CODE);
  check(infoBack === 'handled' && await page.evaluate(() => state.route === 'home'),
    'Android geri: Bilgi Odası yerine ana ekrana döner');
  await page.evaluate(() => {
    const c = Array.from(document.querySelectorAll('.room-card')).find((el) => el.dataset.roomId === '0');
    if (c) c.click();
  });
  await page.waitForFunction(() => state.route === 'info', { timeout: 15000 });

  // --- 3) Haber sekmesi: 24 saat yenileme notu + besleme içeriği ---
  await page.evaluate(() => {
    const b = Array.from(document.querySelectorAll('.info-cat')).find((el) => el.dataset.cat === 'haber');
    if (b) b.click();
  });
  await page.waitForFunction(() => !!document.querySelector('#info-content .info-item-list'), { timeout: 30000 }).catch(() => {});
  await sleep(600);
  const haber = await page.evaluate(() => ({
    note: document.querySelector('.info-refresh-note') ? document.querySelector('.info-refresh-note').textContent : '',
    titles: Array.from(document.querySelectorAll('#info-content .info-item-title')).map((el) => el.textContent),
  }));
  check(haber.note.includes('24 saat'), 'haber sekmesinde "24 saatte bir" yenileme notu var');
  check(haber.note.includes('Son güncelleme') || haber.note.includes('Sonraki'), 'notta son güncelleme/sonraki bilgisi var');
  check(haber.titles.some((t2) => t2.includes('UI FIXTURE HABER')), 'haber beslemesi içeriği geldi: ' + haber.titles.join(' | '));
  await page.screenshot({ path: SHOTDIR + '/2-haber.png' });

  // --- 4) Kaynak sekmesi: boş değil, rehber (builtin) maddeler ---
  await page.evaluate(() => {
    const b = Array.from(document.querySelectorAll('.info-cat')).find((el) => el.dataset.cat === 'kaynak');
    if (b) b.click();
  });
  await page.waitForFunction(() => !!document.querySelector('#info-content .info-item-list'), { timeout: 20000 }).catch(() => {});
  await sleep(500);
  const kaynak = await page.evaluate(() => ({
    count: document.querySelectorAll('#info-content .info-item').length,
    badges: Array.from(document.querySelectorAll('#info-content .badge')).map((el) => el.textContent),
    emptyText: document.querySelector('#info-content .panel-description') ? document.querySelector('#info-content .panel-description').textContent : '',
  }));
  check(kaynak.count >= 6, 'kaynak sekmesi dolu (≥6 madde, gelen: ' + kaynak.count + ')');
  check(kaynak.badges.includes('Rehber'), 'rehber (builtin) rozetleri görünüyor');
  check(!kaynak.emptyText.includes('henüz içerik yok'), '"içerik yok" uyarısı görünmüyor');
  await page.screenshot({ path: SHOTDIR + '/3-kaynak.png' });

  // --- 5) Form sekmesi: 24 saat uyarısı; form aç; yorum yaz; kalbe bas ---
  await page.evaluate(() => {
    const b = Array.from(document.querySelectorAll('.info-cat')).find((el) => el.dataset.cat === 'form');
    if (b) b.click();
  });
  await page.waitForFunction(() => !!document.querySelector('.info-form-create'), { timeout: 20000 }).catch(() => {});
  const noteText = await page.evaluate(() => {
    const n = document.querySelector('.form-retention-note');
    return n ? n.textContent : '';
  });
  check(noteText.includes('24 saat') && noteText.includes('silinir'), 'formlarda "24 saat sonra silinir" uyarısı var');
  await page.screenshot({ path: SHOTDIR + '/4-form-uyari.png' });

  await page.evaluate(() => {
    const nb = document.querySelector('#info-form-new-btn');
    if (nb) nb.click();
  });
  await page.waitForFunction(() => {
    const box = document.querySelector('.info-form-create');
    return box && !box.hidden;
  }, { timeout: 10000 }).catch(() => {});
  await page.evaluate(() => {
    const t = document.querySelector('#info-form-title');
    const b2 = document.querySelector('#info-form-body');
    if (t) { t.value = 'UI kalp denemesi'; t.dispatchEvent(new Event('input', { bubbles: true })); }
    if (b2) { b2.value = 'Bu form tarayıcı testi için açıldı.'; b2.dispatchEvent(new Event('input', { bubbles: true })); }
    const f = document.querySelector('.info-form-create form');
    if (f) f.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  });
  const formCreated = await page.waitForFunction(() => !!document.querySelector('.form-card'), { timeout: 20000 }).then(() => true).catch(() => false);
  check(formCreated, 'UI üzerinden form açıldı');

  await page.evaluate(() => {
    const inp = document.querySelector('.form-reply-input');
    if (inp) { inp.value = 'Yoruma kalp atılabilmeli!'; inp.dispatchEvent(new Event('input', { bubbles: true })); }
    const snd = document.querySelector('.form-reply-send');
    if (snd) snd.click();
  });
  const replyShown = await page.waitForFunction(() => document.querySelectorAll('.form-reply').length >= 1, { timeout: 20000 }).then(() => true).catch(() => false);
  check(replyShown, 'yorum satırı oluştu');
  await sleep(700);
  const heart0 = await page.evaluate(() => {
    const b = document.querySelector('.form-reply .form-reply-like');
    return b ? b.textContent : '';
  });
  check(heart0.startsWith('♡'), 'yorum satırında kalp butonu var: "' + heart0 + '"');
  await page.evaluate(() => {
    const b = document.querySelector('.form-reply .form-reply-like');
    if (b) b.click();
  });
  const likedOn = await page.waitForFunction(() => {
    const b = document.querySelector('.form-reply .form-reply-like');
    return b && b.textContent.indexOf('♥') === 0;
  }, { timeout: 15000 }).then(() => true).catch(() => false);
  check(likedOn, 'kalbe basınca ♥ ile beğenildi');
  await sleep(1200);
  await page.screenshot({ path: SHOTDIR + '/5-yorum-kalp.png' });

  // tekrar bas -> kaldır (çift dokunuş kilidi 3 sn; onun geçmesini bekle)
  await sleep(3200);
  await page.evaluate(() => {
    const b = document.querySelector('.form-reply .form-reply-like');
    if (b) b.click();
  });
  let likedOff = false;
  for (let i = 0; i < 15; i++) {
    const txt = await page.evaluate(() => {
      const b = document.querySelector('.form-reply .form-reply-like');
      return b ? b.textContent : '(yok)';
    });
    console.log('OFF_POLL t+' + i + ' ' + txt);
    if (txt.startsWith('♡')) { likedOff = true; break; }
    await sleep(1000);
  }
  check(likedOff, 'kalp kapatılabiliyor (♡)');

  console.log('OK');
  console.log('SONUÇ: ✓' + pass + ' ✗' + fail + (jsErrors ? '  JS_HATASI:' + jsErrors : ''));
  await browser.close();
  process.exit(fail > 0 || jsErrors > 0 ? 1 : 0);
})().catch(async (e) => {
  console.log('HATA ' + e.message);
  try { if (browser) await browser.close(); } catch (_) {}
  process.exit(1);
});
