#!/usr/bin/env bash
# ============================================================================
#  ASRA E2E Test v2 — BASH/CLI
#  Kapsam:
#   - davet/kayıt/giriş akışı
#   - ERIŞİM KODU: açıkken sınırsız giriş (2 farklı cihaz + 2 farklı kayıt),
#     kapatınca kod ölür, tekrar açılınca YENİ kod üretilir
#   - HİT KORUMASI HER ZAMAN AÇIK: kod açık da kapalı da bütçe 60/dk (testte)
#   - hatırla-token ile oturum kurtarma (güncelleme senaryosu)
#   - 12-aylık inaktiflik penceresi, CSRF koruması
# ============================================================================
set -u
BASE="http://127.0.0.1:8090/auth.php"
# Yol BETİĞİN KENDİ KONUMUNDAN türetilir: sabit yol yazılırsa test, düzenlenen
# kopyayı değil başka bir kopyayı ölçer (bu hata yaşandı — 53 doğrulama
# orijinal kod üzerinde koşmuştu).
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run"
rm -rf "$TDIR"; mkdir -p "$TDIR"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }

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

get_cookie() { awk -v name="$2" '$6==name {print $7}' "$1" | tail -1; }
sql() { sudo mysql -N asra_test -e "$1"; }

echo "== 0) Test izolasyonu: ortam sıfırdan kurulur =="
bash "$SDIR/reset_env.sh"
sql "SELECT 1" > /dev/null && ok "veritabanı sıfırdan kuruldu" || bad "veritabanı kurulamadı"

echo "== 1) Önyükleme (session bootstrap) =="
curl -s -c "$TDIR/jar_main.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "session ok=True" "True" "$(python3 -c "import json;print(json.load(open('$TDIR/r.json')).get('ok'))")"
CSRF=$(jget "$TDIR/r.json" '$.csrf_token')
[ -n "$CSRF" ] && [ "$CSRF" != "__MISSING__" ] && ok "csrf_token alındı" || bad "csrf_token yok"

post() { # JAR CSRF AKSİYON JSON -> r.json
  curl -s -b "$1" -c "$1" -H "X-CSRF-Token: $2" -H "Content-Type: application/json" \
    -d "$4" "$BASE?action=$3" -o "$TDIR/r.json"; }

echo "== 2) Yanlış davet kodu reddedilir =="
post "$TDIR/jar_main.txt" "$CSRF" check_invite_code '{"code":"YANLISKOD123"}'
check_eq "INVALID_INVITE" "INVALID_INVITE" "$(jget "$TDIR/r.json" '$.error.code')"

echo "== 3) Kalıcı davet kodu kabul edilir =="
post "$TDIR/jar_main.txt" "$CSRF" check_invite_code '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}'
check_eq "davet doğrulandı ok=True" "True" "$(jget "$TDIR/r.json" '$.ok')"

echo "== 4) Kayıt (onay bekleyen) =="
post "$TDIR/jar_main.txt" "$CSRF" register '{"username":"testuser1","password":"Test123!xyz","password_confirm":"Test123!xyz"}'
check_eq "kayıt approval_required" "True" "$(jget "$TDIR/r.json" '$.approval_required')"
sql "UPDATE users SET approval_status='approved' WHERE username='testuser1'" > /dev/null

echo "== 5) Giriş + hatırla çerezi =="
post "$TDIR/jar_main.txt" "$CSRF" login '{"username":"testuser1","password":"Test123!xyz"}'
check_eq "giriş ok" "True" "$(jget "$TDIR/r.json" '$.ok')"
REMEMBER=$(get_cookie "$TDIR/jar_main.txt" ASRA_REMEMBER)
DEVICE=$(get_cookie "$TDIR/jar_main.txt" ASRA_DEVICE)
[ -n "$REMEMBER" ] && ok "ASRA_REMEMBER çerezi verildi" || bad "ASRA_REMEMBER çerezi yok"
curl -s -b "$TDIR/jar_main.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "hit koruma bayrağı (kod kapalıyken bile) true" "True" "$(jget "$TDIR/r.json" '$.hit_economy')"

