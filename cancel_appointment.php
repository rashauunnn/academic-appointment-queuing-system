<?php
// cancel_appointment.php
require_once 'db_connect.php';
session_start();

// Authentication Guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['user_id'];
$app_id = $_GET['app_id'] ?? null;

if ($app_id) {
    try {
        // Verify ownership and ensure the status is still 'Pending'
        $stmt = $pdo->prepare("SELECT * FROM appointments WHERE app_id = ? AND student_id = ?");
        $stmt->execute([$app_id, $student_id]);
        $appointment = $stmt->fetch();

        if ($appointment && $appointment['status'] === 'Pending') {
            // Update status to 'Cancelled'
            $update_stmt = $pdo->prepare("UPDATE appointments SET status = 'Cancelled' WHERE app_id = ?");
            $update_stmt->execute([$app_id]);
            
            // Redirect with success message
            header("Location: student_dashboard.php?msg=cancelled");
            exit();
        } else {
            // Unauthorized or invalid status for cancellation
            header("Location: student_dashboard.php?error=cannot_cancel");
            exit();
        }
    } catch (PDOException $e) {
        die("System Error: " . $e->getMessage());
    }
} else {
    header("Location: student_dashboard.php");
    exit();
}
?>
