<?php
// security_headers.php

// Prevent Clickjacking
header("X-Frame-Options: SAMEORIGIN");

// Prevent Content Sniffing
header("X-Content-Type-Options: nosniff");

// Basic XSS Protection
header("X-XSS-Protection: 1; mode=block");

// Referrer Policy
header("Referrer-Policy: strict-origin-when-cross-origin");

// Content Security Policy (Basic) - Allow scripts from trusted sources
// Note: In a production app, this should be more granular
header("Content-Security-Policy: default-src 'self' tel: mailto:; script-src 'self' 'unsafe-inline' https://cdn.tailwindcss.com https://unpkg.com https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data: https://images.unsplash.com; connect-src 'self'; frame-ancestors 'self';");
