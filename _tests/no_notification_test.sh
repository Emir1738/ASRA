#!/usr/bin/env bash
# ============================================================================
#  ASRA "Bildirim sistemi kaldırıldı" doğrulaması — BASH/CLI
#
#  Üç katman:
#   1) KAYNAK TARAMASI  : istemci/sunucu/APK kaynaklarında hiçbir bildirim,
#                         FCM, push, servis işçisi veya izin kalıntısı yok.
#   2) SUNUCU DAVRANIŞI : kaldırılan uçlar 404 döner; mesaj gönderme, oda
#                         listesi ve oturum akışı bildirim olmadan çalışır.
#   3) TARAYICI         : sayfa Notification izni İSTEMEZ, servis işçisi
#                         KAYDETMEZ, odaya girince yoklama zamanlayıcısı
#                         kurulur ve gelen mesaj ekranda belirir.
#
#  Bu suite, kaldırma işleminin hem "tam" hem de "zararsız" olduğunu kanıtlar.
# ============================================================================
set -u
BASE="http://127.0.0.1:8090/auth.php"
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
SITE="$ROOT/asra-site-fix"
APK="$ROOT/asra-apk-build/asra-1.7.1.apk"
BT="${ANDROID_SDK_ROOT:-/home/user/.asra-build/android-sdk}/build-tools/34.0.0"
TDIR="$SDIR/run_nonotif"
rm -rf "$TDIR"; mkdir -p "$TDIR"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql -N asra_test -e "$1"; }

