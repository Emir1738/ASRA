#!/usr/bin/env bash
# ============================================================================
#  ŞİFRE DEĞİŞTİRME SUITE'İ — BASH/CLI
#
#  Eklenen özellik: Ayarlar > Güvenlik > "Şifre Değiştir". Kullanıcı güncel
#  şifresini ve yeni şifresini İKİ KEZ girer; hepsi doğruysa ve yeni şifre
#  kayıt kurallarına uyuyorsa şifre değişir. "Şifremi unuttum" ise yetkiliye
#  yönlendirir (platformda e-posta doğrulaması ve kendi kendine sıfırlama yok).
#
#  Ölçülenler:
#    1) UÇ KAYDI: change_password bir POST eylemidir; GET 405, oturumsuz 401,
#       CSRF'siz 419 döner.
#    2) GÜNCEL ŞİFRE DOĞRULAMASI: yanlış ya da boş güncel şifre 401 verir ve
#       saklanan hash DEĞİŞMEZ.
#    3) YENİ ŞİFRE KURALLARI: kayıt akışıyla birebir aynı doğrulayıcı
#       (validatePassword) kullanılır — eşleşmeyen tekrar, 10 karakter altı,
#       128 üstü, boşluk, harf/rakam/özel karakter eksiği 422 verir.
#    4) AYNI ŞİFRE: yeni şifre güncel şifreye eşitse 422 SAME_PASSWORD.
#    5) BAŞARILI DEĞİŞİM: hash gerçekten değişir, saklanan hash YENİ şifreyi
#       doğrular ve ESKİ şifreyi doğrulamaz.
#    6) OTURUM POLİTİKASI: session_version tam 1 artar, DİĞER cihazların
#       "beni hatırla" anahtarları silinir, bu isteği yapan cihazın oturumu ve
#       anahtarı KORUNUR (kendi şifresini değiştiren kullanıcı işlemin
#       ortasında dışarı atılmaz).
#    7) BRUTE-FORCE POLİTİKASI: yalnızca HATALI güncel şifre sayılır; kural
#       hataları bütçeyi tüketmez, başarılı değişim sayacı sıfırlar, 5 hatalı
#       denemeden sonra 429 gelir ve kilit KULLANICI bazlıdır (başka kullanıcı
#       etkilenmez).
#    8) ARAYÜZ: Ayarlar'da düğme var, modal üç şifre alanı açıyor, zayıf şifre
#       İSTEK ATILMADAN yerinde reddediliyor, "Şifremi unuttum" yetkili
#       mesajını gösteriyor, başarılı akışta şifre gerçekten değişiyor.
#
#  Gerçek Chromium + gerçek PHP/MySQL sunucusuna karşı koşar. Çıkış: FAIL sayısı.
# ============================================================================
set -u
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run_pwchange"
rm -rf "$TDIR"; mkdir -p "$TDIR"
PORT="${PORT:-8095}"
BASE="http://127.0.0.1:$PORT/auth.php"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql -N asra_test -e "$1" 2>/dev/null; }

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

# api_post <jar> <ua> <csrf> <action> <json> <outfile> -> HTTP kodu
api_post() {
  curl -s -m 15 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -H "Content-Type: application/json" \
    -d "$5" -o "$6" -w '%{http_code}' "$BASE?action=$4"
}
api_get() { # <jar> <ua> <action> <outfile> -> HTTP kodu
  curl -s -m 15 -A "$2" -b "$1" -c "$1" -o "$4" -w '%{http_code}' "$BASE?action=$3"
}
# İstek gövdeleri json.dumps ile kurulur: elle yazılmış tırnak/kaçış dizileri
# hem hatalı JSON üretiyor hem de şifredeki özel karakterleri bozuyordu.
pw_json() { # <guncel> <yeni> [tekrar]
  python3 -c 'import json,sys
print(json.dumps({"current_password":sys.argv[1],"password":sys.argv[2],"password_confirm":sys.argv[3] if len(sys.argv)>3 else sys.argv[2]}))' "$1" "$2" ${3+"$3"}
}
pw_json_only() { # <guncel> <yeni>  (tekrar alani HIC gonderilmez)
  python3 -c 'import json,sys
print(json.dumps({"current_password":sys.argv[1],"password":sys.argv[2]}))' "$1" "$2"
}
# rateKey() kimliği SHA-256'lar: anahtar 'kapsam:hash' biçimindedir, ham
# kullanıcı kimliğiyle LIKE araması asla eşleşmez.
rate_key() { php -r 'echo $argv[1].":".hash("sha256", $argv[2]);' "$1" "$2"; }
refresh_csrf() { # <jar> <ua> -> güncel jeton
  api_get "$1" "$2" session "$TDIR/csrf.json" >/dev/null
  jget "$TDIR/csrf.json" '$.csrf_token'
}

