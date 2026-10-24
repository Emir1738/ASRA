#!/usr/bin/env bash
# ============================================================================
#  ASRA Mesaj Silme E2E — BASH/CLI
#
#  Doğrulananlar:
#   - delete_message geri-alma belirteci döner, satır hemen GİZLENİR
#   - DİĞER kullanıcı da artık görmez (deleted_at filtresi her okuma yolunda)
#   - süre dolmadan finalize reddedilir (DELETE_COOLDOWN_ACTIVE)
#   - süre dolunca finalize satırı KALICI siler
#   - pencere içinde undo satırı geri getirir (her iki kullanıcıda da görünür)
#   - başkasının mesajı silinemez (403)
#   - silinmiş mesaja tekrar delete_message 409 verir
#
#  NOT: Bu suite daha önce YOKTU; silme akışı hiç otomatik test edilmiyordu.
# ============================================================================
set -u
BASE="http://127.0.0.1:8090/auth.php"
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run_del"
rm -rf "$TDIR"; mkdir -p "$TDIR"

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

echo "== 0) Ortam sıfırdan kurulur =="
bash "$SDIR/reset_env.sh" >/dev/null
sql "SELECT 1" >/dev/null && ok "veritabanı hazır" || bad "veritabanı kurulamadı"

# --- kullanıcı hazırla: adminuser (yetkili) + deluser1 (gönderen) + deluser2 (alıcı)
mk_user() { # ad -> id
  local name="$1" jar="$TDIR/jar_$1.txt" ua="E2E-Del-$1/1.0"
  rm -f "$jar"
  curl -s -A "$ua" -c "$jar" "$BASE?action=session" -o "$TDIR/r.json"
  local ccsrf; ccsrf=$(jget "$TDIR/r.json" '$.csrf_token')
  curl -s -A "$ua" -b "$jar" -c "$jar" -H "X-CSRF-Token: $ccsrf" -H "Content-Type: application/json" \
    -d "{\"username\":\"$name\",\"password\":\"Test123!xyz\",\"password_confirm\":\"Test123!xyz\"}" \
    "$BASE?action=register" -o "$TDIR/r.json"
  sql "UPDATE users SET approval_status='approved' WHERE username='$name'"
  curl -s -A "$ua" -b "$jar" -c "$jar" -H "X-CSRF-Token: $ccsrf" -H "Content-Type: application/json" \
    -d "{\"username\":\"$name\",\"password\":\"Test123!xyz\"}" "$BASE?action=login" -o "$TDIR/r.json"
  sql "SELECT id FROM users WHERE username='$name'"
}

echo "== 1) Kullanıcılar =="
ID1=$(mk_user deluser1); ID2=$(mk_user deluser2)
check_eq "deluser1 giriş" "True" "$(jget "$TDIR/r.json" '$.ok')"
if [ -z "$ID1" ] || [ -z "$ID2" ]; then
  bad "kullanıcı oluşturulamadı (ID1='$ID1' ID2='$ID2')"
  echo "================ SONUÇ ================"; echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"; exit "$FAIL"
fi
ok "iki kullanıcı hazır ($ID1, $ID2)"

# CSRF'ler her profil için ayrı
CSRF1=$(curl -s -A "E2E-Del-deluser1/1.0" -b "$TDIR/jar_deluser1.txt" -c "$TDIR/jar_deluser1.txt" "$BASE?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin).get('csrf_token',''))")
CSRF2=$(curl -s -A "E2E-Del-deluser2/1.0" -b "$TDIR/jar_deluser2.txt" -c "$TDIR/jar_deluser2.txt" "$BASE?action=session" | python3 -c "import json,sys;print(json.load(sys.stdin).get('csrf_token',''))")
post1() { curl -s -A "E2E-Del-deluser1/1.0" -b "$TDIR/jar_deluser1.txt" -c "$TDIR/jar_deluser1.txt" -H "X-CSRF-Token: $CSRF1" -H "Content-Type: application/json" -d "$2" "$BASE?action=$1" -o "$TDIR/r1.json"; }
post2() { curl -s -A "E2E-Del-deluser2/1.0" -b "$TDIR/jar_deluser2.txt" -c "$TDIR/jar_deluser2.txt" -H "X-CSRF-Token: $CSRF2" -H "Content-Type: application/json" -d "$2" "$BASE?action=$1" -o "$TDIR/r2.json"; }
get1() { curl -s -A "E2E-Del-deluser1/1.0" -b "$TDIR/jar_deluser1.txt" -c "$TDIR/jar_deluser1.txt" "$BASE?action=$1" -o "$TDIR/g1.json"; }
get2() { curl -s -A "E2E-Del-deluser2/1.0" -b "$TDIR/jar_deluser2.txt" -c "$TDIR/jar_deluser2.txt" "$BASE?action=$1" -o "$TDIR/g2.json"; }

