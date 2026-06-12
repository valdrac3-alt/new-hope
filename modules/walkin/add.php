<?php
ob_start(); // Buffer output — prevents PHP warnings from corrupting JSON responses
// Register a walk-in patient and auto-assign the next available time slot for today.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Variables from auth.php for static analysis
/** @var int $current_user_id */
/** @var string $current_user_name */
/** @var string $current_user_role */

$page_title = 'Walk-in Registration';
$error      = '';
$success    = '';
$new_appt   = null;

$services = sc_get('svc_walkin') ?? sc_set('svc_walkin', $conn->query(
    "SELECT id, service_name, price, duration_minutes FROM services WHERE is_active = TRUE ORDER BY service_name"
)->fetchAll(PDO::FETCH_ASSOC), 300);

$walkin_doctors = sc_get('doc_walkin') ?? sc_set('doc_walkin', $conn->query(
    "SELECT id, full_name, specialization FROM doctors WHERE is_active = TRUE ORDER BY full_name ASC"
)->fetchAll(PDO::FETCH_ASSOC), 300);

// WALK-IN NEXT SLOT LOGIC
//
// Correct approach (as described in system documentation):
//
//   1. Get clinic schedule for today (open_time, close_time, duration)
//   2. Get ALL appointments already booked today
//   3. Generate every possible slot from open_time → close_time
//      using the configured duration interval (e.g. every 30 mins)
//   4. For each slot, check if it is already taken by an appointment
//   5. Return the FIRST slot that is free AND is not in the past
//
// Example:
//   Schedule: 8:00 AM – 5:00 PM, 30-min slots
//   Booked:   9:00, 9:30, 10:00
//   Current time: 9:50 AM
//   → Skips 8:00,8:30 (past), 9:00,9:30,10:00 (taken)
//   → Returns 10:30 AM as the next available slot
//
// If NO open schedule exists today → staff can enter manual time
// If schedule is full → show message but still allow manual time
function find_next_available_slot($conn) {
    $today = date('Y-m-d');
    $now   = time();
    $day   = strtolower(date('l'));

    // Check if today is blocked
    $bl = $conn->prepare("SELECT id FROM blocked_dates WHERE blocked_date = ? LIMIT 1");
    $bl->execute([$today]);
    $blocked_row = $bl->fetch(PDO::FETCH_ASSOC);
    $bl->closeCursor();

    if ($blocked_row) {
        return [
            'slot'      => null,
            'label'     => null,
            'is_closed' => true,
            'reason'    => 'Today is a blocked date (holiday or clinic closed).',
            'all_slots' => [],
        ];
    }

    // Get today's schedule
    $ss = $conn->prepare("SELECT * FROM schedules WHERE day_of_week = ? AND is_open = TRUE LIMIT 1");
    $ss->execute([$day]);
    $sched = $ss->fetch(PDO::FETCH_ASSOC);
    $ss->closeCursor();

    if (!$sched) {
        return [
            'slot'      => null,
            'label'     => null,
            'is_closed' => true,
            'reason'    => 'No schedule configured for ' . ucfirst($day) . '. Use the manual time field below.',
            'all_slots' => [],
        ];
    }

    $open_ts  = strtotime($today . ' ' . $sched['open_time']);
    $close_ts = strtotime($today . ' ' . $sched['close_time']);
    $step     = intval($sched['slot_duration_minutes'] ?? 30) * 60;

    // Get all booked appointments today WITH durations (not cancelled/no-show).
    // We need duration_minutes so we block slots that fall *inside* an existing
    // appointment's window, not just slots that share the exact same start time.
    // e.g. A 90-min root canal at 13:00 must also block the 13:30 slot.
    $br = $conn->prepare("
        SELECT a.appointment_time,
               COALESCE(s.duration_minutes, ?) AS duration_minutes
        FROM   appointments a
        LEFT JOIN services s ON s.id = a.service_id
        WHERE  a.appointment_date = ?
        AND    a.status NOT IN ('cancelled','no-show')
        ORDER BY a.appointment_time ASC
    ");
    $br->execute([intval($sched['slot_duration_minutes']), $today]);
    $booked_rows = $br->fetchAll(PDO::FETCH_ASSOC);
    $br->closeCursor();
    $booked_windows = [];
    $booked_count   = 0;
    foreach ($booked_rows as $row) {
        $appt_start = strtotime($today . ' ' . $row['appointment_time']);
        $booked_windows[] = [
            'start' => $appt_start,
            'end'   => $appt_start + (intval($row['duration_minutes']) * 60),
        ];
        $booked_count++;
    }

    // Build list of all slots and find the first free one after current time.
    $all_slots  = [];
    $next_slot  = null;
    $next_label = null;

    for ($t = $open_ts; $t < $close_ts; $t += $step) {
        $slot_time  = date('H:i', $t);
        $slot_label = date('h:i A', $t);
        $is_past    = $t < $now;

        // A slot is taken if it falls inside any existing appointment's time window.
        // Condition: appt_start <= slot_time < appt_end
        $is_taken = false;
        foreach ($booked_windows as $win) {
            if ($t >= $win['start'] && $t < $win['end']) {
                $is_taken = true;
                break;
            }
        }

        $all_slots[] = [
            'time'  => $slot_time,
            'label' => $slot_label,
            'taken' => $is_taken,
            'past'  => $is_past,
        ];

        // First slot that is free AND not in the past
        if (!$next_slot && !$is_taken && !$is_past) {
            $next_slot  = $slot_time . ':00';
            $next_label = $slot_label;
        }
    }

    $is_full = ($next_slot === null);

    return [
        'slot'        => $next_slot,
        'label'       => $next_label,
        'is_closed'   => false,
        'is_full'     => $is_full,
        'reason'      => $is_full
            ? 'Schedule is full for today (no more slots before ' . date('h:i A', $close_ts) . ').'
            : null,
        'all_slots'   => $all_slots,
        'open_label'  => date('h:i A', $open_ts),
        'close_label' => date('h:i A', $close_ts),
        'booked_count'=> $booked_count,
        'total_slots' => count($all_slots),
    ];
}

// ── AJAX: slot + doctor data for any date (used by the drawer) ──────────────
function get_slots_for_date_any($conn, $date) {
    $today    = date('Y-m-d');
    $day      = strtolower(date('l', strtotime($date)));
    $is_today = ($date === $today);

    // Check blocked date
    $bst = $conn->prepare("SELECT id FROM blocked_dates WHERE blocked_date = ? LIMIT 1");
    $bst->execute([$date]);
    $blocked_row = $bst->fetch(PDO::FETCH_ASSOC);
    $bst->closeCursor();
    if ($blocked_row) return ['is_closed'=>true,'reason'=>'This date is blocked.','all_slots'=>[],'slot'=>null,'label'=>null];

    // Check schedule
    $sst = $conn->prepare("SELECT * FROM schedules WHERE day_of_week = ? AND is_open = TRUE LIMIT 1");
    $sst->execute([$day]);
    $sched = $sst->fetch(PDO::FETCH_ASSOC);
    $sst->closeCursor();
    if (!$sched) return ['is_closed'=>true,'reason'=>'Clinic is closed on '.ucfirst($day).'s.','all_slots'=>[],'slot'=>null,'label'=>null];

    $open_ts  = strtotime($date.' '.$sched['open_time']);
    $close_ts = strtotime($date.' '.$sched['close_time']);
    $step     = intval($sched['slot_duration_minutes'] ?? 30) * 60;
    $def_dur  = intval($sched['slot_duration_minutes'] ?? 30);

    // Get booked appointments
    $ast = $conn->prepare("SELECT a.appointment_time, COALESCE(s.duration_minutes,?) AS duration_minutes FROM appointments a LEFT JOIN services s ON s.id=a.service_id WHERE a.appointment_date=? AND a.status NOT IN ('cancelled','no-show')");
    $ast->execute([$def_dur, $date]);
    $arows = $ast->fetchAll(PDO::FETCH_ASSOC);
    $ast->closeCursor();

    $booked=[]; foreach ($arows as $r) { $s=strtotime($date.' '.$r['appointment_time']); $booked[]=['start'=>$s,'end'=>$s+intval($r['duration_minutes'])*60]; }
    $all_slots=[]; $next_slot=$next_label=null; $now=time();
    for ($t=$open_ts; $t<$close_ts; $t+=$step) {
        $taken=false; $past=$is_today&&($t<$now);
        foreach ($booked as $w){if($t>=$w['start']&&$t<$w['end']){$taken=true;break;}}
        $all_slots[]=['time'=>date('H:i',$t),'label'=>date('h:i A',$t),'taken'=>$taken,'past'=>$past];
        if(!$next_slot&&!$taken&&!$past){$next_slot=date('H:i',$t).':00';$next_label=date('h:i A',$t);}
    }
    $is_full=($next_slot===null);
    return ['is_closed'=>false,'is_full'=>$is_full,'slot'=>$next_slot,'label'=>$next_label,
        'reason'=>$is_full?'No available slots on '.date('M d,Y',strtotime($date)).'.':null,
        'all_slots'=>$all_slots,'total_slots'=>count($all_slots),'booked_count'=>count($arows),'is_today'=>$is_today];
}
function get_doctors_for_date_any($conn, $date) {
    $abbr=strtolower(substr(date('l',strtotime($date)),0,3));
    $res=$conn->query("SELECT id,full_name,specialization,schedule_days FROM doctors WHERE is_active=TRUE ORDER BY full_name ASC");
    $docs=[];
    while($d=$res->fetch(PDO::FETCH_ASSOC)){$days=array_map('trim',explode(',',$d['schedule_days']??''));if(in_array($abbr,$days))$docs[]=['id'=>$d['id'],'full_name'=>$d['full_name'],'specialization'=>$d['specialization']];}
    return $docs;
}
// ── AJAX: search existing patients by name or phone ─────────────────────────
if (isset($_GET['action']) && $_GET['action']==='search_patient') {
    ob_clean();
    header('Content-Type: application/json');
    $q = trim($_GET['q'] ?? '');
    if (strlen($q) < 2) { echo json_encode(['status'=>'ok','patients'=>[]]); exit(); }
    $like = '%' . $q . '%';
    $res = $conn->prepare("
        SELECT id, patient_code, first_name, last_name, phone,
               (SELECT COUNT(*) FROM appointments WHERE patient_id=patients.id) as appt_count
        FROM patients
        WHERE CONCAT(first_name,' ',last_name) LIKE ?
           OR phone LIKE ?
           OR patient_code LIKE ?
        ORDER BY last_name, first_name
        LIMIT 8
    ");
    $res->execute([$like, $like, $like]);
    $patients = $res->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'ok','patients'=>$patients]);
    exit();
}

