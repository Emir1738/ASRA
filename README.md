# ASRA

Site (PHP) + Android uygulaması (WebView, Kotlin) + tam E2E test altyapısı.

## Yapı

| Klasör | İçerik |
|---|---|
| `asra-site-fix/` | **Güncel site** — `auth.php` (API), `index.html` (arayüz), `.htaccess` |
| `asra-apk-build/` | Android kaynak (Kotlin) + derlenmiş **`asra-1.7.1.apk`** (imzalı) |
| `_tests/` | BASH/CLI + Chromium E2E suite'leri |
| `.setup/` | Test koşucusu ve ortam kurulum betikleri (`run-tests.sh`, `install-testenv.sh`, `install-toolchain.sh`) — çalışma alanında `/home/user/.setup/` yolunda durur |
| `asra-yukleme/` | Sunucuya yüklenecek **hazır paket**: `auth.php` + `index.html` + `.htaccess` + APK + `YUKLEME-NOTU.txt`. Kaynak `asra-site-fix/`'tir; bu klasör onun kopyasıdır ve her değişiklikte yeniden eşitlenir (sha256 ile doğrulanır) |

Depoya **girmeyen** çalışma alanı klasörleri: `secrets/` (Firebase servis hesabı
özel anahtarı) ve `uploads/` (kullanıcı yüklemeleri). İkisi de `.gitignore` ile
açıkça engellenmiştir.

## Güncel özellikler

- **Bildirim sistemi KALDIRILDI (v1.7.0):** sunucu hiçbir bildirim üretmez,
  cihaz hiçbir kanaldan itilmez. Uygulama `POST_NOTIFICATIONS` istemez; arka
  plan servisi, alarm, açılışta çalışma ve titreşim izinleri de yoktur.
  Firebase/FCM bağımlılığı, servis işçisi ve `ntfy` kalıntısı tamamen silindi.
- **Sıfır-hit boşta düzeni:** periyodik `get_messages` YOKTUR. Tek zamanlayıcı
  `startRoomPulseWatcher()` boşta `get_rooms` çeker; odanın okunmamış rozeti
  **değişim dedektörü** olarak kullanılır ve yalnızca rozet değiştiğinde
  **tek** `get_messages` gider. Mesaj gönderildikten sonra ritim 2 dakika
  boyunca 10 sn'ye iner. Ekran gizliyken **0 istek**.
- **Kademeli seyreltme (hit tasarrufu):** hiçbir şey değişmeyen açık bir sekme
  saatlerce 1 istek/dk yemesin diye boşta ritim kademeli seyrekleşir —
  ilk 5 tur **60 sn**, sonra **120 sn**, sonra **180 sn** tavan
  (`roomPulseIdleInterval()`). Herhangi bir etkinlik (mesaj gönderme, rozet
  değişimi, odaya giriş, ekrana dönüş) sayacı sıfırlar; kullanıcı başındayken
  gecikme 60 sn'de kalır. Uzun vadede **%58–66 daha az hit**.
- **Sıfır-istek oda girişi:** son 200 mesaj `localStorage`'dan çizilir; oda
  okunmuş durumdaysa girişte hiç istek gitmez. Mesaj anında yerel çizilir ve
  arka planda 1 istekle iletilir (outbox).
- **Şema kendini kurar:** `ensureCoreSchema()` 9 çekirdek tabloyu ve 20 odalık
  kataloğu `IF NOT EXISTS` / `INSERT IGNORE` ile kurar. **Boş bir veritabanına
  bile** yalnızca dosyaları yüklemek yeterlidir; SQL yüklemek gerekmez.
- **5 sn gönderim cooldown:** buton üzerinde 5→4→3→2→1 geri sayımı; süre bitmeden
  gönderim kilitli, süre bitince tekrar gönderilebilir.
