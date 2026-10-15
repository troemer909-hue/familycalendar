-- Verhindert doppelte Erinnerungen (gleicher Termin, gleicher Nutzer, gleicher
-- Vorlauf). Für NEUE Installationen ist der Schlüssel bereits in
-- database/schema.sql enthalten - dieses Skript ist nur für bereits
-- bestehende Datenbanken nötig (einmalig per phpMyAdmin/Konsole ausführen).
ALTER TABLE reminders
  ADD UNIQUE KEY uniq_event_user_offset (event_id, user_id, remind_before_minutes);
