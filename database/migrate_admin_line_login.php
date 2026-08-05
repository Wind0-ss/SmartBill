<?php
/**
 * Migration: Support LINE Login and Admin Invitations
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDB();

echo "Starting database migration...\n";

// 1. Add line_user_id column to users table
try {
    $db->exec("
        ALTER TABLE `users` 
        ADD COLUMN `line_user_id` VARCHAR(100) UNIQUE NULL 
        AFTER `avatar`
    ");
    echo "✔ Successfully added 'line_user_id' column to 'users' table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
        echo "ℹ Column 'line_user_id' already exists in 'users' table.\n";
    } else {
        echo "❌ Error adding 'line_user_id' column: " . $e->getMessage() . "\n";
    }
}

// 2. Create admin_invitations table
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `admin_invitations` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `token` VARCHAR(64) NOT NULL UNIQUE,
            `role_id` INT UNSIGNED NOT NULL DEFAULT 2 COMMENT '2=admin, 3=staff',
            `is_used` TINYINT(1) DEFAULT 0,
            `used_by` INT UNSIGNED NULL,
            `expires_at` DATETIME NOT NULL,
            `created_by` INT UNSIGNED NOT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`used_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB
    ");
    echo "✔ Successfully created/verified 'admin_invitations' table.\n";
} catch (PDOException $e) {
    echo "❌ Error creating 'admin_invitations' table: " . $e->getMessage() . "\n";
}

// 3. Seed default system settings for LINE Login if not exist
try {
    $db->exec("
        INSERT INTO `system_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
        VALUES 
        ('line_login_channel_id', '', 'string', 'LINE Login Channel ID'),
        ('line_login_channel_secret', '', 'string', 'LINE Login Channel Secret')
        ON DUPLICATE KEY UPDATE description=VALUES(description)
    ");
    echo "✔ Successfully seeded system settings for LINE Login.\n";
} catch (PDOException $e) {
    echo "❌ Error seeding system settings: " . $e->getMessage() . "\n";
}

echo "Database migration finished.\n";