# Saklanan hash'i DOSYAYA yazar: Argon2id hash'i '$' içerdiği için kabuk
# değişkenine almak interpolasyonu bozar.
dump_hash() { sql "SELECT password_hash FROM users WHERE username='$1'" > "$TDIR/hash_$1.txt"; }
hash_verifies() { # <kullanici> <sifre> -> EVET/HAYIR
  php -r '$h=trim((string)@file_get_contents($argv[1])); echo password_verify($argv[2],$h)?"EVET":"HAYIR";' \
    "$TDIR/hash_$1.txt" "$2" 2>/dev/null
}

echo "== 0) Ortam sıfırdan kurulur =="
bash "$SDIR/reset_env.sh" >/dev/null 2>&1
sql "SELECT 1" >/dev/null 2>&1 && ok "veritabanı hazır" || bad "veritabanı kurulamadı"

pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1; sleep 1
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

echo "== 1) Kullanıcılar =="
# pwuser1 API üzerinden kaydedilir (uçtan uca gerçek akış).
UA1="PW-Test-pwuser1/1.0"; JAR1="$TDIR/jar_pwuser1.txt"; rm -f "$JAR1"
api_get "$JAR1" "$UA1" session "$TDIR/r.json" >/dev/null
CSRF1=$(jget "$TDIR/r.json" '$.csrf_token')
[ -n "$CSRF1" ] && [ "$CSRF1" != "__MISSING__" ] && ok "csrf jetonu alındı" || bad "csrf jetonu alınamadı"
api_post "$JAR1" "$UA1" "$CSRF1" register '{"username":"pwuser1","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$TDIR/r.json" >/dev/null
sql "UPDATE users SET approval_status='approved' WHERE username='pwuser1'"
LOGIN_CODE=$(api_post "$JAR1" "$UA1" "$CSRF1" login '{"username":"pwuser1","password":"Test123!xyz"}' "$TDIR/r.json")
check_eq "pwuser1 girişi (HTTP)" "200" "$LOGIN_CODE"
check_eq "pwuser1 girişi (ok)" "True" "$(jget "$TDIR/r.json" '$.ok')"
UID1=$(sql "SELECT id FROM users WHERE username='pwuser1'")
[ -n "$UID1" ] && ok "pwuser1 veritabanında (id=$UID1)" || bad "pwuser1 oluşturulamadı"
CSRF1=$(jget "$TDIR/r.json" '$.csrf_token'); [ "$CSRF1" = "__MISSING__" ] && CSRF1=$(api_get "$JAR1" "$UA1" session "$TDIR/r.json" >/dev/null; jget "$TDIR/r.json" '$.csrf_token')

# pwuser2 ayrı bir kullanıcı: hız kilidinin KULLANICI bazlı olduğu ölçülür.
UA2="PW-Test-pwuser2/1.0"; JAR2="$TDIR/jar_pwuser2.txt"; rm -f "$JAR2"
api_get "$JAR2" "$UA2" session "$TDIR/r.json" >/dev/null
CSRF2=$(jget "$TDIR/r.json" '$.csrf_token')
api_post "$JAR2" "$UA2" "$CSRF2" register '{"username":"pwuser2","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$TDIR/r.json" >/dev/null
sql "UPDATE users SET approval_status='approved' WHERE username='pwuser2'"
api_post "$JAR2" "$UA2" "$CSRF2" login '{"username":"pwuser2","password":"Test123!xyz"}' "$TDIR/r.json" >/dev/null
UID2=$(sql "SELECT id FROM users WHERE username='pwuser2'")
CSRF2=$(jget "$TDIR/r.json" '$.csrf_token')
[ -n "$UID2" ] && ok "pwuser2 hazır (id=$UID2)" || bad "pwuser2 oluşturulamadı"

