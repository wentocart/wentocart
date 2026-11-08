-- WentoCart fresh-install schema (auto-generated).
-- Placeholder __DB_PREFIX__ is replaced by InstallerService with the merchant prefix.
-- Regenerate: php package/dump-install-schema.php --database=NAME --prefix=PREFIX_
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
SET UNIQUE_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `__DB_PREFIX__admin_impersonations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admin_impersonations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `ended_at` timestamp NULL DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__admin_impersonations_admin_id_index` (`admin_id`),
  KEY `__DB_PREFIX__admin_impersonations_customer_id_index` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__admin_notification_reads`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admin_notification_reads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_notification_id` bigint(20) unsigned NOT NULL,
  `admin_id` bigint(20) unsigned NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `admin_notif_read_unique` (`admin_notification_id`,`admin_id`),
  KEY `__DB_PREFIX__admin_notification_reads_admin_id_foreign` (`admin_id`),
  CONSTRAINT `__DB_PREFIX__admin_notification_reads_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__admin_notification_reads_admin_notification_id_foreign` FOREIGN KEY (`admin_notification_id`) REFERENCES `__DB_PREFIX__admin_notifications` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__admin_notifications`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admin_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(64) NOT NULL,
  `tone` varchar(16) NOT NULL DEFAULT 'info',
  `title` varchar(255) NOT NULL,
  `body` varchar(500) DEFAULT NULL,
  `href` varchar(255) DEFAULT NULL,
  `subject_type` varchar(120) DEFAULT NULL,
  `subject_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `admin_notif_subject_idx` (`subject_type`,`subject_id`,`type`),
  KEY `__DB_PREFIX__admin_notifications_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__admin_notifications_type_index` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__admin_password_reset_tokens`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admin_password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__admin_role`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admin_role` (
  `admin_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`admin_id`,`role_id`),
  KEY `__DB_PREFIX__admin_role_role_id_foreign` (`role_id`),
  CONSTRAINT `__DB_PREFIX__admin_role_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__admin_role_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `__DB_PREFIX__roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__admins`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_confirmed_at` timestamp NULL DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `team_id` bigint(20) unsigned DEFAULT NULL,
  `profile_id` bigint(20) unsigned DEFAULT NULL,
  `job_title` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `jump_shortcut` varchar(32) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__admins_email_unique` (`email`),
  KEY `__DB_PREFIX__admins_team_id_foreign` (`team_id`),
  KEY `__DB_PREFIX__admins_profile_id_foreign` (`profile_id`),
  CONSTRAINT `__DB_PREFIX__admins_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `__DB_PREFIX__profiles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__admins_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `__DB_PREFIX__teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__ai_assistant_chats`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__ai_assistant_chats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `messages_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`messages_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__ai_assistant_chats_admin_id_index` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__ai_assistant_logs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__ai_assistant_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `topic` varchar(64) NOT NULL DEFAULT 'general',
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `admin_name` varchar(255) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `context_type` varchar(64) NOT NULL DEFAULT 'general',
  `chat_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`chat_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__ai_assistant_logs_admin_id_index` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__ai_customer_chats`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__ai_customer_chats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `visitor_token` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `title` varchar(160) NOT NULL,
  `accepted_terms` tinyint(1) NOT NULL DEFAULT 0,
  `terms_accepted_at` timestamp NULL DEFAULT NULL,
  `messages_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`messages_json`)),
  `context_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context_json`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__ai_customer_chats_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__ai_customer_chats_visitor_token_index` (`visitor_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attachment_product`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attachment_product` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `attachment_id` bigint(20) unsigned NOT NULL,
  `role` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__attachment_product_product_id_attachment_id_unique` (`product_id`,`attachment_id`),
  KEY `__DB_PREFIX__attachment_product_attachment_id_index` (`attachment_id`),
  KEY `__DB_PREFIX__attachment_product_role_index` (`role`),
  CONSTRAINT `__DB_PREFIX__attachment_product_attachment_id_foreign` FOREIGN KEY (`attachment_id`) REFERENCES `__DB_PREFIX__attachments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__attachment_product_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attachment_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attachment_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attachment_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(16) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__attachment_translations_attachment_id_language_code_unique` (`attachment_id`,`language_code`),
  KEY `__DB_PREFIX__attachment_translations_language_code_index` (`language_code`),
  CONSTRAINT `__DB_PREFIX__attachment_translations_attachment_id_foreign` FOREIGN KEY (`attachment_id`) REFERENCES `__DB_PREFIX__attachments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attachments`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `stored_filename` varchar(255) NOT NULL,
  `storage_path` varchar(255) NOT NULL,
  `disk` varchar(32) NOT NULL DEFAULT 'local',
  `mime_type` varchar(128) DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT 0,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__attachments_name_index` (`name`),
  KEY `__DB_PREFIX__attachments_original_filename_index` (`original_filename`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attribute_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attribute_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attribute_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attr_trans_locale_unique` (`attribute_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__attribute_translations_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `__DB_PREFIX__attributes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attribute_value_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attribute_value_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attribute_value_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `attr_val_trans_locale_unique` (`attribute_value_id`,`language_code`),
  CONSTRAINT `attr_val_tr_val_fk` FOREIGN KEY (`attribute_value_id`) REFERENCES `__DB_PREFIX__attribute_values` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attribute_values`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attribute_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attribute_id` bigint(20) unsigned NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `swatch_type` varchar(16) DEFAULT NULL,
  `color_code` varchar(32) DEFAULT NULL,
  `swatch_image` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__attribute_values_attribute_id_code_unique` (`attribute_id`,`code`),
  CONSTRAINT `__DB_PREFIX__attribute_values_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `__DB_PREFIX__attributes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `__DB_PREFIX__product_templates`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `name` varchar(160) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `template_groups` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_templates_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__attributes`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__attributes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'select',
  `supports_swatches` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__attributes_code_unique` (`code`),
  KEY `__DB_PREFIX__attributes_seller_id_index` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__automation_runs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__automation_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `automation_id` bigint(20) unsigned NOT NULL,
  `status` varchar(32) NOT NULL,
  `operation_id` bigint(20) unsigned DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__automation_runs_automation_id_foreign` (`automation_id`),
  KEY `__DB_PREFIX__automation_runs_operation_id_index` (`operation_id`),
  KEY `__DB_PREFIX__automation_runs_created_at_index` (`created_at`),
  CONSTRAINT `__DB_PREFIX__automation_runs_automation_id_foreign` FOREIGN KEY (`automation_id`) REFERENCES `__DB_PREFIX__automations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__automations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__automations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `event` varchar(120) NOT NULL,
  `condition_path` varchar(120) DEFAULT NULL,
  `condition_equals` varchar(255) DEFAULT NULL,
  `ability` varchar(120) NOT NULL,
  `action_input` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`action_input`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__automations_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__automations_event_index` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__b2b_quote_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__b2b_quote_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `quote_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(190) NOT NULL,
  `sku` varchar(64) DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__b2b_quote_items_quote_id_foreign` (`quote_id`),
  CONSTRAINT `__DB_PREFIX__b2b_quote_items_quote_id_foreign` FOREIGN KEY (`quote_id`) REFERENCES `__DB_PREFIX__b2b_quotes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__b2b_quotes`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__b2b_quotes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `number` varchar(32) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'requested',
  `note` text DEFAULT NULL,
  `currency_code` varchar(8) DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `valid_until` timestamp NULL DEFAULT NULL,
  `converted_order_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__b2b_quotes_number_unique` (`number`),
  KEY `__DB_PREFIX__b2b_quotes_customer_id_foreign` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__b2b_quotes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__brand_images`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__brand_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `brand_id` bigint(20) unsigned NOT NULL,
  `path` varchar(255) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__brand_images_brand_id_foreign` (`brand_id`),
  CONSTRAINT `__DB_PREFIX__brand_images_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `__DB_PREFIX__brands` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__brand_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__brand_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `brand_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `short_description` text DEFAULT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__brand_translations_brand_id_language_code_unique` (`brand_id`,`language_code`),
  UNIQUE KEY `__DB_PREFIX__brand_translations_language_code_slug_unique` (`language_code`,`slug`),
  CONSTRAINT `__DB_PREFIX__brand_translations_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `__DB_PREFIX__brands` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__brands`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__brands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `country_code` varchar(8) DEFAULT NULL,
  `established_year` smallint(5) unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `postcode` varchar(32) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `facebook_url` varchar(255) DEFAULT NULL,
  `instagram_url` varchar(255) DEFAULT NULL,
  `youtube_url` varchar(255) DEFAULT NULL,
  `twitter_url` varchar(255) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `tiktok_url` varchar(255) DEFAULT NULL,
  `og_image_path` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__brands_code_unique` (`code`),
  KEY `__DB_PREFIX__brands_seller_id_index` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cache`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `__DB_PREFIX__cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cache_locks`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `__DB_PREFIX__cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cart_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cart_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cart_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `shipping_method_id` bigint(20) unsigned DEFAULT NULL,
  `parent_product_id` bigint(20) unsigned DEFAULT NULL,
  `combination_id` bigint(20) unsigned DEFAULT NULL,
  `subscription_plan_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `unit_price` decimal(12,2) NOT NULL,
  `customization` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`customization`)),
  `bundle_selection` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bundle_selection`)),
  `gift_card_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gift_card_data`)),
  `booking_date` date DEFAULT NULL,
  `booking_end_date` date DEFAULT NULL,
  `rental_duration` int(10) unsigned DEFAULT NULL,
  `rental_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rental_data`)),
  `preorder_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preorder_data`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__cart_items_cart_id_product_id_unique` (`cart_id`,`product_id`),
  KEY `__DB_PREFIX__cart_items_product_id_foreign` (`product_id`),
  KEY `__DB_PREFIX__cart_items_shipping_method_id_foreign` (`shipping_method_id`),
  KEY `__DB_PREFIX__cart_items_cart_id_product_id_index` (`cart_id`,`product_id`),
  KEY `cart_item_sub_plan_fk` (`subscription_plan_id`),
  KEY `__DB_PREFIX__cart_items_parent_product_id_index` (`parent_product_id`),
  CONSTRAINT `__DB_PREFIX__cart_items_cart_id_foreign` FOREIGN KEY (`cart_id`) REFERENCES `__DB_PREFIX__carts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__cart_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__cart_items_shipping_method_id_foreign` FOREIGN KEY (`shipping_method_id`) REFERENCES `__DB_PREFIX__shipping_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `cart_item_sub_plan_fk` FOREIGN KEY (`subscription_plan_id`) REFERENCES `__DB_PREFIX__subscription_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__carts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__carts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `coupon_id` bigint(20) unsigned DEFAULT NULL,
  `discount_rule_id` bigint(20) unsigned DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__carts_customer_id_foreign` (`customer_id`),
  KEY `__DB_PREFIX__carts_coupon_id_foreign` (`coupon_id`),
  KEY `__DB_PREFIX__carts_session_id_index` (`session_id`),
  KEY `__DB_PREFIX__carts_discount_rule_id_foreign` (`discount_rule_id`),
  CONSTRAINT `__DB_PREFIX__carts_coupon_id_foreign` FOREIGN KEY (`coupon_id`) REFERENCES `__DB_PREFIX__coupons` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__carts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__carts_discount_rule_id_foreign` FOREIGN KEY (`discount_rule_id`) REFERENCES `__DB_PREFIX__discount_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__checkout_groups`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__checkout_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reference` varchar(255) NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `payment_status` varchar(255) NOT NULL DEFAULT 'pending',
  `currency_code` varchar(8) DEFAULT NULL,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `capture_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_details`)),
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__checkout_groups_reference_unique` (`reference`),
  KEY `__DB_PREFIX__checkout_groups_customer_id_foreign` (`customer_id`),
  KEY `__DB_PREFIX__checkout_groups_payment_method_id_foreign` (`payment_method_id`),
  KEY `__DB_PREFIX__checkout_groups_store_id_index` (`store_id`),
  CONSTRAINT `__DB_PREFIX__checkout_groups_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__checkout_groups_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `__DB_PREFIX__payment_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__categories`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `thumbnail_path` varchar(255) DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `cover_path` varchar(255) DEFAULT NULL,
  `icon_path` varchar(255) DEFAULT NULL,
  `og_image_path` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `customer_group_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`customer_group_ids`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__categories_parent_id_foreign` (`parent_id`),
  KEY `__DB_PREFIX__categories_seller_id_index` (`seller_id`),
  CONSTRAINT `__DB_PREFIX__categories_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `__DB_PREFIX__categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__category_product`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__category_product` (
  `category_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`category_id`,`product_id`),
  KEY `__DB_PREFIX__category_product_product_id_foreign` (`product_id`),
  KEY `category_product_product_id_index` (`product_id`,`category_id`),
  CONSTRAINT `__DB_PREFIX__category_product_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `__DB_PREFIX__categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__category_product_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__category_store`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__category_store` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__category_store_category_id_store_id_unique` (`category_id`,`store_id`),
  KEY `__DB_PREFIX__category_store_store_id_foreign` (`store_id`),
  CONSTRAINT `__DB_PREFIX__category_store_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `__DB_PREFIX__categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__category_store_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shop_lookups`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shop_lookups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `family` varchar(32) NOT NULL,
  `lookup_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__shop_lookups_family_lookup_store_unique` (`family`,`lookup_id`,`store_id`),
  KEY `__DB_PREFIX__shop_lookups_store_id_index` (`store_id`),
  CONSTRAINT `__DB_PREFIX__shop_lookups_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__category_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__category_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text DEFAULT NULL,
  `canonical_url` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__category_translations_category_id_language_code_unique` (`category_id`,`language_code`),
  UNIQUE KEY `__DB_PREFIX__category_translations_language_code_slug_unique` (`language_code`,`slug`),
  FULLTEXT KEY `__DB_PREFIX__category_search` (`name`,`description`),
  CONSTRAINT `__DB_PREFIX__category_translations_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `__DB_PREFIX__categories` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cities`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_id` bigint(20) unsigned NOT NULL,
  `region_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__cities_region_id_foreign` (`region_id`),
  KEY `__DB_PREFIX__cities_country_id_is_enabled_index` (`country_id`,`is_enabled`),
  CONSTRAINT `__DB_PREFIX__cities_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `__DB_PREFIX__countries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__cities_region_id_foreign` FOREIGN KEY (`region_id`) REFERENCES `__DB_PREFIX__country_regions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cms_block_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cms_block_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cms_block_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `title` varchar(190) DEFAULT NULL,
  `content` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__cms_block_translations_cms_block_id_language_code_unique` (`cms_block_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__cms_block_translations_cms_block_id_foreign` FOREIGN KEY (`cms_block_id`) REFERENCES `__DB_PREFIX__cms_blocks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cms_blocks`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cms_blocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL DEFAULT 'home',
  `content` longtext DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__cms_blocks_code_store_id_unique` (`code`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cms_categories`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cms_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(64) NOT NULL,
  `description` varchar(500) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__cms_categories_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cms_page_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cms_page_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cms_page_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `title` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `short_description` varchar(255) DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__cms_page_translations_cms_page_id_language_code_unique` (`cms_page_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__cms_page_translations_cms_page_id_foreign` FOREIGN KEY (`cms_page_id`) REFERENCES `__DB_PREFIX__cms_pages` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__cms_pages`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__cms_pages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(16) NOT NULL DEFAULT 'published',
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `template` varchar(64) NOT NULL DEFAULT 'blank',
  `cms_category_id` bigint(20) unsigned DEFAULT NULL,
  `menu_id` bigint(20) unsigned DEFAULT NULL,
  `canonical_url` varchar(255) DEFAULT NULL,
  `robots` varchar(64) NOT NULL DEFAULT 'index,follow',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__cms_pages_cms_category_id_foreign` (`cms_category_id`),
  KEY `__DB_PREFIX__cms_pages_menu_id_foreign` (`menu_id`),
  CONSTRAINT `__DB_PREFIX__cms_pages_cms_category_id_foreign` FOREIGN KEY (`cms_category_id`) REFERENCES `__DB_PREFIX__cms_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__cms_pages_menu_id_foreign` FOREIGN KEY (`menu_id`) REFERENCES `__DB_PREFIX__menus` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__commerce_events`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__commerce_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `operation_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__commerce_events_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__commerce_events_name_index` (`name`),
  KEY `__DB_PREFIX__commerce_events_operation_id_index` (`operation_id`),
  KEY `__DB_PREFIX__commerce_events_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__companies`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__companies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `credit_limit` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__company_users`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__company_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `company_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `role` varchar(20) NOT NULL DEFAULT 'buyer',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__company_users_company_id_customer_id_unique` (`company_id`,`customer_id`),
  KEY `__DB_PREFIX__company_users_customer_id_foreign` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__company_users_company_id_foreign` FOREIGN KEY (`company_id`) REFERENCES `__DB_PREFIX__companies` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__company_users_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__compares`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__compares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `session_id` varchar(255) DEFAULT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__compares_customer_id_foreign` (`customer_id`),
  KEY `__DB_PREFIX__compares_product_id_foreign` (`product_id`),
  KEY `__DB_PREFIX__compares_session_id_index` (`session_id`),
  CONSTRAINT `__DB_PREFIX__compares_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__compares_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__countries`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__countries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(4) NOT NULL,
  `iso3` varchar(3) DEFAULT NULL,
  `calling_code` varchar(8) DEFAULT NULL,
  `default_currency_code` varchar(8) DEFAULT NULL,
  `timezone` varchar(64) DEFAULT NULL,
  `address_format` varchar(32) DEFAULT NULL,
  `postal_code_label` varchar(64) DEFAULT NULL,
  `tax_id_label` varchar(64) DEFAULT NULL,
  `has_regions` tinyint(1) NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `zone_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__countries_code_unique` (`code`),
  KEY `__DB_PREFIX__countries_zone_id_index` (`zone_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__country_regions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__country_regions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_id` bigint(20) unsigned NOT NULL,
  `code` varchar(16) NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__country_regions_country_id_code_unique` (`country_id`,`code`),
  CONSTRAINT `__DB_PREFIX__country_regions_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `__DB_PREFIX__countries` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__coupons`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__coupons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `min_cart` decimal(12,2) DEFAULT NULL,
  `max_uses` int(10) unsigned DEFAULT NULL,
  `used_count` int(10) unsigned NOT NULL DEFAULT 0,
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__coupons_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__currencies`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__currencies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `symbol` varchar(16) NOT NULL DEFAULT '$',
  `decimal_places` tinyint(3) unsigned NOT NULL DEFAULT 2,
  `symbol_position` varchar(16) NOT NULL DEFAULT 'before',
  `exchange_rate` decimal(12,6) NOT NULL DEFAULT 1.000000,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__currencies_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_addresses`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_addresses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `company` varchar(255) DEFAULT NULL,
  `tax_id` varchar(64) DEFAULT NULL,
  `line1` varchar(255) NOT NULL,
  `line2` varchar(255) DEFAULT NULL,
  `city` varchar(255) NOT NULL,
  `state` varchar(255) DEFAULT NULL,
  `postal_code` varchar(255) DEFAULT NULL,
  `country_code` varchar(4) NOT NULL,
  `is_default_billing` tinyint(1) NOT NULL DEFAULT 0,
  `is_default_shipping` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__customer_addresses_customer_id_foreign` (`customer_id`),
  KEY `__DB_PREFIX__customer_addresses_country_code_customer_id_index` (`country_code`,`customer_id`),
  CONSTRAINT `__DB_PREFIX__customer_addresses_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_credits`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_credits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance` decimal(12,2) NOT NULL,
  `reason` varchar(190) DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__customer_credits_customer_id_created_at_index` (`customer_id`,`created_at`),
  CONSTRAINT `__DB_PREFIX__customer_credits_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_groups`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(64) NOT NULL,
  `color` varchar(32) NOT NULL DEFAULT 'primary',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `tax_exempt` tinyint(1) NOT NULL DEFAULT 0,
  `description` varchar(500) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `hide_prices` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__customer_groups_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_password_reset_tokens`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_product_prices`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_product_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `starts_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__customer_product_prices_customer_id_product_id_unique` (`customer_id`,`product_id`),
  KEY `__DB_PREFIX__customer_product_prices_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__customer_product_prices_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__customer_product_prices_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_subscriptions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `last_renewal_order_id` bigint(20) unsigned DEFAULT NULL,
  `order_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `subscription_plan_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'active',
  `started_at` timestamp NULL DEFAULT NULL,
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `next_renewal_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `cancel_at_period_end` tinyint(1) NOT NULL DEFAULT 0,
  `cycles_completed` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `dunning_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `renewal_attempts` int(10) unsigned NOT NULL DEFAULT 0,
  `last_renewal_error` varchar(500) DEFAULT NULL,
  `last_pending_action_url` varchar(500) DEFAULT NULL,
  `last_dunning_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__customer_subscriptions_order_id_foreign` (`order_id`),
  KEY `__DB_PREFIX__customer_subscriptions_order_item_id_foreign` (`order_item_id`),
  KEY `__DB_PREFIX__customer_subscriptions_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__customer_subscriptions_product_id_index` (`product_id`),
  KEY `__DB_PREFIX__customer_subscriptions_subscription_plan_id_index` (`subscription_plan_id`),
  KEY `__DB_PREFIX__customer_subscriptions_status_index` (`status`),
  KEY `__DB_PREFIX__customer_subscriptions_next_renewal_at_index` (`next_renewal_at`),
  KEY `customer_subscriptions_due_index` (`status`,`next_renewal_at`),
  CONSTRAINT `__DB_PREFIX__customer_subscriptions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__customer_subscriptions_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `__DB_PREFIX__order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `customer_group` varchar(255) NOT NULL DEFAULT 'retailer',
  `date_of_birth` date DEFAULT NULL,
  `tax_vat` varchar(255) DEFAULT NULL,
  `gender` varchar(16) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `business_type` varchar(255) DEFAULT NULL,
  `company_website` varchar(255) DEFAULT NULL,
  `gst_tax_id` varchar(255) DEFAULT NULL,
  `newsletter` tinyint(1) NOT NULL DEFAULT 0,
  `notes` varchar(500) DEFAULT NULL,
  `profile` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profile`)),
  `avatar_path` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `totp_secret` varchar(64) DEFAULT NULL,
  `totp_confirmed_at` timestamp NULL DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `customer_group_id` bigint(20) unsigned DEFAULT NULL,
  `company_id` bigint(20) unsigned DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `sandbox_payment_token` varchar(80) DEFAULT NULL,
  `credit_limit` decimal(12,2) DEFAULT NULL,
  `google_id` varchar(191) DEFAULT NULL,
  `facebook_id` varchar(191) DEFAULT NULL,
  `linkedin_id` varchar(191) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__customers_email_store_id_unique` (`email`,`store_id`),
  UNIQUE KEY `customers_store_google_unique` (`store_id`,`google_id`),
  UNIQUE KEY `customers_store_facebook_unique` (`store_id`,`facebook_id`),
  UNIQUE KEY `customers_store_linkedin_unique` (`store_id`,`linkedin_id`),
  KEY `__DB_PREFIX__customers_created_at_index` (`created_at`),
  KEY `__DB_PREFIX__customers_is_enabled_id_index` (`is_enabled`,`id`),
  KEY `__DB_PREFIX__customers_customer_group_id_index` (`customer_group`,`id`),
  KEY `__DB_PREFIX__customers_first_name_index` (`first_name`),
  KEY `__DB_PREFIX__customers_last_name_index` (`last_name`),
  KEY `__DB_PREFIX__customers_phone_index` (`phone`),
  KEY `__DB_PREFIX__customers_customer_group_id_foreign` (`customer_group_id`),
  KEY `customers_created_id_index` (`created_at`,`id`),
  FULLTEXT KEY `customers_search_fulltext` (`name`,`first_name`,`last_name`,`email`,`phone`),
  CONSTRAINT `__DB_PREFIX__customers_customer_group_id_foreign` FOREIGN KEY (`customer_group_id`) REFERENCES `__DB_PREFIX__customer_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__dashboard_admin`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__dashboard_admin` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dashboard_id` bigint(20) unsigned NOT NULL,
  `admin_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__dashboard_admin_dashboard_id_admin_id_unique` (`dashboard_id`,`admin_id`),
  KEY `__DB_PREFIX__dashboard_admin_admin_id_foreign` (`admin_id`),
  CONSTRAINT `__DB_PREFIX__dashboard_admin_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__dashboard_admin_dashboard_id_foreign` FOREIGN KEY (`dashboard_id`) REFERENCES `__DB_PREFIX__dashboards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__dashboard_profile`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__dashboard_profile` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dashboard_id` bigint(20) unsigned NOT NULL,
  `profile_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__dashboard_profile_dashboard_id_profile_id_unique` (`dashboard_id`,`profile_id`),
  KEY `__DB_PREFIX__dashboard_profile_profile_id_foreign` (`profile_id`),
  CONSTRAINT `__DB_PREFIX__dashboard_profile_dashboard_id_foreign` FOREIGN KEY (`dashboard_id`) REFERENCES `__DB_PREFIX__dashboards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__dashboard_profile_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `__DB_PREFIX__profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__dashboard_widgets`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__dashboard_widgets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `dashboard_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `chart_type` varchar(32) NOT NULL,
  `metric` varchar(64) NOT NULL,
  `range_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `col_span` tinyint(3) unsigned NOT NULL DEFAULT 6,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`options`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__dashboard_widgets_dashboard_id_foreign` (`dashboard_id`),
  CONSTRAINT `__DB_PREFIX__dashboard_widgets_dashboard_id_foreign` FOREIGN KEY (`dashboard_id`) REFERENCES `__DB_PREFIX__dashboards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__dashboards`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__dashboards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `visibility` varchar(24) NOT NULL DEFAULT 'assigned',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__dashboards_slug_unique` (`slug`),
  KEY `__DB_PREFIX__dashboards_created_by_admin_id_foreign` (`created_by_admin_id`),
  CONSTRAINT `__DB_PREFIX__dashboards_created_by_admin_id_foreign` FOREIGN KEY (`created_by_admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__discount_rule_conditions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__discount_rule_conditions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `discount_rule_id` bigint(20) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `value_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`value_ids`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__discount_rule_conditions_discount_rule_id_foreign` (`discount_rule_id`),
  CONSTRAINT `__DB_PREFIX__discount_rule_conditions_discount_rule_id_foreign` FOREIGN KEY (`discount_rule_id`) REFERENCES `__DB_PREFIX__discount_rules` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__discount_rules`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__discount_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `apply_as` varchar(16) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `action` varchar(16) NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `currency_code` varchar(3) DEFAULT NULL,
  `priority` int(10) unsigned NOT NULL DEFAULT 100,
  `stop_further` tinyint(1) NOT NULL DEFAULT 0,
  `min_cart` decimal(12,2) DEFAULT NULL,
  `min_qty` int(10) unsigned DEFAULT NULL,
  `max_discount` decimal(12,2) DEFAULT NULL,
  `max_uses` int(10) unsigned DEFAULT NULL,
  `max_uses_per_customer` int(10) unsigned DEFAULT NULL,
  `used_count` int(10) unsigned NOT NULL DEFAULT 0,
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__discount_rules_code_store_id_unique` (`code`,`store_id`),
  KEY `__DB_PREFIX__discount_rules_apply_as_is_enabled_priority_index` (`apply_as`,`is_enabled`,`priority`),
  KEY `__DB_PREFIX__discount_rules_parent_id_index` (`parent_id`),
  KEY `__DB_PREFIX__discount_rules_seller_id_index` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__discount_redemptions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__discount_redemptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `discount_rule_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__discount_redemptions_rule_order_unique` (`discount_rule_id`,`order_id`),
  KEY `__DB_PREFIX__discount_redemptions_rule_customer_index` (`discount_rule_id`,`customer_id`),
  KEY `__DB_PREFIX__discount_redemptions_email_index` (`email`),
  CONSTRAINT `__DB_PREFIX__discount_redemptions_rule_id_foreign` FOREIGN KEY (`discount_rule_id`) REFERENCES `__DB_PREFIX__discount_rules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__discount_redemptions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__discount_redemptions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__document_sequences`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__document_sequences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(40) NOT NULL,
  `next_number` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__document_sequences_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__document_templates`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__document_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(32) NOT NULL,
  `email_subject` varchar(255) DEFAULT NULL,
  `body` longtext NOT NULL,
  `design_json` longtext DEFAULT NULL,
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_custom` tinyint(1) NOT NULL DEFAULT 1,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_in_use` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__document_templates_store_id_code_unique` (`store_id`,`code`),
  KEY `__DB_PREFIX__document_templates_type_is_enabled_index` (`type`,`is_enabled`),
  CONSTRAINT `__DB_PREFIX__document_templates_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__document_template_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__document_template_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `document_template_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `email_subject` varchar(255) DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `design_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__doc_tpl_tr_lang_unique` (`document_template_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__doc_tpl_tr_fk` FOREIGN KEY (`document_template_id`) REFERENCES `__DB_PREFIX__document_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__email_event_settings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__email_event_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `event_code` varchar(255) NOT NULL,
  `recipient` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__email_event_settings_event_code_recipient_store_id_unique` (`event_code`,`recipient`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__email_logs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__email_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `to_email` varchar(255) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `template_code` varchar(255) DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'sent',
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__email_logs_created_at_index` (`created_at`),
  KEY `__DB_PREFIX__email_logs_store_id_index` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__email_templates`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__email_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `event_code` varchar(255) DEFAULT NULL,
  `recipient` varchar(255) NOT NULL DEFAULT 'customer',
  `is_custom` tinyint(1) NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body` longtext NOT NULL,
  `design_json` longtext DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__email_templates_code_store_id_unique` (`code`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__email_template_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__email_template_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email_template_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `body` longtext DEFAULT NULL,
  `design_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__email_tpl_tr_lang_unique` (`email_template_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__email_tpl_tr_fk` FOREIGN KEY (`email_template_id`) REFERENCES `__DB_PREFIX__email_templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__failed_jobs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__failed_jobs_uuid_unique` (`uuid`),
  KEY `__DB_PREFIX__failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__feature_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__feature_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feature_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__feature_translations_feature_id_language_code_unique` (`feature_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__feature_translations_feature_id_foreign` FOREIGN KEY (`feature_id`) REFERENCES `__DB_PREFIX__features` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__feature_value_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__feature_value_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feature_value_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `value` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `feature_value_lang_unique` (`feature_value_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__feature_value_translations_feature_value_id_foreign` FOREIGN KEY (`feature_value_id`) REFERENCES `__DB_PREFIX__feature_values` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__feature_values`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__feature_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `feature_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `code` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__feature_values_feature_id_code_unique` (`feature_id`,`code`),
  CONSTRAINT `__DB_PREFIX__feature_values_feature_id_foreign` FOREIGN KEY (`feature_id`) REFERENCES `__DB_PREFIX__features` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__features`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__features` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `is_filterable` tinyint(1) NOT NULL DEFAULT 1,
  `used_for_combinations` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'select',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__features_code_unique` (`code`),
  KEY `__DB_PREFIX__features_seller_id_index` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__file_change_logs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__file_change_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(40) NOT NULL,
  `path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__file_change_logs_created_at_index` (`created_at`),
  KEY `__DB_PREFIX__file_change_logs_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__file_change_logs_admin_id_index` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__gift_card_transactions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__gift_card_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `gift_card_id` bigint(20) unsigned NOT NULL,
  `type` varchar(16) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance_before` decimal(12,2) NOT NULL,
  `balance_after` decimal(12,2) NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__gift_card_transactions_order_id_foreign` (`order_id`),
  KEY `__DB_PREFIX__gift_card_transactions_gift_card_id_created_at_index` (`gift_card_id`,`created_at`),
  CONSTRAINT `__DB_PREFIX__gift_card_transactions_gift_card_id_foreign` FOREIGN KEY (`gift_card_id`) REFERENCES `__DB_PREFIX__gift_cards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__gift_card_transactions_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__gift_cards`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__gift_cards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(64) NOT NULL,
  `initial_balance` decimal(12,2) NOT NULL,
  `current_balance` decimal(12,2) NOT NULL,
  `selected_amount` decimal(12,2) NOT NULL,
  `recipient_name` varchar(255) DEFAULT NULL,
  `recipient_email` varchar(255) DEFAULT NULL,
  `sender_name` varchar(255) DEFAULT NULL,
  `gift_message` text DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__gift_cards_code_unique` (`code`),
  KEY `__DB_PREFIX__gift_cards_order_item_id_foreign` (`order_item_id`),
  KEY `__DB_PREFIX__gift_cards_product_id_status_index` (`product_id`,`status`),
  KEY `__DB_PREFIX__gift_cards_order_id_index` (`order_id`),
  KEY `__DB_PREFIX__gift_cards_customer_id_index` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__gift_cards_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__gift_cards_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__gift_cards_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `__DB_PREFIX__order_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__gift_cards_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__health_ai_proposals`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__health_ai_proposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `fix_job_id` bigint(20) unsigned DEFAULT NULL,
  `resource_type` varchar(40) NOT NULL,
  `resource_id` bigint(20) unsigned DEFAULT NULL,
  `task_type` varchar(60) NOT NULL,
  `status` varchar(32) NOT NULL,
  `input_context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`input_context`)),
  `current_content` longtext DEFAULT NULL,
  `generated_output` longtext DEFAULT NULL,
  `edited_output` longtext DEFAULT NULL,
  `model` varchar(120) DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__health_ai_proposals_uuid_unique` (`uuid`),
  KEY `__DB_PREFIX__health_ai_proposals_fix_job_id_foreign` (`fix_job_id`),
  KEY `__DB_PREFIX__health_ai_proposals_status_task_type_index` (`status`,`task_type`),
  KEY `__DB_PREFIX__health_ai_proposals_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__health_ai_proposals_resource_type_index` (`resource_type`),
  KEY `__DB_PREFIX__health_ai_proposals_resource_id_index` (`resource_id`),
  KEY `__DB_PREFIX__health_ai_proposals_task_type_index` (`task_type`),
  KEY `__DB_PREFIX__health_ai_proposals_status_index` (`status`),
  CONSTRAINT `__DB_PREFIX__health_ai_proposals_fix_job_id_foreign` FOREIGN KEY (`fix_job_id`) REFERENCES `__DB_PREFIX__health_fix_jobs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__health_findings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__health_findings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `scan_id` bigint(20) unsigned DEFAULT NULL,
  `rule_code` varchar(80) NOT NULL,
  `category` varchar(40) NOT NULL,
  `severity` varchar(20) NOT NULL,
  `status` varchar(32) NOT NULL,
  `resource_type` varchar(40) DEFAULT NULL,
  `resource_id` bigint(20) unsigned DEFAULT NULL,
  `resource_label` varchar(255) DEFAULT NULL,
  `resource_url` varchar(500) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `detail` text DEFAULT NULL,
  `impact` text DEFAULT NULL,
  `recommendation` text DEFAULT NULL,
  `evidence` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`evidence`)),
  `current_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`current_value`)),
  `expected_value` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`expected_value`)),
  `affected_count` int(10) unsigned NOT NULL DEFAULT 0,
  `fix_capabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fix_capabilities`)),
  `first_detected_at` timestamp NULL DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `ignored_at` timestamp NULL DEFAULT NULL,
  `snoozed_until` timestamp NULL DEFAULT NULL,
  `ignore_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__health_findings_scan_id_foreign` (`scan_id`),
  KEY `__DB_PREFIX__health_findings_rule_code_status_index` (`rule_code`,`status`),
  KEY `__DB_PREFIX__health_findings_category_severity_status_index` (`category`,`severity`,`status`),
  KEY `__DB_PREFIX__health_findings_resource_type_resource_id_index` (`resource_type`,`resource_id`),
  KEY `__DB_PREFIX__health_findings_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__health_findings_rule_code_index` (`rule_code`),
  KEY `__DB_PREFIX__health_findings_category_index` (`category`),
  KEY `__DB_PREFIX__health_findings_severity_index` (`severity`),
  KEY `__DB_PREFIX__health_findings_status_index` (`status`),
  KEY `__DB_PREFIX__health_findings_resource_type_index` (`resource_type`),
  KEY `__DB_PREFIX__health_findings_resource_id_index` (`resource_id`),
  CONSTRAINT `__DB_PREFIX__health_findings_scan_id_foreign` FOREIGN KEY (`scan_id`) REFERENCES `__DB_PREFIX__health_scans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__health_fix_jobs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__health_fix_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `finding_id` bigint(20) unsigned DEFAULT NULL,
  `rule_code` varchar(80) NOT NULL,
  `fix_type` varchar(40) NOT NULL,
  `status` varchar(32) NOT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `preview_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preview_data`)),
  `original_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`original_data`)),
  `proposed_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`proposed_data`)),
  `applied_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`applied_data`)),
  `rollback_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rollback_data`)),
  `rollback_supported` tinyint(1) NOT NULL DEFAULT 0,
  `rolled_back_at` timestamp NULL DEFAULT NULL,
  `error` text DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__health_fix_jobs_uuid_unique` (`uuid`),
  KEY `__DB_PREFIX__health_fix_jobs_finding_id_foreign` (`finding_id`),
  KEY `__DB_PREFIX__health_fix_jobs_status_created_at_index` (`status`,`created_at`),
  KEY `__DB_PREFIX__health_fix_jobs_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__health_fix_jobs_rule_code_index` (`rule_code`),
  KEY `__DB_PREFIX__health_fix_jobs_fix_type_index` (`fix_type`),
  KEY `__DB_PREFIX__health_fix_jobs_status_index` (`status`),
  CONSTRAINT `__DB_PREFIX__health_fix_jobs_finding_id_foreign` FOREIGN KEY (`finding_id`) REFERENCES `__DB_PREFIX__health_findings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__health_scans`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__health_scans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(40) NOT NULL,
  `status` varchar(32) NOT NULL,
  `initiated_by` bigint(20) unsigned DEFAULT NULL,
  `pages_total` int(10) unsigned NOT NULL DEFAULT 0,
  `pages_checked` int(10) unsigned NOT NULL DEFAULT 0,
  `products_checked` int(10) unsigned NOT NULL DEFAULT 0,
  `images_checked` int(10) unsigned NOT NULL DEFAULT 0,
  `assets_checked` int(10) unsigned NOT NULL DEFAULT 0,
  `links_checked` int(10) unsigned NOT NULL DEFAULT 0,
  `issues_found` int(10) unsigned NOT NULL DEFAULT 0,
  `critical_count` int(10) unsigned NOT NULL DEFAULT 0,
  `warning_count` int(10) unsigned NOT NULL DEFAULT 0,
  `opportunity_count` int(10) unsigned NOT NULL DEFAULT 0,
  `passed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `overall_score` tinyint(3) unsigned DEFAULT NULL,
  `category_scores` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`category_scores`)),
  `progress` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`progress`)),
  `comparison` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`comparison`)),
  `error` text DEFAULT NULL,
  `current_step` varchar(180) DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__health_scans_uuid_unique` (`uuid`),
  KEY `__DB_PREFIX__health_scans_status_created_at_index` (`status`,`created_at`),
  KEY `__DB_PREFIX__health_scans_type_created_at_index` (`type`,`created_at`),
  KEY `__DB_PREFIX__health_scans_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__health_scans_type_index` (`type`),
  KEY `__DB_PREFIX__health_scans_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__health_settings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__health_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `key` varchar(120) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__health_settings_store_id_key_unique` (`store_id`,`key`),
  KEY `__DB_PREFIX__health_settings_store_id_index` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__inbound_webhook_events`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__inbound_webhook_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `driver` varchar(64) NOT NULL,
  `event_id` varchar(190) DEFAULT NULL,
  `payload_hash` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'processing',
  `http_status` smallint(5) unsigned NOT NULL DEFAULT 200,
  `response_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_payload`)),
  `error_message` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__inbound_webhook_events_driver_event_id_index` (`driver`,`event_id`),
  KEY `__DB_PREFIX__inbound_webhook_events_driver_payload_hash_index` (`driver`,`payload_hash`),
  KEY `__DB_PREFIX__inbound_webhook_events_driver_index` (`driver`),
  KEY `__DB_PREFIX__inbound_webhook_events_event_id_index` (`event_id`),
  KEY `__DB_PREFIX__inbound_webhook_events_payload_hash_index` (`payload_hash`),
  KEY `__DB_PREFIX__inbound_webhook_events_status_index` (`status`),
  UNIQUE KEY `__DB_PREFIX__inbound_webhook_events_driver_payload_hash_unique` (`driver`,`payload_hash`),
  UNIQUE KEY `__DB_PREFIX__inbound_webhook_events_driver_event_id_unique` (`driver`,`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__inventory_movements`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__inventory_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `reference` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__inventory_movements_warehouse_id_foreign` (`warehouse_id`),
  KEY `__DB_PREFIX__inventory_movements_product_id_created_at_index` (`product_id`,`created_at`),
  KEY `__DB_PREFIX__inventory_movements_seller_id_index` (`seller_id`),
  KEY `inventory_movements_seller_created_idx` (`seller_id`,`created_at`),
  KEY `inventory_movements_ref_product_action_idx` (`reference`,`product_id`,`action`),
  CONSTRAINT `__DB_PREFIX__inventory_movements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__inventory_movements_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__inventory_movements_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `__DB_PREFIX__warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__job_batches`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__jobs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__languages`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__languages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__languages_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__localization_import_batches`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__localization_import_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'pending',
  `filename` varchar(255) DEFAULT NULL,
  `preview` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preview`)),
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result`)),
  `row_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__localization_packs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__localization_packs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `country_code` varchar(4) NOT NULL,
  `name` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__localization_packs_country_code_unique` (`country_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__loyalty_movements`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__loyalty_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `points` int(11) NOT NULL,
  `type` varchar(20) NOT NULL,
  `reference` varchar(190) DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__loyalty_movements_customer_id_created_at_index` (`customer_id`,`created_at`),
  CONSTRAINT `__DB_PREFIX__loyalty_movements_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__marketplace_audit_logs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__marketplace_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  `actor_type` varchar(20) NOT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `before_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_state`)),
  `after_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_state`)),
  `reason` text DEFAULT NULL,
  `reference` varchar(120) DEFAULT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `marketplace_audit_idempotent` (`action`,`reference`),
  KEY `__DB_PREFIX__marketplace_audit_logs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `__DB_PREFIX__marketplace_audit_logs_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__marketplace_audit_logs_action_index` (`action`),
  KEY `__DB_PREFIX__marketplace_audit_logs_entity_type_index` (`entity_type`),
  KEY `__DB_PREFIX__marketplace_audit_logs_entity_id_index` (`entity_id`),
  KEY `__DB_PREFIX__marketplace_audit_logs_actor_type_index` (`actor_type`),
  KEY `__DB_PREFIX__marketplace_audit_logs_actor_id_index` (`actor_id`),
  KEY `__DB_PREFIX__marketplace_audit_logs_seller_id_index` (`seller_id`),
  KEY `__DB_PREFIX__marketplace_audit_logs_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__marketplace_audit_logs_reference_index` (`reference`),
  KEY `__DB_PREFIX__marketplace_audit_logs_created_at_index` (`created_at`),
  KEY `marketplace_audit_logs_seller_id_id_index` (`seller_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__marketplace_commission_rules`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__marketplace_commission_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `scope` varchar(20) NOT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'percent',
  `value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__marketplace_commission_rules_scope_is_enabled_index` (`scope`,`is_enabled`),
  KEY `__DB_PREFIX__marketplace_commission_rules_product_id_index` (`product_id`),
  KEY `__DB_PREFIX__marketplace_commission_rules_seller_id_index` (`seller_id`),
  KEY `__DB_PREFIX__marketplace_commission_rules_category_id_index` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__menu_item_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__menu_item_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `menu_item_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `label` varchar(120) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__menu_item_translations_menu_item_id_language_code_unique` (`menu_item_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__menu_item_translations_menu_item_id_foreign` FOREIGN KEY (`menu_item_id`) REFERENCES `__DB_PREFIX__menu_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__menu_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__menu_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `menu_id` bigint(20) unsigned NOT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `url` varchar(255) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__menu_items_menu_id_foreign` (`menu_id`),
  KEY `__DB_PREFIX__menu_items_parent_id_foreign` (`parent_id`),
  CONSTRAINT `__DB_PREFIX__menu_items_menu_id_foreign` FOREIGN KEY (`menu_id`) REFERENCES `__DB_PREFIX__menus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__menu_items_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `__DB_PREFIX__menu_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__menus`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__menus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__menus_code_store_id_unique` (`code`,`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__migrations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__data_transfer_imports`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__data_transfer_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(60) NOT NULL,
  `action` varchar(20) NOT NULL DEFAULT 'append',
  `state` varchar(40) NOT NULL DEFAULT 'pending',
  `file_name` varchar(255) DEFAULT NULL,
  `csv_content` longtext DEFAULT NULL,
  `processed_rows_count` int(10) unsigned NOT NULL DEFAULT 0,
  `invalid_rows_count` int(10) unsigned NOT NULL DEFAULT 0,
  `errors_count` int(10) unsigned NOT NULL DEFAULT 0,
  `errors_json` longtext DEFAULT NULL,
  `summary_json` longtext DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__data_transfer_imports_code_index` (`code`),
  KEY `__DB_PREFIX__data_transfer_imports_state_index` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `__DB_PREFIX__marketing_sitemaps`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__marketing_sitemaps` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `file_name` varchar(120) NOT NULL,
  `path` varchar(255) NOT NULL DEFAULT '/',
  `store_ids` json DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `generated_files` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__marketing_events`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__marketing_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(190) NOT NULL,
  `description` text DEFAULT NULL,
  `date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__marketing_events_date_index` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__newsletter_campaigns`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__newsletter_campaigns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subject` varchar(190) NOT NULL,
  `html_body` longtext NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'draft',
  `sent_count` int(10) unsigned NOT NULL DEFAULT 0,
  `sent_at` timestamp NULL DEFAULT NULL,
  `marketing_event_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__newsletter_campaigns_marketing_event_id_index` (`marketing_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__newsletter_subscribers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__newsletter_subscribers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `is_subscribed` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__newsletter_subscribers_email_unique` (`email`),
  KEY `__DB_PREFIX__newsletter_subscribers_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__operation_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__operation_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `entity_type` varchar(60) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(20) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `before_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`before_state`)),
  `after_state` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`after_state`)),
  `reversible` tinyint(1) NOT NULL DEFAULT 1,
  `rolled_back` tinyint(1) NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__operation_items_operation_id_foreign` (`operation_id`),
  KEY `__DB_PREFIX__operation_items_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `__DB_PREFIX__operation_items_entity_type_index` (`entity_type`),
  KEY `__DB_PREFIX__operation_items_entity_id_index` (`entity_id`),
  CONSTRAINT `__DB_PREFIX__operation_items_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `__DB_PREFIX__operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__operations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `ability` varchar(120) NOT NULL,
  `source` varchar(32) NOT NULL,
  `actor_type` varchar(32) DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(32) NOT NULL,
  `dry_run` tinyint(1) NOT NULL DEFAULT 0,
  `reversible` tinyint(1) NOT NULL DEFAULT 1,
  `summary` varchar(255) DEFAULT NULL,
  `input` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`input`)),
  `result` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`result`)),
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `rolled_back_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__operations_uuid_unique` (`uuid`),
  KEY `__DB_PREFIX__operations_ability_created_at_index` (`ability`,`created_at`),
  KEY `__DB_PREFIX__operations_source_created_at_index` (`source`,`created_at`),
  KEY `__DB_PREFIX__operations_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__operations_ability_index` (`ability`),
  KEY `__DB_PREFIX__operations_source_index` (`source`),
  KEY `__DB_PREFIX__operations_status_index` (`status`),
  KEY `__DB_PREFIX__operations_parent_id_index` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_comments`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_comments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `author_type` varchar(20) NOT NULL,
  `author_id` bigint(20) unsigned DEFAULT NULL,
  `visibility` varchar(20) NOT NULL DEFAULT 'private',
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__order_comments_order_id_foreign` (`order_id`),
  CONSTRAINT `__DB_PREFIX__order_comments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_downloads`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_downloads` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `attachment_id` bigint(20) unsigned DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `filename` varchar(190) DEFAULT NULL,
  `max_uses` int(10) unsigned NOT NULL DEFAULT 5,
  `uses` int(10) unsigned NOT NULL DEFAULT 0,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__order_downloads_token_unique` (`token`),
  KEY `__DB_PREFIX__order_downloads_order_id_foreign` (`order_id`),
  CONSTRAINT `__DB_PREFIX__order_downloads_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `parent_product_id` bigint(20) unsigned DEFAULT NULL,
  `combination_id` bigint(20) unsigned DEFAULT NULL,
  `subscription_plan_id` bigint(20) unsigned DEFAULT NULL,
  `tax_class_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `returned_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(12,2) NOT NULL,
  `tax_rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL,
  `customization` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`customization`)),
  `bundle_selection` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bundle_selection`)),
  `gift_card_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`gift_card_data`)),
  `booking_date` date DEFAULT NULL,
  `booking_end_date` date DEFAULT NULL,
  `rental_duration` int(10) unsigned DEFAULT NULL,
  `rental_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rental_data`)),
  `preorder_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`preorder_data`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__order_items_order_id_foreign` (`order_id`),
  KEY `__DB_PREFIX__order_items_product_id_foreign` (`product_id`),
  KEY `__DB_PREFIX__order_items_tax_class_id_foreign` (`tax_class_id`),
  KEY `order_item_sub_plan_fk` (`subscription_plan_id`),
  KEY `__DB_PREFIX__order_items_warehouse_id_foreign` (`warehouse_id`),
  KEY `__DB_PREFIX__order_items_parent_product_id_index` (`parent_product_id`),
  CONSTRAINT `__DB_PREFIX__order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__order_items_tax_class_id_foreign` FOREIGN KEY (`tax_class_id`) REFERENCES `__DB_PREFIX__tax_classes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__order_items_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `__DB_PREFIX__warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `order_item_sub_plan_fk` FOREIGN KEY (`subscription_plan_id`) REFERENCES `__DB_PREFIX__subscription_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_return_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_return_id` bigint(20) unsigned NOT NULL,
  `order_item_id` bigint(20) unsigned NOT NULL,
  `qty` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__order_return_items_order_return_id_foreign` (`order_return_id`),
  KEY `__DB_PREFIX__order_return_items_order_item_id_foreign` (`order_item_id`),
  CONSTRAINT `__DB_PREFIX__order_return_items_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `__DB_PREFIX__order_items` (`id`),
  CONSTRAINT `__DB_PREFIX__order_return_items_order_return_id_foreign` FOREIGN KEY (`order_return_id`) REFERENCES `__DB_PREFIX__order_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_returns`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `number` varchar(40) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'requested',
  `reason` varchar(500) DEFAULT NULL,
  `customer_note` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `refund_subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `restock` tinyint(1) NOT NULL DEFAULT 1,
  `requested_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `approved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `rejected_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `refunded_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `reason_id` bigint(20) unsigned DEFAULT NULL,
  `resolution` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__order_returns_number_unique` (`number`),
  KEY `__DB_PREFIX__order_returns_order_id_status_index` (`order_id`,`status`),
  KEY `__DB_PREFIX__order_returns_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__order_returns_status_index` (`status`),
  CONSTRAINT `__DB_PREFIX__order_returns_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_stage_profile`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_stage_profile` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_stage_id` bigint(20) unsigned NOT NULL,
  `profile_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__order_stage_profile_order_stage_id_profile_id_unique` (`order_stage_id`,`profile_id`),
  KEY `__DB_PREFIX__order_stage_profile_profile_id_foreign` (`profile_id`),
  CONSTRAINT `__DB_PREFIX__order_stage_profile_order_stage_id_foreign` FOREIGN KEY (`order_stage_id`) REFERENCES `__DB_PREFIX__order_stages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__order_stage_profile_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `__DB_PREFIX__profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__payment_statuses`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__payment_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(120) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__payment_statuses_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__sales_channels`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__sales_channels` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(32) NOT NULL,
  `name` varchar(120) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__sales_channels_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_stages`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_stages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `buyer_label` varchar(255) NOT NULL,
  `buyer_note` varchar(255) DEFAULT NULL,
  `tone` varchar(32) NOT NULL DEFAULT 'neutral',
  `lane` varchar(32) NOT NULL DEFAULT 'inbox',
  `tracker_step` tinyint(3) unsigned DEFAULT NULL,
  `sla_hours` smallint(5) unsigned DEFAULT NULL,
  `settle_payment` tinyint(1) NOT NULL DEFAULT 0,
  `unlock_invoice` tinyint(1) NOT NULL DEFAULT 0,
  `unlock_delivery_slip` tinyint(1) NOT NULL DEFAULT 0,
  `unlock_credit_slip` tinyint(1) NOT NULL DEFAULT 0,
  `stock_move` varchar(16) NOT NULL DEFAULT 'none',
  `notify_buyer` tinyint(1) NOT NULL DEFAULT 1,
  `notify_staff` tinyint(1) NOT NULL DEFAULT 0,
  `mail_template` varchar(64) DEFAULT NULL,
  `buyer_may_pay` tinyint(1) NOT NULL DEFAULT 0,
  `buyer_may_cancel` tinyint(1) NOT NULL DEFAULT 0,
  `next_stage_ids` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`next_stage_ids`)),
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__order_stages_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__order_status_histories`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__order_status_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL,
  `order_stage_id` bigint(20) unsigned DEFAULT NULL,
  `comment` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__order_status_histories_order_id_foreign` (`order_id`),
  KEY `__DB_PREFIX__order_status_histories_order_stage_id_index` (`order_stage_id`),
  CONSTRAINT `__DB_PREFIX__order_status_histories_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__orders`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `checkout_group_id` bigint(20) unsigned DEFAULT NULL,
  `number` varchar(255) NOT NULL,
  `checkout_reference` varchar(255) DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_admin_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `order_stage_id` bigint(20) unsigned DEFAULT NULL,
  `channel` varchar(16) NOT NULL DEFAULT 'web',
  `sales_channel_id` bigint(20) unsigned DEFAULT NULL,
  `currency_code` varchar(8) NOT NULL,
  `currency_rate` decimal(12,6) NOT NULL DEFAULT 1.000000,
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`tax_details`)),
  `shipping_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `fee_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refunded_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `shipping_method_id` bigint(20) unsigned DEFAULT NULL,
  `payment_status` varchar(255) NOT NULL DEFAULT 'pending',
  `payment_status_id` bigint(20) unsigned DEFAULT NULL,
  `amount_paid` decimal(12,2) DEFAULT NULL,
  `amount_due` decimal(12,2) DEFAULT NULL,
  `payment_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_details`)),
  `fulfillment_details` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`fulfillment_details`)),
  `billing_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`billing_address`)),
  `shipping_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`shipping_address`)),
  `customer_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__orders_checkout_group_id_foreign` (`checkout_group_id`),
  KEY `__DB_PREFIX__orders_checkout_reference_index` (`checkout_reference`),
  KEY `__DB_PREFIX__orders_payment_method_id_foreign` (`payment_method_id`),
  KEY `__DB_PREFIX__orders_shipping_method_id_foreign` (`shipping_method_id`),
  KEY `__DB_PREFIX__orders_customer_id_status_index` (`customer_id`,`status`),
  KEY `orders_status_index` (`status`),
  KEY `orders_order_stage_id_index` (`order_stage_id`),
  KEY `orders_payment_status_index` (`payment_status`),
  KEY `orders_payment_status_id_index` (`payment_status_id`),
  KEY `orders_sales_channel_id_index` (`sales_channel_id`),
  KEY `orders_created_at_index` (`created_at`),
  KEY `orders_email_index` (`email`),
  KEY `orders_status_id_index` (`status`,`id`),
  KEY `__DB_PREFIX__orders_assigned_admin_id_foreign` (`assigned_admin_id`),
  KEY `orders_customer_created_index` (`customer_id`,`created_at`),
  KEY `orders_created_id_index` (`created_at`,`id`),
  KEY `orders_number_index` (`number`),
  CONSTRAINT `__DB_PREFIX__orders_assigned_admin_id_foreign` FOREIGN KEY (`assigned_admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__orders_checkout_group_id_foreign` FOREIGN KEY (`checkout_group_id`) REFERENCES `__DB_PREFIX__checkout_groups` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__orders_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__orders_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `__DB_PREFIX__payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__orders_shipping_method_id_foreign` FOREIGN KEY (`shipping_method_id`) REFERENCES `__DB_PREFIX__shipping_methods` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__password_reset_tokens`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__payment_checkouts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__payment_checkouts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `token` varchar(64) NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `cart_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `email` varchar(190) DEFAULT NULL,
  `payment_method_id` bigint(20) unsigned NOT NULL,
  `driver` varchar(64) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'requires_payment',
  `amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `currency_code` varchar(8) NOT NULL DEFAULT 'USD',
  `provider_reference` varchar(255) DEFAULT NULL,
  `idempotency_key` varchar(128) DEFAULT NULL,
  `checkout_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`checkout_snapshot`)),
  `payment_proof` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payment_proof`)),
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__payment_checkouts_token_unique` (`token`),
  UNIQUE KEY `__DB_PREFIX__payment_checkouts_idempotency_key_unique` (`idempotency_key`),
  UNIQUE KEY `__DB_PREFIX__payment_checkouts_order_id_unique` (`order_id`),
  KEY `__DB_PREFIX__payment_checkouts_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__payment_checkouts_cart_id_index` (`cart_id`),
  KEY `__DB_PREFIX__payment_checkouts_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__payment_checkouts_payment_method_id_index` (`payment_method_id`),
  KEY `__DB_PREFIX__payment_checkouts_status_index` (`status`),
  KEY `__DB_PREFIX__payment_checkouts_provider_reference_index` (`provider_reference`),
  KEY `__DB_PREFIX__payment_checkouts_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__payment_method_store`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__payment_method_store` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `payment_method_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__payment_method_store_payment_method_id_store_id_unique` (`payment_method_id`,`store_id`),
  KEY `__DB_PREFIX__payment_method_store_store_id_foreign` (`store_id`),
  CONSTRAINT `__DB_PREFIX__payment_method_store_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `__DB_PREFIX__payment_methods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__payment_method_store_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__payment_methods`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__payment_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `driver` varchar(255) NOT NULL DEFAULT 'offline',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `rules` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rules`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__payment_methods_code_unique` (`code`),
  KEY `__DB_PREFIX__payment_methods_seller_id_index` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__permission_role`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__permission_role` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `__DB_PREFIX__permission_role_role_id_foreign` (`role_id`),
  CONSTRAINT `__DB_PREFIX__permission_role_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `__DB_PREFIX__permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__permission_role_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `__DB_PREFIX__roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__permissions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `group` varchar(255) NOT NULL DEFAULT 'general',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__permissions_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__personal_access_tokens`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__personal_access_tokens_token_unique` (`token`),
  KEY `__DB_PREFIX__personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `__DB_PREFIX__personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__plugin_settings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__plugin_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plugin_id` bigint(20) unsigned NOT NULL,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__plugin_settings_plugin_id_key_unique` (`plugin_id`,`key`),
  CONSTRAINT `__DB_PREFIX__plugin_settings_plugin_id_foreign` FOREIGN KEY (`plugin_id`) REFERENCES `__DB_PREFIX__plugins` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__plugin_store`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__plugin_store` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned NOT NULL,
  `plugin` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__plugin_store_store_id_plugin_unique` (`store_id`,`plugin`),
  CONSTRAINT `__DB_PREFIX__plugin_store_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__plugins`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__plugins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `vendor` varchar(40) NOT NULL,
  `name` varchar(40) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `version` varchar(20) NOT NULL DEFAULT '1.0.0',
  `author` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'safe',
  `capabilities` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`capabilities`)),
  `hooks` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`hooks`)),
  `is_bundled` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `path` varchar(255) NOT NULL,
  `checksum` varchar(64) DEFAULT NULL,
  `health_status` varchar(20) DEFAULT NULL,
  `last_error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__plugins_vendor_name_unique` (`vendor`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__price_alerts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__price_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `last_price` decimal(12,2) NOT NULL,
  `email` varchar(190) NOT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__price_alerts_customer_id_product_id_unique` (`customer_id`,`product_id`),
  KEY `__DB_PREFIX__price_alerts_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__price_alerts_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__price_alerts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_answers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_answers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_question_id` bigint(20) unsigned NOT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `is_official` tinyint(1) NOT NULL DEFAULT 1,
  `is_approved` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__product_answers_product_question_id_foreign` (`product_question_id`),
  KEY `__DB_PREFIX__product_answers_admin_id_foreign` (`admin_id`),
  KEY `__DB_PREFIX__product_answers_customer_id_foreign` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__product_answers_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__product_answers_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__product_answers_product_question_id_foreign` FOREIGN KEY (`product_question_id`) REFERENCES `__DB_PREFIX__product_questions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_combination_axes`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_combination_axes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `attribute_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_combination_axes_product_id_attribute_id_unique` (`product_id`,`attribute_id`),
  KEY `__DB_PREFIX__product_combination_axes_attribute_id_foreign` (`attribute_id`),
  CONSTRAINT `__DB_PREFIX__product_combination_axes_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `__DB_PREFIX__attributes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_combination_axes_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_combination_axis_values`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_combination_axis_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `attribute_id` bigint(20) unsigned NOT NULL,
  `attribute_value_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pc_axis_values_unique` (`product_id`,`attribute_id`,`attribute_value_id`),
  KEY `__DB_PREFIX__product_combination_axis_values_attribute_id_foreign` (`attribute_id`),
  KEY `__DB_PREFIX__product_combination_axis_values_attribute_value_id_foreign` (`attribute_value_id`),
  CONSTRAINT `__DB_PREFIX__product_combination_axis_values_attribute_id_foreign` FOREIGN KEY (`attribute_id`) REFERENCES `__DB_PREFIX__attributes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_combination_axis_values_attribute_value_id_foreign` FOREIGN KEY (`attribute_value_id`) REFERENCES `__DB_PREFIX__attribute_values` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_combination_axis_values_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_components`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_components` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_product_id` bigint(20) unsigned NOT NULL,
  `child_product_id` bigint(20) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `min_quantity` int(10) unsigned DEFAULT NULL,
  `max_quantity` int(10) unsigned DEFAULT NULL,
  `qty_editable` tinyint(1) NOT NULL DEFAULT 1,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `default_selected` tinyint(1) NOT NULL DEFAULT 1,
  `option_group` varchar(255) DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_components_unique` (`parent_product_id`,`child_product_id`),
  KEY `__DB_PREFIX__product_components_child_product_id_index` (`child_product_id`),
  CONSTRAINT `__DB_PREFIX__product_components_child_product_id_foreign` FOREIGN KEY (`child_product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_components_parent_product_id_foreign` FOREIGN KEY (`parent_product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_customization_fields`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_customization_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `label` varchar(255) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__product_customization_fields_product_id_position_index` (`product_id`,`position`),
  CONSTRAINT `__DB_PREFIX__product_customization_fields_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_feature_values`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_feature_values` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `feature_id` bigint(20) unsigned NOT NULL,
  `feature_value_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pfv_unique` (`product_id`,`feature_id`,`feature_value_id`),
  KEY `__DB_PREFIX__product_feature_values_feature_id_foreign` (`feature_id`),
  KEY `__DB_PREFIX__product_feature_values_feature_value_id_foreign` (`feature_value_id`),
  CONSTRAINT `__DB_PREFIX__product_feature_values_feature_id_foreign` FOREIGN KEY (`feature_id`) REFERENCES `__DB_PREFIX__features` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_feature_values_feature_value_id_foreign` FOREIGN KEY (`feature_value_id`) REFERENCES `__DB_PREFIX__feature_values` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_feature_values_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_gift_card_amounts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_gift_card_amounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `value` decimal(12,2) NOT NULL,
  `selling_price` decimal(12,2) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_gift_card_amounts_product_id_value_unique` (`product_id`,`value`),
  KEY `__DB_PREFIX__product_gift_card_amounts_product_id_position_index` (`product_id`,`position`),
  CONSTRAINT `__DB_PREFIX__product_gift_card_amounts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_gift_cards`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_gift_cards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `amount_type` varchar(16) NOT NULL DEFAULT 'fixed',
  `min_amount` decimal(12,2) DEFAULT NULL,
  `max_amount` decimal(12,2) DEFAULT NULL,
  `default_amount` decimal(12,2) DEFAULT NULL,
  `amount_step` decimal(12,2) DEFAULT NULL,
  `delivery_method` varchar(16) NOT NULL DEFAULT 'email',
  `recipient_name_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `recipient_name_required` tinyint(1) NOT NULL DEFAULT 0,
  `recipient_email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `recipient_email_required` tinyint(1) NOT NULL DEFAULT 1,
  `sender_name_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `sender_name_required` tinyint(1) NOT NULL DEFAULT 0,
  `gift_message_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `gift_message_required` tinyint(1) NOT NULL DEFAULT 0,
  `validity_type` varchar(16) NOT NULL DEFAULT 'never',
  `validity_days` int(10) unsigned DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `allow_online_redemption` tinyint(1) NOT NULL DEFAULT 1,
  `allow_offline_redemption` tinyint(1) NOT NULL DEFAULT 0,
  `usage_type` varchar(16) NOT NULL DEFAULT 'multiple',
  `allow_partial_redemption` tinyint(1) NOT NULL DEFAULT 1,
  `code_length` tinyint(3) unsigned NOT NULL DEFAULT 12,
  `code_prefix` varchar(32) DEFAULT NULL,
  `code_charset` varchar(32) NOT NULL DEFAULT 'alphanumeric',
  `code_uppercase` tinyint(1) NOT NULL DEFAULT 1,
  `min_order_amount` decimal(12,2) DEFAULT NULL,
  `max_order_amount` decimal(12,2) DEFAULT NULL,
  `restrictions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`restrictions`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_gift_cards_product_id_unique` (`product_id`),
  CONSTRAINT `__DB_PREFIX__product_gift_cards_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_images`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `path` varchar(255) NOT NULL,
  `alt` varchar(255) DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__product_images_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__product_images_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_preorders`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_preorders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `start_at` timestamp NULL DEFAULT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `release_at` timestamp NULL DEFAULT NULL,
  `fulfillment_at` timestamp NULL DEFAULT NULL,
  `expected_delivery_at` timestamp NULL DEFAULT NULL,
  `payment_type` varchar(16) NOT NULL DEFAULT 'full',
  `deposit_type` varchar(16) DEFAULT NULL,
  `deposit_value` decimal(12,2) DEFAULT NULL,
  `min_quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `max_quantity` int(10) unsigned DEFAULT NULL,
  `total_limit` int(10) unsigned DEFAULT NULL,
  `orders_placed` int(10) unsigned NOT NULL DEFAULT 0,
  `message` text DEFAULT NULL,
  `show_badge` tinyint(1) NOT NULL DEFAULT 1,
  `show_release_date` tinyint(1) NOT NULL DEFAULT 1,
  `show_delivery_date` tinyint(1) NOT NULL DEFAULT 0,
  `show_countdown_pdp` tinyint(1) NOT NULL DEFAULT 0,
  `show_countdown_listing` tinyint(1) NOT NULL DEFAULT 0,
  `show_image_label` tinyint(1) NOT NULL DEFAULT 0,
  `notify_me_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `fulfillment_rule` varchar(32) NOT NULL DEFAULT 'auto',
  `preorder_price` decimal(12,2) DEFAULT NULL,
  `preorder_price_valid_until` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_preorders_product_id_unique` (`product_id`),
  CONSTRAINT `__DB_PREFIX__product_preorders_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_price_history`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_price_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `price` decimal(12,2) NOT NULL,
  `recorded_on` date NOT NULL,
  `recorded_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__price_history_product_day` (`product_id`,`recorded_on`,`store_id`),
  KEY `__DB_PREFIX__product_price_history_product_id_recorded_at_index` (`product_id`,`recorded_at`),
  CONSTRAINT `__DB_PREFIX__product_price_history_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_price_history_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_qty_prices`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_qty_prices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `min_qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `max_qty` decimal(12,3) DEFAULT NULL,
  `price` decimal(12,2) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_qty_prices_product_id_min_qty_unique` (`product_id`,`min_qty`),
  CONSTRAINT `__DB_PREFIX__product_qty_prices_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_questions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_questions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `body` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `rejected_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__product_questions_product_id_foreign` (`product_id`),
  KEY `__DB_PREFIX__product_questions_customer_id_foreign` (`customer_id`),
  KEY `__DB_PREFIX__product_questions_status_index` (`status`),
  KEY `__DB_PREFIX__product_questions_is_approved_index` (`is_approved`),
  CONSTRAINT `__DB_PREFIX__product_questions_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_questions_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_relations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_relations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `related_product_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_rel_unique` (`product_id`,`related_product_id`,`type`),
  KEY `__DB_PREFIX__product_relations_related_product_id_foreign` (`related_product_id`),
  CONSTRAINT `__DB_PREFIX__product_relations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_relations_related_product_id_foreign` FOREIGN KEY (`related_product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_rental_assets`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_rental_assets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `asset_id` varchar(64) NOT NULL,
  `serial_number` varchar(128) DEFAULT NULL,
  `barcode` varchar(128) DEFAULT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'available',
  `location` varchar(255) DEFAULT NULL,
  `condition` varchar(255) DEFAULT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_rental_assets_product_id_asset_id_unique` (`product_id`,`asset_id`),
  KEY `__DB_PREFIX__product_rental_assets_product_id_status_index` (`product_id`,`status`),
  CONSTRAINT `__DB_PREFIX__product_rental_assets_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_rental_pricing_tiers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_rental_pricing_tiers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `min_duration` int(10) unsigned NOT NULL,
  `max_duration` int(10) unsigned NOT NULL,
  `unit` varchar(16) NOT NULL,
  `price` decimal(12,2) NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__product_rental_pricing_tiers_product_id_position_index` (`product_id`,`position`),
  CONSTRAINT `__DB_PREFIX__product_rental_pricing_tiers_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_rentals`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_rentals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `rental_unit` varchar(16) NOT NULL DEFAULT 'day',
  `min_duration` int(10) unsigned NOT NULL DEFAULT 1,
  `max_duration` int(10) unsigned NOT NULL DEFAULT 30,
  `default_duration` int(10) unsigned NOT NULL DEFAULT 1,
  `pricing_model` varchar(32) NOT NULL DEFAULT 'per_day',
  `availability_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`availability_config`)),
  `security_deposit_type` varchar(16) NOT NULL DEFAULT 'none',
  `security_deposit_value` decimal(12,2) DEFAULT NULL,
  `inventory_mode` varchar(16) NOT NULL DEFAULT 'quantity',
  `maintenance_units` int(10) unsigned NOT NULL DEFAULT 0,
  `out_of_service_units` int(10) unsigned NOT NULL DEFAULT 0,
  `fulfillment_method` varchar(16) NOT NULL DEFAULT 'both',
  `return_method` varchar(16) NOT NULL DEFAULT 'both',
  `return_location` varchar(255) DEFAULT NULL,
  `grace_period` int(10) unsigned DEFAULT NULL,
  `grace_period_unit` varchar(16) DEFAULT NULL,
  `late_fee_type` varchar(16) NOT NULL DEFAULT 'none',
  `late_fee_value` decimal(12,2) DEFAULT NULL,
  `damage_fee_type` varchar(16) NOT NULL DEFAULT 'none',
  `damage_fee_value` decimal(12,2) DEFAULT NULL,
  `lost_item_fee_type` varchar(16) NOT NULL DEFAULT 'none',
  `lost_item_fee_value` decimal(12,2) DEFAULT NULL,
  `cleaning_fee` decimal(12,2) DEFAULT NULL,
  `policies` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`policies`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_rentals_product_id_unique` (`product_id`),
  CONSTRAINT `__DB_PREFIX__product_rentals_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_services`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_services` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `service_type` varchar(32) NOT NULL DEFAULT 'one_time',
  `duration` decimal(10,2) DEFAULT NULL,
  `duration_unit` varchar(16) DEFAULT NULL,
  `pricing_model` varchar(32) NOT NULL DEFAULT 'fixed',
  `delivery_method` varchar(16) NOT NULL DEFAULT 'online',
  `meeting_instructions` text DEFAULT NULL,
  `delivery_instructions` text DEFAULT NULL,
  `online_service_url` varchar(255) DEFAULT NULL,
  `service_area` varchar(255) DEFAULT NULL,
  `location_info` text DEFAULT NULL,
  `travel_fee` decimal(12,2) DEFAULT NULL,
  `availability_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`availability_config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_services_product_id_unique` (`product_id`),
  CONSTRAINT `__DB_PREFIX__product_services_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_shipping_method`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_shipping_method` (
  `product_id` bigint(20) unsigned NOT NULL,
  `shipping_method_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`product_id`,`shipping_method_id`),
  KEY `__DB_PREFIX__product_shipping_method_shipping_method_id_foreign` (`shipping_method_id`),
  CONSTRAINT `__DB_PREFIX__product_shipping_method_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_shipping_method_shipping_method_id_foreign` FOREIGN KEY (`shipping_method_id`) REFERENCES `__DB_PREFIX__shipping_methods` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_store`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_store` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `is_visible` tinyint(1) NOT NULL DEFAULT 1,
  `price` decimal(12,2) DEFAULT NULL,
  `url_key` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_store_product_id_store_id_unique` (`product_id`,`store_id`),
  KEY `__DB_PREFIX__product_store_store_id_foreign` (`store_id`),
  CONSTRAINT `__DB_PREFIX__product_store_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_store_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_subscription_plans`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_subscription_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `subscription_plan_id` bigint(20) unsigned NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_type` varchar(16) DEFAULT NULL,
  `discount_value` decimal(12,2) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `product_plan_unique` (`product_id`,`subscription_plan_id`),
  KEY `prod_sub_plan_plan_fk` (`subscription_plan_id`),
  CONSTRAINT `__DB_PREFIX__product_subscription_plans_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prod_sub_plan_plan_fk` FOREIGN KEY (`subscription_plan_id`) REFERENCES `__DB_PREFIX__subscription_plans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_supplier`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_supplier` (
  `product_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `supplier_reference` varchar(255) DEFAULT NULL,
  `wholesale_price` decimal(12,4) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`product_id`,`supplier_id`),
  KEY `__DB_PREFIX__product_supplier_supplier_id_foreign` (`supplier_id`),
  CONSTRAINT `__DB_PREFIX__product_supplier_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_supplier_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `__DB_PREFIX__suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_tag`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_tag` (
  `product_id` bigint(20) unsigned NOT NULL,
  `tag_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`product_id`,`tag_id`),
  KEY `__DB_PREFIX__product_tag_tag_id_foreign` (`tag_id`),
  CONSTRAINT `__DB_PREFIX__product_tag_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__product_tag_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `__DB_PREFIX__tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `short_description` text DEFAULT NULL,
  `description` longtext DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  `og_title` varchar(255) DEFAULT NULL,
  `og_description` text DEFAULT NULL,
  `focus_keyword` varchar(255) DEFAULT NULL,
  `canonical_url` varchar(255) DEFAULT NULL,
  `robots` varchar(255) NOT NULL DEFAULT 'index,follow',
  `subscription_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_translations_product_id_language_code_unique` (`product_id`,`language_code`),
  UNIQUE KEY `__DB_PREFIX__product_translations_language_code_slug_unique` (`language_code`,`slug`),
  KEY `product_trans_lang_name` (`language_code`,`name`(80)),
  FULLTEXT KEY `__DB_PREFIX__product_search` (`name`,`description`,`meta_keywords`),
  CONSTRAINT `__DB_PREFIX__product_translations_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_variant_values`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_variant_values` (
  `product_variant_id` bigint(20) unsigned NOT NULL,
  `attribute_value_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`product_variant_id`,`attribute_value_id`),
  KEY `prod_var_val_attr_fk` (`attribute_value_id`),
  CONSTRAINT `prod_var_val_attr_fk` FOREIGN KEY (`attribute_value_id`) REFERENCES `__DB_PREFIX__attribute_values` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prod_var_val_var_fk` FOREIGN KEY (`product_variant_id`) REFERENCES `__DB_PREFIX__product_variants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__product_variants`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__product_variants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `barcode` varchar(255) DEFAULT NULL,
  `price` decimal(12,2) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `sale_price_type` varchar(255) NOT NULL DEFAULT 'fixed',
  `cost_price` decimal(12,2) DEFAULT NULL,
  `stock_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `reserved_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `manage_stock` tinyint(1) NOT NULL DEFAULT 1,
  `stock_status` varchar(255) NOT NULL DEFAULT 'in_stock',
  `allow_backorder` tinyint(1) NOT NULL DEFAULT 0,
  `weight` decimal(10,3) DEFAULT NULL,
  `length` decimal(10,3) DEFAULT NULL,
  `width` decimal(10,3) DEFAULT NULL,
  `height` decimal(10,3) DEFAULT NULL,
  `cover_path` varchar(255) DEFAULT NULL,
  `cover_image_id` bigint(20) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `combination_key` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__product_variants_product_id_combination_key_unique` (`product_id`,`combination_key`),
  KEY `prod_var_cover_img_fk` (`cover_image_id`),
  CONSTRAINT `__DB_PREFIX__product_variants_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prod_var_cover_img_fk` FOREIGN KEY (`cover_image_id`) REFERENCES `__DB_PREFIX__product_images` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__products`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(255) NOT NULL DEFAULT 'simple',
  `selection_mode` varchar(255) DEFAULT NULL,
  `bundle_type` varchar(255) DEFAULT NULL,
  `pricing_mode` varchar(255) DEFAULT NULL,
  `pricing_discount_type` varchar(255) DEFAULT NULL,
  `pricing_discount_value` decimal(12,2) DEFAULT NULL,
  `inventory_mode` varchar(255) DEFAULT NULL,
  `shipping_mode` varchar(255) DEFAULT NULL,
  `sku` varchar(255) NOT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `brand_id` bigint(20) unsigned DEFAULT NULL,
  `tax_class_id` bigint(20) unsigned DEFAULT NULL,
  `default_category_id` bigint(20) unsigned DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `compare_price` decimal(12,2) DEFAULT NULL,
  `sale_price` decimal(12,2) DEFAULT NULL,
  `sale_price_type` varchar(255) NOT NULL DEFAULT 'fixed',
  `sale_starts_at` date DEFAULT NULL,
  `sale_ends_at` date DEFAULT NULL,
  `cost_price` decimal(12,2) DEFAULT NULL,
  `stock_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `reserved_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `stock_status` varchar(255) NOT NULL DEFAULT 'in_stock',
  `low_stock_threshold` int(10) unsigned NOT NULL DEFAULT 5,
  `low_stock_alert` int(10) unsigned NOT NULL DEFAULT 10,
  `critical_stock` int(10) unsigned NOT NULL DEFAULT 3,
  `manage_stock` tinyint(1) NOT NULL DEFAULT 1,
  `allow_backorder` tinyint(1) NOT NULL DEFAULT 0,
  `backorder_status` varchar(255) NOT NULL DEFAULT 'deny',
  `notify_low_stock` tinyint(1) NOT NULL DEFAULT 1,
  `notify_out_of_stock` tinyint(1) NOT NULL DEFAULT 1,
  `low_stock_notified_at` timestamp NULL DEFAULT NULL,
  `min_qty` decimal(12,3) DEFAULT NULL,
  `max_qty` decimal(12,3) DEFAULT NULL,
  `qty_step` decimal(12,3) DEFAULT NULL,
  `sale_unit` varchar(16) NOT NULL DEFAULT 'piece',
  `sale_unit_code` varchar(8) NOT NULL DEFAULT 'pc',
  `weight` decimal(10,3) DEFAULT NULL,
  `weight_class` varchar(255) NOT NULL DEFAULT 'kg',
  `length` decimal(10,3) DEFAULT NULL,
  `width` decimal(10,3) DEFAULT NULL,
  `height` decimal(10,3) DEFAULT NULL,
  `length_class` varchar(255) NOT NULL DEFAULT 'cm',
  `processing_time` int(10) unsigned DEFAULT NULL,
  `processing_time_unit` varchar(255) NOT NULL DEFAULT 'days',
  `delivery_from` int(10) unsigned DEFAULT NULL,
  `delivery_to` int(10) unsigned DEFAULT NULL,
  `additional_shipping_cost` decimal(12,6) NOT NULL DEFAULT 0.000000,
  `available_all_carriers` tinyint(1) NOT NULL DEFAULT 1,
  `barcode` varchar(255) DEFAULT NULL,
  `isbn` varchar(255) DEFAULT NULL,
  `upc` varchar(255) DEFAULT NULL,
  `mpn` varchar(255) DEFAULT NULL,
  `gtin` varchar(255) DEFAULT NULL,
  `display_condition` tinyint(1) NOT NULL DEFAULT 0,
  `condition` varchar(255) DEFAULT NULL,
  `visibility` varchar(255) NOT NULL DEFAULT 'both',
  `available_for_order` tinyint(1) NOT NULL DEFAULT 1,
  `show_price` tinyint(1) NOT NULL DEFAULT 1,
  `customization_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `subscription_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `preorder_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `purchase_option` varchar(24) NOT NULL DEFAULT 'one_time',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_new` tinyint(1) NOT NULL DEFAULT 0,
  `is_on_sale` tinyint(1) NOT NULL DEFAULT 0,
  `is_best_seller` tinyint(1) NOT NULL DEFAULT 0,
  `cover_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `video_url` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__products_sku_unique` (`sku`),
  KEY `__DB_PREFIX__products_brand_id_foreign` (`brand_id`),
  KEY `__DB_PREFIX__products_tax_class_id_foreign` (`tax_class_id`),
  KEY `__DB_PREFIX__products_default_category_id_foreign` (`default_category_id`),
  KEY `__DB_PREFIX__products_seller_id_index` (`seller_id`),
  KEY `__DB_PREFIX__products_supplier_id_foreign` (`supplier_id`),
  KEY `products_enabled_id_index` (`is_enabled`,`id`),
  KEY `products_stock_id_index` (`stock_qty`,`id`),
  KEY `products_created_id_index` (`created_at`,`id`),
  CONSTRAINT `__DB_PREFIX__products_brand_id_foreign` FOREIGN KEY (`brand_id`) REFERENCES `__DB_PREFIX__brands` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__products_default_category_id_foreign` FOREIGN KEY (`default_category_id`) REFERENCES `__DB_PREFIX__categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__products_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__products_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `__DB_PREFIX__suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__products_tax_class_id_foreign` FOREIGN KEY (`tax_class_id`) REFERENCES `__DB_PREFIX__tax_classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__profile_permission`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__profile_permission` (
  `profile_id` bigint(20) unsigned NOT NULL,
  `permission_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`profile_id`,`permission_id`),
  KEY `__DB_PREFIX__profile_permission_permission_id_foreign` (`permission_id`),
  CONSTRAINT `__DB_PREFIX__profile_permission_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `__DB_PREFIX__permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__profile_permission_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `__DB_PREFIX__profiles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__profile_role`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__profile_role` (
  `profile_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`profile_id`,`role_id`),
  KEY `__DB_PREFIX__profile_role_role_id_foreign` (`role_id`),
  CONSTRAINT `__DB_PREFIX__profile_role_profile_id_foreign` FOREIGN KEY (`profile_id`) REFERENCES `__DB_PREFIX__profiles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__profile_role_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `__DB_PREFIX__roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__profiles`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__profiles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__profiles_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__purchase_order_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(120) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `qty_ordered` decimal(12,3) NOT NULL DEFAULT 0.000,
  `qty_received` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__purchase_order_items_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `__DB_PREFIX__purchase_order_items_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__purchase_order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__purchase_order_items_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `__DB_PREFIX__purchase_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__purchase_order_receipts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__purchase_order_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `purchase_order_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(12,3) NOT NULL,
  `unit_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `inventory_reference` varchar(255) DEFAULT NULL,
  `is_final` tinyint(1) NOT NULL DEFAULT 0,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversed_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `reversal_reference` varchar(255) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__purchase_order_receipts_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `__DB_PREFIX__purchase_order_receipts_purchase_order_item_id_foreign` (`purchase_order_item_id`),
  KEY `__DB_PREFIX__purchase_order_receipts_product_id_foreign` (`product_id`),
  KEY `__DB_PREFIX__purchase_order_receipts_warehouse_id_foreign` (`warehouse_id`),
  KEY `__DB_PREFIX__purchase_order_receipts_admin_id_foreign` (`admin_id`),
  CONSTRAINT `__DB_PREFIX__purchase_order_receipts_admin_id_foreign` FOREIGN KEY (`admin_id`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__purchase_order_receipts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__purchase_order_receipts_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `__DB_PREFIX__purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__purchase_order_receipts_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `__DB_PREFIX__purchase_order_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__purchase_order_receipts_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `__DB_PREFIX__warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__purchase_orders`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `number` varchar(40) NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(24) NOT NULL DEFAULT 'draft',
  `expected_at` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `discount_type` varchar(16) DEFAULT NULL,
  `discount_value` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `shipping_cost` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `ordered_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__purchase_orders_number_unique` (`number`),
  KEY `__DB_PREFIX__purchase_orders_supplier_id_foreign` (`supplier_id`),
  KEY `__DB_PREFIX__purchase_orders_warehouse_id_foreign` (`warehouse_id`),
  CONSTRAINT `__DB_PREFIX__purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `__DB_PREFIX__suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__purchase_orders_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `__DB_PREFIX__warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `__DB_PREFIX__return_rules`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__return_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  `return_period` int(10) unsigned NOT NULL DEFAULT 30,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__return_statuses`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__return_statuses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(120) NOT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#6B7280',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `__DB_PREFIX__return_custom_fields`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__return_custom_fields` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `label` varchar(160) NOT NULL,
  `type` varchar(40) NOT NULL DEFAULT 'text',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `input_validation` varchar(80) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `options` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__return_custom_fields_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `__DB_PREFIX__return_messages`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__return_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_return_id` bigint(20) unsigned NOT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `body` text NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__return_messages_order_return_id_index` (`order_return_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__return_reasons`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__return_reasons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(120) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__return_reasons_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__review_votes`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__review_votes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `review_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `helpful` tinyint(1) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__review_votes_review_id_customer_id_unique` (`review_id`,`customer_id`),
  KEY `__DB_PREFIX__review_votes_customer_id_foreign` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__review_votes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__review_votes_review_id_foreign` FOREIGN KEY (`review_id`) REFERENCES `__DB_PREFIX__reviews` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__reviews`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `guest_name` varchar(120) DEFAULT NULL,
  `guest_email` varchar(190) DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `rejected_reason` varchar(255) DEFAULT NULL,
  `admin_reply` text DEFAULT NULL,
  `admin_replied_at` timestamp NULL DEFAULT NULL,
  `is_verified_purchase` tinyint(1) NOT NULL DEFAULT 0,
  `photo_path` varchar(255) DEFAULT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`images`)),
  `video_path` varchar(255) DEFAULT NULL,
  `helpful_count` int(10) unsigned NOT NULL DEFAULT 0,
  `not_helpful_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__reviews_product_id_customer_id_unique` (`product_id`,`customer_id`),
  KEY `__DB_PREFIX__reviews_customer_id_foreign` (`customer_id`),
  KEY `__DB_PREFIX__reviews_guest_email_index` (`guest_email`),
  KEY `__DB_PREFIX__reviews_status_index` (`status`),
  KEY `reviews_product_approved_index` (`product_id`,`is_approved`),
  CONSTRAINT `__DB_PREFIX__reviews_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__reviews_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__roles`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__roles_slug_unique` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__saved_payment_methods`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__saved_payment_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `driver` varchar(40) NOT NULL,
  `reference` varchar(190) NOT NULL,
  `gateway_customer_ref` varchar(190) DEFAULT NULL,
  `brand` varchar(40) DEFAULT NULL,
  `last4` varchar(4) DEFAULT NULL,
  `exp_month` tinyint(3) unsigned DEFAULT NULL,
  `exp_year` smallint(5) unsigned DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `pending_action_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `saved_payment_methods_unique` (`customer_id`,`driver`,`reference`),
  KEY `__DB_PREFIX__saved_payment_methods_customer_id_is_default_index` (`customer_id`,`is_default`),
  KEY `__DB_PREFIX__saved_payment_methods_customer_id_driver_index` (`customer_id`,`driver`),
  CONSTRAINT `__DB_PREFIX__saved_payment_methods_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__saved_queries`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__saved_queries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `sql` text NOT NULL,
  `last_run_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__saved_queries_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__saved_queries_admin_id_index` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__search_pins`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__search_pins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `query` varchar(120) NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__search_pins_query_product_id_unique` (`query`,`product_id`),
  KEY `__DB_PREFIX__search_pins_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__search_pins_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__search_redirects`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__search_redirects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `query` varchar(120) NOT NULL,
  `target_path` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__search_redirects_query_unique` (`query`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__search_synonyms`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__search_synonyms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `term` varchar(120) NOT NULL,
  `aliases` varchar(500) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP TABLE IF EXISTS `__DB_PREFIX__admin_api_audits`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__admin_api_audits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `token_id` bigint(20) unsigned DEFAULT NULL,
  `method` varchar(16) NOT NULL,
  `path` varchar(255) NOT NULL,
  `operation` varchar(120) DEFAULT NULL,
  `status` int(11) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `request_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`request_payload`)),
  `response_summary` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`response_summary`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__admin_api_audits_admin_id_index` (`admin_id`),
  KEY `__DB_PREFIX__admin_api_audits_token_id_index` (`token_id`),
  KEY `__DB_PREFIX__admin_api_audits_path_index` (`path`),
  KEY `__DB_PREFIX__admin_api_audits_created_at_index` (`created_at`),
  KEY `__DB_PREFIX__admin_api_audits_operation_index` (`operation`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__security_audit_logs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__security_audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(80) NOT NULL,
  `actor_type` varchar(40) NOT NULL DEFAULT 'system',
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `actor_email` varchar(190) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `entity_type` varchar(80) DEFAULT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__security_audit_logs_actor_type_actor_id_index` (`actor_type`,`actor_id`),
  KEY `__DB_PREFIX__security_audit_logs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `__DB_PREFIX__security_audit_logs_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__security_audit_logs_action_index` (`action`),
  KEY `__DB_PREFIX__security_audit_logs_actor_type_index` (`actor_type`),
  KEY `__DB_PREFIX__security_audit_logs_actor_id_index` (`actor_id`),
  KEY `__DB_PREFIX__security_audit_logs_entity_type_index` (`entity_type`),
  KEY `__DB_PREFIX__security_audit_logs_entity_id_index` (`entity_id`),
  KEY `__DB_PREFIX__security_audit_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_attributes`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_attributes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `attribute_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'select',
  `supports_swatches` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_attributes_seller_id_code_unique` (`seller_id`,`code`),
  KEY `__DB_PREFIX__seller_attributes_attribute_id_index` (`attribute_id`),
  CONSTRAINT `__DB_PREFIX__seller_attributes_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_brands`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_brands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `brand_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_brands_seller_id_slug_unique` (`seller_id`,`slug`),
  KEY `__DB_PREFIX__seller_brands_brand_id_index` (`brand_id`),
  CONSTRAINT `__DB_PREFIX__seller_brands_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_categories`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_categories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_categories_seller_id_slug_unique` (`seller_id`,`slug`),
  KEY `__DB_PREFIX__seller_categories_category_id_index` (`category_id`),
  KEY `__DB_PREFIX__seller_categories_parent_id_index` (`parent_id`),
  CONSTRAINT `__DB_PREFIX__seller_categories_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_commissions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_commissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT 'Default',
  `type` varchar(20) NOT NULL DEFAULT 'percent',
  `rate` decimal(5,2) NOT NULL DEFAULT 10.00,
  `is_default` tinyint(1) NOT NULL DEFAULT 1,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_commissions_seller_id_foreign` (`seller_id`),
  CONSTRAINT `__DB_PREFIX__seller_commissions_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_discounts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_discounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `discount_rule_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `apply_as` varchar(16) NOT NULL DEFAULT 'coupon',
  `action` varchar(16) NOT NULL DEFAULT 'percent',
  `value` decimal(12,2) NOT NULL DEFAULT 0.00,
  `min_cart` decimal(12,2) DEFAULT NULL,
  `max_uses` int(10) unsigned DEFAULT NULL,
  `used_count` int(10) unsigned NOT NULL DEFAULT 0,
  `starts_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_discounts_seller_id_foreign` (`seller_id`),
  CONSTRAINT `__DB_PREFIX__seller_discounts_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_earnings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_earnings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `seller_order_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `gross_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refunded_gross` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refunded_commission` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refunded_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `paid_net` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `commission_type` varchar(20) DEFAULT NULL,
  `commission_value` decimal(12,2) DEFAULT NULL,
  `commission_source` varchar(32) DEFAULT NULL,
  `commission_rule_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `available_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_earnings_seller_id_status_index` (`seller_id`,`status`),
  KEY `seller_earnings_release_idx` (`seller_id`,`status`,`available_at`),
  KEY `seller_earnings_seller_order_order_item_index` (`seller_order_id`,`order_item_id`),
  CONSTRAINT `__DB_PREFIX__seller_earnings_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`),
  CONSTRAINT `__DB_PREFIX__seller_earnings_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `__DB_PREFIX__seller_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_features`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_features` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `feature_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `type` varchar(20) NOT NULL DEFAULT 'select',
  `is_filterable` tinyint(1) NOT NULL DEFAULT 1,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_features_seller_id_code_unique` (`seller_id`,`code`),
  KEY `__DB_PREFIX__seller_features_feature_id_index` (`feature_id`),
  CONSTRAINT `__DB_PREFIX__seller_features_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_ledger_entries`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_ledger_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `seller_order_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `seller_earning_id` bigint(20) unsigned DEFAULT NULL,
  `seller_withdrawal_id` bigint(20) unsigned DEFAULT NULL,
  `entry_type` varchar(40) NOT NULL,
  `direction` varchar(10) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `currency_code` varchar(8) NOT NULL DEFAULT 'USD',
  `reference` varchar(120) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `meta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_ledger_entries_reference_unique` (`reference`),
  KEY `__DB_PREFIX__seller_ledger_entries_seller_order_id_foreign` (`seller_order_id`),
  KEY `__DB_PREFIX__seller_ledger_entries_seller_id_created_at_index` (`seller_id`,`created_at`),
  KEY `__DB_PREFIX__seller_ledger_entries_seller_id_entry_type_index` (`seller_id`,`entry_type`),
  KEY `__DB_PREFIX__seller_ledger_entries_order_id_index` (`order_id`),
  KEY `__DB_PREFIX__seller_ledger_entries_seller_earning_id_index` (`seller_earning_id`),
  KEY `__DB_PREFIX__seller_ledger_entries_seller_withdrawal_id_index` (`seller_withdrawal_id`),
  CONSTRAINT `__DB_PREFIX__seller_ledger_entries_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`),
  CONSTRAINT `__DB_PREFIX__seller_ledger_entries_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `__DB_PREFIX__seller_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_messages`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `reply_body` text DEFAULT NULL,
  `replied_at` timestamp NULL DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_messages_seller_id_foreign` (`seller_id`),
  KEY `__DB_PREFIX__seller_messages_customer_id_index` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__seller_messages_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_order_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_order_id` bigint(20) unsigned NOT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 1.000,
  `returned_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `cancelled_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_order_items_seller_order_id_order_item_id_unique` (`seller_order_id`,`order_item_id`),
  KEY `__DB_PREFIX__seller_order_items_order_item_id_foreign` (`order_item_id`),
  KEY `__DB_PREFIX__seller_order_items_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__seller_order_items_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `__DB_PREFIX__order_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__seller_order_items_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__seller_order_items_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `__DB_PREFIX__seller_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_orders`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `number` varchar(255) NOT NULL,
  `customer_email` varchar(255) DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'pending',
  `payment_status` varchar(255) DEFAULT NULL,
  `currency_code` varchar(8) NOT NULL DEFAULT 'USD',
  `subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shipping_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `shipping_method_id` bigint(20) unsigned DEFAULT NULL,
  `discount_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_rate` decimal(5,2) DEFAULT NULL,
  `commission_source` varchar(32) DEFAULT NULL,
  `commission_type` varchar(20) DEFAULT NULL,
  `commission_value` decimal(12,2) DEFAULT NULL,
  `commission_rule_id` bigint(20) unsigned DEFAULT NULL,
  `commission_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `earning_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `billing_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`billing_address`)),
  `shipping_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`shipping_address`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_orders_seller_id_order_id_unique` (`seller_id`,`order_id`),
  KEY `__DB_PREFIX__seller_orders_number_index` (`number`),
  KEY `__DB_PREFIX__seller_orders_status_index` (`status`),
  KEY `__DB_PREFIX__seller_orders_order_id_foreign` (`order_id`),
  KEY `__DB_PREFIX__seller_orders_shipping_method_id_index` (`shipping_method_id`),
  KEY `seller_orders_seller_id_status_index` (`seller_id`,`status`),
  CONSTRAINT `__DB_PREFIX__seller_orders_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`),
  CONSTRAINT `__DB_PREFIX__seller_orders_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_payment_methods`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_payment_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `type` varchar(32) NOT NULL DEFAULT 'bank',
  `name` varchar(255) NOT NULL,
  `account_name` varchar(255) DEFAULT NULL,
  `account_number` varchar(255) DEFAULT NULL,
  `bank_name` varchar(255) DEFAULT NULL,
  `routing_number` varchar(255) DEFAULT NULL,
  `paypal_email` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_payment_methods_seller_id_foreign` (`seller_id`),
  CONSTRAINT `__DB_PREFIX__seller_payment_methods_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_products`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_products` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `short_description` text DEFAULT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `compare_price` decimal(12,2) DEFAULT NULL,
  `stock_qty` int(10) unsigned NOT NULL DEFAULT 0,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `approval_status` varchar(20) NOT NULL DEFAULT 'approved',
  `approval_note` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_products_seller_id_product_id_unique` (`seller_id`,`product_id`),
  KEY `__DB_PREFIX__seller_products_product_id_foreign` (`product_id`),
  KEY `__DB_PREFIX__seller_products_sku_index` (`sku`),
  KEY `__DB_PREFIX__seller_products_approval_status_index` (`approval_status`),
  KEY `__DB_PREFIX__seller_products_reviewed_by_foreign` (`reviewed_by`),
  KEY `seller_products_seller_id_approval_status_index` (`seller_id`,`approval_status`),
  CONSTRAINT `__DB_PREFIX__seller_products_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__seller_products_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__seller_products_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_return_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_return_id` bigint(20) unsigned NOT NULL,
  `seller_order_item_id` bigint(20) unsigned NOT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `qty` int(10) unsigned NOT NULL,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `line_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `tax_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `gross_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_return_items_return_soi_unique` (`seller_return_id`,`seller_order_item_id`),
  KEY `__DB_PREFIX__seller_return_items_seller_order_item_id_foreign` (`seller_order_item_id`),
  KEY `__DB_PREFIX__seller_return_items_order_item_id_index` (`order_item_id`),
  KEY `__DB_PREFIX__seller_return_items_product_id_index` (`product_id`),
  CONSTRAINT `__DB_PREFIX__seller_return_items_seller_order_item_id_foreign` FOREIGN KEY (`seller_order_item_id`) REFERENCES `__DB_PREFIX__seller_order_items` (`id`),
  CONSTRAINT `__DB_PREFIX__seller_return_items_seller_return_id_foreign` FOREIGN KEY (`seller_return_id`) REFERENCES `__DB_PREFIX__seller_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_returns`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `seller_order_id` bigint(20) unsigned NOT NULL,
  `seller_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `number` varchar(40) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'requested',
  `reason` varchar(500) DEFAULT NULL,
  `customer_note` text DEFAULT NULL,
  `seller_note` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `refund_subtotal` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `refund_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_reversed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `seller_net_reversed` decimal(12,2) NOT NULL DEFAULT 0.00,
  `restock` tinyint(1) NOT NULL DEFAULT 1,
  `requested_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `refunded_at` timestamp NULL DEFAULT NULL,
  `approved_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `rejected_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `refunded_by_admin_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_returns_number_unique` (`number`),
  KEY `__DB_PREFIX__seller_returns_seller_order_id_foreign` (`seller_order_id`),
  KEY `__DB_PREFIX__seller_returns_seller_id_status_index` (`seller_id`,`status`),
  KEY `__DB_PREFIX__seller_returns_order_id_status_index` (`order_id`,`status`),
  KEY `__DB_PREFIX__seller_returns_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__seller_returns_status_index` (`status`),
  CONSTRAINT `__DB_PREFIX__seller_returns_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__seller_returns_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`),
  CONSTRAINT `__DB_PREFIX__seller_returns_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `__DB_PREFIX__seller_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_revenues`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_revenues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `period_date` date NOT NULL,
  `period_type` varchar(16) NOT NULL DEFAULT 'day',
  `order_count` int(10) unsigned NOT NULL DEFAULT 0,
  `item_count` int(10) unsigned NOT NULL DEFAULT 0,
  `gross_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `commission_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `net_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_revenues_seller_id_period_date_period_type_unique` (`seller_id`,`period_date`,`period_type`),
  CONSTRAINT `__DB_PREFIX__seller_revenues_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_reviews`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_reviews` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `seller_order_id` bigint(20) unsigned DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL DEFAULT 5,
  `title` varchar(255) DEFAULT NULL,
  `body` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `is_verified_purchase` tinyint(1) NOT NULL DEFAULT 1,
  `moderated_by` bigint(20) unsigned DEFAULT NULL,
  `moderated_at` timestamp NULL DEFAULT NULL,
  `moderation_note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `seller_reviews_seller_customer_unique` (`seller_id`,`customer_id`),
  KEY `__DB_PREFIX__seller_reviews_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__seller_reviews_order_id_index` (`order_id`),
  KEY `__DB_PREFIX__seller_reviews_status_index` (`status`),
  KEY `__DB_PREFIX__seller_reviews_seller_order_id_index` (`seller_order_id`),
  KEY `seller_reviews_seller_id_status_index` (`seller_id`,`status`),
  CONSTRAINT `__DB_PREFIX__seller_reviews_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_shipping_methods`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_shipping_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `shipping_method_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'flat',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_shipping_methods_seller_id_foreign` (`seller_id`),
  CONSTRAINT `__DB_PREFIX__seller_shipping_methods_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_suppliers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_suppliers_seller_id_slug_unique` (`seller_id`,`slug`),
  KEY `__DB_PREFIX__seller_suppliers_supplier_id_index` (`supplier_id`),
  CONSTRAINT `__DB_PREFIX__seller_suppliers_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `shop_name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `store_policy` text DEFAULT NULL,
  `shipping_policy` text DEFAULT NULL,
  `return_policy` text DEFAULT NULL,
  `vacation_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__seller_translations_seller_id_language_code_unique` (`seller_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__seller_translations_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__seller_withdrawals`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__seller_withdrawals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `seller_id` bigint(20) unsigned NOT NULL,
  `seller_payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `note` text DEFAULT NULL,
  `payout_reference` varchar(80) DEFAULT NULL,
  `payout_rail` varchar(32) DEFAULT NULL,
  `processed_by` bigint(20) unsigned DEFAULT NULL,
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__seller_withdrawals_seller_id_status_index` (`seller_id`,`status`),
  KEY `__DB_PREFIX__seller_withdrawals_seller_payment_method_id_index` (`seller_payment_method_id`),
  KEY `__DB_PREFIX__seller_withdrawals_status_index` (`status`),
  CONSTRAINT `__DB_PREFIX__seller_withdrawals_seller_id_foreign` FOREIGN KEY (`seller_id`) REFERENCES `__DB_PREFIX__sellers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__sellers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__sellers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `first_name` varchar(255) NOT NULL,
  `last_name` varchar(255) NOT NULL,
  `shop_name` varchar(255) NOT NULL,
  `shop_slug` varchar(255) NOT NULL,
  `profile_name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) NOT NULL,
  `contact_name` varchar(255) NOT NULL,
  `contact_phone` varchar(255) NOT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `address_line1` varchar(255) NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(255) NOT NULL,
  `state` varchar(255) DEFAULT NULL,
  `postal_code` varchar(255) NOT NULL,
  `country_code` varchar(4) NOT NULL,
  `description` text DEFAULT NULL,
  `tax_id` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `commission_rate` decimal(8,2) DEFAULT NULL,
  `review_note` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `blocked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `facebook_url` varchar(255) DEFAULT NULL,
  `twitter_url` varchar(255) DEFAULT NULL,
  `instagram_url` varchar(255) DEFAULT NULL,
  `youtube_url` varchar(255) DEFAULT NULL,
  `banner_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `vacation_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `vacation_message` text DEFAULT NULL,
  `store_policy` text DEFAULT NULL,
  `shipping_policy` text DEFAULT NULL,
  `return_policy` text DEFAULT NULL,
  `payout_bank_account` varchar(80) DEFAULT NULL,
  `payout_bank_ifsc` varchar(32) DEFAULT NULL,
  `payout_upi_id` varchar(80) DEFAULT NULL,
  `payout_paypal_email` varchar(160) DEFAULT NULL,
  `health_score` tinyint unsigned DEFAULT NULL,
  `health_band` varchar(32) DEFAULT NULL,
  `health_cancellation_rate` decimal(8,4) DEFAULT NULL,
  `health_avg_rating` decimal(3,2) DEFAULT NULL,
  `health_flag_count` int unsigned DEFAULT NULL,
  `health_refreshed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__sellers_customer_id_unique` (`customer_id`),
  UNIQUE KEY `__DB_PREFIX__sellers_shop_slug_unique` (`shop_slug`),
  KEY `__DB_PREFIX__sellers_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__sellers_status_index` (`status`),
  KEY `__DB_PREFIX__sellers_reviewed_by_foreign` (`reviewed_by`),
  CONSTRAINT `__DB_PREFIX__sellers_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__sellers_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `__DB_PREFIX__admins` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__sessions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__sessions_user_id_index` (`user_id`),
  KEY `__DB_PREFIX__sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__wento_upgrade_log`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__wento_upgrade_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(32) NOT NULL,
  `status` varchar(32) NOT NULL DEFAULT 'applied',
  `message` text DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__wento_upgrade_log_version_status_index` (`version`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__wento_platform_meta`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__wento_platform_meta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `platform_version` varchar(32) NOT NULL DEFAULT '1.0.0',
  `schema_version` varchar(32) NOT NULL DEFAULT '1.0.0',
  `upgraded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__settings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shipment_items`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shipment_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `seller_order_item_id` bigint(20) unsigned DEFAULT NULL,
  `qty` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_items_shipment_soi_unique` (`shipment_id`,`seller_order_item_id`),
  KEY `__DB_PREFIX__shipment_items_order_item_id_index` (`order_item_id`),
  KEY `__DB_PREFIX__shipment_items_seller_order_item_id_index` (`seller_order_item_id`),
  CONSTRAINT `__DB_PREFIX__shipment_items_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `__DB_PREFIX__shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shipments`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shipments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `seller_order_id` bigint(20) unsigned DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `shipping_method_id` bigint(20) unsigned DEFAULT NULL,
  `shipping_cost` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` varchar(32) NOT NULL DEFAULT 'processing',
  `carrier` varchar(255) DEFAULT NULL,
  `tracking_number` varchar(255) DEFAULT NULL,
  `tracking_url` varchar(500) DEFAULT NULL,
  `shipped_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `shipping_address` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`shipping_address`)),
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__shipments_order_id_status_index` (`order_id`,`status`),
  KEY `__DB_PREFIX__shipments_seller_order_id_status_index` (`seller_order_id`,`status`),
  KEY `__DB_PREFIX__shipments_seller_id_index` (`seller_id`),
  CONSTRAINT `__DB_PREFIX__shipments_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__shipments_seller_order_id_foreign` FOREIGN KEY (`seller_order_id`) REFERENCES `__DB_PREFIX__seller_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shipping_method_store`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shipping_method_store` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shipping_method_id` bigint(20) unsigned NOT NULL,
  `store_id` bigint(20) unsigned NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__shipping_method_store_shipping_method_id_store_id_unique` (`shipping_method_id`,`store_id`),
  KEY `__DB_PREFIX__shipping_method_store_store_id_foreign` (`store_id`),
  CONSTRAINT `__DB_PREFIX__shipping_method_store_shipping_method_id_foreign` FOREIGN KEY (`shipping_method_id`) REFERENCES `__DB_PREFIX__shipping_methods` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__shipping_method_store_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shipping_methods`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shipping_methods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'flat',
  `driver` varchar(255) NOT NULL DEFAULT 'offline',
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`config`)),
  `conditions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`conditions`)),
  `rate_config` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`rate_config`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  `tax_class_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__shipping_methods_code_unique` (`code`),
  KEY `__DB_PREFIX__shipping_methods_seller_id_index` (`seller_id`),
  KEY `__DB_PREFIX__shipping_methods_tax_class_id_foreign` (`tax_class_id`),
  CONSTRAINT `__DB_PREFIX__shipping_methods_tax_class_id_foreign` FOREIGN KEY (`tax_class_id`) REFERENCES `__DB_PREFIX__tax_classes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shipping_zone_countries`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shipping_zone_countries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shipping_zone_id` bigint(20) unsigned NOT NULL,
  `country_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__shipping_zone_countries_shipping_zone_id_country_id_unique` (`shipping_zone_id`,`country_id`),
  KEY `__DB_PREFIX__shipping_zone_countries_country_id_index` (`country_id`),
  CONSTRAINT `__DB_PREFIX__shipping_zone_countries_shipping_zone_id_foreign` FOREIGN KEY (`shipping_zone_id`) REFERENCES `__DB_PREFIX__shipping_zones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shipping_zones`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shipping_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__shop_urls`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__shop_urls` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `scheme` varchar(8) NOT NULL DEFAULT 'http',
  `domain` varchar(255) NOT NULL,
  `port` int(10) unsigned DEFAULT NULL,
  `physical_uri` varchar(255) NOT NULL DEFAULT '/',
  `virtual_uri` varchar(255) NOT NULL DEFAULT '',
  `is_primary` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__shop_urls_store_id_foreign` (`store_id`),
  CONSTRAINT `__DB_PREFIX__shop_urls_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__sms_logs`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__sms_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `to_phone` varchar(40) NOT NULL,
  `body` varchar(480) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'logged',
  `purpose` varchar(40) DEFAULT NULL,
  `error` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__customer_stock_alerts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__customer_stock_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `product_variant_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `email` varchar(190) NOT NULL,
  `first_name` varchar(120) DEFAULT NULL,
  `last_name` varchar(120) DEFAULT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__customer_stock_alerts_email_product_variant_unique` (`email`,`product_id`,`product_variant_id`),
  KEY `__DB_PREFIX__customer_stock_alerts_notified_at_index` (`notified_at`),
  KEY `__DB_PREFIX__customer_stock_alerts_product_id_index` (`product_id`),
  KEY `__DB_PREFIX__customer_stock_alerts_customer_id_index` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__customer_stock_alerts_product_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__customer_stock_alerts_customer_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__preorder_notify_subscriptions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__preorder_notify_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__preorder_notify_product_customer_unique` (`product_id`,`customer_id`),
  KEY `__DB_PREFIX__preorder_notify_notified_at_index` (`notified_at`),
  CONSTRAINT `__DB_PREFIX__preorder_notify_product_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__preorder_notify_customer_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__stock_alerts`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__stock_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `email` varchar(190) NOT NULL,
  `notified_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__stock_alerts_product_id_email_unique` (`product_id`,`email`),
  CONSTRAINT `__DB_PREFIX__stock_alerts_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__store_groups`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__store_groups` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `share_customers` tinyint(1) NOT NULL DEFAULT 0,
  `share_orders` tinyint(1) NOT NULL DEFAULT 0,
  `share_stock` tinyint(1) NOT NULL DEFAULT 0,
  `color` varchar(32) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__store_groups_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__store_sharing_policies`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__store_sharing_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned NOT NULL,
  `family` varchar(255) NOT NULL,
  `mode` varchar(255) NOT NULL DEFAULT 'own',
  `source_store_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__store_sharing_policies_store_id_family_unique` (`store_id`,`family`),
  KEY `__DB_PREFIX__store_sharing_policies_source_store_id_foreign` (`source_store_id`),
  CONSTRAINT `__DB_PREFIX__store_sharing_policies_source_store_id_foreign` FOREIGN KEY (`source_store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__store_sharing_policies_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__stores`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__stores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_group_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(500) DEFAULT NULL,
  `theme` varchar(255) NOT NULL DEFAULT 'default',
  `theme_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`theme_settings`)),
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `color` varchar(32) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__stores_code_unique` (`code`),
  KEY `__DB_PREFIX__stores_store_group_id_foreign` (`store_group_id`),
  CONSTRAINT `__DB_PREFIX__stores_store_group_id_foreign` FOREIGN KEY (`store_group_id`) REFERENCES `__DB_PREFIX__store_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__storefront_api_keys`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__storefront_api_keys` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `key_prefix` varchar(40) NOT NULL,
  `key_hash` varchar(64) NOT NULL,
  `rate_limit` int unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__storefront_api_keys_key_hash_unique` (`key_hash`),
  KEY `__DB_PREFIX__storefront_api_keys_store_id_index` (`store_id`),
  CONSTRAINT `__DB_PREFIX__storefront_api_keys_store_id_foreign` FOREIGN KEY (`store_id`) REFERENCES `__DB_PREFIX__stores` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



DROP TABLE IF EXISTS `__DB_PREFIX__subscription_plan_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__subscription_plan_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `subscription_plan_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_translation_locale_unique` (`subscription_plan_id`,`language_code`),
  CONSTRAINT `sub_plan_tr_plan_fk` FOREIGN KEY (`subscription_plan_id`) REFERENCES `__DB_PREFIX__subscription_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__subscription_plans`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__subscription_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `trial_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `trial_duration` int(10) unsigned NOT NULL DEFAULT 0,
  `trial_cycle` int(10) unsigned NOT NULL DEFAULT 1,
  `trial_frequency` varchar(16) NOT NULL DEFAULT 'month',
  `duration` int(10) unsigned NOT NULL DEFAULT 0,
  `cycle` int(10) unsigned NOT NULL DEFAULT 1,
  `frequency` varchar(16) NOT NULL DEFAULT 'month',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__subscription_plans_is_enabled_position_index` (`is_enabled`,`position`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__supplier_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__supplier_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `meta_title` varchar(255) DEFAULT NULL,
  `meta_description` varchar(255) DEFAULT NULL,
  `meta_keywords` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__supplier_translations_supplier_id_language_code_unique` (`supplier_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__supplier_translations_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `__DB_PREFIX__suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__suppliers`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__suppliers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `facebook_url` varchar(255) DEFAULT NULL,
  `instagram_url` varchar(255) DEFAULT NULL,
  `youtube_url` varchar(255) DEFAULT NULL,
  `twitter_url` varchar(255) DEFAULT NULL,
  `linkedin_url` varchar(255) DEFAULT NULL,
  `tiktok_url` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `postcode` varchar(255) DEFAULT NULL,
  `country` varchar(255) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `seller_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__suppliers_seller_id_index` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__support_ticket_replies`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__support_ticket_replies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `support_ticket_id` bigint(20) unsigned NOT NULL,
  `admin_id` bigint(20) unsigned DEFAULT NULL,
  `author` varchar(20) NOT NULL DEFAULT 'staff',
  `body` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__support_ticket_replies_support_ticket_id_index` (`support_ticket_id`),
  KEY `__DB_PREFIX__support_ticket_replies_admin_id_index` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__support_tickets`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__support_tickets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `order_number` varchar(64) DEFAULT NULL,
  `body` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'open',
  `replied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__support_tickets_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__support_tickets_customer_id_index` (`customer_id`),
  KEY `__DB_PREFIX__support_tickets_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tag_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tag_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tag_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__tag_translations_tag_id_language_code_unique` (`tag_id`,`language_code`),
  UNIQUE KEY `__DB_PREFIX__tag_translations_language_code_slug_unique` (`language_code`,`slug`),
  CONSTRAINT `__DB_PREFIX__tag_translations_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `__DB_PREFIX__tags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tags`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tags` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tax_classes`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tax_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tax_rates`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tax_rates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tax_rules`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tax_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tax_class_id` bigint(20) unsigned NOT NULL,
  `country_id` bigint(20) unsigned DEFAULT NULL,
  `tax_zone_id` bigint(20) unsigned DEFAULT NULL,
  `tax_rate_id` bigint(20) unsigned DEFAULT NULL,
  `priority` int(10) unsigned NOT NULL DEFAULT 0,
  `is_compound` tinyint(1) NOT NULL DEFAULT 0,
  `name` varchar(255) NOT NULL,
  `rate` decimal(8,4) NOT NULL DEFAULT 0.0000,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__tax_rules_tax_class_id_foreign` (`tax_class_id`),
  KEY `__DB_PREFIX__tax_rules_country_id_foreign` (`country_id`),
  KEY `__DB_PREFIX__tax_rules_tax_zone_id_foreign` (`tax_zone_id`),
  KEY `__DB_PREFIX__tax_rules_tax_rate_id_foreign` (`tax_rate_id`),
  CONSTRAINT `__DB_PREFIX__tax_rules_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `__DB_PREFIX__countries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__tax_rules_tax_class_id_foreign` FOREIGN KEY (`tax_class_id`) REFERENCES `__DB_PREFIX__tax_classes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__tax_rules_tax_rate_id_foreign` FOREIGN KEY (`tax_rate_id`) REFERENCES `__DB_PREFIX__tax_rates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__tax_rules_tax_zone_id_foreign` FOREIGN KEY (`tax_zone_id`) REFERENCES `__DB_PREFIX__tax_zones` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tax_zone_regions`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tax_zone_regions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tax_zone_id` bigint(20) unsigned NOT NULL,
  `country_region_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__tax_zone_regions_tax_zone_id_country_region_id_unique` (`tax_zone_id`,`country_region_id`),
  KEY `__DB_PREFIX__tax_zone_regions_country_region_id_foreign` (`country_region_id`),
  CONSTRAINT `__DB_PREFIX__tax_zone_regions_country_region_id_foreign` FOREIGN KEY (`country_region_id`) REFERENCES `__DB_PREFIX__country_regions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__tax_zone_regions_tax_zone_id_foreign` FOREIGN KEY (`tax_zone_id`) REFERENCES `__DB_PREFIX__tax_zones` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__tax_zones`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__tax_zones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `country_id` bigint(20) unsigned DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `position` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__tax_zones_country_id_foreign` (`country_id`),
  CONSTRAINT `__DB_PREFIX__tax_zones_country_id_foreign` FOREIGN KEY (`country_id`) REFERENCES `__DB_PREFIX__countries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__teams`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__teams` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `color` varchar(255) NOT NULL DEFAULT 'primary',
  `description` text DEFAULT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `default_profile_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__teams_slug_unique` (`slug`),
  KEY `__DB_PREFIX__teams_default_profile_id_foreign` (`default_profile_id`),
  CONSTRAINT `__DB_PREFIX__teams_default_profile_id_foreign` FOREIGN KEY (`default_profile_id`) REFERENCES `__DB_PREFIX__profiles` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__ui_string_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__ui_string_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ui_string_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(8) NOT NULL,
  `text` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__ui_string_translations_ui_string_id_language_code_unique` (`ui_string_id`,`language_code`),
  CONSTRAINT `__DB_PREFIX__ui_string_translations_ui_string_id_foreign` FOREIGN KEY (`ui_string_id`) REFERENCES `__DB_PREFIX__ui_strings` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__ui_strings`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__ui_strings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `surface` varchar(16) NOT NULL,
  `page` varchar(80) NOT NULL,
  `key` varchar(160) NOT NULL,
  `source` text NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__ui_strings_key_unique` (`key`),
  KEY `__DB_PREFIX__ui_strings_surface_page_index` (`surface`,`page`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__url_redirects`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__url_redirects` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `language_code` varchar(12) NOT NULL,
  `from_path` varchar(255) NOT NULL,
  `to_path` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url_redirects_store_lang_from_unique` (`store_id`,`language_code`,`from_path`),
  KEY `__DB_PREFIX__url_redirects_store_id_index` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__url_rewrites`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__url_rewrites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `language_code` varchar(8) NOT NULL,
  `request_path` varchar(255) NOT NULL,
  `entity_type` varchar(255) NOT NULL,
  `entity_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `url_rewrites_store_language_path_unique` (`store_id`,`language_code`,`request_path`),
  KEY `__DB_PREFIX__url_rewrites_entity_type_entity_id_index` (`entity_type`,`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__users`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__warehouse_stocks`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__warehouse_stocks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `reserved_qty` decimal(12,3) NOT NULL DEFAULT 0.000,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__warehouse_stocks_warehouse_id_product_id_unique` (`warehouse_id`,`product_id`),
  KEY `__DB_PREFIX__warehouse_stocks_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__warehouse_stocks_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__warehouse_stocks_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `__DB_PREFIX__warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__warehouse_translations`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__warehouse_translations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `language_code` varchar(16) NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__warehouse_translations_warehouse_id_language_code_unique` (`warehouse_id`,`language_code`),
  KEY `__DB_PREFIX__warehouse_translations_language_code_index` (`language_code`),
  CONSTRAINT `__DB_PREFIX__warehouse_translations_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `__DB_PREFIX__warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__warehouses`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__warehouses` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `code` varchar(255) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(40) DEFAULT NULL,
  `fax` varchar(40) DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(120) DEFAULT NULL,
  `state` varchar(120) DEFAULT NULL,
  `postcode` varchar(32) DEFAULT NULL,
  `country` varchar(120) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__warehouses_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__webauthn_credentials`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__webauthn_credentials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `authenticatable_type` varchar(255) NOT NULL,
  `authenticatable_id` bigint(20) unsigned NOT NULL,
  `credential_id` varchar(255) NOT NULL,
  `public_key` text NOT NULL,
  `sign_count` int(10) unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__webauthn_credentials_credential_id_unique` (`credential_id`),
  KEY `webauthn_auth_idx` (`authenticatable_type`,`authenticatable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__webhook_deliveries`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__webhook_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` char(36) NOT NULL,
  `webhook_endpoint_id` bigint(20) unsigned NOT NULL,
  `commerce_event_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(120) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `status` varchar(32) NOT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `available_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `sent_at` timestamp NULL DEFAULT NULL,
  `response_code` smallint(5) unsigned DEFAULT NULL,
  `response_body` text DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__webhook_deliveries_uuid_unique` (`uuid`),
  KEY `__DB_PREFIX__webhook_deliveries_webhook_endpoint_id_foreign` (`webhook_endpoint_id`),
  KEY `__DB_PREFIX__webhook_deliveries_commerce_event_id_index` (`commerce_event_id`),
  KEY `__DB_PREFIX__webhook_deliveries_event_index` (`event`),
  KEY `__DB_PREFIX__webhook_deliveries_status_index` (`status`),
  KEY `__DB_PREFIX__webhook_deliveries_available_at_index` (`available_at`),
  CONSTRAINT `__DB_PREFIX__webhook_deliveries_webhook_endpoint_id_foreign` FOREIGN KEY (`webhook_endpoint_id`) REFERENCES `__DB_PREFIX__webhook_endpoints` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__webhook_endpoints`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__webhook_endpoints` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `url` varchar(500) NOT NULL,
  `secret` varchar(120) DEFAULT NULL,
  `secret_ciphertext` text DEFAULT NULL,
  `secret_prefix` varchar(16) DEFAULT NULL,
  `event_pattern` varchar(120) NOT NULL DEFAULT '*',
  `events` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`events`)),
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `last_status` varchar(32) DEFAULT NULL,
  `last_error` varchar(255) DEFAULT NULL,
  `consecutive_failures` int(10) unsigned NOT NULL DEFAULT 0,
  `disabled_at` timestamp NULL DEFAULT NULL,
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__webhook_endpoints_store_id_index` (`store_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__webservice_keys`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__webservice_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `store_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `key_prefix` varchar(16) NOT NULL,
  `key_hash` varchar(64) NOT NULL,
  `scopes` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`scopes`)),
  `allowed_ips` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`allowed_ips`)),
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `expires_at` timestamp NULL DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `last_ip` varchar(45) DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__webservice_keys_key_hash_unique` (`key_hash`),
  KEY `__DB_PREFIX__webservice_keys_store_id_index` (`store_id`),
  KEY `__DB_PREFIX__webservice_keys_key_prefix_index` (`key_prefix`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__wishlist_shares`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__wishlist_shares` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__wishlist_shares_token_unique` (`token`),
  KEY `__DB_PREFIX__wishlist_shares_customer_id_foreign` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__wishlist_shares_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__wishlists`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__wishlists` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__wishlists_customer_id_product_id_unique` (`customer_id`,`product_id`),
  KEY `__DB_PREFIX__wishlists_product_id_foreign` (`product_id`),
  CONSTRAINT `__DB_PREFIX__wishlists_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `__DB_PREFIX__wishlists_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `__DB_PREFIX__products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__gdpr_requests`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__gdpr_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `type` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `message` varchar(500) NOT NULL,
  `requested_changes` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `__DB_PREFIX__gdpr_requests_status_type_index` (`status`,`type`),
  CONSTRAINT `__DB_PREFIX__gdpr_requests_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__gdpr_cookie_consents`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__gdpr_cookie_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `strictly_necessary` tinyint(1) NOT NULL DEFAULT 1,
  `basic` tinyint(1) NOT NULL DEFAULT 0,
  `experience` tinyint(1) NOT NULL DEFAULT 0,
  `measurements` tinyint(1) NOT NULL DEFAULT 0,
  `targeting` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__gdpr_cookie_consents_customer_id_unique` (`customer_id`),
  CONSTRAINT `__DB_PREFIX__gdpr_cookie_consents_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `__DB_PREFIX__eu_withdrawal_requests`;
CREATE TABLE IF NOT EXISTS `__DB_PREFIX__eu_withdrawal_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `order_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(32) DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `reason` varchar(500) DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `__DB_PREFIX__eu_withdrawal_requests_reference_unique` (`reference`),
  KEY `__DB_PREFIX__eu_withdrawal_requests_status_customer_id_index` (`status`,`customer_id`),
  KEY `__DB_PREFIX__eu_withdrawal_requests_order_id_status_index` (`order_id`,`status`),
  CONSTRAINT `__DB_PREFIX__eu_withdrawal_requests_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `__DB_PREFIX__customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `__DB_PREFIX__eu_withdrawal_requests_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `__DB_PREFIX__orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET UNIQUE_CHECKS=1;
SET FOREIGN_KEY_CHECKS=1;
