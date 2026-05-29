# TODO: Role-separated PHP sessions

- [ ] Update `session_helper.php` to support separate session cookie names (Admin/Student/Faculty) via a helper that starts the correct session.
- [ ] Update `login_process.php` to choose the correct session namespace/cookie name based on `role` and set session variables there.
- [ ] Update `login.php` to no longer redirect dashboards using a shared session; ensure it starts the neutral/pre-auth session or clears role-specific session cookies.
- [ ] Update `student_dashboard.php`, `faculty_dashboard.php`, `admin_dashboard.php` to start the correct role session before calling `check_session_role()`.
- [ ] Update `logout.php` to destroy the active role session only (and optionally clear all role sessions).
- [ ] Update any API endpoints that depend on session state (if present) to start the correct role session.
- [ ] Hard test:
  - Open 3 tabs: admin_dashboard.php, student_dashboard.php, faculty_dashboard.php
  - Log in each account in each tab
  - Refresh each tab independently and verify the role stays.

