<?php
require_once 'db_connect.php';

try {
    $rows = $pdo->query('SELECT COUNT(*) AS total FROM appointments')->fetch();
    echo "total={$rows['total']}\n";
    foreach ($pdo->query('SHOW COLUMNS FROM appointments') as $col) {
        echo "{$col['Field']} {$col['Type']} {$col['Null']} default:{$col['Default']}\n";
    }
    echo "---\n";
    foreach ($pdo->query('SELECT app_id, student_id, faculty_id, appointment_date, time_slot, status, created_at FROM appointments ORDER BY created_at DESC LIMIT 5') as $row) {
        echo json_encode($row) . "\n";
    }
} catch (PDOException $e) {
    echo 'ERROR: ' . $e->getMessage() . "\n";
}
