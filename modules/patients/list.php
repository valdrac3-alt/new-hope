<?php
// List all active patients with search, and handle soft-delete (archive).

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Patient Records';

// ── Soft-delete (archive) ────────────────────────────────────────────────────
// Sets is_active = FALSE instead of running DELETE queries.
// All appointments, billing, and dental records are preserved.
// The patient can be restored at any time from the Archived Patients page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    validate_csrf();
    $del_id = secure_int($_POST['delete_id']);
    if ($del_id) {
        $nr = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as n FROM patients WHERE id = ? LIMIT 1");
        $nr->execute([$del_id]);
        $pname = $nr->fetch(PDO::FETCH_ASSOC)['n'] ?? 'Unknown';
        $nr->closeCursor();

        $stmt = $conn->prepare("UPDATE patients SET is_active = FALSE WHERE id = ?");
        $stmt->execute([$del_id]);
        $stmt->closeCursor();

        log_action($conn, $current_user_id, $current_user_name, 'Archived Patient', 'patients', $del_id, "Soft-deleted (archived): $pname — all records preserved.");
    }
    header('Location: list.php');
    exit();
}

$search   = trim($_GET['search'] ?? '');
$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));

// Build query using prepared statements to prevent SQL injection.
// For LIKE searches we bind the wildcard pattern as a parameter.
$params      = [];
$param_types = '';

$base_where = "WHERE p.is_active = TRUE";
if ($search) {
    $base_where .= " AND (
        p.first_name LIKE ? OR p.last_name LIKE ? OR p.middle_name LIKE ?
        OR p.patient_code LIKE ? OR p.phone LIKE ? OR p.email LIKE ?
        OR CONCAT(p.first_name,' ',p.last_name) LIKE ?
        OR CONCAT(p.first_name,' ',COALESCE(p.middle_name,''),' ',p.last_name) LIKE ?
    )";
    $like = '%' . $search . '%';
    $params      = [$like, $like, $like, $like, $like, $like, $like, $like];
    $param_types = 'ssssssss';
}

// COUNT query
$count_sql  = "SELECT COUNT(*) as c FROM patients p $base_where";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_count = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['c'];
$count_stmt->closeCursor();

$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_qs = $search ? 'search=' . urlencode($search) . '&' : '';

// Main list query — append LIMIT/OFFSET as validated integers (not user input)
$list_sql  = "
    SELECT p.*, COUNT(a.id) as total_visits
    FROM patients p
    LEFT JOIN appointments a ON a.patient_id = p.id AND a.status = 'completed'
    $base_where
    GROUP BY p.id
    ORDER BY p.last_name ASC, p.first_name ASC
    LIMIT $per_page OFFSET $offset
";
$list_stmt = $conn->prepare($list_sql);
$list_stmt->execute($params);
$patients = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
$list_stmt->closeCursor();

// Count archived patients for the badge
$archived_count = (int)$conn->query("SELECT COUNT(*) as c FROM patients WHERE is_active = FALSE")->fetch(PDO::FETCH_ASSOC)['c'];
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
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

        <form method="GET" class="mb-3">
            <div class="input-group" style="max-width:400px;">
                <input type="text" name="search" class="form-control" placeholder="Search by name, email, phone, or code..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search): ?><a href="list.php" class="btn btn-outline-danger">Clear</a><?php endif; ?>
            </div>
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="mobile-card-table-wrap">
                    <table class="table mb-0 mobile-card-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Gender</th>
                                <th>Phone</th>
                                <th>Visits</th>
                                <th>Registered</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($patients)): ?>
                                <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--gray-400);">
                                    <?php echo $search ? 'No results for "'.htmlspecialchars($search).'".' : 'No patients yet.'; ?>
                                </td></tr>
                            <?php else: ?>
                                <?php foreach ($patients as $p): ?>
                                <tr>
                                    <td data-label="Code" style="font-weight:600;color:var(--blue-500);font-size:0.8rem;"><?php echo htmlspecialchars($p['patient_code']); ?></td>
                                    <td data-label="Name" style="font-weight:500;"><?php echo htmlspecialchars(ucwords(strtolower($p['last_name'])).', '.ucwords(strtolower($p['first_name']))); ?></td>
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