# pwui tarayıcı testi içindir ve DOĞRUDAN SQL ile yaratılır: kayıt akışı cihazı
# hesaba bağlar (assertDeviceMayUseAccount), Chromium farklı bir cihaz kimliğiyle
# geldiği için giriş reddedilirdi. reset_env.sh uiadmin'i de aynı yöntemle kurar.
UIHASH=$(php -r "echo password_hash('Test123!xyz', PASSWORD_DEFAULT);")
sql "INSERT IGNORE INTO users (username, password_hash, avatar_color, approval_status, online_status, last_seen, created_at)
     VALUES ('pwui','$UIHASH','c1','approved',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())"
UIDUI=$(sql "SELECT id FROM users WHERE username='pwui'")
[ -n "$UIDUI" ] && ok "tarayıcı kullanıcısı pwui hazır (id=$UIDUI)" || bad "pwui oluşturulamadı"

echo "== 2) Uç kaydı ve koruma rayları =="
GOT=$(api_get "$JAR1" "$UA1" change_password "$TDIR/r.json")
check_eq "GET ile change_password reddedilir" "405" "$GOT"
check_eq "GET -> METHOD_NOT_ALLOWED" "METHOD_NOT_ALLOWED" "$(jget "$TDIR/r.json" '$.error.code')"

JAR_ANON="$TDIR/jar_anon.txt"; rm -f "$JAR_ANON"
api_get "$JAR_ANON" "PW-Test-anon/1.0" session "$TDIR/r.json" >/dev/null
CSRF_ANON=$(jget "$TDIR/r.json" '$.csrf_token')
GOT=$(api_post "$JAR_ANON" "PW-Test-anon/1.0" "$CSRF_ANON" change_password \
  '{"current_password":"Test123!xyz","password":"Yeni123!abc","password_confirm":"Yeni123!abc"}' "$TDIR/r.json")
check_eq "oturumsuz istek reddedilir" "401" "$GOT"
check_eq "oturumsuz -> AUTH_REQUIRED" "AUTH_REQUIRED" "$(jget "$TDIR/r.json" '$.error.code')"

GOT=$(curl -s -m 15 -A "$UA1" -b "$JAR1" -c "$JAR1" -H "Content-Type: application/json" \
  -d '{"current_password":"Test123!xyz","password":"Yeni123!abc","password_confirm":"Yeni123!abc"}' \
  -o "$TDIR/r.json" -w '%{http_code}' "$BASE?action=change_password")
check_eq "CSRF jetonsuz istek reddedilir" "419" "$GOT"
check_eq "CSRF'siz -> CSRF_FAILED" "CSRF_FAILED" "$(jget "$TDIR/r.json" '$.error.code')"
CSRF1=$(jget "$TDIR/r.json" '$.error.details.csrf_token')
[ -n "$CSRF1" ] && [ "$CSRF1" != "__MISSING__" ] && ok "419 yanıtı güncel jetonu döndürdü (istemci kendini onarabilir)" \
  || { CSRF1=$(api_get "$JAR1" "$UA1" session "$TDIR/r.json" >/dev/null; jget "$TDIR/r.json" '$.csrf_token'); bad "419 yanıtında güncel jeton yok"; }

echo "== 3) Güncel şifre doğrulaması =="
CUR_PW="Test123!xyz"          # her başarılı değişimde güncellenir
dump_hash pwuser1; HASH_BEFORE=$(cat "$TDIR/hash_pwuser1.txt")
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json 'Yanlis123!abc' 'Yeni123!abc')" "$TDIR/r.json")
check_eq "yanlış güncel şifre reddedilir" "401" "$GOT"
check_eq "yanlış güncel şifre -> INVALID_CREDENTIALS" "INVALID_CREDENTIALS" "$(jget "$TDIR/r.json" '$.error.code')"
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json '' 'Yeni123!abc')" "$TDIR/r.json")
check_eq "boş güncel şifre reddedilir" "401" "$GOT"
# Güncel şifre alanı HİÇ gönderilmez; yeni şifre geçerli kurulur ki istek
# biçim denetimini geçsin ve sıra gerçekten güncel şifre doğrulamasına gelsin.
NO_CURRENT=$(python3 -c 'import json;print(json.dumps({"password":"Yeni123!abc","password_confirm":"Yeni123!abc"}))')
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$NO_CURRENT" "$TDIR/r.json")
check_eq "güncel şifre alanı hiç gönderilmezse reddedilir" "401" "$GOT"
dump_hash pwuser1
check_eq "başarısız denemeler hash'i DEĞİŞTİRMEDİ" "$HASH_BEFORE" "$(cat "$TDIR/hash_pwuser1.txt")"

