<?php
// login_process.php
require_once 'db_connect.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $school_id = $_POST['school_id'] ?? '';
    $password = $_POST['password'] ?? '';

    if (empty($school_id) || empty($password)) {
        header("Location: login.php?error=empty_fields");
        exit();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE school_id = ?");
        $stmt->execute([$school_id]);
        $user = $stmt->fetch();

        // Assuming plain text passwords for now as per your initial setup, 
        // but password_verify() is recommended for production.
        if ($user && $password === $user['password']) {
            // Role-specific session handling to avoid conflicts
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            switch ($user['role']) {
                case 'Student':
                    $_SESSION['student_id'] = $user['user_id'];
                    $_SESSION['student_name'] = $user['full_name'];
                    header("Location: student_dashboard.php");
                    break;
                case 'Faculty':
                    $_SESSION['faculty_id'] = $user['user_id'];
                    $_SESSION['faculty_name'] = $user['full_name'];
                    header("Location: faculty_dashboard.php");
                    break;
                case 'Admin':
                    $_SESSION['admin_id'] = $user['user_id'];
                    $_SESSION['admin_name'] = $user['full_name'];
                    header("Location: admin_dashboard.php");
                    break;
                default:
                    header("Location: login.php?error=invalid_role");
                    break;
            }
            exit();
        } else {
            header("Location: login.php?error=invalid_credentials");
            exit();
        }
    } catch (PDOException $e) {
        die("System Error: " . $e->getMessage());
    }
} else {
    header("Location: login.php");
    exit();
}
?>
