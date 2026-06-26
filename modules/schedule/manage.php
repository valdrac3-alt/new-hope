<?php
// Manage weekly clinic hours and block specific dates (holidays, closures).

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Schedule Management';
$current_user_id = $_SESSION['user']['id'] ?? $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
$current_user_name = $current_user_name ?? ($_SESSION['user']['name'] ?? $_SESSION['name'] ?? $_SESSION['full_name'] ?? 'System');

$success = '';
$error   = '';

$days_list = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

// Ensure exactly ONE row per day exists in schedules table
foreach ($days_list as $d) {
    $count = $conn->query(
        "SELECT COUNT(*) as c FROM schedules WHERE day_of_week = '$d'"
    )->fetch(PDO::FETCH_ASSOC)['c'];

    if ($count > 1) {
        // Keep only highest ID row
        $conn->query("
            DELETE FROM schedules
            WHERE day_of_week = '$d'
            AND id NOT IN (
                SELECT id FROM (
                    SELECT MAX(id) as id FROM schedules WHERE day_of_week = '$d'
                ) as t
            )
        ");
    } elseif ($count == 0) {
        // Insert a default row
        $defaults = [
            'monday'    => ['08:00','17:00',30,1],
            'tuesday'   => ['08:00','17:00',30,1],
            'wednesday' => ['08:00','17:00',30,1],
            'thursday'  => ['08:00','17:00',30,1],
            'friday'    => ['08:00','17:00',30,1],
            'saturday'  => ['08:00','12:00',30,1],
            'sunday'    => ['00:00','00:00',30,0],
        ];
        [$o, $c2, $dur, $open] = $defaults[$d];
        $conn->query(
            "INSERT INTO schedules (day_of_week, open_time, close_time, slot_duration_minutes, is_open)
             VALUES ('$d', '$o', '$c2', $dur, $open)"
        );
    }
}

// UPDATE SCHEDULE
// KEY FIX: Read time values from HIDDEN inputs (not disabled
// visible inputs) so values are always submitted with the form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
        validate_csrf();
    foreach ($days_list as $day) {
        // is_open comes from checkbox — only present when checked
        $is_open = isset($_POST['open_' . $day]) ? 1 : 0;

        // Times come from hidden inputs — always submitted regardless of disabled state
        $open_time  = trim($_POST['hidden_open_'  . $day] ?? '08:00');
        $close_time = trim($_POST['hidden_close_' . $day] ?? '17:00');
        $duration   = intval($_POST['duration_' . $day] ?? 30);

        // Validate time format HH:MM or HH:MM:SS
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $open_time))  $open_time  = '08:00';
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $close_time)) $close_time = '17:00';

        $stmt = $conn->prepare("
            UPDATE schedules
            SET is_open = ?, open_time = ?, close_time = ?, slot_duration_minutes = ?
            WHERE day_of_week = ?
        ");
        $stmt->execute([$is_open, $open_time, $close_time, $duration, $day]);
        $stmt = null;
    }

    log_action($conn, $current_user_id, $current_user_name, 'Updated Clinic Schedule', 'schedule');
    $success = 'Schedule saved successfully!';
}

// BLOCK A DATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['block_date'])) {
    validate_csrf();
    $date   = trim($_POST['blocked_date'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        $stmt = $conn->prepare(
            "INSERT IGNORE INTO blocked_dates (blocked_date, reason, created_by) VALUES (?,?,?)"
        );
        $stmt->execute([$date, $reason, $current_user_id]);
        $stmt = null;
        log_action($conn, $current_user_id, $current_user_name, 'Blocked Date', 'schedule', null, "Blocked: $date — $reason");
        $success = "Date $date has been blocked.";
    } else {
        $error = 'Please enter a valid date.';
    }
}

// UNBLOCK A DATE
if (isset($_GET['unblock'])) {
    $bid = intval($_GET['unblock'] ?? 0);
    if ($bid > 0) {
        $stmt = $conn->prepare("DELETE FROM blocked_dates WHERE id = ?");
        $stmt->execute([$bid]);
        $stmt = null;
        log_action($conn, $current_user_id, $current_user_name, 'Unblocked Date', 'schedule', $bid);
    }
    header('Location: manage.php?msg=unblocked');
    exit();
}

