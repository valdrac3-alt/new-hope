<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Patient Profile';

$id = secure_int($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$patient = $conn->query("SELECT * FROM patients WHERE id = $id AND is_active = TRUE LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$patient) { header('Location: list.php'); exit(); }

$dental_records = $conn->query("
    SELECT dr.*, s.service_name, CONCAT(u.full_name) as recorded_by_name
    FROM dental_records dr
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    WHERE dr.patient_id = $id
    ORDER BY dr.visit_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$appointments = $conn->query("
    SELECT a.*, s.service_name, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    WHERE a.patient_id = $id
    ORDER BY a.appointment_date DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

$payments = $conn->query("
    SELECT b.*, s.service_name,
           b.amount_paid, b.amount_due, b.status as payment_status,
           b.payment_method, b.created_at
    FROM bills b
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.patient_id = $id
    ORDER BY b.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_paid = array_sum(array_column($payments, 'amount_paid'));

// Build unified timeline (dental records + completed appointments)
$timeline_events = [];
foreach ($dental_records as $rec) {
    $timeline_events[] = [
        'date'    => $rec['visit_date'],
        'type'    => 'treatment',
        'title'   => $rec['service_name'] ?? 'Treatment',
        'detail'  => $rec['treatment_done'],
        'tooth'   => $rec['tooth_number'] ?? null,
        'by'      => $rec['recorded_by_name'] ?? null,
        'id'      => $rec['id'],
    ];
}
foreach ($appointments as $apt) {
    if ($apt['status'] === 'completed') {
        $timeline_events[] = [
            'date'   => $apt['appointment_date'],
            'type'   => 'appointment',
            'title'  => $apt['service_name'] ?? 'Appointment',
            'detail' => $apt['notes'] ?? '',
            'by'     => $apt['doctor_name'] ?? null,
            'id'     => $apt['id'],
        ];
    }
}
usort($timeline_events, fn($a, $b) => strcmp($b['date'], $a['date']));

// Age
$age = '';
if (!empty($patient['date_of_birth'])) {
    $age = (int)floor((time() - strtotime($patient['date_of_birth'])) / 31557600);
}
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Patient Profile Layout ─────────────────────────────── */
.profile-grid {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 20px;
    align-items: start;
}
@media (max-width: 900px) {
    .profile-grid { grid-template-columns: 1fr; }
}

/* Info table */
.info-table { width: 100%; border-collapse: collapse; }
.info-table tr { border-bottom: 1px solid var(--gray-100); }
.info-table tr:last-child { border-bottom: none; }
.info-table th {
    width: 42%; padding: 8px 12px;
    font-size: 0.78rem; font-weight: 600;
    color: var(--gray-500); text-transform: uppercase;
    letter-spacing: 0.04em; white-space: nowrap; vertical-align: top;
}
.info-table td { padding: 8px 12px; font-size: 0.875rem; color: var(--gray-800); word-break: break-word; }

/* Accordion */
.rec-accordion { border: var(--border); border-radius: 12px; overflow: hidden; }
.rec-item + .rec-item { border-top: 1px solid var(--gray-100); }
.rec-toggle {
    width: 100%; text-align: left; background: var(--white);
    border: none; padding: 12px 16px; cursor: pointer;
    display: flex; align-items: center; gap: 12px;
    font-size: 0.875rem; transition: background 0.15s;
}
.rec-toggle:hover { background: var(--gray-50); }
.rec-toggle .rec-date { font-weight: 700; color: var(--gray-800); white-space: nowrap; }
.rec-toggle .rec-svc  { color: var(--gray-500); font-size: 0.82rem; }
.rec-toggle .rec-arrow { margin-left: auto; color: var(--gray-400); transition: transform 0.2s; }
.rec-toggle.open .rec-arrow { transform: rotate(180deg); }
.rec-body { display: none; padding: 14px 16px; background: var(--gray-50); border-top: 1px solid var(--gray-100); font-size: 0.875rem; }
.rec-body.show { display: block; }
.rec-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
@media (max-width: 600px) { .rec-grid { grid-template-columns: 1fr; } }
.rec-field { margin-bottom: 6px; }
.rec-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--gray-400); margin-bottom: 2px; }
.rec-value { color: var(--gray-800); }

/* ── Treatment Timeline ───────────────────────────────────── */
.timeline { position: relative; padding-left: 28px; }
.timeline::before {
    content: '';
    position: absolute;
    left: 9px; top: 8px; bottom: 8px;
    width: 2px;
    background: linear-gradient(to bottom, var(--primary), #14B8A6);
    border-radius: 2px;
    opacity: 0.25;
}
.tl-event {
    position: relative;
    margin-bottom: 18px;
}
.tl-dot {
    position: absolute;
    left: -23px; top: 6px;
    width: 14px; height: 14px;
    border-radius: 50%;
    border: 2.5px solid var(--white);
    box-shadow: 0 0 0 2px var(--primary);
    background: var(--primary);
    transition: transform 0.15s;
}
.tl-dot.appt { background: #14B8A6; box-shadow: 0 0 0 2px #14B8A6; }
.tl-event:hover .tl-dot { transform: scale(1.25); }
.tl-card {
    background: var(--white);
    border: var(--border);
    border-radius: 10px;
    padding: 12px 14px;
    transition: box-shadow 0.15s;
}
.tl-card:hover { box-shadow: 0 2px 12px rgba(0,0,0,0.08); }
.tl-date { font-size: 0.72rem; font-weight: 700; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 3px; }
.tl-title { font-weight: 700; font-size: 0.9rem; color: var(--gray-800); margin-bottom: 4px; }
.tl-detail { font-size: 0.82rem; color: var(--gray-500); line-height: 1.4; }
.tl-chip {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 8px;
    font-size: 0.7rem; font-weight: 700;
    margin-right: 4px; margin-top: 4px;
}
.tl-chip.tooth { background: #EFF6FF; color: #3B82F6; }
.tl-chip.by    { background: #F0FDF4; color: #22C55E; }
.tl-empty { text-align:center; padding: 32px 0; color: var(--gray-300); }
.tl-empty i { font-size: 2rem; display: block; margin-bottom: 8px; }

/* Mobile tabs */
.profile-tabs { display: none; }
@media (max-width: 900px) {
    .profile-tabs {
        display: flex; gap: 6px; overflow-x: auto;
        padding-bottom: 4px; margin-bottom: 16px; scrollbar-width: none;
    }
    .profile-tabs::-webkit-scrollbar { display: none; }
    .ptab {
        white-space: nowrap; padding: 7px 14px; border-radius: 20px;
        border: 1.5px solid var(--gray-200); background: var(--white);
        font-size: 0.82rem; font-weight: 600; color: var(--gray-600);
        cursor: pointer; transition: all 0.15s; flex-shrink: 0;
    }
    .ptab.active { background: var(--primary); border-color: var(--primary); color: #fff; }
    .tab-section { display: none; }
    .tab-section.active { display: block; }
    .profile-left { display: none; }
    .profile-left.active { display: block; }
    .profile-right { display: none; }
    .profile-right.active { display: block; }
}
@media (min-width: 901px) {
    .tab-section { display: block !important; }
    .profile-left, .profile-right { display: block !important; }
}

/* Payment summary */
.pay-summary {
    background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark, #1a4bb8) 100%);
    border-radius: 12px; padding: 18px; color: #fff; margin-bottom: 12px;
}
.pay-summary .amount { font-size: 1.8rem; font-weight: 800; letter-spacing: -0.02em; }
.pay-summary .label  { font-size: 0.78rem; opacity: 0.8; margin-top: 2px; }

/* Quick stats strip */
.quick-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin-bottom: 16px;
}
.qs-card {
    background: var(--gray-50);
    border: var(--border);
    border-radius: 10px;
    padding: 10px 12px;
    text-align: center;
}
.qs-num { font-size: 1.4rem; font-weight: 800; color: var(--primary); line-height: 1; }
.qs-lbl { font-size: 0.68rem; font-weight: 600; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.04em; margin-top: 2px; }

/* Dark mode */
[data-theme="dark"] .info-table th { color: #94A3B8; }
[data-theme="dark"] .info-table td { color: #E2E8F0; }
[data-theme="dark"] .info-table tr { border-bottom-color: #1E293B; }
[data-theme="dark"] .rec-toggle { background: #1E293B; color: #E2E8F0; }
[data-theme="dark"] .rec-toggle:hover { background: #263348; }
[data-theme="dark"] .rec-toggle .rec-date { color: #E2E8F0; }
[data-theme="dark"] .rec-toggle .rec-svc { color: #94A3B8; }
[data-theme="dark"] .rec-body { background: #0F172A; border-top-color: #1E293B; }
[data-theme="dark"] .rec-value { color: #E2E8F0; }
[data-theme="dark"] .rec-label { color: #64748B; }
[data-theme="dark"] .rec-accordion { border-color: #1E293B; }
[data-theme="dark"] .rec-item + .rec-item { border-top-color: #1E293B; }
[data-theme="dark"] .tl-card { background: #1E293B; border-color: #334155; }
[data-theme="dark"] .tl-title { color: #E2E8F0; }
[data-theme="dark"] .tl-detail { color: #94A3B8; }
[data-theme="dark"] .tl-chip.tooth { background: #1E3A5F; color: #93C5FD; }
[data-theme="dark"] .tl-chip.by    { background: #14532D; color: #86EFAC; }
[data-theme="dark"] .qs-card { background: #1E293B; border-color: #334155; }
[data-theme="dark"] .qs-lbl { color: #64748B; }
[data-theme="dark"] .tl-dot { border-color: #1E293B; }
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header -->
        <div class="page-header" style="margin-bottom:16px;">
            <div>
                <h5 style="margin-bottom:2px;">
                    <?php echo e($patient['first_name'] . ' ' . $patient['last_name']); ?>
                    <?php if ($age !== ''): ?><span style="font-size:0.9rem;font-weight:400;color:var(--gray-400);margin-left:6px;"><?php echo $age; ?> yrs</span><?php endif; ?>
                    <?php if (!empty($patient['is_incomplete'])): ?> <span class="badge bg-warning" style="font-size:0.65rem;font-weight:600;vertical-align:middle;"><i class="bi bi-exclamation-circle"></i> Incomplete profile</span><?php endif; ?>
                </h5>
                <small class="text-muted"><?php echo e($patient['patient_code']); ?><?php if (!empty($patient['blood_type'])): ?> &nbsp;·&nbsp; <span style="color:var(--danger);font-weight:600;"><?php echo e($patient['blood_type']); ?></span><?php endif; ?></small>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i> Edit</a>
                <a href="../treatments/add.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-success"><i class="bi bi-journal-plus"></i> Add Record</a>
                <a href="../appointments/list.php?walkin=1&patient_id=<?php echo $id; ?>" class="btn btn-sm btn-primary"><i class="bi bi-calendar-plus"></i> Book</a>
                <a href="list.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if (!empty($patient['is_incomplete'])): ?>
        <div class="alert alert-warning" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
            <span><i class="bi bi-exclamation-triangle"></i> Quick-added patient — add date of birth, gender, and address to complete the profile.</span>
            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-warning" style="white-space:nowrap;"><i class="bi bi-pencil"></i> Complete Profile</a>
        </div>
        <?php endif; ?>

        <!-- Quick Stats Strip -->
        <div class="quick-stats">
            <div class="qs-card">
                <div class="qs-num"><?php echo count($dental_records); ?></div>
                <div class="qs-lbl">Records</div>
            </div>
            <div class="qs-card">
                <div class="qs-num"><?php echo count($appointments); ?></div>
                <div class="qs-lbl">Appts</div>
            </div>
            <div class="qs-card">
                <div class="qs-num">₱<?php echo number_format($total_paid, 0); ?></div>
                <div class="qs-lbl">Total Paid</div>
            </div>
        </div>

        <!-- Mobile Tabs -->
        <div class="profile-tabs">
            <button class="ptab active" onclick="switchTab('info')">👤 Info</button>
            <button class="ptab" onclick="switchTab('timeline')">📋 Timeline (<?php echo count($timeline_events); ?>)</button>
            <button class="ptab" onclick="switchTab('records')">🦷 Records (<?php echo count($dental_records); ?>)</button>
            <button class="ptab" onclick="switchTab('appointments')">📅 Appointments (<?php echo count($appointments); ?>)</button>
            <button class="ptab" onclick="switchTab('billing')">💳 Billing (<?php echo count($payments); ?>)</button>
        </div>

        <!-- Main Grid -->
        <div class="profile-grid">

            <!-- LEFT COLUMN -->
            <div class="profile-left tab-section active" data-tab="info">

                <!-- Personal Info -->
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-person-fill me-2" style="color:var(--primary);"></i>Personal Information</div>
                    <div class="card-body p-0">
                        <table class="info-table">
                            <tr><th>Full Name</th><td><?php echo e($patient['first_name'] . ' ' . ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . $patient['last_name']); ?></td></tr>
                            <tr><th>Date of Birth</th><td><?php if ($patient['date_of_birth']): echo date('M d, Y', strtotime($patient['date_of_birth'])); echo ' <span style="font-size:0.78rem;color:var(--gray-400);font-weight:400;">(' . $age . ' yrs)</span>'; else: echo '—'; endif; ?></td></tr>
                            <tr><th>Gender</th><td><?php echo ucfirst($patient['gender'] ?? '—'); ?></td></tr>
                            <tr><th>Civil Status</th><td><?php echo ucfirst($patient['civil_status'] ?? '—'); ?></td></tr>
                            <tr><th>Blood Type</th><td><?php echo e($patient['blood_type'] ?? '—'); ?></td></tr>
                            <tr><th>Address</th><td><?php echo e($patient['address'] ?? '—'); ?></td></tr>
                            <tr><th>Occupation</th><td><?php echo e($patient['occupation'] ?: '—'); ?></td></tr>
                            <tr><th>Phone</th><td><a href="tel:<?php echo e($patient['phone'] ?? ''); ?>"><?php echo e($patient['phone'] ?? '—'); ?></a></td></tr>
                            <tr><th>Email</th><td><?php echo e($patient['email'] ?? '—'); ?></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-telephone-fill me-2" style="color:var(--danger);"></i>Emergency Contact</div>
                    <div class="card-body p-0">
                        <table class="info-table">
                            <tr><th>Name</th><td><?php echo e($patient['emergency_contact_name'] ?? '—'); ?></td></tr>
                            <tr><th>Phone</th><td><a href="tel:<?php echo e($patient['emergency_contact_phone'] ?? ''); ?>"><?php echo e($patient['emergency_contact_phone'] ?? '—'); ?></a></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Medical Background -->
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-heart-pulse-fill me-2" style="color:var(--danger);"></i>Medical Background</div>
                    <div class="card-body">
                        <p class="mb-1" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray-400);">Allergies</p>
                        <p class="mb-3" style="font-size:0.875rem;"><?php echo nl2br(e($patient['allergies'] ?? 'None reported')); ?></p>
                        <p class="mb-1" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray-400);">Medical Notes</p>
                        <p class="mb-3" style="font-size:0.875rem;"><?php echo nl2br(e($patient['medical_notes'] ?? 'None')); ?></p>
                        <?php if (!empty($patient['illness_history'])): ?>
                        <p class="mb-1" style="font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gray-400);">History of Illness</p>
                        <p class="mb-0" style="font-size:0.875rem;"><?php echo nl2br(e($patient['illness_history'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Summary -->
                <div class="card mb-3">
                    <div class="card-body p-3">
                        <div class="pay-summary">
                            <div class="amount">₱<?php echo number_format($total_paid, 2); ?></div>
                            <div class="label">Total paid (all time)</div>
                        </div>
                        <a href="../billing/create.php?patient_id=<?php echo $id; ?>" class="btn btn-outline-success w-100">
                            <i class="bi bi-plus-lg"></i> Create Bill
                        </a>
                    </div>
                </div>

            </div><!-- /LEFT -->

            <!-- RIGHT COLUMN -->
            <div class="profile-right" style="min-width:0;">

                <!-- ── Treatment Timeline ───────────────────────────────── -->
                <div class="card mb-4 tab-section" data-tab="timeline">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock-history me-2" style="color:#14B8A6;"></i>Treatment Timeline
                            <span class="badge ms-1" style="background:#14B8A6;"><?php echo count($timeline_events); ?></span>
                        </span>
                        <a href="../treatments/add.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus"></i> Add</a>
                    </div>
                    <div class="card-body">
                        <?php if (empty($timeline_events)): ?>
                            <div class="tl-empty">
                                <i class="bi bi-clock-history"></i>
                                No treatment history yet.
                            </div>
                        <?php else: ?>
                            <div class="timeline">
                                <?php
                                $prev_year = null;
                                foreach ($timeline_events as $ev):
                                    $year = date('Y', strtotime($ev['date']));
                                    if ($year !== $prev_year):
                                        $prev_year = $year;
                                ?>
                                <div style="margin-left:-28px;margin-bottom:8px;margin-top:<?php echo $year !== date('Y') ? '8px' : '0'; ?>;">
                                    <span style="font-size:0.72rem;font-weight:800;color:var(--gray-400);text-transform:uppercase;letter-spacing:0.06em;background:var(--gray-50);padding:2px 8px;border-radius:8px;"><?php echo $year; ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="tl-event">
                                    <div class="tl-dot <?php echo $ev['type'] === 'appointment' ? 'appt' : ''; ?>"></div>
                                    <div class="tl-card">
                                        <div class="tl-date"><?php echo date('M d, Y', strtotime($ev['date'])); ?></div>
                                        <div class="tl-title">
                                            <?php if ($ev['type'] === 'appointment'): ?>
                                            <i class="bi bi-calendar-check" style="color:#14B8A6;font-size:0.75rem;margin-right:4px;"></i>
                                            <?php else: ?>
                                            <i class="bi bi-journal-medical" style="color:var(--primary);font-size:0.75rem;margin-right:4px;"></i>
                                            <?php endif; ?>
                                            <?php echo e($ev['title']); ?>
                                        </div>
                                        <?php if (!empty($ev['detail'])): ?>
                                        <div class="tl-detail"><?php echo e(strlen($ev['detail']) > 90 ? substr($ev['detail'], 0, 90) . '…' : $ev['detail']); ?></div>
                                        <?php endif; ?>
                                        <div>
                                            <?php if (!empty($ev['tooth'])): ?>
                                            <span class="tl-chip tooth"><i class="bi bi-circle-fill" style="font-size:0.5rem;"></i> Tooth <?php echo e($ev['tooth']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($ev['by'])): ?>
                                            <span class="tl-chip by"><i class="bi bi-person"></i> <?php echo e($ev['by']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Dental Records -->
                <div class="card mb-4 tab-section" data-tab="records">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-journal-medical me-2" style="color:var(--primary);"></i>Dental / Treatment Records
                            <span class="badge bg-primary ms-1"><?php echo count($dental_records); ?></span>
                        </span>
                        <a href="../treatments/add.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus"></i> Add</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($dental_records)): ?>
                            <div class="text-center py-4 text-muted">
                                <i class="bi bi-journal-x" style="font-size:2rem;opacity:0.3;"></i>
                                <p class="mt-2 mb-0">No dental records yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="rec-accordion">
                                <?php foreach ($dental_records as $i => $rec): ?>
                                <div class="rec-item">
                                    <button class="rec-toggle <?php echo $i === 0 ? 'open' : ''; ?>"
                                            onclick="toggleRec(this, 'rec<?php echo $rec['id']; ?>')">
                                        <span class="rec-date"><?php echo date('M d, Y', strtotime($rec['visit_date'])); ?></span>
                                        <span class="rec-svc"><?php echo e($rec['service_name'] ?? 'General'); ?></span>
                                        <i class="bi bi-chevron-down rec-arrow"></i>
                                    </button>
                                    <div class="rec-body <?php echo $i === 0 ? 'show' : ''; ?>" id="rec<?php echo $rec['id']; ?>">
                                        <div class="rec-grid">
                                            <div>
                                                <?php if (!empty($rec['chief_complaint'])): ?>
                                                <div class="rec-field"><div class="rec-label">Chief Complaint</div><div class="rec-value"><?php echo e($rec['chief_complaint']); ?></div></div>
                                                <?php endif; ?>
                                                <div class="rec-field"><div class="rec-label">Tooth Number</div><div class="rec-value"><?php echo e($rec['tooth_number'] ?? '—'); ?></div></div>
                                                <div class="rec-field"><div class="rec-label">Diagnosis</div><div class="rec-value"><?php echo nl2br(e($rec['diagnosis'] ?? '—')); ?></div></div>
                                                <div class="rec-field"><div class="rec-label">Treatment Done</div><div class="rec-value"><?php echo nl2br(e($rec['treatment_done'])); ?></div></div>
                                            </div>
                                            <div>
                                                <div class="rec-field"><div class="rec-label">Medications</div><div class="rec-value"><?php echo nl2br(e($rec['medications_prescribed'] ?? '—')); ?></div></div>
                                                <div class="rec-field"><div class="rec-label">Next Visit Notes</div><div class="rec-value"><?php echo nl2br(e($rec['next_visit_notes'] ?? '—')); ?></div></div>
                                                <div class="rec-field"><div class="rec-label">Recorded By</div><div class="rec-value"><?php echo e($rec['recorded_by_name'] ?? '—'); ?></div></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Appointments -->
                <div class="card mb-4 tab-section" data-tab="appointments">
                    <div class="card-header">
                        <i class="bi bi-calendar-check me-2" style="color:var(--primary);"></i>Recent Appointments
                        <span class="badge bg-primary ms-1"><?php echo count($appointments); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($appointments)): ?>
                            <div class="text-center py-4 text-muted"><i class="bi bi-calendar-x" style="font-size:2rem;opacity:0.3;"></i><p class="mt-2 mb-0">No appointments yet.</p></div>
                        <?php else: ?>
                        <div class="mobile-card-table-wrap">
                        <table class="table table-sm table-hover mb-0 mobile-card-table">
                            <thead><tr><th>Code</th><th>Service</th><th>Doctor</th><th>Date</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach ($appointments as $a): ?>
                                <tr>
                                    <td data-label="Code"><?php echo e($a['appointment_code']); ?></td>
                                    <td data-label="Service"><?php echo e($a['service_name'] ?? 'N/A'); ?></td>
                                    <td data-label="Doctor"><?php echo e($a['doctor_name'] ?? '—'); ?></td>
                                    <td data-label="Date"><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?></td>
                                    <td data-label="Status">
                                        <span class="badge bg-<?php echo match($a['status']) {
                                            'pending'   => 'warning', 'confirmed' => 'primary',
                                            'completed' => 'success', 'cancelled'  => 'danger',
                                            'no-show'   => 'secondary', default => 'light'
                                        }; ?>"><?php echo ucfirst($a['status']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card tab-section" data-tab="billing">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-receipt me-2" style="color:var(--success);"></i>Payment History <span class="badge bg-success ms-1"><?php echo count($payments); ?></span></span>
                        <a href="../billing/create.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-plus"></i> Add</a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($payments)): ?>
                            <div class="text-center py-4 text-muted"><i class="bi bi-receipt-cutoff" style="font-size:2rem;opacity:0.3;"></i><p class="mt-2 mb-0">No payment records.</p></div>
                        <?php else: ?>
                        <div class="mobile-card-table-wrap">
                        <table class="table table-sm table-hover mb-0 mobile-card-table">
                            <thead><tr><th>Service</th><th>Due</th><th>Paid</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
                            <tbody>
                                <?php foreach ($payments as $py): ?>
                                <tr>
                                    <td data-label="Service"><?php echo e($py['service_name'] ?? 'N/A'); ?></td>
                                    <td data-label="Due">₱<?php echo number_format($py['amount_due'], 2); ?></td>
                                    <td data-label="Paid">₱<?php echo number_format($py['amount_paid'], 2); ?></td>
                                    <td data-label="Method"><?php echo ucfirst($py['payment_method'] ?? '—'); ?></td>
                                    <td data-label="Status">
                                        <span class="badge bg-<?php echo match($py['payment_status']) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }; ?>"><?php echo ucfirst($py['payment_status']); ?></span>
                                    </td>
                                    <td data-label="Date"><?php echo date('M d, Y', strtotime($py['created_at'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

            </div><!-- /RIGHT -->
        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
<script>
function toggleRec(btn, id) {
    var body = document.getElementById(id);
    var isOpen = body.classList.contains('show');
    body.classList.toggle('show', !isOpen);
    btn.classList.toggle('open', !isOpen);
}

function switchTab(tab) {
    document.querySelectorAll('.ptab').forEach(function(t) { t.classList.remove('active'); });
    event.currentTarget.classList.add('active');
    var left  = document.querySelector('.profile-left');
    var right = document.querySelector('.profile-right');
    if (tab === 'info') {
        left.classList.add('active');
        right.classList.remove('active');
    } else {
        left.classList.remove('active');
        right.classList.add('active');
        document.querySelectorAll('.profile-right .tab-section').forEach(function(s) {
            s.style.display = s.dataset.tab === tab ? 'block' : 'none';
        });
    }
}

if (window.innerWidth > 900) {
    document.querySelectorAll('.profile-right .tab-section').forEach(function(s) { s.style.display = 'block'; });
}
window.addEventListener('resize', function() {
    if (window.innerWidth > 900) {
        document.querySelectorAll('.profile-right .tab-section').forEach(function(s) { s.style.display = 'block'; });
        document.querySelector('.profile-left').classList.add('active');
        document.querySelector('.profile-right').classList.add('active');
    }
});
</script>
</body>
</html>
