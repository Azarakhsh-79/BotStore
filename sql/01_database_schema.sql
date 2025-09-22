-- ============================
-- Telegram Bot Database Schema (Multi-Bot Architecture)
-- ============================

-- Central table for managing all bots
CREATE TABLE IF NOT EXISTS `managed_bots` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id_string` VARCHAR(50) NOT NULL UNIQUE COMMENT 'شناسه متنی ربات مثل amir یا mtr',
  `bot_token` VARCHAR(100) NOT NULL,
  `bot_name` VARCHAR(100) NOT NULL,
  `status` ENUM('active', 'inactive', 'expired') NOT NULL DEFAULT 'inactive',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Stores information about each managed bot';

ALTER TABLE `managed_bots`
ADD COLUMN `subscription_expires_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`;

-- Users Table
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `chat_id` BIGINT UNSIGNED NOT NULL,
  `username` VARCHAR(32) NULL,
  `first_name` VARCHAR(100) NULL,
  `last_name` VARCHAR(100) NULL,
  `language` CHAR(2) DEFAULT 'fa',
  `is_admin` BOOLEAN DEFAULT FALSE,
  `status` ENUM('active', 'blocked', 'pending') DEFAULT 'active',
  `entry_token` VARCHAR(64) NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_user_per_bot` (`bot_id`, `chat_id`),
  INDEX `idx_username` (`username`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Telegram Bot Users (Multi-Bot)';


-- Categories Table
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `parent_id` INT UNSIGNED NULL DEFAULT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`parent_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL,
  INDEX `idx_parent_id` (`parent_id`),
  INDEX `idx_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Product Categories (Multi-Bot)';


-- Products Table
CREATE TABLE IF NOT EXISTS `products` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `price` DECIMAL(15,2) NOT NULL,
  `discount_price` DECIMAL(15, 2) NULL DEFAULT NULL,
  `stock` INT UNSIGNED NOT NULL DEFAULT 0,
  `discount_expires_at` TIMESTAMP NULL DEFAULT NULL,
  `category_id` INT UNSIGNED NOT NULL,
  `is_active` BOOLEAN NOT NULL DEFAULT TRUE,
  `channel_message_id` BIGINT UNSIGNED NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE RESTRICT,
  INDEX `idx_category_id` (`category_id`),
  INDEX `idx_discount_price` (`discount_price`),
  FULLTEXT INDEX `idx_name_description` (`name`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Product Images Table
CREATE TABLE IF NOT EXISTS `product_images` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `file_id` VARCHAR(150) NOT NULL,
  `sort_order` INT UNSIGNED DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Product Variants Table
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `variant_name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(15,2) NOT NULL,
  `stock` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_active` BOOLEAN DEFAULT TRUE,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- User Addresses Table
CREATE TABLE IF NOT EXISTS `user_addresses` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NULL,
  `phone` VARCHAR(20) NULL,
  `address` TEXT NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Carts Table
CREATE TABLE IF NOT EXISTS `carts` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_user_product_per_bot` (`bot_id`, `user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Favorites Table
CREATE TABLE IF NOT EXISTS `favorites` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_favorite_per_bot` (`bot_id`, `user_id`, `product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Settings Table
CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `key` VARCHAR(50) NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) NULL,
  `type` VARCHAR(20) DEFAULT 'string',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_key_per_bot` (`bot_id`, `key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Invoices Table
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `total_amount` DECIMAL(15,2) NOT NULL,
  `status` ENUM('pending', 'paid', 'canceled', 'failed') NOT NULL DEFAULT 'pending',
  `receipt_file_id` VARCHAR(100) NULL,
  `user_info` JSON NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Invoice Items Table
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `invoice_id` INT UNSIGNED NOT NULL,
  `product_id` INT UNSIGNED NOT NULL,
  `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
  `price` DECIMAL(15,2) NOT NULL,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT,
  INDEX `idx_invoice_id` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Admin Access Tokens Table
CREATE TABLE IF NOT EXISTS `admin_tokens` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bot_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `is_used` BOOLEAN DEFAULT FALSE,
  `expires_at` TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`bot_id`) REFERENCES `managed_bots`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================
--  TRIGGERS: Prevent category/product conflict
-- ============================
-- Note: These triggers will now work within the context of a single bot's data,
-- which is the desired behavior. No changes are needed for them.

DELIMITER //

CREATE TRIGGER prevent_product_in_parent_category
BEFORE INSERT ON products
FOR EACH ROW
BEGIN
    DECLARE child_count INT;
    SELECT COUNT(*) INTO child_count FROM categories WHERE parent_id = NEW.category_id AND bot_id = NEW.bot_id;
    IF child_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot add product to a category that has subcategories.';
    END IF;
END;
//

CREATE TRIGGER prevent_product_update_in_parent_category
BEFORE UPDATE ON products
FOR EACH ROW
BEGIN
    DECLARE child_count INT;
    SELECT COUNT(*) INTO child_count FROM categories WHERE parent_id = NEW.category_id AND bot_id = NEW.bot_id;
    IF child_count > 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot assign product to a category that has subcategories.';
    END IF;
END;
//

CREATE TRIGGER prevent_subcategory_in_product_category
BEFORE INSERT ON categories
FOR EACH ROW
BEGIN
    DECLARE product_count INT;
    IF NEW.parent_id IS NOT NULL THEN
        SELECT COUNT(*) INTO product_count FROM products WHERE category_id = NEW.parent_id AND bot_id = NEW.bot_id;
        IF product_count > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot add subcategory to a category that already has products.';
        END IF;
    END IF;
END;
//

CREATE TRIGGER prevent_subcategory_update_in_product_category
BEFORE UPDATE ON categories
FOR EACH ROW
BEGIN
    DECLARE product_count INT;
    IF NEW.parent_id IS NOT NULL THEN
        SELECT COUNT(*) INTO product_count FROM products WHERE category_id = NEW.parent_id AND bot_id = NEW.bot_id;
        IF product_count > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Cannot assign subcategory to a category that already has products.';
        END IF;
    END IF;
END;
//

DELIMITER ;