// Load fresh from DB after any updates
$schedules     = $conn->query("
    SELECT * FROM schedules
    ORDER BY CASE day_of_week WHEN 'monday' THEN 1 WHEN 'tuesday' THEN 2 WHEN 'wednesday' THEN 3 WHEN 'thursday' THEN 4 WHEN 'friday' THEN 5 WHEN 'saturday' THEN 6 WHEN 'sunday' THEN 7 END
")->fetchAll(PDO::FETCH_ASSOC);

$blocked_dates = $conn->query(
    "SELECT * FROM blocked_dates ORDER BY blocked_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Clinic Schedule Management</h5>
                <p>Set clinic hours per day — walk-in appointment booking will respect these settings</p>
            </div>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i> <?php echo e($success); ?>
        </div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-triangle-fill"></i> <?php echo e($error); ?>
        </div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'unblocked'): ?>
        <div class="alert alert-success">
            <i class="bi bi-check-circle-fill"></i> Date unblocked successfully.
        </div>
        <?php endif; ?>

        <!-- Weekly Schedule -->
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-calendar-week" style="color:var(--blue-500)"></i>
                Weekly Clinic Hours
            </div>
            <div class="card-body">
                <p style="font-size:0.82rem;color:var(--gray-500);margin-bottom:16px;">
                    <i class="bi bi-info-circle"></i>
                    Check the box to mark a day as open. Set open/close times and slot duration.
                    Changes affect walk-in scheduling and appointment booking immediately.
                </p>

                <form method="POST" id="scheduleForm">
                    <?php echo csrf_field(); ?>
                    <div class="table-responsive">
                        <table class="table align-middle" style="font-size:0.875rem;">
                            <thead>
                                <tr>
                                    <th style="width:130px;">Day</th>
                                    <th style="width:80px; text-align:center;">Open?</th>
                                    <th style="width:160px;">Open Time</th>
                                    <th style="width:160px;">Close Time</th>
                                    <th>Slot Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($schedules as $s):
                                    $d      = $s['day_of_week'];
                                    $is_open= $s['is_open'];
                                    $ot_raw = substr($s['open_time'],  0, 5);
                                    $ct_raw = substr($s['close_time'], 0, 5);
                                    // Show sensible defaults for closed days with midnight placeholder
                                    $ot  = (!$is_open && $ot_raw === '00:00') ? '08:00' : $ot_raw;
                                    $ct  = (!$is_open && $ct_raw === '00:00') ? '17:00' : $ct_raw;
                                    $dur = $s['slot_duration_minutes'];
                                ?>
                                <tr id="row-<?php echo $d; ?>" style="<?php echo !$is_open ? 'opacity:0.55;' : ''; ?>">
                                    <td><strong><?php echo ucfirst($d); ?></strong></td>
                                    <td style="text-align:center;">
                                        <input type="checkbox"
                                            name="open_<?php echo $d; ?>"
                                            id="cb_<?php echo $d; ?>"
                                            class="form-check-input"
                                            style="width:18px;height:18px;cursor:pointer;"
                                            onchange="toggleDay('<?php echo $d; ?>', this.checked)"
                                            <?php echo $is_open ? 'checked' : ''; ?>>
                                    </td>
                                    <td>
                                        <!--
                                            IMPORTANT: We use a VISIBLE time input for the UI
                                            and a HIDDEN input to always submit the value.
                                            The hidden input is updated by JS when the visible one changes.
                                            This ensures the value is submitted even when the row is "closed".
                                        -->
                                        <input type="time"
                                            id="open_time_<?php echo $d; ?>"
                                            class="form-control form-control-sm"
                                            value="<?php echo $ot; ?>"
                                            onchange="document.getElementById('hidden_open_<?php echo $d; ?>').value=this.value"
                                            <?php echo !$is_open ? 'disabled' : ''; ?>>
                                        <input type="hidden"
                                            id="hidden_open_<?php echo $d; ?>"
                                            name="hidden_open_<?php echo $d; ?>"
                                            value="<?php echo $ot; ?>">
                                    </td>
                                    <td>
                                        <input type="time"
                                            id="close_time_<?php echo $d; ?>"
                                            class="form-control form-control-sm"
                                            value="<?php echo $ct; ?>"
                                            onchange="document.getElementById('hidden_close_<?php echo $d; ?>').value=this.value"
                                            <?php echo !$is_open ? 'disabled' : ''; ?>>
                                        <input type="hidden"
                                            id="hidden_close_<?php echo $d; ?>"
                                            name="hidden_close_<?php echo $d; ?>"
                                            value="<?php echo $ct; ?>">
                                    </td>
                                    <td>
                                        <select name="duration_<?php echo $d; ?>"
                                            id="duration_<?php echo $d; ?>"
                                            class="form-select form-select-sm"
                                            style="width:130px;"
                                            <?php echo !$is_open ? 'disabled' : ''; ?>>
                                            <?php foreach ([15, 30, 45, 60] as $dm): ?>
                                            <option value="<?php echo $dm; ?>"
                                                <?php echo $dur == $dm ? 'selected' : ''; ?>>
                                                <?php echo $dm; ?> min slots
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px;">
                        <button type="submit" name="update_schedule" class="btn btn-primary">
                            <i class="bi bi-floppy"></i> Save Schedule
                        </button>
                        <button type="button" class="btn btn-outline-secondary" onclick="applyToAll()" title="Copy Monday's settings to all open days">
                            <i class="bi bi-arrow-repeat"></i> Apply to All Days
                        </button>
                        <small style="color:var(--gray-400);">Copies Monday's hours &amp; slot duration to Tue – Sat</small>
                    </div>
                </form>
            </div>
        </div>

        <!-- Block Dates -->
        <div class="card">
            <div class="card-header">
                <i class="bi bi-calendar-x" style="color:var(--danger)"></i>
                Blocked Dates (Holidays / Clinic Closed)
            </div>
            <div class="card-body">
                <p style="font-size:0.82rem;color:var(--gray-500);margin-bottom:14px;">
                    On blocked dates, no appointments can be booked and walk-in will show a closed notice.
                </p>
                <form method="POST" class="row g-2 mb-3">
                <?php echo csrf_field(); ?>
                    <div class="col-md-3">
                        <input type="date" name="blocked_date"
                            class="form-control form-control-sm"
                            min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="reason"
                            class="form-control form-control-sm"
                            placeholder="Reason (e.g. Holiday, Doctor unavailable)">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" name="block_date" class="btn btn-warning btn-sm w-100">
                            <i class="bi bi-slash-circle"></i> Block Date
                        </button>
                    </div>
                </form>

                <?php if (empty($blocked_dates)): ?>
                    <p style="color:var(--gray-400);font-size:0.82rem;">No blocked dates set.</p>
                <?php else: ?>
                <table class="table table-sm">
                    <thead>
                        <tr><th>Date</th><th>Reason</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($blocked_dates as $bd): ?>
                        <tr>
                            <td style="font-weight:600;">
                                <?php echo date('l, M d, Y', strtotime($bd['blocked_date'])); ?>
                            </td>
                            <td style="color:var(--gray-500);">
                                <?php echo e($bd['reason'] ?: '—'); ?>
                            </td>
                            <td>
                                <a href="manage.php?unblock=<?php echo $bd['id']; ?>"
                                   class="btn btn-sm btn-outline-danger"
                                   onclick="return confirm('Unblock this date?')">
                                    <i class="bi bi-unlock"></i> Unblock
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

    </div>

<script>
function toggleDay(day, isOpen) {
    var row    = document.getElementById('row-' + day);
    var fields = ['open_time_' + day, 'close_time_' + day, 'duration_' + day];

    row.style.opacity = isOpen ? '1' : '0.55';

    fields.forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.disabled = !isOpen;
    });
}

