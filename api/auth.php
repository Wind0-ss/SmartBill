<?php
/**
 * Auth API — Login / Logout
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(['error' => 'Method not allowed'], 405);
        }
        
        $input = getInput();
        $username = trim($input['username'] ?? '');
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            jsonResponse(['success' => false, 'message' => 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน'], 400);
        }
        
        $result = login($username, $password);
        
        if ($result['success']) {
            jsonResponse([
                'success' => true,
                'message' => 'เข้าสู่ระบบสำเร็จ',
                'redirect' => APP_URL . '/admin/',
                'user' => [
                    'id' => $result['user']['id'],
                    'full_name' => $result['user']['full_name'],
                    'role' => $result['user']['role_name']
                ]
            ]);
        } else {
            jsonResponse(['success' => false, 'message' => $result['message']], 401);
        }
        break;
    
    case 'logout':
        logout();
        // If AJAX request
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            jsonResponse(['success' => true, 'message' => 'ออกจากระบบแล้ว']);
        }
        // Otherwise redirect
        header('Location: ' . APP_URL . '/login.php');
        exit;
        break;
    
    case 'me':
        requireLoginAPI();
        $user = currentUser();
        jsonResponse(['success' => true, 'user' => $user]);
        break;
    
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}
