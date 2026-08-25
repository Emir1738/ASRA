// Eski sürüm nudge testi: UA'da AsraApp/x.y.z varsa ve sürüm 1.5.7'den eskiyse
// #app-view içinde .update-nudge bandı görünmeli; güncel/tarayıcı UA'da olmamalı.
// Kullanım: node jsdom_nudge_test.js "UA-STRING"
const puppeteer = require('puppeteer-core');
const UA_ARG = process.argv[2];

(async () => {
  const browser = await puppeteer.launch({ executablePath: '/usr/bin/chromium', args: ['--no-sandbox', '--disable-dev-shm-usage'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 393, height: 850, isMobile: true });
  if (UA_ARG) await page.setUserAgent(UA_ARG);
  await page.evaluateOnNewDocument(() => {
    window.AsraNative = {
      isNativeApp: () => true,
      getDeviceId: () => 'asra.testdevice000000',
      setWatching: () => {},
      setNtfyChannel: () => {},
      notifyRooms: () => {},
      notify: () => {},
      requestNotifications: () => {},
      openRoom: () => {},
    };
    window.__NTFY_BASE__ = 'http://127.0.0.1:8090/ntfy';
    window.__NTFY_INTERVAL_MS__ = 20000;
  });
  await page.goto('http://127.0.0.1:8090/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await new Promise((r) => setTimeout(r, 2500));
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
  await new Promise((r) => setTimeout(r, 1500));
  const has = await page.evaluate(() => !!document.querySelector('#app-view .update-nudge'));
  console.log((UA_ARG ? 'UA: …' + UA_ARG.slice(-16) : 'UA: (temiz)') + ' -> nudge: ' + has);
  await browser.close();
})().catch((e) => { console.error('HATA:', e.message); process.exit(1); });
