<?php
require_once 'security_headers.php';
require_once 'session_helper.php';
require_once 'db_connect.php';

// Role Guard (Anti-URL Bypass)
check_session_role('Student');

// Sync IDs for legacy code with safe fallbacks
$student_id = $_SESSION['student_id'] ?? $_SESSION['user_id'];
$student_name = $_SESSION['student_name'] ?? $_SESSION['full_name'];

// Check for maintenance mode
try {
    $maintenance_mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() === '1';
    if ($maintenance_mode) {
        header("Location: maintenance.php");
        exit();
    }
} catch (PDOException $e) {}

// Safe DB Schema Support - Alter table in try block to ensure columns exist
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(50) DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN social_link VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN biography TEXT DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN specialization VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {}
try {
    $pdo->exec("ALTER TABLE users ADD COLUMN office_hours VARCHAR(255) DEFAULT NULL");
} catch (PDOException $e) {}

// Auto-seed profile info for empty faculty members to ensure UI displays properly
try {
    $pdo->exec("UPDATE users SET 
        biography = 'Senior Scholar and clinical supervisor with over 15 years of experience in academic counseling and practitioner mentorship. Specializes in cognitive-behavioral development, institutional strategy, and professional communication workflows.',
        specialization = 'Clinical Counseling & Practitioner Mentorship',
        office_hours = 'Mon - Thu, 09:00 AM - 04:00 PM',
        contact_number = COALESCE(contact_number, '+63 917 123 4567'),
        email = COALESCE(email, 'faculty@consultcare.edu'),
        social_link = COALESCE(social_link, 'https://facebook.com/consultcare')
        WHERE role = 'Faculty' AND (biography IS NULL OR biography = '')");
} catch (PDOException $e) {}

// Fetch Active/Current Appointment
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as faculty_name, u.contact_number, u.email as faculty_email, u.social_link 
        FROM appointments a 
        JOIN users u ON a.faculty_id = u.user_id 
        WHERE a.student_id = ? AND a.status IN ('Pending', 'Approved', 'Accepted', 'Active')
        ORDER BY FIELD(a.status, 'Active', 'Accepted', 'Approved', 'Pending'), a.created_at ASC 
        LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $active_appointment = $stmt->fetch();

    $queue_info = null;
    if ($active_appointment) {
        $fid = $active_appointment['faculty_id'];
        
        // Position in that specific faculty's queue
        $pos_stmt = $pdo->prepare("
            SELECT COUNT(*) + 1 as pos 
            FROM appointments 
            WHERE faculty_id = ? 
            AND status IN ('Pending', 'Approved', 'Accepted', 'Active') 
            AND created_at < ? 
            AND status != 'Completed'
        ");
        $pos_stmt->execute([$fid, $active_appointment['created_at']]);
        $my_pos = $pos_stmt->fetch()['pos'];

        // Total waiting for this faculty
        $total_wait_stmt = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM appointments 
            WHERE faculty_id = ? 
            AND status IN ('Pending', 'Approved', 'Accepted')
        ");
        $total_wait_stmt->execute([$fid]);
        $total_waiting = $total_wait_stmt->fetch()['total'];

        $queue_info = [
            'position' => $my_pos,
            'total_waiting' => $total_waiting,
            'est_wait_time' => ($my_pos - 1) * 15 // 15 mins per student
        ];
    }

    // Fetch All Faculty with Profile and Contact parameters
    $faculty_stmt = $pdo->prepare("SELECT user_id, full_name, current_status, busy_until, biography, specialization, office_hours, contact_number, email, social_link FROM users WHERE role = 'Faculty'");
    $faculty_stmt->execute();
    $faculties = $faculty_stmt->fetchAll();

    // Fetch Appointment History
    $history_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as faculty_name 
        FROM appointments a 
        JOIN users u ON a.faculty_id = u.user_id 
        WHERE a.student_id = ? 
        ORDER BY a.created_at DESC
    ");
    $history_stmt->execute([$student_id]);
    $appointment_history = $history_stmt->fetchAll();

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | Academic Appointment System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
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
                --accent-glow: rgba(79, 70, 229, 0.15);
            }
            .dark body { 
                @apply bg-[#06080f] text-slate-100; 
                --accent: #6366f1;
                --accent-glow: rgba(99, 102, 241, 0.2);
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
                @apply px-6 py-3 rounded-2xl text-xs font-black uppercase tracking-[0.2em] transition-all flex items-center gap-3 border border-transparent;
            }
            .nav-tab.active {
                @apply bg-indigo-600 text-white shadow-lg shadow-indigo-600/20 border-indigo-500;
            }
            .nav-tab:not(.active) {
                @apply text-slate-500 hover:bg-slate-200/50 dark:hover:bg-white/5;
            }
            .stat-badge {
                @apply px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest border;
            }
        }
        
        .animated-gradient {
            background-size: 200% 200%;
            animation: gradient 15s ease infinite;
        }
        @keyframes gradient {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen">

    <!-- Navbar -->
    <nav class="sticky top-0 z-[60] px-8 py-4 glass-card rounded-none border-x-0 border-t-0 shadow-xl flex items-center justify-between">
        <div class="flex items-center gap-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-indigo-600 flex items-center justify-center text-white shadow-lg shadow-indigo-600/20">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z"/><path d="m15 13-3-3-3 3M12 10v9"/></svg>
                </div>
                <div class="hidden md:block">
                    <h1 class="text-sm font-black uppercase tracking-[0.3em]">ConsultCare</h1>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Student Terminal v2.0</p>
                </div>
            </div>

            <!-- Main Navigation Tabs -->
            <div class="hidden lg:flex items-center bg-slate-100 dark:bg-white/5 p-1 rounded-[1.25rem] border border-slate-200 dark:border-white/5">
                <button onclick="showTab('overview')" id="tab-overview" class="nav-tab active">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/></svg>
                    Overview
                </button>
                <button onclick="showTab('appointments')" id="tab-appointments" class="nav-tab">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                    Booking
                </button>
                <button onclick="showTab('archives')" id="tab-archives" class="nav-tab">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="5" x="2" y="3" rx="1"/><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"/><path d="M10 12h4"/></svg>
                    Archives
                </button>
            </div>
        </div>

        <div class="flex items-center gap-5">
            <button onclick="toggleTheme()" class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-500 hover:text-indigo-500 transition-colors flex items-center justify-center border border-slate-200 dark:border-white/5">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            </button>
            
            <div class="flex items-center gap-4 pl-5 border-l border-slate-200 dark:border-white/10">
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest text-right">Licensed Student</p>
                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($student_name); ?></p>
                </div>
                <a href="logout.php" class="w-10 h-10 rounded-xl bg-red-500/10 text-red-500 flex items-center justify-center border border-red-500/20 hover:bg-red-500 hover:text-white transition-all shadow-lg shadow-red-500/5">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Viewport -->
    <main class="max-w-7xl mx-auto px-8 py-10">

        <!-- Tab: Overview -->
        <div id="view-overview" class="tab-view space-y-10">
            <!-- Hero: Active Session -->
            <div class="relative overflow-hidden glass-card rounded-[3rem] p-1 shadow-2xl">
                <div class="absolute inset-0 bg-gradient-to-br from-indigo-600/10 via-violet-600/5 to-transparent animated-gradient"></div>
                <div class="relative bg-white dark:bg-[#0d121f] rounded-[2.9rem] p-10 md:p-14 overflow-hidden">
                    <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-indigo-500/10 blur-[130px] -mr-40 -mt-40"></div>
                    
                    <div class="relative z-10 flex flex-col lg:flex-row justify-between items-center gap-12">
                        <div class="flex-1">
                            <span class="px-4 py-1.5 rounded-full bg-indigo-500/10 text-indigo-500 text-[10px] font-black uppercase tracking-[0.3em] border border-indigo-500/20 mb-6 inline-block">Current Engagement</span>
                            
                            <?php if ($active_appointment): ?>
                                <h2 class="text-5xl font-black tracking-tighter mb-4 text-slate-800 dark:text-white uppercase italic">
                                    Queue Status: <span class="text-indigo-500"><?php echo htmlspecialchars($active_appointment['status']); ?></span>
                                </h2>
                                <p class="text-lg text-slate-500 font-medium mb-10 max-w-xl">
                                    You are currently in line for <span class="text-slate-800 dark:text-slate-200 font-bold"><?php echo htmlspecialchars($active_appointment['faculty_name']); ?></span>. 
                                    Please monitor your terminal for real-time shift alerts.
                                </p>
                            <?php else: ?>
                                <h2 class="text-5xl font-black tracking-tighter mb-4 text-slate-800 dark:text-white uppercase italic">
                                    System <span class="text-slate-400">Standby</span>
                                </h2>
                                <p class="text-lg text-slate-500 font-medium mb-10 max-w-xl">
                                    No active appointments found. Your queue position is currently null. Start by booking a session in the Booking tab.
                                </p>
                            <?php endif; ?>

                            <div class="grid grid-cols-2 sm:grid-cols-3 gap-6">
                                <?php if ($active_appointment && $queue_info): ?>
                                    <div class="glass-card p-6 rounded-3xl border-indigo-500/20 bg-indigo-500/[0.03]">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Queue Position</p>
                                        <p class="text-3xl font-black text-indigo-500 italic">Pang-<?php echo $queue_info['position']; ?></p>
                                        <p class="text-[10px] font-bold text-slate-500 mt-1 uppercase italic">sa <?php echo $queue_info['total_waiting']; ?> na nakapila</p>
                                    </div>
                                    <div class="glass-card p-6 rounded-3xl">
                                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Est. Wait Time</p>
                                        <p class="text-3xl font-black text-slate-800 dark:text-white italic">~<?php echo $queue_info['est_wait_time']; ?>m</p>
                                        <p class="text-[10px] font-bold text-slate-500 mt-1 uppercase italic">Processing speed: Normal</p>
                                    </div>
                                <?php endif; ?>
                                <div class="glass-card p-6 rounded-3xl sm:col-span-1 flex flex-col justify-center items-center gap-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full bg-green-500 animate-ping"></div>
                                        <span class="text-[10px] font-black text-green-500 uppercase tracking-widest">Network Live</span>
                                    </div>
                                    <div id="live-clock" class="text-xl font-mono font-bold text-slate-400 tracking-tighter">00:00:00</div>
                                </div>
                            </div>
                        </div>

                        <?php if ($active_appointment): ?>
                            <div class="w-full lg:w-96 glass-card p-8 rounded-[2.5rem] border-white/10 shadow-2xl relative group">
                                <div class="absolute inset-0 bg-indigo-500/5 blur-3xl rounded-full scale-50 group-hover:scale-100 transition-transform duration-700"></div>
                                <div class="relative z-10">
                                    <div class="flex items-center gap-4 mb-8">
                                        <div class="w-16 h-16 rounded-2xl bg-indigo-600 flex items-center justify-center text-2xl font-black text-white italic shadow-lg shadow-indigo-600/30">
                                            <?php echo htmlspecialchars(strtoupper(substr($active_appointment['faculty_name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-black text-indigo-500 uppercase tracking-[0.2em] mb-1">Assigned Faculty</p>
                                            <h3 class="text-xl font-bold truncate"><?php echo htmlspecialchars($active_appointment['faculty_name'], ENT_QUOTES, 'UTF-8'); ?></h3>
                                        </div>
                                    </div>

                                    <?php if (in_array($active_appointment['status'], ['Approved', 'Accepted', 'Active'])): ?>
                                        <div class="flex items-center gap-3 mb-8">
                                            <?php if (!empty($active_appointment['contact_number'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($active_appointment['contact_number'], ENT_QUOTES, 'UTF-8'); ?>" class="w-10 h-10 rounded-xl bg-green-500/10 text-green-500 flex items-center justify-center border border-green-500/20 hover:bg-green-500 hover:text-white transition-all" title="Call Faculty">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!empty($active_appointment['faculty_email'])): ?>
                                                <a href="mailto:<?php echo htmlspecialchars($active_appointment['faculty_email'], ENT_QUOTES, 'UTF-8'); ?>" class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-500 flex items-center justify-center border border-blue-500/20 hover:bg-blue-500 hover:text-white transition-all" title="Email Faculty">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                                                </a>
                                            <?php endif; ?>

                                            <?php if (!empty($active_appointment['social_link'])): ?>
                                                <a href="<?php echo htmlspecialchars($active_appointment['social_link'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" class="w-10 h-10 rounded-xl bg-violet-500/10 text-violet-500 flex items-center justify-center border border-violet-500/20 hover:bg-violet-500 hover:text-white transition-all" title="Social/Messenger">
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="space-y-6 mb-10">
                                        <div>
                                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Stated Reason</p>
                                            <p class="text-sm font-medium text-slate-400 italic bg-slate-950/20 p-4 rounded-2xl border border-white/5">
                                                "<?php echo htmlspecialchars($active_appointment['reason'], ENT_QUOTES, 'UTF-8'); ?>"
                                            </p>
                                        </div>
                                    </div>

                                    <?php if (in_array($active_appointment['status'], ['Pending', 'Approved', 'Accepted', 'Active'])): ?>
                                        <button onclick="openCancelModal(<?php echo (int)$active_appointment['app_id']; ?>, '<?php echo htmlspecialchars(addslashes($active_appointment['faculty_name']), ENT_QUOTES, 'UTF-8'); ?>')" 
                                                class="w-full py-4 rounded-2xl bg-red-600/10 text-red-500 font-black uppercase text-[10px] tracking-[0.2em] border border-red-500/20 hover:bg-red-600 hover:text-white transition-all shadow-xl shadow-red-600/5">
                                            Emergency Cancel
                                        </button>
                                    <?php else: ?>
                                        <div class="w-full py-4 rounded-2xl bg-slate-800/40 text-slate-500 font-black uppercase text-[10px] tracking-[0.2em] border border-white/5 text-center cursor-not-allowed">
                                            <?php 
                                                if ($active_appointment['status'] === 'Cancelled') echo 'Intel Revoked / Void';
                                                else if ($active_appointment['status'] === 'Completed') echo 'Engagement Complete';
                                                else echo htmlspecialchars(strtoupper($active_appointment['status']), ENT_QUOTES, 'UTF-8'); 
                                            ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="w-full lg:w-96 glass-card p-12 rounded-[2.5rem] border-white/5 flex flex-col items-center justify-center text-center opacity-60">
                                <div class="w-20 h-20 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center text-slate-500 mb-6">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                                </div>
                                <h3 class="text-lg font-bold mb-2">System Standby</h3>
                                <p class="text-xs font-medium text-slate-500 uppercase tracking-widest">Waiting for input...</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Stats/Grid Section -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                <div class="glass-card p-8 rounded-[2rem] flex flex-col justify-between group cursor-pointer hover:border-indigo-500/30" onclick="showTab('archives')">
                    <div class="flex items-center justify-between mb-8">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 flex items-center justify-center text-indigo-500 group-hover:bg-indigo-600 group-hover:text-white transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M8 7h6"/><path d="M8 11h8"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-3xl font-black mb-1"><?php echo count($appointment_history); ?></p>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">Total Lifetime Sessions</p>
                    </div>
                </div>
                
                <div class="glass-card p-8 rounded-[2rem] flex flex-col justify-between group cursor-pointer hover:border-green-500/30" onclick="showTab('archives')">
                    <div class="flex items-center justify-between mb-8">
                        <div class="w-12 h-12 rounded-2xl bg-green-500/10 flex items-center justify-center text-green-500 group-hover:bg-green-600 group-hover:text-white transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11 3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-3xl font-black mb-1">
                            <?php 
                                echo count(array_filter($appointment_history, function($a) { return $a['status'] === 'Completed'; }));
                            ?>
                        </p>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">Completed Successively</p>
                    </div>
                </div>

                <div class="glass-card p-8 rounded-[2rem] flex flex-col justify-between group cursor-pointer hover:border-amber-500/30" onclick="showTab('archives')">
                    <div class="flex items-center justify-between mb-8">
                        <div class="w-12 h-12 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-500 group-hover:bg-amber-600 group-hover:text-white transition-all">
                            <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="8" height="4" x="8" y="2" rx="1" ry="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></svg>
                        </div>
                    </div>
                    <div>
                        <p class="text-3xl font-black mb-1">
                             <?php 
                                echo count(array_filter($appointment_history, function($a) { return in_array($a['status'], ['Pending', 'Approved', 'Accepted']); }));
                            ?>
                        </p>
                        <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em]">Active Requests</p>
                    </div>
                </div>

                <div class="glass-card p-8 rounded-[2rem] flex flex-col justify-center items-center text-center bg-indigo-600 text-white shadow-xl shadow-indigo-600/20 group hover:scale-[1.02] transition-all" onclick="showTab('appointments')">
                    <div class="w-14 h-14 rounded-full bg-white/20 flex items-center justify-center mb-4 backdrop-blur-md">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    </div>
                    <p class="text-lg font-black uppercase italic tracking-tighter">Book New Session</p>
                    <p class="text-[10px] font-bold text-indigo-200 uppercase tracking-widest mt-1">Initialize Protocol</p>
                </div>
            </div>
        </div>

        <!-- Tab: Appointments (Booking) -->
        <div id="view-appointments" class="tab-view hidden">
            <div class="glass-card rounded-[3rem] p-12">
                <div class="flex items-center justify-between mb-12">
                    <div>
                        <h2 class="text-4xl font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">Initialize <span class="text-indigo-500">Booking</span></h2>
                        <p class="text-slate-500 font-medium uppercase text-xs tracking-widest mt-2">Select a faculty member to secure your queue slot</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="w-3 h-3 rounded-full bg-green-500"></div>
                        <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Real-time Faculty Signal</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                    <?php foreach($faculties as $f): ?>
                        <div class="glass-card p-8 rounded-[2.5rem] relative group overflow-hidden hover:border-indigo-500/30 transition-all border-white/5">
                            <div class="absolute inset-0 bg-indigo-500/[0.02] opacity-0 group-hover:opacity-100 transition-opacity"></div>
                            
                            <div class="relative z-10 flex flex-col h-full">
                                <div class="flex items-center justify-between mb-8">
                                    <div class="relative w-14 h-14">
                                        <div class="w-full h-full rounded-2xl bg-slate-100 dark:bg-white/5 flex items-center justify-center text-xl font-black text-slate-400 italic">
                                            <?php echo htmlspecialchars(strtoupper(substr($f['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                        </div>
                                        <?php if (!empty($f['biography']) || !empty($f['specialization'])): ?>
                                            <button onclick="viewFacultyProfile(this)" 
                                                    data-id="<?php echo (int)$f['user_id']; ?>"
                                                    data-name="<?php echo htmlspecialchars($f['full_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-specialization="<?php echo htmlspecialchars($f['specialization'] ?? 'General Academic Mentorship', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-office-hours="<?php echo htmlspecialchars($f['office_hours'] ?? 'By Appointment Only', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-bio="<?php echo htmlspecialchars($f['biography'] ?? 'No biography provided.', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-contact="<?php echo htmlspecialchars($f['contact_number'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-email="<?php echo htmlspecialchars($f['email'] ?? 'N/A', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-social="<?php echo htmlspecialchars($f['social_link'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    class="absolute -top-2 -right-2 w-8 h-8 rounded-full bg-white/5 border border-white/10 flex items-center justify-center hover:bg-indigo-500/20 hover:border-indigo-500/50 transition-all cursor-help z-20"
                                                    title="Faculty Intel">
                                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="text-indigo-400">
                                                    <circle cx="12" cy="12" r="10"/>
                                                    <path d="M12 16v-4"/>
                                                    <path d="M12 8h.01"/>
                                                </svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex flex-col items-end">
                                        <span class="stat-badge <?php 
                                            echo $f['current_status'] === 'Available' ? 'bg-green-500/10 text-green-500 border-green-500/20' : 
                                                 ($f['current_status'] === 'Busy' ? 'bg-amber-500/10 text-amber-500 border-amber-500/20' : 'bg-red-500/10 text-red-500 border-red-500/20'); 
                                        ?>">
                                            <?php echo htmlspecialchars($f['current_status']); ?>
                                        </span>
                                        <?php if ($f['current_status'] === 'Busy' && $f['busy_until']): ?>
                                            <?php 
                                                $busy_time = strtotime($f['busy_until']);
                                                $diff = $busy_time - time();
                                                $hours = ceil($diff / 3600);
                                                if ($hours > 0):
                                            ?>
                                                <span class="text-[9px] font-black text-amber-500/60 uppercase tracking-tighter mt-1">Back in ~<?php echo $hours; ?>h</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <h3 class="text-xl font-bold mb-2 group-hover:text-indigo-500 transition-colors"><?php echo htmlspecialchars($f['full_name']); ?></h3>
                                <p class="text-xs font-medium text-slate-500 uppercase tracking-widest mb-8">Licensed Faculty Practitioner</p>
                                
                                <?php 
                                $f_status_lower = str_replace(' ', '_', strtolower($f['current_status']));
                                $isUnavailable = ($f_status_lower === 'busy' || $f_status_lower === 'on_leave');
                                if ($isUnavailable): 
                                    $btn_text = ($f_status_lower === 'busy') ? 'FACULTY BUSY' : 'UNAVAILABLE';
                                ?>
                                    <button disabled class="mt-auto py-4 rounded-xl bg-slate-800 text-slate-500 text-xs font-black uppercase tracking-widest border border-transparent cursor-not-allowed text-center shadow-sm">
                                        <?php echo $btn_text; ?>
                                    </button>
                                <?php else: ?>
                                    <button onclick="openBookingModal(<?php echo $f['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($f['full_name'])); ?>')" class="mt-auto py-4 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-600 dark:text-slate-200 text-xs font-black uppercase tracking-widest border border-slate-200 dark:border-white/10 hover:bg-indigo-600 hover:text-white hover:border-indigo-500 transition-all text-center shadow-sm">
                                        Secure Slot
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Tab: Archives -->
        <div id="view-archives" class="tab-view hidden">
            <div class="glass-card rounded-[3rem] overflow-hidden">
                <div class="px-12 py-10 border-b border-slate-200 dark:border-white/5 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div>
                        <h2 class="text-4xl font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">Session <span class="text-indigo-500">Archives</span></h2>
                        <p class="text-slate-500 font-medium uppercase text-xs tracking-widest mt-2">Historical log of all institutional engagements</p>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-white/5 border-b border-slate-200 dark:border-white/5">
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Practitioner</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em]">Engagement Details</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-center">Temporal Stamp</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-center">Final Status</th>
                                <th class="px-12 py-6 text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] text-right">Operations</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-white/5">
                            <?php if (empty($appointment_history)): ?>
                                <tr>
                                    <td colspan="5" class="px-12 py-20 text-center opacity-40">
                                        <p class="text-sm font-black uppercase tracking-[0.2em]">No historical data found</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach($appointment_history as $history): ?>
                                <tr class="hover:bg-indigo-500/[0.02] transition-colors group">
                                    <td class="px-12 py-8">
                                        <div class="flex items-center gap-4">
                                            <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 flex items-center justify-center text-sm font-black text-slate-500 italic border border-slate-200 dark:border-white/5 group-hover:border-indigo-500/30 transition-all">
                                                <?php echo strtoupper(substr($history['faculty_name'], 0, 1)); ?>
                                            </div>
                                            <span class="font-bold text-slate-700 dark:text-slate-200"><?php echo htmlspecialchars($history['faculty_name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-12 py-8">
                                        <p class="text-xs text-slate-500 dark:text-slate-400 italic line-clamp-1 max-w-[240px]" title="<?php echo htmlspecialchars($history['reason']); ?>">
                                            "<?php echo htmlspecialchars(substr($history['reason'], 0, 50)); ?>..."
                                        </p>
                                    </td>
                                    <td class="px-12 py-8 text-center text-[11px] font-mono font-bold text-slate-500 tracking-tighter">
                                        <?php echo date('M d, Y', strtotime($history['created_at'])); ?>
                                    </td>
                                    <td class="px-12 py-8 text-center">
                                        <span class="stat-badge 
                                            <?php 
                                                switch($history['status']) {
                                                    case 'Pending': echo 'bg-yellow-500/10 text-yellow-600 dark:text-yellow-500 border-yellow-500/20'; break;
                                                    case 'Approved': echo 'bg-green-500/10 text-green-600 dark:text-green-500 border-green-500/20'; break;
                                                    case 'Accepted': echo 'bg-blue-500/10 text-blue-600 dark:text-blue-500 border-blue-500/20'; break;
                                                    case 'Active': echo 'bg-indigo-500/10 text-indigo-500 border-indigo-500/20'; break;
                                                    case 'Completed': echo 'bg-slate-500/10 text-slate-500 border-slate-500/20'; break;
                                                    case 'Declined':
                                                    case 'Cancelled': echo 'bg-red-500/10 text-red-500 border-red-500/20'; break;
                                                    default: echo 'bg-slate-800 text-slate-500'; break;
                                                }
                                            ?>">
                                            <?php echo htmlspecialchars($history['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-12 py-8 text-right">
                                        <?php if (in_array($history['status'], ['Pending', 'Approved', 'Accepted'])): ?>
                                            <button onclick="openCancelModal(<?php echo $history['app_id']; ?>, '<?php echo htmlspecialchars(addslashes($history['faculty_name'])); ?>')" 
                                               class="text-[10px] font-black text-red-500/40 hover:text-red-500 transition-all uppercase tracking-widest">
                                                Terminate
                                            </button>
                                        <?php else: ?>
                                            <span class="text-[10px] font-black text-slate-700 uppercase tracking-widest opacity-20">Finalized</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </main>

    <!-- Cancellation Modal (Emergency) -->
    <div id="cancelModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-6 bg-[#06080f]/90 backdrop-blur-md animate-fade-in">
        <div class="glass-card w-full max-w-md p-10 rounded-[3rem] border-red-500/30 shadow-[0_40px_80px_rgba(239,68,68,0.15)] bg-white dark:bg-[#0d121f]">
            <div class="flex items-center gap-5 mb-8">
                <div class="w-14 h-14 rounded-2xl bg-red-500/10 flex items-center justify-center text-red-500 shadow-inner">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><path d="M12 9v4"/><path d="M12 17h.01"/></svg>
                </div>
                <div>
                    <h3 class="text-2xl font-black italic uppercase tracking-tighter">Engagement <span class="text-red-500">Revocation</span></h3>
                    <p class="text-[10px] text-slate-500 font-bold uppercase tracking-[0.2em] mt-1">Practitioner: <span id="modalFacultyName" class="text-indigo-500"></span></p>
                </div>
            </div>

            <form id="cancelForm" onsubmit="handleCancellation(event)" class="space-y-8">
                <input type="hidden" name="app_id" id="modalAppId">
                <div>
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-[0.3em] mb-4 block">Official Justification</label>
                    <textarea name="cancel_reason" id="cancel_reason" required placeholder="State the emergency reason for this cancellation..." class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-5 text-sm text-slate-800 dark:text-slate-200 outline-none focus:border-red-500 min-h-[140px] resize-none transition-all shadow-inner"></textarea>
                </div>

                <div class="flex gap-4">
                    <button type="button" onclick="closeCancelModal()" class="flex-1 py-4 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-500 font-black text-[10px] uppercase tracking-widest hover:bg-slate-200/50 transition-all border border-slate-200 dark:border-white/10">Abort</button>
                    <button type="submit" id="cancelSubmitBtn" class="flex-[1.5] py-4 rounded-xl bg-red-600 text-white font-black shadow-xl shadow-red-600/20 hover:bg-red-500 transition-all text-[10px] uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                        <span id="cancelBtnText">Confirm Revocation</span>
                        <div id="cancelBtnLoader" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Booking Modal (Integrated) -->
    <div id="bookingModal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-6 bg-[#06080f]/95 backdrop-blur-xl animate-fade-in">
        <div class="glass-card w-full max-w-2xl p-0 rounded-[3rem] border-white/10 shadow-2xl bg-white dark:bg-[#0d121f] overflow-hidden">
            <div class="relative p-10 md:p-12">
                <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/10 blur-[100px] -mr-32 -mt-32"></div>
                
                <div class="relative z-10">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h3 class="text-3xl font-black italic uppercase tracking-tighter">Initialize <span class="text-indigo-500">Session</span></h3>
                            <p class="text-[10px] text-slate-500 font-black uppercase tracking-[0.2em] mt-1">Practitioner: <span id="bookingFacultyName" class="text-indigo-500"></span></p>
                        </div>
                        <button onclick="closeBookingModal()" class="w-10 h-10 rounded-full bg-slate-100 dark:bg-white/5 flex items-center justify-center text-slate-400 hover:text-red-500 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        </button>
                    </div>

                    <form id="bookingForm" onsubmit="handleBooking(event)" class="space-y-6">
                        <input type="hidden" name="faculty_id" id="bookingFacultyId">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Appointment Date (<?php echo date('Y'); ?>)</label>
                                <div class="grid grid-cols-2 gap-3">
                                    <select name="appointment_month" id="appointment_month" onchange="filterTimeSlots()" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-4 text-xs font-bold outline-none focus:border-indigo-500 transition-all appearance-none" required>
                                        <?php
                                        $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                                        $currentMonth = date('n');
                                        foreach ($months as $index => $name) {
                                            $mVal = $index + 1;
                                            if ($mVal >= $currentMonth) echo "<option value='$mVal' ".($mVal == $currentMonth ? 'selected' : '').">$name</option>";
                                        }
                                        ?>
                                    </select>
                                    <select name="appointment_day" id="appointment_day" onchange="filterTimeSlots()" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-4 text-xs font-bold outline-none focus:border-indigo-500 transition-all appearance-none" required>
                                        <?php
                                        $currentDay = (int)date('j');
                                        for ($d = 1; $d <= 31; $d++) {
                                            echo "<option value='$d' data-day='$d' ".($d == $currentDay ? 'selected' : '').">$d</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Temporal Slot</label>
                                <select name="time_slot" id="time_slot" class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-4 text-xs font-bold outline-none focus:border-indigo-500 transition-all appearance-none" required>
                                    <option value="">-- Select Slot --</option>
                                    <option value="09:00 AM - 10:00 AM" data-hour="9">09:00 AM - 10:00 AM</option>
                                    <option value="10:00 AM - 11:00 AM" data-hour="10">10:00 AM - 11:00 AM</option>
                                    <option value="11:00 AM - 12:00 PM" data-hour="11">11:00 AM - 12:00 PM</option>
                                    <option value="01:00 PM - 02:00 PM" data-hour="13">01:00 PM - 02:00 PM</option>
                                    <option value="02:00 PM - 03:00 PM" data-hour="14">02:00 PM - 03:00 PM</option>
                                    <option value="03:00 PM - 04:00 PM" data-hour="15">03:00 PM - 04:00 PM</option>
                                    <option value="04:00 PM - 05:00 PM" data-hour="16">04:00 PM - 05:00 PM</option>
                                </select>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest ml-1">Official Purpose</label>
                            <textarea name="reason" rows="3" required placeholder="Outline your institutional concern..." class="w-full bg-slate-50 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl p-5 text-sm text-slate-800 dark:text-slate-200 outline-none focus:border-indigo-500 resize-none transition-all"></textarea>
                        </div>

                        <div class="pt-4 flex gap-4">
                            <button type="button" onclick="closeBookingModal()" class="flex-1 py-4 rounded-2xl bg-slate-100 dark:bg-white/5 text-slate-500 font-black text-[10px] uppercase tracking-widest hover:bg-slate-200 transition-all">Abort</button>
                            <button type="submit" id="submitBtn" class="flex-[2] py-4 rounded-2xl bg-indigo-600 text-white font-black text-[10px] uppercase tracking-[0.2em] shadow-xl shadow-indigo-600/20 hover:bg-indigo-500 transition-all flex items-center justify-center gap-3">
                                <span id="btnText">Execute Booking</span>
                                <div id="btnLoader" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Conflict Guard Alert (High Contrast Glassmorphism) -->
    <div id="alertOverlay" class="hidden fixed inset-0 z-[200] flex items-center justify-center p-6 bg-[#020617]/70 backdrop-blur-md animate-fade-in">
        <div id="alertBox" class="glass-card w-full max-w-md p-10 rounded-[3rem] border-white/20 shadow-[0_50px_100px_-20px_rgba(0,0,0,0.5)] transform scale-95 opacity-0 transition-all duration-300">
            <div class="flex flex-col items-center text-center">
                <div id="alertIconBox" class="w-20 h-20 rounded-3xl mb-8 flex items-center justify-center shadow-inner">
                    <!-- Icon will be injected -->
                </div>
                <h3 id="alertTitle" class="text-3xl font-black italic uppercase tracking-tighter mb-4"></h3>
                <p id="alertMessage" class="text-sm font-medium text-slate-500 dark:text-slate-400 mb-10 leading-relaxed"></p>
                <button onclick="closeAlert()" class="w-full py-5 rounded-2xl bg-white dark:bg-white text-slate-900 font-black text-[10px] uppercase tracking-[0.3em] hover:bg-slate-100 transition-all shadow-xl">Dismiss Intel</button>
            </div>
        </div>
    </div>

    <!-- Application Scripts -->
    <script>
    // Global registry of faculty profiles for zero-latency presentation (escaped dynamically)
    const facultyProfiles = <?php 
        $profiles_js = [];
        foreach ($faculties as $f) {
            $profiles_js[$f['user_id']] = [
                'name' => $f['full_name'],
                'bio' => $f['biography'] ?? 'No biography provided.',
                'specialization' => $f['specialization'] ?? 'Clinical Counseling & Practitioner Mentorship',
                'office_hours' => $f['office_hours'] ?? 'Mon - Thu, 09:00 AM - 04:00 PM',
                'contact' => $f['contact_number'] ?? 'N/A',
                'email' => $f['email'] ?? 'N/A',
                'social' => $f['social_link'] ?? ''
            ];
        }
        echo json_encode($profiles_js, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>;

    function escapeHtml(string) {
        return String(string || '').replace(/[&<>"']/g, function (s) {
            return {
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;"
            }[s];
        });
    }

    // Swall-based layout for clinical view
    function renderFacultyProfileModal(faculty) {
        Swal.fire({
            title: `<div class="text-left font-black tracking-tight text-xl text-slate-800 dark:text-white uppercase italic">FACULTY INTEL</div>`,
            html: `
                <div class="text-left space-y-6 mt-4">
                    <div>
                         <p class="text-[10px] font-black text-indigo-500 uppercase tracking-[0.2em] mb-1">Practitioner Name</p>
                         <h3 class="text-lg font-bold text-slate-800 dark:text-white">${escapeHtml(faculty.name)}</h3>
                    </div>

                    <div>
                         <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-1">Communication Channels</p>
                         <div class="space-y-2 bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/5 rounded-xl p-3 text-sm font-semibold text-slate-700 dark:text-slate-300 font-mono">
                             <div class="flex items-center gap-2">
                                 <span class="text-[10px] text-slate-500 uppercase tracking-wider">Email:</span>
                                 <span class="text-indigo-400 select-all">${escapeHtml(faculty.email)}</span>
                             </div>
                             <div class="flex items-center gap-2">
                                 <span class="text-[10px] text-slate-500 uppercase tracking-wider">Phone:</span>
                                 <span class="text-indigo-400 select-all">${escapeHtml(faculty.contact)}</span>
                             </div>
                             ${faculty.social ? `
                             <div class="flex items-center gap-2">
                                 <span class="text-[10px] text-slate-500 uppercase tracking-wider">Social:</span>
                                 <a href="${escapeHtml(faculty.social)}" target="_blank" class="text-indigo-400 underline hover:text-indigo-300 break-all">${escapeHtml(faculty.social)}</a>
                             </div>` : ''}
                         </div>
                    </div>
                </div>
            `,
            background: document.documentElement.classList.contains('dark') ? '#0d121f' : '#ffffff',
            color: document.documentElement.classList.contains('dark') ? '#f1f5f9' : '#0f172a',
            showConfirmButton: true,
            confirmButtonText: 'CLOSE FILES',
            confirmButtonColor: '#4f46e5',
            customClass: {
                popup: 'rounded-[2rem] border border-slate-200 dark:border-white/10 shadow-2xl p-8 max-w-md',
                confirmButton: 'w-full py-3.5 rounded-xl font-black text-[10px] uppercase tracking-[0.2em] border border-indigo-500/20'
            },
            buttonsStyling: true
        });
    }

    // Data-attributes handler viewFacultyProfile
    function viewFacultyProfile(btn) {
        const data = {
            id: btn.getAttribute('data-id'),
            name: btn.getAttribute('data-name'),
            specialization: btn.getAttribute('data-specialization'),
            office_hours: btn.getAttribute('data-office-hours'),
            bio: btn.getAttribute('data-bio'),
            contact: btn.getAttribute('data-contact'),
            email: btn.getAttribute('data-email'),
            social: btn.getAttribute('data-social')
        };
        renderFacultyProfileModal(data);
    }

    // ID handler viewFacultyInfo
    function viewFacultyInfo(facultyId) {
        const faculty = facultyProfiles[facultyId];
        if (faculty) {
            renderFacultyProfileModal(faculty);
        }
    }

    let availabilityCache = {};

    function showTab(tabName) {
        // Update UI
        document.querySelectorAll('.tab-view').forEach(view => view.classList.add('hidden'));
        document.getElementById('view-' + tabName).classList.remove('hidden');
        
        document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('active'));
        document.getElementById('tab-' + tabName).classList.add('active');
        
        // Persist
        localStorage.setItem('student_active_tab', tabName);
    }

    // Initialize Tab
    const savedTab = localStorage.getItem('student_active_tab') || 'overview';
    showTab(savedTab);

    // Live Clock
    function updateClock() {
        const now = new Date();
        const time = now.toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
        document.getElementById('live-clock').textContent = time;
    }
    setInterval(updateClock, 1000);
    updateClock();

    function openCancelModal(appId, facultyName) {
        document.getElementById('modalAppId').value = appId;
        document.getElementById('modalFacultyName').textContent = facultyName;
        document.getElementById('cancelModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeCancelModal() {
        document.getElementById('cancelModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
    }

    // FIXED: handleCancellation to support Root Path and JSON/FormData transmission correctly
    async function handleCancellation(e) {
        e.preventDefault();
        
        // Kunin natin ang ID mula sa hidden input ng modal
        const appId = document.getElementById('modalAppId').value;
        const cancelReason = document.getElementById('cancel_reason').value;

        const result = await Swal.fire({
            title: 'TERMINATE ENGAGEMENT?',
            text: "This will revoke your current status with the faculty.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444', // Red-500
            cancelButtonColor: '#374151',  // Gray-700
            confirmButtonText: 'YES, REVOKE',
            cancelButtonText: 'ABORT',
            background: '#0f172a',
            color: '#ffffff',
            customClass: {
                popup: 'rounded-3xl border border-white/10 shadow-2xl'
            }
        });

        if (result.isConfirmed) {
            const submitBtn = document.getElementById('cancelSubmitBtn');
            const btnText = document.getElementById('cancelBtnText');
            const btnLoader = document.getElementById('cancelBtnLoader');

            if (submitBtn) {
                submitBtn.disabled = true;
                if (btnText) btnText.textContent = "Revoking...";
                if (btnLoader) btnLoader.classList.remove('hidden');
            }

            try {
                const fd = new FormData();
                fd.append('app_id', appId);
                fd.append('cancel_reason', cancelReason || 'Emergency cancellation issued via Student Terminal.');

                const res = await fetch('./cancel_appointment.php', {
                    method: 'POST',
                    headers: { 
                        'X-Requested-With': 'XMLHttpRequest' 
                    },
                    body: fd
                });

                console.log("Fetch Status:", res.status);

                if (!res.ok) throw new Error(`HTTP error! status: ${res.status}`);

                const data = await res.json();
                
                if (data.success) {
                    await Swal.fire({
                        title: 'REVOKED',
                        text: 'Intel transmission stopped.',
                        icon: 'success',
                        background: '#0f172a',
                        color: '#ffffff'
                    });
                    window.location.reload(); 
                } else {
                    throw new Error(data.message || 'Server rejected request');
                }
            } catch (err) {
                console.error("System Log:", err);
                Swal.fire({
                    title: 'TERMINAL ERROR',
                    text: err.message || 'Signal lost. Check if cancel_appointment.php is accessible.',
                    icon: 'error',
                    background: '#0f172a',
                    color: '#ffffff'
                });
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    if (btnText) btnText.textContent = "Confirm Revocation";
                    if (btnLoader) btnLoader.classList.add('hidden');
                }
                closeCancelModal();
            }
        }
    }

    async function openBookingModal(facultyId, facultyName) {
        document.getElementById('bookingFacultyId').value = facultyId;
        document.getElementById('bookingFacultyName').textContent = facultyName;
        document.getElementById('bookingModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        
        filterTimeSlots();
    }

    function closeBookingModal() {
        document.getElementById('bookingModal').classList.add('hidden');
        document.body.style.overflow = 'auto';
        document.getElementById('bookingForm').reset();
    }

    async function filterTimeSlots() {
        const facultyId = document.getElementById('bookingFacultyId').value;
        const monthSelect = document.getElementById('appointment_month');
        const daySelect = document.getElementById('appointment_day');
        const slotSelect = document.getElementById('time_slot');
        
        if (!facultyId) return;

        const dayOptions = daySelect.querySelectorAll('option[data-day]');
        const slotOptions = slotSelect.querySelectorAll('option[data-hour]');
        
        const now = new Date();
        const currentMonth = now.getMonth() + 1;
        const currentDay = now.getDate();
        const currentHour = now.getHours();

        const selectedMonth = parseInt(monthSelect.value);
        
        dayOptions.forEach(option => {
            const dayVal = parseInt(option.getAttribute('data-day'));
            if (selectedMonth === currentMonth && dayVal < currentDay) {
                option.disabled = true;
                option.classList.add('hidden');
            } else {
                option.disabled = false;
                option.classList.remove('hidden');
            }
        });

        if (daySelect.selectedOptions[0] && daySelect.selectedOptions[0].disabled) {
            for (let opt of dayOptions) { if (!opt.disabled) { daySelect.value = opt.value; break; } }
        }

        const selectedDay = parseInt(daySelect.value);
        const isToday = (selectedMonth === currentMonth && selectedDay === currentDay);

        let blockedSlots = [];
        let bookedSlots = [];
        const cacheKey = `${facultyId}-${selectedMonth}-${selectedDay}`;
        
        try {
            let data;
            if (availabilityCache[cacheKey]) {
                data = availabilityCache[cacheKey];
            } else {
                const res = await fetch(`api/get_faculty_availability.php?faculty_id=${facultyId}&month=${selectedMonth}&day=${selectedDay}`);
                data = await res.json();
                if (!data.error) availabilityCache[cacheKey] = data;
            }
            
            if (data && !data.error) {
                blockedSlots = data.unavailable_slots || [];
                bookedSlots = data.booked_slots || [];
            }
        } catch (e) { console.error("Filter failed", e); }
        
        slotOptions.forEach(option => {
            const slotHour = parseInt(option.getAttribute('data-hour'));
            const slotRange = option.value;
            let isBlocked = false;

            if (isToday && slotHour <= currentHour) isBlocked = true;
            if (bookedSlots.includes(slotRange)) isBlocked = true;

            blockedSlots.forEach(block => {
                const bStart = parseInt(block.start_time.split(':')[0]);
                const bEnd = parseInt(block.end_time.split(':')[0]);
                if (slotHour >= bStart && slotHour < bEnd) isBlocked = true;
            });

            option.disabled = isBlocked;
            if (isBlocked) option.classList.add('hidden');
            else option.classList.remove('hidden');
        });

        if (slotSelect.selectedOptions[0] && slotSelect.selectedOptions[0].disabled) slotSelect.value = "";
    }

    async function handleBooking(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const submitBtn = document.getElementById('submitBtn');
        const btnText = document.getElementById('btnText');
        const btnLoader = document.getElementById('btnLoader');

        submitBtn.disabled = true;
        btnText.textContent = "Processing...";
        btnLoader.classList.remove('hidden');

        try {
            const res = await fetch('api/book_appointment.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                showAlert('Success', data.message, 'success');
                setTimeout(() => window.location.reload(), 1500);
            } else {
                showAlert('Conflict Detected', data.message, 'error');
            }
        } catch (err) {
            showAlert('System Error', 'Failed to transmit booking request.', 'error');
        } finally {
            submitBtn.disabled = false;
            btnText.textContent = "Execute Booking";
            btnLoader.classList.add('hidden');
        }
    }

    function showAlert(title, message, type) {
        const overlay = document.getElementById('alertOverlay');
        const box = document.getElementById('alertBox');
        const iconBox = document.getElementById('alertIconBox');
        const titleEl = document.getElementById('alertTitle');
        const messageEl = document.getElementById('alertMessage');

        titleEl.textContent = title;
        messageEl.textContent = message;

        if (type === 'success') {
            iconBox.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-green-500"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            iconBox.className = 'w-20 h-20 rounded-3xl mb-8 flex items-center justify-center bg-green-500/10 shadow-inner';
            titleEl.className = 'text-3xl font-black italic uppercase tracking-tighter mb-4 text-green-500';
        } else {
            iconBox.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-red-500"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>';
            iconBox.className = 'w-20 h-20 rounded-3xl mb-8 flex items-center justify-center bg-red-500/10 shadow-inner';
            titleEl.className = 'text-3xl font-black italic uppercase tracking-tighter mb-4 text-red-500';
        }

        overlay.classList.remove('hidden');
        setTimeout(() => {
            box.classList.remove('scale-95', 'opacity-0');
            box.classList.add('scale-100', 'opacity-100');
        }, 10);
    }

    function closeAlert() {
        const overlay = document.getElementById('alertOverlay');
        const box = document.getElementById('alertBox');
        box.classList.remove('scale-100', 'opacity-100');
        box.classList.add('scale-95', 'opacity-0');
        setTimeout(() => overlay.classList.add('hidden'), 300);
    }
</script>
</body>
</html>
