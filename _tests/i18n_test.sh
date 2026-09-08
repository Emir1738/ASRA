#!/usr/bin/env bash
# ============================================================================
#  ÇOK DİLLİLİK (tr + en) test paketi
#
#  İki katman doğrulanır:
#    1) STATİK (i18n_check.js, node): sözlük tr/en anahtar paritesi, koddaki
#       her t('anahtar') ve her data-i18n değerinin sözlükte varlığı, {param}
#       yer tutucu eşitliği, de/fr seçeneğinin kalktığı. Tarayıcı gerektirmez.
#    2) UÇTAN UCA (i18n_browser_test.js, gerçek Chromium + PHP/MySQL): Türkçe
#       arayüzün bozulmadığı, English seçilince üye yüzeylerinde Türkçe
#       sızıntı kalmadığı, sunucu hata mesajının çevrildiği, çıkıştan sonra
#       giriş ekranının İngilizce kaldığı ve Türkçeye dönüşün çalıştığı.
#
#  Kullanım: bash _tests/i18n_test.sh
#  Çıkış: FAIL sayısı (0 = hepsi geçti).
# ============================================================================
set -u
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run_i18n"
rm -rf "$TDIR"; mkdir -p "$TDIR"
PORT="${PORT:-8097}"
BASE="http://127.0.0.1:$PORT/auth.php"
UI_USER="i18nui"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql -N asra_test -e "$1" 2>/dev/null; }

echo "== 0) Statik bütünlük denetimi (node) =="
set +e
node "$SDIR/i18n_check.js" "$ROOT/asra-site-fix/index.html" > "$TDIR/static.log" 2>&1
STATIC_EXIT=$?
set +e
cat "$TDIR/static.log"
STATIC_PASS=$(grep -oE 'SONUC: ✓[0-9]+' "$TDIR/static.log" | grep -oE '[0-9]+' || echo 0)
STATIC_FAIL=$(grep -oE '✗[0-9]+' "$TDIR/static.log" | tail -1 | grep -oE '[0-9]+' || echo 0)
for i in $(seq 1 "${STATIC_PASS:-0}"); do PASS=$((PASS+1)); done
for i in $(seq 1 "${STATIC_FAIL:-0}"); do FAIL=$((FAIL+1)); done
check_eq "statik denetim çıkış kodu" "0" "$STATIC_EXIT"

echo "== 1) Ortam sıfırdan kurulur =="
bash "$SDIR/reset_env.sh" >/dev/null 2>&1
sql "SELECT 1" >/dev/null 2>&1 && ok "veritabanı hazır" || bad "veritabanı kurulamadı"

# Tarayıcı kullanıcısı DOĞRUDAN SQL ile yaratılır: kayıt akışı cihazı hesaba
# bağlar (assertDeviceMayUseAccount), Chromium farklı cihaz kimliğiyle geldiği
# için giriş reddedilirdi. reset_env.sh uiadmin'i de aynı yöntemle kurar.
UIHASH=$(php -r "echo password_hash('Test123!xyz', PASSWORD_DEFAULT);")
sql "INSERT INTO users (username, password_hash, avatar_color, approval_status, online_status, language, last_seen, created_at)
     VALUES ('$UI_USER','$UIHASH','c1','approved',0,'tr',UTC_TIMESTAMP(),UTC_TIMESTAMP())
     ON DUPLICATE KEY UPDATE password_hash='$UIHASH', approval_status='approved', language='tr'"
