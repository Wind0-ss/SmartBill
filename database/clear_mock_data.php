<?php
/**
 * Script to clear all mock/testing data from the database
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

if (php_sapi_name() !== 'cli') {
    die("This script can only be run via the CLI (command line) for security reasons.\n");
}

$db = getDB();

echo "Preparing to delete mock data...\n";

try {
    // Disable foreign key checks temporarily
    $db->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // List of tables to truncate completely
    $tablesToTruncate = [
        'activity_logs',
        'notifications',
        'payment_slips',
        'payments',
        'invoice_items',
        'invoices',
        'meter_readings',
        'meters',
        'leases',
        'tenants',
        'rooms',
        'floors',
        'buildings',
        'building_meters',
        'admin_invitations'
    ];
    
    foreach ($tablesToTruncate as $table) {
        $db->exec("TRUNCATE TABLE `$table`");
        echo "✔ Truncated table: $table\n";
    }
    
    // Delete all users except the default admin (id = 1)
    $db->exec("DELETE FROM `users` WHERE `id` > 1");
    echo "✔ Cleaned 'users' table (kept default admin user).\n";
    
    // Reset utility rates and add defaults
    $db->exec("TRUNCATE TABLE `utility_rates`");
    $db->exec("
        INSERT INTO `utility_rates` (`rate_type`, `building_id`, `rate_per_unit`, `effective_date`) VALUES
        ('water', NULL, 18.00, '2024-01-01'),
        ('electric', NULL, 7.50, '2024-01-01')
    ");
    echo "✔ Reset 'utility_rates' to default values.\n";
    
    // Re-enable foreign key checks
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    echo "\n🎉 Successfully deleted all mock data and initialized database to a clean state!\n";
} catch (Exception $e) {
    // Re-enable foreign key checks in case of error
    $db->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "❌ Error clearing mock data: " . $e->getMessage() . "\n";
}
