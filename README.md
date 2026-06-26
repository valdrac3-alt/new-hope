# Dental Clinic Management And Recording System
A full-featured, web-based clinic management system built with **PHP 8.4**, **MySQL**, and **Bootstrap 5**. Designed for dental clinics to manage patients, appointments, billing, dental records, and staff — all from a single, role-protected admin panel.

---

## Features

### Authentication & Security
- Secure login with brute-force lockout (5 failed attempts → 5-minute cooldown), backed by both a session counter and an IP-based rate limiter
- **Honeypot field** to silently catch and block bots
- **OTP-based password reset** delivered via SMS (Semaphore) and Email (Resend)
- Session hardening: `httponly`, `strict_mode`, `SameSite=Lax`, 8-hour TTL
- Session ID regeneration on login, CSRF token validation on all state-changing forms
- API rate limiting (per-IP, per-endpoint) and token-based API authentication
- Bcrypt password hashing (PHP `PASSWORD_BCRYPT`)

### Patient Management
- Register patients with full profile: name, date of birth, gender, civil status, address, occupation, blood type, allergies, medical history, illness history, emergency contact
- Auto-generated unique **patient code** per record
- **Soft-delete / archive** — patients are never hard-deleted; all appointments, billing, and dental records are preserved
- Restore archived patients at any time
- Search by name, patient code, or phone number with pagination

### Appointments
- Book appointments by patient, service, doctor, date, and time
- **Walk-in registration** — auto-assigns the next available slot for today based on clinic schedule
- Status tracking: `pending` → `confirmed` → `completed` / `cancelled` / `no-show`
- Filter by status, date, and doctor
- **Calendar view** (month/day) with color-coded appointments per doctor
- Pre-fill booking form from a patient's profile page
- Printable **appointment slip**

### Treatments & Dental Records
- Record dental treatments linked to appointments
- Store clinical notes, tooth chart data, prescriptions, and service rendered
- Print **dental record**, **prescription (Rx)**, and **dental fitness certificate** — all print-ready

### Billing
- Create bills linked to appointments
- Partial payment support — track `amount_due` vs `amount_paid`
- Bill statuses: `unpaid`, `partial`, `paid`
- Dashboard shows outstanding balance and unpaid bill count
- Filter by status, date range, and patient
- Printable **payment receipt**

### Analytics (Admin only)
- KPI cards: total patients, today's appointments, monthly revenue, pending bills
- Month-over-month trend comparisons (↑↓ percentage change)
- Revenue, appointment, and patient growth charts
- Range filter: Last 7 days, Last 30 days, This Month, Year to Date
- Navigable month-by-month history

### Reports (Admin only)
- Monthly appointment and revenue summary
- Print-ready daily schedule
- Export to PDF

### Schedule Management (Admin only)
- Set clinic open/close hours per day of the week
- Configure slot duration (e.g. every 30 minutes)
- Block specific dates (holidays, closures)

### User Management (Admin only)
- Two roles: `admin` and `staff`
- Add/edit users; toggle active/inactive status
- Admins cannot deactivate their own account

### Notifications
- In-app notification bell with unread badge
- Mark individual or all notifications as read
- Session-cached notification count (auto-busted on update)

### Audit Logs (Admin only)
- Every significant action is logged: login, patient archive/restore, billing, user changes
- Filter by action keyword or module
- Paginated (50 per page)

### Dark Mode
- Full dark mode support — toggled via a button in the header
- Persisted in `localStorage` across sessions
- Applied before page render to prevent flash of unstyled content (FOUC)

### Accessibility
- Skip-to-content link for keyboard users
- Proper focus ring styles (WCAG AA compliant contrast)
- `prefers-reduced-motion` support
- Screen reader utilities (`.sr-only`)

### Performance
- **PJAX navigation** — pages load without full reload; the sidebar and header stay mounted
- **OPcache** enabled — PHP files are parsed once, cached in memory
- Static assets served with long-lived cache headers via Caddy (`max-age=31536000` for Bootstrap/fonts)
- Server-side session cache for frequently-accessed data (doctor list, service list, notification count)

---

## Database Tables

| Table | Description |
|---|---|
| `users` | System users (admin / staff) |
| `patients` | Patient records with full medical profile |
| `services` | Dental services with pricing and duration |
| `doctors` | Clinic doctors with license and specialization |
| `schedules` | Weekly clinic hours per day |
| `blocked_dates` | Specific dates when clinic is closed |
| `appointments` | Booked and walk-in appointments |
| `dental_records` | Treatment records per appointment |
| `bills` | Billing records with partial payment tracking |
| `notifications` | In-app alerts for users |
| `audit_logs` | Full action history for accountability |
| `rate_limits` | API-level brute-force rate tracking |
| `api_tokens` | Token-based API authentication |
| `inventory` | *Scaffolded for a future stock-tracking feature — not yet read or written by any module* |
| `settings` | *Scaffolded for future system preferences — not yet read or written by any module* |

