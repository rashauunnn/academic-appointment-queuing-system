<?php
require_once 'security_headers.php';
require_once 'session_helper.php';
require_once 'db_connect.php';

// Role Guard
check_session_role('Student');

$student_id = $_SESSION['student_id'] ?? $_SESSION['user_id'];
$student_name = $_SESSION['student_name'] ?? $_SESSION['full_name'];

// Auto-revert expired busy statuses
try {
    $now_str = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE users SET current_status = 'Available', busy_until = NULL WHERE current_status = 'Busy' AND busy_until <= ?")->execute([$now_str]);
} catch (PDOException $e) {}

// Fetch Faculty
try {
    $faculty_stmt = $pdo->prepare("SELECT user_id, full_name, current_status, busy_until, biography, specialization, office_hours, contact_number, email, social_link FROM users WHERE role = 'Faculty'");
    $faculty_stmt->execute();
    $faculties = $faculty_stmt->fetchAll();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Instructor Booking | Academic Appointment System</title>
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

    <style>
        :root {
            --accent: #4f46e5;
            --accent-glow: rgba(79, 70, 229, 0.15);
        }
        .dark {
            --accent: #6366f1;
            --accent-glow: rgba(99, 102, 241, 0.2);
        }
    </style>
    <style type="text/tailwindcss">
        @layer base {
            body { 
                @apply bg-slate-50 text-slate-900 transition-colors duration-500 overflow-x-hidden; 
            }
            .dark body { 
                @apply bg-[#06080f] text-slate-100; 
            }
        }
        @layer components {
            .glass-card { 
                @apply bg-white/80 backdrop-blur-2xl border border-slate-200/60 shadow-[0_8px_30px_rgb(0,0,0,0.04)] transition-all duration-300; 
            }
            .dark .glass-card { 
                @apply bg-[#0d121f]/80 border-white/5 shadow-none; 
            }
            .stat-badge {
                @apply px-2.5 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest border;
            }
            .input-field { 
                @apply bg-white/70 dark:bg-slate-950/70 border border-slate-200 dark:border-white/5 transition-all duration-300 rounded-2xl block w-full pl-12 pr-4 py-4 text-sm font-bold text-slate-800 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600; 
            }
            .input-field:focus { 
                @apply border-indigo-500/50 bg-white dark:bg-slate-950 outline-none ring-4 ring-indigo-500/10; 
            }
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen">

    <!-- Navbar -->
    <nav class="sticky top-0 z-[60] px-8 py-4 glass-card rounded-none border-x-0 border-t-0 shadow-xl flex items-center justify-between">
        <div class="flex items-center gap-6">
            <a href="student_dashboard.php" class="p-2.5 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-500 hover:text-indigo-500 hover:bg-slate-200/50 dark:hover:bg-white/10 transition-all border border-slate-200 dark:border-white/5" title="Return to Terminal Dashboard">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-2xl bg-indigo-600 flex items-center justify-center text-white shadow-lg shadow-indigo-600/20">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z"/><path d="m15 13-3-3-3 3M12 10v9"/></svg>
                </div>
                <div>
                    <h1 class="text-sm font-black uppercase tracking-[0.3em]">ConsultCare</h1>
                    <p class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Instructor Booking System</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-5">
            <button onclick="toggleTheme()" class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-500 hover:text-indigo-500 transition-colors flex items-center justify-center border border-slate-200 dark:border-white/5">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
            </button>
            
            <div class="flex items-center gap-4 pl-5 border-l border-slate-200 dark:border-white/10">
                <div class="text-right hidden sm:block">
                    <p class="text-[10px] font-black text-indigo-500 uppercase tracking-widest text-right">Student Agent</p>
                    <p class="text-xs font-black text-slate-700 dark:text-slate-200 select-none uppercase tracking-wider"><?php echo htmlspecialchars($student_name); ?></p>
                </div>
                <div class="w-10 h-10 rounded-xl bg-indigo-500/10 border border-indigo-500/20 flex items-center justify-center font-black text-indigo-500 italic text-sm">
                    <?php echo strtoupper(substr($student_name, 0, 1)); ?>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-6 py-12">
        <div class="glass-card rounded-[3rem] p-12">
            <div class="flex flex-col md:flex-row md:items-center justify-between mb-12 gap-6">
                <div>
                    <h2 class="text-4xl font-black text-slate-800 dark:text-white uppercase italic tracking-tighter">Instructor <span class="text-indigo-500">Booking</span></h2>
                    <p class="text-slate-500 font-medium uppercase text-xs tracking-widest mt-2">Select an available instructor to secure your queue slot instantly</p>
                </div>
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full bg-emerald-500 shadow-[0_0_12px_rgba(16,185,129,0.5)] animate-pulse"></div>
                    <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Real-Time Sync Ready</span>
                </div>
            </div>

            <!-- Error and Success Messages -->
            <?php if ($error === 'past_date'): ?>
                <div class="mb-8 p-4 rounded-2xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Invalid date selection. You cannot book a date in the past.
                </div>
            <?php elseif ($error === 'faculty_unavailable'): ?>
                <div class="mb-8 p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Status Conflict: This faculty is currently unavailable for consultation (Busy or On Leave).
                </div>
            <?php elseif ($error === 'prof_unavailable'): ?>
                <div class="mb-8 p-4 rounded-2xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Professor is unavailable during this time slot (e.g., On Break or in a Meeting).
                </div>
            <?php elseif ($error === 'slot_taken'): ?>
                <div class="mb-8 p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    This time slot is already booked. Please choose another.
                </div>
            <?php elseif ($error === 'past_time'): ?>
                <div class="mb-8 p-4 rounded-2xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    You cannot book a time slot that has already passed for today.
                </div>
            <?php elseif ($success === 'appointment_booked'): ?>
                <div class="mb-8 p-4 rounded-2xl bg-green-500/10 border border-green-500/20 text-green-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    Appointment request submitted successfully!
                </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                <?php foreach($faculties as $f): ?>
                    <div class="glass-card p-8 rounded-[2.5rem] relative group overflow-hidden hover:border-indigo-500/30 transition-all border-white/5 flex flex-col h-full">
                        <div class="absolute inset-0 bg-indigo-500/[0.02] opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        
                        <div class="relative z-10 flex flex-col h-full">
                            <div class="flex items-center justify-between mb-8">
                                <div class="relative w-14 h-14">
                                    <div class="w-full h-full rounded-2xl bg-slate-100 dark:bg-white/5 flex items-center justify-center text-xl font-black text-slate-400 italic">
                                        <?php echo htmlspecialchars(strtoupper(substr($f['full_name'], 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end">
                                    <span class="stat-badge <?php 
                                        echo $f['current_status'] === 'Available' ? 'bg-green-500/10 text-green-500 border-green-500/20' : 
                                             ($f['current_status'] === 'Busy' ? 'bg-orange-500/10 text-orange-500 border-orange-500/20' : 'bg-red-500/10 text-red-500 border-red-500/20'); 
                                    ?>">
                                        <?php 
                                            if ($f['current_status'] === 'Available') echo 'AVAILABLE';
                                            elseif ($f['current_status'] === 'Busy') echo 'BUSY';
                                            else echo 'ON LEAVE';
                                        ?>
                                    </span>
                                    <?php if ($f['current_status'] === 'Busy' && $f['busy_until']): ?>
                                        <span class="text-[10px] font-extrabold text-orange-500 uppercase tracking-tight mt-1 busy-countdown" data-busy-until="<?php echo htmlspecialchars($f['busy_until']); ?>">Available at <?php echo date('h:i A', strtotime($f['busy_until'])); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <h3 class="text-xl font-bold mb-2 group-hover:text-indigo-500 transition-colors"><?php echo htmlspecialchars($f['full_name']); ?></h3>
                            <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mb-4"><?php echo htmlspecialchars($f['specialization'] ?? 'Institutional Mentor'); ?></p>
                            <p class="text-xs text-slate-400/80 mb-8 line-clamp-3 leading-relaxed"><?php echo htmlspecialchars($f['biography'] ?? 'No biography loaded.'); ?></p>

                            <?php 
                            $f_status_lower = str_replace(' ', '_', strtolower($f['current_status']));
                            $isUnavailable = ($f_status_lower === 'on_leave');
                            if ($isUnavailable): 
                            ?>
                                <button disabled class="mt-auto py-4 rounded-xl bg-slate-800 text-slate-500 text-xs font-black uppercase tracking-widest border border-transparent cursor-not-allowed text-center shadow-sm">
                                    ON LEAVE - UNAVAILABLE
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
    </main>

    <!-- Modal Booking Overlay -->
    <div id="bookingModal" class="fixed inset-0 z-[100] hidden flex items-center justify-center p-6">
        <div class="fixed inset-0 bg-slate-950/60 backdrop-blur-md opacity-0 transition-opacity duration-300" id="modalBackdrop" onclick="closeBookingModal()"></div>
        
        <div class="glass-card w-full max-w-xl rounded-[2.5rem] overflow-hidden shadow-2xl relative z-10 border border-white/10 opacity-0 scale-95 transition-all duration-300 flex flex-col" id="modalBox">
            <div class="px-8 pt-8 pb-6 border-b border-white/5 flex items-center justify-between">
                <div>
                    <h3 class="text-xl font-bold">Secure Appointment</h3>
                    <p class="text-xs font-semibold text-slate-500 uppercase tracking-widest mt-1">Practitioner: <span id="modalFacultyName" class="text-indigo-400 font-bold"></span></p>
                </div>
                <button onclick="closeBookingModal()" class="w-10 h-10 rounded-xl bg-slate-100 dark:bg-white/5 text-slate-400 hover:text-red-500 hover:bg-red-500/10 flex items-center justify-center transition-all">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            <form id="bookingForm" action="process_booking.php" method="POST" onsubmit="submitForm(event)" class="px-8 py-6 space-y-6 flex-1 max-h-[75vh] overflow-y-auto">
                <input type="hidden" name="faculty_id" id="modalFacultyId">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block ml-1">Appt Month</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="4" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            </div>
                            <select name="appointment_month" id="modalMonth" onchange="loadAvailability()" class="input-field appearance-none py-4 px-12 font-bold text-sm" required>
                                <?php
                                $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                                $currentMonth = date('n');
                                foreach ($months as $index => $name) {
                                    $mVal = $index + 1;
                                    $selected = ($mVal == $currentMonth) ? 'selected' : '';
                                    if ($mVal >= $currentMonth) {
                                        echo "<option value='$mVal' $selected>$name</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block ml-1">Appt Day</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/></svg>
                            </div>
                            <select name="appointment_day" id="modalDay" onchange="loadAvailability()" class="input-field appearance-none py-4 px-12 font-bold text-sm" required>
                                <?php
                                $currentDay = (int)date('j');
                                for ($d = 1; $d <= 31; $d++) {
                                    $selected = ($d == $currentDay) ? 'selected' : '';
                                    echo "<option value='$d' data-day='$d' $selected>$d</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block ml-1">Select Time Slot</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                        <select name="time_slot" id="modalSlot" class="input-field appearance-none py-4 px-12 font-bold text-sm" required>
                            <option value="">-- Choose Slot --</option>
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
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-wider block ml-1">Consultation Purpose</label>
                    <div class="relative">
                        <div class="absolute top-4 left-4 text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                        </div>
                        <textarea name="reason" id="modalReason" rows="3" class="input-field py-4 px-12 resize-none text-sm font-semibold" placeholder="Briefly elaborate your concern..." required></textarea>
                    </div>
                </div>

                <div class="pt-4 grid grid-cols-2 gap-4">
                    <button type="button" onclick="closeBookingModal()" class="w-full py-4 rounded-2xl bg-slate-100 dark:bg-white/5 hover:bg-slate-200 dark:hover:bg-white/10 font-bold text-xs uppercase tracking-widest text-slate-500 transition-all border border-slate-200 dark:border-white/5">
                        Abort
                    </button>
                    <button type="submit" id="submitBtn" class="w-full py-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-black text-xs uppercase tracking-widest shadow-lg shadow-indigo-600/20 transition-all flex items-center justify-center gap-2">
                        <span id="btnText">Confirm Appointment</span>
                        <div id="btnLoader" class="hidden w-4 h-4 border-2 border-white/30 border-t-white rounded-full animate-spin"></div>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const modal = document.getElementById('bookingModal');
        const backdrop = document.getElementById('modalBackdrop');
        const box = document.getElementById('modalBox');

        async function openBookingModal(facultyId, facultyName) {
            document.getElementById('modalFacultyId').value = facultyId;
            document.getElementById('modalFacultyName').textContent = facultyName;
            document.getElementById('modalReason').value = '';
            document.getElementById('modalSlot').value = '';
            
            // Set values and filter day options initially
            const now = new Date();
            const currentMonth = now.getMonth() + 1;
            document.getElementById('modalMonth').value = currentMonth;
            document.getElementById('modalDay').value = now.getDate();

            modal.classList.remove('hidden');
            setTimeout(() => {
                backdrop.classList.remove('opacity-0');
                box.classList.remove('opacity-0', 'scale-95');
            }, 10);

            await loadAvailability();
        }

        function closeBookingModal() {
            backdrop.classList.add('opacity-0');
            box.classList.add('opacity-0', 'scale-95');
            setTimeout(() => {
                modal.classList.add('hidden');
            }, 300);
        }

        async function loadAvailability() {
            const facultyId = document.getElementById('modalFacultyId').value;
            const monthSelect = document.getElementById('modalMonth');
            const daySelect = document.getElementById('modalDay');
            const slotSelect = document.getElementById('modalSlot');
            
            if (!facultyId) return;

            const now = new Date();
            const currentMonth = now.getMonth() + 1;
            const currentDay = now.getDate();
            const currentHour = now.getHours();

            const selectedMonth = parseInt(monthSelect.value);

            // Filter Day select options based on month
            const dayOptions = daySelect.querySelectorAll('option[data-day]');
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
                for (let opt of dayOptions) {
                    if (!opt.disabled) {
                        daySelect.value = opt.value;
                        break;
                    }
                }
            }

            const selectedDay = parseInt(daySelect.value);
            const isToday = (selectedMonth === currentMonth && selectedDay === currentDay);

            let blockedSlots = [];
            let bookedSlots = [];
            let facultyStatus = 'Available';
            let busyUntil = null;

            try {
                const res = await fetch(`api/get_faculty_availability.php?faculty_id=${facultyId}&month=${selectedMonth}&day=${selectedDay}`);
                const data = await res.json();
                if (data && !data.error) {
                    blockedSlots = data.unavailable_slots || [];
                    bookedSlots = data.booked_slots || [];
                    facultyStatus = data.faculty_status || 'Available';
                    busyUntil = data.busy_until || null;
                }
            } catch (e) {
                console.error("Filter failed", e);
            }

            // Check if faculty is Busy or On Leave
            const statusNormalized = facultyStatus.toLowerCase().replace(' ', '_');
            const isFacultyOnLeave = (statusNormalized === 'on_leave');
            
            const submitBtn = document.getElementById('submitBtn');
            const slotErrorMsg = document.getElementById('modal-status-warning');
            
            if (isFacultyOnLeave) {
                const errorText = `ON LEAVE: This instructor is not accepting appointments at this time.`;
                if (!slotErrorMsg) {
                    const warningDiv = document.createElement('div');
                    warningDiv.id = 'modal-status-warning';
                    warningDiv.className = 'p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-xs font-bold mb-4';
                    warningDiv.textContent = errorText;
                    document.getElementById('bookingForm').insertBefore(warningDiv, document.getElementById('bookingForm').firstChild);
                } else {
                    slotErrorMsg.textContent = errorText;
                    slotErrorMsg.className = 'p-4 rounded-xl bg-red-500/10 border border-red-500/20 text-red-500 text-xs font-bold mb-4';
                    slotErrorMsg.classList.remove('hidden');
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            } else if (statusNormalized === 'busy' && busyUntil) {
                let busyTimeStr = "";
                try {
                    const busyDate = new Date(busyUntil.replace(/-/g, '/'));
                    busyTimeStr = busyDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                } catch (err) {
                    busyTimeStr = busyUntil;
                }
                const infoText = `BUSY: This instructor is currently unavailable and will be available at ${busyTimeStr}. You can still book a later slot.`;
                if (!slotErrorMsg) {
                    const warningDiv = document.createElement('div');
                    warningDiv.id = 'modal-status-warning';
                    warningDiv.className = 'p-4 rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-xs font-bold mb-4';
                    warningDiv.textContent = infoText;
                    document.getElementById('bookingForm').insertBefore(warningDiv, document.getElementById('bookingForm').firstChild);
                } else {
                    slotErrorMsg.textContent = infoText;
                    slotErrorMsg.className = 'p-4 rounded-xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-xs font-bold mb-4';
                    slotErrorMsg.classList.remove('hidden');
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            } else {
                if (slotErrorMsg) {
                    slotErrorMsg.classList.add('hidden');
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }

            // Filter individual slot selections
            const slotOptions = slotSelect.querySelectorAll('option[data-hour]');
            slotOptions.forEach(option => {
                const slotHour = parseInt(option.getAttribute('data-hour'));
                const slotRange = option.value;
                let isBlocked = false;

                if (isToday && slotHour <= currentHour) {
                    isBlocked = true;
                }
                if (bookedSlots.includes(slotRange)) {
                    isBlocked = true;
                }

                // Block busy duration slots
                if (facultyStatus === 'Busy' && busyUntil && isToday) {
                    try {
                        const busyUntilDate = new Date(busyUntil.replace(/-/g, '/'));
                        const slotPart = slotRange.split(' - ')[0];
                        const nowYear = now.getFullYear();
                        const slotDate = new Date(nowYear, selectedMonth - 1, selectedDay);
                        const [timeStr, ampm] = slotPart.split(' ');
                        let [h, m] = timeStr.split(':').map(Number);
                        if (ampm === 'PM' && h < 12) h += 12;
                        if (ampm === 'AM' && h === 12) h = 0;
                        slotDate.setHours(h, m, 0, 0);
                        if (slotDate < busyUntilDate) {
                            isBlocked = true;
                        }
                    } catch (e) { console.error(e); }
                }

                blockedSlots.forEach(block => {
                    const bStart = parseInt(block.start_time.split(':')[0]);
                    const bEnd = parseInt(block.end_time.split(':')[0]);
                    if (slotHour >= bStart && slotHour < bEnd) {
                        isBlocked = true;
                    }
                });

                if (isBlocked) {
                    option.disabled = true;
                    option.classList.add('hidden');
                } else {
                    option.disabled = false;
                    option.classList.remove('hidden');
                }
            });

            if (slotSelect.selectedOptions[0] && slotSelect.selectedOptions[0].disabled) {
                slotSelect.value = "";
            }
        }

        async function submitForm(event) {
            event.preventDefault();
            const form = document.getElementById('bookingForm');
            const formData = new FormData(form);

            const btnText = document.getElementById('btnText');
            const btnLoader = document.getElementById('btnLoader');
            const submitBtn = document.getElementById('submitBtn');

            submitBtn.disabled = true;
            btnText.textContent = "Processing...";
            btnLoader.classList.remove('hidden');

            try {
                const res = await fetch('process_booking.php', {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json'
                    },
                    body: formData
                });
                const data = await res.json();

                if (data.success) {
                    Swal.fire({
                        title: 'Success!',
                        text: data.message,
                        icon: 'success',
                        background: '#0d121f',
                        color: '#fff',
                        confirmButtonColor: '#6366f1'
                    }).then(() => {
                        window.location.href = 'student_dashboard.php';
                    });
                } else {
                    Swal.fire({
                        title: 'Booking Blocked',
                        text: data.message,
                        icon: 'error',
                        background: '#0d121f',
                        color: '#fff',
                        confirmButtonColor: '#ef4444'
                    });
                }
            } catch (err) {
                console.error(err);
                Swal.fire({
                    title: 'Network Error',
                    text: 'Unable to communicate with the secure vault. Try again.',
                    icon: 'error',
                    background: '#0d121f',
                    color: '#fff',
                    confirmButtonColor: '#ef4444'
                });
            } finally {
                submitBtn.disabled = false;
                btnText.textContent = "Confirm Appointment";
                btnLoader.classList.add('hidden');
            }
        }

        function showAlert(title, text, icon) {
            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                background: '#0d121f',
                color: '#fff',
                confirmButtonColor: '#6366f1'
            });
        }

        function updateBusyCountdowns() {
            document.querySelectorAll('.busy-countdown').forEach(el => {
                const rawUntil = el.dataset.busyUntil;
                if (!rawUntil) return;

                const busyUntil = new Date(rawUntil.replace(/-/g, '/'));
                const remaining = Math.max(0, Math.floor((busyUntil.getTime() - Date.now()) / 1000));

                if (remaining <= 0) {
                    el.textContent = 'Available now';
                    return;
                }

                const hours = Math.floor(remaining / 3600);
                const minutes = Math.floor((remaining % 3600) / 60);
                const seconds = remaining % 60;
                const timeLeft = hours > 0
                    ? `${hours}h ${String(minutes).padStart(2, '0')}m`
                    : `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;

                el.textContent = `Available in ${timeLeft}`;
            });
        }

        updateBusyCountdowns();
        setInterval(updateBusyCountdowns, 1000);
    </script>
</body>
</html>
