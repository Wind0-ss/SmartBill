<?php
/**
 * Migration: Add invoice_type column to invoices table
 */
require_once dirname(__DIR__) . '/includes/config.php';
require_once dirname(__DIR__) . '/includes/helpers.php';

$db = getDB();

try {
    // 1. Add invoice_type column if it doesn't exist
    $db->exec("
        ALTER TABLE `invoices` 
        ADD COLUMN `invoice_type` ENUM('rent', 'utility') NOT NULL DEFAULT 'rent' 
        AFTER `billing_month`
    ");
    echo "Successfully added 'invoice_type' column to invoices table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'duplicate column') !== false || strpos($e->getMessage(), 'already exists') !== false) {
        echo "Column 'invoice_type' already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

// 2. Classify existing invoices based on their items
try {
    $invoices = $db->query("SELECT id FROM invoices")->fetchAll();
    foreach ($invoices as $inv) {
        $invId = $inv['id'];
        
        // Check what types of items this invoice has
        $stmt = $db->prepare("SELECT item_type FROM invoice_items WHERE invoice_id = ?");
        $stmt->execute([$invId]);
        $items = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $hasRent = in_array('rent', $items) || in_array('other', $items);
        $hasUtility = in_array('water', $items) || in_array('electric', $items);
        
        if ($hasUtility && !$hasRent) {
            // It's a utility invoice
            $db->prepare("UPDATE invoices SET invoice_type = 'utility' WHERE id = ?")->execute([$invId]);
        }
    }
    echo "Successfully updated existing invoices to appropriate types.\n";
} catch (PDOException $e) {
    echo "Error updating existing records: " . $e->getMessage() . "\n";
}
