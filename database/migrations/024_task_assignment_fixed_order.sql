-- Feste Aufgabenrotation (Issue #58): Positionen legen die Reihenfolge
-- fest, in der Mitglieder bei jeder Aufgabe zyklisch berücksichtigt werden.
-- MODIFY auf denselben ENUM ist wiederholbar und funktioniert auf MySQL und
-- MariaDB.
ALTER TABLE task_assignment_pools
  MODIFY mode ENUM('random','fair','fixed_order') NOT NULL DEFAULT 'fair';

SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'task_assignment_pool_members'
    AND COLUMN_NAME = 'position'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE task_assignment_pool_members ADD COLUMN position INT NOT NULL DEFAULT 0 AFTER max_tasks',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;

-- Existing pools get a stable initial order by member ID. The UI lets
-- families change that order afterward.
SET @position_pool_id = NULL;
SET @position = -1;
UPDATE task_assignment_pool_members
SET position = IF(
  @position_pool_id = pool_id,
  @position := @position + 1,
  @position := 0
),
pool_id = (@position_pool_id := pool_id)
ORDER BY pool_id, family_member_id;
