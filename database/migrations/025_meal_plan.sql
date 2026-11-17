-- Gemeinsamer Essensplan je Familie: genau ein Gericht pro Mahlzeit und Tag.
CREATE TABLE IF NOT EXISTS meal_plan_entries (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  entry_date DATE NOT NULL,
  meal_slot ENUM('breakfast','lunch','dinner','snack') NOT NULL,
  label VARCHAR(255) NOT NULL,
  notes TEXT NULL,
  created_by_member_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by_member_id) REFERENCES family_members(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_family_date_slot (family_id, entry_date, meal_slot),
  INDEX idx_family_date (family_id, entry_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
