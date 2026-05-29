<?php
require_once 'security_headers.php';
require_once 'session_helper.php';
require_once 'db_connect.php';

secure_session_start();

// NOTE: This page is accessed by users via email link.
// URL example: verify_email.php?token=XYZ

$token = $_GET['token'] ?? '';
$token = trim($token);

if ($token === '') {
    http_response_code(400);
    die('Invalid verification request. Missing token.');
}

$message = '';
// Default to error until token is confirmed valid or password is set successfully
$message_type = 'error';

// If user submits new password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token_post = trim($_POST['token'] ?? '');
    $new_password = $_POST['password'] ?? '';
    $new_password_confirm = $_POST['password_confirm'] ?? '';

    // password_confirm no longer required
    if ($token_post === '' || $new_password === '') {
        $message = 'All required fields are required.';
        $message_type = 'error';
    } elseif (strlen($new_password) < 6) {
        $message = 'Password must be at least 6 characters.';
        $message_type = 'error';

    } else {
        try {
            $stmt = $pdo->prepare("SELECT user_id, email_verification_expires_at FROM users WHERE email_verification_token = ? LIMIT 1");
            $stmt->execute([$token_post]);
            $u = $stmt->fetch();

            if (!$u) {
                $message = 'Invalid or already used token.';
                $message_type = 'error';
            } else {
                $expires_at = $u['email_verification_expires_at'];
                if ($expires_at && strtotime($expires_at) < time()) {
                    $message = 'This verification token has expired. Ask admin to re-send.';
                    $message_type = 'error';
                } else {
                    $hashed = password_hash($new_password, PASSWORD_DEFAULT);

                    // Mark verified + set password + clear token
                    $stmt2 = $pdo->prepare("UPDATE users
                        SET password = ?,
                            password_set_at = NOW(),
                            email_verified_at = NOW(),
                            email_verification_token = NULL,
                            email_verification_expires_at = NULL
                        WHERE user_id = ? AND email_verification_token = ?");

                    $ok = $stmt2->execute([$hashed, $u['user_id'], $token_post]);

                    if ($ok) {
                        $message = 'Email verified successfully. You can now log in.';
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to verify. Please try again.';
                        $message_type = 'error';
                    }
                }
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email | ConsultCare</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen bg-slate-950 flex items-center justify-center p-6">
    <div class="w-full max-w-md rounded-3xl bg-white/5 border border-white/10 backdrop-blur-xl p-8">
        <h1 class="text-white text-2xl font-black italic">Verify Your Account</h1>
        <p class="text-slate-300 mt-2 text-sm">Set your password to activate your account.</p>




<?php if ($message !== ''): ?>
            <div class="mt-6 p-4 rounded-2xl <?php echo $message_type === 'error' ? 'bg-red-500/10 border border-red-500/20 text-red-200' : 'bg-emerald-500/10 border border-emerald-500/20 text-emerald-200'; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>


<?php
// Always validate token for rendering decisions (so users never get stuck with only Go-to-Login).
$userInfo = null;
try {
    $stmtInfo = $pdo->prepare(
        "SELECT user_id, full_name, school_id, email, role, current_status, email_verification_expires_at
         FROM users
         WHERE email_verification_token = ? LIMIT 1"
    );
    $stmtInfo->execute([$token]);
    $userInfo = $stmtInfo->fetch();
} catch (PDOException $e) {
    $userInfo = null;
}
$tokenValid = (bool)$userInfo;

// TEMP DEBUG (remove after testing)
$__dbg = [
    'token' => $token,
    'tokenValid' => $tokenValid,
    'user_id' => $userInfo['user_id'] ?? null,
    'expires_at' => $userInfo['email_verification_expires_at'] ?? null,
    'password_is_set' => isset($userInfo['password']) ? ($userInfo['password'] !== null ? true : false) : null,
];


// If token invalid, force error UI.
if (!$tokenValid && $message_type !== 'success') {
    $message = $message !== '' ? $message : 'Invalid or already used token.';
    $message_type = 'error';
}
?>


<?php
// Always validate token for rendering decisions (so users never get stuck with only Go-to-Login).
$userInfo = null;
try {
    $stmtInfo = $pdo->prepare(
        "SELECT user_id, full_name, school_id, email, role, current_status, email_verification_expires_at
         FROM users
         WHERE email_verification_token = ? LIMIT 1"
    );
    $stmtInfo->execute([$token]);
    $userInfo = $stmtInfo->fetch();
} catch (PDOException $e) {
    $userInfo = null;
}
$tokenValid = (bool)$userInfo;

// TEMP DEBUG (remove after testing)
$__dbg = [
    'token' => $token,
    'tokenValid' => $tokenValid,
    'user_id' => $userInfo['user_id'] ?? null,
    'expires_at' => $userInfo['email_verification_expires_at'] ?? null,
    'password_is_set' => isset($userInfo['password']) ? ($userInfo['password'] !== null ? true : false) : null,
];


// If token invalid, force error UI.
if (!$tokenValid && $message_type !== 'success') {
    $message = $message !== '' ? $message : 'Invalid or already used token.';
    $message_type = 'error';
}
?>

<?php if ($message_type === 'success'): ?>
            <div class="mt-6">
        <a href="login.php?error=verify_required" class="block w-full text-center py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-black uppercase tracking-widest text-xs" onclick="return true;">
            Go to Login
        </a>
    </div>
<?php else: ?>
    <?php if ($tokenValid): ?>

        <div class="mt-6 p-4 rounded-2xl bg-white/5 border border-white/10">
            <h2 class="text-white font-black text-sm uppercase tracking-widest">Confirm your details</h2>
            <div class="mt-3 text-slate-200 text-sm space-y-2">
                <div class="flex justify-between gap-4"><span class="text-slate-400">Full Name</span><span class="font-semibold"><?php echo htmlspecialchars($userInfo['full_name']); ?></span></div>
                <div class="flex justify-between gap-4"><span class="text-slate-400">Role</span><span class="font-semibold"><?php echo htmlspecialchars($userInfo['role']); ?></span></div>
                <div class="flex justify-between gap-4"><span class="text-slate-400">ID Number</span><span class="font-semibold"><?php echo htmlspecialchars($userInfo['school_id']); ?></span></div>
                <div class="flex justify-between gap-4"><span class="text-slate-400">Email</span><span class="font-semibold"><?php echo htmlspecialchars($userInfo['email']); ?></span></div>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" class="mt-6 space-y-4">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>" />

        <div>
            <label class="block text-xs font-black uppercase tracking-widest text-slate-300 mb-2">New Password</label>
            <input type="password" name="password" class="w-full bg-slate-900/60 border border-white/10 rounded-2xl px-4 py-3 text-white outline-none" required minlength="6" />
        </div>

        <div>
            <label class="block text-xs font-black uppercase tracking-widest text-slate-300 mb-2">Confirm Password</label>
            <input type="password" name="password_confirm" class="w-full bg-slate-900/60 border border-white/10 rounded-2xl px-4 py-3 text-white outline-none" required minlength="6" />
        </div>

        <button type="submit" class="w-full py-3 rounded-2xl bg-indigo-600 hover:bg-indigo-500 text-white font-black uppercase tracking-widest text-xs">
            Verify & Set Password
        </button>
    </form>
<?php endif; ?>
    </div>
</body>
</html>

