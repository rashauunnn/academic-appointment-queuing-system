<?php
// db_connect.php
$host = 'sql308.infinityfree.com'; // REMINDER: Yung MySQL Hostname, hindi yung website URL
$dbname = 'if0_41895345_appointment_db'; 
$username = 'if0_41895345'; 
$password = 'gkSoZNqwaCu';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}
?>