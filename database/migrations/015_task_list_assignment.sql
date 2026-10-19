-- Aufgabenlisten lassen sich optional einer Person zuweisen ("diese Liste
-- ist für Kind X") - siehe TaskListController. Bleibt für die ganze
-- Familie sichtbar/bearbeitbar wie bisher, KEINE Zugriffsbeschränkung;
-- die Zuweisung triggert nur eine Push-Benachrichtigung an die
-- zugewiesene Person (sofern sie ein eigenes Login + Push-Abo hat).
-- Für NEUE Installationen bereits in database/schema.sql enthalten -
-- dieses Skript ist nur für bereits bestehende Datenbanken nötig
-- (automatisch per Selbst-Update).
--
-- "ADD COLUMN IF NOT EXISTS" ist eine MariaDB-Erweiterung, die auf
-- echtem MySQL (z.B. der MySQL-8-Service-Container in CI, siehe
-- .github/workflows/tests.yml) mit einem Syntaxfehler scheitert - hier
-- stattdessen der portable Weg über information_schema + dynamisches SQL.
-- Wird genau EINMAL gebraucht, weil MigrationRunnerTest::
-- testBootstrapsSchemaMigrationsTableOnPreExistingInstall die komplette
-- Migrationsliste testweise gegen ein bereits aktuelles Schema erneut
-- abspielt - in echten Selbst-Update-Läufen verhindert schema_migrations
-- ein zweites Ausführen ohnehin.
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_lists' AND COLUMN_NAME = 'assigned_member_id'
);
-- "DO 0" statt "SELECT 1" als No-Op: ein SELECT hinterlässt ein offenes
-- Resultset auf der (unbuffered) PDO-Verbindung, das nachfolgende Queries
-- auf derselben Verbindung mit "Cannot execute queries while other
-- unbuffered queries are active" blockiert - DO verwirft sein Ergebnis
-- sofort, ohne diesen Nebeneffekt.
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE task_lists ADD COLUMN assigned_member_id INT NULL AFTER name, ADD FOREIGN KEY (assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;