- **Mesaj silme:** buton + modal + 4 sn geri-alma (undo) + kalıcı silme
  (`delete_message` / `undo_delete_message` / `finalize_message_delete`,
  sunucuda hash'li geri-alma belirteci). Akış `_tests/message_delete_test.sh`
  ile otomatik test edilir.
- **Şifre değiştirme (Ayarlar > Güvenlik):** kullanıcı güncel şifresini ve yeni
  şifresini **iki kez** girer; güncel şifre doğrulanır, yeni şifre kayıt
  akışıyla **birebir aynı** kurallardan geçer (`validatePassword` tek kaynak),
  hepsi tutarsa hash yenilenir (`change_password`). "Şifremi unuttum" ise
  ağ isteği üretmeyen bir açıklama modalı açar: kullanıcıya ne yapacağı
  (yetkiliyle iletişime geçme), bunun neden böyle olduğu ve sonrasında ne
  olacağı anlatılır.
  Teknik gerçek: kendi kendine sıfırlama ucu **yok** (`users` tablosunda
  e-posta kolonu bile yok), tek yol yetkilidir. Ama kullanıcıya bakan metin
  BİLEREK bir eksiklik itirafı gibi yazılmaz — "bu platformda e-posta
  doğrulaması yok" demek platformu kötülemektir, yetkilinin hangi paneli
  kullandığını anlatmak da kullanıcının işi değildir. Bu ton
  `_tests/password_change_browser_test.js` içinde olumsuz doğrulamalarla
  kilitlidir (e-posta anılmamalı, "yapamazsın" dili olmamalı, Admin Paneli
  geçmemeli, metin ≥4 paragraf ve ≥400 karakter olmalı). Değişimde `session_version` yükselir: **diğer** cihazların
  oturumları ve hatırla-anahtarları düşer, bu isteği yapan cihazınki korunur.
  Koruma giriş ucuyla aynı politikadır: yalnızca **hatalı güncel şifre** sayılır
  (5 deneme / 15 dk), kural hataları bütçeyi tüketmez, başarı sayacı sıfırlar ve
  kilit **kullanıcı** bazlıdır (paylaşımlı hosting'de aynı IP'deki başkası
  etkilenmez). Akış `_tests/password_change_test.sh` ile otomatik test edilir.
- **Çok dillilik (tr + en):** dil seçimi artık GERÇEKTEN uygulanıyor. Kök neden
  üçlüydü: `applyLanguage()` yalnızca `<html lang>` yazıyordu (statik HTML ve
  açık ekran Türkçe kalıyordu), üye yolculuğundaki yüzlerce metin sözlükte
  değil kodun içinde sabit Türkçe duruyordu ve sunucu hata mesajları Türkçe
  dönüp olduğu gibi gösteriliyordu. Çözüm: 292 anahtarlı tr/en sözlük +
  `data-i18n` / `-ph` / `-aria` / `-title` bağlaması + `t(key, {param})`
  yer tutucu desteği + sunucu **cümle**→anahtar eşlemesi (`SERVER_MESSAGE_I18N`;
  aynı hata kodu farklı bağlamlarda farklı cümle taşıdığı için kod eşlemesi
  bilinçli seçilmedi, bağlam bozulurdu) + çıkışta/giriş ekranında tercih
  `localStorage` ile hatırlanıyor (tema ile aynı desen). de/fr seçenekleri
  kaldırıldı, yalnızca tr + en var. Türkçe arayüz birebir korunur — bu,
  regresyon doğrulamasıyla testte kilitlidir.
  **Kapsam sınırı (bilinçli):** üye yüzeyleri çevrilir; admin paneli, Asra AI
  içeriği ve arayüzü, oda kataloğunun tohum adları/açıklamaları ile görev ve
  oyun metinleri çevrilmez. Sunucunun insan dili kod haritasında yoksa
  sunucunun kendi (Türkçe) mesajı gösterilir.
  Akış `_tests/i18n_test.sh` ile otomatik test edilir (statik + Chromium E2E).
- **Onay kuyruğu görünürlüğü:** yeni kayıt `pending` doğar ve kullanıcıya
  "SON ADIM" ekranında katılma nedenini yazması sunulur; nedeni yazsa da
  yazmasa da hesap **Onaylanmayı Bekleyenler** listesine düşer. Kök neden:
  `admin_pending_approvals` sorgusu `approval_submitted_at IS NOT NULL`
  taşıyordu — nedeni henüz yazılmamış bekleyen hesaplar (ekranı kapatıp giden,
  eski istemci/APK'dan gelen, geçmişten kalan satırlar) moderatöre HİÇ
  görünmüyordu. Artık bekleyen HER hesap listelenir; gönderilmiş başvurular
  önde, nedeni yazılmamışlar kayıt tarihiyle arkadan gelir ve satırda
  "katılma nedeni henüz yazılmadı" notu dürüstçe gösterilir. 15 gün
  yanıtlanmayan **gönderilmiş** başvurular politika gereği sistemce kapatılır;
  nedeni yazılmamışlar kapatılmaz, moderatörün kararını bekler.
  Akış `_tests/approval_visibility_test.sh` ile otomatik test edilir
  (API + gerçek Chromium'da admin paneli).
- **Bekleyenleri gör/yenile (v31):** sekmenin üstünde **Herkesi Gör / Yenile**
  düğmesi ve sayaç satırı var: "N bekleyen hesap · X nedeni yazılmış · Y nedeni
  henüz yazılmamış · son yenileme SS:DD". Liste sekme açılır açılmaz da
  kendiliğinden dolu gelir; düğme yalnızca tazelemek içindir.
  Kök neden düzeltmeleri: (1) `installGentleActionGuard` `.secondary-button`
  tıklamalarını 3 sn kilitliyor ve aradaki tıklamaları `stopImmediatePropagation`
  ile sessizce yutuyordu — düğme `data-no-action-lock` ile bu kilidin dışında,
  meşgulken gelen tıklama da kaybolmuyor, sıraya alınıyor; (2) arka plan
  yoklamalarının biriktirdiği istemci hız-sınırı beklemesi elle yenilemeyi
  15 sn'ye kadar geciktiriyordu — elle istek `priority` ile bekletilmiyor;
  (3) sunucudaki `auth.php` eskiyse (yanıtta `includes_unsubmitted` yoksa)
  liste sessizce boş kalmıyor, moderatöre hangi dosyayı yükleyeceğini söyleyen
  uyarı çıkıyor. Sunucu sürümü `?action=health` yanıtındaki `build` alanından
  doğrulanır (bu sürüm: `v31`).
- **Hit koruması (kalıcı):** 60/dk host bütçesi + uç bazlı limitler; yetkili
  "erişim kodu" aç/kapa + otomatik sabit kod üretimi.
- **Güvenli oturum:** hatırla-token ile sessiz oturum kurtarma (uygulama
  güncellemesinde hesap düşmez). Kimlik eşleştirmesi yalnızca benzersiz token +
  uygulama kimliğiyle.
- **CSP:** inline style/script SHA-256 hash'li (katı) ve `connect-src 'self'` —
  üçüncü taraf hiçbir kaynağa bağlantı açılmaz.
  Güncel hash'ler — style: `aCJi/6WSe8xD5uQIEshH2B/h/HTB9JPRsZZZ0jnFjrQ=`,
  script: `kmwzOB/bAupQPp0VnSFx1lE8k9/+z6j11dpVyrQ5NqM=`.
  CSS/JS her değiştiğinde `bash _tests/csp_hash_check.sh asra-site-fix/index.html`
  çalıştırılmalı; hash tutmazsa sayfa tarayıcıda tamamen çalışmaz.

## Canlılık mimarisi (bildirim yerine)

```
              ┌───────────────────────────────┐
              │  auth.php                     │  ← bildirim ÜRETMEZ
              │  (get_rooms / get_messages)   │
              └───────────────┬───────────────┘
                              │  isteği HER ZAMAN istemci başlatır
                              ▼
        ┌───────────────────────────────────────────────┐
        │  startRoomPulseWatcher()  — TEK zamanlayıcı   │
        │  boşta 60 sn · sohbet ederken 10 sn (2 dk)    │
        │  her turda: get_rooms  (1 istek)              │
        └───────────────┬───────────────────┬───────────┘
                        ▼                   ▼
        ┌────────────────────────┐  ┌──────────────────────────┐
        │ Ana ekran              │  │ Oda ekranı               │
        │ rozetleri yerinde çiz  │  │ rozet DEĞİŞTİ mi?        │
        │ (0 ek istek)           │  │  hayır → 0 istek         │
        │                        │  │  evet  → TEK get_messages│
        └────────────────────────┘  └──────────────────────────┘
        yalnızca: ekran görünür + oturum açık + arka plan bütçesi uygun
```

Odaya girişte son 200 mesaj `localStorage`'dan çizilir (**0 istek**); oda
okunmuş durumdaysa giriş isteği de gitmez. Periyodik `get_messages`
**yoktur** — canlılık, `get_rooms` yanıtındaki okunmamış sayacının değişim
dedektörü olarak kullanılmasıyla sağlanır.

### Ölçülen istek yükü (Chromium ile, gerçek hızda)

`node _tests/hit_olcum.js` gerçek tarayıcıda, **hiçbir hızlandırma olmadan**
180 sn boyunca hosta giden `auth.php` isteklerini sayar (mesaj gönderilmeden,
ekrana dokunulmadan):

| Ekran | Kararlı durum | Ölçüm |
|---|---|---|
| Oda içi (boşta) | 60 sn'de 1 `get_rooms`, **0** `get_messages` | **1,00 istek/dk** (180 sn'de 3 istek) |
| Ana ekran | 60 sn'de 1 `get_rooms` | **1,00 istek/dk** (180 sn'de 3 istek) |
| Ekran görünmezken | — | **0 istek** (70 sn'de 0) |

Oda boşta **hiç `get_messages` istemez**; bu davranış
`_tests/no_notification_browser_test.js` içinde ayrıca doğrulanır (boşta 0
`get_messages`, başka kullanıcı yazınca **tek** `get_messages`).

Sohbet ederken: mesaj gönderildikten sonra 2 dakika boyunca ritim 10 sn'ye
iner (6 `get_rooms`/dk), karşı tarafın yanıtı en geç 10 sn'de görünür. Pencere
kapanınca ritim kendiliğinden 60 sn'ye döner.

Tavanlar: sunucu dakika bütçesi `HIT_PROTECTION_MAX_REQUESTS = 60`, oturum
bütçesi `HIT_PROTECTION_SESSION_MAX_REQUESTS = 50`, istemci arka plan bütçesi
`BACKGROUND_BUDGET_MAX_REQUESTS = 6 / 60 sn`. Boşta bir kullanıcı sunucu
bütçesinin **%1,7**'sini kullanır.

### Hit kotası hesabı

Boşta maliyet **60 istek/saat** (1/dk). Sayfa açılışı **1 istek**tir — CSS, JS
ve logo dosyaları `index.html` içine gömülü olduğu için ek dosya isteği yoktur.

| Kullanım (ekran açık süre) | Günlük | Aylık (30 gün) |
|---|---|---|
| günde 2 saat | 120 | 3.600 |
| günde 4 saat | 240 | 7.200 |
| 7/24 açık | 1.440 | 43.200 |

50.000'lik kota **günlükse** çok rahattır: 7/24 açık ~34 kullanıcı, günde 4 saat
kullanımda ~208 kullanıcı sığar. **Aylıksa** dardır: 7/24 açık TEK kullanıcı
43.200 ile kotanın %86'sını yer, günde 4 saat kullanımda ~6 kullanıcı sığar.
Aylık kotada rahatlamak için ya kota yükseltilmeli ya da `ROOM_PULSE_IDLE_MS`
60.000'den 120.000'e çıkarılmalıdır (maliyet yarıya iner, mesaj gecikmesi
2 dakikaya çıkar).

> Not: Tablodaki sayılar boştadır. Aktif sohbet her mesaj için +1 istek
> (gönderim) ve 2 dakika boyunca +5 istek/dk ekler.

**Asıl dar boğaz kota değil, sunucudaki hesap geneli tavandır:**
`consumeGlobalReadBudget` tüm hesabı kapsayan **60 sıcak-okuma/dk** sınırı
uygular (`get_rooms`, `get_messages`, `session` … bu kovadadır) ve aşılınca
90 sn boyunca `429 HOST_LIMIT_REACHED` döner. Boşta kullanıcı 1 istek/dk
olduğu için bu tavan ~60 eşzamanlı boşta kullanıcıya denk gelir; aynı anda
sohbet eden kullanıcı 6 istek/dk tükettiğinden pratikte ~10 eşzamanlı aktif
kullanıcıda devreye girer. Kullanıcı sayısı bu seviyeye yaklaşırsa
`ASRA_HOT_READS_PER_MIN` yükseltilmelidir (tavan `HIT_PROTECTION_MAX_REQUESTS`).

Kaldırılan uçlar artık **tanınmıyor**: `register_push`, `register_push_token`
(POST → 405, GET → 404 `ACTION_NOT_FOUND`) ve `poll_push` (aynı).
`user_push_tokens` / `push_events` / `user_push_topics` tablolarına sunucu
hiç dokunmaz; canlı veritabanında duruyorlarsa yalnızca atıl kalırlar.

## Uygulama tarafı

Paket: `app.asra.client`. Bildirim/push ile ilgili tüm sınıflar silindi
(`AsraMessagingService`, `AsraPush`, `AsraNotifier`, `AsraNotifyService`,
`AsraPingJob`, `AsraAlarmReceiver`, `AsraRestartReceiver`, `AsraBootReceiver`,
`AsraWatch`, `AsraBus`, `AsraInbox`).

| Dosya | Görev |
|---|---|
| `MainActivity.kt` | WebView kabuğu; dosya seçimi, indirme, geri tuşu, oturum çerezi |
| `AsraApp.kt` | Uygulama sınıfı; yalnızca WebView ortamı (Firebase başlatması yok) |
| `AsraBridge.kt` | `AsraNative` köprüsü: `getDeviceId`, `isNativeApp`, `openRoom`. Eski sayfalarla çökme olmasın diye `notify`/`notifyRooms`/`requestNotifications`/`setWatching` **boş** bırakıldı (bildirim üretmez) |
| `AsraInject.kt` | `fetch` yaması: `X-Asra-Device-Id` başlığı + `asraOpenRoom` |
| `AsraHttp.kt`, `AsraSession.kt`, `AsraIdentity.kt`, `CaptureFileProvider.kt` | Ağ, oturum, cihaz kimliği, dosya sağlayıcı |

## APK

- Dosya: `asra-apk-build/asra-1.7.0.apk`
- SHA-256: `63ba88254d0f1816636d58c0bed43e2b209c9683e5fbbe0acaa73d1deff087c1`
- Boyut: 711.676 bayt
- Sürüm: `versionCode 27` / `versionName 1.7.0`
- İzinler (yalnızca 5): `INTERNET`, `ACCESS_NETWORK_STATE`, `CAMERA`,
  `RECORD_AUDIO`, `MODIFY_AUDIO_SETTINGS` — **bildirim izni yok**
- Manifest'te `service`/`receiver` yok; yalnızca `MainActivity` +
  `CaptureFileProvider`. DEX içinde Firebase ve `NotificationManager` yok
- İmza: v2 + v3 — **önceki sürümle aynı sertifika** (`3527c221…`), bu yüzden
  eski sürüm kurulu cihazlara doğrudan **güncelleme olarak** kurulur
- Derleme: `ASRA_BUILD_HOME=/home/user/.asra-build bash asra-apk-build/tools/build_apk.sh`
  (JDK 17 + Gradle 8.5 + Android SDK build-tools 34)

## Kurulum (sunucu)

Belge köküne **üç** dosya birlikte yüklenir:

| Dosya | Görev |
|---|---|
| `auth.php` | API + veritabanı bağlantısı (tek PHP dosyası) |
| `index.html` | Arayüz (CSS/JS/logo gömülü) |
| `.htaccess` | Başlıklar + gizli dosya engeli |

- **Tek PHP dosyası yeter.** Bağlantı bilgileri `auth.php` içinde varsayılan
  olarak durur; ayrı bir yapılandırma dosyası yoktur. Başka bir veritabanına
  bağlanmak gerekirse `ASRA_DB_DSN` / `ASRA_DB_USER` / `ASRA_DB_PASSWORD`
  ortam değişkenleri bu varsayılanları ezer (kod değişmeden).
- **SQL yüklemek gerekmez.** Boş bir veritabanında ilk istek şemayı kurar:
  9 çekirdek tablo + ek tablolar + 20 odalık katalog (`ensureCoreSchema`).
  Tüm ifadeler `IF NOT EXISTS` / `INSERT IGNORE` olduğu için tekrar çalışmak
  zararsızdır, mevcut veri ezilmez.
- Dışarıdan yüklenen SQL yalnızca 15 oda içeriyorsa eksik 5 oda otomatik
  tamamlanır.
- Şema kurulum işaretleri **veritabanı kimliğine göre** ayrılır
  (`asraSchemaKey()`); hesap/veritabanı değiştirdiğinde eski önbellek işareti
  yeni veritabanında kurulumu atlatmaz.

## "Bağlantı geçici olarak yavaşladı" kilitlenmesi (onarıldı)

İki kusur birleşince site kalıcı olarak kilitleniyordu:

1. **Kilit kendini uzatıyordu.** `consumeGlobalReadBudget` engeli her istekte
   `blocked_until = NOW + cooldown` yazıyordu. Engel sürerken gelen her istek
   (istemcinin 60 sn'lik nabız turu bile) engeli ileri atıyordu; kilit
   pratikte hiç bitmiyordu. Sayaç hesabın TÜMÜNÜ kapsadığı için tek bozuk
   sekme herkesi dışarıda bırakıyordu.
2. **Bozuk pencere kalıcı kilide yol açıyordu.** `rate_limits.window_started_at`
   NULL/bozuk olduğunda (SQL ile içe aktarılmış satır) pencere asla
   sıfırlanmıyor, sayaç sonsuza dek büyüyordu.

3. **Eski döküm içe aktarımı her isteği 503'e düşürüyordu.** Veritabanı eski
   bir dökümden içe aktarıldığında `rooms` tablosunda `position` sütunu
   yoktu. `ensureCoreSchema` "tablo yoksa oluştur" mantığıyla çalıştığı için
   **var olan** tabloya dokunmuyor, oda tohumlaması her istekte
   `Unknown column 'position'` ile patlıyor ve arayüz sürekli bu uyarıyı
   gösteriyordu. Tanı ucu `ok:true` dönüyordu çünkü kendi PDO bağlantısıyla
   çalışıp `db()`/`ensureCoreSchema` yolunu atlıyor — yani `ok:true`,
   uygulamanın çalıştığı anlamına gelmiyordu.

Onarım: engel artık uzamıyor ve süresi bitince site kendiliğinden açılıyor;
engel sürerken sayaç artmıyor; NULL pencere "süresi dolmuş" sayılıp satır
onarılıyor; kurulum bozuk kilit satırlarını bir kez temizliyor; **eksik sütun
`ensureColumn()` ile kendiliğinden ekleniyor**, yani eski döküm içe
aktarılsa bile elle SQL çalıştırmak gerekmiyor (şema sürümü `v19`).
Eşikler: hesap geneli **180 istek/dk** (önceden 60), oturum başına
**90/dk** (önceden 50), engel **30 sn** (önceden 90 sn).

`_tests/throttle_release_test.sh` bunu davranışsal olarak kanıtlar: sınır
aşılınca 429 döner, engel bitince 200'e döner, bozuk satır kalıcı kilide yol
açmaz ve engel sürerken `blocked_until` değeri değişmez.

`_tests/legacy_schema_test.sh` üçüncü kusuru kanıtlar: `position`'sız eski bir
`rooms` tablosu kurulur, ilk istek 503 değil **200** döner, eksik sütun
otomatik eklenir, katalog 20 odaya tamamlanır ve mevcut satırlar ezilmez.

## Arayüz onarımları: görenler, geri sayım, kırpılan metin

Kullanıcı bildiriminden çıkan üç kusur (üçü de istemci tarafındaydı; sunucu
uçları ölçüldü ve sağlam çıktı):

1. **Mesajı atınca "Görenler" açılmıyordu.** Yerel çizilen satırın butonu,
   dinleyici kurulurken yakalanan `message.id`'yi kullanıyordu. Mesaj sunucuya
   teslim edilince satırın `dataset.msgId`'si resmî kimliğe dönüyor ama kapanış
   eski `local-…` değerinde kalıyordu → `Number()` = `NaN` → buton **sessizce**
   hiçbir şey yapmıyordu. Kimlik artık **tıklama anında** satırdan okunuyor;
   satır henüz teslim edilmediyse kullanıcıya bilgi veriliyor.
2. **Temizlenme geri sayımı boş odada hiç başlamıyordu.** Sunucu `next_cleanup_at`
   değerini yalnızca odaya ilk girişte (`meta=1`) ve odada mesaj varsa
   gönderiyor; boş odaya girip mesaj yazınca sayaç sabit kalıyordu. Geri sayım
   artık **ek istek atılmadan** eldeki mesaj listesinden türetiliyor
   (`deriveRetentionFrom`) ve ilk gönderimde başlatılıyor. Oda boşken uydurma
   "24 saat 0 dakika" yerine "oda boş · ilk mesajdan sonra başlar" yazıyor.
   Saklama süresi istemcide sabit yazılmıyor: `session` yanıtındaki
   `message_retention_hours` kullanılıyor.
3. **Geri sayım dar ekranda kırpılıyordu.** `.countdown` mobilde
   `overflow:hidden + text-overflow:ellipsis` idi; sayacın sonu hiç
   görünmüyordu. Hap artık **yatay kaydırılabilir** (`overflow-x:auto`,
   dokunmatik kaydırma + ince kaydırma çubuğu): çekince metnin tamamı okunur.
   Ölçüm: 393 px genişlikte `scrollLeft 0 → 98`.

Aynı denetimde bulunan ek kırpılma: **davet kodu** kutusu da `ellipsis` ile
kesiliyordu; artık satır atlıyor ve kodun tamamı görünüyor.

`_tests/ui_fix_browser_test.js` üçünü de gerçek Chromium'da ölçer. Düzeltme
öncesi aynı test **7 doğrulamada kırmızıydı** (görenler penceresi açılmıyor,
`get_message_views` isteği gitmiyor, sayaç başlamıyor, `overflow-x=hidden`),
düzeltme sonrası 18/18 yeşil.

Sunucu tarafında tek değişiklik: `get_message_views` üzerindeki 24 saatlik ek
pencere kaldırıldı. Temizlik örneklemeli çalıştığı için süresi dolmuş bir mesaj
bir süre daha veritabanında duruyor; o aralıkta ekranda görünen mesajın görenler
listesi "Mesaj bulunamadı" diyordu.

## Adres ve tanı

- **Site adresi:** `https://asra.42web.io` (eskisi `asra.freedev.app`).
  Uygulamadaki `SITE_ORIGIN` ve `START_URL` bu adrese güncellendi; eski ad
  kaynakta hiçbir yerde kalmadı.
- **Tanı ucu:** `GET /auth.php?action=health` — kimlik doğrulama istemez, tüm
  hız sınırlarının önünde çalışır ve JSON döner: PHP sürümü, hedef veritabanı
  sunucusu/adı, bağlantı durumu, tablo ve oda sayısı, şema damgaları.
  Veritabanına bağlanılamıyorsa `ok:false` + gerçek `db_error` ile 503 döner.
  Parola ve kullanıcı adı sızmaz (testle doğrulanıyor).
- Arayüz, API'den JSON yerine HTML geldiğinde artık HTTP durum kodunu ve tanı
  adresini gösteriyor; böylece "istekler yavaşlatıldı" mesajı dosya eksikliği
  ile gerçek hız sınırından ayırt edilebiliyor.

## Testler

Önkoşul: PHP 8.4 (curl, mbstring, pdo_mysql, openssl), MariaDB, Chromium +
puppeteer-core. Sunucu testlerde **üretimle aynı** yapılandırmayla ayağa
kalkar; taklit edilen hiçbir harici servis yoktur.

```bash
# tek komutla tam koşu (ortamı kendi onarır: php/chromium/mariadb/npm/SDK)
bash /home/user/.setup/run-tests.sh /home/user/projects/ASRA-fcm <etiket>

# ya da elle:
bash _tests/reset_env.sh          # DB + şema + referans kullanıcı
cd asra-site-fix && PHP_CLI_SERVER_WORKERS=8 \
  env ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
      ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_COOKIE_SECURE=0 \
  php -S 127.0.0.1:8090 ../_tests/test_router.php
```

| Suite | Kapsam |
|---|---|
| `bash _tests/no_notification_test.sh` | Bildirim sisteminin **tamamen kalktığını** kanıtlar: kaynak taraması (istemci + sunucu + manifest + APK), kaldırılan uçların 405/404 dönmesi, mesaj akışının bildirimsiz çalışması, tarayıcıda izin istenmemesi/servis işçisi kaydedilmemesi. Tarayıcı bölümü ayrıca **sıfır-hit boşta düzenini** ölçer: periyodik yoklama zamanlanmaz, boşta `get_messages` gitmez, başka kullanıcı yazınca rozet değişimiyle **tek** `get_messages` gider ve mesaj ekranda çizilir |
| `bash _tests/fresh_db_test.sh` | **Boş veritabanı / taşıma testi:** 0 tablolu bir veritabanına bağlanır, klasörde TEK .php olduğunu ve `auth.php` gömülü varsayılanının geçerli hesaba baktığını denetler; ilk isteğin 200 döndüğünü, 9 çekirdek tablonun ve 20 odanın otomatik oluştuğunu, kayıt→giriş→oda→mesaj akışının çalıştığını ve ikinci koşunun zararsız olduğunu kanıtlar |
| `bash _tests/e2e_test.sh` | davet/kayıt/giriş, erişim kodu, hit koruması, oturum kurtarma, 12 ay inaktiflik, CSRF |
| `bash _tests/message_delete_test.sh` | silme + 4 sn undo + kalıcı silme + sahiplik/çakışma hataları |
| `bash _tests/csrf_heal_test.sh` | 419 kendini-onarma (oturum kaybı) |
| `node _tests/cooldown_test.js` | 5 sn cooldown saniye saniye (Chromium) |
| `bash _tests/retention_countdown_test.sh` | **Saklama süresi / geri sayım:** sıfır-istek oda girişinde (önbellek dolu + rozet 0) sayacın `hesaplanıyor…`da takılı kalmadığı, kalan sürenin doğru hesaplandığı, vadesi dolmuş önbellek satırlarının **hayalet mesaj** olarak çizilmediği, bozuk zaman damgasının dürüst duruma düştüğü ve `INTERVAL 24 HOUR` yerine tek kaynak `MESSAGE_RETENTION_HOURS` kullanıldığı |
| `bash _tests/password_change_test.sh` | **Şifre değiştirme:** uç kaydı (GET 405 / oturumsuz 401 / CSRF'siz 419), güncel şifre doğrulaması, yeni şifre kurallarının **tüm dalları**, aynı şifre reddi, hash'in gerçekten değişmesi, `session_version`'ın tam 1 artması, diğer cihazların hatırla-anahtarlarının silinip bu cihazınkinin korunması, brute-force politikası (kural hatası bütçeyi tüketmez / başarı sıfırlar / 5'ten sonra 429 / kilit kullanıcı bazlı) ve arayüz: üç şifre alanı, Göster-Gizle, zayıf şifrenin **istek atılmadan** yerinde reddi, yetkili mesajı, uçtan uca eski şifre reddi + yeni şifre girişi |
| `bash _tests/i18n_test.sh` | **Çok dillilik (tr+en):** statik bütünlük (tr/en anahtar paritesi, koddaki her `t()` ve statik HTML'deki her `data-i18n*` değerinin sözlükte olması, `{param}` yer tutucu eşitliği, de/fr seçeneğinin kalkması) + API dil beyaz listesi (`en` kabul, `tr`ye dönüş) + gerçek Chromium'da uçtan uca: Türkçe arayüzün bozulmadığı, English seçilince ayarlar/menü/ana sayfa/şifre modalı/kural hatası/"şifremi unuttum" modalı/tarih biçiminde tek Türkçe karakter kalmadığı, sunucu hata mesajının İngilizceye çevrildiği, çıkıştan sonra giriş ekranının İngilizce kaldığı ve Türkçeye geri dönüşün çalıştığı |
| `bash _tests/approval_visibility_test.sh` | **Onay kuyruğu görünürlüğü:** bekleyen her hesabın (nedeni yazılmış/yazılmamış) `admin_pending_approvals` listesine düştüğü, sıralamanın gönderilmiş-önde olduğu, `submitted` bayrağı ve null gönderim zamanının dürüst döndüğü, admin doğrulamasız oturumun 403 aldığı, kabul/red kararlarının nedeni yazılmamış hesaplarda da çalıştığı ve gerçek Chromium'da admin panelinin nedeni yazılmamış hesabı kayıt tarihi + "katılma nedeni henüz yazılmadı" notuyla çizdiği |
| `bash _tests/csp_hash_check.sh` | CSP hash / içerik bütünlüğü |

Yardımcılar (suite'e dâhil değil):

| Araç | Görev |
|---|---|
| `python3 _tests/csp_hash_sync.py asra-site-fix/index.html` | Satır içi betiğin CSP `script-src` hash'ini **yerinde tazeler**. `index.html`'deki betik her değiştiğinde çalıştırılmalı; hash tutmazsa tarayıcı betiğin tamamını sessizce engeller |
| `node _tests/hit_olcum.js [oda-sn] [ana-ekran-sn]` | Gerçek hızda hit ölçümü: davetle girer, odaya girer ve ekrana dokunmadan hosta giden tüm `auth.php` isteklerini sayar |

### Son koşu

`bash .setup/run-tests.sh <proje-kökü> <etiket>`

Son tam koşu: `v31` (**bekleyenleri gör/yenile:** Herkesi Gör / Yenile düğmesi,
sayaç satırı, tıklama yutan 3 sn eylem kilidinin kökten çözümü, elle yenilemede
hız-sınırı beklemesinin atlanması ve eski sunucu sürümü uyarısı). Önceki: `v30`
(onay kuyruğu görünürlüğü), `v29` (çok dillilik onarımı: tr + en).

| Suite | Sonuç |
|---|---|
| `csp_hash_check` | ✓2 ✗0 |
| `no_notification_test` | ✓70 ✗0 |
| `fresh_db_test` | ✓30 ✗0 |
| `throttle_release_test` | ✓11 ✗0 |
| `legacy_schema_test` | ✓5 ✗0 |
| `e2e_test` | ✓53 ✗0 |
| `message_delete_test` | ✓29 ✗0 |
| `csrf_heal_test` | ✓5 ✗0 |
| `cooldown_test` | ✓20 ✗0 |
| `ui_fix_browser_test` | ✓18 ✗0 |
| `retention_countdown_test` | ✓45 ✗0 |
| `password_change_test` | ✓130 ✗0 |
| `i18n_test` | ✓65 ✗0 |
| `approval_visibility_test` | ✓62 ✗0 |
| **TOPLAM** | **✓545 ✗0** |

## Sunucuya yükleme

`asra-site-fix/` içindeki **3 dosya** (`auth.php`, `index.html`, `.htaccess`)
birlikte yüklenir — sürüm çifti olarak düşünün, ayrı güncelleme yapmayın.

Belge kökünde duruyorsa **silinmesi gerekenler**: `firebase-messaging-sw.js`
ve `asra-fcm-service-account.json`. Sunucu artık ikisini de kullanmıyor;
ikincisi bir RSA özel anahtarıdır ve web kökünde durmamalıdır.

APK: `asra-1.7.1.apk` cihazlara doğrudan kurulur (eski sürümün üzerine
güncelleme olarak da kurulur).

### Eski sürüm notu

1.6.x ve öncesindeki uygulamalar sunucudan itilen bir kanal kullanıyordu. O
uçlar kaldırıldığı için eski sürümler **anlık mesaj almaz** — kullanıcının
**1.7.0'a güncellemesi gerekir**. Eski sürüm sunucuyu çökertmez; yalnızca
gerçek zamanlı akış çalışmaz.
