<?php
/**
 * Expenses API — จัดการ "ประเภทค่าใช้จ่ายเพิ่มเติม" (ค่าขยะ, ค่าบำรุงมิเตอร์ ฯลฯ)
 *
 * รายการที่บันทึกจริงต่อห้อง/เดือน จะถูกบันทึกผ่าน api/meters.php?action=save
 * เพื่อให้การออกบิลเกิดขึ้นในธุรกรรมเดียวกัน
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {

    // รายการประเภทค่าใช้จ่ายทั้งหมด (?active=1 = เฉพาะที่เปิดใช้งาน)
    case 'types':
        requireLoginAPI();
        $onlyActive = !empty($_GET['active']);
        $sql = "SELECT * FROM expense_types" . ($onlyActive ? " WHERE is_active = 1" : "") . " ORDER BY sort_order ASC, id ASC";
        jsonResponse(['success' => true, 'data' => $db->query($sql)->fetchAll()]);
        break;

    // เพิ่ม / แก้ไขประเภทค่าใช้จ่าย
    case 'save_type':
        requireRoleAPI('super_admin', 'admin');
        $input = getInput();

        $id            = (int)($input['id'] ?? 0);
        $name          = trim($input['name'] ?? '');
        $icon          = trim($input['icon'] ?? '') ?: 'receipt_long';
        $defaultAmount = round((float)($input['default_amount'] ?? 0), 2);
        $billGroup     = ($input['bill_group'] ?? 'rent') === 'utility' ? 'utility' : 'rent';
        $isActive      = !empty($input['is_active']) ? 1 : 0;
        $sortOrder     = (int)($input['sort_order'] ?? 0);

        if ($name === '') {
            jsonResponse(['success' => false, 'message' => 'กรุณากรอกชื่อรายการ'], 400);
        }
        if (mb_strlen($name) > 150) {
            jsonResponse(['success' => false, 'message' => 'ชื่อรายการยาวเกิน 150 ตัวอักษร'], 400);
        }
        if ($defaultAmount < 0) {
            jsonResponse(['success' => false, 'message' => 'ยอดเริ่มต้นต้องไม่ติดลบ'], 400);
        }

        // กันชื่อซ้ำ
        $dupe = $db->prepare("SELECT id FROM expense_types WHERE name = ? AND id <> ?");
        $dupe->execute([$name, $id]);
        if ($dupe->fetchColumn()) {
            jsonResponse(['success' => false, 'message' => 'มีรายการชื่อนี้อยู่แล้ว'], 400);
        }

        try {
            if ($id > 0) {
                $db->prepare("
                    UPDATE expense_types
                    SET name = ?, icon = ?, default_amount = ?, bill_group = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ")->execute([$name, $icon, $defaultAmount, $billGroup, $isActive, $sortOrder, $id]);
                logActivity($_SESSION['user_id'], 'แก้ไขประเภทค่าใช้จ่าย: ' . $name, 'expense_type', $id);
                jsonResponse(['success' => true, 'message' => 'บันทึกการแก้ไขเรียบร้อย', 'id' => $id]);
            }

            if ($sortOrder === 0) {
                $sortOrder = (int)$db->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM expense_types")->fetchColumn();
            }
            $db->prepare("
                INSERT INTO expense_types (name, icon, default_amount, bill_group, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?)
            ")->execute([$name, $icon, $defaultAmount, $billGroup, $isActive, $sortOrder]);
            $newId = (int)$db->lastInsertId();

            logActivity($_SESSION['user_id'], 'เพิ่มประเภทค่าใช้จ่าย: ' . $name, 'expense_type', $newId);
            jsonResponse(['success' => true, 'message' => 'เพิ่มรายการเรียบร้อย', 'id' => $newId]);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'บันทึกไม่สำเร็จ: ' . $e->getMessage()], 500);
        }
        break;

    // ลบประเภทค่าใช้จ่าย (บิลเก่าที่ออกไปแล้วไม่ถูกกระทบ)
    case 'delete_type':
        requireRoleAPI('super_admin', 'admin');
        $input = getInput();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['success' => false, 'message' => 'ไม่ระบุรายการที่ต้องการลบ'], 400);
        }

        $stmt = $db->prepare("SELECT name FROM expense_types WHERE id = ?");
        $stmt->execute([$id]);
        $name = $stmt->fetchColumn();
        if (!$name) {
            jsonResponse(['success' => false, 'message' => 'ไม่พบรายการนี้'], 404);
        }

        $used = $db->prepare("SELECT COUNT(*) FROM additional_charges WHERE expense_type_id = ?");
        $used->execute([$id]);
        $usedCount = (int)$used->fetchColumn();

        $db->prepare("DELETE FROM expense_types WHERE id = ?")->execute([$id]);
        logActivity($_SESSION['user_id'], 'ลบประเภทค่าใช้จ่าย: ' . $name, 'expense_type', $id);

        jsonResponse([
            'success' => true,
            'message' => 'ลบรายการเรียบร้อย' . ($usedCount > 0 ? " (ประวัติที่บันทึกไว้ $usedCount รายการยังคงอยู่)" : '')
        ]);
        break;

    // ประวัติค่าใช้จ่ายเพิ่มเติมของห้องหนึ่ง (ย้อนหลังตามรอบเดือน)
    case 'history':
        requireLoginAPI();
        $roomId = (int)($_GET['room_id'] ?? 0);
        $limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        if ($roomId <= 0) {
            jsonResponse(['success' => false, 'message' => 'ไม่ระบุห้อง'], 400);
        }

        $stmt = $db->prepare("
            SELECT ac.*, et.icon
            FROM additional_charges ac
            LEFT JOIN expense_types et ON ac.expense_type_id = et.id
            WHERE ac.room_id = ?
            ORDER BY ac.billing_month DESC, ac.id ASC
            LIMIT $limit
        ");
        $stmt->execute([$roomId]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
