-- Einladungs-Tokens für das Hochstufen eines Profils (ohne Login) zu einem
-- Vollmitglied. Ersetzt den bisherigen Ablauf, bei dem der Admin selbst ein
-- Passwort für die andere Person vergeben musste - jetzt gibt der Admin nur
-- die E-Mail-Adresse an, die eingeladene Person setzt ihr Passwort über den
-- Link selbst (siehe MemberController::invite() / AuthController::
-- acceptInvite()). Gespeichert wird NUR der SHA-256-Hash des Tokens, analog
-- zu password_resets.
-- Für NEUE Installationen ist die Tabelle bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (einmalig per phpMyAdmin/Konsole ausführen).
CREATE TABLE IF NOT EXISTS member_invites (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_member_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  token_hash VARCHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_token_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
