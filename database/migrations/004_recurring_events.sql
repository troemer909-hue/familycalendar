-- Wiederkehrende Termine werden als eigenständige Zeilen materialisiert:
-- der Master-Termin (erstes Vorkommen) behält recurrence_rule, jede weitere
-- Instanz ist eine eigene events-Zeile mit recurrence_parent_id = Master-ID.
-- Löschen des Masters löscht per CASCADE automatisch alle Instanzen mit.
-- Für NEUE Installationen ist die Spalte bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
ALTER TABLE events
  ADD COLUMN recurrence_parent_id INT NULL AFTER recurrence_rule,
  ADD FOREIGN KEY (recurrence_parent_id) REFERENCES events(id) ON DELETE CASCADE,
  ADD INDEX idx_recurrence_parent (recurrence_parent_id);
