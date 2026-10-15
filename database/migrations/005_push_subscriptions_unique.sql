-- push_subscriptions.endpoint ist TEXT (Push-Endpoints können lang sein) und
-- kann in MySQL nicht direkt eindeutig indiziert werden. Stattdessen wird
-- ein SHA-256-Hash des Endpoints in einer eigenen Spalte gepflegt, über die
-- sich doppelte Abos desselben Nutzers/Geräts per ON DUPLICATE KEY UPDATE
-- erkennen lassen (siehe PushSubscriptionController::subscribe()).
-- Für NEUE Installationen ist die Spalte bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
ALTER TABLE push_subscriptions
  ADD COLUMN endpoint_hash VARCHAR(64) NOT NULL DEFAULT '' AFTER endpoint;

UPDATE push_subscriptions SET endpoint_hash = SHA2(endpoint, 256) WHERE endpoint_hash = '';

ALTER TABLE push_subscriptions
  ADD UNIQUE KEY uniq_user_endpoint (user_id, endpoint_hash);
