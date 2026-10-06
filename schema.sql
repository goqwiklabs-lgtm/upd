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
  -- Dynamic limit calculated as: (Total Google Drive Capacity - User Previous Usage - 2GB Safety Buffer)
  `storage_limit_bytes` BIGINT DEFAULT 13958643712,
  `total_capacity_bytes` BIGINT DEFAULT 16106127360,
  `initial_used_bytes` BIGINT DEFAULT 0,
  `drive_folder_id` VARCHAR(255) NULL,
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

CREATE TABLE IF NOT EXISTS `settings` (
  `key_name` VARCHAR(50) PRIMARY KEY,
  `key_value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default admin account (Email: omkumar.working@gmail.com, Username: omkumar, Password: Q0gng04bk3)
INSERT IGNORE INTO `users` (`id`, `username`, `email`, `password_hash`, `role`)
VALUES (1, 'omkumar', 'omkumar.working@gmail.com', '$2y$12$2bx9dFXpU8Agv0LIsKot7.sJy6QweLZbzFs0xXs3t/GoyXoAn4r1q', 'admin');
