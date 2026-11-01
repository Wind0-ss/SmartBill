-- ============================================================
-- SmartBill — Migration: ค่าใช้จ่ายเพิ่มเติม (Additional Charges)
-- ============================================================
-- ใช้แทน migrate_additional_charges.php กรณีเปิดผ่านเบราว์เซอร์ไม่ได้
-- (.htaccess บล็อกโฟลเดอร์ database/ ไว้ด้วยเหตุผลด้านความปลอดภัย)
--
-- วิธีใช้: cPanel > phpMyAdmin > เลือกฐานข้อมูลของ SmartBill > แท็บ SQL
--          วางทั้งไฟล์นี้ แล้วกด Go
--
-- รันซ้ำได้ ไม่ทำให้ข้อมูลเดิมเสียหาย (idempotent)
-- ไม่มีการแก้ไขหรือลบตารางเดิม
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- 1. ประเภทค่าใช้จ่ายเพิ่มเติม (ค่าขยะ, ค่าบำรุงมิเตอร์ ฯลฯ)
-- ------------------------------------------------------------
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. รายการค่าใช้จ่ายที่บันทึกจริง แยกตามห้อง + รอบเดือน
-- ------------------------------------------------------------
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
  FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  KEY `idx_charges_room_month` (`room_id`, `billing_month`),
  KEY `idx_charges_month` (`billing_month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. ประเภทเริ่มต้น — ยอดตั้งต้น 0 ให้ผู้ดูแลกำหนดเองที่หน้าตั้งค่า
--    ON DUPLICATE KEY UPDATE ทำให้รันซ้ำแล้วไม่สร้างซ้ำ
-- ------------------------------------------------------------
INSERT INTO `expense_types` (`name`, `icon`, `default_amount`, `bill_group`, `sort_order`) VALUES
('ค่าขยะ',          'delete',        0.00, 'rent',    1),
('ค่าบำรุงมิเตอร์',  'build',         0.00, 'utility', 2),
('ค่าส่วนกลาง',     'apartment',     0.00, 'rent',    3),
('ค่าอินเทอร์เน็ต',  'wifi',          0.00, 'rent',    4),
('ค่าที่จอดรถ',      'local_parking', 0.00, 'rent',    5)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ------------------------------------------------------------
-- ตรวจผลลัพธ์
-- ------------------------------------------------------------
SELECT `id`, `name`, `bill_group`, `default_amount`, `is_active` FROM `expense_types` ORDER BY `sort_order`;
