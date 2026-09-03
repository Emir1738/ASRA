#!/usr/bin/env bash
# ASRA test ortamı kurulumu: PHP 8.4 + MariaDB + Chromium
set -uo pipefail
export DEBIAN_FRONTEND=noninteractive

echo "=== [1/4] apt update ==="
sudo apt-get update -qq 2>&1 | tail -2

echo "=== [2/4] PHP + gerekli uzantılar ==="
sudo apt-get install -y -qq --no-install-recommends \
  php-cli php-mysql php-curl php-mbstring php-xml php-zip php-sqlite3 2>&1 | tail -3

echo "=== [3/4] MariaDB sunucu + istemci ==="
sudo apt-get install -y -qq --no-install-recommends mariadb-server mariadb-client 2>&1 | tail -3

echo "=== [4/4] Chromium (puppeteer-core için) ==="
sudo apt-get install -y -qq --no-install-recommends chromium 2>&1 | tail -3

echo
echo "=== SONUÇ ==="
php -v 2>&1 | head -1
php -m | grep -iE "^(pdo_mysql|mysqli|curl|mbstring|openssl|json)$" | tr '\n' ' '; echo
mariadbd --version 2>&1 | head -1 || mysqld --version 2>&1 | head -1
which mariadb mysql chromium 2>&1
echo "TESTENV_OK"
