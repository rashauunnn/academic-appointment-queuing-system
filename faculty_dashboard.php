<?php
require_once 'security_headers.php';
require_once 'session_helper.php';
require_once 'db_connect.php';

// Role Guard (Anti-URL Bypass)
check_session_role('Faculty');

// Sync IDs for legacy code
$faculty_id = $_SESSION['faculty_id'] ?? $_SESSION['user_id'];
$faculty_name = $_SESSION['faculty_name'] ?? $_SESSION['full_name'];

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
    $pdo->exec("ALTER TABLE appointments ADD COLUMN IF NOT EXISTS cancel_reason TEXT");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS contact_number VARCHAR(50) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS email VARCHAR(100) DEFAULT NULL");
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS social_link VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {}


// ==========================================
// ULTIMATE FIX: ASYNC STATUS HANDLER 
// Captures both FormData and JSON POST requests
// ==========================================
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, TRUE);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $is_status_update = false;
    $new_status_raw = null;
    $hours = 0;

    // 1. Check if sent via FormData (traditional AJAX/POST)
    if (isset($_POST['status'])) {
        $new_status_raw = $_POST['status'];
        $hours = isset($_POST['hours']) ? (int)$_POST['hours'] : 0;
        $is_status_update = true;
    } 
    // 2. Check if sent via JSON (Fetch API JSON.stringify)
    elseif (isset($input['status'])) {
        $new_status_raw = $input['status'];
        $hours = isset($input['hours']) ? (int)$input['hours'] : 0;
        $is_status_update = true;
    }
    // 3. Fallback check for URL parameter
    elseif (isset($_GET['action']) && $_GET['action'] === 'update_status') {
        $is_status_update = true;
    }

    // Execute logic if it's confirmed as a status update API call
    if ($is_status_update) {
        header('Content-Type: application/json');
        
        if (empty($new_status_raw)) {
            echo json_encode(['success' => false, 'message' => 'Missing status value.']);
            exit();
        }

        $normalized = 'Available';
        $busy_time = null;
        $status_lower = strtolower($new_status_raw);

        if ($status_lower === 'busy') {
            $normalized = 'Busy';
            if ($hours > 0) {
                $busy_time = date('Y-m-d H:i:s', time() + ($hours * 3600));
            }
        } elseif ($status_lower === 'on_leave' || $status_lower === 'on leave') {
            $normalized = 'On Leave';
        }

        try {
            $stmt = $pdo->prepare("UPDATE users SET current_status = ?, busy_until = ? WHERE user_id = ?");
            $stmt->execute([$normalized, $busy_time, $faculty_id]);
            echo json_encode(['success' => true]);
            exit(); 
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
            exit();
        }
    }
}
// ==========================================