# Belirli bir metnin get_messages içinde görünüp görünmediği
visible_to() { # dosya metin -> 0/1
  python3 - "$1" "$2" <<'PY' >/dev/null 2>&1
import json, sys
d = json.load(open(sys.argv[1]))
msgs = d.get('messages') or []
sys.exit(0 if any((m.get('content') or '') == sys.argv[2] for m in msgs) else 1)
PY
}

echo "== 2) Mesajlar gönderilir =="
post1 send_message '{"room_id":1,"message":"SILINECEK-A"}'
check_eq "A gönderildi" "True" "$(jget "$TDIR/r1.json" '$.ok')"
MIDA=$(jget "$TDIR/r1.json" '$.message_id')
post1 send_message '{"room_id":1,"message":"SILINECEK-B"}'
check_eq "B gönderildi" "True" "$(jget "$TDIR/r1.json" '$.ok')"
MIDB=$(jget "$TDIR/r1.json" '$.message_id')
post1 send_message '{"room_id":1,"message":"KALACAK-C"}'
MIDC=$(jget "$TDIR/r1.json" '$.message_id')
if [ "$MIDA" = "__MISSING__" ] || [ "$MIDB" = "__MISSING__" ] || [ "$MIDC" = "__MISSING__" ]; then
  bad "mesaj kimliği alınamadı (A=$MIDA B=$MIDB C=$MIDC) — send_message yanıtı beklenenden farklı"
  echo "================ SONUÇ ================"; echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"; exit "$FAIL"
fi
ok "mesaj kimlikleri alındı ($MIDA, $MIDB, $MIDC)"

get1 "get_messages&room_id=1"
get2 "get_messages&room_id=1"
visible_to "$TDIR/g1.json" "SILINECEK-A" && ok "gönderen A'yı görüyor" || bad "gönderen A'yı görmüyor"
visible_to "$TDIR/g2.json" "SILINECEK-A" && ok "DİĞER kullanıcı A'yı görüyor" || bad "diğer kullanıcı A'yı görmüyor"

echo "== 3) delete_message: geri-alma penceresi açılır =="
post1 delete_message "{\"message_id\":$MIDA}"
check_eq "silme başlatıldı" "True" "$(jget "$TDIR/r1.json" '$.ok')"
UNDO=$(jget "$TDIR/r1.json" '$.undo_token')
UNTIL=$(jget "$TDIR/r1.json" '$.undo_until')
[ -n "$UNDO" ] && [ "$UNDO" != "__MISSING__" ] && ok "undo_token döndü" || bad "undo_token yok"
[ -n "$UNTIL" ] && [ "$UNTIL" != "__MISSING__" ] && ok "undo_until döndü ($UNTIL)" || bad "undo_until yok"

echo "== 4) KRİTİK: satır her iki kullanıcıda da anında gizlenir =="
get1 "get_messages&room_id=1"
get2 "get_messages&room_id=1"
visible_to "$TDIR/g1.json" "SILINECEK-A" && bad "gönderen hâlâ A'yı görüyor" || ok "gönderen artık A'yı GÖRMÜYOR"
visible_to "$TDIR/g2.json" "SILINECEK-A" && bad "DİĞER kullanıcı hâlâ A'yı görüyor" || ok "diğer kullanıcı artık A'yı GÖRMÜYOR"
visible_to "$TDIR/g2.json" "KALACAK-C" && ok "silinmeyen mesaj duruyor (yan etki yok)" || bad "silinmeyen mesaj da kayboldu"

echo "== 5) Süre dolmadan kalıcı silme reddedilir =="
post1 finalize_message_delete "{\"message_id\":$MIDA}"
check_eq "DELETE_COOLDOWN_ACTIVE" "DELETE_COOLDOWN_ACTIVE" "$(jget "$TDIR/r1.json" '$.error.code')"

