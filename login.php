<?php
// login.php
session_start();

// Redirect if already logged in
if (isset($_SESSION['student_id']) || isset($_SESSION['faculty_id']) || isset($_SESSION['admin_id'])) {
    if (isset($_SESSION['role'])) {
        switch ($_SESSION['role']) {
            case 'Student': header("Location: student_dashboard.php"); break;
            case 'Faculty': header("Location: faculty_dashboard.php"); break;
            case 'Admin': header("Location: admin_dashboard.php"); break;
        }
        exit();
    }
}

$error_message = "";
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'empty_fields':
            $error_message = "Please fill in all fields.";
            break;
        case 'invalid_credentials':
            $error_message = "Invalid School ID or Password.";
            break;
        case 'invalid_role':
            $error_message = "Your account has an invalid role configuration.";
            break;
        default:
            $error_message = "An unexpected error occurred. Please try again.";
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Academic Appointment System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Fonts: Plus Jakarta Sans -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <script>
        // Tailwind Configuration with Dark Mode Class enabled
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Plus Jakarta Sans', 'sans-serif'],
                    },
                    colors: {
                        slate: {
                            900: '#0f172a',
                            950: '#020617',
                        },
                    }
                }
            }
        }

        // Initialize Theme BEFORE page renders to prevent white flash
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
                @apply bg-slate-50 text-slate-900 transition-colors duration-500; 
            }
            .dark body { 
                @apply bg-[#0b0f19] text-slate-100; 
            }
        }

        @layer components {
            /* Subtle background glow effects */
            .glow {
                @apply absolute w-[500px] h-[500px] rounded-full blur-[120px] -z-10 opacity-10;
                background: linear-gradient(to right, #6366f1, #8b5cf6);
            }
            
            .glow-1 { @apply -top-[10%] -left-[10%]; }
            .glow-2 { @apply -bottom-[10%] -right-[10%] opacity-10; background: linear-gradient(to left, #4f46e5, #7c3aed); }

            /* Glassmorphism */
            .glass-card {
                @apply bg-white/70 backdrop-blur-xl border border-slate-200/50 shadow-2xl transition-all duration-500;
            }
            .dark .glass-card {
                @apply bg-slate-900/50 border-white/10 shadow-none;
            }

            /* Input styling */
            .input-field {
                @apply bg-white dark:bg-slate-950 border border-slate-200 dark:border-slate-800 transition-all duration-300 rounded-xl block w-full pl-11 py-3 px-4 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-600;
            }

            .input-field:focus {
                @apply ring-2 ring-indigo-500/20 border-indigo-500 outline-none;
            }
        }
    </style>
</head>
<body class="font-sans antialiased flex items-center justify-center min-h-screen p-4 overflow-y-auto">
    
    <!-- Theme Toggle Button -->
    <button onclick="toggleTheme()" class="fixed top-6 right-6 p-3 rounded-2xl bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 text-slate-600 dark:text-slate-300 hover:scale-110 active:scale-95 transition-all shadow-lg z-50">
        <!-- Sun Icon (Visible in Dark Mode) -->
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block">
            <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
        </svg>
        <!-- Moon Icon (Visible in Light Mode) -->
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
        </svg>
    </button>
    
    <!-- Background Glow Elements -->
    <div class="glow glow-1"></div>
    <div class="glow glow-2"></div>

    <div class="w-full max-w-md relative z-10 py-8">
        <!-- Header Section -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-600 shadow-xl mb-4 ring-4 ring-indigo-500/10">
                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white">
                    <path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z" />
                    <path d="m15 13-3-3-3 3M12 10v9" />
                </svg>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 dark:text-white">Welcome Back</h1>
            <p class="mt-1 text-xs text-slate-500 dark:text-slate-400 font-medium uppercase tracking-widest">Academic Appointment System</p>
        </div>

        <!-- Login Card -->
        <div class="glass-card p-8 md:p-10 rounded-[2.5rem] relative overflow-hidden group">
            <!-- Decorative Accent -->
            <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 blur-3xl -mr-16 -mt-16 group-hover:bg-indigo-500/20 transition-colors duration-500"></div>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center gap-3 animate-pulse">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-500">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span class="text-sm font-medium text-red-600 dark:text-red-400"><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST" class="space-y-5">
                <!-- School ID Input -->
                <div class="space-y-2">
                    <label for="school_id" class="text-xs font-bold uppercase tracking-widest text-slate-500 ml-1">School ID</label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 group-focus-within:text-indigo-500 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <input type="text" id="school_id" name="school_id" class="input-field" placeholder="2021-XXXXX" required>
                    </div>
                </div>

                <!-- Password Input -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between ml-1">
                        <label for="password" class="text-xs font-bold uppercase tracking-widest text-slate-500">Password</label>
                        <a href="#" class="text-xs font-bold text-indigo-600 dark:text-indigo-400 hover:underline">Forgot?</a>
                    </div>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-400 group-focus-within:text-indigo-500 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </div>
                        <input type="password" id="password" name="password" class="input-field" placeholder="••••••••" required>
                    </div>
                </div>

                <!-- Remember Me Checkbox -->
                <div class="flex items-center gap-2 px-1">
                    <input type="checkbox" id="remember" class="w-4 h-4 rounded border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-800 text-indigo-600 focus:ring-indigo-500">
                    <label for="remember" class="text-sm text-slate-500 dark:text-slate-400 cursor-pointer select-none">Remember this device</label>
                </div>

                <!-- Submit Button -->
                <button type="submit" class="group relative w-full flex justify-center py-4 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-slate-900 transition-all duration-300 shadow-xl transform hover:-translate-y-1 active:translate-y-0">
                    <span class="relative z-10">Sign In to Dashboard</span>
                </button>
            </form>

            <!-- Footer Info -->
            <div class="mt-8 pt-6 border-t border-slate-200 dark:border-slate-800 text-center">
                <p class="text-xs text-slate-400 tracking-wider">Student Assistance Center &bull; v1.0.4</p>
            </div>
        </div>

        <!-- External Help Link -->
        <p class="text-center mt-6 text-sm text-slate-500">
            Need help? Contact <a href="mailto:support@school.edu" class="text-indigo-600 dark:text-indigo-400 font-bold hover:underline">IT Support Services</a>
        </p>
    </div>
</body>
</html>