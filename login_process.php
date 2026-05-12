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
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            switch ($user['role']) {
                case 'Student':
                    header("Location: student_dashboard.php");
                    break;
                case 'Faculty':
                    header("Location: faculty_dashboard.php");
                    break;
                case 'Admin':
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