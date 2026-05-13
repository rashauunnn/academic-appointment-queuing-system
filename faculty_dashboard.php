<?php
// faculty_dashboard.php
require_once 'db_connect.php';
session_start();

// Role-specific Authentication Guard
if (!isset($_SESSION['faculty_id'])) {
    header("Location: login.php");
    exit();
}

$faculty_id = $_SESSION['faculty_id'];
$faculty_name = $_SESSION['faculty_name'];

// Handle Action (Call, Complete, Cancel, No-Show, Call Next, Update Status)
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    $app_id = $_GET['app_id'] ?? null;
    $status_val = $_GET['status_val'] ?? null;

    try {
        switch ($action) {
            case 'update_status':
                if ($status_val) {
                    $stmt = $pdo->prepare("UPDATE users SET current_status = ? WHERE user_id = ?");
                    $stmt->execute([$status_val, $faculty_id]);
                }
                break;
            case 'call_next':
                // Find oldest Pending appointment for this faculty
                $next_stmt = $pdo->prepare("
                    SELECT app_id FROM appointments 
                    WHERE faculty_id = ? AND status IN ('Pending', 'Accepted') 
                    ORDER BY created_at ASC LIMIT 1
                ");
                $next_stmt->execute([$faculty_id]);
                $next_app = $next_stmt->fetch();
                
                if ($next_app) {
                    $app_id = $next_app['app_id'];
                    // Update status to Active
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Active' WHERE app_id = ?");
                    $stmt->execute([$app_id]);
                    // Log the call time (called_at/call_time)
                    $log_stmt = $pdo->prepare("INSERT INTO queue_logs (app_id, call_time, start_time) VALUES (?, NOW(), NOW())");
                    $log_stmt->execute([$app_id]);
                }
                break;
            case 'call':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Active' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                    $log_stmt = $pdo->prepare("INSERT INTO queue_logs (app_id, call_time, start_time) VALUES (?, NOW(), NOW()) ON DUPLICATE KEY UPDATE call_time = NOW(), start_time = NOW()");
                    $log_stmt->execute([$app_id]);
                }
                break;
            case 'complete':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'Completed' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                    // Log completion and calculate duration in minutes
                    $log_stmt = $pdo->prepare("UPDATE queue_logs SET end_time = NOW(), duration = TIMESTAMPDIFF(MINUTE, start_time, NOW()) WHERE app_id = ?");
                    $log_stmt->execute([$app_id]);
                }
                break;
            case 'noshow':
                if ($app_id) {
                    $stmt = $pdo->prepare("UPDATE appointments SET status = 'No-Show' WHERE app_id = ? AND faculty_id = ?");
                    $stmt->execute([$app_id, $faculty_id]);
                }
                break;
        }
        header("Location: faculty_dashboard.php?msg=success");
        exit();
    } catch (PDOException $e) {
        $error = "Update failed: " . $e->getMessage();
    }
}