echo "== 6) Başkasının mesajı silinemez =="
post2 delete_message "{\"message_id\":$MIDB}"
check_eq "NOT_MESSAGE_OWNER (403)" "NOT_MESSAGE_OWNER" "$(jget "$TDIR/r2.json" '$.error.code')"

echo "== 7) Aynı mesaj için ikinci silme isteği 409 =="
post1 delete_message "{\"message_id\":$MIDA}"
check_eq "DELETE_ALREADY_PENDING" "DELETE_ALREADY_PENDING" "$(jget "$TDIR/r1.json" '$.error.code')"

echo "== 8) Geri alma (undo) satırı geri getirir =="
post1 undo_delete_message "{\"message_id\":$MIDA,\"undo_token\":\"$UNDO\"}"
check_eq "undo başarılı" "True" "$(jget "$TDIR/r1.json" '$.ok')"
get1 "get_messages&room_id=1"
get2 "get_messages&room_id=1"
visible_to "$TDIR/g1.json" "SILINECEK-A" && ok "gönderen A'yı tekrar görüyor" || bad "undo sonrası gönderen göremiyor"
visible_to "$TDIR/g2.json" "SILINECEK-A" && ok "diğer kullanıcı A'yı tekrar görüyor" || bad "undo sonrası diğer kullanıcı göremiyor"
check_eq "undo_token tek kullanımlık" "UNDO_EXPIRED" "$(post1 undo_delete_message "{\"message_id\":$MIDA,\"undo_token\":\"$UNDO\"}"; jget "$TDIR/r1.json" '$.error.code')"

echo "== 9) Süre dolunca KALICI silinir =="
post1 delete_message "{\"message_id\":$MIDB}"
check_eq "B için silme başlatıldı" "True" "$(jget "$TDIR/r1.json" '$.ok')"
# Geri-alma penceresi 4 sn; sonuna kadar beklenir
for i in $(seq 1 30); do
  REM=$(sql "SELECT COUNT(*) FROM messages WHERE id=$MIDB AND deleted_at IS NOT NULL AND deleted_at <= UTC_TIMESTAMP()")
  [ "$REM" = "1" ] && break
  sleep 0.5
done
check_eq "pencere doldu (silme vadesi geldi)" "1" "$REM"
post1 finalize_message_delete "{\"message_id\":$MIDB}"
check_eq "finalize başarılı" "True" "$(jget "$TDIR/r1.json" '$.ok')"
check_eq "satır veritabanından SİLİNDİ" "0" "$(sql "SELECT COUNT(*) FROM messages WHERE id=$MIDB")"
get2 "get_messages&room_id=1"
visible_to "$TDIR/g2.json" "SILINECEK-B" && bad "kalıcı silinen mesaj hâlâ görünüyor" || ok "kalıcı silinen mesaj listede yok"

echo "== 10) Silinen mesaja tekrar silme 404 =="
post1 delete_message "{\"message_id\":$MIDB}"
check_eq "MESSAGE_NOT_FOUND" "MESSAGE_NOT_FOUND" "$(jget "$TDIR/r1.json" '$.error.code')"

echo "== 11) Vadesi geçen silmeler bakım göreviyle temizlenir =="
post1 delete_message "{\"message_id\":$MIDC}"
check_eq "C için silme başlatıldı" "True" "$(jget "$TDIR/r1.json" '$.ok')"
sleep 5
# İstemci finalize çağırmadı (sekme kapandı senaryosu): bakım yolu temizlemeli
( cd "$ROOT/asra-site-fix" && \
  ASRA_DB_DSN='mysql:host=127.0.0.1;port=3306;dbname=asra_test;charset=utf8mb4' \
  ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' php auth.php cleanup >/dev/null 2>&1 )
get1 "get_messages&room_id=1"
visible_to "$TDIR/g1.json" "KALACAK-C" && bad "vadesi geçen silme temizlenmedi" || ok "istemci finalize etmese de mesaj listede yok"

echo
echo "================ SONUÇ ================"
echo "GEÇTİ: $PASS   BAŞARISIZ: $FAIL"
[ "$FAIL" = "0" ] && echo "TÜM TESTLER GEÇTİ" || echo "BAŞARISIZ TEST VAR"
exit "$FAIL"
