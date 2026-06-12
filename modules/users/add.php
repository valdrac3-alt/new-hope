<?php
// Create a new admin or staff user account.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Add User';
$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $password  = $_POST['password']       ?? '';
    $confirm   = $_POST['confirm_password'] ?? '';
    $role      = $_POST['role']           ?? 'staff';
    $email     = trim($_POST['email']     ?? '');
    $phone     = trim($_POST['phone']     ?? '');

    if (empty($full_name) || empty($username) || empty($password)) {
        $error = 'Full name, username, and password are required.';
    } elseif (empty($email)) {
        $error = 'Email address is required.';
    } elseif (!valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } elseif (empty($phone)) {
        $error = 'Phone number is required.';
    } elseif (!valid_phone($phone)) {
        $error = 'Please enter a valid phone number.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } elseif ($pw_err = validate_password($password)) {
        $error = $pw_err;
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $check->execute([$username]);
        $exists = $check->fetch(PDO::FETCH_ASSOC);
        $check->close();

        // Also check email uniqueness
        $email_check = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $email_check->execute([$email]);
        $email_exists = $email_check->fetch(PDO::FETCH_ASSOC);
        $email_check->close();

        if ($exists) {
            $error = 'Username already exists. Choose another.';
        } elseif ($email_exists) {
            $error = 'That email address is already registered to another user.';
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $stmt   = $conn->prepare("INSERT INTO users (full_name, username, password, role, email, phone) VALUES (?,?,?,?,?,?)");
            if ($stmt->execute([$full_name, $username, $hashed, $role, $email, $phone])) {
                $new_id = (int)$conn->lastInsertId();
                log_action($conn, $current_user_id, $current_user_name, 'Added User', 'users', $new_id, "New user: $username ($role)");
                $success = "User '$username' created successfully.";
            } else {
                $error = 'Failed to create user. Please try again.';
            }
            $stmt->close();
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Add New User</h5>
            <a href="list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
        </div>
        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <div class="card" style="max-width:540px;">
            <div class="card-body">
                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" required autocomplete="off" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select name="role" class="form-select">
                                <option value="staff" <?php echo ($_POST['role'] ?? '') === 'staff' ? 'selected' : ''; ?>>Staff</option>
                                <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <div style="position:relative;">
                                <input type="password" name="password" id="pw" class="form-control" required autocomplete="new-password" placeholder="8–18 chars" oninput="checkStrength(this.value)">
                                <i class="bi bi-eye" id="togPw" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;"></i>
                            </div>
                            <div id="strengthBar" style="height:4px;border-radius:4px;margin-top:6px;background:var(--gray-200);"><div id="strengthFill" style="height:100%;border-radius:4px;width:0%;transition:width 0.3s,background 0.3s;"></div></div>
                            <div id="strengthText" style="font-size:0.72rem;margin-top:3px;min-height:15px;font-weight:600;"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <div style="position:relative;">
                                <input type="password" name="confirm_password" id="pwConf" class="form-control" required autocomplete="new-password" oninput="checkMatch()">
                                <i class="bi bi-eye" id="togConf" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);cursor:pointer;"></i>
                            </div>
                            <div id="matchText" style="font-size:0.72rem;margin-top:3px;min-height:15px;font-weight:600;"></div>
                        </div>
                        <div class="col-12">
                            <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:8px;padding:9px 14px;font-size:0.75rem;color:var(--gray-500);display:flex;gap:14px;flex-wrap:wrap;">
                                <span id="rule_len">⬜ 8–18 chars</span>
                                <span id="rule_upper">⬜ uppercase (A–Z)</span>
                                <span id="rule_lower">⬜ lowercase (a–z)</span>
                                <span id="rule_num">⬜ number (0–9)</span>
                                <span id="rule_spec">⬜ special (@#$!...)</span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required
                                placeholder="user@example.com"
                                value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <?php
                                $phone_field_name     = 'phone';
                                $phone_field_value    = $_POST['phone'] ?? '';
                                $phone_field_label    = 'Phone';
                                $phone_field_required = true;
                                include '../../includes/phone_input.php';
                            ?>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary" id="createUserBtn"><i class="bi bi-person-plus-fill"></i> Create User</button>
                        <a href="list.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
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
    var len = v.length;
    var rl  = len >= 8 && len <= 18;
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
    var fill  = document.getElementById('strengthFill');
    var txt   = document.getElementById('strengthText');
    fill.style.width      = fills[score];
    fill.style.background = cols[score]  || '';
    txt.textContent       = v.length ? labs[score] : '';
    txt.style.color       = cols[score]  || '';
    checkMatch();
}
function checkMatch() {
    var pw = document.getElementById('pw').value;
    var cf = document.getElementById('pwConf').value;
    var mt = document.getElementById('matchText');
    if (!cf) { mt.textContent = ''; return; }
    mt.textContent = pw === cf ? '✓ Passwords match' : '✗ Do not match';
    mt.style.color = pw === cf ? 'var(--success)' : 'var(--danger)';
    updateSubmitBtn();
}

function updateSubmitBtn() {
    var pw   = document.getElementById('pw').value;
    var cf   = document.getElementById('pwConf').value;
    var rl   = pw.length >= 8 && pw.length <= 18;
    var ru   = /[A-Z]/.test(pw);
    var rlw  = /[a-z]/.test(pw);
    var rn   = /[0-9]/.test(pw);
    var rs   = /[^A-Za-z0-9]/.test(pw);
    var match = pw === cf && cf.length > 0;
    var allOk = rl && ru && rlw && rn && rs && match;
    var btn  = document.getElementById('createUserBtn');
    btn.disabled = !allOk;
    btn.title    = allOk ? '' : 'Please meet all password requirements and ensure passwords match.';
    btn.style.opacity = allOk ? '1' : '0.5';
}

// Run on page load so button starts disabled
document.addEventListener('DOMContentLoaded', function() {
    updateSubmitBtn();
    document.getElementById('pw').addEventListener('input', function() {
        checkStrength(this.value);
        updateSubmitBtn();
    });
    document.getElementById('pwConf').addEventListener('input', updateSubmitBtn);
});
</script>
</body>
</html>