echo "== 6) Oda listesi + mesaj =="
curl -s -b "$TDIR/jar_main.txt" "$BASE?action=get_rooms" -o "$TDIR/r.json"
ROOMCOUNT=$(python3 -c "import json;print(len(json.load(open('$TDIR/r.json'))['rooms']))" 2>/dev/null || echo HATA)
# Beklenen sayı SABİT yazılmaz: auth.php'deki canonicalRoomCatalog() tek
# doğruluk kaynağıdır ve ensureCoreSchema() bu kataloğu veritabanına tohumlar.
# Sayı kaynaktan türetilir ki katalog büyüyünce test eskimesin.
EXPECTED_ROOMS=$(awk '/function canonicalRoomCatalog/,/^}/' "$ROOT/asra-site-fix/auth.php" | grep -cE "^[[:space:]]+[0-9]+ =>")
check_eq "oda kataloğu tam ($EXPECTED_ROOMS oda)" "$EXPECTED_ROOMS" "$ROOMCOUNT"
post "$TDIR/jar_main.txt" "$CSRF" send_message '{"room_id":1,"message":"E2E test mesajı"}'
check_eq "mesaj gönderildi" "True" "$(jget "$TDIR/r.json" '$.ok')"

echo "== 7) Yetkili girişi =="
post "$TDIR/jar_main.txt" "$CSRF" admin_login '{"password":"AsraAsraJshe+sbuwnduxmsgdnfusmg"}'
check_eq "admin ok" "True" "$(jget "$TDIR/r.json" '$.ok')"

echo "== 8) Kod bölümü başlangıç durumu (kapalı, kod yok) =="
curl -s -b "$TDIR/jar_main.txt" "$BASE?action=admin_access_code_status" -o "$TDIR/r.json"
check_eq "başlangıç kapalı" "False" "$(jget "$TDIR/r.json" '$.enabled')"
check_eq "başlangıçta kod gösterilmez" "" "$(jget "$TDIR/r.json" '$.code')"

echo "== 9) KOD AÇILIYOR — otomatik YENİ kod üretilir =="
post "$TDIR/jar_main.txt" "$CSRF" admin_set_access_code '{"enabled":true}'
check_eq "açma ok" "True" "$(jget "$TDIR/r.json" '$.ok')"
CODE1=$(jget "$TDIR/r.json" '$.code')
check_eq "durum açık" "True" "$(curl -s -b "$TDIR/jar_main.txt" "$BASE?action=admin_access_code_status" | python3 -c "import json,sys;print(json.load(sys.stdin)['enabled'])")"
python3 -c "import re,sys; sys.exit(0 if re.fullmatch(r'[A-Z2-9]{4}(-[A-Z2-9]{4}){3}', sys.argv[1]) else 1)" "$CODE1" \
  && ok "kod karışık biçiminde: $CODE1" || bad "kod biçimi hatalı: $CODE1"
curl -s -b "$TDIR/jar_main.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "session hit_economy=true (kalıcı)" "True" "$(jget "$TDIR/r.json" '$.hit_economy')"

