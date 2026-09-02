#!/usr/bin/env bash
# ============================================================================
#  ASRA test ortamı izolasyonu (her E2E script'inin başında çağrılır)
#
#  KÖK NEDEN: Önceki çalışmalardan kalan veritabanı durumu (kullanıcılar,
#  register rate sayaçları) ve PHP oturum dosyaları sonraki
#  çalışmayı kirletiyordu:
#   - aynı kullanıcı adları  -> USERNAME_TAKEN
#   - 1 saatlik register     -> bütçe taşması (429)
#   - eski şema işaretleri   -> yeni DB göçetmez (1146 tablo yok)
#
#  Bu script ortamı SIFIRDAN kurar; testler birbirinden bağımsız çalışır.
# ============================================================================
SDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Şema kısa devre işaret dosyaları silinmezse auth.php yeni kurulan veritabanını
# göçetmez (24 saatlik kısa devre) -> tablo yok hataları.
rm -f /tmp/asra_schema_v16.flag \
      /tmp/asra_schema_identity_v3.flag /tmp/asra_schema_platform_v1.flag

sudo mysql -e "DROP DATABASE IF EXISTS asra_test" > /dev/null 2>&1
sudo mysql < "$SDIR/test_schema.sql" > /dev/null 2>&1

# Oturum dosyaları: önceki çalışmalardan gelen oturumlar geçersiz kılınır.
find /tmp -maxdepth 1 -name 'sess_*' -delete 2>/dev/null

# Referans kullanıcı (tarayıcı testleri buna giriş yapar).
UIHASH=$(php -r "echo password_hash('Test123!xyz', PASSWORD_DEFAULT);")
sudo mysql -N asra_test -e "INSERT IGNORE INTO users (username, password_hash, avatar_color, approval_status, online_status, last_seen, created_at)
     VALUES ('uiadmin','$UIHASH','c1','approved',0,UTC_TIMESTAMP(),UTC_TIMESTAMP())" > /dev/null 2>&1

sudo mysql -N asra_test -e "SELECT 1" > /dev/null 2>&1
