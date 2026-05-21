<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// booking_process.php
require_once 'db_connect.php';

// Authentication Guard
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_SESSION['student_id'];
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
        header("Location: book_appointment.php?error=past_date");
        exit();
    }

    $appointment_date = sprintf("%s-%02d-%02d", $current_year, $month, $day);

    if (empty($faculty_id) || empty($month) || empty($day) || empty($time_slot) || empty($reason)) {
        header("Location: book_appointment.php?error=missing_fields");
        exit();
    }

    try {
        // First, ensure the columns exist (Safety check for migration)
        // This is a one-time thing usually but helps if migration tool isn't run manually
        $pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS appointment_date DATE AFTER reason");
        $pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS time_slot VARCHAR(50) AFTER appointment_date");

        // Auto-revert expired busy status before checking
        try {
            $now_str = date('Y-m-d H:i:s');
            $pdo->prepare("UPDATE users SET current_status = 'Available', busy_until = NULL WHERE current_status = 'Busy' AND busy_until <= ?")->execute([$now_str]);
        } catch (PDOException $pe) {}

        // Strict Status Lock: Prevent booking Busy or On Leave instructors
        $status_stmt = $pdo->prepare("SELECT current_status FROM users WHERE user_id = ?");
        $status_stmt->execute([$faculty_id]);
        $faculty_status = $status_stmt->fetchColumn() ?: 'Available';
        $faculty_status_lower = str_replace(' ', '_', strtolower($faculty_status));
        if ($faculty_status_lower === 'busy' || $faculty_status_lower === 'on_leave') {
            header("Location: book_appointment.php?error=faculty_unavailable");
            exit();
        }

        // 1. Past Time Validation
        $today = date('Y-m-d');
        if ($appointment_date === $today) {
            $currentHour = (int)date('H');
            
            // Extract starting hour from slot (e.g., "09:00 AM" -> 9, "01:00 PM" -> 13)
            $slotStartStr = explode(' - ', $time_slot)[0];
            $slotHour = (int)date('H', strtotime($slotStartStr));
            
            if ($slotHour <= $currentHour) {
                header("Location: book_appointment.php?error=past_time");
                exit();
            }
        }

        // 2. Professor Unavailability Check
        $slotStartStr = explode(' - ', $time_slot)[0];
        $slotHour = (int)date('H', strtotime($slotStartStr));
        
        $avail_check = $pdo->prepare("
            SELECT COUNT(*) 
            FROM faculty_availability 
            WHERE faculty_id = ? 
            AND unavailable_date = ? 
            AND HOUR(start_time) <= ? 
            AND HOUR(end_time) > ?
        ");
        $avail_check->execute([$faculty_id, $appointment_date, $slotHour, $slotHour]);
        
        if ($avail_check->fetchColumn() > 0) {
            header("Location: book_appointment.php?error=prof_unavailable");
            exit();
        }

        // 3. Conflict Check
        // Check if the faculty already has an appointment at that specific date and time slot
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
            // Conflict found
            header("Location: book_appointment.php?error=slot_taken");
            exit();
        }

        // 2. Insertion Logic
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

        header("Location: book_appointment.php?success=appointment_booked");
        exit();

    } catch (PDOException $e) {
        // Log the error or handle it
        die("Booking Error: " . $e->getMessage());
    }
} else {
    header("Location: book_appointment.php");
    exit();
}
?>
