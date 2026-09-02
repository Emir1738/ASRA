#!/usr/bin/env bash
# ============================================================================
#  ESKİ DÖKÜM ŞEMASI UYUM TESTİ
#
#  Gerçek vaka: kullanıcı veritabanını ESKİ bir dökümden içe aktardı ve
#  `rooms` tablosunda `position` sütunu YOKTU. ensureCoreSchema tohumu
#  position'a yazdığı için her istek 503 dönüyor, arayüz sürekli
#  "Bağlantı geçici olarak yavaşladı" gösteriyordu. Sağlık ucu db() kullan-
#  madığı için ok:true dönüyor, bu da teşhisi zorlaştırıyordu.
#
#  Kanıtlananlar:
#    1. Eski şemada (rooms.position yok) ilk istek 503 DEĞİL 200 döner
#    2. Eksik sütun otomatik eklenir
#    3. Katalog 20 odaya tamamlanır, mevcut odalar ezilmez
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SDIR")"
TDIR="${TMPDIR:-/tmp}/asra_legacy_$$"
mkdir -p "$TDIR"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
sql() { sudo mysql -N asra_legacy -e "$1" 2>/dev/null; }

echo "== 0) Eski dökümü taklit eden veritabanı =="
sudo mysql -e "DROP DATABASE IF EXISTS asra_legacy" >/dev/null 2>&1
sudo mysql -e "CREATE DATABASE asra_legacy CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null 2>&1
sudo mysql -e "GRANT ALL PRIVILEGES ON asra_legacy.* TO 'asra'@'localhost'" >/dev/null 2>&1
# Eski rooms: position YOK, description NOT NULL, name UNIQUE
sudo mysql asra_legacy -e "CREATE TABLE rooms (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_rooms_name (name)) ENGINE=InnoDB"
sudo mysql asra_legacy -e "INSERT INTO rooms (id,name,description) VALUES
 (1,'Asra Genel','eski aciklama'),(2,'Asra Astrofotoğraf','eski'),(3,'Asra Astronomi','eski')"
HASPOS=$(sql "SHOW COLUMNS FROM rooms LIKE 'position'")
[ -z "$HASPOS" ] && ok "eski şema hazır: rooms.position YOK" || bad "test önkoşulu bozuk"

SITE="$TDIR/site"; mkdir -p "$SITE"
cp "$ROOT/asra-site-fix/auth.php" "$ROOT/asra-site-fix/index.html" "$ROOT/asra-site-fix/.htaccess" "$SITE/"
( cd "$SITE" && ASRA_COOKIE_SECURE=0 PHP_CLI_SERVER_WORKERS=4 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=asra_legacy;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' \
    php -S 127.0.0.1:8096 "$ROOT/_tests/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
BASE="http://127.0.0.1:8096/auth.php"

echo "== 1) Eski şemada ilk istek 503 DEĞİL 200 =="
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -o "$TDIR/s.json" -w '%{http_code}' "$BASE?action=session" 2>/dev/null); CODE=${CODE:-000}
  [ "$CODE" = "200" ] && break
  sleep 0.5
done
[ "$CODE" = "200" ] && ok "eski şemada session 200 döndü (503 değil)" || bad "session $CODE döndü"

echo "== 2) Eksik sütun otomatik eklendi =="
HASPOS=$(sql "SHOW COLUMNS FROM rooms LIKE 'position'")
[ -n "$HASPOS" ] && ok "rooms.position otomatik eklendi" || bad "position eklenmedi"

echo "== 3) Katalog tamamlandı, mevcut korunuyor =="
ROOMS=$(sql "SELECT COUNT(*) FROM rooms")
[ "$ROOMS" = "20" ] && ok "20 odaya tamamlandı" || bad "oda sayısı $ROOMS"
NAME1=$(sql "SELECT description FROM rooms WHERE id=1")
[ "$NAME1" = "eski aciklama" ] && ok "mevcut oda ezilmedi (eski açıklama korundu)" || bad "mevcut oda ezildi: $NAME1"

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] || { echo "--- php.log ---"; tail -6 "$TDIR/php.log"; }
sudo mysql -e "DROP DATABASE IF EXISTS asra_legacy" >/dev/null 2>&1
rm -rf "$TDIR"
exit "$FAIL"
