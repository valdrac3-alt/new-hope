# Lesson 3 — Database Design

This lesson explains how the data is organized — what each table is for,
and how they connect to each other. You don't need SQL experience to
follow this; think of it as a map of the clinic's filing cabinet.

---

## 1. The five tables that matter most

Everything in the system revolves around five core tables:

```
patients  →  appointments  →  dental_records
                  ↓
                bills
```

- **`patients`** — one row per person. Name, birthdate, blood type,
  allergies, emergency contact, medical history.
- **`appointments`** — one row per visit (booked *or* walk-in). Links to
  which patient, which doctor, which service, and what date/time.
- **`dental_records`** — the clinical notes from a completed appointment:
  diagnosis, treatment done, tooth status, prescriptions.
- **`bills`** — the money side of an appointment: how much is owed, how
  much has been paid, and the payment method.

So a single patient visit flows like this: a **patient** books an
**appointment**, the doctor fills out a **dental record** during the
visit, and a **bill** gets created for payment. Four tables, one story.

---

## 2. How tables "know" about each other (foreign keys)

Look at the `appointments` table definition:

```sql
FOREIGN KEY (`patient_id`)  REFERENCES `patients`(`id`)  ON DELETE CASCADE,
FOREIGN KEY (`service_id`)  REFERENCES `services`(`id`)  ON DELETE SET NULL,
FOREIGN KEY (`doctor_id`)   REFERENCES `doctors`(`id`)   ON DELETE SET NULL,
```

Each line is a rule the database enforces automatically. In plain English:

- **`ON DELETE CASCADE`** (used for `patient_id`) means: *if this patient
  is ever hard-deleted, delete all their appointments too* — because an
  appointment makes no sense without a patient attached to it.
- **`ON DELETE SET NULL`** (used for `service_id` and `doctor_id`) means:
  *if a doctor or service is removed, don't delete the appointment — just
  blank out which doctor/service it pointed to.* The appointment record
  (and the history) survives even if a doctor later leaves the clinic.

This is why you'll never see a "broken" appointment pointing at a doctor
that no longer exists — the database itself prevents that.

---

## 3. Soft delete — nothing is ever really gone

Notice that `patients` has an `is_active` column, and "deleting" a patient
in the app (Lesson 2's `archived.php` pattern) doesn't run `DELETE FROM
patients` — it runs:

```sql
UPDATE patients SET is_active = FALSE WHERE id = ?
```

The record stays in the database forever; it's just hidden from the
normal patient list. This is called a **soft delete**, and it's the right
choice for a clinic system for a simple reason: **medical and billing
records can't legally just disappear.** If a patient is archived by
mistake, restoring them brings back every appointment, bill, and dental
record exactly as it was — because nothing was ever actually deleted.

---

## 4. The supporting cast — tables that aren't "the data" but make the system work

| Table | What it's actually for |
|---|---|
| `users` | Login accounts for staff/admin — separate from `patients` |
| `services` | The price list (checkup, extraction, cleaning, etc.) |
| `doctors` | Doctor profiles, including `leave_start`/`leave_end` so the booking screen knows when a doctor is unavailable |
| `schedules` | Clinic open/close hours **per day of the week** — this is what powers the "next available slot" logic for walk-ins |
| `blocked_dates` | One-off closures (holidays) that aren't part of the weekly pattern |
| `notifications` | The little bell icon in the header — one row per alert |
| `audit_logs` | A permanent record of "who did what, when" — covered in Lesson 4 |
| `rate_limits` | Tracks how many times an IP address has hit an API endpoint recently (anti-abuse — Lesson 4) |
| `api_tokens` | Lets external tools authenticate with a token instead of a username/password |

---

## 5. Two tables that exist but aren't wired up yet

Being upfront about this matters for a capstone defense — if a panel
member opens the database and asks about a table, you want to have the
honest answer ready rather than be caught off guard.

- **`inventory`** — the table exists in the schema, but no module
  currently reads from or writes to it. It was scaffolded for a future
  "track dental supplies" feature that hasn't been built yet.
- **`settings`** — same situation. The table is there, but nothing in the
  code currently uses it.

If asked, the accurate answer is: *"those are scaffolded for a planned
feature — inventory tracking for dental supplies — that's on the roadmap
but not part of the current scope."* That's a perfectly normal and honest
thing to say in a capstone defense; it shows you know your own codebase.

---

### Try it yourself

Using phpMyAdmin or HeidiSQL, try writing (or just reading and predicting
the result of) this query before running it:

```sql
SELECT p.first_name, p.last_name, COUNT(a.id) AS total_visits
FROM patients p
LEFT JOIN appointments a ON a.patient_id = p.id
WHERE p.is_active = TRUE
GROUP BY p.id
ORDER BY total_visits DESC
LIMIT 5;
```

Before running it, guess what it does. (Answer: it lists the 5 active
patients with the most appointments — your "most frequent visitors.")
Being able to read a query like this and explain it in plain English is
exactly the kind of thing a panel might ask you to do live.
