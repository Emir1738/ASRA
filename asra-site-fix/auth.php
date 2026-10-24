<?php
declare(strict_types=1);

/*
 * JSON Güvencesi ve Performans İyileştirmeleri (2026-08-20)
 * - Tüm çıktılar JSON olacak, fatal hatalar bile JSON döner
 * - Oturum kilidi okuma isteklerinde erken bırakılır (paralel istekler hızlanır)
 * - Bildirim/push sistemi kaldırıldı (2026-08-29): sunucu artık hiçbir bildirim üretmez
 * - Onay bekleyenler yönetim ekranından gizlendi
 */
ob_start();
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// Fatal hataları JSON'a çevir
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        while (ob_get_level() > 0) { @ob_end_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        }
        $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
        error_log(sprintf('[fatal][%s] %s in %s:%d', $ref, $error['message'], $error['file'], $error['line']));
        echo json_encode(['ok'=>false,'error'=>['code'=>'INTERNAL_ERROR','message'=>'İşlem tamamlanamadı. Lütfen tekrar deneyin.','details'=>['reference'=>$ref]]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    }
});
set_error_handler(function (int $severity, string $message, string $file, int $line) {
    if (!(error_reporting() & $severity)) return false;
    // Uyarı ve notice'lar exception olmasın - özellikle session ve dosya uyarıları JSON'u bozmasın
    if (in_array($severity, [E_WARNING, E_NOTICE, E_DEPRECATED, E_USER_WARNING, E_USER_NOTICE, E_USER_DEPRECATED], true)) {
        // Logla ama akışı bozma
        error_log(sprintf('[php-warning][%s] %s in %s:%d', $severity, $message, $file, $line));
        return true; // işlendi say, HTML basma
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});


// Şema sürüm anahtarı: yükseltildiğinde her veritabanı kurulumu bir kez
// yeniden çalışır (idempotenttir) ve yeni tablo/tohumlar eklenir.
const SCHEMA_VERSION_KEY = 'schema:runtime:v21';
const DEFAULT_ADMIN_PASSWORD = 'AsraAsraJshe+sbuwnduxmsgdnfusmg';
// Sunucu sürüm işareti: /auth.php?action=health yanıtında döner. Yükleme
// sonrasında DOĞRU dosyanın gerçekten sunucuda olduğunu buradan doğrulanır;
// istemci de bekleyenler listesinin yeni sorguyu kullanıp kullanmadığını
// bundan bağımsız olarak includes_unsubmitted alanıyla anlar.
const ASRA_BUILD = 'v34';
// Bilgi Odası (içerik odası) sabit oda kimliği: oda listesinde id sırasına
// göre EN ÜSTTE durur; normal mesajlaşma kabul etmez. İçerik (haber/kaynak/
// ders/çalışma kağıdı/form) ayrı tablolarda yaşar.
const INFO_ROOM_ID = 0;
// Bir kullanıcının günde gönderebileceği toplam şikayet (UTC gün).
const COMPLAINT_DAILY_LIMIT = 5;
// Haber akışı tazeleme aralığı: 24 saatte bir.
const INFO_FEED_REFRESH_SECONDS = 86400;
const INFO_FEED_HTTP_TIMEOUT = 10;
// Varsayılan güvenilir haber kaynakları (yönetici panelinden değiştirilebilir;
// ortam değişkeni ASRA_INFO_FEED_URLS ile ezilebilir — testler yerel beslemeyi
// bu yolla bağlar). Satır veya virgülle ayrılır.
const DEFAULT_INFO_FEED_URLS = "https://www.aa.com.tr/tr/rss/default?cat=guncel\nhttps://www.nasa.gov/rss/dyn/breaking_news.rss";
const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
const MESSAGE_RETENTION_HOURS = 24;
const ONLINE_WINDOW_SECONDS = 120;
const DEVICE_COOKIE_NAME = 'ASRA_DEVICE';
const DEVICE_COOKIE_LIFETIME = 157680000;
// Kayıtlı kullanıcıların hesabı kolay kolay silinmesin:
// inaktiflik penceresi 12 ay. 5 ay iken kullanıcılar kısa aralıklarla "kayıptı".
const INACTIVE_ACCOUNT_MONTHS = 12;
const INACTIVE_ACCOUNT_REASON = '12 ay boyunca aktif olmadığın için hesabın silindi.';
const REMEMBER_COOKIE_NAME = 'ASRA_REMEMBER';
const REMEMBER_LIFETIME = 15552000;
const APP_DEVICE_HEADER = 'HTTP_X_ASRA_DEVICE_ID';
const APP_DEVICE_COOKIE = 'ASRA_APP_DEVICE';
const SESSION_ROTATION_INTERVAL = 1800;
const SESSION_ROTATION_GRACE = 45;
// Hit koruması: platformda HER ZAMAN aktiftir (kod/anahtar ile hiçbir ilişkisi yok).
// Sunucu okuma bütçesi sürekli düşük tavana tutulur; ücretsiz host "hits"
// kotası daima korunur. İstemci tarafı yoklama ritmi de kalıcı olarak yavaştır.
// Hesap GENELİNİ kapsayan okuma tavanı. Bu bir emniyet sübabıdır, günlük
// kota değildir: boşta kullanıcı 1 istek/dk harcadığı için normal kullanımda
// asla devreye girmez. Eski değer (60/dk) birkaç eşzamanlı kullanıcıda bile
// aşılıp herkesi kilitliyordu.
const HIT_PROTECTION_MAX_REQUESTS = 600;
const HIT_PROTECTION_COOLDOWN_SECONDS = 30;
// Oturum başına sınır: tek bir bozuk/otomatikleşmiş istemciyi durdurur.
// Asıl kötüye kullanım koruması budur; genel tavan yalnızca emniyet sübabıdır.
const HIT_PROTECTION_SESSION_MAX_REQUESTS = 90;

final class ApiError extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }
}

function envValue(string $name, string $default = ''): string
{
    $value = getenv($name);
    return $value === false ? $default : $value;
}

/**
 * Şema kurulum damgası — veritabanının İÇİNDE tutulur.
 *
 * Neden dosya değil: sys_get_temp_dir() içindeki bir işaret dosyası 24 saat
 * geçerlidir ve veritabanının yeniden kurulduğunu (hesap taşıma, SQL içe
 * aktarma, test sıfırlaması) BİLEMEZ. Sıcak bir işaret dosyası, tabloları
 * silinmiş bir veritabanında şema kurulumunu ATLATIR ve site 500 üretir.
 * Damga veritabanında tutulduğunda veritabanıyla birlikte yok olur; kurulum
 * gerektiğinde mutlaka yeniden çalışır. Maliyet: birincil anahtar üzerinden
 * tek satırlık bir SELECT.
 */
function schemaStampApplied(PDO $pdo, string $key): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM rate_limits WHERE rate_key = ? LIMIT 1');
    $stmt->execute([$key]);
    return (bool) $stmt->fetchColumn();
}

function markSchemaStamp(PDO $pdo, string $key): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
         VALUES (?, 0, UTC_TIMESTAMP(), NULL)
         ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([$key]);
}

/**
 * Yoğunlukta geçici olabilecek veritabanı hataları: bağlantı doluluğu, kilit
 * bekleme aşımı ve ölü kilit. Bunlar kalıcı arıza değildir; kısa bir bekleme
 * sonrası yeniden denenir ve kullanıcıya 500 yerine "yoğunluk" bilgisi verilir.
 */
function isTransientDbError(Throwable $error): bool
{
    $transientSqlStates = ['40001', 'HY000', '08S01', '08004', '08001', '08006'];
    $transientDriverCodes = [1040, 1042, 1047, 1158, 1159, 1160, 1161, 1205, 1213, 2002, 2003, 2006, 2013];
    $needles = [
        'deadlock', 'lock wait timeout', 'too many connections', 'gone away',
        'connection refused', 'connection reset', 'connection timed out',
        'max_user_connections', 'max connections', 'server has gone',
        'try restarting transaction', 'lost connection', 'no connection to the server',
        'temporary failure', 'resource temporarily unavailable',
    ];

    for ($current = $error; $current !== null; $current = $current->getPrevious()) {
        $code = strtoupper((string) $current->getCode());
        $driverCode = null;
        if ($current instanceof PDOException && is_array($current->errorInfo ?? null)) {
            $driverCode = isset($current->errorInfo[1]) ? (int) $current->errorInfo[1] : null;
        }
        $message = strtolower($current->getMessage());
        foreach ($needles as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }
        if (in_array($driverCode, $transientDriverCodes, true)) {
            return true;
        }
        if ($code === '40001' || str_starts_with($code, '08')) {
            return true;
        }
        if (in_array($code, $transientSqlStates, true) && $driverCode !== null && in_array($driverCode, $transientDriverCodes, true)) {
            return true;
        }
    }
    return false;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    // GitHub kaynak kopyası: üretim bilgisi yok. Dağıtımdan önce
    // ASRA_DB_DSN / ASRA_DB_USER / ASRA_DB_PASSWORD ortamda tanımlanmalı.
    $dsn = envValue(
        'ASRA_DB_DSN',
        ''
    );
    $dbUser = envValue('ASRA_DB_USER', '');
    $dbPassword = envValue('ASRA_DB_PASSWORD', '');
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_TIMEOUT => 6,
    ];

    $lastError = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $pdo = new PDO($dsn, $dbUser, $dbPassword, $options);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
            ensureRuntimeSchema($pdo);
            return $pdo;
        } catch (PDOException $error) {
            $pdo = null;
            $lastError = $error;
            if ($attempt === 3 || !isTransientDbError($error)) {
                break;
            }
            usleep($attempt * 150000);
        }
    }

    error_log('[db] connection failed: ' . ($lastError ? $lastError->getMessage() : 'bilinmeyen hata'));
    throw new ApiError(503, 'SERVICE_BUSY', 'Sunucu şu anda yoğun. Birkaç saniye sonra otomatik olarak yeniden denenecek.', [
        'retry_after' => 3,
    ]);
}

/**
 * Ölü kilit veya kilit bekleme aşımına takılan işlemleri kısa aralıklarla
 * yeniden dener. Yalnızca güvenli (geri alınmış) işlemler tekrar çalıştırılır.
 */
function runTransaction(PDO $pdo, callable $work, int $attempts = 3): mixed
{
    for ($attempt = 1; ; $attempt++) {
        $pdo->beginTransaction();
        try {
            $result = $work($pdo);
            $pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($attempt >= $attempts || !($error instanceof PDOException) || !isTransientDbError($error)) {
                throw $error;
            }
            usleep($attempt * 120000);
        }
    }
}

function secureCookies(): bool
{
    return envValue('ASRA_COOKIE_SECURE', '1') !== '0';
}

/**
 * Bakım görevlerini (mesaj temizliği, süresi dolan kayıtlar) her istekte değil,
 * yalnızca verilen aralıkta bir kez çalıştırır. Kilit veritabanı üzerinden
 * atomik kurulur; aynı anda gelen istekler işi tekrarlamaz.
 */
function shouldRunMaintenance(PDO $pdo, string $task, int $intervalSeconds): bool
{
    static $handled = [];
    if (isset($handled[$task])) {
        return false;
    }
    $handled[$task] = true;
    // Bakım denetimi her istekte değil, örnekleme ile yapılır: normal trafikte
    // fazladan sorgu üretmez, yoğunlukta da işi zamanında tetikler.
    if (random_int(1, 5) !== 1) {
        return false;
    }
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
             VALUES (?, 0, UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE
                window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP(), window_started_at)"
        );
        $stmt->execute(['maintenance:' . $task, $intervalSeconds]);
        return $stmt->rowCount() > 0;
    } catch (PDOException $error) {
        error_log('[maintenance] ' . $task . ' throttle skipped: ' . $error->getMessage());
        return false;
    }
}

function runMaintenanceSafely(PDO $pdo, string $task, int $intervalSeconds, callable $work): void
{
    if (!shouldRunMaintenance($pdo, $task, $intervalSeconds)) {
        return;
    }
    try {
        $work($pdo);
    } catch (Throwable $error) {
        // Bakım işi kullanıcı isteğini bozmaz. Bir sonraki uygun istekte tekrar denenir.
        error_log('[maintenance] ' . $task . ' failed: ' . $error->getMessage());
    }
}

function safeSchemaExec(PDO $pdo, string $sql, string $label): void
{
    try {
        $pdo->exec($sql);
    } catch (PDOException $error) {
        error_log('[schema] ' . $label . ' skipped: ' . $error->getMessage());
    }
}

/**
 * Çekirdek şema + oda kataloğu: veritabanı BOŞ olsa bile uygulama ayağa kalkar.
 *
 * Neden gerekli: daha önce users/rooms/messages gibi temel tablolar yalnızca
 * dışarıdan yüklenen SQL ile oluşuyordu; ensureRuntimeSchema ise rate_limits
 * ve users tablolarının VAR OLDUĞUNU varsayıyordu. Boş veya kısmen yüklenmiş
 * bir veritabanında ilk istek 500 dönüyordu. Artık şema kendini kurar.
 *
 * Güvenlik: tüm ifadeler CREATE TABLE IF NOT EXISTS / INSERT IGNORE olduğu
 * için tekrar tekrar çalışmak zararsızdır; mevcut veri ve kullanıcı
 * düzenlemeleri asla ezilmez.
 */
/**
 * Eski şemadan kalan tabloya eksik bir sütunu güvenle ekler.
 *
 * Neden gerekli: kullanıcı veritabanını eski bir dökümden içe aktardığında
 * tablolar VAR olur ama yeni sürümün beklediği sütunlar eksik kalır.
 * CREATE TABLE IF NOT EXISTS mevcut tabloya dokunmadığı için eksik sütun
 * kalıcı hataya yol açar (ör. rooms.position). Bu yardımcı sütun yoksa
 * ekler, varsa dokunmaz.
 */
function ensureColumn(PDO $pdo, string $table, string $column, string $ddl): void
{
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $check->execute([$table, $column]);
    if ((int) $check->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $ddl);
        } catch (PDOException $error) {
            // 42S21: sütun eşzamanlı olarak eklendi — zararsız.
            if ((string) $error->getCode() !== '42S21') {
                throw $error;
            }
        }
    }
}

