<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once 'db_connect.php';

// Para siguradong JSON ang ibabalik natin sa JS
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Session expired. Please login again.']);
    exit();
}

// BASAHIN ANG JSON DATA MULA SA JS FETCH
$json_input = json_decode(file_get_contents('php://input'), true);

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Kunin ang ID kahit 'appointment_id' o 'app_id' ang gamit
$app_id = $json_input['appointment_id'] ?? $_POST['app_id'] ?? null;
$cancel_reason = $json_input['cancel_reason'] ?? $_POST['cancel_reason'] ?? 'No reason provided';

if (!$app_id) {
    echo json_encode(['success' => false, 'error' => 'Missing Appointment ID.']);
    exit();
}

try {
    // 1. I-verify muna kung sa user nga itong appointment na ito
    if ($user_role === 'Student') {
        $stmt = $pdo->prepare("SELECT status FROM appointments WHERE app_id = ? AND student_id = ?");
    } else {
        $stmt = $pdo->prepare("SELECT status FROM appointments WHERE app_id = ? AND faculty_id = ?");
    }
    
    $stmt->execute([$app_id, $user_id]);
    $appointment = $stmt->fetch();

    if (!$appointment) {
        echo json_encode(['success' => false, 'error' => 'Appointment record not found.']);
        exit();
    }

    // 2. Update status to Cancelled
    $update_stmt = $pdo->prepare("UPDATE appointments SET status = 'Cancelled', cancel_reason = ?, cancelled_by = ? WHERE app_id = ?");
    $result = $update_stmt->execute([$cancel_reason, $user_role, $app_id]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Engagement terminated successfully.']);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update database.']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database Error: ' . $e->getMessage()]);
}
exit();