<?php
// List all active patients with search, gender/blood type filters, stats bar, and age column.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Patient Records';

// ── Soft-delete (archive) ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    validate_csrf();
    $del_id = secure_int($_POST['delete_id']);
    if ($del_id) {
        $nr = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as n FROM patients WHERE id = ? LIMIT 1");
        $nr->execute([$del_id]);
        $pname = $nr->fetch(PDO::FETCH_ASSOC)['n'] ?? 'Unknown';
        $nr->close();
        $stmt = $conn->prepare("UPDATE patients SET is_active = FALSE WHERE id = ?");
        $stmt->execute([$del_id]);
        $stmt->close();
        log_action($conn, $current_user_id, $current_user_name, 'Archived Patient', 'patients', $del_id, "Soft-deleted (archived): $pname — all records preserved.");
    }
    header('Location: list.php');
    exit();
}

$search     = trim($_GET['search'] ?? '');
$gender_f   = trim($_GET['gender'] ?? '');
$blood_f    = trim($_GET['blood'] ?? '');
$per_page   = 20;
$page       = max(1, intval($_GET['page'] ?? 1));

$params = [];
$base_where = "WHERE p.is_active = TRUE";

if ($search) {
    $like = '%' . $search . '%';
    $base_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ? OR p.phone LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
}
if ($gender_f && in_array($gender_f, ['male','female','other'])) {
    $base_where .= " AND p.gender = ?";
    $params[] = $gender_f;
}
if ($blood_f) {
    $base_where .= " AND p.blood_type = ?";
    $params[] = $blood_f;
}

// COUNT
$count_stmt = $conn->prepare("SELECT COUNT(*) as c FROM patients p $base_where");
$count_stmt->execute($params);
$total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['c'];
$count_stmt->close();

$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_parts = [];
if ($search)   $filter_parts[] = 'search=' . urlencode($search);
if ($gender_f) $filter_parts[] = 'gender=' . urlencode($gender_f);
if ($blood_f)  $filter_parts[] = 'blood='  . urlencode($blood_f);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

