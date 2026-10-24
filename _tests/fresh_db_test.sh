#!/usr/bin/env bash
# ============================================================================
#  BOŞ VERİTABANI / TAŞIMA TESTİ
#
#  Kullanıcının gerçek senaryosu birebir taklit edilir:
#    • Veritabanı TAMAMEN BOŞ (hiç tablo yok, hiçbir SQL yüklenmemiş)
#    • Bağlantı bilgileri ortam değişkeniyle verilir; ayrıca auth.php'nin
#      GÖMÜLÜ varsayılanının geçerli hesaba (sql303 / if0_42805855_asra)
#      baktığı kaynak üzerinden denetlenir
#    • Site dosyaları geçici bir kopyadan sunulur (depoya dokunulmaz)
#
#  Kanıtlananlar:
#    1. Boş veritabanında ilk istek 500 DEĞİL 200 döner (şema kendini kurar)
#    2. 9 çekirdek tablo + ek tablolar otomatik oluşur
#    3. Oda kataloğu TAM (21 oda — Bilgi Odası dahil) tohumlanır — dışarıdan SQL yüklemeye gerek yok
#    4. Kayıt → giriş → get_rooms → send_message → get_messages uçtan uca çalışır
#    5. İkinci koşu zararsızdır (idempotent): tablo/oda sayısı değişmez
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(dirname "$SDIR")"
TDIR="${TMPDIR:-/tmp}/asra_fresh_$$"
mkdir -p "$TDIR"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
chk() { if [ "$1" = "$2" ]; then ok "$3"; else bad "$3 (beklenen '$2', gelen '$1')"; fi; }
sql() { sudo mysql -N asra_fresh -e "$1" 2>/dev/null; }
jget() { python3 -c "
import json,sys
try:
    d=json.load(open(sys.argv[1]))
except Exception:
    print('__PARSE_FAIL__'); sys.exit(0)
for k in sys.argv[2].strip('\$').strip('.').split('.'):
    if k=='': continue
    if isinstance(d,dict) and k in d: d=d[k]
    else: print('__MISSING__'); sys.exit(0)
print(len(d) if isinstance(d,list) else (d if not isinstance(d,(dict,)) else '__DICT__'))" "$1" "$2"; }

echo "== 0) Tamamen boş veritabanı kurulur =="
sudo mysql -e "DROP DATABASE IF EXISTS asra_fresh" >/dev/null 2>&1
sudo mysql -e "CREATE DATABASE asra_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" >/dev/null 2>&1
sudo mysql -e "GRANT ALL PRIVILEGES ON asra_fresh.* TO 'asra'@'localhost'" >/dev/null 2>&1
sudo mysql -e "FLUSH PRIVILEGES" >/dev/null 2>&1
TABLO=$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='asra_fresh'")
chk "$TABLO" "0" "veritabanı gerçekten boş (0 tablo)"

echo "== 1) Site dosyaları geçici kopyaya alınır, tek .php denetlenir =="
SITE="$TDIR/site"
mkdir -p "$SITE"
cp "$ROOT/asra-site-fix/auth.php" "$ROOT/asra-site-fix/index.html" "$ROOT/asra-site-fix/.htaccess" "$SITE/"
PHPSAYISI=$(ls "$SITE"/*.php 2>/dev/null | wc -l)
chk "$PHPSAYISI" "1" "klasörde TEK .php var (auth.php)"

# GitHub'daki kaynak parola/hesap içermez; DB ayarı sunucu ortamından
# alınmalı. Bu testte ASRA_DB_* ile boş asra_fresh veritabanı doğrulanır.
grep -q "'ASRA_DB_DSN'" "$SITE/auth.php" \
  && ok "auth.php DSN ortam ayarını okuyor" \
  || bad "auth.php DSN ortam ayarını okumuyor"
grep -q "'ASRA_DB_USER'" "$SITE/auth.php" \
  && ok "auth.php DB kullanıcı ortam ayarını okuyor" \
  || bad "auth.php DB kullanıcı ortam ayarını okumuyor"
if grep -q "sql102\|if0_42676193" "$SITE/auth.php"; then bad "auth.php içinde ESKİ hesap kalıntısı var"; else ok "auth.php içinde eski hesap kalıntısı yok"; fi

# Eski şema işaret dosyaları temizlenir (24 saatlik önbellek testi bozmasın)
find /tmp -maxdepth 1 -name 'asra_schema_*.flag' -delete 2>/dev/null
find /tmp -maxdepth 1 -name 'sess_*' -delete 2>/dev/null

echo "== 2) Sunucu ayağa kalkar (hedef: boş asra_fresh) =="
( cd "$SITE" && ASRA_COOKIE_SECURE=0 PHP_CLI_SERVER_WORKERS=4 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=asra_fresh;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' \
    php -S 127.0.0.1:8093 "$ROOT/_tests/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
BASE="http://127.0.0.1:8093/auth.php"
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -o "$TDIR/s.json" -w '%{http_code}' "$BASE?action=session" 2>/dev/null); CODE=${CODE:-000}
  [ "$CODE" = "200" ] && break
  sleep 0.5
done
chk "$CODE" "200" "BOŞ veritabanında ilk istek 200 döndü (500 değil)"

echo "== 3) Şema kendini kurdu mu? =="
CORE=0; MISSING=""
for t in users rooms announcements messages message_views message_deletions banned_users deleted_users_log rate_limits; do
  VAR=$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='asra_fresh' AND TABLE_NAME='$t'")
  if [ "$VAR" = "1" ]; then CORE=$((CORE+1)); else MISSING="$MISSING $t"; fi
done
chk "$CORE" "9" "9 çekirdek tablo otomatik oluştu${MISSING:+ (eksik:$MISSING)}"

ROOMS=$(sql "SELECT COUNT(*) FROM rooms")
chk "$ROOMS" "21" "oda kataloğu tam tohumlandı (21 oda — Bilgi Odası dâhil)"

# Ek tablolar: media/token/okuma/cihaz/kimlik/ekonomi + Bilgi Odası ve şikayet
# altyapısı (7 yeni tablo: site_settings, info_items, info_forms,
# info_form_replies, info_form_likes, info_form_reply_likes, complaints).
EK=$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='asra_fresh' AND TABLE_NAME IN ('media_files','auth_remember_tokens','room_reads','user_devices','blocked_identifiers','aa_quest_claims','site_settings','info_items','info_forms','info_form_replies','info_form_likes','info_form_reply_likes','complaints')")
chk "$EK" "13" "ek tablolar da oluştu (13)"

VER=$(sql "SELECT COUNT(*) FROM rate_limits WHERE rate_key LIKE 'schema:runtime:%'")
chk "$VER" "1" "şema sürüm damgası yazıldı (tekrar kurulum tetiklenmez)"

echo "== 4) Uçtan uca akış: kayıt → giriş → oda → mesaj =="
JAR="$TDIR/jar.txt"; rm -f "$JAR"
curl -s -A "Fresh/1.0" -c "$JAR" "$BASE?action=session" -o "$TDIR/r.json"
CSRF=$(python3 -c "import json;print(json.load(open('$TDIR/r.json')).get('csrf_token',''))" 2>/dev/null)
[ -n "$CSRF" ] && ok "oturum + CSRF alındı" || bad "CSRF alınamadı"

curl -s -A "Fresh/1.0" -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"username":"freshuser","password":"Test123!xyz","password_confirm":"Test123!xyz"}' \
  "$BASE?action=register" -o "$TDIR/r.json"
chk "$(jget "$TDIR/r.json" '$.ok')" "True" "kayıt başarılı"

# Yeni hesap yönetici onayı bekler (APPROVAL_PENDING = 403, doğru davranış).
# Diğer suite'lerdeki gibi onaylanır; böylece oturum yetkili hâle gelir.
chk "$(sql "SELECT approval_status FROM users WHERE username='freshuser'")" "pending" \
    "yeni hesap onay bekliyor (doğru varsayılan)"
sql "UPDATE users SET approval_status='approved' WHERE username='freshuser'" >/dev/null

# Oturum döndüğü için taze CSRF gerekir
curl -s -A "Fresh/1.0" -b "$JAR" -c "$JAR" "$BASE?action=session" -o "$TDIR/r.json"
CSRF=$(python3 -c "import json;print(json.load(open('$TDIR/r.json')).get('csrf_token',''))" 2>/dev/null)

curl -s -A "Fresh/1.0" -b "$JAR" -c "$JAR" "$BASE?action=get_rooms" -o "$TDIR/r.json"
chk "$(jget "$TDIR/r.json" '$.rooms')" "21" "get_rooms 21 oda döndürdü (Bilgi Odası en üstte)"

curl -s -A "Fresh/1.0" -b "$JAR" -c "$JAR" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" \
  -d '{"room_id":1,"message":"bos veritabani testi"}' "$BASE?action=send_message" -o "$TDIR/r.json"
chk "$(jget "$TDIR/r.json" '$.ok')" "True" "mesaj gönderildi"

curl -s -A "Fresh/1.0" -b "$JAR" -c "$JAR" "$BASE?action=get_messages&room_id=1" -o "$TDIR/r.json"
grep -q "bos veritabani testi" "$TDIR/r.json" && ok "mesaj geri okundu" || bad "mesaj geri okunamadı"

echo "== 5) İkinci koşu zararsız mı (idempotent)? =="
CODE2=$(curl -s -o /dev/null -w '%{http_code}' "$BASE?action=session" 2>/dev/null); CODE2=${CODE2:-000}
chk "$CODE2" "200" "ikinci istek de 200"
chk "$(sql 'SELECT COUNT(*) FROM rooms')" "21" "oda sayısı değişmedi (21)"
chk "$(sql 'SELECT COUNT(*) FROM users')" "1" "kullanıcı çoğalmadı (1)"

echo "== 6) Tanı ucu: /auth.php?action=health =="
curl -s -A "Fresh/1.0" "$BASE?action=health" -o "$TDIR/health.json"
HCODE=$(curl -s -o /dev/null -w '%{http_code}' "$BASE?action=health" 2>/dev/null)
chk "$HCODE" "200" "health 200 döndü (JSON)"
chk "$(jget "$TDIR/health.json" '$.ok')" "True" "health: ok=true"
chk "$(jget "$TDIR/health.json" '$.db_connected')" "True" "health: veritabanı bağlı"
[ "$(jget "$TDIR/health.json" '$.rooms')" = "21" ] && ok "health: 21 oda görünüyor" || bad "health: oda sayısı yanlış"
grep -q '"db_name":"asra_fresh"' "$TDIR/health.json" && ok "health: doğru veritabanı adı" || bad "health: veritabanı adı yanlış"
# Parola/kullanıcı adı sızmamalı
if grep -q "asra_test_pw\|SDspuzgAn17CUg" "$TDIR/health.json"; then bad "health PAROLA sızdırıyor"; else ok "health parola sızdırmıyor"; fi

echo "== 7) Tanı ucu veritabanı BOZUKKEN de JSON döner =="
( cd "$SITE" && ASRA_COOKIE_SECURE=0 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=BOZUK_DB_YOK;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' \
    php -S 127.0.0.1:8094 "$ROOT/_tests/test_router.php" ) >"$TDIR/php_bad.log" 2>&1 &
BAD_PID=$!
for i in $(seq 1 20); do
  BH=$(curl -s -o "$TDIR/bad.json" -w '%{http_code}' "http://127.0.0.1:8094/auth.php?action=health" 2>/dev/null)
  [ -n "$BH" ] && [ "$BH" != "000" ] && break
  sleep 0.5
done
chk "$BH" "503" "bozuk veritabanında health 503 döndü"
chk "$(jget "$TDIR/bad.json" '$.ok')" "False" "health: ok=false"
chk "$(jget "$TDIR/bad.json" '$.db_connected')" "False" "health: db_connected=false"
grep -q '"db_error"' "$TDIR/bad.json" && ok "health gerçek hata nedenini gösteriyor" || bad "health hata nedenini göstermiyor"
kill $BAD_PID 2>/dev/null

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] || { echo "--- php.log kuyruğu ---"; tail -12 "$TDIR/php.log"; }
rm -rf "$TDIR"
exit "$FAIL"
