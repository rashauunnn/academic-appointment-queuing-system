<?php
// session_helper.php
if (session_status() === PHP_SESSION_NONE) {
    // Set secure cookie parameters
    $is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    session_set_cookie_params([
        'lifetime' => 0, // Session cookie
        'path' => '/',
        'domain' => '',
        'secure' => $is_secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

/**
 * Validates the session and checks the required role.
 * Redirects to login or correct dashboard if unauthorized.
 */
function check_session_role($required_role) {
    // 1. Basic Auth Check
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        // Only redirect if NOT on login.php already (to avoid potential loops)
        if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
            header("Location: login.php");
            exit();
        }
        return;
    }

    // 2. Session Timeout (e.g., 30 minutes of inactivity)
    $timeout_duration = 1800; 
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        session_unset();
        session_destroy();
        header("Location: login.php?error=timeout");
        exit();
    }
    $_SESSION['last_activity'] = time();

    // 3. Role Isolation Check (RBAC)
    if ($_SESSION['role'] !== $required_role) {
        // Determine where this role SHOULD be
        $target = 'login.php';
        switch ($_SESSION['role']) {
            case 'Student': $target = 'student_dashboard.php'; break;
            case 'Faculty': $target = 'faculty_dashboard.php'; break;
            case 'Admin':   $target = 'admin_dashboard.php'; break;
        }

        // Only redirect if we are not already at the target
        if (basename($_SERVER['PHP_SELF']) !== $target) {
            header("Location: " . $target);
            exit();
        }
    }

    // 4. IP and User Agent Check (Basic Hijacking Protection)
    if (!isset($_SESSION['user_ip'])) {
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    } else {
        if ($_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR'] || $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            session_unset();
            session_destroy();
            header("Location: login.php?error=session_breach");
            exit();
        }
    }
}

/**
 * Regenerates session ID to prevent session fixation.
 */
function secure_session_start() {
    if (!isset($_SESSION['regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = true;
    }
}
