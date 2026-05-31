# Appointment booking fix TODO (JSON reliability)

- [ ] Update `process_booking.php` so it always returns valid JSON for AJAX calls (no redirects, no `die()` outputs).
- [ ] Keep existing booking logic: 
  - [ ] Available instructors can be booked.
  - [ ] Busy instructors cannot be booked until `busy_until` ends (timer shown).
  - [ ] On Leave instructors cannot be booked.
  - [ ] `faculty_availability` block duty window prevents booking on that date/time.
- [ ] Fix session guard in `process_booking.php` to accept either `$_SESSION['student_id']` or `$_SESSION['user_id']` for students.
- [ ] Add a safe check that `$pdo` is available; if not, return JSON with an error.
- [ ] Run quick endpoint test by submitting booking and verifying JSON response in browser devtools/network.

