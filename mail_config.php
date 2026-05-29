<?php
// mail_config.php
// Configure your SMTP credentials here.
// Keep this file OUT of public view if possible.

return [
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => 587,
    'smtp_username' => 'shaquieldaniel21@gmail.com',
    'smtp_password' => 'trrh kybj khym vtqc',
    'smtp_secure' => 'tls', // 'tls' or 'ssl'

    'from_email' => 'shaquieldaniel21@gmail.com',
    'from_name'  => 'ConsultCare Registration',

    // Token URL settings
    // Change base URL to match your server.
    'app_base_url' => 'http://localhost/academic_system',

    // Token TTL (seconds)
    'verification_token_ttl' => 3600, // 1 hour
];

