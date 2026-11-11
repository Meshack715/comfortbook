# ComfortBook — UEAB hostel booking (immersive edition)

Single-university system. PHP 8.1+, MariaDB/MySQL, Bootstrap 5 (vendored, works offline), Three.js (vendored).

## Install on XAMPP
1. Copy this folder to `C:\xampp\htdocs\comfortbook`. Start Apache and MySQL.
2. Import `includes/comfortbook_db.sql` into a database named `comfortbook_db` (existing installs skip this).
3. Run `C:\xampp\php\php.exe bin\migrate.php` — safe to repeat. It adds the bed layer, notifications, AI and push tables, creates the push (VAPID) keys, gives every building a default design and one bed per place.
4. Copy `includes/config.local.php.sample` to `includes/config.local.php` and put your SMTP details in it.
5. Sign in as admin, open **AI settings** and paste up to three free Gemini keys (aistudio.google.com).

## What is new
- **3D explorer** (Hostel in 3D / Explore 3D): campus view, floor dollhouse, and walk mode (keyboard, mouse-drag, on-screen joystick on phones) with glide, stairs, doors, other buildings. Tap a room to step in, tap a bed to see it and book it. Beds are double-decker (lower/upper) or single. Live hold timer.
- **Bed-level booking**: atomic claim per bed (9 simultaneous claims on one bed → exactly one wins). Room status is derived from its beds, so every older page keeps working.
- **Layout Studio** (admin, deans): default designs (straight, bent wing like New Men's, L, U, curved, shared-bathroom), wings with angles/curves/attachment, rooms (position, size, bunks, singles, bathroom inside or on the floor), facilities (stairs, bathrooms, lounge, offices, entrance), drag-and-place beds. Buildings can all differ.
- **Assistant** (Gemini): different tools for student, secretary, dean and admin. Anything that changes data shows a Confirm card first and is written to the audit log. Several keys and models rotate automatically on quota limits. Works in a basic mode without keys.
- **PWA + push**: installable, offline page, notifications for new approvals (staff), confirmed/rejected bookings and expired holds (students), de-duplicated.
- **Bootstrap only**: phone = bottom tab bar and bottom sheets; desktop = sidebar and modals.

## Late-fee payments (M-Pesa STK push)
Admin → **AI & payments**. *Demo* mode needs no keys (a test payment is approved after a few seconds, labelled DEMO in the audit log). For real money use *Sandbox* or *Live* with your Daraja consumer key, secret, shortcode and passkey, and set the public https address so Safaricom can reach `api/mpesa_callback.php` (the callback URL is signed with a secret, the amount must cover the fee, and a receipt can pay only one booking). The manual reference option stays as a fallback.

## Assistant safety
Tools are filtered per role on the server. Anything that changes data needs a Confirm tap; bulk approve and broadcasts also need a 6-digit code emailed to the staff member; changing a building layout needs the admin password. Every executed action is written to the audit log.

## Needs HTTPS
Install-to-home-screen, service worker and push only work on `https://` (or `localhost`). Put the site behind HTTPS (e.g. a host with a free certificate) for the demo on phones.

## Tests used
Bed-claim race (parallel PHP processes), full book → OTP → approve flow, push encryption (RFC 8291) round-trip, migration run twice, desktop and phone browser checks.

## Security notes
Rotate any SMTP key that has been shared in a chat. Gemini keys live in the `config` table and are only shown masked.


## Notes
- The original ComfortBook look (styles.css, ueab-theme.css, header, sidebar, student pages) is preserved; new screens sit on top using Bootstrap utilities.
- Gemini: add any number of API keys in Admin → AI & payments ("Test every key" shows which work). A key from a suspended Google project returns 403 and is skipped.
