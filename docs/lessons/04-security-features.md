# Lesson 4 — Security Features

This lesson walks through what actually protects this system, in the order
someone would hit it: from typing a password, to submitting a form, to an
attacker trying to brute-force their way in. Everything here is verified
against the actual code — not a feature wishlist.

> **Note before we start:** an earlier draft of this project's README also
> claimed a *math* CAPTCHA and *hCaptcha* on login. Checked directly
> against the code, **neither of those is actually implemented** — only
> `.env.example` has a leftover `HCAPTCHA_SITE_KEY` placeholder that
> nothing in the code reads. The honeypot field, on the other hand, *is*
> real and is covered in section 2 below. This lesson (and the corrected
> README) reflects only what's actually in the code, since that's what a
> panel member could open and check for themselves.

---

## 1. Passwords aren't stored — hashes are

When an account is created, the password is run through PHP's
`PASSWORD_BCRYPT` algorithm before it ever touches the database. The
database column is called `password`, but what's actually stored looks
like this:

```
$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
```

That's not encrypted text that can be "decrypted" back to the original —
it's a one-way hash. Even with full database access, the original
password can't be recovered, only guessed and re-hashed to compare.

New passwords are also required to pass `validate_password()` in
`includes/auth.php`: 8–18 characters, at least one uppercase letter, one
lowercase letter, one number, and one special character.

---

## 2. Brute-force protection on login

This is real, and it's in `index.php`:

```php
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    300);  // 5 minutes
```

Every failed login increments a counter stored in the session. After 5
wrong attempts, the account is locked for 5 minutes — the error message
changes from "invalid username or password" to "too many failed
attempts," so an attacker can't tell whether they're guessing the wrong
password or just hitting the lockout. There's also a deliberate `sleep(2)`
on every wrong password, which slows down automated guessing scripts
without being noticeable to a real person typing their password.

On top of the session-based counter, every login attempt also passes
through `api_rate_limit($conn, 'login', ...)` — the same IP-based rate
limiter covered in section 6. So even if an attacker clears cookies to
reset their session counter, their IP address is still being tracked
separately at the database level.

---

## 3. The honeypot — catching bots before they even try a password

Look at the login form in `index.php` and there's a field most humans
never see:

```html
<div style="display:none;visibility:hidden;position:absolute;left:-9999px;" aria-hidden="true">
    <label for="website">Leave this empty</label>
    <input type="text" name="website" id="website" tabindex="-1" autocomplete="off">
</div>
```

A real visitor never sees or fills this in — it's invisible, and
`tabindex="-1"` + `aria-hidden="true"` mean it's also skipped by keyboard
navigation and screen readers, so it doesn't trip up anyone using
accessibility tools either. **Bots, on the other hand, often auto-fill
every input field they find on a page** — including this one. The
moment the form is submitted:

```php
if (!empty($_POST['website'])) {
    error_log('[HONEYPOT] Bot detected from IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    sleep(3);
    exit();
}
```

If that hidden field has anything in it, the request is logged and
silently dropped — the bot doesn't even get a response telling it what
went wrong, it just hangs for 3 seconds and gets nothing back.

---

## 4. Forgot password → OTP, not a reset link alone

Password recovery doesn't just email a reset link — it requires a 6-digit
one-time code:

1. User requests a reset → a token + OTP code are generated and saved to
   the `users` table with a 5-minute expiry (`OTP_TTL = 300`).
2. The OTP is sent through **both** channels that are configured:
   `send_otp_sms()` (via Semaphore, a Philippine SMS API) and
   `send_otp_email()` (via Resend).
3. The user has a maximum of 5 attempts to enter the correct code
   (`OTP_MAX_ATTEMPTS = 5`) before it's invalidated and they have to
   request a new one.
4. Only after the correct OTP is entered can the password actually be
   changed.

**Demo mode:** if no `RESEND_API_KEY` or `SEMAPHORE_API_KEY` is set in
`.env`, the system doesn't fail silently — it shows the OTP directly on
screen, so you can demo the full flow without needing real API keys
during a panel presentation.

---

## 5. CSRF protection — stopping forged form submissions

CSRF (Cross-Site Request Forgery) is when a malicious site tricks a
logged-in user's browser into submitting a form on *this* site without
them meaning to. The fix is a hidden token that proves the form really
came from this app:

```php
function csrf_field(): string {
    $token = generate_csrf_token();
    return '<input type="hidden" name="_csrf" value="' . $token . '">';
}

function validate_csrf(): void {
    $submitted = $_POST['_csrf'] ?? '';
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (empty($submitted) || empty($expected) || !hash_equals($expected, $submitted)) {
        // reject the request
    }
}
```

