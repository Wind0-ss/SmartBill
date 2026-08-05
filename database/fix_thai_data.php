<?php
/**
 * Fix Thai encoding — Run this once to update Thai text in database
 * Usage: php database/fix_thai_data.php
 */
$pdo = new PDO('mysql:host=localhost;dbname=smartbill;charset=utf8mb4', 'root', '');
$pdo->exec('SET NAMES utf8mb4');

echo "Fixing Thai data...\n";

// Fix Roles
$roles = [
    ['super_admin', 'Super Admin', 'จัดการทุกองค์กร, ดูรายงานทั้งหมด, กำหนดค่าระบบ'],
    ['admin', 'Admin', 'จัดการอาคาร, ห้อง, ผู้เช่า, สร้างบิล, อนุมัติการชำระเงิน'],
    ['staff', 'พนักงาน', 'ถ่ายรูปมิเตอร์, แก้ไขตัวเลข OCR, บันทึกการชำระเงิน'],
    ['tenant', 'ผู้เช่า', 'ดูใบแจ้งหนี้, ดูประวัติ, อัปโหลดสลิป'],
];
$stmt = $pdo->prepare("UPDATE roles SET display_name = ?, description = ? WHERE name = ?");
foreach ($roles as $r) {
    $stmt->execute([$r[1], $r[2], $r[0]]);
}
echo "  Roles: OK\n";

// Fix Tenants
$tenants = [
    [1, 'สมชาย', 'ใจดี'],
    [2, 'วิภาดา', 'แสงมณี'],
    [3, 'กิตติ', 'ใจดี'],
    [4, 'นภา', 'ศรีสง่า'],
    [5, 'ธนวัฒน์', 'มั่นคง'],
    [6, 'จิราพร', 'สุขใจ'],
];
$stmt = $pdo->prepare("UPDATE tenants SET first_name = ?, last_name = ? WHERE id = ?");
foreach ($tenants as $t) {
    $stmt->execute([$t[1], $t[2], $t[0]]);
}
echo "  Tenants: OK\n";

// Fix System Settings descriptions
$settings = [
    ['water_rate', 'อัตราค่าน้ำต่อหน่วย (บาท)'],
    ['electric_rate', 'อัตราค่าไฟต่อหน่วย (บาท)'],
    ['promptpay_number', 'หมายเลข PromptPay'],
    ['promptpay_name', 'ชื่อบัญชี PromptPay'],
    ['line_channel_token', 'LINE Messaging API Channel Access Token'],
    ['line_channel_secret', 'LINE Messaging API Channel Secret'],
    ['line_webhook_url', 'LINE Webhook URL'],
    ['company_name', 'ชื่อบริษัท/หอพัก'],
    ['company_address', 'ที่อยู่'],
    ['company_phone', 'เบอร์โทรศัพท์'],
    ['bill_due_day', 'วันครบกำหนดชำระ'],
];
$stmt = $pdo->prepare("UPDATE system_settings SET description = ? WHERE setting_key = ?");
foreach ($settings as $s) {
    $stmt->execute([$s[1], $s[0]]);
}
echo "  Settings: OK\n";

// Verify
echo "\n=== Verification ===\n";
foreach ($pdo->query('SELECT first_name, last_name FROM tenants LIMIT 3') as $r) {
    echo "  Tenant: {$r['first_name']} {$r['last_name']}\n";
}
foreach ($pdo->query("SELECT display_name FROM roles WHERE name IN ('staff','tenant')") as $r) {
    echo "  Role: {$r['display_name']}\n";
}

echo "\nDone!\n";
