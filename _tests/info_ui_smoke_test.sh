#!/usr/bin/env bash
# ============================================================================
#  BİLGİ ODASI İYİLEŞTİRME — TARAYICI GÖRSEL/UI DOĞRULAMASI (elle koşulur)
#  Ana ekran kartında ⭐ ve parlak stil; Bilgi Odası başlığında kalp YOK;
#  haber sekmesinde 24 saatlik yenileme notu; formlarda 24 saat uyarısı;
#  yorumlarda kalp aç/kapa. Yalnızca kendi feed sunucusunu kullanır.
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run_info_ui"
rm -rf "$TDIR"; mkdir -p "$TDIR/feed"
cat > "$TDIR/feed/feed.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>UI Akışı</title>
<item><title>UI FIXTURE HABER</title><link>https://orn.example/1</link><guid>ui-1</guid><pubDate>Mon, 07 Sep 2026 08:00:00 +0000</pubDate><description>UI özet metni.</description></item>
</channel></rss>
XML
PORT="${UI_INFO_PORT:-8096}"
FEED_PORT="${UI_INFO_FEED_PORT:-8127}"
BASE="http://127.0.0.1:$PORT/auth.php"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql -N asra_test -e "$1" 2>/dev/null; }

bash "$SDIR/reset_env.sh" >/dev/null 2>&1
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null; pkill -f "php -S 127.0.0.1:$FEED_PORT" 2>/dev/null
sleep 1
( cd "$TDIR/feed" && exec php -S "127.0.0.1:$FEED_PORT" >"$TDIR/feed.log" 2>&1 ) &
FEED_PID=$!
( cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_HOT_READS_PER_MIN=600 \
    ASRA_INFO_FEED_URLS="http://127.0.0.1:$FEED_PORT/feed.xml" \
    exec php -S "127.0.0.1:$PORT" "$SDIR/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -m 2 -o /dev/null -w '%{http_code}' "$BASE?action=session" || echo 000)
  [ "$CODE" = "200" ] && break; sleep 0.5
done
check_eq "uygulama sunucusu ayakta" "200" "$CODE"

# Kullanıcıyı hazırla (tarayıcıda yalnızca giriş yapılacak)
J="$TDIR/j_ui.txt"; UA="InfoUI/smoke/1.0"
api_get() { curl -s -m 20 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -o "$4" -w '%{http_code}' "$BASE?action=$5"; }
api_post() { curl -s -m 20 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -H 'Content-Type: application/json' -d "$5" -o "$6" -w '%{http_code}' "$BASE?action=$4"; }
tok() { python3 -c "
import json,sys
d=json.load(open(sys.argv[1]))
det=(d.get('error') or {}).get('details') or {}
t=d.get('csrf_token') or (det.get('csrf_token') if isinstance(det,dict) else '') or ''
print(t or sys.argv[2])" "$1" "$2" 2>/dev/null || echo "$2"; }
api_get "$J" "$UA" "" "$TDIR/s.json" session >/dev/null
T=$(tok "$TDIR/s.json" "")
api_post "$J" "$UA" "$T" register '{"username":"uismoke","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$TDIR/reg.json" >/dev/null
api_post "$J" "$UA" "$T" submit_approval '{"reason":"UI smoke testi için katılıyorum."}' "$TDIR/ap.json" >/dev/null
sql "UPDATE users SET approval_status='approved' WHERE username='uismoke'"
api_get "$J" "$UA" "$T" "$TDIR/s3.json" session >/dev/null
T=$(tok "$TDIR/s3.json" "$T")
api_post "$J" "$UA" "$T" login '{"username":"uismoke","password":"Test123!xyz"}' "$TDIR/l.json" >/dev/null
check_eq "API girişi hazır (uygulama erişimi)" "200" "$(python3 -c "
import json
print(200 if json.load(open('$TDIR/l.json')).get('ok') else 0)")"
check_eq "giriş çerezi hazır" "True" "$(python3 -c "
import os
print(os.path.exists('$J') and os.path.getsize('$J')>100)")"

# Tarayıcı doğrulaması
export NODE_PATH=/home/user/.asra-tests/node_modules
export CHROME_PATH=/usr/bin/chromium
node "$SDIR/info_ui_smoke.js" "http://127.0.0.1:$PORT/" "uismoke" "Test123!xyz" "/tmp/info-ui-shots" > "$TDIR/browser.log" 2>&1
RC=$?
cat "$TDIR/browser.log"
check_eq "tarayıcı doğrulaması çıktısı" "OK" "$(grep -c '^OK' "$TDIR/browser.log" | sed 's/^0$/YOK/;s/^1$/OK/')"
check_eq "tarayıcı süreci temiz çıktı (exit 0)" "0" "$RC"
# JS hata yok mu?
JSERR=$(grep -c "PAGE_ERROR" "$TDIR/browser.log" || true)
check_eq "sayfada JavaScript hatası yok" "0" "$JSERR"

echo
echo "SONUÇ: GEÇTİ $PASS / BAŞARISIZ $FAIL"
kill $PHP_PID $FEED_PID 2>/dev/null
rm -rf "$TDIR"
[ "$FAIL" = "0" ] && echo "UI SMOKE ÇALIŞIYOR" || echo "SORUN VAR"
exit "$FAIL"
