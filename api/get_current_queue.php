<?php
// api/get_current_queue.php
require_once '../db_connect.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

try {
    // 1. Current Serving Global Count (Active appointments)
    $serving_stmt = $pdo->prepare("SELECT count(*) as count FROM appointments WHERE status = 'Active'");
    $serving_stmt->execute();
    $serving_count = $serving_stmt->fetch()['count'];

    // 2. User Specific Status
    $user_queue_pos = 0;
    $status = 'Idle';
    $faculty_name = 'N/A';

    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as faculty_name 
        FROM appointments a 
        JOIN users u ON a.faculty_id = u.user_id 
        WHERE a.student_id = ? AND a.status NOT IN ('Completed', 'Cancelled', 'No-Show')
        ORDER BY a.created_at DESC LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $active_appointment = $stmt->fetch();

    if ($active_appointment) {
        $status = $active_appointment['status'];
        $faculty_name = $active_appointment['faculty_name'];

        // Position in queue (count pending/accepted before them)
        $pos_stmt = $pdo->prepare("
            SELECT count(*) + 1 as pos 
            FROM appointments 
            WHERE status IN ('Pending', 'Accepted', 'Active') 
            AND created_at < ?
        ");
        $pos_stmt->execute([$active_appointment['created_at']]);
        $user_queue_pos = $pos_stmt->fetch()['pos'];
    }

    echo json_encode([
        'success' => true,
        'serving_no' => sprintf("%03d", $serving_count),
        'your_no' => $user_queue_pos > 0 ? sprintf("%03d", $user_queue_pos) : "---",
        'status' => $status,
        'faculty' => $faculty_name,
        'estimated_wait' => $user_queue_pos * 15
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