// Handle actions (call, complete, noshow, call_next, update_profile, unavailability, etc.)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $app_id = $_GET['app_id'] ?? null;
    $avail_id = $_GET['avail_id'] ?? null;

    try {
        switch ($action) {
            case 'update_profile':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $c_num = $_POST['contact_number'] ?? '';
                    $f_email = $_POST['email'] ?? '';
                    $s_link = $_POST['social_link'] ?? '';
                    
                    $stmt = $pdo->prepare("UPDATE users SET contact_number = ?, email = ?, social_link = ? WHERE user_id = ?");
                    $stmt->execute([$c_num, $f_email, $s_link, $faculty_id]);
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

            case 'call_next':
                $next_stmt = $pdo->prepare("
                    SELECT app_id FROM appointments 
                    WHERE faculty_id = ? AND status IN ('Pending', 'Approved', 'Accepted') 
                    ORDER BY created_at ASC LIMIT 1
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
        
        // Prevent redirect loop if it was just an async/API update that fell through
        if ($_GET['action'] !== 'update_status') {
            header("Location: faculty_dashboard.php?msg=success");
            exit();
        }
    } catch (PDOException $e) {
        $error = "System modification failed: " . $e->getMessage();
    }
}

// Fetch Appointments & General Dashboard Layout Properties
try {
    // Fetch faculty state
    $faculty_info = $pdo->prepare("SELECT current_status, contact_number, email AS faculty_email, social_link, busy_until FROM users WHERE user_id = ?");
    $faculty_info->execute([$faculty_id]);
    $f_data = $faculty_info->fetch();

    $current_status = $f_data['current_status'] ?? 'Available';
    $busy_until = $f_data['busy_until'] ?? null;
    $remaining_seconds = 0;
    
    if ($current_status === 'Busy' && $busy_until) {
        $remaining_seconds = strtotime($busy_until) - time();
    }
    
    $contact_number = $f_data['contact_number'] ?? '';
    $faculty_email = $f_data['faculty_email'] ?? '';
    $social_link = $f_data['social_link'] ?? '';

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

    // Queue Load String
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

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard | Academic Appointment System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: { slate: { 900: '#0f172a', 950: '#020617' } }
                }
            }
        }

        if (localStorage.getItem('theme') === 'dark' || (!('theme' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
            document.documentElement.classList.add('dark');
        } else {
            document.documentElement.classList.remove('dark');
        }

        function toggleTheme() {
            if (document.documentElement.classList.contains('dark')) {
                document.documentElement.classList.remove('dark');
                localStorage.setItem('theme', 'light');
            } else {
                document.documentElement.classList.add('dark');
                localStorage.setItem('theme', 'dark');
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer base {
            body { 
                @apply bg-slate-50 text-slate-900 transition-colors duration-500 overflow-x-hidden; 
                --accent: #4f46e5;
            }
            .dark body { 
                @apply bg-[#06080f] text-slate-100; 
                --accent: #6366f1;
            }
        }
        @layer components {
            .glass-card { 
                @apply bg-white/80 backdrop-blur-2xl border border-slate-200/60 shadow-[0_8px_30px_rgb(0,0,0,0.04)] transition-all duration-300; 
            }
            .dark .glass-card { 
                @apply bg-[#0d121f]/80 border-white/5 shadow-none; 
            }
            .nav-tab {
                @apply px-6 py-3 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] transition-all flex items-center gap-3 border border-transparent;
            }
            .nav-tab.active {
                @apply bg-violet-600 text-white shadow-lg shadow-violet-600/20 border-violet-500;
            }
            .nav-tab:not(.active) {
                @apply text-slate-500 hover:bg-slate-200/50 dark:hover:bg-white/5;
            }
            .stat-badge {
                @apply px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest border;
            }
            .input-field { 
                @apply bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 transition-all duration-300 rounded-xl block w-full pl-4 py-3 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600; 
            }
            .action-btn {
                @apply h-11 px-6 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all flex items-center gap-2 shadow-lg;
            }
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen">
    <!-- Navbar -->
    <nav class="sticky top-0 z-[60] px-8 py-4 glass-card rounded-none border-x-0 border-t-0 shadow-xl flex items-center justify-between">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-violet-600 flex items-center justify-center text-white shadow-lg shadow-violet-600/20">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z"/><path d="m15 13-3-3-3 3M12 10v9"/></svg>
                </div>
                <div class="hidden md:block">
                    <h1 class="text-sm font-black uppercase tracking-[0.3em]">ConsultCare</h1>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Faculty Command Console</p>
                </div>
            </div>

            <!-- Dashboard Navigation Tabs -->
            <div class="hidden lg:flex items-center bg-slate-100 dark:bg-white/5 p-1 rounded-[1.25rem] border border-slate-200 dark:border-white/5">
                <button onclick="showTab('dashboard')" id="tab-dashboard" class="nav-tab active">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    Dashboard
                </button>
                <button onclick="showTab('requests')" id="tab-requests" class="nav-tab">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Requests
                </button>
                <button onclick="showTab('schedule')" id="tab-schedule" class="nav-tab">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 2v4"/><path d="M16 2v4"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 14h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/><path d="M8 18h.01"/></svg>
                    Schedule
                </button>
                <button onclick="showTab('archives')" id="tab-archives" class="nav-tab">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                    Archives
                </button>
                <button onclick="showTab('profile')" id="tab-profile" class="nav-tab">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Profile
                </button>
            </div>
        </div>

        <div class="flex items-center gap-5">
            <!-- Global Status Indicator -->
            <div class="hidden sm:flex items-center gap-3 px-4 py-2 rounded-xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/5">
                <div id="nav-status-dot" class="w-2 h-2 rounded-full <?php 
                    echo $current_status === 'Available' ? 'bg-green-500 shadow-[0_0_10px_rgba(34,197,94,0.5)]' : 
                         ($current_status === 'Busy' ? 'bg-amber-500 shadow-[0_0_10px_rgba(245,158,11,0.5)]' : 'bg-red-500 shadow-[0_0_10px_rgba(239,68,68,0.5)]'); 
                ?>"></div>
                <span id="nav-status-text" class="text-[10px] font-black uppercase tracking-widest text-slate-500"><?php echo htmlspecialchars($current_status); ?></span>
            </div>

            <button onclick="toggleTheme()" class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-500 hover:text-indigo-500 transition-colors flex items-center justify-center border border-slate-200 dark:border-white/5">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            </button>
            
            <div class="flex items-center gap-4 pl-5 border-l border-slate-200 dark:border-white/10">
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-black text-violet-500 uppercase tracking-widest text-right">Faculty Lead</p>
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($faculty_name); ?></p>
                </div>
                <a href="logout.php" class="w-10 h-10 rounded-xl bg-red-500/10 text-red-500 flex items-center justify-center border border-red-500/20 hover:bg-red-500 hover:text-white transition-all shadow-lg shadow-red-500/5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Viewport -->
    <main class="max-w-7xl mx-auto px-8 py-10">
        <!-- Tailwind CSS Compile Purge Whitelist for Dynamic Status Terminal Colors -->
        <div class="hidden select-none pointer-events-none bg-green-500 border-green-500 bg-orange-500 border-orange-500 bg-red-500 border-red-500 shadow-[0_0_20px_rgba(34,197,94,0.4)] shadow-[0_0_20px_rgba(249,115,22,0.4)] shadow-[0_0_20px_rgba(239,68,68,0.4)] hover:border-orange-500/30 hover:border-red-500/30 text-white text-green-500 text-orange-500 text-red-500 italic"></div>

        <!-- Tab: Dashboard -->
        <div id="view-dashboard" class="tab-view space-y-10">
            <!-- Hero: Active Student -->
            <div class="relative overflow-hidden glass-card rounded-[3rem] p-1 shadow-2xl">
                <div class="absolute inset-0 bg-gradient-to-br from-violet-600/10 via-indigo-600/5 to-transparent animated-gradient"></div>
                <div class="relative bg-white dark:bg-[#0d121f] rounded-[2.9rem] p-10 md:p-14 overflow-hidden">
                    <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-violet-500/10 blur-[130px] -mr-40 -mt-40"></div>
                    
                    <div class="relative z-10 flex flex-col lg:flex-row justify-between items-center gap-12">
                        <div class="flex-1">
                            <span class="px-4 py-1.5 rounded-full bg-violet-500/10 text-violet-500 text-[10px] font-black uppercase tracking-[0.3em] border border-violet-500/20 mb-6 inline-block">Session Controller</span>
                            
                            <?php if ($active_app): ?>
                                <h2 class="text-5xl font-black tracking-tighter mb-4 text-slate-800 dark:text-white uppercase italic">
                                    Serving: <span class="text-violet-500"><?php echo htmlspecialchars($active_app['student_name']); ?></span>
                                </h2>
                                <p class="text-lg text-slate-500 font-medium mb-10 max-w-xl">
                                    Student ID <span class="text-slate-800 dark:text-slate-100 font-bold"><?php echo htmlspecialchars($active_app['school_id']); ?></span> is currently in the session room. 
                                    Update status below upon completion.
                                </p>
                            <?php else: ?>
                                <h2 class="text-5xl font-black tracking-tighter mb-4 text-slate-800 dark:text-white uppercase italic">
                                    Queue <span class="text-slate-400">Idle</span>
                                </h2>
                                <p class="text-lg text-slate-500 font-medium mb-10 max-w-xl">
                                    No active sessions detected. Call the next student in line to begin or wait for a priority request.
                                </p>
                            <?php endif; ?>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-6">
                                <div class="glass-card p-6 rounded-3xl border-violet-500/20 bg-violet-500/[0.03]">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Queue Load</p>
                                    <p class="text-3xl font-black text-violet-500 italic"><?php echo $queue_load_str; ?></p>
                                    <p class="text-[10px] font-bold text-slate-500 mt-1 uppercase italic"><?php echo count($appointments); ?> remaining</p>
                                </div>
                                <div class="glass-card p-6 rounded-3xl">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Next in Line</p>
                                    <p class="text-3xl font-black text-slate-800 dark:text-white italic truncate max-w-[150px]">
                                        <?php echo $next_in_line ? htmlspecialchars($next_in_line['student_name']) : '---'; ?>
                                    </p>
                                    <p class="text-[10px] font-bold text-slate-500 mt-1 uppercase italic">Oldest Pending</p>
                                </div>
                                <div class="glass-card p-6 rounded-3xl">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Avg Session</p>
                                    <p class="text-3xl font-black text-slate-800 dark:text-white italic">14.2m</p>
                                    <p class="text-[10px] font-bold text-slate-500 mt-1 uppercase italic">Rolling Average</p>
                                </div>
                                <div class="glass-card p-6 rounded-3xl bg-violet-600 text-white shadow-xl shadow-violet-600/20 flex flex-col justify-center items-center text-center cursor-pointer hover:scale-105 transition-all" onclick="window.location.href='faculty_dashboard.php?action=call_next'">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="mb-2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                                    <p class="text-[10px] font-black uppercase tracking-widest">Call Next</p>
                                </div>
                            </div>
                        </div>

                        <?php if ($active_app): ?>
                            <div class="w-full lg:w-96 glass-card p-8 rounded-[2.5rem] border-white/10 shadow-2xl relative group">
                                <div class="absolute inset-0 bg-violet-500/5 blur-3xl rounded-full scale-50 group-hover:scale-100 transition-transform duration-700"></div>
                                <div class="relative z-10">
                                    <div class="flex items-center gap-4 mb-8">
                                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center text-2xl font-black text-white italic shadow-lg shadow-violet-600/30">
                                            <?php echo strtoupper(substr($active_app['student_name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-violet-500 uppercase tracking-[0.2em] mb-1">Active Participant</p>
                                            <h3 class="text-xl font-bold truncate"><?php echo htmlspecialchars($active_app['student_name']); ?></h3>
                                        </div>
                                    </div>
                                    
                                    <div class="space-y-6 mb-10">
                                        <div>
                                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Subject Matter</p>
                                            <p class="text-sm font-medium text-slate-400 italic bg-slate-950/20 p-4 rounded-2xl border border-white/5">
                                                "<?php echo htmlspecialchars($active_app['reason']); ?>"
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex flex-col gap-3">
                                        <a href="faculty_dashboard.php?action=complete&app_id=<?php echo $active_app['app_id']; ?>" class="action-btn bg-violet-600 text-white shadow-violet-600/20 hover:bg-violet-500 w-full justify-center">
                                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                            Finalize Session
                                        </a>
                                        <a href="faculty_dashboard.php?action=noshow&app_id=<?php echo $active_app['app_id']; ?>" class="action-btn bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-slate-400 border border-slate-200 dark:border-white/10 hover:bg-slate-200 w-full justify-center">
                                            Report No-Show
                                        </a>
                                        <button onclick="openCancelModal(<?php echo $active_app['app_id']; ?>, '<?php echo htmlspecialchars($active_app['student_name']); ?>')" 
                                                class="action-btn bg-red-600/10 text-red-500 border border-red-500/20 hover:bg-red-600 hover:text-white w-full justify-center">
                                            Emergency Drop
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="w-full lg:w-96 glass-card p-12 rounded-[2.5rem] border-white/5 flex flex-col items-center justify-center text-center opacity-60">
                                <div class="w-20 h-20 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center text-slate-500 mb-6">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                </div>
                                <h3 class="text-lg font-bold mb-2">No Active Engagement</h3>
                                <p class="text-xs font-medium text-slate-500 uppercase tracking-widest">Waiting for session start...</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="lg:col-span-1 glass-card p-8 rounded-[2rem] space-y-8">
            <div>
                <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em] mb-6">Quick Status Terminal</h3>
                <div class="flex flex-col gap-3">
                    <?php 
                    // 1. SAFE NORMALIZATION: trim spaces, lowercase, replace space with underscore
                    $status_norm = str_replace(' ', '_', strtolower(trim($current_status)));
                    
                    // 2. STANDARD TAILWIND CLASSES (safer for JIT compiler than arbitrary brackets)
                    $statuses = [
                        'Available' => [
                            'active_cls' => 'bg-green-600 border-green-600 text-white shadow-lg shadow-green-600/40 scale-[1.02] opacity-100',
                            'icon' => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
                            'key' => 'available'
                        ],
                        'Busy' => [
                            'active_cls' => 'bg-orange-600 border-orange-600 text-white shadow-lg shadow-orange-600/40 scale-[1.02] opacity-100',
                            'icon' => '<path d="M10 2h4"/><path d="M12 14v6"/><path d="M7 18h10"/><circle cx="12" cy="10" r="8"/>',
                            'key' => 'busy'
                        ],
                        'On Leave' => [
                            'active_cls' => 'bg-red-600 border-red-600 text-white shadow-lg shadow-red-600/40 scale-[1.02] opacity-100',
                            'icon' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
                            'key' => 'on_leave'
                        ]
                    ];

                    foreach ($statuses as $label => $data): 
                        $isActive = ($status_norm === $data['key']);
                    ?>
                        <button id="status-btn-<?php echo $data['key']; ?>" 
                                onclick="handleStatusUpdate('<?php echo $data['key']; ?>')" 
                                class="w-full flex items-center justify-between p-4 rounded-2xl border transition-all duration-300 
                                <?php echo $isActive ? $data['active_cls'] : 'bg-slate-100 dark:bg-white/5 border-slate-200 dark:border-white/10 hover:border-violet-500/30 text-slate-600 dark:text-slate-400 opacity-60'; ?>">
                            <div class="flex items-center gap-4">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><?php echo $data['icon']; ?></svg>
                                <div class="text-left">
                                    <span class="text-xs font-black uppercase tracking-widest block" id="<?php echo $data['key'] === 'busy' ? 'status-text-Busy' : 'label-text-'.$data['key']; ?>">
                                        <?php echo $label; ?>
                                    </span>
                                    <?php if ($data['key'] === 'busy' && $isActive && isset($busy_until)): ?>
                                        <span id="busy-timer-sub" class="text-[9px] font-bold opacity-80 uppercase tracking-tighter text-white block mt-0.5">
                                            INITIALIZING...
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($isActive): ?>
                                <div class="w-1.5 h-1.5 rounded-full bg-white animate-pulse"></div>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="pt-6 border-t border-slate-200 dark:border-white/5">
                <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest mb-3 italic">Live Status Protocol</p>
                <div class="flex items-center gap-3 p-4 rounded-2xl bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 shadow-sm">
                    <?php 
                        // Clean PHP conditions for terminal status output
                        $dot_color = 'bg-slate-500 shadow-slate-500/60 shadow-lg';
                        $text_color = 'text-slate-500';
                        $subtext = '<span class="text-slate-500">UNKNOWN STATUS</span>';

                        if ($status_norm === 'available') {
                            $dot_color = 'bg-green-500 shadow-green-500/60 shadow-lg';
                            $text_color = 'text-green-500';
                            $subtext = '<span class="text-green-500">AVAILABLE Mode - Fully accessible</span>';
                        } elseif ($status_norm === 'busy') {
                            $dot_color = 'bg-orange-500 shadow-orange-500/60 shadow-lg';
                            $text_color = 'text-orange-500';
                            $subtext = '<span class="text-orange-500">BUSY Mode Active - Countdown Engaged</span>';
                        } elseif ($status_norm === 'on_leave') {
                            $dot_color = 'bg-red-500 shadow-red-500/60 shadow-lg';
                            $text_color = 'text-red-500';
                            $subtext = '<span class="text-red-500">ON LEAVE Mode ACTIVE</span>';
                        }
                    ?>
                    <div id="terminal-status-dot" class="w-2 h-2 rounded-full <?php echo $dot_color; ?> animate-pulse"></div>
                    <div class="flex flex-col">
                        <p class="text-[10px] font-black text-slate-900 dark:text-slate-200 uppercase tracking-tighter">
                            CURRENT MODE: <span id="terminal-status-text" class="<?php echo $text_color; ?> italic"><?php echo strtoupper(str_replace('_', ' ', $status_norm)); ?></span>
                        </p>
                        <p id="terminal-status-subtext" class="text-[8px] font-black uppercase tracking-widest mt-0.5">
                            <?php echo $subtext; ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

                <!-- Recent Activities / Quick View of Request -->
                <div class="lg:col-span-2 glass-card p-8 rounded-[2rem]">
                    <div class="flex items-center justify-between mb-8">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">Next in Line Preview</h3>
                        <button onclick="showTab('requests')" class="text-[10px] font-black text-violet-500 uppercase tracking-widest hover:text-white transition-all">View All Requests</button>
                    </div>

                    <?php if ($next_in_line): ?>
                        <div class="flex items-center gap-6 p-6 rounded-3xl bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10">
                            <div class="w-14 h-14 rounded-2xl bg-violet-600/10 flex items-center justify-center text-xl font-black text-violet-500 italic">
                                <?php echo strtoupper(substr($next_in_line['student_name'], 0, 1)); ?>
                            </div>
                            <div class="flex-1">
                                <h4 class="text-lg font-bold"><?php echo htmlspecialchars($next_in_line['student_name']); ?></h4>
                                <p class="text-xs text-slate-500 font-medium uppercase tracking-widest mt-1"><?php echo htmlspecialchars($next_in_line['school_id']); ?></p>
                            </div>
                            <a href="faculty_dashboard.php?action=call&app_id=<?php echo $next_in_line['app_id']; ?>" class="action-btn bg-violet-600 text-white shadow-violet-600/20 hover:bg-violet-500">
                                Call Student
                            </a>
                        </div>
                    <?php else: ?>
                        <div class="flex flex-col items-center justify-center py-10 opacity-40">
                            <p class="text-xs font-black uppercase tracking-[0.2em]">No students waiting</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Tab: Requests -->
        <div id="view-requests" class="tab-view hidden">
            <div class="glass-card rounded-[3rem] overflow-hidden">
                <div class="px-12 py-10 border-b border-slate-200 dark:border-white/5 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div>
                        <h2 class="text-4xl font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">Queue <span class="text-violet-500">Controller</span></h2>
                        <p class="text-slate-500 font-medium uppercase text-xs tracking-widest mt-2">Manage incoming appointment requests and priority levels</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-white/5 border-b border-slate-200 dark:border-white/5">
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Student Intelligence</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Engagement Purpose</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-center">Temporal Stamp</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-center">Status</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-right">Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/5">
                            <?php if (empty($appointments)): ?>
                                <tr>
                                    <td colspan="5" class="px-12 py-20 text-center opacity-40">
                                        <p class="text-sm font-black uppercase tracking-[0.2em]">Queue is currently empty</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach($appointments as $app): ?>
                                <tr class="hover:bg-violet-500/[0.02] transition-colors group <?php echo ($next_in_line && $next_in_line['app_id'] === $app['app_id']) ? 'bg-violet-500/[0.03]' : ''; ?>">
                                    <td class="px-12 py-8">
                                        <div class="flex items-center gap-4">
                                            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 flex items-center justify-center text-sm font-black text-slate-500 italic border border-slate-200 dark:border-white/5 group-hover:border-violet-500/30 transition-all">
                                                <?php echo strtoupper(substr($app['student_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <span class="font-bold text-slate-700 dark:text-slate-200 block"><?php echo htmlspecialchars($app['student_name']); ?></span>
                                                <span class="text-[10px] font-mono text-slate-500 tracking-tighter"><?php echo htmlspecialchars($app['school_id']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-12 py-8">
                                        <p class="text-xs text-slate-500 dark:text-slate-400 italic line-clamp-1 max-w-[240px]" title="<?php echo htmlspecialchars($app['reason']); ?>">
                                            "<?php echo htmlspecialchars($app['reason']); ?>"
                                        </p>
                                    </td>
                                    <td class="px-12 py-8 text-center">
                                        <div class="flex flex-col">
                                            <span class="text-[11px] font-mono font-bold text-slate-500 tracking-tighter"><?php echo date('M d', strtotime($app['created_at'])); ?></span>
                                            <span class="text-[9px] font-black text-violet-500/60 uppercase"><?php echo date('H:i', strtotime($app['created_at'])); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-12 py-8 text-center">
                                        <span class="stat-badge 
                                            <?php 
                                                switch($app['status']) {
                                                    case 'Pending': echo 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-500 border-yellow-500/20'; break;
                                                    case 'Approved': echo 'bg-green-500/10 text-green-600 dark:text-green-500 border-green-500/20'; break;
                                                    case 'Accepted': echo 'bg-blue-500/10 text-blue-600 dark:text-blue-500 border-blue-500/20'; break;
                                                    default: echo 'bg-slate-800 text-slate-500'; break;
                                                }
                                            ?>">
                                            <?php echo $app['status']; ?>
                                        </span>
                                    </td>
                                    <td class="px-12 py-8 text-right">
                                        <div class="flex items-center justify-end gap-3">
                                            <?php if ($app['status'] === 'Pending'): ?>
                                                <a href="faculty_dashboard.php?action=approve_appointment&app_id=<?php echo $app['app_id']; ?>" class="w-8 h-8 rounded-lg bg-green-500/10 text-green-500 flex items-center justify-center border border-green-500/20 hover:bg-green-500 hover:text-white transition-all">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                </a>
                                            <?php elseif ($app['status'] === 'Approved' || $app['status'] === 'Accepted'): ?>
                                                <a href="faculty_dashboard.php?action=call&app_id=<?php echo $app['app_id']; ?>" class="w-8 h-8 rounded-lg bg-violet-600 text-white flex items-center justify-center shadow-lg shadow-violet-600/20 hover:scale-110 transition-all">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                                </a>
                                            <?php endif; ?>
                                            <button onclick="openCancelModal(<?php echo $app['app_id']; ?>, '<?php echo htmlspecialchars($app['student_name']); ?>')" class="w-8 h-8 rounded-lg bg-red-500/10 text-red-500 flex items-center justify-center border border-red-500/20 hover:bg-red-500 hover:text-white transition-all">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Schedule (Unavailability) -->
        <div id="view-schedule" class="tab-view hidden">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
                <div class="lg:col-span-1 space-y-10">
                    <div class="glass-card p-10 rounded-[3rem]">
                        <h3 class="text-2xl font-black italic uppercase tracking-tighter mb-8">Block <span class="text-violet-500">Temporally</span></h3>
                        <form action="faculty_dashboard.php?action=set_unavailability" method="POST" class="space-y-6">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 block">Month</label>
                                    <select name="avail_month" class="input-field appearance-none" required>
                                        <?php
                                        $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                                        $currentM = (int)date('n');
                                        foreach ($months as $i => $m) {
                                            $mv = $i + 1;
                                            if ($mv >= $currentM) echo "<option value='$mv' ".($mv==$currentM?'selected':'').">$m</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 block">Day</label>
                                    <select name="avail_day" class="input-field appearance-none" required>
                                        <?php
                                        $currentD = (int)date('j');
                                        for ($d=1; $d<=31; $d++) echo "<option value='$d' ".($d==$currentD?'selected':'').">$d</option>";
                                        ?>
                                    </select>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 block">Commence</label>
                                    <input type="time" name="avail_start" class="input-field" required>
                                </div>
                                <div>
                                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 block">Conclude</label>
                                    <input type="time" name="avail_end" class="input-field" required>
                                </div>
                            </div>
                            <div>
                                <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-3 block">Protocol Reason</label>
                                <input type="text" name="avail_reason" placeholder="e.g. Research Symposium, Medical Interv." class="input-field">
                            </div>
                            <button type="submit" class="w-full py-5 rounded-2xl bg-violet-600 text-white font-black uppercase text-[10px] tracking-[0.2em] shadow-xl shadow-violet-600/20 hover:bg-violet-500 transition-all">
                                Synchronize Block
                            </button>
                        </form>
                    </div>
                </div>

                <div class="lg:col-span-2 glass-card rounded-[3rem] overflow-hidden">
                    <div class="px-10 py-8 border-b border-slate-200 dark:border-white/5">
                        <h3 class="text-xl font-bold">Active Temporal Blocks</h3>
                    </div>
                    <div class="p-4 space-y-4">
                        <?php if (empty($blocked_slots)): ?>
                            <div class="py-20 text-center opacity-40">
                                <p class="text-sm font-black uppercase tracking-[0.2em]">No temporal blocks active</p>
                            </div>
                        <?php endif; ?>
                        <?php foreach($blocked_slots as $slot): ?>
                            <div class="p-6 rounded-[2rem] bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/5 flex items-center justify-between group">
                                <div class="flex items-center gap-6">
                                    <div class="w-12 h-12 rounded-2xl bg-violet-600/10 flex items-center justify-center text-violet-500">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M9 16h6"/></svg>
                                    </div>
                                    <div>
                                        <p class="text-[10px] font-black text-violet-500 uppercase tracking-widest mb-1"><?php echo date('F d, Y', strtotime($slot['unavailable_date'])); ?></p>
                                        <h4 class="text-sm font-bold"><?php echo date('h:i A', strtotime($slot['start_time'])); ?> — <?php echo date('h:i A', strtotime($slot['end_time'])); ?></h4>
                                        <p class="text-[10px] text-slate-500 font-bold uppercase mt-1">Reason: <span class="italic font-medium normal-case"><?php echo htmlspecialchars($slot['reason']); ?></span></p>
                                    </div>
                                </div>
                                <a href="faculty_dashboard.php?action=delete_availability&avail_id=<?php echo $slot['id']; ?>" class="w-10 h-10 rounded-xl bg-red-500/10 text-red-500 flex items-center justify-center border border-red-500/20 opacity-0 group-hover:opacity-100 transition-all hover:bg-red-500 hover:text-white">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab: Archives -->
        <div id="view-archives" class="tab-view hidden">
            <div class="glass-card rounded-[3rem] overflow-hidden">
                <div class="px-12 py-10 border-b border-slate-200 dark:border-white/5 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div>
                        <h2 class="text-4xl font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">Engagement <span class="text-violet-500">Archives</span></h2>
                        <p class="text-slate-500 font-medium uppercase text-xs tracking-widest mt-2">Historical data of all processed and finalized sessions</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-white/5 border-b border-slate-200 dark:border-white/5">
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Participant</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Subject Matter</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-center">Temporal Stamp</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-center">Final State</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-right">Records</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/5">
                            <?php if (empty($archives)): ?>
                                <tr>
                                    <td colspan="5" class="px-12 py-20 text-center opacity-40">
                                        <p class="text-sm font-black uppercase tracking-[0.2em]">Archive is currently empty</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach($archives as $entry): ?>
                                <tr class="hover:bg-indigo-500/[0.02] transition-colors group">
                                    <td class="px-12 py-8">
                                        <div class="flex items-center gap-4">
                                            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 flex items-center justify-center text-sm font-black text-slate-500 italic border border-slate-200 dark:border-white/5 group-hover:border-violet-500/30 transition-all">
                                                <?php echo strtoupper(substr($entry['student_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <span class="font-bold text-slate-700 dark:text-slate-200 block"><?php echo htmlspecialchars($entry['student_name']); ?></span>
                                                <span class="text-[10px] font-mono text-slate-500 tracking-tighter"><?php echo htmlspecialchars($entry['school_id']); ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-12 py-8">
                                        <p class="text-xs text-slate-500 dark:text-slate-400 italic line-clamp-1 max-w-[240px]" title="<?php echo htmlspecialchars($entry['reason']); ?>">
                                            "<?php echo htmlspecialchars($entry['reason']); ?>"
                                        </p>
                                    </td>
                                    <td class="px-12 py-8 text-center text-[11px] font-mono font-bold text-slate-500 tracking-tighter">
                                        <?php echo date('M d, Y', strtotime($entry['created_at'])); ?>
                                    </td>
                                    <td class="px-12 py-8 text-center">
                                        <span class="stat-badge 
                                            <?php 
                                                switch($entry['status']) {
                                                    case 'Completed': echo 'bg-green-500/10 text-green-500 border-green-500/20'; break;
                                                    case 'Cancelled':
                                                    case 'Declined': echo 'bg-red-500/10 text-red-500 border-red-500/20'; break;
                                                    case 'No-Show': echo 'bg-slate-700 text-slate-400 border border-slate-600'; break;
                                                    default: echo 'bg-slate-800 text-slate-500'; break;
                                                }
                                            ?>">
                                            <?php echo htmlspecialchars($entry['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-12 py-8 text-right">
                                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest opacity-20 italic">Validated</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tab: Profile (Communication Suite) -->
        <div id="view-profile" class="tab-view hidden">
            <div class="max-w-2xl mx-auto glass-card rounded-[3rem] p-12">
                <div class="flex items-center gap-6 mb-12">
                    <div class="w-20 h-20 rounded-[2rem] bg-violet-600 flex items-center justify-center text-white shadow-2xl shadow-violet-600/30 font-black italic text-3xl">
                        <?php echo strtoupper(substr($faculty_name, 0, 1)); ?>
                    </div>
                    <div>
                        <h2 class="text-4xl font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">Communication <span class="text-violet-500">Suite</span></h2>
                        <p class="text-slate-500 font-medium uppercase text-xs tracking-widest mt-2">Manage how students contact you for institutional matters</p>
                    </div>
                </div>

                <form action="faculty_dashboard.php?action=update_profile" method="POST" class="space-y-8">
                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4">Primary Contact Number</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                            </div>
                            <input type="text" name="contact_number" value="<?php echo htmlspecialchars($contact_number); ?>" class="input-field" placeholder="+63 9XX XXX XXXX">
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4">Institutional Email Address</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            </div>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($faculty_email); ?>" class="input-field" placeholder="faculty@school.edu.ph">
                        </div>
                    </div>

                    <div class="space-y-3">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4">Messenger / Social Grid Link</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M7.9 20A9 9 0 1 0 4 16.1L2 22Z"/><path d="M8 12h.01"/><path d="M12 12h.01"/><path d="M16 12h.01"/></svg>
                            </div>
                            <input type="url" name="social_link" value="<?php echo htmlspecialchars($social_link); ?>" class="input-field" placeholder="https://m.me/yourusername">
                        </div>
                    </div>

                    <button type="submit" class="w-full py-5 rounded-2xl bg-violet-600 text-white font-black uppercase text-xs tracking-[0.3em] shadow-xl shadow-violet-600/20 hover:bg-violet-500 hover:-translate-y-1 transition-all duration-300">
                        Authorize Profile Update
                    </button>
                </form>
            </div>
        </div>

    </main>

    <!-- Busy Duration Modal -->
    <div id="busyModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6 bg-slate-950/60 backdrop-blur-sm">
        <div class="glass-card w-full max-w-sm rounded-[3rem] p-10 relative overflow-hidden animate-in fade-in zoom-in duration-300 bg-white dark:bg-[#0d121f]">
            <div class="absolute top-0 right-0 w-32 h-32 bg-amber-500/10 blur-3xl -mr-16 -mt-16"></div>
            
            <div class="relative z-10 text-center">
                <div class="inline-flex items-center justify-center w-20 h-20 rounded-[2.5rem] bg-orange-500 shadow-2xl shadow-orange-500/30 mb-8">
                    <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-white"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </div>
                
                <h2 class="text-3xl font-black italic tracking-tighter uppercase mb-2">Set <span class="text-orange-500">Duration</span></h2>
                <p class="text-xs font-black uppercase tracking-[0.3em] text-slate-500 mb-10">How many hours will you be busy?</p>

                <div class="space-y-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest block text-left pl-2">Specify Busy Hours</label>
                        <div class="flex items-center justify-between bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-2 select-none">
                            <button type="button" onclick="adjustHours(-1)" class="w-12 h-12 flex items-center justify-center rounded-xl bg-white dark:bg-white/10 border border-slate-200 dark:border-white/10 shadow text-slate-700 dark:text-white hover:bg-orange-500 hover:text-white hover:border-orange-500 hover:shadow-orange-500/20 active:scale-95 transition-all text-xl font-black cursor-pointer">-</button>
                            <input id="custom-busy-hours" type="number" min="1" max="24" value="1" readonly class="bg-transparent text-center text-2xl font-black w-20 outline-none text-slate-800 dark:text-white" />
                            <button type="button" onclick="adjustHours(1)" class="w-12 h-12 flex items-center justify-center rounded-xl bg-white dark:bg-white/10 border border-slate-200 dark:border-white/10 shadow text-slate-700 dark:text-white hover:bg-orange-500 hover:text-white hover:border-orange-500 hover:shadow-orange-500/20 active:scale-95 transition-all text-xl font-black cursor-pointer">+</button>
                        </div>
                    </div>

                    <div class="flex gap-4">
                        <button type="button" onclick="closeBusyModal()" class="flex-1 py-4 text-[10px] font-black border border-slate-200 dark:border-white/10 rounded-2xl text-slate-500 dark:text-slate-400 hover:text-white hover:bg-white/5 hover:border-white/20 transition-all uppercase tracking-widest cursor-pointer">Cancel</button>
                        <button onclick="confirmCustomBusy()" class="flex-[1.5] py-4 rounded-2xl bg-orange-500 text-white font-black text-[10px] uppercase shadow-lg shadow-orange-500/20 hover:bg-orange-400 hover:scale-[1.02] active:scale-95 transition-all tracking-[0.2em] cursor-pointer">Confirm</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Emergency Cancellation Modal -->
    <div id="cancelModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-6 bg-[#06080f]/90 backdrop-blur-md animate-fade-in">
        <div class="glass-card w-full max-w-md p-10 rounded-[3rem] border-red-500/30 shadow-[0_40px_80px_rgba(239,68,68,0.15)] bg-white dark:bg-[#0d121f]">
            <div class="flex items-center gap-5 mb-8">
                <div class="w-14 h-14 rounded-2xl bg-red-500/10 flex items-center justify-center text-red-500 shadow-inner">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <div>
                    <h3 class="text-2xl font-black italic uppercase tracking-tighter text-slate-800 dark:text-white">Force <span class="text-red-500">Revocation</span></h3>
                    <p class="text-[10px] text-slate-500 font-bold uppercase tracking-[0.2em] mt-1">Target: <span id="modalStudentName" class="text-violet-500"></span></p>
                </div>
            </div>

            <form action="faculty_dashboard.php?action=decline_appointment" method="POST" class="space-y-8">
                <input type="hidden" name="app_id" id="modalAppId">
                <div>
                     <input type="hidden" name="app_id" id="modalAppId">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] mb-4 block">Institutional Justification</label>
                    <textarea name="decline_reason" required placeholder="Provide an official reason for this sudden cancellation..." class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-5 text-sm text-slate-800 dark:text-slate-200 outline-none focus:border-red-500 min-h-[140px] resize-none transition-all shadow-inner"></textarea>
                </div>

                <div class="flex gap-4">
                    <button type="button" onclick="closeCancelModal()" class="flex-1 py-4 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-500 font-black text-[10px] uppercase tracking-widest hover:bg-slate-200/50 transition-all border border-slate-200 dark:border-white/10">Abort</button>
                    <button type="submit" class="flex-[1.5] py-4 rounded-xl bg-red-600 text-white font-black shadow-xl shadow-red-600/20 hover:bg-red-500 transition-all text-[10px] uppercase tracking-[0.2em]">Confirm Revocation</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Persistence parameters injected from PHP
        const currentStatus = <?php echo json_encode($current_status); ?>;
        let remainingSeconds = <?php echo (int)($remaining_seconds ?? 0); ?>;
        let timerInterval = null;

        function showTab(tabName) {
            // Update UI Views
            document.querySelectorAll('.tab-view').forEach(view => view.classList.add('hidden'));
            const targetView = document.getElementById('view-' + tabName);
            if (targetView) targetView.classList.remove('hidden');
            
            // Update Tab Activation Class
            document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
            const targetTab = document.getElementById('tab-' + tabName);
            if (targetTab) targetTab.classList.add('active');
            
            // Persist User active Tab
            localStorage.setItem('faculty_active_tab', tabName);
        }

        // Initialize Tab
        const savedTab = localStorage.getItem('faculty_active_tab') || 'dashboard';
        showTab(savedTab);

        // FIXED: Function name changed to match HTML onclick="handleStatusUpdate()"
        function handleStatusUpdate(val) {
            const valLower = val.toLowerCase();
            const currentLower = currentStatus.toLowerCase();
            if (valLower === 'busy') {
                if ((currentLower === 'busy' || currentLower === 'busy_mode') && remainingSeconds > 0) {
                    Swal.fire({
                        title: '<div class="text-left font-black tracking-tight text-xl text-slate-800 dark:text-white uppercase italic">Confirm Reset</div>',
                        text: 'Are you sure you want to remove the busy countdown and return to Available?',
                        icon: 'warning',
                        background: document.documentElement.classList.contains('dark') ? '#0d121f' : '#ffffff',
                        color: document.documentElement.classList.contains('dark') ? '#f1f5f9' : '#0f172a',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, return to Available',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#10b981',
                        cancelButtonColor: '#ef4444',
                        customClass: {
                            popup: 'rounded-[2rem] border border-slate-200 dark:border-white/10 shadow-2xl p-8 max-w-md',
                            confirmButton: 'px-6 py-3.5 rounded-xl font-black text-[10px] uppercase tracking-[0.2em] cursor-pointer text-white',
                            cancelButton: 'px-6 py-3.5 rounded-xl font-black text-[10px] uppercase tracking-[0.2em] cursor-pointer text-white'
                        }
                    }).then((result) => {
                        if (result.isConfirmed) {
                            setStatus('Available');
                        }
                    });
                } else {
                    document.getElementById('busyModal').classList.remove('hidden');
                }
            } else {
                setStatus(val);
            }
        }

        function closeBusyModal() {
            document.getElementById('busyModal').classList.add('hidden');
        }

        async function setStatus(val, h = null) {
            try {
                // FIX 1: Gumamit ng .pathname para malinis ang URL na pagbabatuhaan ng request.
                // Tatanggalin nito ang mga sumabit na ?action= parameters.
                const cleanUrl = window.location.pathname;
                
                // FIX 2: I-format as JSON ang ipapasa natin sa PHP.
                const payload = { status: val, hours: h };
                
                const res = await fetch(cleanUrl, { 
                    method: 'POST', 
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest' 
                    },
                    body: JSON.stringify(payload)
                });
                
                const data = await res.json();
                
                if (data.success) {
                    // FIX 3: Imbes na reload(), ire-direct natin sa malinis na URL
                    // para maiwasan ang pabalik-balik na error loop.
                    window.location.href = cleanUrl; 
                } else {
                    Swal.fire({
                        title: 'Error',
                        text: data.message || 'An unknown error occurred.',
                        icon: 'error',
                        confirmButtonColor: '#4f46e5'
                    });
                }
            } catch(e) { 
                console.error("Transmission Error:", e);
                Swal.fire({
                    title: 'System Transmission Failure',
                    text: 'Unable to synchronize status changes with the server. Please check the network connectivity.',
                    icon: 'error',
                    confirmButtonColor: '#4f46e5'
                });
            }
        }
        function formatTime(seconds) {
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${String(mins).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }

        function startBusyTimer() {
            if (remainingSeconds <= 0) return;

            const timerEl = document.getElementById('status-text-Busy');
            const subTimerEl = document.getElementById('busy-timer-sub');
            const terminalTextEl = document.getElementById('terminal-status-text');
            const terminalSubEl = document.getElementById('terminal-status-subtext');
            const navTextEl = document.getElementById('nav-status-text');

            function updateDisplay() {
                if (remainingSeconds <= 0) {
                    clearInterval(timerInterval);
                    if (timerEl) timerEl.textContent = 'Busy';
                    if (terminalTextEl) {
                        terminalTextEl.textContent = 'AVAILABLE';
                        terminalTextEl.className = "text-green-500 italic";
                    }
                    if (terminalSubEl) terminalSubEl.textContent = 'Fully accessible for cueing';
                    setStatus('Available');
                    return;
                }

                const timerStr = formatTime(remainingSeconds);
                if (timerEl) {
                    timerEl.textContent = `BUSY - ${timerStr}`;
                }
                if (subTimerEl) {
                    subTimerEl.textContent = `Expires in ${timerStr}`;
                }
                if (terminalTextEl) {
                    terminalTextEl.textContent = `BUSY - ${timerStr}`;
                }
                if (terminalSubEl) {
                    terminalSubEl.textContent = `Expires in ${timerStr}`;
                }
                if (navTextEl) {
                    navTextEl.textContent = `BUSY (${timerStr})`;
                }
                remainingSeconds--;
            }

            updateDisplay();
            timerInterval = setInterval(updateDisplay, 1000);
        }

        window.addEventListener('DOMContentLoaded', () => {
            if (currentStatus.toLowerCase() === 'busy') {
                startBusyTimer();
            }
        });

        function adjustHours(amount) {
            const input = document.getElementById('custom-busy-hours');
            if (input) {
                let val = parseInt(input.value) || 1;
                val += amount;
                if (val < 1) val = 1;
                if (val > 24) val = 24;
                input.value = val;
            }
        }

        function confirmCustomBusy() {
            const input = document.getElementById('custom-busy-hours');
            const hours = input ? parseInt(input.value) || 1 : 1;
            setStatus('Busy', hours);
        }

        const busyModal = document.getElementById('busyModal');
        if (busyModal) {
            busyModal.addEventListener('click', (e) => {
                if (e.target === busyModal) closeBusyModal();
            });
        }

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
    </script>
    
</body>
</html>
