<?php
// student_dashboard.php
require_once 'db_connect.php';
session_start();

// Role-specific Authentication Guard
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];

// Handle Book Appointment Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_appointment'])) {
    $faculty_id = $_POST['faculty_id'] ?? '';
    $reason = $_POST['reason'] ?? '';

    if (!empty($faculty_id) && !empty($reason)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO appointments (student_id, faculty_id, reason, status) VALUES (?, ?, ?, 'Pending')");
            $stmt->execute([$student_id, $faculty_id, $reason]);
            header("Location: student_dashboard.php?success=booked");
            exit();
        } catch (PDOException $e) {
            $error = "Booking failed: " . $e->getMessage();
        }
    }
}

// Fetch Active Appointment
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as faculty_name 
        FROM appointments a 
        JOIN users u ON a.faculty_id = u.user_id 
        WHERE a.student_id = ? AND a.status NOT IN ('Completed', 'Cancelled', 'No-Show')
        ORDER BY a.created_at DESC LIMIT 1
    ");
    $stmt->execute([$student_id]);
    $active_appointment = $stmt->fetch();

    // Fetch All Faculty for Modal with Status
    $faculty_stmt = $pdo->prepare("SELECT user_id, full_name, current_status FROM users WHERE role = 'Faculty'");
    $faculty_stmt->execute();
    $faculties = $faculty_stmt->fetchAll();

    // Mock/Simple Queue Logic
    // In a real system, 'Current Serving' would be the first 'Active' appointment
    $serving_stmt = $pdo->prepare("SELECT count(*) as count FROM appointments WHERE status = 'Active'");
    $serving_stmt->execute();
    $serving_count = $serving_stmt->fetch()['count'];
    
    // Student's position (count pending/accepted before them)
    $queue_pos = 0;
    if ($active_appointment) {
        $pos_stmt = $pdo->prepare("
            SELECT count(*) + 1 as pos 
            FROM appointments 
            WHERE status IN ('Pending', 'Accepted', 'Active') 
            AND created_at < ?
        ");
        $pos_stmt->execute([$active_appointment['created_at']]);
        $queue_pos = $pos_stmt->fetch()['pos'];
    }

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
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script>
        // 1. Immediate Theme Application (Iwas white flash)
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
        window.tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                    colors: {
                        slate: { 900: '#0f172a', 950: '#020617' },
                        indigo: { 500: '#6366f1', 600: '#4f46e5' },
                        violet: { 500: '#8b5cf6', 600: '#7c3aed' }
                    }
                }
            }
        }
    </script>

    <!-- Fixed Style Tag for Tailwind CDN -->
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
            
            .glow-card {
                @apply relative overflow-hidden;
            }
        }

        /* Custom Glow Effect for Student Cards */
        .glow-card::after {
            content: '';
            @apply absolute pointer-events-none;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.1) 0%, transparent 70%);
            z-index: 0;
        }

        .dark .glow-card::after {
            background: radial-gradient(circle, rgba(139, 92, 246, 0.15) 0%, transparent 70%);
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen">

    <!-- Header -->
    <nav class="glass sticky top-0 z-50 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-600 flex items-center justify-center">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z" /><path d="m15 13-3-3-3 3M12 10v9" /></svg>
            </div>
            <span class="font-bold text-xl tracking-tight hidden sm:block">AAS</span>
        </div>
        
        <div class="flex items-center gap-4">
            <button onclick="toggleTheme()" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors" title="Toggle Theme">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
            </button>
            <div class="text-right mr-2">
                <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest">Student</p>
                <p class="text-sm font-bold text-slate-600 dark:text-slate-200"><?php echo htmlspecialchars($student_name); ?></p>
            </div>
            <a href="logout.php" class="px-4 py-2 rounded-xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 text-slate-600 dark:text-slate-300 text-sm font-semibold transition-colors flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-8">
        
        <!-- Waitlist Confirmation Banner (Hidden by default) -->
        <div id="waitlist-alert" class="hidden mb-8 p-6 rounded-[2rem] bg-gradient-to-r from-violet-600/20 to-indigo-600/20 border border-violet-500/30 shadow-[0_0_40px_rgba(139,92,246,0.15)] animate-bounce-subtle">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-full bg-violet-500 flex items-center justify-center animate-pulse">
                        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><path d="m9 12 2 2 4-4"/></svg>
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-white">Your slot is now open!</h3>
                        <p class="text-sm text-violet-200/70">A faculty member is ready to see you. Confirm your slot now.</p>
                    </div>
                </div>
                <div class="flex items-center gap-6">
                    <div class="text-center">
                        <p class="text-[10px] uppercase font-bold tracking-tighter text-violet-300/50 mb-1">Expires in</p>
                        <div id="timer" class="text-2xl font-mono font-bold text-white">05:00</div>
                    </div>
                    <button class="px-8 py-3 rounded-2xl bg-white text-violet-600 font-bold hover:bg-violet-50 hover:scale-105 transition-all shadow-xl">
                        Confirm Slot
                    </button>
                    <button onclick="document.getElementById('waitlist-alert').classList.add('hidden')" class="p-2 text-violet-300 hover:text-white transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </div>
        </div>

        <!-- Hero Section: Queue Visibility -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">
            <!-- Queue Card -->
            <div class="lg:col-span-2 glass rounded-[2.5rem] p-8 md:p-12 glow-card relative group">
                <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/5 blur-[100px] -mr-32 -mt-32"></div>
                
                <div class="relative z-10 flex flex-col md:flex-row justify-between items-start md:items-center gap-8">
                    <div>
                        <span class="px-3 py-1 rounded-full bg-indigo-500/10 text-indigo-400 text-xs font-bold uppercase tracking-widest border border-indigo-500/20 mb-4 inline-block">Live System</span>
                        <h2 class="text-4xl font-bold tracking-tight mb-2">Real-Time Queue</h2>
                        <p class="text-slate-400 max-w-sm">Keep track of your appointment status and current serving number.</p>
                    </div>
                    
                    <div class="flex gap-6 w-full md:w-auto">
                        <div class="flex-1 md:w-32 glass bg-slate-900/40 p-6 rounded-3xl border-slate-700/50 text-center">
                            <p class="text-xs font-semibold text-slate-500 uppercase mb-2">Serving</p>
                            <p id="serving_no" class="text-3xl font-bold text-indigo-400"><?php echo sprintf("%03d", $serving_count); ?></p>
                        </div>
                        <div class="flex-1 md:w-32 glass bg-indigo-600/10 p-6 rounded-3xl border-indigo-500/30 text-center ring-2 ring-indigo-500/10">
                            <p class="text-xs font-semibold text-indigo-300 uppercase mb-2">Your No.</p>
                            <p id="your_no" class="text-3xl font-bold text-white"><?php echo $queue_pos > 0 ? sprintf("%03d", $queue_pos) : "---"; ?></p>
                        </div>
                    </div>
                </div>

                <div class="mt-12 pt-8 border-t border-slate-700/50 grid grid-cols-2 sm:grid-cols-4 gap-6">
                    <div class="text-center">
                        <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">Estimated Wait</p>
                        <p id="estimated_wait" class="text-lg font-bold">~<?php echo $queue_pos * 15; ?> min</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">Queue Status</p>
                        <p class="text-lg font-bold text-green-400">Moving</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">Next Call From</p>
                        <p class="text-lg font-bold">Admin-S1</p>
                    </div>
                    <div class="text-center">
                        <p class="text-[10px] text-slate-500 uppercase font-bold mb-1">Active Rooms</p>
                        <p class="text-lg font-bold">04/06</p>
                    </div>
                </div>
            </div>

            <!-- Profile/Summary Card -->
            <div class="glass rounded-[2.5rem] p-8 flex flex-col justify-between">
                <div>
                    <h3 class="text-xl font-bold mb-6">Active Appointment</h3>
                    <?php if ($active_appointment): ?>
                        <div class="space-y-6">
                            <div class="flex items-start gap-4">
                                <div class="w-12 h-12 rounded-2xl bg-slate-800 flex items-center justify-center text-slate-400 font-bold shrink-0">
                                    <?php echo strtoupper(substr($active_appointment['faculty_name'], 0, 2)); ?>
                                </div>
                                <div class="overflow-hidden">
                                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Faculty Member</p>
                                    <p class="font-bold text-slate-100 truncate"><?php echo htmlspecialchars($active_appointment['faculty_name']); ?></p>
                                </div>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-1">Reason</p>
                                <p class="text-sm text-slate-400 italic line-clamp-2">"<?php echo htmlspecialchars($active_appointment['reason']); ?>"</p>
                            </div>
                            <div class="flex items-center justify-between p-4 bg-slate-900/50 rounded-2xl border border-slate-700/50">
                                <span class="text-xs font-bold text-slate-400 uppercase tracking-widest">Status</span>
                                <span class="px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-tighter 
                                    <?php 
                                        switch($active_appointment['status']) {
                                            case 'Pending': echo 'bg-yellow-500/10 text-yellow-500 border border-yellow-500/20'; break;
                                            case 'Accepted': echo 'bg-green-500/10 text-green-500 border border-green-500/20'; break;
                                            case 'Active': echo 'bg-indigo-500/10 text-indigo-500 border border-indigo-500/20 animate-pulse'; break;
                                            case 'Waitlist': echo 'bg-violet-500/10 text-violet-500 border border-violet-500/20'; break;
                                            default: echo 'bg-slate-500/10 text-slate-500 border border-slate-500/20'; break;
                                        }
                                    ?>">
                                    <?php echo htmlspecialchars($active_appointment['status']); ?>
                                </span>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-10 opacity-50">
                            <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="mx-auto mb-4 text-slate-600"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            <p class="text-sm font-medium">No active appointments found.</p>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="mt-8">
                    <button onclick="document.getElementById('booking-modal').classList.remove('hidden')" class="w-full py-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 font-bold shadow-lg shadow-indigo-600/20 transition-all flex items-center justify-center gap-3">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Book Appointment
                    </button>
                    <p class="text-center text-[10px] text-slate-500 font-bold uppercase tracking-widest mt-4">One booking per session limit</p>
                </div>
            </div>
        </div>

        <!-- Appointment History Section -->
        <div class="mt-12 glass rounded-[2.5rem] overflow-hidden">
            <div class="px-8 py-6 border-b border-slate-700/50 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">My Appointment History</h3>
                    <p class="text-xs text-slate-500 font-medium uppercase tracking-wider mt-1">Track your previous and upcoming visits</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-500 animate-pulse"></span>
                    <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Real-time sync</span>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-900/40">
                            <th class="px-8 py-4 text-[10px] font-black text-slate-500 uppercase tracking-widest">Faculty Name</th>
                            <th class="px-8 py-4 text-[10px] font-black text-slate-500 uppercase tracking-widest">Reason</th>
                            <th class="px-8 py-4 text-[10px] font-black text-slate-500 uppercase tracking-widest">Date & Time</th>
                            <th class="px-8 py-4 text-[10px] font-black text-slate-500 uppercase tracking-widest text-center">Status</th>
                            <th class="px-8 py-4 text-[10px] font-black text-slate-500 uppercase tracking-widest text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-800/50">
                        <?php if (empty($appointment_history)): ?>
                            <tr>
                                <td colspan="5" class="px-8 py-12 text-center text-slate-500 italic">
                                    No appointment records found.
                                </td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach($appointment_history as $history): ?>
                            <tr class="hover:bg-slate-800/20 transition-colors">
                                <td class="px-8 py-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-8 h-8 rounded-lg bg-slate-800 flex items-center justify-center text-[10px] font-black text-slate-400">
                                            <?php echo strtoupper(substr($history['faculty_name'], 0, 2)); ?>
                                        </div>
                                        <span class="font-bold text-slate-200"><?php echo htmlspecialchars($history['faculty_name']); ?></span>
                                    </div>
                                </td>
                                <td class="px-8 py-6">
                                    <p class="text-sm text-slate-400 max-w-xs truncate" title="<?php echo htmlspecialchars($history['reason']); ?>">
                                        <?php echo htmlspecialchars($history['reason']); ?>
                                    </p>
                                </td>
                                <td class="px-8 py-6">
                                    <div class="flex flex-col">
                                        <span class="text-sm font-semibold text-slate-300"><?php echo date('M d, Y', strtotime($history['created_at'])); ?></span>
                                        <span class="text-xs font-mono text-slate-500 uppercase"><?php echo date('h:i A', strtotime($history['created_at'])); ?></span>
                                    </div>
                                </td>
                                <td class="px-8 py-6 text-center">
                                    <span class="px-3 py-1 rounded-full text-[9px] font-black uppercase tracking-tighter 
                                        <?php 
                                            switch($history['status']) {
                                                case 'Pending': echo 'bg-yellow-500/10 text-yellow-500 border border-yellow-500/20'; break;
                                                case 'Accepted': echo 'bg-blue-500/10 text-blue-500 border border-blue-500/20'; break;
                                                case 'Active': echo 'bg-indigo-500/10 text-indigo-500 border border-indigo-500/20'; break;
                                                case 'Completed': echo 'bg-green-500/10 text-green-500 border border-green-500/20'; break;
                                                case 'Cancelled': echo 'bg-red-500/10 text-red-500 border border-red-500/20'; break;
                                                case 'No-Show': echo 'bg-slate-700 text-slate-400 border border-slate-600'; break;
                                                default: echo 'bg-slate-800 text-slate-500'; break;
                                            }
                                        ?>">
                                        <?php echo $history['status']; ?>
                                    </span>
                                </td>
                                <td class="px-8 py-6 text-right">
                                    <?php if ($history['status'] === 'Pending'): ?>
                                        <a href="cancel_appointment.php?app_id=<?php echo $history['app_id']; ?>" 
                                           onclick="return confirm('Are you sure you want to cancel this appointment?')"
                                           class="text-xs font-bold text-red-400 hover:text-red-300 transition-colors uppercase tracking-widest decoration-red-900/50 underline-offset-4 underline">
                                            Cancel
                                        </a>
                                    <?php else: ?>
                                        <span class="text-xs font-bold text-slate-700 uppercase tracking-widest cursor-default">Disabled</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- System Logs / History -->
        <div class="glass rounded-[2rem] p-8">
             <div class="flex items-center justify-between mb-8">
                <h3 class="text-xl font-bold">Recent Updates</h3>
                <button class="text-xs font-bold text-indigo-400 uppercase tracking-widest hover:text-white transition-colors">View All Logs</button>
             </div>
             <div class="space-y-4">
                 <div class="p-4 rounded-2xl hover:bg-slate-800/50 transition-colors border border-transparent hover:border-slate-700/50 flex items-center justify-between">
                     <div class="flex items-center gap-4">
                         <div class="w-10 h-10 rounded-full bg-slate-900 border border-slate-700 flex items-center justify-center text-slate-500">
                             <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22c5.523 0 10-4.477 10-10S17.523 2 12 2 2 6.477 2 12s4.477 10 10 10z"/><polyline points="12 6 12 12 16 14"/></svg>
                         </div>
                         <div>
                             <p class="text-sm font-bold">Queue Shifted</p>
                             <p class="text-xs text-slate-500">Number 012 has been called to Room C.</p>
                         </div>
                     </div>
                     <span class="text-[10px] font-bold text-slate-600 uppercase">2 mins ago</span>
                 </div>
                 <!-- More logs can be dynamically pulled here -->
             </div>
        </div>
    </main>

    <!-- Booking Modal -->
    <div id="booking-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4">
        <div class="absolute inset-0 bg-slate-950/80 backdrop-blur-md" onclick="document.getElementById('booking-modal').classList.add('hidden')"></div>
        <div class="max-w-md w-full glass rounded-[2.5rem] p-10 relative z-10 shadow-2xl animate-in zoom-in-95 duration-200">
            <h2 class="text-2xl font-bold mb-2">New Appointment</h2>
            <p class="text-slate-400 text-sm mb-8">Schedule a meeting with your preferred faculty member.</p>

            <form action="student_dashboard.php" method="POST" class="space-y-6">
                <!-- Faculty Selection -->
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider ml-1">Select Faculty</label>
                    <select name="faculty_id" id="faculty-select" onchange="checkFacultyStatus(this)" class="w-full bg-slate-900/50 border border-slate-700/50 rounded-2xl py-4 px-4 text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all appearance-none" required>
                        <option value="">-- Choose Faculty --</option>
                        <?php foreach($faculties as $faculty): ?>
                            <?php 
                                $is_unavailable = $faculty['current_status'] !== 'Available';
                                $status_color = $faculty['current_status'] === 'Available' ? 'text-green-400' : ($faculty['current_status'] === 'On Break' ? 'text-yellow-400' : 'text-red-400');
                            ?>
                            <option 
                                value="<?php echo $faculty['user_id']; ?>" 
                                data-status="<?php echo $faculty['current_status']; ?>"
                                class="<?php echo $status_color; ?>"
                            >
                                <?php echo htmlspecialchars($faculty['full_name']); ?> 
                                (<?php echo $faculty['current_status']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p id="availability-warning" class="hidden text-[10px] font-bold text-red-400 uppercase tracking-tight mt-2 ml-1 flex items-center gap-1">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Currently unavailable for booking.
                    </p>
                </div>

                <!-- Reason Selection -->
                <div class="space-y-2">
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wider ml-1">Reason for Visit</label>
                    <textarea 
                        name="reason" 
                        rows="4" 
                        class="w-full bg-slate-900/50 border border-slate-700/50 rounded-2xl py-4 px-4 text-slate-200 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all resize-none"
                        placeholder="Briefly explain your concern (e.g., Grade Inquiry, Thesis Consultation)"
                        required
                    ></textarea>
                </div>

                <div class="flex flex-col gap-3 pt-4">
                    <button type="submit" name="book_appointment" id="submit-booking-btn" class="w-full py-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 font-bold shadow-lg shadow-indigo-600/10 transition-all">
                        Request Appointment
                    </button>
                    <button type="button" onclick="document.getElementById('booking-modal').classList.add('hidden')" class="w-full py-4 rounded-2xl bg-slate-800 hover:bg-slate-700 font-bold transition-all">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Live Polling & UI Logic Script -->
    <script>
        function checkFacultyStatus(select) {
            const selectedOption = select.options[select.selectedIndex];
            const status = selectedOption.getAttribute('data-status');
            const warning = document.getElementById('availability-warning');
            const submitBtn = document.getElementById('submit-booking-btn');

            if (status && status !== 'Available') {
                warning.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                warning.classList.add('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }

        // Check for updates every 10 seconds
        setInterval(fetchQueueStatus, 10000);

        async function fetchQueueStatus() {
            try {
                const response = await fetch('api/get_current_queue.php');
                const data = await response.json();
                
                if (data.success) {
                    // Update main counters
                    document.getElementById('serving_no').textContent = data.serving_no;
                    document.getElementById('your_no').textContent = data.your_no;
                    document.getElementById('estimated_wait').textContent = `~${data.estimated_wait} min`;

                    // Check for waitlist status change to trigger alert
                    if (data.status === 'Waitlist') {
                        const alert = document.getElementById('waitlist-alert');
                        if (alert.classList.contains('hidden')) {
                            alert.classList.remove('hidden');
                            startTimer(300, document.getElementById('timer'));
                        }
                    } else if (data.status === 'Active') {
                        // Optionally refresh if status changes to Active to show room info etc.
                        // For now we'll just keep the polling silent
                    }
                }
            } catch (error) {
                console.error('Queue polling failed:', error);
            }
        }

        // Timer logic
        function startTimer(duration, display) {
            var timer = duration, minutes, seconds;
            var countdown = setInterval(function () {
                minutes = parseInt(timer / 60, 10);
                seconds = parseInt(timer % 60, 10);

                minutes = minutes < 10 ? "0" + minutes : minutes;
                seconds = seconds < 10 ? "0" + seconds : seconds;

                display.textContent = minutes + ":" + seconds;

                if (--timer < 0) {
                    clearInterval(countdown);
                    display.textContent = "00:00";
                    // Alert expired
                }
            }, 1000);
        }

        // Demo logic: Initial check
        <?php if ($active_appointment && $active_appointment['status'] === 'Waitlist'): ?>
        document.getElementById('waitlist-alert').classList.remove('hidden');
        startTimer(300, document.getElementById('timer'));
        <?php endif; ?>
    </script>
</body>
</html>