// Fetch Appointments
try {
    // Fetch Faculty info for status
    $faculty_stmt = $pdo->prepare("SELECT current_status FROM users WHERE user_id = ?");
    $faculty_stmt->execute([$faculty_id]);
    $faculty_info = $faculty_stmt->fetch();
    $current_status = $faculty_info['current_status'] ?? 'Available';

    // Current Active (if any)
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as student_name, u.school_id 
        FROM appointments a 
        JOIN users u ON a.student_id = u.user_id 
        WHERE a.faculty_id = ? AND a.status = 'Active' 
        LIMIT 1
    ");
    $stmt->execute([$faculty_id]);
    $active_app = $stmt->fetch();

    // Pending / Accepted List
    $list_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as student_name, u.school_id 
        FROM appointments a 
        JOIN users u ON a.student_id = u.user_id 
        WHERE a.faculty_id = ? AND a.status IN ('Pending', 'Accepted') 
        ORDER BY a.created_at ASC
    ");
    $list_stmt->execute([$faculty_id]);
    $appointments = $list_stmt->fetchAll();

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
    
    <script>
        // 1. Immediate Theme Application (Iwas "white flash")
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        })();

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

        // 3. Tailwind Configuration
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: {
                        slate: { 900: '#0f172a', 950: '#020617' },
                        violet: { 500: '#8b5cf6', 600: '#7c3aed' }
                    }
                }
            }
        }
    </script>

    <!-- CRITICAL: Added type="text/tailwindcss" -->
    <style type="text/tailwindcss">
        @layer base {
            body { 
                @apply bg-slate-50 text-slate-900 transition-colors duration-300; 
            }
            .dark body { 
                @apply bg-slate-950 text-slate-100; 
            }
        }

        @layer components {
            .glass { 
                @apply bg-white/70 backdrop-blur-xl border border-slate-200/50 shadow-sm transition-all duration-300; 
            }
            .dark .glass { 
                @apply bg-slate-900/70 border-slate-800/50 shadow-none; 
            }
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen">
    <!-- Header -->
    <nav class="glass sticky top-0 z-50 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-violet-600 to-indigo-600 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z"/></svg>
            </div>
            <span class="font-bold text-xl tracking-tight">Faculty Portal</span>
        </div>
        <div class="flex items-center gap-4">
            <button onclick="toggleTheme()" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors" title="Toggle Theme">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <div class="flex items-center gap-2 mr-4 bg-slate-100 dark:bg-slate-900/50 p-1.5 rounded-xl border border-slate-200 dark:border-slate-700/50">
                <span class="text-[10px] font-bold text-slate-500 uppercase ml-2 mr-1">Status</span>
                <select onchange="window.location.href='faculty_dashboard.php?action=update_status&status_val=' + this.value" class="bg-white dark:bg-slate-800 text-xs font-bold text-slate-600 dark:text-slate-200 border-none rounded-lg py-1 px-3 focus:ring-0 cursor-pointer">
                    <option value="Available" <?php echo $current_status === 'Available' ? 'selected' : ''; ?>>Available</option>
                    <option value="On Break" <?php echo $current_status === 'On Break' ? 'selected' : ''; ?>>On Break</option>
                    <option value="Out of Office" <?php echo $current_status === 'Out of Office' ? 'selected' : ''; ?>>Out of Office</option>
                </select>
                <div class="w-2 h-2 rounded-full mr-2 <?php 
                    echo $current_status === 'Available' ? 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]' : 
                        ($current_status === 'On Break' ? 'bg-yellow-500 shadow-[0_0_8px_rgba(234,179,8,0.5)]' : 'bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.5)]'); 
                ?>"></div>
            </div>
            <div class="text-right mr-2">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest">Logged In as</p>
                <p class="text-sm font-bold text-slate-600 dark:text-slate-200"><?php echo htmlspecialchars($faculty_name); ?></p>
            </div>
            <a href="logout.php" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-sm font-semibold transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        <!-- Dashboard Header -->
        <div class="mb-10">
            <h1 class="text-3xl font-bold mb-2">Queue Management</h1>
            <p class="text-slate-400">Handle your upcoming appointments and track student arrivals.</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- Left: Current Serving -->
            <div class="lg:col-span-1">
                <h2 class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4 ml-2">Now Serving</h2>
                <?php if ($active_app): ?>
                    <div class="glass rounded-[2rem] p-8 border-violet-500/30 relative overflow-hidden ring-2 ring-violet-500/20 shadow-[0_0_50px_rgba(139,92,246,0.1)]">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-violet-600/10 blur-3xl -mr-16 -mt-16"></div>
                        <div class="relative z-10">
                            <div class="flex items-center gap-4 mb-6">
                                <div class="w-16 h-16 rounded-2xl bg-violet-500 flex items-center justify-center text-2xl font-bold text-white shadow-lg">
                                    <?php echo strtoupper(substr($active_app['student_name'], 0, 1)); ?>
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold"><?php echo htmlspecialchars($active_app['student_name']); ?></h3>
                                    <p class="text-sm text-slate-400"><?php echo htmlspecialchars($active_app['school_id']); ?></p>
                                </div>
                            </div>
                            <div class="mb-8 space-y-4">
                                <div class="p-4 rounded-xl bg-slate-900/50 border border-slate-700/50">
                                    <p class="text-[10px] font-bold text-slate-500 uppercase mb-1">Reason</p>
                                    <p class="text-sm text-slate-300"><?php echo htmlspecialchars($active_app['reason']); ?></p>
                                </div>
                                <div class="flex items-center justify-between text-xs font-bold text-slate-500 px-1">
                                    <span>Session Started</span>
                                    <span class="text-violet-400 animate-pulse">Live</span>
                                </div>
                            </div>
                            <div class="flex flex-col gap-3">
                                <a href="faculty_dashboard.php?action=complete&app_id=<?php echo $active_app['app_id']; ?>" class="w-full py-4 rounded-2xl bg-violet-600 hover:bg-violet-500 text-center font-bold transition-all shadow-lg shadow-violet-600/20">
                                    Complete Session
                                </a>
                                <a href="faculty_dashboard.php?action=noshow&app_id=<?php echo $active_app['app_id']; ?>" class="w-full py-4 rounded-2xl bg-slate-800 hover:bg-slate-700 text-center font-bold text-slate-300 transition-all">
                                    Mark as No-Show
                                </a>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="glass rounded-[2rem] p-12 text-center border-dashed border-slate-700/50">
                        <div class="w-16 h-16 rounded-full bg-slate-800/50 flex items-center justify-center mx-auto mb-4 text-slate-600">
                             <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <p class="font-bold text-slate-400 mb-1">No Active Session</p>
                        <p class="text-xs text-slate-500 mb-6">Call the next student from the list.</p>
                        <a href="faculty_dashboard.php?action=call_next" class="inline-flex items-center gap-2 px-6 py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-sm font-bold transition-all shadow-lg shadow-indigo-600/20">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            Call Next Student
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Right: Queue List -->
            <div class="lg:col-span-2">
                <h2 class="text-xs font-bold text-slate-500 uppercase tracking-widest mb-4 ml-4">Waiting List (<?php echo count($appointments); ?>)</h2>
                <div class="glass rounded-[2rem] overflow-hidden">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-slate-700/50 bg-slate-900/40">
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Student</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Reason</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Status</th>
                                <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800/50">
                            <?php if (empty($appointments)): ?>
                                <tr>
                                    <td colspan="4" class="px-6 py-12 text-center text-slate-500">
                                        No pending appointments found for today.
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($appointments as $app): ?>
                                <tr class="hover:bg-slate-800/30 transition-colors">
                                    <td class="px-6 py-6">
                                        <p class="font-bold text-slate-200"><?php echo htmlspecialchars($app['student_name']); ?></p>
                                        <p class="text-[10px] text-slate-500 font-mono"><?php echo htmlspecialchars($app['school_id']); ?></p>
                                    </td>
                                    <td class="px-6 py-6">
                                        <p class="text-sm text-slate-400 italic line-clamp-1">"<?php echo htmlspecialchars($app['reason']); ?>"</p>
                                    </td>
                                    <td class="px-6 py-6">
                                        <span class="px-3 py-1 rounded-full text-[10px] font-black tracking-tighter uppercase <?php echo $app['status'] === 'Pending' ? 'bg-yellow-500/10 text-yellow-500' : 'bg-green-500/10 text-green-500'; ?>">
                                            <?php echo htmlspecialchars($app['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-6">
                                        <?php if (!$active_app): ?>
                                            <a href="faculty_dashboard.php?action=call&app_id=<?php echo $app['app_id']; ?>" class="px-5 py-2 rounded-xl bg-violet-600 hover:bg-violet-500 text-xs font-bold transition-all shadow-lg shadow-violet-600/10">
                                                Call Student
                                            </a>
                                        <?php else: ?>
                                            <button disabled class="px-5 py-2 rounded-xl bg-slate-800 text-slate-600 text-xs font-bold cursor-not-allowed">
                                                Call Locked
                                            </button>
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
</body>
</html>
