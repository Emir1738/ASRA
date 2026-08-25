#!/usr/bin/env bash
# ============================================================================
#  ASRA Onay-Kararı Push Bildirimi E2E (APK güncellemesiz mekanizma)
#  - kabul  -> kullanıcının ntfy konusu: "Kabul Edildin"
#  - red    -> kullanıcının ntfy konusu: "Başvuru Reddedildi"
#  - tekrar karar (already_decided) -> yeni push YOK
#  - konusu olmayan kullanıcı -> push yok, karar yine başarılı
#  - duyuru push hâlâ çalışıyor (referans mekanizma)
# ============================================================================
set -u
BASE="http://127.0.0.1:8090/auth.php"
TDIR="/home/user/projects/ASRA/_tests/run_push"
rm -rf "$TDIR"; mkdir -p "$TDIR"
LOG="/home/user/projects/ASRA/_tests/ntfy_log.json"
echo '[]' > "$LOG"
# Ortam izolasyonu: sabit kullanıcı adları / push konuları her seferinde taze olmalı
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
bash "$SDIR/reset_env.sh"

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql -N asra_test -e "$1"; }

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

count_log() { python3 -c "
import json
try:
    d=json.load(open('$LOG'))
    print(len(d) if isinstance(d,list) else 0)
except Exception: print(0)"; }
last_log()  { python3 -c "
import json
try:
    d=json.load(open('$LOG'))
    print(json.dumps(d[-1] if isinstance(d,list) and d else {}, ensure_ascii=False))
except Exception: print('{}')"; }

post() { curl -s -b "$TDIR/jar.txt" -c "$TDIR/jar.txt" -H "X-CSRF-Token: $CSRF" -H "Content-Type: application/json" -d "$2" "$BASE?action=$1" -o "$TDIR/r.json"; }

echo "== 0) Kurulum: yetkili kullanıcı =="
curl -s -c "$TDIR/jar.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSRF=$(jget "$TDIR/r.json" '$.csrf_token')
post check_invite_code '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}'
post register '{"username":"adminuser","password":"Test123!xyz","password_confirm":"Test123!xyz"}'
sql "UPDATE users SET approval_status='approved' WHERE username='adminuser'"
post login '{"username":"adminuser","password":"Test123!xyz"}'
check_eq "giriş" "True" "$(jget "$TDIR/r.json" '$.ok')"
post admin_login '{"password":"AsraAsraJshe+sbuwnduxmsgdnfusmg"}'
check_eq "yetkili erişim" "True" "$(jget "$TDIR/r.json" '$.ok')"

mk_pending_user() { # kullanıcı adı -> id (HER KULLANICI FARKLI TARAYICI PROFİLİ)
  local name="$1"
  local jar="$TDIR/jar_$name.txt"
  local ua="E2E-Push-$name/1.0"
  curl -s -A "$ua" -c "$jar" "$BASE?action=session" -o "$TDIR/r.json"
  local ccsrf=$(jget "$TDIR/r.json" '$.csrf_token')
  curl -s -A "$ua" -b "$jar" -c "$jar" -H "X-CSRF-Token: $ccsrf" -H "Content-Type: application/json" -d '{"code":"DAVETDAVETJSJEJSNFJSNDJSDNFH"}' "$BASE?action=check_invite_code" -o "$TDIR/r.json"
  curl -s -A "$ua" -b "$jar" -c "$jar" -H "X-CSRF-Token: $ccsrf" -H "Content-Type: application/json" -d "{\"username\":\"$name\",\"password\":\"Test123!xyz\",\"password_confirm\":\"Test123!xyz\"}" "$BASE?action=register" -o "$TDIR/r.json"
  sql "SELECT id FROM users WHERE username='$name'"
}

echo "== 1) KABUL bildirimi =="
ID1=$(mk_pending_user pushuser1)
sql "INSERT INTO user_push_topics (topic, user_id, updated_at) VALUES ('asra11112222333344445555aa', $ID1, UTC_TIMESTAMP())"
N0=$(count_log)
post admin_decide_approval "{\"user_id\":$ID1,\"decision\":\"accept\"}"
check_eq "karar: kabul edildi" "Başvuru kabul edildi." "$(jget "$TDIR/r.json" '$.message')"
N1=$(count_log)
[ $((N1 - N0)) -eq 1 ] && ok "kabulde YENİ push gönderildi (1 adet)" || bad "kabulde push sayı hatası (+$((N1-N0)))"
L1=$(last_log)
echo "$L1" | grep -q '"topic": "asra11112222333344445555aa"' && ok "push doğru kullanıcının konusuna gitti" || bad "yanlış konu: $L1"
echo "$L1" | grep -q '"title": "Kabul Edildin"' && ok "başlık: Kabul Edildin" || bad "başlık hatalı: $L1"
echo "$L1" | grep -q "kabul edildi" && ok "gövde düz metin (APK doğrudan gösterir)" || bad "gövde hatalı: $L1"
echo "$L1" | grep -q '"body": {"' && bad "gövde JSON (APK senderId skip'ine takılabilir)!" || ok "gövde JSON değil (senderId 0 kalır, self-skip yok)"

echo "== 2) Tekrar karar (already_decided) -> yeni push YOK =="
N2=$(count_log)
post admin_decide_approval "{\"user_id\":$ID1,\"decision\":\"accept\"}"
check_eq "tekrar karar algılandı" "Başvuru daha önce kabul edilmiş." "$(jget "$TDIR/r.json" '$.message')"
N3=$(count_log)
[ "$N3" = "$N2" ] && ok "tekrar kabulde push gönderilmedi" || bad "tekrar kabulde fazladan push (+$((N3-N2)))"

echo "== 3) RED bildirimi =="
ID2=$(mk_pending_user pushuser2)
sql "INSERT INTO user_push_topics (topic, user_id, updated_at) VALUES ('asra99998888777766665555bb', $ID2, UTC_TIMESTAMP())"
N4=$(count_log)
post admin_decide_approval "{\"user_id\":$ID2,\"decision\":\"reject\"}"
check_eq "karar: red" "Başvuru reddedildi ve hesap silindi." "$(jget "$TDIR/r.json" '$.message')"
N5=$(count_log)
[ $((N5 - N4)) -eq 1 ] && ok "reddede YENİ push gönderildi (1 adet)" || bad "reddede push sayı hatası (+$((N5-N4)))"
L2=$(last_log)
echo "$L2" | grep -q '"topic": "asra99998888777766665555bb"' && ok "push doğru kullanıcının konusuna gitti" || bad "yanlış konu: $L2"
echo "$L2" | grep -q '"title": "Başvuru Reddedildi"' && ok "başlık: Başvuru Reddedildi" || bad "başlık hatalı: $L2"
check_eq "hesap silindi" "0" "$(sql "SELECT COUNT(*) FROM users WHERE id=$ID2")"
check_eq "push konusu korundu (bildirim ulaşıyor)" "1" "$(sql "SELECT COUNT(*) FROM user_push_topics WHERE user_id=$ID2")"

echo "== 4) Konusu olmayan kullanıcı -> push yok, karar başarılı =="
ID3=$(mk_pending_user pushuser3)
N6=$(count_log)
post admin_decide_approval "{\"user_id\":$ID3,\"decision\":\"accept\"}"
N7=$(count_log)
[ "$N7" = "$N6" ] && ok "konusu yoksa push denemesi yok" || bad "beklenmeyen push (+$((N7-N6)))"
check_eq "karar yine başarılı" "approved" "$(sql "SELECT approval_status FROM users WHERE id=$ID3")"

echo "== 5) Duyuru push hâlâ çalışıyor (referans) =="
sql "INSERT INTO user_push_topics (topic, user_id, updated_at) VALUES ('asra55556666777788889999cc', $ID3, UTC_TIMESTAMP())"
N8=$(count_log)
post admin_send_announcement '{"room_ids":[1],"message":"E2E duyuru testi"}'
N9=$(count_log)
[ $((N9 - N8)) -ge 1 ] && ok "duyuru push'ı gitti (+$((N9-N8)), tüm kayıtlı konulara)" || bad "duyuru push'ı gitmedi"
# Duyurunun kabul edilen kullanıcıya ($ID3 konusu) ulaştığı doğrulanır
python3 - "$LOG" <<'EOF'
import json, sys
d = json.load(open(sys.argv[1]))
hit = [e for e in d if e.get('topic') == 'asra55556666777788889999cc' and 'DUYURU: E2E duyuru testi' in e.get('body', '')]
sys.exit(0 if hit else 1)
EOF
[ $? -eq 0 ] && ok "duyuru hedef kullanıcının konusuna ulaştı" || bad "duyuru hedef konuya ulaşmadı"

echo "== 6) NORMAL MESAJ -> ANINDA ntfy push (push mimarisi) =="
# pushuser3 (topic asra5555...) oda 1'e mesaj atar; pushuser1 (asra1111...) anında alır
rm -f "$TDIR/jar_p3.txt"
curl -s -A "E2E-Push-pushuser3/1.0" -c "$TDIR/jar_p3.txt" "$BASE?action=session" -o "$TDIR/r.json"
CSR3=$(jget "$TDIR/r.json" '$.csrf_token')
curl -s -A "E2E-Push-pushuser3/1.0" -b "$TDIR/jar_p3.txt" -c "$TDIR/jar_p3.txt" -H "X-CSRF-Token: $CSR3" -H "Content-Type: application/json" -d '{"username":"pushuser3","password":"Test123!xyz"}' "$BASE?action=login" -o "$TDIR/r.json"
check_eq "pushuser3 girişi" "True" "$(jget "$TDIR/r.json" '$.ok')"
N10=$(count_log)
curl -s -A "E2E-Push-pushuser3/1.0" -b "$TDIR/jar_p3.txt" -c "$TDIR/jar_p3.txt" -H "X-CSRF-Token: $CSR3" -H "Content-Type: application/json" -d '{"room_id":1,"message":"push-mim-test"}' "$BASE?action=send_message" -o "$TDIR/r.json"
check_eq "mesaj gönderildi" "True" "$(jget "$TDIR/r.json" '$.ok')"
sleep 1
N11=$(count_log)
[ $((N11 - N10)) -ge 1 ] && ok "mesajda ANINDA push gitti (+$((N11-N10)))" || bad "mesajda push gitmedi"
python3 - "$LOG" <<'EOF'
import json, sys
d = json.load(open(sys.argv[1]))
# alıcı (asra1111...) push'u almış olmalı; gönderici (asra5555...) almamalı
alc  = [e for e in d if e.get('topic') == 'asra11112222333344445555aa' and 'push-mim-test' in e.get('body', '')]
snd  = [e for e in d if e.get('topic') == 'asra55556666777788889999cc' and 'push-mim-test' in e.get('body', '')]
ok_json = False
if alc:
    try:
        p = json.loads(alc[0]['body'])
        ok_json = p.get('rid') == 1 and p.get('rn') == 'Asra Genel' and p.get('tx') == 'push-mim-test' and 'sid' in p
    except Exception:
        ok_json = False
sys.exit(0 if (alc and ok_json and not snd) else 1)
EOF
[ $? -eq 0 ] && ok "alıcı doğru payload'ı aldı {rn,rid,sid,tx}; gönderici kendine push almADI" || bad "push payload/hedef hatası"

echo
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" = "0" ] && echo "TÜM PUSH TESTLERİ GEÇTİ" || echo "BAŞARISIZ TEST VAR"
exit "$FAIL"
