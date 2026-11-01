-- ============================================================
-- AutoBill Property Management — Database Schema
-- Version: 1.0
-- Engine: MySQL 8.0+ / MariaDB 10.5+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `smartbill` 
  DEFAULT CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE `smartbill`;

-- ============================================================
-- 1. ROLES — สิทธิ์ผู้ใช้งาน
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(50) NOT NULL UNIQUE COMMENT 'super_admin, admin, staff, tenant',
  `display_name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 2. USERS — ผู้ใช้งาน
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `role_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(100) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL COMMENT 'bcrypt hash',
  `full_name` VARCHAR(200) NOT NULL,
  `email` VARCHAR(200),
  `phone` VARCHAR(20),
  `avatar` VARCHAR(500),
  `is_active` TINYINT(1) DEFAULT 1,
  `last_login` DATETIME,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ============================================================
-- 3. BUILDINGS — อาคาร
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 4. FLOORS — ชั้น
-- ============================================================
CREATE TABLE IF NOT EXISTS `floors` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `building_id` INT UNSIGNED NOT NULL,
  `floor_number` INT NOT NULL,
  `name` VARCHAR(100),
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`building_id`) REFERENCES `buildings`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
-- 5. ROOMS — ห้อง
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 6. TENANTS — ผู้เช่า
-- ============================================================
CREATE TABLE IF NOT EXISTS `tenants` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT UNSIGNED,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100),
  `id_card` VARCHAR(20) COMMENT 'เลขบัตรประชาชน',
  `phone` VARCHAR(20),
  `email` VARCHAR(200),
  `line_user_id` VARCHAR(100) COMMENT 'LINE User ID',
  `emergency_contact` VARCHAR(200),
  `emergency_phone` VARCHAR(20),
  `profile_image` VARCHAR(500),
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- 7. LEASES — สัญญาเช่า
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 8. METERS — มิเตอร์
-- ============================================================
CREATE TABLE IF NOT EXISTS `meters` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT UNSIGNED NOT NULL,
  `meter_type` ENUM('water','electric') NOT NULL,
  `meter_number` VARCHAR(50) COMMENT 'เลขที่มิเตอร์',
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uq_room_meter_type` (`room_id`, `meter_type`)
) ENGINE=InnoDB;

-- ============================================================
-- 9. METER_READINGS — ประวัติการอ่านมิเตอร์
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 10. UTILITY_RATES — อัตราค่าน้ำค่าไฟ
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 11. INVOICES — ใบแจ้งหนี้
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 12. INVOICE_ITEMS — รายการในบิล
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 13. PAYMENTS — การชำระเงิน
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 14. PAYMENT_SLIPS — สลิป
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 15. NOTIFICATIONS — การแจ้งเตือน
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 16. SYSTEM_SETTINGS — ค่าตั้งค่าระบบ
-- ============================================================
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `setting_type` ENUM('string','number','boolean','json') DEFAULT 'string',
  `description` VARCHAR(300),
  `updated_by` INT UNSIGNED,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- 17. ACTIVITY_LOGS — ประวัติการใช้งานระบบ
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 18. BUILDING_METERS — มิเตอร์รวมอาคาร (Leakage Analyzer)
-- ============================================================
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
) ENGINE=InnoDB;

-- ============================================================
-- 19. EXPENSE_TYPES — ประเภทค่าใช้จ่ายเพิ่มเติม (ค่าขยะ, ค่าบำรุงมิเตอร์ ฯลฯ)
-- ============================================================
CREATE TABLE IF NOT EXISTS `expense_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL COMMENT 'ชื่อรายการ เช่น ค่าขยะ',
  `icon` VARCHAR(50) DEFAULT 'receipt_long' COMMENT 'Material Symbols icon',
  `default_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ยอดเริ่มต้นที่เติมให้อัตโนมัติ',
  `bill_group` ENUM('rent','utility') NOT NULL DEFAULT 'rent' COMMENT 'ออกรวมกับบิลค่าเช่า หรือ บิลค่าน้ำ/ไฟ',
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_expense_type_name` (`name`)
) ENGINE=InnoDB;