UIDUI=$(sql "SELECT id FROM users WHERE username='$UI_USER'")
[ -n "$UIDUI" ] && ok "tarayıcı kullanıcısı $UI_USER hazır (id=$UIDUI)" || bad "$UI_USER oluşturulamadı"

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true; sleep 1
( cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 PHP_CLI_SERVER_WORKERS=4 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_HOT_READS_PER_MIN=600 \
    php -S "127.0.0.1:$PORT" "$SDIR/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -m 2 -o /dev/null -w '%{http_code}' "$BASE?action=session" || echo 000)
  [ "$CODE" = "200" ] && break
  sleep 0.5
done
check_eq "sunucu ayakta ($BASE)" "200" "$CODE"

echo "== 2) Sunucu dil beyaz listesi 'en' kabul ediyor =="
JAR="$TDIR/jar.txt"; rm -f "$JAR"
UA="i18n-Test/1.0"
curl -s -m 5 -c "$JAR" -b "$JAR" -A "$UA" "$BASE?action=session" -o "$TDIR/s.json"
# CSRF jetonu YALNIZCA X-CSRF-Token başlığından okunur (auth.php: HTTP_X_CSRF_TOKEN).
# Başarılı yanıtlar jeton DÖNDÜRMEZ (login anahtarları: ok/message/user); jeton
# yalnızca hata ayrıntılarında tazelenir. Bu yüzden tok() mevcut değeri korur.
tok() { python3 -c "import json,sys
d=json.load(open(sys.argv[1]))
det=(d.get('error') or {}).get('details') or {}
t=d.get('csrf_token') or (det.get('csrf_token') if isinstance(det,dict) else '') or ''
print(t or sys.argv[2])" "$1" "$2" 2>/dev/null || echo "$2"; }
CSRF=$(tok "$TDIR/s.json" "")
[ -n "$CSRF" ] && ok "csrf jetonu alındı" || bad "csrf jetonu alınamadı"
curl -s -m 5 -c "$JAR" -b "$JAR" -A "$UA" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d "{\"code\":\"DAVETDAVETJSJEJSNFJSNDJSDNFH\"}" "$BASE?action=check_invite_code" -o "$TDIR/i.json"
curl -s -m 5 -c "$JAR" -b "$JAR" -A "$UA" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d "{\"username\":\"$UI_USER\",\"password\":\"Test123!xyz\"}" "$BASE?action=login" -o "$TDIR/l.json"
CSRF=$(tok "$TDIR/l.json" "$CSRF")
check_eq "giriş (API)" "True" "$(python3 -c "import json;print(json.load(open('$TDIR/l.json')).get('ok'))" 2>/dev/null)"
curl -s -m 5 -c "$JAR" -b "$JAR" -A "$UA" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d '{"theme":"dark","language":"en","show_read_receipts":true,"show_last_seen":true}' \
  "$BASE?action=update_settings" -o "$TDIR/u.json"
check_eq "update_settings language=en (API)" "True" "$(python3 -c "import json;print(json.load(open('$TDIR/u.json')).get('ok'))" 2>/dev/null)"
check_eq "dil veritabanına yazıldı" "en" "$(sql "SELECT language FROM users WHERE username='$UI_USER'")"
# Türkçe'ye dönüş de kabul edilmeli (geri dönüş yolu kanıtı).
curl -s -m 5 -c "$JAR" -b "$JAR" -A "$UA" -H "X-CSRF-Token: $CSRF" -H 'Content-Type: application/json' \
  -d '{"theme":"dark","language":"tr","show_read_receipts":true,"show_last_seen":true}' \
  "$BASE?action=update_settings" -o "$TDIR/u2.json"
check_eq "update_settings language=tr (API)" "True" "$(python3 -c "import json;print(json.load(open('$TDIR/u2.json')).get('ok'))" 2>/dev/null)"

echo "== 3) Uçtan uca arayüz (gerçek Chromium) =="
export NODE_PATH=/home/user/.asra-tests/node_modules
set +e
ORIGIN="http://127.0.0.1:$PORT" TEST_USER="$UI_USER" TEST_PASSWORD="Test123!xyz" \
  SHOT_DIR="${SHOT_DIR:-}" \
  node "$SDIR/i18n_browser_test.js" "$TDIR" 2>&1 | tail -70
NODE_EXIT=${PIPESTATUS[0]}
set +e
if [ ! -f "$TDIR/browser_counters.txt" ]; then
  bad "tarayıcı testi sonuç üretmedi (node exit=$NODE_EXIT)"
fi
while read -r k v; do
  case "$k" in
    PASS) for i in $(seq 1 "${v:-0}"); do ok "çok dillilik arayüz doğrulaması"; done ;;
    FAIL) for i in $(seq 1 "${v:-0}"); do bad "çok dillilik arayüz doğrulaması"; done ;;
  esac
done < <(cat "$TDIR/browser_counters.txt" 2>/dev/null || true)

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] && echo "TÜM TESTLER GEÇTİ" || { echo "--- php.log ---"; tail -14 "$TDIR/php.log"; echo "--- static.log ---"; tail -20 "$TDIR/static.log"; }
rm -rf "$TDIR"
exit "$FAIL"
