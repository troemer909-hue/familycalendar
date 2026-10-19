-- Wunsch: Termine aus abonnierten/importierten Kalendern sollen eine eigene,
-- feste Farbe bekommen, unabhängig davon, welcher Person sie zugeordnet
-- sind (bisher bestimmte ausschließlich die Farbe des ersten Teilnehmers
-- den Termin-Chip, siehe eventColor() in Helpers.php). Jede Kalenderquelle
-- bekommt beim Anlegen automatisch eine Farbe aus einer festen Palette
-- zugewiesen (CalendarSourceController::nextPaletteColor()), damit mehrere
-- Quellen optisch unterscheidbar bleiben.
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
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'calendar_sources' AND COLUMN_NAME = 'color'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE calendar_sources ADD COLUMN color VARCHAR(7) NULL AFTER default_reminder_minutes',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;
