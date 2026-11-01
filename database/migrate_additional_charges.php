<?php
/**
 * Migration: Additional Charges (ค่าใช้จ่ายเพิ่มเติม)
 *
 * เพิ่ม 2 ตาราง
 *   - expense_types      : ประเภทค่าใช้จ่ายเพิ่มเติม (ค่าขยะ, ค่าบำรุงมิเตอร์, ...)
 *   - additional_charges : รายการที่บันทึกจริง แยกตามห้อง + รอบเดือน
 *
 * รันซ้ำได้ (idempotent)
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDB();

// 1. ประเภทค่าใช้จ่าย
try {
    $db->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK: table `expense_types` พร้อมใช้งาน\n";
} catch (PDOException $e) {
    echo "Error (expense_types): " . $e->getMessage() . "\n";
}

// 2. รายการค่าใช้จ่ายที่บันทึกต่อห้อง/เดือน
try {
    $db->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK: table `additional_charges` พร้อมใช้งาน\n";
} catch (PDOException $e) {
    echo "Error (additional_charges): " . $e->getMessage() . "\n";
}

// 3. ประเภทเริ่มต้น — ยอดตั้งต้นเป็น 0 ให้ผู้ดูแลกำหนดเองที่หน้าตั้งค่า
try {
    $seed = $db->prepare("
        INSERT INTO `expense_types` (`name`, `icon`, `default_amount`, `bill_group`, `sort_order`)
        VALUES (?, ?, 0.00, ?, ?)
        ON DUPLICATE KEY UPDATE `id` = `id`
    ");
    $defaults = [
        ['ค่าขยะ',            'delete',         'rent',    1],
        ['ค่าบำรุงมิเตอร์',    'build',          'utility', 2],
        ['ค่าส่วนกลาง',       'apartment',      'rent',    3],
        ['ค่าอินเทอร์เน็ต',    'wifi',           'rent',    4],
        ['ค่าที่จอดรถ',        'local_parking',  'rent',    5],
    ];
    foreach ($defaults as $d) {
        $seed->execute([$d[0], $d[1], $d[2], $d[3]]);
    }
    echo "OK: เพิ่มประเภทค่าใช้จ่ายเริ่มต้น " . count($defaults) . " รายการ\n";
} catch (PDOException $e) {
    echo "Error (seed): " . $e->getMessage() . "\n";
}

echo "\nMigration เสร็จสมบูรณ์\n";
