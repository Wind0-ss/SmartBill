<?php
/**
 * AutoBill — Authentication Middleware
 * Login, logout, session management, and role-based access control
 */

require_once __DIR__ . '/config.php';

/**
 * Attempt login with username and password
 */
function login(string $username, string $password): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.*, r.name as role_name, r.display_name as role_display
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.username = ? AND u.is_active = 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
    }
    
    // Update last login
    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
    
    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role_name'];
    $_SESSION['role_display'] = $user['role_display'];
    $_SESSION['avatar'] = $user['avatar'];
    
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    // Log activity
    logActivity($user['id'], 'login', 'user', $user['id']);
    
    return ['success' => true, 'user' => $user];
}

/**
 * Logout current user
 */
function logout(): void {
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'user', $_SESSION['user_id']);
    }
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Check if user is logged in
 */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/**
 * Get current logged-in user data
 */
function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'role' => $_SESSION['role'],
        'role_display' => $_SESSION['role_display'],
        'avatar' => $_SESSION['avatar'] ?? null
    ];
}

/**
 * Check if current user has required role
 */
function hasRole(string ...$roles): bool {
    if (!isLoggedIn()) return false;
    return in_array($_SESSION['role'], $roles);
}

/**
 * Require login — redirect to login page if not authenticated
 */
function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

/**
 * Require specific role — show 403 if unauthorized
 */
function requireRole(string ...$roles): void {
    requireLogin();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        include APP_ROOT . '/components/403.php';
        exit;
    }
}

/**
 * Require login for API — return JSON error
 */
function requireLoginAPI(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        die(json_encode(['error' => 'Unauthorized', 'message' => 'กรุณาเข้าสู่ระบบ']));
    }
}

/**
 * Require role for API
 */
function requireRoleAPI(string ...$roles): void {
    requireLoginAPI();
    if (!hasRole(...$roles)) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden', 'message' => 'คุณไม่มีสิทธิ์เข้าถึง']));
    }
}

/**
 * Log user activity
 */
function logActivity(int $userId, string $action, string $entityType = null, int $entityId = null, array $details = null): void {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO activity_logs (user_id, action, entity_type, entity_id, details, ip_address)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $entityType,
            $entityId,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    } catch (Exception $e) {
        // Silently fail for logging
    }
}
