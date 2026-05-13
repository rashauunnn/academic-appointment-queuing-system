<?php
// admin_dashboard.php
require_once 'db_connect.php';
session_start();

// Role-specific Authentication Guard
if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit();
}

// Silent Migration: Ensure current_status column exists in users table
try {
    $pdo->query("SELECT current_status FROM users LIMIT 1");
} catch (Exception $e) {
    try {
        $pdo->exec("ALTER TABLE users ADD COLUMN current_status VARCHAR(50) DEFAULT 'Available'");
    } catch (Exception $e2) {
        // Silently fail if something else happened (e.g. table locked)
    }
}

$admin_id = $_SESSION['admin_id'];
$admin_name = $_SESSION['admin_name'];

try {
    // 1. Statistical Overview Cards
    $stats_stmt = $pdo->query("SELECT 
        (SELECT COUNT(*) FROM users WHERE role = 'Student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'Faculty') as total_faculty,
        (SELECT COUNT(*) FROM appointments WHERE DATE(created_at) = CURDATE()) as apps_today,
        (SELECT COUNT(*) FROM appointments WHERE status = 'Completed') as completed_sessions
    ");
    $stats = $stats_stmt->fetch();

    // 2. Peak Hours Analysis (Top 3 Busiest Hours)
    $peak_stmt = $pdo->query("
        SELECT HOUR(created_at) as hr, COUNT(*) as count 
        FROM appointments 
        GROUP BY hr 
        ORDER BY count DESC 
        LIMIT 3
    ");
    $peak_hours = $peak_stmt->fetchAll();

    // 3. Recent Activity Log
    $activity_stmt = $pdo->query("
        SELECT a.*, s.full_name as student_name, f.full_name as faculty_name 
        FROM appointments a 
        JOIN users s ON a.student_id = s.user_id 
        JOIN users f ON a.faculty_id = f.user_id 
        ORDER BY a.created_at DESC LIMIT 10
    ");
    $recent_activity = $activity_stmt->fetchAll();

    // 4. User Management / Faculty Statuses
    $user_list_stmt = $pdo->query("
        SELECT user_id, full_name, school_id, current_status 
        FROM users 
        WHERE role = 'Faculty'
        ORDER BY full_name ASC
    ");
    $faculty_users = $user_list_stmt->fetchAll();

} catch (PDOException $e) {
    die("Data Load Error: " . $e->getMessage());
}

// Helper to format hour
function formatHour($hour) {
    $h = (int)$hour;
    $suffix = ($h >= 12) ? 'PM' : 'AM';
    $display_h = ($h % 12 == 12 || $h % 12 == 0) ? 12 : $h % 12;
    $next_h = (($h + 1) % 12 == 12 || ($h + 1) % 12 == 0) ? 12 : ($h + 1) % 12;
    $next_suffix = (($h + 1) >= 12 && ($h + 1) < 24) ? 'PM' : 'AM';
    if ($h == 11) $next_suffix = 'PM';
    if ($h == 23) $next_suffix = 'AM';
    return "{$display_h}:00 {$suffix} - {$next_h}:00 {$next_suffix}";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Command Center | AAS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
 
    // 1. Check theme and apply immediately
    const theme = localStorage.getItem('theme');
    if (theme === 'dark' || (!theme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        document.documentElement.classList.add('dark');
    } else {
        document.documentElement.classList.remove('dark');
    }

    // 2. Toggle Function
    function toggleTheme() {
        const html = document.documentElement;
        if (html.classList.contains('dark')) {
            html.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        } else {
            html.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        }
    }

    // 3. Tailwind Config
    window.tailwind.config = {
        darkMode: 'class',
        theme: {
            extend: {
                fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                colors: {
                    slate: { 900: '#0f172a', 950: '#020617' },
                    indigo: { 600: '#4f46e5', 500: '#6366f1' }
                }
            }
        }
    }
</script>
    <style type="text/tailwindcss">
    @layer base {
        /* Default: Light Mode */
        body { 
            @apply bg-slate-50 text-slate-900 transition-colors duration-300; 
        }

        /* Dark Mode Override */
        .dark body { 
            @apply bg-slate-950 text-slate-100; 
        }
    }

    @layer components {
        /* Glass Effect Utility */
        .glass { 
            @apply bg-white/70 backdrop-blur-xl border border-slate-200/50 shadow-sm transition-all duration-300; 
        }

        /* Dark Mode Glass */
        .dark .glass { 
            @apply bg-slate-900/70 border-slate-800/50 shadow-none; 
        }

        .peak-card {
            @apply bg-indigo-50 border border-indigo-200 transition-all duration-300;
        }

        .dark .peak-card {
            @apply bg-indigo-900/10 border-indigo-500/20;
        }
    }
</style>
</head>
<body class="font-sans antialiased min-h-screen">
    <!-- Header -->
    <nav class="glass sticky top-0 z-50 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-500 to-violet-600 flex items-center justify-center">
                 <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <span class="font-bold text-xl tracking-tight dark:text-white">Admin Console</span>
        </div>
        <div class="flex items-center gap-4">
            <!-- Theme Toggle Button -->
            <button onclick="toggleTheme()" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors" title="Toggle Theme">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>

            <div class="hidden sm:flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-100 dark:bg-slate-800/50 border border-slate-200 dark:border-slate-700/50">
                <div class="w-2 h-2 rounded-full bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]"></div>
                <p class="text-xs font-bold text-slate-600 dark:text-slate-300">System Live</p>
            </div>
            <p class="text-sm font-bold text-slate-600 dark:text-slate-200"><?php echo htmlspecialchars($admin_name); ?></p>
            <a href="logout.php" class="px-4 py-2 rounded-xl bg-red-500/10 hover:bg-red-600/20 text-red-500 text-sm font-bold transition-all">Logout</a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        
        <!-- Statistical Overview Cards -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-6 mb-12">
            <div class="glass p-7 rounded-[2.5rem] hover:scale-[1.02] transition-transform cursor-pointer group">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-3 group-hover:text-slate-400 transition-colors">Total Students</p>
                <p class="text-4xl font-bold text-slate-900 dark:text-white"><?php echo $stats['total_students']; ?></p>
                <div class="mt-4 flex items-center gap-1 text-green-500 dark:text-green-400 text-xs font-bold">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    2.4% vs last week
                </div>
            </div>
            <div class="glass p-7 rounded-[2.5rem] hover:scale-[1.02] transition-transform cursor-pointer group">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-3 group-hover:text-slate-400 transition-colors">Total Faculty</p>
                <p class="text-4xl font-bold text-slate-900 dark:text-white"><?php echo $stats['total_faculty']; ?></p>
                <p class="mt-4 text-xs font-bold text-slate-500">Active Monitoring</p>
            </div>
            <div class="glass p-7 rounded-[2.5rem] border-indigo-500/30 ring-4 ring-indigo-500/5 hover:scale-[1.02] transition-transform cursor-pointer group">
                <p class="text-[10px] font-black text-indigo-500 dark:text-indigo-400 uppercase tracking-[0.2em] mb-3 group-hover:text-indigo-400 transition-colors">Today's Apps</p>
                <p class="text-4xl font-bold text-indigo-600 dark:text-indigo-400"><?php echo $stats['apps_today']; ?></p>
                <div class="mt-4 flex items-center gap-1 text-indigo-500 dark:text-indigo-300 text-xs font-bold">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Live Traffic
                </div>
            </div>
            <div class="glass p-7 rounded-[2.5rem] hover:scale-[1.02] transition-transform cursor-pointer group">
                <p class="text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] mb-3 group-hover:text-slate-400 transition-colors">Completed Sessions</p>
                <p class="text-4xl font-bold text-green-600 dark:text-green-400"><?php echo $stats['completed_sessions']; ?></p>
                <p class="mt-4 text-xs font-bold text-slate-500">Global cumulative</p>
            </div>
        </div>

        <!-- Peak Hours Section -->
        <div class="mb-12">
            <div class="flex items-center gap-3 mb-6 px-2">
                <div class="w-8 h-8 rounded-lg bg-indigo-500/20 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg>
                </div>
                <h2 class="text-xl font-bold uppercase tracking-tight text-slate-700 dark:text-slate-300">Peak Traffic Analysis</h2>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach($peak_hours as $index => $peak): ?>
                <div class="peak-card p-6 rounded-[2rem] flex items-center justify-between group hover:border-indigo-500/50 transition-all cursor-default">
                    <div>
                        <p class="text-[10px] font-black text-indigo-500 dark:text-indigo-400 uppercase tracking-widest mb-1">Rank #<?php echo $index+1; ?></p>
                        <p class="font-bold text-slate-800 dark:text-slate-100"><?php echo formatHour($peak['hr']); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="text-2xl font-black text-slate-900 dark:text-white"><?php echo $peak['count']; ?></p>
                        <p class="text-[10px] font-bold text-slate-500 uppercase">Bookings</p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-10">
            <!-- Recent Activity Log -->
            <div>
                <div class="flex items-center justify-between mb-6 px-2">
                    <h2 class="text-xl font-bold dark:text-white">Recent System Activity</h2>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Last 10 Actions</span>
                </div>
                <div class="glass rounded-[2.5rem] p-5">
                    <div class="space-y-3">
                        <?php foreach($recent_activity as $act): ?>
                        <div class="p-4 rounded-2xl bg-white/50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700/30 flex items-center justify-between group hover:border-slate-400 dark:hover:border-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800/40 transition-all">
                            <div class="flex items-center gap-4">
                                <div class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-slate-800 flex items-center justify-center text-slate-500 shrink-0 group-hover:scale-110 transition-transform">
                                    <?php if ($act['status'] === 'Completed'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                    <?php elseif ($act['status'] === 'Pending'): ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#eab308" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?php else: ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#4f46e5" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                                    <?php endif; ?>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="text-sm font-bold text-slate-700 dark:text-slate-200 truncate">
                                        <span class="text-indigo-600 dark:text-indigo-400"><?php echo htmlspecialchars($act['student_name']); ?></span> 
                                        <?php 
                                            if ($act['status'] === 'Pending') echo 'booked with';
                                            else if ($act['status'] === 'Completed') echo 'finished session with';
                                            else if ($act['status'] === 'Active') echo 'is now in session with';
                                            else echo 'status marked as <span class="capitalize text-slate-400">'.strtolower($act['status']).'</span> with';
                                        ?> 
                                        <span class="text-violet-600 dark:text-violet-400"><?php echo htmlspecialchars($act['faculty_name']); ?></span>
                                    </p>
                                    <p class="text-[10px] text-slate-500 uppercase font-mono mt-0.5"><?php echo date('M d, h:i A', strtotime($act['created_at'])); ?></p>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- User Management / Faculty Status Table -->
            <div>
                <div class="flex items-center justify-between mb-6 px-2">
                    <h2 class="text-xl font-bold dark:text-white">Faculty Availability Monitor</h2>
                    <button class="text-[10px] font-black text-indigo-500 uppercase tracking-widest hover:text-indigo-600 dark:hover:text-white transition-colors">Refresh Radar</button>
                </div>
                <div class="glass rounded-[2.5rem] overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-100 dark:bg-slate-900/40 border-b border-slate-200 dark:border-slate-700/50">
                                <th class="px-8 py-5 text-[10px] font-black text-slate-500 uppercase tracking-widest">Faculty Member</th>
                                <th class="px-8 py-5 text-[10px] font-black text-slate-500 uppercase tracking-widest">Current Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800/50">
                            <?php foreach($faculty_users as $fac): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition-colors group">
                                <td class="px-8 py-5">
                                    <p class="text-sm font-bold text-slate-800 dark:text-slate-200 group-hover:text-indigo-600 dark:group-hover:text-white transition-colors"><?php echo htmlspecialchars($fac['full_name']); ?></p>
                                    <p class="text-[10px] text-slate-500 font-mono uppercase mt-0.5"><?php echo htmlspecialchars($fac['school_id']); ?></p>
                                </td>
                                <td class="px-8 py-5">
                                    <div class="flex items-center gap-3">
                                        <div class="w-2.5 h-2.5 rounded-full <?php 
                                            echo $fac['current_status'] === 'Available' ? 'bg-green-500' : 
                                                ($fac['current_status'] === 'On Break' ? 'bg-yellow-500' : 'bg-red-500'); 
                                        ?>"></div>
                                        <span class="text-xs font-black uppercase tracking-wider <?php 
                                            echo $fac['current_status'] === 'Available' ? 'text-green-600 dark:text-green-400' : 
                                                ($fac['current_status'] === 'On Break' ? 'text-yellow-600 dark:text-yellow-400' : 'text-red-600 dark:text-red-400'); 
                                        ?>">
                                            <?php echo htmlspecialchars($fac['current_status']); ?>
                                        </span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Bottom Action Card -->
                <div class="mt-8 p-10 rounded-[3rem] bg-gradient-to-br from-indigo-500/5 via-slate-100/50 dark:via-slate-800/50 to-violet-500/5 border border-slate-200 dark:border-indigo-500/20 relative overflow-hidden group">
                    <h3 class="font-black text-xl mb-3 tracking-tight dark:text-white">Intelligence Oversight</h3>
                    <p class="text-sm text-slate-600 dark:text-slate-400 leading-relaxed mb-8">
                        Operational efficiency is at <b><?php 
                            $avail_count = 0; 
                            foreach($faculty_users as $f) if($f['current_status'] === 'Available') $avail_count++;
                            echo count($faculty_users) > 0 ? round(($avail_count / count($faculty_users)) * 100) : 0;
                        ?>%</b>. 
                        <?php echo $avail_count; ?> of <?php echo count($faculty_users); ?> nodes are currently accepting new entries. 
                    </p>
                    <div class="flex flex-col sm:flex-row gap-4">
                        <button class="flex-1 py-4 px-6 rounded-2xl bg-slate-200 dark:bg-white/5 hover:bg-slate-300 dark:hover:bg-white/10 text-xs font-black text-slate-700 dark:text-white uppercase tracking-widest transition-all">Broadcast Notice</button>
                        <button class="flex-1 py-4 px-6 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-xs font-black text-white uppercase tracking-widest transition-all shadow-lg shadow-indigo-600/20">Audit Database</button>
                    </div>
                </div>
            </div>
        </div>
    </main>
</body>
</html>