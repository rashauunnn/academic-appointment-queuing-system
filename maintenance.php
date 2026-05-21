<?php
require_once 'db_connect.php';

// Check if maintenance is actually on
$maintenance_mode = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'maintenance_mode'")->fetchColumn() === '1';

// If maintenance is OFF, redirect to home
if (!$maintenance_mode) {
    header("Location: index.html");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Optimization | ConsultCare</title>
    <script src="https://unpkg.com/@tailwindcss/browser@4"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --font-sans: 'Plus Jakarta Sans', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
        }
        body { 
            font-family: var(--font-sans);
            background: #020617;
            color: #f1f5f9;
            overflow: hidden;
        }
        .glass-card {
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(24px);
            border: 1px solid rgba(255, 255, 255, 0.05);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        .animate-pulse-slow { animation: pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center">

    <div class="relative z-10 text-center max-w-2xl px-6">
        <div class="mb-12 relative flex justify-center">
            <div class="absolute inset-0 bg-indigo-600/20 blur-3xl rounded-full scale-150 animate-pulse-slow"></div>
            <div class="w-24 h-24 rounded-3xl bg-indigo-600 flex items-center justify-center text-white relative shadow-2xl shadow-indigo-600/30">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
            </div>
        </div>

        <h1 class="text-6xl font-black text-white italic tracking-tighter mb-6 uppercase">System Under<br>Optimization</h1>
        <p class="text-slate-400 text-lg font-medium leading-relaxed mb-10">
            We are currently refining the neural pathways of the ConsultCare network. Access is temporarily restricted to ensure data integrity and peak performance.
        </p>

        <div class="glass-card p-6 rounded-3xl inline-flex flex-col items-center gap-4">
            <div class="flex items-center gap-3">
                <div class="w-2 h-2 rounded-full bg-indigo-500 animate-ping"></div>
                <span class="text-[10px] font-black text-indigo-400 uppercase tracking-[0.3em]">Institutional Upgrade in Progress</span>
            </div>
            <p class="text-[11px] font-bold text-slate-500 italic uppercase">Expected completion: Soon.™</p>
        </div>

        <div class="mt-16 flex justify-center gap-8 text-[10px] font-black uppercase tracking-widest text-slate-600">
            <div class="flex items-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-slate-800"></div>
                Database Sync
            </div>
            <div class="flex items-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-slate-800"></div>
                Cache Flush
            </div>
            <div class="flex items-center gap-2">
                <div class="w-1.5 h-1.5 rounded-full bg-slate-800"></div>
                Security Audit
            </div>
        </div>
    </div>

    <!-- Background Accents -->
    <div class="fixed top-0 left-0 w-full h-full pointer-events-none -z-10">
        <div class="absolute top-1/4 -left-20 w-[600px] h-[600px] bg-indigo-600/10 blur-[120px] rounded-full"></div>
        <div class="absolute bottom-1/4 -right-20 w-[700px] h-[700px] bg-violet-600/5 blur-[150px] rounded-full"></div>
    </div>

</body>
</html>