// Main list — includes DOB for age calculation
$list_stmt = $conn->prepare("
    SELECT p.*, COUNT(a.id) as total_visits
    FROM patients p
    LEFT JOIN appointments a ON a.patient_id = p.id AND a.status = 'completed'
    $base_where
    GROUP BY p.id
    ORDER BY p.last_name ASC, p.first_name ASC
    LIMIT $per_page OFFSET $offset
");
$list_stmt->execute($params);
$patients = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
$list_stmt->close();

// Stats bar (always across all active patients, not filtered)
$stats_row = $conn->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as males,
        SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as females,
        SUM(CASE WHEN created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') THEN 1 ELSE 0 END) as new_this_month,
        SUM(CASE WHEN is_incomplete = 1 THEN 1 ELSE 0 END) as incomplete_count
    FROM patients WHERE is_active = TRUE
")->fetch(PDO::FETCH_ASSOC);

// Distinct blood types for filter pill
$blood_types = $conn->query("SELECT DISTINCT blood_type FROM patients WHERE is_active = TRUE AND blood_type IS NOT NULL AND blood_type != '' ORDER BY blood_type")->fetchAll(PDO::FETCH_COLUMN);

// Archived count
$archived_count = (int)$conn->query("SELECT COUNT(*) as c FROM patients WHERE is_active = FALSE")->fetch(PDO::FETCH_ASSOC)['c'];
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Stats bar ─────────────────────────────────────────── */
.stats-bar {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 18px;
}
@media (max-width: 700px) {
    .stats-bar { grid-template-columns: repeat(2, 1fr); }
}
.stat-card {
    background: var(--white);
    border: var(--border);
    border-radius: 12px;
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.stat-icon {
    width: 38px; height: 38px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.stat-label { font-size: 0.72rem; font-weight: 600; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.04em; }
.stat-value { font-size: 1.35rem; font-weight: 800; color: var(--gray-800); line-height: 1.1; }

/* ── Filter pills ──────────────────────────────────────── */
.filter-pills {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 14px;
    align-items: center;
}
.pill-label { font-size: 0.72rem; font-weight: 700; color: var(--gray-400); text-transform: uppercase; letter-spacing: 0.05em; margin-right: 2px; }
.pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1.5px solid var(--gray-200);
    background: var(--white);
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--gray-600);
    text-decoration: none;
    cursor: pointer;
    transition: all 0.15s;
    white-space: nowrap;
}
.pill:hover { border-color: var(--primary); color: var(--primary); }
.pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }

/* ── Age badge ──────────────────────────────────────────── */
.age-badge {
    display: inline-block;
    font-size: 0.72rem;
    font-weight: 700;
    color: var(--gray-500);
    background: var(--gray-100);
    border-radius: 8px;
    padding: 1px 6px;
}

/* Dark mode */
[data-theme="dark"] .stat-card { background: #1E293B; border-color: #334155; }
[data-theme="dark"] .stat-value { color: #E2E8F0; }
[data-theme="dark"] .pill { background: #1E293B; border-color: #334155; color: #94A3B8; }
[data-theme="dark"] .pill.active { background: var(--primary); border-color: var(--primary); color: #fff; }
[data-theme="dark"] .pill:hover { border-color: var(--primary); color: #A5B4FC; }
[data-theme="dark"] .age-badge { background: #334155; color: #94A3B8; }
</style>
</head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <h5>Patient Records</h5>
            <div style="display:flex;gap:8px;align-items:center;">
                <?php if ($archived_count > 0): ?>
                <a href="archived.php" class="btn btn-outline-secondary btn-sm" title="View archived patients">
                    <i class="bi bi-archive"></i> Archived
                    <span class="badge bg-secondary ms-1"><?php echo $archived_count; ?></span>
                </a>
                <?php endif; ?>
                <a href="add.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-person-plus"></i> Add Patient
                </a>
            </div>
        </div>

        <!-- ── Stats Bar ─────────────────────────────────────────── -->
        <div class="stats-bar">
            <div class="stat-card">
                <div class="stat-icon" style="background:#EFF6FF;"><i class="bi bi-people-fill" style="color:#3B82F6;"></i></div>
                <div>
                    <div class="stat-label">Total Patients</div>
                    <div class="stat-value"><?php echo number_format($stats_row['total']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#F0FDF4;"><i class="bi bi-person-check-fill" style="color:#22C55E;"></i></div>
                <div>
                    <div class="stat-label">New This Month</div>
                    <div class="stat-value"><?php echo number_format($stats_row['new_this_month']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#EFF6FF;"><i class="bi bi-gender-male" style="color:#3B82F6;"></i></div>
                <div>
                    <div class="stat-label">Male</div>
                    <div class="stat-value"><?php echo number_format($stats_row['males']); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#FDF4FF;"><i class="bi bi-gender-female" style="color:#A855F7;"></i></div>
                <div>
                    <div class="stat-label">Female</div>
                    <div class="stat-value"><?php echo number_format($stats_row['females']); ?></div>
                </div>
            </div>
        </div>

        <!-- ── Search + Filter Pills ─────────────────────────────── -->
        <form method="GET" class="mb-2">
            <div class="input-group" style="max-width:420px;">
                <?php if ($gender_f): ?><input type="hidden" name="gender" value="<?php echo htmlspecialchars($gender_f); ?>"><?php endif; ?>
                <?php if ($blood_f): ?><input type="hidden" name="blood" value="<?php echo htmlspecialchars($blood_f); ?>"><?php endif; ?>
                <input type="text" name="search" class="form-control" placeholder="Search by name, code, or phone..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search || $gender_f || $blood_f): ?>
                    <a href="list.php" class="btn btn-outline-danger">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Gender pills -->
        <div class="filter-pills">
            <span class="pill-label">Gender:</span>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($blood_f ? 'blood='.urlencode($blood_f).'&' : ''); ?>"
               class="pill <?php echo !$gender_f ? 'active' : ''; ?>">All</a>
            <?php foreach (['male' => '♂ Male', 'female' => '♀ Female', 'other' => 'Other'] as $gv => $gl): ?>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($blood_f ? 'blood='.urlencode($blood_f).'&' : ''); ?>gender=<?php echo $gv; ?>"
               class="pill <?php echo $gender_f === $gv ? 'active' : ''; ?>"><?php echo $gl; ?></a>
            <?php endforeach; ?>

            <?php if (!empty($blood_types)): ?>
            <span class="pill-label ms-2">Blood:</span>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($gender_f ? 'gender='.urlencode($gender_f).'&' : ''); ?>"
               class="pill <?php echo !$blood_f ? 'active' : ''; ?>">All</a>
            <?php foreach ($blood_types as $bt): ?>
            <a href="list.php?<?php echo ($search ? 'search='.urlencode($search).'&' : '').($gender_f ? 'gender='.urlencode($gender_f).'&' : ''); ?>blood=<?php echo urlencode($bt); ?>"
               class="pill <?php echo $blood_f === $bt ? 'active' : ''; ?>"><?php echo htmlspecialchars($bt); ?></a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($total_count > 0 || $search || $gender_f || $blood_f): ?>
        <div style="font-size:0.8rem;color:var(--gray-400);margin-bottom:8px;">
            <?php echo number_format($total_count); ?> patient<?php echo $total_count !== 1 ? 's' : ''; ?> found
            <?php if ($gender_f || $blood_f || $search): ?> <span style="color:var(--primary);">— filtered</span><?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-0">
                <div class="mobile-card-table-wrap">
                    <table class="table mb-0 mobile-card-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Age</th>
                                <th>Gender</th>
                                <th>Phone</th>
                                <th>Visits</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($patients)): ?>
                                <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">
                                    <?php echo ($search || $gender_f || $blood_f) ? 'No patients match the current filters.' : 'No patients yet.'; ?>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($patients as $p):
                                    $age = '';
                                    if (!empty($p['date_of_birth'])) {
                                        $age = (int)floor((time() - strtotime($p['date_of_birth'])) / 31557600);
                                    }
                                ?>
                                <tr>
                                    <td data-label="Code" style="font-weight:600;color:var(--blue-500);font-size:0.8rem;"><?php echo htmlspecialchars($p['patient_code']); ?></td>
                                    <td data-label="Name" style="font-weight:500;">
                                        <?php echo htmlspecialchars(ucwords(strtolower($p['last_name'])).', '.ucwords(strtolower($p['first_name']))); ?>
                                        <?php if (!empty($p['is_incomplete'])): ?>
                                        <span class="badge bg-warning" style="font-size:0.65rem;font-weight:600;" title="Incomplete profile"><i class="bi bi-exclamation-circle"></i> Incomplete</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Age">
                                        <?php if ($age !== ''): ?>
                                        <span class="age-badge"><?php echo $age; ?> yrs</span>
                                        <?php else: ?>
                                        <span style="color:var(--gray-300);">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Gender"><?php echo ucfirst($p['gender'] ?? '—'); ?></td>
                                    <td data-label="Phone"><?php echo htmlspecialchars($p['phone'] ?? '—'); ?></td>
                                    <td data-label="Visits"><span class="badge bg-primary"><?php echo $p['total_visits']; ?></span></td>
                                    <td data-label="Registered" style="font-size:0.8rem;color:var(--gray-500);"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></td>
                                    <td data-label="Actions">
                                        <div style="display:flex;gap:6px;">
                                            <a href="view.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-info" title="View Patient">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="edit.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-outline-secondary" title="Edit Patient">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline-warning"
                                                title="Archive Patient"
                                                onclick="confirmArchive(<?php echo $p['id']; ?>, '<?php echo htmlspecialchars($p['first_name'].' '.$p['last_name'], ENT_QUOTES); ?>')">
                                                <i class="bi bi-archive"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format(($offset+1)); ?>–<?php echo number_format(min($offset+$per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> patients
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page-1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page+1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Archive Confirmation Modal -->
<div class="modal fade" id="archiveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid var(--border-color,#e2e8f0);">
                <h6 class="modal-title" style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;background:#fef3c7;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-archive-fill" style="color:#d97706;font-size:0.9rem;"></i>
                    </span>
                    Archive Patient
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                <p style="margin-bottom:12px;">Archive <strong id="archivePatientName"></strong>?</p>
                <div style="padding:12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:0.78rem;color:#166534;line-height:1.5;">
                    <i class="bi bi-shield-check-fill"></i> <strong>Safe operation.</strong>
                    All appointments, billing records, and dental history are preserved.
                    The patient can be restored at any time from <strong>Archived Patients</strong>.
                </div>
            </div>
            <div class="modal-footer" style="gap:8px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="margin:0;">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="delete_id" id="archivePatientId">
                    <button type="submit" class="btn btn-sm btn-warning" style="color:#fff;">
                        <i class="bi bi-archive"></i> Archive
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
var archiveModal = new bootstrap.Modal(document.getElementById('archiveModal'));
function confirmArchive(id, name) {
    document.getElementById('archivePatientId').value = id;
    document.getElementById('archivePatientName').textContent = name;
    archiveModal.show();
}
</script>
</body>
</html>
