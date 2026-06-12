<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: ../treatments/list.php'); exit(); }

$stmt_record = $conn->prepare("
    SELECT dr.*, s.service_name, s.price,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.date_of_birth, p.gender, p.phone, p.allergies,
           p.occupation,
           u.full_name as recorded_by_name
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    WHERE dr.id = ? LIMIT 1\n");
$stmt_record->execute([$id]);
$record = $stmt_record->fetch(PDO::FETCH_ASSOC);

if (!$record) { header('Location: ../treatments/list.php'); exit(); }

$age = $record['date_of_birth'] ? date_diff(date_create($record['date_of_birth']), date_create('today'))->y . ' yrs' : '—';
?><!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>assets/images/favicon.svg">
    <meta charset="UTF-8">
    <title>Dental Record — <?php echo e($record['patient_code']); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/print.css">
</head>
<body>
<div class="print-toolbar">
    <button onclick="window.print()" style="padding:8px 16px;background:#2563eb;color:#fff;border:none;border-radius:8px;cursor:pointer;font-family:inherit;">🖨️ Print Record</button>
    <button onclick="downloadPDF('dentalRecord','dental-record-<?php echo $id; ?>')" style="padding:8px 16px;background:#fff;color:#2563eb;border:1.5px solid #2563eb;border-radius:8px;cursor:pointer;font-family:inherit;">📄 PDF</button>
    <a href="../treatments/list.php" style="padding:8px 16px;background:#fff;color:#64748b;border:1.5px solid #e2e8f0;border-radius:8px;text-decoration:none;">← Back</a>
</div>

<div class="print-page" id="dentalRecord">
    <div class="doc-header">
        <div class="clinic-info">
            <div class="clinic-logo">🦷</div>
            <div>
                <div class="clinic-name">DentalCare Clinic</div>
                <div class="clinic-sub">Dental Treatment Record</div>
            </div>
        </div>
        <div class="doc-meta">
            <div class="doc-title">Treatment Record</div>
            <div class="doc-code"><?php echo e($record['patient_code']); ?></div>
            <div class="doc-date">Visit: <?php echo date('M d, Y', strtotime($record['visit_date'])); ?></div>
        </div>
    </div>

    <div class="doc-section">
        <div class="section-title">Patient Information</div>
        <div class="info-grid cols-3">
            <div class="info-item"><div class="info-label">Patient Name</div><div class="info-value"><?php echo e($record['patient_name']); ?></div></div>
            <div class="info-item"><div class="info-label">Age / Gender</div><div class="info-value"><?php echo $age; ?> / <?php echo ucfirst($record['gender'] ?? '—'); ?></div></div>
            <div class="info-item"><div class="info-label">Phone</div><div class="info-value"><?php echo e($record['phone'] ?? '—'); ?></div></div>
            <?php if (!empty($record['occupation'])): ?>
            <div class="info-item"><div class="info-label">Occupation</div><div class="info-value"><?php echo e($record['occupation']); ?></div></div>
            <?php endif; ?>
        </div>
        <?php if ($record['allergies']): ?>
        <div style="margin-top:8px;padding:8px 12px;background:#fff3f3;border:1px solid #fecaca;border-radius:6px;font-size:0.8rem;">
            <strong style="color:#dc2626;">⚠ Allergies:</strong> <?php echo e($record['allergies']); ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($record['chief_complaint'])): ?>
    <div class="doc-section">
        <div class="section-title">Chief Complaint</div>
        <div style="padding:10px 14px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;font-size:0.88rem;font-style:italic;color:#78350f;">
            "<?php echo e($record['chief_complaint']); ?>"
        </div>
    </div>
    <?php endif; ?>

    <div class="doc-section">
        <div class="section-title">Treatment Details</div>
        <div class="info-grid">
            <div class="info-item"><div class="info-label">Service / Procedure</div><div class="info-value"><?php echo e($record['service_name'] ?? '—'); ?></div></div>
            <div class="info-item"><div class="info-label">Tooth Number / Area</div><div class="info-value"><?php echo e($record['tooth_number'] ?? '—'); ?></div></div>
        </div>
        <div class="info-grid cols-1" style="margin-top:10px;">
            <div class="info-item">
                <div class="info-label">Diagnosis</div>
                <div class="info-value" style="padding:10px;background:#f8fafc;border-radius:6px;margin-top:4px;"><?php echo e($record['diagnosis'] ?? '—'); ?></div>
            </div>
            <div class="info-item" style="margin-top:10px;">
                <div class="info-label">Treatment Done</div>
                <div class="info-value" style="padding:10px;background:#f8fafc;border-radius:6px;margin-top:4px;"><?php echo e($record['treatment_done']); ?></div>
            </div>
            <?php if ($record['medications_prescribed']): ?>
            <div class="info-item" style="margin-top:10px;">
                <div class="info-label">Medications Prescribed</div>
                <div class="info-value" style="padding:10px;background:#eff6ff;border-radius:6px;margin-top:4px;"><?php echo e($record['medications_prescribed']); ?></div>
            </div>
            <?php endif; ?>
            <?php if ($record['next_visit_notes']): ?>
            <div class="info-item" style="margin-top:10px;">
                <div class="info-label">Next Visit / Follow-up Notes</div>
                <div class="info-value" style="padding:10px;background:#f0fdf4;border-radius:6px;margin-top:4px;"><?php echo e($record['next_visit_notes']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="sig-row">
        <div class="sig-item"><div class="sig-line"></div><div class="sig-label">Patient Signature</div></div>
        <div class="sig-item"><div class="sig-line"></div><div class="sig-label">Recorded by: <?php echo e($record['recorded_by_name'] ?? ''); ?></div></div>
    </div>

    <div class="doc-footer">DentalCare Clinic — Treatment Record ID: <?php echo $id; ?> — Printed: <?php echo date('M d, Y h:i A'); ?></div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<script>
function downloadPDF(elementId, filename) {
    html2pdf().set({ margin:8, filename:filename+'.pdf', image:{type:'jpeg',quality:0.98}, html2canvas:{scale:2}, jsPDF:{unit:'mm',format:'a4',orientation:'portrait'} }).from(document.getElementById(elementId)).save();
}
</script>
</body>
</html>
