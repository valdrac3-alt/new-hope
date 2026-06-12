<?php
// Book Appointment — redirected to the New Appointment drawer on the appointments list.
// The drawer handles both walk-ins (today) and advance bookings (future date).
require_once '../../includes/config.php';
header('Location: ' . BASE_URL . 'modules/appointments/list.php?walkin=1');
exit();

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Book Appointment';

$error   = '';
$success = '';

$patients = sc_get('pat_add') ?? sc_set('pat_add', $conn->query("SELECT id, patient_code, first_name, last_name FROM patients WHERE is_active = TRUE ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC), 300);
$services = sc_get('svc_add') ?? sc_set('svc_add', $conn->query("SELECT id, service_name, duration_minutes, price FROM services WHERE is_active = TRUE ORDER BY service_name ASC")->fetchAll(PDO::FETCH_ASSOC), 300);
$doctors  = sc_get('doc_add') ?? sc_set('doc_add', $conn->query("SELECT id, full_name, specialization, photo_url FROM doctors WHERE is_active = TRUE ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC), 300);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $patient_id = intval($_POST['patient_id'] ?? 0);
    $service_id = intval($_POST['service_id'] ?? 0);
    $doctor_id  = intval($_POST['doctor_id']  ?? 0) ?: null;
    $appt_date  = trim($_POST['appointment_date'] ?? '');
    $appt_time  = trim($_POST['appointment_time'] ?? '');
    $type       = 'walk-in';
    $notes      = trim($_POST['notes'] ?? '');

    if (!$patient_id || !$appt_date || !$appt_time) {
        $error = 'Patient, date, and time are required.';
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $appt_date) || !strtotime($appt_date)) {
        $error = 'Invalid date format.';
    } elseif ($appt_date < date('Y-m-d')) {
        $error = 'Appointment date cannot be in the past.';
    } elseif (strlen($notes) > 500) {
        $error = 'Notes must be 500 characters or fewer.';
    } else {
        // Resolve the duration of the service being booked (default 30 min if none selected).
        $new_duration = 30;
        if ($service_id) {
            $svc_stmt = $conn->prepare("SELECT duration_minutes FROM services WHERE id = ? LIMIT 1");
            $svc_stmt->execute([$service_id]);
            $svc_row = $svc_stmt->fetch(PDO::FETCH_ASSOC);
            $svc_stmt->close();
            if ($svc_row) $new_duration = intval($svc_row['duration_minutes']);
        }

        // Check for time-overlap conflicts per doctor (if doctor selected) or clinic-wide.
        $new_start_ts = strtotime($appt_date . ' ' . $appt_time);
        $new_end_ts   = $new_start_ts + ($new_duration * 60);

        if ($doctor_id) {
            $conf_stmt = $conn->prepare("
                SELECT a.appointment_time,
                       COALESCE(s.duration_minutes, 30) AS duration_minutes
                FROM   appointments a
                LEFT JOIN services s ON s.id = a.service_id
                WHERE  a.appointment_date = ?
                AND    a.doctor_id = ?
                AND    a.status NOT IN ('cancelled', 'no-show')
            ");
        } else {
            $conf_stmt = $conn->prepare("
                SELECT a.appointment_time,
                       COALESCE(s.duration_minutes, 30) AS duration_minutes
                FROM   appointments a
                LEFT JOIN services s ON s.id = a.service_id
                WHERE  a.appointment_date = ?
                AND    a.status NOT IN ('cancelled', 'no-show')
            ");
        }
        $conf_stmt->execute($doctor_id ? [$appt_date, $doctor_id] : [$appt_date]);
        $existing_appts = $conf_stmt->fetchAll(PDO::FETCH_ASSOC);
        $conf_stmt->closeCursor();

        $conflict = false;
        foreach ($existing_appts as $ex) {
            $ex_start = strtotime($appt_date . ' ' . $ex['appointment_time']);
            $ex_end   = $ex_start + (intval($ex['duration_minutes']) * 60);
            if ($new_start_ts < $ex_end && $ex_start < $new_end_ts) {
                $conflict = true;
                break;
            }
        }

        if ($conflict) {
            $error = 'That time slot overlaps with an existing appointment. Please choose another time.';
        } else {
            $appt_code = generate_code($conn, 'appointments', 'APT');
            $stmt = $conn->prepare("
                INSERT INTO appointments
                (appointment_code, patient_id, service_id, doctor_id, appointment_date, appointment_time, type, notes, handled_by)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            if ($stmt->execute([$appt_code, $patient_id, $service_id, $doctor_id, $appt_date, $appt_time, $type, $notes, $current_user_id])) {
                $new_id = $conn->lastInsertId();
                log_action($conn, $current_user_id, $current_user_name, 'Booked Appointment', 'appointments', $new_id, "Appointment: $appt_code on $appt_date $appt_time");

                // ── Notification trigger ──────────────────────────────
                $pat_stmt = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as name FROM patients WHERE id = ? LIMIT 1");
                $pat_stmt->execute([$patient_id]);
                $pat_name = $pat_stmt->fetch(PDO::FETCH_ASSOC)['name'] ?? 'Patient';
                $pat_stmt->close();
                notify($conn, 'appointment',
                    'New Appointment Booked',
                    "$pat_name booked for " . date('M d, Y', strtotime($appt_date)) . ' at ' . date('h:i A', strtotime($appt_time)) . '.',
                    'modules/appointments/list.php'
                );
                // ─────────────────────────────────────────────────────

                $success = "Appointment $appt_code booked successfully.";
            } else {
                $error = 'Failed to book appointment. Please try again.';
            }
            $stmt->close();
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

        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5>Book Appointment</h5>
            <a href="list.php" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Patient <span class="text-danger">*</span></label>
                            <!-- Searchable patient autocomplete -->
                            <div style="position:relative;">
                                <input type="text" id="patient_search_input" class="form-control"
                                    placeholder="Type name or patient code..."
                                    autocomplete="off"
                                    value="<?php
                                        if ($pre_patient_id ?? 0) {
                                            foreach ($patients as $pp) {
                                                if ($pp['id'] == ($pre_patient_id ?? 0)) {
                                                    echo htmlspecialchars(ucwords(strtolower($pp['last_name'])).', '.ucwords(strtolower($pp['first_name'])).' ('.$pp['patient_code'].')');
                                                }
                                            }
                                        }
                                    ?>">
                                <input type="hidden" name="patient_id" id="patient_id_hidden"
                                    value="<?php echo $pre_patient_id ?? ''; ?>" required>
                                <div id="patient_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;z-index:1000;background:var(--white);border:1px solid var(--gray-200);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-height:220px;overflow-y:auto;margin-top:2px;"></div>
                            </div>
                            <div id="patient_selected_badge" style="display:none;margin-top:6px;"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Service</label>
                            <select name="service_id" class="form-select" id="service_select">
                                <option value="">Select Service</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" data-price="<?php echo $s['price']; ?>">
                                        <?php echo htmlspecialchars($s['service_name']); ?> — ₱<?php echo number_format($s['price'], 2); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Doctor</label>
                            <select name="doctor_id" class="form-select" id="doctor_select">
                                <option value="">Any Available Doctor</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?php echo $d['id']; ?>">
                                        <?php echo htmlspecialchars($d['full_name']); ?>
                                        <?php if ($d['specialization']): ?> — <?php echo htmlspecialchars($d['specialization']); ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Slots shown will be filtered by the selected doctor's availability.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" name="appointment_date" id="appt_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Time <span class="text-danger">*</span></label>
                            <select name="appointment_time" id="appt_time" class="form-select" required>
                                <option value="">Select date first</option>
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Patient Notes / Complaint</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Describe the patient's concern..."></textarea>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">Book Appointment</button>
                        <a href="list.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script>
function loadSlots(date) {
    var doctorId = document.getElementById('doctor_select').value;
    var select   = document.getElementById('appt_time');
    select.innerHTML = '<option value="">Loading slots...</option>';

    var url = '<?php echo BASE_URL; ?>api/appointments.php?action=get_slots&date=' + date;
    if (doctorId) url += '&doctor_id=' + doctorId;

    fetch(url)
    .then(res => res.json())
    .then(data => {
        select.innerHTML = '<option value="">Select time</option>';
        if (data.slots && data.slots.length > 0) {
            data.slots.forEach(function(slot) {
                var opt = document.createElement('option');
                opt.value = slot.time_24;
                opt.textContent = slot.time_12 + (slot.available ? '' : ' (Booked)');
                opt.disabled = !slot.available;
                select.appendChild(opt);
            });
        } else {
            var msg = data.message || 'No slots available for this date';
            select.innerHTML = '<option value="">' + msg + '</option>';
        }
    });
}

// Reload slots when date or doctor changes
document.getElementById('appt_date').addEventListener('change', function() {
    if (this.value) loadSlots(this.value);
});
document.getElementById('doctor_select').addEventListener('change', function() {
    var date = document.getElementById('appt_date').value;
    if (date) loadSlots(date);
});
</script>
<script>
// Patient autocomplete
var patientData = <?php echo json_encode(array_map(function($p) {
    $first = ucwords(strtolower($p['first_name']));
    $last  = ucwords(strtolower($p['last_name']));
    return [
        'id'       => $p['id'],
        'label'    => $last.', '.$first.' ('.$p['patient_code'].')',
        'name_fwd' => $first.' '.$last,
        'code'     => strtolower($p['patient_code']),
        'display'  => $last.', '.$first,
    ];
}, $patients)); ?>;

var pInput   = document.getElementById('patient_search_input');
var pHidden  = document.getElementById('patient_id_hidden');
var pDrop    = document.getElementById('patient_dropdown');
var pBadge   = document.getElementById('patient_selected_badge');

function renderDropdown(results) {
    if (!results.length) {
        pDrop.innerHTML = '<div style="padding:10px 14px;color:var(--gray-400);font-size:0.85rem;">No patients found</div>';
    } else {
        pDrop.innerHTML = results.map(function(r) {
            return '<div class="patient-option" data-id="'+r.id+'" data-label="'+r.label+'" style="padding:9px 14px;cursor:pointer;font-size:0.875rem;border-bottom:1px solid var(--gray-100);transition:background 0.1s;">'
                + '<strong>'+r['display']+'</strong> <span style="color:var(--gray-400);font-size:0.8rem;">('+r.code.toUpperCase()+')</span></div>';
        }).join('');
        pDrop.querySelectorAll('.patient-option').forEach(function(el) {
            el.addEventListener('mouseenter', function(){ this.style.background='var(--gray-50)'; });
            el.addEventListener('mouseleave', function(){ this.style.background=''; });
            el.addEventListener('mousedown', function(e) {
                e.preventDefault();
                selectPatient(this.dataset.id, this.dataset.label);
            });
        });
    }
    pDrop.style.display = 'block';
}

function selectPatient(id, label) {
    pHidden.value = id;
    pInput.value  = label;
    pDrop.style.display = 'none';
    pBadge.style.display = 'none';
    pInput.style.borderColor = 'var(--success)';
    var dateInput = document.querySelector('[name="appointment_date"]');
    if (dateInput && dateInput.value) loadSlots(dateInput.value);
}

pInput.addEventListener('input', function() {
    var q = this.value.trim().toLowerCase();
    pHidden.value = '';
    pInput.style.borderColor = '';
    if (!q) { pDrop.style.display = 'none'; return; }
    var results = patientData.filter(function(r) {
        return r.label.toLowerCase().includes(q)
            || r.name_fwd.toLowerCase().includes(q)
            || r.code.includes(q);
    }).slice(0, 8);
    renderDropdown(results);
});

pInput.addEventListener('focus', function() {
    if (this.value.trim()) {
        var q = this.value.trim().toLowerCase();
        var results = patientData.filter(function(r) {
            return r.label.toLowerCase().includes(q)
                || r.name_fwd.toLowerCase().includes(q)
                || r.code.includes(q);
        }).slice(0, 8);
        if (results.length) renderDropdown(results);
    }
});

document.addEventListener('click', function(e) {
    if (!e.target.closest('#patient_search_input') && !e.target.closest('#patient_dropdown')) {
        pDrop.style.display = 'none';
        // If user typed but didn't select, clear hidden field
        if (!pHidden.value && pInput.value) {
            pInput.style.borderColor = 'var(--danger)';
        }
    }
});

pInput.addEventListener('keydown', function(e) {
    var items = pDrop.querySelectorAll('.patient-option');
    var active = pDrop.querySelector('.patient-option.active');
    if (e.key === 'ArrowDown') {
        e.preventDefault();
        var next = active ? active.nextElementSibling : items[0];
        if (active) active.classList.remove('active'), active.style.background = '';
        if (next) next.classList.add('active'), next.style.background = 'var(--gray-50)';
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        var prev = active ? active.previousElementSibling : items[items.length-1];
        if (active) active.classList.remove('active'), active.style.background = '';
        if (prev) prev.classList.add('active'), prev.style.background = 'var(--gray-50)';
    } else if (e.key === 'Enter' && active) {
        e.preventDefault();
        selectPatient(active.dataset.id, active.textContent.trim());
    } else if (e.key === 'Escape') {
        pDrop.style.display = 'none';
    }
});
</script>
</body>
</html>
