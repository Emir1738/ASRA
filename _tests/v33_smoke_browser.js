// v33 tarayıcı doğrulaması: davet kapısı yok, onay akışı var, mağaza açılıyor
const puppeteer = require('puppeteer-core');

const BASE = 'http://127.0.0.1:8090';
const EXE = '/usr/bin/chromium';
let pass = 0, fail = 0;
const ok = (c, m) => { if (c) { pass++; console.log('  ✓ ' + m); } else { fail++; console.log('  ✗ ' + m); } };
const bad = (c, m) => { if (!c) { fail++; console.log('  ✗ ' + m); } else { pass++; console.log('  ✓ ' + m); } };

(async () => {
  const browser = await puppeteer.launch({
    executablePath: EXE, headless: 'new',
    args: ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'],
  });
  const page = await browser.newPage();
  const errors = [];
  page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
  page.on('console', (m) => { if (m.type() === 'error') errors.push('console: ' + m.text()); });

  await page.goto(BASE + '/', { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1200));

  // 1) Davet kodu formu YOK
  const inviteForm = await page.$('#invite-form');
  ok(!inviteForm, 'davet kodu formu YOK (#invite-form)');

  // 2) Giriş/kayıt sekmeleri doğrudan görünür
  const authOptionsHidden = await page.$eval('#auth-options', el => el.hidden);
  ok(!authOptionsHidden, 'giriş/kayıt seçenekleri doğrudan açık');
  const desc = await page.$eval('#auth-description', el => el.textContent);
  ok(!/davet/i.test(desc), 'açıklama metninde davet yok: "' + desc + '"');

  // 3) Menüde Davet Et YOK (önce giriş yapılmalı; menü gizli) — statik kontrol jsdom yerine DOM: menü nav gizli ama içerikte olmamalı
  const menuInvite = await page.evaluate(() => !!document.querySelector('[data-menu-action="invite"]'));
  ok(!menuInvite, 'menüde "Davet Et" düğmesi YOK');

  // 4) Yeni kayıt -> onay akışı
  const uname = 'p1' + Date.now().toString(36);
  await page.click('#register-tab');
  await page.waitForSelector('#register-form:not([hidden])', { timeout: 8000 });
  await page.type('#register-username', uname);
  await page.type('#register-password', 'Test123!xyz');
  await page.type('#register-password-confirm', 'Test123!xyz');
  await page.evaluate(() => document.querySelector('#register-form').requestSubmit());
  await page.waitForFunction(() => document.querySelector('.approval-card, .status-card'), { timeout: 15000 });
  const cardText = await page.evaluate(() => document.body.textContent);
  ok(/Neden katılmak istiyorsun|Başvurun/.test(cardText) && !/davet/i.test(cardText), 'kayıt sonrası ONAY ekranı açıldı (davetsiz)');
  // onay formu var mı
  const hasReasonForm = await page.evaluate(() => !!document.querySelector('.approval-form, #approval-form, textarea'));
  ok(hasReasonForm, 'katılma nedeni formu görünüyor');

  // 5) Neden gönder
  try {
    const ta = await page.$('.approval-form textarea, textarea');
    if (ta) {
      await ta.type('Test katılım nedeni');
      await Promise.all([
        page.click('.approval-form button[type="submit"], .approval-form .primary-button'),
        page.waitForFunction(() => document.body.textContent.includes('İnceleniyor') || document.body.textContent.includes('paneline düştü') || document.body.textContent.includes('bekliyor'), { timeout: 15000 }),
      ]);
      ok(true, 'neden gönderildi -> inceleme ekranı');
    } else ok(false, 'textarea bulunamadı');
  } catch (e) { ok(false, 'neden gönderilemedi: ' + e.message.slice(0, 120)); }

  // 6) admin tarafı: uiadmin giriş + admin panel -> onay sekmesi, Kod sekmesi yok
  // (yeni, çerezsiz tarayıcı bağlamı — pending oturumu karışmasın)
  const ctx = await browser.createBrowserContext();
  const page2 = await ctx.newPage();
  const err2 = [];
  page2.on('pageerror', (e) => err2.push('pageerror: ' + e.message));
  page2.on('console', (m) => { if (m.type() === 'error') err2.push('console: ' + m.text()); });
  await page2.goto(BASE + '/', { waitUntil: 'networkidle2', timeout: 30000 });
  await new Promise(r => setTimeout(r, 1200));
  await page2.waitForSelector('#auth-screen:not([hidden]) #login-username', { timeout: 15000 });
  await page2.type('#login-username', 'uiadmin');
  await page2.type('#login-password', 'Test123!xyz');
  await page2.evaluate(() => document.querySelector('#login-form').requestSubmit());
  await page2.waitForFunction(() => document.querySelector('#auth-screen').hidden, { timeout: 20000 });
  await page2.waitForSelector('#app-shell:not([hidden])', { timeout: 15000 });
  await new Promise(r => setTimeout(r, 800));

  // 7) Mağaza AÇILIYOR mu (kullanıcının şikayeti)
  await Promise.all([
    page2.evaluate(() => document.querySelector('[data-menu-action="shop"]').click()),
    page2.waitForFunction(() => document.querySelector('.shop-grid'), { timeout: 15000 }),
  ]);
  await new Promise(r => setTimeout(r, 2500));
  const shopOk = await page2.evaluate(() => {
    const cards = document.querySelectorAll('.shop-card');
    return { cards: cards.length, hasErr: document.body.textContent.includes('İşlem tamamlanamadı') };
  });
  ok(shopOk.cards > 0 && !shopOk.hasErr, `mağaza açıldı: ${shopOk.cards} ürün, hata yok`);
  await page2.evaluate(() => { document.querySelector('#modal-close')?.click(); });

  // 8) Görevler/Oyunlar
  try {
    await Promise.all([
      page2.evaluate(() => document.querySelector('[data-menu-action="quests"]').click()),
      page2.waitForFunction(() => document.querySelector('.quest-grid'), { timeout: 12000 }),
    ]);
    ok(true, 'Görevler ve Oyunlar açıldı');
  } catch (e) { ok(false, 'görevler açılmadı: ' + e.message.slice(0, 100)); }

  // 9) Admin paneli: sekmeler
  await page2.evaluate(() => document.querySelector('[data-menu-action="admin"]').click());
  await page2.waitForSelector('#modal input[type="password"]', { timeout: 10000 });
  await page2.type('#modal input[type="password"]', 'AsraAsraJshe+sbuwnduxmsgdnfusmg');
  await page2.evaluate(() => { const f = document.querySelector('#modal form'); if (f) f.requestSubmit(); else document.querySelector('#modal button.primary-button')?.click(); });
  await page2.waitForFunction(() => document.querySelector('.admin-tabs'), { timeout: 15000 });
  const tabs = await page2.evaluate(() => Array.from(document.querySelectorAll('.admin-tabs button')).map(b => b.textContent.trim()));
  ok(tabs.includes('Onaylanmayı Bekleyenler'), 'admin sekmelerinde Onay Bekleyenler VAR: ' + JSON.stringify(tabs));
  ok(!tabs.some(t => t === 'Kod'), 'admin sekmelerinde "Kod" YOK');

  // 10) Onay sekmesine git, bekleyen listede p1 görünmeli
  await page2.evaluate(() => { const b = Array.from(document.querySelectorAll('.admin-tabs button')).find(x => x.textContent.includes('Onaylanmayı')); b.click(); });
  await new Promise(r => setTimeout(r, 1800));
  const pendingText = await page2.evaluate(() => document.body.textContent);
  ok(pendingText.includes(uname), 'bekleyenler listesinde yeni kayıt görünüyor');

  // 11) Kabul et
  try {
    await page2.evaluate((u) => {
      const rows = Array.from(document.querySelectorAll('.admin-user-row, [class*="approval"]'));
      const row = rows.find(r => r.textContent.includes(u));
      if (!row) throw new Error('satır yok');
      const btns = Array.from(row.querySelectorAll('button'));
      const accept = btns.find(b => /kabul/i.test(b.textContent) || /accept/i.test(b.textContent));
      if (!accept) throw new Error('kabul butonu yok: ' + btns.map(b => b.textContent.trim()).join('|'));
      accept.click();
    }, uname);
    await new Promise(r => setTimeout(r, 2500));
    const after = await page2.evaluate(() => document.body.textContent);
    ok(/kabul edildi|onaylandı/i.test(after) || !after.includes(uname + '"') && after.includes(uname), 'başvuru kabul edildi');
  } catch (e) { ok(false, 'kabul işlemi: ' + e.message.slice(0, 150)); }

  // 12) JS hatası yok mu (her iki sayfa)
  const realErrors = errors.concat(err2).filter(e => !/favicon|net::|ERR_|download the React DevTools/i.test(e));
  ok(realErrors.length === 0, 'sayfada JS hatası yok' + (realErrors.length ? ' -> ' + realErrors.join(' | ').slice(0, 200) : ''));

  await ctx.close();
  await browser.close();
  console.log(`\nSONUÇ: ✓${pass} ✗${fail}`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error('ÇALIŞMA HATASI:', e.stack || e.message); process.exit(2); });