echo "== 10) KODLA SINIRSIZ ERİŞİM: 2 farklı cihaz + 2 farklı kayıt =="
# Cihaz A
rm -f "$TDIR/jar_a.txt"
curl -s -A "E2E-DevA/1.0" -c "$TDIR/jar_a.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRA=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-DevA/1.0" -b "$TDIR/jar_a.txt" -c "$TDIR/jar_a.txt" -H "X-CSRF-Token: $CSRA" -H "Content-Type: application/json" -d "{\"code\":\"$CODE1\"}" "$BASE?action=check_invite_code" -o "$TDIR/r.json"
check_eq "cihaz A: kodla doğrulama" "True" "$(jget "$TDIR/r.json" '$.ok')"
curl -s -A "E2E-DevA/1.0" -b "$TDIR/jar_a.txt" -c "$TDIR/jar_a.txt" -H "X-CSRF-Token: $CSRA" -H "Content-Type: application/json" -d '{"username":"openuser_a","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
check_eq "cihaz A: kayıt BAŞARILI (limit yok)" "True" "$(jget "$TDIR/r.json" '$.approval_required')"
sql "UPDATE users SET approval_status='approved' WHERE username='openuser_a'" > /dev/null
# Cihaz B — AYNI KODU KULLANIR
rm -f "$TDIR/jar_b.txt"
curl -s -A "E2E-DevB/1.0" -c "$TDIR/jar_b.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRB=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-DevB/1.0" -b "$TDIR/jar_b.txt" -c "$TDIR/jar_b.txt" -H "X-CSRF-Token: $CSRB" -H "Content-Type: application/json" -d "{\"code\":\"$CODE1\"}" "$BASE?action=check_invite_code" -o "$TDIR/r.json"
check_eq "cihaz B: AYNI kodla doğrulama (tüketilmedi)" "True" "$(jget "$TDIR/r.json" '$.ok')"
curl -s -A "E2E-DevB/1.0" -b "$TDIR/jar_b.txt" -c "$TDIR/jar_b.txt" -H "X-CSRF-Token: $CSRB" -H "Content-Type: application/json" -d '{"username":"openuser_b","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
check_eq "cihaz B: kayıt BAŞARILI (hakkı tüketilmedi)" "True" "$(jget "$TDIR/r.json" '$.approval_required')"
sql "UPDATE users SET approval_status='approved' WHERE username='openuser_b'" > /dev/null

echo "== 10b) KÖK TEST: FARKLI KİŞİLER BİRBİRİYLE EŞLEŞMESİN (ortak UA/dil) =="
# Kişi 1 ve Kişi 2 AYNI tarayıcı/model/dil (birebir aynı UA) ama FARKLI insanlar
# (farklı tarayıcı profili = farklı ASRA_DEVICE token'ı).
rm -f "$TDIR/jar_p1.txt" "$TDIR/jar_p2.txt"
curl -s -A "E2E-SameBrowser/1.0" -c "$TDIR/jar_p1.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSR1=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-SameBrowser/1.0" -b "$TDIR/jar_p1.txt" -c "$TDIR/jar_p1.txt" -H "X-CSRF-Token: $CSR1" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json"
curl -s -A "E2E-SameBrowser/1.0" -b "$TDIR/jar_p1.txt" -c "$TDIR/jar_p1.txt" -H "X-CSRF-Token: $CSR1" -H "Content-Type: application/json" -d '{"username":"person1","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
sql "UPDATE users SET approval_status='approved' WHERE username='person1'" > /dev/null
# Girişte uygulama cihaz kimliği de gönderilir -> token + app hash bağlanır
curl -s -A "E2E-SameBrowser/1.0" -H "X-Asra-Device-Id: asra.e2eperson1" -b "$TDIR/jar_p1.txt" -c "$TDIR/jar_p1.txt" -H "X-CSRF-Token: $CSR1" -H "Content-Type: application/json" -d '{"username":"person1","password":"Test123!xyz"}' "$BASE?action=login" -o "$TDIR/r.json"
check_eq "kişi 1 giriş (token + app hash bağlandı)" "True" "$(jget "$TDIR/r.json" '$.ok')"
# Kişi 2: AYNI UA (aynı fingerprint), YENİ tarayıcı profili
curl -s -A "E2E-SameBrowser/1.0" -c "$TDIR/jar_p2.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSR2=$(jget "$TDIR/r.json" '$.csrf_token')
check_eq "KİŞİ 2: başkasının hesabıyla EŞLEŞMEZ (has_account=false)" "False" "$(jget "$TDIR/r.json" '$.device_has_account')"
check_eq "KİŞİ 2: bound_username boş" "null" "$(jget "$TDIR/r.json" '$.bound_username')"
curl -s -A "E2E-SameBrowser/1.0" -b "$TDIR/jar_p2.txt" -c "$TDIR/jar_p2.txt" -H "X-CSRF-Token: $CSR2" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json"
check_eq "KİŞİ 2: davet doğrulaması (kendine has kimlik)" "True" "$(jget "$TDIR/r.json" '$.ok')"
curl -s -A "E2E-SameBrowser/1.0" -b "$TDIR/jar_p2.txt" -c "$TDIR/jar_p2.txt" -H "X-CSRF-Token: $CSR2" -H "Content-Type: application/json" -d '{"username":"person2","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
check_eq "KİŞİ 2: kayıt ENGELLENMEDİ (cihaz çakışması yok)" "True" "$(jget "$TDIR/r.json" '$.approval_required')"
sql "UPDATE users SET approval_status='approved' WHERE username='person2'" > /dev/null
# Kişi 1'in kendi cihazı hâlâ kendisini tanımalı (token yolu sağlam)
curl -s -A "E2E-SameBrowser/1.0" -b "$TDIR/jar_p1.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "KİŞİ 1: kendi cihazı hâlâ tanınıyor" "True" "$(jget "$TDIR/r.json" '$.device_has_account')"
check_eq "KİŞİ 1: doğru hesap person1" "person1" "$(jget "$TDIR/r.json" '$.bound_username')"
# Uygulama cihaz kimliği (app hash) yolu: farklı tarayıcı, aynı app ID -> eşleşmeli
rm -f "$TDIR/jar_p3.txt"
curl -s -A "E2E-OtherBrowser/9.9" -H "X-Asra-Device-Id: asra.e2eperson1" -c "$TDIR/jar_p3.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "app kimliği: doğru hesapla eşleşir" "person1" "$(jget "$TDIR/r.json" '$.bound_username')"
# Remember güveni: fingerprint TEK BAŞINA yetmez (token + app yoksa geri yükleme YOK)
REMEMBER_P1=$(get_cookie "$TDIR/jar_p1.txt" ASRA_REMEMBER)
rm -f "$TDIR/jar_p4.txt"
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tASRA_REMEMBER\t%s\n' "$REMEMBER_P1" > "$TDIR/jar_p4.txt"
curl -s -A "E2E-SameBrowser/1.0" -b "$TDIR/jar_p4.txt" -c "$TDIR/jar_p4.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "fingerprint tek başına güven VERMEZ (geri yükleme yok)" "False" "$(jget "$TDIR/r.json" '$.authenticated')"

