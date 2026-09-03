#!/usr/bin/env bash
# ============================================================================
#  ASRA test paketi koşucusu
#  Kullanım: bash run-tests.sh <proje-kökü> [etiket] [test-adı-filtresi]
#
#  Bildirim/push sistemi kaldırıldığı için bu koşucuda artık FCM ortamı
#  (taşıyıcı belirteç ucu, gönderim ucu, test servis hesabı) YOKTUR.
#  Sunucu, üretimdekiyle birebir aynı yapılandırmayla ayağa kalkar.
# ============================================================================
set -uo pipefail
ROOT="${1:-/home/user/projects/ASRA}"
LABEL="${2:-orijinal}"
FILTER="${3:-}"
cd "$ROOT" || exit 1

export NODE_PATH=/home/user/.asra-tests/node_modules
export ASRA_DB_DSN='mysql:host=127.0.0.1;dbname=asra_test;charset=utf8mb4'
export ASRA_DB_USER='asra'
export ASRA_DB_PASSWORD='asra_test_pw'
export ASRA_COOKIE_SECURE=0
# e2e_test "hit koruması her zaman açık" doğrulaması 80 istekle tetiklenmeyi
# ölçer. Üretim varsayılanı 180/dk olduğu için test sunucusu bilinçli olarak
# 60/dk ile çalıştırılır; aksi hâlde o doğrulama anlamsızlaşır.
# (throttle_release_test kendi sunucusunu 20/dk ile ayrıca başlatır.)
export ASRA_HOT_READS_PER_MIN=60

# --- ortam kendini onarır -------------------------------------------------
# Bu çalışma ortamı zaman zaman php/chromium/npm paketlerini siliyor.
# Koşu ortasında "php: command not found" yerine baştan kurulur.
if ! command -v php >/dev/null 2>&1 || ! command -v chromium >/dev/null 2>&1; then
  echo "ortam eksik (php/chromium) -> install-testenv.sh çalıştırılıyor"
  sudo -n bash /home/user/.setup/install-testenv.sh >/tmp/install-testenv.log 2>&1
fi
if ! pgrep -x mariadbd >/dev/null 2>&1; then
  echo "mariadb çalışmıyor -> elle başlatılıyor"
  sudo -n mkdir -p /run/mysqld && sudo -n chown mysql:mysql /run/mysqld
  (sudo -n /usr/sbin/mariadbd --user=mysql >/tmp/mariadb.log 2>&1 &)
  for i in $(seq 1 30); do sudo -n mysql -N -e "SELECT 1" >/dev/null 2>&1 && break; sleep 1; done
fi
if [ ! -d /home/user/.asra-tests/node_modules/puppeteer-core ]; then
  echo "node modülleri eksik -> npm install"
  mkdir -p /home/user/.asra-tests
  ( cd /home/user/.asra-tests && npm install puppeteer-core@24 jsdom --no-audit --no-fund >/tmp/npm-asra-tests.log 2>&1 )
fi
# APK derin denetimi (aapt2/dexdump) araç zinciri gerektirir.
if [ ! -x /home/user/.asra-build/android-sdk/build-tools/34.0.0/aapt2 ]; then
  echo "Android build-tools eksik -> install-toolchain.sh çalıştırılıyor"
  bash /home/user/.setup/install-toolchain.sh >/tmp/install-toolchain.log 2>&1
fi
echo "php=$(command -v php)  chromium=$(command -v chromium)  node_modul=$([ -d /home/user/.asra-tests/node_modules/puppeteer-core ] && echo var || echo yok)"

echo "############ TEST KOŞUSU: $LABEL ############"
echo "kök : $ROOT"
echo "zmn : $(date +%H:%M:%S)"

pkill -f "php -S 127.0.0.1:8090" >/dev/null 2>&1
sleep 1

bash _tests/reset_env.sh

# PHP_CLI_SERVER_WORKERS: yerleşik sunucu normalde TEK iş parçacıklıdır.
# Tarayıcı testleri Chromium'dan eşzamanlı istek attığı için çok işçili mod
# gerekir; bu aynı zamanda üretimdeki eşzamanlılık modelini de taklit eder.
( cd "$ROOT/asra-site-fix" && PHP_CLI_SERVER_WORKERS=8 exec php -S 127.0.0.1:8090 "$ROOT/_tests/test_router.php" ) >/tmp/php-$LABEL.log 2>&1 &
PHP_PID=$!
CODE=000
for i in $(seq 1 40); do
  CODE=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:8090/auth.php?action=session" || echo 000)
  [ "$CODE" = "200" ] && break
  sleep 0.5
