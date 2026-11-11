# Payments update

1. Upload the files (same folders). Nothing to run by hand: the database upgrades itself the first time a payment is touched
   (or run `php bin/migrate.php`). It adds columns/tables only; nothing is deleted.
2. New menu item for admin, deans and secretaries: **Collect payment** (`user/collect_payment.php`).
3. Optional cron (every minute): `php bin/mpesa_sweep.php` settles prompts nobody is watching.
4. AI & payments settings: new "Demo mode result" to rehearse cancel / wrong PIN / no answer without money.

Outcomes handled: paid, cancelled on phone, wrong PIN, low balance, no answer/timeout, prompt could not be sent, network down,
Safaricom busy, bad phone number, expired token (auto-refresh), callback never arrives (status check), callback arrives late
(still applied), duplicate callbacks, double-click, two prompts for one fee (second flagged for refund), short payment,
booking cancelled while paying, money with no matching request (kept for staff in "needs attention").
