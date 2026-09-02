#!/usr/bin/env bash
# ============================================================================
#  HIZ SINIRI KİLİDİNİN AÇILMASI (regresyon testi)
#
#  Onarılan kusur: consumeGlobalReadBudget engeli her istekte
#  `blocked_until = NOW + cooldown` yazıyordu. Engel sürerken gelen her istek
#  (istemcinin 60 sn'lik nabız turu bile) engeli yeniden uzatıyordu; kilit
#  pratikte HİÇ bitmiyor ve TEK bozuk istemci TÜM kullanıcıları kalıcı olarak
#  dışarıda bırakıyordu ("bağlantı geçici olarak yavaşladı" ekranı).
#  Ayrıca window_started_at NULL/bozuk olduğunda pencere asla sıfırlanmıyordu.
#
#  Kanıtlananlar:
#    1. Sınır aşılınca istekler gerçekten 429 ile kesilir (koruma çalışıyor)
#    2. Engel süresi bitince site KENDİLİĞİNDEN yeniden açılır (asıl kusur)
#    3. Bozuk/NULL pencere satırı kalıcı kilide yol açmaz
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SDIR")"
TDIR="${TMPDIR:-/tmp}/asra_throttle_$$"
mkdir -p "$TDIR"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
sql() { sudo mysql -N asra_throttle -e "$1" 2>/dev/null; }

PORT=8095
BASE="http://127.0.0.1:$PORT/auth.php"

