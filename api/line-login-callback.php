<?php
/**
 * LINE Login Callback Handler
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDB();

// 1. Verify State (CSRF prevention)
$state = $_GET['state'] ?? '';
$sessionState = $_SESSION['line_login_state'] ?? '';
unset($_SESSION['line_login_state']); // Clear state after use

if (empty($state) || $state !== $sessionState) {
    redirect(APP_URL . '/login.php', 'การเข้าสู่ระบบหมดเวลาความปลอดภัย (Invalid State) กรุณาลองใหม่อีกครั้ง', 'error');
}

// 2. Check Code
$code = $_GET['code'] ?? '';
if (empty($code)) {
    $errorDesc = $_GET['error_description'] ?? 'การเข้าสู่ระบบถูกยกเลิก';
    redirect(APP_URL . '/login.php', 'เกิดข้อผิดพลาด: ' . htmlspecialchars($errorDesc), 'error');
}

$channelId = getSetting('line_login_channel_id', '');
$channelSecret = getSetting('line_login_channel_secret', '');

if (empty($channelId) || empty($channelSecret)) {
    redirect(APP_URL . '/login.php', 'ระบบยังไม่ได้กำหนดการตั้งค่า LINE Login ในหลังบ้าน', 'error');
}

// 3. Exchange Code for Access Token
$tokenUrl = 'https://api.line.me/oauth2/v2.1/token';
$postData = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => APP_URL . '/api/line-login-callback.php',
    'client_id'     => $channelId,
    'client_secret' => $channelSecret
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query($postData),
    CURLOPT_SSL_VERIFYPEER => false // For local XAMPP compatibility
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    $errData = json_decode($response, true);
    $errMsg = $errData['error_description'] ?? 'ไม่สามารถขอ Access Token ได้';
    redirect(APP_URL . '/login.php', 'LINE Login Error: ' . htmlspecialchars($errMsg), 'error');
}

$tokenData = json_decode($response, true);
$accessToken = $tokenData['access_token'] ?? '';

if (empty($accessToken)) {
    redirect(APP_URL . '/login.php', 'ไม่ได้รับ Access Token จาก LINE', 'error');
}

// 4. Retrieve User Profile from LINE
$profileUrl = 'https://api.line.me/v2/profile';
$ch = curl_init($profileUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
    CURLOPT_SSL_VERIFYPEER => false
]);
$profileResponse = curl_exec($ch);
$profileHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($profileHttpCode !== 200) {
    redirect(APP_URL . '/login.php', 'ไม่สามารถดึงข้อมูลโปรไฟล์จาก LINE ได้', 'error');
}

$profileData = json_decode($profileResponse, true);
$lineUserId = $profileData['userId'] ?? '';
$displayName = $profileData['displayName'] ?? '';
$pictureUrl = $profileData['pictureUrl'] ?? '';

if (empty($lineUserId)) {
    redirect(APP_URL . '/login.php', 'ไม่พบ LINE User ID จากโปรไฟล์', 'error');
}

// 5. Flow Logic

// CASE A: User is already logged in (Linking their LINE account)
if (isLoggedIn()) {
    $currentUserId = $_SESSION['user_id'];
    
    // Check if this lineUserId is already linked to ANOTHER user
    $stmt = $db->prepare("SELECT id, full_name FROM users WHERE line_user_id = ? AND id != ?");
    $stmt->execute([$lineUserId, $currentUserId]);
    $existingLink = $stmt->fetch();
    
    if ($existingLink) {
        redirect(APP_URL . '/admin/admins.php', 'บัญชี LINE นี้ถูกเชื่อมโยงกับผู้ใช้อื่นแล้ว (' . htmlspecialchars($existingLink['full_name']) . ')', 'error');
    }
    
    // Update current user
    $stmt = $db->prepare("UPDATE users SET line_user_id = ?, avatar = COALESCE(avatar, ?) WHERE id = ?");
    $stmt->execute([$lineUserId, $pictureUrl, $currentUserId]);
    
    // Update session avatar if empty
    if (empty($_SESSION['avatar'])) {
        $_SESSION['avatar'] = $pictureUrl;
    }
    
    logActivity($currentUserId, 'ผูกบัญชี LINE Login', 'user', $currentUserId);
    redirect(APP_URL . '/admin/admins.php', 'เชื่อมโยงบัญชี LINE สำเร็จแล้ว! คุณสามารถใช้ LINE เข้าสู่ระบบในครั้งถัดไป');
}

// CASE B: User is NOT logged in - Check if LINE ID is registered
$stmt = $db->prepare("
    SELECT u.*, r.name as role_name, r.display_name as role_display
    FROM users u
    JOIN roles r ON u.role_id = r.id
    WHERE u.line_user_id = ? AND u.is_active = 1
");
$stmt->execute([$lineUserId]);
$user = $stmt->fetch();

if ($user) {
    // Registered User -> Log them in!
    $db->prepare("UPDATE users SET last_login = NOW(), avatar = COALESCE(avatar, ?) WHERE id = ?")
       ->execute([$pictureUrl, $user['id']]);
    
    $_SESSION['user_id']      = $user['id'];
    $_SESSION['username']     = $user['username'];
    $_SESSION['full_name']    = $user['full_name'];
    $_SESSION['role']         = $user['role_name'];
    $_SESSION['role_display'] = $user['role_display'];
    $_SESSION['avatar']       = $user['avatar'] ?: $pictureUrl;
    
    session_regenerate_id(true);
    logActivity($user['id'], 'login_via_line', 'user', $user['id']);
    
    redirect(APP_URL . '/admin/');
}

// CASE C: User is NOT registered - Check if they have an invitation token
$inviteToken = $_SESSION['invite_token'] ?? '';
unset($_SESSION['invite_token']); // Consume token session

if (!empty($inviteToken)) {
    // Validate invitation
    $stmt = $db->prepare("
        SELECT * FROM admin_invitations 
        WHERE token = ? AND is_used = 0 AND expires_at > NOW()
    ");
    $stmt->execute([$inviteToken]);
    $invitation = $stmt->fetch();
    
    if ($invitation) {
        // Create user and consume invitation
        try {
            $db->beginTransaction();
            
            // Insert user
            $stmt = $db->prepare("
                INSERT INTO users (role_id, username, password, full_name, avatar, line_user_id, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $dummyPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
            $username = 'line_' . mb_substr($lineUserId, 0, 15) . '_' . bin2hex(random_bytes(4));
            
            $stmt->execute([
                $invitation['role_id'],
                $username,
                $dummyPassword,
                $displayName,
                $pictureUrl,
                $lineUserId
            ]);
            
            $newUserId = $db->lastInsertId();
            
            // Mark invitation as used
            $stmt = $db->prepare("UPDATE admin_invitations SET is_used = 1, used_by = ? WHERE id = ?");
            $stmt->execute([$newUserId, $invitation['id']]);
            
            $db->commit();
            
            // Log in the new user
            $stmt = $db->prepare("
                SELECT u.*, r.name as role_name, r.display_name as role_display
                FROM users u
                JOIN roles r ON u.role_id = r.id
                WHERE u.id = ?
            ");
            $stmt->execute([$newUserId]);
            $newUser = $stmt->fetch();
            
            $_SESSION['user_id']      = $newUser['id'];
            $_SESSION['username']     = $newUser['username'];
            $_SESSION['full_name']    = $newUser['full_name'];
            $_SESSION['role']         = $newUser['role_name'];
            $_SESSION['role_display'] = $newUser['role_display'];
            $_SESSION['avatar']       = $newUser['avatar'];
            
            session_regenerate_id(true);
            logActivity($newUser['id'], 'register_and_login_via_line', 'user', $newUser['id']);
            
            redirect(APP_URL . '/admin/', 'ยินดีต้อนรับ! คุณเข้าร่วมเป็นผู้ดูแลระบบด้วย LINE สำเร็จแล้ว');
        } catch (Exception $e) {
            $db->rollBack();
            redirect(APP_URL . '/login.php', 'เกิดข้อผิดพลาดในการลงทะเบียน: ' . htmlspecialchars($e->getMessage()), 'error');
        }
    } else {
        redirect(APP_URL . '/login.php', 'ลิงก์เชิญหมดอายุหรือไม่ถูกต้อง กรุณาติดต่อแอดมินเพื่อขอลิงก์ใหม่', 'error');
    }
}

// CASE D: Not registered, no invitation
redirect(APP_URL . '/login.php', 'คุณไม่มีสิทธิ์เข้าสู่ระบบในระบบนี้ หากต้องการใช้งานกรุณาขอลิงก์เชิญจากแอดมิน', 'error');
