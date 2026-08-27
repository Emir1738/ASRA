# ASRA

Site (PHP) + Android uygulaması (WebView, Kotlin) + tam E2E test altyapısı.

## Yapı

| Klasör | İçerik |
|---|---|
| `asra-site-fix/` | **Güncel site** — `auth.php` (API), `index.html` (arayüz), `.htaccess` |
| `asra-apk-build/` | Android kaynak (Kotlin) + derlenmiş **`asra-1.5.7.apk`** (imzalı) |
| `_tests/` | BASH/CLI + Chromium E2E suite'leri ve ortam kurulumu |

## Güncel özellikler

- **Sıfır-istek mesaj mimarisi:** mesaj anında yerel çizilir, arka planda 1 istekle iletilir (outbox). Odaya geri dönüş 0 istek (yerel önbellek). Periyodik istek yok. Gerçek zamanlı akış ntfy (dış servis) üzerinden — hosting kotasına sayılmaz.
- **5 sn gönderim cooldown:** buton üzerinde 5→4→3→2→1 geri sayımı; süre bitmeden gönderim kilitli, süre bitince tekrar gönderilebilir.
- **Mesaj silme:** buton + modal + 4 sn geri-alma (undo) + kalıcı silme. Silme 2 istek (teslim edilmemiş mesaj: 0 istek).
- **Hit koruması (kalıcı):** 60/dk host bütçesi + uç bazlı limitler; yetkili "erişim kodu" aç/kapa + otomatik sabit kod üretimi.
- **Güvenli oturum:** hatırla-token ile sessiz oturum kurtarma (uygulama güncellemesinde hesap düşmez). Kimlik eşleştirmesi yalnızca benzersiz token + uygulama kimliğiyle.
- **CSP:** inline style/script SHA-256 hash'li (katı). CSS/JS değişince `_tests/csp_hash_check.sh` ile hash kontrolü yapın.

## APK

- Dosya: `asra-apk-build/asra-1.5.7.apk`
- SHA-256: `d3a3559898fc54cb9bdce7aef25cce1b4b541ac58131995fe1a536f1c7982f42`
- İmza: v2 + v3 (keystore: `asra-apk-build/asra-release.jks`, sürüm güncellemelerinde uyumlu)
- Derleme: `asra-apk-build/tools/build_apk.sh` (JDK 17 + kotlinc 1.9 + Android SDK build-tools 34)

## Testler

Önkoşul: PHP 8.4 (curl eklentili), MariaDB, Chromium + puppeteer-core.

```bash
# ortam sıfırdan kurulur (DB + şema + referans kullanıcı)
bash _tests/reset_env.sh

# PHP sunucu (test ortamı)
env ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_COOKIE_SECURE=0 \
    ASRA_NTFY_HOST='http://127.0.0.1:8090/ntfy' \
    php -S 127.0.0.1:8090 _tests/test_router.php
```

| Suite | Kapsam |
|---|---|
| `bash _tests/e2e_test.sh` | davet/kayıt/giriş, erişim kodu, hit koruma, oturum kurtarma, 12 ay inaktiflik, CSRF |
| `bash _tests/push_decision_test.sh` | onay/karar push bildirimleri, duyuru fan-out |
| `bash _tests/csrf_heal_test.sh` | 419 kendini-onarma (oturum kaybı) |
| `node _tests/jsdom_push_arch_test.js [native\|browser]` | yerel render, 0 istek, outbox, ntfy (Chromium) |
| `node _tests/cooldown_test.js` | 5 sn cooldown saniye saniye (Chromium) |
| `bash _tests/csp_hash_check.sh` | CSP hash / içerik bütünlüğü |

Son tam koşu: **134/134 yeşil**.

## Sunucuya yükleme

`asra-site-fix/` içindeki 3 dosya (`auth.php`, `index.html`, `.htaccess`) birlikte yüklenir — sürüm çifti olarak düşün, ayrı güncelleme yapma. APK: `asra-1.5.7.apk` cihazlara doğrudan kurulur.
