-- Ergänzt notification_preferences um Kanal-Schalter (E-Mail/Push), damit
-- Nutzer zentral steuern können, über welche Kanäle Erinnerungen überhaupt
-- zugestellt werden - unabhängig von den einzelnen Erinnerungen, die sie
-- pro Termin anlegen (siehe ReminderController / bin/send-reminders.php).
-- Für NEUE Installationen sind die Spalten bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
ALTER TABLE notification_preferences
  ADD COLUMN channel_mail TINYINT(1) NOT NULL DEFAULT 1 AFTER changes,
  ADD COLUMN channel_push TINYINT(1) NOT NULL DEFAULT 1 AFTER channel_mail;
