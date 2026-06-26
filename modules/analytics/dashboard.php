<?php
// Admin-only analytics: patient growth, appointment trends, revenue charts.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Analytics';

// ── Month navigation ─────────────────────────────────────────
$selected_month = $_GET['month'] ?? '';
if (!preg_match('/^\d{4}-\d{2}$/', $selected_month)) {
    $selected_month = date('Y-m');
}
$month_start_ts = strtotime($selected_month . '-01');
if (!$month_start_ts) { $selected_month = date('Y-m'); $month_start_ts = strtotime($selected_month . '-01'); }
$month_start  = date('Y-m-d', $month_start_ts);
$month_end    = date('Y-m-t', $month_start_ts);
$prev_month   = date('Y-m', strtotime('-1 month', $month_start_ts));
$next_month   = date('Y-m', strtotime('+1 month', $month_start_ts));
$is_future    = $next_month > date('Y-m');
$month_label  = date('F Y', strtotime($month_start));
$prev_start   = $prev_month . '-01';
$prev_end     = date('Y-m-t', strtotime($prev_start));

// ── Month range helpers for SQL ──────────────────────────────
$sql_cur_start  = $month_start;
$sql_cur_end    = $month_end;
$sql_prev_start = $prev_start;
$sql_prev_end   = $prev_end;

// ── Range filter (overrides month window when set) ────────────
$range = $_GET['range'] ?? 'month';
if (!in_array($range, ['7days','month','year'])) $range = 'month';
if ($range === '7days') {
    $sql_cur_start = date('Y-m-d', strtotime('-6 days'));
    $sql_cur_end   = date('Y-m-d');
    $range_label   = 'Last 7 Days';
} elseif ($range === '30days') {
    $sql_cur_start = date('Y-m-d', strtotime('-29 days'));
    $sql_cur_end   = date('Y-m-d');
    $range_label   = 'Last 30 Days';
} elseif ($range === 'year') {
    $sql_cur_start = date('Y-01-01');
    $sql_cur_end   = date('Y-m-d');
    $range_label   = 'Year to Date (' . date('Y') . ')';
} else {
    $range_label = $month_label;
}