if (isset($_GET['action']) && $_GET['action']==='get_slots') {
    ob_clean(); // discard any stray output before JSON
    header('Content-Type: application/json');
    $date = trim($_GET['date'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Invalid date format. Expected YYYY-MM-DD.']);
        exit();
    }
    // Allow today and future dates only
    $today_ph = date('Y-m-d'); // Asia/Manila set in config.php
    if ($date < $today_ph) {
        http_response_code(422);
        echo json_encode(['status'=>'error','message'=>'Cannot load slots for a past date.']);
        exit();
    }
    try {
        $slot_data   = get_slots_for_date_any($conn, $date);
        $doctors     = get_doctors_for_date_any($conn, $date);
        $day_name    = date('l, F j Y', strtotime($date));
        echo json_encode(['status'=>'success','slot_data'=>$slot_data,'doctors'=>$doctors,'day_name'=>$day_name]);
    } catch (Exception $ex) {
        error_log('[get_slots] ' . $ex->getMessage());
        http_response_code(500);
        echo json_encode(['status'=>'error','message'=>'Failed to load slot data. Please try again.']);
    }
    exit();
}

$slot_data  = find_next_available_slot($conn);
$next_slot  = $slot_data['slot'];
$next_label = $slot_data['label'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $first_name  = ucwords(strtolower(trim($_POST['first_name']  ?? '')));
    $last_name   = ucwords(strtolower(trim($_POST['last_name']   ?? '')));
    $phone       = trim($_POST['phone']       ?? '');

    // FIX #1: Use null instead of 0 when no service/doctor is selected.
    // A value of 0 breaks the FK constraint on the appointments table.
    $service_id  = !empty($_POST['service_id']) ? intval($_POST['service_id']) : null;
    $doctor_id   = !empty($_POST['doctor_id'])  ? intval($_POST['doctor_id'])  : null;

    $notes            = trim($_POST['notes']            ?? '');
    $selected_time    = trim($_POST['selected_time']    ?? ''); // future date slot picker
    $manual_time      = trim($_POST['manual_time']      ?? ''); // fallback (legacy)
    $appointment_date = trim($_POST['appointment_date'] ?? '');
    $today            = date('Y-m-d');
    $appointment_date = (!empty($appointment_date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $appointment_date)) ? $appointment_date : $today;
    $is_today_appt    = ($appointment_date === $today);
    // Prefer selected_time (slot picker) over manual_time
    $slot_input = !empty($selected_time) ? $selected_time : $manual_time;

    $existing_patient_id_check = intval($_POST['existing_patient_id'] ?? 0);
    if ($existing_patient_id_check === 0) {
        if (empty($first_name) || empty($last_name)) {
            $error = 'First name and last name are required.';
        } elseif (strlen($first_name) < 2 || strlen($last_name) < 2) {
            $error = 'First and last name must each be at least 2 characters.';
        }
    }
    if (empty($error) && !empty($phone) && !valid_phone($phone)) {
        $error = 'Please enter a valid phone number using the country code selector.';
    }
    if (empty($error) && strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    }
    if (empty($error)) {
        // Determine assigned time
        if (!empty($slot_input)) {
            // Staff picked a slot — validate format AND check conflicts on the correct date
            if (!preg_match('/^\d{2}:\d{2}$/', $slot_input)) {
                $error = 'Please enter time in HH:MM format (e.g. 14:30).';
            } else {
                $sel_ts = strtotime($appointment_date . ' ' . $slot_input);
                $conf_stmt = $conn->prepare("
                    SELECT a.appointment_time, COALESCE(s.duration_minutes,30) AS duration_minutes
                    FROM appointments a LEFT JOIN services s ON s.id=a.service_id
                    WHERE a.appointment_date=? AND a.status NOT IN ('cancelled','no-show')
                ");
                $conf_stmt->execute([$appointment_date]);
                $conf_rows = $conf_stmt->fetchAll(PDO::FETCH_ASSOC); $conf_stmt->closeCursor();
                $has_conflict=false; $conflict_label='';
                foreach ($conf_rows as $crow) {
                    $ex_start=strtotime($appointment_date.' '.$crow['appointment_time']);
                    $ex_end=$ex_start+(intval($crow['duration_minutes'])*60);
                    if ($sel_ts>=$ex_start&&$sel_ts<$ex_end){$has_conflict=true;$conflict_label=date('h:i A',$ex_start).' – '.date('h:i A',$ex_end);break;}
                }
                if ($has_conflict) {
                    $error = "That time ($slot_input) conflicts with an existing appointment ($conflict_label). Please choose a different time.";
                } else {
                    $assigned_time = $slot_input . ':00';
                }
            }
        } else {
            // Auto-assign: first free slot on the selected date
            $fresh = get_slots_for_date_any($conn, $appointment_date);
            if (!$fresh['slot']) {
                $error = $fresh['reason'] ?? 'No available slot found. Please pick a time manually.';
            } else {
                $assigned_time = $fresh['slot'];
            }
        }

        if (empty($error)) {
            // ── RETURNING PATIENT CHECK ────────────────────────────────────────
            // If patient_id was passed (staff selected an existing patient), use it.
            // Otherwise try to find a match by name + phone before creating new.
            $existing_patient_id = intval($_POST['existing_patient_id'] ?? 0);
            $patient_id          = 0;
            $is_returning        = false;

            if ($existing_patient_id > 0) {
                // Staff explicitly selected a returning patient from the search
                $chk = $conn->prepare("SELECT id, patient_code FROM patients WHERE id = ? LIMIT 1");
                $chk->execute([$existing_patient_id]);
                $chk_row = $chk->fetch(PDO::FETCH_ASSOC);
                $chk->closeCursor();
                if ($chk_row) {
                    $patient_id   = $chk_row['id'];
                    $patient_code = $chk_row['patient_code'];
                    $is_returning = true;
                }
            }

            if (!$patient_id) {
                // Auto-match: same first+last name AND same phone (non-empty)
                if (!empty($phone)) {
                    $match = $conn->prepare("SELECT id, patient_code FROM patients WHERE first_name=? AND last_name=? AND phone=? LIMIT 1");
                    $match->execute([$first_name, $last_name, $phone]);
                    $match_row = $match->fetch(PDO::FETCH_ASSOC);
                    $match->closeCursor();
                    if ($match_row) {
                        $patient_id   = $match_row['id'];
                        $patient_code = $match_row['patient_code'];
                        $is_returning = true;
                    }
                }
            }

            if (!$patient_id) {
                // No match — create new patient
                $patient_code = generate_code($conn, 'patients', 'PAT');
                $stmt = $conn->prepare(
                    "INSERT INTO patients (patient_code, first_name, last_name, phone, registered_by)
                     VALUES (?,?,?,?,?)"
                );
                $stmt->execute([$patient_code, $first_name, $last_name, $phone, $current_user_id]);
                $patient_id = $conn->lastInsertId();
                $stmt->closeCursor();
            }

            // Create appointment
            $appt_code = generate_code($conn, 'appointments', 'APT');
            $type      = 'walk-in';
            $status    = 'pending'; // Staff must click Confirm — never auto-confirmed

            $stmt2 = $conn->prepare("
                INSERT INTO appointments
                (appointment_code, patient_id, service_id, doctor_id, appointment_date,
                 appointment_time, type, status, notes, handled_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt2->execute([$appt_code, $patient_id, $service_id, $doctor_id, $appointment_date, $assigned_time, $type, $status, $notes, $current_user_id]);
            $new_appt_id = $conn->lastInsertId(); // capture immediately before other queries
            $stmt2->closeCursor();

            // Get service info for the slip
            $svc_name  = '—';
            $svc_price = 0;
            if ($service_id) {
                $svc_stmt = $conn->prepare("SELECT service_name, price FROM services WHERE id = ? LIMIT 1");
                $svc_stmt->execute([$service_id]);
                $svc_row   = $svc_stmt->fetch(PDO::FETCH_ASSOC);
                $svc_stmt->closeCursor();
                $svc_name  = $svc_row['service_name'] ?? '—';
                $svc_price = $svc_row['price'] ?? 0;
            }

            // FIX #3: Look up doctor name so it can be included in the AJAX response.
            $doc_name = '—';
            if ($doctor_id) {
                $doc_stmt = $conn->prepare("SELECT full_name FROM doctors WHERE id = ? LIMIT 1");
                $doc_stmt->execute([$doctor_id]);
                $doc_row  = $doc_stmt->fetch(PDO::FETCH_ASSOC);
                $doc_stmt->closeCursor();
                $doc_name = $doc_row['full_name'] ?? '—';
            }

            log_action($conn, $current_user_id, $current_user_name,
                'Walk-in Registration', 'walkin', $patient_id,
                "Patient: $first_name $last_name | Appt: $appt_code | Slot: $assigned_time"
            );

            $new_appt = [
                'appt_code'    => $appt_code,
                'appt_id'      => $new_appt_id,
                'patient_id'   => $patient_id,
                'patient_code' => $patient_code,
                'patient_name' => "$first_name $last_name",
                'phone'        => $phone,
                'service_name' => $svc_name,
                'service'      => $svc_name,
                'doctor_name'  => $doc_name,   // FIX #3: included in response
                'price'        => $svc_price,
                'date'         => $appointment_date,
                'time'         => $assigned_time,
                'notes'        => $notes,
                'staff'        => $current_user_name,
            ];

            $success = 'Walk-in registered! Assigned time slot: ' . date('h:i A', strtotime($assigned_time));

            // If called from drawer (AJAX), return JSON and exit
            if (!empty($_POST['_ajax'])) {
                ob_clean(); // Wipe any buffered warnings
                header('Content-Type: application/json');
                echo json_encode([
                    'status'       => 'success',
                    'message'      => $success,
                    'appt'         => $new_appt,
                    'is_returning' => $is_returning,
                ]);
                exit();
            }

            // Refresh slot data
            $slot_data  = find_next_available_slot($conn);
            $next_slot  = $slot_data['slot'];
            $next_label = $slot_data['label'];
        }
    }
}

// If AJAX request with an error, return JSON and stop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['_ajax']) && $error) {
    ob_clean(); // Wipe any buffered warnings
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $error]);
    exit();
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Walk-in Registration</h5>
                <p>
                    The system scans today's schedule and assigns the first available slot.
                    <?php if (!empty($slot_data['open_label'])): ?>
                    <span style="color:var(--gray-500);">
                        Clinic hours today: <strong><?php echo $slot_data['open_label']; ?></strong>
                        to <strong><?php echo $slot_data['close_label']; ?></strong>
                        (<?php echo $slot_data['total_slots'] ?? 0; ?> slots,
                        <?php echo $slot_data['booked_count'] ?? 0; ?> booked)
                    </span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <!-- Status Banner -->
        <?php if ($slot_data['is_closed']): ?>
        <div class="alert alert-warning" style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">
            <i class="bi bi-calendar-x" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <strong>Clinic closed today</strong> — <?php echo e($slot_data['reason']); ?><br>
                <span style="font-size:0.82rem;">You can still register using the manual time field below.</span>
            </div>
        </div>
        <?php elseif (!empty($slot_data['is_full'])): ?>
        <div class="alert alert-warning" style="display:flex;align-items:flex-start;gap:12px;margin-bottom:20px;">
            <i class="bi bi-calendar-check" style="font-size:1.2rem;flex-shrink:0;margin-top:2px;"></i>
            <div>
                <strong>Schedule is full</strong> — <?php echo e($slot_data['reason']); ?><br>
                <span style="font-size:0.82rem;">You can still register using the manual time field below.</span>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info" style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
            <i class="bi bi-clock-fill" style="font-size:1.2rem;flex-shrink:0;"></i>
            <div>
                <strong>Next available slot:</strong>
                <span style="color:var(--blue-600);font-weight:700;font-size:1.05rem;margin:0 8px;">
                    <?php echo $next_label; ?>
                </span>
                <span style="font-size:0.82rem;color:var(--gray-500);">
                    — This will be assigned to the walk-in patient automatically.
                </span>
            </div>
        </div>
        <?php endif; ?>

        <!-- Error -->
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <!-- TWO-COLUMN LAYOUT: Form left, Schedule right -->
        <div style="display:grid;grid-template-columns:1fr 380px;gap:22px;align-items:start;">

            <!-- LEFT: Form or Slip -->
            <div>
            <?php if ($success && $new_appt): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo e($success); ?></div>
            <div id="printSlip" style="margin-bottom:20px;">
                <div class="slip-box">
                    <div class="slip-header">
                        <div style="font-size:2rem;">🦷</div>
                        <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;margin:4px 0 2px;">DentalCare Clinic</div>
                        <div style="font-size:0.75rem;color:var(--gray-500);">Walk-in Appointment Slip</div>
                    </div>
                    <div class="slip-time">
                        <div class="tl">Assigned Time Slot</div>
                        <div class="tv"><?php echo date('h:i A', strtotime($new_appt['time'])); ?></div>
                        <div style="font-size:0.82rem;color:var(--gray-500);margin-top:4px;"><?php echo date('F d, Y', strtotime($new_appt['date'])); ?></div>
                    </div>
                    <div class="slip-row"><span class="sl">Appointment Code</span><span class="sv"><?php echo e($new_appt['appt_code']); ?></span></div>
                    <div class="slip-row"><span class="sl">Patient Code</span><span class="sv"><?php echo e($new_appt['patient_code']); ?></span></div>
                    <div class="slip-row"><span class="sl">Patient Name</span><span class="sv"><?php echo e($new_appt['patient_name']); ?></span></div>
                    <div class="slip-row"><span class="sl">Phone</span><span class="sv"><?php echo e($new_appt['phone'] ?: '—'); ?></span></div>
                    <div class="slip-row"><span class="sl">Service</span><span class="sv"><?php echo e($new_appt['service']); ?></span></div>
                    <?php if ($new_appt['price'] > 0): ?>
                    <div class="slip-row"><span class="sl">Estimated Fee</span><span class="sv">₱<?php echo number_format($new_appt['price'], 2); ?></span></div>
                    <?php endif; ?>
                    <div class="slip-row"><span class="sl">Served by</span><span class="sv"><?php echo e($new_appt['staff']); ?></span></div>
                    <div style="text-align:center;margin-top:14px;font-size:0.72rem;color:var(--gray-400);">
                        Please present this slip at the reception.<br>Generated: <?php echo date('M d, Y h:i A'); ?>
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;">
                <button onclick="window.print()" class="btn btn-primary"><i class="bi bi-printer"></i> Print Slip</button>
                <a href="add.php" class="btn btn-outline-secondary"><i class="bi bi-plus"></i> Register Another</a>
            </div>

            <?php else: ?>
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-walking" style="color:var(--blue-500)"></i> Patient Details
                </div>
                <div class="card-body">
                    <form method="POST">
                    <?php echo csrf_field(); ?>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="first_name" class="form-control" required autofocus>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <?php
                                    $phone_field_name     = 'phone';
                                    $phone_field_value    = '';
                                    $phone_field_label    = 'Phone (optional)';
                                    $phone_field_required = false;
                                    include '../../includes/phone_input.php';
                                ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Service <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <select name="service_id" class="form-select">
                                    <option value="">Select Service</option>
                                    <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"><?php echo e($s['service_name']); ?> — ₱<?php echo number_format($s['price'], 2); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if (!empty($walkin_doctors)): ?>
                            <div class="col-md-6">
                                <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <select name="doctor_id" class="form-select">
                                    <option value="">Any Available Doctor</option>
                                    <?php foreach ($walkin_doctors as $d): ?>
                                    <option value="<?php echo $d['id']; ?>">
                                        <?php echo e($d['full_name']); ?>
                                        <?php if ($d['specialization']): ?> — <?php echo e($d['specialization']); ?><?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <label class="form-label">Appointment Date <span style="font-size:0.72rem;color:var(--gray-400);">(leave blank for today)</span></label>
                                <input type="date" name="appointment_date" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes / Chief Complaint <span style="font-size:0.72rem;color:var(--gray-400)">(optional)</span></label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Describe the patient's concern..."></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">
                                    Override Time
                                    <span style="font-size:0.72rem;color:var(--gray-400);">
                                        — Leave blank to auto-assign<?php if ($next_label): ?> <strong style="color:var(--blue-500);">(<?php echo $next_label; ?>)</strong><?php endif; ?>
                                    </span>
                                </label>
                                <input type="text" name="manual_time" class="form-control" style="max-width:200px;"
                                    placeholder="HH:MM e.g. 14:30 (24-hr)" pattern="\d{2}:\d{2}">
                                <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">24-hour format only. Leave blank to use the next available slot.</div>
                            </div>
                        </div>
                        <div class="mt-4" style="display:flex;gap:10px;align-items:center;">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-person-check-fill"></i> Register Walk-in
                            </button>
                            <?php if ($next_label): ?>
                            <span style="font-size:0.82rem;color:var(--gray-500);">
                                <i class="bi bi-clock"></i> Auto-slot: <strong style="color:var(--blue-600);"><?php echo $next_label; ?></strong>
                            </span>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            </div><!-- /left col -->

            <!-- RIGHT: Today's slot timeline — always visible -->
            <div>
                <div class="card" style="position:sticky;top:82px;">
                    <div class="card-header" style="font-size:0.82rem;">
                        <i class="bi bi-calendar-day" style="color:var(--blue-500)"></i>
                        Today — <?php echo date('D, M d'); ?>
                    </div>
                    <div class="card-body" style="padding:14px 16px;">
                        <?php if (empty($slot_data['all_slots'])): ?>
                        <div style="text-align:center;padding:24px;color:var(--gray-400);font-size:0.82rem;">
                            <i class="bi bi-calendar-x" style="font-size:2rem;display:block;margin-bottom:8px;"></i>
                            <?php echo e($slot_data['reason'] ?? 'No schedule today.'); ?>
                        </div>
                        <?php else: ?>

                        <!-- Stats row -->
                        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:14px;text-align:center;">
                            <div style="background:var(--gray-50);border-radius:8px;padding:8px 4px;">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.3rem;color:var(--blue-500);"><?php echo $slot_data['total_slots']; ?></div>
                                <div style="font-size:0.62rem;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.05em;">Total</div>
                            </div>
                            <div style="background:var(--danger-bg);border-radius:8px;padding:8px 4px;">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.3rem;color:var(--danger);"><?php echo $slot_data['booked_count']; ?></div>
                                <div style="font-size:0.62rem;color:var(--danger);text-transform:uppercase;letter-spacing:0.05em;">Booked</div>
                            </div>
                            <div style="background:var(--success-bg);border-radius:8px;padding:8px 4px;">
                                <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1.3rem;color:var(--success);"><?php echo max(0, $slot_data['total_slots'] - $slot_data['booked_count']); ?></div>
                                <div style="font-size:0.62rem;color:var(--success);text-transform:uppercase;letter-spacing:0.05em;">Free</div>
                            </div>
                        </div>

                        <!-- Slot pills -->
                        <div class="slot-timeline">
                        <?php foreach ($slot_data['all_slots'] as $s):
                            if ($s['past'] && $s['taken']) $cls = 'taken past';
                            elseif ($s['past'])              $cls = 'past';
                            elseif ($s['taken'])             $cls = 'taken';
                            elseif ($s['time'].':00' === $next_slot) $cls = 'next';
                            else                             $cls = 'free';
                        ?>
                        <span class="slot-pill <?php echo $cls; ?>" title="<?php
                            if ($s['taken']) echo 'Booked';
                            elseif ($s['past']) echo 'Past';
                            elseif ($cls === 'next') echo 'Next → will be auto-assigned';
                            else echo 'Available';
                        ?>">
                            <?php echo $s['label']; ?>
                            <?php if ($s['taken']): ?> ✗<?php elseif ($cls === 'next'): ?> ←<?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                        </div>

                        <!-- Legend -->
                        <div style="margin-top:12px;font-size:0.68rem;color:var(--gray-400);display:flex;gap:10px;flex-wrap:wrap;">
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--blue-500);border-radius:2px;margin-right:3px;"></span>Next</span>
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--blue-100);border:1px solid var(--blue-300);border-radius:2px;margin-right:3px;"></span>Free</span>
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--gray-200);border-radius:2px;margin-right:3px;"></span>Booked</span>
                            <span><span style="display:inline-block;width:8px;height:8px;background:var(--gray-100);border-radius:2px;margin-right:3px;"></span>Past</span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div><!-- /right col -->

        </div><!-- /grid -->

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
