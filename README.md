# GitHub kaynak paketi · ASRA 1.7.3

## 29 EKİM ÖZEL GÜN PAKETİ (build v35)

**Kapsam:** Özel gün teması (kırmızı-beyaz + Atatürk sözleri), iki özel gün
rozeti (Gazi Mustafa Kemal Atatürk / Türk Bayrağı) ve görev sistemi, yetkili
panelde iki AYRI anahtar (Özel Günler teması ve Özel Rozetler), profil
avatarındaki rozet taşma hatasının düzeltmesi.

- **İki bağımsız anahtar:** `Özel Günler (tema)` siteyi kırmızı-beyaz temaya
  ve ana ekrandaki söz şeridine geçirir; kapatınca site TAMAMEN eski
  görünümüne döner (kullanıcının koyu/açık tercihi korunur).
  `Özel Rozetler` rozetlerin görevle ALINABİLİR olmasını açıp kapatır.
  Anahtarlar ayrıca tarih penceresiyle (`event_*_starts_at/ends_at`, UTC)
  sınırlanabilir; tarih boşsa yalnızca anahtar belirleyicidir.
- **Rozetler her durumda mağazada:** pencere kapalıyken kart KALIR, "Al"
  düğmesi kilitlenir ve altında "🔒 Sadece özel günler için" yazar. Alınmış
  rozetler etkinlik bitse de envanterde ve profilde kalır, takılı kalabilir.
- **Görevler (ikisi farklı, orta zorluk):**
  Atatürk rozeti → 6 farklı kanala mesaj + toplam 30 mesaj + 200 AA Coin.
  Türk Bayrağı → toplam 30 dk aktiflik + 2 farklı günde giriş + 2000 AA Coin.
  Kartta her görevin GERÇEK ilerlemesi (12/30) ve ilerleme çubuğu görünür.
  "Toplam mesaj/kanal/aktif süre" hesabın geçmişinden sayılır; coin şartı
  anlık bakiyedir.
- **Mevcut çerçeve sistemi kullanılır:** rozet, `aa_frame_purchases`
  envanterine yazılır; takma/çıkarma `equip_frame` ile birebir aynıdır.
  Yeni uçlar: `admin_event_status` (GET), `admin_event_settings` (POST);
  `get_economy` yanıtına `event_badges` + `event` alanları eklendi.
- **Yeni tablolar (SQL yüklemek gerekmez):** `event_badge_claims`,
  `event_badge_quests`, `event_badges`, `event_activity_days`,
  `event_badge_channels`. DDL damga korumalıdır (`schema:event:v1`) —
  her istekte çalışmaz.
- **Avatar taşma hatası:** çerçeve işareti/madalyonu negatif konumla avatar
  kutusunun dışına taşıyordu. `.avatar-frame-wrap` sarmalayıcısı eklendi;
  profil rozet madalyonu artık kutunun içinde kalır. Kendi profilinde
  "fotoğraf değiştir" rozetiyle çakışmaması için madalyon sol-üsttedir.
- **CSP:** tema ve rozet stilleri mevcut `<style>` bloğuna eklendi, hash'ler
  güncellendi. JS'te inline `style` kullanılmadı (CSP onu engeller):
  ilerleme çubuğu `data-percent` + CSS ile çizilir.
- **Portre:** kamu malı (public domain) bir Atatürk fotoğrafından üretilen
  dairesel madalyon sayfaya gömülüdür (data: URI, dış istek yok).
- **Testler:** tam paket 685/685 geçti. Değişen test dosyaları:
  `_tests/approval_visibility_test.sh` (build v35 beklentisi) ve
  `_tests/no_notification_test.sh` (çok satıra bölünmüş gömülü görsel
  satırlarının taranmaması — "fcm" yanlış pozitifi).


**Bu kopya canlı dağıtım dosyası değildir.** `auth.php` yalnızca
`ASRA_DB_DSN`, `ASRA_DB_USER`, `ASRA_DB_PASSWORD` sunucu ortam değişkenleri
sağlanırsa veritabanına bağlanır. Canlı veritabanı parolası, GitHub tokenı ve
APK imza anahtarı/parolası bu pakete konulmadı. Eski Git geçmişinde yayımlanan
sırlar yalnızca yeni commit ile kaybolmaz; parola/tokenı döndürün ve geçmişi
ayrıca güvenli yöntemle temizleyin. Android APK'sı aynı eski imzayla güncellenir.

