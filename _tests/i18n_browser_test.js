// ============================================================================
//  ÇOK DİLLİLİK (tr + en) — tarayıcı doğrulaması (puppeteer + Chromium)
//
//  Düzeltilen kök nedenler:
//    1) applyLanguage() yalnızca <html lang> yazıyordu; statik HTML (giriş,
//       kayıt, başlık, menü) ve açık ekran hiç dile dönmüyordu.
//    2) Üye yolculuğundaki metinlerin çoğu I18N sözlüğünde değil, kodun içinde
//       sabit Türkçe dizgi olarak duruyordu; dil değişse bile Türkçe kalıyordu.
//    3) Sunucu hata mesajları Türkçe döner ve olduğu gibi gösterilirdi.
//    4) Dil seçimi kullanıcı kaydında yaşadığı için çıkışta/giriş ekranında
//       tercih hatırlanmıyordu (state.user = null → hep tr).
//
//  Ölçülenler (gerçek sayfada, gerçek PHP/MySQL sunucusuna karşı):
//    A) Türkçe arayüz BOZULMADI: açılış, davet kapısı, giriş formu ve sunucu
//       hata mesajı Türkçe kalmaya devam ediyor (regresyon kanıtı).
//    B) Dil seçicide yalnızca tr + en var (de/fr kaldırıldı).
//    C) English seçilince: <html lang="en">, Ayarlar ekranı, hesap menüsü,
//       ana sayfa başlığı, şifre değiştirme modalı, kural hatası, "şifremi
//       unuttum" modalı ve tarih biçimi İngilizce; bu yüzeylerde tek bir Türkçe
//       karakter bile kalmıyor.
//    D) Sunucu kaynaklı hata (yanlış şifre) English'de İngilizce gösteriliyor.
//    E) Çıkıştan sonra giriş ekranı İngilizce kalıyor (tercih hatırlanıyor).
//    F) Türkçeye geri dönüş çalışıyor: ekranlar yeniden Türkçe (kalıcı hasar yok).
//    G) Kural hatası İSTEK ATILMADAN yerinde reddediliyor (change_password = 0).
//    H) Tüm akış boyunca sayfada JS hatası oluşmuyor.
//
//  KAPSAM SINIRI (bilinçli): oda kataloğunun tohum adları/açıklamaları, Asra AI
//  içeriği, görev/oyun metinleri ve admin paneli çevrilmez — bu yüzden "Türkçe
//  karakter yok" iddiası YALNIZCA üye yolculuğunun belirli yüzeylerinde ölçülür.
//
//  Çıkış: 0 = hepsi geçti.
// ============================================================================
const fs = require('fs');
const puppeteer = require('puppeteer-core');

const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const ORIGIN = process.env.ORIGIN || 'http://127.0.0.1:8097';
const TDIR = process.argv[2] || '/tmp';
const USERNAME = process.env.TEST_USER || 'i18nui';
const PASSWORD = process.env.TEST_PASSWORD || 'Test123!xyz';
const SHOTS = process.env.SHOT_DIR || '';

let pass = 0;
let fail = 0;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); };
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

const TR = /[çğıöşüÇĞİÖŞÜâîû]/;

/** Bir seçicinin görünür metnini döndürür (yoksa boş dizge). */
async function textOf(page, selector) {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    return el ? String(el.textContent || '').replace(/\s+/g, ' ').trim() : '';
  }, selector);
}

/** Seçicide Türkçe karakter kalmadığını doğrular; kalanları rapora yazar. */
/**
 * Görünür metni innerText ile alır: textContent bitişik öğeleri AYRAÇSIZ
 * birleştirir ve sözcük denetimi sahte sonuç üretir. Dil seçicideki 'Türkçe'
 * gibi KENDİ DİLİNDE yazılan dil adları bilinçli istisnadır (evrensel UX).
 */
