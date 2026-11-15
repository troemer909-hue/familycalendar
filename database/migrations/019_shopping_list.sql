-- Gemeinsame Einkaufsliste (siehe
-- docs/feature-ideas/einkaufsliste.md): eine Liste pro Familie, jedes
-- Mitglied darf Artikel hinzufügen/abhaken/löschen. Abgehakte Artikel
-- bleiben (ausgegraut) sichtbar, bis "Liste leeren" sie entfernt - siehe
-- ShoppingListController::clear().
--
-- Neue Tabelle per CREATE TABLE IF NOT EXISTS ist auf MySQL UND MariaDB
-- gleichermaßen portabel, braucht also nicht den
-- information_schema+PREPARE-Umweg, der nur für ein nachträgliches
-- ALTER TABLE ADD COLUMN auf einer bestehenden Tabelle nötig ist (siehe
-- z.B. 017_calendar_source_color.sql).
CREATE TABLE IF NOT EXISTS shopping_list_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  added_by INT NULL,
  label VARCHAR(255) NOT NULL,
  checked TINYINT(1) NOT NULL DEFAULT 0,
  checked_by INT NULL,
  checked_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (added_by) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (checked_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_family_checked (family_id, checked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
