-- Ermöglicht Upsert bei ICS/CSV-Import und laufenden Abonnements: derselbe
-- externe Termin (external_uid) innerhalb einer Quelle wird bei erneutem
-- Sync aktualisiert statt dupliziert angelegt. Für NEUE Installationen ist
-- der Schlüssel bereits in database/schema.sql enthalten - dieses Skript
-- ist nur für bereits bestehende Datenbanken nötig (einmalig per
-- phpMyAdmin/Konsole ausführen).
ALTER TABLE events
  ADD UNIQUE KEY uniq_source_uid (calendar_source_id, external_uid);
