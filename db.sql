SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `order_history_archive`;
DROP TABLE IF EXISTS `customer_loyalty_rewards`;
DROP TABLE IF EXISTS `system_activity_logs`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `foods`;
DROP TABLE IF EXISTS `halal_suppliers`;
DROP TABLE IF EXISTS `food_categories`;
DROP TABLE IF EXISTS `users`;

CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','staff','customer','supplier') NOT NULL DEFAULT 'customer',
  `status` enum('active','inactive','pending','approved') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `loyalty_points` int(11) NOT NULL DEFAULT 0,
  `loyalty_level` varchar(30) NOT NULL DEFAULT 'Bronze',
  `total_spent` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `status`) VALUES
(1, 'System Admin', 'admin@example.com', '$2y$10$8k9fJ0X.4uI8T9vX5s/G.O0gA2J.Y/2z7xP3p/X1A6p1u/N2m.Z3K', 'admin', 'active'),
(2, 'Operation Staff', 'staff@halalheritage.com', '$2y$10$E2S/H7S7c/X88e9yY8A73.3wI6l4I4D9/f/u3V3l.9h8d.sYy3vS2', 'staff', 'approved'),
(3, 'Fatima Al-Rasi', 'customer@gmail.com', '$2y$10$E2S/H7S7c/X88e9yY8A73.3wI6l4I4D9/f/u3V3l.9h8d.sYy3vS2', 'customer', 'active');

CREATE TABLE `food_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `food_categories` (`id`, `name`, `description`) VALUES
(1, 'Traditional Main Dishes', 'Authentic heritage meals served during cultural gatherings'),
(2, 'Halal Delicacies', 'Traditional rice cakes and sweet preserved treats'),
(3, 'Rice & Noodles', NULL),
(4, 'Desserts & Beverages', NULL);

CREATE TABLE `halal_suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `supplier_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `cert_number` varchar(100) DEFAULT NULL,
  `cert_issuer` varchar(100) DEFAULT NULL,
  `cert_expiry` date DEFAULT NULL,
  `status` enum('verified','pending','expired') DEFAULT 'verified',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `certification_no` varchar(100) DEFAULT NULL,
  `supplier_type` varchar(32) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `region` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `certification_body` varchar(150) DEFAULT NULL,
  `certification_valid_until` date DEFAULT NULL,
  `verification_status` varchar(20) NOT NULL DEFAULT 'pending',
  `verified_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `supplier_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`), UNIQUE KEY `cert_number` (`cert_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `foods` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `origin` varchar(100) DEFAULT NULL,
  `history` text DEFAULT NULL,
  `cultural_significance` text DEFAULT NULL,
  `ingredients` text DEFAULT NULL,
  `preparation_method` text DEFAULT NULL,
  `halal_info` text DEFAULT NULL,
  `image_url` varchar(255) DEFAULT 'default_food.jpg',
  `price` decimal(10,2) NOT NULL DEFAULT 0.00,
  `is_available` tinyint(1) DEFAULT 1,
  `is_heritage` tinyint(1) DEFAULT 1,
  `status` enum('approved','pending','rejected','archived') NOT NULL DEFAULT 'approved',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_food_category` (`category_id`), KEY `idx_food_supplier` (`supplier_id`), KEY `idx_food_status` (`status`,`is_available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `order_status` enum('Pending','Confirmed','Preparing','Ready','Out for Delivery','Completed','Cancelled') DEFAULT 'Pending',
  `delivery_address` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','preparing','completed','cancelled') NOT NULL DEFAULT 'pending',
  `payment_method` enum('COD','GCash') NOT NULL DEFAULT 'COD',
  `payment_status` enum('unpaid','paid') DEFAULT 'unpaid',
  PRIMARY KEY (`id`), KEY `idx_orders_user` (`user_id`), KEY `idx_orders_status_created` (`order_status`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `payment_method` enum('GCash','COD') NOT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_status` enum('Pending','Pending Verification','Confirmed','Rejected') NOT NULL DEFAULT 'Pending Verification',
  `amount` decimal(10,2) NOT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `food_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `price` decimal(10,2) NOT NULL,
  `custom_cut` varchar(32) NOT NULL DEFAULT 'none',
  `custom_toppings` varchar(500) NOT NULL DEFAULT '[]',
  PRIMARY KEY (`id`), KEY `idx_order_items_order` (`order_id`), KEY `idx_order_items_food` (`food_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `user_id` (`user_id`), KEY `idx_notif_user_active` (`user_id`,`is_deleted`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `system_activity_logs` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_id` int(11) DEFAULT NULL,
  `actor_name` varchar(100) NOT NULL,
  `actor_role` varchar(30) NOT NULL,
  `action` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), KEY `idx_system_activity_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `customer_loyalty_rewards` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `tier` varchar(30) NOT NULL,
  `food_name` varchar(150) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `order_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`), UNIQUE KEY `uq_customer_loyalty_reward` (`user_id`,`tier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `order_history_archive` (
  `order_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `archived_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`order_id`), KEY `idx_order_history_archive_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `foods` ADD CONSTRAINT `fk_food_category` FOREIGN KEY (`category_id`) REFERENCES `food_categories`(`id`) ON DELETE CASCADE;
ALTER TABLE `orders` ADD CONSTRAINT `fk_order_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;
ALTER TABLE `payments` ADD CONSTRAINT `fk_payment_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE;
ALTER TABLE `order_items` ADD CONSTRAINT `fk_item_order` FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `fk_notification_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;