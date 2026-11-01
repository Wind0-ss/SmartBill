<?php
/**
 * Bills API — Lookup, Generate, List invoices
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/line_helpers.php';

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    // Public: Tenant bill lookup
    case 'lookup':
        $roomNumber = trim($_GET['room'] ?? '');
        if (empty($roomNumber)) {
            jsonResponse(['success' => false, 'message' => 'กรุณากรอกหมายเลขห้อง'], 400);
        }
        
        $stmt = $db->prepare("SELECT id, room_number FROM rooms WHERE room_number = ? LIMIT 1");
        $stmt->execute([$roomNumber]);
        $room = $stmt->fetch();
        
        if (!$room) {
            jsonResponse(['success' => false, 'message' => 'ไม่พบห้อง ' . $roomNumber . ' ในระบบ']);
        }
        
        jsonResponse([
            'success' => true,
            'redirect' => APP_URL . '/tenant/bill.php?room=' . urlencode($roomNumber)
        ]);
        break;

    // Admin: List invoices
    case 'list':
        requireLoginAPI();
        $month = $_GET['month'] ?? date('Y-m-01');
        $status = $_GET['status'] ?? '';
        
        $where = "WHERE i.billing_month = ?";
        $params = [$month];
        
        if ($status) {
            $where .= " AND i.status = ?";
            $params[] = $status;
        }
        
        $stmt = $db->prepare("
            SELECT i.*, r.room_number, b.name as building_name,
                   CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name
            FROM invoices i
            JOIN rooms r ON i.room_id = r.id
            LEFT JOIN buildings b ON r.building_id = b.id
            JOIN tenants t ON i.tenant_id = t.id
            $where
            ORDER BY r.room_number
        ");
        $stmt->execute($params);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    // Admin: Generate bills for all rooms
    case 'generate':
        requireLoginAPI();
        $month = $_POST['billing_month'] ?? date('Y-m-01');
        
        $rooms = $db->query("
            SELECT r.*, t.id as tenant_id, CONCAT(t.first_name, ' ', COALESCE(t.last_name, '')) as tenant_name
            FROM rooms r
            JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
            WHERE r.status = 'occupied'
        ")->fetchAll();
        
        $created = 0;
        $skipped = 0;
        
        foreach ($rooms as $room) {
            // Check existing invoices
            $existingRent = $db->prepare("SELECT id FROM invoices WHERE room_id = ? AND billing_month = ? AND invoice_type = 'rent'");
            $existingRent->execute([$room['id'], $month]);
            $hasRent = $existingRent->fetch();

            $existingUtil = $db->prepare("SELECT id FROM invoices WHERE room_id = ? AND billing_month = ? AND invoice_type = 'utility'");
            $existingUtil->execute([$room['id'], $month]);
            $hasUtil = $existingUtil->fetch();
            
            if ($hasRent && $hasUtil) {
                $skipped++;
                continue;
            }
            
            // Get meter readings
            $waterReading = $db->prepare("
                SELECT mr.consumption, mr.total_cost FROM meter_readings mr
                JOIN meters m ON mr.meter_id = m.id
                WHERE m.room_id = ? AND m.meter_type = 'water' AND mr.billing_month = ?
            ");
            $waterReading->execute([$room['id'], $month]);
            $water = $waterReading->fetch();
            
            $electricReading = $db->prepare("
                SELECT mr.consumption, mr.total_cost FROM meter_readings mr
                JOIN meters m ON mr.meter_id = m.id
                WHERE m.room_id = ? AND m.meter_type = 'electric' AND mr.billing_month = ?
            ");
            $electricReading->execute([$room['id'], $month]);
            $electric = $electricReading->fetch();
            
            $waterCost = $water['total_cost'] ?? 0;
            $electricCost = $electric['total_cost'] ?? 0;

            // ค่าใช้จ่ายเพิ่มเติมที่บันทึกไว้สำหรับห้อง/รอบเดือนนี้ (ค่าขยะ, ค่าบำรุงมิเตอร์ ฯลฯ)
            $extraStmt = $db->prepare("
                SELECT description, amount, bill_group
                FROM additional_charges
                WHERE room_id = ? AND billing_month = ?
                ORDER BY id ASC
            ");
            $extraStmt->execute([$room['id'], $month]);
            $extras = $extraStmt->fetchAll();

            $rentExtras = array_values(array_filter($extras, function ($c) { return $c['bill_group'] === 'rent'; }));
            $utilExtras = array_values(array_filter($extras, function ($c) { return $c['bill_group'] === 'utility'; }));
            $rentExtrasTotal = array_sum(array_column($rentExtras, 'amount'));
            $utilExtrasTotal = array_sum(array_column($utilExtras, 'amount'));

            $dueDay = getSetting('bill_due_day', 5);
            $dueDate = date('Y-m-' . str_pad($dueDay, 2, '0', STR_PAD_LEFT), strtotime($month . ' +1 month'));

            // 1. Rent Invoice
            $rentAmount = $room['base_rent'] + $room['other_fees'] + $rentExtrasTotal;
            if ($rentAmount > 0 && !$hasRent) {
                $invoiceNumber = generateInvoiceNumber($month, 'rent');
                $db->prepare("
                    INSERT INTO invoices (invoice_number, room_id, tenant_id, billing_month, invoice_type, total_amount, due_date, status, created_by)
                    VALUES (?, ?, ?, ?, 'rent', ?, ?, 'draft', ?)
                ")->execute([$invoiceNumber, $room['id'], $room['tenant_id'], $month, $rentAmount, $dueDate, $_SESSION['user_id']]);
                $invId = $db->lastInsertId();
                
                $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $itemStmt->execute([$invId, 'rent', 'ค่าเช่าห้องพัก', 1, $room['base_rent'], $room['base_rent'], 1]);
                if ($room['other_fees'] > 0) {
                    $itemStmt->execute([$invId, 'other', 'ค่าบริการอื่นๆ', 1, $room['other_fees'], $room['other_fees'], 2]);
                }
                $sort = 10;
                foreach ($rentExtras as $c) {
                    $itemStmt->execute([$invId, 'other', $c['description'], 1, $c['amount'], $c['amount'], $sort++]);
                }
                $created++;
            }

            // 2. Utility Invoice
            $utilityAmount = $waterCost + $electricCost + $utilExtrasTotal;
            if ($utilityAmount > 0 && !$hasUtil) {
                $invoiceNumber = generateInvoiceNumber($month, 'utility');
                $db->prepare("
                    INSERT INTO invoices (invoice_number, room_id, tenant_id, billing_month, invoice_type, total_amount, due_date, status, created_by)
                    VALUES (?, ?, ?, ?, 'utility', ?, ?, 'draft', ?)
                ")->execute([$invoiceNumber, $room['id'], $room['tenant_id'], $month, $utilityAmount, $dueDate, $_SESSION['user_id']]);
                $invId = $db->lastInsertId();
                
                $itemStmt = $db->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, amount, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                if ($waterCost > 0) {
                    $itemStmt->execute([$invId, 'water', 'ค่าน้ำประปา (' . number_format($water['consumption'] ?? 0, 2) . ' หน่วย)', $water['consumption'] ?? 0, getSetting('water_rate', 18), $waterCost, 1]);
                }
                if ($electricCost > 0) {
                    $itemStmt->execute([$invId, 'electric', 'ค่าไฟฟ้า (' . number_format($electric['consumption'] ?? 0, 2) . ' หน่วย)', $electric['consumption'] ?? 0, getSetting('electric_rate', 7.5), $electricCost, 2]);
                }
                $sort = 10;
                foreach ($utilExtras as $c) {
                    $itemStmt->execute([$invId, 'other', $c['description'], 1, $c['amount'], $c['amount'], $sort++]);
                }
                $created++;
            }
        }
        
        logActivity($_SESSION['user_id'], 'สร้างบิลประจำเดือน (สร้าง: ' . $created . ', ข้าม: ' . $skipped . ')', 'invoice', null);
        jsonResponse(['success' => true, 'message' => "สร้างบิลเรียบร้อย: $created รายการ, ข้าม: $skipped รายการ"]);
        break;

    // Admin: Send bill notification via LINE
    case 'notify_line':
        requireLoginAPI();
        $input = getInput();
        $invoiceId = (int)($input['invoice_id'] ?? 0);
        
        if ($invoiceId <= 0) {
            jsonResponse(['success' => false, 'message' => 'ไม่ระบุ invoice_id'], 400);
        }
        
        $result = sendFlexBillNotification($invoiceId);
        
        if ($result['success']) {
            logActivity($_SESSION['user_id'], 'ส่งแจ้งเตือนบิลผ่าน LINE', 'invoice', $invoiceId);
        }
        
        jsonResponse($result, $result['success'] ? 200 : 400);
        break;

    // Admin: Send bill notifications to all tenants for a month
    case 'notify_all':
        requireLoginAPI();
        $input = getInput();
        $month = $input['billing_month'] ?? date('Y-m-01');
        
        $invoices = $db->prepare("
            SELECT i.id, r.room_number, t.line_user_id
            FROM invoices i
            JOIN rooms r ON i.room_id = r.id
            JOIN tenants t ON i.tenant_id = t.id
            WHERE i.billing_month = ? AND i.sent_via_line = 0 AND i.status != 'cancelled'
              AND t.line_user_id IS NOT NULL AND t.line_user_id != ''
        ");
        $invoices->execute([$month]);
        $bills = $invoices->fetchAll();
        
        $sent = 0;
        $failed = 0;
        foreach ($bills as $bill) {
            $result = sendFlexBillNotification($bill['id']);
            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
            }
            usleep(200000); // 200ms delay to avoid LINE rate limit
        }
        
        logActivity($_SESSION['user_id'], "ส่งแจ้งเตือนบิลทั้งหมด (สำเร็จ: $sent, ล้มเหลว: $failed)", 'invoice', null);
        jsonResponse(['success' => true, 'message' => "ส่งสำเร็จ $sent รายการ" . ($failed > 0 ? ", ล้มเหลว $failed รายการ" : '')]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