echo "== 10c) RED BLOK TAŞMASI YOK: blok yalnızca kişinin kendisini etkiler =="
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'register:%'" > /dev/null
rm -f "$TDIR/jar_rej.txt"
curl -s -A "E2E-Rej/1.0" -c "$TDIR/jar_rej.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRJ=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-Rej/1.0" -b "$TDIR/jar_rej.txt" -c "$TDIR/jar_rej.txt" -H "X-CSRF-Token: $CSRJ" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json"
curl -s -A "E2E-Rej/1.0" -b "$TDIR/jar_rej.txt" -c "$TDIR/jar_rej.txt" -H "X-CSRF-Token: $CSRJ" -H "Content-Type: application/json" -d '{"username":"rejuser","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
check_eq "rejuser kaydı (pending)" "True" "$(jget "$TDIR/r.json" '$.approval_required')"
IDREJ=$(sql "SELECT id FROM users WHERE username='rejuser'")
post "$TDIR/jar_main.txt" "$CSRF" admin_decide_approval "{\"user_id\":$IDREJ,\"decision\":\"reject\"}"
check_eq "rejuser reddedildi" "Başvuru reddedildi ve hesap silindi." "$(jget "$TDIR/r.json" '$.message')"
# Aynı fingerprint (birebir aynı UA), FARKLI kişi, yeni tarayıcı profili -> bloklanmamalı
rm -f "$TDIR/jar_rej2.txt"
curl -s -A "E2E-Rej/1.0" -c "$TDIR/jar_rej2.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "farklı kişi (aynı UA/model/dil) bloklanMAZ" "null" "$(jget "$TDIR/r.json" '$.approval_block')"
# Aynı tarayıcı (aynı token) -> serinleme devam etmeli.
# İki geçerli biçim: temiz oturumda payload.approval_block; silinmiş kullanıcının
# eski oturumunda 410 ACCOUNT_DELETED -> details.approval_block.
curl -s -A "E2E-Rej/1.0" -b "$TDIR/jar_rej.txt" "$BASE?action=session" -o "$TDIR/r.json"
ATYPE=$(python3 -c "
import json
d=json.load(open('$TDIR/r.json'))
ab = d.get('approval_block') or d.get('error',{}).get('details',{}).get('approval_block') or {}
print(ab.get('type',''))")
check_eq "aynı tarayıcı serinlemede (rejected)" "rejected" "$ATYPE"
# Aynı kullanıcı adı, YENİ tarayıcı -> username serinlemesi devam etmeli
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'register:%'" > /dev/null
rm -f "$TDIR/jar_rej3.txt"
curl -s -A "E2E-Rej3/9.9" -c "$TDIR/jar_rej3.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRJ3=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-Rej3/9.9" -b "$TDIR/jar_rej3.txt" -c "$TDIR/jar_rej3.txt" -H "X-CSRF-Token: $CSRJ3" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json"
curl -s -A "E2E-Rej3/9.9" -b "$TDIR/jar_rej3.txt" -c "$TDIR/jar_rej3.txt" -H "X-CSRF-Token: $CSRJ3" -H "Content-Type: application/json" -d '{"username":"rejuser","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
check_eq "aynı kullanıcı adı yeni tarayıcıda serinlemede" "APPROVAL_COOLDOWN" "$(jget "$TDIR/r.json" '$.error.code')"
# Farklı kişi + farklı kullanıcı adı + aynı UA -> kayıt serbest
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'register:%'" > /dev/null
rm -f "$TDIR/jar_rej4.txt"
curl -s -A "E2E-Rej/1.0" -c "$TDIR/jar_rej4.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRJ4=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-Rej/1.0" -b "$TDIR/jar_rej4.txt" -c "$TDIR/jar_rej4.txt" -H "X-CSRF-Token: $CSRJ4" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json"
curl -s -A "E2E-Rej/1.0" -b "$TDIR/jar_rej4.txt" -c "$TDIR/jar_rej4.txt" -H "X-CSRF-Token: $CSRJ4" -H "Content-Type: application/json" -d '{"username":"rejuser_other","password":"Test123!xyz","password_confirm":"Test123!xyz"}' "$BASE?action=register" -o "$TDIR/r.json"
check_eq "farklı kişi + farklı ad (aynı UA) kayıt SERBEST" "True" "$(jget "$TDIR/r.json" '$.approval_required')"
sql "UPDATE users SET approval_status='approved' WHERE username='rejuser_other'" > /dev/null

echo "== 11) HİT KORUMASI HER ZAMAN AÇIK =="
# 4 test kullanıcısı (kopya). Test sunucusu ASRA_HOT_READS_PER_MIN=60 ile
# çalışır (run-tests.sh); üretim varsayılanı 180/dk.
for i in 2 3 4 5; do
  sql "INSERT INTO users (username, password_hash, avatar_color, approval_status, online_status, last_seen)
       SELECT 'hammer$i', password_hash, avatar_color, 'approved', 0, UTC_TIMESTAMP() FROM users WHERE username='testuser1'" > /dev/null
  rm -f "$TDIR/jar_h$i.txt"
  curl -s -A "E2E-Hammer-$i/1.0" -c "$TDIR/jar_h$i.txt" "$BASE?action=session" -o "$TDIR/r.json"
  CSRFH=$(jget "$TDIR/r.json" '$.csrf_token')
  curl -s -A "E2E-Hammer-$i/1.0" -b "$TDIR/jar_h$i.txt" -c "$TDIR/jar_h$i.txt" \
    -H "X-CSRF-Token: $CSRFH" -H "Content-Type: application/json" \
    -d "{\"username\":\"hammer$i\",\"password\":\"Test123!xyz\"}" "$BASE?action=login" -o "$TDIR/r.json"
  [ "$(jget "$TDIR/r.json" '$.ok')" = "True" ] || bad "hammer$i girişi başarısız"
done
ok "4 test kullanıcısı hazır (kod AÇIK durumda)"

count_host_limits() {
  local total=0 j n code
  for j in "$@"; do
    for n in $(seq 1 20); do
      code=$(curl -s -A "E2E-Hammer-$(basename "$j" | sed 's/jar_h//;s/\.txt//')" -b "$j" -o "$TDIR/ab.json" -w '%{http_code}' "$BASE?action=get_rooms")
      if [ "$code" = "429" ] && python3 -c "import json,sys; d=json.load(open('$TDIR/ab.json')); sys.exit(0 if d.get('error',{}).get('code')=='HOST_LIMIT_REACHED' else 1)" 2>/dev/null; then
        total=$((total+1))
      fi
    done
  done
  echo "$total"
}

sql "DELETE FROM rate_limits WHERE rate_key LIKE 'global_read:%'" > /dev/null
ON_HITS=$(count_host_limits "$TDIR/jar_h2.txt" "$TDIR/jar_h3.txt" "$TDIR/jar_h4.txt" "$TDIR/jar_h5.txt")
[ "$ON_HITS" -ge 1 ] && ok "kod AÇIK: 80 istekte $ON_HITS host-limit (koruma aktif)" || bad "kod AÇIK: koruma tetiklenmedi"

# Kod KAPATILSA BİLE koruma devam eder
post "$TDIR/jar_main.txt" "$CSRF" admin_set_access_code '{"enabled":false}'
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'global_read:%'" > /dev/null
OFF_HITS=$(count_host_limits "$TDIR/jar_h2.txt" "$TDIR/jar_h3.txt" "$TDIR/jar_h4.txt" "$TDIR/jar_h5.txt")
[ "$OFF_HITS" -ge 1 ] && ok "kod KAPALI: 80 istekte $OFF_HITS host-limit (koruma Hâlâ aktif — kalıcı)" || bad "kod KAPALI: koruma tetiklenmedi"
# 90sn'lik soğuma süresi "geçmiş" sayılır (sayaç temizliği)
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'global_read:%'" > /dev/null
sleep 1
check_eq "kapatınca hit_economy bayrağı hâlâ true" "True" "$(curl -s -b "$TDIR/jar_main.txt" "$BASE?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin)['hit_economy'])")"

