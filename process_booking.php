<?php
require_once 'security_headers.php';
require_once 'session_helper.php';
require_once 'db_connect.php';

header('Content-Type: application/json');

// Authentication Guard (support both session keys)
// IMPORTANT: booking_page.php/JS uses process_booking.php (AJAX). 
// Some flows store student id in student_id and may not have role set consistently.
$student_id = $_SESSION['student_id'] ?? ($_SESSION['user_id'] ?? null);
$role = $_SESSION['role'] ?? null;
if (!$student_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}
// Do not hard-require $_SESSION['role'] here.
// Some role/session isolation setups may not populate role reliably for AJAX.



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Always respond JSON for this endpoint
    $wants_json = true;

    // Ensure PDO exists
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        echo json_encode(['success' => false, 'message' => 'Server Error: Database connection not available.']);
        exit();
    }

    $faculty_id = $_POST['faculty_id'] ?? '';
    $month = $_POST['appointment_month'] ?? '';
    $day = $_POST['appointment_day'] ?? '';
    $time_slot = $_POST['time_slot'] ?? '';
    $reason = $_POST['reason'] ?? '';


    // Construct standard YYYY-MM-DD date
    $current_year = date('Y');
    $current_month = (int)date('n');
    $current_day = (int)date('j');
    
    // Server-side Guard: Prevent booking past days of current month
    if ($month < $current_month || ($month == $current_month && $day < $current_day)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date selection. You cannot book a date in the past.']);
        exit();
    }


    $appointment_date = sprintf("%s-%02d-%02d", $current_year, $month, $day);

    if (empty($faculty_id) || empty($month) || empty($day) || empty($time_slot) || empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'All fields are required.']);
        exit();
    }


    try {
        // First, ensure columns exist
        $pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS appointment_date DATE AFTER reason");
        $pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS time_slot VARCHAR(50) AFTER appointment_date");

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
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => "On Leave: This instructor is not accepting appointments right now."]);
                exit();
            }
            header("Location: booking_page.php?error=faculty_unavailable&status=" . urlencode("On Leave"));
            exit();
        }

        if ($faculty_status_lower === 'busy') {
            $today = date('Y-m-d');
            if ($appointment_date === $today && $busy_until) {
                $slotStartStr = explode(' - ', $time_slot)[0];
                $slot_timestamp = strtotime($appointment_date . ' ' . $slotStartStr);
                $busy_until_timestamp = strtotime($busy_until);
                if ($slot_timestamp < $busy_until_timestamp) {
                    if ($wants_json) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => "Busy: This instructor is unavailable until " . date('h:i A', $busy_until_timestamp) . ". Please select a later slot."]);
                        exit();
                    }
                    header("Location: booking_page.php?error=faculty_unavailable&status=" . urlencode("Busy") . "&busy_until=" . urlencode($busy_until));
                    exit();
                }
            }
        }

        // 1. Past Time Validation
        $today = date('Y-m-d');
        if ($appointment_date === $today) {
            $currentHour = (int)date('H');
            $slotStartStr = explode(' - ', $time_slot)[0];
            $slotHour = (int)date('H', strtotime($slotStartStr));
            
            if ($slotHour <= $currentHour) {
                if ($wants_json) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => 'You cannot book a time slot that has already passed for today.']);
                    exit();
                }
                header("Location: booking_page.php?error=past_time");
                exit();
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
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Professor is unavailable during this time slot (e.g., On Break or in a Meeting).']);
                exit();
            }
            header("Location: booking_page.php?error=prof_unavailable");
            exit();
        }

        // 3. Conflict Check
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
            if ($wants_json) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'This time slot is already booked. Please choose another.']);
                exit();
            }
            header("Location: booking_page.php?error=slot_taken");
            exit();
        }

        // 4. Insertion Logic
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

        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Appointment request submitted successfully!']);
            exit();
        }

        header("Location: booking_page.php?success=appointment_booked");
        exit();

    } catch (PDOException $e) {
        if ($wants_json) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Booking Error: ' . $e->getMessage()]);
            exit();
        }
        die("Booking Error: " . $e->getMessage());
    }
} else {
    header("Location: booking_page.php");
    exit();
}
?>
