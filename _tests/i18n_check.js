/**
 * i18n butunluk denetimi (statik, tarayici gerektirmez).
 *  1) I18N sozlugu gercek JS olarak degerlendirilir; tr/en anahtar kumeleri
 *     birebir ayni olmali (bir dilde eksik anahtar = sessiz Turkce sizinti).
 *  2) Koddaki her t('anahtar') cagrisi sozlukte bulunmali.
 *  3) Statik HTML'deki her data-i18n / -ph / -aria / -title degeri bulunmali.
 *  4) SUPPORTED_LANGUAGES yalnizca tr+en olmali ve secicide baska dil kalmamali.
 *  5) {param} yer tutuculari tr/en icin ayni kumede olmali.
 * Cikis: PASS/FAIL satirlari; hata varsa exit 1.
 */
const fs = require('fs');
const path = process.argv[2];
if (!path) { console.error('kullanim: node i18n_check.js <index.html>'); process.exit(2); }
const html = fs.readFileSync(path, 'utf8');

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  ✓ ' + m); };
const bad = (m) => { fail++; console.log('  ✗ ' + m); };

const scriptMatch = html.match(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/);
if (!scriptMatch) { bad('satir ici <script> bulunamadi'); process.exit(1); }
const js = scriptMatch[1];

// --- 1) sozlugu degerlendir ---
const dStart = js.indexOf('const I18N = {');
if (dStart < 0) { bad('const I18N bulunamadi'); process.exit(1); }
const dEnd = js.indexOf('\n};\n', dStart);
const dictSrc = js.slice(dStart, dEnd + 3);
const supStart = js.indexOf('const SUPPORTED_LANGUAGES');
const supSrc = supStart >= 0 ? js.slice(supStart, js.indexOf(';', supStart) + 1) : '';
let I18N, SUPPORTED_LANGUAGES;
try {
  ({ I18N, SUPPORTED_LANGUAGES } = new Function(supSrc + '\n' + dictSrc + '\nreturn { I18N, SUPPORTED_LANGUAGES };')());
  ok('I18N + SUPPORTED_LANGUAGES degerlendirildi');
} catch (e) { bad('sozluk degerlendirilemedi: ' + e.message); process.exit(1); }

const langs = Object.keys(I18N);
if (JSON.stringify(langs) === JSON.stringify(['tr', 'en'])) ok('sozluk dilleri tam olarak tr+en: ' + langs.join(','));
else bad('beklenmeyen dil blogu: ' + langs.join(','));

if (JSON.stringify(SUPPORTED_LANGUAGES) === JSON.stringify(['tr', 'en'])) ok("SUPPORTED_LANGUAGES = ['tr','en']");
else bad('SUPPORTED_LANGUAGES bekleniyor [tr,en], bulunan: ' + JSON.stringify(SUPPORTED_LANGUAGES));

const trKeys = Object.keys(I18N.tr), enKeys = Object.keys(I18N.en);
const missingEn = trKeys.filter((k) => !(k in I18N.en));
const missingTr = enKeys.filter((k) => !(k in I18N.tr));
if (!missingEn.length && !missingTr.length) ok(`tr/en anahtar kumeleri esit (${trKeys.length} anahtar)`);
else { if (missingEn.length) bad('en icinde eksik: ' + missingEn.join(', ')); if (missingTr.length) bad('tr icinde eksik: ' + missingTr.join(', ')); }

// bos / ayni kalmis ceviriler
const dup = trKeys.filter((k) => k in I18N.en && I18N.tr[k] === I18N.en[k] && /[çğıöşüÇĞİÖŞÜ]/.test(I18N.tr[k]));
if (!dup.length) ok('Turkce karakter icerip en ile ayni kalan anahtar yok');
else bad('cevrilmemis (tr==en ve Turkce karakterli): ' + dup.join(', '));
const empty = trKeys.concat(enKeys).filter((k) => { const v = (I18N.tr[k] ?? I18N.en[k]); return typeof v !== 'string' || !v.trim(); });
if (!empty.length) ok('bos deger yok'); else bad('bos deger: ' + empty.join(', '));

// --- 2) t('key') kullanimlari ---
const dictSpan = [dStart, dEnd];
const used = new Map();
const re = /\bt\(\s*'([A-Za-z0-9_]+)'/g;
let m;
while ((m = re.exec(js))) {
  if (m.index >= dictSpan[0] && m.index <= dictSpan[1]) continue;
  used.set(m[1], (used.get(m[1]) || 0) + 1);
}
const unknownT = [...used.keys()].filter((k) => !(k in I18N.tr));
if (!unknownT.length) ok(`kodda kullanilan ${used.size} ayri t() anahtarinin hepsi sozlukte`);
else bad('sozlukte OLMAYAN t() anahtarlari: ' + unknownT.join(', '));

// --- 3) data-i18n nitelikleri ---
const attrs = [...html.matchAll(/data-i18n(?:-(ph|aria|title))?="([A-Za-z0-9_]+)"/g)].map((x) => x[2]);
const unknownAttr = [...new Set(attrs)].filter((k) => !(k in I18N.tr));
if (!unknownAttr.length) ok(`${new Set(attrs).size} ayri data-i18n degerinin hepsi sozlukte`);
else bad('sozlukte OLMAYAN data-i18n degerleri: ' + unknownAttr.join(', '));

// --- 4) secicide eski diller kalmamali ---
if (!/new Option\('Deutsch'|new Option\('Français'/.test(js)) ok('dil secicide de/fr secenegi yok');
else bad('dil secicide hâlâ de/fr var');

// --- 5) {param} yer tutucu parity ---
const ph = (s) => (String(s).match(/\{[A-Za-z0-9_]+\}/g) || []).sort().join(',');
const parDiff = trKeys.filter((k) => k in I18N.en && ph(I18N.tr[k]) !== ph(I18N.en[k]));
if (!parDiff.length) ok('{param} yer tutuculari tr/en arasinda birebir ayni');
else bad('yer tutucu uyusmazligi: ' + parDiff.map((k) => `${k} tr[${ph(I18N.tr[k])}] en[${ph(I18N.en[k])}]`).join(' | '));

console.log(`\nSONUC: ✓${pass} ✗${fail}`);
process.exit(fail ? 1 : 0);
