// ============================================================================
//  ONAY BEKLEYENLERİN GÖRÜNÜRLÜĞÜ — tarayıcı doğrulaması (puppeteer + Chromium)
//
//  Düzeltilen kök neden: admin_pending_approvals sorgusu
//  "AND approval_submitted_at IS NOT NULL" taşıyordu. Yani katılma nedenini
//  henüz yazmamış bekleyen hesaplar (yeni kaydolan ama ekranı kapatan, eski
//  istemci/APK'dan gelen, geçmişten kalan) moderatör panelinde HİÇ
//  görünmüyordu — "yeni kullanıcılar yetkili paneline düşmüyor" şikâyeti.
//
//  Ölçülenler (gerçek sayfada, gerçek PHP/MySQL sunucusuna karşı):
//    A) Yönetim şifresiyle admin paneli açılır ve "Onaylanmayı Bekleyenler"
//       sekmesi liste getirir.
//    B) Nedeni YAZILMIŞ bekleyen hesap, nedeni ve gönderim zamanıyla görünür.
//    C) Nedeni HİÇ yazılmamış bekleyen hesap da görünür; satırında kayıt
//       tarihi ve "katılma nedeni henüz yazılmadı" notu vardır (boş bırakılmaz).
//    D) Gönderilmiş başvurular listede ÖNDE durur (moderatörün önceliği).
//    E) Akış boyunca sayfada JS hatası oluşmaz.
//
//  Çıkış: 0 = hepsi geçti.
// ============================================================================
const fs = require('fs');
const puppeteer = require('puppeteer-core');

const CHROME = process.env.CHROME_PATH || '/usr/bin/chromium';
const ORIGIN = process.env.ORIGIN || 'http://127.0.0.1:8102';
const TDIR = process.argv[2] || '/tmp';
const USERNAME = process.env.TEST_USER || 'uiadmin';
const PASSWORD = process.env.TEST_PASSWORD || 'Test123!xyz';
const ADMIN_PASSWORD = process.env.ADMIN_PASSWORD || 'AsraAsraJshe+sbuwnduxmsgdnfusmg';
const PENDING_SUBMITTED = process.env.PENDING_SUBMITTED || 'apprA';
const PENDING_PLAIN = process.env.PENDING_PLAIN || 'apprD';
const PLAIN_REASON = process.env.PLAIN_REASON || '';

