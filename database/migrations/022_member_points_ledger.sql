-- ---------------------------------------------------------------------
-- Belohnungssystem / Taschengeld ("member points")
-- ---------------------------------------------------------------------
ALTER TABLE tasks
  ADD COLUMN reward_points INT NULL DEFAULT NULL AFTER auto_assign,
  ADD COLUMN completed_by_member_id INT NULL AFTER done_by;

CREATE TABLE IF NOT EXISTS member_points_ledger (
  id INT PRIMARY KEY AUTO_INCREMENT,
  family_member_id INT NOT NULL,
  task_id INT NULL,
  points INT NOT NULL,
  reason ENUM('task_completed','task_reverted','manual_adjustment','redemption') NOT NULL,
  created_by_member_id INT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (family_member_id) REFERENCES family_members(id) ON DELETE CASCADE,
  FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE SET NULL,
  FOREIGN KEY (created_by_member_id) REFERENCES family_members(id) ON DELETE SET NULL,
  INDEX idx_family_member (family_member_id),
  INDEX idx_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
