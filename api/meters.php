<?php
/**
 * Meters API — Read/Save meter readings + ค่าใช้จ่ายเพิ่มเติม
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLoginAPI();

$action = $_GET['action'] ?? '';
$db = getDB();

/**
 * ค่ามิเตอร์ล่าสุดก่อนรอบเดือนที่เลือก (ไม่จำกัดว่าต้องเป็นเดือนก่อนหน้าพอดี
 * เผื่อกรณีเดือนไหนไม่ได้จด หรือเริ่มจดไม่ต่อเนื่อง)
 * คืนค่า ['reading' => float, 'month' => 'YYYY-MM-01'|null]
 */
function getPreviousReading(PDO $db, int $roomId, string $type, string $billingMonth): array {
    $stmt = $db->prepare("
        SELECT mr.current_reading, mr.billing_month
        FROM meter_readings mr
        JOIN meters m ON mr.meter_id = m.id
        WHERE mr.room_id = ? AND m.meter_type = ? AND mr.billing_month < ?
        ORDER BY mr.billing_month DESC, mr.id DESC
        LIMIT 1
    ");
    $stmt->execute([$roomId, $type, $billingMonth]);
    $row = $stmt->fetch();
    return [
        'reading' => $row ? (float)$row['current_reading'] : 0.0,
        'month'   => $row ? $row['billing_month'] : null,
    ];
}

/**
 * หา id มิเตอร์ของห้อง ถ้ายังไม่มีให้สร้างให้เลย
 * (ห้องที่เพิ่มโดยยังไม่มีผู้เช่าจะไม่มีแถว meters ทำให้ก่อนหน้านี้บันทึกค่าไม่ลง)
 */
function getOrCreateMeterId(PDO $db, int $roomId, string $type): int {
    $stmt = $db->prepare("SELECT id FROM meters WHERE room_id = ? AND meter_type = ? ORDER BY id ASC LIMIT 1");
    $stmt->execute([$roomId, $type]);
    $id = $stmt->fetchColumn();
    if ($id) return (int)$id;

    $rn = $db->prepare("SELECT room_number FROM rooms WHERE id = ?");
    $rn->execute([$roomId]);
    $roomNumber = $rn->fetchColumn() ?: $roomId;

    $db->prepare("INSERT INTO meters (room_id, meter_type, meter_number) VALUES (?, ?, ?)")
       ->execute([$roomId, $type, ($type === 'water' ? 'W-' : 'E-') . $roomNumber]);
    return (int)$db->lastInsertId();
}

switch ($action) {
    case 'get_readings':
        $roomId = (int)($_GET['room_id'] ?? 0);
        $billingMonth = $_GET['billing_month'] ?? date('Y-m-01');
        $billingMonth = date('Y-m-01', strtotime($billingMonth));

        $waterPrev    = getPreviousReading($db, $roomId, 'water', $billingMonth);
        $electricPrev = getPreviousReading($db, $roomId, 'electric', $billingMonth);
        $waterPrevReading    = $waterPrev['reading'];
        $electricPrevReading = $electricPrev['reading'];

        // Check if current month already recorded
        $existing = $db->prepare("
            SELECT mr.*, m.meter_type
            FROM meter_readings mr
            JOIN meters m ON mr.meter_id = m.id
            WHERE m.room_id = ? AND mr.billing_month = ?
        ");
        $existing->execute([$roomId, $billingMonth]);
        $existingReadings = $existing->fetchAll();

        // ค่ามิเตอร์ที่บันทึกไว้แล้วของเดือนนี้ (ใช้เติมกลับในฟอร์มตอนแก้ไข)
        $waterCurrent = 0;
        $electricCurrent = 0;
        foreach ($existingReadings as $r) {
            if ($r['meter_type'] === 'water')    $waterCurrent    = (float)$r['current_reading'];
            if ($r['meter_type'] === 'electric') $electricCurrent = (float)$r['current_reading'];
        }

        // ค่าใช้จ่ายเพิ่มเติมที่บันทึกไว้แล้วของเดือนนี้
        $charges = $db->prepare("
            SELECT id, expense_type_id, description, amount, bill_group, notes
            FROM additional_charges
            WHERE room_id = ? AND billing_month = ?
            ORDER BY id ASC
        ");
        $charges->execute([$roomId, $billingMonth]);

        jsonResponse([
            'success' => true,
            'water_previous' => (float)$waterPrevReading,
            'electric_previous' => (float)$electricPrevReading,
            'water_previous_month' => $waterPrev['month'],
            'electric_previous_month' => $electricPrev['month'],
            'water_current' => $waterCurrent,
            'electric_current' => $electricCurrent,
            'existing' => $existingReadings,
            'additional_charges' => $charges->fetchAll(),
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

        // ---------- ค่าใช้จ่ายเพิ่มเติม ----------
        $chargesInput = json_decode($_POST['charges'] ?? '[]', true);
        if (!is_array($chargesInput)) $chargesInput = [];
        if (count($chargesInput) > 50) {
            jsonResponse(['success' => false, 'message' => 'ค่าใช้จ่ายเพิ่มเติมได้สูงสุด 50 รายการต่อรอบเดือน'], 400);
        }

        // id ของประเภทที่มีอยู่จริง เพื่อกัน foreign key error
        $validTypeIds = array_map('intval', $db->query("SELECT id FROM expense_types")->fetchAll(PDO::FETCH_COLUMN));

        $charges = [];
        foreach ($chargesInput as $c) {
            if (!is_array($c)) continue;

            $desc   = trim((string)($c['description'] ?? ''));
            $amount = round((float)($c['amount'] ?? 0), 2);

            // ข้ามรายการที่ไม่ได้กรอก หรือยอดเป็น 0
            if ($desc === '' || $amount <= 0) continue;
            if ($amount > 9999999.99) {
                jsonResponse(['success' => false, 'message' => 'ยอดของ "' . $desc . '" สูงเกินกำหนด'], 400);
            }

            $typeId = (isset($c['expense_type_id']) && $c['expense_type_id'] !== '' && $c['expense_type_id'] !== null)
                    ? (int)$c['expense_type_id'] : null;
            if ($typeId !== null && !in_array($typeId, $validTypeIds, true)) $typeId = null;

            $notes = mb_substr(trim((string)($c['notes'] ?? '')), 0, 300);

            $charges[] = [
                'expense_type_id' => $typeId,
                'description'     => mb_substr($desc, 0, 200),
                'amount'          => $amount,
                'bill_group'      => (($c['bill_group'] ?? 'rent') === 'utility') ? 'utility' : 'rent',
                'notes'           => $notes !== '' ? $notes : null,
            ];
        }

        if ($waterReading <= 0 && $electricReading <= 0 && empty($charges)) {
            jsonResponse(['success' => false, 'message' => 'กรุณากรอกค่ามิเตอร์ หรือค่าใช้จ่ายเพิ่มเติมอย่างน้อย 1 รายการ'], 400);
        }

        $billingMonth = date('Y-m-01', strtotime($billingMonth));
        $waterRate = getSetting('water_rate', 18.00);
        $electricRate = getSetting('electric_rate', 7.50);

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

            // บันทึกค่ามิเตอร์ — previous_reading อัปเดตด้วยทุกครั้งที่บันทึกซ้ำ
            // เพื่อให้แถวเก่าที่เคยถูกบันทึกเป็น 0 ถูกแก้ให้ถูกต้องเมื่อกลับมาบันทึกใหม่
            $insReading = $db->prepare("
                INSERT INTO meter_readings (meter_id, room_id, billing_month, previous_reading, current_reading, unit_price, image_path, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE previous_reading = VALUES(previous_reading), current_reading = VALUES(current_reading),
                                        unit_price = VALUES(unit_price), image_path = COALESCE(VALUES(image_path), image_path),
                                        recorded_by = VALUES(recorded_by)
            ");

            // เดือนแรกที่เริ่มใช้ระบบยังไม่มีประวัติ ให้แอดมินกรอกเลขตั้งต้นเองได้
            // (ใช้เฉพาะตอนที่ยังไม่มีค่าเดือนก่อนในระบบ เพื่อไม่ให้เขียนทับประวัติจริง)
            $waterPrevInput    = (float)($_POST['water_previous'] ?? 0);
            $electricPrevInput = (float)($_POST['electric_previous'] ?? 0);

            if ($waterReading > 0) {
                $waterMeterId = getOrCreateMeterId($db, $roomId, 'water');
                $hist = getPreviousReading($db, $roomId, 'water', $billingMonth);
                $waterPrev = $hist['month'] !== null ? $hist['reading'] : $waterPrevInput;
                $insReading->execute([$waterMeterId, $roomId, $billingMonth, $waterPrev, $waterReading, $waterRate, $waterPhotoPath, $_SESSION['user_id']]);
            }

            if ($electricReading > 0) {
                $electricMeterId = getOrCreateMeterId($db, $roomId, 'electric');
                $hist = getPreviousReading($db, $roomId, 'electric', $billingMonth);
                $electricPrev = $hist['month'] !== null ? $hist['reading'] : $electricPrevInput;
                $insReading->execute([$electricMeterId, $roomId, $billingMonth, $electricPrev, $electricReading, $electricRate, $electricPhotoPath, $_SESSION['user_id']]);
            }

            // บันทึกค่าใช้จ่ายเพิ่มเติม — ฟอร์มส่งรายการทั้งหมดของรอบเดือนมา จึงเขียนทับทั้งชุด
            $db->prepare("DELETE FROM additional_charges WHERE room_id = ? AND billing_month = ?")
               ->execute([$roomId, $billingMonth]);

            if (!empty($charges)) {
                $insCharge = $db->prepare("
                    INSERT INTO additional_charges (room_id, expense_type_id, billing_month, description, amount, bill_group, notes, recorded_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($charges as $c) {
                    $insCharge->execute([
                        $roomId, $c['expense_type_id'], $billingMonth,
                        $c['description'], $c['amount'], $c['bill_group'], $c['notes'],
                        $_SESSION['user_id']
                    ]);
                }
            }

            // Auto-generate invoice
            $room = $db->prepare("SELECT r.*, CONCAT(t.first_name,' ',COALESCE(t.last_name,'')) as tenant_name, t.id as tenant_id
                                  FROM rooms r LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1 WHERE r.id = ?");
            $room->execute([$roomId]);
            $roomInfo = $room->fetch();

            if ($roomInfo && $roomInfo['tenant_id']) {
                // อ่านค่ามิเตอร์ที่บันทึกไว้จริงของเดือนนี้
                // (ครอบคลุมกรณีบันทึกทีละอย่าง หรือกลับมาแก้เฉพาะค่าใช้จ่ายเพิ่มเติมภายหลัง)
                $saved = $db->prepare("
                    SELECT m.meter_type, mr.consumption, mr.total_cost, mr.unit_price
                    FROM meter_readings mr
                    JOIN meters m ON mr.meter_id = m.id
                    WHERE mr.room_id = ? AND mr.billing_month = ?
                ");
                $saved->execute([$roomId, $billingMonth]);

                $waterConsumption = 0;    $waterCost = 0;    $waterUnitPrice = $waterRate;
                $electricConsumption = 0; $electricCost = 0; $electricUnitPrice = $electricRate;
                foreach ($saved->fetchAll() as $r) {
                    if ($r['meter_type'] === 'water') {
                        $waterConsumption = max(0, (float)$r['consumption']);
                        $waterCost        = max(0, (float)$r['total_cost']);
                        $waterUnitPrice   = (float)$r['unit_price'];
                    } elseif ($r['meter_type'] === 'electric') {
                        $electricConsumption = max(0, (float)$r['consumption']);
                        $electricCost        = max(0, (float)$r['total_cost']);
                        $electricUnitPrice   = (float)$r['unit_price'];
                    }
                }

                // แยกค่าใช้จ่ายเพิ่มเติมตามบิลปลายทาง
                $rentExtras = array_values(array_filter($charges, function ($c) { return $c['bill_group'] === 'rent'; }));
                $utilExtras = array_values(array_filter($charges, function ($c) { return $c['bill_group'] === 'utility'; }));
                $rentExtrasTotal = array_sum(array_column($rentExtras, 'amount'));
                $utilExtrasTotal = array_sum(array_column($utilExtras, 'amount'));

                $dueDay = getSetting('bill_due_day', 5);
                $dueDate = date('Y-m-' . str_pad($dueDay, 2, '0', STR_PAD_LEFT), strtotime($billingMonth . ' +1 month'));

                // 1. Rent Invoice (ค่าเช่า + ค่าบริการอื่นๆ + ค่าใช้จ่ายเพิ่มเติมกลุ่มค่าเช่า)
                $rentAmount = $roomInfo['base_rent'] + $roomInfo['other_fees'] + $rentExtrasTotal;

                $existingInv = $db->prepare("SELECT id, status FROM invoices WHERE room_id = ? AND billing_month = ? AND invoice_type = 'rent'");
                $existingInv->execute([$roomId, $billingMonth]);
                $existingRent = $existingInv->fetch();

                // บิลที่ชำระแล้ว/ยกเลิกแล้ว ไม่เขียนทับ
                $rentLocked = $existingRent && in_array($existingRent['status'], ['paid', 'cancelled'], true);

                if (!$rentLocked && ($rentAmount > 0 || $existingRent)) {
                    if ($existingRent) {
                        $rentInvId = $existingRent['id'];
                        $db->prepare("UPDATE invoices SET total_amount = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$rentAmount, $rentInvId]);
                        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$rentInvId]);
                    } else {
                        $invoiceNumber = generateInvoiceNumber($billingMonth, 'rent');
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
                    $sort = 10;
                    foreach ($rentExtras as $c) {
                        $itemStmt->execute([$rentInvId, 'other', $c['description'], 1, $c['amount'], $c['amount'], $sort++]);
                    }
                }

                // 2. Utility Invoice (ค่าน้ำ + ค่าไฟ + ค่าใช้จ่ายเพิ่มเติมกลุ่มค่าน้ำ/ไฟ)
                $utilityAmount = $waterCost + $electricCost + $utilExtrasTotal;

                $existingInv = $db->prepare("SELECT id, status FROM invoices WHERE room_id = ? AND billing_month = ? AND invoice_type = 'utility'");
                $existingInv->execute([$roomId, $billingMonth]);
                $existingUtil = $existingInv->fetch();

                $utilLocked = $existingUtil && in_array($existingUtil['status'], ['paid', 'cancelled'], true);

                if (!$utilLocked && ($utilityAmount > 0 || $existingUtil)) {
                    if ($existingUtil) {
                        $utilInvId = $existingUtil['id'];
                        $db->prepare("UPDATE invoices SET total_amount = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$utilityAmount, $utilInvId]);
                        $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$utilInvId]);
                    } else {
                        $invoiceNumber = generateInvoiceNumber($billingMonth, 'utility');
                        $db->prepare("
                            INSERT INTO invoices (invoice_number, room_id, tenant_id, billing_month, invoice_type, total_amount, due_date, status, created_by)
                            VALUES (?, ?, ?, ?, 'utility', ?, ?, 'draft', ?)
                        ")->execute([$invoiceNumber, $roomId, $roomInfo['tenant_id'], $billingMonth, $utilityAmount, $dueDate, $_SESSION['user_id']]);
                        $utilInvId = $db->lastInsertId();
                    }

                    // Insert utility items
                    $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    if ($waterCost > 0) {
                        $itemStmt->execute([$utilInvId, 'water', 'ค่าน้ำประปา (' . number_format($waterConsumption, 2) . ' หน่วย × ฿' . number_format($waterUnitPrice, 2) . ')', $waterConsumption, $waterUnitPrice, $waterCost, 1]);
                    }
                    if ($electricCost > 0) {
                        $itemStmt->execute([$utilInvId, 'electric', 'ค่าไฟฟ้า (' . number_format($electricConsumption, 2) . ' หน่วย × ฿' . number_format($electricUnitPrice, 2) . ')', $electricConsumption, $electricUnitPrice, $electricCost, 2]);
                    }
                    $sort = 10;
                    foreach ($utilExtras as $c) {
                        $itemStmt->execute([$utilInvId, 'other', $c['description'], 1, $c['amount'], $c['amount'], $sort++]);
                    }
                }
            }

            $db->commit();

            $logMsg = 'บันทึกมิเตอร์ห้อง ' . ($roomInfo['room_number'] ?? $roomId);
            if (!empty($charges)) $logMsg .= ' + ค่าใช้จ่ายเพิ่มเติม ' . count($charges) . ' รายการ';
            logActivity($_SESSION['user_id'], $logMsg, 'meter_reading', $roomId);

            jsonResponse(['success' => true, 'message' => 'บันทึกและสร้างบิลสำเร็จ', 'charges_saved' => count($charges)]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
        }
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
