-- ============================================================
-- SmartBill Property Management — Production Database Schema
-- Version: 1.1 (Includes LIFF, LINE Login, & Separated Bills updates)
-- Engine: MySQL 8.0+ / MariaDB 10.5+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Table structure for `roles`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE COMMENT 'super_admin, admin, staff, tenant',
  `display_name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `users`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
  `full_name` VARCHAR(200) NOT NULL,
  `email` VARCHAR(200),
  `phone` VARCHAR(20),
  `avatar` VARCHAR(500),
  `line_user_id` VARCHAR(100) UNIQUE NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login` DATETIME,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `admin_invitations`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_invitations` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `token` VARCHAR(64) NOT NULL UNIQUE,
  `role_id` INT UNSIGNED NOT NULL DEFAULT 2 COMMENT '2=admin, 3=staff',
  `is_used` TINYINT(1) DEFAULT 0,
  `used_by` INT UNSIGNED NULL,
  `expires_at` DATETIME NOT NULL,
  `created_by` INT UNSIGNED NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`used_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `buildings`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `buildings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(200) NOT NULL,
  `address` TEXT,
  `total_floors` INT UNSIGNED DEFAULT 1,
  `description` TEXT,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_by` INT UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `floors`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `floors` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `building_id` INT UNSIGNED NOT NULL,
  `floor_number` INT NOT NULL,
  `name` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `rooms`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rooms` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `building_id` INT UNSIGNED NOT NULL,
  `floor_id` INT UNSIGNED,
  `room_number` VARCHAR(20) NOT NULL,
  `room_type` ENUM('standard','deluxe','suite') DEFAULT 'standard',
  `base_rent` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่าเช่ารายเดือน',
  `other_fees` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'ค่าบริการอื่นๆ (เน็ต, จอดรถ)',
  `status` ENUM('vacant','occupied','maintenance','reserved') DEFAULT 'vacant',
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`floor_id`) REFERENCES `floors`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_building_room` (`building_id`, `room_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `tenants`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT UNSIGNED,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100),
  `id_card` VARCHAR(20) COMMENT 'เลขบัตรประชาชน',
  `phone` VARCHAR(20),
  `email` VARCHAR(200),
  `line_user_id` VARCHAR(100) COMMENT 'LINE User ID',
  `registration_token` VARCHAR(100) NULL,
  `emergency_contact` VARCHAR(200),
  `emergency_phone` VARCHAR(20),
  `profile_image` VARCHAR(500),
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `tenant_line_sessions`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenant_line_sessions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `line_user_id` VARCHAR(100) NOT NULL,
  `step` ENUM('ask_room','ask_phone','done') DEFAULT 'ask_room',
  `room_number` VARCHAR(20),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_line_user` (`line_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `leases`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leases` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `start_date` DATE NOT NULL,
  `end_date` DATE,
  `deposit_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'เงินประกัน',
  `monthly_rent` DECIMAL(10,2) NOT NULL COMMENT 'ค่าเช่ารายเดือน ณ เวลาทำสัญญา',
  `status` ENUM('active','expired','terminated') DEFAULT 'active',
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `meters`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meters` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT UNSIGNED NOT NULL,
  `meter_type` ENUM('water','electric') NOT NULL,
  `meter_number` VARCHAR(50) COMMENT 'เลขที่มิเตอร์',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_room_meter_type` (`room_id`, `meter_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `meter_readings`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meter_readings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `meter_id` INT UNSIGNED NOT NULL,
  `room_id` INT UNSIGNED NOT NULL,
  `billing_month` DATE NOT NULL COMMENT 'YYYY-MM-01 (เดือนที่เก็บค่า)',
  `previous_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `current_reading` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `consumption` DECIMAL(12,2) GENERATED ALWAYS AS (`current_reading` - `previous_reading`) STORED,
  `unit_price` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `total_cost` DECIMAL(10,2) GENERATED ALWAYS AS ((`current_reading` - `previous_reading`) * `unit_price`) STORED,
  `image_path` VARCHAR(500) COMMENT 'รูปมิเตอร์',
  `ocr_confidence` DECIMAL(5,2) COMMENT 'ค่าความมั่นใจ OCR (%)',
  `ocr_raw_value` VARCHAR(50) COMMENT 'ค่าที่ OCR อ่านได้',
  `recorded_by` INT UNSIGNED,
  `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`meter_id`) REFERENCES `meters`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_meter_month` (`meter_id`, `billing_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `utility_rates`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `utility_rates` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `rate_type` ENUM('water','electric') NOT NULL,
  `building_id` INT UNSIGNED COMMENT 'NULL = ค่าเริ่มต้นทั้งระบบ',
  `room_id` INT UNSIGNED COMMENT 'NULL = ใช้ค่าของอาคาร',
  `rate_per_unit` DECIMAL(8,2) NOT NULL,
  `min_units` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'หน่วยเริ่มต้น (สำหรับหลายเรท)',
  `max_units` DECIMAL(10,2) DEFAULT 999999.00 COMMENT 'หน่วยสูงสุด (สำหรับหลายเรท)',
  `effective_date` DATE NOT NULL COMMENT 'วันที่เริ่มใช้อัตรานี้',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `invoices`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoices` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_number` VARCHAR(30) NOT NULL UNIQUE COMMENT 'INV-YYYYMM-XXXX',
  `room_id` INT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `billing_month` DATE NOT NULL COMMENT 'YYYY-MM-01',
  `invoice_type` ENUM('rent','utility') NOT NULL DEFAULT 'rent',
  `subtotal` DECIMAL(10,2) DEFAULT 0.00,
  `discount` DECIMAL(10,2) DEFAULT 0.00,
  `penalty` DECIMAL(10,2) DEFAULT 0.00 COMMENT 'ค่าปรับ',
  `total_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `due_date` DATE,
  `status` ENUM('draft','sent','paid','overdue','cancelled') DEFAULT 'draft',
  `notes` TEXT,
  `pdf_path` VARCHAR(500),
  `sent_via_line` TINYINT(1) DEFAULT 0,
  `sent_at` DATETIME,
  `created_by` INT UNSIGNED,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `invoice_items`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `item_type` ENUM('rent','water','electric','internet','parking','common_fee','penalty','discount','other') NOT NULL,
  `description` VARCHAR(300) NOT NULL,
  `quantity` DECIMAL(10,2) DEFAULT 1.00,
  `unit_price` DECIMAL(10,2) DEFAULT 0.00,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` INT DEFAULT 0,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `payments`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT UNSIGNED NOT NULL,
  `tenant_id` INT UNSIGNED NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `payment_method` ENUM('bank_transfer','promptpay','cash','other') DEFAULT 'bank_transfer',
  `payment_date` DATETIME,
  `reference_number` VARCHAR(100),
  `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` INT UNSIGNED,
  `approved_at` DATETIME,
  `reject_reason` TEXT,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `payment_slips`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payment_slips` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `payment_id` INT UNSIGNED NOT NULL,
  `image_path` VARCHAR(500) NOT NULL,
  `ocr_amount` DECIMAL(10,2) COMMENT 'ยอดเงินที่ OCR อ่านได้',
  `ocr_date` DATETIME COMMENT 'วันเวลาที่ OCR อ่านได้',
  `ocr_account` VARCHAR(100) COMMENT 'เลขบัญชีที่ OCR อ่านได้',
  `ocr_confidence` DECIMAL(5,2),
  `verification_status` ENUM('pending','matched','mismatched') DEFAULT 'pending',
  `uploaded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `notifications`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tenant_id` INT UNSIGNED,
  `invoice_id` INT UNSIGNED,
  `channel` ENUM('line','email','sms') DEFAULT 'line',
  `type` ENUM('bill_sent','reminder','overdue','payment_confirmed','receipt') NOT NULL,
  `message` TEXT,
  `status` ENUM('pending','sent','failed') DEFAULT 'pending',
  `sent_at` DATETIME,
  `error_message` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`tenant_id`) REFERENCES `tenants`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `system_settings`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `setting_type` ENUM('string','number','boolean','json') DEFAULT 'string',
  `description` VARCHAR(300),
  `updated_by` INT UNSIGNED,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `activity_logs`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT UNSIGNED,
  `action` VARCHAR(200) NOT NULL,
  `entity_type` VARCHAR(50) COMMENT 'e.g. room, tenant, invoice',
  `entity_id` INT UNSIGNED,
  `details` JSON,
  `ip_address` VARCHAR(45),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Table structure for `building_meters`
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `building_meters` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `building_id` INT UNSIGNED NOT NULL,
  `billing_month` DATE NOT NULL,
  `total_electric_kwh` DECIMAL(12,2) DEFAULT 0.00,
  `total_water_m3` DECIMAL(12,2) DEFAULT 0.00,
  `recorded_by` INT UNSIGNED,
  `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  UNIQUE KEY `uq_building_month` (`building_id`, `billing_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Indexes
-- ------------------------------------------------------------
CREATE INDEX `idx_rooms_status` ON `rooms`(`status`);
CREATE INDEX `idx_rooms_building` ON `rooms`(`building_id`);
CREATE INDEX `idx_tenants_room` ON `tenants`(`room_id`);
CREATE INDEX `idx_tenants_phone` ON `tenants`(`phone`);
CREATE INDEX `idx_invoices_status` ON `invoices`(`status`);
CREATE INDEX `idx_invoices_month` ON `invoices`(`billing_month`);
CREATE INDEX `idx_invoices_room` ON `invoices`(`room_id`);
CREATE INDEX `idx_payments_status` ON `payments`(`status`);
CREATE INDEX `idx_meter_readings_month` ON `meter_readings`(`billing_month`);
CREATE INDEX `idx_notifications_tenant` ON `notifications`(`tenant_id`);
CREATE INDEX `idx_activity_logs_user` ON `activity_logs`(`user_id`);
CREATE INDEX `idx_activity_logs_entity` ON `activity_logs`(`entity_type`, `entity_id`);

-- ============================================================
-- SEED DATA (Default Admin, Roles, & System Settings)
-- ============================================================

-- Roles
INSERT INTO `roles` (`id`, `name`, `display_name`, `description`) VALUES
(1, 'super_admin', 'Super Admin', 'จัดการทุกองค์กร, ดูรายงานทั้งหมด, กำหนดค่าระบบ'),
(2, 'admin', 'Admin', 'จัดการอาคาร, ห้อง, ผู้เช่า, สร้างบิล, อนุมัติการชำระเงิน'),
(3, 'staff', 'พนักงาน', 'ถ่ายรูปมิเตอร์, แก้ไขตัวเลข OCR, บันทึกการชำระเงิน'),
(4, 'tenant', 'ผู้เช่า', 'ดูใบแจ้งหนี้, ดูประวัติ, อัปโหลดสลิป')
ON DUPLICATE KEY UPDATE display_name=VALUES(display_name), description=VALUES(description);

-- Default Admin User (username: admin / password: admin123)
INSERT INTO `users` (`id`, `role_id`, `username`, `password`, `full_name`, `email`, `is_active`) VALUES
(1, 1, 'admin', '$2y$10$2fUyiPzcK7gST.RCqlogC.y1WKEM86PJ/kLoFpZ.2u8tJEe7kILhi', 'System Administrator', 'admin@smartbill.io', 1)
ON DUPLICATE KEY UPDATE username=VALUES(username), password=VALUES(password), full_name=VALUES(full_name);

-- Default System Settings
INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`) VALUES
('water_rate', '18.00', 'number', 'อัตราค่าน้ำต่อหน่วย (บาท)'),
('electric_rate', '7.50', 'number', 'อัตราค่าไฟต่อหน่วย (บาท)'),
('promptpay_number', '', 'string', 'หมายเลข PromptPay'),
('promptpay_name', '', 'string', 'ชื่อบัญชี PromptPay'),
('line_channel_token', '', 'string', 'LINE Messaging API Channel Access Token'),
('line_channel_secret', '', 'string', 'LINE Messaging API Channel Secret'),
('line_webhook_url', '', 'string', 'LINE Webhook URL'),
('company_name', 'Smart Bill', 'string', 'ชื่อบริษัท/หอพัก'),
('company_address', '', 'string', 'ที่อยู่'),
('company_phone', '', 'string', 'เบอร์โทรศัพท์'),
('bill_due_day', '5', 'number', 'วันครบกำหนดชำระ (วันที่ของเดือน)'),
('late_fee_percent', '0', 'number', 'ค่าปรับล่าช้า (%)'),
('invoice_prefix', 'INV', 'string', 'คำนำหน้าเลขที่บิล'),
('liff_id', '', 'string', 'LINE LIFF App ID'),
('line_login_channel_id', '', 'string', 'LINE Login Channel ID'),
('line_login_channel_secret', '', 'string', 'LINE Login Channel Secret')
ON DUPLICATE KEY UPDATE description=VALUES(description);

-- Default Utility Rates
INSERT INTO `utility_rates` (`rate_type`, `building_id`, `rate_per_unit`, `effective_date`) VALUES
('water', NULL, 18.00, '2024-01-01'),
('electric', NULL, 7.50, '2024-01-01')
ON DUPLICATE KEY UPDATE rate_per_unit=VALUES(rate_per_unit);

SET FOREIGN_KEY_CHECKS = 1;
