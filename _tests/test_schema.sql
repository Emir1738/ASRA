-- ASRA test ortamı: baz şema (üretimdeki temel tabloların test kopyası)
CREATE DATABASE IF NOT EXISTS asra_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'asra'@'localhost' IDENTIFIED BY 'asra_test_pw';
GRANT ALL PRIVILEGES ON asra_test.* TO 'asra'@'localhost';
FLUSH PRIVILEGES;
USE asra_test;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(30) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  session_version INT UNSIGNED NOT NULL DEFAULT 1,
  avatar_color VARCHAR(16) NOT NULL DEFAULT 'c1',
  profile_image_path VARCHAR(255) NULL,
  theme VARCHAR(16) NOT NULL DEFAULT 'dark',
  language VARCHAR(8) NOT NULL DEFAULT 'tr',
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
  approval_status ENUM('approved','pending') NOT NULL DEFAULT 'approved',
  approval_reason VARCHAR(1000) NULL,
  approval_submitted_at DATETIME NULL,
  approval_decision_notice ENUM('accepted') NULL,
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
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS announcements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id BIGINT UNSIGNED NOT NULL,
  message VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_announcements_admin (admin_id, created_at),
  CONSTRAINT fk_announcements_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id SMALLINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NULL,
  announcement_id BIGINT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'text',
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
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_views (
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (message_id, user_id),
  KEY idx_message_views_message (message_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS message_deletions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  deleted_for_user TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_message_deletions (message_id, user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS banned_users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  banned_until DATETIME NULL,
  banned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_banned_user (user_id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS deleted_users_log (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL,
  username VARCHAR(30) NOT NULL,
  reason VARCHAR(500) NULL,
  deleted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_deleted_username (username)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
  rate_key VARCHAR(191) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NULL,
  blocked_until DATETIME NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_key),
  KEY idx_rate_limits_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Oda kataloğu (auth.php canonicalRoomCatalog ile birebir)
INSERT INTO rooms (id, name, description, position) VALUES
(1,'Asra Genel','Topluluk gündemi, duyurular ve genel sohbet.',1),
(2,'Asra Astrofotoğraf','Gece gökyüzü çekimleri, ekipman ve işleme teknikleri.',2),
(3,'Asra Astronomi','Gök cisimleri, uzay araştırmaları ve gözlem notları.',3),
(4,'Asra Politika','Güncel siyaset ve kamu politikaları üzerine saygılı tartışmalar.',4),
(5,'Asra Makale','Makaleler, denemeler ve uzun biçimli içerikler.',5),
(6,'Asra Fotoğrafçılık','Fotoğraf teknikleri, kompozisyon ve ekipman konuşmaları.',6),
(7,'Asra Coğrafya','Dünya, bölgeler, iklim ve insan coğrafyası.',7),
(8,'Asra Antropoloji','İnsan, kültür ve toplumların bilimsel incelemeleri.',8),
(9,'Asra Spor','Spor dalları, karşılaşmaları ve performansları.',9),
(10,'Asra Teknoloji','Yazılım, donanım ve yeni teknolojiler.',10),
(11,'Asra Sanat','Görsel sanatlar, müzik, edebiyat ve sahne sanatları.',11),
(12,'Asra Matematik','Matematiksel problemler, kuramlar ve çözüm yöntemleri.',12),
(13,'Asra Sosyoloji','Toplum, kurumlar ve sosyal ilişkiler.',13),
(14,'Asra Psikoloji','Toplum, birey, bilinç ve psikoloji araştırmaları.',14),
(15,'Asra Tarih','Geçmiş dönemler, olaylar ve tarih araştırmaları.',15);
