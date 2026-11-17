-- Rotierende feste Reihenfolge für Aufgabenpools. Die letzte Zuteilung
-- wird je Aufgabe gespeichert, damit jede Aufgabe unabhängig rotiert.
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'last_assigned_member_id'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE tasks ADD COLUMN last_assigned_member_id INT NULL AFTER assigned_member_id, ADD FOREIGN KEY (last_assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'task_assignment_pool_members' AND COLUMN_NAME = 'position'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE task_assignment_pool_members ADD COLUMN position INT NOT NULL DEFAULT 0 AFTER max_tasks',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

ALTER TABLE task_assignment_pools
  MODIFY mode ENUM('random','fair','fixed_order') NOT NULL DEFAULT 'fair';
