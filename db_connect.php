<?php
// db_connect.php
$host = 'localhost';
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
     // Gumawa tayo ng dalawang variables para sa compatibility
     $pdo = new PDO($dsn, $user, $pass, $options);
     $conn = $pdo; // Eto ang bridge! Kahit alin sa dalawa ang tawagin, gagana.
     
} catch (\PDOException $e) {
     die("Connection failed: " . $e->getMessage());
}
?>