echo "== 12) KOD KAPATILINCA KOD ÖLÜR, AÇILINCA YENİ KOD =="
OLD_CODE="$CODE1"
curl -s -A "E2E-DevC/1.0" -c "$TDIR/jar_c.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRC=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-DevC/1.0" -b "$TDIR/jar_c.txt" -c "$TDIR/jar_c.txt" -H "X-CSRF-Token: $CSRC" -H "Content-Type: application/json" -d "{\"code\":\"$OLD_CODE\"}" "$BASE?action=check_invite_code" -o "$TDIR/r.json"
check_eq "kapatılmış eski kod REDDEDİLİR" "INVALID_INVITE" "$(jget "$TDIR/r.json" '$.error.code')"
post "$TDIR/jar_main.txt" "$CSRF" admin_set_access_code '{"enabled":true}'
CODE2=$(jget "$TDIR/r.json" '$.code')
[ "$CODE2" != "$OLD_CODE" ] && [ -n "$CODE2" ] && ok "tekrar açılışta YENİ kod üretildi: $OLD_CODE -> $CODE2" || bad "yeni kod üretilmedi (eski: $OLD_CODE, yeni: $CODE2)"
curl -s -A "E2E-DevC/1.0" -b "$TDIR/jar_c.txt" -c "$TDIR/jar_c.txt" -H "X-CSRF-Token: $CSRC" -H "Content-Type: application/json" -d "{\"code\":\"$CODE2\"}" "$BASE?action=check_invite_code" -o "$TDIR/r.json"
check_eq "yeni kod ÇALIŞIYOR" "True" "$(jget "$TDIR/r.json" '$.ok')"