jget() { python3 -c "import json,sys
try:
    d=json.load(open(sys.argv[1]))
except Exception:
    print('__PARSE_FAIL__'); sys.exit(0)
v=d
for k in sys.argv[2].strip('\$').strip('.').split('.'):
    if k=='': continue
    if isinstance(v,dict) and k in v: v=v[k]
    else: v='__MISSING__'; break
if v is None: print('null')
else: print(v if not isinstance(v,(dict,list)) else json.dumps(v))" "$1" "$2"; }

# Dosyada kalıntı arar: eşleşme YOKSA geçer
expect_absent() { # etiket, dosya, kalıp
  # Gömülü base64 görseller (data: URI) rastgele harf dizileri içerir ve
  # 'fcm' gibi kısa kalıplarda yanlış pozitif üretir; bu satırlar atlanır.
  local tmp="$TDIR/scan.tmp"
  grep -v 'data:' "$2" > "$tmp" 2>/dev/null
  local hits
  hits=$(grep -Eic "$3" "$tmp" 2>/dev/null || true)
  [ -z "$hits" ] && hits=0
  if [ "$hits" -eq 0 ]; then ok "$1"; else
    bad "$1 — $hits eşleşme: $(grep -Ein "$3" "$tmp" | head -2 | tr '\n' ' ' | cut -c1-140)"
  fi
}

echo "== 1) Kaynak taraması: istemci (index.html) =="
expect_absent "index.html: FCM/Firebase izi yok"        "$SITE/index.html" 'fcm|firebase|gstatic'
expect_absent "index.html: push kanalı API'leri yok"    "$SITE/index.html" '__asraPush|startPushChannel|stopPushChannel|registerPushToken|handlePushEvent|appendPushedMessage|coalescePush|bumpLocalUnread|pushedMessages'
expect_absent "index.html: Notification API kullanımı yok" "$SITE/index.html" 'new Notification|Notification\.requestPermission|Notification\.permission|requestNotifyPermission|notifyRooms'
expect_absent "index.html: servis işçisi kaydı yok"     "$SITE/index.html" "serviceWorker\.register"
expect_absent "index.html: poll_push istemci referansı yok" "$SITE/index.html" 'poll_push'
expect_absent "index.html: ntfy kalıntısı yok"          "$SITE/index.html" 'ntfy'
if grep -q "connect-src 'self';" "$SITE/index.html"; then
  ok "index.html: CSP connect-src yalnızca kendi kökeni"
else
  bad "index.html: CSP connect-src beklenenden farklı"
fi
[ ! -e "$SITE/firebase-messaging-sw.js" ] && ok "firebase-messaging-sw.js silinmiş" || bad "firebase-messaging-sw.js hâlâ duruyor"

echo
echo "== 2) Kaynak taraması: sunucu (auth.php) =="
expect_absent "auth.php: FCM/push fonksiyonu yok"  "$SITE/auth.php" 'function (fcm|ensurePushInfra|validPushTopic|validFcmToken|notifyPushRecipients|notifyApprovalDecision|actionRegisterPush|actionRegisterPushToken|actionPollPush|maybeRegisterAppPushTopic)'
expect_absent "auth.php: push tablolarına dokunmuyor" "$SITE/auth.php" 'user_push_tokens|push_events|user_push_topics'
expect_absent "auth.php: push aksiyonları kayıtlı değil" "$SITE/auth.php" "'register_push'|'register_push_token'|'poll_push'"
expect_absent "auth.php: FCM ortam değişkeni okunmuyor" "$SITE/auth.php" 'ASRA_FCM_'
php -l "$SITE/auth.php" >/dev/null 2>&1 && ok "auth.php: php -l temiz" || bad "auth.php: php -l hata verdi"
python3 -c "
import io,re
h=io.open('$SITE/index.html',encoding='utf-8').read()
io.open('$TDIR/inline.js','w',encoding='utf-8').write(re.search(r'<script>(.*)</script>', h, re.S).group(1))
" && node --check "$TDIR/inline.js" >/dev/null 2>&1 \
  && ok "index.html: satır içi JS söz dizimi temiz" || bad "index.html: satır içi JS söz dizimi bozuk"

echo
echo "== 3) Kaynak taraması: Android (manifest + APK) =="
expect_absent "AndroidManifest.xml: POST_NOTIFICATIONS yok" "$ROOT/asra-apk-build/app/src/main/AndroidManifest.xml" 'POST_NOTIFICATIONS'
expect_absent "AndroidManifest.xml: boot/alarm/ön plan servis izni yok" "$ROOT/asra-apk-build/app/src/main/AndroidManifest.xml" 'RECEIVE_BOOT_COMPLETED|SCHEDULE_EXACT_ALARM|USE_EXACT_ALARM|FOREGROUND_SERVICE|WAKE_LOCK|VIBRATE'
if [ -f "$APK" ] && [ -x "$BT/aapt2" ]; then
  BADGE=$("$BT/aapt2" dump badging "$APK" 2>/dev/null)
  echo "$BADGE" | grep -q "versionName='1.7.1'" && ok "APK: versionName 1.7.1" || bad "APK: versionName 1.7.1 değil"
  if echo "$BADGE" | grep -q 'POST_NOTIFICATIONS'; then bad "APK: POST_NOTIFICATIONS izni duruyor"; else ok "APK: bildirim izni YOK"; fi
  PERMS=$(echo "$BADGE" | grep -c '^uses-permission' || true)
  check_eq "APK: izin sayısı 5 (INTERNET, ACCESS_NETWORK_STATE, CAMERA, RECORD_AUDIO, MODIFY_AUDIO_SETTINGS)" "5" "$PERMS"
  DEX=$("$BT/dexdump" "$APK" 2>/dev/null)
  if echo "$DEX" | grep -q 'com/google/firebase'; then bad "APK: DEX içinde Firebase sınıfı var"; else ok "APK: DEX içinde Firebase yok"; fi
  if echo "$DEX" | grep -qE 'NotificationManager|NotificationChannel'; then bad "APK: DEX içinde bildirim API'si var"; else ok "APK: DEX içinde bildirim API'si yok"; fi
  TREE=$("$BT/aapt2" dump xmltree --file AndroidManifest.xml "$APK" 2>/dev/null)
  if echo "$TREE" | grep -qE 'E: (service|receiver)'; then bad "APK: manifest'te servis/alıcı var"; else ok "APK: manifest'te servis/alıcı yok"; fi
elif [ ! -f "$APK" ]; then
  bad "APK bulunamadı ($APK)"
else
  # build-tools yoksa derin denetim (badging/dexdump) yapılamaz; bu bir
  # ÜRÜN hatası değil ortam eksiğidir. Sessizce atlanmaz, açıkça raporlanır.
  echo "  ! Android build-tools yok ($BT) — APK derin denetimi atlandı."
  echo "    Kurulum: bash /home/user/.setup/install-toolchain.sh"
fi

echo
echo "== 4) Sunucu davranışı =="
bash "$SDIR/reset_env.sh" >/dev/null
sql "SELECT 1" >/dev/null && ok "veritabanı hazır" || bad "veritabanı kurulamadı"

UA="E2E-NoNotif/1.0"
JAR="$TDIR/jar.txt"
rm -f "$JAR"
curl -s -A "$UA" -c "$JAR" "$BASE?action=session" -o "$TDIR/r.json"
CSRF=$(jget "$TDIR/r.json" '$.csrf_token')
# NOT: "${2:-{}}" YAZILMAZ — bash ilk } ile genişlemeyi kapatır ve gövdenin
# sonuna fazladan bir } ekler (INVALID_JSON). Varsayılan tırnak içine alınır.
post() { curl -s -o "$TDIR/r.json" -w '%{http_code}' -A "$UA" -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" -d "${2:-"{}"}" "$BASE?action=$1"; }
get()  { curl -s -o "$TDIR/r.json" -w '%{http_code}' -A "$UA" -b "$JAR" -c "$JAR" "$BASE?action=$1"; }

curl -s -A "$UA" -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json" >/dev/null
curl -s -A "$UA" -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"username":"nonotif1","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json" >/dev/null
sql "UPDATE users SET approval_status='approved' WHERE username='nonotif1'"
CODE=$(curl -s -o "$TDIR/r.json" -w '%{http_code}' -A "$UA" -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"username":"nonotif1","password":"Test123!xyz"}' "$BASE?action=login")
check_eq "giriş 200" "200" "$CODE"
check_eq "giriş başarılı" "True" "$(jget "$TDIR/r.json" '$.ok')"
# Giriş yanıtı csrf_token DÖNDÜRMEZ (yalnızca okuma aksiyonları döndürür) ve
# giriş oturumu yenilediği için giriş öncesi belirteç geçersizdir; eski
# belirteçle gönderim 419 CSRF_FAILED verir. Bu yüzden oturumdan tazelenir.
CSRF=$(curl -s -A "$UA" -b "$JAR" -c "$JAR" "$BASE?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin).get('csrf_token',''))")
[ -n "$CSRF" ] && ok "CSRF belirteci tazelendi" || bad "CSRF belirteci alınamadı"

# Kaldırılan uçlar: POST yazma listesinde olmadıkları için 405, GET okuma
# listesinde olmadıkları ve aksiyon haritasında bulunmadıkları için 404 döner.
# Her iki yolda da uç "tanınmıyor"; ikisi de bildirim üretmez.
for act in register_push register_push_token poll_push; do
  CODE=$(post "$act" '{}')
  check_eq "$act -> POST reddedildi (405)" "405" "$CODE"
  CODE=$(get "$act")
  ERR=$(jget "$TDIR/r.json" '$.error.code')
  check_eq "$act -> GET 404" "404" "$CODE"
  check_eq "$act -> ACTION_NOT_FOUND" "ACTION_NOT_FOUND" "$ERR"
done

ROOM=$(sql "SELECT id FROM rooms ORDER BY id LIMIT 1")
[ -z "$ROOM" ] && ROOM=1
CODE=$(get "get_rooms")
check_eq "get_rooms 200 (bildirim altyapısı olmadan)" "200" "$CODE"
# NOT: sunucu alanı "message" (content DEĞİL); yanlış alan 422 EMPTY_MESSAGE verir.
CODE=$(post "send_message" "{\"room_id\":$ROOM,\"message\":\"Bildirim sistemi kaldirildi testi\"}")
if [ "$CODE" = "201" ]; then ok "send_message 201"; else bad "send_message 201 — HTTP $CODE: $(head -c 200 "$TDIR/r.json")"; fi
MID=$(jget "$TDIR/r.json" '$.message_id')
if [ -n "$MID" ] && [ "$MID" != "__MISSING__" ]; then ok "send_message message_id döndü ($MID)"; else bad "send_message message_id dönmedi"; fi
# push_events tablosu artık hiç yazılmamalı
if sudo mysql -N asra_test -e "SHOW TABLES LIKE 'push_events'" | grep -q push_events; then
  ROWS=$(sql "SELECT COUNT(*) FROM push_events")
  check_eq "push_events tablosuna yazılmıyor" "0" "$ROWS"
else
  ok "push_events tablosu hiç oluşturulmadı"
fi
CODE=$(get "get_messages&room_id=$ROOM")
check_eq "get_messages 200" "200" "$CODE"
if grep -q "Bildirim sistemi kaldirildi testi" "$TDIR/r.json"; then ok "mesaj listede görünüyor"; else bad "mesaj listede yok"; fi

echo
echo "== 5) Tarayıcı: izin istenmez, yoklama çalışır =="
export NODE_PATH=/home/user/.asra-tests/node_modules
if node "$SDIR/no_notification_browser_test.js" "$TDIR"; then
  ok "tarayıcı suite'i başarılı"
else
  bad "tarayıcı suite'i başarısız"
fi
while read -r line; do
  case "$line" in
    PASS\ *) PASS=$((PASS+$(echo "$line" | cut -d' ' -f2))) ;;
    FAIL\ *) FAIL=$((FAIL+$(echo "$line" | cut -d' ' -f2))) ;;
  esac
done < <(cat "$TDIR/browser_counters.txt" 2>/dev/null || true)

echo
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
exit "$FAIL"
