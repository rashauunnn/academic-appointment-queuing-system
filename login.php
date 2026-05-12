<?php
// login.php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    switch ($_SESSION['role']) {
        case 'Student': header("Location: student_dashboard.php"); break;
        case 'Faculty': header("Location: faculty_dashboard.php"); break;
        case 'Admin': header("Location: admin_dashboard.php"); break;
    }
    exit();
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
<html lang="en" class="dark">
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
                        indigo: {
                            500: '#6366f1',
                            600: '#4f46e5',
                        },
                        violet: {
                            500: '#8b5cf6',
                            600: '#7c3aed',
                        }
                    }
                }
            }
        }
    </script>

    <style>
        body {
            background-color: #0f172a;
            position: relative;
            overflow: hidden;
        }

        /* Subtle background glow effects */
        .glow {
            position: absolute;
            width: 500px;
            height: 500px;
            border-radius: 50%;
            filter: blur(120px);
            z-index: -1;
            opacity: 0.15;
            background: linear-gradient(to right, #6366f1, #8b5cf6);
        }
        
        .glow-1 { top: -10%; left: -10%; }
        .glow-2 { bottom: -10%; right: -10%; background: linear-gradient(to left, #4f46e5, #7c3aed); }

        /* Glassmorphism */
        .glass {
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        /* Input styling */
        .input-field {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid rgba(255, 255, 255, 0.1);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .input-field:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 2px rgba(99, 102, 241, 0.2);
            outline: none;
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-100 flex items-center justify-center min-h-screen p-4">
    
    <!-- Background Elements -->
    <div class="glow glow-1"></div>
    <div class="glow glow-2"></div>

    <div class="w-full max-w-md">
        <!-- Logo/Header -->
        <div class="text-center mb-10 transition-all duration-700 ease-out transform">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-tr from-indigo-600 to-violet-600 shadow-xl mb-6 ring-4 ring-indigo-500/10">
                <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-white">
                    <path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z" />
                    <path d="m15 13-3-3-3 3M12 10v9" />
                </svg>
            </div>
            <h1 class="text-3xl font-bold tracking-tight bg-clip-text text-transparent bg-gradient-to-r from-white to-slate-400">
                Welcome Back
            </h1>
            <p class="mt-2 text-slate-400 font-medium">Academic Appointment System</p>
        </div>

        <!-- Login Card -->
        <div class="glass p-8 md:p-10 rounded-[2rem] shadow-2xl relative overflow-hidden group">
            <!-- Subtle accent reveal -->
            <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 blur-3xl -mr-16 -mt-16 group-hover:bg-indigo-500/20 transition-colors duration-500"></div>

            <?php if ($error_message): ?>
                <div class="mb-6 p-4 rounded-xl bg-red-500/10 border border-red-500/20 flex items-center gap-3 animate-in fade-in slide-in-from-top-4 duration-300">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="text-red-400">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span class="text-sm font-medium text-red-200"><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST" class="space-y-6">
                <!-- School ID -->
                <div class="space-y-2">
                    <label for="school_id" class="text-xs font-semibold uppercase tracking-wider text-slate-500 ml-1">
                        School ID
                    </label>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-500 group-focus-within:text-indigo-400 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <input 
                            type="text" 
                            id="school_id" 
                            name="school_id" 
                            class="input-field block w-full pl-11 py-3.5 px-4 text-slate-100 placeholder-slate-600 rounded-xl"
                            placeholder="2021-XXXXX"
                            required
                        >
                    </div>
                </div>

                <!-- Password -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between ml-1">
                        <label for="password" class="text-xs font-semibold uppercase tracking-wider text-slate-500">
                            Password
                        </label>
                        <a href="#" class="text-xs font-semibold text-indigo-400 hover:text-violet-400 transition-colors">Forgot?</a>
                    </div>
                    <div class="relative group">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-slate-500 group-focus-within:text-indigo-400 transition-colors">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </div>
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            class="input-field block w-full pl-11 py-3.5 px-4 text-slate-100 placeholder-slate-600 rounded-xl"
                            placeholder="••••••••"
                            required
                        >
                    </div>
                </div>

                <!-- Remember Me -->
                <div class="flex items-center gap-2 px-1">
                    <input type="checkbox" id="remember" class="w-4 h-4 rounded border-slate-700 bg-slate-900/50 text-indigo-600 focus:ring-indigo-500/20">
                    <label for="remember" class="text-sm text-slate-400 cursor-pointer">Remember this device</label>
                </div>

                <!-- Submit Button -->
                <button 
                    type="submit" 
                    class="group relative w-full flex justify-center py-4 px-4 border border-transparent text-sm font-bold rounded-xl text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 focus:ring-offset-slate-900 transition-all duration-300 shadow-[0_0_20px_rgba(79,70,229,0.3)] hover:shadow-[0_0_25px_rgba(79,70,229,0.5)] transform hover:-translate-y-0.5"
                >
                    <span class="relative z-10">Sign In to Dashboard</span>
                    <div class="absolute inset-0 rounded-xl opacity-0 group-hover:opacity-100 transition-opacity bg-gradient-to-r from-indigo-400/20 to-violet-400/20 blur-xl"></div>
                </button>
            </form>

            <!-- Footer Info -->
            <div class="mt-10 pt-6 border-t border-slate-700/50 text-center">
                <p class="text-sm text-slate-500">
                    Student Assistance Center &bull; v1.0.4
                </p>
            </div>
        </div>

        <!-- Help Link -->
        <p class="text-center mt-8 text-sm text-slate-500">
            Need help? Contact <a href="mailto:support@school.edu" class="text-slate-400 font-semibold hover:text-white transition-colors underline decoration-slate-600 underline-offset-4">IT Support Services</a>
        </p>
    </div>
</body>
</html>
