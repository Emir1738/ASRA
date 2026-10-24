// ============================================================================
//  ŞİFRE DEĞİŞTİRME — tarayıcı doğrulaması (puppeteer + Chromium)
//
//  Eklenen özellik: Ayarlar > Güvenlik > "Şifre Değiştir" ve "Şifremi unuttum".
//
//  Ölçülenler (gerçek sayfada, gerçek PHP/MySQL sunucusuna karşı):
//    A) Ayarlar ekranında her iki düğme de çiziliyor; "Şifre Değiştir" çift
//       dokunuş kilidinin dışında (data-no-action-lock) — aksi halde modalı
//       kapatıp 3 sn içinde tekrar basan kullanıcının dokunuşu yutulur.
//    B) Modal üç şifre alanı açıyor: güncel, yeni, yeni (tekrar). Güncel
//       alanda minlength YOK (kural sıkılaşmadan önce açılmış hesaplar kendi
//       şifresini girebilmeli), yeni alanlarda minlength=10 var. autocomplete
//       değerleri parola yöneticilerinin doğru davranması için kritik.
//    C) Zayıf/eşleşmeyen/aynı yeni şifre İSTEK ATILMADAN yerinde reddedilir:
//       sunucuya gitmeden hata gösterilmeli, change_password istek sayısı 0
//       kalmalı ve modal açık kalmalı.
//    D) "Şifremi unuttum" yetkiliye yönlendiren mesajı gösterir ve ağ isteği
//       üretmez (platformda sıfırlama ucu yok).
//    E) Sunucu reddi (yanlış güncel şifre) modalın İÇİNDE gösterilir, modal
//       kapanmaz — toast 6 sn'de kaybolur, kullanıcı hatayı okuyabilmeli.
//    F) Doğru bilgiyle şifre gerçekten değişir: modal kapanır, bildirim
//       gösterilir, çıkış yapınca ESKİ şifre reddedilir ve YENİ şifre kabul
//       edilir (uçtan uca kanıt).
//    G) Tüm akış boyunca sayfada JS hatası oluşmaz.
//
//  Çıkış: 0 = hepsi geçti.
// ============================================================================
const fs = require('fs');
const path = require('path');
const puppeteer = require('puppeteer-core');

const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const ORIGIN = process.env.ORIGIN || 'http://127.0.0.1:8095';
const TDIR = process.argv[2] || '/tmp';
const USERNAME = process.env.TEST_USER || 'pwui';
const OLD_PASSWORD = process.env.TEST_PASSWORD || 'Test123!xyz';
const NEW_PASSWORD = process.env.NEW_PASSWORD || 'Arayuz123!xy';

let pass = 0;
let fail = 0;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); };
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/** Koşulu en çok `tries` kez, `gap` ms arayla dener (yarış durumlarını önler). */
async function until(page, fn, tries = 25, gap = 200) {
  for (let i = 0; i < tries; i += 1) {
    const value = await page.evaluate(fn).catch(() => null);
    if (value) return value;
    await sleep(gap);
  }
  return null;
}

async function login(page, password) {
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
  }, USERNAME, password);
  await page.waitForSelector('#app-shell:not([hidden])', { timeout: 20000 }).catch(() => {});
  await sleep(2000);
}

/** Ayarlar ekranına gerçek kullanıcı yoluyla gider (menü > Ayarlar). */
async function openSettings(page) {
  await page.evaluate(() => { if (typeof renderHome === 'function' && state.route !== 'home') renderHome(); });
  await sleep(500);
  await page.evaluate(() => document.querySelector('#menu-button').click());
  await sleep(350);
  await page.evaluate(() => document.querySelector('[data-menu-action="settings"]').click());
  await until(page, () => state.route === 'settings', 20, 200);
  return page.evaluate(() => state.route);
}

/** Metni verilen düğmeyi Ayarlar ekranında bulur ve tıklar. */
async function clickSettingsButton(page, label) {
  return page.evaluate((text) => {
    const buttons = Array.from(document.querySelectorAll('#app-view button'));
    const target = buttons.find((b) => (b.textContent || '').trim().toLowerCase() === text.toLowerCase());
    if (!target) return false;
    target.click();
    return true;
  }, label);
}

