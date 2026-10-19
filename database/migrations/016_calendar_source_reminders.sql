-- ICS/CSV-Import und ICS-Abos konnten bisher keine Erinnerungen für die
-- importierten/abonnierten Termine auslösen (siehe CalendarSourceController/
-- CalendarSync). Optionales Feld: ist es gesetzt, bekommt jeder Teilnehmer
-- MIT eigenem Login bei jedem Import/Sync (auch bei künftigen, über den
-- Cron-Job nachgezogenen Terminen eines Abos) automatisch eine Erinnerung
-- mit dieser Vorlaufzeit - Entscheidung wird einmalig beim Einrichten der
-- Quelle getroffen, nicht pro einzelnem Termin.
--
-- Portabler Weg über information_schema + dynamisches SQL statt "ADD
-- COLUMN IF NOT EXISTS" (MariaDB-Erweiterung, scheitert auf echtem MySQL
-- mit Syntaxfehler - siehe 015_task_list_assignment.sql) - wird genau
-- einmal gebraucht, weil MigrationRunnerTest::
-- testBootstrapsSchemaMigrationsTableOnPreExistingInstall die komplette
-- Migrationsliste testweise gegen ein bereits aktuelles Schema erneut
-- abspielt. "DO 0" statt "SELECT 1" als No-Op, sonst bleibt auf der
-- (unbuffered) PDO-Verbindung ein offenes Resultset zurück.
-- Für NEUE Installationen bereits in database/schema.sql enthalten -
-- dieses Skript ist nur für bereits bestehende Datenbanken nötig
-- (automatisch per Selbst-Update).
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_sources' AND COLUMN_NAME = 'default_reminder_minutes'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE calendar_sources ADD COLUMN default_reminder_minutes INT NULL AFTER sync_interval_minutes',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;
