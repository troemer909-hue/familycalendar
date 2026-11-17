-- Zufällige Aufgaben-Zuteilung ("Losliste", siehe
-- docs/feature-ideas/aufgaben-zufallsverteilung.md): eine Aufgabenliste
-- bekommt optional einen Zuteilungs-Pool (Personen + Rhythmus + Modus),
-- der Cron-Job bin/draw-task-assignments.php lost dann regelmäßig neu,
-- wer welche Aufgabe übernimmt.
--
-- Neue Tabellen per CREATE TABLE IF NOT EXISTS sind - anders als ein
-- nachträgliches ALTER TABLE ADD COLUMN - auf MySQL UND MariaDB
-- gleichermaßen portabel, brauchen also nicht den
-- information_schema+PREPARE-Umweg aus z.B. 017_calendar_source_color.sql.
CREATE TABLE IF NOT EXISTS task_assignment_pools (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_list_id INT NOT NULL,
  rhythm ENUM('daily','weekly','monthly') NOT NULL,
  mode ENUM('random','fair') NOT NULL DEFAULT 'fair',
  next_draw_at DATE NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_list_pool (task_list_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS task_assignment_pool_members (
  id INT PRIMARY KEY AUTO_INCREMENT,
  pool_id INT NOT NULL,
  family_member_id INT NOT NULL,
  max_tasks INT NULL,                       -- NULL = unbegrenzt
  FOREIGN KEY (pool_id) REFERENCES task_assignment_pools(id) ON DELETE CASCADE,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  UNIQUE KEY uniq_pool_member (pool_id, family_member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- tasks.auto_assign: ALTER TABLE ADD COLUMN IF NOT EXISTS ist eine
-- MariaDB-only-Erweiterung und scheitert auf echtem MySQL mit
-- Syntaxfehler (siehe 015_task_list_assignment.sql) - deshalb der
-- information_schema+PREPARE-Umweg. "DO 0" statt "SELECT 1" als No-Op,
-- sonst bleibt auf der (unbuffered) PDO-Verbindung ein offenes
-- Resultset zurück.
SET @column_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks' AND COLUMN_NAME = 'auto_assign'
);
SET @add_column_sql = IF(
  @column_exists = 0,
  'ALTER TABLE tasks ADD COLUMN auto_assign TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order',
  'DO 0'
);
PREPARE add_column_stmt FROM @add_column_sql;
EXECUTE add_column_stmt;
DEALLOCATE PREPARE add_column_stmt;
