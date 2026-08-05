<?php
/**
 * Buildings API
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

requireLoginAPI();

$action = $_GET['action'] ?? '';
$db = getDB();

switch ($action) {
    case 'create':
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $address = trim($input['address'] ?? '');
        $totalFloors = max(1, (int)($input['total_floors'] ?? 1));
        
        if (empty($name)) jsonResponse(['success' => false, 'message' => 'กรุณากรอกชื่ออาคาร'], 400);
        
        $db->beginTransaction();
        try {
            $stmt = $db->prepare("INSERT INTO buildings (name, address, total_floors, created_by) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $address, $totalFloors, $_SESSION['user_id']]);
            $buildingId = $db->lastInsertId();
            
            // Create floors
            for ($f = 1; $f <= $totalFloors; $f++) {
                $db->prepare("INSERT INTO floors (building_id, floor_number, name) VALUES (?, ?, ?)")
                   ->execute([$buildingId, $f, 'ชั้น ' . $f]);
            }
            
            $db->commit();
            logActivity($_SESSION['user_id'], 'เพิ่มอาคาร: ' . $name, 'building', $buildingId);
            jsonResponse(['success' => true, 'message' => 'เพิ่มอาคารสำเร็จ', 'id' => $buildingId]);
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
        }
        break;
    
    case 'update':
        $input = getInput();
        $id = (int)($input['id'] ?? 0);
        $name = trim($input['name'] ?? '');
        
        if ($id <= 0 || empty($name)) jsonResponse(['success' => false, 'message' => 'Invalid data'], 400);
        
        $db->prepare("UPDATE buildings SET name = ? WHERE id = ?")->execute([$name, $id]);
        logActivity($_SESSION['user_id'], 'แก้ไขอาคาร: ' . $name, 'building', $id);
        jsonResponse(['success' => true, 'message' => 'อัปเดตสำเร็จ']);
        break;
    
    case 'list':
        $buildings = $db->query("
            SELECT b.*, COUNT(r.id) as room_count
            FROM buildings b
            LEFT JOIN rooms r ON r.building_id = b.id
            WHERE b.is_active = 1
            GROUP BY b.id
            ORDER BY b.name
        ")->fetchAll();
        jsonResponse(['success' => true, 'data' => $buildings]);
        break;

    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