function ensureCoreSchema(PDO $pdo): void
{
    $statements = [
        'CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  avatar_color VARCHAR(16) NOT NULL DEFAULT \'c1\',
  profile_image_path VARCHAR(255) NULL,
  theme VARCHAR(16) NOT NULL DEFAULT \'dark\',
  language VARCHAR(8) NOT NULL DEFAULT \'tr\',
  show_read_receipts TINYINT(1) NOT NULL DEFAULT 1,
  show_last_seen TINYINT(1) NOT NULL DEFAULT 1,
  about TEXT NULL,
  birthday DATE NULL,
  aa_coins DECIMAL(12,1) NOT NULL DEFAULT 0.0,
  active_profile_frame VARCHAR(40) NULL,
  total_active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
  last_activity_counted_at DATETIME NULL,
  invite_reset_notice_at DATETIME NULL,
  coin_debt_due_at DATETIME NULL,
  coin_debt_notice_at DATETIME NULL,
  approval_status ENUM(\'approved\',\'pending\') NOT NULL DEFAULT \'approved\',
  approval_reason VARCHAR(1000) NULL,
  approval_submitted_at DATETIME NULL,
  approval_decision_notice ENUM(\'accepted\') NULL,
  approval_decision_at DATETIME NULL,
  invite_code VARCHAR(32) NULL,
  invite_code_updated_at DATETIME NULL,
  invite_code_consumed_at DATETIME NULL,
  invite_code_consumed_by VARCHAR(32) NULL,
  bind_token_hash CHAR(64) NULL,
  bind_fingerprint_hash CHAR(64) NULL,
  bind_app_hash CHAR(64) NULL,
  bind_ip VARCHAR(45) NULL,
  last_seen DATETIME NULL,
  online_status TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS rooms (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcements_admin (admin_id, created_at),
  CONSTRAINT fk_announcements_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id SMALLINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  announcement_id BIGINT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT \'text\',
  content TEXT NULL,
  image_path VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_messages_room_id (room_id, id),
  KEY idx_messages_created (created_at),
  KEY idx_messages_user_created (user_id, created_at),
  KEY idx_messages_announcement (announcement_id),
  CONSTRAINT fk_messages_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_announcement FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS message_views (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, user_id),
  KEY idx_message_views_message (message_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS message_deletions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  deleted_for_user TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_deletions (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS banned_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  banned_until DATETIME NULL,
  banned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_banned_user (user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS deleted_users_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  username VARCHAR(30) NOT NULL,
  reason VARCHAR(500) NULL,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_deleted_username (username)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS site_settings (
  setting_key VARCHAR(64) NOT NULL,
  setting_value TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS info_items (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  section ENUM(\'haber\',\'kaynak\',\'ders\',\'calisma\') NOT NULL DEFAULT \'haber\',
  source ENUM(\'manual\',\'feed\',\'builtin\') NOT NULL DEFAULT \'manual\',
  feed_key VARCHAR(64) NULL,
  title VARCHAR(300) NOT NULL,
  content MEDIUMTEXT NULL,
  link VARCHAR(700) NULL,
  author VARCHAR(64) NULL,
  published_at DATETIME NULL,
  updated_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_info_feed_key (feed_key),
  KEY idx_info_section_pub (section, published_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS info_forms (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  author_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(120) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_info_forms_created (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS info_form_replies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id BIGINT UNSIGNED NOT NULL,
  author_id BIGINT UNSIGNED NOT NULL,
  content VARCHAR(2000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ifr_form (form_id, id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS info_form_likes (
  form_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (form_id, user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS info_form_reply_likes (
  reply_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (reply_id, user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS complaints (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  target_type ENUM(\'chat\',\'form\',\'form_reply\') NOT NULL,
  target_id BIGINT UNSIGNED NOT NULL,
  target_author_id BIGINT UNSIGNED NULL,
  target_username VARCHAR(30) NULL,
  reporter_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(1000) NOT NULL,
  snippet VARCHAR(300) NOT NULL DEFAULT \'\',
  room_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_complaints_created (id),
  KEY idx_complaints_reporter_day (reporter_id, created_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        'CREATE TABLE IF NOT EXISTS rate_limits (
  rate_key VARCHAR(191) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NULL,
  blocked_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_key),
  KEY idx_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'
    ];
    foreach ($statements as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $error) {
            // 42S01: tablo zaten var (eşzamanlı ilk istek yarışı) — zararsız.
            if ((string) $error->getCode() !== '42S01') {
                throw $error;
            }
        }
    }

    // Eski dökümlerde rooms tablosu position sütunu OLMADAN gelir ve
    // CREATE TABLE IF NOT EXISTS mevcut tabloya dokunmaz. Tohum position'a
    // yazdığı için eksik sütun her istekte kalıcı 500/503 üretirdi
    // ("Bağlantı geçici olarak yavaşladı" döngüsünün gerçek nedeni).
    ensureColumn($pdo, 'rooms', 'position', 'position SMALLINT UNSIGNED NOT NULL DEFAULT 0');

    // Oda kataloğu: TEK doğruluk kaynağı canonicalRoomCatalog(). Dışarıdan
    // yüklenen SQL yalnızca 15 oda içeriyordu; katalog 20 oda olduğu için
    // eksik odalar burada tamamlanır (INSERT IGNORE mevcutları ezmez).
    $rows = [];
    foreach (canonicalRoomCatalog() as $roomId => $meta) {
        $rows[] = sprintf(
            '(%d, %s, %s, %d)',
            (int) $roomId,
            $pdo->quote((string) ($meta[0] ?? '')),
            $pdo->quote((string) ($meta[1] ?? '')),
            (int) $roomId
        );
    }
    if ($rows !== []) {
        // MySQL, sql_mode'da NO_AUTO_VALUE_ON_ZERO yokken id=0'ı otomatik
        // artırıma çevirir (Bilgi Odası id=0 olamaz, kopya satırlar ürer).
        // Bu bağlantı için kip açılır; yalnızca tohum etkilenir.
        try {
            $pdo->exec("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        } catch (Throwable $error) {
            error_log('[schema] sql_mode ayarı yapılamadı: ' . $error->getMessage());
        }
        $pdo->exec(
            'INSERT IGNORE INTO rooms (id, name, description, position) VALUES ' . implode(', ', $rows)
        );
        // Geçiş dönemi onarımı: kip kapalıyken yazılmış kopya 'Bilgi Odası'
        // satırları (yanlış kimlikle) içinde mesaj yoksa temizlenir.
        try {
            $pdo->exec(
                "DELETE FROM rooms WHERE id <> " . INFO_ROOM_ID . " AND name = 'Bilgi Odası'
                 AND NOT EXISTS (SELECT 1 FROM messages m WHERE m.room_id = rooms.id)"
            );
        } catch (Throwable $error) {
            error_log('[schema] Bilgi Odası kopyası temizlenemedi: ' . $error->getMessage());
        }
    }
}

function ensureRuntimeSchema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    // Çekirdek tablolar her şeyden önce: aşağıdaki damga sondası rate_limits
    // tablosuna, kolon yamaları ise users tablosuna soruyor. Boş veritabanında
    // bunlar yoksa 500 üretir; önce çekirdek şema kurulur.
    ensureCoreSchema($pdo);

    // Kurulum damgası veritabanındaysa ana kurulum (DDL/ALTER) atlanır.
    // Kimlik ve platform altyapıları kendi damgalarıyla korunur, bu yüzden
    // burada da garantilenir.
    if (schemaStampApplied($pdo, SCHEMA_VERSION_KEY)) {
        ensureIdentityInfra($pdo);
        $checked = true;
        return;
    }

    $column = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'profile_image_path'"
    );
    if ((int) $column->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN profile_image_path VARCHAR(255) NULL AFTER avatar_color');
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '42S21') {
                throw $error;
            }
        }
    }

    $sessionVersionColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'session_version'"
    );
    if ((int) $sessionVersionColumn->fetchColumn() === 0) {
        try {
            $pdo->exec('ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER password_hash');
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '42S21') {
                throw $error;
            }
        }
    }

    $economyColumns = [
        'aa_coins' => "ALTER TABLE users ADD COLUMN aa_coins DECIMAL(12,1) NOT NULL DEFAULT 0.0 AFTER birthday",
        'active_profile_frame' => "ALTER TABLE users ADD COLUMN active_profile_frame VARCHAR(40) NULL AFTER aa_coins",
        'total_active_seconds' => "ALTER TABLE users ADD COLUMN total_active_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER active_profile_frame",
        'last_activity_counted_at' => "ALTER TABLE users ADD COLUMN last_activity_counted_at DATETIME NULL AFTER total_active_seconds",
        'invite_reset_notice_at' => "ALTER TABLE users ADD COLUMN invite_reset_notice_at DATETIME NULL AFTER last_activity_counted_at",
        'coin_debt_due_at' => "ALTER TABLE users ADD COLUMN coin_debt_due_at DATETIME NULL AFTER invite_reset_notice_at",
        'coin_debt_notice_at' => "ALTER TABLE users ADD COLUMN coin_debt_notice_at DATETIME NULL AFTER coin_debt_due_at",
        'approval_status' => "ALTER TABLE users ADD COLUMN approval_status ENUM('approved','pending') NOT NULL DEFAULT 'approved' AFTER coin_debt_notice_at",
        'approval_reason' => "ALTER TABLE users ADD COLUMN approval_reason VARCHAR(1000) NULL AFTER approval_status",
        'approval_submitted_at' => "ALTER TABLE users ADD COLUMN approval_submitted_at DATETIME NULL AFTER approval_reason",
        'approval_decision_notice' => "ALTER TABLE users ADD COLUMN approval_decision_notice ENUM('accepted') NULL AFTER approval_submitted_at",
        'approval_decision_at' => "ALTER TABLE users ADD COLUMN approval_decision_at DATETIME NULL AFTER approval_decision_notice",
        'invite_code_consumed_at' => "ALTER TABLE users ADD COLUMN invite_code_consumed_at DATETIME NULL AFTER invite_code_updated_at",
        'invite_code_consumed_by' => "ALTER TABLE users ADD COLUMN invite_code_consumed_by VARCHAR(24) NULL AFTER invite_code_consumed_at",
    ];
    foreach ($economyColumns as $columnName => $alterSql) {
        $columnCheck = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?"
        );
        $columnCheck->execute([$columnName]);
        if ((int) $columnCheck->fetchColumn() === 0) {
            try {
                $pdo->exec($alterSql);
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '42S21') {
                    try {
                        $pdo->exec(preg_replace('/\s+AFTER\s+`?[a-zA-Z0-9_]+`?/i', '', $alterSql) ?? $alterSql);
                    } catch (PDOException $fallbackError) {
                        error_log('[schema] column migration skipped: ' . $fallbackError->getMessage());
                    }
                }
            }
        }
    }

    try {
        $pdo->exec('ALTER TABLE users MODIFY COLUMN aa_coins DECIMAL(12,1) NOT NULL DEFAULT 0.0');
    } catch (PDOException) {
        /* Eski kurulumlarda kolon tipi zaten uyumlu olabilir. */
    }

    $announcementColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages' AND COLUMN_NAME = 'announcement_id'"
    );
    if ((int) $announcementColumn->fetchColumn() === 0) {
        try {
            $pdo->exec(
                'ALTER TABLE messages ADD COLUMN announcement_id BIGINT UNSIGNED NULL AFTER user_id,
                 ADD KEY idx_messages_announcement (announcement_id)'
            );
        } catch (PDOException $error) {
            if ((string) $error->getCode() !== '42S21') {
                throw $error;
            }
        }
    }

    $announcementForeignKey = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'messages'
           AND CONSTRAINT_NAME = 'fk_messages_announcement'"
    );
    if ((int) $announcementForeignKey->fetchColumn() === 0) {
        try {
            $pdo->exec(
                'ALTER TABLE messages ADD CONSTRAINT fk_messages_announcement
                 FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE'
            );
        } catch (PDOException $error) {
            error_log('[schema] foreign key migration skipped: ' . $error->getMessage());
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS room_reads (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            room_id SMALLINT UNSIGNED NOT NULL,
            last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            last_read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_room_reads_user_room (user_id, room_id),
            KEY idx_room_reads_room (room_id),
            CONSTRAINT fk_room_reads_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_room_reads_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "INSERT IGNORE INTO room_reads (user_id, room_id, last_read_message_id, last_read_at)
         SELECT u.id, r.id, COALESCE(MAX(m.id), 0), UTC_TIMESTAMP()
         FROM users u CROSS JOIN rooms r
         LEFT JOIN messages m ON m.room_id = r.id AND m.deleted_at IS NULL
         GROUP BY u.id, r.id"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS user_devices (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            last_username VARCHAR(24) NOT NULL,
            device_token_hash CHAR(64) NOT NULL,
            fingerprint_hash CHAR(64) NOT NULL,
            user_agent VARCHAR(255) NOT NULL,
            first_ip VARCHAR(45) NOT NULL,
            last_ip VARCHAR(45) NOT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_blocked TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uq_user_devices_token (device_token_hash),
            KEY idx_user_devices_user (user_id),
            KEY idx_user_devices_fingerprint_ip (fingerprint_hash, last_ip),
            KEY idx_user_devices_last_seen (last_seen_at),
            CONSTRAINT fk_user_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS blocked_identifiers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier_type ENUM('device', 'fingerprint', 'ip') NOT NULL,
            identifier_hash CHAR(64) NOT NULL,
            source_user_id BIGINT UNSIGNED NULL,
            reason VARCHAR(1000) NOT NULL,
            blocked_until DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_blocked_identifier (identifier_type, identifier_hash),
            KEY idx_blocked_identifiers_user (source_user_id),
            KEY idx_blocked_identifiers_until (blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS media_files (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                access_token CHAR(32) NOT NULL,
                owner_user_id BIGINT UNSIGNED NULL,
                mime_type VARCHAR(40) NOT NULL,
                original_name VARCHAR(180) NULL,
                byte_size INT UNSIGNED NOT NULL,
                content LONGBLOB NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_media_files_token (access_token),
                KEY idx_media_files_owner (owner_user_id),
                KEY idx_media_files_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (PDOException $error) {
        error_log('[schema] media_files migration skipped: ' . $error->getMessage());
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id BIGINT UNSIGNED NOT NULL,
                selector CHAR(32) NOT NULL,
                validator_hash CHAR(64) NOT NULL,
                session_version INT UNSIGNED NOT NULL DEFAULT 1,
                device_token_hash CHAR(64) NOT NULL,
                user_agent VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY uq_remember_selector (selector),
                KEY idx_remember_user (user_id),
                KEY idx_remember_expires (expires_at),
                CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    } catch (PDOException $error) {
        error_log('[schema] auth_remember_tokens migration skipped: ' . $error->getMessage());
    }
    try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS ai_safety_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(40) NOT NULL,
            input_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ai_safety_user_created (user_id, created_at),
            KEY idx_ai_safety_type_created (event_type, created_at),
            CONSTRAINT fk_ai_safety_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
        $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_frame_purchases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            frame_id VARCHAR(40) NOT NULL,
            price_paid INT UNSIGNED NOT NULL,
            purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aa_frame_user_frame (user_id, frame_id),
            KEY idx_aa_frame_user (user_id),
            CONSTRAINT fk_aa_frame_purchases_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
        $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_quest_claims (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            quest_id VARCHAR(60) NOT NULL,
            reward DECIMAL(8,1) UNSIGNED NOT NULL,
            claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aa_quest_user_quest (user_id, quest_id),
            KEY idx_aa_quest_user (user_id),
            CONSTRAINT fk_aa_quest_claims_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
        $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_game_rewards (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            game_id VARCHAR(60) NOT NULL,
            reward DECIMAL(8,1) UNSIGNED NOT NULL,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            rewarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aa_game_user_time (user_id, rewarded_at),
            KEY idx_aa_game_user_game_time (user_id, game_id, rewarded_at),
            CONSTRAINT fk_aa_game_rewards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
        $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_daily_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            activity_date DATE NOT NULL,
            active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aa_daily_activity_user_date (user_id, activity_date),
            KEY idx_aa_daily_activity_date (activity_date),
            CONSTRAINT fk_aa_daily_activity_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
        foreach (['aa_quest_claims' => 'DECIMAL(8,1)', 'aa_game_rewards' => 'DECIMAL(8,1)'] as $tableName => $decimalType) {
        try {
            $pdo->exec('ALTER TABLE `' . $tableName . '` MODIFY COLUMN reward ' . $decimalType . ' UNSIGNED NOT NULL');
        } catch (PDOException) {
            /* Tablo yeni oluşturulmuş veya kolon tipi zaten uyumlu olabilir. */
        }
    }
        $pdo->exec(
        "CREATE TABLE IF NOT EXISTS aa_coin_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            action_type ENUM('grant', 'take', 'debt') NOT NULL,
            amount DECIMAL(12,1) UNSIGNED NOT NULL,
            previous_balance DECIMAL(12,1) NOT NULL,
            new_balance DECIMAL(12,1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aa_coin_adjustments_user (user_id, created_at),
            KEY idx_aa_coin_adjustments_admin (admin_id, created_at),
            CONSTRAINT fk_aa_coin_adjustments_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_aa_coin_adjustments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
        $pdo->exec(
        "CREATE TABLE IF NOT EXISTS approval_request_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(24) NOT NULL,
            device_token_hash CHAR(64) NOT NULL,
            fingerprint_hash CHAR(64) NOT NULL,
            reason_type ENUM('rejected','expired','deleted') NOT NULL,
            message VARCHAR(1000) NOT NULL,
            blocked_until DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_approval_blocks_username (username, blocked_until),
            KEY idx_approval_blocks_device (device_token_hash, blocked_until),
            KEY idx_approval_blocks_fingerprint (fingerprint_hash, blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );
    } catch (PDOException $error) {
        error_log('[schema] optional feature table migration skipped: ' . $error->getMessage());
    }
    $featureTables = [
        'auth_remember_tokens' => "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            selector CHAR(32) NOT NULL,
            validator_hash CHAR(64) NOT NULL,
            session_version INT UNSIGNED NOT NULL DEFAULT 1,
            device_token_hash CHAR(64) NOT NULL,
            user_agent VARCHAR(255) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_used_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_remember_selector (selector),
            KEY idx_remember_user (user_id),
            KEY idx_remember_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'media_files' => "CREATE TABLE IF NOT EXISTS media_files (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            access_token CHAR(32) NOT NULL,
            owner_user_id BIGINT UNSIGNED NULL,
            mime_type VARCHAR(40) NOT NULL,
            original_name VARCHAR(180) NULL,
            byte_size INT UNSIGNED NOT NULL,
            content LONGBLOB NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_media_files_token (access_token),
            KEY idx_media_files_owner (owner_user_id),
            KEY idx_media_files_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'aa_frame_purchases' => "CREATE TABLE IF NOT EXISTS aa_frame_purchases (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            frame_id VARCHAR(40) NOT NULL,
            price_paid INT UNSIGNED NOT NULL,
            purchased_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aa_frame_user_frame (user_id, frame_id),
            KEY idx_aa_frame_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'aa_quest_claims' => "CREATE TABLE IF NOT EXISTS aa_quest_claims (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            quest_id VARCHAR(60) NOT NULL,
            reward DECIMAL(8,1) UNSIGNED NOT NULL,
            claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aa_quest_user_quest (user_id, quest_id),
            KEY idx_aa_quest_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'aa_game_rewards' => "CREATE TABLE IF NOT EXISTS aa_game_rewards (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            game_id VARCHAR(60) NOT NULL,
            reward DECIMAL(8,1) UNSIGNED NOT NULL,
            score INT UNSIGNED NOT NULL DEFAULT 0,
            rewarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aa_game_user_time (user_id, rewarded_at),
            KEY idx_aa_game_user_game_time (user_id, game_id, rewarded_at)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'aa_daily_activity' => "CREATE TABLE IF NOT EXISTS aa_daily_activity (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            activity_date DATE NOT NULL,
            active_seconds INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_aa_daily_activity_user_date (user_id, activity_date),
            KEY idx_aa_daily_activity_date (activity_date)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'aa_coin_adjustments' => "CREATE TABLE IF NOT EXISTS aa_coin_adjustments (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT UNSIGNED NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            action_type ENUM('grant', 'take', 'debt') NOT NULL,
            amount DECIMAL(12,1) UNSIGNED NOT NULL,
            previous_balance DECIMAL(12,1) NOT NULL,
            new_balance DECIMAL(12,1) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_aa_coin_adjustments_user (user_id, created_at),
            KEY idx_aa_coin_adjustments_admin (admin_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'approval_request_blocks' => "CREATE TABLE IF NOT EXISTS approval_request_blocks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(24) NOT NULL,
            device_token_hash CHAR(64) NOT NULL,
            fingerprint_hash CHAR(64) NOT NULL,
            reason_type ENUM('rejected','expired','deleted') NOT NULL,
            message VARCHAR(1000) NOT NULL,
            blocked_until DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_approval_blocks_username (username, blocked_until),
            KEY idx_approval_blocks_device (device_token_hash, blocked_until),
            KEY idx_approval_blocks_fingerprint (fingerprint_hash, blocked_until)
        ) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];
    foreach ($featureTables as $featureTable => $featureSql) {
        safeSchemaExec($pdo, $featureSql, $featureTable);
    }

    // Uygulama (WebView) kimliği: mobil uygulama ile site aynı kimliği paylaşabilsin
    // ve bir yerde uygulanan yasak diğerinde de geçerli olsun.
    $appIdentityColumn = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices' AND COLUMN_NAME = 'app_hash'"
    );
    if ((int) $appIdentityColumn->fetchColumn() === 0) {
        safeSchemaExec($pdo, 'ALTER TABLE user_devices ADD COLUMN app_hash CHAR(64) NULL AFTER fingerprint_hash, ADD KEY idx_user_devices_app (app_hash)', 'user_devices.app_hash');
    }
    safeSchemaExec($pdo, "ALTER TABLE blocked_identifiers MODIFY COLUMN identifier_type ENUM('device', 'fingerprint', 'ip', 'app') NOT NULL", 'blocked_identifiers.identifier_type');

    // Performans indeksleri: eski kurulumlarda eksik olabilir. Yoğun sorguların
    // tam tablo taramasına düşmesini engeller.
    $requiredIndexes = [
        ['users', 'idx_users_invite_updated', 'invite_code_updated_at'],
        ['users', 'idx_users_approval', 'approval_status'],
        ['messages', 'idx_messages_room_id', 'room_id, id'],
        ['messages', 'idx_messages_created', 'created_at'],
        ['messages', 'idx_messages_user_created', 'user_id, created_at'],
        ['message_views', 'idx_message_views_message', 'message_id'],
        ['user_devices', 'idx_user_devices_ip', 'last_ip'],
        ['rate_limits', 'idx_rate_limits_updated', 'updated_at'],
    ];
    $indexCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $tableCheck = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    foreach ($requiredIndexes as [$table, $indexName, $columns]) {
        $tableCheck->execute([$table]);
        if ((int) $tableCheck->fetchColumn() === 0) {
            continue;
        }
        $indexCheck->execute([$table, $indexName]);
        if ((int) $indexCheck->fetchColumn() === 0) {
            safeSchemaExec($pdo, 'ALTER TABLE `' . $table . '` ADD KEY `' . $indexName . '` (' . $columns . ')', $table . '.' . $indexName);
        }
    }

    $utf8mb4Tables = [
        'users', 'rooms', 'announcements', 'messages', 'message_views',
        'message_deletions', 'room_reads', 'banned_users', 'deleted_users_log',
        'user_devices', 'blocked_identifiers', 'media_files', 'auth_remember_tokens', 'ai_safety_events', 'aa_frame_purchases', 'aa_quest_claims', 'aa_game_rewards', 'aa_daily_activity', 'aa_coin_adjustments', 'approval_request_blocks', 'rate_limits',
        'site_settings', 'info_items', 'info_forms', 'info_form_replies', 'info_form_likes', 'info_form_reply_likes', 'complaints',
    ];
    $collation = $pdo->prepare(
        'SELECT TABLE_COLLATION FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    foreach ($utf8mb4Tables as $table) {
        $collation->execute([$table]);
        $currentCollation = (string) ($collation->fetchColumn() ?: '');
        if ($currentCollation !== '' && !str_starts_with(strtolower($currentCollation), 'utf8mb4_')) {
            try {
                $pdo->exec('ALTER TABLE `' . $table . '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            } catch (PDOException $error) {
                error_log('[schema] collation conversion skipped for ' . $table . ': ' . $error->getMessage());
            }
        }
    }

    $pdo->exec('DELETE FROM user_devices WHERE user_id IS NULL');
    $pdo->exec(
        'DELETE bi FROM blocked_identifiers bi
         LEFT JOIN users u ON u.id = bi.source_user_id
         WHERE bi.source_user_id IS NOT NULL AND u.id IS NULL'
    );

    // Bozuk/kalıcı kilit bırakmış hız sınırı satırları temizlenir. Eski sürüm
    // engeli her istekte uzatıyordu; içe aktarılmış veritabanlarında NULL
    // pencere de kalıcı kilide yol açıyordu. Kurulum bir kez çalıştığı için
    // bu temizlik siteyi otomatik olarak yeniden erişilebilir yapar.
    $pdo->exec(
        "DELETE FROM rate_limits
         WHERE rate_key LIKE 'global_read:%'
            OR rate_key LIKE 'rate:%'
            OR (blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP())"
    );

    // Kimlik ve platform altyapısı TABLOLAR OLUŞTUKTAN SONRA kurulur: aksi
    // hâlde veri göçleri henüz var olmayan tablolara dokunur ve her ilk
    // istekte "table doesn't exist" uyarısı üretir.
    ensureIdentityInfra($pdo);

    $pdo->exec("DELETE FROM rate_limits WHERE rate_key LIKE 'schema:runtime:%' AND rate_key <> '" . SCHEMA_VERSION_KEY . "'");
    markSchemaStamp($pdo, SCHEMA_VERSION_KEY);
    $checked = true;
}


function clearDeletedAccountWait(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $check = $pdo->prepare("SELECT 1 FROM rate_limits WHERE rate_key = 'schema:approval:no_deleted_wait:v1' LIMIT 1");
        $check->execute();
        if ($check->fetchColumn()) {
            return;
        }
        $pdo->exec("DELETE FROM approval_request_blocks WHERE reason_type = 'deleted'");
        $pdo->exec(
            "INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
             VALUES ('schema:approval:no_deleted_wait:v1', 0, UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()"
        );
    } catch (Throwable $error) {
        error_log('[approval] deleted-wait clear skipped: ' . $error->getMessage());
    }
}

function relaxLegacyIdentityBinds(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $check = $pdo->prepare("SELECT 1 FROM rate_limits WHERE rate_key = 'schema:identity:relax:v1' LIMIT 1");
        $check->execute();
        if ($check->fetchColumn()) {
            return;
        }
        // Eşleşme korunuyor: cihazlar silinmiyor, sadece flag yazılıyor
        error_log('[identity] cross-platform eşleşme korunuyor, cihazlar silinmedi');
        $pdo->exec(
            "INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
             VALUES ('schema:identity:relax:v1', 0, UTC_TIMESTAMP(), NULL)
             ON DUPLICATE KEY UPDATE updated_at = UTC_TIMESTAMP()"
        );
    } catch (Throwable $error) {
        error_log('[identity] relax skipped: ' . $error->getMessage());
    }
}

function ensureIdentityInfra(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    if (schemaStampApplied($pdo, 'schema:identity:v3')) {
        $ready = true;
        return;
    }
    relaxLegacyIdentityBinds($pdo);
    clearDeletedAccountWait($pdo);
    try {
        $appIdentityColumn = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_devices' AND COLUMN_NAME = 'app_hash'"
        );
        if ($appIdentityColumn && (int) $appIdentityColumn->fetchColumn() === 0) {
            safeSchemaExec($pdo, 'ALTER TABLE user_devices ADD COLUMN app_hash CHAR(64) NULL AFTER fingerprint_hash, ADD KEY idx_user_devices_app (app_hash)', 'user_devices.app_hash');
        }
    } catch (PDOException $error) {
        error_log('[schema] identity column check skipped: ' . $error->getMessage());
    }
    $userBindColumns = [
        'bind_token_hash' => "ALTER TABLE users ADD COLUMN bind_token_hash CHAR(64) NULL, ADD KEY idx_users_bind_token (bind_token_hash)",
        'bind_fingerprint_hash' => "ALTER TABLE users ADD COLUMN bind_fingerprint_hash CHAR(64) NULL, ADD KEY idx_users_bind_fp (bind_fingerprint_hash)",
        'bind_app_hash' => "ALTER TABLE users ADD COLUMN bind_app_hash CHAR(64) NULL, ADD KEY idx_users_bind_app (bind_app_hash)",
        'bind_ip' => "ALTER TABLE users ADD COLUMN bind_ip VARCHAR(45) NULL, ADD KEY idx_users_bind_ip (bind_ip)",
    ];
    foreach ($userBindColumns as $columnName => $alterSql) {
        try {
            $check = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?"
            );
            $check->execute([$columnName]);
            if ((int) $check->fetchColumn() === 0) {
                safeSchemaExec($pdo, $alterSql, 'users.' . $columnName);
            }
        } catch (PDOException $error) {
            error_log('[schema] user bind column skipped: ' . $error->getMessage());
        }
    }
    try {
        $typeCheck = $pdo->query(
            "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blocked_identifiers' AND COLUMN_NAME = 'identifier_type'"
        );
        $columnType = strtolower((string) ($typeCheck ? $typeCheck->fetchColumn() : ''));
        if ($columnType !== '' && !str_contains($columnType, "'app'")) {
            safeSchemaExec($pdo, "ALTER TABLE blocked_identifiers MODIFY COLUMN identifier_type ENUM('device', 'fingerprint', 'ip', 'app') NOT NULL", 'blocked_identifiers.identifier_type');
        }
    } catch (PDOException $error) {
        error_log('[schema] identifier type check skipped: ' . $error->getMessage());
    }
    markSchemaStamp($pdo, 'schema:identity:v3');
    $ready = true;
}

/**
 * Platform ayarları tablosunu kurar.
 *
 * ÖNEMLİ: DDL (CREATE TABLE) yalnızca günde bir kez çalışır (marker dosyası).
 * Ücretsiz hostlarda her istekte DDL, CPU kota aşımı ve hesap kilitlemeye
 * yol açar; diğer altyapı fonksiyonlarındaki marker deseniyle
 * birebir aynı akış izlenir. Hiçbir adımda istek öldürmez.
 */
function sessionCookieOptions(): array
{
    $params = session_get_cookie_params();
    return [
        'expires' => 0,
        'path' => $params['path'] !== '' ? $params['path'] : '/',
        'domain' => (string) ($params['domain'] ?? ''),
        'secure' => secureCookies(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function sendSessionCookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(session_name(), session_id(), sessionCookieOptions());
}

function sessionIdentityGuard(): string
{
    $userAgent = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $userAgent = preg_replace('/\s*AsraApp\/[0-9.]+/i', '', $userAgent) ?? $userAgent;
    return hash('sha256', implode('|', [
        strtolower((string) ($_COOKIE[DEVICE_COOKIE_NAME] ?? '')),
        (string) (appDeviceHash() ?? ''),
        $userAgent,
    ]));
}

/**
 * Oturum kimliği yenilendiğinde eski kimlik hemen yok edilmez; kısa bir süre
 * yeni kimliğe yönlendiren güvenli bir işaret bırakılır. Böylece yenileme anında
 * yolda olan istekler (avatar/medya indirmeleri, arka plan yoklamaları) 401 almaz.
 */
function rotateSessionId(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $data = $_SESSION;
    unset($data['session_successor'], $data['session_grace_until'], $data['session_guard']);

    $newId = session_create_id();
    if (!is_string($newId) || $newId === '') {
        $newId = bin2hex(random_bytes(24));
    }

    $_SESSION['session_successor'] = $newId;
    $_SESSION['session_grace_until'] = time() + SESSION_ROTATION_GRACE;
    $_SESSION['session_guard'] = sessionIdentityGuard();
    session_write_close();

    session_id($newId);
    // Katı mod, depoda henüz karşılığı olmayan kimliği reddeder. Kimlik devralınırken
    // kısa süreliğine kapatılır; her istek bootWebSession içinde yeniden etkinleşir.
    ini_set('session.use_strict_mode', '0');
    @session_start();
    $_SESSION = $data;
    $_SESSION['created_at'] = time();
    sendSessionCookie();
}

function followRotatedSession(): void
{
    for ($hop = 0; $hop < 3; $hop++) {
        $successor = $_SESSION['session_successor'] ?? null;
        if (!is_string($successor) || !preg_match('/\A[A-Za-z0-9,\-]{16,128}\z/', $successor)) {
            return;
        }
        $graceValid = (int) ($_SESSION['session_grace_until'] ?? 0) > time();
        $guardValid = hash_equals((string) ($_SESSION['session_guard'] ?? ''), sessionIdentityGuard());
        if (!$graceValid || !$guardValid) {
            unset($_SESSION['session_successor'], $_SESSION['session_grace_until'], $_SESSION['session_guard']);
            return;
        }
        session_write_close();
        session_id($successor);
        ini_set('session.use_strict_mode', '0');
        session_start();
        sendSessionCookie();
    }
}

function bootWebSession(): void
{
    // Session klasörü yazılabilir değilse (InfinityFree'de bazen /var/lib/php/sessions kilitli), temp'e düş
    $tmp = sys_get_temp_dir();
    if (is_string($tmp) && $tmp !== '' && is_dir($tmp) && is_writable($tmp)) {
        @ini_set('session.save_path', $tmp);
    }
    // Garbage collection temp'de de çalışsın
    @ini_set('session.gc_probability', '1');
    @ini_set('session.gc_divisor', '100');
    $secure = secureCookies();
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $secure ? '1' : '0');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', '43200');

    session_name('ASRA_SID');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    // @ ile uyarı bastır, başarısız olursa temp ile tekrar dene
    $started = @session_start();
    if ($started === false) {
        $tmp2 = sys_get_temp_dir();
        if (is_string($tmp2) && $tmp2 !== '' && is_dir($tmp2) && is_writable($tmp2)) {
            @ini_set('session.save_path', $tmp2);
            $started = @session_start();
        }
        if ($started === false) {
            // Session olmadan da JSON dönebilmeli - geçici dizi kullan
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            error_log('[session] fallback to temp array, session_start failed');
        }
    }
    followRotatedSession();

    if (!isset($_SESSION['created_at'])) {
        $_SESSION['created_at'] = time();
    } elseif (time() - (int) $_SESSION['created_at'] > SESSION_ROTATION_INTERVAL) {
        rotateSessionId();
    }

    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Salt okunur medya isteklerinde oturum kilidini mümkün olan en erken anda
 * bırakır ve kilit bırakılmadan önce okunan kullanıcı kimliğini döndürür.
 */
function releaseSessionEarly(): int
{
    static $viewerId = null;
    if ($viewerId === null) {
        $viewerId = (int) (filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT) ?: 0);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
    return $viewerId;
}

function sendSecurityHeaders(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'");
    if (envValue('ASRA_HSTS', '1') !== '0') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function respond(array $payload = [], int $status = 200): never
{
    // Önceki tamponları temizle - HTML sızıntısı JSON'u bozmamalı
    while (ob_get_level() > 0) { @ob_end_clean(); }
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    // fastcgi_finish_request sonrası bile yanıt gidebilsin
    $json = json_encode(['ok' => $status < 400] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $json = json_encode(['ok'=>false,'error'=>['code'=>'INTERNAL_ERROR','message'=>'Yanıt oluşturulamadı.','details'=>['json_error'=>json_last_error_msg()]]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json === false) { $json = '{"ok":false,"error":{"code":"INTERNAL_ERROR","message":"Yanıt oluşturulamadı."}}'; }
    }
    // Bağlantı kopmuşsa bile hata fırlatma
    echo $json;
    // Eğer fastcgi kullanıyorsak, yanıtı hemen gönder, sonrası arka planda kalsın
    if (function_exists('fastcgi_finish_request')) {
        try { fastcgi_finish_request(); } catch (Throwable $e) {}
    }
    exit;
}

function closeSessionEarlyForReads(string $action): void
{
    static $readActions = ['session','get_rooms','get_messages','get_message_views','get_user_profile','admin_search_users','admin_pending_approvals','get_economy','get_quests','heartbeat','get_banned_info','get_media','media','info_items','info_forms','info_form_replies','complaint_quota','admin_feed_status','admin_complaints'];
    if (!in_array($action, $readActions, true)) return;
    if (session_status() === PHP_SESSION_ACTIVE) {
        try { session_write_close(); } catch (Throwable $e) {}
    }
}

function requestData(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 1_000_000) {
            throw new ApiError(413, 'REQUEST_TOO_LARGE', 'İstek gövdesi çok büyük.');
        }
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiError(400, 'INVALID_JSON', 'Geçersiz JSON verisi.');
        }
        if (!is_array($decoded)) {
            throw new ApiError(400, 'INVALID_REQUEST', 'İstek gövdesi nesne olmalıdır.');
        }
        return $decoded;
    }
    return $_POST;
}

function requireMethod(string $method): void
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== $method) {
        header('Allow: ' . $method);
        throw new ApiError(405, 'METHOD_NOT_ALLOWED', 'Bu işlem için izin verilmeyen istek yöntemi.');
    }
}

function requireCsrf(): void
{
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['csrf_token'] ?? '');
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        // Güncel jeton yanıta eklenir; istemci sayfayı yenilemeden isteği tekrarlayabilir.
        throw new ApiError(419, 'CSRF_FAILED', 'Güvenlik doğrulaması yenilendi, işlem tekrarlanıyor.', [
            'csrf_token' => $_SESSION['csrf_token'] ?? '',
        ]);
    }
}

function clientIp(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function ensureDeviceToken(): string
{
    $token = strtolower((string) ($_COOKIE[DEVICE_COOKIE_NAME] ?? ''));
    if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
        $token = bin2hex(random_bytes(32));
        setcookie(DEVICE_COOKIE_NAME, $token, [
            'expires' => time() + DEVICE_COOKIE_LIFETIME,
            'path' => '/',
            'secure' => secureCookies(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[DEVICE_COOKIE_NAME] = $token;
    }
    return $token;
}

/**
 * Mobil uygulama (WebView) isteklerinde gönderilen kalıcı cihaz kimliği.
 * Uygulama ile site aynı kimliği paylaştığında bir tarafta uygulanan yasak
 * diğer tarafta da geçerli olur.
 */
function appDeviceHash(): ?string
{
    $raw = strtolower(trim((string) ($_SERVER[APP_DEVICE_HEADER] ?? $_COOKIE[APP_DEVICE_COOKIE] ?? '')));
    if (!preg_match('/\A[a-z0-9_.:-]{8,128}\z/', $raw)) {
        return null;
    }
    return hash('sha256', 'app:' . $raw);
}

function deviceIdentity(): array
{
    $tokenHash = hash('sha256', ensureDeviceToken());
    $ip = clientIp();
    // Cross-platform: AsraApp suffix'i ve IP fingerprint'ten çıkarıldı -> Chrome ve WebView aynı cihazda aynı fingerprint
    $rawUa = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    // AsraApp/1.5.4 gibi ekleri temizle, böylece Chrome ve WebView aynı hash'i üretir
    $rawUa = preg_replace('/\s*AsraApp\/[0-9.]+/i', '', $rawUa) ?? $rawUa;
    $parts = [
        $rawUa,
        strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? 'unknown')),
        strtolower((string) ($_SERVER['HTTP_SEC_CH_UA'] ?? 'unknown')),
        strtolower((string) ($_SERVER['HTTP_SEC_CH_UA_PLATFORM'] ?? 'unknown')),
        strtolower((string) ($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? 'unknown')),
    ];
    return [
        'token_hash' => $tokenHash,
        'fingerprint_hash' => hash('sha256', implode('|', $parts)),
        'ip_hash' => hash('sha256', $ip),
        'app_hash' => appDeviceHash(),
        'ip' => $ip,
        'user_agent' => substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'Bilinmeyen cihaz')) ?: 'Bilinmeyen cihaz', 0, 255),
    ];
}

function persistUserDeviceBind(PDO $pdo, int $userId, ?array $identity = null): void
{
    if ($userId <= 0) {
        return;
    }
    $identity = $identity ?? deviceIdentity();
    $ip = $identity['ip'] ?? '';
    if ($ip === '0.0.0.0') {
        $ip = null;
    }
    try {
        $stmt = $pdo->prepare(
            'UPDATE users
             SET bind_token_hash = COALESCE(bind_token_hash, ?),
                 bind_fingerprint_hash = COALESCE(bind_fingerprint_hash, ?),
                 bind_app_hash = COALESCE(bind_app_hash, ?),
                 bind_ip = COALESCE(?, bind_ip)
             WHERE id = ?'
        );
        $stmt->execute([
            $identity['token_hash'],
            $identity['fingerprint_hash'],
            $identity['app_hash'],
            $ip,
            $userId,
        ]);
    } catch (Throwable $error) {
        error_log('[identity] user bind persist skipped: ' . $error->getMessage());
    }
}

function findUserAccountBinding(PDO $pdo): ?array
{
    $identity = deviceIdentity();
    $appHash = $identity['app_hash'];
    try {
        // DİREKT USERS: Ayrı tablo değil, users tablosundaki bind_* kolonları ile eşleşme.
        // KÖK DÜZELTME: Eşleşme YALNIZCA benzersiz kimliklerle yapılır:
        //   - bind_token_hash  (tarayıcı çerezi, profil başına benzersiz)
        //   - bind_app_hash    (uygulama cihaz kimliği, kurulum başına benzersiz)
        // Fingerprint (UA + dil başlıkları) EŞLEŞTİRMEDE KULLANILMAZ: aynı
        // tarayıcı/model/dile sahip İKİ FARKLI KİŞİ aynı fingerprint'i üretir
        // ve alakasız şekilde birbirinin hesabıyla eşleşirdi. Fingerprint yalnızca
        // ban (blockUserIdentities) ve user_devices kaydı gibi güvenlik amaçlı
        // kullanılmaya devam eder.
        $stmt = $pdo->prepare(
            'SELECT id AS user_id, username AS last_username, username AS active_username, 0 AS is_blocked
             FROM users
             WHERE bind_token_hash = ?
                OR (? IS NOT NULL AND bind_app_hash = ?)
             LIMIT 1'
        );
        $stmt->execute([
            $identity['token_hash'],
            $appHash,
            $appHash,
        ]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $error) {
        error_log('[identity] user bind lookup skipped: ' . $error->getMessage());
        return null;
    }
}

function adoptAppHashForAccount(PDO $pdo, int $userId, ?string $appHash): void
{
    if ($userId <= 0 || $appHash === null || $appHash === '') {
        return;
    }
    try {
        $pdo->prepare('UPDATE users SET bind_app_hash = COALESCE(bind_app_hash, ?) WHERE id = ?')->execute([$appHash, $userId]);
    } catch (Throwable $error) {
        error_log('[identity] user app hash adopt skipped: ' . $error->getMessage());
    }
    try {
        $stmt = $pdo->prepare(
            "UPDATE user_devices SET app_hash = ? WHERE user_id = ? AND (app_hash IS NULL OR app_hash = '')"
        );
        $stmt->execute([$appHash, $userId]);
    } catch (Throwable $error) {
        error_log('[identity] app hash adopt skipped: ' . $error->getMessage());
    }
}

function findDeviceBinding(PDO $pdo): ?array
{
    // DİREKT USERS: Ayrı tablo değil, users'daki bind_* ile eşleşme - çok katı değil
    // findUserAccountBinding zaten users'ı kontrol ediyor, onu kullan
    return findUserAccountBinding($pdo);
}

function deviceAccountState(PDO $pdo): array
{
    $binding = findDeviceBinding($pdo);
    $alive = $binding !== null && $binding['user_id'] !== null;
    return [
        'has_account' => $alive,
        'username' => $alive ? ($binding['active_username'] ?: $binding['last_username']) : null,
    ];
}

function assertDeviceAvailableForRegistration(PDO $pdo): void
{
    $binding = findDeviceBinding($pdo);
    if ($binding !== null && $binding['user_id'] !== null) {
        // DİREKT USERS ama çok katı değil: 7 günden eski hesap varsa izin ver (aile, eski telefon)
        // 7 gün içinde aynı cihazdan 2. hesap engellenir -> baypas zor, ama çok katı değil
        try {
            $stmt = $pdo->prepare('SELECT created_at FROM users WHERE id = ?');
            $stmt->execute([(int)$binding['user_id']]);
            $created = $stmt->fetchColumn();
            $isRecent = $created && strtotime($created . ' UTC') > time() - 7*86400;
            if ($isRecent !== false && $isRecent) {
                throw new ApiError(409, 'DEVICE_ALREADY_REGISTERED', 'Bu cihazda yakın zamanda bir hesap oluşturuldu (' . ($binding['active_username'] ?: $binding['last_username']) . '). Güvenlik nedeniyle aynı cihazdan 7 gün içinde yeni hesap oluşturulamaz.', [
                    'username' => $binding['active_username'] ?: $binding['last_username'],
                ]);
            }
            // 7+ gün eski -> izin ver, çok katı değil
            error_log('[identity] device reuse allowed after 7d for ' . $binding['user_id']);
        } catch (ApiError $e) {
            throw $e;
        } catch (Throwable $e) {
            // Hata olursa güvenli tarafta kal
            error_log('[identity] device check error: ' . $e->getMessage());
            throw new ApiError(409, 'DEVICE_ALREADY_REGISTERED', 'Bu cihazda daha önce bir hesap kullanıldı. Güvenlik nedeniyle aynı cihazdan yeni hesap oluşturulamaz.', [
                'username' => $binding['active_username'] ?: $binding['last_username'],
            ]);
        }
    }
}

function assertDeviceMayUseAccount(PDO $pdo, int $userId): void
{
    $binding = findDeviceBinding($pdo);
    if ($binding !== null && $binding['user_id'] !== null && (int) $binding['user_id'] !== $userId) {
        throw new ApiError(409, 'DEVICE_ACCOUNT_MISMATCH', 'Bu cihaz başka bir hesaba bağlıdır. Yalnızca cihazın mevcut hesabıyla giriş yapılabilir.', [
            'username' => $binding['active_username'] ?: $binding['last_username'],
        ]);
    }
}

function rememberDeviceForUser(PDO $pdo, int $userId, string $username): void
{
    $identity = deviceIdentity();
    // DİREKT USERS: Ana eşleşme users.bind_* ile, user_devices sadece log için
    // Çok katı değil: her girişte users'daki bind_* güncellenir, ama ayrı tabloya da yazılır (geriye dönük uyum)
    persistUserDeviceBind($pdo, $userId, $identity);
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO user_devices
                (user_id, last_username, device_token_hash, fingerprint_hash, app_hash, user_agent, first_ip, last_ip, first_seen_at, last_seen_at, is_blocked)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), 0)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_username = VALUES(last_username),
                fingerprint_hash = IF(VALUES(app_hash) IS NULL, VALUES(fingerprint_hash), fingerprint_hash),
                app_hash = COALESCE(VALUES(app_hash), app_hash),
                user_agent = IF(VALUES(app_hash) IS NULL, VALUES(user_agent), user_agent),
                last_ip = VALUES(last_ip),
                last_seen_at = UTC_TIMESTAMP()'
        );
        $stmt->execute([
            $userId,
            $username,
            $identity['token_hash'],
            $identity['fingerprint_hash'],
            $identity['app_hash'],
            $identity['user_agent'],
            $identity['ip'],
            $identity['ip'],
        ]);
    } catch (Throwable $e) {
        // user_devices hatası kritik değil, users zaten güncellendi
        error_log('[identity] user_devices log skipped: ' . $e->getMessage());
    }
}

function rememberCookieOptions(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => secureCookies(),
        'httponly' => true,
        'samesite' => 'Strict',
    ];
}

function clearRememberCookie(): void
{
    if (!headers_sent()) {
        setcookie(REMEMBER_COOKIE_NAME, '', rememberCookieOptions(time() - 86400));
    }
    unset($_COOKIE[REMEMBER_COOKIE_NAME]);
}

/**
 * Kalıcı oturum anahtarı üretir. Sunucu oturum dosyaları silinse, uygulama
 * güncellense veya barındırma oturumları temizlese bile kullanıcı hesabından
 * düşmez; bir sonraki istekte sessizce geri yüklenir.
 */
function issueRememberToken(PDO $pdo, int $userId, int $sessionVersion): void
{
    try {
        $selector = bin2hex(random_bytes(16));
        $validator = bin2hex(random_bytes(32));
        $identity = deviceIdentity();
        $pdo->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ? AND device_token_hash = ?')
            ->execute([$userId, $identity['token_hash']]);
        $stmt = $pdo->prepare(
            'INSERT INTO auth_remember_tokens
                (user_id, selector, validator_hash, session_version, device_token_hash, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))'
        );
        $stmt->execute([
            $userId,
            $selector,
            hash('sha256', $validator),
            $sessionVersion,
            $identity['token_hash'],
            $identity['user_agent'],
            REMEMBER_LIFETIME,
        ]);
        $cookie = $selector . ':' . $validator;
        if (!headers_sent()) {
            setcookie(REMEMBER_COOKIE_NAME, $cookie, rememberCookieOptions(time() + REMEMBER_LIFETIME));
        }
        $_COOKIE[REMEMBER_COOKIE_NAME] = $cookie;
    } catch (Throwable $error) {
        error_log('[remember] issue skipped: ' . $error->getMessage());
    }
}

function revokeRememberTokens(PDO $pdo, int $userId): void
{
    try {
        $pdo->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?')->execute([$userId]);
    } catch (Throwable $error) {
        error_log('[remember] revoke skipped: ' . $error->getMessage());
    }
}

function currentRememberSelector(): string
{
    // "Beni hatırla" çerezinin seçici yarısı: bu isteği yapan cihazı diğer
    // cihazlardan ayıran tek kimlik. Biçim tutmazsa boş döner.
    $raw = (string) ($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    return preg_match('/\A([a-f0-9]{32}):[a-f0-9]{64}\z/', $raw, $matches) ? $matches[1] : '';
}

function forgetCurrentRememberToken(PDO $pdo): void
{
    $selector = currentRememberSelector();
    if ($selector !== '') {
        try {
            $pdo->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?')->execute([$selector]);
        } catch (Throwable $error) {
            error_log('[remember] forget skipped: ' . $error->getMessage());
        }
    }
    clearRememberCookie();
}

function revokeOtherRememberTokens(PDO $pdo, int $userId, int $keepVersion): void
{
    // Şifre değişince hesabın DİĞER cihazlarındaki "beni hatırla" anahtarları
    // düşer. Bu isteği yapan cihazın anahtarı yeni oturum sürümüne taşınır;
    // aksi halde restoreSessionFromRemember sürüm uyuşmazlığı görüp kendi
    // şifresini değiştiren kullanıcıyı da oturumdan atardı.
    $selector = currentRememberSelector();
    try {
        if ($selector === '') {
            $pdo->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?')->execute([$userId]);
            return;
        }
        $pdo->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ? AND selector <> ?')
            ->execute([$userId, $selector]);
        $pdo->prepare('UPDATE auth_remember_tokens SET session_version = ? WHERE user_id = ? AND selector = ?')
            ->execute([$keepVersion, $userId, $selector]);
    } catch (Throwable $error) {
        error_log('[remember] partial revoke skipped: ' . $error->getMessage());
    }
}

function restoreSessionFromRemember(PDO $pdo): void
{
    if (!empty($_SESSION['user_id'])) {
        return;
    }
    $raw = (string) ($_COOKIE[REMEMBER_COOKIE_NAME] ?? '');
    if (!preg_match('/\A([a-f0-9]{32}):([a-f0-9]{64})\z/', $raw, $matches)) {
        return;
    }
    try {
        if (shouldRunMaintenance($pdo, 'remember_cleanup', 3600)) {
            $pdo->exec('DELETE FROM auth_remember_tokens WHERE expires_at <= UTC_TIMESTAMP()');
        }
        $stmt = $pdo->prepare(
            'SELECT t.id, t.user_id, t.validator_hash, t.session_version, t.device_token_hash, t.expires_at,
                    u.session_version AS current_version, u.bind_app_hash AS account_app_hash,
                    u.bind_fingerprint_hash AS account_fingerprint_hash
             FROM auth_remember_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.selector = ? LIMIT 1'
        );
        $stmt->execute([$matches[1]]);
        $token = $stmt->fetch();
        if (!$token) {
            clearRememberCookie();
            return;
        }
        if (!hash_equals((string) $token['validator_hash'], hash('sha256', $matches[2]))) {
            // Geçersiz bir tarayıcı çerezi diğer cihazlardaki geçerli oturumları
            // düşürmemelidir. Yalnızca bu istekteki hatalı çerez temizlenir.
            clearRememberCookie();
            return;
        }
        $identity = deviceIdentity();
        $expired = strtotime((string) $token['expires_at'] . ' UTC') <= time();
        $versionChanged = (int) $token['session_version'] !== (int) $token['current_version'];
        // KÖK DÜZELTME: Güven, YALNIZCA benzersiz kimliklere verilir
        // (tarayıcı token'ı / uygulama cihaz kimliği). Fingerprint ortak
        // UA-dil imzasıdır; farklı kişilerde çakışır ve asla güven
        // kriteri olarak kullanılmaz.
        $tokenDeviceMatches = hash_equals((string) $token['device_token_hash'], $identity['token_hash']);
        $appMatches = is_string($identity['app_hash']) && $identity['app_hash'] !== ''
            && is_string($token['account_app_hash']) && $token['account_app_hash'] !== ''
            && hash_equals($token['account_app_hash'], $identity['app_hash']);
        $trustedDevice = $tokenDeviceMatches || $appMatches;
        if ($expired || $versionChanged || !$trustedDevice) {
            $pdo->prepare('DELETE FROM auth_remember_tokens WHERE id = ?')->execute([$token['id']]);
            clearRememberCookie();
            return;
        }

        // Validator her paralel istekte döndürülürse iki eşzamanlı istek birbirinin
        // yeni anahtarını geçersiz kılar ve kullanıcı rastgele dışarı atılır. Güçlü,
        // cihaz bağlı validator sabit tutulur; kullanım/son kullanma süresi uzatılır.
        $refresh = $pdo->prepare(
            'UPDATE auth_remember_tokens
             SET device_token_hash = ?, last_used_at = UTC_TIMESTAMP(),
                 expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)
             WHERE id = ? AND validator_hash = ?'
        );
        $refresh->execute([
            $identity['token_hash'],
            REMEMBER_LIFETIME,
            $token['id'],
            (string) $token['validator_hash'],
        ]);
        $cookie = $matches[1] . ':' . $matches[2];
        if (!headers_sent()) {
            setcookie(REMEMBER_COOKIE_NAME, $cookie, rememberCookieOptions(time() + REMEMBER_LIFETIME));
        }
        $_COOKIE[REMEMBER_COOKIE_NAME] = $cookie;

        $_SESSION['user_id'] = (int) $token['user_id'];
        $_SESSION['session_version'] = (int) $token['current_version'];
        $_SESSION['last_touch'] = 0;
    } catch (Throwable $error) {
        error_log('[remember] restore skipped: ' . $error->getMessage());
    }
}

function activeIdentityBlock(PDO $pdo): ?array
{
    $identity = deviceIdentity();
    runMaintenanceSafely($pdo, 'identity_blocks', 600, static function (PDO $database): void {
        $database->exec('DELETE FROM blocked_identifiers WHERE blocked_until IS NOT NULL AND blocked_until <= UTC_TIMESTAMP()');
    });
    $stmt = $pdo->prepare(
        "SELECT reason, blocked_until, created_at FROM blocked_identifiers
         WHERE ((identifier_type = 'device' AND identifier_hash = ?)
            OR (identifier_type = 'fingerprint' AND identifier_hash = ?)
            OR (identifier_type = 'ip' AND identifier_hash = ?)
            OR (identifier_type = 'app' AND identifier_hash = ?))
           AND (blocked_until IS NULL OR blocked_until > UTC_TIMESTAMP())
         ORDER BY blocked_until IS NULL DESC, created_at DESC LIMIT 1"
    );
    $stmt->execute([
        $identity['token_hash'],
        $identity['fingerprint_hash'],
        $identity['ip_hash'],
        $identity['app_hash'] ?? '',
    ]);
    $block = $stmt->fetch();
    if (!$block) {
        return null;
    }
    return [
        'reason' => $block['reason'],
        'permanent' => $block['blocked_until'] === null,
        'banned_until' => $block['blocked_until'] ? gmdate(DateTimeInterface::ATOM, strtotime($block['blocked_until'] . ' UTC')) : null,
        'banned_at' => gmdate(DateTimeInterface::ATOM, strtotime($block['created_at'] . ' UTC')),
    ];
}

function blockUserIdentities(PDO $pdo, int $userId, string $reason, ?string $until): void
{
    $devices = $pdo->prepare('SELECT device_token_hash, fingerprint_hash, app_hash, last_ip FROM user_devices WHERE user_id = ?');
    $devices->execute([$userId]);
    $rows = $devices->fetchAll();
    // Yasaklanan hesabın kalıcı oturumları da düşer: uygulamada ya da sitede
    // açık kalan hiçbir oturum yasağı atlatamaz.
    revokeRememberTokens($pdo, $userId);
    $upsert = $pdo->prepare(
        'INSERT INTO blocked_identifiers (identifier_type, identifier_hash, source_user_id, reason, blocked_until)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE source_user_id = VALUES(source_user_id), reason = VALUES(reason),
            blocked_until = VALUES(blocked_until), created_at = UTC_TIMESTAMP()'
    );
    $currentAppHash = appDeviceHash();
    if ($currentAppHash) {
        $upsert->execute(['app', $currentAppHash, $userId, $reason, $until]);
    }
    foreach ($rows as $device) {
        $upsert->execute(['device', $device['device_token_hash'], $userId, $reason, $until]);
        $upsert->execute(['fingerprint', $device['fingerprint_hash'], $userId, $reason, $until]);
        if (!empty($device['app_hash'])) {
            $upsert->execute(['app', $device['app_hash'], $userId, $reason, $until]);
        }
        $shared = $pdo->prepare('SELECT COUNT(DISTINCT user_id) FROM user_devices WHERE last_ip = ? AND user_id IS NOT NULL');
        $shared->execute([$device['last_ip']]);
        if ((int) $shared->fetchColumn() <= 1) {
            $upsert->execute(['ip', hash('sha256', $device['last_ip']), $userId, $reason, $until]);
        }
    }
    $pdo->prepare('UPDATE user_devices SET is_blocked = 1 WHERE user_id = ?')->execute([$userId]);
}

function unblockUserIdentities(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM blocked_identifiers WHERE source_user_id = ?')->execute([$userId]);
    $pdo->prepare('UPDATE user_devices SET is_blocked = 0 WHERE user_id = ?')->execute([$userId]);
}

function rateKey(string $scope, string $identity): string
{
    return $scope . ':' . hash('sha256', $identity);
}

function rateStatus(PDO $pdo, string $key): array
{
    $stmt = $pdo->prepare('SELECT attempts, window_started_at, blocked_until FROM rate_limits WHERE rate_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['attempts' => 0, 'blocked' => false, 'retry_after' => 0];
    }
    $blockedUntil = $row['blocked_until'] ? strtotime($row['blocked_until'] . ' UTC') : false;
    $retry = $blockedUntil ? max(0, $blockedUntil - time()) : 0;
    return ['attempts' => (int) $row['attempts'], 'blocked' => $retry > 0, 'retry_after' => $retry];
}

function assertNotRateLimited(PDO $pdo, string $key): void
{
    $status = rateStatus($pdo, $key);
    if ($status['blocked']) {
        throw new ApiError(429, 'RATE_LIMITED', 'Çok fazla deneme yapıldı. Lütfen daha sonra tekrar deneyin.', [
            'retry_after' => $status['retry_after'],
        ]);
    }
}

function recordFailedAttempt(PDO $pdo, string $key, int $maxAttempts, int $windowSeconds, int $blockSeconds): void
{
    // Tek atomik sorgu: satır kilidi tutulmaz, eşzamanlı denemelerde ölü kilit oluşmaz.
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
         VALUES (?, 1, UTC_TIMESTAMP(), NULL)
         ON DUPLICATE KEY UPDATE
            attempts = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), 1, attempts + 1),
            window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP(), window_started_at),
            blocked_until = IF(attempts >= ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), blocked_until)'
    );
    $stmt->execute([$key, $windowSeconds, $windowSeconds, $maxAttempts, $blockSeconds]);
}

function blockImmediately(PDO $pdo, string $key, int $seconds): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until) VALUES (?, 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND))
         ON DUPLICATE KEY UPDATE attempts = attempts + 1, window_started_at = UTC_TIMESTAMP(), blocked_until = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND)'
    );
    $stmt->execute([$key, $seconds, $seconds]);
}

