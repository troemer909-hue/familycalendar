-- Geteilte Aufgaben-/Einkaufslisten pro Familie (siehe
-- docs/aufgabenlisten-konzept.md und TaskListController). Eigene Tabellen
-- statt Zweckentfremdung von events: eine Aufgabe hat kein starts_at/
-- ends_at/Ort, eine Vermischung würde EventController nur mit
-- Sonderfällen verunreinigen (analog zur Begründung bei categories, siehe
-- 013_categories.sql). Für NEUE Installationen bereits in
-- database/schema.sql enthalten - dieses Skript ist nur für bereits
-- bestehende Datenbanken nötig (automatisch per Selbst-Update).
CREATE TABLE IF NOT EXISTS task_lists (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_id INT NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_by INT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
  FOREIGN KEY (created_by) REFERENCES family_members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id INT PRIMARY KEY AUTO_INCREMENT,
  task_list_id INT NOT NULL,
  title VARCHAR(255) NOT NULL,
  assigned_member_id INT NULL,
  due_date DATE NULL,
  is_done TINYINT(1) NOT NULL DEFAULT 0,
  done_at DATETIME NULL,
  done_by INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (task_list_id) REFERENCES task_lists(id) ON DELETE CASCADE,
  FOREIGN KEY (assigned_member_id) REFERENCES family_members(id) ON DELETE SET NULL,
  FOREIGN KEY (done_by) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_task_list_done (task_list_id, is_done)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
