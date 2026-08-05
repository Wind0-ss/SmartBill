<?php
/**
 * Rooms API — CRUD Operations
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLoginAPI();

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    // ---- CREATE ----
    case 'create':
        $input = getInput();
        $buildingId = (int)($input['building_id'] ?? 0);
        $roomNumber = trim($input['room_number'] ?? '');
        $floorId = null;
        $baseRent = (float)($input['base_rent'] ?? 0);
        $otherFees = 0.0;
        $tenantName = trim($input['tenant_name'] ?? '');
        $tenantPhone = trim($input['tenant_phone'] ?? '');

        if (empty($roomNumber) || $buildingId <= 0) {
            jsonResponse(['success' => false, 'message' => 'กรุณากรอกเลขห้องและเลือกอาคาร'], 400);
        }

        // Check duplicate
        $check = $db->prepare("SELECT id FROM rooms WHERE building_id = ? AND room_number = ?");
        $check->execute([$buildingId, $roomNumber]);
        if ($check->fetch()) {
            jsonResponse(['success' => false, 'message' => 'เลขห้อง ' . $roomNumber . ' มีอยู่ในระบบแล้ว'], 400);
        }

        $status = $tenantName ? 'occupied' : 'vacant';

        $db->beginTransaction();
        try {
            // Insert room
            $stmt = $db->prepare("
                INSERT INTO rooms (building_id, floor_id, room_number, base_rent, other_fees, status)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$buildingId, $floorId, $roomNumber, $baseRent, $otherFees, $status]);
            $roomId = $db->lastInsertId();

            // Insert tenant if provided
            if ($tenantName) {
                $nameParts = explode(' ', $tenantName, 2);
                $stmt = $db->prepare("
                    INSERT INTO tenants (room_id, first_name, last_name, phone)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$roomId, $nameParts[0], $nameParts[1] ?? '', $tenantPhone]);

                // Create meters
                $db->prepare("INSERT INTO meters (room_id, meter_type, meter_number) VALUES (?, 'water', ?)")
                   ->execute([$roomId, 'W-' . $roomNumber]);
                $db->prepare("INSERT INTO meters (room_id, meter_type, meter_number) VALUES (?, 'electric', ?)")
                   ->execute([$roomId, 'E-' . $roomNumber]);
            }

            $db->commit();
            logActivity($_SESSION['user_id'], 'เพิ่มห้อง ' . $roomNumber, 'room', $roomId);
            jsonResponse(['success' => true, 'message' => 'เพิ่มห้องสำเร็จ', 'room_id' => $roomId]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
        }
        break;

    // ---- UPDATE ----
    case 'update':
        $input = getInput();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) jsonResponse(['success' => false, 'message' => 'Invalid room ID'], 400);

        $baseRent = (float)($input['base_rent'] ?? 0);
        $otherFees = 0.0;
        $tenantName = trim($input['tenant_name'] ?? '');
        $tenantPhone = trim($input['tenant_phone'] ?? '');

        $db->beginTransaction();
        try {
            // Update room
            $stmt = $db->prepare("UPDATE rooms SET base_rent = ?, other_fees = ?, status = ? WHERE id = ?");
            $status = $tenantName ? 'occupied' : 'vacant';
            $stmt->execute([$baseRent, $otherFees, $status, $id]);

            // Update or create tenant
            $existingTenant = $db->prepare("SELECT id FROM tenants WHERE room_id = ? AND is_active = 1");
            $existingTenant->execute([$id]);
            $tenant = $existingTenant->fetch();

            if ($tenantName) {
                $nameParts = explode(' ', $tenantName, 2);
                if ($tenant) {
                    $db->prepare("UPDATE tenants SET first_name = ?, last_name = ?, phone = ? WHERE id = ?")
                       ->execute([$nameParts[0], $nameParts[1] ?? '', $tenantPhone, $tenant['id']]);
                } else {
                    $db->prepare("INSERT INTO tenants (room_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)")
                       ->execute([$id, $nameParts[0], $nameParts[1] ?? '', $tenantPhone]);
                    // Create meters if not exist
                    $room = $db->prepare("SELECT room_number FROM rooms WHERE id = ?")->execute([$id]);
                    $roomData = $db->prepare("SELECT room_number FROM rooms WHERE id = ?");
                    $roomData->execute([$id]);
                    $rn = $roomData->fetchColumn();
                    $db->prepare("INSERT IGNORE INTO meters (room_id, meter_type, meter_number) VALUES (?, 'water', ?)")
                       ->execute([$id, 'W-' . $rn]);
                    $db->prepare("INSERT IGNORE INTO meters (room_id, meter_type, meter_number) VALUES (?, 'electric', ?)")
                       ->execute([$id, 'E-' . $rn]);
                }
            } elseif ($tenant) {
                $db->prepare("UPDATE tenants SET is_active = 0 WHERE id = ?")->execute([$tenant['id']]);
            }

            $db->commit();
            logActivity($_SESSION['user_id'], 'แก้ไขห้อง', 'room', $id);
            jsonResponse(['success' => true, 'message' => 'อัปเดตห้องสำเร็จ']);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()], 500);
        }
        break;

    // ---- DELETE ----
    case 'delete':
        $input = getInput();
        $id = (int)($input['id'] ?? 0);
        if ($id <= 0) jsonResponse(['success' => false, 'message' => 'Invalid room ID'], 400);

        try {
            $db->prepare("DELETE FROM rooms WHERE id = ?")->execute([$id]);
            logActivity($_SESSION['user_id'], 'ลบห้อง', 'room', $id);
            jsonResponse(['success' => true, 'message' => 'ลบห้องสำเร็จ']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'ไม่สามารถลบได้ เนื่องจากมีข้อมูลที่เกี่ยวข้อง'], 400);
        }
        break;

    // ---- LIST ----
    case 'list':
        $buildingId = (int)($_GET['building_id'] ?? 0);
        $where = $buildingId > 0 ? "WHERE r.building_id = $buildingId" : "";
        $rooms = $db->query("
            SELECT r.*, b.name as building_name,
                   CONCAT(t.first_name, ' ', COALESCE(t.last_name,'')) as tenant_name,
                   t.phone as tenant_phone
            FROM rooms r
            LEFT JOIN buildings b ON r.building_id = b.id
            LEFT JOIN tenants t ON t.room_id = r.id AND t.is_active = 1
            $where
            ORDER BY r.room_number
        ")->fetchAll();
        jsonResponse(['success' => true, 'data' => $rooms]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
