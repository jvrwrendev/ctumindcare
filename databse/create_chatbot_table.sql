-- ============================================================
--  CTU MindCare — AI Assistant chat history table
--  Run in phpMyAdmin (also auto-created by chatbot/api.php)
-- ============================================================
CREATE TABLE IF NOT EXISTS chatbot_messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  user_role  VARCHAR(20) NOT NULL,
  sender     ENUM('user','bot') NOT NULL,
  message    TEXT NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
