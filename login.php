<?php
require_once 'security_headers.php';
require_once 'session_helper.php';
require_once 'db_connect.php';

secure_session_start();

// Keep login page visible even if user has an active session in another tab.
// Role-isolated sessions are handled by session_helper.php; we explicitly use neutral context on login.
if (isset($_COOKIE['ACTIVE_ROLE_SESSION'])) {
    // Force neutral so this tab cannot attach to another role's session.
    setcookie('ACTIVE_ROLE_SESSION', 'Neutral', [
        'expires' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}


$error_message = "";
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'empty_fields': $error_message = "All terminals require identification."; break;
        case 'invalid_credentials': $error_message = "Access Denied: Invalid Credentials."; break;
        case 'verify_required': $error_message = "Your email is not verified yet. Check your inbox for the verification link."; break;

        case 'timeout': $error_message = "Session Expired due to inactivity."; break;
        case 'session_breach': $error_message = "Security Alert: Session mismatch detected."; break;
        default: $error_message = "System Error: Contact Site Command."; break;
    }
}

$maintenance_mode = false;
try {
    $maintenance_mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() === '1';
} catch (PDOException $e) {}

?>
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Authorize | ConsultCare Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@200;400;600;800&display=swap" rel="stylesheet">
    
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Plus Jakarta Sans', 'sans-serif'] },
                }
            }
        }

        if (localStorage.getItem('theme') === 'light') {
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

        function togglePassword() {
            const pwd = document.getElementById('password');
            const iconShow = document.getElementById('icon-show');
            const iconHide = document.getElementById('icon-hide');
            if (pwd.type === 'password') {
                pwd.type = 'text';
                iconShow.classList.add('hidden');
                iconHide.classList.remove('hidden');
            } else {
                pwd.type = 'password';
                iconShow.classList.remove('hidden');
                iconHide.classList.add('hidden');
            }
        }
    </script>

    <style>
        .animate-float { animation: float 6s ease-in-out infinite; }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
        }

        .entrance-stagger > * {
            opacity: 0;
            transform: translateY(20px);
            animation: emerge 0.8s cubic-bezier(0.2, 0.8, 0.2, 1) forwards;
        }
        @keyframes emerge {
            to { opacity: 1; transform: translateY(0); }
        }
        .entrance-stagger > *:nth-child(1) { animation-delay: 0.1s; }
        .entrance-stagger > *:nth-child(2) { animation-delay: 0.2s; }
        .entrance-stagger > *:nth-child(3) { animation-delay: 0.3s; }
        .entrance-stagger > *:nth-child(4) { animation-delay: 0.4s; }
    </style>
    <style type="text/tailwindcss">
        @layer base {
            body { @apply bg-slate-50 text-slate-900 transition-colors duration-700; }
            .dark body { @apply bg-[#020617] text-slate-100; }
        }
        @layer components {
            .glass-panel { @apply bg-white/80 dark:bg-[#0d121f]/80 backdrop-blur-3xl border border-slate-200/60 dark:border-white/5 shadow-2xl transition-all duration-500; }
            .input-box { @apply bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 rounded-2xl w-full pl-12 py-4 pr-12 text-sm font-bold focus:border-indigo-500 outline-none transition-all; }
            .nav-btn { @apply w-12 h-12 rounded-2xl flex items-center justify-center bg-white dark:bg-white/5 border border-slate-200 dark:border-white/10 hover:border-indigo-500 transition-all; }
        }
    </style>
</head>
<body class="font-sans antialiased flex flex-col items-center justify-center min-h-screen p-6 py-20 relative">
    
    <!-- Decorative Glows -->
    <div class="fixed top-[-10%] left-[-10%] w-[40%] h-[40%] bg-indigo-600/20 blur-[120px] rounded-full animate-pulse"></div>
    <div class="fixed bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-violet-600/20 blur-[120px] rounded-full animate-pulse" style="animation-delay: 2s"></div>

    <div class="fixed top-8 right-8 flex gap-4">
        <button onclick="toggleTheme()" class="nav-btn group">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden dark:block group-hover:text-indigo-400 transition-colors"><circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/></svg>
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="block dark:hidden group-hover:text-indigo-600 transition-colors"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
        </button>
    </div>

    <main class="w-full max-w-lg entrance-stagger">
        <!-- Logo & Title -->
        <div class="text-center mb-12">
            <div class="inline-flex items-center justify-center w-20 h-20 rounded-[2rem] bg-indigo-600 shadow-2xl shadow-indigo-600/30 mb-8 border-4 border-white/10">
                <svg xmlns="http://www.w3.org/2000/svg" width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-white"><path d="M12 7v14M12 7a2 2 0 1 0-4-1V5a2 2 0 1 1 4 0v2zM12 7a2 2 0 1 1 4-1V5a2 2 0 1 0-4 0v2z"/><path d="m15 13-3-3-3 3M12 10v9"/></svg>
            </div>
            <h1 class="text-5xl font-black italic tracking-tighter uppercase mb-2">Consult <span class="text-indigo-600">Care</span></h1>
            <p class="text-xs font-black uppercase tracking-[0.4em] text-slate-500">ConsultCare Management Cluster</p>
        </div>

        <!-- Auth Card -->
        <div class="glass-panel p-10 md:p-14 rounded-[3.5rem] relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500/5 blur-[80px] -mr-32 -mt-32"></div>
            
            <!-- System Status -->
            <div class="flex items-center gap-3 mb-10 px-4 py-2 rounded-full bg-slate-100 dark:bg-white/5 border border-slate-200 dark:border-white/10 w-fit mx-auto">
                <div class="w-2 h-2 rounded-full <?php echo $maintenance_mode ? 'bg-amber-500 animate-pulse shadow-[0_0_8px_rgba(245,158,11,0.5)]' : 'bg-green-500 shadow-[0_0_8px_rgba(34,197,94,0.5)]'; ?>"></div>
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500">
                    <?php echo $maintenance_mode ? 'Maintenance Active' : 'Cluster: Operational'; ?>
                </span>
            </div>

            <?php if ($error_message): ?>
                <div class="mb-8 p-5 rounded-2xl bg-red-500/10 border border-red-500/20 flex items-center gap-4 animate-pulse">
                    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="text-red-500"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span class="text-xs font-black uppercase tracking-widest text-red-500"><?php echo $error_message; ?></span>
                </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST" class="space-y-8">
                <div class="space-y-3">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4">Registry Identifier</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="14" x="2" y="5" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                        </div>
                        <input type="text" name="school_id" class="input-box" placeholder="2024-00000" required>
                    </div>
                </div>

                <div class="space-y-3">
                    <label class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4">Access Keyword</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-5 flex items-center pointer-events-none text-slate-400">
                            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/><path d="M21 12c-1.889 2.991-4.67 5-9 5s-7.111-2.009-9-5c1.889-2.991 4.67-5 9-5s7.111 2.009 9 5Z"/></svg>
                        </div>
                        <input type="password" id="password" name="password" class="input-box" placeholder="••••••••" required>
                        <button type="button" onclick="togglePassword()" class="absolute inset-y-0 right-0 pr-5 flex items-center text-slate-400 hover:text-indigo-500 transition-colors">
                            <svg id="icon-show" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0Z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg id="icon-hide" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="hidden"><path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"/><path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.52 13.52 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between px-2">
                    <label class="flex items-center gap-3 cursor-pointer group">
                        <input type="checkbox" class="w-5 h-5 rounded-lg border-slate-200 dark:border-white/10 bg-white dark:bg-white/5 text-indigo-600 focus:ring-0 transition-all">
                        <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 group-hover:text-indigo-500 transition-colors">Remember Intel</span>
                    </label>
                    <a href="#" class="text-[10px] font-black uppercase tracking-widest text-indigo-500 hover:text-white transition-all underline decoration-indigo-500/30 underline-offset-4">Reset Access</a>
                </div>

                <button type="submit" class="w-full py-5 rounded-[2rem] bg-indigo-600 text-white font-black text-xs uppercase tracking-[0.3em] shadow-2xl shadow-indigo-600/30 hover:bg-indigo-500 hover:-translate-y-1 transition-all duration-300">
                    Establish Connection
                </button>
            </form>

            <div class="mt-12 pt-8 border-t border-slate-200 dark:border-white/5 text-center">
                <p class="text-[10px] font-black uppercase tracking-[0.5em] text-slate-500">v1.2.0 &bull; Secure Node</p>
            </div>
        </div>

        <!-- External Support -->
        <p class="text-center mt-12 text-[10px] font-black uppercase tracking-[0.2em] text-slate-500">
            Encountering issues? <a href="#" class="text-indigo-500 hover:text-white transition-colors">Consult Support Grid</a>
        </p>
    </main>
</body>
</html>
