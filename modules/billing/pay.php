<?php
// Record an additional payment against an existing unpaid or partial bill.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$current_user_id = $_SESSION['user_id'] ?? 0;
$current_user_name = $_SESSION['user_name'] ?? '';

$page_title = 'Record Payment';
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$bill = $conn->query("
    SELECT b.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, s.service_name
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.id = $id AND b.status != 'paid' LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if (!$bill) { header('Location: list.php'); exit(); }

$balance = $bill['amount_due'] - $bill['amount_paid'];
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        validate_csrf();
        $add_payment    = round(floatval($_POST['add_payment'] ?? 0), 2);
    $payment_method = $_POST['payment_method'] ?? 'cash';
    $gcash_ref      = trim($_POST['gcash_ref'] ?? '');
    $bank_ref       = trim($_POST['bank_ref']  ?? '');
    $payment_ref    = $gcash_ref ?: $bank_ref ?: '';

    // Whitelist payment method
    $allowed_methods = ['cash', 'gcash', 'bank', 'other'];
    if (!in_array($payment_method, $allowed_methods)) $payment_method = 'cash';

    if ($add_payment <= 0) {
        $error = 'Please enter a valid payment amount.';
    } elseif ($add_payment > $balance + 0.01) {
        $error = 'Payment amount cannot exceed the remaining balance of ₱' . number_format($balance, 2) . '.';
    } else {
        $new_paid = $bill['amount_paid'] + $add_payment;
        $new_status = $new_paid >= $bill['amount_due'] ? 'paid' : 'partial';

        $stmt = $conn->prepare("
            UPDATE bills
            SET amount_paid = ?, payment_method = ?, payment_ref = ?, status = ?
            WHERE id = ?
        ");
        $stmt->execute([$new_paid, $payment_method, $payment_ref, $new_status, $id]);

        if ($stmt->rowCount() < 1) {
            $error = 'Failed to record payment. Please try again.';
        } else {
            log_action($conn, $current_user_id, $current_user_name,
                'Recorded Payment', 'billing', $id,
                "Bill: {$bill['bill_code']} | Added: ₱$add_payment | Method: $payment_method | New status: $new_status"
            );

            // ── Notification trigger ──────────────────────────────
            $pname = $bill['patient_name'] ?? 'Patient';
            $bcode = $bill['bill_code'];
            if ($new_status === 'paid') {
                notify($conn, 'payment', 'Bill Fully Paid',
                    "$pname's bill $bcode is now fully paid. Total: ₱" . number_format($bill['amount_due'], 2) . ".",
                    'modules/billing/list.php');
            } else {
                $remaining = $bill['amount_due'] - $new_paid;
                notify($conn, 'payment', 'Payment Recorded',
                    "₱" . number_format($add_payment, 2) . " received from $pname. Remaining balance: ₱" . number_format($remaining, 2) . ". Bill: $bcode.",
                    'modules/billing/list.php');
            }
            // ─────────────────────────────────────────────────────

            header('Location: view.php?id=' . $id);
            exit();
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

        <div class="page-header">
            <div>
                <h5>Record Payment</h5>
                <p>Bill: <?php echo e($bill['bill_code']); ?> — <?php echo e($bill['patient_name']); ?></p>
            </div>
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <div class="card" style="max-width:520px;">
            <div class="card-header"><i class="bi bi-cash" style="color:var(--success)"></i> Payment Details</div>
            <div class="card-body">

                <!-- Current balance info -->
                <div style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:8px;padding:14px;margin-bottom:20px;">
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:6px;">
                        <span style="color:var(--gray-600);">Service</span>
                        <span style="font-weight:600;"><?php echo e($bill['service_name'] ?? '—'); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:6px;">
                        <span style="color:var(--gray-600);">Total Bill</span>
                        <span style="font-weight:600;">₱<?php echo number_format($bill['amount_due'],2); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:0.875rem;margin-bottom:6px;">
                        <span style="color:var(--gray-600);">Already Paid</span>
                        <span style="font-weight:600;color:var(--success);">₱<?php echo number_format($bill['amount_paid'],2); ?></span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:1rem;border-top:1px solid var(--blue-200);padding-top:8px;margin-top:4px;">
                        <span style="font-weight:700;color:var(--danger);">Remaining Balance</span>
                        <span style="font-weight:800;color:var(--danger);font-family:'Outfit',sans-serif;">₱<?php echo number_format($balance,2); ?></span>
                    </div>
                </div>

                <form method="POST">
                    <?php echo csrf_field(); ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Amount to Pay (₱) <span style="color:var(--danger)">*</span></label>
                            <input type="number" name="add_payment" id="add_payment" class="form-control"
                                step="0.01" min="0.01" max="<?php echo $balance; ?>"
                                placeholder="Enter amount" oninput="showChange()"
                                required autofocus>
                            <div id="change_display" style="margin-top:6px;font-size:0.82rem;color:var(--gray-500);"></div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Payment Method</label>
                            <select name="payment_method" id="method_select" class="form-select" onchange="toggleRef(this.value)">
                                <option value="cash">💵 Cash</option>
                                <option value="gcash">📱 GCash</option>
                                <option value="bank">🏦 Bank Transfer</option>
                                <option value="other">💳 Other</option>
                            </select>
                        </div>
                        <div class="col-12" id="gcash_ref_row" style="display:none;">
                            <label class="form-label">GCash Reference No.</label>
                            <input type="text" name="gcash_ref" class="form-control" placeholder="e.g. 1234567890">
                        </div>
                        <div class="col-12" id="bank_ref_row" style="display:none;">
                            <label class="form-label">Bank Reference No.</label>
                            <input type="text" name="bank_ref" class="form-control" placeholder="e.g. Transfer ref number">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:20px;">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-lg"></i> Confirm Payment
                        </button>
                        <a href="view.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

    </div>
<script>
var balance = <?php echo $balance; ?>;

function showChange() {
    var paid   = parseFloat(document.getElementById('add_payment').value) || 0;
    var disp   = document.getElementById('change_display');
    var remain = balance - paid;

    if (paid <= 0) { disp.textContent = ''; return; }

    if (paid >= balance) {
        disp.innerHTML = '<span style="color:var(--success);font-weight:600;">✅ Full payment — no balance remaining</span>';
    } else {
        disp.innerHTML = 'Remaining balance after this payment: <strong style="color:var(--danger);">₱' + remain.toFixed(2) + '</strong>';
    }
}

function toggleRef(method) {
    document.getElementById('gcash_ref_row').style.display = method === 'gcash' ? 'block' : 'none';
    document.getElementById('bank_ref_row').style.display  = method === 'bank'  ? 'block' : 'none';
}
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
