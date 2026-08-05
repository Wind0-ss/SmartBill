<?php
/**
 * SmartBill — Configuration
 * Database connection, constants, and error handling
 * 
 * Supports both development (localhost) and production environments.
 * In production, create a .env file with your settings.
 */

// ============================================================
// Environment Detection
// ============================================================
// Load .env file if exists (production)
$_envFile = dirname(__DIR__) . '/.env';
$_ENV_CONFIG = [];
if (file_exists($_envFile)) {
    $_lines = file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($_lines as $_line) {
        $_line = trim($_line);
        if ($_line === '' || $_line[0] === '#') continue;
        if (strpos($_line, '=') === false) continue;
        [$_key, $_val] = array_map('trim', explode('=', $_line, 2));
        $_ENV_CONFIG[$_key] = trim($_val, '"\'');
    }
}

// Determine environment
$_isProduction = !empty($_ENV_CONFIG['APP_ENV']) && $_ENV_CONFIG['APP_ENV'] === 'production';

// ============================================================
// Error Handling
// ============================================================
error_reporting(E_ALL);
ini_set('display_errors', $_isProduction ? 0 : 0);
ini_set('log_errors', 1);
if ($_isProduction) {
    ini_set('error_log', dirname(__DIR__) . '/logs/error.log');
}
date_default_timezone_set('Asia/Bangkok');

// ============================================================
// Application Constants
// ============================================================
define('APP_NAME', 'Smart Bill');
define('APP_VERSION', '1.0.0');
define('APP_ENV', $_isProduction ? 'production' : 'development');

// Dynamic APP_URL: auto-detect from the current request
$_scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        ? 'https' : 'http';
$_host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// In production: use configured domain. In dev: auto-detect path
if (!empty($_ENV_CONFIG['APP_URL'])) {
    define('APP_URL', rtrim($_ENV_CONFIG['APP_URL'], '/'));
} else {
    // Detect the subdirectory (SmartBill) from SCRIPT_NAME
    $_scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/SmartBill/index.php');
    $_basePath = $_scriptDir;
    if (preg_match('#^(.*?/SmartBill)#i', $_basePath, $_m)) {
        $_basePath = $_m[1];
    }
    define('APP_URL', $_scheme . '://' . $_host . $_basePath);
}

define('APP_ROOT', dirname(__DIR__));

// Upload directories
define('UPLOAD_DIR', APP_ROOT . '/uploads');
define('METER_UPLOAD_DIR', UPLOAD_DIR . '/meters');
define('SLIP_UPLOAD_DIR', UPLOAD_DIR . '/slips');

// Ensure upload directories exist
foreach ([UPLOAD_DIR, METER_UPLOAD_DIR, SLIP_UPLOAD_DIR] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

// Ensure logs directory exists in production
if ($_isProduction && !is_dir(APP_ROOT . '/logs')) {
    mkdir(APP_ROOT . '/logs', 0755, true);
}

// ============================================================
// Database Configuration
// ============================================================
define('DB_HOST', $_ENV_CONFIG['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV_CONFIG['DB_NAME'] ?? 'smartbill');
define('DB_USER', $_ENV_CONFIG['DB_USER'] ?? 'root');
define('DB_PASS', $_ENV_CONFIG['DB_PASS'] ?? '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO database connection (singleton)
 */
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('SmartBill DB Error: ' . $e->getMessage());
            die(json_encode(['error' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้']));
        }
    }
    return $pdo;
}

// ============================================================
// Session Configuration
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    if ($_isProduction) {
        ini_set('session.cookie_secure', 1);
        ini_set('session.cookie_samesite', 'Lax');
    }
    session_start();
}

// ============================================================
// CSRF Token
// ============================================================
function generateCSRFToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRFToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ============================================================
// System Settings Helper
// ============================================================
function getSetting(string $key, $default = null) {
    global $_settingsCache;
    if (!isset($_settingsCache)) $_settingsCache = [];
    if (isset($_settingsCache[$key])) return $_settingsCache[$key];
    
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT setting_value, setting_type FROM system_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if (!$row) return $default;
        
        $value = $row['setting_value'];
        switch ($row['setting_type']) {
            case 'number': $value = floatval($value); break;
            case 'boolean': $value = filter_var($value, FILTER_VALIDATE_BOOLEAN); break;
            case 'json': $value = json_decode($value, true); break;
        }
        
        $_settingsCache[$key] = $value;
        return $value;
    } catch (Exception $e) {
        return $default;
    }
}

function setSetting(string $key, $value, string $type = 'string', int $userId = null): bool {
    try {
        $db = getDB();
        $stmt = $db->prepare("
            INSERT INTO system_settings (setting_key, setting_value, setting_type, updated_by)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), 
                                    setting_type = VALUES(setting_type),
                                    updated_by = VALUES(updated_by)
        ");
        $result = $stmt->execute([$key, is_array($value) ? json_encode($value) : (string)$value, $type, $userId]);
        
        // Clear cached value so subsequent getSetting() calls return fresh data
        clearSettingCache($key);
        
        return $result;
    } catch (Exception $e) {
        return false;
    }
}

function clearSettingCache(string $key = null): void {
    global $_settingsCache;
    if ($key === null) {
        $_settingsCache = [];
    } else {
        unset($_settingsCache[$key]);
    }
}