echo "== 13) Uygulama güncellemesi simülasyonu: oturum dosyaları silindi =="
sql "DELETE FROM rate_limits WHERE rate_key LIKE 'global_read:%'" > /dev/null
find /tmp -maxdepth 1 -name 'sess_*' -delete 2>/dev/null
sleep 1
rm -f "$TDIR/jar_reborn.txt"
printf '127.0.0.1\tFALSE\t/\tFALSE\t0\tASRA_REMEMBER\t%s\n127.0.0.1\tFALSE\t/\tFALSE\t0\tASRA_DEVICE\t%s\n' "$REMEMBER" "$DEVICE" > "$TDIR/jar_reborn.txt"
curl -s -b "$TDIR/jar_reborn.txt" -c "$TDIR/jar_reborn.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "yeni cihazda oturum sessizce geri yüklendi" "True" "$(jget "$TDIR/r.json" '$.authenticated')"
check_eq "doğru kullanıcı geri döndü" "testuser1" "$(jget "$TDIR/r.json" '$.user.username')"
curl -s -b "$TDIR/jar_main.txt" -c "$TDIR/jar_main.txt" "$BASE?action=session" -o "$TDIR/r.json"
check_eq "eski cihazda da kurtarma çalıştı" "True" "$(jget "$TDIR/r.json" '$.authenticated')"
CSRF=$(jget "$TDIR/r.json" '$.csrf_token')
post "$TDIR/jar_main.txt" "$CSRF" admin_login '{"password":"AsraAsraJshe+sbuwnduxmsgdnfusmg"}' > /dev/null
check_eq "kurtarma sonrası yetkili erişimi yeniden kuruldu" "True" "$(jget "$TDIR/r.json" '$.ok')"

