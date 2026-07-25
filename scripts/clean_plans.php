<?php
require_once __DIR__ . '/../src/Core/Database.php';

echo "Cleaning up duplicate plans...\n";

try {
    $db = Database::getInstance();
    
    // Find duplicates (same name, higher ID)
    $sql = "DELETE p1 FROM plans p1
            INNER JOIN plans p2 
            WHERE p1.id > p2.id AND p1.name = p2.name";
            
    $stmt = $db->query($sql);
    $count = $stmt->rowCount();
    
    echo "Deleted {$count} duplicate plans.\n";
    
    // Check remaining plans
    $plans = $db->fetchAll("SELECT * FROM plans ORDER BY id");
    foreach ($plans as $p) {
        echo "ID: {$p['id']}, Name: {$p['name']}, Price: {$p['price_monthly']}\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
