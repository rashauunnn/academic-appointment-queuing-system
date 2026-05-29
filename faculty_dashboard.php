<?php
require_once 'security_headers.php';

// Ensure this tab always attaches to the Faculty isolated session on refresh.
if (empty($_COOKIE['ACTIVE_ROLE_SESSION']) || $_COOKIE['ACTIVE_ROLE_SESSION'] !== 'Faculty') {
    setcookie('ACTIVE_ROLE_SESSION', 'Faculty', [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    $_COOKIE['ACTIVE_ROLE_SESSION'] = 'Faculty';
}

require_once 'session_helper.php';
require_once 'db_connect.php';


// Role Guard (Anti-URL Bypass)
check_session_role('Faculty');

// Sync IDs for legacy code
$faculty_id = $_SESSION['faculty_id'] ?? $_SESSION['user_id'];
$faculty_name = $_SESSION['faculty_name'] ?? $_SESSION['full_name'];

function duration_to_seconds($duration) {
    if (!is_numeric($duration)) {
        return 0;
    }

    return max(60, (int)round((float)$duration * 3600));
}

// Check for maintenance mode
try {
    $maintenance_mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() === '1';
    if ($maintenance_mode) {
        header("Location: maintenance.php");
        exit();
    }
} catch (PDOException $e) {}

// Setup database tables if needed to avoid errors
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS faculty_availability (
        id INT AUTO_INCREMENT PRIMARY KEY,
        faculty_id INT NOT NULL,
        unavailable_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        reason VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS current_status VARCHAR(50) DEFAULT 'Available'");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS busy_until DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS status_message VARCHAR(255) DEFAULT 'Available now for questions & office hours sessions.'");
    $pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancel_reason TEXT");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS social_link VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS biography TEXT DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS specialization VARCHAR(255) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS office_hours VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {}

// Handle actions (call, complete, noshow, call_next, update_profile, unavailability, etc.)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $app_id = $_GET['app_id'] ?? null;
    $status_val = $_GET['status_val'] ?? null;
    $avail_id = $_GET['avail_id'] ?? null;

    try {
        switch ($action) {
            case 'update_profile':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $c_num = $_POST['contact_number'] ?? '';
                    $f_email = $_POST['email'] ?? '';
                    $s_link = $_POST['social_link'] ?? '';
                    $bio = $_POST['biography'] ?? '';
                    $spec = $_POST['specialization'] ?? '';
                    $hours = $_POST['office_hours'] ?? '';
                    
                    $stmt = $pdo->prepare("UPDATE users SET contact_number = ?, email = ?, social_link = ?, biography = ?, specialization = ?, office_hours = ? WHERE user_id = ?");
                    $stmt->execute([$c_num, $f_email, $s_link, $bio, $spec, $hours, $faculty_id]);
                    header("Location: faculty_dashboard.php?success=profile_updated");
                    exit();
                }
                break;

            case 'approve_appointment':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Approved' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                    header("Location: faculty_dashboard.php?success=approved");
                    exit();
                }
                break;

            case 'decline_appointment':
                if ($app_id && $_SERVER['REQUEST_METHOD'] === 'POST') {
                    $reason = $_POST['decline_reason'] ?? 'No reason provided';
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Declined', cancel_reason = ? WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$reason, $app_id, $faculty_id]);
                    header("Location: faculty_dashboard.php?success=declined");
                    exit();
                }
                break;

            case 'complete_appointment':
            case 'complete':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Completed' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                    // Try to log core queue duration
                    try {
                        $pdo->prepare("UPDATE queue_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(MINUTE, start_time, NOW()) WHERE app_id = ?")->execute([$app_id]);
                    } catch (PDOException $ex) {}
                    header("Location: faculty_dashboard.php?success=completed");
                    exit();
                }
                break;

            case 'noshow':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'No-Show' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                    header("Location: faculty_dashboard.php?success=noshow");
                    exit();
                }
                break;

            case 'set_unavailability':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $month = $_POST['avail_month'];
                    $day = $_POST['avail_day'];
                    $start_t = $_POST['avail_start'];
                    $end_t = $_POST['avail_end'];
                    $reason_txt = $_POST['avail_reason'] ?? 'On Break';
                    $year = date('Y');
                    $date_str = sprintf("%s-%02d-%02d", $year, $month, $day);

                    $stmt = $pdo->prepare("INSERT INTO faculty_availability (faculty_id, unavailable_date, start_time, end_time, reason) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$faculty_id, $date_str, $start_t, $end_t, $reason_txt]);
                }
                break;

            case 'delete_availability':
                if ($avail_id) {
                    $stmt = $pdo->prepare("DELETE FROM faculty_availability WHERE id = ? AND faculty_id = ?");
                    $stmt->execute([$avail_id, $faculty_id]);
                }
                break;

            case 'update_status':
                if ($status_val) {
                    $duration = $_POST['duration_hours'] ?? null;
                    $status_lower = strtolower($status_val);
                    if ($status_lower === 'busy' && $duration) {
                        $duration_seconds = duration_to_seconds($duration);
                        $stmt = $pdo->prepare("UPDATE users SET current_status = 'Busy', busy_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE user_id = ?");
                        $stmt->execute([$duration_seconds, $faculty_id]);
                    } else {
                        $normalized_status = 'Available';
                        if ($status_lower === 'busy') {
                            $normalized_status = 'Busy';
                        } elseif ($status_lower === 'on leave' || $status_lower === 'on_leave') {
                            $normalized_status = 'On Leave';
                        }
                        $stmt = $pdo->prepare("UPDATE users SET current_status = ?, busy_until = NULL WHERE user_id = ?");
                        $stmt->execute([$normalized_status, $faculty_id]);
                    }
                }
                break;

            case 'call_next':
                $next_stmt = $pdo->prepare("
                    SELECT a.app_id FROM appointments a
                    WHERE a.faculty_id = ? AND a.status IN ('Pending', 'Approved', 'Accepted') 
                    ORDER BY a.created_at ASC LIMIT 1
                ");
                $next_stmt->execute([$faculty_id]);
                $next_app = $next_stmt->fetch();
                
                if ($next_app) {
                    $app_id = $next_app['app_id'];
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Active' WHERE app_id = ?");
                    $stmt->execute([$app_id]);
                    try {
                        $pdo->prepare("INSERT INTO queue_logs (app_id, call_time, start_time) VALUES (?, NOW(), NOW())")->execute([$app_id]);
                    } catch (PDOException $ex) {}
                }
                break;

            case 'call':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Active' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                    try {
                        $pdo->prepare("INSERT INTO queue_logs (app_id, call_time, start_time) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE call_time = NOW(), start_time = NOW()")->execute([$app_id]);
                    } catch (PDOException $ex) {}
                }
                break;
        }
        header("Location: faculty_dashboard.php?msg=success");
        exit();
    } catch (PDOException $e) {
        $error = "System modification failed: " . $e->getMessage();
    }
}

