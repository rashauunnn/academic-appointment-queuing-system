<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// book_appointment.php
require_once 'db_connect.php';

// Role-specific Authentication Guard
if (!isset($_SESSION['student_id'])) {
    header("Location: login.php");
    exit();
}

$student_id = $_SESSION['student_id'];
$student_name = $_SESSION['student_name'];

// Fetch All Faculty
try {
    $now_str = date('Y-m-d H:i:s');
    $pdo->prepare("UPDATE users SET current_status = 'Available', busy_until = NULL WHERE current_status = 'Busy' AND busy_until <= ?")->execute([$now_str]);

    $faculty_stmt = $pdo->prepare("SELECT user_id, full_name, current_status FROM users WHERE role = 'Faculty'");
    $faculty_stmt->execute();
    $faculties = $faculty_stmt->fetchAll();
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

$error = $_GET['error'] ?? '';
$success = $_GET['success'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Appointment | Academic Appointment System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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
            body { @apply bg-slate-50 text-slate-900 transition-colors duration-500; }
            .dark body { @apply bg-[#0b0f19] text-slate-100; }
        }
        @layer components {
            .glass-card { @apply bg-white/70 backdrop-blur-xl border border-slate-200/50 shadow-2xl transition-all duration-500; }
            .dark .glass-card { @apply bg-slate-900/50 border-white/10 shadow-none; }
            .input-field { @apply bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 transition-all duration-300 rounded-xl block w-full pl-11 py-3 px-4 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600; }
            .input-field:focus { @apply ring-2 ring-indigo-500/20 border-indigo-500 outline-none; }
        }
    </style>
</head>
<body class="font-sans antialiased min-h-screen">
    
    <!-- Header -->
    <nav class="glass-card sticky top-0 z-50 px-6 py-4 flex items-center justify-between mt-0 rounded-none border-x-0 border-t-0 shadow-lg">
        <div class="flex items-center gap-3">
            <a href="student_dashboard.php" class="p-2 rounded-xl bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </a>
            <span class="font-bold text-xl tracking-tight">Book Appointment</span>
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

    <main class="max-w-7xl mx-auto px-6 py-12">
        <div class="max-w-2xl mx-auto">
            <div class="glass-card rounded-[2.5rem] p-10 relative overflow-hidden">
                <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/5 blur-[100px] -mr-32 -mt-32"></div>
                
                <h2 class="text-3xl font-bold mb-2">Request an Appointment</h2>
                <p class="text-slate-400 mb-8">Choose a faculty member and select your preferred schedule.</p>

                <?php if ($error === 'past_date'): ?>
                    <div class="mb-6 p-4 rounded-2xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Invalid date selection. You cannot book a date in the past.
                    </div>
                <?php endif; ?>

                <?php if ($error === 'faculty_unavailable'): ?>
                    <div class="mb-6 p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        This faculty is currently unavailable for consultation.
                    </div>
                <?php endif; ?>

                <?php if ($error === 'prof_unavailable'): ?>
                    <div class="mb-6 p-4 rounded-2xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        Professor is unavailable during this time slot (e.g., On Break or in a Meeting).
                    </div>
                <?php endif; ?>

                <?php if ($error === 'slot_taken'): ?>
                    <div class="mb-6 p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        This time slot is already booked. Please choose another.
                    </div>
                <?php endif; ?>

                <?php if ($error === 'past_time'): ?>
                    <div class="mb-6 p-4 rounded-2xl bg-orange-500/10 border border-orange-500/20 text-orange-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        You cannot book a time slot that has already passed for today.
                    </div>
                <?php endif; ?>

                <?php if ($success === 'appointment_booked'): ?>
                    <div class="mb-6 p-4 rounded-2xl bg-green-500/10 border border-green-500/20 text-green-500 text-sm font-bold flex items-center gap-3 animate-in slide-in-from-top-4 duration-300">
                        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        Appointment request submitted successfully!
                    </div>
                <?php endif; ?>

                <form action="booking_process.php" method="POST" class="space-y-6 relative z-10">
                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider ml-1">Select Faculty</label>
                        <div class="relative">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </div>
                            <select name="faculty_id" id="faculty_id" onchange="filterTimeSlots()" class="input-field appearance-none py-4 px-12" required>
                                <option value="">-- Choose Faculty --</option>
                                <?php foreach($faculties as $faculty): 
                                    $f_status_lower = str_replace(' ', '_', strtolower($faculty['current_status'] ?? 'available'));
                                    $isUnavailable = ($f_status_lower === 'busy' || $f_status_lower === 'on_leave');
                                    $suffix = "";
                                    if ($f_status_lower === 'busy') {
                                        $suffix = " (Busy)";
                                    } elseif ($f_status_lower === 'on_leave') {
                                        $suffix = " (On Leave)";
                                    }
                                ?>
                                    <option value="<?php echo $faculty['user_id']; ?>" <?php echo $isUnavailable ? 'disabled class="text-slate-400 bg-slate-800"' : ''; ?>>
                                        <?php echo htmlspecialchars($faculty['full_name']) . $suffix; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="faculty_status_badge" class="hidden mt-2 ml-1">
                            <span class="px-2 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider flex items-center gap-1.5 w-fit">
                                <span class="w-1.5 h-1.5 rounded-full status-dot"></span>
                                <span class="status-text"></span>
                            </span>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="space-y-2">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider ml-1">Appointment Date (<?php echo date('Y'); ?>)</label>
                            <div class="grid grid-cols-2 gap-4">
                                <div class="relative">
                                    <select name="appointment_month" id="appointment_month" onchange="filterTimeSlots()" class="input-field py-4 px-4 appearance-none" required>
                                        <?php
                                        $months = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];
                                        $currentMonth = date('n');
                                        foreach ($months as $index => $name) {
                                            $mVal = $index + 1;
                                            $selected = ($mVal == $currentMonth) ? 'selected' : '';
                                            // Only show current or future months of the year
                                            if ($mVal >= $currentMonth) {
                                                echo "<option value='$mVal' $selected>$name</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="relative">
                                    <select name="appointment_day" id="appointment_day" onchange="filterTimeSlots()" class="input-field py-4 px-4 appearance-none" required>
                                        <?php
                                        $currentDay = (int)date('j');
                                        $currentMonth = (int)date('n');
                                        for ($d = 1; $d <= 31; $d++) {
                                            $selected = ($d == $currentDay) ? 'selected' : '';
                                            // The JavaScript handles dynamic visibility on month change,
                                            // but we can add a data-day attribute for easier filtering.
                                            echo "<option value='$d' data-day='$d' $selected>$d</option>";
                                        }
                                        ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="text-xs font-bold text-slate-500 uppercase tracking-wider ml-1">Time Slot</label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                </div>
                                <select name="time_slot" id="time_slot" class="input-field appearance-none py-4 px-12" required>
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
                    </div>

                    <div class="space-y-2">
                        <label class="text-xs font-bold text-slate-500 uppercase tracking-wider ml-1">Purpose / Reason</label>
                        <div class="relative">
                            <div class="absolute top-4 left-4 text-slate-400">
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            </div>
                            <textarea name="reason" rows="4" class="input-field py-4 px-12 resize-none" placeholder="Explain your concern..." required></textarea>
                        </div>
                    </div>

                    <div class="pt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <a href="student_dashboard.php" class="w-full py-4 rounded-2xl bg-slate-100 dark:bg-slate-800 hover:bg-slate-200 dark:hover:bg-slate-700 font-bold text-center transition-all">
                            Cancel
                        </a>
                        <button type="submit" class="w-full py-4 rounded-2xl bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-bold shadow-lg shadow-indigo-600/20 transition-all">
                            Confirm Appointment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <script>
        let availabilityCache = {};

        async function filterTimeSlots() {
            const facultyId = document.getElementById('faculty_id').value;
            const monthSelect = document.getElementById('appointment_month');
            const daySelect = document.getElementById('appointment_day');
            const slotSelect = document.getElementById('time_slot');
            
            const dayOptions = daySelect.querySelectorAll('option[data-day]');
            const slotOptions = slotSelect.querySelectorAll('option[data-hour]');
            const statusBadge = document.getElementById('faculty_status_badge');
            
            const now = new Date();
            const currentMonth = now.getMonth() + 1;
            const currentDay = now.getDate();
            const currentHour = now.getHours();

            const selectedMonth = parseInt(monthSelect.value);
            
            // 1. Filter Days based on Month
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

            // 2. Fetch Faculty Availability if faculty and date are selected
            let blockedSlots = [];
            let bookedSlots = [];
            let isFacultyUnavailable = false;
            let currentFacultyStatus = 'Available';
            if (facultyId && selectedMonth && selectedDay) {
                const cacheKey = `${facultyId}-${selectedMonth}-${selectedDay}`;
                if (availabilityCache[cacheKey]) {
                    blockedSlots = availabilityCache[cacheKey].unavailable_slots;
                    bookedSlots = availabilityCache[cacheKey].booked_slots;
                    currentFacultyStatus = availabilityCache[cacheKey].faculty_status;
                    updateFacultyBadge(currentFacultyStatus);
                } else {
                    try {
                        const res = await fetch(`api/get_faculty_availability.php?faculty_id=${facultyId}&month=${selectedMonth}&day=${selectedDay}`);
                        const data = await res.json();
                        if (!data.error) {
                            availabilityCache[cacheKey] = data;
                            blockedSlots = data.unavailable_slots;
                            bookedSlots = data.booked_slots;
                            currentFacultyStatus = data.faculty_status;
                            updateFacultyBadge(currentFacultyStatus);
                        }
                    } catch (e) { console.error("Fetch failed", e); }
                }
                
                const statusNormalized = currentFacultyStatus.toLowerCase().replace(' ', '_');
                isFacultyUnavailable = (statusNormalized === 'busy' || statusNormalized === 'on_leave');
            } else {
                statusBadge.classList.add('hidden');
            }
            
            // Block/Disable slot selection & submit if the selected faculty is Busy or On Leave
            const submitBtn = document.querySelector('form[action="booking_process.php"] button[type="submit"]');
            let formWarningMsg = document.getElementById('faculty-form-warning');
            
            if (isFacultyUnavailable) {
                if (!formWarningMsg) {
                    formWarningMsg = document.createElement('div');
                    formWarningMsg.id = 'faculty-form-warning';
                    formWarningMsg.className = 'p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-500 text-sm font-bold flex items-center gap-3 mb-6 animate-in slide-in-from-top-4 duration-300';
                    formWarningMsg.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> This faculty is currently ${currentFacultyStatus}. Booking is suspended.`;
                    const formEl = document.querySelector('form[action="booking_process.php"]');
                    formEl.insertBefore(formWarningMsg, formEl.firstChild);
                } else {
                    formWarningMsg.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> This faculty is currently ${currentFacultyStatus}. Booking is suspended.`;
                    formWarningMsg.classList.remove('hidden');
                }
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                }
            } else {
                if (formWarningMsg) {
                    formWarningMsg.classList.add('hidden');
                }
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            }
            
            // 3. Filter Time Slots
            slotOptions.forEach(option => {
                const slotHour = parseInt(option.getAttribute('data-hour'));
                const slotRange = option.value; // e.g., "09:00 AM - 10:00 AM"
                const [startStr, endStr] = slotRange.split(' - ');
                
                let isBlocked = false;
                let blockReason = "";

                // Check Past Hour
                if (isToday && slotHour <= currentHour) {
                    isBlocked = true;
                }

                // Check Already Booked
                if (bookedSlots.includes(slotRange)) {
                    isBlocked = true;
                    blockReason = "Already Booked";
                }

                // Check Faculty Unavailability
                blockedSlots.forEach(block => {
                    // Simple hour-based overlap check for our fixed slots
                    const bStart = parseInt(block.start_time.split(':')[0]);
                    const bEnd = parseInt(block.end_time.split(':')[0]);
                    // If block is 14:00-15:00, and slot is 02:00 PM (14) - 03:00 PM (15), it matches
                    if (slotHour >= bStart && slotHour < bEnd) {
                        isBlocked = true;
                        blockReason = block.reason || "Professor Occupied";
                    }
                });

                if (isBlocked) {
                    option.disabled = true;
                    option.classList.add('hidden');
                    if (blockReason) {
                        // We can't easily change text of nested options in all browsers, 
                        // but we can at least disable them.
                    }
                } else {
                    option.disabled = false;
                    option.classList.remove('hidden');
                }
            });

            if (slotSelect.selectedOptions[0] && slotSelect.selectedOptions[0].disabled) {
                slotSelect.value = "";
            }
        }

        function updateFacultyBadge(status) {
            const badge = document.getElementById('faculty_status_badge');
            const dot = badge.querySelector('.status-dot');
            const text = badge.querySelector('.status-text');
            
            badge.classList.remove('hidden');
            text.textContent = status;
            
            badge.querySelector('span').className = 'px-2 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider flex items-center gap-1.5 w-fit ' + 
                (status === 'Available' ? 'bg-green-500/10 text-green-500' : 
                 status === 'Busy' ? 'bg-yellow-500/10 text-yellow-500' : 'bg-red-500/10 text-red-500');
            
            dot.className = 'w-1.5 h-1.5 rounded-full status-dot ' + 
                (status === 'Available' ? 'bg-green-500' : 
                 status === 'Busy' ? 'bg-yellow-500' : 'bg-red-500');
        }

        window.onload = filterTimeSlots;
    </script>
</body>
</html>
