#!/usr/bin/env bash
# ============================================================================
#  SAKLAMA SÜRESİ / GERİ SAYIM SUITE'İ
#
#  Bildirilen kusur: "saat sistemini hesaplayamıyor, 'hesaplanıyor…'da takılı
#  kalıyor."
#
#  Ölçülenler:
#    1) SIFIR-İSTEK ODA GİRİŞİ: önbellek dolu + okunmamış rozeti 0 iken odaya
#       yeniden girince sunucuya HİÇ gidilmez. Geri sayımın tek türetme noktası
#       fetchMessages() olduğu için sayaç "hesaplanıyor…"da KALICI takılıyordu.
#       -> sayaç önbellekten, istek atmadan türetilmeli ve 0 istek KORUNMALI.
#    2) KALAN SÜRENİN DOĞRULUĞU: 5 saatlik mesaj için ~19 saat gösterilmeli.
#    3) VADESİ GEÇMİŞ MESAJ: 25 saatlik mesaj sunucuda listelenmemeli, rozete
#       sayılmamalı ve SAYACI GERİYE ÇEKMEMELİ (istemci önbelleğinden elenmeli).
#    4) BOZUK ZAMAN DAMGASI: çözümlenemeyen created_at sayaçta yer tutucuyu
#       kilitlememeli; dürüst duruma düşmeli.
#    5) ZAMANLAYICI: geri sayım zamanlayıcısı kurulmuş olmalı (metin tazelenir).
#
#  Gerçek Chromium + gerçek PHP/MySQL sunucusuna karşı koşar.
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SDIR")"
TDIR="$SDIR/run_retention"
rm -rf "$TDIR"; mkdir -p "$TDIR"
PORT="${PORT:-8094}"
BASE="http://127.0.0.1:$PORT"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }

echo "== 0) Ortam: biri 5, diğeri 25 saatlik iki mesaj =="
bash "$SDIR/reset_env.sh"
sudo mysql -N asra_test -e "DELETE FROM messages" >/dev/null 2>&1

UID_ADMIN=$(sudo mysql -N asra_test -e "SELECT id FROM users WHERE username='uiadmin' LIMIT 1")
[ -n "$UID_ADMIN" ] && ok "referans kullanıcı hazır (id=$UID_ADMIN)" || bad "referans kullanıcı bulunamadı"

ROOMS_TOTAL=$(sudo mysql -N asra_test -e "SELECT COUNT(*) FROM rooms")
LIVE_ROOM_ID=$(sudo mysql -N asra_test -e "SELECT id FROM rooms WHERE id > 0 ORDER BY id LIMIT 1")
STALE_ROOM_ID=$(sudo mysql -N asra_test -e "SELECT id FROM rooms ORDER BY id DESC LIMIT 1")
[ "$ROOMS_TOTAL" -ge 2 ] && ok "katalog hazır: $ROOMS_TOTAL oda (canlı=$LIVE_ROOM_ID, ikinci=$STALE_ROOM_ID)" \
  || bad "en az 2 oda gerek, bulunan: $ROOMS_TOTAL"

# Mesajlar KENDİ kullanıcımızdan yazılıyor: unread_count kendi mesajımızı
# saymaz, böylece rozet 0 kalır ve sıfır-istek oda girişi yolu devreye girer
# (kusurun tetiklendiği yol tam olarak budur).
sudo mysql -N asra_test -e "INSERT INTO messages (room_id, user_id, type, content, created_at)
     VALUES ($LIVE_ROOM_ID, $UID_ADMIN, 'text', 'geri sayim canli mesaj', UTC_TIMESTAMP() - INTERVAL 5 HOUR)" >/dev/null 2>&1
sudo mysql -N asra_test -e "INSERT INTO messages (room_id, user_id, type, content, created_at)
     VALUES ($LIVE_ROOM_ID, $UID_ADMIN, 'text', 'vadesi gecmis mesaj', UTC_TIMESTAMP() - INTERVAL 25 HOUR)" >/dev/null 2>&1

