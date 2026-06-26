# Lesson 2 — How the System is Built (Architecture)

This lesson explains *why* the project folders are organized the way they
are, and how a click in the browser turns into data on the screen. Useful
for explaining the system to a panel, or for a new teammate who needs to
add a feature without breaking everything else.

---

## 1. The big picture

This is **not** one giant file with everything crammed inside. It's split
into small, predictable pieces:

```
includes/    →  the shared "brain" — every page loads this first
modules/     →  one folder per feature (patients, billing, appointments...)
api/         →  small JSON endpoints for background requests (AJAX)
assets/      →  CSS, JS, images — what the browser downloads to look nice
database/    →  the one SQL file that builds the whole database
```

Think of `includes/` as the foundation of a house, and each folder inside
`modules/` as one room. Every room (module) is built on the same foundation,
so they all behave consistently — but you can renovate one room (say,
`billing/`) without touching any of the others.

---

## 2. What happens when a page loads

Open any module file, like `modules/patients/list.php`, and the first three
lines are almost always the same:

```php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
```

This is the system's "boot sequence," and it runs in this exact order on
**every single protected page**:

1. **`config.php`** — sets up constants (app name, base URL), turns on
   error logging, and adds security headers to the response.
2. **`db.php`** — opens the MySQL connection and loads a toolbox of shared
   helper functions (CSRF protection, audit logging, rate limiting — more
   on these in Lesson 4).
3. **`auth.php`** — checks "is someone actually logged in?" If not, it
   redirects to the login page immediately. If the session has been idle
   for more than 8 hours, it logs the person out automatically.

Because every page does this in the same order, there's exactly **one
place** to change something that should apply everywhere. Want to change
the session timeout from 8 hours to 4? One line, in one file
(`includes/config.php`), and it's changed everywhere — not 40 separate
edits across every module.

---

## 3. The module pattern

Open the `modules/` folder and you'll notice the same shape repeating:

```
modules/patients/
├── list.php       — the table/search view
├── add.php        — the "create new" form
├── edit.php        — the "edit existing" form
├── view.php        — the read-only detail page
└── archived.php   — the soft-deleted (archived) records
```

`appointments/`, `billing/`, `users/` — they all roughly follow this same
shape. Once you understand how *one* module works, you can read any other
module and already know where to look for the search logic, the form
handling, or the delete button.

**Why this matters for a panel question:** if they ask "how would you add
a new feature, like a *Suppliers* module?" — the honest, confident answer
is: *copy the shape of an existing module (e.g. `doctors/`, since it's
simple), and adapt the fields.* The pattern is already proven across 13
modules.

---

## 4. PJAX — why pages feel instant

Click a link in the sidebar and notice: the **sidebar and header never
disappear or flicker** — only the content area changes. That's not a
full page reload. It's handled by `assets/js/app.js`, using a technique
called **PJAX** (PJAX = "pushState + AJAX").

In plain terms, here's what happens on every sidebar click:

1. JavaScript intercepts the click *before* the browser navigates away.
2. It fetches just the new page's HTML in the background
   (`fetch(url, { headers: { 'X-Requested-With': 'pjax' } })`).
3. It swaps out only the `.main-content` part of the page with the new
   content — sidebar, header, and dark mode stay exactly as they were.
4. It updates the browser's address bar (`history.pushState`) so the
   back/forward buttons still work correctly.
5. It re-runs any `<script>` tags in the new content (this is what makes
   charts on the Analytics page re-draw correctly after navigating there).

This is also why the app cleans up Chart.js instances and Bootstrap modals
before swapping content — without that cleanup, old chart objects and
modal popups would pile up in memory the longer someone uses the app
without a full page refresh.

**The result for the end user:** clicking around the dashboard feels like
a modern single-page app, even though it's built in plain PHP with no
frontend framework.

---

## 5. Dark mode — a small but clever detail

Dark mode is saved in the browser's `localStorage`, but there's a subtle
problem it solves: if the page loaded normally and *then* applied dark
mode, you'd see a flash of the white theme for a split second before it
switched — annoying and unpolished.

The fix lives in `includes/head.php`, as a tiny inline script that runs
**before any CSS even loads**:

```js
var t = localStorage.getItem('theme');
if (t === 'dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
}
```

Because this runs immediately, the dark theme is already applied by the
time the page is visible — no flash. This is a real technique used in
production websites, not something unique to this project, but it's worth
being able to explain if asked "how does dark mode avoid that white flash
you sometimes see on other sites?"

---

### Try it yourself

Pick a module you haven't touched yet (e.g. `modules/doctors/`). Without
asking for help, try to answer:

1. Where does it check the user is an admin before allowing access?
2. Where does it validate the submitted form data?
3. Where does it call `log_action(...)` to record what happened?

If you can find all three quickly, the pattern has clicked. If you can't
find one of them, that's worth double-checking — it might mean that module
is missing a piece the others have.
