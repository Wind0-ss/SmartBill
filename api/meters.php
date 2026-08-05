<?php
/**
 * Meters API — Read/Save meter readings
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLoginAPI();

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'get_readings':
        $roomId = (int)($_GET['room_id'] ?? 0);
        $billingMonth = $_GET['billing_month'] ?? date('Y-m-01');
        
        // Get previous month
        $prevMonth = date('Y-m-01', strtotime($billingMonth . ' -1 month'));
        
        // Water previous
        $waterPrev = $db->prepare("
            SELECT mr.current_reading 
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.room_id = ? AND m.meter_type = 'water' AND mr.billing_month = ?
        ");
        $waterPrev->execute([$roomId, $prevMonth]);
        $waterPrevReading = $waterPrev->fetchColumn() ?: 0;
        
        // Electric previous
        $electricPrev = $db->prepare("
            SELECT mr.current_reading 
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.room_id = ? AND m.meter_type = 'electric' AND mr.billing_month = ?
        ");
        $electricPrev->execute([$roomId, $prevMonth]);
        $electricPrevReading = $electricPrev->fetchColumn() ?: 0;
        
        // Check if current month already recorded
        $existing = $db->prepare("
            SELECT mr.*, m.meter_type
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.room_id = ? AND mr.billing_month = ?
        ");
        $existing->execute([$roomId, $billingMonth]);
        $existingReadings = $existing->fetchAll();
        
        jsonResponse([
            'success' => true,
            'water_previous' => (float)$waterPrevReading,
            'electric_previous' => (float)$electricPrevReading,
            'existing' => $existingReadings,
            'water_rate' => getSetting('water_rate', 18.00),
            'electric_rate' => getSetting('electric_rate', 7.50)
        ]);
        break;

    case 'save':
        $roomId = (int)($_POST['room_id'] ?? 0);
        $billingMonth = $_POST['billing_month'] ?? date('Y-m-01');
        $waterReading = (float)($_POST['water_reading'] ?? 0);
        $electricReading = (float)($_POST['electric_reading'] ?? 0);
        
        if ($roomId <= 0) jsonResponse(['success' => false, 'message' => 'Invalid room'], 400);
        
        $waterRate = getSetting('water_rate', 18.00);
        $electricRate = getSetting('electric_rate', 7.50);
        $prevMonth = date('Y-m-01', strtotime($billingMonth . ' -1 month'));
        
        $db->beginTransaction();
        try {
            // Handle photos
            $waterPhotoPath = null;
            $electricPhotoPath = null;
            
            if (!empty($_FILES['water_photo']['tmp_name'])) {
                $upload = uploadFile($_FILES['water_photo'], METER_UPLOAD_DIR);
                if ($upload['success']) $waterPhotoPath = 'uploads/meters/' . $upload['filename'];
            }
            if (!empty($_FILES['electric_photo']['tmp_name'])) {
                $upload = uploadFile($_FILES['electric_photo'], METER_UPLOAD_DIR);
                if ($upload['success']) $electricPhotoPath = 'uploads/meters/' . $upload['filename'];
            }
            
            // Save water reading
            if ($waterReading > 0) {
                $waterMeter = $db->prepare("SELECT id FROM meters WHERE room_id = ? AND meter_type = 'water'");
                $waterMeter->execute([$roomId]);
                $waterMeterId = $waterMeter->fetchColumn();
                
                if ($waterMeterId) {
                    $prev = $db->prepare("SELECT current_reading FROM meter_readings WHERE meter_id = ? AND billing_month = ?");
                    $prev->execute([$waterMeterId, $prevMonth]);
                    $waterPrev = $prev->fetchColumn() ?: 0;
                    
                    $db->prepare("
                        INSERT INTO meter_readings (meter_id, room_id, billing_month, previous_reading, current_reading, unit_price, image_path, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE current_reading = VALUES(current_reading), unit_price = VALUES(unit_price), 
                                                image_path = COALESCE(VALUES(image_path), image_path), recorded_by = VALUES(recorded_by)
                    ")->execute([$waterMeterId, $roomId, $billingMonth, $waterPrev, $waterReading, $waterRate, $waterPhotoPath, $_SESSION['user_id']]);
                }
            }
            
            // Save electric reading
            if ($electricReading > 0) {
                $electricMeter = $db->prepare("SELECT id FROM meters WHERE room_id = ? AND meter_type = 'electric'");
                $electricMeter->execute([$roomId]);
                $electricMeterId = $electricMeter->fetchColumn();
                
                if ($electricMeterId) {
                    $prev = $db->prepare("SELECT current_reading FROM meter_readings WHERE meter_id = ? AND billing_month = ?");
                    $prev->execute([$electricMeterId, $prevMonth]);
                    $electricPrev = $prev->fetchColumn() ?: 0;
                    
                    $db->prepare("
                        INSERT INTO meter_readings (meter_id, room_id, billing_month, previous_reading, current_reading, unit_price, image_path, recorded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE current_reading = VALUES(current_reading), unit_price = VALUES(unit_price),
                                                image_path = COALESCE(VALUES(image_path), image_path), recorded_by = VALUES(recorded_by)
                    ")->execute([$electricMeterId, $roomId, $billingMonth, $electricPrev, $electricReading, $electricRate, $electricPhotoPath, $_SESSION['user_id']]);
                }
            }
            
            // Auto-generate invoice
            $room = $db->prepare("SELECT r.*, CONCAT(t.first_name,' ',COALESCE(t.last_name,'')) as tenant_name, t.id as tenant_id 
                                  FROM rooms r LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1 WHERE r.id = ?");
            $room->execute([$roomId]);
            $roomInfo = $room->fetch();
            
            if ($roomInfo && $roomInfo['tenant_id']) {
                $waterConsumption = max(0, $waterReading - ($waterPrev ?? 0));
                $electricConsumption = max(0, $electricReading - ($electricPrev ?? 0));
                $waterCost = $waterConsumption * $waterRate;
                $electricCost = $electricConsumption * $electricRate;
                
                $dueDay = getSetting('bill_due_day', 5);
                $dueDate = date('Y-m-' . str_pad($dueDay, 2, '0', STR_PAD_LEFT), strtotime($billingMonth . ' +1 month'));
                
                // 1. Rent Invoice (Rent + Other fees)
                $rentAmount = $roomInfo['base_rent'] + $roomInfo['other_fees'];
                if ($rentAmount > 0) {
                    $invoiceNumber = generateInvoiceNumber($billingMonth, 'rent');
                    
                    // Check existing rent invoice
                    $existingInv = $db->prepare("SELECT id FROM invoices WHERE room_id = ? AND billing_month = ? AND invoice_type = 'rent'");
                    $existingInv->execute([$roomId, $billingMonth]);
                    $existingInvId = $existingInv->fetchColumn();
                    
                    if ($existingInvId) {
                        $db->prepare("UPDATE invoices SET total_amount = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$rentAmount, $existingInvId]);
                        $rentInvId = $existingInvId;
                        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$rentInvId]);
                    } else {
                        $db->prepare("
                            INSERT INTO invoices (invoice_number, room_id, tenant_id, billing_month, invoice_type, total_amount, due_date, status, created_by)
                            VALUES (?, ?, ?, ?, 'rent', ?, ?, 'draft', ?)
                        ")->execute([$invoiceNumber, $roomId, $roomInfo['tenant_id'], $billingMonth, $rentAmount, $dueDate, $_SESSION['user_id']]);
                        $rentInvId = $db->lastInsertId();
                    }
                    
                    // Insert rent items
                    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($roomInfo['base_rent'] > 0) {
                        $itemStmt->execute([$rentInvId, 'rent', 'ค่าเช่าห้องพัก', 1, $roomInfo['base_rent'], $roomInfo['base_rent'], 1]);
                    }
                    if ($roomInfo['other_fees'] > 0) {
                        $itemStmt->execute([$rentInvId, 'other', 'ค่าบริการอื่นๆ', 1, $roomInfo['other_fees'], $roomInfo['other_fees'], 2]);
                    }
                }
                
                // 2. Utility Invoice (Water + Electric)
                $utilityAmount = $waterCost + $electricCost;
                if ($utilityAmount > 0) {
                    $invoiceNumber = generateInvoiceNumber($billingMonth, 'utility');
                    
                    // Check existing utility invoice
                    $existingInv = $db->prepare("SELECT id FROM invoices WHERE room_id = ? AND billing_month = ? AND invoice_type = 'utility'");
                    $existingInv->execute([$roomId, $billingMonth]);
                    $existingInvId = $existingInv->fetchColumn();
                    
                    if ($existingInvId) {
                        $db->prepare("UPDATE invoices SET total_amount = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$utilityAmount, $existingInvId]);
                        $utilInvId = $existingInvId;
                        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$utilInvId]);
                    } else {
                        $db->prepare("
                            INSERT INTO invoices (invoice_number, room_id, tenant_id, billing_month, invoice_type, total_amount, due_date, status, created_by)
                            VALUES (?, ?, ?, ?, 'utility', ?, ?, 'draft', ?)
                        ")->execute([$invoiceNumber, $roomId, $roomInfo['tenant_id'], $billingMonth, $utilityAmount, $dueDate, $_SESSION['user_id']]);
                        $utilInvId = $db->lastInsertId();
                    }
                    
                    // Insert utility items
                    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($waterCost > 0) {
                        $itemStmt->execute([$utilInvId, 'water', 'ค่าน้ำประปา (' . number_format($waterConsumption, 2) . ' หน่วย × ฿' . number_format($waterRate, 2) . ')', $waterConsumption, $waterRate, $waterCost, 1]);
                    }
                    if ($electricCost > 0) {
                        $itemStmt->execute([$utilInvId, 'electric', 'ค่าไฟฟ้า (' . number_format($electricConsumption, 2) . ' หน่วย × ฿' . number_format($electricRate, 2) . ')', $electricConsumption, $electricRate, $electricCost, 2]);
                    }
                }
            }
            
            $db->commit();
            logActivity($_SESSION['user_id'], 'บันทึกมิเตอร์ห้อง ' . ($roomInfo['room_number'] ?? $roomId), 'meter_reading', $roomId);
            jsonResponse(['success' => true, 'message' => 'บันทึกมิเตอร์และสร้างบิลสำเร็จ']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
