#!/usr/bin/env bash
# ============================================================================
#  Oturum kaybı + CSRF 419 kendini-onarma testi (kullanıcının prod senaryosu)
#  Senaryo: host, sunucu oturum dosyasını sildi (kilit/GC). Kullanıcı açık
#  sayfada davet kodunu girer -> ilk POST 419 CSRF_FAILED -> istemci yeni
#  token + yeni cookie ile otomatik tekrarlar -> BAŞARILI.
# ============================================================================
set -u
BASE="http://127.0.0.1:8090/auth.php"
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
T="$SDIR/run_csrf"; rm -rf "$T"; mkdir -p "$T"
# Ortam izolasyonu: sabit kullanıcı adı her seferinde taze olmalı
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$SDIR/reset_env.sh"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
sql() { sudo mysql -N asra_test -e "$1"; }

# Kullanıcı hazırla (davet + kayıt + onay + giriş)
curl -s -c "$T/jar.txt" "$BASE?action=session" -o /dev/null
CSRF=$(curl -s -b "$T/jar.txt" -c "$T/jar.txt" "$BASE?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin)['csrf_token'])")
post() { curl -s -b "$T/jar.txt" -c "$T/jar.txt" -H "X-CSRF-Token: $1" -H "Content-Type: application/json" -d "$2" "$BASE?action=$3"; }
post "$CSRF" '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' check_invite_code > /dev/null
post "$CSRF" '{"username":"csrfheal1","password":"Test123!xyz","password_confirm":"Test123!xyz"}' register > /dev/null
sql "UPDATE users SET approval_status='approved' WHERE username='csrfheal1'"
post "$CSRF" '{"username":"csrfheal1","password":"Test123!xyz"}' login > /dev/null

echo "== 1) Sağlıklı oturumla davet kontrolü (referans) =="
curl -s -c "$T/jar.txt" "$BASE?action=session" -o /dev/null
CSRF=$(curl -s -b "$T/jar.txt" -c "$T/jar.txt" "$BASE?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin)['csrf_token'])")
R=$(post "$CSRF" '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' check_invite_code)
echo "$R" | grep -q '"ok":true' && ok "sağlıklı oturumda istek geçiyor" || bad "referans istek geçmedi: $R"

echo "== 2) HOST SENARYOSU: sunucu oturum dosyaları silindi =="
find /tmp -maxdepth 1 -name 'sess_*' -delete
sleep 1
# İstemci hâlâ ESKİ ASRA_SID cookie'sini + ESKİ csrf token'ını taşıyor (açık sekme)
OLD_JAR="$T/jar.txt"
cp "$OLD_JAR" "$T/old_jar.txt"
OLD_CSRF="$CSRF"

echo "== 3) Kullanıcı davet kodunu gönderiyor (eski cookie + eski token) =="
RESP1=$(curl -s -D "$T/h1.txt" -b "$T/old_jar.txt" -o "$T/r1.json" -w '%{http_code}' -H "X-CSRF-Token: $OLD_CSRF" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code")
CODE1=$(python3 -c "import json;print(json.load(open('$T/r1.json')).get('error',{}).get('code',''))")
[ "$RESP1" = "419" ] && [ "$CODE1" = "CSRF_FAILED" ] && ok "ilk istek 419 CSRF_FAILED (beklenen)" || bad "ilk istek beklenmedik: HTTP $RESP1 code=$CODE1"

echo "== 4) Sunucu yeni token + yeni oturum cookie'si verdi mi? =="
NEW_CSRF=$(python3 -c "import json;print(json.load(open('$T/r1.json')).get('error',{}).get('details',{}).get('csrf_token',''))")
[ -n "$NEW_CSRF" ] && ok "419 yanıtında yeni csrf_token var" || bad "419 yanıtında yeni token yok"
grep -q "Set-Cookie: ASRA_SID=" "$T/h1.txt" && ok "419 yanıtında yeni ASRA_SID cookie'si var" || bad "yeni cookie yok"

echo "== 5) İstemci otomatik tekrar (yeni cookie + yeni token) =="
# Tarayıcı davranışı: 419 yanıtındaki Set-Cookie jar'a yazar (eski cookie değişir)
python3 - "$T/h1.txt" "$T/old_jar.txt" <<'EOF'
import sys, re
hdrs = open(sys.argv[1]).read()
lines = open(sys.argv[2]).read().splitlines()
m = re.findall(r'Set-Cookie: (ASRA_SID=[^;]+)', hdrs)
if m:
    kept = [l for l in lines if len(l.split('\t')) > 5 and l.split('\t')[5] != 'ASRA_SID']
    name, val = m[0].split('=', 1)
    kept.append("127.0.0.1\tFALSE\t/\tFALSE\t0\t%s\t%s" % (name, val))
    open(sys.argv[2], 'w').write('\n'.join(kept) + '\n')
EOF
RESP2=$(curl -s -b "$T/old_jar.txt" -c "$T/old_jar.txt" -o "$T/r2.json" -w '%{http_code}' -H "X-CSRF-Token: $NEW_CSRF" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code")
OK2=$(python3 -c "import json;print(json.load(open('$T/r2.json')).get('ok',''))")
[ "$RESP2" = "200" ] && [ "$OK2" = "True" ] && ok "tekrar deneme BAŞARILI — davet kodu doğrulandı (kullanıcı fark etmez)" || bad "tekrar başarısız: HTTP $RESP2 $(head -c 150 $T/r2.json)"

echo
echo "SONUÇ: GEÇTİ $PASS / BAŞARISIZ $FAIL"
[ "$FAIL" = "0" ] && echo "KENDİNİ-ONARMA ÇALIŞIYOR" || echo "SORUN VAR"
exit "$FAIL"
