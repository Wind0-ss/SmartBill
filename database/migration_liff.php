<?php
/**
 * Migration: Add LIFF support + tenant LINE registration
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDB();

echo "🔄 Running LIFF migration...\n";

try {
    // 1. Add LIFF ID setting
    $db->prepare("
        INSERT INTO system_settings (setting_key, setting_value, setting_type, description)
        VALUES ('liff_id', '', 'string', 'LINE LIFF App ID')
        ON DUPLICATE KEY UPDATE setting_key = setting_key
    ")->execute();
    echo "✅ Added liff_id setting\n";

    // 2. Add registration_token column to tenants (if not exists)
    $cols = $db->query("SHOW COLUMNS FROM tenants LIKE 'registration_token'")->fetchAll();
    if (empty($cols)) {
        $db->exec("ALTER TABLE tenants ADD COLUMN registration_token VARCHAR(100) NULL AFTER line_user_id");
        echo "✅ Added registration_token column to tenants\n";
    } else {
        echo "⏭️ registration_token column already exists\n";
    }

    // 3. Create tenant_line_sessions table for registration flow
    $db->exec("
        CREATE TABLE IF NOT EXISTS tenant_line_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            line_user_id VARCHAR(100) NOT NULL,
            step ENUM('ask_room','ask_phone','done') DEFAULT 'ask_room',
            room_number VARCHAR(20),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_line_user (line_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "✅ Created tenant_line_sessions table\n";

    echo "\n🎉 Migration completed successfully!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
