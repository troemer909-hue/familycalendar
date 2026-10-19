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
  -- Geheimes Token für den Outbound-ICS-Export (siehe IcsExportController) -
  -- NULL = noch nicht erzeugt bzw. deaktiviert.
  ics_export_token VARCHAR(64) NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (plan_id) REFERENCES plans(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Login-fähige Konten
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  -- 191 statt 255: bei utf8mb4 (4 Byte/Zeichen) wäre ein UNIQUE-Index auf
  -- VARCHAR(255) 1020 Byte lang - über dem 767-Byte-Limit älterer InnoDB-
  -- Zeilenformate (COMPACT/REDUNDANT, wie es mancher Shared-Hosting-MySQL
  -- noch als Default hat). 191*4=764 Byte passt überall, auch ohne
  -- innodb_large_prefix/Barracuda. E-Mail-Adressen sind laut RFC 5321
  -- ohnehin auf 254 Zeichen begrenzt, 191 reicht in der Praxis immer.
  email VARCHAR(191) NOT NULL UNIQUE,
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
  -- Optional: jeder Teilnehmer MIT eigenem Login bekommt bei jedem
  -- Import/Sync dieser Quelle automatisch eine Erinnerung mit dieser
  -- Vorlaufzeit (siehe CalendarSourceController/CalendarSync). NULL =
  -- keine automatischen Erinnerungen für diese Quelle.
  default_reminder_minutes INT NULL,
  -- Feste Farbe (Hex) für alle Termine dieser Quelle, unabhängig davon,
  -- welcher Person sie zugeordnet sind - überschreibt die Mitglieder-Farbe
  -- in eventColor()/Helpers.php. Wird beim Anlegen automatisch aus einer
  -- festen Palette vergeben (siehe CalendarSourceController::nextPaletteColor()).
  color VARCHAR(7) NULL,
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
  -- Gesetzt NUR bei materialisierten Instanzen einer Wiederholungsserie
  -- (siehe EventController::create() / RRule) - verweist auf den
  -- Master-Termin, der selbst recurrence_rule trägt und recurrence_parent_id
  -- NULL hat. Löschen des Masters löscht alle Instanzen per CASCADE mit.
  recurrence_parent_id INT NULL,
  category VARCHAR(100) NULL,
  source ENUM('manual','import','stream') NOT NULL DEFAULT 'manual',
  -- 190 statt 255 (gleicher Grund wie bei users.email, siehe dort) - hier
  -- zusätzlich 4 Byte für calendar_source_id in derselben zusammengesetzten
  -- UNIQUE KEY eingerechnet (190*4 + 4 = 764 Byte, passt unter 767).
  external_uid VARCHAR(190) NULL,
  calendar_source_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (calendar_source_id) REFERENCES calendar_sources(id) ON DELETE SET NULL,
  FOREIGN KEY (recurrence_parent_id) REFERENCES events(id) ON DELETE CASCADE,
  INDEX idx_family_range (family_id, starts_at, ends_at),
  INDEX idx_recurrence_parent (recurrence_parent_id),
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
-- Frei verwaltbare Termin-Kategorien - NUR für Familien im "plus"-Plan
-- relevant (siehe CategoryController::familyPlanCode()). Familien im
-- "standard"-Plan sehen stattdessen eine feste, serverseitig definierte
-- Auswahlliste (siehe CategoryController::DEFAULT_CATEGORIES) und legen
-- hier nie eigene Zeilen an. events.category bleibt bewusst ein reines
-- Freitextfeld (keine Fremdschlüssel-Beziehung zu categories.id) - ein
-- gelöschtes/umbenanntes Kategorie-Label soll bereits vergebene Termine
-- nicht invalidieren, genau wie beim bisherigen Freitext-Verhalten.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#64748b',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_family_category_name (family_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Geteilte Aufgaben-/Einkaufslisten pro Familie (siehe
-- docs/aufgabenlisten-konzept.md und TaskListController). Eigene Tabellen
-- statt Zweckentfremdung von events: eine Aufgabe hat kein starts_at/
-- ends_at/Ort, eine Vermischung würde EventController nur mit
-- Sonderfällen verunreinigen (analog zur Begründung bei categories oben).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS task_lists (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  -- Optional: "diese Liste ist für Kind X" - löst beim Anlegen/Ändern eine
  -- Push-Benachrichtigung an diese Person aus (siehe TaskListController).
  -- KEINE Zugriffsbeschränkung, die Liste bleibt für die ganze Familie
  -- sichtbar/bearbeitbar wie jede andere.
  assigned_member_id INT NULL,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by) REFERENCES family_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_list_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  assigned_member_id INT NULL,
  due_date DATE NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  done_at DATETIME NULL,
  done_by INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (done_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_task_list_done (task_list_id, is_done)
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
  -- SHA-256 von endpoint - TEXT kann in MySQL nicht direkt eindeutig
  -- indiziert werden, siehe PushSubscriptionController::subscribe().
  endpoint_hash VARCHAR(64) NOT NULL,
  p256dh_key VARCHAR(255) NOT NULL,
  auth_key VARCHAR(255) NOT NULL,
  device_info VARCHAR(255) NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_user_endpoint (user_id, endpoint_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Benachrichtigungseinstellungen je Login-Nutzer
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_preferences (
  user_id INT PRIMARY KEY,
  own_events TINYINT(1) NOT NULL DEFAULT 1,
  family_events TINYINT(1) NOT NULL DEFAULT 1,
  changes TINYINT(1) NOT NULL DEFAULT 1,
  -- Globale Kanal-Schalter: gelten für ALLE Erinnerungen des Nutzers,
  -- zusätzlich zu (nicht statt) den einzelnen Erinnerungen, die er pro
  -- Termin anlegt (siehe bin/send-reminders.php).
  channel_mail TINYINT(1) NOT NULL DEFAULT 1,
  channel_push TINYINT(1) NOT NULL DEFAULT 1,
  reminder_default_minutes INT NOT NULL DEFAULT 30,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Passwort-Reset-Tokens
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  -- SHA-256 des Tokens, NICHT der Token selbst - siehe
  -- AuthController::requestPasswordReset().
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Rate-Limiting für den Login (siehe src/Core/RateLimiter.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  -- 191 statt 255 (gleicher Grund wie bei users.email, siehe dort) - auch
  -- ein normaler, nicht-eindeutiger INDEX unterliegt dem 767-Byte-Limit.
  email VARCHAR(191) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_created (email, created_at),
  INDEX idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Einladungs-Tokens (siehe MemberController::invite() /
-- AuthController::acceptInvite())
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS member_invites (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_member_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  -- SHA-256 des Tokens, NICHT der Token selbst - analog zu password_resets.
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Selbst-Update (siehe SelfUpdateController / README Abschnitt
-- "Selbst-Update")
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS schema_migrations (
  -- 191 statt 255 (gleicher Grund wie bei users.email, siehe dort) -
  -- Migrationsdateinamen sind ohnehin nie annähernd so lang.
  migration VARCHAR(191) PRIMARY KEY,
  applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bei einer FRISCHEN Installation per schema.sql sind alle bisherigen
-- Migrationsdateien bereits enthalten - sofort als "angewendet" markieren,
-- damit der Runner sie nicht fälschlich erneut ausführen will.
INSERT IGNORE INTO schema_migrations (migration) VALUES
  ('002_calendar_source_sync.sql'),
  ('003_reminders_unique.sql'),
  ('004_recurring_events.sql'),
  ('005_push_subscriptions_unique.sql'),
  ('006_notification_preferences_channels.sql'),
  ('007_password_resets.sql'),
  ('008_login_attempts.sql'),
  ('009_ics_export_token.sql'),
  ('010_member_invites.sql'),
  ('011_self_update.sql'),
  ('012_shorten_indexed_varchars.sql'),
  ('013_categories.sql'),
  ('014_task_lists.sql'),
  ('015_task_list_assignment.sql'),
  ('016_calendar_source_reminders.sql'),
  ('017_calendar_source_color.sql');

CREATE TABLE IF NOT EXISTS self_update_runs (
  id INT PRIMARY KEY AUTO_INCREMENT,
  triggered_by_user_id INT NOT NULL,
  from_commit VARCHAR(40) NULL,
  to_commit VARCHAR(40) NULL,
  migrations_applied TEXT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  message TEXT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (triggered_by_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