/** Modaldeki şifre alanlarının niteliklerini okur. */
const readModalFields = () => {
  const content = document.querySelector('#modal-content');
  if (!content) return null;
  const read = (name) => {
    const input = content.querySelector(`input[name="${name}"]`);
    if (!input) return null;
    return {
      type: input.type,
      required: input.required,
      minLength: input.minLength,
      maxLength: input.maxLength,
      autocomplete: input.getAttribute('autocomplete') || '',
    };
  };
  return {
    visible: !document.querySelector('#modal-backdrop').hidden,
    title: (document.querySelector('#modal-title') || {}).textContent || '',
    text: content.textContent || '',
    current: read('current_password'),
    next: read('password'),
    confirm: read('password_confirm'),
    toggles: content.querySelectorAll('.password-toggle').length,
    notice: (() => {
      const note = content.querySelector('.field-note.danger-text');
      return note && !note.hidden ? (note.textContent || '').trim() : '';
    })(),
    form: !!content.querySelector('form'),
  };
};

const fillForm = (current, next, confirm) => {
  const content = document.querySelector('#modal-content');
  if (!content) return false;
  const set = (name, value) => {
    const input = content.querySelector(`input[name="${name}"]`);
    if (!input) return;
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
  };
  set('current_password', current);
  set('password', next);
  set('password_confirm', confirm);
  const form = content.querySelector('form');
  if (!form) return false;
  form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
  return true;
};

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
  await login(page, OLD_PASSWORD);
  check((await page.evaluate(() => state.route)) === 'home', `oturum açıldı (${USERNAME}), ana ekranda`);

  const countChanges = () => apiRequests.filter((a) => a === 'change_password').length;

  // ==========================================================================
  // A) Ayarlar ekranı: Güvenlik paneli ve iki düğme
  // ==========================================================================
  check((await openSettings(page)) === 'settings', 'menüden Ayarlar ekranına gidildi');

  const settingsView = await page.evaluate(() => {
    const buttons = Array.from(document.querySelectorAll('#app-view button'));
    const find = (text) => buttons.find((b) => (b.textContent || '').trim().toLowerCase() === text.toLowerCase()) || null;
    const change = find('Şifre Değiştir');
    const forgot = find('Şifremi unuttum');
    return {
      headings: Array.from(document.querySelectorAll('#app-view h2')).map((h) => (h.textContent || '').trim()),
      changeFound: !!change,
      changeClass: change ? change.className : '',
      changeExempt: change ? change.hasAttribute('data-no-action-lock') : false,
      forgotFound: !!forgot,
    };
  });
  check(settingsView.changeFound, '"Şifre Değiştir" düğmesi Ayarlar ekranında çizildi');
  check(settingsView.forgotFound, '"Şifremi unuttum" düğmesi Ayarlar ekranında çizildi');
  check(settingsView.headings.some((h) => h === 'Güvenlik'), 'Güvenlik paneli başlığı var');
  check(settingsView.changeExempt,
    '"Şifre Değiştir" çift dokunuş kilidinin dışında (data-no-action-lock) — modal kapatılıp hemen tekrar basılabilir');

  // Kilit gerçekten devre dışı mı: modalı aç, kapat, 3 sn DOLMADAN tekrar aç.
  await clickSettingsButton(page, 'Şifre Değiştir');
  check(Boolean(await until(page, () => !document.querySelector('#modal-backdrop').hidden)),
    'düğmeye basınca modal açıldı');
  await page.evaluate(() => document.querySelector('#modal-close').click());
  await sleep(250);
  await clickSettingsButton(page, 'Şifre Değiştir');
  check(Boolean(await until(page, () => !document.querySelector('#modal-backdrop').hidden, 8, 150)),
    'modal kapatılıp hemen (3 sn dolmadan) tekrar açıldı — dokunuş yutulmadı');

  // ==========================================================================
  // B) Modal alanları
  // ==========================================================================
  let fields = await page.evaluate(readModalFields);
  check(!!fields && fields.visible, 'şifre değiştirme modalı görünür');
  check(!!fields && fields.current !== null, 'güncel şifre alanı var (current_password)');
  check(!!fields && fields.next !== null, 'yeni şifre alanı var (password)');
  check(!!fields && fields.confirm !== null, 'yeni şifre TEKRAR alanı var (password_confirm)');
  check(!!fields && fields.current && fields.current.type === 'password', 'güncel şifre alanı type=password');
  check(!!fields && fields.next && fields.next.type === 'password', 'yeni şifre alanı type=password');
  check(!!fields && fields.confirm && fields.confirm.type === 'password', 'tekrar alanı type=password');
  check(!!fields && fields.toggles === 3, `üç alanda da Göster/Gizle düğmesi var (bulunan: ${fields ? fields.toggles : 0})`);
  check(!!fields && fields.current && fields.current.autocomplete === 'current-password',
    'güncel alan autocomplete=current-password (parola yöneticisi doğru alanı tanır)');
  check(!!fields && fields.next && fields.next.autocomplete === 'new-password',
    'yeni alan autocomplete=new-password');
  check(!!fields && fields.confirm && fields.confirm.autocomplete === 'new-password',
    'tekrar alanı autocomplete=new-password');
  check(!!fields && fields.current && fields.current.minLength === -1,
    'güncel şifrede minlength YOK — eski kuralla açılmış hesap kendi şifresini girebilir');
  check(!!fields && fields.next && fields.next.minLength === 10, 'yeni şifrede minlength=10');
  check(!!fields && fields.confirm && fields.confirm.maxLength === 128, 'tekrar alanı maxlength=128');

  // Göster/Gizle düğmesi gerçekten çalışıyor mu?
  const toggled = await page.evaluate(() => {
    const content = document.querySelector('#modal-content');
    const toggle = content.querySelector('.password-toggle');
    const input = content.querySelector('input[name="current_password"]');
    const before = input.type;
    toggle.click();
    const after = input.type;
    const label = (toggle.textContent || '').trim();
    toggle.click();
    return { before, after, label, restored: input.type };
  });
  check(toggled.before === 'password' && toggled.after === 'text', 'Göster düğmesi şifreyi görünür yapıyor');
  check(toggled.label === 'Gizle', 'düğme etiketi "Gizle" oldu');
  check(toggled.restored === 'password', 'tekrar basınca şifre yeniden maskelendi');

  // ==========================================================================
  // C) İstemci tarafı kural denetimi: HİÇ istek atılmamalı
  // ==========================================================================
  const beforeRules = countChanges();
  await page.evaluate(fillForm, OLD_PASSWORD, 'kisa1!a', 'kisa1!a');
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && fields.visible, 'kısa şifrede modal AÇIK KALDI (kullanıcı düzeltebilsin)');
  check(!!fields && /10 ile 128 karakter/.test(fields.notice), `kısa şifre yerinde reddedildi: "${fields ? fields.notice : ''}"`);
  check(countChanges() === beforeRules, `kısa şifre için sunucuya istek GİTMEDİ (change_password=${countChanges() - beforeRules})`);

  await page.evaluate(fillForm, OLD_PASSWORD, NEW_PASSWORD, 'Farkli123!ab');
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && /eşleşmiyor/.test(fields.notice), `eşleşmeyen tekrar yerinde reddedildi: "${fields ? fields.notice : ''}"`);
  check(countChanges() === beforeRules, 'eşleşmeyen tekrar için istek gitmedi');

  await page.evaluate(fillForm, OLD_PASSWORD, 'bosluk var 1!', 'bosluk var 1!');
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && /boşluk içeremez/.test(fields.notice), `boşluklu şifre yerinde reddedildi: "${fields ? fields.notice : ''}"`);

  // Kural dallarının HER BİRİ ayrı ölçülür. Değerler gerçekten ilgili kuralı
  // ihlal etmeli: 'Harfsiz12345!' gibi harf içeren bir değer kuralları GEÇER,
  // sunucuya gider ve şifreyi değiştirip sonraki tüm doğrulamaları bozardı.
  await page.evaluate(fillForm, OLD_PASSWORD, 'Ozelsiz1234abc', 'Ozelsiz1234abc');
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && /özel karakter/.test(fields.notice), `özel karaktersiz şifre yerinde reddedildi: "${fields ? fields.notice : ''}"`);

  await page.evaluate(fillForm, OLD_PASSWORD, 'Rakamsiz!abcDEF', 'Rakamsiz!abcDEF');
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && /bir rakam/.test(fields.notice), `rakamsız şifre yerinde reddedildi: "${fields ? fields.notice : ''}"`);

  await page.evaluate(fillForm, OLD_PASSWORD, '12345!6789', '12345!6789');
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && /en az bir harf/.test(fields.notice), `harfsiz şifre yerinde reddedildi: "${fields ? fields.notice : ''}"`);

  await page.evaluate(fillForm, OLD_PASSWORD, OLD_PASSWORD, OLD_PASSWORD);
  await sleep(500);
  fields = await page.evaluate(readModalFields);
  check(!!fields && /farklı olmalıdır/.test(fields.notice), `yeni = güncel şifre yerinde reddedildi: "${fields ? fields.notice : ''}"`);
  check(countChanges() === beforeRules,
    `tüm kural ihlalleri boyunca SUNUCUYA HİÇ İSTEK GİTMEDİ (toplam ${countChanges() - beforeRules})`);

  // ==========================================================================
  // D) "Şifremi unuttum" -> yetkili mesajı, ağ isteği yok
  // ==========================================================================
  await page.evaluate(() => document.querySelector('#modal-close').click());
  await sleep(400);
  const requestsBeforeForgot = apiRequests.length;
  await clickSettingsButton(page, 'Şifremi unuttum');
  const forgot = await until(page, () => {
    if (document.querySelector('#modal-backdrop').hidden) return null;
    const text = (document.querySelector('#modal-content') || {}).textContent || '';
    return /yetkili/i.test(text) ? text : null;
  }, 15, 200);
  const forgotText = String(forgot || '');
  check(!!forgot, '"Şifremi unuttum" modalı yetkili mesajını gösterdi');
  check(/yetkili/i.test(forgotText) && /iletişime geç/i.test(forgotText),
    'mesaj yetkiliyle iletişime geçme yönlendirmesini içeriyor');

  // METİN TONU: platformu eksik/kusurlu göstermemeli ve yetkilinin iç
  // işleyişini (hangi panel, hangi düğme) kullanıcıya anlatmamalı. Bu iki
  // doğrulama bilerek OLUMSUZ yazıldı: metni biri kısaltıp "bu platformda
  // e-posta doğrulaması yok" gibi bir itirafa çevirirse test yakalar.
  check(!/e-?posta/i.test(forgotText),
    'mesaj olmayan bir e-posta akışından söz edip platformu eksik göstermiyor');
  check(!/(sıfırlayamaz|kendi başına (?:sıfırla|yapamaz)|yapamazsın|edemezsin|desteklenmi|imkâ?nsız)/i.test(forgotText),
    'mesaj "yapamazsın/edemezsin" diliyle platformu kötülemiyor');
  check(!/admin paneli|paneline gir|yanındaki .*seçeneği/i.test(forgotText),
    'mesaj yetkilinin iç işleyişini (Admin Paneli vb.) kullanıcıya anlatmıyor');

  // İÇERİK: ne yapacağı + hesabının güvende olduğu + sonrasında ne olacağı.
  check(/(hesabın|verilerin)/i.test(forgotText) && /(silinmedi|yerinde|güvende)/i.test(forgotText),
    'mesaj önce içini rahatlatıyor: hesap ve veriler duruyor');
  check(/kullanıcı ad/i.test(forgotText),
    'mesaj iletişime geçerken ne belirtmesi gerektiğini söylüyor');
  check(/Ayarlar/i.test(forgotText) && /Güvenlik/i.test(forgotText),
    'mesaj sonrasında şifresini kendi değiştirebileceği yolu gösteriyor');

  // UZUNLUK: tek cümlelik baştan savma bir yönlendirme olmamalı.
  const forgotShape = await page.evaluate(() => {
    const paragraphs = Array.from(document.querySelectorAll('#modal-content .modal-copy'));
    return { count: paragraphs.length, chars: paragraphs.map((p) => (p.textContent || '').trim()).join(' ').length };
  });
  check(forgotShape.count >= 4 && forgotShape.chars >= 400,
    `mesaj yeterince açıklayıcı (${forgotShape.count} paragraf, ${forgotShape.chars} karakter)`);
  check(apiRequests.length === requestsBeforeForgot,
    `"Şifremi unuttum" ağ isteği üretmedi (${apiRequests.length - requestsBeforeForgot} istek)`);
  await page.evaluate(() => document.querySelector('#modal-close').click());
  await sleep(400);

  // ==========================================================================
  // E) Sunucu reddi modalın içinde gösterilir
  // ==========================================================================
  await clickSettingsButton(page, 'Şifre Değiştir');
  await until(page, () => !document.querySelector('#modal-backdrop').hidden, 15, 200);
  const beforeWrong = countChanges();
  await page.evaluate(fillForm, 'Yanlis123!abc', NEW_PASSWORD, NEW_PASSWORD);
  const serverReject = await until(page, () => {
    const note = document.querySelector('#modal-content .field-note.danger-text');
    return note && !note.hidden ? (note.textContent || '').trim() : null;
  }, 30, 200);
  check(countChanges() === beforeWrong + 1, 'yanlış güncel şifre sunucuya gönderildi (1 istek)');
  check(/Güncel şifre hatalı/.test(String(serverReject || '')),
    `sunucu reddi modalın İÇİNDE gösterildi: "${serverReject || ''}"`);
  check(await page.evaluate(() => !document.querySelector('#modal-backdrop').hidden),
    'sunucu reddinde modal kapanmadı (kullanıcı tekrar deneyebilir)');

  // ==========================================================================
  // F) Başarılı değişim + uçtan uca giriş kanıtı
  // ==========================================================================
  await page.evaluate(fillForm, OLD_PASSWORD, NEW_PASSWORD, NEW_PASSWORD);
  const toastText = await until(page, () => {
    const t = document.querySelector('#toast-region .toast');
    return t ? (t.textContent || '').trim() : null;
  }, 30, 200);
  check(/Şifren değiştirildi/i.test(String(toastText || '')), `başarı bildirimi gösterildi: "${toastText || ''}"`);
  check(Boolean(await until(page, () => document.querySelector('#modal-backdrop').hidden === true, 20, 200)),
    'başarılı değişimde modal kapandı');
  check(await page.evaluate(() => !document.querySelector('#modal-content').querySelector('input[name="password"]')),
    'modal içeriği temizlendi — şifreler DOM\'da bırakılmadı');

  // Bu cihazın oturumu korunmuş olmalı: kullanıcı işlemin ortasında atılmamalı.
  await sleep(800);
  check(await page.evaluate(() => Boolean(state.user && state.user.username)),
    'değişimden sonra bu cihazda oturum açık kaldı');
  check((await openSettings(page)) === 'settings', 'değişimden sonra Ayarlar ekranına tekrar gidilebildi');

  // Uçtan uca kanıt: çıkış yap, ESKİ şifre reddedilmeli, YENİ şifre kabul edilmeli.
  await page.evaluate(() => { if (typeof renderHome === 'function') renderHome(); });
  await sleep(400);
  await page.evaluate(() => document.querySelector('#menu-button').click());
  await sleep(300);
  await page.evaluate(() => document.querySelector('[data-menu-action="logout"]').click());
  await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
  await sleep(600);
  check(await page.evaluate(() => Boolean(document.querySelector('#login-username'))),
    'çıkış yapıldı, giriş ekranına dönüldü');

  // ESKİ şifre reddedilmeli: uygulama kabuğu gizli kalmalı ve giriş ekranı
  // görünmeli. state.route'a bakılmaz — oturumdan düştükten sonra bayat
  // kalabilir, ölçülen şey kullanıcının gerçekten içeri alınıp alınmadığıdır.
  await login(page, OLD_PASSWORD);
  await sleep(1200);
  const oldRejected = await page.evaluate(() => ({
    shellHidden: document.querySelector('#app-shell').hidden,
    loginVisible: Boolean(document.querySelector('#login-password')),
    toast: ((document.querySelector('#toast-region .toast') || {}).textContent || '').trim(),
  }));
  check(oldRejected.shellHidden && oldRejected.loginVisible,
    `ESKİ şifre artık kabul edilmiyor — uygulama giriş ekranında kaldı (bildirim='${oldRejected.toast}')`);

  await login(page, NEW_PASSWORD);
  const shellUp = await until(page, () => (document.querySelector('#app-shell').hidden === false
    ? String((typeof state === 'object' && state ? state.route : '') || '') : null), 30, 300);
  check(shellUp === 'home', `YENİ şifreyle giriş başarılı (route='${shellUp || ''}') — şifre gerçekten değişti`);

  check(pageErrors.length === 0,
    pageErrors.length === 0 ? 'akış boyunca sayfada JS hatası oluşmadı' : `JS hataları: ${pageErrors.slice(0, 3).join(' | ')}`);

  await browser.close();
  fs.mkdirSync(TDIR, { recursive: true });
  fs.writeFileSync(path.join(TDIR, 'browser_counters.txt'), 'PASS ' + pass + '\nFAIL ' + fail + '\n');
  console.log('================ SONUÇ ================');
  console.log('SONUÇ: ✓' + pass + ' ✗' + fail);
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
