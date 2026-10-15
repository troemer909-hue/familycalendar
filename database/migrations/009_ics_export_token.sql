-- Outbound-ICS-Export: ein geheimes Token pro Familie, über das externe
-- Kalender-Clients (Google/Outlook/Apple Kalender) den Familienkalender
-- read-only abonnieren können (siehe IcsExportController). NULL = noch
-- nicht erzeugt bzw. deaktiviert.
-- Für NEUE Installationen ist die Spalte bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
ALTER TABLE families
  ADD COLUMN ics_export_token VARCHAR(64) NULL UNIQUE AFTER status;