---

## Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.4 (FrankenPHP) |
| Database | MySQL 8 / MariaDB (PDO) |
| Frontend | Bootstrap 5.3, Bootstrap Icons |
| Web Server | Caddy (via Caddyfile) |
| Containerization | Docker (FrankenPHP image) |

---

## Local Setup (Laragon / XAMPP)

1. Copy the whole project folder into `C:\laragon\www\` (or `htdocs` for XAMPP).
2. Start **Apache** and **MySQL** in Laragon/XAMPP.
3. Open **phpMyAdmin** (or HeidiSQL) → create a database named `cap` → import `database/cap.sql`.
4. Copy `.env.example` to `.env` and fill in your local values (the defaults already match a stock Laragon/XAMPP install — `root` user, blank password, port `3306`).
5. Visit `http://localhost/cap/` (adjust the folder name to whatever you named it).

> ⚠️ If you re-extract the project from a fresh zip, remember the `.git` folder gets wiped — run `git init` and push again, and re-copy your `.env` file (it's git-ignored, so it won't be in the zip).

## Running with Docker

```bash
# Build and run
docker build -t cap-dental .
docker run -p 8080:8080 --env-file .env cap-dental
```

The app will be available at `http://localhost:8080`.

---

## Environment Variables

| Variable | Description |
|---|---|
| `DB_HOST` | MySQL host (`localhost` for Laragon/XAMPP) |
| `DB_PORT` | MySQL port (`3306` by default) |
| `DB_NAME` | Database name |
| `DB_USER` | Database user (`root` by default locally) |
| `DB_PASS` | Database password (blank by default on Laragon/XAMPP) |
| `APP_NAME` | Clinic name shown in the UI |
| `BASE_URL` | Base URL of the app |
| `SEMAPHORE_API_KEY` | SMS OTP delivery *(optional)* |
| `RESEND_API_KEY` | Email OTP delivery *(optional)* |

> If SMS/Email API keys are not set, the system runs in **demo mode** — the OTP code is displayed directly on screen for testing.
>
> Note: `.env.example` also lists `HCAPTCHA_SITE_KEY` / `HCAPTCHA_SECRET` as placeholders for a possible future hCaptcha integration, but no code currently reads them — they have no effect yet.

---

## Default Credentials

After importing `database/cap.sql`, the default login is:

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `password` |

**Change this immediately after first login.**

---

## Documentation & Lessons

Beyond this README, there's a set of step-by-step lesson guides in
`docs/lessons/` written for anyone — client, panel, or new teammate — who
needs to understand *how* the system works, not just what it does:

| Lesson | Covers |
|---|---|
| [`01-getting-started.md`](docs/lessons/01-getting-started.md) | Local setup, Docker, default credentials, client handover checklist |
| [`02-system-architecture.md`](docs/lessons/02-system-architecture.md) | Folder structure, the `config → db → auth` boot sequence, the module pattern, PJAX navigation, dark mode |
| [`03-database-design.md`](docs/lessons/03-database-design.md) | Table relationships, soft-delete, foreign key rules, supporting tables |
| [`04-security-features.md`](docs/lessons/04-security-features.md) | Brute-force lockout, honeypot, OTP reset, CSRF, session hardening, rate limiting, audit logging — verified line-by-line against the actual code |

Each lesson ends with a short **Try it yourself** exercise so the material
isn't just read passively.

---

## Project Structure

```
/
├── index.php                  # Login, forgot password, OTP verify, reset
├── dashboard.php              # Main dashboard with KPIs and schedule
├── router.php                 # PJAX content router
├── logout.php
├── api/                       # JSON API endpoints (appointments, billing, etc.)
├── modules/
│   ├── patients/              # List, add, view, edit, archive
│   ├── appointments/          # List, add, calendar
│   ├── treatments/            # Dental records list, add, view
│   ├── billing/               # List, create, pay, view
│   ├── doctors/               # Doctor list
│   ├── services/              # Service catalog
│   ├── schedule/              # Weekly schedule + blocked dates
│   ├── users/                 # User management (admin only)
│   ├── analytics/             # Charts and KPIs (admin only)
│   ├── reports/               # Monthly reports (admin only)
│   ├── logs/                  # Audit log viewer (admin only)
│   ├── walkin/                # Walk-in registration
│   └── print/                 # Print-ready document views
├── includes/                  # Shared PHP (config, db, auth, header, sidebar)
├── assets/
│   ├── css/                   # style.css, print.css, accessibility.css
│   ├── js/                    # app.js (PJAX, dark mode, charts)
│   └── images/
├── database/
│   └── cap.sql                # Full schema + seed data
├── docs/
│   └── lessons/                # Step-by-step guides (setup, architecture, database, security)
├── Dockerfile
└── Caddyfile
```