# ASRA

Site (PHP) + Android uygulaması (WebView, Kotlin) + tam E2E test altyapısı.

## Sürüm 1.7.3 (versionCode 30) — sunucu build **v35**

- **Geri tuşu kilitlenmez:** WebView yanıtı gelmezse zaman aşımıyla çıkar;
  renderer yeniden kurulurken/sayfa değişirken eski callback yeni ekrana işlemez.
- **Dosya/çekim:** Kamera uygulamasına URI izni ClipData ile aktarılır;
  fotoğraf ve video birlikte kabul edilirse tür seçimi açılır. Başlatılamayan
  kamera dosya seçimine düşer, tam boyutlu çekim küçük resme tercih edilir;
  tek dosyalı forma birden çok URI verilmez.
- **Dış uygulama bağlantıları:** e-posta ve SMS bağlantıları doğru Android
  SENDTO eylemini kullanır; telefon araması DIAL ile açılır.
- **Oturum taşıma:** yeni korumalı alanda oturum varsa eski cihaz-kilit-öncesi
  kayıt onu ezmez. İmza parolaları koddan kaldırıldı; imza anahtarı Git dışı.

**Önceki 1.7.2 iyileştirmeleri de korunur:**

- **Android geri tuşu** modal → menü → alt ekran → ana ekrandan çıkış sırasını
  izler; SPA geçmişinde eski giriş sayfasına gitmez.
- **Dış bağlantılar** uygulama WebView'ine değil sistem tarayıcısına gider.
  Uygulama yalnızca `https://asra.42web.io` kökenine güvenip native köprüyü
  orada çalıştırır; izin istekleri kökenle sınırlandırılır, açık HTTP ve
  üçüncü taraf çerezleri kapalıdır.
- **Dosya seçimi** kamera izni sormadan çalışır. Yalnızca doğrudan yakalama
  için izin istenir; fotoğraf/video türleri karışmaz. `rw` modundaki kamera
  dosyasını yanlışlıkla sıfırlama ve boş çekim artıkları giderildi.
- **Oturum** çerezleri güncelleme sonrası ayrı ayrı geri kurulur, oturum
  kapatıldığında kaydedilmiş kopyalar kaldırılır; eski cihaz-kilit-öncesi
  kayıtlar korumalı alana taşınır. User-Agent gerçek APK sürümüdür.
- **Sunucu:** 24 saati dolan mesaj/form/yorum, fiziksel temizlikten önce bile
  şikayete kapalıdır (404); önceki şikayet kanıtları korunur. API değişikliği
  için `asra-yukleme/auth.php` yüklenmelidir.

Dağıtım: `asra-yukleme/` (3 sunucu dosyası + `asra-1.7.3.apk` + yönerge).
Mevcut kurulumun üzerine güncellenebilir; imza sertifikası değişmedi.
Android fiziksel cihaz/emülatör kurulumu bu ortamda yapılmadı; JVM + Node
mantık testleri, gerçek Chromium site smoke'u ve imzalı APK denetimi yapıldı.

## Yapı

| Klasör | İçerik |
|---|---|
| `asra-site-fix/` | **Güncel site** — `auth.php` (API), `index.html` (arayüz), `.htaccess` |
| `asra-apk-build/` | Android kaynak (Kotlin) + derlenmiş **`asra-1.7.3.apk`** (imzalı) |
| `_tests/` | BASH/CLI + Chromium E2E suite'leri |
| `.setup/` | Test koşucusu ve ortam kurulum betikleri (`run-tests.sh`, `install-testenv.sh`, `install-toolchain.sh`) — proje kökünde `.setup/` yolunda durur |
| `asra-yukleme/` | Veritabanı ortam değişkenleri sağlanınca yüklenebilecek **örnek paket**: `auth.php` + `index.html` + `.htaccess` + APK + `YUKLEME-NOTU.txt`. Kaynak `asra-site-fix/`'tir; bu klasör onun kopyasıdır ve her değişiklikte yeniden eşitlenir (sha256 ile doğrulanır) |

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
- **Şema kendini kurar:** `ensureCoreSchema()` 9 çekirdek tabloyu ve 21 odalık
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
  doğrulanır (bu paket sunucusu: `v35`).
