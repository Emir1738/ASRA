#!/usr/bin/env bash
# ============================================================================
#  ONAY BEKLEYENLERİN GÖRÜNÜRLÜĞÜ test paketi
#
#  Düzeltilen kök neden: admin_pending_approvals sorgusu
#  "AND approval_submitted_at IS NOT NULL" koşulu taşıyordu; katılma nedenini
#  henüz yazmamış bekleyen hesaplar (yeni kayıt, eski istemci/APK, geçmişten
#  kalan satırlar) moderatör panelinde HİÇ görünmüyordu.
#
#  Ölçülenler:
#    1) API: bekleyen HER hesap listede; gönderilmişler önde; submitted bayrağı
#       ve null gönderim zamanı dürüstçe dönüyor.
#    2) API: admin olmayan oturum uca erişemez (403 ADMIN_REQUIRED).
#    3) TARAYICI: gerçek admin panelinde nedeni yazılmamış hesap kendi notu ve
#       kayıt tarihiyle görünür; gönderilmiş başvuru öndedir.
#    4) API: kabul/red kararları nedeni yazılmamış hesaplar için de çalışır ve
#       karar sonrası satır listeden düşer.
#
#  Kullanım: bash _tests/approval_visibility_test.sh
#  Çıkış: FAIL sayısı (0 = hepsi geçti).
# ============================================================================
set -u
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run_approval"
rm -rf "$TDIR"; mkdir -p "$TDIR"
PORT="${PORT:-8102}"
BASE="http://127.0.0.1:$PORT/auth.php"
ADMIN_PW="${ADMIN_PASSWORD:-AsraAsraJshe+sbuwnduxmsgdnfusmg}"
PEND_A="apprA"   # nedeni yazilmis bekleyen
PEND_D="apprD"   # nedeni HIC yazilmamis bekleyen

PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql -N asra_test -e "$1" 2>/dev/null; }
jget() { python3 -c "import json,sys
try: d=json.load(open(sys.argv[1]))
except Exception: print('__PARSE_FAIL__'); sys.exit(0)
v=d
for k in sys.argv[2].strip('\$').strip('.').split('.'):
    if k=='': continue
    if isinstance(v,list):
        try: v=v[int(k)]
        except Exception: v='__MISSING__'; break
    elif isinstance(v,dict) and k in v: v=v[k]
    else: v='__MISSING__'; break
print(v if not isinstance(v,(dict,list)) else json.dumps(v,ensure_ascii=False))" "$1" "$2"; }
tok() { python3 -c "import json,sys
d=json.load(open(sys.argv[1]))
det=(d.get('error') or {}).get('details') or {}
t=d.get('csrf_token') or (det.get('csrf_token') if isinstance(det,dict) else '') or ''
print(t or sys.argv[2])" "$1" "$2" 2>/dev/null || echo "$2"; }
api_get() { curl -s -m 15 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -o "$4" -w '%{http_code}' "$BASE?action=$5"; }
api_post() { curl -s -m 15 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -H 'Content-Type: application/json' -d "$5" -o "$6" -w '%{http_code}' "$BASE?action=$4"; }
jbody() { python3 -c "import json,sys;print(json.dumps(dict(p.split('=',1) for p in sys.argv[1:])))" "$@"; }

echo "== 0) Ortam =="
bash "$SDIR/reset_env.sh" >/dev/null 2>&1
sql "SELECT 1" >/dev/null 2>&1 && ok "veritabanı hazır" || bad "veritabanı kurulamadı"
pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true; sleep 1
( cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 PHP_CLI_SERVER_WORKERS=4 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_HOT_READS_PER_MIN=600 \
    php -S "127.0.0.1:$PORT" "$SDIR/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -m 2 -o /dev/null -w '%{http_code}' "$BASE?action=session" || echo 000)
  [ "$CODE" = "200" ] && break; sleep 0.5
done
check_eq "sunucu ayakta" "200" "$CODE"

echo "== 1) Bekleyen hesaplar kurulur =="
register_pending() { # <ad> <jar>
  local N=$1 J=$2; rm -f "$J"
  api_get "$J" "appr-$N/1.0" "" "$TDIR/s.json" session >/dev/null
  local T; T=$(tok "$TDIR/s.json" "")
  api_post "$J" "appr-$N/1.0" "$T" register "$(jbody username="$N" password='Test123!xyz' password_confirm='Test123!xyz')" "$TDIR/reg_$N.json" >/dev/null
  jget "$TDIR/reg_$N.json" '$.ok'
}
check_eq "$PEND_A kaydı (pending)" "True" "$(register_pending "$PEND_A" "$TDIR/jA.txt")"
JA_T=$(tok "$TDIR/jA.txt" ""); JA_T=$(api_get "$TDIR/jA.txt" "appr-$PEND_A/1.0" "" "$TDIR/sA.json" session >/dev/null; tok "$TDIR/sA.json" "")
api_post "$TDIR/jA.txt" "appr-$PEND_A/1.0" "$JA_T" submit_approval "$(jbody reason='Astrofotoğraf odalarında öğrenmek ve paylaşmak istiyorum.')" "$TDIR/sub.json" >/dev/null
check_eq "$PEND_A nedeni gönderdi" "True" "$(jget "$TDIR/sub.json" '$.ok')"
check_eq "$PEND_D kaydı (pending, neden YOK)" "True" "$(register_pending "$PEND_D" "$TDIR/jD.txt")"
check_eq "veritabanında $PEND_D submitted_at NULL" "NULL" "$(sql "SELECT IFNULL(approval_submitted_at,'NULL') FROM users WHERE username='$PEND_D'")"

echo "== 2) API: bekleyen HER hesap listede =="
JADM="$TDIR/jadm.txt"; rm -f "$JADM"
api_get "$JADM" "appr-admin/1.0" "" "$TDIR/as.json" session >/dev/null
AT=$(tok "$TDIR/as.json" "")
api_post "$JADM" "appr-admin/1.0" "$AT" login "$(jbody username=uiadmin password='Test123!xyz')" "$TDIR/al.json" >/dev/null
AT=$(tok "$TDIR/al.json" "$AT")
api_post "$JADM" "appr-admin/1.0" "$AT" admin_login "$(jbody password="$ADMIN_PW")" "$TDIR/aad.json" >/dev/null
check_eq "admin_login" "True" "$(jget "$TDIR/aad.json" '$.ok')"
AT=$(tok "$TDIR/aad.json" "$AT")
api_get "$JADM" "appr-admin/1.0" "$AT" "$TDIR/p1.json" admin_pending_approvals >/dev/null
check_eq "liste iki bekleyeni de döner" "2" "$(python3 -c "import json;print(len(json.load(open('$TDIR/p1.json'))['pending']))")"
check_eq "ilk sıra gönderilmiş başvuru" "$PEND_A" "$(jget "$TDIR/p1.json" '$.pending.0.username')"
check_eq "ikinci sıra nedeni yazılmamış" "$PEND_D" "$(jget "$TDIR/p1.json" '$.pending.1.username')"
check_eq "gönderilmiş bayrağı True" "True" "$(jget "$TDIR/p1.json" '$.pending.0.submitted')"
check_eq "yazılmamış bayrağı False" "False" "$(jget "$TDIR/p1.json" '$.pending.1.submitted')"
check_eq "yazılmamışta submitted_at null" "None" "$(jget "$TDIR/p1.json" '$.pending.1.submitted_at')"
check_eq "yazılmamışta created_at dolu" "True" "$(python3 -c "import json;print(bool(json.load(open('$TDIR/p1.json'))['pending'][1]['created_at']))")"
check_eq "yanıt includes_unsubmitted=True (yeni sorgu)" "True" "$(jget "$TDIR/p1.json" '$.includes_unsubmitted')"
check_eq "yanıt total=2" "2" "$(jget "$TDIR/p1.json" '$.total')"
check_eq "yanıt build=v34" "v34" "$(jget "$TDIR/p1.json" '$.build')"
curl -s -m 5 "$BASE?action=health" -o "$TDIR/h.json"
check_eq "health ucu sunucu sürümünü bildirir (v34)" "v34" "$(jget "$TDIR/h.json" '$.build')"

echo "== 3) API: admin olmayan erişemez =="
# uiadmin oturumu admin dogrulamasi YAPMADIGI icin 403 beklenir.
JNO="$TDIR/jno.txt"; rm -f "$JNO"
api_get "$JNO" "appr-noadmin/1.0" "" "$TDIR/ns.json" session >/dev/null
NT=$(tok "$TDIR/ns.json" "")
api_post "$JNO" "appr-noadmin/1.0" "$NT" login "$(jbody username=uiadmin password='Test123!xyz')" "$TDIR/nl.json" >/dev/null
NT=$(tok "$TDIR/nl.json" "$NT")
CODE=$(api_get "$JNO" "appr-noadmin/1.0" "$NT" "$TDIR/np.json" admin_pending_approvals)
check_eq "admin doğrulamasız oturum 403 alır" "403" "$CODE"
check_eq "403 kodu ADMIN_REQUIRED" "ADMIN_REQUIRED" "$(jget "$TDIR/np.json" '$.error.code')"

echo "== 4) Tarayıcı: gerçek admin panelinde görünürlük =="
export NODE_PATH=/home/user/.asra-tests/node_modules
set +e
ORIGIN="http://127.0.0.1:$PORT" TEST_USER="uiadmin" TEST_PASSWORD="Test123!xyz" \
  ADMIN_PASSWORD="$ADMIN_PW" PENDING_SUBMITTED="$PEND_A" PENDING_PLAIN="$PEND_D" \
  node "$SDIR/approval_visibility_browser_test.js" "$TDIR" 2>&1 | tail -70
NODE_EXIT=${PIPESTATUS[0]}
set +e
if [ ! -f "$TDIR/browser_counters.txt" ]; then bad "tarayıcı testi sonuç üretmedi (node exit=$NODE_EXIT)"; fi
while read -r k v; do
  case "$k" in
    PASS) for i in $(seq 1 "${v:-0}"); do ok "admin paneli görünürlük doğrulaması"; done ;;
    FAIL) for i in $(seq 1 "${v:-0}"); do bad "admin paneli görünürlük doğrulaması"; done ;;
  esac
done < <(cat "$TDIR/browser_counters.txt" 2>/dev/null || true)

echo "== 5) API: kararlar nedeni yazılmamış hesapta da çalışır =="
DID=$(sql "SELECT id FROM users WHERE username='$PEND_D'")
AID=$(sql "SELECT id FROM users WHERE username='$PEND_A'")
api_post "$JADM" "appr-admin/1.0" "$AT" admin_decide_approval "$(jbody user_id="$AID" decision=accept)" "$TDIR/d1.json" >/dev/null
check_eq "$PEND_A kabul edildi" "True" "$(jget "$TDIR/d1.json" '$.ok')"
check_eq "$PEND_A durumu approved" "approved" "$(sql "SELECT approval_status FROM users WHERE username='$PEND_A'")"
api_post "$JADM" "appr-admin/1.0" "$AT" admin_decide_approval "$(jbody user_id="$DID" decision=reject)" "$TDIR/d2.json" >/dev/null
check_eq "$PEND_D reddedildi" "True" "$(jget "$TDIR/d2.json" '$.ok')"
api_get "$JADM" "appr-admin/1.0" "$AT" "$TDIR/p2.json" admin_pending_approvals >/dev/null
check_eq "karar sonrası liste boş" "0" "$(python3 -c "import json;print(len(json.load(open('$TDIR/p2.json'))['pending']))")"

kill $PHP_PID 2>/dev/null
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" -eq 0 ] && echo "TÜM TESTLER GEÇTİ" || { echo "--- php.log ---"; tail -14 "$TDIR/php.log"; }
rm -rf "$TDIR"
exit "$FAIL"
