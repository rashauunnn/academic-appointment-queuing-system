<?php
// session_helper.php
// Provides session start + role-based dashboard authorization.

function get_role_session_name(): string {
    // Decide session name before session_start() using a dedicated cookie.
    // This prevents cross-tab overwrites.
    // If cookie is missing or explicitly neutral, always use neutral session.
    $role = $_COOKIE['ACTIVE_ROLE_SESSION'] ?? 'Neutral';

    return match ($role) {
        'Admin' => 'PHPSESSID_ADMIN',
        'Faculty' => 'PHPSESSID_FACULTY',
        'Student' => 'PHPSESSID_STUDENT',
        default => 'PHPSESSID_NEUTRAL',
    };
}

function start_role_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // Lock which role-session this request is allowed to attach to.
    // If ACTIVE_ROLE_SESSION is missing/invalid, always fall back to Neutral.
    $rawRole = $_COOKIE['ACTIVE_ROLE_SESSION'] ?? 'Neutral';
    $role = match ($rawRole) {
        'Admin', 'Faculty', 'Student' => $rawRole,
        default => 'Neutral',
    };

    $session_name = get_role_session_name();

    $is_secure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_name($session_name);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $is_secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    // Hard RBAC synchronization: if cookie says role X but session says role Y, rewrite/clear.
    // This prevents cross-tab refresh attaching to a different PHPSESSID_* and then rendering wrong data.
    if (isset($_SESSION['role']) && $_SESSION['role'] !== $role) {
        // Drop stale data and prevent role/session mixups across tabs.
        $_SESSION = [];

        // If this request is already on a non-neutral page, hard-fail RBAC later.
        // We do not force $_SESSION['role'] here because login_process.php is the source of truth.
    }

    // If role isn't present yet, we intentionally do NOT force it here.
    // login_process.php sets $_SESSION['role'] after successful login.
}

start_role_session();

/**
 * Validates the session and checks the required role.
 * Redirects to login or correct dashboard if unauthorized.
 */
function check_session_role(string $required_role): void {
    // 1. Basic Auth Check
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
        // Strict: unauthenticated users must be forced to login.
        if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
            header('Location: login.php');
            exit();
        }
        return;
    }

    // Strict: if a user is logged in with a different role, force their own dashboard.
    // (Prevents URL bypass even if session cookie isolation fails.)

    // 2. Session Timeout (e.g., 30 minutes of inactivity)

    $timeout_duration = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout_duration)) {
        session_unset();
        session_destroy();
        header('Location: login.php?error=timeout');
        exit();
    }
    $_SESSION['last_activity'] = time();

    // 3. Role Isolation Check (RBAC)
    if ($_SESSION['role'] !== $required_role) {
        $target = 'login.php';
        switch ($_SESSION['role']) {
            case 'Student':
                $target = 'student_dashboard.php';
                break;
            case 'Faculty':
                $target = 'faculty_dashboard.php';
                break;
            case 'Admin':
                $target = 'admin_dashboard.php';
                break;
        }

        if (basename($_SERVER['PHP_SELF']) !== $target) {
            header('Location: ' . $target);
            exit();
        }
    }

    // 4. IP and User Agent Check (Basic Hijacking Protection)
    // Relaxed for stability: on many deployments REMOTE_ADDR and/or HTTP_USER_AGENT can
    // legitimately change (proxy/load-balancer/mobile browsers), which breaks valid sessions.
    // If you need this back later, re-add it behind a feature flag.
}




/**
 * Regenerates session ID to prevent session fixation.
 */
function secure_session_start(): void {

    if (!isset($_SESSION['regenerated'])) {
        session_regenerate_id(true);
        $_SESSION['regenerated'] = true;
    }
}

