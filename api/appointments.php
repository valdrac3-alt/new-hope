<?php
// API: get available time slots for a date, update appointment status, delete appointment.
// Migrated to PDO / PostgreSQL (Supabase)

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

$body = json_decode(file_get_contents('php://input'), true) ?? [];
if (!empty($body)) $action = $body['action'] ?? $action;

$mutating_actions = ['update_status', 'delete_appointment'];
if (in_array($action, $mutating_actions)) {
    $submitted = $body['_csrf'] ?? ($_POST['_csrf'] ?? '');
    $expected  = $_SESSION['csrf_token'] ?? '';
    if (empty($submitted) || empty($expected) || !hash_equals($expected, $submitted)) {
        error_log('[CSRF] Token mismatch on api/appointments.php action=' . $action . ' from IP: ' . get_client_ip());
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Invalid request token. Please refresh and try again.']);
        exit();
    }
}

// GET AVAILABLE TIME SLOTS FOR A DATE
if ($action === 'get_slots') {
    $date      = $_GET['date']      ?? '';
    $doctor_id = intval($_GET['doctor_id'] ?? 0);

    if (empty($date)) {
        echo json_encode(['status' => 'error', 'message' => 'Date required']);
        exit();
    }

    $parsed_date = DateTime::createFromFormat('Y-m-d', $date);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $date) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid date format. Expected YYYY-MM-DD.']);
        exit();
    }

    $day      = strtolower(date('l', strtotime($date)));
    $day_code = strtolower(substr($day, 0, 3));

    $bl_stmt = $conn->prepare("SELECT id FROM blocked_dates WHERE blocked_date = ? LIMIT 1");
    $bl_stmt->execute([$date]);
    $blocked = $bl_stmt->rowCount();
    if ($blocked > 0) {
        echo json_encode(['status' => 'ok', 'slots' => [], 'message' => 'Clinic is closed on this date.']);
        exit();
    }

    $open_time  = null;
    $close_time = null;

    if ($doctor_id > 0) {
        $doc_stmt = $conn->prepare("SELECT schedule_days, start_time, end_time FROM doctors WHERE id = ? AND is_active = TRUE LIMIT 1");
        $doc_stmt->execute([$doctor_id]);
        $doctor = $doc_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doctor) {
            echo json_encode(['status' => 'ok', 'slots' => [], 'message' => 'Doctor not found or inactive.']);
            exit();
        }

        $working_days = array_map('trim', explode(',', $doctor['schedule_days'] ?? ''));
        if (!in_array($day_code, $working_days)) {
            echo json_encode(['status' => 'ok', 'slots' => [], 'message' => 'This doctor does not work on ' . ucfirst($day) . 's.']);
            exit();
        }

        $open_time  = $doctor['start_time'];
        $close_time = $doctor['end_time'];
    }

    $sc_stmt = $conn->prepare("SELECT * FROM schedules WHERE day_of_week = ? AND is_open = TRUE LIMIT 1");
    $sc_stmt->execute([$day]);
    $sched = $sc_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sched) {
        echo json_encode(['status' => 'ok', 'slots' => [], 'message' => 'Clinic is closed on this day.']);
        exit();
    }

    $open_time  = $open_time  ?? $sched['open_time'];
    $close_time = $close_time ?? $sched['close_time'];

    $slots    = [];
    $start    = strtotime($open_time);
    $end      = strtotime($close_time);
    $slot_dur = intval($sched['slot_duration_minutes']);

    if ($slot_dur <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid clinic schedule: slot duration must be greater than zero.']);
        exit();
    }

    $step = $slot_dur * 60;

    if ($doctor_id > 0) {
        $br_stmt = $conn->prepare("
            SELECT a.appointment_time,
                   COALESCE(s.duration_minutes, ?) AS duration_minutes
            FROM   appointments a
            LEFT JOIN services s ON s.id = a.service_id
            WHERE  a.appointment_date = ?
            AND    a.doctor_id = ?
            AND    a.status NOT IN ('cancelled', 'no-show')
        ");
        $br_stmt->execute([$slot_dur, $date, $doctor_id]);
    } else {
        $br_stmt = $conn->prepare("
            SELECT a.appointment_time,
                   COALESCE(s.duration_minutes, ?) AS duration_minutes
            FROM   appointments a
            LEFT JOIN services s ON s.id = a.service_id
            WHERE  a.appointment_date = ?
            AND    a.status NOT IN ('cancelled', 'no-show')
        ");
        $br_stmt->execute([$slot_dur, $date]);
    }
    $booked_rows = $br_stmt->fetchAll(PDO::FETCH_ASSOC);

    $booked_windows = [];
    foreach ($booked_rows as $row) {
        $appt_start = strtotime($row['appointment_time']);
        $booked_windows[] = [
            'start' => $appt_start,
            'end'   => $appt_start + (intval($row['duration_minutes']) * 60),
        ];
    }

    for ($t = $start; $t < $end; $t += $step) {
        $time_24    = date('H:i', $t);
        $time_12    = date('h:i A', $t);
        $is_blocked = false;
        foreach ($booked_windows as $win) {
            if ($t >= $win['start'] && $t < $win['end']) {
                $is_blocked = true;
                break;
            }
        }
        $slots[] = [
            'time_24'   => $time_24,
            'time_12'   => $time_12,
            'available' => !$is_blocked,
        ];
    }

    echo json_encode(['status' => 'ok', 'slots' => $slots]);
    exit();
}

// UPDATE APPOINTMENT STATUS
if ($action === 'update_status') {
    $id     = intval($body['id'] ?? 0);
    $status = $body['status'] ?? '';
    $allowed = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];

    if (!$id || !in_array($status, $allowed)) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid data.']);
        exit();
    }

    if ($status === 'confirmed') {
        $past_chk = $conn->prepare("SELECT appointment_date FROM appointments WHERE id = ? LIMIT 1");
        $past_chk->execute([$id]);
        $past_row = $past_chk->fetch(PDO::FETCH_ASSOC);
        if ($past_row && strtotime($past_row['appointment_date']) < strtotime('today')) {
            http_response_code(422);
            echo json_encode(['status' => 'error', 'message' => 'Cannot confirm an appointment that has already passed.']);
            exit();
        }
    }

    if ($status === 'completed' && empty($body['force'])) {
        $chk = $conn->prepare("SELECT COUNT(*) as c FROM dental_records WHERE appointment_id = ?");
        $chk->execute([$id]);
        $chk_row = $chk->fetch(PDO::FETCH_ASSOC);
        if (($chk_row['c'] ?? 0) == 0) {
            echo json_encode([
                'status'  => 'no_record_warning',
                'appt_id' => $id,
            ]);
            exit();
        }
    }

    $stmt = $conn->prepare("UPDATE appointments SET status = ? WHERE id = ?");
    if ($stmt->execute([$status, $id])) {
        log_action($conn, $current_user_id, $current_user_name, 'Updated Appointment Status', 'appointments', $id, "Status changed to: $status");

        $appt_stmt = $conn->prepare("
            SELECT a.appointment_code, a.appointment_date,
                   CONCAT(p.first_name,' ',p.last_name) as patient_name
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            WHERE a.id = ? LIMIT 1
        ");
        $appt_stmt->execute([$id]);
        $appt = $appt_stmt->fetch(PDO::FETCH_ASSOC);
        if ($appt) {
            $pname = $appt['patient_name'];
            $code  = $appt['appointment_code'];
            $date  = date('M d, Y', strtotime($appt['appointment_date']));
            $notif_map = [
                'confirmed'  => ['Appointment Confirmed',  "$pname's appointment ($code) on $date has been confirmed."],
                'completed'  => ['Appointment Completed',  "$pname's appointment ($code) on $date marked as completed."],
                'cancelled'  => ['Appointment Cancelled',  "$pname's appointment ($code) on $date has been cancelled."],
                'no-show'    => ['Patient No-Show',        "$pname did not show up for appointment $code on $date."],
            ];
            if (isset($notif_map[$status])) {
                notify($conn, 'appointment', $notif_map[$status][0], $notif_map[$status][1], 'modules/appointments/list.php');
            }
        }

        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Update failed.']);
    }
    exit();
}

// DELETE APPOINTMENT
if ($action === 'delete_appointment') {
    $id = intval($body['id'] ?? 0);

    if (!$id) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid appointment ID.']);
        exit();
    }

    $appt_stmt = $conn->prepare("SELECT appointment_code FROM appointments WHERE id = ? LIMIT 1");
    $appt_stmt->execute([$id]);
    $appt = $appt_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$appt) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Appointment not found.']);
        exit();
    }

    $conn->prepare("DELETE FROM bills WHERE appointment_id = ?")->execute([$id]);
    $del = $conn->prepare("DELETE FROM appointments WHERE id = ?");
    $del->execute([$id]);

    if ($del->rowCount() > 0) {
        log_action($conn, $current_user_id, $current_user_name, 'Deleted Appointment', 'appointments', $id, "Permanently deleted: " . $appt['appointment_code']);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Delete failed.']);
    }
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
