-- Grundlage für das Selbst-Update-Feature (SelfUpdateController): eine
-- Tabelle, die protokolliert, welche Migrationsdateien bereits angewendet
-- wurden, damit der automatische Migrations-Runner beim nächsten Update
-- nur NEUE Dateien ausführt (siehe README, Abschnitt "Selbst-Update").
-- Für NEUE Installationen ist dies bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
CREATE TABLE IF NOT EXISTS schema_migrations (
  migration VARCHAR(255) PRIMARY KEY,
  applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Alle bis zu diesem Release bereits existierenden Migrationsdateien sind
-- in database/schema.sql ohnehin schon vollständig enthalten - hier nur
-- als "bereits angewendet" markieren, damit der Runner sie nicht erneut
-- (und damit scheiternd, z.B. doppeltes ALTER TABLE ADD COLUMN) ausführt.
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
  ('011_self_update.sql');

-- Protokoll jedes Selbst-Update-Laufs (wer, wann, von welchem auf welchen
-- Commit, welche Migrationen, Erfolg/Fehler) - rein zur Nachvollziehbarkeit
-- einer sicherheitsrelevanten Aktion, siehe SelfUpdateController.
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
