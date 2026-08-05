<?php
// Test Thai encoding from database
$pdo = new PDO('mysql:host=localhost;dbname=smartbill;charset=utf8mb4', 'root', '');
$pdo->exec('SET NAMES utf8mb4');

echo "=== Tenants ===" . PHP_EOL;
foreach ($pdo->query('SELECT first_name, last_name FROM tenants LIMIT 3') as $r) {
    echo "  " . $r['first_name'] . " " . $r['last_name'] . PHP_EOL;
}

echo "=== Roles ===" . PHP_EOL;
foreach ($pdo->query('SELECT display_name FROM roles') as $r) {
    echo "  " . $r['display_name'] . PHP_EOL;
}

echo "=== Settings ===" . PHP_EOL;
foreach ($pdo->query("SELECT setting_key, description FROM system_settings WHERE description != '' LIMIT 3") as $r) {
    echo "  " . $r['setting_key'] . ": " . $r['description'] . PHP_EOL;
}
