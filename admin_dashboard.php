<?php
require_once 'security_headers.php';

// Ensure this tab always attaches to the Admin isolated session on refresh.
if (empty($_COOKIE['ACTIVE_ROLE_SESSION']) || $_COOKIE['ACTIVE_ROLE_SESSION'] !== 'Admin') {
    setcookie('ACTIVE_ROLE_SESSION', 'Admin', [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_COOKIE['ACTIVE_ROLE_SESSION'] = 'Admin';
}

require_once 'session_helper.php';
require_once 'db_connect.php';


// Role Guard (Anti-URL Bypass)
check_session_role('Admin');

$admin_id = $_SESSION['user_id'];
$admin_name = $_SESSION['full_name'];

// --- Database Schema Support: Settings ---
$pdo->exec("CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value VARCHAR(255)
)");
$pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('maintenance_mode', '0')");
$pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('system_name', 'ConsultCare')");
$pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('support_email', 'admin@consultcare.edu')");
$pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('default_password', '12345')");
$pdo->exec("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES ('banner_notice', 'Institutional Appointment Matrix Operational.')");

$pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(50) DEFAULT NULL");
$pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancel_reason TEXT DEFAULT NULL");

// Fetch settings key-value pairs
$settings = [];
try {
    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$system_name = $settings['system_name'] ?? 'ConsultCare';
$support_email = $settings['support_email'] ?? 'admin@consultcare.edu';
$default_password_setting = $settings['default_password'] ?? '12345';
$banner_notice = $settings['banner_notice'] ?? 'Institutional Appointment Matrix Operational.';

// --- Handle Admin Actions ---
$message = "";
$message_type = "success"; // 'success' or 'error'

// Handle POST request Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (isset($_POST['form_action'])) {
        	$form_action = $_POST['form_action'];

        if ($form_action === 'create_user') {
            $full_name = trim($_POST['full_name'] ?? '');
            $school_id = trim($_POST['school_id'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $role = trim($_POST['role'] ?? 'Student');
            $custom_pwd = trim($_POST['password'] ?? '');


            if (empty($full_name) || empty($school_id) || empty($role) || empty($email) || empty($custom_pwd)) {

                $message = "Error: Name, ID number, Role, and Email are mandatory.";
                $message_type = "error";
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $message = "Error: Invalid email format.";
                $message_type = "error";
            } else {
                // Normalize for checks
                $email_norm = strtolower($email);

                // Check unique school ID
                $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE school_id = ?");
                $chk->execute([$school_id]);
                if ($chk->fetchColumn() > 0) {
                    $message = "Error: ID number '" . htmlspecialchars($school_id) . "' is already allocated in the directory.";
                    $message_type = "error";
                } else {
                    // Check unique email (best-effort)
                    try {
                        $chkEmail = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                        $chkEmail->execute([$email_norm]);
                        if ((int)$chkEmail->fetchColumn() > 0) {
                            $message = "Error: Email '" . htmlspecialchars($email) . "' is already registered.";
                            $message_type = "error";
                        } else {
                            $final_pwd = !empty($custom_pwd) ? $custom_pwd : $default_password_setting;
                            $hashed = password_hash($final_pwd, PASSWORD_DEFAULT);

                            // Create user as active immediately (skip verification email flow)
                            $stmt = $pdo->prepare(
                                "INSERT INTO users (school_id, full_name, email, role, password, current_status, email_verification_token, email_verification_expires_at, email_verified_at, password_set_at) 
                                 VALUES (?, ?, ?, ?, ?, 'Available', NULL, NULL, NOW(), NOW())"
                            );

                            if ($stmt->execute([$school_id, $full_name, $email_norm, $role, $hashed])) {
                                $message = "Success: Account created for " . htmlspecialchars($full_name) . " (" . $role . "). You may log in now.";
                                $message_type = "success";
                            } else {
                                $message = "Error: Global directories rejected registration record.";
                                $message_type = "error";
                            }



                        }
                    } catch (PDOException $e) {
                        // If email column exists but checks fail, fall back to original insert behavior
                        // Email verification fallback (same approach)
                        $token = bin2hex(random_bytes(32));
                        $expires_at = date('Y-m-d H:i:s', time() + 3600);
                        $hashed = NULL;

                        $stmt = $pdo->prepare(
                            "INSERT INTO users (school_id, full_name, email, role, password, current_status, email_verification_token, email_verification_expires_at, email_verified_at, password_set_at)
                             VALUES (?, ?, ?, ?, ?, 'Pending Verification', ?, ?, NULL, NULL)"
                        );

                        if ($stmt->execute([$school_id, $full_name, $email_norm, $role, $hashed, $token, $expires_at])) {
                            require_once __DIR__ . '/mail/send_verification_email.php';
                            $sent = send_verification_email($email_norm, $full_name, $token);

                            if ($sent) {
                                $message = "Success: Account created for " . htmlspecialchars($full_name) . " (" . $role . "). Verification email sent.";
                                $message_type = "success";
                            } else {
                                $message = "Account created, but failed to send verification email. Check SMTP configuration.";
                                $message_type = "error";
                            }
                        } else {
                            $message = "Error: Global directories rejected registration record.";
                            $message_type = "error";
                        }

                    }
                }
            }
        }

        if ($form_action === 'update_settings') {
            try {
                if (isset($_POST['settings']) && is_array($_POST['settings'])) {
                    foreach ($_POST['settings'] as $key => $value) {
                        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                        $stmt->execute([$key, $value, $value]);
                    }
                    $message = "Success: System parameters updated successfully.";
                    $message_type = "success";
                    // Refresh variables
                    $settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
                    $system_name = $settings['system_name'] ?? 'ConsultCare';
                    $support_email = $settings['support_email'] ?? 'admin@consultcare.edu';
                    $default_password_setting = $settings['default_password'] ?? '12345';
                    $banner_notice = $settings['banner_notice'] ?? 'Institutional Appointment Matrix Operational.';
                }
            } catch (PDOException $ex) {
                $message = "Error: Settings upgrade command faulted. " . $ex->getMessage();
                $message_type = "error";
            }
        }
    }
}

// Handle GET Actions
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $target_id = $_GET['user_id'] ?? null;

    if ($action === 'toggle_maintenance') {
        $current = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn();
        $new_val = ($current === '1') ? '0' : '1';
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_mode'");
        if ($stmt->execute([$new_val])) {
            $message = "System status: " . ($new_val === '1' ? 'MAINTENANCE MODE FROZEN' : 'SYSTEM FULLY OPERATIONAL');
            $message_type = "success";
        }
    }

    if ($action === 'reset_password' && $target_id) {
        $stmt_user = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
        $stmt_user->execute([$target_id]);
        $target_name = $stmt_user->fetchColumn();

        $hashed = password_hash($default_password_setting, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        if ($stmt->execute([$hashed, $target_id])) {
            $message = "Password for " . htmlspecialchars($target_name) . " reset to default: '$default_password_setting'";
            $message_type = "success";
        }
    }

    if ($action === 'delete_user' && $target_id) {
        if ($target_id == $admin_id) {
            $message = "Error: You cannot terminate your own active administrator node.";
            $message_type = "error";
        } else {
            $stmt_user = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $stmt_user->execute([$target_id]);
            $target_name = $stmt_user->fetchColumn();

            $stmt = $pdo->prepare("DELETE FROM users WHERE user_id = ?");
            if ($stmt->execute([$target_id])) {
                $message = "Account " . htmlspecialchars($target_name) . " permanently purged from active directory.";
                $message_type = "success";
            }
        }
    }

    if ($action === 'update_role' && $target_id && isset($_GET['new_role'])) {
        $new_role = $_GET['new_role'];
        if (in_array($new_role, ['Student', 'Faculty', 'Admin'])) {
            $stmt_user = $pdo->prepare("SELECT full_name FROM users WHERE user_id = ?");
            $stmt_user->execute([$target_id]);
            $target_name = $stmt_user->fetchColumn();

            $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?");
            if ($stmt->execute([$new_role, $target_id])) {
                $message = "Authorization updated: " . htmlspecialchars($target_name) . " switched to cluster role: " . $new_role;
                $message_type = "success";
            }
        }
    }
}

// --- Fetch Analytics ---

// 1. Total Appointments & Growth
$total_apps = $pdo->query("SELECT COUNT(*) FROM appointments")->fetchColumn();
$last_week_apps = $pdo->query("SELECT COUNT(*) FROM appointments WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$weekly_growth = ($last_week_apps > 0) ? round((($total_apps - $last_week_apps) / $last_week_apps) * 100, 1) : 100;

// 2. User Stats
$student_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Student'")->fetchColumn();
$faculty_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Faculty'")->fetchColumn();
$admin_count = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'Admin'")->fetchColumn();
$total_users = max(1, $student_count + $faculty_count + $admin_count);

// 3. Peak Hour
$peak_hour_row = $pdo->query("SELECT HOUR(created_at) as hr FROM appointments GROUP BY hr ORDER BY COUNT(*) DESC LIMIT 1")->fetch();
$peak_hour = $peak_hour_row ? date("g A", strtotime($peak_hour_row['hr'] . ":00")) : 'N/A';

// 4. Most Active Student
$top_student = $pdo->query("
    SELECT u.full_name, COUNT(a.app_id) as app_count 
    FROM users u 
    JOIN appointments a ON u.user_id = a.student_id 
    GROUP BY u.user_id 
    ORDER BY app_count DESC 
    LIMIT 1
")->fetch();

// 5. Star Faculty
$top_prof = $pdo->query("
    SELECT u.full_name, COUNT(a.app_id) as app_count 
    FROM users u 
    JOIN appointments a ON u.user_id = a.faculty_id 
    GROUP BY u.user_id 
    ORDER BY app_count DESC 
    LIMIT 1
")->fetch();

// 6. Completion Rate & Breakdown
$status_results = $pdo->query("SELECT status, COUNT(*) as count FROM appointments GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$completed_count = $status_results['Completed'] ?? 0;
$active_count = $status_results['Active'] ?? 0;
$declined_count = $status_results['Declined'] ?? 0;
$cancelled_count = $status_results['Cancelled'] ?? 0;
$completion_rate = ($total_apps > 0) ? round(($completed_count / $total_apps) * 100) : 0;

// 7. Maintenance Status
$maintenance_mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() === '1';

// --- Fetch Tables ---
$users = $pdo->query("SELECT * FROM users ORDER BY role DESC, full_name ASC")->fetchAll();
$logs = $pdo->query("
    SELECT a.*, s.full_name as student_name, f.full_name as faculty_name 
    FROM appointments a 
    JOIN users s ON a.student_id = s.user_id 
    JOIN users f ON a.faculty_id = f.user_id 
    ORDER BY a.created_at DESC 
    LIMIT 100
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Command Center | <?php echo htmlspecialchars($system_name); ?></title>
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-sans: 'Plus Jakarta Sans', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --bg-main: #020617;
            --text-main: #f1f5f9;
            --card-bg: rgba(15, 23, 42, 0.6);
            --card-border: rgba(255, 255, 255, 0.05);
            --accent: #6366f1;
            --input-bg: rgba(30, 41, 59, 0.5);
            --input-border: rgba(255, 255, 255, 0.08);
            --dropdown-bg: #0f172a;
        }

        body.light-mode {
            --bg-main: #f8fafc;
            --text-main: #0f172a;
            --card-bg: rgba(255, 255, 255, 0.9);
            --card-border: rgba(0, 0, 0, 0.08);
            --accent: #4f46e5;
            --input-bg: #f1f5f9;
            --input-border: rgba(0, 0, 0, 0.1);
            --dropdown-bg: #ffffff;
        }

        body.light-mode .text-white { color: #0f172a !important; }
        body.light-mode .bg-slate-950, 
        body.light-mode .bg-slate-900 { background-color: #ffffff !important; }
        body.light-mode .bg-slate-950\/80 { background-color: rgba(255, 255, 255, 0.8) !important; }
        body.light-mode .border-white\/5,
        body.light-mode .border-white\/10 { border-color: rgba(0, 0, 0, 0.05) !important; }
        body.light-mode .radial-progress { 
            background: radial-gradient(closest-side, #ffffff 80%, transparent 0 100%),
                        conic-gradient(#4f46e5 calc(var(--value) * 1%), #e2e8f0 0);
        }

        body { 
            font-family: var(--font-sans);
            background: var(--bg-main);
            color: var(--text-main);
            overflow-x: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }
        .glass-card {
            background: var(--card-bg);
            backdrop-filter: blur(16px);
            border: 1px solid var(--card-border);
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .glass-card:hover {
            border-color: rgba(99, 102, 241, 0.3);
            transform: translateY(-4px);
        }
        .nav-link.active {
            color: #818cf8;
            background: rgba(99, 102, 241, 0.1);
        }
        .nav-link.active svg { transform: scale(1.1); color: #818cf8; }
        
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.5s ease-out; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .dot-menu-wrapper { position: relative; }
        .dot-menu { 
            display: none; 
            position: absolute; 
            right: 0; 
            top: 100%; 
            margin-top: 0.5rem; 
            width: 14rem; 
            background: var(--dropdown-bg); 
            border: 1px solid var(--card-border); 
            border-radius: 1.25rem; 
            z-index: 50; 
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); 
        }
        .dot-menu-wrapper:focus-within .dot-menu { display: block; }
        
        .radial-progress {
            --size: 6rem;
            --thickness: 0.5rem;
            width: var(--size);
            height: var(--size);
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: radial-gradient(closest-side, #020617 80%, transparent 0 100%),
                        conic-gradient(#6366f1 calc(var(--value) * 1%), #1e293b 0);
        }
    </style>
</head>
<body class="min-h-screen">

    <!-- Top Navigation -->
    <nav class="fixed top-0 w-full z-50 bg-slate-950/80 backdrop-blur-xl border-b border-white/5 px-8 py-4 flex items-center justify-between">
        <div class="flex items-center gap-4">
            <div class="w-10 h-10 rounded-2xl bg-indigo-600 flex items-center justify-center shadow-lg shadow-indigo-500/20">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div>
                <h1 class="font-black text-xl tracking-tighter text-white uppercase italic"><?php echo htmlspecialchars($system_name); ?></h1>
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-[0.3em] leading-none">Global Control</p>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="hidden lg:flex items-center bg-slate-900/50 p-1 rounded-2xl border border-white/5">
            <button onclick="switchTab('dashboard')" id="nav-dashboard" class="nav-link active px-6 py-2 rounded-xl text-xs font-black uppercase tracking-widest flex items-center gap-2 transition-all cursor-pointer">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                Overview
            </button>
            <button onclick="switchTab('registry')" id="nav-registry" class="nav-link px-6 py-2 rounded-xl text-xs font-black uppercase tracking-widest flex items-center gap-2 transition-all cursor-pointer text-slate-500 hover:text-slate-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="19" cy="11" r="2"/></svg>
                Registry
            </button>
            <button onclick="switchTab('archives')" id="nav-archives" class="nav-link px-6 py-2 rounded-xl text-xs font-black uppercase tracking-widest flex items-center gap-2 transition-all cursor-pointer text-slate-500 hover:text-slate-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
                Logs Archive
            </button>
            <button onclick="switchTab('settings')" id="nav-settings" class="nav-link px-6 py-2 rounded-xl text-xs font-black uppercase tracking-widest flex items-center gap-2 transition-all cursor-pointer text-slate-500 hover:text-slate-300">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                Configuration
            </button>
        </div>

        <div class="flex items-center gap-4">
            <div class="text-right hidden sm:block">
                <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest"><?php echo htmlspecialchars($admin_name); ?></p>
                <p class="text-[9px] font-bold text-slate-500 uppercase tracking-tighter">Root Administrator</p>
            </div>
            
            <!-- Theme Toggle -->
            <button onclick="toggleTheme()" class="p-2.5 rounded-xl bg-slate-900/50 border border-white/5 text-slate-400 hover:text-white transition-all cursor-pointer shadow-xl">
                <svg id="sun-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <svg id="moon-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            </button>

            <a href="logout.php" onclick="handleLogout(event)" class="p-2.5 rounded-xl bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white transition-all border border-red-500/20">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </nav>

    <!-- Institutional Notice Banner -->
    <div class="fixed top-20 left-0 w-full z-45 bg-indigo-600 px-8 py-2 text-center text-[11px] font-extrabold uppercase tracking-[0.2em] text-white flex items-center justify-center gap-2">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?php echo htmlspecialchars($banner_notice); ?></span>
    </div>

    <!-- Active Broadcast message (if present) -->
    <main class="pt-36 pb-20 px-8 max-w-7xl mx-auto">

        <!-- TAB: DASHBOARD OVERVIEW -->
        <div id="tab-dashboard" class="tab-content active">
            <!-- Analytics Hub -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-12">
                <!-- 1. Total Appointments -->
                <div class="glass-card p-6 rounded-[2.5rem] relative overflow-hidden group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-indigo-600/10 rounded-full blur-2xl group-hover:bg-indigo-600/20 transition-all"></div>
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-4">Total Sessions</p>
                    <div class="flex items-end gap-3">
                        <span class="text-4xl font-black text-white"><?php echo $total_apps; ?></span>
                        <span class="text-[10px] font-bold text-green-500 mb-2 flex items-center">
                            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 7-7 7 7"/><path d="M12 19V5"/></svg>
                            <?php echo $weekly_growth; ?>%
                        </span>
                    </div>
                </div>

                <!-- 2. Peak Hour -->
                <div class="glass-card p-6 rounded-[2.5rem] relative group">
                    <div class="absolute -right-6 -top-6 w-24 h-24 bg-violet-600/10 rounded-full blur-2xl group-hover:bg-violet-600/20 transition-all"></div>
                    <div class="flex items-center gap-2 text-violet-400 mb-4">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span class="text-[10px] font-black uppercase tracking-widest">Busy Hour</span>
                    </div>
                    <span class="text-2xl font-black text-white italic"><?php echo $peak_hour; ?></span>
                    <p class="text-[10px] text-slate-500 font-bold mt-2 font-mono tracking-tight underline decoration-violet-500/50 italic font-black">Peak Appointment Load</p>
                </div>

                <!-- 3. Active S | Star F -->
                <div class="glass-card p-6 rounded-[2.5rem] md:col-span-2 relative overflow-hidden flex items-center justify-between">
                    <div class="absolute left-1/2 top-0 bottom-0 w-px bg-white/5"></div>
                    <div class="w-1/2 pr-4">
                        <p class="text-[9px] font-black text-emerald-500 uppercase tracking-widest mb-2">Most Engaged Student</p>
                        <h4 class="text-sm font-bold text-white truncate"><?php echo $top_student ? htmlspecialchars($top_student['full_name']) : 'N/A'; ?></h4>
                        <p class="text-[10px] text-slate-500 font-bold"><?php echo $top_student ? $top_student['app_count'] : 0; ?> registered sessions</p>
                    </div>
                    <div class="w-1/2 pl-6">
                        <p class="text-[9px] font-black text-indigo-400 uppercase tracking-widest mb-2">Star Advisor</p>
                        <h4 class="text-sm font-bold text-white truncate"><?php echo $top_prof ? htmlspecialchars($top_prof['full_name']) : 'N/A'; ?></h4>
                        <p class="text-[10px] text-slate-500 font-bold"><?php echo $top_prof ? $top_prof['app_count'] : 0; ?> requests fulfilled</p>
                    </div>
                </div>

                <!-- 4. Completion Rate -->
                <div class="glass-card p-6 rounded-[2.5rem] flex flex-col items-center justify-center">
                    <div class="radial-progress" style="--value: <?php echo $completion_rate; ?>; --size: 5rem;">
                        <span class="text-lg font-black text-white tracking-tighter"><?php echo $completion_rate; ?>%</span>
                    </div>
                    <p class="text-[9px] font-black text-slate-500 uppercase tracking-[0.2em] mt-3">Success Rate</p>
                </div>

                <!-- 5. Maintenance Control -->
                <div class="glass-card p-6 rounded-[2.5rem] flex flex-col items-center justify-center relative overflow-hidden <?php echo $maintenance_mode ? 'border-red-500/50 bg-red-500/5' : ''; ?>">
                    <a href="admin_dashboard.php?action=toggle_maintenance" class="group flex flex-col items-center gap-2">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center transition-all <?php echo $maintenance_mode ? 'bg-red-500 text-white shadow-[0_0_20px_rgba(239,68,68,0.4)]' : 'bg-slate-800 text-slate-400 group-hover:bg-indigo-600 group-hover:text-white'; ?>">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v4"/><path d="m16.24 7.76-2.83 2.83"/><path d="M18 12h4"/><path d="m16.24 16.24-2.83-2.83"/><path d="M12 18v4"/><path d="m7.76 16.24 2.83-2.83"/><path d="M2 12h4"/><path d="m7.76 7.76 2.83 2.83"/></svg>
                        </div>
                        <span class="text-[10px] font-black uppercase tracking-widest <?php echo $maintenance_mode ? 'text-red-500 font-extrabold' : 'text-slate-500'; ?>">
                            <?php echo $maintenance_mode ? 'Lockdown Active' : 'Cluster Offline'; ?>
                        </span>
                    </a>
                    <p class="text-[8px] text-slate-600 font-bold uppercase mt-2">Maintenance Override</p>
                </div>
            </div>

            <!-- Visual SVG Analytics Charts Panel -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">
                <!-- SVG Status Chart -->
                <div class="glass-card p-8 rounded-[3rem] lg:col-span-2 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <h3 class="text-lg font-black text-white uppercase italic tracking-tighter">Status Analytics Breakdown</h3>
                                <p class="text-xs text-slate-500 font-bold">Relative balance of appointment resolutions</p>
                            </div>
                            <span class="text-[10px] font-black bg-indigo-500/10 text-indigo-400 px-3 py-1 rounded-full uppercase">Real-Time</span>
                        </div>
                        <!-- Bar chart visualization using styled native SVG -->
                        <div class="relative w-full h-44 mt-6">
                            <?php
                            $max_status = max(1, $completed_count, $active_count, $declined_count, $cancelled_count);
                            $pct_comp = round(($completed_count / $max_status) * 100);
                            $pct_act  = round(($active_count / $max_status) * 100);
                            $pct_decl = round(($declined_count / $max_status) * 100);
                            $pct_canc = round(($cancelled_count / $max_status) * 100);
                            ?>
                            <div class="flex justify-around items-end h-32 px-4 gap-4">
                                <!-- Completed -->
                                <div class="flex flex-col items-center group w-12 cursor-pointer">
                                    <span class="text-[10px] font-bold text-emerald-400 mb-2 opacity-0 group-hover:opacity-100 transition-opacity"><?php echo $completed_count; ?></span>
                                    <div class="w-full bg-gradient-to-t from-emerald-600 to-emerald-400 rounded-xl transition-all duration-700 ease-out shadow-[0_0_15px_rgba(16,185,129,0.3)]" style="height: <?php echo max(5, $pct_comp); ?>%"></div>
                                    <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-2">Closed</span>
                                </div>
                                <!-- Active -->
                                <div class="flex flex-col items-center group w-12 cursor-pointer">
                                    <span class="text-[10px] font-bold text-indigo-400 mb-2 opacity-0 group-hover:opacity-100 transition-opacity"><?php echo $active_count; ?></span>
                                    <div class="w-full bg-gradient-to-t from-indigo-600 to-indigo-400 rounded-xl transition-all duration-700 ease-out shadow-[0_0_15px_rgba(99,102,241,0.3)]" style="height: <?php echo max(5, $pct_act); ?>%"></div>
                                    <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-2">Active</span>
                                </div>
                                <!-- Declined -->
                                <div class="flex flex-col items-center group w-12 cursor-pointer">
                                    <span class="text-[10px] font-bold text-violet-400 mb-2 opacity-0 group-hover:opacity-100 transition-opacity"><?php echo $declined_count; ?></span>
                                    <div class="w-full bg-gradient-to-t from-violet-600 to-violet-400 rounded-xl transition-all duration-700 ease-out shadow-[0_0_15px_rgba(139,92,246,0.3)]" style="height: <?php echo max(5, $pct_decl); ?>%"></div>
                                    <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-2">Denied</span>
                                </div>
                                <!-- Cancelled -->
                                <div class="flex flex-col items-center group w-12 cursor-pointer">
                                    <span class="text-[10px] font-bold text-rose-400 mb-2 opacity-0 group-hover:opacity-100 transition-opacity"><?php echo $cancelled_count; ?></span>
                                    <div class="w-full bg-gradient-to-t from-rose-600 to-rose-400 rounded-xl transition-all duration-700 ease-out shadow-[0_0_15px_rgba(244,63,94,0.3)]" style="height: <?php echo max(5, $pct_canc); ?>%"></div>
                                    <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest mt-2">Void</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="border-t border-white/5 pt-4 mt-6 flex justify-between items-center text-[10px] text-slate-500 font-bold uppercase tracking-wider">
                        <span>Highest Category Yield: <?php echo $max_status > 1 ? $max_status : "0"; ?></span>
                        <span class="text-indigo-400 flex items-center gap-1">
                            <span class="w-1.5 h-1.5 rounded-full bg-indigo-500 animate-ping"></span> Live Analytics Synchronized
                        </span>
                    </div>
                </div>

                <!-- Fast Actions & Status List -->
                <div class="glass-card p-8 rounded-[3rem] flex flex-col justify-between">
                    <div>
                        <h3 class="text-base font-black text-white uppercase italic tracking-tighter mb-4">Command Actions</h3>
                        <p class="text-xs text-slate-500 font-bold mb-6">Immediate system level execution triggers</p>
                        
                        <div class="space-y-3">
                            <button onclick="switchTab('registry')" class="w-full p-4 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 text-indigo-400 hover:bg-indigo-600 hover:text-white transition-all flex items-center justify-between text-xs font-black uppercase tracking-wider cursor-pointer">
                                <span>Manfully Provision User</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                            </button>
                            <button onclick="switchTab('settings')" class="w-full p-4 rounded-2xl bg-purple-500/10 border border-purple-500/20 text-purple-400 hover:bg-purple-600 hover:text-white transition-all flex items-center justify-between text-xs font-black uppercase tracking-wider cursor-pointer font-bold">
                                <span>Modify System Brand</span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.1a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                            <a href="admin_dashboard.php?action=toggle_maintenance" class="w-full p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 text-amber-500 hover:bg-amber-600 hover:text-white transition-all flex items-center justify-between text-xs font-black uppercase tracking-wider">
                                <span><?php echo $maintenance_mode ? "Disable Lockdown" : "Initiate Lockdown"; ?></span>
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </a>
                        </div>
                    </div>
                    <span class="text-[9px] text-slate-500 font-bold uppercase tracking-widest mt-6">Administrative Authorization Required</span>
                </div>
            </div>

            <!-- Population Distribution Matrix -->
            <div class="glass-card p-8 rounded-[3rem] mb-12 relative overflow-hidden group">
                <div class="absolute -right-20 -bottom-20 w-80 h-80 bg-indigo-600/5 rounded-full blur-[100px] group-hover:bg-indigo-600/10 transition-all duration-1000"></div>
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h3 class="text-xl font-black text-white uppercase italic tracking-tighter">System Population Distribution</h3>
                        <p class="text-xs text-slate-500 font-bold">Real-time role-based segmentation</p>
                    </div>
                    <div class="text-right">
                        <span class="text-4xl font-black text-white tracking-tighter"><?php echo $student_count + $faculty_count + $admin_count; ?></span>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Total Active Nodes</p>
                    </div>
                </div>
                <div class="flex h-4 bg-slate-900 rounded-full overflow-hidden shadow-inner border border-white/5 p-1">
                    <div class="bg-gradient-to-r from-indigo-600 to-indigo-400 h-full transition-all duration-1000 rounded-full shadow-[0_0_15px_rgba(99,102,241,0.4)]" style="width: <?php echo ($student_count/$total_users)*100; ?>%"></div>
                    <div class="bg-gradient-to-r from-violet-600 to-violet-400 h-full transition-all duration-1000 rounded-full mx-1 shadow-[0_0_15px_rgba(139,92,246,0.4)]" style="width: <?php echo ($faculty_count/$total_users)*100; ?>%"></div>
                    <div class="bg-gradient-to-r from-emerald-600 to-emerald-400 h-full transition-all duration-1000 rounded-full shadow-[0_0_15px_rgba(16,185,129,0.4)]" style="width: <?php echo ($admin_count/$total_users)*100; ?>%"></div>
                </div>
                <div class="mt-6 flex flex-wrap gap-8 items-center text-[10px] font-black uppercase tracking-widest">
                    <div class="flex items-center gap-3 text-indigo-400">
                        <div class="w-3 h-3 rounded-full bg-indigo-500 shadow-[0_0_10px_rgba(99,102,241,0.5)]"></div>
                        Students (<?php echo $student_count; ?>)
                    </div>
                    <div class="flex items-center gap-3 text-violet-400">
                        <div class="w-3 h-3 rounded-full bg-violet-500 shadow-[0_0_10px_rgba(139,92,246,0.5)]"></div>
                        Faculty / Advisers (<?php echo $faculty_count; ?>)
                    </div>
                    <div class="flex items-center gap-3 text-emerald-400">
                        <div class="w-3 h-3 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]"></div>
                        Admins (<?php echo $admin_count; ?>)
                    </div>
                </div>
            </div>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 mt-12 bg-indigo-600/5 p-12 rounded-[4rem] border border-white/5">
                <div>
                    <h3 class="text-3xl font-black text-white italic tracking-tighter mb-4">System Integrity Details</h3>
                    <p class="text-slate-500 text-sm font-medium leading-relaxed max-w-sm mb-8">All cluster elements are fully synchronized via secure, optimized database transactions. Real-time logging enforces user tracing and protection against parameter spoofing.</p>
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center text-indigo-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>
                            <p class="text-xs font-black text-white uppercase tracking-widest tracking-tighter">Security Posture: Maximized</p>
                            <p class="text-[10px] font-bold text-slate-500 uppercase">Dual-Mode Hash Verification Standard Active</p>
                        </div>
                    </div>
                </div>
                <div class="flex flex-col justify-center space-y-4">
                    <div class="p-4 rounded-2xl bg-slate-900/50 border border-white/5 flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Real-time Database Latency</span>
                        <div class="flex items-center gap-2">
                            <div class="w-2 h-2 rounded-full bg-emerald-500"></div>
                            <span class="text-xs font-black text-emerald-400 font-mono">1.2ms (Perfect)</span>
                        </div>
                    </div>
                    <div class="p-4 rounded-2xl bg-slate-900/50 border border-white/5 flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">System Architecture Threading</span>
                        <span class="text-xs font-black text-indigo-400 font-mono">Multi-Role Concurrent (PDO)</span>
                    </div>
                    <div class="p-4 rounded-2xl bg-slate-900/50 border border-white/5 flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Master Admin Server Integrity</span>
                        <span class="text-xs font-black text-violet-400 font-mono">Operational (100% Up)</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- TAB: ACCOUNT REGISTRY -->
        <div id="tab-registry" class="tab-content transition-all">
            <div class="flex flex-col xl:flex-row items-start xl:items-center justify-between mb-10 gap-6">
                <div>
                    <h2 class="text-5xl font-black text-white italic tracking-tighter">Account Registry</h2>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.4em] mt-2">Authenticated System Access Directory</p>
                </div>
                <!-- Controls and Creation Actions -->
                <div class="flex flex-wrap items-center gap-4 w-full xl:w-auto">
                    <button onclick="toggleProvisioningPanel()" class="bg-indigo-600 hover:bg-indigo-500 text-white rounded-[1.5rem] px-6 py-4 text-xs font-black uppercase tracking-widest focus:outline-none cursor-pointer shadow-xl shadow-indigo-600/20 flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="M12 5l7 7-7 7"/></svg>
                        Provision Account
                    </button>
                    <div class="relative flex-1 min-w-[200px] xl:w-80">
                        <input type="text" id="registrySearch" placeholder="Filter directories..." class="w-full bg-slate-900/80 border border-white/10 rounded-[1.5rem] px-14 py-4 text-sm focus:outline-none focus:border-indigo-500/50 text-white placeholder:text-slate-600 transition-all shadow-2xl">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="absolute left-6 top-1/2 -translate-y-1/2 text-slate-600"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </div>
                    <select id="roleFilter" class="bg-slate-900/80 text-white border border-white/10 rounded-[1.5rem] px-8 py-4 text-xs font-black uppercase tracking-widest focus:outline-none cursor-pointer shadow-xl">
                        <option value="all">Global Role Selector</option>
                        <option value="Student">Students</option>
                        <option value="Faculty">Faculty Advisors</option>
                        <option value="Admin">Administrators</option>
                    </select>
                </div>
            </div>

            <!-- Manual User Provisioning Accordion Panel/Drawer -->
            <div id="provisioningPanel" class="hidden glass-card p-8 rounded-[3rem] mb-10 border border-indigo-500/30 bg-indigo-500/5 relative overflow-hidden animate-in slide-in-from-top-4 duration-300">
                <div class="absolute -right-16 -top-16 w-44 h-44 bg-indigo-600/10 rounded-full blur-3xl"></div>
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-xl font-black text-white uppercase italic tracking-tighter">Register New System Node</h3>
                        <p class="text-xs text-indigo-400 font-bold">Instantly allocate access credentials into the global directory</p>
                    </div>
                    <button onclick="toggleProvisioningPanel()" class="p-2 rounded-xl hover:bg-white/10 text-slate-400 hover:text-white transition-all cursor-pointer">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <form action="admin_dashboard.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <input type="hidden" name="form_action" value="create_user">
                    
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">Full Identity Name</label>
                        <input type="text" name="full_name" placeholder="Johnathan Doe" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">School ID (Unique)</label>
                        <input type="text" name="school_id" placeholder="2024-10045" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">Email Address</label>
                        <input type="email" name="email" placeholder="jdoe@university.edu" class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">System Role Hierarchy</label>
                        <select name="role" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                            <option value="Student">Student (Default Access)</option>
                            <option value="Faculty">Faculty Ambassador</option>
                            <option value="Admin">Root Operator (Admin)</option>
                        </select>
                    </div>

                        <div class="space-y-2 lg:col-span-2">
                        		<label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">Access Password</label>
                        		<input type="password" name="password" required placeholder="Enter the password that the user will use in the admin login" class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                        	</div>


                    <div class="lg:col-span-2 flex items-end">
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-black text-xs uppercase tracking-widest py-3.5 rounded-xl cursor-pointer transition-all shadow-lg active:scale-95">
                            Authorise Directory Registration
                        </button>
                    </div>
                </form>
            </div>

            <div class="glass-card rounded-[3.5rem] overflow-hidden border border-white/5 shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left" id="userTable">
                        <thead class="bg-slate-950/80 border-b border-white/5">
                            <tr class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">
                                <th class="px-12 py-8">Entity Meta</th>
                                <th class="px-12 py-8 text-center">Authorization Level</th>
                                <th class="px-12 py-8 text-center">Node Status</th>
                                <th class="px-12 py-8 text-right italic">Direct Overrides</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.03]">
                            <?php foreach ($users as $user): ?>
                                <tr class="user-row group hover:bg-white/[0.02] transition-all" data-name="<?php echo strtolower($user['full_name']); ?>" data-id="<?php echo strtolower($user['school_id']); ?>" data-role="<?php echo $user['role']; ?>" data-email="<?php echo strtolower($user['email'] ?? ''); ?>">
                                    <td class="px-12 py-8">
                                        <div class="flex items-center gap-6">
                                            <div class="w-14 h-14 rounded-2xl bg-slate-900 border border-white/5 flex items-center justify-center text-lg font-black text-indigo-500 group-hover:scale-110 group-hover:border-indigo-500/50 transition-all shadow-inner">
                                                <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <p class="font-bold text-white text-lg tracking-tight"><?php echo htmlspecialchars($user['full_name']); ?></p>
                                                <p class="text-[11px] text-slate-500 font-mono flex items-center gap-3">
                                                    <span class="bg-indigo-500/10 text-indigo-400 px-2 rounded tracking-tight"><?php echo htmlspecialchars($user['school_id']); ?></span>
                                                    <span class="opacity-30">/</span>
                                                    <span class="italic lowercase"><?php echo htmlspecialchars($user['email'] ?? 'undefined@cluster.edu'); ?></span>
                                                </p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-12 py-8 text-center">
                                        <span class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest <?php echo $user['role'] === 'Admin' ? 'bg-emerald-600/10 text-emerald-400 border border-emerald-500/20 shadow-[0_0_10px_rgba(16,185,129,0.15)]' : ($user['role'] === 'Faculty' ? 'bg-violet-600/10 text-violet-400 border border-violet-500/20 shadow-[0_0_10px_rgba(139,92,246,0.15)]' : 'bg-slate-800 text-slate-400 border border-white/5'); ?>">
                                            <?php echo $user['role']; ?>
                                        </span>
                                    </td>
                                    <td class="px-12 py-8 text-center">
                                        <div class="flex items-center justify-center gap-3">
                                            <div class="w-2.5 h-2.5 rounded-full <?php echo $user['current_status'] === 'Available' ? 'bg-emerald-500 shadow-[0_0_12px_rgba(16,185,129,0.5)] animate-pulse' : ($user['current_status'] === 'Busy' ? 'bg-amber-500 shadow-[0_0_12px_rgba(245,158,11,0.5)]' : 'bg-slate-700'); ?>"></div>
                                            <span class="text-[10px] font-black text-slate-500 uppercase tracking-wider"><?php echo $user['current_status']; ?></span>
                                        </div>
                                    </td>
                                    <td class="px-12 py-8 text-right">
                                        <div class="dot-menu-wrapper flex justify-end">
                                            <button class="p-3 rounded-2xl bg-slate-900 border border-white/5 text-slate-500 hover:text-white transition-all cursor-pointer">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1"/><circle cx="12" cy="5" r="1"/><circle cx="12" cy="19" r="1"/></svg>
                                            </button>
                                            <!-- Action Menu -->
                                            <div class="dot-menu p-3 space-y-1">
                                                <p class="text-[9px] font-black text-slate-600 uppercase tracking-widest mb-2 px-2 italic">Role Override</p>
                                                
                                                <!-- Dynamic confirmations wrapped via beautiful SweetAlert handlers -->
                                                <button onclick="confirmResetPassword(event, '<?php echo htmlspecialchars($user['full_name']); ?>', <?php echo $user['user_id']; ?>)" class="w-full text-left flex items-center gap-3 px-3 py-2 text-[10px] font-black text-indigo-400 hover:bg-indigo-600 hover:text-white rounded-xl transition-all uppercase tracking-widest cursor-pointer">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.778-7.778z"/><path d="M10 14l-2 2"/></svg>
                                                    Flush Password
                                                </button>
                                                
                                                <div class="h-px bg-white/5 my-2"></div>
                                                
                                                <button onclick="confirmRoleChange(event, '<?php echo htmlspecialchars($user['full_name']); ?>', <?php echo $user['user_id']; ?>, 'Admin')" class="w-full text-left block px-3 py-2 text-[10px] font-black text-emerald-400 hover:bg-emerald-600 hover:text-white rounded-xl transition-all uppercase cursor-pointer">Elevate: Admin</button>
                                                <button onclick="confirmRoleChange(event, '<?php echo htmlspecialchars($user['full_name']); ?>', <?php echo $user['user_id']; ?>, 'Faculty')" class="w-full text-left block px-3 py-2 text-[10px] font-black text-violet-400 hover:bg-violet-600 hover:text-white rounded-xl transition-all uppercase cursor-pointer">Switch: Faculty</button>
                                                <button onclick="confirmRoleChange(event, '<?php echo htmlspecialchars($user['full_name']); ?>', <?php echo $user['user_id']; ?>, 'Student')" class="w-full text-left block px-3 py-2 text-[10px] font-black text-slate-400 hover:bg-slate-600 hover:text-white rounded-xl transition-all uppercase cursor-pointer">Revert: Student</button>
                                                
                                                <?php if ($user['user_id'] != $admin_id): ?>
                                                    <div class="h-px bg-red-500/10 my-2"></div>
                                                    <button onclick="confirmPurgeUser(event, '<?php echo htmlspecialchars($user['full_name']); ?>', <?php echo $user['user_id']; ?>)" class="w-full text-left flex items-center gap-3 px-3 py-2 text-[10px] font-black text-red-500 hover:bg-red-600 hover:text-white rounded-xl transition-all uppercase cursor-pointer">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m3 6 18 0"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><line x1="10" y1="11" x2="10" y2="17"/></svg>
                                                        Purge Entity
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: ACTIVITY ARCHIVES -->
        <div id="tab-archives" class="tab-content border-none">
            <div class="flex flex-col md:flex-row items-start md:items-center justify-between mb-10 gap-6">
                <div>
                    <h2 class="text-5xl font-black text-white italic tracking-tighter">System Archives</h2>
                    <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.4em] mt-2">Historical Event Tracking Matrix (PDO Stream)</p>
                </div>
                <!-- Mini Log Filters -->
                <div class="flex items-center gap-4 w-full md:w-auto">
                    <input type="text" id="logSearch" placeholder="Search archive logs..." class="bg-slate-900/85 text-white text-xs border border-white/10 px-6 py-4 rounded-[1.25rem] w-full md:w-64 focus:outline-none focus:border-indigo-500/50 placeholder:text-slate-650 font-bold transition-all">
                    <select id="logStatusFilter" class="bg-indigo-600 text-white border-none rounded-[1.25rem] px-6 py-4 text-xs font-black uppercase tracking-widest focus:outline-none cursor-pointer">
                        <option value="all">Statuses (All)</option>
                        <option value="Active">Active</option>
                        <option value="Completed">Completed</option>
                        <option value="Declined">Declined</option>
                        <option value="Cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            
            <div class="glass-card rounded-[4rem] overflow-hidden border border-white/5 shadow-2xl">
                <div class="overflow-x-auto">
                    <table class="w-full text-left" id="archiveLogsTable">
                        <thead class="bg-indigo-600 text-white">
                            <tr class="text-[10px] font-black uppercase tracking-[0.3em]">
                                <th class="px-12 py-8 italic tracking-tighter">Origin Node (Student)</th>
                                <th class="px-12 py-8 italic tracking-tighter">Destination (Advisor)</th>
                                <th class="px-12 py-8 text-center">(UTC) Temporal Index</th>
                                <th class="px-12 py-8 text-center italic">Resolving Outcome</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.03]">
                            <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="4" class="px-12 py-20 text-center text-slate-600 italic font-medium uppercase tracking-widest">No archival data recovered.</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($logs as $log): 
                                $res_cls = 'bg-slate-900 text-slate-500 border border-white/5';
                                if ($log['status'] === 'Completed') $res_cls = 'bg-emerald-650/10 text-emerald-400 border border-emerald-500/20 shadow-[0_0_15px_rgba(16,185,129,0.15)]';
                                if ($log['status'] === 'Declined') $res_cls = 'bg-red-650/10 text-red-500 border border-red-500/20';
                                if ($log['status'] === 'Active') $res_cls = 'bg-indigo-650/10 text-indigo-300 border border-indigo-500/20 shadow-[0_0_15px_rgba(99,102,241,0.15)]';
                                if ($log['status'] === 'Cancelled') $res_cls = 'bg-amber-655/10 text-amber-500 border border-amber-500/20';
                            ?>
                                <tr class="archive-log-row hover:bg-white/[0.01] transition-all" data-student="<?php echo strtolower($log['student_name']); ?>" data-faculty="<?php echo strtolower($log['faculty_name']); ?>" data-status="<?php echo $log['status']; ?>">
                                    <td class="px-12 py-7">
                                        <p class="text-base font-bold text-white tracking-tight"><?php echo htmlspecialchars($log['student_name']); ?></p>
                                    </td>
                                    <td class="px-12 py-7">
                                        <p class="text-base font-bold text-slate-400 italic lowercase tracking-tighter"><?php echo htmlspecialchars($log['faculty_name']); ?></p>
                                    </td>
                                    <td class="px-12 py-7 text-center">
                                        <p class="text-[11px] font-mono text-slate-500 uppercase tracking-tight"><?php echo date('M. d. Y / H:i', strtotime($log['created_at'])); ?></p>
                                    </td>
                                    <td class="px-12 py-7 text-center">
                                        <span class="px-4 py-2 rounded-xl text-[9px] font-black uppercase tracking-widest <?php echo $res_cls; ?>">
                                            <?php echo $log['status']; ?>
                                        </span>
                                        <?php if (!empty($log['cancel_reason'])): ?>
                                            <div class="mt-3 text-[10px] text-slate-500 font-medium italic overflow-hidden text-ellipsis max-w-[200px] mx-auto border-t border-white/5 pt-2">
                                                "<?php echo htmlspecialchars($log['cancel_reason']); ?>"
                                                <?php if (!empty($log['cancelled_by'])): ?>
                                                    <span class="block mt-1 text-[8px] font-black text-indigo-500 uppercase not-italic tracking-[0.2em] opacity-60">— By <?php echo $log['cancelled_by']; ?></span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: SYSTEM SETTINGS -->
        <div id="tab-settings" class="tab-content transition-all">
            <div class="mb-10">
                <h2 class="text-5xl font-black text-white italic tracking-tighter">System Configuration</h2>
                <p class="text-slate-500 text-[10px] font-black uppercase tracking-[0.4em] mt-2">Tailor branding, policies, notices, and operational parameters</p>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Global Brand form -->
                <div class="glass-card p-8 rounded-[3rem] lg:col-span-2">
                    <h3 class="text-xl font-black text-white uppercase italic tracking-tighter mb-4">Edit Configuration Matrix</h3>
                    <p class="text-xs text-slate-500 font-bold mb-8">Save branding, emails, default passwords, and welcome notice banners instantly</p>

                    <form action="admin_dashboard.php" method="POST" class="space-y-6">
                        <input type="hidden" name="form_action" value="update_settings">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">System Title / Brand Name</label>
                                <input type="text" name="settings[system_name]" value="<?php echo htmlspecialchars($system_name); ?>" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">Support Contact Email</label>
                                <input type="email" name="settings[support_email]" value="<?php echo htmlspecialchars($support_email); ?>" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500">
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">Default Password (Reset Target)</label>
                                <input type="text" name="settings[default_password]" value="<?php echo htmlspecialchars($default_password_setting); ?>" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-indigo-500 font-mono">
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2 font-bold text-indigo-400">Lockdown Mode status</label>
                                <div class="flex items-center gap-4 h-12">
                                    <span class="text-xs font-black uppercase tracking-wider <?php echo $maintenance_mode ? 'text-red-500' : 'text-emerald-500'; ?>"><?php echo $maintenance_mode ? 'Locked down' : 'Active / Operational'; ?></span>
                                    <a href="admin_dashboard.php?action=toggle_maintenance" class="px-4 py-2 rounded-xl bg-slate-900 border border-white/5 text-[10px] font-black uppercase tracking-wider text-white hover:bg-slate-800 transition-colors">Toggle Mode</a>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest pl-2">Universal Notice Broadcast Banner Text</label>
                            <input type="text" name="settings[banner_notice]" value="<?php echo htmlspecialchars($banner_notice); ?>" required class="w-full bg-slate-900/80 border border-white/10 rounded-xl px-4 py-3 text-sm text-indigo-300 focus:outline-none focus:border-indigo-500">
                        </div>

                        <div class="pt-4">
                            <button type="submit" class="w-full md:w-auto bg-indigo-600 hover:bg-indigo-500 text-white font-black text-xs uppercase tracking-[0.2em] px-8 py-4 rounded-xl cursor-pointer transition-all active:scale-95 shadow-lg shadow-indigo-600/20">
                                Save System Settings Configuration
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Settings helper informational guides -->
                <div class="glass-card p-8 rounded-[3rem] flex flex-col justify-between">
                    <div class="space-y-6">
                        <h4 class="text-lg font-black text-white uppercase italic tracking-tighter">Directives Manual</h4>
                        
                        <div class="space-y-4">
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <h5 class="text-xs font-black uppercase text-indigo-400 mb-1">Brand Branding</h5>
                                <p class="text-[11px] text-slate-400">Modifying Title variables alters brand labels on student calendars, faculty terminals, and master layouts.</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <h5 class="text-xs font-black uppercase text-violet-400 mb-1">Rest-Passwords Policy</h5>
                                <p class="text-[11px] text-slate-400">Flushing standardizes user records to the Default Password setup using clean salted bcrypt layers.</p>
                            </div>
                            <div class="p-4 rounded-2xl bg-white/[0.02] border border-white/5">
                                <h5 class="text-xs font-black uppercase text-amber-500 mb-1">Global Lockdown</h5>
                                <p class="text-[11px] text-slate-400">Enabling Freeze redirects any attempting Student and Faculty to an informative off-grid Maintenance Notice screen immediately.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </main>

    <!-- Visual Accents -->
    <div class="fixed top-0 left-0 w-full h-full pointer-events-none -z-10 bg-[#020617]">
        <div class="absolute top-[5%] left-[5%] w-[800px] h-[800px] bg-indigo-500/10 blur-[150px] rounded-full animate-pulse"></div>
        <div class="absolute bottom-[5%] right-[5%] w-[900px] h-[900px] bg-violet-600/5 blur-[180px] rounded-full"></div>
    </div>

    <!-- Activation Trigger message (from server) -->
    <?php if (!empty($message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const msg = <?php echo json_encode($message); ?>;
            const msgType = <?php echo json_encode($message_type); ?>;
            const isError = msgType === 'error' || msg.toLowerCase().includes('error');
            Swal.fire({
                icon: isError ? 'error' : 'success',
                title: isError ? '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Directory Alert</div>' : '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Command Successful</div>',
                text: msg.replace(/Success: |Error: /gi, ''),
                background: '#0d121f',
                color: '#f1f5f9',
                confirmButtonColor: '#6366f1',
                customClass: {
                    popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-7 max-w-sm',
                    confirmButton: 'px-6 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white'
                }
            });
        });
    </script>
    <?php endif; ?>

    <script>
        function toggleTheme() {
            const body = document.body;
            const isLight = body.classList.toggle('light-mode');
            localStorage.setItem('cc_theme', isLight ? 'light' : 'dark');
            updateThemeIcons(isLight);
        }

        function updateThemeIcons(isLight) {
            document.getElementById('sun-icon').classList.toggle('hidden', !isLight);
            document.getElementById('moon-icon').classList.toggle('hidden', isLight);
        }

        function switchTab(tabId) {
            // Content Toggle
            document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            
            // Sidebar Navigation Update
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active', 'text-slate-500', 'text-white');
                if (link.id === 'nav-' + tabId) {
                    link.classList.add('active');
                    if (document.body.classList.contains('light-mode')) {
                        link.classList.add('text-indigo-600');
                    }
                } else {
                    link.classList.add('text-slate-500');
                }
            });

            // Persist State
            localStorage.setItem('cc_session_tab', tabId);
        }

        function toggleProvisioningPanel() {
            const panel = document.getElementById('provisioningPanel');
            panel.classList.toggle('hidden');
        }

        // Initialization
        const activeTab = localStorage.getItem('cc_session_tab') || 'dashboard';
        const savedTheme = localStorage.getItem('cc_theme') || 'dark';
        
        if (savedTheme === 'light') {
            document.body.classList.add('light-mode');
            updateThemeIcons(true);
        }

        switchTab(activeTab);

        // Advanced Search Logic (Accounts Registry)
        const regSearch = document.getElementById('registrySearch');
        const roleFilter = document.getElementById('roleFilter');
        const entityRows = document.querySelectorAll('.user-row');

        let __admin_regDebounce = null;
        function runRefinery() {
            const q = regSearch.value.trim().toLowerCase();
            const r = roleFilter.value;

            entityRows.forEach(row => {
                const n = row.dataset.name;
                const i = row.dataset.id;
                const e = row.dataset.email;
                const currentRole = row.dataset.role;

                const queryMatch = !q || n.includes(q) || i.includes(q) || e.includes(q);
                const roleMatch = r === 'all' || currentRole === r;

                row.style.display = (queryMatch && roleMatch) ? 'table-row' : 'none';
            });
        }

        // Registry search debounced for performance
        if (regSearch) {
            let __admin_regDebounce = null;
            regSearch.addEventListener('input', () => {
                clearTimeout(__admin_regDebounce);
                __admin_regDebounce = setTimeout(runRefinery, 120);
            });
        }

        if (roleFilter) roleFilter.addEventListener('change', runRefinery);

        // Advanced Search Logic (Logs Archive Filter)
        const logSearch = document.getElementById('logSearch');
        const logStatusFilter = document.getElementById('logStatusFilter');
        const archiveRows = document.querySelectorAll('.archive-log-row');

        function runLogRefinery() {
            const q = logSearch.value.trim().toLowerCase();
            const s = logStatusFilter.value;

            archiveRows.forEach(row => {
                const student = row.dataset.student;
                const faculty = row.dataset.faculty;
                const currentStatus = row.dataset.status;

                const queryMatch = !q || student.includes(q) || faculty.includes(q);
                const statusMatch = s === 'all' || currentStatus === s;

                row.style.display = (queryMatch && statusMatch) ? 'table-row' : 'none';
            });
        }

        if (logSearch) {
            let __admin_logDebounce = null;
            logSearch.addEventListener('input', () => {
                clearTimeout(__admin_logDebounce);
                __admin_logDebounce = setTimeout(runLogRefinery, 120);
            });
        }

        if (logStatusFilter) logStatusFilter.addEventListener('change', runLogRefinery);


        // SweetAlert action confirmations for clean, gorgeous administrative overrides
        function confirmResetPassword(event, fullName, userId) {
            event.preventDefault();
            Swal.fire({
                title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Confirm Password Flush</div>',
                html: 'Are you sure you want to reset ' + fullName + '\'s account password to ' + <?php echo json_encode($default_password_setting); ?> + '?',
                icon: 'warning',
                background: '#0d121f',
                color: '#f1f5f9',
                showCancelButton: true,
                confirmButtonText: 'Yes, Reset',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#475569',
                customClass: {
                    popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-7 max-w-md',
                    confirmButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white',
                    cancelButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'admin_dashboard.php?action=reset_password&user_id=' + userId;
                }
            });
        }

        function confirmRoleChange(event, fullName, userId, newRole) {
            event.preventDefault();
            Swal.fire({
                title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Confirm Role Overlap</div>',
                html: 'Proceed with shifting ' + fullName + '\'s operational access model to <b>' + newRole + '</b> level?',
                icon: 'question',
                background: '#0d121f',
                color: '#f1f5f9',
                showCancelButton: true,
                confirmButtonText: 'Yes, Overlap',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#8b5cf6',
                cancelButtonColor: '#475569',
                customClass: {
                    popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-7 max-w-md',
                    confirmButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white',
                    cancelButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'admin_dashboard.php?action=update_role&user_id=' + userId + '&new_role=' + newRole;
                }
            });
        }

        function confirmPurgeUser(event, fullName, userId) {
            event.preventDefault();
            Swal.fire({
                title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Permanent Server Deletion</div>',
                html: 'PROCEED WITH FORENSIC PURGE FOR:<br><span class="text-rose-500 font-extrabold uppercase tracking-wide text-sm">' + fullName + '</span>?<br>This action commits permanent directory delete operations.',
                icon: 'error',
                background: '#0d121f',
                color: '#f1f5f9',
                showCancelButton: true,
                confirmButtonText: 'Yes, Purge Node',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#475569',
                customClass: {
                    popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-7 max-w-md',
                    confirmButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white',
                    cancelButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'admin_dashboard.php?action=delete_user&user_id=' + userId;
                }
            });
        }

        function handleLogout(event) {
            event.preventDefault();
            Swal.fire({
                title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Confirm Logout</div>',
                text: 'Are you sure you want to end your administrator session?',
                icon: 'warning',
                background: '#0d121f',
                color: '#f1f5f9',
                showCancelButton: true,
                confirmButtonText: 'Yes, Sign Out',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#e11d48',
                cancelButtonColor: '#475569',
                customClass: {
                    popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-7 max-w-md',
                    confirmButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white',
                    cancelButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white'
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }
    </script>
</body>
</html>