async function visibleText(page, selector) {
  return page.evaluate((sel) => {
    const el = document.querySelector(sel);
    if (!el) return '';
    const clone = el.cloneNode(true);
    clone.querySelectorAll('select[name="language"] option').forEach((o) => o.remove());
    return String(clone.innerText || clone.textContent || '').replace(/\s+/g, ' ').trim();
  }, selector);
}

async function assertNoTurkish(page, selector, label) {
  const text = await visibleText(page, selector);
  if (!text) { bad(`${label}: "${selector}" boş/bulunamadı`); return; }
  const leaked = [...new Set(text.split(/\s+/).filter((w) => TR.test(w)))];
  check(!leaked.length, `${label}: Türkçe sızıntı yok (${text.length} karakter)`
    + (leaked.length ? ` — sızan: ${leaked.slice(0, 8).join(', ')}` : ''));
}

async function assertTurkish(page, selector, needle, label) {
  const text = await textOf(page, selector);
  check(text.includes(needle), `${label}: "${needle}" görünüyor`
    + (text.includes(needle) ? '' : ` — gelen: ${text.slice(0, 90)}`));
}

/** Sayfa içi bir ifade doğru olana kadar bekler. */
/**
 * Sayfa içi koşul sağlanana kadar bekler. `arg` SAYFA BAĞLAMINA taşınır:
 * page.evaluate(fn) yalnızca fn.toString() gönderdiği için Node tarafındaki
 * kapalı değişkenler tarayıcıda ReferenceError üretir ve koşul hiç sağlanmaz.
 */
async function until(page, fn, arg, tries = 30, step = 200) {
  for (let i = 0; i < tries; i += 1) {
    // eslint-disable-next-line no-await-in-loop
    const done = await page.evaluate(fn, arg).catch(() => false);
    if (done) return true;
    // eslint-disable-next-line no-await-in-loop
    await sleep(step);
  }
  return false;
}

/** Toast bölmesinin metin listesi (anlık görüntü). */
function snapshotToasts(page) {
  return page.evaluate(() => Array.from(document.querySelectorAll('#toast-region .toast'))
    .map((el) => String(el.textContent || '').replace(/\s+/g, ' ').trim()));
}

/**
 * YENİ bir bildirimi bekler ve metnini döndürür.
 *
 * Sayı artışıyla beklemek YANLIŞ SONUÇ verir: giriş/kayıt bildirimleri birkaç
 * saniye yaşar, giriş isteği ise minLoadingMs=3000 nedeniyle ~3-4 sn sürer. Eski
 * bildirim yenisi gelmeden söndüğü için sayı 1 -> 0 -> 1 olur ve "arttı"
 * koşulu hiç sağlanmaz. Bu yüzden metin KÜMESİ karşılaştırılır.
 */
async function waitForNewToast(page, before, tries = 60, step = 250) {
  for (let i = 0; i < tries; i += 1) {
    // eslint-disable-next-line no-await-in-loop
    const now = await snapshotToasts(page);
    const fresh = now.filter((text) => text && !before.includes(text));
    if (fresh.length) return fresh[fresh.length - 1];
    // eslint-disable-next-line no-await-in-loop
    await sleep(step);
  }
  return '';
}

/** Modal gerçekten açık mı (kapalıyken metin denetimi sahte geçiş üretir). */
function modalIsOpen(page) {
  return page.evaluate(() => {
    const m = document.querySelector('#modal');
    return Boolean(m && !m.hidden);
  });
}

/**
 * Giriş ekranını SIFIRDAN kurar: davet kapısı doğrulanır ve 3 sn'lik form
 * kilidi ile başarısız denemenin bekleme süresi temizlenmiş olur.
 */
async function freshAuthPage(page) {
  await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(1200);
  await waitAuthReady(page);
}