// ── Today's Appointments ─────────────────────────────────────
$appts_today = (int)$conn->query("
    SELECT COUNT(*) as c FROM appointments
    WHERE appointment_date = CURRENT_DATE
")->fetch(PDO::FETCH_ASSOC)['c'];

// ── Pending Bills (unpaid / partial) ────────────────────────
$pending_bills = (float)$conn->query("
    SELECT COALESCE(SUM(amount_due - amount_paid), 0) as total
    FROM bills WHERE status IN ('unpaid','partial')
")->fetch(PDO::FETCH_ASSOC)['total'];

// ── Daily Appointments for selected range (peaks & valleys) ──
$daily_raw = $conn->query("
    SELECT appointment_date as day, COUNT(*) as total
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY appointment_date ORDER BY appointment_date ASC
")->fetchAll(PDO::FETCH_ASSOC);
$daily_map = [];
foreach ($daily_raw as $row) { $daily_map[$row['day']] = (int)$row['total']; }
$daily_labels = []; $daily_values = [];
$cursor = strtotime($sql_cur_start);
$end_ts = strtotime($sql_cur_end);
$span_days = ($end_ts - $cursor) / 86400;
while ($cursor <= $end_ts) {
    $d = date('Y-m-d', $cursor);
    $daily_labels[] = $span_days > 14 ? date('M j', $cursor) : date('D j', $cursor);
    $daily_values[] = $daily_map[$d] ?? 0;
    $cursor = strtotime('+1 day', $cursor);
}
$daily_labels_json = json_encode($daily_labels);
$daily_values_json = json_encode($daily_values);

// ============================================================
// KPI — Current Month
// ============================================================
$new_patients = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE DATE(created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
")->fetch(PDO::FETCH_ASSOC)['c'];

$returning = (int)$conn->query("
    SELECT COUNT(DISTINCT patient_id) as c
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    AND patient_id NOT IN (
        SELECT id FROM patients WHERE DATE(created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    )
")->fetch(PDO::FETCH_ASSOC)['c'];

$revenue = (float)$conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total
    FROM bills
    WHERE DATE(created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
")->fetch(PDO::FETCH_ASSOC)['total'];

$status_breakdown = $conn->query("
    SELECT status, COUNT(*) as total
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);

$status_map = [];
foreach ($status_breakdown as $row) { $status_map[$row['status']] = (int)$row['total']; }
$total_this_month = array_sum($status_map);
$completed_count  = $status_map['completed'] ?? 0;

$total_patients_this_month = $new_patients + $returning;

// ============================================================
// KPI — Previous Month (for trend arrows)
// ============================================================
$prev_new_patients = (int)$conn->query("
    SELECT COUNT(*) as c FROM patients
    WHERE DATE(created_at) BETWEEN '$sql_prev_start' AND '$sql_prev_end'
")->fetch(PDO::FETCH_ASSOC)['c'];

$prev_returning = (int)$conn->query("
    SELECT COUNT(DISTINCT patient_id) as c
    FROM appointments
    WHERE appointment_date BETWEEN '$sql_prev_start' AND '$sql_prev_end'
      AND patient_id NOT IN (
          SELECT id FROM patients
          WHERE DATE(created_at) BETWEEN '$sql_prev_start' AND '$sql_prev_end'
      )
")->fetch(PDO::FETCH_ASSOC)['c'];

$prev_revenue = (float)$conn->query("
    SELECT COALESCE(SUM(amount_paid), 0) as total FROM bills
    WHERE DATE(created_at) BETWEEN '$sql_prev_start' AND '$sql_prev_end'
")->fetch(PDO::FETCH_ASSOC)['total'];

$prev_status = $conn->query("
    SELECT status, COUNT(*) as total FROM appointments
    WHERE appointment_date BETWEEN '$sql_prev_start' AND '$sql_prev_end'
    GROUP BY status
")->fetchAll(PDO::FETCH_ASSOC);
$prev_map   = [];
foreach ($prev_status as $r) { $prev_map[$r['status']] = (int)$r['total']; }
$prev_total = array_sum($prev_map);

$prev_total_patients = $prev_new_patients + $prev_returning;

// Helper: trend badge
function trend_badge(float $now, float $prev, string $suffix = '%'): string {
    if ($prev == 0) {
        if ($now == 0) return '<span class="kpi-trend neutral">No data last month</span>';
        return '<span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i>New this month</span>';
    }
    $pct = round((($now - $prev) / $prev) * 100, 1);
    if ($pct > 0)     return '<span class="kpi-trend up"><i class="bi bi-arrow-up-short"></i>+'.abs($pct).'% vs last month</span>';
    elseif ($pct < 0) return '<span class="kpi-trend down"><i class="bi bi-arrow-down-short"></i>'.abs($pct).'% vs last month</span>';
    else              return '<span class="kpi-trend neutral">Same as last month</span>';
}

// ============================================================
// Revenue per Month (last 6 months) + Forecast
// ============================================================
$revenue_per_month = $conn->query("
    SELECT DATE_FORMAT(created_at, '%b %Y') as month,
           DATE_FORMAT(created_at, '%Y-%m') as sort_key,
           COALESCE(SUM(amount_paid), 0) as total
    FROM bills
    WHERE created_at >= NOW() - INTERVAL 6 MONTH
    GROUP BY sort_key, month ORDER BY sort_key ASC
")->fetchAll(PDO::FETCH_ASSOC);

$rev_totals = array_column($revenue_per_month, 'total');
$last3      = array_slice($rev_totals, -3);
if (count($last3) >= 2) {
    $slope    = ($last3[count($last3)-1] - $last3[0]) / max(count($last3) - 1, 1);
    $forecast = max(0, round(end($last3) + $slope));
} elseif (count($last3) === 1) {
    $forecast = (int)$last3[0];
} else {
    $forecast = 0;
}
$next_month_label = date('M Y', strtotime('first day of next month'));

// ============================================================
// Appointment Breakdown by Service (for donut)
// ============================================================
$appt_by_service = $conn->query("
    SELECT s.service_name, COUNT(a.id) as total
    FROM appointments a
    JOIN services s ON a.service_id = s.id
    WHERE a.appointment_date BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY s.id, s.service_name
    ORDER BY total DESC
    LIMIT 6
");
$appt_by_service = $appt_by_service ? $appt_by_service->fetchAll(PDO::FETCH_ASSOC) : [];

// ============================================================
// Top Revenue Generating Services
// ============================================================
$top_services = $conn->query("
    SELECT s.service_name, COALESCE(SUM(b.amount_paid), 0) as total_revenue, COUNT(b.id) as bill_count
    FROM bills b
    JOIN appointments a ON b.appointment_id = a.id
    JOIN services s ON a.service_id = s.id
    WHERE DATE(b.created_at) BETWEEN '$sql_cur_start' AND '$sql_cur_end'
    GROUP BY s.id, s.service_name
    ORDER BY total_revenue DESC
    LIMIT 5
");
$top_services = $top_services ? $top_services->fetchAll(PDO::FETCH_ASSOC) : [];

// ============================================================
// New vs Returning (last 6 months for bar chart)
// ============================================================
$patient_breakdown = $conn->query("
    SELECT
        DATE_FORMAT(a.appointment_date, '%b %Y') as month,
        DATE_FORMAT(a.appointment_date, '%Y-%m') as sort_key,
        SUM(CASE WHEN DATE_FORMAT(p.created_at,'%Y-%m') = DATE_FORMAT(a.appointment_date,'%Y-%m') THEN 1 ELSE 0 END) as new_count,
        SUM(CASE WHEN DATE_FORMAT(p.created_at,'%Y-%m') != DATE_FORMAT(a.appointment_date,'%Y-%m') THEN 1 ELSE 0 END) as returning_count
    FROM appointments a
    JOIN patients p ON a.patient_id = p.id
    WHERE a.appointment_date >= NOW() - INTERVAL 6 MONTH
    GROUP BY sort_key, month
    ORDER BY sort_key ASC
");
$patient_breakdown = $patient_breakdown ? $patient_breakdown->fetchAll(PDO::FETCH_ASSOC) : [];

// ============================================================
// Encode for JS
// ============================================================
$rev_labels     = json_encode(array_column($revenue_per_month, 'month'));
$rev_data       = json_encode(array_column($revenue_per_month, 'total'));
$status_labels  = json_encode(array_keys($status_map));
$status_data    = json_encode(array_values($status_map));
$svc_labels     = json_encode(array_column($appt_by_service, 'service_name'));
$svc_data       = json_encode(array_column($appt_by_service, 'total'));
$pb_labels      = json_encode(array_column($patient_breakdown, 'month'));
$pb_new         = json_encode(array_column($patient_breakdown, 'new_count'));
$pb_returning   = json_encode(array_column($patient_breakdown, 'returning_count'));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Analytics Dashboard Overrides ───────────────────────── */
.an-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 18px;
    margin-bottom: 24px;
}
@media (max-width: 992px) { .an-kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media (max-width: 576px)  { .an-kpi-grid { grid-template-columns: 1fr; } }

.an-kpi-card {
    background: #fff;
    border: 1px solid var(--gray-100);
    border-radius: 16px;
    padding: 22px 24px;
    display: flex;
    align-items: flex-start;
    gap: 16px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
    transition: box-shadow 0.2s, transform 0.2s;
}
.an-kpi-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.09); transform: translateY(-1px); }
[data-theme="dark"] .an-kpi-card { background: var(--gray-100); border-color: var(--gray-200); }

.an-kpi-icon {
    width: 48px; height: 48px; border-radius: 14px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.3rem; flex-shrink: 0;
}
.an-kpi-icon.blue   { background: rgba(37,99,235,0.1);  color: #2563eb; }
.an-kpi-icon.green  { background: rgba(22,163,74,0.1);  color: #16a34a; }
.an-kpi-icon.teal   { background: rgba(20,184,166,0.1); color: #0d9488; }
.an-kpi-icon.indigo { background: rgba(99,102,241,0.1); color: #6366f1; }

.an-kpi-label  { font-size: 0.72rem; color: var(--gray-500); font-weight: 500; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px; }
.an-kpi-value  { font-size: 1.9rem; font-weight: 800; line-height: 1.1; color: var(--gray-900); margin-bottom: 5px; }
[data-theme="dark"] .an-kpi-value { color: var(--gray-900); }
.an-kpi-value.sm { font-size: 1.5rem; }

.kpi-trend      { display: inline-flex; align-items: center; gap: 1px; font-size: 0.74rem; font-weight: 600; border-radius: 20px; padding: 2px 7px; }
.kpi-trend.up   { color: #16a34a; background: rgba(22,163,74,0.09); }
.kpi-trend.down { color: #dc2626; background: rgba(220,38,38,0.09); }
.kpi-trend.neutral { color: var(--gray-500); background: var(--gray-100); font-weight: 400; }

/* Chart row grid */
.an-chart-row { display: grid; gap: 20px; margin-bottom: 20px; }
.an-chart-row.cols-8-4 { grid-template-columns: 8fr 4fr; }
.an-chart-row.cols-6-3-3 { grid-template-columns: 6fr 3fr 3fr; }
@media (max-width: 992px) {
    .an-chart-row.cols-8-4,
    .an-chart-row.cols-6-3-3 { grid-template-columns: 1fr; }
}

.an-card {
    background: #fff;
    border: 1px solid var(--gray-100);
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}
[data-theme="dark"] .an-card { background: var(--gray-100); border-color: var(--gray-200); }

.an-card-head {
    padding: 16px 22px;
    border-bottom: 1px solid var(--gray-100);
    display: flex; align-items: center; justify-content: space-between;
}
[data-theme="dark"] .an-card-head { border-bottom-color: var(--gray-200); }
.an-card-head-title { font-size: 0.82rem; font-weight: 700; color: var(--gray-700); display: flex; align-items: center; gap: 6px; }
[data-theme="dark"] .an-card-head-title { color: var(--gray-700); }
.an-card-head-sub   { font-size: 0.73rem; color: var(--gray-400); }
.an-card-body { padding: 22px; }

/* Top services list */
.top-svc-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid var(--gray-100);
}
[data-theme="dark"] .top-svc-item { border-bottom-color: var(--gray-700); }
.top-svc-item:last-child { border-bottom: none; }
.top-svc-rank {
    width: 26px; height: 26px; border-radius: 8px; font-size: 0.72rem;
    font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.rank-1 { background: rgba(37,99,235,0.12);  color: #2563eb; }
.rank-2 { background: rgba(22,163,74,0.12);  color: #16a34a; }
.rank-3 { background: rgba(245,158,11,0.12); color: #d97706; }
.rank-4 { background: rgba(99,102,241,0.12); color: #6366f1; }
.rank-5 { background: var(--gray-100); color: var(--gray-500); }

/* Month label pill */
.month-pill {
    display: inline-flex; align-items: center; gap: 6px;
    background: rgba(37,99,235,0.07); border: 1px solid rgba(37,99,235,0.15);
    border-radius: 20px; padding: 4px 12px;
    font-size: 0.75rem; font-weight: 600; color: #2563eb;
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom: 22px;">
            <div>
                <h5>Analytics Dashboard</h5>
                <p style="color:var(--gray-500);font-size:0.85rem;margin:0;">
                    <i class="bi bi-calendar3" style="margin-right:5px;"></i>
                    <?php echo $range_label; ?>
                </p>
            </div>
            <!-- Range filter tabs -->
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                <?php
                $tabs = [
                    '7days' => 'Last 7 Days',
                    'month' => 'This Month',
                    'year'  => 'This Year',
                ];
                foreach ($tabs as $key => $label):
                    $active = ($range === $key) || ($range === '30days' && $key === 'month');
                    $href = '?range=' . $key . ($key === 'month' ? '&month=' . $selected_month : '');
                    $style = $active
                        ? 'padding:6px 14px;border-radius:20px;font-size:0.78rem;font-weight:700;background:#2563eb;color:#fff;border:none;cursor:pointer;text-decoration:none;'
                        : 'padding:6px 14px;border-radius:20px;font-size:0.78rem;font-weight:500;background:var(--gray-100);color:var(--gray-600);border:none;cursor:pointer;text-decoration:none;transition:background 0.15s;';
                ?>
                <a href="<?php echo $href; ?>" style="<?php echo $style; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
                <?php if ($range === 'month' || $range === '30days' || $range === 'year'): ?>
                <span style="width:1px;height:20px;background:var(--gray-200);margin:0 4px;"></span>
                <?php
                    if ($range === 'year') {
                        $cur_year   = (int)date('Y');
                        $prev_year  = $cur_year - 1;
                        $next_year  = $cur_year + 1;
                        $year_future = $next_year > $cur_year;
                        $prev_href = '?range=year&yr=' . $prev_year;
                        $next_href = '?range=year&yr=' . $next_year;
                        $prev_title = 'Previous year';
                        $next_title = 'Next year';
                    } else {
                        $prev_href  = '?range=month&month=' . $prev_month;
                        $next_href  = '?range=month&month=' . $next_month;
                        $prev_title = 'Previous month';
                        $next_title = 'Next month';
                        $year_future = false;
                    }
                ?>
                <a href="<?php echo $prev_href; ?>" class="btn btn-sm btn-outline-secondary" title="<?php echo $prev_title; ?>" style="padding:4px 8px;">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <?php if (!$is_future && !$year_future): ?>
                <a href="<?php echo $next_href; ?>" class="btn btn-sm btn-outline-secondary" title="<?php echo $next_title; ?>" style="padding:4px 8px;">
                    <i class="bi bi-chevron-right"></i>
                </a>
                <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary" disabled style="padding:4px 8px;">
                    <i class="bi bi-chevron-right"></i>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── ROW 1: KPI Cards ────────────────────────────────── -->
        <div class="an-kpi-grid">

            <!-- Total Revenue -->
            <div class="an-kpi-card">
                <div class="an-kpi-icon green"><i class="bi bi-cash-coin"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">Total Revenue</div>
                    <div class="an-kpi-value sm">₱<?php echo number_format($revenue, 0); ?></div>
                    <?php echo trend_badge($revenue, $prev_revenue, '%'); ?>
                </div>
            </div>

            <!-- New Patients -->
            <div class="an-kpi-card">
                <div class="an-kpi-icon blue"><i class="bi bi-person-plus-fill"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">New Patients</div>
                    <div class="an-kpi-value"><?php echo $new_patients; ?></div>
                    <?php echo trend_badge($new_patients, $prev_new_patients); ?>
                </div>
            </div>

            <!-- Appointments Today -->
            <div class="an-kpi-card">
                <div class="an-kpi-icon teal"><i class="bi bi-calendar-check-fill"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">Appointments Today</div>
                    <div class="an-kpi-value"><?php echo $appts_today; ?></div>
                    <span class="kpi-trend neutral"><?php echo date('l, M j'); ?></span>
                </div>
            </div>

            <!-- Pending Bills -->
            <div class="an-kpi-card">
                <div class="an-kpi-icon indigo"><i class="bi bi-receipt-cutoff"></i></div>
                <div style="flex:1;min-width:0;">
                    <div class="an-kpi-label">Pending Bills</div>
                    <div class="an-kpi-value sm">₱<?php echo number_format($pending_bills, 0); ?></div>
                    <span class="kpi-trend <?php echo $pending_bills > 0 ? 'down' : 'up'; ?>">
                        <?php echo $pending_bills > 0 ? 'Needs collection' : 'All settled'; ?>
                    </span>
                </div>
            </div>

        </div><!-- /kpi grid -->

        <!-- ── DAILY ACTIVITY: Appointments per Day ───────────── -->
        <div class="an-chart-row" style="grid-template-columns:1fr;margin-bottom:20px;">
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-activity" style="color:#2563eb;"></i>
                        Daily Appointment Activity
                    </div>
                    <span class="an-card-head-sub"><?php echo $range_label; ?> — peaks &amp; busy days</span>
                </div>
                <div class="an-card-body">
                    <div style="position:relative;height:130px;">
                        <canvas id="dailyApptChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="an-chart-row cols-8-4">

            <!-- Revenue Trend & Projection -->
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-graph-up" style="color:#16a34a;"></i>
                        Revenue Trend & Projection
                    </div>
                    <div style="display:flex;align-items:center;gap:16px;">
                        <?php if ($forecast > 0): ?>
                        <span style="font-size:0.75rem;color:var(--gray-500);">
                            Forecasted <strong style="color:#16a34a;">₱<?php echo number_format($forecast); ?></strong>
                            <span style="color:var(--gray-400);"> (<?php echo $next_month_label; ?>)</span>
                        </span>
                        <?php endif; ?>
                        <span class="an-card-head-sub">Last 6 months</span>
                    </div>
                </div>
                <div class="an-card-body">
                    <div style="position:relative;height:320px;">
                        <canvas id="revenueChart"></canvas>
                    </div>
                    <div style="display:flex;align-items:center;gap:18px;margin-top:14px;font-size:0.76rem;color:var(--gray-500);">
                        <span><span style="display:inline-block;width:24px;height:3px;background:#0d9488;border-radius:2px;margin-right:5px;vertical-align:middle;"></span>Actual revenue</span>
                        <?php if ($forecast > 0): ?>
                        <span><span style="display:inline-block;width:20px;height:0;border-top:2px dashed #16a34a;margin-right:5px;vertical-align:middle;display:inline-block;"></span>Forecasted</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Appointment Breakdown donut -->
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-pie-chart-fill" style="color:#2563eb;"></i>
                        Appointment Breakdown
                    </div>
                    <span class="an-card-head-sub">By service</span>
                </div>
                <div class="an-card-body" style="display:flex;flex-direction:column;align-items:center;padding:20px 16px;">
                    <div style="position:relative;width:190px;height:190px;">
                        <canvas id="apptBreakdownChart"></canvas>
                    </div>
                    <div id="donutLegend" style="margin-top:14px;width:100%;font-size:0.73rem;"></div>
                </div>
            </div>

        </div><!-- /row 2 -->

        <!-- ── ROW 3: Top Services (left) + Patient Growth (right) -->
        <div class="an-chart-row" style="grid-template-columns:1fr 1fr;">

            <!-- Top Revenue Generating Services -->
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-trophy-fill" style="color:#d97706;"></i>
                        Top Revenue Generating Services
                    </div>
                    <span class="an-card-head-sub">This month</span>
                </div>
                <div class="an-card-body" style="padding:8px 22px 16px;">
                    <?php if (empty($top_services)): ?>
                    <div style="text-align:center;padding:32px;color:var(--gray-400);font-size:0.85rem;">
                        No billing data this month
                    </div>
                    <?php else: ?>
                    <?php
                    $rank_cls = ['rank-1','rank-2','rank-3','rank-4','rank-5'];
                    $svc_icons = ['🦷','🦷','🦷','🦷','🦷'];
                    $max_svc_rev = max(array_column($top_services, 'total_revenue')) ?: 1;
                    foreach ($top_services as $i => $svc):
                        $bar_pct = round(($svc['total_revenue'] / $max_svc_rev) * 100);
                    ?>
                    <div class="top-svc-item">
                        <div class="top-svc-rank <?php echo $rank_cls[$i] ?? 'rank-5'; ?>"><?php echo $i+1; ?></div>
                        <div style="flex:1;min-width:0;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px;">
                                <span style="font-size:0.82rem;font-weight:600;color:var(--gray-700);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px;"><?php echo e($svc['service_name']); ?></span>
                                <span style="font-size:0.82rem;font-weight:700;color:var(--gray-800);margin-left:8px;white-space:nowrap;">₱<?php echo number_format($svc['total_revenue'], 0); ?></span>
                            </div>
                            <div style="height:5px;background:var(--gray-100);border-radius:4px;overflow:hidden;">
                                <div style="height:100%;width:<?php echo $bar_pct; ?>%;background:<?php echo ['#2563eb','#16a34a','#f59e0b','#6366f1','#94a3b8'][$i] ?? '#94a3b8'; ?>;border-radius:4px;transition:width 0.6s ease;"></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Patient Growth by Type -->
            <div class="an-card">
                <div class="an-card-head">
                    <div class="an-card-head-title">
                        <i class="bi bi-bar-chart-fill" style="color:#2563eb;"></i>
                        Patient Growth
                    </div>
                    <span class="an-card-head-sub">Last 6 months</span>
                </div>
                <div class="an-card-body">
                    <div style="position:relative;height:200px;">
                        <canvas id="patientGrowthChart"></canvas>
                    </div>
                    <div style="display:flex;gap:14px;margin-top:12px;font-size:0.73rem;color:var(--gray-500);justify-content:center;">
                        <span><span style="display:inline-block;width:10px;height:10px;background:#2563eb;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>New</span>
                        <span><span style="display:inline-block;width:10px;height:10px;background:#16a34a;border-radius:2px;margin-right:4px;vertical-align:middle;"></span>Returning</span>
                    </div>
                </div>
            </div>

        </div><!-- /row 3 -->

    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// Destroy any existing Chart instances on this canvas (PJAX re-navigation fix)
function safeChart(id, config) {
    var el = document.getElementById(id);
    if (!el) return null;
    var existing = Chart.getChart(el);
    if (existing) existing.destroy();
    return new Chart(el, config);
}

var isDark     = document.documentElement.getAttribute('data-bs-theme') === 'dark'
              || document.documentElement.getAttribute('data-theme') === 'dark';
var gridColor  = isDark ? 'rgba(255,255,255,0.07)' : 'rgba(0,0,0,0.05)';
var tickColor  = isDark ? '#8a9bb0' : '#64748b';
var legendColor= isDark ? '#b0bec5' : '#475569';

var scaleBase = {
    grid:  { color: gridColor },
    ticks: { color: tickColor, font: { size: 11 } }
};

function noDataPlugin(msg) {
    return {
        id: 'noData',
        afterDraw(chart) {
            var empty = !chart.data.datasets[0].data.length
                     || chart.data.datasets[0].data.every(v => v == 0);
            if (!empty) return;
            var ctx = chart.ctx, w = chart.width, h = chart.height;
            chart.clear();
            ctx.save();
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillStyle = tickColor;
            ctx.font = '13px DM Sans, system-ui, sans-serif';
            ctx.fillText(msg, w / 2, h / 2);
            ctx.restore();
        }
    };
}

// ── 1. Revenue Chart — Actual vs Forecast line chart ────────
(function() {
    var revLabels = <?php echo $rev_labels ?: '[]'; ?>;
    var revData   = <?php echo $rev_data   ?: '[]'; ?>.map(Number);
    var forecast  = <?php echo $forecast; ?>;
    var nextLabel = <?php echo json_encode($next_month_label); ?>;

    // Build label + dataset arrays
    var allLabels    = [...revLabels];
    var actualData   = [...revData];
    var forecastData = new Array(revData.length).fill(null);

    // Connect forecast from the last actual point
    if (forecast > 0 && revData.length > 0) {
        forecastData[forecastData.length - 1] = revData[revData.length - 1];
        allLabels.push(nextLabel);
        actualData.push(null);
        forecastData.push(forecast);
    }

    function formatPeso(v) {
        if (v === null || v === undefined) return '';
        if (v >= 1000000) return '₱'+(v/1000000).toFixed(1)+'M';
        if (v >= 1000)    return '₱'+(v/1000).toFixed(0)+'K';
        return '₱'+v;
    }

    safeChart('revenueChart', {
        type: 'line',
        plugins: [
            noDataPlugin('No revenue recorded yet'),
            // Inline data-labels plugin
            {
                id: 'revLabels',
                afterDatasetsDraw(chart) {
                    var ctx = chart.ctx;
                    chart.data.datasets.forEach((ds, di) => {
                        var meta = chart.getDatasetMeta(di);
                        if (meta.hidden) return;
                        meta.data.forEach((point, i) => {
                            var val = ds.data[i];
                            if (val === null || val === undefined) return;
                            var label = formatPeso(val);
                            ctx.save();
                            ctx.font = 'bold 10px DM Sans, system-ui, sans-serif';
                            ctx.fillStyle = di === 0 ? '#0d9488' : '#f59e0b';
                            ctx.textAlign = 'center';
                            ctx.textBaseline = 'bottom';
                            ctx.fillText(label, point.x, point.y - 8);
                            ctx.restore();
                        });
                    });
                }
            }
        ],
        data: {
            labels: allLabels,
            datasets: [
                {
                    label: 'Actual',
                    data: actualData,
                    borderColor: '#0d9488',
                    borderWidth: 2.5,
                    backgroundColor: 'rgba(13,148,136,0.08)',
                    fill: true,
                    tension: 0.35,
                    pointRadius: 5,
                    pointBackgroundColor: '#0d9488',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointHoverRadius: 7,
                    order: 1
                },
                {
                    label: 'Forecast',
                    data: forecastData,
                    borderColor: '#16a34a',
                    borderWidth: 2,
                    borderDash: [7, 4],
                    backgroundColor: 'transparent',
                    fill: false,
                    tension: 0.35,
                    pointRadius: function(ctx) {
                        // Only show dot at the forecast end point
                        var v = forecastData[ctx.dataIndex];
                        return (v !== null && ctx.dataIndex === forecastData.length - 1) ? 6 : 0;
                    },
                    pointBackgroundColor: '#16a34a',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    order: 2
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            layout: {
                padding: { top: 30, bottom: 10, left: 10, right: 10 }
            },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            if (ctx.parsed.y === null) return null;
                            return ' ' + ctx.dataset.label + ': ₱' +
                                Number(ctx.parsed.y).toLocaleString('en-PH');
                        }
                    }
                }
            },
            scales: {
                x: {
                    ...scaleBase,
                    grid: { display: false },
                    ticks: { ...scaleBase.ticks, maxRotation: 0 }
                },
                y: {
                    ...scaleBase,
                    beginAtZero: true,
                    ticks: {
                        ...scaleBase.ticks,
                        callback: v => {
                            if (v >= 1000000) return '₱'+(v/1000000).toFixed(1)+'M';
                            if (v >= 1000)    return '₱'+(v/1000).toFixed(0)+'K';
                            return '₱'+v;
                        }
                    }
                }
            }
        }
    });
})();

// ── 2. Appointment Breakdown Donut ───────────────────────────
(function() {
    var svcLabels = <?php echo $svc_labels ?: '[]'; ?>;
    var svcData   = <?php echo $svc_data   ?: '[]'; ?>;
    var palette   = ['#2563eb','#16a34a','#f59e0b','#0d9488','#6366f1','#dc2626'];

    safeChart('apptBreakdownChart', {
        type: 'doughnut',
        plugins: [noDataPlugin('No service data this month')],
        data: {
            labels: svcLabels,
            datasets: [{
                data: svcData,
                backgroundColor: palette.slice(0, svcLabels.length),
                borderWidth: 3,
                borderColor: isDark ? '#1e2535' : '#ffffff',
                hoverOffset: 8
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.label + ': ' + ctx.parsed + ' appt' + (ctx.parsed !== 1 ? 's' : '')
                    }
                }
            }
        }
    });

    // Custom legend — parse to numbers first (PHP json_encode gives strings for COUNT())
    var legend  = document.getElementById('donutLegend');
    var svcNums = svcData.map(Number);
    var total   = svcNums.reduce((a,b) => a+b, 0) || 1;
    legend.innerHTML = svcLabels.map((l,i) => {
        var pct = Math.round((svcNums[i] / total) * 100);
        return '<div style="display:flex;align-items:center;justify-content:space-between;padding:4px 0;">'
             + '<span style="display:flex;align-items:center;gap:6px;">'
             + '<span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:'+palette[i]+';flex-shrink:0;"></span>'
             + '<span style="font-size:0.74rem;color:'+(isDark?'#b0bec5':'#475569')+';">'+l+'</span>'
             + '</span>'
             + '<span style="font-size:0.74rem;font-weight:600;color:'+(isDark?'#e2e8f0':'#1e293b')+';">'+pct+'%</span>'
             + '</div>';
    }).join('');
})();

// ── 4. Daily Appointment Activity ────────────────────────────
(function() {
    var dLabels = <?php echo $daily_labels_json ?: '[]'; ?>;
    var dValues = <?php echo $daily_values_json ?: '[]'; ?>.map(Number);
    var maxVal  = Math.max(...dValues, 1);

    safeChart('dailyApptChart', {
        type: 'bar',
        plugins: [noDataPlugin('No appointments in this period')],
        data: {
            labels: dLabels,
            datasets: [{
                label: 'Appointments',
                data: dValues,
                backgroundColor: dValues.map(v => {
                    if (v === 0)        return 'rgba(203,213,225,0.4)';
                    if (v >= maxVal)    return 'rgba(37,99,235,0.85)';  // busiest day
                    if (v >= maxVal * 0.7) return 'rgba(37,99,235,0.6)';
                    return 'rgba(37,99,235,0.35)';
                }),
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => ' ' + ctx.parsed.y + ' appointment' + (ctx.parsed.y !== 1 ? 's' : '')
                    }
                }
            },
            scales: {
                x: {
                    ...scaleBase,
                    grid: { display: false },
                    ticks: {
                        ...scaleBase.ticks,
                        maxTicksLimit: 20,
                        maxRotation: 45
                    }
                },
                y: {
                    ...scaleBase,
                    beginAtZero: true,
                    ticks: {
                        ...scaleBase.ticks,
                        stepSize: 1,
                        precision: 0
                    }
                }
            }
        }
    });
})();

(function() {
    var pbLabels    = <?php echo $pb_labels    ?: '[]'; ?>;
    var pbNew       = <?php echo $pb_new       ?: '[]'; ?>;
    var pbReturning = <?php echo $pb_returning ?: '[]'; ?>;

    safeChart('patientGrowthChart', {
        type: 'bar',
        plugins: [noDataPlugin('No data yet')],
        data: {
            labels: pbLabels,
            datasets: [
                {
                    label: 'New',
                    data: pbNew,
                    backgroundColor: 'rgba(37,99,235,0.80)',
                    borderRadius: 0, borderSkipped: false, stack: 'p'
                },
                {
                    label: 'Returning',
                    data: pbReturning,
                    backgroundColor: 'rgba(22,163,74,0.80)',
                    borderRadius: 5, borderSkipped: 'bottom', stack: 'p'
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        afterBody: items => {
                            var i = items[0].dataIndex;
                            return 'Total: ' + ((pbNew[i]||0) + (pbReturning[i]||0));
                        }
                    }
                }
            },
            scales: {
                x: { ...scaleBase, grid: { display: false }, stacked: true,
                     ticks: { ...scaleBase.ticks, font: { size: 10 } } },
                y: { ...scaleBase, stacked: true, beginAtZero: true,
                     ticks: { ...scaleBase.ticks, stepSize: 1 } }
            }
        }
    });
})();
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>