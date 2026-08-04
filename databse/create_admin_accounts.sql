-- ============================================================
--  CTU MindCare — Create counselor (wellness_admin) and
--  MIS office (system_admin) accounts
--  Run in phpMyAdmin against the database used by
--  includes/config.php (DB_NAME = ctumindcares)
-- ============================================================

CREATE TABLE IF NOT EXISTS system_admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(50) NOT NULL,
  last_name  VARCHAR(50) NOT NULL,
  email      VARCHAR(100) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  is_active  TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wellness_admins (
  id INT AUTO_INCREMENT PRIMARY KEY,
  first_name VARCHAR(50) NOT NULL,
  last_name  VARCHAR(50) NOT NULL,
  email      VARCHAR(100) NOT NULL UNIQUE,
  password   VARCHAR(255) NOT NULL,
  specialization VARCHAR(100),
  is_active  TINYINT(1) DEFAULT 1,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counselor (wellness_admin) -> password: Counselor@CTU2024
INSERT INTO wellness_admins (first_name, last_name, email, password, specialization)
VALUES ('Wellness', 'Counselor', 'counselor@ctu.edu.ph',
        '$2y$10$40ge46lTgIG7xVAuWSd.meJaMp/hj3K1X7ju3fGQdy08qrHd.czOm', 'General Counseling');

-- MIS office (system_admin) -> password: MIS@CTU2024
INSERT INTO system_admins (first_name, last_name, email, password)
VALUES ('MIS', 'Office', 'mis@ctu.edu.ph',
        '$2y$10$9OEtF5YvRv/SyiN/5O1IweDbimYXOEuFbtZKq7SferqCvRZ.8nuHO');
