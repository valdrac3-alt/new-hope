<?php
// Today's queue: who's currently in the chair, and who's waiting next.
// Pulls from the same appointments table used by both the Appointment
// drawer and the Walk-in page — both feed this view automatically.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Queue';
$today = date('Y-m-d');

// In chair right now: confirmed, started, not finished.
$in_chair_stmt = $conn->prepare("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, s.duration_minutes, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    WHERE a.appointment_date = ? AND a.status = 'confirmed' AND a.started_at IS NOT NULL AND a.finished_at IS NULL
    ORDER BY a.started_at ASC
");
$in_chair_stmt->execute([$today]);
$in_chair = $in_chair_stmt->fetchAll(PDO::FETCH_ASSOC);
$in_chair_stmt->closeCursor();

// Waiting: confirmed, not started yet. Ordered by planned slot time —
// the only stable signal that works the same for walk-ins (auto-assigned
// to the next free slot when they registered) and pre-booked appointments.
$waiting_stmt = $conn->prepare("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, s.duration_minutes, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    WHERE a.appointment_date = ? AND a.status = 'confirmed' AND a.started_at IS NULL
    ORDER BY a.appointment_time ASC
");
$waiting_stmt->execute([$today]);
$waiting = $waiting_stmt->fetchAll(PDO::FETCH_ASSOC);
$waiting_stmt->closeCursor();

// Still pending (not yet confirmed) — shown separately so staff knows
// these people haven't been checked in yet and won't appear in the queue
// until someone clicks "Confirm" on the Appointments page.
$pending_stmt = $conn->prepare("
    SELECT COUNT(*) as c FROM appointments
    WHERE appointment_date = ? AND status = 'pending'
");
$pending_stmt->execute([$today]);
$pending_count = (int)$pending_stmt->fetch(PDO::FETCH_ASSOC)['c'];
$pending_stmt->closeCursor();

// Schedule drift: compare where we'd "be" by planned time vs. actual.
// If the currently in-chair appointment's planned time is earlier than
// now, and it's still going, we're running behind by that much.
$now = time();
$drift_minutes = null;
if (!empty($in_chair)) {
    $first = $in_chair[0];
    $planned = strtotime($today . ' ' . $first['appointment_time']);
    $drift_minutes = round(($now - $planned) / 60);
}

?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
.queue-card {
    background: var(--white);
    border: var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 14px;
    flex-wrap: wrap;
}
.queue-pos {
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 0.95rem;
}
.queue-empty {
    text-align: center;
    padding: 32px 16px;
    color: var(--gray-400);
    font-size: 0.85rem;
}
</style>
</head>
<body>
    <?php include '../../includes/sidebar.php'; ?>
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">
        <?php echo csrf_field(); ?>

        <div class="page-header" style="margin-bottom:16px;">
            <div>
                <h5 style="margin-bottom:2px;"><i class="bi bi-people-fill"></i> Today's Queue</h5>
                <small class="text-muted"><?php echo date('l, M d, Y', strtotime($today)); ?></small>
            </div>
            <div style="margin-left:auto;display:flex;gap:8px;">
                <a href="list.php?status=pending&date=<?php echo $today; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-clock"></i> <?php echo $pending_count; ?> awaiting confirmation
                </a>
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list-ul"></i> Full List
                </a>
            </div>
        </div>

        <?php if ($drift_minutes !== null && $drift_minutes >= 10): ?>
        <div class="alert alert-warning" style="display:flex;align-items:center;gap:10px;">
            <i class="bi bi-clock-history" style="font-size:1.1rem;"></i>
            <span>Running about <strong><?php echo $drift_minutes; ?> min behind</strong> the planned schedule — the patient in chair was due at <?php echo date('h:i A', strtotime($in_chair[0]['appointment_time'])); ?>.</span>
        </div>
        <?php elseif ($drift_minutes !== null && $drift_minutes <= -10): ?>
        <div class="alert alert-success" style="display:flex;align-items:center;gap:10px;">
            <i class="bi bi-rocket-takeoff"></i>
            <span>Running about <strong><?php echo abs($drift_minutes); ?> min ahead</strong> of schedule. The next waiting patient can likely be seen early.</span>
        </div>
        <?php endif; ?>

        <!-- In Chair -->
        <div style="margin-bottom:8px;font-size:0.78rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.5px;">
            <i class="bi bi-hourglass-split" style="color:var(--primary);"></i> In Chair Now
        </div>
        <?php if (empty($in_chair)): ?>
        <div class="queue-card queue-empty">No one is currently in the chair. Tap "Start" on a confirmed appointment to begin.</div>
        <?php else: foreach ($in_chair as $a):
            $elapsed = round(($now - strtotime($a['started_at'])) / 60);
            $planned_dur = (int)($a['duration_minutes'] ?? 30);
            $over = $elapsed > $planned_dur;
        ?>
        <div class="queue-card" style="border-color:var(--blue-200);background:var(--primary-bg);">
            <div class="queue-pos" style="background:var(--primary);color:var(--white);"><i class="bi bi-person-fill"></i></div>
            <div style="flex:1;min-width:160px;">
                <div style="font-weight:700;color:var(--gray-900);"><?php echo htmlspecialchars($a['patient_name']); ?></div>
                <div style="font-size:0.78rem;color:var(--gray-500);">
                    <?php echo htmlspecialchars($a['service_name'] ?? 'No service specified'); ?>
                    <?php if ($a['doctor_name']): ?> · <?php echo htmlspecialchars($a['doctor_name']); ?><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.78rem;color:var(--gray-500);">Started <?php echo date('h:i A', strtotime($a['started_at'])); ?></div>
                <div style="font-size:0.85rem;font-weight:700;color:<?php echo $over ? 'var(--warning)' : 'var(--primary)'; ?>;">
                    <?php echo $elapsed; ?> min<?php echo $over ? ' (over planned ' . $planned_dur . ' min)' : ' / ' . $planned_dur . ' min'; ?>
                </div>
            </div>
            <a href="<?php echo BASE_URL; ?>modules/treatments/add.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
               class="btn btn-sm btn-success">
                <i class="bi bi-check2-circle"></i> Finish &amp; Record
            </a>
        </div>
        <?php endforeach; endif; ?>

        <!-- Waiting -->
        <div style="margin:18px 0 8px;font-size:0.78rem;font-weight:700;color:var(--gray-500);text-transform:uppercase;letter-spacing:0.5px;">
            <i class="bi bi-list-ol" style="color:var(--gray-400);"></i> Waiting
            <span style="font-weight:500;text-transform:none;letter-spacing:normal;">— ordered by planned time</span>
        </div>
        <?php if (empty($waiting)): ?>
        <div class="queue-card queue-empty">No one is waiting. Confirmed appointments not yet started will appear here.</div>
        <?php else: foreach ($waiting as $i => $a):
            $planned = strtotime($today . ' ' . $a['appointment_time']);
            $diff = round(($now - $planned) / 60);
        ?>
        <div class="queue-card">
            <div class="queue-pos" style="background:var(--gray-100);color:var(--gray-600);"><?php echo $i + 1; ?></div>
            <div style="flex:1;min-width:160px;">
                <div style="font-weight:700;color:var(--gray-900);"><?php echo htmlspecialchars($a['patient_name']); ?></div>
                <div style="font-size:0.78rem;color:var(--gray-500);">
                    <?php echo htmlspecialchars($a['service_name'] ?? 'No service specified'); ?>
                    <?php if ($a['doctor_name']): ?> · <?php echo htmlspecialchars($a['doctor_name']); ?><?php endif; ?>
                    <?php if ($a['type'] === 'walk-in'): ?> · <span style="color:var(--gray-400);">Walk-in</span><?php endif; ?>
                </div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:0.78rem;color:var(--gray-500);">Planned <?php echo date('h:i A', strtotime($a['appointment_time'])); ?></div>
                <?php if ($diff >= 5): ?>
                <div style="font-size:0.78rem;font-weight:700;color:var(--warning);">Waiting ~<?php echo $diff; ?> min</div>
                <?php elseif ($diff < -5): ?>
                <div style="font-size:0.78rem;font-weight:700;color:var(--success);">Early by <?php echo abs($diff); ?> min</div>
                <?php endif; ?>
            </div>
            <?php if (empty($in_chair) && $i === 0): ?>
            <button onclick="startAppointment(<?php echo $a['id']; ?>, this)" class="btn btn-sm btn-primary">
                <i class="bi bi-play-fill"></i> Start
            </button>
            <?php endif; ?>
        </div>
        <?php endforeach; endif; ?>

        <div style="margin-top:18px;font-size:0.72rem;color:var(--gray-400);">
            <i class="bi bi-info-circle"></i> Appointments must be <strong>Confirmed</strong> on the
            <a href="list.php?status=pending&date=<?php echo $today; ?>">Appointments</a> page before they show up here.
            Walk-ins and advance bookings both appear in this same queue once confirmed.
        </div>

    </div>
<?php include '../../includes/footer.php'; ?>
<script>
var _phpBaseUrl = '<?php echo BASE_URL; ?>';
var _baseUrl=(function(){
    try {
        var parsed=new URL(_phpBaseUrl);
        if(parsed.hostname===window.location.hostname){
            return window.location.protocol+'//'+window.location.host+parsed.pathname;
        }
    } catch(e){}
    var p=window.location.pathname.replace(/\/modules\/.*$/,'/');
    return window.location.protocol+'//'+window.location.host+p;
})();
function getCsrfToken() {
    var el = document.querySelector('input[name="_csrf"]');
    return el ? el.value : '';
}
function startAppointment(id, btn) {
    btn.disabled = true;
    var orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Starting...';
    fetch(_baseUrl+'api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'start_appointment', id: id, _csrf: getCsrfToken() })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') {
            location.reload();
        } else {
            btn.disabled = false;
            btn.innerHTML = orig;
            alert(data.message || 'Could not start appointment.');
        }
    })
    .catch(function () {
        btn.disabled = false;
        btn.innerHTML = orig;
        alert('Network error. Please try again.');
    });
}
</script>
</body>
</html>
