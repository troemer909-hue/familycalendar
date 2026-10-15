-- Passwort-Reset-Tokens. Gespeichert wird NUR der SHA-256-Hash des Tokens
-- (nicht der Token selbst) - analog zu push_subscriptions.endpoint_hash:
-- selbst bei einem DB-Leak lässt sich daraus kein gültiger Reset-Link
-- rekonstruieren (siehe AuthController::requestPasswordReset()).
-- Für NEUE Installationen ist die Tabelle bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
CREATE TABLE IF NOT EXISTS password_resets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  user_id INT NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