function consumeRequestLimit(PDO $pdo, string $key, int $maxRequests, int $windowSeconds): void
{
    // Sayaç tek atomik sorguyla ilerletilir; FOR UPDATE kilidi ve işlem açılmaz.
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
         VALUES (?, 1, UTC_TIMESTAMP(), NULL)
         ON DUPLICATE KEY UPDATE
            attempts = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), 1, attempts + 1),
            window_started_at = IF(window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP(), window_started_at),
            blocked_until = NULL'
    );
    $stmt->execute([$key, $windowSeconds, $windowSeconds]);

    $status = $pdo->prepare('SELECT attempts, window_started_at FROM rate_limits WHERE rate_key = ?');
    $status->execute([$key]);
    $row = $status->fetch();
    $attempts = (int) ($row['attempts'] ?? 1);
    if ($attempts > $maxRequests) {
        $windowStart = isset($row['window_started_at']) ? strtotime($row['window_started_at'] . ' UTC') : time();
        throw new ApiError(429, 'RATE_LIMITED', 'İstek sınırı aşıldı. Lütfen kısa bir süre bekleyin.', [
            'retry_after' => max(1, $windowStart + $windowSeconds - time()),
        ]);
    }
}

/**
 * Okuma uçlarında veritabanına dokunmadan çalışan hafif koruma. Hızlı aç-kapa
 * ve yoklama fırtınalarını daha ilk adımda keser; normal kullanımda hiç devreye
 * girmez ve hiçbir sorgu maliyeti oluşturmaz.
 */
function sessionRateGuard(string $bucket, int $maxRequests, int $windowSeconds): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $now = time();
    $counters = $_SESSION['read_guard'] ?? [];
    if (!is_array($counters)) {
        $counters = [];
    }
    $entry = $counters[$bucket] ?? null;
    if (!is_array($entry) || ($now - (int) ($entry['started'] ?? 0)) >= $windowSeconds) {
        $entry = ['started' => $now, 'count' => 0];
    }
    $entry['count'] = (int) $entry['count'] + 1;
    $counters[$bucket] = $entry;
    if (count($counters) > 12) {
        $counters = array_slice($counters, -12, null, true);
    }
    $_SESSION['read_guard'] = $counters;

    if ($entry['count'] > $maxRequests) {
        throw new ApiError(429, 'RATE_LIMITED', 'Çok hızlı istek gönderiliyor. Lütfen bir an bekleyin.', [
            'retry_after' => max(1, (int) $entry['started'] + $windowSeconds - $now),
        ]);
    }
}

function clearRateLimit(PDO $pdo, string $key): void
{
    $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE rate_key = ?');
    $stmt->execute([$key]);
}

/**
 * Tüm hesabı kapsayan çok-yüksek hızlı okuma fırtınası koruması.
 * Ücretsiz hosting "hits" kotası aşıldığında hesap askıya alınır. Bu geçit,
 * GET/okuma yoklamalarının toplam hacmini dakika bazında sınırlayarak sunucu
 * ve hesap kotasını korur. Normal kullanımın çok üzerinde bir eşik kullanılır;
 * yalnızca bozuk/kaçak bir istemcinin veya yoklama çılgınlığının hesabı
 * düşürmesini engeller. İstemci Retry-After başlığına saygı gösterip yavaşlar.
 */
function consumeGlobalReadBudget(PDO $pdo, string $bucket, int $maxRequests, int $windowSeconds, int $cooldownSeconds): void
{
    // Anahtar, az sayıda genel kovası temsil eder; kimlik bazlı değildir.
    $key = 'global_read:' . hash('sha256', $bucket);
    // ONARILAN İKİ KUSUR:
    //  1) Kilit kendini uzatıyordu: eski ifade her istekte
    //     `blocked_until = NOW + cooldown` yazıyordu. Engel sürerken gelen her
    //     istek (istemcinin 60 sn'lik nabız turu bile) engeli 90 sn daha
    //     ileri atıyordu; yani kilit pratikte HİÇ bitmiyordu ve TEK bir bozuk
    //     istemci TÜM kullanıcıları kalıcı olarak dışarıda bırakıyordu.
    //  2) `window_started_at` NULL/bozuk olduğunda (ör. SQL ile içe aktarılmış
    //     eski satır) pencere ASLA sıfırlanmıyordu: sayaç sonsuza dek büyüyor,
    //     koşul her zaman doğru oluyor ve site kalıcı olarak kilitleniyordu.
    //
    // Yeni davranış: pencere dolduğunda sayaç VE engel birlikte sıfırlanır;
    // engel sürerken sayaç artmaz ve engel UZATILMAZ; NULL pencere "süresi
    // dolmuş" sayılır. Atamalar soldan sağa değerlendirildiği için sıra
    // önemlidir: attempts -> blocked_until -> window_started_at.
    $expired = 'window_started_at IS NULL OR window_started_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND)';
    $blocked = 'blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP()';
    $stmt = $pdo->prepare(
        'INSERT INTO rate_limits (rate_key, attempts, window_started_at, blocked_until)
         VALUES (?, 1, UTC_TIMESTAMP(), NULL)
         ON DUPLICATE KEY UPDATE
            attempts = IF(' . $expired . ', 1, IF(' . $blocked . ', attempts, attempts + 1)),
            blocked_until = IF(' . $expired . ', NULL,
                IF(' . $blocked . ', blocked_until,
                   IF(attempts >= ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), NULL))),
            window_started_at = IF(' . $expired . ', UTC_TIMESTAMP(), window_started_at)'
    );
    // 7 placeholder: key; (attempts) windowSeconds; (blocked_until) windowSeconds,
    // maxRequests, cooldownSeconds; (window_started_at) windowSeconds.
    $stmt->execute([$key, $windowSeconds, $windowSeconds, $maxRequests, $cooldownSeconds, $windowSeconds]);

    $status = $pdo->prepare('SELECT attempts, window_started_at, blocked_until FROM rate_limits WHERE rate_key = ?');
    $status->execute([$key]);
    $row = $status->fetch();
    $attempts = (int) ($row['attempts'] ?? 1);
    $now = time();
    $blockedUntil = isset($row['blocked_until']) ? strtotime($row['blocked_until'] . ' UTC') : false;
    if ($blockedUntil && $blockedUntil > $now) {
        throw new ApiError(429, 'HOST_LIMIT_REACHED', 'Sunucu kaynak koruması etkinleşti. İstekler otomatik olarak yavaşlatıldı.', [
            'retry_after' => max(1, $blockedUntil - $now),
        ]);
    }
    if ($attempts > $maxRequests) {
        $windowStart = isset($row['window_started_at']) ? strtotime($row['window_started_at'] . ' UTC') : $now;
        throw new ApiError(429, 'HOST_LIMIT_REACHED', 'Sunucu kaynak koruması etkinleşti. İstekler otomatik olarak yavaşlatıldı.', [
            'retry_after' => max(1, $windowStart + $windowSeconds - $now),
        ]);
    }
}

/**
 * Açık erişim kodunu üretir: 4 grup x 4 karakter, karışık harf/rakam.
 * 0/O, 1/I/L gibi birbirine karışan karakterler hariçtir. Her AÇILIŞTA
 * yeniden üretilir (eski kodun sızması halinde otomatik geçersiz kalır).
 */
/**
 * Platform ayarları deposu. Tüm ayarlar istek başına bir kez, tek sorguyla
 * önbelleğe alınır; aynı istek içindeki sonraki okumalar DB'sizdir.
 * Okuma ve yazma ayrı API'lerdir: okuma asla yazmaz, yazma önbelleği taze
 * tutar — böylece aynı istekte okuyan taraf yeni değeri görür.
 */
final class PlatformSettingsStore
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    public static function get(PDO $pdo, string $key, string $default = ''): string
    {
        if (self::$cache === null) {
            try {
                $rows = [];
                $stmt = $pdo->query('SELECT setting_key, setting_value FROM platform_settings');
                foreach ($stmt->fetchAll() as $row) {
                    $rows[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
                }
                self::$cache = $rows;
            } catch (Throwable $error) {
                // Önbellek tutma; bir sonraki çağrıda yeniden dene.
                error_log('[platform] settings read skipped: ' . $error->getMessage());
                return $default;
            }
        }
        return (string) (self::$cache[$key] ?? $default);
    }

    public static function set(PDO $pdo, string $key, string $value): string
    {
        $pdo->prepare(
            'INSERT INTO platform_settings (setting_key, setting_value) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
        if (self::$cache === null) {
            self::$cache = [];
        }
        self::$cache[$key] = $value;
        return $value;
    }
}

/** Salt okuyucu platform ayarı erişimi. */
/** Kalıcı platform ayarı yazımı. */
/**
 * Açık erişim kodu durumu: aktif kod + anahtar durumu.
 * Bozuk/eksik tablo istekleri asla boğmaz: okuma hatası "kapalı + kod yok"
 * sayılır (fail-safe).
 */
function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function repairLegacyTurkishText(?string $value): ?string
{
    if ($value === null || $value === '') {
        return $value;
    }
    $value = strtr($value, [
        'Ho? geldin' => 'Hoş geldin',
        'Ho? ' => 'Hoş ',
        'Astrofoto?raf' => 'Astrofotoğraf',
        'astrofoto?raf' => 'astrofotoğraf',
        'Foto?rafçılık' => 'Fotoğrafçılık',
        'foto?rafçılık' => 'fotoğrafçılık',
        'Foto?raf' => 'Fotoğraf',
        'foto?raf' => 'fotoğraf',
        'G?ky?z?' => 'Gökyüzü',
        'g?ky?z?' => 'gökyüzü',
        'İ?leme' => 'İşleme',
        'i?leme' => 'işleme',
        'I?ık' => 'Işık',
        'ı?ık' => 'ışık',
        'Ba?arılı' => 'Başarılı',
        'ba?arılı' => 'başarılı',
        'G?zel' => 'Güzel',
        'g?zel' => 'güzel',
        'ÄŸ' => 'ğ',
        'Äž' => 'Ğ',
        'ÅŸ' => 'ş',
        'Åž' => 'Ş',
        'Ä±' => 'ı',
        'Ä°' => 'İ',
        'Ã¶' => 'ö',
        'Ã–' => 'Ö',
        'Ã¼' => 'ü',
        'Ãœ' => 'Ü',
        'Ã§' => 'ç',
        'Ã‡' => 'Ç',
    ]);
    $value = preg_replace('/\x{FFFD}+/u', '…', $value) ?? $value;
    return $value;
}

function cleanText(mixed $value, string $field, int $max, bool $required = true): string
{
    if (!is_string($value)) {
        throw new ApiError(422, 'VALIDATION_ERROR', $field . ' metin olmalıdır.');
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        throw new ApiError(422, 'INVALID_UTF8', $field . ' geçersiz karakter kodlaması içeriyor.');
    }
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $value = $normalized;
        }
    }
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? $value;
    $value = trim($value);
    if ($required && $value === '') {
        throw new ApiError(422, 'VALIDATION_ERROR', $field . ' boş bırakılamaz.');
    }
    if (textLength($value) > $max) {
        throw new ApiError(422, 'VALIDATION_ERROR', $field . ' en fazla ' . $max . ' karakter olabilir.');
    }
    return $value;
}

function validatedUsername(mixed $value): string
{
    $username = cleanText($value, 'Kullanıcı adı', 24);
    if (textLength($username) < 3) {
        throw new ApiError(422, 'INVALID_USERNAME', 'Kullanıcı adı en az 3 karakter olmalıdır.');
    }
    if (!preg_match('/\A[\p{L}\p{N}_]+\z/u', $username)) {
        throw new ApiError(422, 'INVALID_USERNAME', 'Kullanıcı adı yalnızca harf, rakam ve alt çizgi içerebilir; boşluk içeremez.');
    }
    return $username;
}

function validatePassword(mixed $password, mixed $confirmation): string
{
    if (!is_string($password) || !is_string($confirmation)) {
        throw new ApiError(422, 'INVALID_PASSWORD', 'Şifre alanları geçersiz.');
    }
    if (!hash_equals($password, $confirmation)) {
        throw new ApiError(422, 'PASSWORD_MISMATCH', 'Şifreler eşleşmiyor.');
    }
    if (strlen($password) < 10 || strlen($password) > 128) {
        throw new ApiError(422, 'INVALID_PASSWORD', 'Şifre 10 ile 128 karakter arasında olmalıdır.');
    }
    if (preg_match('/\s/u', $password)) {
        throw new ApiError(422, 'INVALID_PASSWORD', 'Şifre boşluk içeremez.');
    }
    if (!preg_match('/\p{L}/u', $password) || !preg_match('/\p{N}/u', $password) || !preg_match('/[^\p{L}\p{N}]/u', $password)) {
        throw new ApiError(422, 'INVALID_PASSWORD', 'Şifre en az bir harf, bir rakam ve bir özel karakter içermelidir.');
    }
    return $password;
}

function passwordAlgorithm(): string|int
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function avatarColor(): string
{
    $colors = ['#5B6CF9', '#7C4DFF', '#009688', '#E85D75', '#F08C46', '#2E86DE', '#8E5CBA', '#2D9C6F'];
    return $colors[random_int(0, count($colors) - 1)];
}

function nextHourIso(): string
{
    $next = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour')->setTime((int) gmdate('H', time() + 3600), 0, 0);
    return $next->format(DateTimeInterface::ATOM);
}

function cleanupExpiredBans(PDO $pdo): void
{
    $pdo->exec('DELETE FROM banned_users WHERE banned_until IS NOT NULL AND banned_until <= UTC_TIMESTAMP()');
    $pdo->exec('DELETE FROM blocked_identifiers WHERE blocked_until IS NOT NULL AND blocked_until <= UTC_TIMESTAMP()');
    $pdo->exec(
        'UPDATE user_devices d LEFT JOIN banned_users b ON b.user_id = d.user_id
         SET d.is_blocked = 0
         WHERE d.user_id IS NOT NULL AND b.id IS NULL'
    );
}

function safeDeleteImage(?string $relativePath): void
{
    $media = parseMediaPath($relativePath);
    if ($media !== null) {
        try {
            $stmt = db()->prepare('DELETE FROM media_files WHERE id = ? AND access_token = ?');
            $stmt->execute([$media['id'], $media['token']]);
        } catch (Throwable $error) {
            error_log('[media] delete skipped: ' . $error->getMessage());
        }
        return;
    }
    if (!$relativePath || !preg_match('#\Auploads/[a-f0-9]{32}\.(?:jpg|png|gif|webp)\z#', $relativePath)) {
        return;
    }
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function finalizeScheduledMessageDeletes(PDO $pdo, int $limit = 500): int
{
    $limit = max(1, min($limit, 5000));
    $stmt = $pdo->query(
        'SELECT id, image_path FROM messages
         WHERE deleted_at IS NOT NULL AND deleted_at <= UTC_TIMESTAMP()
         ORDER BY deleted_at ASC LIMIT ' . $limit
    );
    $scheduled = $stmt->fetchAll();
    if (!$scheduled) {
        return 0;
    }
    $ids = array_column($scheduled, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare('DELETE FROM messages WHERE id IN (' . $placeholders . ') AND deleted_at <= UTC_TIMESTAMP()');
    $delete->execute($ids);
    $deletedCount = $delete->rowCount();
    $survivors = $pdo->prepare('SELECT id FROM messages WHERE id IN (' . $placeholders . ')');
    $survivors->execute($ids);
    $stillPresent = array_flip(array_map('intval', $survivors->fetchAll(PDO::FETCH_COLUMN)));
    foreach ($scheduled as $message) {
        if (!isset($stillPresent[(int) $message['id']])) {
            safeDeleteImage($message['image_path']);
        }
    }
    return $deletedCount;
}

function cleanupOrphanAnnouncements(PDO $pdo): void
{
    $pdo->exec(
        'DELETE a FROM announcements a
         LEFT JOIN messages m ON m.announcement_id = a.id
         WHERE m.id IS NULL'
    );
}

function cleanupExpiredMessages(PDO $pdo, int $limit = 500): int
{
    finalizeScheduledMessageDeletes($pdo, $limit);
    $limit = max(1, min($limit, 5000));
    $stmt = $pdo->query(
        'SELECT id, image_path FROM messages
         WHERE created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
         ORDER BY id ASC LIMIT ' . $limit
    );
    $expired = $stmt->fetchAll();
    if (!$expired) {
        cleanupOrphanAnnouncements($pdo);
        return 0;
    }
    $ids = array_column($expired, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $delete = $pdo->prepare('DELETE FROM messages WHERE id IN (' . $placeholders . ')');
    $delete->execute($ids);
    foreach ($expired as $message) {
        safeDeleteImage($message['image_path']);
    }
    cleanupOrphanAnnouncements($pdo);
    return $delete->rowCount();
}

/**
 * Formlar ve yorumlari da oda mesajlariyla AYNI saklama politikasina tabidir
 * (MESSAGE_RETENTION_HOURS = 24). Vadesi dolan formlar begenileri ve
 * yorumlariyla birlikte kaldirilir; yorum begenileri de yorumlariyla gider.
 * messages_cleanup ile ayni bakim dongusunde calisir.
 */
function cleanupExpiredForms(PDO $pdo, int $limit = 100): int
{
    $deadline = 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)';
    $formIds = $pdo->prepare('SELECT id FROM info_forms WHERE created_at < ' . $deadline . ' ORDER BY id ASC LIMIT ' . (int) $limit);
    $formIds->execute();
    $ids = array_map('intval', $formIds->fetchAll(PDO::FETCH_COLUMN));
    // Guvenlik agi: yalniz kalmis begeni satirlari (yarida kesilen silme veya
    // eski surum artigi olabilir) her zaman supurulur.
    $pdo->exec('DELETE rl FROM info_form_reply_likes rl LEFT JOIN info_form_replies r ON r.id = rl.reply_id WHERE r.id IS NULL');
    $pdo->exec('DELETE fl FROM info_form_likes fl LEFT JOIN info_forms f ON f.id = fl.form_id WHERE f.id IS NULL');
    if ($ids === []) {
        return 0;
    }
    $placeholders = implode(', ', array_fill(0, count($ids), '?'));
    // Yorum begenileri -> yorumlar -> form begenileri -> formlar.
    $pdo->prepare(
        'DELETE FROM info_form_reply_likes WHERE reply_id IN (SELECT id FROM info_form_replies WHERE form_id IN (' . $placeholders . '))'
    )->execute($ids);
    $pdo->prepare('DELETE FROM info_form_replies WHERE form_id IN (' . $placeholders . ')')->execute($ids);
    $pdo->prepare('DELETE FROM info_form_likes WHERE form_id IN (' . $placeholders . ')')->execute($ids);
    $pdo->prepare('DELETE FROM info_forms WHERE id IN (' . $placeholders . ')')->execute($ids);
    return count($ids);
}


function activeBan(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT reason, banned_until, banned_at FROM banned_users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $ban = $stmt->fetch();
    if (!$ban) {
        return null;
    }
    if ($ban['banned_until'] !== null && strtotime($ban['banned_until'] . ' UTC') <= time()) {
        $delete = $pdo->prepare('DELETE FROM banned_users WHERE user_id = ?');
        $delete->execute([$userId]);
        return null;
    }
    return [
        'reason' => repairLegacyTurkishText($ban['reason']),
        'permanent' => $ban['banned_until'] === null,
        'banned_until' => $ban['banned_until'] ? gmdate(DateTimeInterface::ATOM, strtotime($ban['banned_until'] . ' UTC')) : null,
        'banned_at' => gmdate(DateTimeInterface::ATOM, strtotime($ban['banned_at'] . ' UTC')),
    ];
}

function enforceCoinDebtPolicy(PDO $pdo, array $user): void
{
    $userId = (int) $user['id'];
    $balance = coinAmount($user['aa_coins'] ?? 0);
    $dueAt = $user['coin_debt_due_at'] ?? null;
    if ($balance >= 0 && $dueAt !== null) {
        $pdo->prepare('UPDATE users SET coin_debt_due_at = NULL, coin_debt_notice_at = NULL WHERE id = ?')->execute([$userId]);
        return;
    }
    if ($balance >= 0 || $dueAt === null) {
        return;
    }
    $dueTimestamp = strtotime($dueAt . ' UTC');
    if ($dueTimestamp === false || $dueTimestamp > time()) {
        return;
    }
    $existing = activeBan($pdo, $userId);
    if ($existing) {
        return;
    }
    $reason = 'Yargıç Bot: AA Coin borcu 1 ay içinde ödenmediği için 15 gün sistem yasağı.';
    $until = gmdate('Y-m-d H:i:s', time() + 15 * 86400);
    $stmt = $pdo->prepare(
        'INSERT INTO banned_users (user_id, reason, banned_until, banned_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_until = VALUES(banned_until), banned_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([$userId, $reason, $until]);
    blockUserIdentities($pdo, $userId, $reason, $until);
    $pdo->prepare('UPDATE users SET online_status = 0 WHERE id = ?')->execute([$userId]);
}

function parseCoinAmount(mixed $value, string $field): float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }
    if (!is_numeric($value)) {
        throw new ApiError(422, 'INVALID_COIN_AMOUNT', $field . ' geçerli bir sayı olmalıdır.');
    }
    $amount = round((float) $value, 1);
    if ($amount <= 0 || $amount > 1000000) {
        throw new ApiError(422, 'INVALID_COIN_AMOUNT', $field . ' 0.1 ile 1.000.000 arasında olmalıdır.');
    }
    return $amount;
}

function requireUser(PDO $pdo): array
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        throw new ApiError(401, 'AUTH_REQUIRED', 'Oturum açmanız gerekiyor.');
    }

    // Kullanıcı ve yasak bilgisi tek sorguda alınır (istek başına bir sorgu tasarrufu).
    $stmt = $pdo->prepare(
        'SELECT u.*, b.reason AS active_ban_reason, b.banned_until AS active_ban_until, b.banned_at AS active_ban_at
         FROM users u LEFT JOIN banned_users b ON b.user_id = u.id
         WHERE u.id = ? LIMIT 1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        $deleted = $pdo->prepare('SELECT reason, deleted_at FROM deleted_users_log WHERE user_id = ? ORDER BY deleted_at DESC LIMIT 1');
        $deleted->execute([$userId]);
        $deletedInfo = $deleted->fetch();
        if ($deletedInfo) {
            $approvalBlock = activeApprovalBlock($pdo, null);
            $reasonText = repairLegacyTurkishText($deletedInfo['reason']) ?: 'Hesabın silindi.';
            $deletedMessage = $approvalBlock ? (string) $approvalBlock['message'] : $reasonText;
            throw new ApiError(410, 'ACCOUNT_DELETED', $deletedMessage, [
                'reason' => $approvalBlock ? $approvalBlock['message'] : $reasonText,
                'deleted_at' => gmdate(DateTimeInterface::ATOM, strtotime($deletedInfo['deleted_at'] . ' UTC')),
                'approval_block' => $approvalBlock,
            ]);
        }
        unset($_SESSION['user_id'], $_SESSION['admin_authenticated_until']);
        throw new ApiError(401, 'AUTH_REQUIRED', 'Oturum geçersiz.');
    }

    enforceCoinDebtPolicy($pdo, $user);

    $currentSessionVersion = (int) ($user['session_version'] ?? 1);
    if (!isset($_SESSION['session_version'])) {
        $_SESSION['session_version'] = $currentSessionVersion;
    } elseif ((int) $_SESSION['session_version'] !== $currentSessionVersion) {
        unset($_SESSION['user_id'], $_SESSION['session_version'], $_SESSION['admin_authenticated_until']);
        throw new ApiError(401, 'SESSION_REVOKED', 'Yetkili tarafından şifreniz değiştirildi, oturum açmalısınız.');
    }

    $ban = null;
    if ($user['active_ban_reason'] !== null) {
        $banUntil = $user['active_ban_until'];
        if ($banUntil !== null && strtotime($banUntil . ' UTC') <= time()) {
            $pdo->prepare('DELETE FROM banned_users WHERE user_id = ?')->execute([$userId]);
        } else {
            $ban = [
                'reason' => repairLegacyTurkishText($user['active_ban_reason']),
                'permanent' => $banUntil === null,
                'banned_until' => $banUntil ? gmdate(DateTimeInterface::ATOM, strtotime($banUntil . ' UTC')) : null,
                'banned_at' => gmdate(DateTimeInterface::ATOM, strtotime($user['active_ban_at'] . ' UTC')),
            ];
        }
    }
    unset($user['active_ban_reason'], $user['active_ban_until'], $user['active_ban_at']);
    if ($ban) {
        $_SESSION['blocked_user_id'] = (int) $userId;
        throw new ApiError(403, 'ACCOUNT_BANNED', 'Yasaklandın.', ['ban' => $ban]);
    }

    if (($user['approval_status'] ?? 'approved') === 'pending') {
        throw new ApiError(403, 'APPROVAL_PENDING', 'Hesabın yönetici onayı bekliyor.', [
            'submitted' => !empty($user['approval_reason']),
            'submitted_at' => !empty($user['approval_submitted_at']) ? gmdate(DateTimeInterface::ATOM, strtotime($user['approval_submitted_at'] . ' UTC')) : null,
        ]);
    }


    if (time() - (int) ($_SESSION['last_touch'] ?? 0) >= 30) {
        assertDeviceMayUseAccount($pdo, (int) $userId);
        $touch = $pdo->prepare(
            'UPDATE users
             SET total_active_seconds = total_active_seconds + IF(last_activity_counted_at IS NOT NULL AND TIMESTAMPDIFF(SECOND, last_activity_counted_at, UTC_TIMESTAMP()) BETWEEN 1 AND 120, TIMESTAMPDIFF(SECOND, last_activity_counted_at, UTC_TIMESTAMP()), 0),
                 last_activity_counted_at = UTC_TIMESTAMP(), last_seen = UTC_TIMESTAMP(), online_status = 1
             WHERE id = ?'
        );
        $countedSeconds = 0;
        if (!empty($user['last_activity_counted_at'])) {
            $delta = time() - strtotime($user['last_activity_counted_at'] . ' UTC');
            if ($delta >= 1 && $delta <= 120) {
                $countedSeconds = $delta;
            }
        }
        $touch->execute([$userId]);
        if ($countedSeconds > 0) {
            $daily = $pdo->prepare(
                'INSERT INTO aa_daily_activity (user_id, activity_date, active_seconds, updated_at)
                 VALUES (?, UTC_DATE(), ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE active_seconds = active_seconds + VALUES(active_seconds), updated_at = UTC_TIMESTAMP()'
            );
            $daily->execute([$userId, $countedSeconds]);
        }
        rememberDeviceForUser($pdo, (int) $userId, $user['username']);
        $_SESSION['last_touch'] = time();
    }
    return $user;
}

function requireAdmin(PDO $pdo): array
{
    $user = requireUser($pdo);
    if ((int) ($_SESSION['admin_authenticated_until'] ?? 0) <= time()) {
        unset($_SESSION['admin_authenticated_until']);
        throw new ApiError(403, 'ADMIN_REQUIRED', 'Admin doğrulaması gerekiyor.');
    }
    return $user;
}

function coinAmount(mixed $value): float
{
    return round((float) $value, 1);
}

function publicUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => repairLegacyTurkishText($user['username']),
        'avatar_color' => $user['avatar_color'],
        'profile_image_path' => $user['profile_image_path'] ?? null,
        'theme' => $user['theme'],
        'language' => $user['language'],
        'show_read_receipts' => (bool) $user['show_read_receipts'],
        'show_last_seen' => (bool) $user['show_last_seen'],
        'about' => repairLegacyTurkishText($user['about']),
        'birthday' => $user['birthday'],
        'aa_coins' => coinAmount($user['aa_coins'] ?? 0),
        'active_profile_frame' => $user['active_profile_frame'] ?? null,
        'total_active_seconds' => (int) ($user['total_active_seconds'] ?? 0),
        'coin_debt_due_at' => isset($user['coin_debt_due_at']) && $user['coin_debt_due_at'] ? gmdate(DateTimeInterface::ATOM, strtotime($user['coin_debt_due_at'] . ' UTC')) : null,
        'coin_debt_notice_at' => isset($user['coin_debt_notice_at']) && $user['coin_debt_notice_at'] ? gmdate(DateTimeInterface::ATOM, strtotime($user['coin_debt_notice_at'] . ' UTC')) : null,
        'approval_status' => $user['approval_status'] ?? 'approved',
        'approval_decision_notice' => $user['approval_decision_notice'] ?? null,
        'approval_decision_at' => isset($user['approval_decision_at']) && $user['approval_decision_at'] ? gmdate(DateTimeInterface::ATOM, strtotime($user['approval_decision_at'] . ' UTC')) : null,
    ];
}

function canonicalRoomCatalog(): array
{
    return [
        0 => ['Bilgi Odası', 'Haberler, kaynaklar, dersler, çalışma kağıtları ve topluluk formları.'],
        1 => ['Asra Genel', 'Topluluk gündemi, duyurular ve genel sohbet.'],
        2 => ['Asra Astrofotoğraf', 'Gece gökyüzü çekimleri, ekipman ve işleme teknikleri.'],
        3 => ['Asra Astronomi', 'Gök cisimleri, uzay araştırmaları ve gözlem notları.'],
        4 => ['Asra Politika', 'Güncel siyaset ve kamu politikaları üzerine saygılı tartışmalar.'],
        5 => ['Asra Makale', 'Makaleler, denemeler ve uzun biçimli içerikler.'],
        6 => ['Asra Fotoğrafçılık', 'Fotoğraf teknikleri, kompozisyon ve ekipman konuşmaları.'],
        7 => ['Asra Coğrafya', 'Dünya, bölgeler, iklim ve insan coğrafyası.'],
        8 => ['Asra Antropoloji', 'İnsan, kültür ve toplumların bilimsel incelemesi.'],
        9 => ['Asra Spor', 'Spor dalları, karşılaşmalar ve performans.'],
        10 => ['Asra Teknoloji', 'Yazılım, donanım ve yeni teknolojiler.'],
        11 => ['Asra Sanat', 'Görsel sanatlar, müzik, edebiyat ve sahne sanatları.'],
        12 => ['Asra Matematik', 'Matematiksel problemler, kuramlar ve çözüm yöntemleri.'],
        13 => ['Asra Sosyoloji', 'Toplum, kurumlar ve sosyal ilişkiler.'],
        14 => ['Asra Psikoloji', 'Davranış, biliş ve psikoloji araştırmaları.'],
        15 => ['Asra Tarih', 'Geçmiş dönemler, olaylar ve tarih araştırmaları.'],
        16 => ['Asra Jeoloji', 'Yerküre, kayaçlar ve jeolojik süreçler.'],
        17 => ['Asra Fizik', 'Fizik kuramları, deneyler ve problem çözümleri.'],
        18 => ['Asra Kimya', 'Maddeler, tepkimeler ve kimya uygulamaları.'],
        19 => ['Asra Felsefe', 'Felsefi sorular, düşünürler ve argümanlar.'],
        20 => ['Asra Biyoloji', 'Canlılar, ekosistemler ve yaşam bilimleri.'],
    ];
}

function canonicalizeRoom(array $room, ?int $fallbackPosition = null): array
{
    $catalog = canonicalRoomCatalog();
    $roomId = (int) ($room['id'] ?? 0);
    $canonical = $catalog[$roomId] ?? ($fallbackPosition !== null ? ($catalog[$fallbackPosition] ?? null) : null);
    if ($canonical !== null) {
        $room['name'] = $canonical[0];
        $room['description'] = $canonical[1];
    }
    $room['id'] = $roomId;
    $room['is_info'] = $roomId === INFO_ROOM_ID;
    return $room;
}

function roomExists(PDO $pdo, int $roomId): array
{
    // Odalar sabittir; aynı oturumda tekrar tekrar sorgulanmaz.
    static $cache = [];
    if (isset($cache[$roomId])) {
        return $cache[$roomId];
    }
    $sessionCache = $_SESSION['room_cache'] ?? [];
    if (is_array($sessionCache) && isset($sessionCache[$roomId]) && is_array($sessionCache[$roomId])) {
        $cache[$roomId] = $sessionCache[$roomId];
        return $cache[$roomId];
    }
    $stmt = $pdo->prepare('SELECT id, name, description FROM rooms WHERE id = ?');
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();
    if (!$room) {
        throw new ApiError(404, 'ROOM_NOT_FOUND', 'Oda bulunamadı.');
    }
    $room = canonicalizeRoom($room);
    $cache[$roomId] = $room;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionCache = is_array($sessionCache) ? $sessionCache : [];
        $sessionCache[$roomId] = $room;
        $_SESSION['room_cache'] = array_slice($sessionCache, -25, null, true);
    }
    return $room;
}

function integerField(mixed $value, string $name): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if ($validated === false || $validated < 1) {
        throw new ApiError(422, 'VALIDATION_ERROR', $name . ' geçersiz.');
    }
    return (int) $validated;
}

function mediaPath(int $id, string $token): string
{
    return 'media:' . $id . ':' . $token;
}

function parseMediaPath(?string $path): ?array
{
    if (!is_string($path) || !preg_match('/\Amedia:(\d+):([a-f0-9]{32})\z/', $path, $matches)) {
        return null;
    }
    return ['id' => (int) $matches[1], 'token' => $matches[2]];
}

