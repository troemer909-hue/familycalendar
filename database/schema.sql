-- Familienkalender – MySQL-Schema (Multi-Tenant SaaS)
-- Entspricht dem Konzeptdokument familienkalender-pwa-konzept.md, Abschnitt 4
-- Zeichensatz konsequent utf8mb4 für Umlaute/Emojis in Titeln etc.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- SaaS-Pakete
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS plans (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL UNIQUE,        -- 'standard' | 'plus'
  name VARCHAR(100) NOT NULL,
  max_members INT NOT NULL,
  max_calendar_streams INT NOT NULL DEFAULT 0,
  price_monthly DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO plans (code, name, max_members, max_calendar_streams, price_monthly) VALUES
  ('standard', 'Standard', 5, 0, 0.00),
  ('plus', 'Plus', 10, 5, 0.00)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---------------------------------------------------------------------
-- Mandanten / Kunden
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS families (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  plan_id INT NULL,
  status ENUM('trial','active','suspended','cancelled') NOT NULL DEFAULT 'trial',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Login-fähige Konten
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  is_platform_admin TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Personen je Familie (mit oder ohne Login)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS family_members (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  user_id INT NULL,                         -- NULL = reines Profil ohne Login
  display_name VARCHAR(255) NOT NULL,
  display_color VARCHAR(7) NOT NULL DEFAULT '#3b82f6',
  role ENUM('admin','member') NOT NULL DEFAULT 'member',
  member_type ENUM('login','profile') NOT NULL DEFAULT 'profile',
  birth_year YEAR NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_family_user (family_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Externe Kalenderquellen: einmaliger Import ODER laufendes Abonnement
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS calendar_sources (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  family_member_id INT NULL,
  name VARCHAR(255) NOT NULL,
  type ENUM('import_ics','import_csv','stream_ics') NOT NULL,
  source_url TEXT NULL,
  sync_interval_minutes INT NULL,
  last_synced_at DATETIME NULL,
  status ENUM('active','error','paused') NOT NULL DEFAULT 'active',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Termine
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS events (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  created_by INT NULL,                      -- family_members.id, NULL bei Import/Stream
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  location VARCHAR(255) NULL,
  starts_at DATETIME NOT NULL,
  ends_at DATETIME NOT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  scope ENUM('personal','family') NOT NULL DEFAULT 'personal',
  recurrence_rule VARCHAR(255) NULL,
  category VARCHAR(100) NULL,
  source ENUM('manual','import','stream') NOT NULL DEFAULT 'manual',
  external_uid VARCHAR(255) NULL,
  calendar_source_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (calendar_source_id) REFERENCES calendar_sources(id) ON DELETE SET NULL,
  INDEX idx_family_range (family_id, starts_at, ends_at),
  -- Upsert-Ziel für ICS/CSV-Import & laufende Abos (siehe CalendarSourceController):
  -- derselbe externe Termin wird bei erneutem Sync aktualisiert statt dupliziert.
  UNIQUE KEY uniq_source_uid (calendar_source_id, external_uid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Zuordnung Termin <-> Person(en)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS event_participants (
  id INT PRIMARY KEY AUTO_INCREMENT,
  event_id INT NOT NULL,
  family_member_id INT NOT NULL,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_event_member (event_id, family_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Erinnerungen (nur für Personen mit Login)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reminders (
  id INT PRIMARY KEY AUTO_INCREMENT,
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  remind_before_minutes INT NOT NULL DEFAULT 30,
  sent TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  -- Verhindert doppelte Erinnerungen (siehe ReminderController::create).
  UNIQUE KEY uniq_event_user_offset (event_id, user_id, remind_before_minutes)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Push-Subscriptions (pro Gerät je Login-Nutzer)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS push_subscriptions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  endpoint TEXT NOT NULL,
  p256dh_key VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  device_info VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Benachrichtigungseinstellungen je Login-Nutzer
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT PRIMARY KEY,
  own_events TINYINT(1) NOT NULL DEFAULT 1,
  family_events TINYINT(1) NOT NULL DEFAULT 1,
  changes TINYINT(1) NOT NULL DEFAULT 1,
  reminder_default_minutes INT NOT NULL DEFAULT 30,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
