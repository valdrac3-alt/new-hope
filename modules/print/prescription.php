<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../treatments/list.php'); exit(); }

$stmt_record = $conn->prepare("
    SELECT dr.*, s.service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.date_of_birth, p.gender, p.phone, p.address, p.allergies,
           d.full_name as doctor_name,
           d.specialization as doctor_spec,
           u.full_name as recorded_by_name
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    WHERE dr.id = ? LIMIT 1\n");
$stmt_record->execute([$id]);
$record = $stmt_record->fetch(PDO::FETCH_ASSOC);

if (!$record) { header('Location: ../treatments/list.php'); exit(); }
$age = $record['date_of_birth'] ? date_diff(date_create($record['date_of_birth']), date_create('today'))->y : '—';
$rx_num = 'RX-' . str_pad($id, 5, '0', STR_PAD_LEFT);
?><!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta charset="UTF-8">
    <title>Prescription — <?php echo e($rx_num); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print.css">
    <style>
    body { font-family: 'DM Sans', sans-serif; background: #f1f5f9; margin: 0; padding: 0; }
    .toolbar { position:fixed;top:0;left:0;right:0;z-index:100;background:#fff;border-bottom:1px solid #e2e8f0;padding:12px 24px;display:flex;gap:10px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,0.07); }
    .toolbar button,.toolbar a { padding:8px 18px;border-radius:8px;font-size:0.85rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;border:none; }
    .btn-print { background:#2563eb;color:#fff; }
    .btn-pdf   { background:#fff;color:#2563eb;border:1.5px solid #2563eb !important; }
    .btn-back  { background:#f8fafc;color:#64748b;border:1.5px solid #e2e8f0 !important; }
    .wrap { padding:90px 24px 40px;display:flex;justify-content:center; }
    .rx-card { background:#fff;width:100%;max-width:520px;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.10);overflow:hidden; }
    .rx-header { background:linear-gradient(135deg,#1d4ed8,#1e3a8a);padding:22px 28px;display:flex;justify-content:space-between;align-items:flex-start; }
    .clinic-name { font-size:1.15rem;font-weight:800;color:#fff; }
    .clinic-sub  { font-size:0.75rem;color:rgba(255,255,255,0.65);margin-top:2px; }
    .rx-meta { text-align:right; }
    .rx-num  { font-size:0.9rem;font-weight:700;color:#fff; }
    .rx-date { font-size:0.72rem;color:rgba(255,255,255,0.65);margin-top:3px; }
    .rx-body { padding:22px 28px; }
    .patient-strip { background:#f8fafc;border-radius:10px;padding:12px 16px;margin-bottom:20px;display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:0.82rem; }
    .ps-label { color:#94a3b8;font-size:0.72rem;text-transform:uppercase;letter-spacing:.05em; }
    .ps-value { font-weight:600;color:#0f172a; }
    .rx-symbol { font-size:2.5rem;font-weight:900;color:#1d4ed8;line-height:1;margin-bottom:8px; }
    .rx-box { border:2px solid #1d4ed8;border-radius:12px;padding:18px 20px;min-height:140px;margin-bottom:20px;font-size:0.9rem;color:#0f172a;white-space:pre-wrap;line-height:1.8; }
    .rx-empty { color:#94a3b8;font-style:italic; }
    .disp-label { font-size:0.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:4px; }
    .disp-box { background:#f8fafc;border-radius:8px;padding:10px 14px;font-size:0.82rem;color:#0f172a;margin-bottom:16px;white-space:pre-wrap;min-height:40px; }
    .sig-area { display:flex;gap:24px;margin-top:24px;padding-top:16px;border-top:1px dashed #e2e8f0; }
    .sig-item { flex:1;text-align:center; }
    .sig-line { border-bottom:1.5px solid #cbd5e1;height:40px;margin-bottom:6px; }
    .sig-name { font-size:0.8rem;font-weight:600;color:#0f172a; }
    .sig-sub  { font-size:0.7rem;color:#94a3b8; }
    .allergy-tag { display:inline-block;background:#fee2e2;color:#991b1b;font-size:0.72rem;font-weight:600;padding:2px 8px;border-radius:4px;margin-left:6px; }
    .rx-footer { background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 28px;text-align:center;font-size:0.72rem;color:#94a3b8;line-height:1.8; }
    @media print { .toolbar{display:none!important}body{background:#fff}.wrap{padding:0}.rx-card{box-shadow:none;border-radius:0} }
    </style>
</head>
<body>
<div class="toolbar">
    <button class="btn-print" onclick="window.print()">🖨️ Print</button>
    <button class="btn-pdf" onclick="downloadRxPDF()">📄 Download PDF</button>
    <a class="btn-back" href="../treatments/list.php">← Back</a>
    <span style="margin-left:auto;font-weight:700;color:#0f172a;font-size:0.9rem;">Prescription — <?php echo e($rx_num); ?></span>
</div>

<div class="wrap">
<div class="rx-card" id="rxDoc">

    <div class="rx-header">
        <div>
            <div class="clinic-name">🦷 DentalCare Clinic</div>
            <div class="clinic-sub">Dental Prescription</div>
            <?php if ($record['doctor_name']): ?>
            <div class="clinic-sub" style="margin-top:6px;color:rgba(255,255,255,0.85);">
                Dr. <?php echo e($record['doctor_name']); ?>
                <?php if ($record['doctor_spec']): ?> · <?php echo e($record['doctor_spec']); ?><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="rx-meta">
            <div class="rx-num"><?php echo e($rx_num); ?></div>
            <div class="rx-date"><?php echo date('M d, Y', strtotime($record['visit_date'])); ?></div>
        </div>
    </div>

    <div class="rx-body">
        <!-- Patient Info -->
        <div class="patient-strip">
            <div>
                <div class="ps-label">Patient</div>
                <div class="ps-value"><?php echo e($record['patient_name']); ?></div>
            </div>
            <div>
                <div class="ps-label">Code</div>
                <div class="ps-value"><?php echo e($record['patient_code']); ?></div>
            </div>
            <div>
                <div class="ps-label">Age / Sex</div>
                <div class="ps-value"><?php echo $age; ?> / <?php echo ucfirst($record['gender'] ?? '—'); ?></div>
            </div>
            <div>
                <div class="ps-label">Visit Date</div>
                <div class="ps-value"><?php echo date('M d, Y', strtotime($record['visit_date'])); ?></div>
            </div>
        </div>

        <?php if ($record['allergies']): ?>
        <div style="margin-bottom:14px;font-size:0.82rem;color:#64748b;">
            ⚠ Allergies: <span class="allergy-tag"><?php echo e($record['allergies']); ?></span>
        </div>
        <?php endif; ?>

        <!-- Diagnosis -->
        <?php if ($record['diagnosis']): ?>
        <div class="disp-label">Diagnosis</div>
        <div class="disp-box"><?php echo e($record['diagnosis']); ?></div>
        <?php endif; ?>

        <!-- Rx Symbol + Medications -->
        <div class="rx-symbol">℞</div>
        <div class="rx-box">
            <?php if ($record['medications_prescribed']): ?>
                <?php echo e($record['medications_prescribed']); ?>
            <?php else: ?>
                <span class="rx-empty">No medications prescribed for this visit.</span>
            <?php endif; ?>
        </div>

        <!-- Next Visit Notes -->
        <?php if ($record['next_visit_notes']): ?>
        <div class="disp-label">Instructions / Follow-up</div>
        <div class="disp-box"><?php echo e($record['next_visit_notes']); ?></div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="sig-area">
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-name">Patient / Guardian</div>
                <div class="sig-sub">Signature over printed name</div>
            </div>
            <div class="sig-item">
                <div class="sig-line"></div>
                <div class="sig-name">Dr. <?php echo e($record['doctor_name'] ?? $record['recorded_by_name'] ?? 'Attending Dentist'); ?></div>
                <div class="sig-sub">License No. ________________</div>
            </div>
        </div>
    </div>

    <div class="rx-footer">
        🦷 DentalCare Clinic &nbsp;·&nbsp; <?php echo e($rx_num); ?> &nbsp;·&nbsp; <?php echo date('M d, Y h:i A'); ?><br>
        <em>This prescription is valid for 7 days from the date issued.</em>
    </div>

</div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadRxPDF() {
    html2pdf().set({
        margin: [6,6,6,6],
        filename: '<?php echo $rx_num; ?>.pdf',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a5', orientation: 'portrait' }
    }).from(document.getElementById('rxDoc')).save();
}
</script>
</body>
</html>