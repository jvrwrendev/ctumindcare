-- ============================================================
--  CTU MindCare — AI Assistant support tables
--  Run in phpMyAdmin (also auto-created by chatbot/api.php)
-- ============================================================

-- Queue of booking requests made while NO counselor was online.
-- When a counselor becomes available, the student is notified.
CREATE TABLE IF NOT EXISTS chatbot_booking_requests (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  student_id     INT NOT NULL,
  preferred_date DATE NULL,
  preferred_time TIME NULL,
  topic          VARCHAR(255) NULL,
  status         ENUM('waiting','notified','fulfilled','cancelled') DEFAULT 'waiting',
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  notified_at    DATETIME NULL,
  INDEX (student_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Risk flags raised by the AI assistant's urgency assessment.
-- Counselors review these on the AI support logs page.
CREATE TABLE IF NOT EXISTS chatbot_risk_flags (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  student_id INT NOT NULL,
  risk       ENUM('medium','high') NOT NULL,
  reason     VARCHAR(255) NULL,
  message_id INT NULL,
  status     ENUM('open','reviewed') DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (student_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add risk level column to conversation history (safe to ignore if already added)
-- ALTER TABLE chatbot_messages ADD COLUMN risk ENUM('low','medium','high') DEFAULT 'low' AFTER sender;