- **Hit koruması (kalıcı):** sıcak okuma varsayılanı 180/dk (tavan 600),
  oturum başına 90/dk + uç bazlı limitler. Eski davetiye/erişim kodu kapısı yok.
- **Güvenli oturum:** hatırla-token ile sessiz oturum kurtarma (uygulama
  güncellemesinde hesap düşmez). Kimlik eşleştirmesi yalnızca benzersiz token +
  uygulama kimliğiyle.
- **CSP:** inline style/script SHA-256 hash'li (katı) ve `connect-src 'self'` —
  üçüncü taraf hiçbir kaynağa bağlantı açılmaz.
  Güncel hash'ler — style: `f47FemOrhJQSvXYl5Gjl5+1zbkkAA1yn3Cx7kfUyQ3w=`,
  script: `/kFtz98lyrYGBps6wrf/0P8nidGO3ujxj/muUQfHyi8=`.
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

Tavanlar: sunucu sıcak-okuma varsayılanı **180/dk**, üst sınırı 600/dk;
oturum bütçesi **90/dk**. İstemci arka plan bütçesi 6/60 sn. Boşta oda
ritmi ilk beş tur 60 sn, sonra 120 sn ve son olarak **180 sn** tavandır;
ekran gizliyken istek yoktur.

### Hit kotası hesabı

Sayfa açılışı 1 istek (gömülü CSS/JS/görseller); başında durulmayan açık
sayfada kademeli ritimle yaklaşık 2 saatte 45, 4 saatte 85 ve 24 saatte
485 `get_rooms` isteği üretilir. Bu, önceki sabit 60 sn ritminden daha
seyrektir. Aktif sohbet gönderimleri ve yeni mesajlar ek trafik yaratır;
50.000'lik kotanın günlük mü aylık mı olduğu hosting tarifesine bağlıdır.
Gecikmeyi/gideri değiştirmek için `ROOM_PULSE_IDLE_MAX_MS` ve sunucu
sınırı için `ASRA_HOT_READS_PER_MIN` (üst sınır 600) kullanılır.

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

- Dosya: `asra-apk-build/asra-1.7.3.apk`
- SHA-256: `a08b7323aae2233aa6c18223fcde518141002f8b1b9bb872fea740319e55f996`
- Boyut: 718.560 bayt
- Sürüm: `versionCode 30` / `versionName 1.7.3`
- İzinler (yalnızca 5): `INTERNET`, `ACCESS_NETWORK_STATE`, `CAMERA`,
  `RECORD_AUDIO`, `MODIFY_AUDIO_SETTINGS` — **bildirim izni yok**
- Manifest'te `service`/`receiver` yok; yalnızca `MainActivity` +
  `CaptureFileProvider`. DEX içinde Firebase ve `NotificationManager` yok
- İmza: v2 + v3 — **önceki sürümle aynı sertifika** (`3527c221…`), bu yüzden
  eski sürüm kurulu cihazlara doğrudan **güncelleme olarak** kurulur
- Derleme: `ASRA_BUILD_HOME=/home/user/.asra-build bash asra-apk-build/tools/build_apk.sh`
  (JDK 17 + Gradle 8.5 + Android SDK build-tools 34). Yerel, Git dışı
  `asra-release.jks` ve `ASRA_SIGN_STORE_PASSWORD` / `ASRA_SIGN_KEY_PASSWORD`
  ortam değişkenleri veya Git dışı `signing.properties` gerekir; parolaları
  kaynak koduna veya terminal çıktısına eklemeyin.

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
  9 çekirdek tablo + ek tablolar + 21 odalık katalog (`ensureCoreSchema`).
  Tüm ifadeler `IF NOT EXISTS` / `INSERT IGNORE` olduğu için tekrar çalışmak
  zararsızdır, mevcut veri ezilmez.
- Dışarıdan yüklenen SQL yalnızca 15 oda içeriyorsa eksik odalar otomatik
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
puppeteer-core. Test sunucusu yerel veritabanına bağlanır; sıcak-okuma
sınırı kota testi için 60/dk olarak ayarlanır. RSS akışı yerel fixture kullanır.

