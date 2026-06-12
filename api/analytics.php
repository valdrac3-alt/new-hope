<?php
// API: return chart data for the analytics dashboard.
// Migrated to PDO / PostgreSQL (Supabase)

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Helper: run a query and die cleanly on failure
function run_query(PDO $conn, string $sql): array {
    try {
        $result = $conn->query($sql);
        return $result ? $result->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query failed: ' . $e->getMessage()]);
        exit();
    }
}
function run_query_val(PDO $conn, string $sql, string $field = 'c') {
    $rows = run_query($conn, $sql);
    return $rows[0][$field] ?? 0;
}

// PATIENTS PER MONTH (last 12 months) — PostgreSQL date functions
if ($action === 'patients_per_month') {
    $rows = run_query($conn, "
        SELECT TO_CHAR(created_at, 'Mon YYYY') as label,
               TO_CHAR(created_at, 'YYYY-MM')  as sort_key,
               COUNT(*) as total
        FROM patients
        WHERE created_at >= NOW() - INTERVAL '12 months'
        GROUP BY sort_key, label
        ORDER BY sort_key ASC
    ");
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('intval', array_column($rows, 'total')),
    ]);
    exit();
}

// APPOINTMENTS PER MONTH (last 12 months)
if ($action === 'appointments_per_month') {
    $rows = run_query($conn, "
        SELECT TO_CHAR(appointment_date, 'Mon YYYY') as label,
               TO_CHAR(appointment_date, 'YYYY-MM')  as sort_key,
               COUNT(*) as total
        FROM appointments
        WHERE appointment_date >= NOW() - INTERVAL '12 months'
        GROUP BY sort_key, label
        ORDER BY sort_key ASC
    ");
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('intval', array_column($rows, 'total')),
    ]);
    exit();
}

// TOP SERVICES (completed appointments)
if ($action === 'top_services') {
    $rows = run_query($conn, "
        SELECT s.service_name as label, COUNT(a.id) as total
        FROM appointments a
        JOIN services s ON a.service_id = s.id
        WHERE a.status = 'completed'
        GROUP BY s.id, s.service_name
        ORDER BY total DESC
        LIMIT 8
    ");
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('intval', array_column($rows, 'total')),
    ]);
    exit();
}

// APPOINTMENT STATUS BREAKDOWN (current month)
if ($action === 'status_breakdown') {
    $rows = run_query($conn, "
        SELECT status as label, COUNT(*) as total
        FROM appointments
        WHERE appointment_date >= DATE_TRUNC('month', NOW())
          AND appointment_date <  DATE_TRUNC('month', NOW()) + INTERVAL '1 month'
        GROUP BY status
    ");
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('intval', array_column($rows, 'total')),
    ]);
    exit();
}

// PEAK BOOKING DAYS (all time) — PostgreSQL day name
if ($action === 'peak_days') {
    $rows = run_query($conn, "
        SELECT TO_CHAR(appointment_date, 'Day') as label,
               EXTRACT(DOW FROM appointment_date)::int as sort_key,
               COUNT(*) as total
        FROM appointments
        GROUP BY sort_key, label
        ORDER BY sort_key ASC
    ");
    // Trim whitespace from TO_CHAR Day output
    foreach ($rows as &$r) { $r['label'] = trim($r['label']); }
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('intval', array_column($rows, 'total')),
    ]);
    exit();
}

// PEAK BOOKING HOURS (all time)
if ($action === 'peak_hours') {
    $rows = run_query($conn, "
        SELECT TO_CHAR(appointment_time::time, 'HH12:00 AM') as label,
               EXTRACT(HOUR FROM appointment_time::time)::int as sort_key,
               COUNT(*) as total
        FROM appointments
        GROUP BY sort_key, label
        ORDER BY sort_key ASC
    ");
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('intval', array_column($rows, 'total')),
    ]);
    exit();
}

// NEW VS RETURNING PATIENTS (current month)
if ($action === 'new_vs_returning') {
    $month_start = date('Y-m-01');

    $new = (int) run_query_val($conn, "
        SELECT COUNT(*) as c FROM patients
        WHERE created_at >= '$month_start'
    ");

    $returning = (int) run_query_val($conn, "
        SELECT COUNT(DISTINCT a.patient_id) as c
        FROM appointments a
        JOIN patients p ON p.id = a.patient_id
        WHERE a.appointment_date >= '$month_start'
          AND p.created_at        <  '$month_start'
    ");

    echo json_encode([
        'status' => 'ok',
        'labels' => ['New Patients', 'Returning Patients'],
        'data'   => [$new, $returning],
    ]);
    exit();
}

// REVENUE PER MONTH (last 6 months)
if ($action === 'revenue_per_month') {
    $rows = run_query($conn, "
        SELECT TO_CHAR(created_at, 'Mon YYYY') as label,
               TO_CHAR(created_at, 'YYYY-MM')  as sort_key,
               SUM(amount_paid) as total
        FROM bills
        WHERE created_at >= NOW() - INTERVAL '6 months'
        GROUP BY sort_key, label
        ORDER BY sort_key ASC
    ");
    echo json_encode([
        'status' => 'ok',
        'labels' => array_column($rows, 'label'),
        'data'   => array_map('floatval', array_column($rows, 'total')),
    ]);
    exit();
}

// SUMMARY KPI NUMBERS
if ($action === 'kpi_summary') {
    $month_start = date('Y-m-01');
    $today       = date('Y-m-d');

    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM appointments WHERE appointment_date = ?");
    $stmt->execute([$today]);
    $today_appts = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM appointments WHERE status = 'pending'");
    $stmt->execute();
    $pending = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    $stmt = $conn->prepare("
        SELECT COUNT(*) as c FROM appointments
        WHERE status = 'completed' AND appointment_date >= ?
    ");
    $stmt->execute([$month_start]);
    $completed = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount_paid), 0) as c FROM bills WHERE created_at::date >= ?
    ");
    $stmt->execute([$month_start]);
    $revenue = (float) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    $stmt = $conn->prepare("
        SELECT COUNT(*) as c FROM appointments WHERE appointment_date >= ?
    ");
    $stmt->execute([$month_start]);
    $total_this_month = (int) $stmt->fetch(PDO::FETCH_ASSOC)['c'];

    $total_patients = (int) run_query_val($conn, "SELECT COUNT(*) as c FROM patients WHERE is_active = TRUE");

    $rate = $total_this_month > 0 ? round(($completed / $total_this_month) * 100, 1) : 0;

    echo json_encode([
        'status'          => 'ok',
        'total_patients'  => $total_patients,
        'today_appts'     => $today_appts,
        'pending'         => $pending,
        'completed_month' => $completed,
        'revenue_month'   => number_format($revenue, 2),
        'completion_rate' => $rate,
    ]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