// Fetch Appointments & General Dashboard Layout Properties
try {
    // Fetch faculty state and count remaining seconds timezone-invariantly via MySQL TIMESTAMPDIFF
    $faculty_info = $pdo->prepare("SELECT current_status, contact_number, email AS faculty_email, social_link, busy_until, status_message, biography, specialization, office_hours, TIMESTAMPDIFF(SECOND, NOW(), busy_until) AS remaining_sec FROM users WHERE user_id = ?");
    $faculty_info->execute([$faculty_id]);
    $f_data = $faculty_info->fetch();

    $current_status = $f_data['current_status'] ?? 'Available';
    $status_message = $f_data['status_message'] ?? '';
    if (empty($status_message)) {
        if ($current_status === 'Available') {
            $status_message = 'Available now for questions & office hours sessions.';
        } elseif ($current_status === 'Busy') {
            $status_message = 'A bit busy, will check incoming notifications shortly.';
        } else {
            $status_message = 'Offline. For emergencies, contact department secretary.';
        }
    }
    $busy_until = $f_data['busy_until'] ?? null;
    $remaining_seconds = 0;
    if ($current_status === 'Busy' && $busy_until) {
        $remaining_seconds = isset($f_data['remaining_sec']) ? (int)$f_data['remaining_sec'] : 0;
    }
    $contact_number = $f_data['contact_number'] ?? '';
    $faculty_email = $f_data['faculty_email'] ?? '';
    $social_link = $f_data['social_link'] ?? '';
    $biography = $f_data['biography'] ?? '';
    $specialization = $f_data['specialization'] ?? '';
    $office_hours = $f_data['office_hours'] ?? '';

    // Check if busy timer has expired already on page load
    if ($current_status === 'Busy' && $remaining_seconds <= 0) {
        $pdo->prepare("UPDATE users SET current_status = 'Available', busy_until = NULL WHERE user_id = ?")->execute([$faculty_id]);
        $current_status = 'Available';
        $busy_until = null;
        $remaining_seconds = 0;
    }

    // Serving Active Student (if any)
    $active_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as student_name, u.school_id 
        FROM appointments a 
        JOIN users u ON a.student_id = u.user_id 
        WHERE a.faculty_id = ? AND a.status = 'Active' 
        LIMIT 1
    ");
    $active_stmt->execute([$faculty_id]);
    $active_app = $active_stmt->fetch();

    // Pending / Approved / Accepted List
    $list_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as student_name, u.school_id 
        FROM appointments a 
        JOIN users u ON a.student_id = u.user_id 
        WHERE a.faculty_id = ? AND a.status IN ('Pending', 'Approved', 'Accepted') 
        ORDER BY FIELD(a.status, 'Accepted', 'Approved', 'Pending'), a.created_at ASC
    ");
    $list_stmt->execute([$faculty_id]);
    $appointments = $list_stmt->fetchAll();

    // Next in Line (oldest pending)
    $next_in_line = !empty($appointments) ? $appointments[0] : null;

    // Queue metrics
    $total_pending = count($appointments);
    $queue_load_mins = $total_pending * 15;
    $queue_load_hrs = floor($queue_load_mins / 60);
    $queue_load_remainder_mins = $queue_load_mins % 60;
    
    $queue_load_str = ($queue_load_hrs > 0 ? "{$queue_load_hrs}h " : "") . "{$queue_load_remainder_mins}m";

    // Fetch Temporal Blocks
    $avail_stmt = $pdo->prepare("SELECT * FROM faculty_availability WHERE faculty_id = ? AND unavailable_date >= CURDATE() ORDER BY unavailable_date ASC, start_time ASC");
    $avail_stmt->execute([$faculty_id]);
    $blocked_slots = $avail_stmt->fetchAll();

    // Fetch History / Archives
    $history_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as student_name, u.school_id 
        FROM appointments a 
        JOIN users u ON a.student_id = u.user_id 
        WHERE a.faculty_id = ? AND a.status IN ('Completed', 'Cancelled', 'No-Show', 'Declined')
        ORDER BY a.created_at DESC LIMIT 50
    ");
    $history_stmt->execute([$faculty_id]);
    $archives = $history_stmt->fetchAll();

    // Count statistics from archives
    $total_completed = 0;
    $total_cancelled = 0;
    foreach ($archives as $arch) {
        if ($arch['status'] === 'Completed') {
            $total_completed++;
        } elseif (in_array($arch['status'], ['Cancelled', 'Declined', 'No-Show'])) {
            $total_cancelled++;
        }
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Premium Panel | ConsultCare</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['Inter', 'sans-serif'],
                        mono: ['JetBrains Mono', 'monospace']
                    },
                    colors: {
                        slate: {
                            900: '#090d16',
                            950: '#03050a'
                        }
                    }
                }
            }
        }

        // Theme initialization + toggle (matches student_dashboard.php approach)
        (function initTheme() {
            const saved = localStorage.getItem('theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;

            if (saved === 'light' || (!saved && !prefersDark)) {
                document.documentElement.classList.remove('dark');
            } else {
                document.documentElement.classList.add('dark');
            }

            updateThemeIcons();
        })();

        function toggleTheme() {
            console.log('[ThemeToggle] clicked');
            const isDark = document.documentElement.classList.contains('dark');

            if (isDark) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
            updateThemeIcons();
        }

        function updateThemeIcons() {
            const sunIcon = document.getElementById('sun-icon');
            const moonIcon = document.getElementById('moon-icon');
            const isLight = !document.documentElement.classList.contains('dark');
            if (sunIcon) sunIcon.classList.toggle('hidden', !isLight);
            if (moonIcon) moonIcon.classList.toggle('hidden', isLight);
        }
    </script>
    <style>
        body {
            background-image: radial-gradient(circle at 0% 0%, rgba(99, 102, 241, 0.05) 0%, transparent 40%),
                              radial-gradient(circle at 100% 100%, rgba(239, 68, 68, 0.03) 0%, transparent 45%);
        }
        /* Custom scrollbars */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #03050a;
        }
        ::-webkit-scrollbar-thumb {
            background: #1e293b;
            border-radius: 999px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: #334155;
        }
    </style>
    <style type="text/tailwindcss">
        @layer base {
            body { 
                @apply bg-slate-950 text-slate-100 font-sans tracking-tight antialiased overflow-x-hidden;
            }
        }
        @layer components {
            .glass-card {
                @apply bg-[#0b0f19]/60 backdrop-blur-2xl border border-white/[0.04] shadow-[0_25px_50px_-12px_rgba(0,0,0,0.5)] transition-all duration-300;
            }
            .glass-card:hover {
                @apply border-white/[0.08] shadow-[0_30px_60px_rgba(0,0,0,0.6)];
            }
            .sidebar-btn {
                @apply flex items-center gap-3 px-4 py-3 rounded-2xl text-[13px] font-semibold text-slate-400 hover:text-white hover:bg-white/[0.03] border border-transparent transition-all duration-200 select-none cursor-pointer;
            }
            .sidebar-btn.active {
                @apply bg-violet-600/10 text-violet-400 border-violet-500/20 shadow-lg shadow-violet-600/[0.03];
            }
            .state-card {
                @apply relative overflow-hidden rounded-[2rem] p-6 border transition-all duration-300 flex flex-col justify-between text-left cursor-pointer;
            }
            .action-btn {
                @apply h-11 px-6 rounded-2xl text-[11px] font-bold uppercase tracking-wider transition-all flex items-center gap-2 justify-center;
            }
            .input-field {
                @apply w-full bg-slate-900/50 border border-white/[0.05] focus:border-violet-500/50 rounded-2xl px-5 py-3 text-sm text-white placeholder-slate-500 outline-none transition-all;
            }
        }
    </style>
</head>
<body class="min-h-screen">

    <div class="flex min-h-screen">
        <!-- COLLAPSIBLE SLEEK SIDEBAR -->
        <aside id="sidebar" class="w-72 shrink-0 border-r border-white/[0.04] bg-slate-900/30 backdrop-blur-3xl flex flex-col justify-between transition-all duration-300 ease-in-out relative z-50">
            <!-- Top brand panel -->
            <div class="px-7 py-6 flex items-center justify-between border-b border-white/[0.04]">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-violet-600/30">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="animate-pulse"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z"/><path d="m15 13-3-3-3 3M12 10v9"/></svg>
                    </div>
                    <div class="sidebar-text">
                        <h1 class="text-xs font-black uppercase tracking-[0.3em] text-white">ConsultCare</h1>
                        <p class="text-[9px] font-bold text-slate-500 uppercase tracking-wider">Faculty Module</p>
                    </div>
                </div>
                <!-- Toggle collapse btn -->
                <button onclick="toggleSidebarCollapse()" class="w-8 h-8 rounded-xl bg-white/[0.02] border border-white/[0.04] text-slate-400 hover:text-white hover:bg-white/[0.05] transition-all flex items-center justify-center cursor-pointer">
                    <svg id="sidebar-toggle-icon" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
                </button>
            </div>

            <!-- Navigation menus -->
            <div class="flex-1 px-4 py-6 space-y-2 overflow-y-auto">
                <button onclick="showTab('dashboard')" id="tab-dashboard" class="sidebar-btn active w-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    <span class="sidebar-text">Dashboard</span>
                </button>

                <button onclick="showTab('schedule')" id="tab-schedule" class="sidebar-btn w-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span class="sidebar-text">Queue & Schedule</span>
                </button>



                <button onclick="showTab('requests')" id="tab-requests" class="sidebar-btn w-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="sidebar-text">Student Queue</span>
                </button>

                <button onclick="showTab('archives')" id="tab-archives" class="sidebar-btn w-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                    <span class="sidebar-text">Reports & Archives</span>
                </button>

                <button onclick="showTab('profile')" id="tab-profile" class="sidebar-btn w-full">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <span class="sidebar-text">Faculty Profile</span>
                </button>
            </div>

            <!-- Profile and logout block -->
            <div class="p-4 border-t border-white/[0.04] bg-slate-950/20">
                <div class="flex items-center gap-3 p-2.5 rounded-2xl bg-white/[0.01] border border-white/[0.03]">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center text-sm font-black text-white italic shadow-lg shrink-0 select-none">
                        <?php echo strtoupper(substr($faculty_name, 0, 1)); ?>
                    </div>
                    <div class="flex-1 min-w-0 sidebar-text">
                        <p class="text-xs font-bold text-white truncate"><?php echo htmlspecialchars($faculty_name); ?></p>
                        <p class="text-[9px] font-mono font-medium text-violet-400 uppercase tracking-widest">Faculty Lead</p>
                    </div>
                </div>
                <div class="mt-2.5 flex gap-2">
                    <a href="logout.php" onclick="handleLogout(event)" class="flex-1 h-10 rounded-xl bg-rose-500/10 text-rose-500 border border-rose-500/15 hover:bg-rose-500 hover:text-white transition-all flex items-center justify-center gap-2 text-[10px] font-bold uppercase tracking-wider">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Exit Log
                    </a>
                </div>
            </div>
        </aside>

        <!-- Dynamic Mobile Bottom Navigation -->
        <div class="fixed bottom-0 left-0 right-0 h-16 border-t border-white/[0.05] bg-slate-900/90 backdrop-blur-2xl px-6 flex items-center justify-between md:hidden z-50">
            <button onclick="showTab('dashboard')" id="mobile-tab-dashboard" class="flex flex-col items-center gap-1.5 text-violet-500">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/></svg>
                <span class="text-[9px] font-bold tracking-widest uppercase">Console</span>
            </button>
            <button onclick="showTab('schedule')" id="mobile-tab-schedule" class="flex flex-col items-center gap-1.5 text-slate-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span class="text-[9px] font-bold tracking-widest uppercase">Control</span>
            </button>
            <button onclick="showTab('requests')" id="mobile-tab-requests" class="flex flex-col items-center gap-1.5 text-slate-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/></svg>
                <span class="text-[9px] font-bold tracking-widest uppercase">Student</span>
            </button>
            <button onclick="showTab('archives')" id="mobile-tab-archives" class="flex flex-col items-center gap-1.5 text-slate-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/></svg>
                <span class="text-[9px] font-bold tracking-widest uppercase">Metrics</span>
            </button>
            <button onclick="showTab('profile')" id="mobile-tab-profile" class="flex flex-col items-center gap-1.5 text-slate-400">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="4"/></svg>
                <span class="text-[9px] font-bold tracking-widest uppercase">Settings</span>
            </button>
        </div>

        <!-- MAIN APP WORKSPACE -->
        <main class="flex-1 flex flex-col min-w-0 pb-20 md:pb-0">
            <!-- NAVBAR HEADER WITH LOGGING INFORMATION -->
            <header class="h-20 border-b border-white/[0.04] bg-slate-900/10 backdrop-blur-md px-8 flex items-center justify-between shrink-0">
                <div class="flex items-center gap-4">
                    <h2 class="text-lg font-extrabold tracking-tight text-white hidden sm:block">Faculty Command Center</h2>
                    <!-- Operational Status Line Badge -->
                    <div class="flex items-center gap-2 px-3.5 py-1.5 rounded-full text-[11px] font-mono font-bold border transition-all duration-300
                        <?php 
                        if ($current_status === 'Available') {
                            echo 'bg-emerald-500/10 border-emerald-500/20 text-emerald-400 shadow-[0_0_15px_rgba(16,185,129,0.15)] animate-pulse';
                        } elseif ($current_status === 'Busy') {
                            echo 'bg-orange-500/10 border-orange-500/20 text-orange-400 shadow-[0_0_15px_rgba(249,115,22,0.15)] animate-pulse';
                        } else {
                            echo 'bg-rose-500/10 border-rose-500/20 text-rose-400 shadow-[0_0_15px_rgba(239,68,68,0.15)] animate-pulse';
                        }
                        ?>" 
                        id="header-status-pill"
                    >
                        <span class="w-1.5 h-1.5 rounded-full shrink-0 
                            <?php 
                            if ($current_status === 'Available') echo 'bg-emerald-400';
                            elseif ($current_status === 'Busy') echo 'bg-orange-400';
                            else echo 'bg-rose-400';
                            ?>"></span>
                        <span><?php echo htmlspecialchars($current_status); ?> Mode</span>
                    </div>
                </div>

                <!-- Right dynamic metadata clocks -->
                    <div class="flex items-center gap-4 text-xs font-mono text-slate-400">
                    		<!-- Theme Toggle -->
                        <button id="theme-toggle" type="button" onclick="toggleTheme()" class="w-10 h-10 rounded-xl bg-white/[0.02] border border-white/[0.04] text-slate-400 hover:text-indigo-500 transition-colors flex items-center justify-center cursor-pointer">
                            <svg id="sun-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block">
                                <circle cx="12" cy="12" r="4"/>
                                <path d="M12 2v2"/>
                                <path d="M12 20v2"/>
                                <path d="m4.93 4.93 1.41 1.41"/>
                                <path d="m17.66 17.66 1.41 1.41"/>
                                <path d="M2 12h2"/>
                                <path d="M20 12h2"/>
                                <path d="m6.34 17.66-1.41 1.41"/>
                                <path d="m19.07 4.93-1.41 1.41"/>
                            </svg>
                            <svg id="moon-icon" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden">
                                <path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>
                            </svg>
                        </button>
                    
                        <div class="bg-white/[0.01] border border-white/[0.04] px-4 py-2 rounded-2xl flex items-center gap-2">
                        <svg class="w-3.5 h-3.5 text-violet-500 shrink-0" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span id="nav-utc-clock" class="text-white">--:--:-- UTC</span>
                    </div>
                </div>
            </header>

            <!-- VIEWPORT SCROLLABLE CONTAINER -->
            <div class="flex-1 overflow-y-auto px-8 py-8 space-y-10">

                <!-- STATUS BANNER / PROMPT STATEMENTS -->
                <?php if ($current_status === 'On Leave'): ?>
                    <div class="flex items-start gap-4 bg-rose-500/10 border border-rose-500/20 p-5 rounded-3xl animate-in fade-in duration-300">
                        <div class="w-10 h-10 rounded-2xl bg-rose-500/20 flex items-center justify-center text-rose-400 shrink-0 shadow-lg">
                            <svg class="w-5 h-5 animate-bounce" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        </div>
                        <div class="text-xs text-rose-300 leading-relaxed self-center">
                            <span class="font-black uppercase text-xs block text-rose-400 mb-0.5">On Leave Active</span>
                            You are in <strong class="text-white">On Leave mode</strong>. Student appointments are blocked until you return to Available.
                        </div>
                    </div>
                <?php elseif ($current_status === 'Busy'): ?>
                    <div class="flex items-start gap-4 bg-orange-500/10 border border-orange-500/20 p-5 rounded-3xl animate-in fade-in duration-300">
                        <div class="w-10 h-10 rounded-2xl bg-orange-500/20 flex items-center justify-center text-orange-400 shrink-0 shadow-lg">
                            <svg class="w-5 h-5" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <div class="text-xs text-orange-300 leading-relaxed self-center">
                            <span class="font-black uppercase text-xs block text-orange-400 mb-0.5">Busy Timer Active</span>
                            Currently in <strong class="text-white">Busy mode</strong>. Students can see when you will be available again.
                        </div>
                    </div>
                <?php endif; ?>

                <!-- VIEW 1: DASHBOARD -->
                <div id="view-dashboard" class="tab-view space-y-10">

                    <!-- TOP PROFILE HEADER & STATUS STATEMENT -->
                    <div class="glass-card rounded-[2.5rem] p-8 relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-80 h-80 bg-violet-600/5 blur-3xl -mr-32 -mt-32"></div>
                        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
                            
                            <div class="flex items-center gap-5">
                                <div class="relative">
                                    <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center text-2xl font-black text-white italic shadow-lg shadow-violet-600/30 shrink-0 select-none">
                                        <?php echo strtoupper(substr($faculty_name, 0, 1)); ?>
                                    </div>
                                    <!-- Status beacon widget (glows based on current status) -->
                                    <span class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full border-4 border-[#0d121f] flex items-center justify-center 
                                        <?php 
                                        if ($current_status === 'Available') echo 'bg-emerald-500 shadow-[0_0_14px_rgba(16,185,129,0.55)]';
                                        elseif ($current_status === 'Busy') echo 'bg-orange-500 shadow-[0_0_14px_rgba(249,115,22,0.55)]';
                                        else echo 'bg-rose-500 shadow-[0_0_14px_rgba(244,63,94,0.55)]';
                                        ?>">
                                        <span class="absolute inline-flex h-full w-full rounded-full opacity-75 
                                            <?php 
                                            if ($current_status === 'Available') echo 'bg-emerald-400 animate-ping';
                                            elseif ($current_status === 'Busy') echo 'bg-orange-400 animate-ping';
                                            else echo 'bg-rose-400 animate-ping';
                                            ?>"></span>
                                    </span>

                                </div>
                                <div>
                                    <div class="flex items-center gap-3">
                                        <h3 class="text-xl font-black tracking-tight text-white"><?php echo htmlspecialchars($faculty_name); ?></h3>
                                        <span class="px-2.5 py-0.5 text-[9px] font-mono font-black rounded-lg bg-violet-500/20 text-violet-300 border border-violet-500/35 uppercase tracking-wider">Console Active</span>
                                    </div>
                                    <?php if (!empty($specialization)): ?>
                                        <p class="text-[11px] text-slate-400 font-mono font-bold uppercase tracking-wider mt-1"><?php echo htmlspecialchars($specialization); ?></p>
                                    <?php else: ?>
                                        <p class="text-[11px] text-slate-500 font-mono font-bold uppercase tracking-wider mt-1">EECS Senior Faculty Advisor</p>
                                    <?php endif; ?>

                                    <!-- Status broadcast statement bar -->
                                    <div class="mt-3 flex items-center gap-3 bg-white/[0.01] border border-white/[0.04] p-1.5 rounded-2xl max-w-lg md:max-w-2xl px-3">
                                        <span class="text-[9px] font-mono font-black text-violet-400 tracking-wider uppercase shrink-0">Broadcast Statement:</span>
                                        <span id="display-status-msg" class="text-xs text-slate-300 font-semibold italic truncate">
                                            "<?php echo htmlspecialchars($status_message); ?>"
                                        </span>
                                        <button onclick="triggerCustomMsgEdit()" class="text-[10px] text-violet-400 hover:text-violet-300 font-bold underline cursor-pointer shrink-0 ml-auto pl-2">
                                            Edit
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <!-- Stat Load box -->
                            <div class="bg-white/[0.02] border border-white/[0.05] p-5 rounded-2xl md:w-56 shrink-0 font-mono select-none">
                                <div class="text-[9px] text-slate-500 font-black uppercase tracking-[0.2em] mb-1">Queue Response Time</div>
                                <div class="text-3xl font-black text-violet-400 tracking-tighter"><?php echo $queue_load_str; ?></div>
                                <div class="text-[10px] text-slate-400 mt-1">For <?php echo $total_pending; ?> waiting consultation slots</div>
                            </div>

                        </div>
                    </div>

                    <!-- Row of 4 Stats Cards (Bento-Grid) -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                        <!-- Stat 1: Queue Backlog -->
                        <div class="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col justify-between min-h-[140px]">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Wait list backlog</span>
                                <div class="p-2.5 rounded-xl bg-violet-600/15 text-violet-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                </div>
                            </div>
                            <div>
                                <div class="text-4xl font-black font-mono tracking-tight text-white mb-1">
                                    <?php echo $total_pending; ?>
                                </div>
                                <p class="text-[11px] text-slate-400 leading-normal">Active student bookings queued</p>
                            </div>
                        </div>

                        <!-- Stat 2: Active Consult Status -->
                        <div class="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col justify-between min-h-[140px]">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Active session</span>
                                <div class="p-2.5 rounded-xl bg-emerald-600/15 text-emerald-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polygon points="17 6 23 6 23 12"/></svg>
                                </div>
                            </div>
                            <div>
                                <div class="text-[15px] font-extrabold text-white truncate max-w-[180px] mb-1">
                                    <?php echo $active_app ? htmlspecialchars($active_app['student_name']) : '<span class="text-slate-600">None Active</span>'; ?>
                                </div>
                                <p class="text-[11px] text-slate-400 leading-normal">
                                    <?php echo $active_app ? 'Consultation currently in line' : 'Waiting on desk call'; ?>
                                </p>
                            </div>
                        </div>

                        <!-- Stat 3: Total Completed Today -->
                        <div class="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col justify-between min-h-[140px]">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Successful Logs</span>
                                <div class="p-2.5 rounded-xl bg-blue-600/15 text-blue-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                </div>
                            </div>
                            <div>
                                <div class="text-4xl font-black font-mono tracking-tight text-white mb-1">
                                    <?php echo $total_completed; ?>
                                </div>
                                <p class="text-[11px] text-slate-400 leading-normal">Meetings completed this cycle</p>
                            </div>
                        </div>

                        <!-- Stat 4: Cancelled Rates -->
                        <div class="glass-card rounded-3xl p-6 relative overflow-hidden flex flex-col justify-between min-h-[140px]">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Revocations</span>
                                <div class="p-2.5 rounded-xl bg-rose-600/15 text-rose-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/></svg>
                                </div>
                            </div>
                            <div>
                                <div class="text-4xl font-black font-mono tracking-tight text-white mb-1">
                                    <?php echo $total_cancelled; ?>
                                </div>
                                <p class="text-[11px] text-slate-400 leading-normal">Cancellations / No-Shows</p>
                            </div>
                        </div>
                    </div>

                    <!-- HERO: ACTIVE STUDENT SESSION CONTROLLER -->
                    <div class="glass-card rounded-[2.5rem] p-1 relative overflow-hidden border border-white/[0.05]">
                        <div class="absolute inset-0 bg-gradient-to-br from-violet-600/10 via-slate-900/40 to-transparent"></div>
                        <div class="relative bg-[#0d121f]/90 rounded-[2.4rem] p-8 md:p-12 overflow-hidden">
                            <div class="absolute top-0 right-0 w-[400px] h-[400px] bg-violet-600/10 blur-[120px] -mr-32 -mt-32"></div>

                            <div class="relative z-10 flex flex-col lg:flex-row justify-between items-center gap-10">
                                <div class="flex-1 text-center lg:text-left">
                                    <span class="px-3 py-1.5 rounded-full bg-violet-500/15 text-violet-300 text-[10px] font-black uppercase tracking-[0.30em] border border-violet-500/25 mb-6 inline-block">Session Controller</span>
                                    
                                    <?php if ($active_app): ?>
                                        <h2 class="text-4xl font-black tracking-tight text-white uppercase italic">
                                            Currently Serving: <span class="text-violet-400 not-italic"><?php echo htmlspecialchars($active_app['student_name']); ?></span>
                                        </h2>
                                        <p class="text-xs font-mono font-bold text-slate-400 uppercase tracking-widest mt-2">
                                            Student ID: <span class="text-white"><?php echo htmlspecialchars($active_app['school_id']); ?></span>
                                        </p>
                                        <div class="mt-6 flex flex-col sm:flex-row items-center gap-3 bg-white/[0.02] border border-white/[0.05] p-4 rounded-2xl">
                                            <span class="text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest shrink-0">Consult Purpose:</span>
                                            <span class="text-sm text-slate-300 font-medium text-left">"<?php echo htmlspecialchars($active_app['reason']); ?>"</span>
                                        </div>
                                    <?php else: ?>
                                        <h2 class="text-3xl font-black tracking-tight text-white uppercase italic">
                                            No Active <span class="text-slate-500">Meeting Session</span>
                                        </h2>
                                        <p class="text-sm text-slate-400 max-w-md mt-2 leading-relaxed">
                                            Your consult desk is currently clear. Press call next student to invite the oldest pending appointment.
                                        </p>
                                    <?php endif; ?>
                                </div>

                                <div class="shrink-0 w-full lg:w-auto flex flex-col sm:flex-row lg:flex-col gap-3 min-w-[240px]">
                                    <?php if ($active_app): ?>
                                        <a href="faculty_dashboard.php?action=complete&app_id=<?php echo $active_app['app_id']; ?>" 
                                           class="action-btn bg-emerald-600 hover:bg-emerald-500 hover:scale-[1.02] shadow-xl shadow-emerald-600/10 text-white w-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                            Complete Appointment

                                        </a>
                                        
                                        <a href="faculty_dashboard.php?action=noshow&app_id=<?php echo $active_app['app_id']; ?>" 
                                           class="action-btn bg-slate-800 hover:bg-slate-700 hover:scale-[1.02] text-slate-200 border border-white/[0.06] w-full">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                            Register No-Show

                                        </a>

                                        <button onclick="openCancelModal(<?php echo $active_app['app_id']; ?>, '<?php echo htmlspecialchars($active_app['student_name']); ?>')" 
                                                class="action-btn bg-rose-500/10 text-rose-400 border border-rose-500/20 hover:bg-rose-500 hover:text-white hover:scale-[1.02] transition-all w-full cursor-pointer">
                                                Urgent Cancel
                                        </button>
                                    <?php else: ?>
                                        <?php if ($next_in_line): ?>
                                            <a href="faculty_dashboard.php?action=call_next" 
                                               class="action-btn bg-violet-600 hover:bg-violet-500 hover:scale-[1.02] active:scale-95 shadow-xl shadow-violet-600/20 text-white w-full cursor-pointer">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="animate-bounce"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                                Start Next (<?php echo htmlspecialchars($next_in_line['student_name']); ?>)
                                            </a>
                                        <?php else: ?>
                                            <button disabled 
                                                    class="action-btn bg-slate-900 border border-white/[0.04] text-slate-500 select-none w-full opacity-60">
                                                Queue is Empty
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- RECENT QUEUE ACTIVITY ACTIVITY LIST -->
                    <div class="glass-card rounded-[2rem] p-8">
                        <div class="flex items-center justify-between mb-6">
                            <div>
                                <h3 class="text-lg font-black tracking-tight text-white uppercase italic">Active Queue Backlog <span class="text-violet-400">(First 5)</span></h3>
                                <p class="text-xs text-slate-500">The actual live queue stack in order of prioritization</p>
                            </div>
                            <button onclick="showTab('requests')" class="text-xs text-violet-400 hover:text-violet-300 font-bold uppercase tracking-wider">
                                View Full List
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b border-white/[0.04]">
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Student Info</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Scheduled Mode / Window</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Appointment Purpose</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Priority State</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.02]">
                                    <?php 
                                    $cnt = 0;
                                    foreach ($appointments as $app): 
                                        if ($cnt >= 5) break;
                                        $cnt++;
                                    ?>
                                        <tr class="align-middle hover:bg-white/[0.01]">
                                            <td class="py-4">
                                                <div class="font-extrabold text-sm text-white"><?php echo htmlspecialchars($app['student_name']); ?></div>
                                                <div class="text-[10px] font-mono font-bold text-slate-500 uppercase mt-0.5"><?php echo htmlspecialchars($app['school_id']); ?></div>
                                            </td>
                                            <td class="py-4 text-xs font-mono text-slate-400">
                                                <?php echo htmlspecialchars($app['appointment_date']) ?: 'Walk-In Spot'; ?>
                                                <span class="block text-[10px] text-slate-500 uppercase mt-0.5"><?php echo htmlspecialchars($app['time_slot']); ?></span>
                                            </td>
                                            <td class="py-4 hidden sm:table-cell text-xs text-slate-300 max-w-xs truncate">
                                                <?php echo htmlspecialchars($app['reason']); ?>
                                            </td>
                                            <td class="py-4">
                                                <span class="px-2.5 py-0.5 rounded-full text-[9px] font-mono font-black border uppercase tracking-wider
                                                    <?php 
                                                    if ($app['status'] === 'Accepted') echo 'bg-blue-600/15 text-blue-400 border-blue-500/20';
                                                    elseif ($app['status'] === 'Approved') echo 'bg-indigo-600/15 text-indigo-400 border-indigo-500/20';
                                                    else echo 'bg-amber-600/15 text-amber-400 border-amber-500/20 animate-pulse';
                                                    ?>">
                                                    <?php echo htmlspecialchars($app['status']); ?>
                                                </span>
                                            </td>
                                            <td class="py-4 text-right">
                                                <div class="flex items-center justify-end gap-2.5">
                                                    <a href="faculty_dashboard.php?action=call&app_id=<?php echo $app['app_id']; ?>" 
                                                       title="Activate Consult" 
                                                       class="w-8 h-8 rounded-lg bg-violet-600/10 hover:bg-violet-600 border border-violet-500/20 text-violet-400 hover:text-white transition-all flex items-center justify-center">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                                    </a>
                                                    <button onclick="openCancelModal(<?php echo $app['app_id']; ?>, '<?php echo htmlspecialchars($app['student_name']); ?>')" 
                                                            title="Decline Spot" 
                                                            class="w-8 h-8 rounded-lg bg-rose-500/10 hover:bg-rose-500 border border-rose-500/20 text-rose-400 hover:text-white transition-all flex items-center justify-center cursor-pointer">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if ($cnt === 0): ?>
                                        <tr>
                                            <td colspan="5" class="py-10 text-center text-xs text-slate-500 font-medium">
                                                There are currently no students waiting in the queue.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- VIEW 2: QUEUE & SCHEDULE -->
                <div id="view-schedule" class="tab-view space-y-10 hidden">

                    <!-- CENTERPIECE QUEUE CONTROL SYSTEM -->
                    <div class="glass-card rounded-[2.5rem] p-10">
                        <div class="mb-8">
                                        <h2 class="text-2xl font-black text-white uppercase italic">Queue Operating <span class="text-violet-400">Availability Controller</span></h2>
                            <p class="text-xs text-slate-500 mt-2 font-medium">Broadcast your live availability state globally to student screens in real-time.</p>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- STATE 1: QUEUE OPEN (Emerald) -->
                            <?php $isOpenActive = ($current_status === 'Available'); ?>
                            <div id="state-available" onclick="handleQueueStatus('Available')" 
                                 class="state-card group cursor-pointer transition-all duration-300 <?php 
                                    echo $isOpenActive 
                                        ? 'bg-emerald-500/[0.04] border-emerald-500 shadow-[0_0_30px_rgba(16,185,129,0.2)] ring-1 ring-emerald-500/30 scale-[1.01]' 
                                        : 'bg-white/[0.01] border-white/[0.04] hover:border-emerald-505/50 hover:bg-emerald-550/[0.01] opacity-70 hover:opacity-100'; 
                                 ?>">
                                <div class="flex items-start justify-between w-full mb-8">
                                    <div class="p-3 rounded-2xl bg-emerald-500/10 text-emerald-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="<?php echo $isOpenActive ? 'animate-pulse' : ''; ?>"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
                                    </div>
                                    <div class="status-badge <?php echo $isOpenActive ? 'flex' : 'hidden'; ?> items-center gap-1.5 px-3 py-1 rounded-full bg-emerald-500/15 text-emerald-400 text-[8px] font-black uppercase tracking-widest leading-none">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-450 animate-ping"></span>
                                        ACTIVE
                                    </div>
                                </div>
                                <div>
                                    <h4 class="text-base font-extrabold tracking-tight text-white uppercase">Available</h4>
                                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">Students can book open appointment slots while you are ready for consultation.</p>
                                </div>
                            </div>

                            <!-- STATE 2: BUSY (Orange) -->
                            <?php $isBusyActive = ($current_status === 'Busy'); ?>
                            <div id="state-busy" onclick="handleQueueStatus('Busy')" 
                                 class="state-card group cursor-pointer transition-all duration-300 <?php 
                                    echo $isBusyActive 
                                        ? 'bg-orange-500/[0.04] border-orange-500 shadow-[0_0_30px_rgba(249,115,22,0.25)] ring-1 ring-orange-500/30 scale-[1.01]' 
                                        : 'bg-white/[0.01] border-white/[0.04] hover:border-orange-505/50 hover:bg-orange-550/[0.01] opacity-70 hover:opacity-100'; 
                                 ?>">
                                <div class="flex items-start justify-between w-full mb-8">
                                    <div class="p-3 rounded-2xl bg-orange-500/10 text-orange-400">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="<?php echo $isBusyActive ? 'animate-spin' : ''; ?>"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </div>
                                    <div class="status-badge <?php echo $isBusyActive ? 'flex' : 'hidden'; ?> items-center gap-1.5 px-3 py-1 rounded-full bg-orange-500/15 text-orange-400 text-[8px] font-black uppercase tracking-widest leading-none">
                                        <span class="w-1.5 h-1.5 rounded-full bg-orange-450 animate-ping"></span>
                                        BUSY ACTIVE
                                    </div>
                                </div>
                                <div>
                                    <h4 class="text-base font-extrabold tracking-tight text-white uppercase" id="card-busy-title">
                                        <?php if ($isBusyActive && $remaining_seconds > 0): ?>
                                            Busy (<?php echo sprintf("%02d:%02d", floor($remaining_seconds / 60), $remaining_seconds % 60); ?>)
                                        <?php else: ?>
                                            Busy
                                        <?php endif; ?>
                                    </h4>
                                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed" id="card-busy-desc">
                                        <?php if ($isBusyActive && $busy_until): ?>
                                            Students will see you as busy until this timer ends. <span class="text-orange-400 font-bold font-mono">countdown active</span>.
                                        <?php else: ?>
                                            Set how long you will be unavailable for meetings, classes, or other duties.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>

                            <!-- STATE 3: QUEUE CLOSED (Rose Red) -->
                            <?php $isClosedActive = ($current_status === 'On Leave'); ?>
                            <div id="state-leave" onclick="handleQueueStatus('On Leave')" 
                                 class="state-card group cursor-pointer transition-all duration-300 <?php 
                                    echo $isClosedActive 
                                        ? 'bg-rose-500/[0.04] border-rose-500 shadow-[0_0_30px_rgba(239,68,68,0.2)] ring-1 ring-rose-500/30 scale-[1.01]' 
                                        : 'bg-white/[0.01] border-white/[0.04] hover:border-rose-505/50 hover:bg-rose-550/[0.01] opacity-70 hover:opacity-100'; 
                                 ?>">
                                <div class="flex items-start justify-between w-full mb-8">
                                    <div class="p-3 rounded-2xl bg-rose-500/10 text-rose-455">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="<?php echo $isClosedActive ? 'animate-bounce' : ''; ?>"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                                    </div>
                                    <div class="status-badge <?php echo $isClosedActive ? 'flex' : 'hidden'; ?> items-center gap-1.5 px-3 py-1 rounded-full bg-rose-500/15 text-rose-400 text-[8px] font-black uppercase tracking-widest leading-none">
                                        <span class="w-1.5 h-1.5 rounded-full bg-rose-450 animate-ping"></span>
                                        ON LEAVE
                                    </div>
                                </div>
                                <div>
                                    <h4 class="text-base font-extrabold tracking-tight text-white uppercase">On Leave</h4>
                                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">Students cannot book while you are away from school or not accepting appointments.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- BLOCKED SLOTS MANAGEMENT GRID (Availability Blocks) -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
                        <!-- Left block: Add unavailability slot form -->
                        <div class="lg:col-span-1">
                            <div class="glass-card rounded-[2rem] p-8 space-y-6">
                                <div>
                                    <h3 class="text-lg font-black tracking-tight text-white uppercase italic">Block <span class="text-violet-400">Duty Window</span></h3>
                                    <p class="text-xs text-slate-500">Insert custom blocks for appointments or personal breaks.</p>
                                </div>

                                <form action="faculty_dashboard.php?action=set_unavailability" method="POST" class="space-y-4">
                                    <div class="grid grid-cols-2 gap-4">
                                        <div>
                                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block pl-1">Month</label>
                                            <select name="avail_month" class="input-field bg-slate-900" required>
                                                <?php
                                                for ($m = 1; $m <= 12; $m++) {
                                                    $sel = ($m == date('n')) ? 'selected' : '';
                                                    echo "<option value='$m' $sel>" . date('F', mktime(0, 0, 0, $m, 1)) . "</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block pl-1">Day</label>
                                            <select name="avail_day" class="input-field bg-slate-900" required>
                                                <?php
                                                $days_in_month = date('t');
                                                for ($d = 1; $d <= $days_in_month; $d++) {
                                                    $sel = ($d == date('j')) ? 'selected' : '';
                                                    echo "<option value='$d' $sel>$d</option>";
                                                }
                                                ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block pl-1">Commence Time</label>
                                        <input type="time" name="avail_start" class="input-field" required>
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block pl-1">Conclude Time</label>
                                        <input type="time" name="avail_end" class="input-field" required>
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block pl-1">Justification Reason</label>
                                        <input type="text" name="avail_reason" class="input-field" placeholder="Seminar, Board review, PhD exam..." required>
                                    </div>

                                    <button type="submit" class="w-full py-4 rounded-xl bg-violet-600 hover:bg-violet-500 text-white font-extrabold text-xs uppercase tracking-widest shadow-xl shadow-violet-600/10 mt-2 transition-all">
                                        Confirm Block
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Right block: List existing unavailability periods -->
                        <div class="lg:col-span-2">
                            <div class="glass-card rounded-[2rem] p-8">
                                <h3 class="text-lg font-black tracking-tight text-white uppercase italic mb-6">Existing <span class="text-violet-400">Blocked Duty Blocks</span></h3>
                                
                                <div class="space-y-4">
                                    <?php foreach ($blocked_slots as $slot): ?>
                                        <div class="group flex items-center justify-between p-4 rounded-2xl bg-white/[0.01] hover:bg-white/[0.02] border border-white/[0.03] transition-all">
                                            <div class="flex items-center gap-4">
                                                <div class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-400 flex items-center justify-center">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                                </div>
                                                <div>
                                                    <div class="text-sm font-bold text-white"><?php echo htmlspecialchars($slot['reason']); ?></div>
                                                    <p class="text-xs text-slate-450 mt-1 font-mono">
                                                        <?php echo date('M d, Y', strtotime($slot['unavailable_date'])); ?> • 
                                                        <?php echo date('h:i A', strtotime($slot['start_time'])); ?> - 
                                                        <?php echo date('h:i A', strtotime($slot['end_time'])); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <a href="faculty_dashboard.php?action=delete_availability&avail_id=<?php echo $slot['id']; ?>" class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-500 flex items-center justify-center border border-rose-500/20 opacity-0 group-hover:opacity-100 transition-all hover:bg-rose-500 hover:text-white cursor-pointer">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/></svg>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (empty($blocked_slots)): ?>
                                        <div class="py-12 text-center text-xs text-slate-500 font-semibold select-none">
                                            No unavailable time slots blocked currently.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                </div>

                <!-- VIEW 3: STUDENT QUEUE ENROLLMENT -->
                <div id="view-requests" class="tab-view space-y-10 hidden">

                    <div class="glass-card rounded-[2.5rem] p-8">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6 mb-8 border-b border-white/[0.04] pb-6">
                            <div>
                                <h2 class="text-2xl font-black text-white uppercase italic">Active Student <span class="text-violet-400">Consultation List</span></h2>
                                <p class="text-xs text-slate-500 mt-1">Accept, reject, and priorities queue members</p>
                            </div>
                            <!-- Queue Metrics panel status -->
                            <div class="flex items-center gap-3 font-mono">
                                <span class="text-[10px] text-slate-500 font-extrabold uppercase tracking-widest shrink-0">Priority Stack:</span>
                                <span class="px-3.5 py-1.5 rounded-xl bg-violet-600/10 text-violet-400 border border-violet-500/10 text-xs font-bold"><?php echo count($appointments); ?> Active Stack</span>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b border-white/[0.02]">
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest pl-2">School ID</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Student Information</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Booking Mode</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Consult Issue</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Status ID</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest text-right pr-2">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.02]">
                                    <?php foreach ($appointments as $app): ?>
                                        <tr class="align-middle hover:bg-white/[0.01]">
                                            <td class="py-5 font-mono text-xs font-bold text-slate-400 pl-2">
                                                <?php echo htmlspecialchars($app['school_id']); ?>
                                            </td>
                                            <td class="py-5">
                                                <div class="font-extrabold text-sm text-white"><?php echo htmlspecialchars($app['student_name']); ?></div>
                                            </td>
                                            <td class="py-5 text-xs text-slate-400">
                                                <span class="block font-bold"><?php echo htmlspecialchars($app['appointment_date']) ?: 'Walk-in Line'; ?></span>
                                                <span class="block text-[10px] font-mono text-slate-500 uppercase mt-0.5"><?php echo htmlspecialchars($app['time_slot']); ?></span>
                                            </td>
                                            <td class="py-5 text-xs text-slate-300 max-w-xs truncate">
                                                <?php echo htmlspecialchars($app['reason']); ?>
                                            </td>
                                            <td class="py-5">
                                                <span class="px-2.5 py-1 rounded-full text-[9px] font-mono font-black border uppercase tracking-wider
                                                    <?php 
                                                    if ($app['status'] === 'Accepted') echo 'bg-blue-600/15 text-blue-400 border-blue-500/20';
                                                    elseif ($app['status'] === 'Approved') echo 'bg-indigo-600/15 text-indigo-400 border-indigo-500/20';
                                                    else echo 'bg-amber-600/15 text-amber-450 border-amber-500/20 animate-pulse';
                                                    ?>">
                                                    <?php echo htmlspecialchars($app['status']); ?>
                                                </span>
                                            </td>
                                            <td class="py-5 text-right pr-2">
                                                <div class="flex items-center justify-end gap-2 text-xs">
                                                    <?php if ($app['status'] === 'Pending'): ?>
                                                        <a href="faculty_dashboard.php?action=approve_appointment&app_id=<?php echo $app['app_id']; ?>" 
                                                           class="px-3.5 py-1.5 h-8 bg-emerald-600 hover:bg-emerald-500 text-white hover:scale-105 active:scale-95 rounded-xl transition-all font-bold uppercase text-[9px] tracking-wide flex items-center justify-center">
                                                            Approve
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <a href="faculty_dashboard.php?action=call&app_id=<?php echo $app['app_id']; ?>" 
                                                       class="px-3.5 py-1.5 h-8 bg-violet-600 hover:bg-violet-500 text-white hover:scale-105 active:scale-95 rounded-xl transition-all font-bold uppercase text-[9px] tracking-wide flex items-center justify-center gap-1">
                                                        <polygon points="5 3 19 12 5 21 5 3"/>
                                                        Serve Desk
                                                    </a>

                                                    <button onclick="openCancelModal(<?php echo $app['app_id']; ?>, '<?php echo htmlspecialchars($app['student_name']); ?>')" 
                                                            class="w-8 h-8 rounded-xl bg-rose-500/10 text-rose-500 hover:bg-rose-500 hover:text-white transition-all flex items-center justify-center border border-rose-500/20 cursor-pointer">
                                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($appointments)): ?>
                                        <tr>
                                            <td colspan="6" class="py-14 text-center text-xs text-slate-500 font-semibold select-none">
                                                There are currently no priority student applications queued.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- VIEW 4: METRICS & REPORTS (ARCHIVES) -->
                <div id="view-archives" class="tab-view space-y-10 hidden">

                    <div class="glass-card rounded-[2.5rem] p-8">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-6 mb-8 border-b border-white/[0.04] pb-6">
                            <div>
                                <h2 class="text-2xl font-black text-white uppercase italic">Session History <span class="text-violet-400">& Auditing</span></h2>
                                <p class="text-xs text-slate-500 mt-1">Audit historic consultation logs, times, and cancellations</p>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr class="border-b border-white/[0.02]">
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest pl-2">Sess ID</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Student Info</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Reason Statement</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest">Status State</th>
                                        <th class="py-4 text-[10px] font-mono font-black text-slate-500 uppercase tracking-widest hidden sm:table-cell">Resolution Stamp</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/[0.02]">
                                    <?php foreach ($archives as $arch): ?>
                                        <tr class="align-middle hover:bg-white/[0.01]">
                                            <td class="py-4 font-mono text-xs text-slate-500 pl-2">
                                                #<?php echo $arch['app_id']; ?>
                                            </td>
                                            <td class="py-4">
                                                <div class="font-bold text-sm text-white"><?php echo htmlspecialchars($arch['student_name']); ?></div>
                                                <p class="text-[10px] font-mono text-slate-500 mt-0.5"><?php echo htmlspecialchars($arch['school_id']); ?></p>
                                            </td>
                                            <td class="py-4 text-xs text-slate-350 max-w-sm truncate">
                                                "<?php echo htmlspecialchars($arch['reason']); ?>"
                                            </td>
                                            <td class="py-4">
                                                <span class="px-2.5 py-1 rounded-full text-[9px] font-mono font-black border uppercase tracking-wider
                                                    <?php 
                                                    if ($arch['status'] === 'Completed') echo 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                                                    else echo 'bg-slate-700/30 text-slate-400 border-white/[0.05]';
                                                    ?>">
                                                    <?php echo htmlspecialchars($arch['status']); ?>
                                                </span>
                                            </td>
                                            <td class="py-4 hidden sm:table-cell text-xs font-mono text-slate-500">
                                                <?php echo date('M d, Y h:i A', strtotime($arch['created_at'])); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($archives)): ?>
                                        <tr>
                                            <td colspan="5" class="py-14 text-center text-xs text-slate-500 font-semibold select-none">
                                                There are currently no session logs archived in history.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                </div>

                <!-- VIEW 5: PROFILE SETTINGS -->
                <div id="view-profile" class="tab-view space-y-10 hidden">

                    <div class="glass-card rounded-[2.5rem] p-10">
                        <div class="mb-8 border-b border-white/[0.04] pb-6">
                            <h2 class="text-2xl font-black text-white uppercase italic">Faculty <span class="text-violet-400">Profile Settings</span></h2>
                            <p class="text-xs text-slate-500 mt-1">Review contact channels, narrative logs, and active credentials.</p>
                        </div>

                        <form action="faculty_dashboard.php?action=update_profile" method="POST" class="space-y-8">
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                                
                                <!-- Frame section 1: Contact coordinates -->
                                <div class="space-y-5">
                                    <h4 class="text-[10px] font-mono font-black text-violet-400 tracking-widest uppercase">Communication Channels</h4>
                                    
                                    <div>
                                        <label class="text-[10px] font-bold text-slate-450 uppercase mb-2 block pl-1">Institutional Telephone Line</label>
                                        <input type="text" name="contact_number" value="<?php echo htmlspecialchars($contact_number); ?>" class="input-field" placeholder="+1 (415) 555-0199" required />
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-450 uppercase mb-2 block pl-1">Authorized Departmental Email</label>
                                        <input type="email" name="email" value="<?php echo htmlspecialchars($faculty_email); ?>" class="input-field" placeholder="lead.faculty@institution.edu" required />
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-450 uppercase mb-2 block pl-1">Digital Scholar Link</label>
                                        <input type="text" name="social_link" value="<?php echo htmlspecialchars($social_link); ?>" class="input-field" placeholder="https://scholar.google.com/citations?..." />
                                    </div>
                                </div>

                                <!-- Frame section 2: Profile narratives -->
                                <div class="space-y-5">
                                    <h4 class="text-[10px] font-mono font-black text-violet-400 tracking-widest uppercase">Faculty Biography & Office Schedule</h4>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-450 uppercase mb-2 block pl-1">Academic Specialization / Title</label>
                                        <input type="text" name="specialization" value="<?php echo htmlspecialchars($specialization); ?>" class="input-field" placeholder="Department Chair • Advanced Computer Systems" />
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-455 uppercase mb-2 block pl-1">Regular Office Hours Slots</label>
                                        <input type="text" name="office_hours" value="<?php echo htmlspecialchars($office_hours); ?>" class="input-field" placeholder="Mon/Wed/Fri — 2:00 PM to 5:00 PM PST" />
                                    </div>

                                    <div>
                                        <label class="text-[10px] font-bold text-slate-450 uppercase mb-2 block pl-1">Short Biography Text</label>
                                        <textarea name="biography" class="w-full bg-slate-900/50 border border-white/[0.05] focus:border-violet-500/55 rounded-2xl p-4 text-sm text-white placeholder-slate-550 outline-none transition-all resize-none min-h-[90px]" placeholder="Brief professional statement..."><?php echo htmlspecialchars($biography); ?></textarea>
                                    </div>
                                </div>

                            </div>

                            <button type="submit" class="w-full py-4 rounded-2xl bg-gradient-to-r from-violet-600 to-indigo-600 hover:from-violet-500 hover:to-indigo-500 text-white font-extrabold text-xs uppercase tracking-[0.25em] shadow-xl shadow-violet-600/10 mt-4 transition-all duration-300">
                                Save Profile Configurations
                            </button>

                        </form>
                    </div>

                </div>

            </div>
        </main>
    </div>

    <!-- BUSY DURATION MODAL (AWAY MODE PICKER) -->
    <div id="queueBusyModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6 bg-slate-950/80 backdrop-blur-md">
        <div class="glass-card w-full max-w-sm rounded-[2.5rem] p-8 relative overflow-hidden bg-slate-900">
            <div class="absolute top-0 right-0 w-36 h-36 bg-orange-500/10 blur-3xl -mr-16 -mt-16"></div>
            
            <div class="relative z-10 text-center space-y-6">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-orange-500 shadow-2xl shadow-orange-500/30">
                    <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-white"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </div>
                
                <div>
                    <h2 class="text-2xl font-black italic tracking-tighter uppercase text-white">Set <span class="text-orange-450">Duration</span></h2>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-500 mt-1">Select how long students should see you as busy</p>
                </div>

                <div class="space-y-4">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block text-left pl-1">Specify Busy Duration</label>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" id="btn-unit-mins" onclick="selectDurationUnit('mins')" class="py-3 rounded-xl border font-bold text-xs uppercase tracking-wider bg-orange-500/10 border-orange-500/40 text-orange-400 cursor-pointer">Minutes</button>
                        <button type="button" id="btn-unit-hours" onclick="selectDurationUnit('hours')" class="py-3 rounded-xl border border-white/[0.04] font-bold text-xs uppercase tracking-wider bg-slate-950 text-slate-400 hover:border-orange-500/40 hover:text-white cursor-pointer">Hours</button>
                    </div>

                    <div class="flex items-center justify-between bg-slate-950 border border-white/[0.04] rounded-2xl p-2 select-none">
                        <button type="button" onclick="adjustDuration(-1)" class="w-12 h-12 flex items-center justify-center rounded-xl bg-slate-900 border border-white/[0.04] shadow text-white hover:bg-orange-500 hover:border-orange-500 active:scale-95 transition-all text-xl font-black cursor-pointer">-</button>
                        <input id="custom-queue-duration" type="number" min="1" max="180" value="15" readonly class="bg-transparent text-center text-2xl font-black w-24 outline-none text-white font-mono" onchange="enableButtonGlow()" />
                        <button type="button" onclick="adjustDuration(1)" class="w-12 h-12 flex items-center justify-center rounded-xl bg-slate-900 border border-white/[0.04] shadow text-white hover:bg-orange-500 hover:border-orange-500 active:scale-95 transition-all text-xl font-black cursor-pointer">+</button>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" onclick="closeQueueBusyModal()" class="flex-1 py-3.5 text-[10px] font-black border border-white/[0.05] rounded-xl text-slate-400 hover:text-white hover:bg-white/[0.02] transition-all uppercase tracking-widest cursor-pointer">Cancel</button>
                        <button id="lock-queue-btn" type="button" onclick="confirmQueueBusy()" class="flex-[1.5] py-3.5 rounded-xl bg-orange-500 text-white font-black text-[10px] uppercase shadow-lg shadow-orange-500/10 hover:bg-orange-400 transition-all tracking-wider cursor-pointer">Set Busy</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- EMERGENCY CANCELLATION MODAL -->
    <div id="cancelModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-6 bg-slate-950/80 backdrop-blur-md">
        <div class="glass-card w-full max-w-sm p-8 rounded-[2.5rem] border-rose-500/20 bg-slate-900">
            <div class="flex items-center gap-4 mb-6">
                <div class="w-12 h-12 rounded-xl bg-rose-500/10 flex items-center justify-center text-rose-400 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <div>
                    <h3 class="text-xl font-black italic uppercase tracking-tight text-white mb-0.5">Force <span class="text-rose-500">Revocation</span></h3>
                    <p class="text-[9px] text-slate-500 font-mono font-bold uppercase tracking-wider">Target: <span id="modalStudentName" class="text-violet-400"></span></p>
                </div>
            </div>

            <form action="faculty_dashboard.php?action=decline_appointment" method="POST" class="space-y-6">
                <input type="hidden" name="app_id" id="modalAppId">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 block pl-1">Institutional Justification</label>
                    <textarea name="decline_reason" required placeholder="Provide an official reason for this cancellation..." class="w-full bg-slate-950 border border-white/[0.04] focus:border-rose-500/50 rounded-2xl p-4 text-xs text-white outline-none min-h-[100px] resize-none transition-all" maxLength="255"></textarea>
                </div>

                <div class="flex gap-3">
                    <button type="button" onclick="closeCancelModal()" class="flex-1 py-3.5 rounded-xl bg-white/[0.02] border border-white/[0.04] text-slate-400 hover:text-white text-[10px] font-bold uppercase tracking-wider cursor-pointer">Abort</button>
                    <button type="submit" class="flex-[1.5] py-3.5 rounded-xl bg-rose-600 text-white font-bold shadow-xl shadow-rose-600/15 hover:bg-rose-500 transition-all text-[10px] uppercase tracking-wider cursor-pointer">Cancel Booking</button>
                </div>
            </form>
        </div>
    </div>

    <!-- CORE JAVASCRIPT LOGIC STATE SYNC ENGINE -->
    <script>
        // Synchronized operational states
        let current_status = <?php echo json_encode($current_status); ?>;
        let remaining_seconds = <?php echo (int)($remaining_seconds ?? 0); ?>;
        let timerInterval = null;
        let selected_unit = 'mins';

        // Collapsible Sidebar management
        let sidebarCollapsed = localStorage.getItem('sidebar_collapsed') === 'true';
        function applySidebarState() {
            const sidebar = document.getElementById('sidebar');
            const toggleIcon = document.getElementById('sidebar-toggle-icon');
            if (sidebarCollapsed) {
                sidebar.classList.add('w-[84px]');
                sidebar.classList.remove('w-72');
                document.querySelectorAll('.sidebar-text').forEach(el => el.classList.add('hidden'));
                if (toggleIcon) toggleIcon.classList.add('rotate-180');
            } else {
                sidebar.classList.remove('w-[84px]');
                sidebar.classList.add('w-72');
                document.querySelectorAll('.sidebar-text').forEach(el => el.classList.remove('hidden'));
                if (toggleIcon) toggleIcon.classList.remove('rotate-180');
            }
        }
        function toggleSidebarCollapse() {
            sidebarCollapsed = !sidebarCollapsed;
            localStorage.setItem('sidebar_collapsed', sidebarCollapsed);
            applySidebarState();
        }

        // Tab switches management
        function showTab(tabName) {
            // Unify names to catch 'requests' vs 'students', 'reports' vs 'archives', etc.
            let targetTab = tabName;
            if (tabName === 'reports') targetTab = 'archives';
            if (tabName === 'students') targetTab = 'requests';
            if (tabName === 'profile') targetTab = 'profile';

            // Hide all tab containers
            document.querySelectorAll('.tab-view').forEach(view => view.classList.add('hidden'));
            
            // Map actual target div IDs
            let mappedTabName = targetTab;
            if (targetTab === 'profile') mappedTabName = 'profile';
            else if (targetTab === 'archives') mappedTabName = 'archives';
            
            const targetView = document.getElementById('view-' + mappedTabName);
            if (targetView) targetView.classList.remove('hidden');

            // Deactivate sidebar tab buttons
            document.querySelectorAll('.sidebar-btn').forEach(btn => btn.classList.remove('active'));
            const activeSidebarTab = document.getElementById('tab-' + targetTab);
            if (activeSidebarTab) activeSidebarTab.classList.add('active');

            // Mobile view navigation indicator switches
            document.querySelectorAll('[id^="mobile-tab-"]').forEach(btn => {
                btn.classList.remove('text-violet-500');
                btn.classList.add('text-slate-400');
            });
            const activeMobileTab = document.getElementById('mobile-tab-' + targetTab);
            if (activeMobileTab) {
                activeMobileTab.classList.remove('text-slate-400');
                activeMobileTab.classList.add('text-violet-500');
            }

            localStorage.setItem('faculty_active_tab', targetTab);
        }

        // Preset active tab load
        const savedTab = localStorage.getItem('faculty_active_tab') || 'dashboard';
        showTab(savedTab);

        // Queue lock settings logic
        function selectDurationUnit(unit) {
            selected_unit = unit;
            const btnMins = document.getElementById('btn-unit-mins');
            const btnHours = document.getElementById('btn-unit-hours');
            const input = document.getElementById('custom-queue-duration');
            
            if (unit === 'mins') {
                btnMins.className = 'py-3 rounded-xl border font-bold text-xs uppercase tracking-wider bg-orange-500/10 border-orange-500/40 text-orange-400 cursor-pointer';
                btnHours.className = 'py-3 rounded-xl border border-white/[0.04] font-bold text-xs uppercase tracking-wider bg-slate-950 text-slate-400 hover:border-orange-500/40 hover:text-white cursor-pointer';
                input.value = 15;
            } else {
                btnHours.className = 'py-3 rounded-xl border font-bold text-xs uppercase tracking-wider bg-orange-500/10 border-orange-500/40 text-orange-400 cursor-pointer';
                btnMins.className = 'py-3 rounded-xl border border-white/[0.04] font-bold text-xs uppercase tracking-wider bg-slate-950 text-slate-400 hover:border-orange-500/40 hover:text-white cursor-pointer';
                input.value = 1;
            }
        }

        function adjustDuration(amount) {
            const input = document.getElementById('custom-queue-duration');
            let val = parseInt(input.value) || 1;
            if (selected_unit === 'mins') {
                val += amount * 5;
                if (val < 5) val = 5;
                if (val > 180) val = 180;
            } else {
                val += amount;
                if (val < 1) val = 1;
                if (val > 24) val = 24;
            }
            input.value = val;
            enableButtonGlow();
        }

        function enableButtonGlow() {
            const lockBtn = document.getElementById('lock-queue-btn');
            if (lockBtn) {
                lockBtn.classList.add('shadow-[0_0_20px_rgba(249,115,22,0.6)]', 'animate-pulse');
            }
        }

        function updateStatusCards(newStatus) {
            const stateAvailable = document.getElementById('state-available');
            const stateBusy = document.getElementById('state-busy');
            const stateLeave = document.getElementById('state-leave');

            // Ensure default glow behavior is sticky by removing animation classes that hide the effect.
            // We also explicitly keep orange/red styling until the instructor changes status again.


            // Reset all cards
            [stateAvailable, stateBusy, stateLeave].forEach(card => {
                if (!card) return;
                card.classList.remove('bg-emerald-500/[0.04]', 'border-emerald-500', 'shadow-[0_0_30px_rgba(16,185,129,0.2)]', 'ring-1', 'ring-emerald-500/30');
                card.classList.remove('bg-orange-500/[0.04]', 'border-orange-500', 'shadow-[0_0_30px_rgba(249,115,22,0.25)]', 'ring-1', 'ring-orange-500/30');
                card.classList.remove('bg-rose-500/[0.04]', 'border-rose-500', 'shadow-[0_0_30px_rgba(239,68,68,0.2)]', 'ring-1', 'ring-rose-500/30');
                card.classList.remove('scale-[1.01]');
                card.classList.add('bg-white/[0.01]', 'border-white/[0.04]', 'opacity-70');
                
                const badge = card.querySelector('.status-badge');
                if (badge) badge.classList.add('hidden');
            });

            // Apply active styling to the new status
            if (newStatus === 'Available' && stateAvailable) {
                stateAvailable.classList.remove('bg-white/[0.01]', 'border-white/[0.04]', 'opacity-70');
                stateAvailable.classList.add('bg-emerald-500/[0.04]', 'border-emerald-500', 'shadow-[0_0_30px_rgba(16,185,129,0.2)]', 'ring-1', 'ring-emerald-500/30', 'scale-[1.01]');
                const badge = stateAvailable.querySelector('.status-badge');
                if (badge) badge.classList.remove('hidden');
                const icon = stateAvailable.querySelector('svg');
                if (icon) icon.classList.add('animate-pulse');
            } else if (newStatus === 'Busy' && stateBusy) {
                stateBusy.classList.remove('bg-white/[0.01]', 'border-white/[0.04]', 'opacity-70');
                stateBusy.classList.add('bg-orange-500/[0.04]', 'border-orange-500', 'shadow-[0_0_30px_rgba(249,115,22,0.25)]', 'ring-1', 'ring-orange-500/30', 'scale-[1.01]');
                const badge = stateBusy.querySelector('.status-badge');
                if (badge) badge.classList.remove('hidden');
                const icon = stateBusy.querySelector('svg');
                if (icon) icon.classList.add('animate-spin');
            } else if (newStatus === 'On Leave' && stateLeave) {
                stateLeave.classList.remove('bg-white/[0.01]', 'border-white/[0.04]', 'opacity-70');
                stateLeave.classList.add('bg-rose-500/[0.04]', 'border-rose-500', 'shadow-[0_0_30px_rgba(239,68,68,0.2)]', 'ring-1', 'ring-rose-500/30', 'scale-[1.01]');
                const badge = stateLeave.querySelector('.status-badge');
                if (badge) badge.classList.remove('hidden');

                // Keep a subtle sticky glow by using animate-bounce on the inner svg.
                // Remove any previous busy spin first to avoid conflicting animations.
                const svgs = stateLeave.querySelectorAll('svg');
                svgs.forEach(svg => {
                    svg.classList.remove('animate-spin');
                    svg.classList.remove('animate-pulse');
                    svg.classList.remove('animate-bounce');
                    svg.classList.add('animate-bounce');
                });
            }

        }

        function handleQueueStatus(val) {
            const valLower = val.toLowerCase();
            const currentLower = current_status.toLowerCase();
            if (valLower === 'busy') {
                if (currentLower === 'busy' && remaining_seconds > 0) {
                    Swal.fire({
                        title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Confirm Action</div>',
                        text: 'Your consultation countdown is active. Retract current session lockdown to return to general Available status?',
                        icon: 'warning',
                        background: '#0d121f',
                        color: '#f1f5f9',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, Open Queue',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#10b981',
                        cancelButtonColor: '#e11d48',
                        customClass: {
                            popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-7 max-w-md',
                            confirmButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white',
                            cancelButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider cursor-pointer text-white'
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            setStatus('Available');
                        }
                    });
                } else {
                    document.getElementById('queueBusyModal').classList.remove('hidden');
                }
            } else {
                setStatus(val);
            }
        }

        function closeQueueBusyModal() {
            document.getElementById('queueBusyModal').classList.add('hidden');
        }

        function confirmQueueBusy() {
            const input = document.getElementById('custom-queue-duration');
            const val = parseInt(input.value) || 1;

            // update_status.php expects duration_hours (float). If unit is minutes, convert to hours.
            const hoursVal = selected_unit === 'mins' ? (val / 60) : val;

            // Set local timer seconds immediately so UI glow + countdown start without waiting refresh.
            remaining_seconds = selected_unit === 'mins' ? (val * 60) : Math.max(60, Math.round(val * 3600));

            // Glow + notify students
            setStatus('Busy', hoursVal, 'Busy: lessons/meetings in progress. Booking resumes after timer ends.');
        }



        // Fetch API asynchronous operation update
        async function setStatus(val, h = null, statusMsg = null) {
            const fd = new FormData();
            fd.append('status_val', val);
            if (h !== null) fd.append('duration_hours', h);
            if (statusMsg !== null) fd.append('status_message', statusMsg);

            try {
                const res = await fetch('update_status.php', {
                    method: 'POST',
                    body: fd,
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
                const data = await res.json();

                if (data.success) {
                    // IMPORTANT: keep UI pill + cards synchronized with the real status.
                    current_status = val;
                    window.current_status = val;
                    updateStatusCards(val);

                    // Also update header pill color (your issue: it stayed green)
                    const headerPill = document.getElementById('header-status-pill');
                    if (headerPill) {
                        headerPill.classList.remove('bg-emerald-500/10','border-emerald-500/20','text-emerald-400','shadow-[0_0_15px_rgba(16,185,129,0.15)]','bg-orange-500/10','border-orange-500/20','text-orange-400','bg-rose-500/10','border-rose-500/20','text-rose-400','shadow-[0_0_15px_rgba(239,68,68,0.15)]','animate-pulse','border-rose-500/20','border-orange-500/20','border-emerald-500/20');
                        const normalize = (val || '').toLowerCase();
                        if (normalize === 'available') {
                            headerPill.classList.add('bg-emerald-500/10','border-emerald-500/20','text-emerald-400','shadow-[0_0_15px_rgba(16,185,129,0.15)]','animate-pulse');
                        } else if (normalize === 'busy') {
                            headerPill.classList.add('bg-orange-500/10','border-orange-500/20','text-orange-400','shadow-[0_0_15px_rgba(249,115,22,0.15)]','animate-pulse');
                        } else {
                            headerPill.classList.add('bg-rose-500/10','border-rose-500/20','text-rose-400','shadow-[0_0_15px_rgba(239,68,68,0.15)]','animate-pulse');
                        }
                    }

                    // Ensure busy modal closes after setting busy
                    const queueModal = document.getElementById('queueBusyModal');
                    if (queueModal && !queueModal.classList.contains('hidden')) {
                        queueModal.classList.add('hidden');
                    }

                    Swal.fire({
                        title: 'Success',
                        text: 'Operational status updated successfully.',
                        icon: 'success',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500,
                        background: '#090d16',
                        color: '#f1f5f9'
                    });
                } else {
                    Swal.fire({
                        title: 'Sync Error',
                        text: data.message,
                        icon: 'error',
                        confirmButtonColor: '#4f46e5',
                        background: '#0d121f',
                        color: '#f1f5f9'
                    });
                }
            } catch (e) {
                Swal.fire({
                    title: 'Transmission Failed',
                    text: 'Unable to communicate with status gateway securely.',
                    icon: 'error',
                    confirmButtonColor: '#4f46e5',
                    background: '#0d121f',
                    color: '#f1f5f9'
                });
            }
        }



        // Edit Custom Status message from Alert popup
        function triggerCustomMsgEdit() {
            const currentMsg = <?php echo json_encode($status_message); ?>;
            Swal.fire({
                title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Set Custom Board Message</div>',
                html: '<input id="swal-custom-msg-input" type="text" class="w-full px-4 py-3 rounded-2xl outline-none border border-white/[0.08] bg-slate-950 text-white placeholder-slate-500 focus:border-violet-500/50" value="' + currentMsg.replace(/"/g, '&quot;') + '" placeholder="Type customized status broadcast here..." maxLength="100" />',
                showCancelButton: true,
                confirmButtonText: 'Set Message',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#6366f1',
                cancelButtonColor: '#e11d48',
                background: '#090d16',
                color: '#f1f5f9',
                customClass: {
                    popup: 'rounded-[2rem] border border-white/[0.08] shadow-2xl p-8 max-w-sm',
                    confirmButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider text-white cursor-pointer',
                    cancelButton: 'px-5 py-3 rounded-xl font-bold text-xs uppercase tracking-wider text-white cursor-pointer'
                },
                preConfirm: () => {
                    const el = document.getElementById('swal-custom-msg-input');
                    return el ? el.value.trim() : '';
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    executeCustomMsgSave(result.value);
                }
            });
        }

        async function executeCustomMsgSave(msg) {
            const fd = new FormData();
            fd.append('status_message', msg);
            try {
                const res = await fetch('update_status.php', { 
                    method: 'POST', 
                    body: fd, 
                    headers: { 'X-Requested-With': 'XMLHttpRequest' } 
                });
                const data = await res.json();
                if (data.success) {
                    Swal.fire({
                        title: 'Message Published',
                        text: 'Your board statement is live on student interfaces.',
                        icon: 'success',
                        toast: true,
                        position: 'top-end',
                        showConfirmButton: false,
                        timer: 1500,
                        background: '#090d16',
                        color: '#f1f5f9'
                    });

                } else {
                    Swal.fire({
                        title: 'Error',
                        text: data.message,
                        icon: 'error',
                        confirmButtonColor: '#4f46e5'
                    });
                }
            } catch(e) { 
                Swal.fire({
                    title: 'System Transmission Failure',
                    text: 'Unable to synchronize custom messages with the server.',
                    icon: 'error',
                    confirmButtonColor: '#4f46e5'
                });
            }
        }

        // Live UTC clock rendering
        function updateUTCClock() {
            const clockEl = document.getElementById('nav-utc-clock');
            if (clockEl) {
                const now = new Date();
                clockEl.textContent = now.toLocaleTimeString('en-US', { hour12: true, timeZone: 'UTC' }) + ' UTC';
            }
        }

                                    // Busy countdown live update
        function formatTimeRemaining(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        function runQueueTimer() {
            // Keep Busy glow sticky until server state becomes Available.
            if (current_status.toLowerCase() !== 'busy') return;

            // If busy_until already expired, normalize immediately.
            if (remaining_seconds <= 0) {
                setStatus('Available');
                return;
            }

            const titleEl = document.getElementById('card-busy-title');
            const descEl = document.getElementById('card-busy-desc');
            const busyCardEl = document.getElementById('state-busy');

            // Force orange styling immediately (prevents flash then revert)
            if (busyCardEl) {
                busyCardEl.classList.remove('bg-white/[0.01]', 'border-white/[0.04]', 'opacity-70');
                busyCardEl.classList.add(
                    'bg-orange-500/[0.04]',
                    'border-orange-500',
                    'shadow-[0_0_30px_rgba(249,115,22,0.25)]',
                    'ring-1',
                    'ring-orange-500/30',
                    'scale-[1.01]'
                );

                // Ensure busy badge is visible
                const badge = busyCardEl.querySelector('.status-badge');
                if (badge) badge.classList.remove('hidden');
            }

            // Clear any existing interval before starting a new one.
            if (timerInterval) clearInterval(timerInterval);

            function updateTimerView() {
                if (remaining_seconds <= 0) {
                    clearInterval(timerInterval);
                    remaining_seconds = 0;
                    if (titleEl) titleEl.textContent = 'Busy';
                    if (descEl) descEl.innerHTML = 'Busy timer ended. Switching to Available...';
                    setStatus('Available');
                    return;
                }

                const timeStr = formatTimeRemaining(remaining_seconds);
                if (titleEl) titleEl.textContent = `Busy (${timeStr})`;
                if (descEl) {
                    descEl.innerHTML = `Students will see this busy timer. <span class="text-orange-400 font-bold font-mono">${timeStr} left</span> until Available mode.`;
                }

                // Keep the glow during countdown
                if (busyCardEl) {
                    busyCardEl.classList.add('animate-pulse');
                }

                remaining_seconds--;
            }

            updateTimerView();
            timerInterval = setInterval(updateTimerView, 1000);
        }


        // Form modals cancellation
        function openCancelModal(appId, studentName) {
            document.getElementById('modalAppId').value = appId;
            document.getElementById('modalStudentName').textContent = studentName;
            document.getElementById('cancelModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeCancelModal() {
            document.getElementById('cancelModal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        }

        // Initializer triggerss
        window.addEventListener('DOMContentLoaded', () => {
            applySidebarState();
            if (current_status.toLowerCase() === 'busy') {
                runQueueTimer();
            }
            updateUTCClock();
            setInterval(updateUTCClock, 1000);
        });

        // Click outside modals to close them
        const queueModal = document.getElementById('queueBusyModal');
        if (queueModal) {
            queueModal.addEventListener('click', (e) => {
                if (e.target === queueModal) closeQueueBusyModal();
            });
        }

        function handleLogout(event) {
            event.preventDefault();
            Swal.fire({
                title: '<div class="text-left font-black tracking-tight text-xl text-white uppercase italic">Confirm Logout</div>',
                text: 'Are you sure you want to end your advisor session?',
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
