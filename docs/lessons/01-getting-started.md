# Lesson 1 — Getting Started (Setup & Handover)

This lesson is for anyone who needs to **run the system for the first time** —
whether that's you on a new laptop, a panel member testing the demo, or the
clinic staff who will use it after handover. No programming knowledge needed.

---

## 1. What you actually need installed

Just **one** thing: **Laragon** (or **XAMPP**, same idea). That single program
gives you everything the system needs — a web server (Apache) and a database
(MySQL) — bundled together.

That's it. No Python, no Node.js, no separate database server to install, no
`pip install` or `npm install` commands. This is one of the advantages of a
plain PHP system: the whole stack is one download.

---

## 2. Step-by-step setup

1. **Install Laragon** (or XAMPP) if it isn't already on the machine.
2. **Copy the project folder** into Laragon's `www` folder
   (e.g. `C:\laragon\www\cap`) — or `htdocs` if you're using XAMPP.
3. **Start Apache and MySQL** — open Laragon and click **Start All**.
4. **Create the database:**
   - Open **phpMyAdmin** (Laragon has a shortcut for this) or **HeidiSQL**.
   - Run the file `database/cap.sql` — this one file creates every table
     *and* fills in sample patients, doctors, and services so the demo
     isn't staring at an empty screen.
5. **Set up your `.env` file:**
   - Copy `.env.example` to a new file named `.env` (no `.example` at the end).
   - The defaults already match a stock Laragon/XAMPP install
     (`root` user, blank password, port `3306`) — so for local testing you
     usually don't need to change anything.
6. **Open the browser** and go to `http://localhost/cap/` (match whatever
   folder name you used in step 2).

You should land on the login page.

---

## 3. Logging in for the first time

After importing `database/cap.sql`, two accounts already exist:

| Role  | Username | Password   |
|-------|----------|------------|
| Admin | `admin`  | `password` |
| Staff | `staff`  | `password` |

> ⚠️ **Change these immediately** once the real clinic starts using the
> system. They're only meant for testing and the panel demo.

---

## 4. What if it doesn't work?

The system was built to fail *helpfully* instead of showing a blank white
screen or a scary PHP error. If the database can't be reached, you'll see a
friendly on-screen message that walks through the three most common causes:

1. MySQL isn't running yet (start it in Laragon/XAMPP)
2. The `.env` credentials don't match your local setup
3. The database hasn't been imported yet

This page is generated automatically by `includes/db.php` — you don't have
to do anything special to get it, it just shows up whenever the database
connection fails.

---

## 5. The "online" version (Docker)

If you ever need to deploy this to a real server instead of running it
locally, the project already includes a `Dockerfile` and `Caddyfile`. Two
commands and it's running:

```bash
docker build -t cap-dental .
docker run -p 8080:8080 --env-file .env cap-dental
```

You don't need to understand Docker deeply to know this matters for a
panel question like *"how would you deploy this for the actual clinic?"* —
the honest answer is: **it's already containerized, ready to deploy
anywhere that runs Docker** (a VPS, Railway, Render, etc.), not something
that only works on one developer's laptop.

---

## 6. Handing this off to a real client

When this system goes to PHINMA-UI / Honeytooth / Amparo for real use,
here's the entire handover checklist:

- [ ] Import `database/cap.sql` on their server
- [ ] Fill in their real `.env` values (clinic name, mail/SMS API keys if
      they want OTP delivered for real instead of shown on-screen)
- [ ] Log in with the default admin account and **change the password**
- [ ] Add their real doctors, services, and clinic hours
      (Doctors module, Services module, Schedule module)
- [ ] Archive or delete the sample patients that came with the seed data

That's the whole list. No build step, no compiling, no dependency
installation — copy the files, import one SQL file, open a browser.

---

### Try it yourself

Before the actual demo day, do a **dry run on a second machine** (a
groupmate's laptop, for example) using only this lesson — no other help.
If you can get from "fresh Laragon install" to "logged in and looking at
the dashboard" using just these steps, the handover instructions are solid.
If you get stuck somewhere, that's the part of this lesson that needs more
detail before the real client sees it.
