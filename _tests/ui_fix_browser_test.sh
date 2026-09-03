#!/usr/bin/env bash
# ============================================================================
#  ARAYÜZ ONARIMLARI SUITE'İ
#    • görenler butonu (mesajı atınca çalışmıyordu)
#    • temizlenme geri sayımı (boş odada hiç başlamıyordu)
#    • geri sayım hapı dar ekranda kırpılıyordu (kaydırılabilir yapıldı)
#  Gerçek Chromium + gerçek PHP/MySQL sunucusuna karşı koşar.
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SDIR")"
TDIR="$SDIR/run_uifix"
rm -rf "$TDIR"; mkdir -p "$TDIR"
PORT=8093
BASE="http://127.0.0.1:$PORT"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }

echo "== 0) Ortam: boş odalar (geri sayım türetmesi ölçülecek) =="
bash "$SDIR/reset_env.sh"
sudo mysql -N asra_test -e "DELETE FROM messages" >/dev/null 2>&1
ROOMS=$(sudo mysql -N asra_test -e "SELECT COUNT(*) FROM rooms")
MSGS=$(sudo mysql -N asra_test -e "SELECT COUNT(*) FROM messages")
[ "$ROOMS" -gt 0 ] && [ "$MSGS" = "0" ] && ok "hazır: $ROOMS oda, 0 mesaj (boş oda senaryosu)" || bad "önkoşul: oda=$ROOMS mesaj=$MSGS"

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1; sleep 1
( cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 PHP_CLI_SERVER_WORKERS=4 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_HOT_READS_PER_MIN=600 \
    php -S "127.0.0.1:$PORT" "$SDIR/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
for i in $(seq 1 40); do
  curl -s -m 2 -o /dev/null "$BASE/auth.php?action=health" && break
  sleep 0.5
done
curl -s -m 5 -o /dev/null -w '' "$BASE/auth.php?action=health" && ok "sunucu ayakta ($BASE)" || bad "sunucu kalkmadı"

echo "== 1) Sunucu: saklama süresi istemciye bildiriliyor =="
HOURS=$(curl -s -m 5 "$BASE/auth.php?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin).get('message_retention_hours'))" 2>/dev/null)
[ "$HOURS" = "24" ] && ok "session.message_retention_hours=24" || bad "message_retention_hours geldi: $HOURS"

echo "== 2) Tarayıcı: görenler + geri sayım + kaydırma =="
export NODE_PATH=/home/user/.asra-tests/node_modules
set +e
ORIGIN="$BASE" node "$SDIR/ui_fix_browser_test.js" "$TDIR" 2>&1 | tail -30
NODE_EXIT=${PIPESTATUS[0]}
set -e 2>/dev/null || true
# Betik çökerse (modül yok, sayfa açılmadı) suite SESSİZCE geçmemeli.
if [ ! -f "$TDIR/browser_counters.txt" ]; then
  bad "tarayıcı testi sonuç üretmedi (node exit=$NODE_EXIT)"
fi
while read -r k v; do
  case "$k" in
    PASS) for i in $(seq 1 "${v:-0}"); do ok "tarayıcı doğrulaması"; done ;;
    FAIL) for i in $(seq 1 "${v:-0}"); do bad "tarayıcı doğrulaması"; done ;;
  esac
done < <(cat "$TDIR/browser_counters.txt" 2>/dev/null || true)

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] || { echo "--- php.log ---"; tail -8 "$TDIR/php.log"; }
rm -rf "$TDIR"
exit "$FAIL"
