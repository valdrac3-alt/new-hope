<?php
// API: returns next available walk-in slot for today.
// Migrated to PDO / PostgreSQL (Supabase)
require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$today = date('Y-m-d');
$day   = strtolower(date('l'));
$now   = time();

$bl_stmt = $conn->prepare("SELECT id FROM blocked_dates WHERE blocked_date = ? LIMIT 1");
$bl_stmt->execute([$today]);
$blocked = $bl_stmt->fetch(PDO::FETCH_COLUMN) ? 1 : 0;
if ($blocked > 0) {
    echo json_encode(['is_closed' => true, 'reason' => 'Today is a blocked date (holiday or clinic closed).', 'slot' => null, 'label' => null]);
    exit();
}

$sc_stmt = $conn->prepare("SELECT * FROM schedules WHERE day_of_week = ? AND is_open = TRUE LIMIT 1");
$sc_stmt->execute([$day]);
$sched = $sc_stmt->fetch(PDO::FETCH_ASSOC);
if (!$sched) {
    echo json_encode(['is_closed' => true, 'reason' => 'No schedule configured for ' . ucfirst($day) . '.', 'slot' => null, 'label' => null]);
    exit();
}

$open_ts  = strtotime($today . ' ' . $sched['open_time']);
$close_ts = strtotime($today . ' ' . $sched['close_time']);
$slot_dur = intval($sched['slot_duration_minutes']);
$step     = $slot_dur * 60;

if ($step <= 0) {
    echo json_encode(['is_closed' => true, 'reason' => 'Invalid clinic schedule: slot duration must be greater than zero.', 'slot' => null, 'label' => null]);
    exit();
}

$br_stmt = $conn->prepare("
    SELECT a.appointment_time,
           COALESCE(s.duration_minutes, ?) AS duration_minutes
    FROM appointments a
    LEFT JOIN services s ON s.id = a.service_id
    WHERE a.appointment_date = ?
    AND a.status NOT IN ('cancelled','no-show')
");
$br_stmt->execute([$slot_dur, $today]);
$booked_rows    = $br_stmt->fetchAll(PDO::FETCH_ASSOC);
$booked_windows = [];
foreach ($booked_rows as $row) {
    $start = strtotime($today . ' ' . $row['appointment_time']);
    $booked_windows[] = ['start' => $start, 'end' => $start + intval($row['duration_minutes']) * 60];
}

$next_slot = null; $next_label = null;
for ($t = $open_ts; $t < $close_ts; $t += $step) {
    if ($t < $now) continue;
    $taken = false;
    foreach ($booked_windows as $w) {
        if ($t >= $w['start'] && $t < $w['end']) { $taken = true; break; }
    }
    if (!$taken) { $next_slot = date('H:i', $t); $next_label = date('h:i A', $t); break; }
}

echo json_encode([
    'is_closed'   => false,
    'is_full'     => $next_slot === null,
    'slot'        => $next_slot,
    'label'       => $next_label,
    'open_label'  => date('h:i A', $open_ts),
    'close_label' => date('h:i A', $close_ts),
    'reason'      => $next_slot === null ? 'Schedule is full for today.' : null,
]);
