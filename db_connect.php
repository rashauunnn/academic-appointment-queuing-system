<?php
// db_connect.php
$host = '127.0.0.1'; // Changed from localhost for better compatibility
$port = '3307';      // This MUST match the port you set in XAMPP
$db   = 'academic_appointment_system'; 
$user = 'root';                        
$pass = '';                            
$charset = 'utf8mb4';

// Updated DSN line to include the port
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
     $conn = $pdo; 
     
} catch (\PDOException $e) {
     // This will now tell you EXACTLY why it failed instead of just hanging
     die("Connection failed: " . $e->getMessage());
}
?>