function storeMediaBlob(string $binary, string $mime, ?int $ownerUserId = null, ?string $originalName = null): string
{
    if ($binary === '') {
        throw new RuntimeException('Boş medya verisi kaydedilemez.');
    }
    $token = bin2hex(random_bytes(16));
    $stmt = db()->prepare('INSERT INTO media_files (access_token, owner_user_id, mime_type, original_name, byte_size, content) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->bindValue(1, $token);
    if ($ownerUserId === null) $stmt->bindValue(2, null, PDO::PARAM_NULL); else $stmt->bindValue(2, $ownerUserId, PDO::PARAM_INT);
    $stmt->bindValue(3, $mime);
    $stmt->bindValue(4, $originalName === null ? null : substr($originalName, 0, 180));
    $stmt->bindValue(5, strlen($binary), PDO::PARAM_INT);
    $stmt->bindValue(6, $binary, PDO::PARAM_LOB);
    $stmt->execute();
    return mediaPath((int) db()->lastInsertId(), $token);
}

function storeUploadedImage(): ?string
{
    if (!isset($_FILES['image']) || (int) $_FILES['image']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $file = $_FILES['image'];
    if (is_array($file['name'] ?? null)) {
        throw new ApiError(422, 'UPLOAD_FAILED', 'Tek seferde yalnızca bir resim yüklenebilir.');
    }
    $originalName = (string) ($file['name'] ?? '');
    if (preg_match('/\.(?:php[0-9]?|phtml|phar|cgi|pl|py|sh)(?:\.|$)/i', $originalName)) {
        throw new ApiError(415, 'INVALID_IMAGE_TYPE', 'Dosya adı çalıştırılabilir uzantı içeremez.');
    }
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new ApiError(422, 'UPLOAD_FAILED', 'Resim yüklenemedi. Lütfen yeniden deneyin.');
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > MAX_IMAGE_BYTES) {
        throw new ApiError(413, 'IMAGE_TOO_LARGE', 'Resim en fazla 5 MB olabilir.');
    }
    $temporaryPath = (string) ($file['tmp_name'] ?? '');
    if (!is_uploaded_file($temporaryPath)) {
        throw new ApiError(422, 'UPLOAD_FAILED', 'Geçersiz dosya yüklemesi.');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($allowed[$mime])) {
        throw new ApiError(415, 'INVALID_IMAGE_TYPE', 'Yalnızca JPG, PNG ve WEBP resimleri kabul edilir.');
    }
    $dimensions = @getimagesize($temporaryPath);
    if (!$dimensions || ($dimensions['mime'] ?? '') !== $mime) {
        throw new ApiError(415, 'INVALID_IMAGE', 'Dosya geçerli bir resim değil.');
    }
    $width = (int) $dimensions[0];
    $height = (int) $dimensions[1];
    if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || ($width * $height) > 25_000_000) {
        throw new ApiError(422, 'INVALID_IMAGE_DIMENSIONS', 'Resim boyutları desteklenen sınırların dışında.');
    }

    $maxSide = 1600;
    // GD ile yeniden boyutlandırma bellek ister. Kalan bellek yetmiyorsa iyileştirme
    // atlanır; süreç ölümcül hata (500) yerine dosyayı olduğu gibi kaydeder.
    $memoryLimit = trim((string) ini_get('memory_limit'));
    $limitBytes = -1;
    if ($memoryLimit !== '' && $memoryLimit !== '-1') {
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        $limitBytes = match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
    $estimatedNeed = (int) ($width * $height * 5.2) + (int) (min($maxSide, $width) * min($maxSide, $height) * 5.2);
    $memoryAvailable = $limitBytes < 0 || ($limitBytes - memory_get_usage(true)) > ($estimatedNeed + 4 * 1024 * 1024);
    $canOptimize = $memoryAvailable
        && function_exists('imagecreatetruecolor')
        && (($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg'))
            || ($mime === 'image/png' && function_exists('imagecreatefrompng'))
            || ($mime === 'image/webp' && function_exists('imagecreatefromwebp')));

    if ($canOptimize) {
        $source = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($temporaryPath),
            'image/png' => @imagecreatefrompng($temporaryPath),
            'image/webp' => @imagecreatefromwebp($temporaryPath),
            default => false,
        };
        if ($source !== false) {
            $scale = min(1.0, $maxSide / max($width, $height));
            $targetWidth = max(1, (int) round($width * $scale));
            $targetHeight = max(1, (int) round($height * $scale));
            $target = imagecreatetruecolor($targetWidth, $targetHeight);
            imagealphablending($target, false);
            imagesavealpha($target, true);
            $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
            imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);
            imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
            $optimizedExtension = function_exists('imagewebp') ? 'webp' : ($mime === 'image/png' ? 'png' : 'jpg');
            $destination = tempnam(sys_get_temp_dir(), 'asra_media_');
            if ($destination === false) {
                $destination = sys_get_temp_dir() . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16));
            }
            $written = $optimizedExtension === 'webp'
                ? imagewebp($target, $destination, 82)
                : ($optimizedExtension === 'png' ? imagepng($target, $destination, 6) : imagejpeg($target, $destination, 84));
            imagedestroy($target);
            imagedestroy($source);
            if ($written && is_file($destination) && filesize($destination) > 0) {
                $optimizedBinary = file_get_contents($destination);
                @unlink($destination);
                if (is_string($optimizedBinary) && $optimizedBinary !== '') {
                    $storedMime = $optimizedExtension === 'webp' ? 'image/webp' : ($optimizedExtension === 'png' ? 'image/png' : 'image/jpeg');
                    return storeMediaBlob($optimizedBinary, $storedMime, filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT) ?: null, $originalName);
                }
            }
            if (is_file($destination)) {
                @unlink($destination);
            }
        }
    }

    $binary = file_get_contents($temporaryPath);
    if (!is_string($binary) || $binary === '') {
        throw new RuntimeException('Yüklenen dosya okunamadı.');
    }
    return storeMediaBlob($binary, $mime, filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT) ?: null, $originalName);
}

function deleteUserPermanently(PDO $pdo, int $userId, string $reason, ?string $expectedApprovalStatus = null): void
{
    $pdo->beginTransaction();
    try {
        $userStmt = $pdo->prepare('SELECT username, profile_image_path, approval_status FROM users WHERE id = ? FOR UPDATE');
        $userStmt->execute([$userId]);
        $target = $userStmt->fetch();
        if (!$target) {
            throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.');
        }
        if ($expectedApprovalStatus !== null && ($target['approval_status'] ?? null) !== $expectedApprovalStatus) {
            throw new ApiError(409, 'APPROVAL_STATE_CHANGED', 'Hesabın onay durumu başka bir işlem tarafından değiştirildi. Liste yenileniyor.');
        }
        $imagesStmt = $pdo->prepare('SELECT image_path FROM messages WHERE user_id = ? AND image_path IS NOT NULL');
        $imagesStmt->execute([$userId]);
        $images = $imagesStmt->fetchAll(PDO::FETCH_COLUMN);
        if ($target['profile_image_path']) {
            $images[] = $target['profile_image_path'];
        }

        $pdo->prepare('DELETE mv FROM message_views mv INNER JOIN messages m ON m.id = mv.message_id WHERE m.user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM message_views WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE md FROM message_deletions md INNER JOIN messages m ON m.id = md.message_id WHERE m.user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM message_deletions WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM messages WHERE user_id = ?')->execute([$userId]);
        $pdo->prepare("DELETE FROM messages WHERE user_id IS NULL AND type = 'system' AND content = ?")->execute(['Hoş geldin ' . $target['username']]);

        $log = $pdo->prepare(
            'INSERT INTO deleted_users_log (user_id, username, reason) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), deleted_at = UTC_TIMESTAMP()'
        );
        $log->execute([$userId, $target['username'], $reason]);
        $pdo->prepare('DELETE FROM blocked_identifiers WHERE source_user_id = ?')->execute([$userId]);
        $pdo->prepare('DELETE FROM user_devices WHERE user_id = ?')->execute([$userId]);
        try {
            $pdo->prepare('DELETE FROM auth_remember_tokens WHERE user_id = ?')->execute([$userId]);
        } catch (PDOException $error) {
            error_log('[remember] cleanup skipped: ' . $error->getMessage());
        }
        $delete = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $delete->execute([$userId]);
        $pdo->commit();

        foreach ($images as $path) {
            safeDeleteImage($path);
        }
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

function isUserInactive(array $user): bool
{
    $stamp = $user['last_seen'] ?? null;
    if (!is_string($stamp) || $stamp === '' || str_starts_with($stamp, '0000-00-00')) {
        return false;
    }
    $ts = strtotime($stamp . ' UTC');
    if ($ts === false) {
        $ts = strtotime($stamp);
    }
    if ($ts === false) {
        return false;
    }
    $limit = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-' . INACTIVE_ACCOUNT_MONTHS . ' months')->getTimestamp();
    return $ts <= $limit;
}

function purgeInactiveAccounts(PDO $pdo, int $limit = 20): int
{
    $limit = max(1, min(50, $limit));
    try {
        $stmt = $pdo->query(
            "SELECT id, username, last_seen, created_at FROM users
             WHERE last_seen IS NOT NULL
               AND last_seen > '1970-01-01 00:00:00'
               AND last_seen <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL " . (int) INACTIVE_ACCOUNT_MONTHS . " MONTH)
             ORDER BY last_seen ASC
             LIMIT " . (int) $limit
        );
        $rows = $stmt ? ($stmt->fetchAll() ?: []) : [];
        $count = 0;
        foreach ($rows as $row) {
            try {
                deleteUserPermanently($pdo, (int) $row['id'], INACTIVE_ACCOUNT_REASON);
                $count++;
            } catch (Throwable $error) {
                error_log('[inactive] purge skipped for user ' . $row['id'] . ': ' . $error->getMessage());
            }
        }
        return $count;
    } catch (Throwable $error) {
        error_log('[inactive] purge skipped: ' . $error->getMessage());
        return 0;
    }
}

function rememberApprovalBlock(PDO $pdo, string $username, string $type, string $message, int $days): void
{
    try {
        $identity = deviceIdentity();
        $stmt = $pdo->prepare('INSERT INTO approval_request_blocks (username, device_token_hash, fingerprint_hash, reason_type, message, blocked_until) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))');
        $stmt->execute([$username, $identity['token_hash'], $identity['fingerprint_hash'], $type, $message, $days]);
    } catch (PDOException $error) {
        error_log('[approval] block write skipped: ' . $error->getMessage());
    }
}

function rememberApprovalBlockForUser(PDO $pdo, int $userId, string $username, string $type, string $message, int $days): void
{
    $devices = $pdo->prepare('SELECT device_token_hash, fingerprint_hash FROM user_devices WHERE user_id = ?');
    $devices->execute([$userId]);
    $rows = $devices->fetchAll();
    if (!$rows) {
        rememberApprovalBlock($pdo, $username, $type, $message, $days);
        return;
    }
    $stmt = $pdo->prepare('INSERT INTO approval_request_blocks (username, device_token_hash, fingerprint_hash, reason_type, message, blocked_until) VALUES (?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))');
    foreach ($rows as $device) {
        $stmt->execute([$username, $device['device_token_hash'], $device['fingerprint_hash'], $type, $message, $days]);
    }
}

function activeApprovalBlock(PDO $pdo, ?string $username = null): ?array
{
    try {
        $identity = deviceIdentity();
        if (shouldRunMaintenance($pdo, 'approval_blocks', 900)) {
            $pdo->exec('DELETE FROM approval_request_blocks WHERE blocked_until <= UTC_TIMESTAMP()');
        }
        // KÖK DÜZELTME: Blok eşleşmesi YALNIZCA benzersiz kimliklerle yapılır:
        //   - device_token_hash: reddedilen kişinin kendi tarayıcısı (spam koruması)
        //   - username: aynı kullanıcı adı serinleme süresinde yeniden başvuru yapamaz
        // Fingerprint (ortak UA-dil imzası) EŞLEŞTİRMEDE KULLANILMAZ; aynı
        // tarayıcı/model/dile sahip alakasız diğer kişilerin başvurusunu 5 gün
        // "Reddedildin" mesajıyla engelliyordu.
        $stmt = $pdo->prepare(
            'SELECT reason_type, message, blocked_until FROM approval_request_blocks
             WHERE (device_token_hash = ? OR username = ?)
               AND blocked_until > UTC_TIMESTAMP()
             ORDER BY created_at DESC LIMIT 1'
        );
        $stmt->execute([$identity['token_hash'], $username ?? '']);
        $block = $stmt->fetch();
        if (!$block) return null;
        return [
            'type' => $block['reason_type'],
            'message' => repairLegacyTurkishText($block['message']),
            'blocked_until' => gmdate(DateTimeInterface::ATOM, strtotime($block['blocked_until'] . ' UTC')),
        ];
    } catch (PDOException $error) {
        error_log('[approval] block lookup skipped: ' . $error->getMessage());
        return null;
    }
}

function cleanupExpiredApprovals(PDO $pdo): void
{
    try {
        $stmt = $pdo->query("SELECT id, username FROM users WHERE approval_status = 'pending' AND approval_submitted_at IS NOT NULL AND approval_submitted_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 15 DAY) LIMIT 100");
        foreach ($stmt->fetchAll() as $row) {
            $_SESSION['approval_cleanup_user_id'] = (int) $row['id'];
            rememberApprovalBlockForUser($pdo, (int) $row['id'], $row['username'], 'expired', 'Başvurun belirlenen inceleme süresi içinde sonuçlanmadığı için sistem tarafından kapatıldı. 5 gün sonra yeniden başvuru gönderebilirsin.', 5);
            deleteUserPermanently($pdo, (int) $row['id'], 'Onay başvurusu 15 gün içinde yanıtlanmadığı için sistem tarafından kapatıldı.');
            unset($_SESSION['approval_cleanup_user_id']);
        }
    } catch (PDOException $error) {
        unset($_SESSION['approval_cleanup_user_id']);
        error_log('[approval] cleanup skipped: ' . $error->getMessage());
    }
}

function requireAnySessionUser(PDO $pdo): array
{
    $userId = filter_var($_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) throw new ApiError(401, 'AUTH_REQUIRED', 'Oturum açmanız gerekiyor.');
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) throw new ApiError(401, 'AUTH_REQUIRED', 'Oturum geçersiz.');
    return $user;
}


function consumeApprovalDecisionNotice(PDO $pdo, array $user): void
{
    if (($user['approval_decision_notice'] ?? null) !== 'accepted') {
        return;
    }
    try {
        $pdo->prepare("UPDATE users SET approval_decision_notice = NULL WHERE id = ? AND approval_decision_notice = 'accepted'")
            ->execute([(int) $user['id']]);
    } catch (Throwable $error) {
        error_log('[approval] notice consume skipped: ' . $error->getMessage());
    }
}

function actionSession(PDO $pdo): never
{
    requireMethod('GET');
    // Session yanıtı hızlı dönsün, kilidi hemen bırak
    // Not: requireUser çağrılmadığı için burada kapatmıyoruz ama DB sonrası kapatılabilir
    runMaintenanceSafely($pdo, 'approval_cleanup', 1800, static function (PDO $database): void {
        cleanupPrematureWelcomeMessages($database);
        cleanupExpiredApprovals($database);
    });
    runMaintenanceSafely($pdo, 'inactive_accounts', 3600, static function (PDO $database): void {
        purgeInactiveAccounts($database, 10);
    });
    $deviceState = deviceAccountState($pdo);
    $payload = [
        'csrf_token' => $_SESSION['csrf_token'],
        'authenticated' => false,
        'device_has_account' => $deviceState['has_account'],
        'bound_username' => $deviceState['username'],
        'approval_block' => activeApprovalBlock($pdo, $deviceState['username']),
        // Arayüz geri sayım metnini sabit "24 saat" diye yazıyordu; kural
        // sunucuda tek yerde tutulur ve istemciye buradan bildirilir.
        'message_retention_hours' => MESSAGE_RETENTION_HOURS,
    ];
    if (!empty($_SESSION['user_id'])) {
        try {
            $user = requireUser($pdo);
            $payload['authenticated'] = true;
            $payload['user'] = publicUser($user);
            $payload['admin_authenticated'] = (int) ($_SESSION['admin_authenticated_until'] ?? 0) > time();
            // Hit koruması kalıcıdır: istemci (web) yoklama aralıklarını bu
            // bayrağa göre kalıcı olarak yavaş ritimde tutar.
            $payload['hit_economy'] = true;
            consumeApprovalDecisionNotice($pdo, $user);
        } catch (ApiError $error) {
            if ($error->errorCode === 'APPROVAL_PENDING') {
                $pending = requireAnySessionUser($pdo);
                $payload['approval_required'] = true;
                $payload['approval'] = $error->details;
                $payload['user'] = publicUser($pending);
                respond($payload);
            }
            if (in_array($error->errorCode, ['ACCOUNT_BANNED', 'ACCOUNT_DELETED', 'SESSION_REVOKED'], true)) {
                throw new ApiError($error->status, $error->errorCode, $error->getMessage(), $error->details + [
                    'csrf_token' => $_SESSION['csrf_token'],
                ]);
            }
            unset($_SESSION['user_id']);
        }
    }
    respond($payload);
}

function actionRegister(PDO $pdo, array $data): never
{
    consumeRequestLimit($pdo, rateKey('register', clientIp()), 5, 3600);
    assertDeviceAvailableForRegistration($pdo);
    $username = validatedUsername($data['username'] ?? null);
    $block = activeApprovalBlock($pdo, $username);
    if ($block) {
        throw new ApiError(429, 'APPROVAL_COOLDOWN', $block['message'], ['approval_block' => $block]);
    }
    $password = validatePassword($data['password'] ?? null, $data['password_confirm'] ?? null);
    $hash = password_hash($password, passwordAlgorithm());
    if ($hash === false) {
        throw new RuntimeException('Şifre güvenli biçimde işlenemedi.');
    }

    $pdo->beginTransaction();
    try {
        $userId = 0;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                $insert = $pdo->prepare(
                    "INSERT INTO users (username, password_hash, avatar_color, last_seen, online_status, approval_status)\n                     VALUES (?, ?, ?, UTC_TIMESTAMP(), 1, 'pending')"
                );
                $insert->execute([$username, $hash, avatarColor()]);
                $userId = (int) $pdo->lastInsertId();
                break;
            } catch (PDOException $error) {
                if ((string) $error->getCode() !== '23000') {
                    throw $error;
                }
                $existing = $pdo->prepare('SELECT 1 FROM users WHERE username = ?');
                $existing->execute([$username]);
                if ($existing->fetchColumn()) {
                    throw new ApiError(409, 'USERNAME_TAKEN', 'Bu kullanıcı adı zaten kullanılıyor.');
                }
                if ($attempt === 4) {
                    throw $error;
                }
            }
        }
        // Hoş geldin mesajı kayıt anında değil, yönetici onayından sonra eklenir.
        // Böylece reddedilen veya henüz onaylanmamış hesaplar topluluğa duyurulmaz.
        $initialReads = $pdo->prepare(
            'INSERT INTO room_reads (user_id, room_id, last_read_message_id, last_read_at)
             SELECT ?, r.id, COALESCE(MAX(m.id), 0), UTC_TIMESTAMP()
             FROM rooms r LEFT JOIN messages m ON m.room_id = r.id AND m.deleted_at IS NULL
             GROUP BY r.id'
        );
        $initialReads->execute([$userId]);
        rememberDeviceForUser($pdo, $userId, $username);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    rotateSessionId();
    $_SESSION['user_id'] = $userId;
    $_SESSION['session_version'] = 1;
    $_SESSION['last_touch'] = time();
    unset($_SESSION['admin_authenticated_until'], $_SESSION['blocked_user_id']);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $createdUser = $stmt->fetch();
    issueRememberToken($pdo, $userId, (int) ($createdUser['session_version'] ?? 1));
    respond([
        'message' => 'Hesabınız oluşturuldu. Katılma nedenini yazman gerekiyor.',
        'approval_required' => true,
        'approval' => ['submitted' => false, 'submitted_at' => null],
        'user' => publicUser($createdUser),
        'csrf_token' => $_SESSION['csrf_token'],
    ], 201);
}

function actionLogin(PDO $pdo, array $data): never
{
    // Daha önce hesabı olan kullanıcılar bu sürümde yeniden kayda zorlanmaz.
    $username = validatedUsername($data['username'] ?? null);
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $key = rateKey('login', clientIp() . '|' . strtolower($username));
    assertNotRateLimited($pdo, $key);

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if (!$user) {
        $deleted = $pdo->prepare('SELECT reason, deleted_at FROM deleted_users_log WHERE username = ? LIMIT 1');
        $deleted->execute([$username]);
        $deletedInfo = $deleted->fetch();
        if ($deletedInfo) {
            $approvalBlock = activeApprovalBlock($pdo, null);
            $reasonText = repairLegacyTurkishText($deletedInfo['reason']) ?: 'Hesabın silindi.';
            $deletedMessage = $approvalBlock ? (string) $approvalBlock['message'] : $reasonText;
            throw new ApiError(410, 'ACCOUNT_DELETED', $deletedMessage, [
                'reason' => $approvalBlock ? $approvalBlock['message'] : $reasonText,
                'deleted_at' => gmdate(DateTimeInterface::ATOM, strtotime($deletedInfo['deleted_at'] . ' UTC')),
                'approval_block' => $approvalBlock,
            ]);
        }
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        recordFailedAttempt($pdo, $key, 5, 900, 900);
        throw new ApiError(401, 'INVALID_CREDENTIALS', 'Kullanıcı adı veya şifre hatalı.');
    }
    if ($password === '' || !password_verify($password, $user['password_hash'])) {
        recordFailedAttempt($pdo, $key, 5, 900, 900);
        throw new ApiError(401, 'INVALID_CREDENTIALS', 'Kullanıcı adı veya şifre hatalı.');
    }
    if (($user['approval_status'] ?? 'approved') === 'pending') {
        clearRateLimit($pdo, $key);
        rotateSessionId();
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
        $_SESSION['last_touch'] = time();
        unset($_SESSION['admin_authenticated_until'], $_SESSION['blocked_user_id']);
        rememberDeviceForUser($pdo, (int) $user['id'], $user['username']);
        issueRememberToken($pdo, (int) $user['id'], (int) ($user['session_version'] ?? 1));
        respond([
            'message' => 'Giriş başarılı. Katılma nedenini yazman gerekiyor.',
            'approval_required' => true,
            'approval' => [
                'submitted' => !empty($user['approval_reason']),
                'submitted_at' => !empty($user['approval_submitted_at']) ? gmdate(DateTimeInterface::ATOM, strtotime($user['approval_submitted_at'] . ' UTC')) : null,
            ],
            'user' => publicUser($user),
            'csrf_token' => $_SESSION['csrf_token'],
        ]);
    }
    assertDeviceMayUseAccount($pdo, (int) $user['id']);
    $ban = activeBan($pdo, (int) $user['id']);
    if ($ban) {
        rememberDeviceForUser($pdo, (int) $user['id'], $user['username']);
        blockUserIdentities(
            $pdo,
            (int) $user['id'],
            $ban['reason'],
            $ban['banned_until'] ? gmdate('Y-m-d H:i:s', strtotime($ban['banned_until'])) : null
        );
        $_SESSION['blocked_user_id'] = (int) $user['id'];
        throw new ApiError(403, 'ACCOUNT_BANNED', 'Yasaklandın.', ['ban' => $ban]);
    }
    clearRateLimit($pdo, $key);
    if (password_needs_rehash($user['password_hash'], passwordAlgorithm())) {
        $rehash = password_hash($password, passwordAlgorithm());
        $updateHash = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $updateHash->execute([$rehash, $user['id']]);
    }
    rotateSessionId();
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['session_version'] = (int) ($user['session_version'] ?? 1);
    $_SESSION['last_touch'] = time();
    unset($_SESSION['admin_authenticated_until'], $_SESSION['blocked_user_id']);
    $pdo->prepare('UPDATE users SET last_seen = UTC_TIMESTAMP(), online_status = 1, last_activity_counted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$user['id']]);
    rememberDeviceForUser($pdo, (int) $user['id'], $user['username']);
    issueRememberToken($pdo, (int) $user['id'], (int) ($user['session_version'] ?? 1));
    respond(['message' => 'Giriş başarılı.', 'user' => publicUser($user)]);
}

function actionLogout(PDO $pdo): never
{
    forgetCurrentRememberToken($pdo);
    if (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare('UPDATE users SET online_status = 0, last_seen = UTC_TIMESTAMP() WHERE id = ?');
        $stmt->execute([(int) $_SESSION['user_id']]);
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
    }
    session_destroy();
    respond(['message' => 'Oturum kapatıldı.']);
}

function actionGetRooms(PDO $pdo): never
{
    sessionRateGuard('rooms', 60, 30);
    $user = requireUser($pdo);
    closeSessionEarlyForReads('get_rooms');
    runMaintenanceSafely($pdo, 'messages_cleanup', 300, static function (PDO $database): void {
        cleanupExpiredMessages($database, 100);
        cleanupExpiredForms($database, 100);
    });
    $userId = (int) $user['id'];
    $stmt = $pdo->prepare(
        'SELECT r.id, r.name, r.description, COUNT(m.id) AS unread_count
         FROM rooms r
         LEFT JOIN room_reads rr ON rr.room_id = r.id AND rr.user_id = ?
         LEFT JOIN messages m ON m.room_id = r.id
            AND m.deleted_at IS NULL
            AND m.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
            AND m.id > COALESCE(rr.last_read_message_id, 0)
            AND (
                (m.user_id IS NOT NULL AND m.user_id <> ?)
                OR (
                    m.announcement_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1 FROM announcements a
                        WHERE a.id = m.announcement_id AND a.admin_id <> ?
                    )
                )
            )
         GROUP BY r.id, r.name, r.description
         ORDER BY r.id'
    );
    $stmt->execute([$userId, $userId, $userId]);
    $rooms = $stmt->fetchAll();
    foreach ($rooms as $index => &$room) {
        $room = canonicalizeRoom($room, $index + 1);
        $room['unread_count'] = (int) $room['unread_count'];
    }
    unset($room);
    respond(['rooms' => $rooms]);
}

function actionGetMessages(PDO $pdo): never
{
    sessionRateGuard('messages', 75, 30);
    $user = requireUser($pdo);
    closeSessionEarlyForReads('get_messages');
    $roomId = integerField($_GET['room_id'] ?? null, 'Oda');
    $room = roomExists($pdo, $roomId);
    runMaintenanceSafely($pdo, 'messages_cleanup', 300, static function (PDO $database): void {
        cleanupExpiredMessages($database, 100);
        cleanupExpiredForms($database, 100);
    });

    if ((bool) $user['show_read_receipts']) {
        $views = $pdo->prepare(
            'INSERT IGNORE INTO message_views (message_id, user_id, viewed_at)
             SELECT m.id, ?, UTC_TIMESTAMP() FROM messages m
             WHERE m.room_id = ? AND m.user_id IS NOT NULL AND m.user_id <> ?
               AND m.deleted_at IS NULL
               AND m.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
               AND m.id > COALESCE((SELECT rr.last_read_message_id FROM room_reads rr WHERE rr.user_id = ? AND rr.room_id = ?), 0)'
        );
        $views->execute([$user['id'], $roomId, $user['id'], $user['id'], $roomId]);
    }

    $stmt = $pdo->prepare(
        'SELECT recent.* FROM (
            SELECT m.id, m.room_id, m.user_id, m.announcement_id, m.type, m.content, m.image_path, m.created_at,
                   u.username, u.avatar_color, u.profile_image_path, u.active_profile_frame,
                   (SELECT COUNT(*) FROM message_views mv WHERE mv.message_id = m.id) AS view_count
            FROM messages m
            LEFT JOIN users u ON u.id = m.user_id
            LEFT JOIN message_deletions md ON md.message_id = m.id AND md.user_id = ? AND md.deleted_for_user = 1
            WHERE m.room_id = ? AND m.deleted_at IS NULL
              AND m.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR) AND md.id IS NULL
            ORDER BY m.id DESC LIMIT 100
         ) AS recent ORDER BY recent.id ASC'
    );
    $stmt->execute([$user['id'], $roomId]);
    $messages = $stmt->fetchAll();
    foreach ($messages as &$message) {
        $message['content'] = repairLegacyTurkishText($message['content']);
        $message['username'] = repairLegacyTurkishText($message['username']);
        $message['id'] = (int) $message['id'];
        $message['room_id'] = (int) $message['room_id'];
        $message['user_id'] = $message['user_id'] === null ? null : (int) $message['user_id'];
        $message['announcement_id'] = $message['announcement_id'] === null ? null : (int) $message['announcement_id'];
        $message['view_count'] = (int) $message['view_count'];
        $created = strtotime($message['created_at'] . ' UTC');
        $message['created_at'] = gmdate(DateTimeInterface::ATOM, $created);
        $message['expires_at'] = gmdate(DateTimeInterface::ATOM, $created + MESSAGE_RETENTION_HOURS * 3600);
        $message['is_own'] = $message['user_id'] === (int) $user['id'];
    }
    $lastReadId = $messages ? max(array_column($messages, 'id')) : 0;
    if ($lastReadId > 0) {
        $read = $pdo->prepare(
            'INSERT INTO room_reads (user_id, room_id, last_read_message_id, last_read_at)
             VALUES (?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id)),
                last_read_at = UTC_TIMESTAMP()'
        );
        $read->execute([$user['id'], $roomId, $lastReadId]);
    }

    // Geri sayım bilgisi yalnızca odaya ilk girişte hesaplanır; her yoklamada
    // fazladan toplama sorgusu çalıştırılmaz.
    $nextCleanup = null;
    if ((string) ($_GET['meta'] ?? '0') === '1') {
        $cleanupStmt = $pdo->prepare(
            'SELECT MIN(created_at) FROM messages WHERE room_id = ? AND deleted_at IS NULL AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)'
        );
        $cleanupStmt->execute([$roomId]);
        $oldestCreatedAt = $cleanupStmt->fetchColumn();
        if ($oldestCreatedAt) {
            $nextCleanup = gmdate(DateTimeInterface::ATOM, strtotime($oldestCreatedAt . ' UTC') + MESSAGE_RETENTION_HOURS * 3600);
        }
    }
    respond(['room' => $room, 'messages' => $messages, 'next_cleanup_at' => $nextCleanup]);
}

