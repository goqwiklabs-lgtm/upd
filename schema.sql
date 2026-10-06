-- Database schema for InfinityFree MySQL
-- File Storage & Multi-Account Google Drive Pool

CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('user', 'admin') DEFAULT 'user',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `google_accounts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `account_email` VARCHAR(100) NOT NULL,
  `client_id` VARCHAR(255) NOT NULL,
  `client_secret` VARCHAR(255) NOT NULL,
  `refresh_token` TEXT NOT NULL,
  `access_token` TEXT NULL,
  `token_expires_at` INT DEFAULT 0,
  `used_storage_bytes` BIGINT DEFAULT 0,
  -- 13 GB limit = 13 * 1024 * 1024 * 1024 bytes = 13958643712 bytes (leaves 2GB buffer)
  `storage_limit_bytes` BIGINT DEFAULT 13958643712,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `folders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `parent_id` INT NULL,
  `name` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `folders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `files` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `folder_id` INT NULL,
  `google_account_id` INT NOT NULL,
  `google_file_id` VARCHAR(255) NOT NULL,
  `name` VARCHAR(255) NOT NULL,
  `size_bytes` BIGINT NOT NULL,
  `mime_type` VARCHAR(150) NOT NULL,
  `share_token` VARCHAR(64) NOT NULL UNIQUE,
  `is_public` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`folder_id`) REFERENCES `folders`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`google_account_id`) REFERENCES `google_accounts`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin account (Username: admin, Password: admin123)
-- Hash generated via password_hash('admin123', PASSWORD_BCRYPT)
INSERT IGNORE INTO `users` (`id`, `username`, `email`, `password_hash`, `role`)
VALUES (1, 'admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