echo "== 4) Yeni şifre kuralları (kayıt akışıyla aynı doğrulayıcı) =="
LONG=$(python3 -c "print('A1!'+'x'*126)")
try_rule() { # <etiket> <json> <beklenen-kod>
  local code; code=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$2" "$TDIR/r.json")
  check_eq "$1 (HTTP 422)" "422" "$code"
  check_eq "$1 -> $3" "$3" "$(jget "$TDIR/r.json" '$.error.code')"
}
try_rule "tekrar eşleşmiyor"   "$(pw_json "$CUR_PW" 'Yeni123!abc' 'Baska123!abc')" PASSWORD_MISMATCH
try_rule "9 karakter (kısa)"   "$(pw_json "$CUR_PW" 'Yeni12!ab')"                  INVALID_PASSWORD
try_rule "129 karakter (uzun)" "$(pw_json "$CUR_PW" "$LONG")"                      INVALID_PASSWORD
try_rule "boşluk içeriyor"     "$(pw_json "$CUR_PW" 'Yeni 123!abc')"               INVALID_PASSWORD
try_rule "özel karakter yok"   "$(pw_json "$CUR_PW" 'Yeni1234abc')"                INVALID_PASSWORD
try_rule "rakam yok"           "$(pw_json "$CUR_PW" 'Yeni!abcdEFG')"               INVALID_PASSWORD
try_rule "harf yok"            "$(pw_json "$CUR_PW" '12345!6789')"                 INVALID_PASSWORD
try_rule "tekrar alanı eksik"  "$(pw_json_only "$CUR_PW" 'Yeni123!abc')"           INVALID_PASSWORD
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" "$CUR_PW")" "$TDIR/r.json")
check_eq "yeni şifre = güncel şifre reddedilir" "422" "$GOT"
check_eq "aynı şifre -> SAME_PASSWORD" "SAME_PASSWORD" "$(jget "$TDIR/r.json" '$.error.code')"
dump_hash pwuser1
check_eq "kural ihlalleri hash'i DEĞİŞTİRMEDİ" "$HASH_BEFORE" "$(cat "$TDIR/hash_pwuser1.txt")"

echo "== 5) Başarılı değişim =="
VER_BEFORE=$(sql "SELECT session_version FROM users WHERE id=$UID1")
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" 'Yeni123!abc')" "$TDIR/r.json")
check_eq "doğru istek kabul edilir" "200" "$GOT"
check_eq "yanıt ok=true" "True" "$(jget "$TDIR/r.json" '$.ok')"
MSG=$(jget "$TDIR/r.json" '$.message')
[ -n "$MSG" ] && [ "$MSG" != "__MISSING__" ] && ok "yanıt mesajı döndü" || bad "yanıt mesajı yok"
CUR_PW='Yeni123!abc'
dump_hash pwuser1; HASH_AFTER=$(cat "$TDIR/hash_pwuser1.txt")
[ "$HASH_AFTER" != "$HASH_BEFORE" ] && ok "saklanan hash gerçekten değişti" || bad "hash değişmedi"
check_eq "saklanan hash YENİ şifreyi doğruluyor" "EVET"  "$(hash_verifies pwuser1 "$CUR_PW")"
check_eq "saklanan hash ESKİ şifreyi doğrulamıyor" "HAYIR" "$(hash_verifies pwuser1 'Test123!xyz')"
VER_AFTER=$(sql "SELECT session_version FROM users WHERE id=$UID1")
check_eq "session_version tam 1 arttı" "$((VER_BEFORE+1))" "$VER_AFTER"