function actionSendMessage(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    consumeRequestLimit($pdo, rateKey('message', (string) $user['id']), 20, 30);
    // Oda 0 (Bilgi Odası) geçerli bir kimliktir; normal odalar 1+ olmalıdır.
    $rawRoomId = filter_var($data['room_id'] ?? null, FILTER_VALIDATE_INT);
    if ($rawRoomId === false || $rawRoomId < 0) {
        throw new ApiError(422, 'VALIDATION_ERROR', 'Oda geçersiz.');
    }
    $roomId = (int) $rawRoomId;
    $room = roomExists($pdo, $roomId);
    if ((int) ($room['id'] ?? -1) === INFO_ROOM_ID) {
        throw new ApiError(422, 'INFO_ROOM_NO_CHAT', 'Bilgi Odası içerik odasıdır; burada sohbet mesajı gönderilemez.');
    }
    $content = cleanText($data['message'] ?? '', 'Mesaj', 4000, false);
    $imagePath = storeUploadedImage();
    if ($content === '' && $imagePath === null) {
        throw new ApiError(422, 'EMPTY_MESSAGE', 'Mesaj veya resim ekleyin.');
    }
    $type = $imagePath ? 'image' : 'text';
    try {
        $stmt = $pdo->prepare('INSERT INTO messages (room_id, user_id, type, content, image_path) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$roomId, $user['id'], $type, $content === '' ? null : $content, $imagePath]);
        $messageId = (int) $pdo->lastInsertId();
    } catch (Throwable $error) {
        safeDeleteImage($imagePath);
        throw $error;
    }
    respond(['message' => 'Mesaj gönderildi.', 'message_id' => $messageId, 'image_path' => $imagePath], 201);
}

function actionDeleteMessage(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $messageId = integerField($data['message_id'] ?? null, 'Mesaj');
    $stmt = $pdo->prepare('SELECT id, user_id, image_path, deleted_at FROM messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    if (!$message) {
        throw new ApiError(404, 'MESSAGE_NOT_FOUND', 'Mesaj bulunamadı veya daha önce silindi.');
    }
    if ((int) $message['user_id'] !== (int) $user['id']) {
        throw new ApiError(403, 'NOT_MESSAGE_OWNER', 'Yalnızca kendi mesajınızı silebilirsiniz.');
    }
    if ($message['deleted_at'] !== null) {
        throw new ApiError(409, 'DELETE_ALREADY_PENDING', 'Bu mesaj için silme işlemi zaten devam ediyor.');
    }

    $delete = $pdo->prepare('UPDATE messages SET deleted_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 4 SECOND) WHERE id = ? AND user_id = ? AND deleted_at IS NULL');
    $delete->execute([$messageId, $user['id']]);
    if ($delete->rowCount() !== 1) {
        throw new ApiError(409, 'DELETE_ALREADY_PENDING', 'Mesaj silme işlemi başlatılamadı.');
    }
    $deadlineStmt = $pdo->prepare('SELECT deleted_at FROM messages WHERE id = ?');
    $deadlineStmt->execute([$messageId]);
    $deadlineRaw = (string) $deadlineStmt->fetchColumn();
    $deadline = strtotime($deadlineRaw . ' UTC');
    $undoToken = bin2hex(random_bytes(24));
    $_SESSION['message_undo'][(string) $messageId] = [
        'token_hash' => hash('sha256', $undoToken),
        'user_id' => (int) $user['id'],
        'expires_at' => $deadline,
    ];
    respond([
        'message' => 'Mesaj siliniyor.',
        'message_id' => $messageId,
        'undo_token' => $undoToken,
        'undo_until' => gmdate(DateTimeInterface::ATOM, $deadline),
    ]);
}

function actionUndoDeleteMessage(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $messageId = integerField($data['message_id'] ?? null, 'Mesaj');
    $token = cleanText($data['undo_token'] ?? null, 'Geri alma anahtarı', 128);
    $entry = $_SESSION['message_undo'][(string) $messageId] ?? null;
    if (!is_array($entry)
        || (int) ($entry['user_id'] ?? 0) !== (int) $user['id']
        || !hash_equals((string) ($entry['token_hash'] ?? ''), hash('sha256', $token))
        || (int) ($entry['expires_at'] ?? 0) <= time()) {
        unset($_SESSION['message_undo'][(string) $messageId]);
        throw new ApiError(410, 'UNDO_EXPIRED', 'Geri alma süresi doldu. Mesaj kalıcı olarak silindi.');
    }
    $undo = $pdo->prepare('UPDATE messages SET deleted_at = NULL WHERE id = ? AND user_id = ? AND deleted_at > UTC_TIMESTAMP()');
    $undo->execute([$messageId, $user['id']]);
    unset($_SESSION['message_undo'][(string) $messageId]);
    if ($undo->rowCount() !== 1) {
        finalizeScheduledMessageDeletes($pdo);
        throw new ApiError(410, 'UNDO_EXPIRED', 'Geri alma süresi doldu. Mesaj kalıcı olarak silindi.');
    }
    respond(['message' => 'Mesaj geri getirildi.', 'message_id' => $messageId]);
}

function actionFinalizeMessageDelete(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $messageId = integerField($data['message_id'] ?? null, 'Mesaj');
    $stmt = $pdo->prepare('SELECT user_id, image_path, deleted_at FROM messages WHERE id = ?');
    $stmt->execute([$messageId]);
    $message = $stmt->fetch();
    if (!$message) {
        unset($_SESSION['message_undo'][(string) $messageId]);
        respond(['message' => 'Mesaj kalıcı olarak silindi.', 'message_id' => $messageId]);
    }
    if ((int) $message['user_id'] !== (int) $user['id']) {
        throw new ApiError(403, 'NOT_MESSAGE_OWNER', 'Yalnızca kendi mesajınızı silebilirsiniz.');
    }
    if ($message['deleted_at'] === null) {
        throw new ApiError(409, 'DELETE_NOT_PENDING', 'Bu mesaj için bekleyen bir silme işlemi bulunmuyor.');
    }
    $deadline = strtotime($message['deleted_at'] . ' UTC');
    if ($deadline > time()) {
        throw new ApiError(409, 'DELETE_COOLDOWN_ACTIVE', 'Mesajın geri alma süresi henüz dolmadı.', [
            'retry_after' => $deadline - time(),
        ]);
    }
    $delete = $pdo->prepare('DELETE FROM messages WHERE id = ? AND user_id = ? AND deleted_at <= UTC_TIMESTAMP()');
    $delete->execute([$messageId, $user['id']]);
    if ($delete->rowCount() === 1) {
        safeDeleteImage($message['image_path']);
    }
    unset($_SESSION['message_undo'][(string) $messageId]);
    respond(['message' => 'Mesaj kalıcı olarak silindi.', 'message_id' => $messageId]);
}

function actionGetMessageViews(PDO $pdo): never
{
    sessionRateGuard('views', 30, 30);
    requireUser($pdo);
    closeSessionEarlyForReads('get_message_views');
    $messageId = integerField($_GET['message_id'] ?? null, 'Mesaj');
    // Not: 24 saatlik ek pencere KALDIRILDI. Temizlik örneklemeli çalıştığı
    // için mesaj süresi geçtiği hâlde bir süre daha veritabanında durur; o
    // aralıkta ekranda görünen mesajın görenler listesi "bulunamadı" diyordu.
    $exists = $pdo->prepare('SELECT 1 FROM messages WHERE id = ? AND deleted_at IS NULL');
    $exists->execute([$messageId]);
    if (!$exists->fetchColumn()) {
        throw new ApiError(404, 'MESSAGE_NOT_FOUND', 'Mesaj bulunamadı.');
    }
    $stmt = $pdo->prepare(
        'SELECT u.id, u.username, u.avatar_color, u.profile_image_path, u.active_profile_frame, mv.viewed_at
         FROM message_views mv JOIN users u ON u.id = mv.user_id
         WHERE mv.message_id = ? ORDER BY mv.viewed_at DESC LIMIT 200'
    );
    $stmt->execute([$messageId]);
    $viewers = $stmt->fetchAll();
    foreach ($viewers as &$viewer) {
        $viewer['id'] = (int) $viewer['id'];
        $viewer['username'] = repairLegacyTurkishText($viewer['username']);
        $viewer['viewed_at'] = gmdate(DateTimeInterface::ATOM, strtotime($viewer['viewed_at'] . ' UTC'));
    }
    respond(['viewers' => $viewers]);
}

function actionGetUserProfile(PDO $pdo): never
{
    sessionRateGuard('profile', 30, 30);
    $viewer = requireUser($pdo);
    closeSessionEarlyForReads('get_user_profile');
    $userId = integerField($_GET['user_id'] ?? $viewer['id'], 'Kullanıcı');
    $stmt = $pdo->prepare('SELECT id, username, avatar_color, profile_image_path, active_profile_frame, about, birthday, created_at, last_seen, online_status, show_last_seen, last_username_change FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $profile = $stmt->fetch();
    if (!$profile) {
        throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.');
    }
    $isOwn = $userId === (int) $viewer['id'];
    $canShowPresence = $isOwn || (bool) $profile['show_last_seen'];
    $lastSeenTime = $profile['last_seen'] ? strtotime($profile['last_seen'] . ' UTC') : null;
    $availableAt = $profile['last_username_change'] ? strtotime($profile['last_username_change'] . ' UTC') + 30 * 86400 : null;
    respond(['profile' => [
        'id' => (int) $profile['id'],
        'username' => repairLegacyTurkishText($profile['username']),
        'avatar_color' => $profile['avatar_color'],
        'profile_image_path' => $profile['profile_image_path'],
        'active_profile_frame' => $profile['active_profile_frame'] ?? null,
        'about' => repairLegacyTurkishText($profile['about']),
        'birthday' => $profile['birthday'],
        'created_at' => gmdate(DateTimeInterface::ATOM, strtotime($profile['created_at'] . ' UTC')),
        'last_seen' => $canShowPresence && $lastSeenTime ? gmdate(DateTimeInterface::ATOM, $lastSeenTime) : null,
        'online' => $canShowPresence && (bool) $profile['online_status'] && $lastSeenTime !== null && $lastSeenTime > time() - ONLINE_WINDOW_SECONDS,
        'presence_hidden' => !$canShowPresence,
        'is_own' => $isOwn,
        'username_change_available_at' => $availableAt && $availableAt > time() ? gmdate(DateTimeInterface::ATOM, $availableAt) : null,
    ]]);
}

function actionUpdateProfile(PDO $pdo, array $data): never
{
    $current = requireUser($pdo);
    $username = validatedUsername($data['username'] ?? null);
    $about = cleanText($data['about'] ?? '', 'Hakkında', 500, false);
    $birthday = cleanText($data['birthday'] ?? '', 'Doğum günü', 10, false);
    if ($birthday !== '') {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $birthday, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $birthday || $birthday < '1900-01-01' || $birthday > gmdate('Y-m-d')) {
            throw new ApiError(422, 'INVALID_BIRTHDAY', 'Doğum günü geçerli bir tarih olmalıdır.');
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT username, last_username_change FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$current['id']]);
        $locked = $stmt->fetch();
        $changingUsername = $username !== $locked['username'];
        if ($changingUsername && $locked['last_username_change'] !== null && strtotime($locked['last_username_change'] . ' UTC') > time() - 30 * 86400) {
            $available = strtotime($locked['last_username_change'] . ' UTC') + 30 * 86400;
            throw new ApiError(409, 'USERNAME_CHANGE_LOCKED', 'Kullanıcı adı 30 günde yalnızca bir kez değiştirilebilir.', [
                'available_at' => gmdate(DateTimeInterface::ATOM, $available),
            ]);
        }
        $update = $pdo->prepare(
            'UPDATE users SET username = ?, about = ?, birthday = ?, last_username_change = IF(?, UTC_TIMESTAMP(), last_username_change) WHERE id = ?'
        );
        $update->execute([$username, $about === '' ? null : $about, $birthday === '' ? null : $birthday, $changingUsername ? 1 : 0, $current['id']]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $error->getCode() === '23000') {
            throw new ApiError(409, 'USERNAME_TAKEN', 'Bu kullanıcı adı zaten kullanılıyor.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$current['id']]);
    respond(['message' => 'Profil güncellendi.', 'user' => publicUser($stmt->fetch())]);
}

function actionUpdateProfilePhoto(PDO $pdo): never
{
    $user = requireUser($pdo);
    consumeRequestLimit($pdo, rateKey('profile_photo', (string) $user['id']), 10, 3600);
    $newPath = storeUploadedImage();
    if ($newPath === null) {
        throw new ApiError(422, 'IMAGE_REQUIRED', 'Bir profil resmi seçin.');
    }
    $oldPath = $user['profile_image_path'] ?? null;
    try {
        $stmt = $pdo->prepare('UPDATE users SET profile_image_path = ? WHERE id = ?');
        $stmt->execute([$newPath, $user['id']]);
    } catch (Throwable $error) {
        safeDeleteImage($newPath);
        throw $error;
    }
    safeDeleteImage($oldPath);
    respond(['message' => 'Profil resmi güncellendi.', 'profile_image_path' => $newPath]);
}

function actionDeleteProfilePhoto(PDO $pdo): never
{
    $user = requireUser($pdo);
    $oldPath = $user['profile_image_path'] ?? null;
    $stmt = $pdo->prepare('UPDATE users SET profile_image_path = NULL WHERE id = ?');
    $stmt->execute([$user['id']]);
    safeDeleteImage($oldPath);
    respond(['message' => 'Profil resmi kaldırıldı.', 'profile_image_path' => null]);
}

function actionUpdateSettings(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $theme = (string) ($data['theme'] ?? '');
    $language = (string) ($data['language'] ?? '');
    if (!in_array($theme, ['dark', 'light'], true) || !in_array($language, ['tr', 'en', 'de', 'fr'], true)) {
        throw new ApiError(422, 'INVALID_SETTINGS', 'Tema veya dil seçimi geçersiz.');
    }
    $readReceipts = filter_var($data['show_read_receipts'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $lastSeen = filter_var($data['show_last_seen'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($readReceipts === null || $lastSeen === null) {
        throw new ApiError(422, 'INVALID_SETTINGS', 'Gizlilik ayarı geçersiz.');
    }
    $stmt = $pdo->prepare('UPDATE users SET theme = ?, language = ?, show_read_receipts = ?, show_last_seen = ? WHERE id = ?');
    $stmt->execute([$theme, $language, $readReceipts ? 1 : 0, $lastSeen ? 1 : 0, $user['id']]);
    respond(['message' => 'Ayarlar kaydedildi.', 'settings' => [
        'theme' => $theme,
        'language' => $language,
        'show_read_receipts' => $readReceipts,
        'show_last_seen' => $lastSeen,
    ]]);
}

function actionChangePassword(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    // Güncel şifre burada deneme-yanılmayla tahmin edilebilir. Koruma, giriş
    // ucundakiyle BİREBİR aynı politikadır: yalnızca HATALI güncel şifre
    // sayılır (5 deneme / 15 dk), başarılı değişim sayacı sıfırlar.
    // consumeRequestLimit kullanılmazdı çünkü o BAŞARILI istekleri de sayar;
    // şifresini iki kez üst üste değiştiren ya da yeni şifre kuralına
    // takılan meşru kullanıcı kendi hesabından kilitlenirdi.
    $rateLimitKey = rateKey('change_password', (string) $user['id']);
    assertNotRateLimited($pdo, $rateLimitKey);

    $current = is_string($data['current_password'] ?? null) ? $data['current_password'] : '';
    // Yeni şifre, kayıt akışıyla BİREBİR aynı kurallardan geçer: iki alanın
    // eşleşmesi, 10-128 karakter, boşluk yok, harf + rakam + özel karakter.
    // Ucuz biçim denetimi, pahalı hash doğrulamasından ÖNCE yapılır
    // (delete_own_account ile aynı sıra): kurala takılan istek için boşuna
    // Argon2id maliyeti ödenmez.
    $new = validatePassword($data['password'] ?? null, $data['password_confirm'] ?? null);

    // Güncel şifre boş ya da saklanan hash biçimsiz olsa bile doğrulama
    // çalıştırılır: erken dönüş, yanıt süresi farkından hesabın şifre
    // durumunu sızdırırdı.
    $storedHash = (string) ($user['password_hash'] ?? '');
    if ($storedHash === '') {
        $storedHash = '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
    }
    if (!password_verify($current, $storedHash)) {
        recordFailedAttempt($pdo, $rateLimitKey, 5, 900, 900);
        throw new ApiError(401, 'INVALID_CREDENTIALS', 'Güncel şifre hatalı.');
    }
    if (hash_equals($new, $current)) {
        throw new ApiError(422, 'SAME_PASSWORD', 'Yeni şifre güncel şifreden farklı olmalıdır.');
    }

    $hash = password_hash($new, passwordAlgorithm());
    if ($hash === false) {
        throw new RuntimeException('Şifre güvenli biçimde işlenemedi.');
    }
    $userId = (int) $user['id'];
    $newVersion = (int) ($user['session_version'] ?? 1) + 1;
    $pdo->prepare('UPDATE users SET password_hash = ?, session_version = ? WHERE id = ?')
        ->execute([$hash, $newVersion, $userId]);

    // Sürüm yükseltmesi diğer oturumları düşürür. Bu isteğin sahibi bilinçli
    // olarak yeni sürüme taşınır: kendi şifresini değiştiren kullanıcı
    // işlemin ortasında oturumdan atılmamalı.
    $_SESSION['session_version'] = $newVersion;
    revokeOtherRememberTokens($pdo, $userId, $newVersion);
    clearRateLimit($pdo, $rateLimitKey);

    respond([
        'message' => 'Şifren değiştirildi. Diğer cihazlardaki oturumların kapatıldı.',
        'other_sessions_revoked' => true,
    ]);
}

function actionAdminLogin(PDO $pdo, array $data): never
{
    requireUser($pdo);
    $key = rateKey('admin', clientIp());
    assertNotRateLimited($pdo, $key);
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $configuredHash = envValue('ASRA_ADMIN_PASSWORD_HASH');
    $valid = $configuredHash !== ''
        ? password_verify($password, $configuredHash)
        : hash_equals(DEFAULT_ADMIN_PASSWORD, $password);
    if (!$valid) {
        blockImmediately($pdo, $key, 600);
        throw new ApiError(401, 'INVALID_ADMIN_PASSWORD', 'Admin şifresi hatalı. Yeni deneme 10 dakika sonra yapılabilir.', ['retry_after' => 600]);
    }
    clearRateLimit($pdo, $key);
    rotateSessionId();
    $_SESSION['admin_authenticated_until'] = time() + 1800;
    respond(['message' => 'Admin doğrulaması başarılı.', 'expires_in' => 1800]);
}

function actionAdminSearchUsers(PDO $pdo): never
{
    sessionRateGuard('admin_search', 30, 30);
    $admin = requireAdmin($pdo);
    runMaintenanceSafely($pdo, 'expired_bans', 300, static function (PDO $database): void {
        cleanupExpiredBans($database);
    });
    $query = cleanText($_GET['query'] ?? '', 'Arama', 50, false);
    $pattern = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%';
    // Onay bekleyenler yönetim ekranında görünmez; yalnızca onaylı hesaplar listelenir
    $stmt = $pdo->prepare(
        "SELECT u.id, u.username, u.approval_status, u.avatar_color, u.profile_image_path, u.active_profile_frame, u.aa_coins, u.coin_debt_due_at, u.created_at,
                b.reason AS ban_reason, b.banned_until, b.banned_at
         FROM users u LEFT JOIN banned_users b ON b.user_id = u.id
         WHERE u.approval_status = 'approved' AND u.username LIKE ? ESCAPE '\\\\' ORDER BY u.username LIMIT 100"
    );
    $stmt->execute([$pattern]);
    $users = $stmt->fetchAll();
    foreach ($users as &$user) {
        $user['id'] = (int) $user['id'];
        $user['username'] = repairLegacyTurkishText($user['username']);
        $user['ban_reason'] = repairLegacyTurkishText($user['ban_reason']);
        $user['is_self'] = $user['id'] === (int) $admin['id'];
        $user['aa_coins'] = coinAmount($user['aa_coins'] ?? 0);
        if ($user['coin_debt_due_at']) {
            $user['coin_debt_due_at'] = gmdate(DateTimeInterface::ATOM, strtotime($user['coin_debt_due_at'] . ' UTC'));
        }
        $user['is_banned'] = $user['ban_reason'] !== null;
        $user['permanent'] = $user['is_banned'] && $user['banned_until'] === null;
        if ($user['banned_until']) {
            $user['banned_until'] = gmdate(DateTimeInterface::ATOM, strtotime($user['banned_until'] . ' UTC'));
        }
        unset($user['banned_at']);
    }
    respond(['users' => $users]);
}

function actionAdminBanUser(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $userId = integerField($data['user_id'] ?? null, 'Kullanıcı');
    $reason = cleanText($data['reason'] ?? null, 'Yasak nedeni', 1000);
    $blockIdentity = filter_var($data['block_identity'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($blockIdentity === null) {
        throw new ApiError(422, 'VALIDATION_ERROR', 'Cihaz ve IP engelleme seçimi geçersiz.');
    }
    $unit = (string) ($data['duration_unit'] ?? '');
    if (!in_array($unit, ['seconds', 'minutes', 'days', 'permanent'], true)) {
        throw new ApiError(422, 'INVALID_BAN_DURATION', 'Yasak süresi birimi geçersiz.');
    }
    $until = null;
    if ($unit !== 'permanent') {
        $value = integerField($data['duration_value'] ?? null, 'Yasak süresi');
        $multipliers = ['seconds' => 1, 'minutes' => 60, 'days' => 86400];
        $seconds = $value * $multipliers[$unit];
        if ($seconds < 1 || $seconds > 10 * 365 * 86400) {
            throw new ApiError(422, 'INVALID_BAN_DURATION', 'Yasak süresi 10 yılı aşamaz.');
        }
        $until = gmdate('Y-m-d H:i:s', time() + $seconds);
    }
    $exists = $pdo->prepare("SELECT approval_status FROM users WHERE id = ?");
    $exists->execute([$userId]);
    $approval = $exists->fetchColumn();
    if ($approval === false) {
        throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.');
    }
    if ($approval === 'pending') {
        throw new ApiError(409, 'APPROVAL_PENDING', 'Bu hesap henüz yönetici onayı bekliyor; onaylanmadan ban veya hesap işlemi yapılamaz.');
    }
    $stmt = $pdo->prepare(
        'INSERT INTO banned_users (user_id, reason, banned_until, banned_at) VALUES (?, ?, ?, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE reason = VALUES(reason), banned_until = VALUES(banned_until), banned_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([$userId, $reason, $until]);
    if ($blockIdentity) {
        blockUserIdentities($pdo, $userId, $reason, $until);
    }
    $pdo->prepare('UPDATE users SET online_status = 0 WHERE id = ?')->execute([$userId]);
    respond([
        'message' => 'Kullanıcı yasaklandı.',
        'banned_until' => $until ? gmdate(DateTimeInterface::ATOM, strtotime($until . ' UTC')) : null,
        'device_and_ip_blocked' => $blockIdentity,
    ]);
}

function actionAdminUnbanUser(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $userId = integerField($data['user_id'] ?? null, 'Kullanıcı');
    $stmt = $pdo->prepare('DELETE FROM banned_users WHERE user_id = ?');
    $stmt->execute([$userId]);
    unblockUserIdentities($pdo, $userId);
    respond(['message' => $stmt->rowCount() ? 'Kullanıcının hesabı, cihazı ve IP yasağı kaldırıldı.' : 'Kullanıcının aktif yasağı bulunmuyor.']);
}

function actionAdminDeleteUser(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $userId = integerField($data['user_id'] ?? null, 'Kullanıcı');
    $reason = cleanText($data['reason'] ?? null, 'Silme nedeni', 1000);
    $targetBlock = $pdo->prepare('SELECT username, approval_status FROM users WHERE id = ?');
    $targetBlock->execute([$userId]);
    $targetRow = $targetBlock->fetch();
    if (!$targetRow) { throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.'); }
    if (($targetRow['approval_status'] ?? 'approved') === 'pending') {
        throw new ApiError(409, 'APPROVAL_PENDING', 'Bu hesap henüz onay bekliyor; silme işlemi onaydan sonra yapılabilir. Onay panelinden reddetmeyi kullanın.');
    }
    $targetUsername = (string) ($targetRow['username'] ?: '');
    unblockUserIdentities($pdo, $userId);
    deleteUserPermanently($pdo, $userId, 'Yönetici tarafından hesabın silindi. Neden: ' . $reason);
    respond(['message' => 'Hesap ve ilişkili tüm veriler kalıcı olarak silindi.']);
}

function actionAdminChangeUserPassword(PDO $pdo, array $data): never
{
    $admin = requireAdmin($pdo);
    consumeRequestLimit($pdo, rateKey('admin_password_change', (string) $admin['id']), 20, 3600);
    $userId = integerField($data['user_id'] ?? null, 'Kullanıcı');
    $password = validatePassword($data['password'] ?? null, $data['password_confirm'] ?? null);
    $hash = password_hash($password, passwordAlgorithm());
    if ($hash === false) {
        throw new RuntimeException('Şifre güvenli biçimde işlenemedi.');
    }
    $target = $pdo->prepare('SELECT id, username, approval_status FROM users WHERE id = ?');
    $target->execute([$userId]);
    $user = $target->fetch();
    if (!$user) {
        throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.');
    }
    if (($user['approval_status'] ?? 'approved') === 'pending') {
        throw new ApiError(409, 'APPROVAL_PENDING', 'Bu hesap henüz onay bekliyor; şifre değiştirme onaydan sonra yapılabilir.');
    }
    $update = $pdo->prepare(
        'UPDATE users
         SET password_hash = ?, session_version = session_version + 1,
             online_status = 0, last_seen = UTC_TIMESTAMP()
         WHERE id = ?'
    );
    $update->execute([$hash, $userId]);
    revokeRememberTokens($pdo, $userId);
    respond([
        'message' => 'Kullanıcının şifresi değiştirildi. Açık oturumları sonlandırıldı.',
        'user_id' => $userId,
        'username' => $user['username'],
        'self_changed' => $userId === (int) $admin['id'],
    ]);
}

function actionAdminSendAnnouncement(PDO $pdo, array $data): never
{
    $admin = requireAdmin($pdo);
    $message = cleanText($data['message'] ?? null, 'Duyuru', 2000);
    $rawRoomIds = $data['room_ids'] ?? null;
    if (!is_array($rawRoomIds)) {
        throw new ApiError(422, 'ROOM_SELECTION_REQUIRED', 'Duyurunun gönderileceği en az bir oda seçin.');
    }
    $roomIds = [];
    foreach ($rawRoomIds as $roomId) {
        $validated = filter_var($roomId, FILTER_VALIDATE_INT);
        if ($validated !== false && $validated > 0) {
            $roomIds[] = (int) $validated;
        }
    }
    $roomIds = array_values(array_unique($roomIds));
    if (!$roomIds || count($roomIds) > 20) {
        throw new ApiError(422, 'ROOM_SELECTION_REQUIRED', 'Duyuru için 1 ile 20 arasında geçerli oda seçin.');
    }
    $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
    $rooms = $pdo->prepare('SELECT id FROM rooms WHERE id IN (' . $placeholders . ') ORDER BY id');
    $rooms->execute($roomIds);
    $validRoomIds = array_map('intval', $rooms->fetchAll(PDO::FETCH_COLUMN));
    if (count($validRoomIds) !== count($roomIds)) {
        throw new ApiError(422, 'INVALID_ROOM_SELECTION', 'Seçilen odalardan biri geçersiz.');
    }

    $pdo->beginTransaction();
    try {
        $announcement = $pdo->prepare('INSERT INTO announcements (admin_id, message) VALUES (?, ?)');
        $announcement->execute([$admin['id'], $message]);
        $announcementId = (int) $pdo->lastInsertId();
        $chat = $pdo->prepare(
            "INSERT INTO messages (room_id, user_id, announcement_id, type, content)
             VALUES (?, NULL, ?, 'system', ?)"
        );
        foreach ($validRoomIds as $roomId) {
            $chat->execute([$roomId, $announcementId, 'DUYURU: ' . $message]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    respond([
        'message' => 'Duyuru seçilen odalara gönderildi.',
        'announcement_id' => $announcementId,
        'room_count' => count($validRoomIds),
    ], 201);
}

function actionAdminDeleteAnnouncement(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $messageId = integerField($data['message_id'] ?? null, 'Duyuru mesajı');
    $pdo->beginTransaction();
    try {
        $messageStmt = $pdo->prepare(
            "SELECT id, announcement_id, content FROM messages
             WHERE id = ? AND type = 'system' FOR UPDATE"
        );
        $messageStmt->execute([$messageId]);
        $message = $messageStmt->fetch();
        if (!$message || !str_starts_with((string) $message['content'], 'DUYURU:')) {
            throw new ApiError(404, 'ANNOUNCEMENT_NOT_FOUND', 'Duyuru bulunamadı veya daha önce silindi.');
        }
        $announcementId = $message['announcement_id'] === null ? null : (int) $message['announcement_id'];
        if ($announcementId !== null) {
            $deleteMessages = $pdo->prepare('DELETE FROM messages WHERE announcement_id = ?');
            $deleteMessages->execute([$announcementId]);
            $deleteAnnouncement = $pdo->prepare('DELETE FROM announcements WHERE id = ?');
            $deleteAnnouncement->execute([$announcementId]);
        } else {
            $deleteLegacy = $pdo->prepare('DELETE FROM messages WHERE id = ?');
            $deleteLegacy->execute([$messageId]);
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    cleanupOrphanAnnouncements($pdo);
    respond(['message' => 'Duyuru gönderildiği odalardan kalıcı olarak silindi.']);
}

function actionDeleteOwnAccount(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $username = validatedUsername($data['username'] ?? null);
    $password = is_string($data['password'] ?? null) ? $data['password'] : '';
    $confirmation = is_string($data['password_confirm'] ?? null) ? $data['password_confirm'] : '';
    if (!hash_equals($password, $confirmation)) {
        throw new ApiError(422, 'PASSWORD_MISMATCH', 'Şifreler eşleşmiyor.');
    }
    if (!hash_equals($user['username'], $username) || !password_verify($password, $user['password_hash'])) {
        throw new ApiError(401, 'INVALID_CREDENTIALS', 'Kullanıcı adı veya şifre hatalı.');
    }
    deleteUserPermanently($pdo, (int) $user['id'], 'Kullanıcı kendi hesabını sildi.');
    clearRememberCookie();
    $_SESSION = [];
    session_destroy();
    respond(['message' => 'Hesabınız ve tüm verileriniz kalıcı olarak silindi.']);
}

function actionGetBannedInfo(PDO $pdo): never
{
    $userId = filter_var($_SESSION['blocked_user_id'] ?? $_SESSION['user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId) {
        throw new ApiError(404, 'BAN_NOT_FOUND', 'Yasak bilgisi bulunamadı.');
    }
    $ban = activeBan($pdo, (int) $userId);
    if (!$ban) {
        unset($_SESSION['blocked_user_id']);
        respond(['banned' => false]);
    }
    respond(['banned' => true, 'ban' => $ban]);
}

function normalizedIntentText(string $text): string
{
    $text = repairLegacyTurkishText($text) ?? $text;
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($text, Normalizer::FORM_C);
        if (is_string($normalized)) {
            $text = $normalized;
        }
    }
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = strtr($text, [
        'â' => 'a', 'î' => 'i', 'û' => 'u',
        'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'ı' => 'i', 'İ' => 'i',
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e',
    ]);
    $text = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?: $text;
    return preg_replace('/\s+/u', ' ', trim($text)) ?: '';
}

function aiTokens(string $text): array
{
    $tokens = preg_split('/\s+/', normalizedIntentText($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    return array_values(array_unique($tokens));
}

function aiTextMatches(string $text, array $needles): bool
{
    $normalized = normalizedIntentText($text);
    $tokens = aiTokens($normalized);
    foreach ($needles as $needle) {
        $target = normalizedIntentText((string) $needle);
        if ($target === '') {
            continue;
        }
        if (str_contains($normalized, $target)) {
            return true;
        }
        if (!str_contains($target, ' ') && strlen($target) >= 4) {
            foreach ($tokens as $token) {
                if (abs(strlen($token) - strlen($target)) <= 2 && levenshtein($token, $target) <= 2) {
                    return true;
                }
            }
        }
    }
    return false;
}

function aiLogSafetyEvent(PDO $pdo, int $userId, string $eventType, string $input): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO ai_safety_events (user_id, event_type, input_hash) VALUES (?, ?, ?)');
        $stmt->execute([$userId, substr($eventType, 0, 40), hash('sha256', normalizedIntentText($input))]);
    } catch (Throwable) {
        /* Yardımcı yanıtı log hatasından etkilenmemelidir. */
    }
}

function aiFallbackForRepeatedReply(string $reply, array $context): string
{
    foreach (array_reverse($context) as $entry) {
        if (!is_array($entry) || ($entry['role'] ?? '') !== 'assistant') {
            continue;
        }
        $previous = trim((string) ($entry['text'] ?? ''));
        if ($previous !== '' && hash_equals(hash('sha256', $previous), hash('sha256', $reply))) {
            return 'Aynı cevabı tekrar etmek yerine daha net söyleyeyim: ilgili bölümü sağ üst menüden aç, yapmak istediğin alanı seç ve ekrandaki düğmeyle işlemi tamamla. Hangi ekranda takıldığını yazarsan doğrudan o adıma odaklanırım.';
        }
        break;
    }
    return $reply;
}

function aiScienceResponse(string $text): ?string
{
    $has = static fn(array $words): bool => aiTextMatches($text, $words);
    $isScienceIntent = $has([
        'bilim', 'bilimsel', 'fen', 'fizik', 'kimya', 'biyoloji', 'astronomi', 'matematik',
        'jeoloji', 'psikoloji', 'sosyoloji', 'antropoloji', 'felsefe', 'teknoloji', 'yapay zeka',
        'atom', 'molekul', 'hucre', 'dna', 'evrim', 'fotosentez', 'kuantum', 'kara delik',
        'gezegen', 'yildiz', 'galaksi', 'deprem', 'volkan', 'algoritma', 'turev', 'integral'
    ]);
    if (!$isScienceIntent) {
        return null;
    }

    if ($has(['bilimsel yontem', 'hipotez', 'deney', 'teori', 'kanun'])) {
        return 'Bilimsel yöntem; gözlem, soru, hipotez, test edilebilir tahmin, deney/veri toplama, analiz ve tekrar edilebilir sonuç aşamalarından oluşur. Hipotez geçici açıklamadır; teori çok sayıda kanıtla desteklenen geniş açıklama modelidir; bilimsel kanun ise belirli koşullarda düzenli gözlenen ilişkiyi özetler.';
    }
    if ($has(['bilim dallari', 'bilim dalları', 'hangi bilim', 'dallar'])) {
        return 'Başlıca bilim dalları şöyle ayrılır: doğa bilimleri fizik, kimya, biyoloji, astronomi ve jeoloji; biçimsel bilimler matematik, mantık ve istatistik; sosyal bilimler psikoloji, sosyoloji, antropoloji, ekonomi ve siyaset bilimi; uygulamalı bilimler mühendislik, tıp, bilgisayar bilimi ve yapay zekâdır.';
    }
    if ($has(['kara delik', 'black hole'])) {
        return 'Kara delik, kütlesi çok küçük bir hacme sıkıştığı için kaçış hızının ışık hızını aştığı bölgedir. Olay ufku dışarıyla bilgi alışverişinin sınırıdır. Oluşumları genellikle büyük kütleli yıldızların çökmesiyle açıklanır; merkezdeki tekillik fikri ise genel görelilik ile kuantum fiziğinin hâlâ birleştirilmesi gereken kısmıdır.';
    }
    if ($has(['yildiz', 'gunes', 'gezegen', 'galaksi', 'nebula', 'astronomi', 'uzay'])) {
        return 'Astronomide temel fikir kütleçekimdir: gaz ve toz bulutları çöker, yıldızları oluşturur; yıldızların çevresindeki disklerden gezegenler doğabilir. Yıldızlar enerjilerini çekirdeklerinde hidrojenin helyuma füzyonundan alır. Galaksiler milyarlarca yıldız, gaz, toz ve karanlık madde içeren büyük yapılardır.';
    }
    if ($has(['kuantum', 'foton', 'elektron', 'belirsizlik'])) {
        return 'Kuantum fiziği, atom ve atom altı ölçekte enerjinin ve maddenin davranışını açıklar. Parçacıklar dalga gibi girişim yapabilir, ölçüm sonucu olasılıksaldır ve Heisenberg belirsizlik ilkesi konum ile momentumun aynı anda sınırsız kesinlikle bilinemeyeceğini söyler.';
    }
    if ($has(['newton', 'kuvvet', 'ivme', 'enerji', 'momentum', 'fizik', 'hareket'])) {
        return 'Fizikte hareketi anlamanın temeli kuvvet, kütle ve ivmedir. Newton’un ikinci yasası F = m·a der: aynı kütleye daha büyük kuvvet uygulanırsa ivme artar. Enerji iş yapabilme kapasitesidir; kapalı sistemlerde enerji korunur, yalnızca biçim değiştirir.';
    }
    if ($has(['atom', 'molekul', 'kimya', 'reaksiyon', 'tepkime', 'asit', 'baz', 'ph'])) {
        return 'Kimya maddeyi ve maddenin dönüşümünü inceler. Atomlar proton, nötron ve elektronlardan oluşur; atomlar bağ kurarak molekülleri oluşturur. Kimyasal tepkimede atomlar yok olmaz, bağlar kırılır ve yeni bağlar kurulur. Asitler proton verme, bazlar proton alma eğilimindedir; pH asitlik-bazlık derecesini gösterir.';
    }
    if ($has(['dna', 'gen', 'kromozom', 'kalitim', 'mutasyon'])) {
        return 'DNA, canlıların kalıtsal bilgisini taşıyan moleküldür. Genler DNA üzerindeki işlevsel bilgi bölgeleridir. Mutasyon DNA dizisindeki değişimdir; zararlı, nötr veya yararlı olabilir. Evrimsel süreçte kalıtsal çeşitlilik ve doğal seçilim popülasyonların zamanla değişmesine yol açar.';
    }
    if ($has(['evrim', 'dogal secilim', 'adaptasyon'])) {
        return 'Evrim, popülasyonların kalıtsal özelliklerinin nesiller boyunca değişmesidir. Doğal seçilimde çevreye daha uyumlu özellikler taşıyan bireyler daha fazla hayatta kalıp üreyebilir. Evrim tek bir bireyin değişmesi değil, popülasyonun gen frekanslarının zamanla değişmesidir.';
    }
    if ($has(['fotosentez', 'klorofil', 'bitki'])) {
        return 'Fotosentez, bitkilerin, alglerin ve bazı bakterilerin ışık enerjisini kimyasal enerjiye çevirmesidir. Genel denklem: karbondioksit + su + ışık enerjisi → glikoz + oksijen. Klorofil ışığı soğurur; üretilen glikoz canlı için enerji ve yapı maddesi kaynağıdır.';
    }
    if ($has(['hucre', 'mitoz', 'mayoz', 'organel', 'biyoloji'])) {
        return 'Hücre canlılığın temel birimidir. Hücre zarı madde geçişini düzenler, DNA kalıtsal bilgiyi taşır, ribozom protein üretir, mitokondri enerji dönüşümünde rol oynar. Mitoz büyüme ve onarım için aynı genetik bilgili hücreler üretir; mayoz üreme hücrelerinde genetik çeşitlilik sağlar.';
    }
    if ($has(['deprem', 'fay', 'levha', 'tektonik', 'jeoloji', 'volkan'])) {
        return 'Jeoloji Dünya’nın yapısını ve süreçlerini inceler. Depremler çoğunlukla levha hareketleriyle faylarda biriken gerilimin aniden boşalmasıyla oluşur. Volkanizma ise magmanın yüzeye çıkmasıdır. Risk değerlendirmesinde zemin, yapı kalitesi, fay uzaklığı ve tarihsel sismisite birlikte incelenir.';
    }
    if ($has(['turev', 'integral', 'denklem', 'olasılık', 'istatistik', 'matematik'])) {
        return 'Matematikte türev değişim hızını, integral birikimi ve alanı ifade eder. Olasılık belirsizlik altında sonuçların ölçüsüdür; istatistik ise veriden anlamlı sonuç çıkarır. Bilimde matematik, gözlenen olayları nicel modellemek ve tahmin yapmak için kullanılır.';
    }
    if ($has(['algoritma', 'yapay zeka', 'makine ogrenmesi', 'sinir agi', 'veri', 'bilgisayar'])) {
        return 'Yapay zekâ, veriden örüntü öğrenerek tahmin veya karar üretmeye çalışan sistemleri kapsar. Makine öğrenmesinde model örneklerden öğrenir; sinir ağları katmanlar hâlinde ağırlıklar kullanır. İyi sonuç için kaliteli veri, doğru ölçüt, aşırı öğrenmeyi önleme ve etik/güvenlik kontrolü gerekir.';
    }
    if ($has(['psikoloji', 'davranis', 'beyin', 'bilis', 'sosyoloji', 'toplum', 'antropoloji'])) {
        return 'Sosyal ve davranış bilimleri insanı birey ve toplum düzeyinde inceler. Psikoloji davranış ve zihinsel süreçlere, sosyoloji toplumsal kurum ve ilişkilere, antropoloji kültür ve insan çeşitliliğine odaklanır. Güçlü sonuç için örneklem, yöntem, yanlılık ve tekrar edilebilirlik dikkatle değerlendirilir.';
    }
    if ($has(['iklim', 'sera', 'karbon', 'ekosistem', 'cevre'])) {
        return 'İklim sistemi atmosfer, okyanus, kara, buzullar ve canlıların etkileşiminden oluşur. Sera gazları kızılötesi ışınımı tutarak Dünya’nın enerji dengesini değiştirir. Ekosistemlerde enerji akışı ve madde döngüleri canlı topluluklarının sürdürülebilirliğini belirler.';
    }
    return 'Bilimsel modda cevaplayayım: Bir konuyu güçlü anlamak için önce kavramı tanımlarız, sonra mekanizmasını açıklarız, kanıt türlerini inceleriz, istisnaları belirtiriz ve mümkünse matematiksel ya da deneysel bir örnekle pekiştiririz. Hangi bilim dalını veya konuyu istediğini yazarsan daha derin anlatırım.';
}

function asraAiResponse(string $question, string $username, string $contextHint = '', array $context = []): string
{
    $baseText = normalizedIntentText($question);
    $contextText = normalizedIntentText($contextHint);
    $combined = $contextText !== '' && count(aiTokens($baseText)) <= 6 ? trim($contextText . ' ' . $baseText) : $baseText;
    $has = static fn(array $words): bool => aiTextMatches($combined, $words);
    $hasCurrent = static fn(array $words): bool => aiTextMatches($baseText, $words);

    $abusiveTokens = ['mal', 'salak', 'aptal', 'ahmak', 'gerizekali', 'beyinsiz', 'serefsiz', 'orospu', 'pic', 'siktir', 'amk', 'aq'];
    if (array_intersect(aiTokens($baseText), $abusiveTokens) || $hasCurrent(['bok kafa', 'geri zekali', 'sacmalama', 'mal gibi'])) {
        return 'Bu üslupla yardımcı olamam. Sorununu hakaret etmeden ve açık biçimde yazarsan hemen yardımcı olurum.';
    }

    $scienceReply = aiScienceResponse($combined);
    if ($scienceReply !== null) {
        return $scienceReply;
    }

    $profilePhotoTerms = ['profil foto', 'profil fotograf', 'profil resmi', 'pp', 'avatar', 'fotomu', 'resmimi', 'foto koy', 'fotograf koy', 'foto yukle', 'resim koy', 'profilime foto', 'profilime resim'];
    $changeTerms = ['nasil', 'nereden', 'koy', 'koyarim', 'koycam', 'ekle', 'eklerim', 'eklicem', 'yukle', 'yuklerim', 'degis', 'degistir', 'yap', 'yaparim', 'ayarla', 'istiyorum', 'olmuyor', 'bulamadim'];
    if ($has($profilePhotoTerms) && $has($changeTerms)) {
        return 'Profil fotoğrafı için: sağ üstteki üç nokta menüsünü aç, Profilim seçeneğine gir, yuvarlak profil alanına dokun ve JPG, PNG veya WEBP formatında en fazla 5 MB olan resmi seç. Yükleme bitince fotoğrafın profilinde ve mesaj avatarlarında görünür. Kaldırmak istersen aynı ekrandaki Resmi kaldır düğmesini kullan.';
    }
    if ($has($profilePhotoTerms)) {
        return 'Profil fotoğrafı Profilim ekranından yönetilir. Yuvarlak profil alanına dokunarak fotoğraf seçebilir, varsa Resmi kaldır seçeneğiyle silebilirsin.';
    }
    if ($has(['yanlis cevap', 'anlamadin', 'sacma cevap', 'ise yaramadi', 'alakasiz', 'ayni seyi', 'tekrar ediyorsun', 'tekrarlama'])) {
        return 'Haklı olabilirsin; aynı şeyi tekrar etmeyeceğim. İstediğin sonucu tek cümleyle daha somut yazarsan doğrudan o adıma geçerim.';
    }
    if ($has(['yazim', 'imla', 'noktalama', 'yanlis yaz', 'hata yapsam', 'duzgun yazmak'])) {
        return 'Kusursuz yazmana gerek yok. Yazım hatası, eksik noktalama veya günlük konuşma dili olsa bile niyetini anlamaya çalışırım. Anlamazsam senden daha net anlatmanı isterim.';
    }
    if ($has(['merhaba', 'selam', 'gunaydin', 'iyi aksamlar']) && $has(['nasilsin', 'naber', 'ne haber'])) {
        return 'Merhaba ' . $username . '! İyiyim, teşekkür ederim. Platformda mesajlaşma, profil, davet, bildirim, gizlilik veya güvenlik konusunda takıldığın yeri yazabilirsin.';
    }
    if ($has(['merhaba', 'selam', 'gunaydin', 'iyi aksamlar'])) {
        return 'Merhaba ' . $username . '! Platform kullanımıyla ilgili sorunu yaz; kısa ve net şekilde yardımcı olurum.';
    }
    if ($has(['nasilsin', 'naber', 'ne haber'])) {
        return 'İyiyim, teşekkür ederim. Sana hangi konuda yardımcı olayım?';
    }
    if ($has(['kayit', 'kaydol', 'hesap ac', 'hesap olustur']) && $has(['nasil', 'nereden', 'yap', 'ac'])) {
        return 'Kayıt için Kaydol sekmesinde kullanıcı adını ve güçlü şifreni iki kez gir. Davet kodu gerekmez; hesabın oluşturulur ve yönetici onayından sonra topluluğa katılabilirsin. Bu cihaz daha önce bir hesaba bağlandıysa güvenlik nedeniyle yeni hesap oluşturulamaz.';
    }
    if ($has(['giris', 'oturum']) && $has(['nasil', 'nereden', 'yap', 'ac'])) {
        return 'Giriş için Giriş Yap sekmesine kullanıcı adını ve şifreni yaz. Şifren yetkili tarafından yenilendiyse eski şifre değil yalnızca yeni şifre geçerlidir. Hesabın onay bekliyorsa girişten sonra katılma nedeni ekranı açılır.';
    }
    if ($has(['kullanici adi', 'isim', 'nick']) && $has(['kullanimda', 'mevcut', 'alamiyorum', 'degis', 'degistir'])) {
        return 'Kullanıcı adları benzersizdir. Başkasında olan veya silinmiş hesaba ait adı alamazsın. Kendi kullanıcı adını Profilim ekranından 30 günde bir değiştirebilirsin.';
    }
    if ($has(['mesaj']) && $has(['nasil', 'yaz', 'gonder', 'at', 'yollarim'])) {
        return 'Mesaj göndermek için ana ekrandan bir oda aç, alttaki mesaj alanına yaz ve gönder simgesine bas. Enter gönderir; satır atlamak için Shift + Enter kullanabilirsin.';
    }
    if ($has(['mesaj']) && $has(['satir', 'bosluk', 'bicim', 'format'])) {
        return 'Mesajı doğal biçimde yazabilirsin. Özel biçim zorunlu değil; satır atlamak için Shift + Enter kullanabilirsin.';
    }
    if ($has(['resim', 'fotograf', 'gorsel', 'foto']) && $has(['mesaj', 'gonder', 'paylas', 'at'])) {
        return 'Odayı aç, mesaj alanının yanındaki ataç simgesine bas ve JPG, PNG veya WEBP formatında en fazla 5 MB resim seç. İstersen resmin altına açıklama da ekleyebilirsin.';
    }
    if ($has(['mesaj']) && $has(['sil', 'kaldir', 'geri al'])) {
        return 'Kendi mesajındaki işlem düğmesine basıp Sil seçeneğini kullan. Mesaj herkesten gizlenir; 4 saniye içinde Geri al dersen geri gelir, süre dolarsa kalıcı silinir.';
    }
    if ($has(['davet', 'kod'])) {
        return 'Davet kodu için sağ üstteki üç nokta menüsünden Davet Et bölümünü aç. Her kod yalnızca bir kişiyi içeri alır: kodunla biri kayıt olduğunda kod geçersiz olur ve ekranda gösterilmez. Kodlar saat başında yenilenir; yeni kod geldiğinde yeniden bir kişi davet edebilirsin.';
    }
    if ($has(['bildirim', 'yeni mesaj', 'okunmamis'])) {
        return 'Yeni mesaj olduğunda ana ekrandaki oda kartında okunmamış sayısı görünür. Odayı açınca yalnızca senin bildirimin temizlenir.';
    }
    if ($has(['oda', 'kanal']) && $has(['nasil', 'gir', 'ac', 'bul', 'katil'])) {
        return 'Ana ekrandaki topluluk odalarından birine dokunarak giriş yapabilirsin. Her oda kendi konusu için ayrılmıştır.';
    }
    if ($has(['goruldu', 'okundu', 'okundu bilgisi'])) {
        return 'Ayarlar bölümünden Görüldü gönder seçeneğini kapatırsan başkalarının mesajlarını okuduğunda görenler listesinde görünmezsin.';
    }
    if ($has(['son gorulme', 'cevrimici', 'gizlilik'])) {
        return 'Ayarlar bölümünde son görülme ve çevrimiçi durumunun görünüp görünmeyeceğini değiştirebilirsin.';
    }
    if ($has(['tema', 'karanlik', 'aydinlik', 'dil'])) {
        return 'Ayarlar bölümünden karanlık veya aydınlık tema seçebilir; Türkçe, İngilizce, Almanca ya da Fransızca dilini kullanabilirsin.';
    }
    if ($has(['sifre']) && $has(['unut', 'degis', 'yenile', 'sifirla'])) {
        return 'Şifreni unuttuysan yetkili, Admin Paneli üzerinden hesabının yanındaki Şifre Değiştir seçeneğiyle yeni şifre belirleyebilir. Değişiklikten sonra eski oturumların kapatılır.';
    }
    if ($has(['hesap']) && $has(['sil', 'kapat', 'kaldir'])) {
        return 'Hesabını Ayarlar bölümündeki Hesabımı sil seçeneğiyle silebilirsin. Kullanıcı adı ve şifre doğrulanır; işlem kalıcıdır ve geri alınamaz.';
    }
    if ($has(['ban', 'yasak'])) {
        return 'Süreli ban bitiş zamanına kadar, kalıcı ban ise yetkili kaldırana kadar erişimi engeller. Ban ekranında neden ve süre bilgisi gösterilir.';
    }
    if ($has(['duyuru'])) {
        return 'Yetkililer Admin Paneli üzerinden duyuruyu bir veya birden fazla odaya gönderebilir. Duyurular da 24 saatlik temizleme politikasına tabidir.';
    }
    if ($has(['24 saat', 'neden sil', 'mesajlar ne zaman', 'saklama', 'temizlenir'])) {
        return 'Mesajlar ve duyurular performansı ve veritabanı kapasitesini korumak için gönderildikten 24 saat sonra temizlenir.';
    }
    if ($has(['guvenlik', 'guvenli', 'acik', 'zafiyet'])) {
        return 'Platform güvenlik için HttpOnly ve SameSite oturum çerezleri, CSRF doğrulaması, hazırlanmış SQL sorguları, güçlü şifre hashleme, istek sınırlandırma, güvenli dosya doğrulaması ve cihaz bazlı kötüye kullanım koruması kullanır. Şifreni veya yönetim şifresini kimseyle paylaşma.';
    }
    if ($has(['sen kimsin', 'nesin', 'asra ai'])) {
        return 'Ben Asra AI, platform içindeki kullanım yardımcısıyım. Özellikleri nasıl kullanacağını açık ve kısa şekilde anlatırım.';
    }
    if ($has(['tesekkur', 'sag ol', 'eyvallah'])) {
        return 'Rica ederim! Başka bir konuda takılırsan yazabilirsin.';
    }
    return 'Dediğini anlamadım, daha net anlatır mısın?';
}

function aiWikipediaDefaultSources(): array
{
    return [[
        'title' => 'Wikipedia',
        'url' => 'https://tr.wikipedia.org/wiki/Anasayfa',
    ]];
}

function aiShouldConsultWikipedia(string $question, string $localReply): bool
{
    if ($localReply === 'Dediğini anlamadım, daha net anlatır mısın?' || aiScienceResponse($question) !== null) {
        return true;
    }

    $platformTerms = [
        'profil', 'avatar', 'mesaj', 'oda', 'kanal', 'davet', 'bildirim', 'ayar', 'tema', 'sifre',
        'hesap', 'ban', 'yasak', 'duyuru', 'giris', 'kayit', 'kullanici adi', 'goruldu', 'son gorulme',
        'asra', 'aa coin',
    ];
    if (aiTextMatches($question, $platformTerms)) {
        return false;
    }

    return aiTextMatches($question, [
        'nedir', 'ne demek', 'kimdir', 'kimdi', 'nerededir', 'ne zaman', 'neden', 'nasil calisir',
        'acikla', 'anlat', 'hakkinda', 'tarihi', 'tarihce', 'kavram', 'bilgi ver', 'ozetle',
    ]);
}

function aiWikipediaHttpGet(string $url): ?string
{
    $maxBytes = 524288;
    if (function_exists('curl_init')) {
        $body = '';
        $curl = curl_init($url);
        if ($curl === false) {
            return null;
        }
        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'AsraChat/1.0 (Wikipedia bilgi doğrulama)',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $ok !== false && $status === 200 ? $body : null;
    }

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => 6,
        'ignore_errors' => false,
        'header' => "Accept: application/json\r\nUser-Agent: AsraChat/1.0 (Wikipedia bilgi doğrulama)\r\n",
    ]]);
    $stream = @fopen($url, 'rb', false, $context);
    if ($stream === false) {
        return null;
    }
    $body = stream_get_contents($stream, $maxBytes + 1);
    fclose($stream);
    return is_string($body) && strlen($body) <= $maxBytes ? $body : null;
}

function aiTrimWikipediaExtract(string $extract, int $limit = 680): string
{
    $extract = html_entity_decode(strip_tags($extract), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $extract = preg_replace('/\s+/u', ' ', trim($extract)) ?: '';
    if ($extract === '' || textLength($extract) <= $limit) {
        return $extract;
    }

    $short = function_exists('mb_substr') ? mb_substr($extract, 0, $limit, 'UTF-8') : substr($extract, 0, $limit);
    $sentenceEnd = max((int) strrpos($short, '. '), (int) strrpos($short, '! '), (int) strrpos($short, '? '));
    if ($sentenceEnd >= (int) ($limit * 0.55)) {
        return rtrim(substr($short, 0, $sentenceEnd + 1));
    }
    $wordEnd = strrpos($short, ' ');
    return rtrim($wordEnd === false ? $short : substr($short, 0, $wordEnd)) . '…';
}

function aiWikipediaSearchQuery(string $query): string
{
    $query = repairLegacyTurkishText($query) ?? $query;
    $query = preg_replace('/[^\p{L}\p{N}\s\-\'’]+/u', ' ', $query) ?: $query;
    $query = preg_replace(
        '/\b(?:nedir|kimdir|kimdi|nerededir|neresidir|ne\s+demek|ne\s+anlama\s+gelir|açıkla|acikla|anlat|hakkında|hakkinda|bilgi\s+ver|özetle|ozetle|lütfen|lutfen)\b/ui',
        ' ',
        $query
    ) ?: $query;
    return trim(preg_replace('/\s+/u', ' ', $query) ?: '');
}

function aiWikipediaResponse(string $query): ?array
{
    $query = aiWikipediaSearchQuery($query);
    if ($query === '' || textLength($query) > 900) {
        return null;
    }

    $params = http_build_query([
        'action' => 'query',
        'format' => 'json',
        'formatversion' => 2,
        'generator' => 'search',
        'gsrsearch' => $query,
        'gsrnamespace' => 0,
        'gsrlimit' => 3,
        'prop' => 'extracts|info',
        'inprop' => 'url',
        'explaintext' => 1,
        'exchars' => 1200,
        'redirects' => 1,
        'utf8' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
    $body = aiWikipediaHttpGet('https://tr.wikipedia.org/w/api.php?' . $params);
    if ($body === null) {
        return null;
    }
    try {
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
    $pages = $payload['query']['pages'] ?? null;
    if (!is_array($pages)) {
        return null;
    }
    usort($pages, static fn(array $left, array $right): int => ((int) ($left['index'] ?? PHP_INT_MAX)) <=> ((int) ($right['index'] ?? PHP_INT_MAX)));

    foreach ($pages as $page) {
        if (!is_array($page) || isset($page['missing'])) {
            continue;
        }
        $title = trim((string) ($page['title'] ?? ''));
        $extract = aiTrimWikipediaExtract((string) ($page['extract'] ?? ''));
        if ($title === '' || textLength($extract) < 80) {
            continue;
        }
        $normalizedExtract = normalizedIntentText($extract);
        if (str_contains($normalizedExtract, 'su anlamlara gelebilir') || str_contains($normalizedExtract, 'anlam ayrimi')) {
            continue;
        }
        $url = (string) ($page['fullurl'] ?? '');
        if (!preg_match('~\Ahttps://tr\.wikipedia\.org/wiki/~', $url)) {
            $url = 'https://tr.wikipedia.org/wiki/' . rawurlencode(str_replace(' ', '_', $title));
        }
        return [
            'reply' => $extract,
            'sources' => [['title' => $title, 'url' => $url]],
        ];
    }
    return null;
}

function actionAiChat(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    consumeRequestLimit($pdo, rateKey('ai_chat', (string) $user['id']), 45, 60);
    $question = cleanText($data['message'] ?? null, 'AI mesajı', 600);
    $contextHint = '';
    $context = $data['context'] ?? [];
    if (!is_array($context) || count($context) > 10) {
        throw new ApiError(422, 'INVALID_AI_CONTEXT', 'AI sohbet bağlamı geçersiz.');
    }
    foreach (array_reverse($context) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $role = (string) ($entry['role'] ?? '');
        $text = $entry['text'] ?? null;
        if (!in_array($role, ['user', 'assistant'], true) || !is_string($text) || textLength($text) > 700) {
            throw new ApiError(422, 'INVALID_AI_CONTEXT', 'AI sohbet bağlamı geçersiz.');
        }
        if ($role === 'user' && trim($text) !== '' && trim($text) !== $question) {
            $contextHint = trim($text);
            break;
        }
    }
    $reply = asraAiResponse($question, $user['username'], $contextHint, $context);
    $sources = aiWikipediaDefaultSources();
    if (aiShouldConsultWikipedia($question, $reply)) {
        $wikiQuery = count(aiTokens($question)) <= 5 && $contextHint !== ''
            ? trim($contextHint . ' ' . $question)
            : $question;
        $wikipedia = aiWikipediaResponse($wikiQuery);
        if ($wikipedia !== null) {
            $reply = $wikipedia['reply'];
            $sources = $wikipedia['sources'];
        }
    }
    $reply = aiFallbackForRepeatedReply($reply, $context);
    if ($reply === 'Dediğini anlamadım, daha net anlatır mısın?') {
        aiLogSafetyEvent($pdo, (int) $user['id'], 'ai_unclear_intent', $question);
    }
    respond([
        'reply' => $reply,
        'sources' => $sources,
        'created_at' => gmdate(DateTimeInterface::ATOM),
        'transient' => true,
    ]);
}

function frameCatalog(): array
{
    return [
        'royal_crown' => ['name' => 'Kral Taçlı Çerçeve', 'emoji' => '👑', 'tone' => 'gold'],
        'bear_guard' => ['name' => 'Ayılı Çerçeve', 'emoji' => '🐻', 'tone' => 'bear'],
        'hero_flash' => ['name' => 'Süper Kahraman Çerçevesi', 'emoji' => '🦸', 'tone' => 'hero'],
        'star_nebula' => ['name' => 'Yıldız Nebula Çerçevesi', 'emoji' => '✨', 'tone' => 'star'],
        'dragon_fire' => ['name' => 'Ejder Ateşi Çerçevesi', 'emoji' => '🐉', 'tone' => 'dragon'],
        'ice_legend' => ['name' => 'Buz Efsanesi Çerçevesi', 'emoji' => '❄️', 'tone' => 'ice'],
    ];
}

function framePrice(string $frameId): int
{
    $hour = gmdate('YmdH');
    return 200 + (abs(crc32($frameId . '|' . $hour)) % 801);
}

function currentUserFresh(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!$user) {
        throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.');
    }
    return $user;
}

function actionGetEconomy(PDO $pdo): never
{
    sessionRateGuard('economy', 25, 30);
    $user = requireUser($pdo);
    closeSessionEarlyForReads('get_economy');
    $user = currentUserFresh($pdo, (int) $user['id']);
    $ownedStmt = $pdo->prepare('SELECT frame_id FROM aa_frame_purchases WHERE user_id = ?');
    $ownedStmt->execute([$user['id']]);
    $owned = array_flip($ownedStmt->fetchAll(PDO::FETCH_COLUMN));
    $items = [];
    foreach (frameCatalog() as $id => $frame) {
        $items[] = [
            'id' => $id,
            'name' => $frame['name'],
            'emoji' => $frame['emoji'],
            'tone' => $frame['tone'],
            'price' => framePrice($id),
            'owned' => isset($owned[$id]),
            'active' => ($user['active_profile_frame'] ?? null) === $id,
        ];
    }
    respond([
        'wallet' => ['aa_coins' => coinAmount($user['aa_coins'] ?? 0)],
        'items' => $items,
        'price_rotation_at' => nextHourIso(),
        'user' => publicUser($user),
    ]);
}

function actionBuyFrame(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $frameId = cleanText($data['frame_id'] ?? null, 'Çerçeve', 40);
    $catalog = frameCatalog();
    if (!isset($catalog[$frameId])) {
        throw new ApiError(404, 'FRAME_NOT_FOUND', 'Çerçeve bulunamadı.');
    }
    $price = framePrice($frameId);
    $pdo->beginTransaction();
    try {
        $locked = $pdo->prepare('SELECT aa_coins FROM users WHERE id = ? FOR UPDATE');
        $locked->execute([$user['id']]);
        $coins = coinAmount($locked->fetchColumn());
        $owned = $pdo->prepare('SELECT 1 FROM aa_frame_purchases WHERE user_id = ? AND frame_id = ?');
        $owned->execute([$user['id'], $frameId]);
        if ($owned->fetchColumn()) {
            throw new ApiError(409, 'FRAME_ALREADY_OWNED', 'Bu çerçeve zaten sende.');
        }
        if ($coins < $price) {
            throw new ApiError(402, 'INSUFFICIENT_COINS', 'Yeterli AA Coin yok.');
        }
        $pdo->prepare('UPDATE users SET aa_coins = aa_coins - ?, active_profile_frame = ? WHERE id = ?')->execute([$price, $frameId, $user['id']]);
        $pdo->prepare('INSERT INTO aa_frame_purchases (user_id, frame_id, price_paid) VALUES (?, ?, ?)')->execute([$user['id'], $frameId, $price]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $fresh = currentUserFresh($pdo, (int) $user['id']);
    respond(['message' => 'Çerçeve satın alındı ve takıldı.', 'user' => publicUser($fresh), 'wallet' => ['aa_coins' => coinAmount($fresh['aa_coins'] ?? 0)]]);
}

function actionEquipFrame(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $frameId = cleanText($data['frame_id'] ?? '', 'Çerçeve', 40, false);
    if ($frameId !== '' && !isset(frameCatalog()[$frameId])) {
        throw new ApiError(404, 'FRAME_NOT_FOUND', 'Çerçeve bulunamadı.');
    }
    if ($frameId !== '') {
        $owned = $pdo->prepare('SELECT 1 FROM aa_frame_purchases WHERE user_id = ? AND frame_id = ?');
        $owned->execute([$user['id'], $frameId]);
        if (!$owned->fetchColumn()) {
            throw new ApiError(403, 'FRAME_NOT_OWNED', 'Bu çerçeve sende yok.');
        }
    }
    $pdo->prepare('UPDATE users SET active_profile_frame = ? WHERE id = ?')->execute([$frameId === '' ? null : $frameId, $user['id']]);
    $fresh = currentUserFresh($pdo, (int) $user['id']);
    respond(['message' => $frameId === '' ? 'Çerçeve çıkarıldı.' : 'Çerçeve takıldı.', 'user' => publicUser($fresh)]);
}

function questRotationDate(): string
{
    return gmdate('Ymd');
}

function questRotationEndsAt(): string
{
    return (new DateTimeImmutable('tomorrow', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
}

function questPool(): array
{
    return [
        ['base_id' => 'first_message', 'title' => 'İlk mesajını gönder', 'description' => 'Bugün herhangi bir odaya 1 mesaj yaz.', 'target' => 1, 'reward' => 30],
        ['base_id' => 'hello_5_rooms', 'title' => '5 odaya merhaba yaz', 'description' => 'Bugün 5 farklı odada merhaba, selam veya benzeri karşılama içeren mesaj yaz.', 'target' => 5, 'reward' => 100],
        ['base_id' => 'stay_5_minutes', 'title' => 'Platformda 5 dakika kal', 'description' => 'Bugünkü aktif kullanım süren 5 dakikaya ulaşsın.', 'target' => 300, 'reward' => 75],
        ['base_id' => 'send_10_messages', 'title' => '10 mesaj gönder', 'description' => 'Bugün toplam 10 mesaj gönder.', 'target' => 10, 'reward' => 80],
        ['base_id' => 'profile_photo', 'title' => 'Profilini güzelleştir', 'description' => 'Profil fotoğrafı ekle.', 'target' => 1, 'reward' => 60],
        ['base_id' => 'visit_3_rooms', 'title' => '3 odayı ziyaret et', 'description' => 'Bugün en az 3 farklı odaya gir.', 'target' => 3, 'reward' => 50],
        ['base_id' => 'win_3_games', 'title' => '3 oyun kazan', 'description' => 'Bugün oyunlar bölümünde 3 oyun kazan.', 'target' => 3, 'reward' => 90],
        ['base_id' => 'send_image', 'title' => 'Bir görsel paylaş', 'description' => 'Bugün bir odaya resimli mesaj gönder.', 'target' => 1, 'reward' => 70],
        ['base_id' => 'read_rooms_5', 'title' => '5 odaya göz at', 'description' => 'Bugün 5 farklı odayı aç.', 'target' => 5, 'reward' => 65],
        ['base_id' => 'win_1_game', 'title' => 'Bir oyun kazan', 'description' => 'Bugün oyunlardan birini kazan.', 'target' => 1, 'reward' => 35],
        ['base_id' => 'send_25_messages', 'title' => 'Sohbeti canlandır', 'description' => 'Bugün toplam 25 mesaj gönder.', 'target' => 25, 'reward' => 150],
        ['base_id' => 'hello_2_rooms', 'title' => '2 odaya selam ver', 'description' => 'Bugün 2 farklı odada merhaba, selam veya benzeri karşılama yaz.', 'target' => 2, 'reward' => 45],
    ];
}

function questCatalog(): array
{
    $date = questRotationDate();
    $pool = questPool();
    $required = ['hello_5_rooms', 'stay_5_minutes'];
    $selected = [];
    foreach ($pool as $quest) {
        if (in_array($quest['base_id'], $required, true)) {
            $selected[$quest['base_id']] = $quest;
        }
    }
    $rotating = array_values(array_filter($pool, static fn(array $quest): bool => !isset($selected[$quest['base_id']])));
    usort($rotating, static fn(array $a, array $b): int => (crc32($date . '|' . $a['base_id']) <=> crc32($date . '|' . $b['base_id'])));
    foreach (array_slice($rotating, 0, 5) as $quest) {
        $selected[$quest['base_id']] = $quest;
    }
    $catalog = [];
    foreach (array_values($selected) as $quest) {
        $quest['id'] = $quest['base_id'] . ':' . $date;
        $quest['rotation_date'] = $date;
        $catalog[] = $quest;
    }
    return $catalog;
}

function questProgress(PDO $pdo, array $user): array
{
    $userId = (int) $user['id'];
    $todayStart = gmdate('Y-m-d 00:00:00');
    $messageStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at >= ?');
    $messageStmt->execute([$userId, $todayStart]);
    $messageCount = (int) $messageStmt->fetchColumn();
    $helloStmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT room_id) FROM messages
         WHERE user_id = ? AND created_at >= ? AND content IS NOT NULL
           AND LOWER(content) REGEXP '(^|[^[:alnum:]])(merhaba|selam|selamlar|hello|hi|hey|sa|s[.]a)([^[:alnum:]]|$)'"
    );
    $helloStmt->execute([$userId, $todayStart]);
    $helloRooms = (int) $helloStmt->fetchColumn();
    $visitedStmt = $pdo->prepare('SELECT COUNT(*) FROM room_reads WHERE user_id = ? AND last_read_at >= ?');
    $visitedStmt->execute([$userId, $todayStart]);
    $visitedRooms = (int) $visitedStmt->fetchColumn();
    $gamesStmt = $pdo->prepare('SELECT COUNT(*) FROM aa_game_rewards WHERE user_id = ? AND rewarded_at >= ?');
    $gamesStmt->execute([$userId, $todayStart]);
    $gameWins = (int) $gamesStmt->fetchColumn();
    $imageStmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at >= ? AND image_path IS NOT NULL');
    $imageStmt->execute([$userId, $todayStart]);
    $imageMessages = (int) $imageStmt->fetchColumn();
    $dailyStmt = $pdo->prepare('SELECT active_seconds FROM aa_daily_activity WHERE user_id = ? AND activity_date = UTC_DATE()');
    $dailyStmt->execute([$userId]);
    $dailyActiveSeconds = (int) ($dailyStmt->fetchColumn() ?: 0);
    $claimedStmt = $pdo->prepare('SELECT quest_id FROM aa_quest_claims WHERE user_id = ?');
    $claimedStmt->execute([$userId]);
    $claimed = array_flip($claimedStmt->fetchAll(PDO::FETCH_COLUMN));
    $values = [
        'first_message' => min(1, $messageCount),
        'hello_5_rooms' => $helloRooms,
        'stay_5_minutes' => $dailyActiveSeconds,
        'send_10_messages' => $messageCount,
        'profile_photo' => empty($user['profile_image_path']) ? 0 : 1,
        'visit_3_rooms' => $visitedRooms,
        'win_3_games' => $gameWins,
        'send_image' => min(1, $imageMessages),
        'read_rooms_5' => $visitedRooms,
        'win_1_game' => min(1, $gameWins),
        'send_25_messages' => $messageCount,
        'hello_2_rooms' => $helloRooms,
    ];
    $quests = [];
    foreach (questCatalog() as $quest) {
        $progress = min((int) $quest['target'], (int) ($values[$quest['base_id']] ?? 0));
        $hasDebt = coinAmount($user['aa_coins'] ?? 0) < 0;
        $reward = coinAmount($quest['reward'] * ($hasDebt ? 2 : 1));
        $quest['reward'] = $reward;
        $quest['debt_bonus'] = $hasDebt;
        $quest['progress'] = $progress;
        $quest['completed'] = $progress >= (int) $quest['target'];
        $quest['claimed'] = isset($claimed[$quest['id']]);
        $quest['next_rotation_at'] = questRotationEndsAt();
        $quests[] = $quest;
    }
    return $quests;
}

function actionGetQuests(PDO $pdo): never
{
    sessionRateGuard('quests', 25, 30);
    $user = requireUser($pdo);
    closeSessionEarlyForReads('get_quests');
    $user = currentUserFresh($pdo, (int) $user['id']);
    respond(['quests' => questProgress($pdo, $user), 'next_rotation_at' => questRotationEndsAt(), 'wallet' => ['aa_coins' => coinAmount($user['aa_coins'] ?? 0)], 'user' => publicUser($user)]);
}

function actionClaimQuest(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $user = currentUserFresh($pdo, (int) $user['id']);
    $questId = cleanText($data['quest_id'] ?? null, 'Görev', 60);
    $quests = questProgress($pdo, $user);
    $quest = null;
    foreach ($quests as $candidate) if ($candidate['id'] === $questId) $quest = $candidate;
    if (!$quest) throw new ApiError(404, 'QUEST_NOT_FOUND', 'Görev bulunamadı.');
    if (!$quest['completed']) throw new ApiError(409, 'QUEST_NOT_COMPLETED', 'Görev henüz tamamlanmadı.');
    if ($quest['claimed']) throw new ApiError(409, 'QUEST_ALREADY_CLAIMED', 'Bu görev ödülü zaten alındı.');
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO aa_quest_claims (user_id, quest_id, reward) VALUES (?, ?, ?)')->execute([$user['id'], $questId, $quest['reward']]);
        $pdo->prepare('UPDATE users SET aa_coins = aa_coins + ? WHERE id = ?')->execute([$quest['reward'], $user['id']]);
        $pdo->commit();
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string) $error->getCode() === '23000') {
            throw new ApiError(409, 'QUEST_ALREADY_CLAIMED', 'Bu görev ödülü zaten alındı.');
        }
        throw $error;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $fresh = currentUserFresh($pdo, (int) $user['id']);
    respond(['message' => $quest['reward'] . ' AA Coin kazandın.', 'user' => publicUser($fresh), 'wallet' => ['aa_coins' => coinAmount($fresh['aa_coins'] ?? 0)]]);
}

function actionClaimGameReward(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $gameId = cleanText($data['game_id'] ?? null, 'Oyun', 60);
    if (!in_array($gameId, ['sky_dash', 'lane_rush', 'snake_bite'], true)) {
        throw new ApiError(404, 'GAME_NOT_FOUND', 'Oyun bulunamadı.');
    }
    $score = max(0, min(100000, (int) ($data['score'] ?? 0)));
    $recent = $pdo->prepare('SELECT COUNT(*) FROM aa_game_rewards WHERE user_id = ? AND game_id = ? AND rewarded_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 20 SECOND)');
    $recent->execute([$user['id'], $gameId]);
    if ((int) $recent->fetchColumn() > 0) {
        throw new ApiError(429, 'GAME_REWARD_COOLDOWN', 'Bu oyun için yeni ödül almadan önce kısa süre bekle.');
    }
    $daily = $pdo->prepare('SELECT COUNT(*) FROM aa_game_rewards WHERE user_id = ? AND rewarded_at >= UTC_DATE()');
    $daily->execute([$user['id']]);
    if ((int) $daily->fetchColumn() >= 100) {
        throw new ApiError(429, 'DAILY_GAME_LIMIT', 'Günlük oyun ödülü sınırına ulaştın.');
    }
    $reward = 0.1;
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO aa_game_rewards (user_id, game_id, reward, score) VALUES (?, ?, ?, ?)')->execute([$user['id'], $gameId, $reward, $score]);
        $pdo->prepare('UPDATE users SET aa_coins = aa_coins + ? WHERE id = ?')->execute([$reward, $user['id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    $fresh = currentUserFresh($pdo, (int) $user['id']);
    respond(['message' => 'Oyunu kazandın: 0.1 AA Coin eklendi.', 'user' => publicUser($fresh), 'wallet' => ['aa_coins' => coinAmount($fresh['aa_coins'] ?? 0)]]);
}

function actionAdminAdjustCoins(PDO $pdo, array $data): never
{
    $admin = requireAdmin($pdo);
    $userId = integerField($data['user_id'] ?? null, 'Kullanıcı');
    $mode = cleanText($data['mode'] ?? null, 'Coin işlemi', 20);
    if (!in_array($mode, ['grant', 'take', 'debt'], true)) {
        throw new ApiError(422, 'INVALID_COIN_ACTION', 'Coin işlemi geçersiz.');
    }
    $amount = parseCoinAmount($data['amount'] ?? null, 'Coin miktarı');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, username, aa_coins, coin_debt_due_at, approval_status FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $target = $stmt->fetch();
        if (!$target) {
            throw new ApiError(404, 'USER_NOT_FOUND', 'Kullanıcı bulunamadı.');
        }
        if (($target['approval_status'] ?? 'approved') === 'pending') {
            throw new ApiError(409, 'APPROVAL_PENDING', 'Bu hesap henüz onay bekliyor; coin işlemi onaydan sonra yapılabilir.');
        }
        $previous = coinAmount($target['aa_coins'] ?? 0);
        if ($mode === 'grant') {
            $newBalance = coinAmount($previous + $amount);
            $debtCleared = $newBalance >= 0;
            $update = $pdo->prepare('UPDATE users SET aa_coins = ?, coin_debt_due_at = IF(? = 1, NULL, coin_debt_due_at), coin_debt_notice_at = IF(? = 1, NULL, coin_debt_notice_at) WHERE id = ?');
            $update->execute([$newBalance, $debtCleared ? 1 : 0, $debtCleared ? 1 : 0, $userId]);
        } elseif ($mode === 'take') {
            if ($amount > $previous) {
                throw new ApiError(409, 'INSUFFICIENT_TARGET_COINS', 'Kullanıcının bu kadar AA Coin bakiyesi yok.');
            }
            $newBalance = coinAmount($previous - $amount);
            $pdo->prepare('UPDATE users SET aa_coins = ? WHERE id = ?')->execute([$newBalance, $userId]);
        } else {
            $newBalance = coinAmount($previous - $amount);
            $createdDebt = $newBalance < 0;
            $startsNewDebt = $createdDebt && ($previous >= 0 || $target['coin_debt_due_at'] === null);
            $update = $pdo->prepare(
                'UPDATE users
                 SET aa_coins = ?,
                     coin_debt_due_at = IF(? = 1, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MONTH), coin_debt_due_at),
                     coin_debt_notice_at = IF(? = 1, UTC_TIMESTAMP(), coin_debt_notice_at)
                 WHERE id = ?'
            );
            $update->execute([$newBalance, $startsNewDebt ? 1 : 0, $createdDebt ? 1 : 0, $userId]);
        }
        $log = $pdo->prepare('INSERT INTO aa_coin_adjustments (admin_id, user_id, action_type, amount, previous_balance, new_balance) VALUES (?, ?, ?, ?, ?, ?)');
        $log->execute([$admin['id'], $userId, $mode, $amount, $previous, $newBalance]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
    $fresh = currentUserFresh($pdo, $userId);
    $messages = [
        'grant' => 'Kullanıcıya AA Coin verildi.',
        'take' => 'Kullanıcıdan AA Coin alındı.',
        'debt' => $newBalance < 0 ? 'Borç uygulandı; kullanıcının bakiyesi eksiye düştü.' : 'Borç tutarı bakiyeden tahsil edildi; eksi bakiye oluşmadı.',
    ];
    respond(['message' => $messages[$mode], 'user' => publicUser($fresh), 'previous_balance' => $previous, 'new_balance' => coinAmount($fresh['aa_coins'] ?? 0)]);
}

/**
 * Yetkili panel "Kod" bölümü — durum: aktif kod + anahtar durumu.
 * Kod kapalıyken değeri hiç gösterilmez (geçersizdir zaten).
 */
/**
 * Yetkili panel "Kod" bölümü — aç/kapat anahtarı.
 * AÇ: otomatik YENİ kod üretilir ve aktif olur (sınırsız erişim kodu).
 * KAPAT: kod geçersiz olur ve kayıttan silinir; tekrar açılınca eski kod
 * GEÇERSİZ kalır, taze kod üretilir.
 */
function createApprovedWelcomeMessage(PDO $pdo, string $username): void
{
    $roomId = (int) $pdo->query("SELECT id FROM rooms WHERE name = 'Asra Genel' LIMIT 1")->fetchColumn();
    if ($roomId <= 0) {
        return;
    }
    $content = 'Hoş geldin ' . $username;
    $stmt = $pdo->prepare(
        "INSERT INTO messages (room_id, user_id, type, content)
         SELECT ?, NULL, 'system', ? FROM DUAL
         WHERE NOT EXISTS (
             SELECT 1 FROM messages
             WHERE room_id = ? AND user_id IS NULL AND type = 'system' AND content = ?
             LIMIT 1
         )"
    );
    $stmt->execute([$roomId, $content, $roomId, $content]);
}

function cleanupPrematureWelcomeMessages(PDO $pdo): void
{
    $pdo->exec(
        "DELETE m FROM messages m
         INNER JOIN users u
           ON u.approval_status = 'pending'
          AND m.user_id IS NULL
          AND m.type = 'system'
          AND m.content = CONCAT('Hoş geldin ', u.username)"
    );
}

function actionSubmitApproval(PDO $pdo, array $data): never
{
    $user = requireAnySessionUser($pdo);
    if (($user['approval_status'] ?? 'approved') !== 'pending') {
        throw new ApiError(409, 'APPROVAL_NOT_REQUIRED', 'Bu hesap zaten onaylanmış.');
    }
    $reason = cleanText($data['reason'] ?? null, 'Katılma nedeni', 1000);
    $autoPassword = envValue('ASRA_ADMIN_PASSWORD', DEFAULT_ADMIN_PASSWORD);
    if ($autoPassword !== '' && hash_equals($autoPassword, $reason)) {
        runTransaction($pdo, static function (PDO $database) use ($user): void {
            $update = $database->prepare(
                "UPDATE users
                 SET approval_status = 'approved', approval_reason = ?, approval_submitted_at = UTC_TIMESTAMP(),
                     approval_decision_notice = 'accepted', approval_decision_at = UTC_TIMESTAMP()
                 WHERE id = ? AND approval_status = 'pending'"
            );
            $update->execute(['Otomatik onay', $user['id']]);
            if ($update->rowCount() === 1) {
                createApprovedWelcomeMessage($database, (string) $user['username']);
            }
        });
        respond(['message' => 'Kabul edildin.', 'approved' => true, 'user' => publicUser(currentUserFresh($pdo, (int) $user['id']))]);
    }
    $pdo->prepare('UPDATE users SET approval_reason = ?, approval_submitted_at = UTC_TIMESTAMP() WHERE id = ?')->execute([$reason, $user['id']]);
    respond(['message' => 'Onay başvurun gönderildi.', 'approved' => false, 'user' => publicUser(currentUserFresh($pdo, (int) $user['id']))]);
}

function formatUtc(?string $value): ?string
{
    if ($value === null) {
        return null;
    }
    $value = trim($value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    $timestamp = strtotime($value . ' UTC');
    if ($timestamp === false) {
        $timestamp = strtotime($value);
    }
    return $timestamp === false ? null : gmdate(DateTimeInterface::ATOM, $timestamp);
}

/**
 * Tanı ucu: "site açılmıyor" durumunu TEK istekte teşhis eder.
 *
 * Tarayıcıdan /auth.php?action=health açıldığında sunucunun gerçekten JSON
 * üretip üretmediğini, veritabanına bağlanıp bağlanmadığını ve şemanın kurulu
 * olup olmadığını gösterir. Barındırma sağlayıcısının HTML hata/koruma
 * sayfası ile uygulamanın kendi hatası bu uçla ayırt edilir.
 *
 * Güvenlik: yalnızca okunur bilgi döner. Parola, kullanıcı adı ve oturum
 * verisi SIZMAZ; DSN'den yalnızca sunucu adı ve veritabanı adı gösterilir.
 * Kimlik doğrulama istemez, çünkü amacı tam da giriş yapılamayan durumu
 * teşhis etmektir. Bu yüzden tüm hız sınırlarının ÖNÜNDE çalışır.
 */
function actionHealth(): never
{
    $dsn = envValue(
        'ASRA_DB_DSN',
        ''
    );
    $report = [
        'ok' => true,
        'build' => ASRA_BUILD,
        'php' => PHP_VERSION,
        'utc' => gmdate('c'),
        'db_host' => preg_match('/host=([^;]+)/', $dsn, $m) ? $m[1] : null,
        'db_name' => preg_match('/dbname=([^;]+)/', $dsn, $m) ? $m[1] : null,
        'db_connected' => false,
        'tables' => 0,
        'rooms' => 0,
        'schema_stamps' => [],
    ];
    try {
        // db() yerine DOĞRUDAN bağlantı denenir: db() geçici hataları
        // "Sunucu şu anda yoğun" mesajına çevirir, oysa teşhis için gerçek
        // neden (erişim reddi, çözümlenemeyen sunucu adı, yanlış veritabanı)
        // görünmelidir.
        $pdo = new PDO($dsn, envValue('ASRA_DB_USER', ''), envValue('ASRA_DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 8,
        ]);
        $report['db_connected'] = true;
        $report['tables'] = (int) $pdo->query(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'
        )->fetchColumn();
        $report['rooms'] = (int) $pdo->query('SELECT COUNT(*) FROM rooms')->fetchColumn();
        $stamps = $pdo->prepare("SELECT rate_key FROM rate_limits WHERE rate_key LIKE 'schema:%' ORDER BY rate_key");
        $stamps->execute();
        $report['schema_stamps'] = $stamps->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $error) {
        $report['ok'] = false;
        $report['db_error'] = $error->getMessage();
    }
    respond($report, $report['ok'] ? 200 : 503);
}

function actionAdminPendingApprovals(PDO $pdo): never
{
    requireAdmin($pdo);
    try {
        cleanupPrematureWelcomeMessages($pdo);
    } catch (Throwable $error) {
        error_log('[approval] premature welcome cleanup skipped: ' . $error->getMessage());
    }
    // Bekleyen HER hesap listede görünmelidir: nedeni henüz yazılmamış
    // hesapları gizlemek, moderatörün karar verememesi demek (yeni kayıtlar
    // "panele düşmüyor" şikâyetinin kökü buydu). Gönderilmiş başvurular önde,
    // nedeni yazılmamışlar kayıt tarihine göre arkadan gelir.
    $stmt = $pdo->query(
        "SELECT id, username, approval_reason, approval_submitted_at, created_at
         FROM users
         WHERE approval_status = 'pending'
         ORDER BY (approval_submitted_at IS NOT NULL) DESC, approval_submitted_at ASC, created_at ASC, id ASC
         LIMIT 200"
    );
    $items = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'username' => repairLegacyTurkishText($row['username']),
            'reason' => repairLegacyTurkishText($row['approval_reason'] ?? '') ?: '',
            'submitted_at' => formatUtc($row['approval_submitted_at'] ?? null),
            'created_at' => formatUtc($row['created_at'] ?? null),
            'submitted' => !empty($row['approval_submitted_at']),
        ];
    }
    // includes_unsubmitted: bu yanıtın nedeni yazılmamış bekleyen hesapları
    // DA kapsadığını bildirir. Alan yoksa sunucuda eski auth.php vardır ve
    // istemci moderatöre bunu açıkça söyler (sessiz boş liste bırakmaz).
    respond([
        'pending' => $items,
        'total' => count($items),
        'includes_unsubmitted' => true,
        'limit' => 200,
        'build' => ASRA_BUILD,
    ]);
}

function actionAdminDecideApproval(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $userId = integerField($data['user_id'] ?? null, 'Kullanıcı');
    $decision = cleanText($data['decision'] ?? null, 'Karar', 20);
    if (!in_array($decision, ['accept', 'reject'], true)) {
        throw new ApiError(422, 'INVALID_APPROVAL_DECISION', 'Onay kararı geçersiz.');
    }

    $lockName = 'asra_approval_' . $userId;
    $locked = false;
    $response = [];
    try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 3)');
        $lockStmt->execute([$lockName]);
        $locked = (int) $lockStmt->fetchColumn() === 1;
        if (!$locked) {
            throw new ApiError(409, 'APPROVAL_BUSY', 'Bu başvuru üzerinde başka bir işlem sürüyor. Liste yenileniyor.', ['retry_after' => 2]);
        }

        if ($decision === 'accept') {
            $result = runTransaction($pdo, static function (PDO $database) use ($userId): array {
                $stmt = $database->prepare('SELECT id, username, approval_status FROM users WHERE id = ? FOR UPDATE');
                $stmt->execute([$userId]);
                $target = $stmt->fetch();
                if (!$target) {
                    throw new ApiError(404, 'APPROVAL_NOT_FOUND', 'Başvuru bulunamadı.');
                }
                if (($target['approval_status'] ?? '') === 'approved') {
                    createApprovedWelcomeMessage($database, (string) $target['username']);
                    return ['already_decided' => true, 'username' => (string) $target['username']];
                }
                $update = $database->prepare(
                    "UPDATE users
                     SET approval_status = 'approved', approval_decision_notice = 'accepted', approval_decision_at = UTC_TIMESTAMP()
                     WHERE id = ? AND approval_status = 'pending'"
                );
                $update->execute([$userId]);
                if ($update->rowCount() !== 1) {
                    throw new ApiError(409, 'APPROVAL_STATE_CHANGED', 'Başvurunun durumu başka bir işlem tarafından değiştirildi.');
                }
                createApprovedWelcomeMessage($database, (string) $target['username']);
                return ['already_decided' => false, 'username' => (string) $target['username']];
            });
            $response = [
                'message' => $result['already_decided'] ? 'Başvuru daha önce kabul edilmiş.' : 'Başvuru kabul edildi.',
                'already_decided' => (bool) $result['already_decided'],
                'user_id' => $userId,
                'username' => $result['username'],
            ];
        } else {
            $stmt = $pdo->prepare('SELECT id, username, approval_status FROM users WHERE id = ?');
            $stmt->execute([$userId]);
            $target = $stmt->fetch();
            if (!$target) {
                $response = ['message' => 'Başvuru daha önce reddedilmiş veya hesap kaldırılmış.', 'already_decided' => true, 'user_id' => $userId];
            } else {
                if (($target['approval_status'] ?? '') !== 'pending') {
                    throw new ApiError(409, 'APPROVAL_ALREADY_ACCEPTED', 'Bu hesap daha önce kabul edilmiş; reddetmek için hesap yönetimini kullanın.');
                }
                rememberApprovalBlockForUser($pdo, $userId, $target['username'], 'rejected', 'Reddedildin. Yeni başvuru göndermeden önce 5 gün beklemelisin.', 5);
                deleteUserPermanently($pdo, $userId, 'Onay başvurusu yönetici tarafından reddedildi.', 'pending');
                $response = ['message' => 'Başvuru reddedildi ve hesap silindi.', 'already_decided' => false, 'user_id' => $userId];
            }
        }
    } finally {
        if ($locked) {
            try {
                $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lockName]);
            } catch (Throwable $error) {
                error_log('[approval] lock release skipped: ' . $error->getMessage());
            }
        }
    }
    respond($response);
}

function actionGetMedia(PDO $pdo): never
{
    requireMethod('GET');
    // Avatarlar ve mesaj görselleri aynı anda onlarca istek üretir. Oturum kilidi
    // en erken anda bırakılır; aksi hâlde istekler birbirini bekletir, zaman
    // aşımına uğrar ve tarayıcı kırık görsel gösterir.
    $userId = releaseSessionEarly();
    if (!$userId) {
        throw new ApiError(401, 'AUTH_REQUIRED', 'Oturum açmanız gerekiyor.');
    }
    $id = integerField($_GET['id'] ?? null, 'Medya');
    $token = strtolower(trim((string) ($_GET['token'] ?? '')));
    if (!preg_match('/\A[a-f0-9]{32}\z/', $token)) {
        throw new ApiError(404, 'MEDIA_NOT_FOUND', 'Medya bulunamadı.');
    }

    $viewer = $pdo->prepare(
        'SELECT u.id FROM users u
         LEFT JOIN banned_users b ON b.user_id = u.id AND (b.banned_until IS NULL OR b.banned_until > UTC_TIMESTAMP())
         WHERE u.id = ? AND b.id IS NULL LIMIT 1'
    );
    $viewer->execute([$userId]);
    if (!$viewer->fetchColumn()) {
        throw new ApiError(403, 'MEDIA_FORBIDDEN', 'Bu medyaya erişim yetkiniz yok.');
    }

    $etag = '"media-' . $id . '-' . substr($token, 0, 16) . '"';
    $requestTag = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    if ($requestTag !== '' && (str_contains($requestTag, $etag) || $requestTag === '*')) {
        header_remove('Pragma');
        header('Cache-Control: private, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        http_response_code(304);
        exit;
    }

    $stmt = $pdo->prepare('SELECT mime_type, content FROM media_files WHERE id = ? AND access_token = ? LIMIT 1');
    $stmt->execute([$id, $token]);
    $media = $stmt->fetch();
    if (!$media) {
        throw new ApiError(404, 'MEDIA_NOT_FOUND', 'Medya bulunamadı.');
    }

    $allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $mimeType = in_array((string) $media['mime_type'], $allowedTypes, true)
        ? (string) $media['mime_type']
        : 'application/octet-stream';
    $binary = is_resource($media['content']) ? (string) stream_get_contents($media['content']) : (string) $media['content'];

    ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header_remove('Pragma');
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($binary));
    header('Content-Disposition: inline');
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    echo $binary;
    exit;
}

function actionHeartbeat(PDO $pdo): never
{
    $user = requireUser($pdo);
    $fresh = currentUserFresh($pdo, (int) $user['id']);
    $public = publicUser($fresh);
    consumeApprovalDecisionNotice($pdo, $fresh);
    respond([
        'server_time' => gmdate(DateTimeInterface::ATOM),
        'user' => $public,
        // Web istemci heartbeat'te de bayrağı okur; yoklama ritimi kalıcıdır.
        'hit_economy' => true,
    ]);
}

// ============================================================================
//  BİLGİ ODASI + ŞİKAYET SİSTEMİ — sunucu eylemleri (auth.php gövdesine
//  gömülür). Bilgi Odası (oda id 0): haber (otomatik RSS + panel), kaynaklar,
//  dersler, çalışma kağıtları, formlar (beğeni + yorum) ve oda beğenisi.
//  Şikayet: tüm sohbet mesajları + form + form yorumları; günde 5; yönetim
//  sekmesi (listele/reddet/ban için kullanıcıya git).
// ============================================================================

// ---------- yardımcılar ----------
/** Temiz isteğe bağlı metin: null/eksik -> '' (cleanText null'da hata fırlatır). */
function infoText(array $data, string $key, string $label, int $max, bool $required = false): string
{
    $value = $data[$key] ?? null;
    if ($value === null) {
        if ($required) {
            throw new ApiError(422, 'VALIDATION_ERROR', $label . ' boş bırakılamaz.');
        }
        return '';
    }
    return cleanText($value, $label, $max, $required);
}

// ---------- platform ayar deposu (site_settings) ----------
function infoSettingsAll(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $rows = [];
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM site_settings') as $row) {
            $rows[(string) $row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
    } catch (Throwable $error) {
        error_log('[info] site_settings okuma hatası: ' . $error->getMessage());
    }
    $cache = $rows;
    return $cache;
}

function infoSetting(PDO $pdo, string $key, string $default = ''): string
{
    $all = infoSettingsAll($pdo);
    $value = (string) ($all[$key] ?? '');
    return $value === '' ? $default : $value;
}

function infoSetSetting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}

/** UTC tarihini ISO 8601 yapar; boşsa null döner. */
function infoIso(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    $stamp = strtotime($value . ' UTC');
    return $stamp ? gmdate(DateTimeInterface::ATOM, $stamp) : null;
}

function infoFeedUrls(PDO $pdo): array
{
    $raw = infoSetting($pdo, 'feed_urls', '');
    if ($raw === '') {
        $raw = envValue('ASRA_INFO_FEED_URLS', '');
    }
    if ($raw === '') {
        $raw = DEFAULT_INFO_FEED_URLS;
    }
    $lines = preg_split('/[\r\n,]+/', $raw) ?: [];
    $urls = [];
    foreach ($lines as $line) {
        $url = trim((string) $line);
        if ($url === '') {
            continue;
        }
        $parts = parse_url($url);
        if ($parts && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) && isset($parts['host'])) {
            $urls[] = $url;
        }
    }
    return array_values(array_unique($urls));
}

function infoFetchUrlText(string $url): string
{
    $userAgent = 'AsraChat-InfoBot/1.0 (+bilgi odası haber akışı)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => INFO_FEED_HTTP_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => INFO_FEED_HTTP_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = (string) curl_error($ch);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 300) {
            return (string) $body;
        }
        throw new RuntimeException('HTTP ' . $code . ' ' . $error);
    }
    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'timeout' => INFO_FEED_HTTP_TIMEOUT,
        'header' => "User-Agent: " . $userAgent . "\r\nAccept: */*\r\n",
        'follow_location' => 1,
        'max_redirects' => 4,
    ]]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('Kaynak okunamadı: ' . $url);
    }
    return $body;
}

/**
 * RSS 2.0 / Atom beslemesini ayrıştırır; hiçbir uzantı yoksa regex yedeği.
 * Dönen her öğe: title, link, summary, published (UTC Y-m-d H:i:s ya da null), guid.
 */
function infoParseFeed(string $xml, string $url): array
{
    $items = [];
    $sourceHost = (string) (parse_url($url, PHP_URL_HOST) ?: 'besleme');
    $guidBase = $sourceHost;
    $push = static function (array $item) use (&$items, $guidBase): void {
        $title = trim((string) ($item['title'] ?? ''));
        $link = trim((string) ($item['link'] ?? ''));
        if ($title === '' && $link === '') {
            return;
        }
        $rawGuid = trim((string) ($item['guid'] ?? ''));
        if ($rawGuid === '') {
            $rawGuid = $link !== '' ? $link : $guidBase . '|' . $title;
        }
        $items[] = [
            'title' => mb_substr($title, 0, 300),
            'link' => mb_substr($link, 0, 700),
            'summary' => mb_substr((string) ($item['summary'] ?? ''), 0, 4000),
            'published' => isset($item['published']) && $item['published'] !== '' ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $item['published'])) : null,
            'guid' => $guidBase . '|' . $rawGuid,
        ];
    };
    $extractText = static function ($node): string {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $node)) ?? '');
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    };

    if (function_exists('simplexml_load_string')) {
        $previous = libxml_use_internal_errors(true);
        $feed = simplexml_load_string($xml);
        if ($feed !== false) {
            // Atom: <entry>
            if (isset($feed->entry)) {
                foreach ($feed->entry as $entry) {
                    $push([
                        'title' => $extractText($entry->title),
                        'link' => isset($entry->link['href']) ? trim((string) $entry->link['href']) : '',
                        'summary' => isset($entry->summary) ? $extractText($entry->summary) : (isset($entry->content) ? $extractText($entry->content) : ''),
                        'published' => isset($entry->published) ? trim((string) $entry->published) : (isset($entry->updated) ? trim((string) $entry->updated) : ''),
                        'guid' => isset($entry->id) ? trim((string) $entry->id) : '',
                    ]);
                }
            }
            // RSS 2.0: <channel><item>
            if (isset($feed->channel->item)) {
                foreach ($feed->channel->item as $item) {
                    $summary = isset($item->description) ? $extractText($item->description) : '';
                    if ($summary === '' && isset($item->children('http://purl.org/rss/1.0/modules/content/')->encoded)) {
                        $summary = $extractText($item->children('http://purl.org/rss/1.0/modules/content/')->encoded);
                    }
                    $push([
                        'title' => $extractText($item->title),
                        'link' => isset($item->link) ? trim((string) $item->link) : '',
                        'summary' => $summary,
                        'published' => isset($item->pubDate) ? trim((string) $item->pubDate) : '',
                        'guid' => isset($item->guid) ? trim((string) $item->guid) : '',
                    ]);
                }
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if ($items === [] && class_exists('DOMDocument')) {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        if (@$document->loadXML($xml)) {
            $xpath = new DOMXPath($document);
            $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
            $xpath->registerNamespace('content', 'http://purl.org/rss/1.0/modules/content/');
            foreach ($xpath->query('//item') as $node) {
                $pick = static function (string $name) use ($xpath, $node): string {
                    $found = $xpath->query('.//' . $name, $node);
                    return $found && $found->length > 0 ? trim($found->item(0)->textContent) : '';
                };
                $summary = $pick('description');
                if ($summary === '') {
                    $found = $xpath->query('.//content:encoded', $node);
                    if ($found && $found->length > 0) {
                        $summary = trim($found->item(0)->textContent);
                    }
                }
                $push([
                    'title' => $pick('title'),
                    'link' => $pick('link'),
                    'summary' => $summary,
                    'published' => $pick('pubDate'),
                    'guid' => $pick('guid'),
                ]);
            }
            foreach ($xpath->query('//atom:entry') as $node) {
                $pick = static function (string $name) use ($xpath, $node): string {
                    $found = $xpath->query('.//atom:' . $name, $node);
                    return $found && $found->length > 0 ? trim($found->item(0)->textContent) : '';
                };
                $push([
                    'title' => $pick('title'),
                    'link' => '',
                    'summary' => $pick('summary'),
                    'published' => $pick('published'),
                    'guid' => $pick('id'),
                ]);
            }
        }
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if ($items === [] && preg_match_all('#<item\b[^>]*>(.*?)</item>#si', $xml, $matches)) {
        foreach ($matches[1] as $block) {
            $pick = static function (string $name) use ($block): string {
                return preg_match('#<' . $name . '[^>]*>(.*?)</' . $name . '>#si', $block, $m) ? trim(strip_tags($m[1])) : '';
            };
            $push([
                'title' => $pick('title'),
                'link' => $pick('link'),
                'summary' => $pick('description'),
                'published' => $pick('pubDate'),
                'guid' => $pick('guid'),
            ]);
        }
    }
    return $items;
}

/** Besleme güncellemesini koşturur; asla istek öldürmez, durumu ayar deposuna yazar. */
function infoFeedRefresh(PDO $pdo, bool $force): array
{
    $all = infoSettingsAll($pdo);
    $runningAt = (string) ($all['feed_running_at'] ?? '');
    if ($runningAt !== '' && !$force) {
        $runningStamp = strtotime($runningAt . ' UTC');
        if ($runningStamp !== false && (time() - $runningStamp) < 150) {
            return ['ok' => true, 'skipped' => true];
        }
    }
    infoSetSetting($pdo, 'feed_running_at', gmdate('Y-m-d H:i:s'));
    $urls = infoFeedUrls($pdo);
    $inserted = 0;
    $updated = 0;
    $sourcesOk = 0;
    $firstError = '';
    if ($urls === []) {
        $firstError = 'Kaynak adresi tanımlı değil.';
    }
    foreach ($urls as $url) {
        try {
            $parsed = infoParseFeed(infoFetchUrlText($url), $url);
            $upsert = $pdo->prepare(
                'INSERT INTO info_items (section, source, feed_key, title, content, link, author, published_at, updated_at)
                 VALUES (\'haber\', \'feed\', ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                   title = VALUES(title), content = VALUES(content), link = VALUES(link),
                   author = VALUES(author), published_at = VALUES(published_at), updated_at = UTC_TIMESTAMP()'
            );
            foreach ($parsed as $item) {
                $key = md5((string) $item['guid']);
                $upsert->execute([
                    $key,
                    $item['title'],
                    $item['summary'] === '' ? null : $item['summary'],
                    $item['link'] === '' ? null : $item['link'],
                    (string) (parse_url($url, PHP_URL_HOST) ?: 'besleme'),
                    $item['published'],
                ]);
                if ($upsert->rowCount() === 1) {
                    $inserted++;
                } else {
                    $updated++;
                }
            }
            if ($parsed !== []) {
                $sourcesOk++;
            }
        } catch (Throwable $error) {
            if ($firstError === '') {
                $firstError = mb_substr($error->getMessage(), 0, 300);
            }
            error_log('[info] besleme hatası: ' . $error->getMessage());
        }
    }
    // Eski besleme kayıtları sınırlanır (90 günden eskiyse düşer).
    try {
        $pdo->exec("DELETE FROM info_items WHERE source = 'feed' AND COALESCE(published_at, created_at) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)");
    } catch (Throwable $error) {
        error_log('[info] besleme budama hatası: ' . $error->getMessage());
    }
    $now = gmdate('Y-m-d H:i:s');
    infoSetSetting($pdo, 'feed_last_at', $now);
    $ok = $sourcesOk > 0 && $firstError === '';
    infoSetSetting($pdo, 'feed_last_ok', $ok ? '1' : '0');
    if ($firstError !== '') {
        infoSetSetting($pdo, 'feed_last_error', $firstError);
    } else {
        $stmt = $pdo->prepare("DELETE FROM site_settings WHERE setting_key = 'feed_last_error'");
        $stmt->execute();
    }
    $cleanup = $pdo->prepare("DELETE FROM site_settings WHERE setting_key = 'feed_running_at'");
    $cleanup->execute();
    return [
        'ok' => $ok,
        'urls' => count($urls),
        'sources_ok' => $sourcesOk,
        'inserted' => $inserted,
        'updated' => $updated,
        'at' => infoIso($now),
        'error' => $firstError === '' ? null : $firstError,
    ];
}

/** 24 saat geçmişse tembel tazeleme (okuma ucu tetikler; hata okumayı etkilemez). */
function maybeInfoFeedRefresh(PDO $pdo): void
{
    if (infoSetting($pdo, 'feed_auto', '1') === '0') {
        return;
    }
    $all = infoSettingsAll($pdo);
    $lastAt = (string) ($all['feed_last_at'] ?? '');
    if ($lastAt !== '') {
        $lastStamp = strtotime($lastAt . ' UTC');
        if ($lastStamp !== false && (time() - $lastStamp) < INFO_FEED_REFRESH_SECONDS) {
            return;
        }
    }
    try {
        infoFeedRefresh($pdo, false);
    } catch (Throwable $error) {
        error_log('[info] tembel tazeleme hatası: ' . $error->getMessage());
    }
}

function infoValidateSection(string $section): string
{
    if (!in_array($section, ['haber', 'kaynak', 'ders', 'calisma'], true)) {
        throw new ApiError(422, 'INVALID_SECTION', 'Bölüm tanınmadı.');
    }
    return $section;
}

function infoItemPublic(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'section' => $row['section'],
        'source' => $row['source'],
        'title' => repairLegacyTurkishText($row['title']),
        'content' => $row['content'] !== null ? repairLegacyTurkishText($row['content']) : null,
        'link' => $row['link'],
        'author' => $row['author'],
        'published_at' => infoIso($row['published_at']),
        'updated_at' => infoIso($row['updated_at']),
    ];
}

/**
 * Kaynak / Ders / Çalışma bölümleri için gömülü rehber içeriği.
 *
 * Bu bölümlerin besleme kaynağı yoktur; veritabanı boşken sekmeler "boş"
 * görünürdü. İlk ziyarette bölüm tamamen boşsa güvenilir, resmî/eğitici
 * kaynaklara işaret eden Türkçe rehber maddeleri tohumlanır ve normal
 * listeyle harmanlanır (source = 'builtin'). Bir bölüm bir kez tohumlanır
 * (site_settings'e işlenir); yönetici maddeleri tek tek silerse geri gelmez.
 * Besleme budaması yalnızca source = 'feed' satırlarını sildiğinden bu
 * maddeler kalıcıdır.
 */
/**
 * Kaynak / Ders / Çalışma bölümleri için gömülü rehber içeriği.
 *
 * Bu bölümlerin besleme kaynağı yoktur; veritabanı boşken sekmeler "boş"
 * görünürdü. İlk ziyarette bölüm tamamen boşsa güvenilir, resmî/eğitici
 * kaynaklara işaret eden Türkçe rehber maddeleri tohumlanır ve normal
 * listeyle harmanlanır (source = 'builtin'). Bir bölüm bir kez tohumlanır
 * (site_settings'e işlenir); yönetici maddeleri tek tek silerse geri gelmez.
 * Besleme budaması yalnızca source = 'feed' satırlarını sildiğinden bu
 * maddeler kalıcıdır.
 */
function seedBuiltinInfoItems(PDO $pdo, string $section): void
{
    if (infoSetting($pdo, 'builtin_seed_' . $section, '') === '1') {
        return;
    }
    $count = (int) $pdo->query(
        "SELECT COUNT(*) FROM info_items WHERE section = " . $pdo->quote($section)
    )->fetchColumn();
    if ($count > 0) {
        return; // Bölüm zaten dolu: kullanıcı/içerik karışmasın.
    }
    $rows = [];
    if ($section === 'kaynak') {
        $rows = [
            ['EBA - Resmî Eğitim Platformu', 'MEB\'in tüm kademeler için ders içerikleri, video dersler, soru bankaları ve yardımcı kaynakları tek çatı altında sunduğu resmî platform. Millî Eğitim Bakanlığı\'nın güvenilir kaynağıdır.', 'https://www.eba.gov.tr', 0],
            ['Khan Academy Türkçe', 'İlkokuldan üniversiteye matematik, fen, bilgisayar ve daha fazlası için ücretsiz, bilimsel temelli video anlatımlar ve alıştırmalar. Tamamen ücretsiz ve herkese açıktır.', 'https://tr.khanacademy.org', 1],
            ['OGM Materyal - Ortaöğretim Ders Materyalleri', 'MEB\'in lise kademesi için hazırladığı ders anlatım sunuları, etkinlikler ve ölçme araçları. 9-12. sınıf müfredatına birebir uyumludur.', 'https://ogmmateryal.eba.gov.tr', 2],
            ['TÜBİTAK Bilim Genç', 'Bilim insanı adayları için güncel bilim haberleri, deneyler, yarışmalar ve proje rehberleri. Türkiye Bilimsel ve Teknolojik Araştırma Kurumu\'nun gençlik platformudur.', 'https://bilimgenc.tubitak.gov.tr', 3],
            ['NASA - Bilim ve Uzay Haberleri', 'Uzay araştırmaları, astronomi ve bilim haberlerinin birincil küresel kaynağı. İngilizce içerikler pratik yapmak için de idealdir.', 'https://www.nasa.gov', 4],
            ['Ankara Üniversitesi Açık Ders Malzemeleri', 'Üniversite düzeyinde binlerce dersin not, sunum ve kaynaklarına ücretsiz erişim. Araştırma ve ödevler için akademik ölçekte güvenilir içerik.', 'https://acikders.ankara.edu.tr', 5],
        ];
    } elseif ($section === 'ders') {
        $rows = [
            ['Khan Academy Türkçe - Konu Anlatımları', 'Müfredata uygun matematik, fen bilimleri ve daha fazlası için adım adım video konu anlatımları; her konunun ardından çözümlü alıştırmalarla pekiştirme.', 'https://tr.khanacademy.org', 0],
            ['EBA - Ders İçerikleri ve Video Dersler', 'Sınıf ve ders seçerek ilerleyebileceğin MEB onaylı ders içerikleri, canlı ders arşivleri ve öğretmen rehberleri.', 'https://www.eba.gov.tr', 1],
            ['OGM Materyal - Ders Anlatım Sunuları', 'Lise müfredatının her ünitesi için MEB\'in resmî anlatım sunuları; yazılılara ve YKS\'ye hazırlıkta not çıkarmak için birebir.', 'https://ogmmateryal.eba.gov.tr', 2],
            ['Ankara Üniversitesi Açık Ders Malzemeleri', 'Üniversite hazırlığında ileri konular için açık ders notları ve akademik kaynaklar; özellikle araştırma ödevlerinde güçlü bir yardımcı.', 'https://acikders.ankara.edu.tr', 3],
            ['TÜBİTAK Bilim Genç - Meraklısına Bilim', 'Ders dışı okumalarla genel kültürü ve bilimsel merakı besleyen güncel makaleler, belgesel önerileri ve deney videoları.', 'https://bilimgenc.tubitak.gov.tr', 4],
        ];
    } elseif ($section === 'calisma') {
        $rows = [
            ['MEB ÖDSGM - Kazanım Kavrama Testleri', 'Millî Eğitim Bakanlığı\'nın her ünite için yayımladığı kazanım kavrama testleri; LGS ve yazılılara hazırlıkta resmî soru kaynağı.', 'https://odsgm.meb.gov.tr', 0],
            ['EBA - Çalışma Kitapları ve Yardımcı Kaynaklar', 'MEB\'in destekleme ve yetiştirme kursları ile uyumlu çalışma kitapları, etkinlik dosyaları ve örnek soru paketleri.', 'https://www.eba.gov.tr', 1],
            ['OGM Materyal - Çalışma Kağıtları', 'Lise düzeyinde ünite sonu tekrar çalışma kağıtları ve etkinlikler; yazılı öncesi hızlı tekrar için düzenli aralıklarla güncellenir.', 'https://ogmmateryal.eba.gov.tr', 2],
            ['Khan Academy Türkçe - Alıştırma Setleri', 'Konu anlatımından hemen sonra çözebileceğin anında geri bildirim veren ücretsiz alıştırma setleri; eksiklerini otomatik takip eder.', 'https://tr.khanacademy.org', 3],
            ['TÜBİTAK Bilim Genç - Etkinlik ve Deneyler', 'Evde güvenle yapılabilecek deneyler ve bilim etkinlikleri; el becerisi ve gözlem gücünü geliştiren uygulamalı çalışmalar.', 'https://bilimgenc.tubitak.gov.tr', 4],
        ];
    }
    if ($rows === []) {
        return;
    }
    // Idempotent tohumlama: eş zamanlı iki ilk-ziyaret bile aynı maddeyi
    // iki kez ekleyemez (bölüm+başlık aynı olan builtin satır varsa atlanır).
    $insert = $pdo->prepare(
        'INSERT INTO info_items (section, source, title, content, link, author, published_at, created_at)
         SELECT ?, ?, ?, ?, ?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? SECOND), UTC_TIMESTAMP()
         WHERE NOT EXISTS (
             SELECT 1 FROM info_items WHERE source = ? AND section = ? AND title = ?
         )'
    );
    $done = 0;
    foreach ($rows as $row) {
        [$title, $content, $link, $offsetMin] = $row;
        $insert->execute([
            $section,
            'builtin',
            $title,
            $content,
            $link,
            (string) (parse_url($link, PHP_URL_HOST) ?: 'rehber'),
            (int) $offsetMin * 60,
            'builtin',
            $section,
            $title,
        ]);
        $done += $insert->rowCount();
    }
    if ($done > 0) {
        infoSetSetting($pdo, 'builtin_seed_' . $section, '1');
    }
}

// ============================================================================
//  OKUMA EYLEMLERİ (GET)
// ============================================================================

function actionInfoItems(PDO $pdo): never
{
    sessionRateGuard('info_items', 60, 30);
    requireUser($pdo);
    closeSessionEarlyForReads('info_items');
    $section = infoValidateSection(cleanText($_GET['section'] ?? 'haber', 'Bölüm', 20, false) ?: 'haber');
    if ($section === 'haber') {
        maybeInfoFeedRefresh($pdo);
    } else {
        seedBuiltinInfoItems($pdo, $section);
    }
    $stmt = $pdo->prepare(
        'SELECT id, section, source, title, content, link, author, published_at, updated_at, created_at
         FROM info_items WHERE section = ?
         ORDER BY COALESCE(published_at, created_at) DESC, id DESC LIMIT 80'
    );
    $stmt->execute([$section]);
    $items = [];
    foreach ($stmt->fetchAll() as $row) {
        $items[] = infoItemPublic($row);
    }
    $payload = ['items' => $items];
    if ($section === 'haber') {
        $all = infoSettingsAll($pdo);
        $urls = infoFeedUrls($pdo);
        $lastAt = (string) ($all['feed_last_at'] ?? '');
        $feedItems = (int) ($pdo->query("SELECT COUNT(*) FROM info_items WHERE section = 'haber' AND source = 'feed'")->fetchColumn() ?: 0);
        $nextAt = '';
        if ($lastAt !== '') {
            $nextStamp = strtotime($lastAt . ' UTC') + INFO_FEED_REFRESH_SECONDS;
            $nextAt = gmdate(DateTimeInterface::ATOM, $nextStamp);
        }
        $payload['meta'] = [
            'auto' => infoSetting($pdo, 'feed_auto', '1') === '1',
            'last_at' => infoIso($lastAt),
            'next_at' => $nextAt !== '' ? $nextAt : null,
            'last_ok' => ((string) ($all['feed_last_ok'] ?? '1')) === '1',
            'last_error' => $all['feed_last_error'] ?? null,
            'sources' => count($urls),
            'feed_items' => $feedItems,
        ];
    }
    respond($payload);
}

function actionInfoForms(PDO $pdo): never
{
    sessionRateGuard('info_forms', 60, 30);
    $user = requireUser($pdo);
    closeSessionEarlyForReads('info_forms');
    $stmt = $pdo->query(
        'SELECT f.id, f.author_id, f.title, f.body, f.created_at, u.username
         FROM info_forms f JOIN users u ON u.id = f.author_id
         WHERE f.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
         ORDER BY f.id DESC LIMIT 80'
    );
    $forms = [];
    $likedStmt = $pdo->prepare('SELECT 1 FROM info_form_likes WHERE form_id = ? AND user_id = ?');
    $likesStmt = $pdo->prepare('SELECT COUNT(*) FROM info_form_likes WHERE form_id = ?');
    $repliesStmt = $pdo->prepare('SELECT COUNT(*) FROM info_form_replies WHERE form_id = ?');
    foreach ($stmt->fetchAll() as $row) {
        $formId = (int) $row['id'];
        $likesStmt->execute([$formId]);
        $repliesStmt->execute([$formId]);
        $likedStmt->execute([$formId, (int) $user['id']]);
        $forms[] = [
            'id' => $formId,
            'author_id' => (int) $row['author_id'],
            'author' => repairLegacyTurkishText($row['username']),
            'title' => repairLegacyTurkishText($row['title']),
            'body' => repairLegacyTurkishText($row['body']),
            'created_at' => infoIso($row['created_at']),
            'likes' => (int) $likesStmt->fetchColumn(),
            'replies' => (int) $repliesStmt->fetchColumn(),
            'liked' => (bool) $likedStmt->fetchColumn(),
            'can_delete' => (int) $row['author_id'] === (int) $user['id'],
        ];
    }
    respond(['forms' => $forms]);
}

/**
 * Form, oda mesajlarıyla AYNI saklama politikasına tabidir
 * (MESSAGE_RETENTION_HOURS). Vadesi dolan form "yok" sayılır: listelenmez,
 * yorum/beğeni gibi etkileşimlere kapalıdır; temizlik görevi onu kısa süre
 * içinde veritabanından kaldırır.
 */
function infoFormUsable(PDO $pdo, int $formId): void
{
    $stmt = $pdo->prepare(
        'SELECT 1 FROM info_forms WHERE id = ? AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)'
    );
    $stmt->execute([$formId]);
    if (!$stmt->fetchColumn()) {
        throw new ApiError(404, 'FORM_NOT_FOUND', 'Form bulunamadı.');
    }
}

function actionInfoFormReplies(PDO $pdo): never
{
    sessionRateGuard('info_forms', 60, 30);
    requireUser($pdo);
    closeSessionEarlyForReads('info_forms');
    $formId = integerField($_GET['form_id'] ?? null, 'Form');
    infoFormUsable($pdo, $formId);
    $stmt = $pdo->prepare(
        'SELECT r.id, r.form_id, r.author_id, r.content, r.created_at, u.username
         FROM info_form_replies r JOIN users u ON u.id = r.author_id
         WHERE r.form_id = ?
           AND r.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
         ORDER BY r.id ASC LIMIT 200'
    );
    $stmt->execute([$formId]);
    $likedStmt = $pdo->prepare('SELECT 1 FROM info_form_reply_likes WHERE reply_id = ? AND user_id = ?');
    $likesStmt = $pdo->prepare('SELECT COUNT(*) FROM info_form_reply_likes WHERE reply_id = ?');
    $replies = [];
    foreach ($stmt->fetchAll() as $row) {
        $replyId = (int) $row['id'];
        $likesStmt->execute([$replyId]);
        $likedStmt->execute([$replyId, (int) $user['id']]);
        $replies[] = [
            'id' => $replyId,
            'form_id' => (int) $row['form_id'],
            'author_id' => (int) $row['author_id'],
            'author' => repairLegacyTurkishText($row['username']),
            'content' => repairLegacyTurkishText($row['content']),
            'created_at' => infoIso($row['created_at']),
            'likes' => (int) $likesStmt->fetchColumn(),
            'liked' => (bool) $likedStmt->fetchColumn(),
        ];
    }
    respond(['replies' => $replies]);
}

function actionComplaintQuota(PDO $pdo): never
{
    $user = requireUser($pdo);
    closeSessionEarlyForReads('complaint_quota');
    $used = infoComplaintCountToday($pdo, (int) $user['id']);
    respond(['used' => $used, 'remaining' => max(0, COMPLAINT_DAILY_LIMIT - $used), 'limit' => COMPLAINT_DAILY_LIMIT]);
}

function infoComplaintCountToday(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM complaints WHERE reporter_id = ? AND created_at >= CONCAT(CURDATE(), ' 00:00:00')"
    );
    $stmt->execute([$userId]);
    return (int) ($stmt->fetchColumn() ?: 0);
}

// ============================================================================
//  YAZMA EYLEMLERİ (POST) — Bilgi Odası formlar ve oda beğenisi
// ============================================================================

function actionInfoFormCreate(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    sessionRateGuard('info_form_create', 12, 60);
    $title = infoText($data, 'title', 'Başlık', 120, true);
    $body = infoText($data, 'body', 'İçerik', 4000, false);
    if ($body === '') {
        throw new ApiError(422, 'EMPTY_FORM', 'Form içeriği boş olamaz.');
    }
    $stmt = $pdo->prepare('INSERT INTO info_forms (author_id, title, body) VALUES (?, ?, ?)');
    $stmt->execute([(int) $user['id'], $title, $body]);
    respond(['message' => 'Form yayınlandı.', 'form_id' => (int) $pdo->lastInsertId()], 201);
}

function actionInfoFormDelete(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    $formId = integerField($data['form_id'] ?? null, 'Form');
    $stmt = $pdo->prepare('SELECT author_id FROM info_forms WHERE id = ?');
    $stmt->execute([$formId]);
    $authorId = $stmt->fetchColumn();
    if ($authorId === false) {
        throw new ApiError(404, 'FORM_NOT_FOUND', 'Form bulunamadı.');
    }
    if ((int) $authorId !== (int) $user['id']) {
        throw new ApiError(403, 'FORM_NOT_OWNER', 'Yalnızca kendi formunuzu silebilirsiniz.');
    }
    $pdo->prepare('DELETE FROM info_form_likes WHERE form_id = ?')->execute([$formId]);
    $pdo->prepare('DELETE FROM info_form_replies WHERE form_id = ?')->execute([$formId]);
    $pdo->prepare('DELETE FROM info_forms WHERE id = ?')->execute([$formId]);
    respond(['message' => 'Form silindi.']);
}

function actionInfoFormLike(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    sessionRateGuard('info_form_like', 30, 60);
    $formId = integerField($data['form_id'] ?? null, 'Form');
    infoFormUsable($pdo, $formId);
    $remove = $pdo->prepare('DELETE FROM info_form_likes WHERE form_id = ? AND user_id = ?');
    $remove->execute([$formId, (int) $user['id']]);
    $liked = false;
    if ($remove->rowCount() === 0) {
        $add = $pdo->prepare('INSERT IGNORE INTO info_form_likes (form_id, user_id) VALUES (?, ?)');
        $add->execute([$formId, (int) $user['id']]);
        $liked = $add->rowCount() > 0;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM info_form_likes WHERE form_id = ?');
    $count->execute([$formId]);
    respond(['liked' => $liked, 'likes' => (int) $count->fetchColumn()]);
}

function actionInfoFormReply(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    sessionRateGuard('info_form_reply', 20, 60);
    $formId = integerField($data['form_id'] ?? null, 'Form');
    $content = cleanText($data['content'] ?? null, 'Mesaj', 2000, false);
    if ($content === '') {
        throw new ApiError(422, 'EMPTY_FORM_REPLY', 'Mesaj yazın.');
    }
    infoFormUsable($pdo, $formId);
    $stmt = $pdo->prepare('INSERT INTO info_form_replies (form_id, author_id, content) VALUES (?, ?, ?)');
    $stmt->execute([$formId, (int) $user['id'], $content]);
    respond(['message' => 'Yorum gönderildi.', 'reply_id' => (int) $pdo->lastInsertId()], 201);
}

function actionInfoFormReplyLike(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    sessionRateGuard('info_form_reply_like', 30, 60);
    $replyId = integerField($data['reply_id'] ?? null, 'Yorum');
    // Yorum da formu da vadesi icinde olmali (oda mesajlariyla ayni politika).
    $stmt = $pdo->prepare(
        'SELECT 1 FROM info_form_replies r JOIN info_forms f ON f.id = r.form_id
         WHERE r.id = ?
           AND r.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
           AND f.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)'
    );
    $stmt->execute([$replyId]);
    if (!$stmt->fetchColumn()) {
        throw new ApiError(404, 'REPLY_NOT_FOUND', 'Yorum bulunamadı.');
    }
    $remove = $pdo->prepare('DELETE FROM info_form_reply_likes WHERE reply_id = ? AND user_id = ?');
    $remove->execute([$replyId, (int) $user['id']]);
    $liked = false;
    if ($remove->rowCount() === 0) {
        $add = $pdo->prepare('INSERT IGNORE INTO info_form_reply_likes (reply_id, user_id) VALUES (?, ?)');
        $add->execute([$replyId, (int) $user['id']]);
        $liked = $add->rowCount() > 0;
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM info_form_reply_likes WHERE reply_id = ?');
    $count->execute([$replyId]);
    respond(['liked' => $liked, 'likes' => (int) $count->fetchColumn()]);
}

// ============================================================================
//  ŞİKAYET GÖNDERME
// ============================================================================

function actionComplaintSubmit(PDO $pdo, array $data): never
{
    $user = requireUser($pdo);
    sessionRateGuard('complaint_submit', 20, 60);
    $targetType = infoText($data, 'target_type', 'Hedef türü', 20, true);
    if (!in_array($targetType, ['chat', 'form', 'form_reply'], true)) {
        throw new ApiError(422, 'INVALID_COMPLAINT_TARGET', 'Şikayet hedefi tanınmadı.');
    }
    $reason = infoText($data, 'reason', 'Şikayet nedeni', 1000, false);
    if ($reason === '') {
        throw new ApiError(422, 'COMPLAINT_REASON_REQUIRED', 'Şikayet nedeni yazın.');
    }
    if (function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') < 10 : strlen($reason) < 10) {
        throw new ApiError(422, 'COMPLAINT_REASON_SHORT', 'Şikayet nedeni en az 10 karakter olmalıdır.');
    }
    $used = infoComplaintCountToday($pdo, (int) $user['id']);
    if ($used >= COMPLAINT_DAILY_LIMIT) {
        throw new ApiError(429, 'COMPLAINT_LIMIT', 'Bugünlük şikayet hakkın doldu (5/5). Yarın tekrar deneyebilirsin.', [
            'limit' => COMPLAINT_DAILY_LIMIT,
            'remaining' => 0,
        ]);
    }
    $targetId = integerField($data['target_id'] ?? null, 'Hedef');
    $authorId = null;
    $authorName = '';
    $snippet = '';
    $roomName = null;
    // Bakım henüz DB'den silmemiş olsa bile 24 saati aşan görünmez hedef
    // şikayet edilemez. Önceden kaydedilmiş şikayet kanıtları korunur.
    if ($targetType === 'chat') {
        $stmt = $pdo->prepare(
            'SELECT m.id, m.user_id, m.content, m.image_path, m.room_id, u.username
             FROM messages m LEFT JOIN users u ON u.id = m.user_id
             WHERE m.id = ? AND m.deleted_at IS NULL AND m.user_id IS NOT NULL
               AND m.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)'
        );
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiError(404, 'COMPLAINT_TARGET_MISSING', 'Şikayet edilecek mesaj bulunamadı.');
        }
        $authorId = (int) $row['user_id'];
        $authorName = (string) ($row['username'] ?? '');
        $raw = (string) ($row['content'] ?? '');
        if ($raw === '' && $row['image_path']) {
            $raw = '[Görsel mesaj]';
        }
        $snippet = function_exists('mb_substr') ? mb_substr($raw, 0, 300, 'UTF-8') : substr($raw, 0, 300);
        $roomRow = $pdo->prepare('SELECT name FROM rooms WHERE id = ?');
        $roomRow->execute([(int) $row['room_id']]);
        $roomName = (string) ($roomRow->fetchColumn() ?: '');
    } elseif ($targetType === 'form') {
        $stmt = $pdo->prepare(
            'SELECT id, author_id, title FROM info_forms WHERE id = ?
             AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)'
        );
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiError(404, 'COMPLAINT_TARGET_MISSING', 'Şikayet edilecek form bulunamadı.');
        }
        $authorId = (int) $row['author_id'];
        $snippet = function_exists('mb_substr') ? mb_substr((string) $row['title'], 0, 300, 'UTF-8') : substr((string) $row['title'], 0, 300);
        $roomName = 'Bilgi Odası · Form';
        $name = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $name->execute([$authorId]);
        $authorName = (string) ($name->fetchColumn() ?: '');
    } else {
        $stmt = $pdo->prepare(
            'SELECT r.id, r.form_id, r.author_id, r.content, f.title
             FROM info_form_replies r JOIN info_forms f ON f.id = r.form_id
             WHERE r.id = ?
               AND r.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)
               AND f.created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ' . MESSAGE_RETENTION_HOURS . ' HOUR)'
        );
        $stmt->execute([$targetId]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new ApiError(404, 'COMPLAINT_TARGET_MISSING', 'Şikayet edilecek yorum bulunamadı.');
        }
        $authorId = (int) $row['author_id'];
        $snippet = function_exists('mb_substr') ? mb_substr((string) $row['content'], 0, 300, 'UTF-8') : substr((string) $row['content'], 0, 300);
        $roomName = 'Bilgi Odası · ' . (string) $row['title'];
        $name = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $name->execute([$authorId]);
        $authorName = (string) ($name->fetchColumn() ?: '');
    }
    if ($authorId === null) {
        throw new ApiError(404, 'COMPLAINT_TARGET_MISSING', 'Şikayet edilecek içerik bulunamadı.');
    }
    if ($authorId === (int) $user['id']) {
        throw new ApiError(422, 'COMPLAINT_SELF', 'Kendi içeriğini şikayet edemezsin.');
    }
    $insert = $pdo->prepare(
        'INSERT INTO complaints (target_type, target_id, target_author_id, target_username, reporter_id, reason, snippet, room_name)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$targetType, $targetId, $authorId, $authorName === '' ? null : $authorName, (int) $user['id'], $reason, $snippet, $roomName]);
    respond([
        'message' => 'Şikayetin iletildi. Yetkili ekibi inceleyecek.',
        'complaint_id' => (int) $pdo->lastInsertId(),
        'remaining' => max(0, COMPLAINT_DAILY_LIMIT - $used - 1),
    ], 201);
}