```bash
# tek komutla tam koşu (ortamı kendi onarır: php/chromium/mariadb/npm/SDK)
bash .setup/run-tests.sh "$PWD" v1.7.3

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
| `bash _tests/fresh_db_test.sh` | **Boş veritabanı / taşıma testi:** 0 tablolu bir veritabanına bağlanır, klasörde TEK .php olduğunu ve `auth.php`'nin DB ortam ayarlarını okuduğunu denetler; ilk isteğin 200 döndüğünü, 9 çekirdek tablonun ve 20 odanın otomatik oluştuğunu, kayıt→giriş→oda→mesaj akışının çalıştığını ve ikinci koşunun zararsız olduğunu kanıtlar |
| `bash _tests/e2e_test.sh` | davet/kayıt/giriş, erişim kodu, hit koruması, oturum kurtarma, 12 ay inaktiflik, CSRF |
| `bash _tests/message_delete_test.sh` | silme + 4 sn undo + kalıcı silme + sahiplik/çakışma hataları |
| `bash _tests/csrf_heal_test.sh` | 419 kendini-onarma (oturum kaybı) |
| `node _tests/cooldown_test.js` | 5 sn cooldown saniye saniye (Chromium) |
| `bash _tests/retention_countdown_test.sh` | **Saklama süresi / geri sayım:** sıfır-istek oda girişinde (önbellek dolu + rozet 0) sayacın `hesaplanıyor…`da takılı kalmadığı, kalan sürenin doğru hesaplandığı, vadesi dolmuş önbellek satırlarının **hayalet mesaj** olarak çizilmediği, bozuk zaman damgasının dürüst duruma düştüğü ve `INTERVAL 24 HOUR` yerine tek kaynak `MESSAGE_RETENTION_HOURS` kullanıldığı |
| `bash _tests/password_change_test.sh` | **Şifre değiştirme:** uç kaydı (GET 405 / oturumsuz 401 / CSRF'siz 419), güncel şifre doğrulaması, yeni şifre kurallarının **tüm dalları**, aynı şifre reddi, hash'in gerçekten değişmesi, `session_version`'ın tam 1 artması, diğer cihazların hatırla-anahtarlarının silinip bu cihazınkinin korunması, brute-force politikası (kural hatası bütçeyi tüketmez / başarı sıfırlar / 5'ten sonra 429 / kilit kullanıcı bazlı) ve arayüz: üç şifre alanı, Göster-Gizle, zayıf şifrenin **istek atılmadan** yerinde reddi, yetkili mesajı, uçtan uca eski şifre reddi + yeni şifre girişi |
| `bash _tests/i18n_test.sh` | **Çok dillilik (tr+en):** statik bütünlük (tr/en anahtar paritesi, koddaki her `t()` ve statik HTML'deki her `data-i18n*` değerinin sözlükte olması, `{param}` yer tutucu eşitliği, de/fr seçeneğinin kalkması) + API dil beyaz listesi (`en` kabul, `tr`ye dönüş) + gerçek Chromium'da uçtan uca: Türkçe arayüzün bozulmadığı, English seçilince ayarlar/menü/ana sayfa/şifre modalı/kural hatası/"şifremi unuttum" modalı/tarih biçiminde tek Türkçe karakter kalmadığı, sunucu hata mesajının İngilizceye çevrildiği, çıkıştan sonra giriş ekranının İngilizce kaldığı ve Türkçeye geri dönüşün çalıştığı |
| `bash _tests/approval_visibility_test.sh` | **Onay kuyruğu görünürlüğü:** bekleyen her hesabın (nedeni yazılmış/yazılmamış) `admin_pending_approvals` listesine düştüğü, sıralamanın gönderilmiş-önde olduğu, `submitted` bayrağı ve null gönderim zamanının dürüst döndüğü, admin doğrulamasız oturumun 403 aldığı, kabul/red kararlarının nedeni yazılmamış hesaplarda da çalıştığı ve gerçek Chromium'da admin panelinin nedeni yazılmamış hesabı kayıt tarihi + "katılma nedeni henüz yazılmadı" notuyla çizdiği |
| `bash _tests/info_room_test.sh` | Bilgi Odası rehberi, 24 saat saklama, şikayet kotası + vadesi dolmuş hedefler ve moderasyon kanıtı (152 kontrol) |
| `bash _tests/apk_release_test.sh` | APK sürümü, imza/sertifika devamlılığı, izinler, açık HTTP engeli (11 kontrol) |
| `node _tests/apk_webview_logic_test.js` | Android geri köprüsü ve fetch/cihaz kimliği için köken politikası (4 kontrol) |
| `bash _tests/csp_hash_check.sh` | CSP hash / içerik bütünlüğü |

Yardımcılar (suite'e dâhil değil):

| Araç | Görev |
|---|---|
| `python3 _tests/csp_hash_sync.py asra-site-fix/index.html` | Satır içi betiğin CSP `script-src` hash'ini **yerinde tazeler**. `index.html`'deki betik her değiştiğinde çalıştırılmalı; hash tutmazsa tarayıcı betiğin tamamını sessizce engeller |
| `node _tests/hit_olcum.js [oda-sn] [ana-ekran-sn]` | Gerçek hızda hit ölçümü: odaya girer ve ekrana dokunmadan hosta giden tüm `auth.php` isteklerini sayar |
| `bash _tests/info_ui_smoke_test.sh` | Elle çalışan gerçek Chromium kontrolü: kart, sekmeler, kalp, Android geri köprüsü (23 UI) |
| `cd asra-apk-build && gradle :app:testDebugUnitTest` | Android/JVM testleri: URL, oturum çerezi, kamera/geri tuşu/bağlantı politikası (15 kontrol) |

### Son koşu

`bash .setup/run-tests.sh <proje-kökü> <etiket>`

Son tam koşu: `v1.7.3` — PHP/API + Chromium + imzalı APK denetimi.

| Suite | Sonuç |
|---|---|
| `csp_hash_check` | ✓2 ✗0 |
| `apk_release` | ✓11 ✗0 |
| `apk_webview_logic` | ✓4 ✗0 |
| `no_notification` | ✓76 ✗0 |
| `fresh_db_test` | ✓29 ✗0 |
| `throttle_release` | ✓11 ✗0 |
| `legacy_schema` | ✓5 ✗0 |
| `e2e_test` | ✓38 ✗0 |
| `message_delete` | ✓29 ✗0 |
| `csrf_heal_test` | ✓5 ✗0 |
| `cooldown_test` | ✓20 ✗0 |
| `ui_fix_browser` | ✓18 ✗0 |
| `retention_countdown` | ✓45 ✗0 |
| `password_change` | ✓130 ✗0 |
| `i18n` | ✓65 ✗0 |
| `approval_visibility` | ✓62 ✗0 |
| `info_room_test` | ✓152 ✗0 |
| **TOPLAM** | **✓702 ✗0** |

Ek olarak Gradle `:app:testDebugUnitTest` **9/9**; elle çalışan
`bash _tests/info_ui_smoke_test.sh` (gerçek Chromium) **UI 23/23,
JS hatası 0; kabuk 6/6**. Android cihaz/emülatör testi yapılmadı.

## Sunucuya yükleme

`asra-site-fix/` içindeki **3 dosya** (`auth.php`, `index.html`, `.htaccess`)
birlikte yüklenir — sürüm çifti olarak düşünün, ayrı güncelleme yapmayın.

Belge kökünde duruyorsa **silinmesi gerekenler**: `firebase-messaging-sw.js`
ve `asra-fcm-service-account.json`. Sunucu artık ikisini de kullanmıyor;
ikincisi bir RSA özel anahtarıdır ve web kökünde durmamalıdır.

APK: `asra-1.7.3.apk` cihazlara doğrudan kurulur (1.7.1/1.7.2 üzerine aynı
imzayla güncelleme olur). Şikayet/API düzeltmesi için yeni `auth.php` de
yüklenir; canlı site sürümünden APK dosyası tek başına sorumlu değildir.

### Eski sürüm notu

1.6.x ve öncesindeki uygulamalar sunucudan itilen bir kanal kullanıyordu. O
uçlar kaldırıldığı için eski sürümler **anlık mesaj almaz** — kullanıcının
**1.7.0'a güncellemesi gerekir**. Eski sürüm sunucuyu çökertmez; yalnızca
gerçek zamanlı akış çalışmaz.