let pass = 0;
let fail = 0;
const ok = (m) => { pass += 1; console.log('  ✓ ' + m); };
const bad = (m) => { fail += 1; console.log('  ✗ ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Sayfayı yükler; sunucu hız sınırlamasına takıldıysa (SERVICE_BUSY, 503)
 * retry_after kadar bekleyip yeniden dener. Bu, testin ortam kaynaklı
 * (ardışık koşulardan kalan kilit) düşmesini engeller — kod hatası değil.
 */
async function loadApp(page, tries = 8) {
  for (let i = 0; i < tries; i += 1) {
    // eslint-disable-next-line no-await-in-loop
    await page.goto(ORIGIN + '/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    // eslint-disable-next-line no-await-in-loop
    await sleep(900);
    // eslint-disable-next-line no-await-in-loop
    // Not: body.textContent sayfadaki <script> metnini de içerir ve istemci
    // kodunda 'SERVICE_BUSY' dizgesi geçer; bu yüzden yalnızca uygulamanın
    // kendi kabuğu yok VE sunucunun ham JSON <pre> çıktısı görünüyorsa meşgul say.
    const busy = await page.evaluate(() => {
      if (document.querySelector('#login-form') || document.querySelector('#app-shell')) return false;
      const pre = document.body ? document.body.querySelector('pre') : null;
      return Boolean(pre && /SERVICE_BUSY/.test(pre.textContent || ''));
    }).catch(() => false);
    if (!busy) return true;
    // eslint-disable-next-line no-await-in-loop
    await sleep(3600);
  }
  return false;
}

/** Node tarafındaki bir koşul (ör. istek sayacı) için bekleme döngüsü.
 *  until() fonksiyonu SAYFADA çalıştırılır; bu yüzden Node değişkenlerini
 *  göremez. Ağ olay sayaçları burada beklenir. */
async function waitFor(pred, tries = 40, step = 250) {
  for (let i = 0; i < tries; i += 1) {
    if (pred()) return true;
    // eslint-disable-next-line no-await-in-loop
    await sleep(step);
  }
  return pred();
}

async function until(page, fn, arg, tries = 40, step = 250) {
  for (let i = 0; i < tries; i += 1) {
    // eslint-disable-next-line no-await-in-loop
    const done = await page.evaluate(fn, arg).catch(() => false);
    if (done) return true;
    // eslint-disable-next-line no-await-in-loop
    await sleep(step);
  }
  return false;
}

(async () => {
  const browser = await puppeteer.launch({
    executablePath: CHROME,
    headless: 'new',
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage']
  });
  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 950 });
  // Statik index.html tarayıcı disk önbelleğinden gelirse test ESKİ istemciyi
  // ölçer (yanıltıcı sonuç). Önbellek kapalı: her koşulda güncel dosya yüklenir.
  await page.setCacheEnabled(false);
  const pageErrors = [];
  page.on('pageerror', (e) => pageErrors.push(String(e && e.message ? e.message : e)));
  page.on('console', (m) => {
    if (m.type() === 'error' && !/Failed to load resource/i.test(m.text())) pageErrors.push('console: ' + m.text());
  });

  try {
    console.log('== A) Admin paneli gerçek kullanıcı yoluyla açılır ==');
    check(await loadApp(page), 'sayfa yüklendi (sunucu hız sınırı yok)');
    await page.waitForSelector('#login-username', { timeout: 20000 }).catch(() => {});
    await sleep(1000);
    const loginReady = await page.evaluate(() => Boolean(document.querySelector('#login-username') && document.querySelector('#login-password')));
    check(loginReady, 'giriş ekranı hazır');
    if (!loginReady) throw new Error('giriş ekranı gelmedi: ' + await page.evaluate(() => (document.body.textContent || '').slice(0, 160)).catch(() => '?'));
    await page.evaluate((u, p) => {
      document.querySelector('#login-username').value = u;
      document.querySelector('#login-password').value = p;
      ['#login-username', '#login-password'].forEach((s) => document.querySelector(s).dispatchEvent(new Event('input', { bubbles: true })));
      document.querySelector('#login-form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
    }, USERNAME, PASSWORD);
    await page.waitForSelector('#app-shell:not([hidden])', { timeout: 25000 }).catch(() => {});
    await sleep(1500);
    check(await page.evaluate(() => {
      const shell = document.querySelector('#app-shell');
      return Boolean(shell && !shell.hidden);
    }), 'yetkili oturumu açık');

    await page.evaluate(() => document.querySelector('#menu-button').click());
    await sleep(400);
    await page.evaluate(() => document.querySelector('[data-menu-action="admin"]').click());
    await sleep(600);
    const modalUp = await page.evaluate(() => {
      const m = document.querySelector('#modal');
      return Boolean(m && !m.hidden && m.querySelector('input[name="password"]'));
    });
    check(modalUp, 'yönetim şifresi modalı açıldı');
    const sent = await page.evaluate((pw) => {
      const form = document.querySelector('#modal form');
      const input = form && form.querySelector('input[name="password"]');
      if (!input) return false;
      input.value = pw;
      input.dispatchEvent(new Event('input', { bubbles: true }));
      form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
      return true;
    }, ADMIN_PASSWORD);
    check(sent, 'yönetim şifresi gönderildi');
    check(await until(page, () => state.route === 'admin' && state.adminActive === true, null, 40, 250),
      'admin paneli çizildi (state.route=admin)');

    console.log('== B/C/D) Bekleyenler listesi ==');
    const tabClicked = await page.evaluate(() => {
      const target = Array.from(document.querySelectorAll('#app-view button'))
        .find((b) => (b.textContent || '').trim() === 'Onaylanmayı Bekleyenler');
      if (!target) return false;
      target.click();
      return true;
    });
    check(tabClicked, '"Onaylanmayı Bekleyenler" sekmesi tıklandı');
    check(await until(page, (names) => {
      const rows = Array.from(document.querySelectorAll('#app-view .admin-user-row'));
      const texts = rows.map((r) => (r.textContent || ''));
      return names.every((n) => texts.some((t) => t.includes(n)));
    }, [PENDING_SUBMITTED, PENDING_PLAIN], 40, 250),
      `nedeni yazılmış (${PENDING_SUBMITTED}) VE yazılmamış (${PENDING_PLAIN}) hesaplar listede`);

    const info = await page.evaluate((names) => {
      const rows = Array.from(document.querySelectorAll('#app-view .admin-user-row'));
      const find = (n) => rows.findIndex((r) => (r.textContent || '').includes(n));
      const rowText = (n) => {
        const i = find(n);
        return i >= 0 ? (rows[i].textContent || '').replace(/\s+/g, ' ').trim() : '';
      };
      return {
        submittedIndex: find(names[0]),
        plainIndex: find(names[1]),
        plainText: rowText(names[1]),
        submittedText: rowText(names[0])
      };
    }, [PENDING_SUBMITTED, PENDING_PLAIN]);

    check(info.plainText.includes('katılma nedeni henüz yazılmadı'),
      'nedeni yazılmamış satırda dürüst not var — gelen: ' + info.plainText.slice(0, 120));
    check(/Kayıt:/.test(info.plainText), 'nedeni yazılmamış satırda kayıt tarihi var');
    check(info.submittedIndex >= 0 && !info.submittedText.includes('henüz yazılmadı'),
      'nedeni yazılmış satır kendi nedeni ve gönderim zamanıyla duruyor');
    check(info.submittedIndex >= 0 && info.plainIndex >= 0 && info.submittedIndex < info.plainIndex,
      `gönderilmiş başvuru önde (sıra: ${info.submittedIndex} < ${info.plainIndex})`);

    console.log('== F) "Herkesi Gör / Yenile" düğmesi ve sayaç ==');
    const findBtn = () => {
      const b = Array.from(document.querySelectorAll('#app-view button'))
        .find((x) => (x.textContent || '').trim() === 'Herkesi Gör / Yenile');
      if (b) b.click();
      return Boolean(b);
    };
    const btnState = () => {
      const b = Array.from(document.querySelectorAll('#app-view button'))
        .find((x) => /Herkesi Gör|Yenileniyor/.test((x.textContent || '').trim()));
      return b ? JSON.stringify({ metin: (b.textContent || '').trim(), disabled: b.disabled }) : 'YOK';
    };
    const stampOf = (prev) => {
      const el = document.querySelector('#app-view .admin-user-list');
      return Boolean(el && el.dataset.refreshedAt && el.dataset.refreshedAt !== prev);
    };
    const stampNow = () => {
      const el = document.querySelector('#app-view .admin-user-list');
      return (el && el.dataset.refreshedAt) || '';
    };
    // Düğme "Yenileniyor…" iken tıklanmaz: önce boşta kalması beklenir.
    const waitIdle = () => until(page, () => {
      const b = Array.from(document.querySelectorAll('#app-view button'))
        .find((x) => /Herkesi Gör|Yenileniyor/.test((x.textContent || '').trim()));
      return Boolean(b && (b.textContent || '').trim() === 'Herkesi Gör / Yenile');
    }, null, 80, 250);
    const clickRefresh = async () => (await waitIdle()) && (await page.evaluate(findBtn));

    check(await page.evaluate(() => Array.from(document.querySelectorAll('#app-view button'))
      .some((b) => (b.textContent || '').trim() === 'Herkesi Gör / Yenile')),
      '"Herkesi Gör / Yenile" düğmesi sekmede var');
    check(await until(page, () => /bekleyen hesap/.test((document.querySelector('#app-view .approval-status') || {}).textContent || ''), null, 60, 250),
      'sayaç satırı doldu');
    const counts = await page.evaluate(() => {
      const el = document.querySelector('#app-view .approval-status');
      return el ? (el.textContent || '').trim() : '';
    });
    check(/2 bekleyen hesap/.test(counts) && /1 nedeni yazılmış/.test(counts) && /1 nedeni henüz yazılmamış/.test(counts),
      'sayaç satırı bekleyenleri sayıyla gösteriyor — gelen: ' + counts);
    check(await page.evaluate(() => {
      const w = document.querySelector('#app-view .approval-warn');
      return Boolean(w && w.hidden);
    }), 'yeni sunucuda eski-sürüm uyarısı gizli');
    const stampBefore = await page.evaluate(stampNow);
    check(await clickRefresh(), 'düğme tıklandı');
    check(await until(page, stampOf, stampBefore, 80, 250), 'düğme listeyi gerçekten tazeledi (yenileme zamanı değişti)');
    check(await until(page, () => document.querySelectorAll('#app-view .admin-user-row').length === 2, null, 60, 250),
      'yenileme sonrasında iki bekleyen de listede');

    console.log('== G) Eski sunucu senaryosu: sessiz boş liste yok ==');
    // Bekleyenler ucu AĞ DÜZEYİNDE eski bir sunucu gibi yanıtlattırılır:
    // includes_unsubmitted alanı yok, liste boş. İstemcinin "sunucudaki
    // auth.php eski" uyarısı böylece gerçekten ölçülür (kod kapsamına dokunulmaz).
    let interceptOldServer = true;
    let intercepted = 0;
    await page.setRequestInterception(true);
    const onReq = (req) => {
      if (/action=admin_pending_approvals/.test(req.url())) intercepted += 1;
      if (interceptOldServer && /action=admin_pending_approvals/.test(req.url())) {
        req.respond({
          status: 200,
          contentType: 'application/json; charset=utf-8',
          body: JSON.stringify({ ok: true, pending: [] })
        }).catch(() => {});
        return;
      }
      req.continue().catch(() => {});
    };
    page.on('request', onReq);
    check(await clickRefresh(), 'eski sunucu yanıtı için yenileme tetiklendi');
    await sleep(700);
    check(await until(page, () => {
      const w = document.querySelector('#app-view .approval-warn');
      return Boolean(w && !w.hidden && (w.textContent || '').includes('auth.php'));
    }, null, 60, 250), 'eski sunucu yanıtında moderatöre açık uyarı gösterilir (kesilen istek: ' + intercepted + ')');
    const warnText = await page.evaluate(() => {
      const w = document.querySelector('#app-view .approval-warn');
      return w ? (w.textContent || '').replace(/\s+/g, ' ').trim() : '';
    });
    check(/auth\.php dosyasını sunucuya yükleyip/.test(warnText), 'uyarı yapılacak işlemi adım adım söylüyor — ' + warnText.slice(0, 90));
    check(await page.evaluate(() => /Liste güvenle okunamıyor/.test((document.querySelector('#app-view .admin-user-list') || {}).textContent || '')),
      'eski sunucuda liste alanı da dürüst mesaj veriyor');
    interceptOldServer = false;
    page.off('request', onReq);
    await page.setRequestInterception(false);
    check(await clickRefresh(), 'gerçek sunucuya dönülüp yenilendi');
    check(await until(page, () => {
      const w = document.querySelector('#app-view .approval-warn');
      return Boolean(w && w.hidden) && document.querySelectorAll('#app-view .admin-user-row').length === 2;
    }, null, 80, 250), 'gerçek sunucuda uyarı kaybolur ve liste geri gelir');

    console.log('== H) Elle yenileme, istemci hız sınırı beklemesine takılmaz ==');
    // Üretimdeki asıl şikâyet: arka plan yoklamaları bir bekleme kilidi
    // biriktirdiğinde moderatörün tıklaması 15 sn'ye kadar sessizce beklerdi.
    // priority seçeneği bu kilidi elle istekler için kökten kaldırır.
    check(await page.evaluate(() => {
      if (typeof state === 'undefined') return false;
      state.requestCooldownUntil = Date.now() + 8000;
      return true;
    }), 'istemci bekleme kilidi 8 sn olarak kuruldu');
    let hReq = 0;
    const hCounter = (r) => { if (/action=admin_pending_approvals/.test(r.url())) hReq += 1; };
    page.on('request', hCounter);
    const hStamp = await page.evaluate(stampNow);
    const hStart = Date.now();
    check(await clickRefresh(), 'kilit aktifken düğme tıklandı');
    check(await waitFor(() => hReq > 0, 20, 250), 'istek 5 sn içinde ağa çıktı (8 sn kilidi beklemedi)');
    const hElapsed = Date.now() - hStart;
    check(hElapsed < 8000, 'elle yenileme bekleme kilidini atladı — geçen: ' + hElapsed + ' ms');
    check(await until(page, stampOf, hStamp, 80, 250), 'kilit aktifken de liste tazelendi');
    page.off('request', hCounter);

    console.log('== I) Meşgulken tıklama sıraya alınır, sessizce yutulmaz ==');
    // Yanıt 1,5 sn geciktirilir; bu sırada ikinci tıklama yapılır. Eski davranışta
    // düğme disabled olduğu için tıklama kaybolurdu; şimdi sıraya alınır.
    let slowMode = true;
    let slowReq = 0;
    await page.setRequestInterception(true);
    const slowHandler = (req) => {
      if (/action=admin_pending_approvals/.test(req.url())) {
        slowReq += 1;
        if (slowMode) {
          setTimeout(() => {
            req.respond({
              status: 200,
              contentType: 'application/json; charset=utf-8',
              body: JSON.stringify({ ok: true, pending: [], includes_unsubmitted: true, total: 0 })
            }).catch(() => {});
          }, 1500);
          return;
        }
      }
      req.continue().catch(() => {});
    };
    page.on('request', slowHandler);
    check(await clickRefresh(), 'ilk yenileme başlatıldı (yanıt geciktiriliyor)');
    await sleep(350);
    check(await page.evaluate(() => Array.from(document.querySelectorAll('#app-view button'))
      .some((b) => (b.textContent || '').trim() === 'Yenileniyor…')), 'düğme meşgul olduğunu gösteriyor');
    check(await page.evaluate(() => {
      const b = Array.from(document.querySelectorAll('#app-view button'))
        .find((x) => (x.textContent || '').trim() === 'Yenileniyor…');
      if (!b) return false;
      b.click();
      return true;
    }), 'meşgulken ikinci tıklama yapıldı');
    check(await until(page, () => /sıraya alındı/.test((document.querySelector('#app-view .approval-status') || {}).textContent || ''), null, 20, 200),
      'sıraya alındığı moderatöre açıkça söyleniyor');
    check(await waitFor(() => slowReq >= 2, 60, 250), 'sıradaki yenileme gerçekten çalıştı (2 istek) — gelen: ' + slowReq);
    slowMode = false;
    page.off('request', slowHandler);
    await page.setRequestInterception(false);
    check(await clickRefresh(), 'gerçek veriyle son yenileme');
    check(await until(page, () => document.querySelectorAll('#app-view .admin-user-row').length === 2, null, 80, 250),
      'liste sonunda iki bekleyeni de gösteriyor');

    console.log('== E) Sağlık ==');
    check(pageErrors.length === 0, 'sayfada JS hatası yok' + (pageErrors.length ? ' — ' + pageErrors.slice(0, 3).join(' | ') : ''));
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
