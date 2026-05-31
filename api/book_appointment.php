<?php
require_once '../security_headers.php';
require_once '../session_helper.php';
require_once '../db_connect.php';

header('Content-Type: application/json');

$student_id = $_SESSION['student_id'] ?? ($_SESSION['user_id'] ?? null);
if (!$student_id) {
    // Debug hint: helps pinpoint session isolation problems in dev
    echo json_encode(['success' => false, 'message' => 'Unauthorized', 'debug' => ['has_student_id' => isset($_SESSION['student_id']), 'has_user_id' => isset($_SESSION['user_id']), 'active_role_session' => ($_COOKIE['ACTIVE_ROLE_SESSION'] ?? null), 'session_role' => ($_SESSION['role'] ?? null)]]);
    exit();
}



$faculty_id = $_POST['faculty_id'] ?? '';
$month = $_POST['appointment_month'] ?? '';
$day = $_POST['appointment_day'] ?? '';
$time_slot = $_POST['time_slot'] ?? '';
$reason = $_POST['reason'] ?? '';

if (empty($faculty_id) || empty($month) || empty($day) || empty($time_slot) || empty($reason)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit();
}

// 1. Date Restrictions (Prevent past dates)
$current_year = date('Y');
$current_month = (int)date('n');
$current_day = (int)date('j');
$current_hour = (int)date('H');

$appointment_date = sprintf("%s-%02d-%02d", $current_year, $month, $day);
$today = date('Y-m-d');

if ($appointment_date < $today) {
    echo json_encode(['success' => false, 'message' => 'Past dates are not permitted for booking.']);
    exit();
}

if ($appointment_date === $today) {
    // Extract start hour
    $slotStartStr = explode(' - ', $time_slot)[0];
    $slotHour = (int)date('H', strtotime($slotStartStr));
    if ($slotHour <= $current_hour) {
        echo json_encode(['success' => false, 'message' => 'This time slot has already passed for today.']);
        exit();
    }
}

try {
    // Auto-revert expired busy status before checking
    try {
        $now_str = date('Y-m-d H:i:s');
        $pdo->prepare("UPDATE users SET current_status = 'Available', busy_until = NULL WHERE current_status = 'Busy' AND busy_until <= ?")->execute([$now_str]);
    } catch (PDOException $pe) {}

    // Strict Status Lock: prevent booking On Leave or during an active Busy window.
    $status_stmt = $pdo->prepare("SELECT current_status, busy_until FROM users WHERE user_id = ?");
    $status_stmt->execute([$faculty_id]);
    $faculty_row = $status_stmt->fetch(PDO::FETCH_ASSOC);
    $faculty_status = $faculty_row['current_status'] ?? 'Available';
    $busy_until = $faculty_row['busy_until'] ?? null;
    $faculty_status_lower = str_replace(' ', '_', strtolower($faculty_status));

    if ($faculty_status_lower === 'on_leave') {
        echo json_encode(['success' => false, 'message' => 'On Leave: This instructor is not accepting appointments right now.']);
        exit();
    }

    if ($faculty_status_lower === 'busy') {
        $today = date('Y-m-d');
        if ($appointment_date === $today && $busy_until) {
            $slotStartStr = explode(' - ', $time_slot)[0];
            $slot_timestamp = strtotime($appointment_date . ' ' . $slotStartStr);
            $busy_until_timestamp = strtotime($busy_until);
            if ($slot_timestamp < $busy_until_timestamp) {
                echo json_encode(['success' => false, 'message' => 'Busy: This instructor is unavailable until ' . date('h:i A', $busy_until_timestamp) . '. Please select a slot that begins after this time.']);
                exit();
            }
        }
    }

    // 2. Professor Unavailability Check
    $slotParts = explode(' - ', $time_slot);
    $slotStartStr = $slotParts[0];
    $slotEndStr = $slotParts[1] ?? $slotStartStr;

    $slotStartTime = date('H:i:s', strtotime($appointment_date . ' ' . $slotStartStr));
    $slotEndTime = date('H:i:s', strtotime($appointment_date . ' ' . $slotEndStr));

    $avail_check = $pdo->prepare("
        SELECT COUNT(*)
        FROM faculty_availability
        WHERE faculty_id = ?
        AND unavailable_date = ?
        AND start_time < ?
        AND end_time > ?
    ");
    $avail_check->execute([$faculty_id, $appointment_date, $slotEndTime, $slotStartTime]);
    
    if ($avail_check->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Conflict detected: The professor has marked this slot as unavailable (Break/Meeting).']);
        exit();
    }

    // 3. Conflict Guard (Anti-Double Booking)
    $check_stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM appointments 
        WHERE faculty_id = :faculty_id 
        AND appointment_date = :appointment_date 
        AND time_slot = :time_slot 
        AND status IN ('Pending', 'Approved', 'Accepted', 'Active')
    ");
    
    $check_stmt->execute([
        ':faculty_id' => $faculty_id,
        ':appointment_date' => $appointment_date,
        ':time_slot' => $time_slot
    ]);

    if ($check_stmt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Conflict Guard: This slot was just secured by another student. Please pick a different time.']);
        exit();
    }

    // 4. Insertion
    $insert_stmt = $pdo->prepare("
        INSERT INTO appointments (student_id, faculty_id, reason, appointment_date, time_slot, status) 
        VALUES (:student_id, :faculty_id, :reason, :appointment_date, :time_slot, 'Pending')
    ");
    
    $insert_stmt->execute([
        ':student_id' => $student_id,
        ':faculty_id' => $faculty_id,
        ':reason' => $reason,
        ':appointment_date' => $appointment_date,
        ':time_slot' => $time_slot
    ]);

    echo json_encode(['success' => true, 'message' => 'Appointment secured successfully!']);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Server Error: ' . $e->getMessage()]);
}
