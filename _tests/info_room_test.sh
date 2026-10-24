#!/usr/bin/env bash
# ============================================================================
#  BİLGİ ODASI + ŞİKAYET SİSTEMİ — API test paketi (v34)
#
#  Ölçülenler:
#    1) Şema: 7 yeni tablo; katalog 21 oda; Bilgi Odası id=0 en üstte,
#       is_info=true; Bilgi Odası'na normal sohbet mesajı 422.
#    2) Yönetim: yönetici elle içerik ekler (kaynak/ders/çalışma/haber),
#       düzenler ve siler; yetkisiz ekleyemez; geçersiz bağlantı reddi.
#    3) RSS: 24 saatte bir otomatik tazeleme kuralları; "şimdi çek";
#       kaynak çökünce ESKİ haberler korunur (site düşmez), last_ok=false.
#    4) Formlar: her onaylı üye açar; kalp (beğeni) aç/kapa; altına yorum;
#       yalnız sahibi siler (yanıt+beğeniler kaskat gider).
#    5) Gömülü rehber içerik (builtin): boş bölüm ilk ziyarette dolar, dolu
#       bölüm tohumlanmaz, ikinci ziyarette çoğalmaz, panel içeriğiyle
#       harmanlanır, yönetici silince (işaret yoksa) yeniden dolar.
#    6) Yorum kalbi (info_form_reply_like) aç/kapa; oda beğenisi KALDIRILDI
#       (eski uçlar 404 ACTION_NOT_FOUND).
#    7) 24 saat saklama politikası: vadesi dolan form listelenmez; form/yorum
#       etkileşimleri 404 FORM_NOT_FOUND/REPLY_NOT_FOUND; CLI temizlik formu
#       yorum/beğenileriyle kaskat kaldırır.
#    8) Şikayet: sohbet/form/form yorumu; kendi içeriği red; kısa neden red;
#       olmayan veya 24 saati dolan hedef 404; ret kota tüketmez, mevcut
#       kanıt içerik temizlendikten sonra kalır; günde 5 kota (6. istek 429).
#    9) Yönetim: Şikayetler kuyruğu (eden/edilen/neden/mesaj/oda); Reddet
#       kalıcı siler; Yasakla (admin_ban_user) sonrası accused.banned görünür.
#
#  Kullanım: bash _tests/info_room_test.sh
#  Çıkış: FAIL sayısı (0 = hepsi geçti). Kendi sunucusunu ve yerel RSS
#  beslemesini kullanır; dış ağ gerektirmez.
# ============================================================================
set -uo pipefail
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$SDIR/.." && pwd)"
TDIR="$SDIR/run_info_room"
rm -rf "$TDIR"; mkdir -p "$TDIR/feed"
# v1 besleme içeriği (feed sunucusu başlamadan yazılır)
cat > "$TDIR/feed/feed.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Test Akışı</title>
<item><title>FIXTURE HABER BIR</title><link>https://orn.example/1</link><guid>fx-1</guid><pubDate>Mon, 01 Sep 2026 08:00:00 +0000</pubDate><description>İlk test haberi özeti.</description></item>
<item><title>FIXTURE HABER IKI</title><link>https://orn.example/2</link><guid>fx-2</guid><pubDate>Mon, 01 Sep 2026 09:00:00 +0000</pubDate><description>İkinci test haberi özeti.</description></item>
</channel></rss>
XML
PORT="${INFO_ROOM_PORT:-8104}"
FEED_PORT="${INFO_FEED_PORT:-8123}"
BASE="http://127.0.0.1:$PORT/auth.php"
FEED_URL="http://127.0.0.1:$FEED_PORT/feed.xml"
ADMIN_PW="${ADMIN_PASSWORD:-AsraAsraJshe+sbuwnduxmsgdnfusmg}"
PASS=0; FAIL=0
ok()  { PASS=$((PASS+1)); echo "  ✓ $1"; }
bad() { FAIL=$((FAIL+1)); echo "  ✗ $1"; }
check_eq() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 — beklenen: [$2] gelen: [$3]"; fi; }
sql() { sudo mysql --default-character-set=utf8mb4 -N asra_test -e "$1" 2>/dev/null; }
jget() { python3 -c "
import json,sys
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
jlen() { python3 -c "
import json,sys
try: v=json.load(open(sys.argv[1]))
except Exception: print(-1); sys.exit(0)
for k in sys.argv[2].strip('\$').strip('.').split('.'):
    if k=='' or not isinstance(v,dict): break
    v=v.get(k,'__MISSING__')
print(len(v) if isinstance(v,list) else (-1 if v=='__MISSING__' else v))" "$1" "$2"; }
tok() { python3 -c "
import json,sys
d=json.load(open(sys.argv[1]))
det=(d.get('error') or {}).get('details') or {}
t=d.get('csrf_token') or (det.get('csrf_token') if isinstance(det,dict) else '') or ''
print(t or sys.argv[2])" "$1" "$2" 2>/dev/null || echo "$2"; }
api_get() { curl -s -m 20 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -o "$4" -w '%{http_code}' "$BASE?action=$5"; }
api_post() { curl -s -m 20 -A "$2" -b "$1" -c "$1" -H "X-CSRF-Token: $3" -H 'Content-Type: application/json' -d "$5" -o "$6" -w '%{http_code}' "$BASE?action=$4"; }

echo "== 0) Ortam =="
bash "$SDIR/reset_env.sh" >/dev/null 2>&1
sql "SELECT 1" >/dev/null && ok "veritabanı hazır" || bad "veritabanı kurulamadı"
pkill -f "php -S 127.0.0.1:$PORT" >/dev/null 2>&1 || true
pkill -f "php -S 127.0.0.1:$FEED_PORT" >/dev/null 2>&1 || true
sleep 1
( cd "$TDIR/feed" && exec php -S "127.0.0.1:$FEED_PORT" >"$TDIR/feed.log" 2>&1 ) &
FEED_PID=$!
( cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 \
    ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' \
    ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' ASRA_HOT_READS_PER_MIN=600 \
    ASRA_INFO_FEED_URLS="$FEED_URL" \
    exec php -S "127.0.0.1:$PORT" "$SDIR/test_router.php" ) >"$TDIR/php.log" 2>&1 &
PHP_PID=$!
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -m 2 -o /dev/null -w '%{http_code}' "$BASE?action=session" || echo 000)
  [ "$CODE" = "200" ] && break; sleep 0.5
done
check_eq "uygulama sunucusu ayakta" "200" "$CODE"
CODE=$(curl -s -m 3 -o /dev/null -w '%{http_code}' "$FEED_URL" || echo 000)
check_eq "yerel RSS beslemesi ayakta" "200" "$CODE"

echo "== 1) Şema ve oda kataloğu =="
NEW_TABLES=$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='asra_test' AND TABLE_NAME IN ('site_settings','info_items','info_forms','info_form_replies','info_form_likes','info_form_reply_likes','complaints')")
check_eq "7 yeni tablo otomatik kuruldu" "7" "$NEW_TABLES"
check_eq "info_room_likes tablosu kurulmadı (kalp kaldırıldı)" "0" "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='asra_test' AND TABLE_NAME='info_room_likes'")"
check_eq "toplam oda 21" "21" "$(sql 'SELECT COUNT(*) FROM rooms')"
check_eq "Bilgi Odası id=0 kayıtlı" "1" "$(sql "SELECT COUNT(*) FROM rooms WHERE id=0 AND name='Bilgi Odası'")"

echo "== 2) Kullanıcılar, oda listesi ve Bilgi Odası erişim kuralları =="
mk_user() { # <ad> -> son csrf token (stdout)
  local N="$1"; local J="$TDIR/j_${N}.txt"; local UA="InfoTest/${N}/1.0"
  rm -f "$J"
  api_get "$J" "$UA" "" "$TDIR/s.json" session >/dev/null
  local T; T=$(tok "$TDIR/s.json" "")
  api_post "$J" "$UA" "$T" register "{\"username\":\"$N\",\"password\":\"Test123!xyz\",\"password_confirm\":\"Test123!xyz\"}" "$TDIR/reg_$N.json" >/dev/null
  sql "UPDATE users SET approval_status='approved' WHERE username='$N'"
  api_get "$J" "$UA" "" "$TDIR/s1.json" session >/dev/null
  T=$(tok "$TDIR/s1.json" "$T")
  api_post "$J" "$UA" "$T" login "{\"username\":\"$N\",\"password\":\"Test123!xyz\"}" "$TDIR/l_$N.json" >/dev/null
  api_get "$J" "$UA" "" "$TDIR/s2.json" session >/dev/null
  T=$(tok "$TDIR/s2.json" "$T")
  echo "$T"
}
mk_admin_jar() { # <jar> -> son csrf token
  local J="$1"; local UA="InfoTest/admin/1.0"
  rm -f "$J"
  api_get "$J" "$UA" "" "$TDIR/as.json" session >/dev/null
  local T; T=$(tok "$TDIR/as.json" "")
  api_post "$J" "$UA" "$T" login '{"username":"uiadmin","password":"Test123!xyz"}' "$TDIR/al.json" >/dev/null
  T=$(tok "$TDIR/al.json" "$T")
  api_post "$J" "$UA" "$T" admin_login "{\"password\":\"$ADMIN_PW\"}" "$TDIR/aa.json" >/dev/null
  T=$(tok "$TDIR/aa.json" "$T")
  echo "$T"
}
TA=$(mk_user "iroomsa")
TA2=$(mk_user "iroomsb")
JADM="$TDIR/jadm.txt"; TADM=$(mk_admin_jar "$JADM")

CODE=$(curl -s -A "anon/1.0" -o "$TDIR/anon.json" -w '%{http_code}' "$BASE?action=info_items")
check_eq "oturumsuz info_items 401" "401" "$CODE"
check_eq "hata AUTH_REQUIRED" "AUTH_REQUIRED" "$(jget "$TDIR/anon.json" '$.error.code')"

api_get "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" "$TDIR/rooms.json" get_rooms >/dev/null
check_eq "get_rooms 21 oda" "21" "$(jlen "$TDIR/rooms.json" '$.rooms')"
check_eq "ilk oda Bilgi Odası (id 0)" "0" "$(jget "$TDIR/rooms.json" '$.rooms.0.id')"
check_eq "ilk oda is_info=true" "True" "$(jget "$TDIR/rooms.json" '$.rooms.0.is_info')"
check_eq "ikinci oda is_info=false" "False" "$(jget "$TDIR/rooms.json" '$.rooms.1.is_info')"

CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" send_message '{"room_id":0,"message":"buraya mesaj yazilmaz"}' "$TDIR/send0.json")
check_eq "Bilgi Odası'na sohbet mesajı 422" "422" "$CODE"
check_eq "hata kodu INFO_ROOM_NO_CHAT" "INFO_ROOM_NO_CHAT" "$(jget "$TDIR/send0.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" send_message '{"room_id":1,"message":"bilgi odasi suite ilk mesaj"}' "$TDIR/send1.json")
check_eq "Asra Genel'e mesaj 201" "201" "$CODE"
MSG1=$(jget "$TDIR/send1.json" '$.message_id')

echo "== 3) Yönetim: elle içerik ekleme/düzenleme/silme =="
a_post() { # <jar> <csrf> <action> <json> <out>
  api_post "$1" "InfoTest/admin/1.0" "$2" "$3" "$4" "$5" >/dev/null
  jget "$5" '$.ok'
}
CODE=$(curl -s -m 20 -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" -c "$TDIR/j_iroomsa.txt" -H "X-CSRF-Token: $TA" -H 'Content-Type: application/json' \
  -d '{"section":"kaynak","title":"Yetkisiz","content":"x"}' "$BASE?action=admin_info_article_create" -o "$TDIR/na.json" -w '%{http_code}')
check_eq "yetkisiz içerik ekleme 403" "403" "$CODE"
check_eq "hata kodu ADMIN_REQUIRED" "ADMIN_REQUIRED" "$(jget "$TDIR/na.json" '$.error.code')"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=kaynak" -o "$TDIR/kaynak0.json"
check_eq "kaynak: boş bölüm ilk ziyarette dolar" "6" "$(jlen "$TDIR/kaynak0.json" '$.items')"
check_eq "kaynak0: hepsi rehber (builtin)" "True" "$(python3 -c "
import json
d=json.load(open('$TDIR/kaynak0.json'))['items']
print(len(d)==6 and all(i['source']=='builtin' for i in d))")"
check_eq "kaynak tohum işareti yazıldı" "1" "$(sql "SELECT COUNT(*) FROM site_settings WHERE setting_key='builtin_seed_kaynak' AND setting_value='1'")"
check_eq "kaynak ekleme" "True" "$(a_post "$JADM" "$TADM" admin_info_article_create '{"section":"kaynak","title":"Astrofoto Rehberi","content":"Uzun pozlama ipuçları.","link":"https://orn.example/rehber"}' "$TDIR/adm.json")"
check_eq "ders ekleme" "True" "$(a_post "$JADM" "$TADM" admin_info_article_create '{"section":"ders","title":"Gökyüzü Koordinatları","content":"Ders notu: sağ açıklık ve dik açıklık."}' "$TDIR/adm.json")"
check_eq "çalışma kağıdı ekleme" "True" "$(a_post "$JADM" "$TADM" admin_info_article_create '{"section":"calisma","title":"Ay Evreleri Çalışma Kağıdı","content":"Boşlukları doldurun."}' "$TDIR/adm.json")"
check_eq "elle haber ekleme" "True" "$(a_post "$JADM" "$TADM" admin_info_article_create '{"section":"haber","title":"Panel Haberi","content":"Elle eklenen haber."}' "$TDIR/adm.json")"
check_eq "geçersiz link reddi" "False" "$(a_post "$JADM" "$TADM" admin_info_article_create '{"section":"kaynak","title":"Kötü","content":"x","link":"ftp://x"}' "$TDIR/adm.json")"
CODE=$(curl -s -m 20 -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" -H "X-CSRF-Token: $TA" -o "$TDIR/kaynak.json" -w '%{http_code}' "$BASE?action=info_items&section=kaynak")
check_eq "kaynak listesi ok (200)" "200" "$CODE"
check_eq "kaynak listesinde 7 öğe (6 rehber + 1 panel)" "7" "$(jlen "$TDIR/kaynak.json" '$.items')"
check_eq "kaynak: rehber maddeleri listeleniyor" "True" "$(python3 -c "
import json
d=json.load(open('$TDIR/kaynak.json'))['items']
print(sum(1 for i in d if i['source']=='builtin')==6)")"
check_eq "kaynak: panel maddesi harmanlandı" "True" "$(python3 -c "
import json
print(any(i['title']=='Astrofoto Rehberi' and i['source']=='manual' for i in json.load(open('$TDIR/kaynak.json'))['items']))")"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=kaynak" -o "$TDIR/kaynak2.json"
check_eq "kaynak: ikinci ziyarette tohum çoğalmaz" "7" "$(jlen "$TDIR/kaynak2.json" '$.items')"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=calisma" -o "$TDIR/calisma.json"
check_eq "çalışma: dolu bölüm tohumlanmaz (yalnız panel)" "1" "$(jlen "$TDIR/calisma.json" '$.items')"
ITEM_ID=$(jget "$TDIR/calisma.json" '$.items.0.id')
check_eq "içerik düzenleme" "True" "$(a_post "$JADM" "$TADM" admin_info_article_update "{\"id\":$ITEM_ID,\"title\":\"Ay Evreleri Çalışma Kağıdı v2\"}" "$TDIR/adm.json")"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=calisma" -o "$TDIR/calisma2.json"
check_eq "düzenlenen başlık" "Ay Evreleri Çalışma Kağıdı v2" "$(jget "$TDIR/calisma2.json" '$.items.0.title')"
check_eq "içerik silme" "True" "$(a_post "$JADM" "$TADM" admin_info_article_delete "{\"id\":$ITEM_ID}" "$TDIR/adm.json")"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=calisma" -o "$TDIR/calisma3.json"
check_eq "çalışma: boşalınca rehberle dolar (5 builtin)" "True" "$(python3 -c "
import json
d=json.load(open('$TDIR/calisma3.json'))['items']
print(len(d)==5 and all(i['source']=='builtin' for i in d))")"
check_eq "tohum işaretleri yazıldı (kaynak+çalışma)" "2" "$(sql "SELECT COUNT(*) FROM site_settings WHERE setting_key LIKE 'builtin_seed_%' AND setting_value='1'")"

echo "== 4) RSS beslemesi: şimdi çek, güncelleme, kaynak hatasına dayanıklılık =="
check_eq "şimdi çek (yönetici)" "True" "$(a_post "$JADM" "$TADM" admin_feed_refresh '{}' "$TDIR/adm.json")"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=haber" -o "$TDIR/haber1.json"
check_eq "haberler: 2 akış + 1 panel = 3" "3" "$(jlen "$TDIR/haber1.json" '$.items')"
check_eq "akış haberi geldi" "True" "$(python3 -c "
import json
print(any(i['title']=='FIXTURE HABER BIR' for i in json.load(open('$TDIR/haber1.json'))['items']))")"
check_eq "akış kaynağı source=feed" "feed" "$(python3 -c "
import json
print([i['source'] for i in json.load(open('$TDIR/haber1.json'))['items'] if i['title']=='FIXTURE HABER BIR'][0])")"
check_eq "meta.last_ok=true" "True" "$(jget "$TDIR/haber1.json" '$.meta.last_ok')"
check_eq "meta.auto=true (UI yenileme notu verisi)" "True" "$(jget "$TDIR/haber1.json" '$.meta.auto')"
check_eq "meta.last_at dolu" "True" "$(python3 -c "
import json
print(bool(json.load(open('$TDIR/haber1.json'))['meta'].get('last_at')))")"
check_eq "meta.next_at dolu (24 saat sonrası)" "True" "$(python3 -c "
import json
print(bool(json.load(open('$TDIR/haber1.json'))['meta'].get('next_at')))")"
check_eq "besleme ayarlarını kaydet" "True" "$(a_post "$JADM" "$TADM" admin_feed_settings "{\"urls\":\"$FEED_URL\",\"auto\":true}" "$TDIR/adm.json")"
curl -s -A "InfoTest/admin/1.0" -b "$JADM" "$BASE?action=admin_feed_status" -o "$TDIR/fs.json"
check_eq "admin_feed_status.auto" "True" "$(jget "$TDIR/fs.json" '$.auto')"
check_eq "admin_feed_status tek kaynak" "1" "$(jlen "$TDIR/fs.json" '$.urls')"
cat > "$TDIR/feed/feed.xml" <<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>Test Akışı</title>
<item><title>FIXTURE HABER GUNCELLENDI</title><link>https://orn.example/1</link><guid>fx-1</guid><pubDate>Mon, 02 Sep 2026 08:00:00 +0000</pubDate><description>Güncellenmiş özet.</description></item>
</channel></rss>
XML
a_post "$JADM" "$TADM" admin_feed_refresh '{}' "$TDIR/adm.json" >/dev/null
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=haber" -o "$TDIR/haber2.json"
check_eq "akış güncellemesi uygulandı (aynı guid, yeni başlık)" "True" "$(python3 -c "
import json
print(any(i['title']=='FIXTURE HABER GUNCELLENDI' for i in json.load(open('$TDIR/haber2.json'))['items']))")"
check_eq "kaynağı ölü adrese çevir" "True" "$(a_post "$JADM" "$TADM" admin_feed_settings '{"urls":"http://127.0.0.1:9/feed.xml","auto":true}' "$TDIR/adm.json")"
a_post "$JADM" "$TADM" admin_feed_refresh '{}' "$TDIR/adm.json" >/dev/null
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_items&section=haber" -o "$TDIR/haber3.json"
check_eq "ölü kaynak sonrası meta.last_ok=false" "False" "$(jget "$TDIR/haber3.json" '$.meta.last_ok')"
check_eq "eski haberler korundu (site ayakta)" "True" "$(python3 -c "
import json
print(any(i['title']=='FIXTURE HABER GUNCELLENDI' for i in json.load(open('$TDIR/haber3.json'))['items']))")"
check_eq "beslemeyi geri yükle" "True" "$(a_post "$JADM" "$TADM" admin_feed_settings "{\"urls\":\"$FEED_URL\",\"auto\":true}" "$TDIR/adm.json")"

echo "== 5) Formlar: açma, kalp, yorum, silme =="
u_post() { # <jar> <csrf> <action> <json> <ua> <out>
  api_post "$1" "$5" "$2" "$3" "$4" "$6" >/dev/null
  jget "$6" '$.ok'
}
check_eq "A form açar" "True" "$(u_post "$TDIR/j_iroomsa.txt" "$TA" info_form_create '{"title":"Astrofoto paylaşım günü","body":"Cumartesi buluşalım mı?"}' "InfoTest/iroomsa/1.0" "$TDIR/r.json")"
FORM_ID=$(sql "SELECT id FROM info_forms WHERE title='Astrofoto paylaşım günü' LIMIT 1")
[ -n "$FORM_ID" ] && ok "form id alındı ($FORM_ID)" || bad "form id alınamadı"
check_eq "boş form reddi" "False" "$(u_post "$TDIR/j_iroomsa.txt" "$TA" info_form_create '{"title":"x","body":""}' "InfoTest/iroomsa/1.0" "$TDIR/r.json")"
curl -s -A "InfoTest/iroomsb/1.0" -b "$TDIR/j_iroomsb.txt" "$BASE?action=info_forms" -o "$TDIR/forms.json"
check_eq "form listesinde 1 form" "1" "$(jlen "$TDIR/forms.json" '$.forms')"
check_eq "yazar görünüyor" "iroomsa" "$(jget "$TDIR/forms.json" '$.forms.0.author')"
check_eq "B için can_delete=false" "False" "$(jget "$TDIR/forms.json" '$.forms.0.can_delete')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_like "{\"form_id\":$FORM_ID}" "$TDIR/fl.json")
check_eq "B kalp atar (200)" "200" "$CODE"
check_eq "B beğeni yanıtı liked=true" "True" "$(jget "$TDIR/fl.json" '$.liked')"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_forms" -o "$TDIR/forms2.json"
check_eq "beğeni sayısı 1" "1" "$(jget "$TDIR/forms2.json" '$.forms.0.likes')"
check_eq "A beğenmedi (kendi formu)" "False" "$(jget "$TDIR/forms2.json" '$.forms.0.liked')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" info_form_like "{\"form_id\":$FORM_ID}" "$TDIR/fl2.json")
check_eq "A kalp atar (200)" "200" "$CODE"
check_eq "A beğeni yanıtı liked=true" "True" "$(jget "$TDIR/fl2.json" '$.liked')"
check_eq "toplam beğeni 2" "2" "$(jget "$TDIR/fl2.json" '$.likes')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" info_form_like "{\"form_id\":$FORM_ID}" "$TDIR/fl3.json")
check_eq "A kalbi kaldırır (yanıt liked=false)" "False" "$(jget "$TDIR/fl3.json" '$.liked')"
check_eq "kalp kaldırınca beğeni 1" "1" "$(jget "$TDIR/fl3.json" '$.likes')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_reply "{\"form_id\":$FORM_ID,\"content\":\"Ben varım, saat kaçta?\"}" "$TDIR/fr.json")
check_eq "B yorum yazar (201)" "201" "$CODE"
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_form_replies&form_id=$FORM_ID" -o "$TDIR/repl.json"
check_eq "yorum listesinde 1" "1" "$(jlen "$TDIR/repl.json" '$.replies')"
check_eq "yorum içeriği" "Ben varım, saat kaçta?" "$(jget "$TDIR/repl.json" '$.replies.0.content')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_delete "{\"form_id\":$FORM_ID}" "$TDIR/fd.json")
check_eq "B (sahip değil) silemez 403" "403" "$CODE"
check_eq "hata kodu FORM_NOT_OWNER" "FORM_NOT_OWNER" "$(jget "$TDIR/fd.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" info_form_delete "{\"form_id\":$FORM_ID}" "$TDIR/fd2.json")
check_eq "A formu siler (200)" "200" "$CODE"
check_eq "yanıtlar kaskat silindi" "0" "$(sql "SELECT COUNT(*) FROM info_form_replies WHERE form_id=$FORM_ID")"
check_eq "beğeniler kaskat silindi" "0" "$(sql "SELECT COUNT(*) FROM info_form_likes WHERE form_id=$FORM_ID")"

echo "== 6) Yorum kalbi; oda beğenisi kaldırıldı; 24 saat saklama =="
CODE=$(curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" -o "$TDIR/rl.json" -w '%{http_code}' "$BASE?action=info_room_like_state")
check_eq "oda beğenisi ucu kaldırıldı (404)" "404" "$CODE"
check_eq "hata kodu ACTION_NOT_FOUND" "ACTION_NOT_FOUND" "$(jget "$TDIR/rl.json" '$.error.code')"
check_eq "A form açar (yorum kalbi testi)" "True" "$(u_post "$TDIR/j_iroomsa.txt" "$TA" info_form_create '{"title":"Yorum kalbi testi","body":"Kalp atalım mı?"}' "InfoTest/iroomsa/1.0" "$TDIR/r.json")"
FRM2=$(sql "SELECT id FROM info_forms WHERE title='Yorum kalbi testi' LIMIT 1")
[ -n "$FRM2" ] && ok "form id alındı ($FRM2)" || bad "form id alınamadı"
check_eq "B yorum yazar" "True" "$(u_post "$TDIR/j_iroomsb.txt" "$TA2" info_form_reply "{\"form_id\":$FRM2,\"content\":\"Ben de varım, kalp atalım!\"}" "InfoTest/iroomsb/1.0" "$TDIR/r.json")"
RPY2=$(sql "SELECT id FROM info_form_replies WHERE form_id=$FRM2 LIMIT 1")
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_form_replies&form_id=$FRM2" -o "$TDIR/repl2.json"
check_eq "yorum kalp sayısı başlangıç 0" "0" "$(jget "$TDIR/repl2.json" '$.replies.0.likes')"
check_eq "A için yorum liked=false" "False" "$(jget "$TDIR/repl2.json" '$.replies.0.liked')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" info_form_reply_like "{\"reply_id\":$RPY2}" "$TDIR/rl1.json")
check_eq "A yorum kalbi atar (200)" "200" "$CODE"
check_eq "A liked=true" "True" "$(jget "$TDIR/rl1.json" '$.liked')"
check_eq "yorum beğenisi 1" "1" "$(jget "$TDIR/rl1.json" '$.likes')"
curl -s -A "InfoTest/iroomsb/1.0" -b "$TDIR/j_iroomsb.txt" "$BASE?action=info_form_replies&form_id=$FRM2" -o "$TDIR/repl3.json"
check_eq "B gözünden likes=1, liked=false" "True" "$(python3 -c "
import json
r=json.load(open('$TDIR/repl3.json'))['replies'][0]
print(r['likes']==1 and r['liked'] is False)")"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_reply_like "{\"reply_id\":$RPY2}" "$TDIR/rl2.json")
check_eq "B de kalp atar (liked=true)" "True" "$(jget "$TDIR/rl2.json" '$.liked')"
check_eq "toplam yorum beğenisi 2" "2" "$(jget "$TDIR/rl2.json" '$.likes')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" info_form_reply_like "{\"reply_id\":$RPY2}" "$TDIR/rl3.json")
check_eq "A kalbi kaldırır (liked=false)" "False" "$(jget "$TDIR/rl3.json" '$.liked')"
check_eq "kaldırınca 1 kaldı" "1" "$(jget "$TDIR/rl3.json" '$.likes')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_reply_like '{"reply_id":999999999}' "$TDIR/rl4.json")
check_eq "olmayan yoruma kalp 404" "404" "$CODE"
check_eq "hata kodu REPLY_NOT_FOUND" "REPLY_NOT_FOUND" "$(jget "$TDIR/rl4.json" '$.error.code')"
check_eq "kalıcı yorum beğenisi 1" "1" "$(sql "SELECT COUNT(*) FROM info_form_reply_likes")"
# Yorum vadesi ebeveyn formunkinden bağımsız da doğrulanır (eski veride
# zaman damgası sapmaları olabilir); reddedilen şikayet kota tüketmez.
sql "UPDATE info_form_replies SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id=$RPY2" >/dev/null
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" complaint_submit "{\"target_type\":\"form_reply\",\"target_id\":$RPY2,\"reason\":\"Bu yorum artık görünmüyor ve şikayet edilememeli.\"}" "$TDIR/cr-expired.json")
check_eq "vadesi dolan yoruma şikayet 404 (form canlı)" "404" "$CODE"
check_eq "yorum şikayet hata kodu" "COMPLAINT_TARGET_MISSING" "$(jget "$TDIR/cr-expired.json" '$.error.code')"
sql "UPDATE info_form_replies SET created_at=UTC_TIMESTAMP() WHERE id=$RPY2" >/dev/null
sql "UPDATE info_forms SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id=$FRM2" >/dev/null
curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" "$BASE?action=info_forms" -o "$TDIR/forms3.json"
check_eq "vadesi dolan form listede YOK" "0" "$(jlen "$TDIR/forms3.json" '$.forms')"
CODE=$(curl -s -A "InfoTest/iroomsa/1.0" -b "$TDIR/j_iroomsa.txt" -o "$TDIR/repl4.json" -w '%{http_code}' "$BASE?action=info_form_replies&form_id=$FRM2")
check_eq "vadesi dolan form yorumları 404" "404" "$CODE"
check_eq "hata kodu FORM_NOT_FOUND" "FORM_NOT_FOUND" "$(jget "$TDIR/repl4.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" info_form_like "{\"form_id\":$FRM2}" "$TDIR/rl5.json")
check_eq "vadesi dolan forma kalp 404" "404" "$CODE"
check_eq "hata kodu FORM_NOT_FOUND (2)" "FORM_NOT_FOUND" "$(jget "$TDIR/rl5.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_reply "{\"form_id\":$FRM2,\"content\":\"Vadesi geçmiş forma yorum yazılmaz.\"}" "$TDIR/rl6.json")
check_eq "vadesi dolan forma yorum 404" "404" "$CODE"
check_eq "hata kodu FORM_NOT_FOUND (3)" "FORM_NOT_FOUND" "$(jget "$TDIR/rl6.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" info_form_reply_like "{\"reply_id\":$RPY2}" "$TDIR/rl7.json")
check_eq "vadesi dolan yoruma kalp 404" "404" "$CODE"
check_eq "hata kodu REPLY_NOT_FOUND (2)" "REPLY_NOT_FOUND" "$(jget "$TDIR/rl7.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" complaint_submit "{\"target_type\":\"form\",\"target_id\":$FRM2,\"reason\":\"Artık görünmeyen form şikayete açık olmamalı.\"}" "$TDIR/cf-expired.json")
check_eq "vadesi dolan forma şikayet 404" "404" "$CODE"
check_eq "form şikayet hata kodu" "COMPLAINT_TARGET_MISSING" "$(jget "$TDIR/cf-expired.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" complaint_submit "{\"target_type\":\"form_reply\",\"target_id\":$RPY2,\"reason\":\"Formu vadesi dolan yorum da şikayete kapalıdır.\"}" "$TDIR/cr-parent-expired.json")
check_eq "vadesi dolan ebeveyn formun yorumu şikayet 404" "404" "$CODE"
check_eq "ebeveyn şikayet hata kodu" "COMPLAINT_TARGET_MISSING" "$(jget "$TDIR/cr-parent-expired.json" '$.error.code')"
check_eq "başarısız şikayetler kota/DB yazmadı" "0" "$(sql 'SELECT COUNT(*) FROM complaints')"
CLIOUT=$(cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' php auth.php cleanup 2>&1)
check_eq "CLI temizlik formu sildi (silinen form: 1)" "True" "$(python3 -c "
import sys
print('silinen form: 1' in sys.argv[1])" "$CLIOUT")"
check_eq "form satırı gitti" "0" "$(sql "SELECT COUNT(*) FROM info_forms WHERE id=$FRM2")"
check_eq "yorumlar kaskat gitti" "0" "$(sql "SELECT COUNT(*) FROM info_form_replies WHERE form_id=$FRM2")"
check_eq "yorum beğenileri kaskat gitti" "0" "$(sql "SELECT COUNT(*) FROM info_form_reply_likes WHERE reply_id=$RPY2")"
check_eq "form beğenileri kaskat gitti" "0" "$(sql "SELECT COUNT(*) FROM info_form_likes WHERE form_id=$FRM2")"

echo "== 8) Şikayet: kurallar, hedef türleri, kota =="
# A 5 mesaj daha yazar (B şikayet etsin diye)
for i in 2 3 4 5 6; do
  api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" send_message "{\"room_id\":1,\"message\":\"rahatsız edici içerik örneği $i\"}" "$TDIR/m.json" >/dev/null
  eval "MSG$i=\$(jget \"\$TDIR/m.json\" '$.message_id')"
done
sql "UPDATE messages SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id=$MSG6" >/dev/null
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" complaint_submit "{\"target_type\":\"chat\",\"target_id\":$MSG6,\"reason\":\"Vadesi dolmuş sohbet mesajı görünmüyor.\"}" "$TDIR/cc-expired.json")
check_eq "vadesi dolan oda mesajına şikayet 404" "404" "$CODE"
check_eq "oda şikayet hata kodu" "COMPLAINT_TARGET_MISSING" "$(jget "$TDIR/cc-expired.json" '$.error.code')"
sql "UPDATE messages SET created_at=UTC_TIMESTAMP() WHERE id=$MSG6" >/dev/null
CODE=$(api_post "$TDIR/j_iroomsa.txt" "InfoTest/iroomsa/1.0" "$TA" complaint_submit "{\"target_type\":\"chat\",\"target_id\":$MSG1,\"reason\":\"Kendi mesajımı şikayet edememeli.\"}" "$TDIR/cs0.json")
check_eq "kendi mesajına şikayet 422" "422" "$CODE"
check_eq "hata kodu COMPLAINT_SELF" "COMPLAINT_SELF" "$(jget "$TDIR/cs0.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" complaint_submit "{\"target_type\":\"chat\",\"target_id\":$MSG1,\"reason\":\"kısa\"}" "$TDIR/cs1.json")
check_eq "kısa neden 422" "422" "$CODE"
check_eq "hata kodu COMPLAINT_REASON_SHORT" "COMPLAINT_REASON_SHORT" "$(jget "$TDIR/cs1.json" '$.error.code')"
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" complaint_submit '{"target_type":"chat","target_id":999999999,"reason":"Böyle bir mesaj yok ama yine de deneniyor."}' "$TDIR/cs2.json")
check_eq "olmayan hedef 404" "404" "$CODE"
check_eq "hata kodu COMPLAINT_TARGET_MISSING" "COMPLAINT_TARGET_MISSING" "$(jget "$TDIR/cs2.json" '$.error.code')"
for n in 1 2 3 4 5; do
  eval "MID=\$MSG$n"
  CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" complaint_submit "{\"target_type\":\"chat\",\"target_id\":$MID,\"reason\":\"Şikayet $n: bu mesaj topluluk kurallarını ihlal ediyor.\"}" "$TDIR/cc.json")
  check_eq "B şikayet $n -> 201" "201" "$CODE"
done
CODE=$(api_post "$TDIR/j_iroomsb.txt" "InfoTest/iroomsb/1.0" "$TA2" complaint_submit "{\"target_type\":\"chat\",\"target_id\":$MSG6,\"reason\":\"Altıncı şikayet hakkı olmamalı zaten.\"}" "$TDIR/cs6.json")
check_eq "6. şikayet 429" "429" "$CODE"
check_eq "hata kodu COMPLAINT_LIMIT" "COMPLAINT_LIMIT" "$(jget "$TDIR/cs6.json" '$.error.code')"
curl -s -A "InfoTest/iroomsb/1.0" -b "$TDIR/j_iroomsb.txt" "$BASE?action=complaint_quota" -o "$TDIR/cq.json"
check_eq "kota used=5" "5" "$(jget "$TDIR/cq.json" '$.used')"
check_eq "kota remaining=0" "0" "$(jget "$TDIR/cq.json" '$.remaining')"
# form + form yorumu şikayetleri (C üzerinden)
u_post "$TDIR/j_iroomsa.txt" "$TA" info_form_create '{"title":"Kural dışı form","body":"Sorunlu form gövdesi örneği."}' "InfoTest/iroomsa/1.0" "$TDIR/r.json" >/dev/null
FBAD=$(sql "SELECT id FROM info_forms WHERE title='Kural dışı form' LIMIT 1")
u_post "$TDIR/j_iroomsb.txt" "$TA2" info_form_reply "{\"form_id\":$FBAD,\"content\":\"Sorunlu yorum içeriği örneği.\"}" "InfoTest/iroomsb/1.0" "$TDIR/r.json" >/dev/null
RBAD=$(sql "SELECT id FROM info_form_replies WHERE form_id=$FBAD ORDER BY id DESC LIMIT 1")
TC=$(mk_user "iroomsd")
CODE=$(api_post "$TDIR/j_iroomsd.txt" "InfoTest/iroomsd/1.0" "$TC" complaint_submit "{\"target_type\":\"form\",\"target_id\":$FBAD,\"reason\":\"Bu form topluluk kurallarına aykırı içerik barındırıyor.\"}" "$TDIR/cf.json")
check_eq "form şikayeti 201" "201" "$CODE"
CODE=$(api_post "$TDIR/j_iroomsd.txt" "InfoTest/iroomsd/1.0" "$TC" complaint_submit "{\"target_type\":\"form_reply\",\"target_id\":$RBAD,\"reason\":\"Bu yorum saldırgan ve kural dışı görünüyor.\"}" "$TDIR/cfr.json")
check_eq "form yorumu şikayeti 201" "201" "$CODE"
# Süresi DOLMADAN alınan şikayetler moderasyon kanıtıdır: kaynak silinse bile
# gerekçe/alıntı ve kuyruk korunur; yeni şikayet ise vade sonrası kapalıdır.
sql "UPDATE info_forms SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 25 HOUR) WHERE id=$FBAD" >/dev/null
(cd "$ROOT/asra-site-fix" && ASRA_COOKIE_SECURE=0 ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4' ASRA_DB_USER='asra' ASRA_DB_PASSWORD='asra_test_pw' php auth.php cleanup >/dev/null 2>&1)
check_eq "vade temizliği kaynağı sildi" "0" "$(sql "SELECT COUNT(*) FROM info_forms WHERE id=$FBAD")"
check_eq "şikayet kanıtları korundu" "7" "$(sql 'SELECT COUNT(*) FROM complaints')"

echo "== 9) Yönetim: Şikayetler kuyruğu, Reddet, Yasakla =="
curl -s -A "InfoTest/admin/1.0" -b "$JADM" "$BASE?action=admin_complaints" -o "$TDIR/ac.json"
check_eq "kuyrukta 7 kayıt" "7" "$(jget "$TDIR/ac.json" '$.total')"
check_eq "ilk (en eski) edilen iroomsa" "iroomsa" "$(jget "$TDIR/ac.json" '$.complaints.0.accused.username')"
check_eq "ilk eden iroomsb" "iroomsb" "$(jget "$TDIR/ac.json" '$.complaints.0.reporter.username')"
check_eq "neden metni dolu" "True" "$(python3 -c "
import json
print(bool(json.load(open('$TDIR/ac.json'))['complaints'][0]['reason']))")"
check_eq "mesaj parçası (snippet) dolu" "True" "$(python3 -c "
import json
print(bool(json.load(open('$TDIR/ac.json'))['complaints'][0]['snippet']))")"
check_eq "oda adı dolu" "True" "$(python3 -c "
import json
print(bool(json.load(open('$TDIR/ac.json'))['complaints'][0]['room_name']))")"
check_eq "form şikayeti oda adı Bilgi Odası" "Bilgi Odası · Form" "$(python3 -c "
import json
d=json.load(open('$TDIR/ac.json'))
print([c['room_name'] for c in d['complaints'] if c['target_type']=='form'][0])")"
CID2=$(jget "$TDIR/ac.json" '$.complaints.1.id')
check_eq "Reddet" "True" "$(a_post "$JADM" "$TADM" admin_complaint_reject "{\"id\":$CID2}" "$TDIR/adm.json")"
check_eq "kuyruk 6'ya düştü" "6" "$(sql 'SELECT COUNT(*) FROM complaints')"
check_eq "reddedilen üye banlanmadı" "0" "$(sql "SELECT COUNT(*) FROM banned_users WHERE user_id=(SELECT id FROM users WHERE username='iroomsa')")"
TARGET_ID=$(sql "SELECT id FROM users WHERE username='iroomsa' LIMIT 1")
CODE=$(api_post "$JADM" "InfoTest/admin/1.0" "$TADM" admin_ban_user "{\"user_id\":$TARGET_ID,\"reason\":\"Şikayet sonrası yasak\",\"duration_unit\":\"minutes\",\"duration_value\":60,\"block_identity\":false}" "$TDIR/ban.json")
check_eq "Yasakla (admin_ban_user 200)" "200" "$CODE"
check_eq "ban kaydı yazıldı" "1" "$(sql "SELECT COUNT(*) FROM banned_users WHERE user_id=$TARGET_ID")"
CID1=$(jget "$TDIR/ac.json" '$.complaints.0.id')
check_eq "şikayeti kapat (Reddet)" "True" "$(a_post "$JADM" "$TADM" admin_complaint_reject "{\"id\":$CID1}" "$TDIR/adm.json")"
curl -s -A "InfoTest/admin/1.0" -b "$JADM" "$BASE?action=admin_complaints" -o "$TDIR/ac2.json"
check_eq "kuyruk 5" "5" "$(jget "$TDIR/ac2.json" '$.total')"
check_eq "banlı hedef accused.banned görünüyor" "True" "$(python3 -c "
import json
d=json.load(open('$TDIR/ac2.json'))
print(any(c['accused']['username']=='iroomsa' and c['accused']['banned'] for c in d['complaints']))")"
check_eq "temizlik: yasağı kaldır" "True" "$(a_post "$JADM" "$TADM" admin_unban_user "{\"user_id\":$TARGET_ID}" "$TDIR/adm.json")"

echo
echo "SONUÇ: GEÇTİ $PASS / BAŞARISIZ $FAIL"
kill $PHP_PID $FEED_PID 2>/dev/null
rm -rf "$TDIR"
[ "$FAIL" = "0" ] && echo "BİLGİ ODASI + ŞİKAYET SİSTEMİ ÇALIŞIYOR" || echo "SORUN VAR"
exit "$FAIL"
