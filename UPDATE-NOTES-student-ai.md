# Student AI + fixes (v3) — upload over the existing files, nothing to run, no database changes

**Why the student buttons were dead**
- student/book.php printed a PHP warning inside its <script> whenever there was no active booking, which killed every button on the page. Fixed.
- approvals: the code called send_confirmation_email(), which did not exist, so approving crashed. Added it, fixed the rollBack crash, and approval now emails properly.

**Student assistant now does everything in the chat (action cards, no redirects)**
Find a bed (asks a preference, shows tappable beds) -> Hold this bed -> enter the emailed code in a card -> pay the late fee by M-Pesa in a card with live result -> done.
Also: keep/release room (Phase 1), resend code, pay by Paybill code. New file: includes/student_actions.php.

**Emails to BOTH sides** (includes/notify.php: booking_emails)
- Booking complete (code verified, or paid): student "Booking received" + Secretary/Dean of that wing "awaiting approval".
- Approved / rejected (approvals page, AI approve/reject, bulk approve): student + wing staff. Bulk sends the approver one summary.

Files changed: includes/{student_actions,notify,mailer,mpesa}.php, includes/ai/{tools,chat,ui_tools}.php, includes/explore_ui.php,
user/approvals.php, student/book.php, api/{verify_otp,submit_mpesa}.php, assets/js/copilot.js

## Mobile table scroll fix
assets/css/ueab-theme.css: .table-responsive had overflow:hidden (cut tables off). Now scrolls sideways; tables keep a sensible min width.
user/partial/footer.php: any table not already wrapped gets wrapped automatically, so every staff page scrolls.
