<?php
// db_connect.php

$host = 'localhost';
// Fixed: Changed from 'academic_appointment_system' to your actual database name
$db   = 'academic_appointment_system'; 
$user = 'root';                        
$pass = '';                            
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     // Connection successful
} catch (\PDOException $e) {
     // This will catch the "Unknown database" error if the name still doesn't match
     die("Connection failed: " . $e->getMessage());
}
?>