echo "== 14) Inaktiflik penceresi 12 ay =="
sql "INSERT INTO users (username, password_hash, avatar_color, approval_status, online_status, last_seen, created_at)
     VALUES ('purge_6m','x','c1','approved',0,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 6 MONTH), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 8 MONTH))" > /dev/null
sql "INSERT INTO users (username, password_hash, avatar_color, approval_status, online_status, last_seen, created_at)
     VALUES ('purge_13m','x','c1','approved',0,DATE_SUB(UTC_TIMESTAMP(), INTERVAL 13 MONTH), DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 MONTH))" > /dev/null
( cd "$ROOT/asra-site-fix" && \
  ASRA_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=asra_test;charset=utf8mb4' ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' php auth.php cleanup > /dev/null 2>&1 )
check_eq "6 ay pasif hesap KORUNDU" "1" "$(sql "SELECT COUNT(*) FROM users WHERE username='purge_6m'")"
check_eq "13 ay pasif hesap arındırıldı" "1" "$(sql "SELECT COUNT(*) FROM deleted_users_log WHERE username='purge_13m'")"

echo "== 15) CSRF koruması (token'siz POST reddedilir) =="
CSRC=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Content-Type: application/json" -d '{"enabled":false}' "$BASE?action=admin_set_access_code")
check_eq "token'siz POST 419 (CSRF_FAILED)" "419" "$CSRC"

echo "== 16) Son durum: kod kapalı, kod değer yok =="
post "$TDIR/jar_main.txt" "$CSRF" admin_set_access_code '{"enabled":false}'
curl -s -b "$TDIR/jar_main.txt" "$BASE?action=admin_access_code_status" -o "$TDIR/r.json"
check_eq "son durum kapalı" "False" "$(jget "$TDIR/r.json" '$.enabled')"
check_eq "son durumda kod boş" "" "$(jget "$TDIR/r.json" '$.code')"

echo
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" = "0" ] && echo "TÜM TESTLER GEÇTİ" || echo "BAŞARISIZ TEST VAR"
exit "$FAIL"
