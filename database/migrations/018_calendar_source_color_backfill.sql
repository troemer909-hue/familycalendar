-- Quellen, die VOR Migration 017 angelegt wurden, haben color = NULL (die
-- automatische Palette-Vergabe passiert nur beim Anlegen einer neuen
-- Quelle, siehe CalendarSourceController::nextPaletteColor()). Ohne diesen
-- Nachtrag würden bereits bestehende Quellen nie eine eigene Farbe
-- bekommen und weiterhin nach der alten Logik (Mitglieder-/Familientermin-
-- Farbe) dargestellt - genau das vom Nutzer gemeldete "die Farbe habe ich
-- nicht gefunden".
--
-- Nur WHERE color IS NULL -> auf bereits befüllten Installationen beim
-- erneuten Lauf ein No-Op, dadurch idempotent (wird von
-- MigrationRunnerTest::testBootstrapsSchemaMigrationsTableOnPreExistingInstall
-- gegen eine leere calendar_sources-Tabelle erneut abgespielt).
--
-- ROW_NUMBER() OVER (...) statt Session-Variablen-Trick in der UPDATE..JOIN-
-- Subquery: in MySQL 8.0 ist die Zuweisungsreihenfolge von
-- Nutzervariablen innerhalb einer SELECT-Unterabfrage nicht zuverlässig
-- garantiert - Fensterfunktionen sind seit MySQL 8.0/MariaDB 10.2 Standard
-- und liefern ein deterministisches Ergebnis.
UPDATE calendar_sources cs
JOIN (
  SELECT id,
         ROW_NUMBER() OVER (PARTITION BY family_id ORDER BY created_at, id) - 1 AS rn
  FROM calendar_sources
  WHERE color IS NULL
) ranked ON ranked.id = cs.id
SET cs.color = ELT(
  (ranked.rn MOD 8) + 1,
  '#f97316', '#0ea5e9', '#a855f7', '#22c55e',
  '#ec4899', '#eab308', '#14b8a6', '#6366f1'
);
