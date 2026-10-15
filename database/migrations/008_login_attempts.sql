-- Rate-Limiting für den Login (AuthController::login() /
-- src/Core/RateLimiter.php). Jeder FEHLGESCHLAGENE Login-Versuch landet
-- hier; bei Erfolg werden die Zeilen für diese E-Mail wieder gelöscht.
-- Für NEUE Installationen ist die Tabelle bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
CREATE TABLE IF NOT EXISTS login_attempts (
  id INT PRIMARY KEY AUTO_INCREMENT,
  email VARCHAR(255) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_email_created (email, created_at),
  INDEX idx_ip_created (ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
