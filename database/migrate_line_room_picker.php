<?php
/**
 * Migration: LINE Room Picker (ลงทะเบียนผู้เช่าแบบเลือกห้องจาก Flex Message)
 *
 * เพิ่มคอลัมน์ room_id ใน tenant_line_sessions
 * เพื่อให้ยืนยันเบอร์โทรจากห้องที่เลือกได้ตรงตัว (เลขห้องซ้ำกันข้ามอาคารได้)
 *
 * รันซ้ำได้ (idempotent)
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDB();

try {
    $col = $db->prepare("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tenant_line_sessions' AND COLUMN_NAME = 'room_id'
    ");
    $col->execute();

    if ((int)$col->fetchColumn() === 0) {
        $db->exec("ALTER TABLE `tenant_line_sessions` ADD COLUMN `room_id` INT UNSIGNED NULL AFTER `room_number`");
        echo "OK: เพิ่มคอลัมน์ room_id ใน tenant_line_sessions แล้ว\n";
    } else {
        echo "OK: คอลัมน์ room_id มีอยู่แล้ว ข้าม\n";
    }

    // ล้าง session ค้างของ flow เก่า (พิมพ์เลขห้อง) ที่ใช้ไม่ได้กับ flow ใหม่
    $db->exec("DELETE FROM `tenant_line_sessions` WHERE `step` = 'ask_room'");
    echo "OK: ล้าง session ลงทะเบียนที่ค้างอยู่แล้ว\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\nเสร็จสิ้น — ลบไฟล์นี้ออกจากเซิร์ฟเวอร์ได้หลังรัน\n";