// ============================================================================
//  YÖNETİCİ: içerik yönetimi, besleme ayarları, şikayet kuyruğu
// ============================================================================

function adminInfoTargetRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'section' => $row['section'],
        'source' => $row['source'],
        'title' => repairLegacyTurkishText($row['title']),
        'content' => $row['content'] !== null ? repairLegacyTurkishText($row['content']) : null,
        'link' => $row['link'],
        'author' => $row['author'],
        'published_at' => infoIso($row['published_at']),
    ];
}

function adminAssertManualItem(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare("SELECT id, section, source FROM info_items WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new ApiError(404, 'INFO_ITEM_NOT_FOUND', 'İçerik bulunamadı.');
    }
    if (($row['source'] ?? '') !== 'manual') {
        throw new ApiError(422, 'FEED_ITEM_LOCKED', 'Otomatik haber akışı kayıtları düzenlenemez veya silinemez.');
    }
    return $row;
}

function actionAdminInfoArticleCreate(PDO $pdo, array $data): never
{
    $admin = requireAdmin($pdo);
    $section = infoValidateSection(infoText($data, 'section', 'Bölüm', 20, true));
    $title = infoText($data, 'title', 'Başlık', 300, true);
    $content = infoText($data, 'content', 'İçerik', 4000, false);
    $link = infoText($data, 'link', 'Bağlantı', 700, false);
    if ($content === '' && $link === '') {
        throw new ApiError(422, 'EMPTY_INFO_ITEM', 'İçerik veya bağlantı ekleyin.');
    }
    if ($link !== '') {
        $parts = parse_url($link);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || !isset($parts['host'])) {
            throw new ApiError(422, 'INVALID_INFO_LINK', 'Bağlantı geçersiz; http(s) adresi olmalıdır.');
        }
    }
    $stmt = $pdo->prepare(
        "INSERT INTO info_items (section, source, title, content, link, author, published_at, updated_at)
         VALUES (?, 'manual', ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
    );
    $stmt->execute([$section, $title, $content === '' ? null : $content, $link === '' ? null : $link, (string) ($admin['username'] ?? 'admin')]);
    respond(['message' => 'İçerik yayınlandı.', 'item_id' => (int) $pdo->lastInsertId()], 201);
}

