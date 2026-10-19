-- Aufgabenlisten lassen sich optional einer Person zuweisen ("diese Liste
-- ist für Kind X") - siehe TaskListController. Bleibt für die ganze
-- Familie sichtbar/bearbeitbar wie bisher, KEINE Zugriffsbeschränkung;
-- die Zuweisung triggert nur eine Push-Benachrichtigung an die
-- zugewiesene Person (sofern sie ein eigenes Login + Push-Abo hat).
-- Für NEUE Installationen bereits in database/schema.sql enthalten -
-- dieses Skript ist nur für bereits bestehende Datenbanken nötig
-- (automatisch per Selbst-Update).
-- ADD COLUMN IF NOT EXISTS (MariaDB-Erweiterung) + unbenannter Fremdschlüssel
-- (wie bei allen anderen *_id-Spalten in schema.sql) statt eines benannten
-- CONSTRAINT: ein zweiter Lauf dieser Migration (siehe
-- MigrationRunnerTest::testBootstrapsSchemaMigrationsTableOnPreExistingInstall,
-- das die komplette Migrationsliste testweise gegen ein bereits aktuelles
-- Schema erneut abspielt) würde bei einem benannten CONSTRAINT mit
-- "Duplicate key name" scheitern, sobald die Spalte (und ihr Fremdschlüssel)
-- bereits über database/schema.sql vorhanden sind. In echten Selbst-Update-
-- Läufen passiert das nie zweimal (schema_migrations verhindert das), hier
-- geht es nur um diese eine Idempotenz-Eigenschaft aller Migrationen ab
-- 011_self_update.sql.
ALTER TABLE task_lists
  ADD COLUMN IF NOT EXISTS assigned_member_id INT NULL AFTER name,
  ADD FOREIGN KEY (assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL;
