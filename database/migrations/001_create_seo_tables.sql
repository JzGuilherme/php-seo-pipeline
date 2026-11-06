-- Migration: 001_create_seo_tables.sql
-- Engine: InnoDB (Required for row-level locking and SELECT ... FOR UPDATE SKIP LOCKED in MySQL 8.0+)

SET NAMES utf8mb4;
SET TIME_ZONE = '+00:00';

CREATE TABLE IF NOT EXISTS `seo_job_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_product_id` VARCHAR(64) NOT NULL COMMENT 'SKU or platform-specific product ID',
    `platform` ENUM('woocommerce', 'magento') NOT NULL COMMENT 'Target e-commerce platform',
    `payload` JSON NOT NULL COMMENT 'Serialized ProductInputDTO payload',
    `input_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 hash of normalized input for idempotency',
    `status` ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
    `attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 5,
    `next_retry_at` DATETIME NULL DEFAULT NULL COMMENT 'Scheduled timestamp for exponential backoff retry',
    `error_message` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_product_hash` (`external_product_id`, `platform`, `input_hash`),
    INDEX `idx_queue_poll` (`status`, `next_retry_at`, `attempts`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `seo_ai_generations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `external_product_id` VARCHAR(64) NOT NULL,
    `platform` ENUM('woocommerce', 'magento') NOT NULL,
    `product_name` VARCHAR(255) NOT NULL,
    `meta_title` VARCHAR(70) NOT NULL,
    `meta_description` VARCHAR(170) NOT NULL,
    `focus_keyword` VARCHAR(100) NOT NULL,
    `product_description` TEXT NOT NULL,
    `faqs` JSON NOT NULL COMMENT 'Array of structured Q&A objects',
    `faq_schema_jsonld` JSON NOT NULL COMMENT 'Schema.org FAQPage JSON-LD structure',
    `prompt_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `completion_tokens` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('pending_review', 'approved', 'published') NOT NULL DEFAULT 'pending_review',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_product_platform` (`external_product_id`, `platform`),
    INDEX `idx_generation_status` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
