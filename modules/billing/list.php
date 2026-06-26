<?php
// List all bills with filters and summary totals.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Billing';

$status_filter = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to'] ?? '';

$allowed_statuses = ['unpaid', 'partial', 'paid'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = '';
if ($date_from && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) $date_from = '';
if ($date_to   && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to))   $date_to   = '';

// Build WHERE using prepared-statement parameters to prevent SQL injection.
$where       = "WHERE 1=1";
$params      = [];
$param_types = '';

if ($status_filter) {
    $where        .= " AND b.status = ?";
    $params[]      = $status_filter;   // already whitelisted above
    $param_types  .= 's';
}
if ($search) {
    $like          = '%' . $search . '%';
    $where        .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR b.bill_code LIKE ? OR p.patient_code LIKE ?)";
    $params[]      = $like;
    $params[]      = $like;
    $params[]      = $like;
    $params[]      = $like;
    $param_types  .= 'ssss';
}
if ($date_from) {
    $where        .= " AND DATE(b.created_at) >= ?";
    $params[]      = $date_from;   // already regex-validated above
    $param_types  .= 's';
}
if ($date_to) {
    $where        .= " AND DATE(b.created_at) <= ?";
    $params[]      = $date_to;     // already regex-validated above
    $param_types  .= 's';
}

$per_page    = 20;
$page        = max(1, intval($_GET['page'] ?? 1));

// COUNT query
$count_sql  = "SELECT COUNT(*) as c FROM bills b LEFT JOIN patients p ON b.patient_id = p.id $where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params ?: []);
$total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['c'];
$count_stmt->closeCursor();

$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status='    . urlencode($status_filter);
if ($search)        $filter_parts[] = 'search='    . urlencode($search);
if ($date_from)     $filter_parts[] = 'date_from=' . urlencode($date_from);
if ($date_to)       $filter_parts[] = 'date_to='   . urlencode($date_to);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

// Main list query
$list_sql  = "
    SELECT b.*,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone,
           s.service_name,
           a.appointment_code
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN appointments a ON b.appointment_id = a.id
    $where
    ORDER BY b.created_at DESC
    LIMIT $per_page OFFSET $offset
";
$list_stmt = $conn->prepare($list_sql);
$list_stmt->execute($params ?: []);
$bills = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
$list_stmt->closeCursor();

$totals = $conn->query("
    SELECT
        COUNT(*) as total_bills,
        COALESCE(SUM(amount_due),0) as total_due,
        COALESCE(SUM(amount_paid),0) as total_paid,
        COALESCE(SUM(amount_due) - SUM(amount_paid),0) as total_outstanding,
        COUNT(CASE WHEN status='unpaid' THEN 1 END) as unpaid_count,
        COUNT(CASE WHEN status='partial' THEN 1 END) as partial_count,
        COUNT(CASE WHEN status='paid' THEN 1 END) as paid_count
    FROM bills
")->fetch(PDO::FETCH_ASSOC);

$collection_rate = $totals['total_due'] > 0
    ? round(($totals['total_paid'] / $totals['total_due']) * 100)
    : 0;
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Billing KPI Cards ─────────────────────────────────── */
.bill-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.bill-kpi {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: 20px 22px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    box-shadow: var(--shadow-sm);
    transition: box-shadow 0.2s, transform 0.2s;
    position: relative;
    overflow: hidden;
}
.bill-kpi::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    border-radius: var(--radius-lg) var(--radius-lg) 0 0;
}
.bill-kpi.blue::before   { background: linear-gradient(90deg, var(--blue-500), var(--blue-400)); }
.bill-kpi.green::before  { background: linear-gradient(90deg, var(--success), var(--success-light)); }
.bill-kpi.red::before    { background: linear-gradient(90deg, var(--danger), #f87171); }
.bill-kpi.amber::before  { background: linear-gradient(90deg, #d97706, #f59e0b); }
.bill-kpi:hover { box-shadow: var(--shadow-md); transform: translateY(-1px); }
.bill-kpi-label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--gray-400);
}
.bill-kpi-value {
    font-family: 'Sora', sans-serif;
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--gray-900);
    line-height: 1;
}
.bill-kpi-sub {
    font-size: 0.75rem;
    color: var(--gray-400);
    margin-top: 2px;
}
.bill-kpi-icon {
    position: absolute;
    top: 16px; right: 18px;
    font-size: 1.6rem;
    opacity: 0.12;
}

/* ── Progress bar ─────────────────────────────────────── */
.collection-bar {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    padding: 18px 22px;
    margin-bottom: 24px;
    box-shadow: var(--shadow-sm);
    display: flex;
    align-items: center;
    gap: 20px;
}
.collection-bar-track {
    flex: 1;
    height: 8px;
    background: var(--gray-100);
    border-radius: 99px;
    overflow: hidden;
}
.collection-bar-fill {
    height: 100%;
    border-radius: 99px;
    background: linear-gradient(90deg, var(--blue-500), var(--success));
    transition: width 0.8s var(--ease);
}

/* ── Filter bar ───────────────────────────────────────── */
.billing-filter-bar {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-md);
    padding: 14px 16px;
    margin-bottom: 16px;
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    box-shadow: var(--shadow-xs);
}