Every form that changes data (archiving a patient, creating a bill, adding
a user) includes this hidden field, and the receiving code calls
`validate_csrf()` before doing anything. If the token doesn't match
exactly, the request is rejected — even if the username/password session
is completely valid.

---

## 6. Session hardening — making stolen cookies less useful

Set in `includes/auth.php`, on *every* protected page:

| Setting | Value | What it actually does |
|---|---|---|
| `httponly` | on | JavaScript can't read the session cookie — blocks a whole class of XSS cookie-theft attacks |
| `use_strict_mode` | on | Rejects uninitialized session IDs, so an attacker can't "plant" a session ID |
| `cookie_samesite` | Lax | Stops the cookie from being sent on cross-site requests |
| Idle timeout | 8 hours | Inactive sessions are force-logged-out automatically |
| Session ID regeneration | on login | A fresh session ID is issued the moment someone logs in, so a session ID seen *before* login can't be reused *after* login |

---

## 7. API rate limiting — slowing down abuse

`api_rate_limit()` in `includes/db.php` tracks requests per IP address per
endpoint, using the `rate_limits` table:

```php
api_rate_limit($conn, 'appointments', 60, 60); // max 60 hits per 60 seconds
```

If an IP goes over the limit, the API responds with HTTP `429 Too Many
Requests` and a `Retry-After` header telling the client how long to wait.
Notably, if the rate-limit table itself has a database problem, the system
**fails open** (lets the request through) rather than blocking everyone —
a deliberate choice so a database hiccup never takes the whole API down.

---

## 8. SQL injection — prepared statements everywhere

Every database query in this project uses PDO **prepared statements** with
`?` placeholders, never raw string concatenation of user input. For
example, the Reports module takes a `month` value straight from the URL,
but before it's used anywhere, it's checked against a strict pattern:

```php
$month = $_GET['month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
    $month = date('Y-m');   // silently fall back to safe default
}
```

So even if someone manually edits the URL to try to inject SQL through
the `month` parameter, it gets rejected and replaced with today's month
before it ever reaches a query.

---

## 9. Audit logs — accountability after the fact

Every significant action calls `log_action()`, which writes a permanent
row to `audit_logs`: who did it, what action, which module, which record,
and their IP address. **Successful** logins, patient archiving, billing
changes, and user edits are all tracked this way.

One honest gap worth knowing about: **failed login attempts and honeypot
hits are written to the PHP error log (`logs/error.log`), not to the
`audit_logs` database table.** Only a *successful* login gets a database
audit log entry. So if a panel member asks "show me a record of a failed
login attempt," the accurate answer is "that's in the server's error log
file, not the in-app Logs module" — worth knowing the distinction rather
than promising something the Logs screen doesn't actually show yet.

---

## 10. Honest summary — what's real vs. what isn't

| Feature | Status |
|---|---|
| Bcrypt password hashing | ✅ Real |
| Brute-force lockout (5 attempts / 5 min, session + IP-based) | ✅ Real |
| Honeypot field on login | ✅ Real |
| OTP password reset (SMS + Email) | ✅ Real |
| CSRF protection on state-changing forms | ✅ Real |
| Session hardening (httponly, SameSite, timeout, ID regen) | ✅ Real |
| API rate limiting | ✅ Real |
| API token authentication | ✅ Real |
| Prepared statements / SQL injection prevention | ✅ Real |
| Audit logging (successful logins, patient/billing/user actions) | ✅ Real |
| Audit logging of *failed* login attempts | ❌ Goes to error log only, not the `audit_logs` table |
| Math CAPTCHA on login | ❌ Not implemented |
| hCaptcha | ❌ Scaffolded in `.env.example` only, not wired into any code |

If a math CAPTCHA, or logging failed attempts into the same audit trail
as everything else, is something you actually want before the final
defense, those are both small, well-scoped additions — say so and they
can be built properly instead of just claimed in a README.

---

### Try it yourself

Log in with the wrong password **5 times in a row** on purpose, on a test
account. Confirm you see the lockout message and that it clears after 5
minutes. Then try submitting the login form with the hidden `website`
field filled in (e.g. using your browser's dev tools to un-hide it) and
confirm the request just hangs and returns nothing. Finally, check
`logs/error.log` on the server — you should see both the failed-password
attempts and the honeypot trigger recorded there. If you can demo this
live, it's a strong, honest answer to "how do you prevent brute-force
login attacks and bots?"