function actionAdminInfoArticleUpdate(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $id = integerField($data['id'] ?? null, 'İçerik');
    adminAssertManualItem($pdo, $id);
    $sets = [];
    $params = [];
    foreach (['title' => 300, 'content' => 4000, 'link' => 700] as $field => $max) {
        if (!array_key_exists($field, $data)) {
            continue;
        }
        $label = $field === 'title' ? 'Başlık' : ($field === 'content' ? 'İçerik' : 'Bağlantı');
        $required = $field === 'title';
        $value = infoText($data, $field, $label, $max, $required);
        if ($field === 'link' && $value !== '') {
            $parts = parse_url($value);
            if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || !isset($parts['host'])) {
                throw new ApiError(422, 'INVALID_INFO_LINK', 'Bağlantı geçersiz; http(s) adresi olmalıdır.');
            }
        }
        $sets[] = '`' . $field . '` = ?';
        $params[] = $value === '' ? null : $value;
    }
    if ($sets === []) {
        throw new ApiError(422, 'VALIDATION_ERROR', 'Değiştirilecek alan yok.');
    }
    $sets[] = 'updated_at = UTC_TIMESTAMP()';
    $stmt = $pdo->prepare('UPDATE info_items SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $params[] = $id;
    $stmt->execute($params);
    respond(['message' => 'İçerik güncellendi.']);
}