done
echo "php sunucu pid=$PHP_PID  /auth.php?action=session -> HTTP $CODE"
if [ "$CODE" != "200" ]; then echo "SUNUCU HAZIR DEĞİL"; kill $PHP_PID 2>/dev/null; exit 9; fi
echo

TOTAL_PASS=0; TOTAL_FAIL=0; FAILED_LIST=""
run() {
  local name="$1"; shift
  if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then return 0; fi
  local logf="/tmp/out-$LABEL-$name.log"
  echo "===== [$LABEL] $name ====="
  "$@" >"$logf" 2>&1
  local rc=$?
  local p f
  # İki ayrı çıktı biçimi vardır:
  #   bash suite'leri  -> "GEÇTİ: n" / "BAŞARISIZ: n"
  #   node suite'leri  -> "SONUÇ: ✓n ✗n"
  # İşaretleri dosyanın HER yerinden saymak yanlış olur (özet satırının
  # kendisi de ✓/✗ içerir), bu yüzden yalnızca özet bloğuna bakılır.
  local last
  last=$(grep -A1 'SONUÇ' "$logf" | tail -2 | tr '\n' ' ' || true)
  # Node biçimini ayırt eden işaret "✓n ✗m" ÇİFTİDİR; bash özetindeki tek
  # başına "✓5" bu kalıba uymaz, o yüzden çift aranır (yanlış eşleşme önlemi).
  if printf '%s' "$last" | grep -qE '✓[0-9]+ *✗[0-9]+'; then
    p=$(printf '%s' "$last" | sed -E 's/.*✓([0-9]+).*/\1/')
    f=$(printf '%s' "$last" | sed -E 's/.*✗([0-9]+).*/\1/')
  else
    p=$(printf '%s' "$last" | grep -oE 'GEÇTİ:? *[0-9]+' | grep -oE '[0-9]+' | tail -1)
    f=$(printf '%s' "$last" | grep -oE 'BAŞARISIZ:? *[0-9]+' | grep -oE '[0-9]+' | tail -1)
  fi
  # Özet satırı hiç yoksa (csp_hash_check gibi) dosyadaki işaretler sayılır.
  # NOT: grep -c eşleşme yoksa da "0" basar; çıkış kodu 1 olduğu için
  # "|| echo 0" YAZILMAMALIDIR (iki satır üretir, aritmetik bozulur).
  if [ -z "$p" ]; then p=$(grep -c '✓' "$logf" 2>/dev/null); fi
  if [ -z "$f" ]; then f=$(grep -c '✗' "$logf" 2>/dev/null); fi
  [ -z "$p" ] && p=0
  [ -z "$f" ] && f=0
  TOTAL_PASS=$((TOTAL_PASS+p)); TOTAL_FAIL=$((TOTAL_FAIL+f))
  if [ $rc -eq 0 ]; then echo "SONUÇ: GEÇTİ (exit=0)  ✓$p ✗$f"; else echo "SONUÇ: KALDI (exit=$rc)  ✓$p ✗$f"; FAILED_LIST="$FAILED_LIST $name"; fi
  tail -26 "$logf"
  echo
}

run "csp_hash_check"     bash _tests/csp_hash_check.sh "$ROOT/asra-site-fix/index.html"
run "no_notification"    bash _tests/no_notification_test.sh
run "fresh_db_test"      bash _tests/fresh_db_test.sh
run "throttle_release"   bash _tests/throttle_release_test.sh
run "legacy_schema"      bash _tests/legacy_schema_test.sh
run "e2e_test"           bash _tests/e2e_test.sh
run "message_delete"     bash _tests/message_delete_test.sh
run "csrf_heal_test"     bash _tests/csrf_heal_test.sh
run "cooldown_test"      node _tests/cooldown_test.js
run "ui_fix_browser"     bash _tests/ui_fix_browser_test.sh

kill $PHP_PID 2>/dev/null
echo "############ $LABEL ÖZET: ✓$TOTAL_PASS  ✗$TOTAL_FAIL ############"
[ -n "$FAILED_LIST" ] && echo "KALAN SUİTLER:$FAILED_LIST"
exit $TOTAL_FAIL
