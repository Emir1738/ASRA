#!/usr/bin/env bash
# ASRA dogrulama ortami: PHP 8.4 + MariaDB + Chromium (gercek test paketi icin)
set -uo pipefail
log(){ echo "[$(date +%H:%M:%S)] $*"; }
export DEBIAN_FRONTEND=noninteractive

log "=== apt update ==="
sudo apt-get update -qq 2>&1 | tail -2

log "=== PHP 8.4 (cli + curl + mbstring + mysql) ==="
sudo apt-get install -y -qq php-cli php-curl php-mbstring php-mysql php-xml >/dev/null 2>&1 \
  || sudo apt-get install -y -qq php-cli php-curl php-mbstring php-mysql >/dev/null 2>&1
php -v | head -1
php -m | tr '\n' ' ' | grep -o -E 'curl|mbstring|pdo_mysql|openssl|json' | sort -u | tr '\n' ' '; echo

log "=== MariaDB ==="
sudo apt-get install -y -qq mariadb-server mariadb-client >/dev/null 2>&1
sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld
if ! pgrep -x mariadbd >/dev/null 2>&1 && ! pgrep -x mysqld >/dev/null 2>&1; then
  sudo -u mysql /usr/bin/mariadbd-safe --skip-grant-tables=0 >/tmp/mariadb.log 2>&1 &
  sleep 12
fi
for i in $(seq 1 20); do sudo mysqladmin ping >/dev/null 2>&1 && break; sleep 2; done
sudo mysqladmin ping 2>&1 | head -1

log "=== Chromium (puppeteer-core ile) ==="
sudo apt-get install -y -qq chromium >/dev/null 2>&1
command -v chromium && chromium --version 2>/dev/null | head -1

log "=== npm bagimliliklari (puppeteer-core, jsdom) ==="
mkdir -p /home/user/.asra-tests && cd /home/user/.asra-tests
[ -f package.json ] || echo '{"name":"asra-tests","private":true}' > package.json
npm install --silent --no-audit --no-fund puppeteer-core jsdom >/dev/null 2>&1
ls node_modules 2>/dev/null | head -5
echo "puppeteer-core: $(node -e "console.log(require('/home/user/.asra-tests/node_modules/puppeteer-core/package.json').version)" 2>/dev/null || echo yok)"

log "=== Sonuc ==="
php -v | head -1
sudo mysql -N -e "SELECT VERSION()" 2>&1 | head -1
chromium --version 2>/dev/null | head -1
df -h /home/user | tail -1
echo "TESTENV_OK"
