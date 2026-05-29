<?php
// logout.php
require_once 'session_helper.php';

// Clear role-isolation cookie so next login starts neutral
if (ini_get("session.use_cookies")) {
    // Set explicit Neutral value (and also expire it) so different browsers/proxies behave consistently.
    setcookie('ACTIVE_ROLE_SESSION', 'Neutral', [
        'expires' => time() - 42000,
        'path' => '/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}


// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
// Note: This will destroy the session, and not just the session data!
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_unset();
session_destroy();

header("Location: login.php?msg=logged_out");
exit();
?>
