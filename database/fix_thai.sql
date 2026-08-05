-- Fix Thai data seed
-- Run this after schema.sql import

SET NAMES utf8mb4;
USE smartbill;

-- Fix roles display names
UPDATE roles SET display_name = 'Super Admin', description = 'จัดการทุกองค์กร, ดูรายงานทั้งหมด, กำหนดค่าระบบ' WHERE name = 'super_admin';
UPDATE roles SET display_name = 'Admin', description = 'จัดการอาคาร, ห้อง, ผู้เช่า, สร้างบิล, อนุมัติการชำระเงิน' WHERE name = 'admin';
UPDATE roles SET display_name = 'พนักงาน', description = 'ถ่ายรูปมิเตอร์, แก้ไขตัวเลข OCR, บันทึกการชำระเงิน' WHERE name = 'staff';
UPDATE roles SET display_name = 'ผู้เช่า', description = 'ดูใบแจ้งหนี้, ดูประวัติ, อัปโหลดสลิป' WHERE name = 'tenant';

-- Fix tenants (Thai names)
UPDATE tenants SET first_name = 'สมชาย', last_name = 'ใจดี' WHERE id = 1;
UPDATE tenants SET first_name = 'วิภาดา', last_name = 'แสงมณี' WHERE id = 2;
UPDATE tenants SET first_name = 'กิตติ', last_name = 'ใจดี' WHERE id = 3;
UPDATE tenants SET first_name = 'นภา', last_name = 'ศรีสง่า' WHERE id = 4;
UPDATE tenants SET first_name = 'ธนวัฒน์', last_name = 'มั่นคง' WHERE id = 5;
UPDATE tenants SET first_name = 'จิราพร', last_name = 'สุขใจ' WHERE id = 6;

-- Fix settings descriptions
UPDATE system_settings SET description = 'อัตราค่าน้ำต่อหน่วย (บาท)' WHERE setting_key = 'water_rate';
UPDATE system_settings SET description = 'อัตราค่าไฟต่อหน่วย (บาท)' WHERE setting_key = 'electric_rate';
UPDATE system_settings SET description = 'หมายเลข PromptPay' WHERE setting_key = 'promptpay_number';
UPDATE system_settings SET description = 'ชื่อบัญชี PromptPay' WHERE setting_key = 'promptpay_name';
UPDATE system_settings SET description = 'LINE Messaging API Channel Access Token' WHERE setting_key = 'line_channel_token';
UPDATE system_settings SET description = 'LINE Messaging API Channel Secret' WHERE setting_key = 'line_channel_secret';
UPDATE system_settings SET description = 'LINE Webhook URL' WHERE setting_key = 'line_webhook_url';
UPDATE system_settings SET description = 'ชื่อบริษัท/หอพัก' WHERE setting_key = 'company_name';
UPDATE system_settings SET description = 'ที่อยู่' WHERE setting_key = 'company_address';
UPDATE system_settings SET description = 'เบอร์โทรศัพท์' WHERE setting_key = 'company_phone';
UPDATE system_settings SET description = 'วันครบกำหนดชำระ' WHERE setting_key = 'bill_due_day';
