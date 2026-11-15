-- Vorrat-Tracker / "Wird knapp"-Liste (siehe
-- docs/feature-ideas/vorrat-nachbestellen.md): dauerhafte Liste
-- wiederkehrender Haushaltsartikel. "Wird knapp" erzeugt automatisch
-- einen Eintrag in der Einkaufsliste (shopping_list_items, siehe
-- 019_shopping_list.sql) - beim späteren Abhaken dieses
-- Einkaufslisten-Eintrags wird low_stock hier automatisch wieder
-- zurückgesetzt (siehe ShoppingListController::update()).
--
-- Neue Tabelle per CREATE TABLE IF NOT EXISTS ist auf MySQL UND MariaDB
-- gleichermaßen portabel. Die zusätzliche Spalte auf der bestehenden
-- Tabelle shopping_list_items braucht dagegen den
-- information_schema+PREPARE-Umweg (ADD COLUMN IF NOT EXISTS ist eine
-- MariaDB-only-Erweiterung, scheitert auf echtem MySQL mit
-- Syntaxfehler - siehe 015_task_list_assignment.sql). "DO 0" statt
-- "SELECT 1" als No-Op, sonst bleibt auf der (unbuffered) PDO-
-- Verbindung ein offenes Resultset zurück.
CREATE TABLE IF NOT EXISTS household_stock_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  label VARCHAR(255) NOT NULL,
  low_stock TINYINT(1) NOT NULL DEFAULT 0,
  flagged_by INT NULL,
  flagged_at DATETIME NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (flagged_by) REFERENCES family_members(id) ON DELETE SET NULL,
  UNIQUE KEY uniq_family_label (family_id, label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ADD COLUMN und ADD FOREIGN KEY bewusst in zwei getrennten ALTER-TABLE-
-- Anweisungen (zwei PREPARE/EXECUTE-Blöcke) statt einer gemeinsamen:
-- MariaDB schlägt bei beidem in einem Schritt mit "ERROR 1823 Failed to
-- add the foreign key constraint ... to system tables" fehl (InnoDBs
-- Instant-ADD-COLUMN-Optimierung kollidiert offenbar mit dem
-- gleichzeitigen Hinzufügen eines Fremdschlüssels auf genau diese neue
-- Spalte).
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shopping_list_items' AND COLUMN_NAME = 'source_stock_item_id'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE shopping_list_items ADD COLUMN source_stock_item_id INT NULL AFTER label',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shopping_list_items'
    AND COLUMN_NAME = 'source_stock_item_id' AND REFERENCED_TABLE_NAME = 'household_stock_items'
);
SET @add_fk_sql = IF(
  @fk_exists = 0,
  'ALTER TABLE shopping_list_items ADD FOREIGN KEY (source_stock_item_id) REFERENCES household_stock_items(id) ON DELETE SET NULL',
  'DO 0'
);
PREPARE add_fk_stmt FROM @add_fk_sql;
EXECUTE add_fk_stmt;
DEALLOCATE PREPARE add_fk_stmt;