/* ── Status pills ─────────────────────────────────────── */
.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 3px 10px;
    border-radius: 99px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.03em;
}
.status-pill.paid    { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.status-pill.unpaid  { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger-border); }
.status-pill.partial { background: #fffbeb; color: #d97706; border: 1px solid #fde68a; }

/* ── Bill row cards ───────────────────────────────────── */
.bill-table-wrap {
    background: var(--white);
    border: 1px solid var(--gray-200);
    border-radius: var(--radius-lg);
    overflow: hidden;
    box-shadow: var(--shadow-sm);
}
.bill-table-wrap table { margin: 0; }
.bill-table-wrap thead tr {
    background: var(--gray-50);
    border-bottom: 2px solid var(--gray-200);
}
.bill-table-wrap thead th {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.07em;
    color: var(--gray-400);
    padding: 12px 16px;
    border: none;
}
.bill-table-wrap tbody tr {
    border-bottom: 1px solid var(--gray-100);
    transition: background 0.12s;
}
.bill-table-wrap tbody tr:last-child { border-bottom: none; }
.bill-table-wrap tbody tr:hover { background: var(--blue-50); }
.bill-table-wrap tbody td {
    padding: 14px 16px;
    vertical-align: middle;
    border: none;
    font-size: 0.85rem;
}

/* ── Method badge ─────────────────────────────────────── */
.method-badge {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 0.72rem;
    font-weight: 600;
    background: var(--gray-100);
    color: var(--gray-600);
    border: 1px solid var(--gray-200);
}
.method-badge.gcash { background: #f0fdf4; color: #16a34a; border-color: #bbf7d0; }
.method-badge.bank  { background: #eff6ff; color: var(--blue-600); border-color: var(--blue-200); }

/* ── Dark mode ────────────────────────────────────────── */
[data-theme="dark"] .bill-kpi,
[data-theme="dark"] .collection-bar,
[data-theme="dark"] .billing-filter-bar,
[data-theme="dark"] .bill-table-wrap {
    background: var(--gray-200);
    border-color: var(--gray-300);
}
[data-theme="dark"] .bill-table-wrap thead tr { background: var(--gray-100); border-color: var(--gray-300); }
[data-theme="dark"] .bill-table-wrap thead th { color: var(--gray-700); }
[data-theme="dark"] .bill-table-wrap tbody tr { border-color: var(--gray-300); }
[data-theme="dark"] .bill-table-wrap tbody tr:hover { background: rgba(77,134,240,0.08); }
[data-theme="dark"] .bill-kpi-label { color: var(--gray-600); }
[data-theme="dark"] .bill-kpi-value { color: var(--gray-900); }
[data-theme="dark"] .bill-kpi-sub { color: var(--gray-500); }
[data-theme="dark"] .collection-bar-track { background: var(--gray-300); }
[data-theme="dark"] .method-badge { background: var(--gray-300); border-color: var(--gray-400); color: var(--gray-700); }

@media (max-width: 900px) {
    .bill-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h5>Billing</h5>
                <p>Manage patient payments — Cash, GCash, Bank Transfer</p>
            </div>
            <a href="create.php" class="btn btn-primary btn-sm">
                <i class="bi bi-plus"></i> Create Bill
            </a>
        </div>

        <!-- KPI Cards -->
        <div class="bill-kpi-grid">
            <div class="bill-kpi blue">
                <i class="bi bi-receipt bill-kpi-icon"></i>
                <div class="bill-kpi-label">Total Bills</div>
                <div class="bill-kpi-value"><?php echo number_format($totals['total_bills']); ?></div>
                <div class="bill-kpi-sub">All time</div>
            </div>
            <div class="bill-kpi green">
                <i class="bi bi-cash-coin bill-kpi-icon"></i>
                <div class="bill-kpi-label">Total Collected</div>
                <div class="bill-kpi-value" style="font-size:1.4rem;">₱<?php echo number_format($totals['total_paid'], 0); ?></div>
                <div class="bill-kpi-sub">of ₱<?php echo number_format($totals['total_due'], 0); ?> billed</div>
            </div>
            <div class="bill-kpi red">
                <i class="bi bi-exclamation-circle bill-kpi-icon"></i>
                <div class="bill-kpi-label">Outstanding</div>
                <div class="bill-kpi-value" style="font-size:1.4rem;">₱<?php echo number_format($totals['total_outstanding'], 0); ?></div>
                <div class="bill-kpi-sub"><?php echo $totals['unpaid_count']; ?> unpaid · <?php echo $totals['partial_count']; ?> partial</div>
            </div>
            <div class="bill-kpi amber">
                <i class="bi bi-check-circle bill-kpi-icon"></i>
                <div class="bill-kpi-label">Fully Paid</div>
                <div class="bill-kpi-value"><?php echo $totals['paid_count']; ?></div>
                <div class="bill-kpi-sub">Bills settled</div>
            </div>
        </div>

        <!-- Collection Rate Bar -->
        <div class="collection-bar">
            <div style="min-width:140px;">
                <div style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);">Collection Rate</div>
                <div style="font-family:'Sora',sans-serif;font-size:1.5rem;font-weight:700;color:var(--gray-900);line-height:1.2;"><?php echo $collection_rate; ?>%</div>
            </div>
            <div class="collection-bar-track">
                <div class="collection-bar-fill" style="width:<?php echo $collection_rate; ?>%;"></div>
            </div>
            <div style="font-size:0.78rem;color:var(--gray-400);min-width:120px;text-align:right;">
                ₱<?php echo number_format($totals['total_paid'],0); ?> / ₱<?php echo number_format($totals['total_due'],0); ?>
            </div>
        </div>

        <!-- Filter Bar -->
        <form method="GET" class="billing-filter-bar">
            <input type="text" name="search" class="form-control form-control-sm" style="max-width:220px;"
                placeholder="🔍  Patient or bill code..."
                value="<?php echo e($search); ?>">
            <select name="status" class="form-select form-select-sm" style="max-width:140px;">
                <option value="">All Status</option>
                <option value="unpaid"  <?php echo $status_filter === 'unpaid'  ? 'selected' : ''; ?>>Unpaid</option>
                <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                <option value="paid"    <?php echo $status_filter === 'paid'    ? 'selected' : ''; ?>>Paid</option>
            </select>
            <div style="display:flex;flex-direction:column;gap:1px;">
                <label style="font-size:0.68rem;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.04em;line-height:1;margin-bottom:2px;">From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" style="max-width:150px;"
                    value="<?php echo e($date_from); ?>">
            </div>
            <div style="display:flex;flex-direction:column;gap:1px;">
                <label style="font-size:0.68rem;font-weight:600;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.04em;line-height:1;margin-bottom:2px;">To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" style="max-width:150px;"
                    value="<?php echo e($date_to); ?>">
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Filter</button>
            <a href="list.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </form>

        <!-- Bills Table -->
        <div class="bill-table-wrap">
            <div class="mobile-card-table-wrap">
<table class="table mb-0 mobile-card-table">
                <thead>
                    <tr>
                        <th>Bill</th>
                        <th>Patient</th>
                        <th>Service</th>
                        <th>Amount Due</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Method</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bills)): ?>
                    <tr>
                        <td colspan="10" style="text-align:center;padding:60px;color:var(--gray-400);">
                            <i class="bi bi-receipt" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:0.4;"></i>
                            <div style="font-weight:600;margin-bottom:4px;">No bills found</div>
                            <div style="font-size:0.78rem;">Try adjusting your filters</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($bills as $b):
                        $balance = $b['amount_due'] - $b['amount_paid'];
                        $method_map = [
                            'cash'  => ['label'=>'💵 Cash',  'class'=>''],
                            'gcash' => ['label'=>'📱 GCash', 'class'=>'gcash'],
                            'bank'  => ['label'=>'🏦 Bank',  'class'=>'bank'],
                            'other' => ['label'=>'💳 Other', 'class'=>''],
                        ];
                        $m = $method_map[$b['payment_method']] ?? ['label'=>ucfirst($b['payment_method']),'class'=>''];
                    ?>
                    <tr>
                        <td data-label="Bill">
                            <div style="font-weight:700;color:var(--blue-500);font-size:0.78rem;font-family:'Sora',sans-serif;">
                                <?php echo e($b['bill_code']); ?>
                            </div>
                            <?php if ($b['appointment_code']): ?>
                            <div style="font-size:0.68rem;color:var(--gray-400);"><?php echo e($b['appointment_code']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td data-label="Patient">
                            <a href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $b['patient_id']; ?>" style="font-weight:600;font-size:0.85rem;color:var(--gray-900);text-decoration:none;" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--gray-900)'"><?php echo e(ucwords(strtolower($b['patient_name'] ?? ''))); ?></a>
                            <div style="font-size:0.72rem;color:var(--gray-400);"><?php echo e($b['patient_code']); ?></div>
                        </td>
                        <td data-label="Service" style="font-size:0.82rem;color:var(--gray-600);"><?php echo e($b['service_name'] ?? '—'); ?></td>
                        <td data-label="Amount Due" style="font-weight:600;font-size:0.85rem;">₱<?php echo number_format($b['amount_due'], 2); ?></td>
                        <td data-label="Paid" style="color:var(--success);font-weight:700;font-size:0.85rem;">
                            ₱<?php echo number_format($b['amount_paid'], 2); ?>
                        </td>
                        <td data-label="Balance" style="font-weight:700;font-size:0.85rem;color:<?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                            <?php echo $balance > 0 ? '₱'.number_format($balance,2) : '✓ Settled'; ?>
                        </td>
                        <td data-label="Method">
                            <span class="method-badge <?php echo $m['class']; ?>">
                                <?php echo $m['label']; ?>
                            </span>
                        </td>
                        <td data-label="Status">
                            <span class="status-pill <?php echo $b['status']; ?>">
                                <?php if ($b['status'] === 'paid'): ?>✓<?php elseif ($b['status'] === 'unpaid'): ?>✗<?php else: ?>◑<?php endif; ?>
                                <?php echo ucfirst($b['status']); ?>
                            </span>
                        </td>
                        <td data-label="Date" style="font-size:0.75rem;color:var(--gray-400);">
                            <?php echo date('M d, Y', strtotime($b['created_at'])); ?>
                        </td>
                        <td data-label="Actions">
                            <div style="display:flex;gap:5px;">
                                <a href="view.php?id=<?php echo $b['id']; ?>"
                                   class="btn btn-sm btn-outline-info" title="View" aria-label="View bill">
                                    <i class="bi bi-eye" aria-hidden="true"></i>
                                </a>
                                <?php if ($b['status'] !== 'paid'): ?>
                                <a href="pay.php?id=<?php echo $b['id']; ?>"
                                   class="btn btn-sm btn-outline-success" title="Record Payment" aria-label="Record payment">
                                    <i class="bi bi-cash" aria-hidden="true"></i>
                                </a>
                                <?php endif; ?>
                                <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $b['id']; ?>"
                                   class="btn btn-sm btn-outline-secondary" title="Print Receipt" aria-label="Print receipt">
                                    <i class="bi bi-printer" aria-hidden="true"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> bills
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
