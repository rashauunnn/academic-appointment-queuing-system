<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Faculty') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$faculty_id = $_SESSION['user_id'];
$status_val = $_POST['status_val'] ?? $_GET['status_val'] ?? null;
$duration = $_POST['duration_hours'] ?? null;

if ($status_val) {
    try {
        $status_lower = strtolower($status_val);
        if ($status_lower === 'busy' && $duration) {
            $busy_until_str = date('Y-m-d H:i:s', time() + ((int)$duration * 3600));
            $stmt = $pdo->prepare("UPDATE users SET current_status = 'Busy', busy_until = ? WHERE user_id = ?");
            $stmt->execute([$busy_until_str, $faculty_id]);
        } else {
            $normalized_status = 'Available';
            if ($status_lower === 'busy') {
                $normalized_status = 'Busy';
            } elseif ($status_lower === 'on leave' || $status_lower === 'on_leave') {
                $normalized_status = 'On Leave';
            }
            $stmt = $pdo->prepare("UPDATE users SET current_status = ?, busy_until = NULL WHERE user_id = ?");
            $stmt->execute([$normalized_status, $faculty_id]);
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Status updated to ' . $status_val]);
            exit();
        }

        header("Location: faculty_dashboard.php?msg=status_updated");
        exit();
    } catch (PDOException $e) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit();
        }
        die("Database error: " . $e->getMessage());
    }
} else {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Missing status value.']);
    exit();
}
?>
