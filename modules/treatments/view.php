<?php
// View a single dental record in full detail.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Dental Record';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$stmt = $conn->prepare("
    SELECT dr.*,
           s.service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.id as pid,
           p.date_of_birth, p.gender, p.blood_type,
           p.allergies, p.medical_notes, p.illness_history,
           CONCAT(u.full_name) as recorded_by_name,
           doc.full_name as doctor_name
    FROM dental_records dr
    LEFT JOIN patients  p   ON dr.patient_id     = p.id
    LEFT JOIN services  s   ON dr.service_id     = s.id
    LEFT JOIN users     u   ON dr.recorded_by    = u.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    LEFT JOIN doctors   doc ON a.doctor_id        = doc.id
    WHERE dr.id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) { header('Location: list.php'); exit(); }

// Fetch other dental records for this patient — must be done here before HTML output
$other_stmt = $conn->prepare("
    SELECT dr.id, dr.visit_date, dr.tooth_number, dr.tooth_status, dr.treatment_done,
           s.service_name
    FROM dental_records dr
    LEFT JOIN services s ON dr.service_id = s.id
    WHERE dr.patient_id = ? AND dr.id != ?
    ORDER BY dr.visit_date DESC
    LIMIT 5
");
$other_stmt->execute([$r['pid'], $id]);
$other_records = $other_stmt->fetchAll(PDO::FETCH_ASSOC);
$other_stmt = null;

// Tooth status label + color map (uses CSS variables — dark mode safe)
$status_map = [
    'normal'    => ['label' => 'Normal / Healthy',     'bg' => 'var(--success-bg)',  'color' => 'var(--success)',  'border' => 'var(--success-border)'],
    'caries'    => ['label' => 'Caries (Cavity)',       'bg' => 'var(--warning-bg)',  'color' => 'var(--warning)',  'border' => 'var(--warning-border)'],
    'filling'   => ['label' => 'Filling Done',          'bg' => 'var(--primary-bg)',  'color' => 'var(--primary)',  'border' => 'var(--blue-200)'],
    'extraction'=> ['label' => 'Extraction / Pulled',   'bg' => 'var(--danger-bg)',   'color' => 'var(--danger)',   'border' => 'var(--danger-border)'],
    'missing'   => ['label' => 'Already Missing',       'bg' => 'var(--gray-100)',    'color' => 'var(--gray-500)', 'border' => 'var(--gray-200)'],
    'crown'     => ['label' => 'Crown Placed',          'bg' => '#fdf4ff',            'color' => '#7e22ce',         'border' => '#e9d5ff'],
    'rootcanal' => ['label' => 'Root Canal Treated',    'bg' => '#fff1f2',            'color' => '#be123c',         'border' => '#fecdd3'],
    'bridge'    => ['label' => 'Bridge',                'bg' => 'var(--success-bg)',  'color' => 'var(--success)',  'border' => 'var(--success-border)'],
    'implant'   => ['label' => 'Implant',               'bg' => 'var(--primary-bg)',  'color' => 'var(--primary)',  'border' => 'var(--blue-200)'],
    'denture'   => ['label' => 'Denture',               'bg' => 'var(--gray-100)',    'color' => 'var(--gray-500)', 'border' => 'var(--gray-300)'],
];
$ts  = $r['tooth_status'] ?? 'normal';
$ts_info = $status_map[$ts] ?? $status_map['normal'];
$tsc = $status_map[$ts] ?? $status_map['normal'];
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Dental Record view — mobile ── */
@media (max-width: 640px) {
    .dental-view-grid {
        grid-template-columns: 1fr !important;
    }
    .dental-view-actions {
        flex-direction: column !important;
        width: 100% !important;
    }
    .dental-view-actions a {
        width: 100% !important;
        justify-content: center !important;
    }
    .dental-meds-grid {
        grid-template-columns: 1fr !important;
    }
    .patient-action-btns {
        flex-wrap: wrap !important;
    }
    .patient-action-btns a {
        flex: 1 !important;
        justify-content: center !important;
        text-align: center !important;
    }
    .tooth-chart-scroll {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    .dental-page-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
}

/* ── Medical alert — dark mode ── */
[data-theme="dark"] .view-medical-alert {
    background: #2d2007 !important;
    border-color: #b45309 !important;
}
[data-theme="dark"] .view-alert-icon { color: #fbbf24 !important; }
[data-theme="dark"] .view-alert-body { color: #fde68a !important; }

/* ── Treatment done box — dark mode ── */
[data-theme="dark"] .treatment-done-box {
    background: var(--gray-100) !important;
    border-color: var(--gray-200) !important;
    color: var(--gray-800) !important;
}
</style>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Header row -->
        <div class="page-header" style="margin-bottom:20px;">
            <div>
                <h5>Dental Record</h5>
                <p style="font-size:0.82rem;color:var(--gray-500);margin:0;">
                    <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" style="color:var(--blue-500);">
                        <?php echo e($r['patient_name']); ?>
                    </a>
                    &nbsp;·&nbsp; <?php echo e($r['patient_code']); ?>
                    &nbsp;·&nbsp; Visit: <?php echo date('F d, Y', strtotime($r['visit_date'])); ?>
                </p>
            </div>
            <div class="dental-view-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                <!-- Goes back to the full Dental / Treatment Records list -->
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Records
                </a>
            </div>
        </div>

        <!-- Medical alert banner (shown if patient has relevant data) -->
        <?php if ($r['allergies'] || $r['medical_notes'] || $r['illness_history']): ?>
        <div class="view-medical-alert" style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;">
            <i class="bi bi-shield-exclamation view-alert-icon" style="color:#d97706;font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
            <div class="view-alert-body" style="font-size:0.8rem;color:#92400e;">
                <strong>Medical Alert</strong>
                <?php if ($r['blood_type']): ?>
                    <span style="background:#dc2626;color:#fff;font-size:0.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:8px;"><?php echo e($r['blood_type']); ?></span>
                <?php endif; ?>
                <div style="margin-top:5px;display:flex;gap:16px;flex-wrap:wrap;">
                    <?php if ($r['allergies']): ?><span><strong>Allergies:</strong> <?php echo e($r['allergies']); ?></span><?php endif; ?>
                    <?php if ($r['medical_notes']): ?><span><strong>Medical:</strong> <?php echo e($r['medical_notes']); ?></span><?php endif; ?>
                    <?php if ($r['illness_history']): ?><span><strong>History:</strong> <?php echo e($r['illness_history']); ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dental-view-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">

            <!-- LEFT: Clinical Details -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-journal-medical" style="color:var(--blue-500);margin-right:6px;"></i>
                    Clinical Record
                    <span style="margin-left:auto;font-size:0.75rem;color:var(--gray-400);">
                        <?php echo date('M d, Y', strtotime($r['visit_date'])); ?>
                    </span>
                </div>
                <div class="card-body" style="padding:18px 22px;">

                    <!-- Service + Doctor row -->
                    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                        <?php if ($r['service_name']): ?>
                        <span style="background:var(--primary-bg);color:var(--primary);border:1px solid var(--blue-200);border-radius:7px;padding:4px 12px;font-size:0.78rem;font-weight:600;">
                            <i class="bi bi-clipboard2-pulse"></i> <?php echo e($r['service_name']); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($r['doctor_name']): ?>
                        <span style="background:var(--success-bg);color:var(--success);border:1px solid var(--success-border);border-radius:7px;padding:4px 12px;font-size:0.78rem;font-weight:600;">
                            <i class="bi bi-person-badge"></i> <?php echo e($r['doctor_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Chief Complaint -->
                    <?php if ($r['chief_complaint']): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Chief Complaint</div>
                        <div style="font-size:0.88rem;color:var(--gray-700);font-style:italic;">"<?php echo e($r['chief_complaint']); ?>"</div>
                    </div>
                    <?php endif; ?>

                    <!-- Tooth Chart (read-only visual) -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:8px;">
                            Tooth Chart
                            <?php if ($r['tooth_number']): ?>
                            <span style="margin-left:8px;font-size:0.72rem;color:var(--blue-500);text-transform:none;letter-spacing:0;font-weight:500;">
                                Selected: <?php echo e($r['tooth_number']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php
                        // Parse selected teeth — filter empty/dash values
                        $raw_teeth = array_map('trim', explode(',', $r['tooth_number'] ?? ''));
                        $selected_teeth = array_filter($raw_teeth, fn($t) => $t !== '' && $t !== '—' && $t !== '-');

                        // SVG tooth helpers (same as add.php)
                        function vToothType(int $n): string {
                            if (in_array($n, [18,17,16,28,27,26,38,37,36,48,47,46])) return 'molar';
                            if (in_array($n, [15,14,25,24,35,34,45,44]))              return 'premolar';
                            if (in_array($n, [13,23,33,43]))                          return 'canine';
                            return 'incisor';
                        }
                        function vToothSVG(string $type, string $jaw, bool $sel, array $ti): string {
                            $crown_fill   = $sel ? $ti['bg']     : '#E2E8F0';
                            $crown_stroke = $sel ? $ti['border'] : '#94A3B8';
                            $root_fill    = $sel ? $ti['bg']     : '#CBD5E1';
                            $root_stroke  = $sel ? $ti['border'] : '#94A3B8';
                            // Use inline styles for simplicity per tooth
                            $c = "fill:{$crown_fill};stroke:{$crown_stroke};stroke-width:0.8;";
                            $ro = "fill:{$root_fill};stroke:{$root_stroke};stroke-width:0.8;";
                            if ($jaw === 'upper') {
                                return match($type) {
                                    'molar'   => "<rect x='-10' y='14' width='9'  height='26' rx='2.5' style='{$ro}'/><rect x='1' y='14' width='9' height='26' rx='2.5' style='{$ro}'/><rect x='-13' y='36' width='26' height='26' rx='4' style='{$c}'/>",
                                    'premolar'=> "<rect x='-6.5' y='14' width='7' height='26' rx='2.5' style='{$ro}'/><rect x='1' y='14' width='7' height='26' rx='2.5' style='{$ro}'/><rect x='-11' y='36' width='22' height='26' rx='4' style='{$c}'/>",
                                    'canine'  => "<rect x='-3.5' y='12' width='7' height='28' rx='2.5' style='{$ro}'/><path d='M-8,38 L8,38 L6.5,54 Q0,64 -6.5,54 Z' style='{$c}'/>",
                                    default   => "<rect x='-3.5' y='14' width='7' height='26' rx='2.5' style='{$ro}'/><rect x='-9' y='38' width='18' height='24' rx='4' style='{$c}'/>",
                                };
                            } else {
                                return match($type) {
                                    'molar'   => "<rect x='-13' y='78' width='26' height='26' rx='4' style='{$c}'/><rect x='-10' y='102' width='9' height='26' rx='2.5' style='{$ro}'/><rect x='1' y='102' width='9' height='26' rx='2.5' style='{$ro}'/>",
                                    'premolar'=> "<rect x='-11' y='78' width='22' height='26' rx='4' style='{$c}'/><rect x='-6.5' y='102' width='7' height='26' rx='2.5' style='{$ro}'/><rect x='1' y='102' width='7' height='26' rx='2.5' style='{$ro}'/>",
                                    'canine'  => "<path d='M-8,100 L8,100 L6.5,84 Q0,76 -6.5,84 Z' style='{$c}'/><rect x='-3.5' y='100' width='7' height='28' rx='2.5' style='{$ro}'/>",
                                    default   => "<rect x='-9' y='78' width='18' height='24' rx='4' style='{$c}'/><rect x='-3.5' y='100' width='7' height='26' rx='2.5' style='{$ro}'/>",
                                };
                            }
                        }
                        function vToothCX(int $i): int {
                            if ($i < 8) return 30 + $i * 38;
                            return 344 + ($i - 8) * 38;
                        }
                        $vUpper   = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
                        $vLower   = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
                        $vPrimU   = ['55','54','53','52','51','61','62','63','64','65'];
                        $vPrimL   = ['85','84','83','82','81','71','72','73','74','75'];
                        ?>
                        <div class="tooth-chart-scroll" style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:14px 10px;overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <!-- SVG Odontogram — same layout as add.php -->
                            <div style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <svg viewBox="0 0 640 148" width="640" height="148" style="display:block;margin:0 auto;max-width:100%;min-width:min(520px,100%);">
                                <text x="320" y="10" text-anchor="middle" font-size="8" fill="#94A3B8" font-family="'Outfit',sans-serif" font-weight="700" letter-spacing="1.5">UPPER JAW</text>
                                <?php foreach ($vUpper as $i => $tn):
                                    $sel = in_array((string)$tn, $selected_teeth);
                                ?>
                                <g data-tooth="<?php echo $tn; ?>" transform="translate(<?php echo vToothCX($i); ?>,0)">
                                    <?php echo vToothSVG(vToothType($tn), 'upper', $sel, $ts_info); ?>
                                    <text x="0" y="10" text-anchor="middle" font-size="7.5" font-family="'Outfit',sans-serif" font-weight="600" fill="<?php echo $sel ? $ts_info['color'] : '#64748B'; ?>"><?php echo $tn; ?></text>
                                </g>
                                <?php endforeach; ?>
                                <line x1="320" y1="24" x2="320" y2="126" stroke="#E2E8F0" stroke-width="1" stroke-dasharray="2 3" opacity="0.7"/>
                                <rect x="0" y="66" width="640" height="16" fill="#F8FAFC" opacity="0.85"/>
                                <line x1="8" y1="68" x2="632" y2="68" stroke="#CBD5E1" stroke-width="1.2" stroke-dasharray="5 4"/>
                                <line x1="8" y1="78" x2="632" y2="78" stroke="#CBD5E1" stroke-width="1.2" stroke-dasharray="5 4"/>
                                <text x="320" y="76" text-anchor="middle" font-size="7.5" fill="#CBD5E1" font-family="'Outfit',sans-serif" font-weight="600" letter-spacing="2">GUM LINE</text>
                                <?php foreach ($vLower as $i => $tn):
                                    $sel = in_array((string)$tn, $selected_teeth);
                                ?>
                                <g data-tooth="<?php echo $tn; ?>" transform="translate(<?php echo vToothCX($i); ?>,0)">
                                    <?php echo vToothSVG(vToothType($tn), 'lower', $sel, $ts_info); ?>
                                    <text x="0" y="138" text-anchor="middle" font-size="7.5" font-family="'Outfit',sans-serif" font-weight="600" fill="<?php echo $sel ? $ts_info['color'] : '#64748B'; ?>"><?php echo $tn; ?></text>
                                </g>
                                <?php endforeach; ?>
                                <text x="320" y="148" text-anchor="middle" font-size="8" fill="#94A3B8" font-family="'Outfit',sans-serif" font-weight="700" letter-spacing="1.5">LOWER JAW</text>
                                <text x="155" y="10" text-anchor="middle" font-size="7" fill="#CBD5E1" font-family="'Outfit',sans-serif">← Patient Right</text>
                                <text x="485" y="10" text-anchor="middle" font-size="7" fill="#CBD5E1" font-family="'Outfit',sans-serif">Patient Left →</text>
                            </svg>
                            </div>
                            <!-- Primary / Deciduous teeth -->
                            <div style="margin-top:12px;padding-top:10px;border-top:1px solid var(--gray-200);">
                                <div style="text-align:center;font-size:0.7rem;color:var(--gray-400);margin-bottom:7px;font-weight:600;letter-spacing:0.05em;">PRIMARY / DECIDUOUS TEETH</div>
                                <div style="display:flex;justify-content:center;gap:4px;flex-wrap:wrap;">
                                <?php foreach (array_merge($vPrimU, $vPrimL) as $pt):
                                    $sel = in_array($pt, $selected_teeth);
                                ?>
                                <div title="Primary <?php echo $pt; ?>" style="width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.6rem;font-weight:600;flex-shrink:0;background:<?php echo $sel ? $ts_info['bg'] : 'var(--gray-200)'; ?>;border:1px solid <?php echo $sel ? $ts_info['border'] : 'var(--gray-300)'; ?>;color:<?php echo $sel ? $ts_info['color'] : 'var(--gray-500)'; ?>;"><?php echo $pt; ?></div>
                                <?php endforeach; ?>
                                </div>
                            </div>
                            <!-- Legend -->
                            <div style="display:flex;gap:14px;justify-content:center;margin-top:10px;font-size:0.65rem;color:var(--gray-400);">
                                <?php if (!empty($selected_teeth)): ?>
                                <span><span style="display:inline-block;width:8px;height:8px;background:<?php echo $ts_info['bg']; ?>;border:1px solid <?php echo $ts_info['border']; ?>;border-radius:2px;margin-right:3px;"></span><?php echo htmlspecialchars($ts_info['label']); ?></span>
                                <?php endif; ?>
                                <span><span style="display:inline-block;width:8px;height:8px;background:#E2E8F0;border:1px solid #94A3B8;border-radius:2px;margin-right:3px;"></span>Normal</span>
                                <span><span style="display:inline-block;width:8px;height:8px;background:#E2E8F0;border:1px solid #94A3B8;border-radius:50%;margin-right:3px;"></span>Primary</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tooth Condition -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Tooth Condition</div>
                        <span style="background:<?php echo $tsc['bg']; ?>;color:<?php echo $tsc['color']; ?>;border:1px solid <?php echo $tsc['border']; ?>;border-radius:7px;padding:3px 10px;font-size:0.78rem;font-weight:600;">
                            <?php echo $tsc['label']; ?>
                        </span>
                    </div>

                    <!-- Diagnosis -->
                    <?php if ($r['diagnosis']): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Diagnosis</div>
                        <div style="font-size:0.85rem;color:var(--gray-700);line-height:1.6;"><?php echo e($r['diagnosis']); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Treatment Done -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Treatment Done</div>
                        <div class="treatment-done-box" style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:8px;padding:10px 12px;font-size:0.85rem;color:var(--gray-800);line-height:1.6;">
                            <?php echo e($r['treatment_done']); ?>
                        </div>
                    </div>

                    <!-- Medications + Next Visit -->
                    <div class="dental-meds-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Medications Prescribed</div>
                            <div style="font-size:0.82rem;color:var(--gray-700);">
                                <?php echo $r['medications_prescribed'] ? e($r['medications_prescribed']) : '<span style="color:var(--gray-400);">None</span>'; ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Next Visit Notes</div>
                            <div style="font-size:0.82rem;color:var(--gray-700);">
                                <?php echo $r['next_visit_notes'] ? e($r['next_visit_notes']) : '<span style="color:var(--gray-400);">None</span>'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Footer meta -->
                    <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--gray-100);font-size:0.73rem;color:var(--gray-400);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
                        <span><i class="bi bi-person-fill"></i> Recorded by: <?php echo e($r['recorded_by_name'] ?? '—'); ?></span>
                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?></span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Patient Summary -->
            <div style="display:flex;flex-direction:column;gap:18px;">

                <!-- Patient quick info -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-person-circle" style="color:var(--blue-500);margin-right:6px;"></i>
                        Patient
                    </div>
                    <div class="card-body" style="padding:16px 22px;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
                            <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#5a8fff);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;flex-shrink:0;">
                                <?php echo strtoupper(substr($r['patient_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:0.95rem;"><?php echo e($r['patient_name']); ?></div>
                                <div style="font-size:0.78rem;color:var(--blue-500);"><?php echo e($r['patient_code']); ?></div>
                                <?php if ($r['date_of_birth']): ?>
                                <div style="font-size:0.75rem;color:var(--gray-400);">
                                    <?php
                                    $dob = new DateTime($r['date_of_birth']);
                                    $age = (new DateTime())->diff($dob)->y;
                                    echo $age . ' years old · ' . ucfirst($r['gender'] ?? '');
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="patient-action-btns" style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-person"></i> Full Profile
                            </a>
                            <?php if (!empty($other_records)): ?>
                            <a href="list.php?patient_id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-journal-medical"></i> All Records
                            </a>
                            <?php endif; ?>
                            <a href="add.php?patient_id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-plus"></i> Add Record
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Other dental records for this patient -->
                <?php if (!empty($other_records)): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-clock-history" style="color:var(--blue-500);margin-right:6px;"></i>
                        Previous Records
                        <span style="margin-left:auto;font-size:0.73rem;color:var(--gray-400);">Most recent 5</span>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($other_records as $or): ?>
                        <a href="view.php?id=<?php echo $or['id']; ?>" style="display:block;padding:11px 18px;border-bottom:1px solid var(--gray-100);text-decoration:none;transition:background 0.15s;"
                           onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background=''">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <div>
                                    <div style="font-size:0.82rem;font-weight:600;color:var(--gray-800);">
                                        <?php echo e($or['service_name'] ?? 'Treatment'); ?>
                                        <?php if ($or['tooth_number']): ?>
                                            <span style="font-size:0.72rem;color:var(--gray-400);">· Tooth <?php echo e($or['tooth_number']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:0.75rem;color:var(--gray-500);margin-top:1px;">
                                        <?php echo strlen($or['treatment_done']) > 55 ? substr(e($or['treatment_done']), 0, 55) . '…' : e($or['treatment_done']); ?>
                                    </div>
                                </div>
                                <div style="font-size:0.72rem;color:var(--gray-400);white-space:nowrap;flex-shrink:0;">
                                    <?php echo date('M d, Y', strtotime($or['visit_date'])); ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
