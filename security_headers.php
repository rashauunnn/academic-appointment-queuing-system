<?php
// security_headers.php

// Allow embedding in frames (required for AI Studio iframe preview)
header("X-Frame-Options: ALLOWALL");

// Prevent Content Sniffing
header("X-Content-Type-Options: nosniff");

// Basic XSS Protection
header("X-XSS-Protection: 1; mode=block");

// Referrer Policy
header("Referrer-Policy: no-referrer-when-downgrade");

// Relaxed Content Security Policy for flawless loading of design libraries (Tailwind play CDN, Google Fonts, SweetAlert, Lucide Icons, etc.)
header("Content-Security-Policy: default-src 'self' * 'unsafe-inline' 'unsafe-eval' data: blob:; script-src 'self' 'unsafe-inline' 'unsafe-eval' *; style-src 'self' 'unsafe-inline' *; font-src 'self' data: *; img-src 'self' data: blob: *; connect-src 'self' *; frame-ancestors 'self' *;");