-- ============================================================
-- 20. ADDITIONAL_CHARGES — ค่าใช้จ่ายเพิ่มเติมที่บันทึกต่อห้อง/รอบเดือน
-- ============================================================
CREATE TABLE IF NOT EXISTS `additional_charges` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `room_id` INT UNSIGNED NOT NULL,
  `expense_type_id` INT UNSIGNED NULL COMMENT 'NULL = รายการเฉพาะกิจที่พิมพ์เอง',
  `billing_month` DATE NOT NULL COMMENT 'YYYY-MM-01',
  `description` VARCHAR(200) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `bill_group` ENUM('rent','utility') NOT NULL DEFAULT 'rent',
  `notes` VARCHAR(300),
  `recorded_by` INT UNSIGNED,
  `recorded_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`room_id`) REFERENCES `rooms`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`expense_type_id`) REFERENCES `expense_types`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- INDEXES
-- ============================================================
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
CREATE INDEX `idx_charges_room_month` ON `additional_charges`(`room_id`, `billing_month`);
CREATE INDEX `idx_charges_month` ON `additional_charges`(`billing_month`);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Roles
INSERT INTO `roles` (`id`, `name`, `display_name`, `description`) VALUES
(1, 'super_admin', 'Super Admin', 'จัดการทุกองค์กร, ดูรายงานทั้งหมด, กำหนดค่าระบบ'),
(2, 'admin', 'Admin', 'จัดการอาคาร, ห้อง, ผู้เช่า, สร้างบิล, อนุมัติการชำระเงิน'),
(3, 'staff', 'พนักงาน', 'ถ่ายรูปมิเตอร์, แก้ไขตัวเลข OCR, บันทึกการชำระเงิน'),
(4, 'tenant', 'ผู้เช่า', 'ดูใบแจ้งหนี้, ดูประวัติ, อัปโหลดสลิป');

-- Default Admin User (password: admin123)
INSERT INTO `users` (`role_id`, `username`, `password`, `full_name`, `email`) VALUES
(1, 'admin', '$2y$10$2fUyiPzcK7gST.RCqlogC.y1WKEM86PJ/kLoFpZ.2u8tJEe7kILhi', 'System Administrator', 'admin@smartbill.io');

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
('invoice_prefix', 'INV', 'string', 'คำนำหน้าเลขที่บิล');

-- Demo Building + Rooms (for testing)
INSERT INTO `buildings` (`id`, `name`, `address`, `total_floors`, `created_by`) VALUES
(1, 'Building A', '123 ถ.สุขุมวิท กรุงเทพฯ 10110', 5, 1);

INSERT INTO `floors` (`building_id`, `floor_number`, `name`) VALUES
(1, 1, 'ชั้น 1'), (1, 2, 'ชั้น 2'), (1, 3, 'ชั้น 3'),
(1, 4, 'ชั้น 4'), (1, 5, 'ชั้น 5');

INSERT INTO `rooms` (`building_id`, `floor_id`, `room_number`, `base_rent`, `other_fees`, `status`) VALUES
(1, 1, '101', 5500.00, 300.00, 'occupied'),
(1, 1, '102', 5500.00, 300.00, 'occupied'),
(1, 2, '201', 6200.00, 300.00, 'occupied'),
(1, 2, '202', 6000.00, 0.00, 'vacant'),
(1, 3, '301', 6500.00, 650.00, 'occupied'),
(1, 3, '302', 6500.00, 650.00, 'vacant'),
(1, 4, '401', 7000.00, 650.00, 'occupied'),
(1, 4, '402', 7000.00, 650.00, 'occupied');

INSERT INTO `tenants` (`room_id`, `first_name`, `last_name`, `phone`, `line_user_id`, `is_active`) VALUES
(1, 'สมชาย', 'รักดี', '081-234-5678', NULL, 1),
(2, 'วิภาดา', 'แสงมณี', '089-987-6543', NULL, 1),
(3, 'กิตติ', 'ใจดี', '086-555-4321', 'U1234567890abcdef', 1),
(5, 'นภา', 'ศรีสง่า', '082-111-2233', NULL, 1),
(7, 'ธนวัฒน์', 'มั่นคง', '091-444-5566', 'U0987654321fedcba', 1),
(8, 'จิราพร', 'สุขใจ', '095-777-8899', NULL, 1);

-- Create leases for active tenants
INSERT INTO `leases` (`tenant_id`, `room_id`, `start_date`, `end_date`, `deposit_amount`, `monthly_rent`, `status`) VALUES
(1, 1, '2024-01-15', '2025-01-14', 11000.00, 5500.00, 'active'),
(2, 2, '2024-03-01', '2025-02-28', 11000.00, 5500.00, 'active'),
(3, 3, '2024-06-01', '2025-05-31', 12400.00, 6200.00, 'active'),
(4, 5, '2024-02-01', '2025-01-31', 13000.00, 6500.00, 'active'),
(5, 7, '2024-04-01', '2025-03-31', 14000.00, 7000.00, 'active'),
(6, 8, '2024-07-01', '2025-06-30', 14000.00, 7000.00, 'active');

-- Create meters for occupied rooms
INSERT INTO `meters` (`room_id`, `meter_type`, `meter_number`) VALUES
(1, 'water', 'W-101'), (1, 'electric', 'E-101'),
(2, 'water', 'W-102'), (2, 'electric', 'E-102'),
(3, 'water', 'W-201'), (3, 'electric', 'E-201'),
(5, 'water', 'W-301'), (5, 'electric', 'E-301'),
(7, 'water', 'W-401'), (7, 'electric', 'E-401'),
(8, 'water', 'W-402'), (8, 'electric', 'E-402');

-- Default utility rates
INSERT INTO `utility_rates` (`rate_type`, `building_id`, `rate_per_unit`, `effective_date`) VALUES
('water', NULL, 18.00, '2024-01-01'),
('electric', NULL, 7.50, '2024-01-01');


-- Default expense types (ค่าใช้จ่ายเพิ่มเติม) — ยอดตั้งต้น 0 ให้ผู้ดูแลกำหนดเองที่หน้าตั้งค่า
INSERT INTO `expense_types` (`name`, `icon`, `default_amount`, `bill_group`, `sort_order`) VALUES
('ค่าขยะ', 'delete', 0.00, 'rent', 1),
('ค่าบำรุงมิเตอร์', 'build', 0.00, 'utility', 2),
('ค่าส่วนกลาง', 'apartment', 0.00, 'rent', 3),
('ค่าอินเทอร์เน็ต', 'wifi', 0.00, 'rent', 4),
('ค่าที่จอดรถ', 'local_parking', 0.00, 'rent', 5);
SET FOREIGN_KEY_CHECKS = 1;