echo "== 6) Oturum politikası: bu cihaz kalır, diğerleri düşer =="
GOT=$(api_get "$JAR1" "$UA1" session "$TDIR/r.json")
check_eq "bu cihazın oturumu hâlâ geçerli (HTTP 200)" "200" "$GOT"
check_eq "oturum aynı kullanıcıyı döndürüyor" "pwuser1" "$(jget "$TDIR/r.json" '$.user.username')"

# Yabancı bir cihazın "beni hatırla" anahtarı elle eklenir: gerçek ikinci bir
# cihaz kaydı cihaz bağlama (assertDeviceMayUseAccount) yüzünden test
# edilemediği için tablo düzeyinde kurulur. Değişimden sonra SİLİNMELİDİR.
FOREIGN_SELECTOR=$(python3 -c "import secrets;print(secrets.token_hex(16))")
FOREIGN_HASH=$(python3 -c "import secrets;print(secrets.token_hex(32))")
sql "INSERT INTO auth_remember_tokens (user_id, selector, validator_hash, session_version, device_token_hash, user_agent, expires_at)
     VALUES ($UID1, '$FOREIGN_SELECTOR', '$FOREIGN_HASH', $VER_AFTER, '$FOREIGN_HASH', 'ForeignDevice/9.9', UTC_TIMESTAMP() + INTERVAL 30 DAY)"
check_eq "önkoşul: yabancı remember token eklendi" "1" \
  "$(sql "SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id=$UID1 AND selector='$FOREIGN_SELECTOR'")"
OWN_BEFORE=$(sql "SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id=$UID1 AND selector<>'$FOREIGN_SELECTOR'")

GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" 'Sonra456!def')" "$TDIR/r.json")
check_eq "ikinci değişim de kabul edilir" "200" "$GOT"
CUR_PW='Sonra456!def'
check_eq "yabancı cihazın anahtarı SİLİNDİ" "0" \
  "$(sql "SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id=$UID1 AND selector='$FOREIGN_SELECTOR'")"
check_eq "bu cihazın anahtarı KORUNDU" "$OWN_BEFORE" \
  "$(sql "SELECT COUNT(*) FROM auth_remember_tokens WHERE user_id=$UID1 AND selector<>'$FOREIGN_SELECTOR'")"
VER_NOW=$(sql "SELECT session_version FROM users WHERE id=$UID1")
check_eq "korunan anahtar yeni oturum sürümüne taşındı" "$VER_NOW" \
  "$(sql "SELECT session_version FROM auth_remember_tokens WHERE user_id=$UID1 ORDER BY id DESC LIMIT 1")"
GOT=$(api_get "$JAR1" "$UA1" session "$TDIR/r.json")
check_eq "ikinci değişimden sonra da oturum açık" "200" "$GOT"

echo "== 7) Brute-force politikası =="
KEY1=$(rate_key change_password "$UID1")
KEY2=$(rate_key change_password "$UID2")
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'change_password:%'"

# Kural hataları brute-force bütçesini TÜKETMEMELİ: tüketseydi yeni şifre
# kuralına takılan meşru kullanıcı birkaç denemede kendi hesabını kilitlerdi.
for i in 1 2 3; do
  GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" 'bosluk var 1!')" "$TDIR/r.json")
  [ "$GOT" = "422" ] || bad "kural denemesi $i 422 vermedi (HTTP $GOT)"
done
ok "3 kural ihlali 422 döndü"
check_eq "kural hataları hız sayacını TÜKETMEDİ" "0" \
  "$(sql "SELECT COUNT(*) FROM rate_limits WHERE rate_key='$KEY1'")"

for i in 1 2 3; do
  GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json 'Deneme123!x' 'Baska789!gh')" "$TDIR/r.json")
  check_eq "hatalı güncel şifre denemesi $i -> 401" "401" "$GOT"
done
check_eq "3 hatalı deneme sayaca işlendi" "3" "$(sql "SELECT attempts FROM rate_limits WHERE rate_key='$KEY1'")"
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" 'Temiz123!qr')" "$TDIR/r.json")
check_eq "başarılı değişim kabul edildi" "200" "$GOT"
CUR_PW='Temiz123!qr'
check_eq "başarılı değişim sayacı SIFIRLADI" "0" "$(sql "SELECT COUNT(*) FROM rate_limits WHERE rate_key='$KEY1'")"

