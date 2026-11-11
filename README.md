# ComfortBook

**Smart, fair and auditable hostel booking for the University of Eastern Africa, Baraton (UEAB)**

A semester-based hostel allocation system with a two-phase booking flow, passwordless student magic links, bed-level reservations, M-Pesa late-fee collection, a role-based staff portal, an interactive 3D hostel map, and an embedded AI assistant that can answer questions *and carry out tasks* for Admins, Deans, Secretaries and Students.

> **In one sentence:** students get one personalised email link, pick a real bed on a live map, confirm with a one-time code, and (only if they are late) pay the fee by M-Pesa, all while wing staff approve, track and audit everything from one dashboard.

---

## Table of contents

1. [Team](#-team)
2. [The problem](#-the-problem)
3. [Our solution at a glance](#-our-solution-at-a-glance)
4. [Who uses it](#-who-uses-it)
5. [How booking works (end to end)](#-how-booking-works-end-to-end)
6. [The late-fee rules](#-the-late-fee-rules)
7. [Feature tour](#-feature-tour)
8. [The AI assistant](#-the-ai-assistant)
9. [M-Pesa payments](#-m-pesa-payments)
10. [Fairness, security and audit](#-fairness-security-and-audit)
11. [Architecture and tech stack](#-architecture-and-tech-stack)
12. [Project structure](#-project-structure)
13. [Data model](#-data-model)
14. [Installation](#-installation)
15. [Configuration](#-configuration)
16. [Demo walkthrough for judges](#-demo-walkthrough-for-judges)
17. [Edge cases we handle](#-edge-cases-we-handle)
18. [Roadmap](#-roadmap)

---

## 👥 Team

| # | Name | Reg. No. |
|---|------|----------|
| 1 | Juniormeshack Odondi | SJUNOD2621 |
| 2 | Ochieng Martin Kaunda | SMAROC2611 |
| 3 | Junior Joseph | SJUNJO2511 |
| 4 | Sandra Bonareri Obino | SOBISA2511 |
| 5 | David Kiamba | SKIADA2411 |

**Institution:** University of Eastern Africa, Baraton
**Project type:** Hackathon / Semester Hostel Allocation System
**Stack:** PHP 8 · MySQL/MariaDB · Bootstrap 5 · Three.js · PHPMailer · TCPDF · Safaricom Daraja (M-Pesa) · Gemini AI

---

## 📌 The problem

Every semester, hundreds of UEAB students compete for the same hostel rooms. The paper-based process means:

- Long queues at the hostel office and repeated trips to check status
- No visibility of which rooms or beds are already taken
- Double allocations and disputes that are hard to resolve because nothing is recorded
- Deans and Secretaries in separate wings with no shared view of what is happening
- Late arrivals handled informally, with late fees collected in cash and hard to reconcile

## 💡 Our solution at a glance

ComfortBook replaces the paper lists with a **fair, transparent, time-boxed digital process**:

| Pain point | What ComfortBook does |
|---|---|
| Queues and office visits | Students book from their phone through a single emailed link, no account or password |
| "Is this room taken?" | Bed-level availability, live, on an interactive 3D map of each building |
| Double booking | Atomic bed claims inside a database transaction; a taken bed is never offered twice |
| Unfair first-come chaos | Two scheduled phases: current residents decide first, then everyone else picks |
| Late arrivals | Automatic late fee, paid by M-Pesa STK push, receipt attached to the booking |
| Staff blind spots | Per-wing dashboards, approvals queue, unplaced-student list, reports and a full audit log |
| Hard-to-use systems | An AI assistant in plain language that can search beds, hold them, send the M-Pesa prompt, approve bookings and more |

---

## 🎯 Who uses it

| Role | Scope | What they do |
|------|-------|--------------|
| **Admin** | Both wings | Manages semesters, buildings, blocks, rooms and beds, 3D layouts, students, staff accounts, AI and payment settings, and sees the audit log |
| **Dean (Men / Women)** | Own wing only | Sets Phase 1 / Phase 2 windows, controls late booking, sends magic links, approves bookings, collects payments, generates reports |
| **Secretary (Men / Women)** | Own wing only | Approves or rejects bookings, resolves unplaced students, assigns rooms, collects late fees on the spot |
| **Student** | Self only | Opens a personalised email link, keeps/releases a room or books a new bed, verifies a code, pays any late fee |

**Students never log in.** They receive a personalised **magic link** by email. It is token-based (only a SHA-256 hash is stored), time-limited, and tied to one student and one semester.

Staff access is **scoped by wing**: a Men's Dean cannot see or change Women's wing data, and the same holds in the AI assistant.

---

## 🔄 How booking works (end to end)

```
Dean schedules windows ──► Dean sends magic links ──► Student opens link
                                                             │
        ┌────────────────────────────────────────────────────┤
        ▼                                                    ▼
   PHASE 1 (current residents)                       PHASE 2 (choose a room)
   Keep my room  /  Release my room                  Pick a free bed on the map
        │                                                    │
        └──────────────► 6-digit code emailed ◄──────────────┘
                                 │
                                 ▼
                       Student verifies the code
                                 │
                 ┌───────────────┴────────────────┐
                 ▼                                ▼
        On time (no fee due)          Past the windows (late fee due)
                 │                                │
                 │                      Pay by M-Pesa (STK push
                 │                      or Paybill code)
                 └───────────────┬────────────────┘
                                 ▼
                  Pending approval by Dean / Secretary
                                 │
                  Approved ──► room confirmed + emails to everyone
                  Rejected ──► reason emailed, bed returned to the pool
```

### Phase 1: current residents decide first
Students who already hold a room choose to **keep** it (they stay put for next semester) or **release** it (it becomes available to others in Phase 2). Releasing never costs a fee.

### Phase 2: pick a real bed
Everyone without a room chooses an available **bed** (not just a room), filtered to their own wing. The chosen bed is **held for a short time** while the student confirms with a one-time code. If the code is not entered in time the hold expires and the bed goes straight back to the pool.

### Confirmation, payment, approval
The code proves the student controls the email on file. If a late fee applies it is paid by M-Pesa. Finally a Dean or Secretary approves, and the student and the wing staff are emailed at every important step.

### Booking states
`pending_otp` → `pending_payment` (only if a fee is due) → `pending_approval` → `confirmed`, with `cancelled` / `rejected` as exits. Every transition is timestamped.

---

## ⏱️ The late-fee rules

Phases and late booking are controlled per wing by the Dean from **Phase windows**:

| Setting | Meaning |
|---|---|
| Phase 1 and Phase 2 start/end | The scheduled windows for that wing |
| `late_booking_enabled` | Whether students may still book after the last window |
| `late_booking_until` | Optional cut-off for late booking (defaults to the day the semester starts) |
| `penalty_amount` | The late fee in KES (default 200) |

**After the last window ends**, a student with no booking can still book **only after paying the late fee**. If late booking is switched off or past its cut-off, booking is closed and the student is directed to the Dean or Secretary.

### The fee follows the clock when the booking is *completed*
Opening the email link does not stop the clock, and neither does holding a bed. The fee is evaluated at the moment the student **completes** the booking (verifies the code):

- Student opened the link and held a bed in Phase 2, but verifies the code **after Phase 2 closed** → the late fee is added to that booking and it moves to *pending payment* instead of straight to approval.
- Student opened the link and **never finished** → nothing is reserved (the hold expires), and when they return after the windows they must pay the late fee before they can choose a bed.
- Late booking has been switched off or has expired **while the student was mid-booking** → the hold is cancelled and the bed released, with a clear message.
- Fee already paid for that semester (for example collected by the Secretary) → the student is never charged twice.

This rule lives in one shared function, `booking_settle_fee()` in `includes/booking_state.php`, used by both the normal verification API and the AI assistant, so the two paths can never disagree.

---

## 🧰 Feature tour

### For students (no login, mobile-first)
- One personalised link, single page for the whole flow, installable as a **PWA** with offline fallback
- Clear status banner at every moment: *not open yet · Phase 1 live · Phase 2 live · between phases · late booking · closed*, with countdowns and a timeline of all windows
- **Bed-level selection** with bunk (lower/upper), single, and en-suite information
- **Interactive 3D explorer** of the hostel buildings to see where a bed actually is
- Emailed 6-digit code, resend on request
- **M-Pesa STK push** to pay the late fee, with a live result, or submit a Paybill confirmation code
- In-app and web-push notifications, and emails on submission, approval and rejection

### For Deans and Secretaries
- **Dashboard** with occupancy, pending approvals, unpaid late fees and wing-scoped stats
- **Phase windows** page with a live "what students see right now" preview
- **Send links**: bulk or individual magic links, with tracking of who has not received, not opened and already opened a link
- **Approvals queue**: approve or reject (with reason), single or bulk, with emails to students and staff
- **Unplaced students**: find students with no bed and no live booking, then assign or prompt them
- **Collect payment**: prompt the student's phone from the office, record the fee against the correct semester
- **Reports**: downloadable PDF reports (TCPDF) and downloads centre
- **Student management** with CSV import and active/inactive status

### For Admins
- Semesters, blocks/buildings, rooms and beds management
- **Layout Studio** to design each building floor by floor (wings, bunks, singles, bathroom type) with presets, which drives the 3D map
- **3D map** of the whole estate
- Staff accounts, roles and wing scopes
- **AI & payments settings**: AI on/off, API keys, M-Pesa environment, demo mode, push notifications
- **AI log** and **audit log**: who asked the assistant what, and who did what

### Platform
- Progressive Web App (service worker, manifest, offline page)
- Web push notifications
- Pretty URLs and branded 403/404/500 error pages
- Database self-migration: new columns and tables are added automatically, nothing is ever dropped

---

## 🤖 The AI assistant

ComfortBook's assistant is not a FAQ bot. It is wired into the live system through a **role-aware tool layer** (`includes/ai/tools.php`), so it answers from real data and, with confirmation, performs real actions.

**Students can say:** *"Find me a lower bunk on the first floor with an en-suite."* → the assistant recommends and shows tappable beds → *"Hold this one"* → enters the emailed code in a card → pays the late fee by M-Pesa in a card with a live result, all inside the chat.

| Capability | Student | Dean / Secretary | Admin |
|---|:--:|:--:|:--:|
| Vacancy summary and bed search (by block, floor, kind, en-suite) | ✅ | ✅ | ✅ |
| Rank beds by preference (`recommend_beds`) | ✅ | | |
| "What is my status and what must I do next?" (window-aware) | ✅ | | |
| Hold a bed, keep/release a room, verify or resend the code | ✅ | | |
| Pay the late fee by M-Pesa, submit a Paybill code | ✅ | | |
| Cancel own hold | ✅ | | |
| Pending approvals, approve / reject booking | | ✅ | ✅ |
| Bulk approve (confirmation **plus** emailed code) | | ✅ | ✅ |
| Daily briefing (approvals, oldest wait, unpaid fees, expiring holds, vacancy) | | ✅ | ✅ |
| Find a student, check whether they paid, view M-Pesa attempts | | ✅ | ✅ |
| Prompt a student's phone for the late fee, send a KES 1 test prompt | | ✅ | ✅ |
| Message a student, broadcast to unplaced / awaiting-payment / holding groups | | ✅ | ✅ |
| Layout overview, apply a layout preset (admin password required) | | | ✅ |
| Recent audit log | | | ✅ |

**Safety by design**
- **Every write needs confirmation.** The assistant proposes an *action card*; nothing changes until the user taps confirm.
- **Sensitive actions need step-up verification:** an emailed code (bulk approve, broadcast) or the admin password (re-laying a building).
- **Role and wing scoping is enforced on the server**, not by the prompt. A student's assistant cannot call staff tools.
- **Everything is logged** in the AI log.

**Built to stay online:** the Gemini client rotates across several API keys, remembers which key/model is unhealthy and skips it without a network call, races the next candidate when one is slow, and discovers available models from Google automatically, so a retired model drops out without a code change.

---

## 💳 M-Pesa payments

Late fees are collected through **Safaricom Daraja STK push** with a payment layer built for the real world (see `UPDATE-NOTES-payments.md`):

- Student, Dean/Secretary and AI can all trigger a prompt; the **amount is always decided by the system**, never typed by the user
- Live result polling, plus a background sweeper (`bin/mpesa_sweep.php`) that settles prompts nobody is watching
- **Handled outcomes:** paid · cancelled on phone · wrong PIN · low balance · no answer/timeout · prompt could not be sent · network down · Safaricom busy · bad phone number · expired token (auto-refresh) · callback never arrives (status query fallback) · callback arrives late (still applied) · duplicate callbacks · double-click · two prompts for one fee (second flagged for refund) · short payment · booking cancelled while paying · money with no matching request (kept for staff review)
- Receipt is stored and shown on the booking; the fee is credited to the **correct semester**
- **Demo mode** lets you rehearse each outcome (cancel, wrong PIN, no answer) without moving any money

---

## 🔐 Fairness, security and audit

- **No passwords for students.** Magic-link tokens are random, stored only as SHA-256 hashes, expire, and are bound to a student and a semester
- **Atomic bed claims** in a DB transaction, so two students can never hold the same bed
- **Expiring holds:** unverified holds release themselves, so beds are never locked by someone who walked away
- **Per-wing isolation** for staff and for the AI
- **Prepared statements** throughout (PDO) and hashed staff passwords (bcrypt)
- **Server-side validation** of every phase and fee rule; the UI is never trusted
- **One source of truth** for "what can this student do right now" (`includes/booking_state.php`), shared by the student page, the booking API, the late-fee check and the AI
- **Audit log** of staff actions (approvals, payments, late-booker marking, layout changes)
- **Secrets kept out of the repo:** SMTP, M-Pesa and AI keys live in `config.local.php` / admin settings, never in source

---

## 🏗️ Architecture and tech stack

| Layer | Technology |
|---|---|
| Backend | PHP 8, PDO, plain MVC-style pages and JSON APIs |
| Database | MySQL / MariaDB |
| Frontend | Bootstrap 5, Font Awesome, vanilla JS, Three.js (3D) |
| Email | PHPMailer over SMTP (Brevo relay by default) |
| PDF and QR | TCPDF, Endroid QR |
| Payments | Safaricom Daraja STK push |
| AI | Google Gemini with multi-key failover and tool calling |
| Offline / push | Service worker, Web Push |

**Request flow:** page or JS → `api/*.php` (JSON) → shared rules in `includes/` → MySQL. The same rule functions power the website, the APIs and the AI tools, which is why the three always agree.

---

## 📁 Project structure

```
comfortbook/
├── index.php, logout.php, reset.php, set_password.php   Staff sign-in & password flows
├── student/                 Magic-link student experience (book.php, explore.php, _guard.php, _layout.php)
├── user/                    Staff portal (dashboard, approvals, phase_windows, send_links, collect_payment,
│                            students, buildings, rooms, layout_studio, map3d, reports, ai_settings, ai_log, ...)
├── api/                     JSON endpoints (book_room, verify_otp, mpesa_stk/status/callback, ai_chat, ...)
├── includes/
│   ├── config.php, auth.php           Bootstrap, sessions, role checks
│   ├── booking_state.php              Windows, late-booking rules, booking_settle_fee()
│   ├── beds.php                       Bed claims, holds, reconciliation
│   ├── mpesa.php, payments_staff.php  Daraja client and staff payment helpers
│   ├── student_actions.php            Student actions callable from the AI chat
│   ├── notify.php, mailer.php         In-app, push and email notifications
│   ├── ai/                            Gemini client, tools, chat, logging, UI cards
│   ├── migrations/                    Self-applying schema upgrades
│   └── comfortbook_db.sql             Full schema + seed data
├── assets/                  CSS, JS (copilot, scene3d, studio), logos, vendor libs (bootstrap, three, fontawesome)
├── bin/                     migrate.php, mpesa_sweep.php (cron)
├── tcpdf/, vendor/          PDF and Composer dependencies
├── sw.js, manifest.php, offline.html                     PWA
└── error/                   Branded error pages
```

---

## 🗄️ Data model

Core tables (see `includes/comfortbook_db.sql`):

| Table | Purpose |
|---|---|
| `users` | Admins, Deans, Secretaries with role and wing scope |
| `students` | Registration number, wing (gender), current room/bed, Phase 1 choice, late-booker flag, late-fee-paid semester |
| `semesters`, `semester_windows` | Academic terms and per-wing Phase 1 / Phase 2 windows |
| `blocks`, `rooms` (+ `beds`) | Buildings, rooms and individual beds with kind (lower/upper/single) and status |
| `magic_links` | Hashed, expiring, per-student per-semester booking links |
| `bookings` | Every booking attempt with status, OTP, penalty amount, M-Pesa reference and timestamps |
| `mpesa_payments` | Every STK attempt and its outcome, receipt and review flag |
| `audit_log` | Who did what, when |
| `config` | System settings (windows, fee, late-booking, AI, M-Pesa) |

---

## 🚀 Installation

**Requirements:** PHP 8.1+ (with `pdo_mysql`, `curl`, `mbstring`, `gd`), MySQL/MariaDB, Apache with `mod_rewrite` (XAMPP works well).

1. **Copy** the project into your web root, for example `htdocs/comfortbook`.
2. **Create the database** and import the schema:
   ```bash
   mysql -u root -e "CREATE DATABASE comfortbook CHARACTER SET utf8mb4;"
   mysql -u root comfortbook < includes/comfortbook_db.sql
   ```
3. **Set the database credentials** in `includes/config.php` (or `config.local.php`).
4. **Configure email**: copy `includes/config.local.php.sample` to `includes/config.local.php` and add your SMTP details.
5. **Open the site** in your browser and sign in as a seeded staff user, then change the password.
6. *(Optional)* **Cron** every minute to settle unattended M-Pesa prompts:
   ```bash
   * * * * * php /path/to/comfortbook/bin/mpesa_sweep.php
   ```
7. *(Optional)* Run `php bin/migrate.php` to apply schema upgrades manually. They also apply themselves the first time a payment feature is used.

> Change every seeded password before any real deployment.

## ⚙️ Configuration

| Where | What |
|---|---|
| `includes/config.local.php` | SMTP host/user/password/port/from (never commit this file) |
| **Staff → Phase windows** | Phase 1 / Phase 2 dates per wing, late booking on/off, late fee amount, late-booking cut-off |
| **Staff → AI & payments** | AI on/off, Gemini keys, M-Pesa environment (sandbox/live) and credentials, demo outcome, push notifications |

---

## 🎬 Demo walkthrough for judges

1. **Admin** signs in, shows the **3D map** and **Layout Studio** for a hostel block.
2. **Dean of Men** opens **Phase windows**, sees the live "what students see" preview, then **Send links** to a test student.
3. **Student** opens the emailed link on a phone: status banner, available beds, picks one on the 3D explorer.
4. Student enters the **emailed 6-digit code**. Booking moves to *pending approval*.
5. Ask the **AI assistant** (student): *"Find me a lower bunk with an en-suite"*, then *"hold it"*. Everything happens in chat cards.
6. **Secretary** sees the approval, approves it by hand or by asking the assistant. Student and staff receive emails.
7. Move the window so Phase 2 has ended: a new student is asked to **pay the late fee** by M-Pesa (use **demo mode** to rehearse cancel / wrong PIN / success). Show that a student who held a bed and verified *after* the window still gets the fee.
8. Admin opens the **audit log** and **AI log** to show full traceability.

---

## 🧩 Edge cases we handle

- Two students tapping the same bed at the same moment (atomic claim, loser is told "just taken")
- Hold expires before the code is entered (bed returns to the pool, student notified)
- Student opens the link in time but completes after the window (fee settled at completion)
- Late booking turned off while a student is mid-booking (hold cancelled cleanly)
- Fee paid in the office and then the student books online (never charged twice)
- Fee paid in the wrong semester window (credited to the target semester)
- Wrong/expired OTP, resend OTP, repeat verification
- M-Pesa: callback never arrives, arrives late, arrives twice, or the student pays twice
- A staff session leaking into a student page (kept strictly separate)
- Tables on mobile staff screens (auto-scroll wrapper)

## 🗺️ Roadmap

- SMS delivery of links and codes alongside email
- Roommate requests and preference matching
- Automatic waitlist when a wing is full
- Analytics across semesters (demand by block, time-to-book)

---

**ComfortBook**: fewer queues, fewer disputes, and a hostel allocation process every student can trust.