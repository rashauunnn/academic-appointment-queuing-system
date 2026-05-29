<?php
require_once 'session_helper.php';
require_once 'db_connect.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Faculty') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit();
}

$faculty_id = $_SESSION['user_id'];
$status_val = $_POST['status_val'] ?? $_GET['status_val'] ?? null;
$status_msg = $_POST['status_message'] ?? $_GET['status_message'] ?? null;
$duration = $_POST['duration_hours'] ?? null;

function duration_to_seconds($duration) {
    if (!is_numeric($duration)) {
        return 0;
    }

    return max(60, (int)round((float)$duration * 3600));
}

if ($status_val !== null || $status_msg !== null) {
    try {
        if ($status_msg !== null) {
            $stmt = $pdo->prepare("UPDATE users SET status_message = ? WHERE user_id = ?");
            $stmt->execute([$status_msg, $faculty_id]);
        }

        if ($status_val !== null) {
            $status_lower = strtolower($status_val);
            if ($status_lower === 'busy' && $duration) {
                $duration_seconds = duration_to_seconds($duration);
                $stmt = $pdo->prepare("UPDATE users SET current_status = 'Busy', busy_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE user_id = ?");
                $stmt->execute([$duration_seconds, $faculty_id]);
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
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true, 
                'message' => 'Status and/or status message updated successfully.',
                'status' => $status_val ?? null,
                'status_message' => $status_msg ?? null
            ]);
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
