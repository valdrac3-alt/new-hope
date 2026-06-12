<?php
// Edit an existing user account (name, email, role, password).

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Edit User';

$id = secure_int($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

// DATABASE SECURITY: $id is secure_int() — safe positive integer only
$user = $conn->query("SELECT * FROM users WHERE id = $id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$user) { header('Location: list.php'); exit(); }

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $role      = $_POST['role'] ?? 'staff';
    $password  = trim($_POST['password'] ?? '');
    $confirm   = trim($_POST['confirm_password'] ?? '');

    if (empty($full_name)) {
        $error = 'Full name is required.';
    } elseif (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($phone)) {
        $error = 'Phone number is required.';
    } elseif (!valid_phone($phone)) {
        $error = 'Please enter a valid phone number.';
    } elseif (!empty($password) && $password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif (!empty($password) && ($pw_err = validate_password($password))) {
        $error = $pw_err;
    } else {
        if (!empty($password)) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=?, password=? WHERE id=?");
            
        } else {
            $stmt = $conn->prepare("UPDATE users SET full_name=?, email=?, phone=?, role=? WHERE id=?");
        }

        $params = !empty($password)
            ? [$full_name, $email, $phone, $role, $hashed, $id]
            : [$full_name, $email, $phone, $role, $id];
        if ($stmt->execute($params)) {
            log_action($conn, $current_user_id, $current_user_name, 'Edited User', 'users', $id, "Updated user: {$user['username']}");
            $success = 'User updated successfully.';
            $user = $conn->query("SELECT * FROM users WHERE id = $id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        } else {
            $error = 'Failed to update. Please try again.';
        }
        $stmt->close();
    }
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<style>
/* ── Edit User — redesigned ───────────────────────── */
.eu-wrap        { max-width: 760px; margin: 0 auto; }

/* Profile hero card */
.eu-hero {
    background: linear-gradient(135deg, #1d4ed8 0%, #1e40af 60%, #1e3a8a 100%);
    border-radius: 16px;
    padding: 28px 32px;
    display: flex;
    align-items: center;
    gap: 22px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.eu-hero::before {
    content: '';
    position: absolute;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.06);
    border-radius: 50%;
    top: -60px; right: -40px;
}
.eu-avatar {
    width: 68px; height: 68px;
    border-radius: 50%;
    background: rgba(255,255,255,0.18);
    border: 3px solid rgba(255,255,255,0.35);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; font-weight: 800;
    color: #fff;
    flex-shrink: 0;
    letter-spacing: -1px;
}
.eu-hero-info { flex: 1; }
.eu-hero-name  { font-size: 1.25rem; font-weight: 700; color: #fff; margin: 0 0 4px; }
.eu-hero-meta  { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.eu-badge {
    font-size: 0.7rem; font-weight: 700; padding: 3px 10px;
    border-radius: 20px; text-transform: uppercase; letter-spacing: 0.06em;
}
.eu-badge-role   { background: rgba(255,255,255,0.2); color: #fff; }
.eu-badge-active { background: #22c55e; color: #fff; }
.eu-badge-user   { background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.8); }
.eu-back-btn {
    position: absolute; top: 20px; right: 24px;
    background: rgba(255,255,255,0.15);
    border: 1px solid rgba(255,255,255,0.25);
    color: #fff;
    padding: 6px 16px;
    border-radius: 8px;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    transition: background 0.2s;
    display: flex; align-items: center; gap: 6px;
}
.eu-back-btn:hover { background: rgba(255,255,255,0.25); color: #fff; }

/* Section cards */
.eu-section {
    background: var(--card-bg, #fff);
    border: 1px solid var(--border-color, #e5e7eb);
    border-radius: 14px;
    margin-bottom: 18px;
    overflow: hidden;
}
.eu-section-header {
    padding: 16px 24px 14px;
    border-bottom: 1px solid var(--border-color, #e5e7eb);
    display: flex;
    align-items: center;
    gap: 10px;
}
.eu-section-icon {
    width: 32px; height: 32px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 0.9rem;
    flex-shrink: 0;
}
.eu-section-icon.blue  { background: #eff6ff; color: #1d4ed8; }
.eu-section-icon.green { background: #f0fdf4; color: #16a34a; }
.eu-section-icon.amber { background: #fffbeb; color: #d97706; }
.eu-section-title { font-size: 0.9rem; font-weight: 700; color: var(--gray-800, #1f2937); margin: 0; }
.eu-section-sub   { font-size: 0.75rem; color: var(--gray-400, #9ca3af); margin: 0; }

.eu-section-body  { padding: 22px 24px; }

/* Field labels */
.eu-label {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--gray-600, #4b5563);
    margin-bottom: 6px;
    display: block;
    letter-spacing: 0.01em;
}
.eu-label .req { color: var(--danger, #ef4444); margin-left: 2px; }

/* Readonly field pill */
.eu-readonly {
    background: var(--gray-50, #f9fafb);
    border: 1px solid var(--gray-200, #e5e7eb);
    border-radius: 8px;
    padding: 9px 14px;
    font-size: 0.875rem;
    color: var(--gray-500, #6b7280);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Password strength bar */
#strengthBar  { height: 5px; border-radius: 4px; margin-top: 8px; background: var(--gray-100, #f3f4f6); }
#strengthFill { height: 100%; border-radius: 4px; width: 0%; transition: width .3s, background .3s; }
#strengthText { font-size: 0.72rem; margin-top: 4px; min-height: 14px; font-weight: 700; }

/* Password rules grid */
.eu-rules {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: 6px;
    margin-top: 12px;
}
.eu-rules span {
    font-size: 0.72rem;
    padding: 5px 10px;
    border-radius: 6px;
    background: var(--gray-50, #f9fafb);
    border: 1px solid var(--gray-100, #f3f4f6);
    color: var(--gray-500, #6b7280);
    transition: all 0.2s;
}

/* Action footer */
.eu-footer {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 20px 24px;
    background: var(--gray-50, #f9fafb);
    border-top: 1px solid var(--border-color, #e5e7eb);
    border-radius: 0 0 14px 14px;
}
.eu-footer .btn-save {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border: none;
    padding: 10px 28px;
    border-radius: 9px;
    font-weight: 700;
    font-size: 0.875rem;
    display: flex; align-items: center; gap: 7px;
    cursor: pointer;
    transition: opacity 0.2s, transform 0.1s;
    box-shadow: 0 2px 8px rgba(29,78,216,0.3);
}
.eu-footer .btn-save:hover  { opacity: 0.92; transform: translateY(-1px); }
.eu-footer .btn-save:active { transform: translateY(0); }
.eu-footer .btn-cancel {
    color: var(--gray-500, #6b7280);
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
    padding: 10px 18px;
    border-radius: 9px;
    border: 1px solid var(--gray-200, #e5e7eb);
    background: #fff;
    transition: border-color 0.2s, color 0.2s;
}
.eu-footer .btn-cancel:hover { border-color: var(--gray-400,#9ca3af); color: var(--gray-700,#374151); }

[data-theme="dark"] .eu-section { background: var(--card-bg); }
[data-theme="dark"] .eu-readonly { background: var(--gray-800,#1f2937); color: var(--gray-400); }
[data-theme="dark"] .eu-footer   { background: var(--gray-900,#111827); }
[data-theme="dark"] .eu-footer .btn-cancel { background: transparent; }
[data-theme="dark"] .eu-rules span { background: var(--gray-800); border-color: var(--gray-700); }
</style>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">
    <div class="eu-wrap">

        <?php if ($error): ?>
            <div class="alert alert-danger" style="border-radius:10px;margin-bottom:16px;">
                <i class="bi bi-exclamation-circle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success" style="border-radius:10px;margin-bottom:16px;">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <!-- HERO PROFILE BANNER -->
        <?php
            $initials = strtoupper(substr($user['full_name'], 0, 1));
            $words    = explode(' ', trim($user['full_name']));
            if (count($words) > 1) $initials = strtoupper($words[0][0] . end($words)[0]);
            $role_label = ucfirst($user['role'] ?? 'staff');
        ?>
        <div class="eu-hero">
            <div class="eu-avatar"><?php echo htmlspecialchars($initials); ?></div>
            <div class="eu-hero-info">
                <p class="eu-hero-name"><?php echo htmlspecialchars($user['full_name']); ?></p>
                <div class="eu-hero-meta">
                    <span class="eu-badge eu-badge-user">
                        <i class="bi bi-at" style="font-size:0.7rem;"></i> <?php echo htmlspecialchars($user['username']); ?>
                    </span>
                    <span class="eu-badge eu-badge-role">
                        <i class="bi bi-shield-fill" style="font-size:0.65rem;"></i> <?php echo htmlspecialchars($role_label); ?>
                    </span>
                    <?php if (!empty($user['is_active'])): ?>
                    <span class="eu-badge eu-badge-active">
                        <i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Active
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="list.php" class="eu-back-btn">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <form method="POST">
            <?php echo csrf_field(); ?>

            <!-- SECTION 1: Personal Info -->
            <div class="eu-section">
                <div class="eu-section-header">
                    <div class="eu-section-icon blue"><i class="bi bi-person-fill"></i></div>
                    <div>
                        <p class="eu-section-title">Personal Information</p>
                        <p class="eu-section-sub">Name, username, and system role</p>
                    </div>
                </div>
                <div class="eu-section-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="eu-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" class="form-control" required
                                   value="<?php echo htmlspecialchars($user['full_name']); ?>"
                                   placeholder="Enter full name">
                        </div>
                        <div class="col-md-6">
                            <label class="eu-label">Username</label>
                            <div class="eu-readonly">
                                <i class="bi bi-at" style="color:#1d4ed8;font-size:1rem;"></i>
                                <?php echo htmlspecialchars($user['username']); ?>
                            </div>
                            <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">
                                <i class="bi bi-lock-fill" style="font-size:0.65rem;"></i> Username cannot be changed
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="eu-label">Role</label>
                            <?php if ($id === $current_user_id): ?>
                                <input type="hidden" name="role" value="<?php echo htmlspecialchars($user['role']); ?>">
                                <div class="eu-readonly">
                                    <i class="bi bi-shield-fill" style="color:#1d4ed8;"></i>
                                    <?php echo htmlspecialchars($role_label); ?>
                                </div>
                                <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">
                                    <i class="bi bi-lock-fill" style="font-size:0.65rem;"></i> Cannot change your own role
                                </div>
                            <?php else: ?>
                                <select name="role" class="form-select">
                                    <option value="staff" <?php echo $user['role'] === 'staff' ? 'selected' : ''; ?>>
                                        Staff
                                    </option>
                                    <option value="admin" <?php echo $user['role'] === 'admin' ? 'selected' : ''; ?>>
                                        Admin
                                    </option>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 2: Contact Details -->
            <div class="eu-section">
                <div class="eu-section-header">
                    <div class="eu-section-icon green"><i class="bi bi-envelope-fill"></i></div>
                    <div>
                        <p class="eu-section-title">Contact Details</p>
                        <p class="eu-section-sub">Email address and phone number</p>
                    </div>
                </div>
                <div class="eu-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="eu-label">Email Address <span class="req">*</span></label>
                            <div style="position:relative;">
                                <i class="bi bi-envelope" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.9rem;"></i>
                                <input type="email" name="email" class="form-control" required
                                       style="padding-left:36px;"
                                       placeholder="user@example.com"
                                       value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <?php
                                $phone_field_name     = 'phone';
                                $phone_field_value    = $user['phone'] ?? '';
                                $phone_field_label    = 'Phone';
                                $phone_field_required = true;
                                include '../../includes/phone_input.php';
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- SECTION 3: Security -->
            <div class="eu-section">
                <div class="eu-section-header">
                    <div class="eu-section-icon amber"><i class="bi bi-shield-lock-fill"></i></div>
                    <div>
                        <p class="eu-section-title">Change Password</p>
                        <p class="eu-section-sub">Leave blank to keep the current password</p>
                    </div>
                </div>
                <div class="eu-section-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="eu-label">New Password</label>
                            <div style="position:relative;">
                                <i class="bi bi-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);"></i>
                                <input type="password" name="password" id="pw" class="form-control"
                                       style="padding-left:36px;"
                                       autocomplete="new-password" placeholder="Enter new password"
                                       oninput="checkStrength(this.value)">
                                <i class="bi bi-eye" id="togPw" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;"></i>
                            </div>
                            <div id="strengthBar"><div id="strengthFill"></div></div>
                            <div id="strengthText"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="eu-label">Confirm New Password</label>
                            <div style="position:relative;">
                                <i class="bi bi-lock-fill" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);"></i>
                                <input type="password" name="confirm_password" id="pwConf" class="form-control"
                                       style="padding-left:36px;"
                                       autocomplete="new-password"
                                       placeholder="Repeat new password"
                                       oninput="checkMatch()">
                                <i class="bi bi-eye" id="togConf" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;"></i>
                            </div>
                            <div id="matchText" style="font-size:0.72rem;margin-top:4px;min-height:14px;font-weight:700;"></div>
                        </div>
                        <div class="col-12">
                            <div class="eu-rules">
                                <span id="rule_len">⬜ 8–18 chars</span>
                                <span id="rule_upper">⬜ uppercase (A–Z)</span>
                                <span id="rule_lower">⬜ lowercase (a–z)</span>
                                <span id="rule_num">⬜ number (0–9)</span>
                                <span id="rule_spec">⬜ special (@#$!...)</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="eu-footer">
                    <button type="submit" class="btn-save">
                        <i class="bi bi-check-circle-fill"></i> Save Changes
                    </button>
                    <a href="list.php" class="btn-cancel">Cancel</a>
                </div>
            </div>

        </form>

    </div><!-- end eu-wrap -->
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
<script>
function togglePw(id, icon) {
    var i = document.getElementById(id), t = i.type === 'text';
    i.type = t ? 'password' : 'text';
    icon.className = (t ? 'bi bi-eye' : 'bi bi-eye-slash') + ' eu-eye';
    icon.style.cssText = 'position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;';
}
document.getElementById('togPw').onclick   = function(){ togglePw('pw', this); };
document.getElementById('togConf').onclick = function(){ togglePw('pwConf', this); };

function setRule(id, ok) {
    var e = document.getElementById(id);
    e.textContent = (ok ? '✅' : '⬜') + e.textContent.slice(2);
    e.style.color      = ok ? 'var(--success)' : '';
    e.style.fontWeight = ok ? '700' : '400';
    e.style.background = ok ? 'var(--green-50,#f0fdf4)' : '';
    e.style.borderColor= ok ? '#86efac' : '';
}
function checkStrength(v) {
    var rl  = v.length >= 8 && v.length <= 18;
    var ru  = /[A-Z]/.test(v);
    var rlw = /[a-z]/.test(v);
    var rn  = /[0-9]/.test(v);
    var rs  = /[^A-Za-z0-9]/.test(v);
    setRule('rule_len',   rl);
    setRule('rule_upper', ru);
    setRule('rule_lower', rlw);
    setRule('rule_num',   rn);
    setRule('rule_spec',  rs);
    var score = [rl, ru, rlw, rn, rs].filter(Boolean).length;
    var fills = ['0%','20%','40%','60%','80%','100%'];
    var cols  = ['','#ef4444','#f97316','#eab308','#84cc16','#22c55e'];
    var labs  = ['','Weak','Fair','Moderate','Good','Strong ✓'];
    document.getElementById('strengthFill').style.width      = fills[score];
    document.getElementById('strengthFill').style.background = cols[score] || '';
    document.getElementById('strengthText').textContent      = v.length ? labs[score] : '';
    document.getElementById('strengthText').style.color      = cols[score] || '';
    checkMatch();
}
function checkMatch() {
    var pw = document.getElementById('pw').value;
    var cf = document.getElementById('pwConf').value;
    var mt = document.getElementById('matchText');
    if (!cf) { mt.textContent = ''; return; }
    mt.textContent = pw === cf ? '✓ Passwords match' : '✗ Passwords do not match';
    mt.style.color = pw === cf ? 'var(--success)' : 'var(--danger)';
}
</script>
</body>
</html>
<script>
function togglePw(id, icon) {
    var i = document.getElementById(id), t = i.type === 'text';
    i.type = t ? 'password' : 'text';
    icon.className = (t ? 'bi bi-eye' : 'bi bi-eye-slash');
    icon.style.cssText = 'position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;';
}
document.getElementById('togPw').onclick   = function(){ togglePw('pw', this); };
document.getElementById('togConf').onclick = function(){ togglePw('pwConf', this); };

function setRule(id, ok) {
    var e = document.getElementById(id);
    e.textContent = (ok ? '✅' : '⬜') + e.textContent.slice(2);
    e.style.color = ok ? 'var(--success)' : 'var(--gray-500)';
    e.style.fontWeight = ok ? '600' : '400';
}
function checkStrength(v) {
    var rl  = v.length >= 8 && v.length <= 18;
    var ru  = /[A-Z]/.test(v);
    var rlw = /[a-z]/.test(v);
    var rn  = /[0-9]/.test(v);
    var rs  = /[^A-Za-z0-9]/.test(v);
    setRule('rule_len',   rl);
    setRule('rule_upper', ru);
    setRule('rule_lower', rlw);
    setRule('rule_num',   rn);
    setRule('rule_spec',  rs);
    var score = [rl, ru, rlw, rn, rs].filter(Boolean).length;
    var fills = ['0%','20%','40%','60%','80%','100%'];
    var cols  = ['','#ef4444','#f97316','#eab308','#84cc16','#22c55e'];
    var labs  = ['','Weak','Fair','Moderate','Good','Strong ✓'];
    document.getElementById('strengthFill').style.width      = fills[score];
    document.getElementById('strengthFill').style.background = cols[score] || '';
    document.getElementById('strengthText').textContent      = v.length ? labs[score] : '';
    document.getElementById('strengthText').style.color      = cols[score] || '';
    checkMatch();
}
function checkMatch() {
    var pw = document.getElementById('pw').value;
    var cf = document.getElementById('pwConf').value;
    var mt = document.getElementById('matchText');
    if (!cf) { mt.textContent = ''; return; }
    mt.textContent = pw === cf ? '✓ Passwords match' : '✗ Do not match';
    mt.style.color = pw === cf ? 'var(--success)' : 'var(--danger)';
}
</script>
</body>
</html>