// Before submitting: make sure all hidden inputs have current values
document.getElementById('scheduleForm').addEventListener('submit', function() {
    <?php foreach ($days_list as $d): ?>
    var ot = document.getElementById('open_time_<?php echo $d; ?>');
    var ct = document.getElementById('close_time_<?php echo $d; ?>');
    var ho = document.getElementById('hidden_open_<?php echo $d; ?>');
    var hc = document.getElementById('hidden_close_<?php echo $d; ?>');
    if (ot && ho) ho.value = ot.value;
    if (ct && hc) hc.value = ct.value;
    <?php endforeach; ?>
});

function applyToAll() {
    // Read Monday's values
    var monOpen  = document.getElementById('open_time_monday')  ? document.getElementById('open_time_monday').value  : document.getElementById('hidden_open_monday')?.value;
    var monClose = document.getElementById('close_time_monday') ? document.getElementById('close_time_monday').value : document.getElementById('hidden_close_monday')?.value;
    var monDur   = document.getElementById('duration_monday') ? document.getElementById('duration_monday').value : '30';

    var days = ['tuesday','wednesday','thursday','friday','saturday'];
    var count = 0;
    days.forEach(function(day) {
        // Only apply to open days — check if the day row exists and is open
        var openCheck = document.querySelector('[name="open_' + day + '"]');
        if (!openCheck) return;

        // Set visible time inputs
        var openInput  = document.getElementById('open_time_'  + day);
        var closeInput = document.getElementById('close_time_' + day);
        var hidOpenInput  = document.getElementById('hidden_open_'  + day);
        var hidCloseInput = document.getElementById('hidden_close_' + day);
        var durSelect  = document.getElementById('duration_' + day);

        if (openInput  && monOpen)  { openInput.value  = monOpen;  if(hidOpenInput)  hidOpenInput.value  = monOpen;  }
        if (closeInput && monClose) { closeInput.value = monClose; if(hidCloseInput) hidCloseInput.value = monClose; }
        if (durSelect  && monDur)   { durSelect.value  = monDur; }
        count++;
    });
    // Visual feedback
    var btn = document.querySelector('[onclick="applyToAll()"]');
    var orig = btn.innerHTML;
    btn.innerHTML = '<i class="bi bi-check-lg"></i> Applied to ' + count + ' days!';
    btn.style.background = 'var(--success)';
    btn.style.color = '#fff';
    btn.style.borderColor = 'var(--success)';
    setTimeout(function() {
        btn.innerHTML = orig;
        btn.style.background = '';
        btn.style.color = '';
        btn.style.borderColor = '';
    }, 2500);
}
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
