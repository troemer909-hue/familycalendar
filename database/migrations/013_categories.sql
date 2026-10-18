-- Frei verwaltbare Termin-Kategorien für Familien im "plus"-Plan (siehe
-- CategoryController). Familien im "standard"-Plan sehen stattdessen eine
-- feste, serverseitig definierte Auswahlliste und legen hier nie eigene
-- Zeilen an. Für NEUE Installationen bereits in database/schema.sql
-- enthalten - dieses Skript ist nur für bereits bestehende Datenbanken
-- nötig (automatisch per Selbst-Update, siehe README).
CREATE TABLE IF NOT EXISTS categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(50) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#64748b',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_family_category_name (family_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