function actionAdminInfoArticleDelete(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $id = integerField($data['id'] ?? null, 'İçerik');
    adminAssertManualItem($pdo, $id);
    $pdo->prepare('DELETE FROM info_items WHERE id = ?')->execute([$id]);
    respond(['message' => 'İçerik silindi.']);
}

function actionAdminFeedSettings(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $urls = infoText($data, 'urls', 'Kaynak adresleri', 4000, false);
    $auto = filter_var($data['auto'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($auto === null) {
        throw new ApiError(422, 'VALIDATION_ERROR', 'Otomatik tazeleme seçimi geçersiz.');
    }
    $lines = preg_split('/[\r\n,]+/', $urls ?? '') ?: [];
    $kept = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            continue;
        }
        $parts = parse_url($line);
        if (!$parts || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || !isset($parts['host'])) {
            throw new ApiError(422, 'INVALID_FEED_URL', 'Geçersiz kaynak adresi: ' . mb_substr($line, 0, 120));
        }
        $kept[] = $line;
    }
    $kept = array_values(array_unique($kept));
    if ($kept === []) {
        throw new ApiError(422, 'INVALID_FEED_URL', 'En az bir geçerli kaynak adresi girin.');
    }
    infoSetSetting($pdo, 'feed_urls', implode("\n", $kept));
    infoSetSetting($pdo, 'feed_auto', $auto ? '1' : '0');
    respond(['message' => 'Haber kaynağı ayarları kaydedildi.', 'urls' => $kept, 'auto' => $auto]);
}

function actionAdminFeedRefresh(PDO $pdo): never
{
    requireAdmin($pdo);
    respond(['message' => 'Haber akışı güncellendi.', 'result' => infoFeedRefresh($pdo, true)]);
}

function actionAdminFeedStatus(PDO $pdo): never
{
    requireAdmin($pdo);
    closeSessionEarlyForReads('admin_feed_status');
    $all = infoSettingsAll($pdo);
    $feedItems = (int) ($pdo->query("SELECT COUNT(*) FROM info_items WHERE section = 'haber' AND source = 'feed'")->fetchColumn() ?: 0);
    $manualItems = (int) ($pdo->query("SELECT COUNT(*) FROM info_items WHERE source = 'manual'")->fetchColumn() ?: 0);
    respond([
        'urls' => infoFeedUrls($pdo),
        'auto' => infoSetting($pdo, 'feed_auto', '1') === '1',
        'last_at' => infoIso($all['feed_last_at'] ?? ''),
        'last_ok' => ((string) ($all['feed_last_ok'] ?? '1')) === '1',
        'last_error' => $all['feed_last_error'] ?? null,
        'feed_items' => $feedItems,
        'manual_items' => $manualItems,
    ]);
}

function actionAdminComplaints(PDO $pdo): never
{
    sessionRateGuard('admin_complaints', 30, 30);
    requireAdmin($pdo);
    closeSessionEarlyForReads('admin_complaints');
    $total = (int) ($pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn() ?: 0);
    $stmt = $pdo->query(
        'SELECT c.id, c.target_type, c.target_id, c.target_author_id, c.target_username,
                c.reporter_id, c.reason, c.snippet, c.room_name, c.created_at,
                ru.username AS reporter_username, tu.username AS live_target_username,
                (b.user_id IS NOT NULL) AS accused_banned
         FROM complaints c
         LEFT JOIN users ru ON ru.id = c.reporter_id
         LEFT JOIN users tu ON tu.id = c.target_author_id
         LEFT JOIN banned_users b ON b.user_id = c.target_author_id
            AND (b.banned_until IS NULL OR b.banned_until > UTC_TIMESTAMP())
         ORDER BY c.id ASC LIMIT 200'
    );
    $complaints = [];
    foreach ($stmt->fetchAll() as $row) {
        $accusedName = (string) ($row['live_target_username'] ?? '');
        if ($accusedName === '') {
            $accusedName = (string) ($row['target_username'] ?? '');
        }
        if ($accusedName === '') {
            $accusedName = '(silinmiş hesap)';
        }
        $complaints[] = [
            'id' => (int) $row['id'],
            'target_type' => $row['target_type'],
            'target_id' => (int) $row['target_id'],
            'accused' => [
                'id' => $row['target_author_id'] !== null ? (int) $row['target_author_id'] : null,
                'username' => repairLegacyTurkishText($accusedName),
                'banned' => (bool) $row['accused_banned'],
            ],
            'reporter' => [
                'id' => (int) $row['reporter_id'],
                'username' => repairLegacyTurkishText((string) ($row['reporter_username'] ?? '(silinmiş hesap)')),
            ],
            'reason' => repairLegacyTurkishText((string) $row['reason']),
            'snippet' => repairLegacyTurkishText((string) $row['snippet']),
            'room_name' => $row['room_name'] !== null ? repairLegacyTurkishText((string) $row['room_name']) : null,
            'created_at' => infoIso($row['created_at']),
        ];
    }
    respond(['complaints' => $complaints, 'total' => $total]);
}

function actionAdminComplaintReject(PDO $pdo, array $data): never
{
    requireAdmin($pdo);
    $id = integerField($data['id'] ?? null, 'Şikayet');
    $stmt = $pdo->prepare('DELETE FROM complaints WHERE id = ?');
    $stmt->execute([$id]);
    $remaining = (int) ($pdo->query('SELECT COUNT(*) FROM complaints')->fetchColumn() ?: 0);
    respond(['message' => 'Şikayet reddedildi ve listeden kalıcı olarak kaldırıldı.', 'remaining' => $remaining]);
}

// ============================================================================
//  (BİLGİ ODASI eylemleri sonu — aşağıda dispatch'e bağlanır)
// ============================================================================

function dispatch(): never
{
    bootWebSession();
    sendSecurityHeaders();
    $action = strtolower(trim((string) ($_GET['action'] ?? $_POST['action'] ?? 'session')));
    $postActions = [
        'register', 'login', 'submit_approval', 'logout', 'send_message', 'delete_message', 'undo_delete_message', 'finalize_message_delete',
        'update_profile', 'update_profile_photo', 'delete_profile_photo', 'update_settings', 'admin_login', 'admin_ban_user', 'admin_unban_user',
        'admin_delete_user', 'admin_change_user_password', 'change_password', 'admin_decide_approval', 'admin_adjust_coins', 'admin_send_announcement', 'admin_delete_announcement', 'delete_own_account', 'ai_chat', 'buy_frame', 'equip_frame', 'claim_quest', 'claim_game_reward',
        'info_form_create', 'info_form_like', 'info_form_reply', 'info_form_reply_like', 'info_form_delete',
        'complaint_submit',
        'admin_feed_refresh', 'admin_feed_settings',
        'admin_info_article_create', 'admin_info_article_update', 'admin_info_article_delete',
        'admin_complaint_reject',
    ];
    $isPostAction = in_array($action, $postActions, true);
    requireMethod($isPostAction ? 'POST' : 'GET');
    // Tanı ucu her şeyden önce: veritabanına bağlanılamadığında ya da
    // sağlayıcı HTML koruma sayfası döndürdüğünde bile JSON üretmelidir.
    if ($action === 'health') {
        actionHealth();
    }
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        requireCsrf();
    }
    $pdo = db();
    if (empty($_SESSION['user_id'])) {
        // Sunucu oturumu kaybolduysa (güncelleme, oturum temizliği, yeniden başlatma)
        // kullanıcı sessizce geri yüklenir; kimse hesabından düşmez.
        restoreSessionFromRemember($pdo);
    }
    // Hit koruması platformda HER ZAMAN aktiftir: normal kullanımın çok
    // üzerinde oluşan istemci döngüleri ucuz oturum sayacıyla kesilir.
    // Bozuk istemci, çift sekme veya otomasyonun PHP/DB yükünü büyütmez.
    if (!in_array($action, ['get_media', 'media'], true)) {
        sessionRateGuard('global', HIT_PROTECTION_SESSION_MAX_REQUESTS, 60);
    }
    // Tüm hesabı kapsayan okuma bütçesi (kalıcı koruma): yoklama fırtınası
    // hesabın günlük "hits" kotasını aşıp askıya alınmasına neden olmasın.
    // Retry-After döner, web istemcisi bu başlığa uyarak geri çekilir.
    // (get_media/media hariç: onlar için sessionRateGuard yeterli ve bu uç
    // zaten ağır görsel taşıyabilir.)
    if (in_array($action, ['get_rooms', 'get_messages', 'get_message_views', 'get_user_profile', 'heartbeat', 'session', 'get_banned_info'], true)) {
        // Ortam değişkeniyle yükseltilebilir; koruma tavanı her zaman uygulanır.
        $hotReadsMax = min(max(20, (int) (envValue('ASRA_HOT_READS_PER_MIN', '180'))), HIT_PROTECTION_MAX_REQUESTS);
        $hotReadsWindow = max(15, (int) (envValue('ASRA_HOT_READS_WINDOW', '60')));
        $hotReadsCooldown = max(5, (int) (envValue('ASRA_HOT_READS_COOLDOWN', (string) HIT_PROTECTION_COOLDOWN_SECONDS)));
        consumeGlobalReadBudget($pdo, 'hot_reads', $hotReadsMax, $hotReadsWindow, $hotReadsCooldown);
    }
    // Okuma isteklerinde oturum kilidi erken bırakılır - paralel polling hızlanır, timeout azalır
    if (in_array($action, ['get_media', 'media'], true)) {
        releaseSessionEarly();
    } else {
        // Diğer okuma uçları için de kilidi erken bırak (requireUser sonrası DB işlemleri kilidi tutmasın)
        // closeSessionEarlyForReads çağrısı dispatch sonrası da yapılacak ama burada ön kontrol
        if (in_array($action, ['get_rooms','get_messages','get_message_views','get_user_profile','admin_search_users','admin_pending_approvals','get_economy','get_quests','heartbeat','session','get_banned_info'], true) && session_status() === PHP_SESSION_ACTIVE) {
            // User doğrulandıktan sonra kapatılacak; burada sadece session varlığını koru
        }
    }
    if (!in_array($action, ['logout', 'get_banned_info'], true)) {
        $identityBlock = activeIdentityBlock($pdo);
        if ($identityBlock !== null) {
            throw new ApiError(403, 'ACCOUNT_BANNED', 'Bu cihazın ve ağ kimliğinin platforma erişimi engellenmiştir.', [
                'ban' => $identityBlock,
                'csrf_token' => $_SESSION['csrf_token'],
            ]);
        }
    }
    $data = $isPostAction ? requestData() : [];

    match ($action) {
        'session' => actionSession($pdo),
        'register' => actionRegister($pdo, $data),
        'login' => actionLogin($pdo, $data),
        'submit_approval' => actionSubmitApproval($pdo, $data),
        'logout' => actionLogout($pdo),
        'get_rooms' => actionGetRooms($pdo),
        'get_messages' => actionGetMessages($pdo),
        'send_message' => actionSendMessage($pdo, $data),
        'delete_message' => actionDeleteMessage($pdo, $data),
        'undo_delete_message' => actionUndoDeleteMessage($pdo, $data),
        'finalize_message_delete' => actionFinalizeMessageDelete($pdo, $data),
        'get_message_views' => actionGetMessageViews($pdo),
        'get_user_profile' => actionGetUserProfile($pdo),
        'update_profile' => actionUpdateProfile($pdo, $data),
        'update_profile_photo' => actionUpdateProfilePhoto($pdo),
        'delete_profile_photo' => actionDeleteProfilePhoto($pdo),
        'update_settings' => actionUpdateSettings($pdo, $data),
        'change_password' => actionChangePassword($pdo, $data),
        'get_media' => actionGetMedia($pdo),
        'media' => actionGetMedia($pdo),
        'admin_pending_approvals' => actionAdminPendingApprovals($pdo),
        'get_economy' => actionGetEconomy($pdo),
        'get_quests' => actionGetQuests($pdo),
        'buy_frame' => actionBuyFrame($pdo, $data),
        'equip_frame' => actionEquipFrame($pdo, $data),
        'claim_quest' => actionClaimQuest($pdo, $data),
        'claim_game_reward' => actionClaimGameReward($pdo, $data),
        'admin_login' => actionAdminLogin($pdo, $data),
        'admin_ban_user' => actionAdminBanUser($pdo, $data),
        'admin_unban_user' => actionAdminUnbanUser($pdo, $data),
        'admin_delete_user' => actionAdminDeleteUser($pdo, $data),
        'admin_decide_approval' => actionAdminDecideApproval($pdo, $data),
        'admin_change_user_password' => actionAdminChangeUserPassword($pdo, $data),
        'admin_adjust_coins' => actionAdminAdjustCoins($pdo, $data),
        'admin_send_announcement' => actionAdminSendAnnouncement($pdo, $data),
        'admin_delete_announcement' => actionAdminDeleteAnnouncement($pdo, $data),
        'admin_search_users' => actionAdminSearchUsers($pdo),
        'delete_own_account' => actionDeleteOwnAccount($pdo, $data),
        'get_banned_info' => actionGetBannedInfo($pdo),
        'ai_chat' => actionAiChat($pdo, $data),
        'heartbeat' => actionHeartbeat($pdo),
        'info_items' => actionInfoItems($pdo),
        'info_forms' => actionInfoForms($pdo),
        'info_form_replies' => actionInfoFormReplies($pdo),
        'complaint_quota' => actionComplaintQuota($pdo),
        'info_form_create' => actionInfoFormCreate($pdo, $data),
        'info_form_delete' => actionInfoFormDelete($pdo, $data),
        'info_form_like' => actionInfoFormLike($pdo, $data),
        'info_form_reply' => actionInfoFormReply($pdo, $data),
        'info_form_reply_like' => actionInfoFormReplyLike($pdo, $data),
        'complaint_submit' => actionComplaintSubmit($pdo, $data),
        'admin_feed_refresh' => actionAdminFeedRefresh($pdo),
        'admin_feed_settings' => actionAdminFeedSettings($pdo, $data),
        'admin_feed_status' => actionAdminFeedStatus($pdo),
        'admin_info_article_create' => actionAdminInfoArticleCreate($pdo, $data),
        'admin_info_article_update' => actionAdminInfoArticleUpdate($pdo, $data),
        'admin_info_article_delete' => actionAdminInfoArticleDelete($pdo, $data),
        'admin_complaints' => actionAdminComplaints($pdo),
        'admin_complaint_reject' => actionAdminComplaintReject($pdo, $data),
        default => throw new ApiError(404, 'ACTION_NOT_FOUND', 'İstek tanınmadı: ' . $action),
    };
}

if (PHP_SAPI === 'cli') {
    try {
        $command = $argv[1] ?? '';
        if ($command !== 'cleanup') {
            fwrite(STDERR, "Kullanım: php auth.php cleanup\n");
            exit(2);
        }
        $pdo = db();
        $total = 0;
        $totalForms = 0;
        do {
            $count = cleanupExpiredMessages($pdo, 5000);
            $total += $count;
        } while ($count === 5000);
        do {
            $count = cleanupExpiredForms($pdo, 1000);
            $totalForms += $count;
        } while ($count === 1000);
        cleanupExpiredBans($pdo);
        purgeInactiveAccounts($pdo, 50);
        $pdo->exec("DELETE FROM rate_limits WHERE rate_key NOT LIKE 'schema:%' AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 DAY)");
        fwrite(STDOUT, "Temizlik tamamlandı. Silinen mesaj: {$total}, silinen form: {$totalForms}\n");
        exit(0);
    } catch (Throwable $error) {
        fwrite(STDERR, 'Temizlik hatası: ' . $error->getMessage() . "\n");
        exit(1);
    }
}

try {
    dispatch();
} catch (ApiError $error) {
    if (in_array($error->status, [429, 503], true)) {
        $defaultRetry = $error->status === 429 ? 30 : 5;
        $retryAfter = max(1, min(3600, (int) ($error->details['retry_after'] ?? $defaultRetry)));
        header('Retry-After: ' . $retryAfter);
        header('Cache-Control: no-store');
    }
    respond(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage(), 'details' => $error->details]], $error->status);
} catch (Throwable $error) {
    $reference = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    error_log(sprintf('[Asra Chat][%s] %s in %s:%d', $reference, $error->getMessage(), $error->getFile(), $error->getLine()));
    if (isTransientDbError($error)) {
        // Yoğunluk kaynaklı geçici hata: istemci kısa süre sonra kendi tekrar dener.
        header('Retry-After: 3');
        respond(['error' => [
            'code' => 'SERVICE_BUSY',
            'message' => 'Sunucu şu anda yoğun. İşlem birkaç saniye içinde otomatik olarak yeniden denenecek.',
            'details' => ['retry_after' => 3, 'reference' => $reference],
        ]], 503);
    }
    respond(['error' => [
        'code' => 'INTERNAL_ERROR',
        'message' => 'İşlem tamamlanamadı. Lütfen tekrar deneyin.',
        'details' => ['reference' => $reference],
    ]], 500);
}