echo "== 0) Boş veritabanı + düşük eşikli sunucu =="
sudo mysql -e "DROP DATABASE IF EXISTS asra_throttle" >/dev/null 2>&1
sudo mysql -e "CREATE DATABASE asra_throttle CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null 2>&1
sudo mysql -e "GRANT ALL PRIVILEGES ON asra_throttle.* TO 'asra'@'localhost'" >/dev/null 2>&1
sudo mysql -e "FLUSH PRIVILEGES" >/dev/null 2>&1
SITE="$TDIR/site"; mkdir -p "$SITE"
cp "$ROOT/asra-site-fix/auth.php" "$ROOT/asra-site-fix/index.html" "$ROOT/asra-site-fix/.htaccess" "$SITE/"
# Eşikler test için küçültülür: 15 sn'de 20 istek, 5 sn engel.
( cd "$SITE" && ASRA_COOKIE_SECURE=0 PHP_CLI_SERVER_WORKERS=8 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=asra_throttle;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' \
    ASRA_HOT_READS_PER_MIN=20 ASRA_HOT_READS_WINDOW=15 ASRA_HOT_READS_COOLDOWN=5 \
    php -S "127.0.0.1:$PORT" "$ROOT/_tests/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
for i in $(seq 1 40); do
  C=$(curl -s -o /dev/null -w '%{http_code}' "$BASE?action=session" 2>/dev/null); C=${C:-000}
  [ "$C" = "200" ] && break
  sleep 0.5
done
[ "$C" = "200" ] && ok "sunucu hazır (200)" || { bad "sunucu ayağa kalkmadı ($C)"; }

echo "== 1) Sınır aşılınca koruma devreye giriyor =="
GOT429=0; GOT200=0
for i in $(seq 1 30); do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE?action=session" 2>/dev/null); CODE=${CODE:-000}
  [ "$CODE" = "429" ] && GOT429=$((GOT429+1))
  [ "$CODE" = "200" ] && GOT200=$((GOT200+1))
done
[ "$GOT200" -ge 15 ] && ok "ilk istekler geçti (200: $GOT200)" || bad "beklenenden az istek geçti (200: $GOT200)"
[ "$GOT429" -ge 1 ] && ok "sınır aşılınca 429 döndü (429: $GOT429)" || bad "koruma devreye girmedi (429: $GOT429)"

BLOCKED=$(sql "SELECT COUNT(*) FROM rate_limits WHERE rate_key LIKE 'global_read:%' AND blocked_until IS NOT NULL")
[ "$BLOCKED" -ge 1 ] && ok "veritabanında engel kaydı oluştu" || bad "engel kaydı oluşmadı"

echo "== 2) ASIL KUSUR: engel bitince site KENDİLİĞİNDEN açılıyor =="
# Pencere 15 sn + engel 5 sn; 22 sn beklemek ikisini de aşar.
# Eski kodda engel her istekte uzadığı için bu istek 429 dönerdi.
sleep 22
RECOVER=$(curl -s -o /dev/null -w '%{http_code}' "$BASE?action=session" 2>/dev/null); RECOVER=${RECOVER:-000}
[ "$RECOVER" = "200" ] && ok "engel süresi bitince 200 döndü (kilit kendini uzatmıyor)" \
                      || bad "kilit AÇILMADI (gelen: $RECOVER) — engel hâlâ uzuyor"

echo "== 3) Bozuk/NULL pencere satırı kalıcı kilide yol açmıyor =="
# Senaryo: içe aktarılmış eski satır — pencere NULL, sayaç şişmiş, engel
# geleceğe yazılmış. Eski kod bunu sonsuza dek "engelli" sayıyordu.
sql "UPDATE rate_limits SET window_started_at=NULL, attempts=9999, blocked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 600 SECOND) WHERE rate_key LIKE 'global_read:%'" >/dev/null
CORRUPT=$(curl -s -o /dev/null -w '%{http_code}' "$BASE?action=session" 2>/dev/null); CORRUPT=${CORRUPT:-000}
[ "$CORRUPT" = "200" ] && ok "bozuk satır yok sayıldı, site açık kaldı (200)" \
                      || bad "bozuk satır kalıcı kilide yol açtı (gelen: $CORRUPT)"
STUCK=$(sql "SELECT COUNT(*) FROM rate_limits WHERE rate_key LIKE 'global_read:%' AND window_started_at IS NULL")
[ "$STUCK" = "0" ] && ok "NULL pencere onarıldı" || bad "NULL pencere onarılmadı ($STUCK satır)"
LEFTOVER=$(sql "SELECT COALESCE(SUM(attempts),0) FROM rate_limits WHERE rate_key LIKE 'global_read:%'")
[ "$LEFTOVER" -le 5 ] && ok "şişmiş sayaç sıfırlandı (attempts: $LEFTOVER)" || bad "sayaç sıfırlanmadı ($LEFTOVER)"
FUTURE=$(sql "SELECT COUNT(*) FROM rate_limits WHERE rate_key LIKE 'global_read:%' AND blocked_until > UTC_TIMESTAMP()")
[ "$FUTURE" = "0" ] && ok "geleceğe yazılmış sahte engel temizlendi" || bad "sahte engel duruyor ($FUTURE)"

echo "== 4) Engel sürerken sayaç şişmiyor (engel uzamıyor) =="
sql "UPDATE rate_limits SET attempts=999, blocked_until=DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 SECOND) WHERE rate_key LIKE 'global_read:%'" >/dev/null
B1=$(sql "SELECT UNIX_TIMESTAMP(blocked_until) FROM rate_limits WHERE rate_key LIKE 'global_read:%' LIMIT 1")
for i in 1 2 3 4 5; do curl -s -o /dev/null "$BASE?action=session" 2>/dev/null; done
B2=$(sql "SELECT UNIX_TIMESTAMP(blocked_until) FROM rate_limits WHERE rate_key LIKE 'global_read:%' LIMIT 1")
[ "$B1" = "$B2" ] && ok "5 istek engeli UZATMADI ($B1 -> $B2)" || bad "engel uzadı ($B1 -> $B2)"
A1=$(sql "SELECT attempts FROM rate_limits WHERE rate_key LIKE 'global_read:%' LIMIT 1")
[ "$A1" = "999" ] && ok "engel sürerken sayaç artmadı ($A1)" || bad "sayaç arttı ($A1)"

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] || { echo "--- php.log kuyruğu ---"; tail -8 "$TDIR/php.log"; }
sudo mysql -e "DROP DATABASE IF EXISTS asra_throttle" >/dev/null 2>&1
rm -rf "$TDIR"
exit "$FAIL"