TOTAL_MSG=$(sudo mysql -N asra_test -e "SELECT COUNT(*) FROM messages WHERE room_id=$LIVE_ROOM_ID")
LIVE_AGE=$(sudo mysql -N asra_test -e "SELECT TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) FROM messages WHERE room_id=$LIVE_ROOM_ID AND content='geri sayim canli mesaj'")
STALE_AGE=$(sudo mysql -N asra_test -e "SELECT TIMESTAMPDIFF(HOUR, created_at, UTC_TIMESTAMP()) FROM messages WHERE room_id=$LIVE_ROOM_ID AND content='vadesi gecmis mesaj'")
[ "$TOTAL_MSG" = "2" ] && [ "$LIVE_AGE" = "5" ] && [ "$STALE_AGE" = "25" ] \
  && ok "oda $LIVE_ROOM_ID: 5 saatlik + 25 saatlik mesaj hazır" \
  || bad "önkoşul: toplam=$TOTAL_MSG canlı=$LIVE_AGE bayat=$STALE_AGE"

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
curl -s -m 5 -o /dev/null "$BASE/auth.php?action=health" && ok "sunucu ayakta ($BASE)" || bad "sunucu kalkmadı"

echo "== 1) Sunucu: saklama süresi bildiriliyor ve pencere sabitle bağlı =="
HOURS=$(curl -s -m 5 "$BASE/auth.php?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin).get('message_retention_hours'))" 2>/dev/null)
[ "$HOURS" = "24" ] && ok "session.message_retention_hours=24" || bad "message_retention_hours geldi: $HOURS"

# Sabit ile sorgu penceresi AYNI kaynaktan beslenmeli: elde yazılmış "INTERVAL 24 HOUR"
# kalmışsa MESSAGE_RETENTION_HOURS değiştirildiğinde saat sistemi sorgulardan kopar.
HARDCODED=$(grep -c "INTERVAL 24 HOUR" "$ROOT/asra-site-fix/auth.php" || true)
[ "$HARDCODED" = "0" ] && ok "auth.php içinde elde yazılmış 'INTERVAL 24 HOUR' yok (tek kaynak: MESSAGE_RETENTION_HOURS)" \
  || bad "auth.php içinde $HARDCODED adet elde yazılmış 'INTERVAL 24 HOUR' var"
CONST_USE=$(grep -c "MESSAGE_RETENTION_HOURS" "$ROOT/asra-site-fix/auth.php" || true)
[ "$CONST_USE" -ge 8 ] && ok "MESSAGE_RETENTION_HOURS $CONST_USE yerde kullanılıyor" \
  || bad "MESSAGE_RETENTION_HOURS yalnızca $CONST_USE yerde (beklenen >= 8)"

echo "== 2) Tarayıcı: 'hesaplanıyor…' kilidi + süre hesabı + bayat mesaj =="
export NODE_PATH=/home/user/.asra-tests/node_modules
set +e
ORIGIN="$BASE" LIVE_ROOM_ID="$LIVE_ROOM_ID" STALE_ROOM_ID="$STALE_ROOM_ID" \
  node "$SDIR/retention_countdown_browser_test.js" "$TDIR" 2>&1 | tail -45
NODE_EXIT=${PIPESTATUS[0]}
set -e 2>/dev/null || true
# Betik çökerse suite SESSİZCE geçmemeli.
if [ ! -f "$TDIR/browser_counters.txt" ]; then
  bad "tarayıcı testi sonuç üretmedi (node exit=$NODE_EXIT)"
fi
while read -r k v; do
  case "$k" in
    PASS) for i in $(seq 1 "${v:-0}"); do ok "geri sayım doğrulaması"; done ;;
    FAIL) for i in $(seq 1 "${v:-0}"); do bad "geri sayım doğrulaması"; done ;;
  esac
done < <(cat "$TDIR/browser_counters.txt" 2>/dev/null || true)

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] && echo "TÜM TESTLER GEÇTİ" || { echo "--- php.log ---"; tail -12 "$TDIR/php.log"; }
rm -rf "$TDIR"
exit "$FAIL"
