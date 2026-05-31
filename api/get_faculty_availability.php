<?php
require_once '../security_headers.php';
require_once '../session_helper.php';
require_once '../db_connect.php';

header('Content-Type: application/json');

$student_id = $_SESSION['student_id'] ?? ($_SESSION['user_id'] ?? null);
if (!$student_id) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}


$faculty_id = $_GET['faculty_id'] ?? null;
$month = $_GET['month'] ?? null;
$day = $_GET['day'] ?? null;

if (!$faculty_id || !$month || !$day) {
    echo json_encode(['error' => 'Missing parameters']);
    exit();
}

$year = date('Y');
$date_str = sprintf("%s-%02d-%02d", $year, $month, $day);

try {
    // Auto-revert expired busy status before checking
    try {
        $now_str = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE users SET current_status = 'Available', busy_until = NULL WHERE current_status = 'Busy' AND busy_until <= ?")->execute([$now_str]);
    } catch (PDOException $pe) {}

    // 1. Fetch faculty's unavailable slots from faculty_availability
    $stmt = $pdo->prepare("SELECT start_time, end_time, reason FROM faculty_availability WHERE faculty_id = ? AND unavailable_date = ?");
    $stmt->execute([$faculty_id, $date_str]);
    $unavailable = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch already booked appointments for that day
    $booked_stmt = $pdo->prepare("SELECT time_slot FROM appointments WHERE faculty_id = ? AND appointment_date = ? AND status IN ('Pending', 'Approved', 'Accepted', 'Active')");
    $booked_stmt->execute([$faculty_id, $date_str]);
    $booked = $booked_stmt->fetchAll(PDO::FETCH_COLUMN);

    // 3. Fetch faculty's current status from users table
    $status_stmt = $pdo->prepare("SELECT current_status, busy_until FROM users WHERE user_id = ?");
    $status_stmt->execute([$faculty_id]);
    $status_data = $status_stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_status = $status_data['current_status'] ?? 'Available';
    $busy_until = $status_data['busy_until'] ?? null;

    echo json_encode([
        'unavailable_slots' => $unavailable,
        'booked_slots' => $booked,
        'faculty_status' => $faculty_status,
        'busy_until' => $busy_until
    ]);

} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