async function waitAuthReady(page) {
  await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
  // Davet kapısı kaldırıldığı için giriş formu artık İLK EKRANDADIR; bu bekleme
  // yalnızca önceki bir gönderimin (ör. çıkış) eylem kilidinin (3 sn) düşmesini
  // sağlar. Kilidi beklemeden yapılan gönderim SESSİZCE yutulur, test yanlış
  // toast okur.
  await sleep(3400);
}

async function tryLogin(page, password) {
  await page.evaluate((u, p) => {
    const un = document.querySelector('#login-username');
    const pw = document.querySelector('#login-password');
    if (!un || !pw) return;
    un.value = u; un.dispatchEvent(new Event('input', { bubbles: true }));
    pw.value = p; pw.dispatchEvent(new Event('input', { bubbles: true }));
    document.querySelector('#login-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  }, USERNAME, password);
}

/** Menü > Ayarlar yolunu gerçek kullanıcı gibi yürür. */
async function openSettings(page) {
  const shell = await page.evaluate(() => {
    const el = document.querySelector('#app-shell');
    return Boolean(el && !el.hidden);
  });
  if (!shell) return 'no-shell';
  await page.evaluate(() => { if (typeof renderHome === 'function' && state.route !== 'home') renderHome(); });
  await sleep(400);
  await page.evaluate(() => document.querySelector('#menu-button').click());
  await sleep(300);
  await page.evaluate(() => document.querySelector('[data-menu-action="settings"]').click());
  await until(page, () => state.route === 'settings', null, 25, 200);
  return page.evaluate(() => state.route);
}

/** Ayarlar ekranındaki bir düğmeyi EKRAN DİLİNE BAĞIMLI OLMADAN bulur/tıklar. */
async function clickSettingsButtonByKey(page, key) {
  return page.evaluate((k) => {
    const wanted = t(k).trim().toLowerCase();
    const target = Array.from(document.querySelectorAll('#app-view button'))
      .find((b) => (b.textContent || '').trim().toLowerCase() === wanted);
    if (!target) return false;
    target.click();
    return true;
  }, key);
}

/** Ayarlar formunu seçili dille gönderir. */
function submitLanguage(page, code) {
  return page.evaluate((lang) => {
    const select = document.querySelector('#app-view select[name="language"]');
    if (!select) return false;
    const form = select.closest('form');
    if (!form) return false;
    select.value = lang;
    select.dispatchEvent(new Event('change', { bubbles: true }));
    select.dispatchEvent(new Event('input', { bubbles: true }));
    form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    return true;
  }, code);
}

/**
 * Dili değiştirir ve <html lang> gerçekten dönene kadar bekler. İstemci her
 * gönderimde tüm formları 3 sn kilitlediği için ilk gönderim yutulabilir;
 * bu yüzden koşul sağlanmazsa form bir kez daha gönderilir.
 */
async function setLanguage(page, code) {
  await sleep(3400);
  if (!await submitLanguage(page, code)) return false;
  const isSet = (c) => document.documentElement.lang === c;
  if (await until(page, isSet, code, 40, 250)) return true;
  await sleep(3400);
  if (!await submitLanguage(page, code)) return false;
  return until(page, isSet, code, 40, 250);
}

async function closeModal(page) {
  await page.evaluate(() => { if (typeof closeModal === 'function') closeModal(); });
  await sleep(300);
}

async function shot(page, name) {
  if (!SHOTS) return;
  try { await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false }); } catch (_) { /* yoksay */ }
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 900 });

  const pageErrors = [];
  const failedRequests = [];
  let changePasswordRequests = 0;
  let unauthorized = 0;
  page.on('pageerror', (e) => pageErrors.push(String(e && e.message ? e.message : e)));
  // Bilinçli YANLIŞ ŞİFRE denemeleri 401 üretir; Chromium bunları konsol
  // hatası olarak da loglar. Bu beklenen gürültü başarısızlık sayılmaz,
  // onun yerine aşağıda "denemeler gerçekten sunucuya ulaştı" kanıtı olarak
  // ayrıca sayılır.
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource/i.test(m.text())) pageErrors.push('console: ' + m.text());
  });
  page.on('response', (r) => { if (r.status() === 401) unauthorized += 1; });
  page.on('requestfailed', (r) => failedRequests.push(`${r.url()} ${r.failure() && r.failure().errorText}`));
  page.on('request', (r) => { if (String(r.url()).includes('change_password')) changePasswordRequests += 1; });

  try {
    // ---------------------------------------------------------------- A) TR
    console.log('== A) Türkçe arayüz bozulmadı (regresyon kanıtı) ==');
    await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    await sleep(1200);
    check(await page.evaluate(() => document.documentElement.lang) === 'tr', 'açılışta <html lang="tr">');
    await assertTurkish(page, '#auth-screen', 'Topluluğa katılmak için', 'auth açıklaması (TR)');
    await assertTurkish(page, '#auth-screen', 'Kaydol', 'kayıt sekmesi (TR)');
    await assertTurkish(page, '#auth-screen', 'Kullanıcı adı', 'giriş formu');
    await assertTurkish(page, '#auth-screen', 'Giriş Yap', 'giriş sekmesi');
    await shot(page, 'i18n-1-giris-tr');

    // Sunucu hatası Türkçe arayüzde DOKUNULMADAN geçmeli.
    const beforeTr = await snapshotToasts(page);
    await tryLogin(page, 'Yanlis123!sifre');
    const trToast = await waitForNewToast(page, beforeTr);
    check(trToast.includes('Kullanıcı adı veya şifre hatalı.'),
      'TR: sunucu hata mesajı olduğu gibi Türkçe — gelen: ' + trToast.slice(0, 70));

    // ---------------------------------------------------------------- B) seçenekler
    console.log('== B) Dil seçici yalnızca tr + en ==');
    // Başarısız denemenin hemen ardından aynı form yeniden gönderilmez: istemci
    // 3 sn kilitler. Gerçek kullanıcı gibi sayfa tazelenir.
    await freshAuthPage(page);
    await tryLogin(page, PASSWORD);
    const entered = await page.waitForSelector('#app-shell:not([hidden])', { timeout: 25000 })
      .then(() => true).catch(() => false);
    check(entered, 'giriş başarılı (#app-shell görünür)');
    await sleep(1500);

    check(await openSettings(page) === 'settings', 'menü > Ayarlar açıldı');
    await assertTurkish(page, '#app-view', 'Görünüm, dil ve gizlilik', 'ayarlar açıklaması (TR)');
    const options = await page.evaluate(() => Array.from(document.querySelectorAll('#app-view select[name="language"] option')).map((o) => o.value));
    check(JSON.stringify(options) === JSON.stringify(['tr', 'en']),
      'dil seçenekleri tam olarak [tr,en] — gelen: ' + JSON.stringify(options));
    await shot(page, 'i18n-2-ayarlar-tr');

    // ---------------------------------------------------------------- C) EN
    console.log('== C) English seçilince tüm üye yüzeyleri İngilizce ==');
    check(await setLanguage(page, 'en'), 'dil English olarak kaydedildi ve <html lang="en">');
    await assertNoTurkish(page, '#app-view', 'Ayarlar ekranı');
    await assertTurkish(page, '#header-subtitle', 'Account and privacy', 'ayarlar başlığı (EN)');
    await assertNoTurkish(page, '#header-subtitle', 'başlık alt satırı');
    await shot(page, 'i18n-3-ayarlar-en');

    await page.evaluate(() => document.querySelector('#menu-button').click());
    await sleep(400);
    await assertNoTurkish(page, '#account-menu', 'hesap menüsü');
    await assertTurkish(page, '#account-menu', 'Settings', 'menü öğesi (EN)');
    await shot(page, 'i18n-4-menu-en');
    await page.evaluate(() => document.querySelector('#menu-button').click());
    await sleep(300);

    // Şifre değiştirme modalı + yerinde kural hatası (istek atılmadan).
    check(await clickSettingsButtonByKey(page, 'changePassword'), 'Change password düğmesi bulundu');
    await until(page, () => { const m = document.querySelector('#modal'); return Boolean(m && !m.hidden); }, null, 20, 200);
    check(await modalIsOpen(page), 'şifre değiştirme modalı açık');
    await assertNoTurkish(page, '#modal', 'şifre değiştirme modalı');
    await shot(page, 'i18n-5-sifre-modal-en');
    const ruleBefore = changePasswordRequests;
    const filled = await page.evaluate(() => {
      const form = document.querySelector('#modal form');
      if (!form) return false;
      const set = (name, value) => {
        const el = form.querySelector(`input[name="${name}"]`);
        if (!el) return false;
        el.value = value;
        el.dispatchEvent(new Event('input', { bubbles: true }));
        return true;
      };
      const okFields = set('current_password', 'Test123!xyz')
        && set('password', 'Yeni123!abc') && set('password_confirm', 'Baska123!abc');
      if (!okFields) return false;
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      return true;
    });
    check(filled, 'şifre alanları doldurulup form gönderildi');
    await sleep(800);
    const notice = await textOf(page, '#modal .field-note.danger-text');
    const expectedRule = await page.evaluate(() => t('ruleMismatch'));
    check(notice === expectedRule, `kural hatası İngilizce ve yerinde — beklenen: "${expectedRule}" gelen: "${notice}"`);
    check(!TR.test(notice), 'kural hatasında Türkçe karakter yok');
    check(changePasswordRequests === ruleBefore, 'kural hatası istek atmadan reddedildi (change_password = 0)');
    check(await modalIsOpen(page), 'modal açık kaldı (hata okunabilmeli)');
    await shot(page, 'i18n-6-kural-hatasi-en');
    await closeModal(page);

    // Şifremi unuttum modalı.
    check(await clickSettingsButtonByKey(page, 'forgotPassword'), 'Forgot password düğmesi bulundu');
    await until(page, () => { const m = document.querySelector('#modal'); return Boolean(m && !m.hidden); }, null, 20, 200);
    check(await modalIsOpen(page), '"şifremi unuttum" modalı açık');
    await assertNoTurkish(page, '#modal', '"şifremi unuttum" modalı');
    const paragraphs = await page.evaluate(() => document.querySelectorAll('#modal-content p.modal-copy').length);
    check(paragraphs >= 4, `unuttum mesajı 4 paragraf — gelen: ${paragraphs}`);
    await shot(page, 'i18n-7-unuttum-en');
    await closeModal(page);

    // Ana sayfa başlığı + tarih biçimi (Intl yereli).
    await page.evaluate(() => renderHome());
    await sleep(900);
    await assertNoTurkish(page, '.page-heading', 'ana sayfa başlığı');
    await assertTurkish(page, '.page-heading', 'Welcome to Asra', 'karşılama (EN)');
    const month = await page.evaluate(() => formatDate('2026-09-04T12:00:00Z', { day: 'numeric', month: 'long' }));
    check(/September/.test(month), 'tarih biçimi İngilizce yerel kullanıyor — gelen: ' + month);
    await shot(page, 'i18n-8-ana-sayfa-en');

    // ---------------------------------------------------------------- D/E) çıkış + sunucu hatası EN
    console.log('== D) Sunucu hatası English\'de İngilizce ==');
    await page.evaluate(() => document.querySelector('#menu-button').click());
    await sleep(300);
    await page.evaluate(() => document.querySelector('[data-menu-action="logout"]').click());
    await sleep(600);
    await until(page, () => { const m = document.querySelector('#modal'); return Boolean(m && !m.hidden); }, null, 20, 200);
    // performLogout onay modalı açmaz: doğrudan çıkış yapar ve sayfayı yeniler.
    await until(page, () => Boolean(document.querySelector('#auth-screen') && !document.querySelector('#auth-screen').hidden), null, 60, 400);
    await sleep(2500);
    const loggedOut = await page.evaluate(() => {
      const shell = document.querySelector('#app-shell');
      const auth = document.querySelector('#auth-screen');
      return Boolean(auth && !auth.hidden && (!shell || shell.hidden));
    });
    check(loggedOut, 'çıkış yapıldı ve giriş ekranına dönüldü');

    console.log('== E) Giriş ekranı İngilizce kaldı (tercih hatırlanıyor) ==');
    check(await page.evaluate(() => document.documentElement.lang) === 'en', 'çıkıştan sonra <html lang="en">');
    await assertNoTurkish(page, '#auth-screen', 'giriş ekranı (çıkış sonrası)');
    await assertTurkish(page, '#auth-screen', 'Username', 'giriş formu etiketi (EN)');
    await shot(page, 'i18n-9-giris-en');

    await assertNoTurkish(page, '#auth-screen', 'giriş ekranı (EN, yeniden)');
    await waitAuthReady(page);
    const beforeEn = await snapshotToasts(page);
    await tryLogin(page, 'Yanlis123!sifre');
    const enToast = await waitForNewToast(page, beforeEn);
    check(enToast.includes('Username or password is incorrect.'),
      'EN: sunucu hata mesajı İngilizceye çevrildi — gelen: ' + enToast.slice(0, 70));
    check(!TR.test(enToast), 'hata bildiriminde Türkçe karakter yok');
    await shot(page, 'i18n-10-sunucu-hatasi-en');

    // ---------------------------------------------------------------- F) geri dönüş
    console.log('== F) Türkçeye geri dönüş (kalıcı hasar yok) ==');
    await sleep(3600);
    await tryLogin(page, PASSWORD);
    await page.waitForSelector('#app-shell:not([hidden])', { timeout: 25000 }).catch(() => {});
    await sleep(1500);
    check(await page.evaluate(() => document.documentElement.lang) === 'en', 'oturum açılınca English korundu');
    check(await openSettings(page) === 'settings', 'ayarlar yeniden açıldı');
    check(await setLanguage(page, 'tr'), 'dil Türkçeye geri alındı');
    await assertTurkish(page, '#app-view', 'Görünüm, dil ve gizlilik', 'ayarlar açıklaması yeniden Türkçe');
    await assertTurkish(page, '#header-subtitle', 'Hesap ve gizlilik', 'ayarlar başlığı yeniden Türkçe');
    await shot(page, 'i18n-11-ayarlar-tr-geri');

    // ---------------------------------------------------------------- G/H)
    console.log('== G) Sağlık ==');
    check(pageErrors.length === 0, 'sayfada JS hatası yok' + (pageErrors.length ? ' — ' + pageErrors.slice(0, 3).join(' | ') : ''));
    check(unauthorized >= 2, `yanlış şifre denemeleri sunucuya ulaştı (401 sayısı = ${unauthorized})`);
    const netLeaks = failedRequests.filter((u) => !/favicon/.test(u));
    check(netLeaks.length === 0, 'başarısız ağ isteği yok' + (netLeaks.length ? ' — ' + netLeaks.slice(0, 3).join(' | ') : ''));
  } catch (error) {
    bad('test akışı beklenmedik biçimde durdu: ' + (error && error.message ? error.message : error));
  } finally {
    await browser.close();
    fs.mkdirSync(TDIR, { recursive: true });
    fs.writeFileSync(TDIR + '/browser_counters.txt', `PASS ${pass}\nFAIL ${fail}\n`);
    console.log(`\nTARAYICI SONUCU: ✓${pass} ✗${fail}`);
    process.exit(fail ? 1 : 0);
  }
})();
