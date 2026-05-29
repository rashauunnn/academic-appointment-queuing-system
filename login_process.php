<?php
require_once 'security_headers.php';
require_once 'db_connect.php';

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

        // Block login until email is verified
        if ($user && empty($user['email_verified_at']) && ($user['role'] !== 'Admin')) {
            header("Location: login.php?error=verify_required");
            exit();
        }

        if ($user && ($password === $user['password'] || password_verify($password, $user['password']))) {
            // IMPORTANT: role-isolated session must be chosen before starting the PHP session,
            // otherwise Student/Faculty session data is written to a different PHPSESSID_*.
            $role = $user['role'];

            setcookie('ACTIVE_ROLE_SESSION', $role, [
                'expires' => 0,
                'path' => '/',
                'domain' => '',
                'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
                'httponly' => true,
                'samesite' => 'Lax'
            ]);

            if (session_status() === PHP_SESSION_NONE) {
                $session_name = match ($role) {
                    'Admin' => 'PHPSESSID_ADMIN',
                    'Faculty' => 'PHPSESSID_FACULTY',
                    'Student' => 'PHPSESSID_STUDENT',
                    default => 'PHPSESSID_NEUTRAL',
                };

                session_name($session_name);

                $is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
                    (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $is_secure,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);

                session_start();
            }

            // Set Core Session Data
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $role;
            $_SESSION['last_activity'] = time();
            $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
            $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

            // Maintenance Mode Check (Non-Admins)
            if ($role !== 'Admin') {
                try {
                    $m_status = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
                    if ($m_status === '1') {
                        header("Location: maintenance.php");
                        exit();
                    }
                } catch (PDOException $e) {}
            }

            // Role specific aliases (legacy code)
            switch ($role) {
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
                    header("Location: login.php?error=invalid_credentials");
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

