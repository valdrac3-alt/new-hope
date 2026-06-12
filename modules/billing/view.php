<?php
// View a bill in detail with patient info, service, and payment status.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'View Bill';
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$bill = $conn->query("
    SELECT b.*,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone, p.address, p.email,
           s.service_name, s.duration_minutes,
           a.appointment_code, a.appointment_date, a.appointment_time,
           u.full_name as created_by_name
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    LEFT JOIN appointments a ON b.appointment_id = a.id
    LEFT JOIN users u ON b.created_by = u.id
    WHERE b.id = $id LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$bill) { header('Location: list.php'); exit(); }

$balance = $bill['amount_due'] - $bill['amount_paid'];
$created   = isset($_GET['created']);
$flow_done  = isset($_GET['flow']) && $_GET['flow'] === 'done';
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Bill — <?php echo e($bill['bill_code']); ?></h5>
                <p>Created <?php echo date('M d, Y h:i A', strtotime($bill['created_at'])); ?> by <?php echo e($bill['created_by_name']); ?></p>
            </div>
            <div style="display:flex;gap:8px;">
                <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $id; ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-printer"></i> Print Receipt
                </a>
                <?php if ($bill['status'] !== 'paid'): ?>
                <a href="pay.php?id=<?php echo $id; ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-cash"></i> Record Payment
                </a>
                <?php endif; ?>
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <?php if ($created && $flow_done): ?>
        <!-- FULL FLOW COMPLETE banner -->
        <div style="background:var(--success-bg);border:1.5px solid #86efac;border-radius:12px;padding:22px 24px;margin-bottom:24px;">
            <div style="display:flex;align-items:center;gap:14px;">
                <div style="width:48px;height:48px;background:var(--success);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0;">✅</div>
                <div>
                    <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;color:#14532d;">Patient Visit Complete!</div>
                    <div style="font-size:0.82rem;color:#166534;margin-top:2px;">
                        Appointment → Treatment recorded → Bill created. All steps done for this patient.
                    </div>
                </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:16px;flex-wrap:wrap;">
                <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" class="btn btn-sm btn-success">
                    <i class="bi bi-calendar-check"></i> Back to Appointments
                </a>
                <a href="<?php echo BASE_URL; ?>modules/print/payment_receipt.php?bill_id=<?php echo $id; ?>"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-printer"></i> Print Receipt
                </a>
                <a href="<?php echo BASE_URL; ?>modules/walkin/add.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-person-walking"></i> Next Walk-in
                </a>
            </div>
        </div>
        <?php elseif ($created): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Bill created successfully!</div>
        <?php endif; ?>

        <div class="row g-3">
            <!-- Bill Summary -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header"><i class="bi bi-receipt" style="color:var(--blue-500)"></i> Bill Summary</div>
                    <div class="card-body">

                        <!-- Status badge -->
                        <div style="margin-bottom:20px;text-align:center;">
                            <span class="badge bg-<?php
                                echo match($bill['status']) {
                                    'paid'    => 'success',
                                    'partial' => 'warning',
                                    'unpaid'  => 'danger',
                                    default   => 'secondary'
                                };
                            ?>" style="font-size:0.9rem;padding:8px 20px;">
                                <?php echo strtoupper($bill['status']); ?>
                            </span>
                        </div>

                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <?php
                            $rows = [
                                ['Service',        $bill['service_name'] ?? '—'],
                                ['Amount Due',     '₱'.number_format($bill['amount_due'],2)],
                                ['Amount Paid',    '₱'.number_format($bill['amount_paid'],2)],
                                ['Balance',        $balance > 0 ? '₱'.number_format($balance,2) : 'FULLY PAID ✅'],
                                ['Payment Method', match($bill['payment_method']) {
                                    'cash'  => '💵 Cash',
                                    'gcash' => '📱 GCash',
                                    'bank'  => '🏦 Bank Transfer',
                                    default => ucfirst($bill['payment_method'])
                                }],
                            ];
                            if ($bill['payment_ref']) $rows[] = ['Reference No.', $bill['payment_ref']];
                            if ($bill['appointment_code']) $rows[] = ['Appointment', $bill['appointment_code'] . ' — ' . date('M d, Y', strtotime($bill['appointment_date']))];
                            if ($bill['notes']) $rows[] = ['Notes', $bill['notes']];
                            foreach ($rows as [$label, $value]):
                            ?>
                            <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--gray-100);font-size:0.875rem;">
                                <span style="color:var(--gray-500);font-weight:500;"><?php echo $label; ?></span>
                                <span style="font-weight:600;color:var(--gray-900);text-align:right;"><?php echo e($value); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
            </div>

            <!-- Patient Info -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header"><i class="bi bi-person-fill" style="color:var(--blue-500)"></i> Patient</div>
                    <div class="card-body">
                        <div style="font-family:'Outfit',sans-serif;font-weight:700;font-size:1rem;margin-bottom:4px;">
                            <?php echo e($bill['patient_name']); ?>
                        </div>
                        <div style="font-size:0.78rem;color:var(--gray-400);margin-bottom:14px;">
                            <?php echo e($bill['patient_code']); ?>
                        </div>
                        <div style="font-size:0.85rem;display:flex;flex-direction:column;gap:6px;">
                            <?php if ($bill['phone']): ?>
                            <div><i class="bi bi-telephone" style="color:var(--blue-500);width:18px;"></i> <?php echo e($bill['phone']); ?></div>
                            <?php endif; ?>
                            <?php if ($bill['email']): ?>
                            <div><i class="bi bi-envelope" style="color:var(--blue-500);width:18px;"></i> <?php echo e($bill['email']); ?></div>
                            <?php endif; ?>
                            <?php if ($bill['address']): ?>
                            <div><i class="bi bi-geo-alt" style="color:var(--blue-500);width:18px;"></i> <?php echo e($bill['address']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div style="margin-top:14px;">
                            <a href="../patients/view.php?id=<?php echo $bill['patient_id']; ?>"
                               class="btn btn-sm btn-outline-primary" style="width:100%;">
                                <i class="bi bi-person"></i> View Patient Profile
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