FAILS_OK=1
for i in 1 2 3 4 5; do
  GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json 'Saldiri123!' 'EleGecir1!x')" "$TDIR/r.json")
  [ "$GOT" = "401" ] || { FAILS_OK=0; bad "$i. hatalı deneme 401 vermedi (HTTP $GOT)"; }
done
[ "$FAILS_OK" = "1" ] && ok "5 hatalı güncel şifre denemesi de 401 döndü"
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" 'EleGecir1!x')" "$TDIR/r.json")
check_eq "6. deneme kilitlendi" "429" "$GOT"
check_eq "kilit -> RATE_LIMITED" "RATE_LIMITED" "$(jget "$TDIR/r.json" '$.error.code')"
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" change_password "$(pw_json "$CUR_PW" 'Dogru123!zx')" "$TDIR/r.json")
check_eq "kilitliyken DOĞRU şifre bile işlenmez" "429" "$GOT"
check_eq "kilit sırasında hash değişmedi" "HAYIR" "$(hash_verifies pwuser1 'Dogru123!zx')"
# Kilit KULLANICI bazlı olmalı: ücretsiz paylaşımlı hosting'de herkes aynı
# IP'den görünür, IP bazlı kilit tüm topluluğu kilitleydi.
CSRF2=$(refresh_csrf "$JAR2" "$UA2")
GOT=$(api_post "$JAR2" "$UA2" "$CSRF2" change_password "$(pw_json 'Test123!xyz' 'Komsu123!ab')" "$TDIR/r.json")
check_eq "kilit kullanıcı bazlı: pwuser2 aynı IP'den etkilenmedi" "200" "$GOT"

echo "== 8) Giriş akışıyla uçtan uca doğrulama =="
CSRF1=$(refresh_csrf "$JAR1" "$UA1")
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" login '{"username":"pwuser1","password":"Sonra456!def"}' "$TDIR/r.json")
check_eq "ESKİ şifreyle giriş reddedilir" "401" "$GOT"
check_eq "eski şifre -> INVALID_CREDENTIALS" "INVALID_CREDENTIALS" "$(jget "$TDIR/r.json" '$.error.code')"
CSRF1=$(refresh_csrf "$JAR1" "$UA1")
GOT=$(api_post "$JAR1" "$UA1" "$CSRF1" login "$(python3 -c 'import json,sys;print(json.dumps({"username":"pwuser1","password":sys.argv[1]}))' "$CUR_PW")" "$TDIR/r.json")
check_eq "YENİ şifreyle giriş kabul edilir" "200" "$GOT"
check_eq "yeni şifreyle giriş ok=true" "True" "$(jget "$TDIR/r.json" '$.ok')"

echo "== 9) Arayüz: Ayarlar > Güvenlik (gerçek Chromium) =="
export NODE_PATH=/home/user/.asra-tests/node_modules
set +e
ORIGIN="http://127.0.0.1:$PORT" TEST_USER="pwui" TEST_PASSWORD="Test123!xyz" \
  node "$SDIR/password_change_browser_test.js" "$TDIR" 2>&1 | tail -46
NODE_EXIT=${PIPESTATUS[0]}
set -e 2>/dev/null || true
# Betik çökerse suite SESSİZCE geçmemeli.
if [ ! -f "$TDIR/browser_counters.txt" ]; then
  bad "tarayıcı testi sonuç üretmedi (node exit=$NODE_EXIT)"
fi
while read -r k v; do
  case "$k" in
    PASS) for i in $(seq 1 "${v:-0}"); do ok "şifre değiştirme arayüzü doğrulaması"; done ;;
    FAIL) for i in $(seq 1 "${v:-0}"); do bad "şifre değiştirme arayüzü doğrulaması"; done ;;
  esac
done < <(cat "$TDIR/browser_counters.txt" 2>/dev/null || true)

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] && echo "TÜM TESTLER GEÇTİ" || { echo "--- php.log ---"; tail -14 "$TDIR/php.log"; }
rm -rf "$TDIR"
exit "$FAIL"
