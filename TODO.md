# TODO

## Completed
- N/A

## Next Steps
1. Update `admin_dashboard.php` to temporarily bypass PHPMailer/email verification during admin account creation:
   - Remove generation of email_verification_token/email_verification_expires_at.
   - Stop calling `mail/send_verification_email.php`.
   - Create the user with admin-provided password (required) hashed.
   - Set `email_verified_at = NOW()` so the user can log in immediately.

2. Update the admin “Register New System Node” form in `admin_dashboard.php`:
   - Make password field required (and reflect behavior).
   - Remove/ignore any email verification note/optional password wording.

3. Update `verify_email.php` to remove password confirmation requirement:
   - Only require a single `password` input.
   - Stop checking `password_confirm`.

4. Quick validation after edits:
   - Create a user from admin dashboard.
   - Confirm login works immediately for created user.
   - Confirm verify_email.php still works if accessed (but without password confirmation).

