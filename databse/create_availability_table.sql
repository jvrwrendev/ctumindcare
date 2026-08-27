-- ============================================================
--  CTU MindCare — Counselor availability schedule
--  Run in phpMyAdmin (also auto-created by counselor_availability.php)
-- ============================================================

-- Per-counselor availability rules: one-off dates and recurring
-- weekday windows. is_available = 0 marks a blocked date.
CREATE TABLE IF NOT EXISTS counselor_availability (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  counselor_id  INT NOT NULL,
  date          DATE NULL,
  day_of_week   TINYINT NULL,
  is_recurring  TINYINT(1) NOT NULL DEFAULT 0,
  is_available  TINYINT(1) NOT NULL DEFAULT 1,
  start_time    TIME NULL,
  end_time      TIME NULL,
  created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (counselor_id, date),
  INDEX (counselor_id, day_of_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
