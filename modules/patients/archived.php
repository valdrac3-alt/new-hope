<?php
// Archived Patients — view, restore, or permanently delete soft-deleted patients.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$user_session = $_SESSION['user'] ?? [];
$current_user_id = $current_user_id ?? ($_SESSION['user_id'] ?? $user_session['id'] ?? $_SESSION['uid'] ?? 0);
$current_user_name = $current_user_name ?? ($_SESSION['user_name'] ?? $_SESSION['username'] ?? $user_session['name'] ?? $user_session['full_name'] ?? 'System');

$page_title = 'Archived Patients';

// ── Restore ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    validate_csrf();
    $rid = secure_int($_POST['restore_id']);
    if ($rid) {
        $nr = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as n FROM patients WHERE id = ? LIMIT 1");
        $nr->execute([$rid]);
        $pname = $nr->fetch(PDO::FETCH_ASSOC)['n'] ?? 'Unknown';
        $nr = null;

        $stmt = $conn->prepare("UPDATE patients SET is_active = TRUE WHERE id = ?");
        $stmt->execute([$rid]);
        $stmt = null;

        log_action($conn, $current_user_id, $current_user_name, 'Restored Patient', 'patients', $rid, "Restored from archive: $pname.");
    }
    header('Location: archived.php');
    exit();
}

// ── Permanent delete (destructive — admin only) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['perma_delete_id'])) {
    validate_csrf();
    require_admin(); // Only admins may permanently erase a patient and all their records
    $did = secure_int($_POST['perma_delete_id']);
    $confirmed = isset($_POST['perma_confirm']) && $_POST['perma_confirm'] === '1';

    if ($did && $confirmed) {
        // Only allow permanent delete on already-archived patients
        $chk = $conn->prepare("SELECT CONCAT(first_name,' ',last_name) as n FROM patients WHERE id = ? AND is_active = FALSE LIMIT 1");
        $chk->execute([$did]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        $chk = null;

        if ($row) {
            $pname = $row['n'];
            $conn->query("DELETE FROM bills           WHERE patient_id = $did");
            $conn->query("DELETE FROM dental_records  WHERE patient_id = $did");
            $conn->query("DELETE FROM appointments    WHERE patient_id = $did");
            $conn->query("DELETE FROM patients        WHERE id         = $did");

            log_action($conn, $current_user_id, $current_user_name, 'Permanently Deleted Patient', 'patients', $did, "Hard-deleted from archive: $pname and all related records.");
        }
    }
    header('Location: archived.php');
    exit();
}

$search   = trim($_GET['search'] ?? '');
$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));

// Build query using prepared statements to prevent SQL injection.
$params      = [];
$param_types = '';

$base_where = "WHERE p.is_active = FALSE";
if ($search) {
    $base_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ? OR p.phone LIKE ?)";
    $like = '%' . $search . '%';
    $params      = [$like, $like, $like, $like];
    $param_types = 'ssss';
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

// Main list query
$list_sql  = "
    SELECT p.*,
           COUNT(DISTINCT a.id)  as total_appointments,
           COUNT(DISTINCT b.id)  as total_bills,
           COUNT(DISTINCT dr.id) as total_dental_records
    FROM patients p
    LEFT JOIN appointments   a  ON a.patient_id  = p.id
    LEFT JOIN bills          b  ON b.patient_id  = p.id
    LEFT JOIN dental_records dr ON dr.patient_id = p.id
    $base_where
    GROUP BY p.id
    ORDER BY p.updated_at DESC
    LIMIT $per_page OFFSET $offset
";
$list_stmt = $conn->prepare($list_sql);
$list_stmt->execute($params);
$patients = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
$list_stmt->closeCursor();
?><!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page header -->
        <div class="page-header">
            <div style="display:flex;align-items:center;gap:10px;">
                <a href="list.php" class="btn btn-sm btn-outline-secondary" title="Back to Active Patients">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <div>
                    <h5 style="margin:0;">Archived Patients</h5>
                    <p style="margin:0;font-size:0.78rem;color:var(--gray-500);">
                        All records (appointments, billing, dental history) are fully preserved.
                    </p>
                </div>
            </div>
        </div>

        <!-- Info banner -->
        <div style="display:flex;align-items:flex-start;gap:10px;padding:14px 16px;
                    background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;
                    margin-bottom:20px;font-size:0.83rem;color:#1e40af;">
            <i class="bi bi-info-circle-fill" style="margin-top:1px;flex-shrink:0;"></i>
            <span>
                Archived patients are hidden from active lists but <strong>none of their data is deleted</strong>.
                Use <strong>Restore</strong> to make a patient active again.
                Permanent deletion is irreversible and removes all associated records.
            </span>
        </div>

        <form method="GET" class="mb-3">
            <div class="input-group" style="max-width:400px;">
                <input type="text" name="search" class="form-control" placeholder="Search archived patients..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search): ?><a href="archived.php" class="btn btn-outline-danger">Clear</a><?php endif; ?>
            </div>
        </form>

        <?php if ($total_count === 0): ?>
        <div style="text-align:center;padding:56px 24px;color:var(--gray-400);">
            <i class="bi bi-archive" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:0.4;"></i>
            <?php echo $search ? 'No archived patients match "'.htmlspecialchars($search).'".' : 'No archived patients. Great!'; ?>
        </div>
        <?php else: ?>

        <div class="card">
            <div class="card-body p-0">
                <div class="mobile-card-table-wrap">
                    <table class="table mb-0 mobile-card-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Name</th>
                                <th>Phone</th>
                                <th>Archived Records</th>
                                <th>Archived On</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patients as $p): ?>
                            <tr style="opacity:0.85;">
                                <td data-label="Code" style="font-weight:600;color:var(--gray-500);font-size:0.8rem;"><?php echo htmlspecialchars($p['patient_code']); ?></td>
                                <td data-label="Name" style="font-weight:500;color:var(--gray-600);">
                                    <?php echo htmlspecialchars(ucwords(strtolower($p['last_name'])).', '.ucwords(strtolower($p['first_name']))); ?>
                                </td>
                                <td data-label="Phone" style="color:var(--gray-500);"><?php echo htmlspecialchars($p['phone'] ?? '—'); ?></td>
                                <td data-label="Archived Records">
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                        <?php if ($p['total_appointments'] > 0): ?>
                                        <span class="badge" style="background:#dbeafe;color:#1d4ed8;font-weight:500;">
                                            <i class="bi bi-calendar2"></i> <?php echo $p['total_appointments']; ?> appt<?php echo $p['total_appointments'] != 1 ? 's' : ''; ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($p['total_bills'] > 0): ?>
                                        <span class="badge" style="background:#dcfce7;color:#15803d;font-weight:500;">
                                            <i class="bi bi-receipt"></i> <?php echo $p['total_bills']; ?> bill<?php echo $p['total_bills'] != 1 ? 's' : ''; ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($p['total_dental_records'] > 0): ?>
                                        <span class="badge" style="background:#fef9c3;color:#854d0e;font-weight:500;">
                                            <i class="bi bi-clipboard2-pulse"></i> <?php echo $p['total_dental_records']; ?> dental
                                        </span>
                                        <?php endif; ?>
                                        <?php if ($p['total_appointments'] == 0 && $p['total_bills'] == 0 && $p['total_dental_records'] == 0): ?>
                                        <span style="color:var(--gray-400);font-size:0.78rem;">No records</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Archived On" style="font-size:0.8rem;color:var(--gray-500);"><?php echo date('M d, Y', strtotime($p['updated_at'])); ?></td>
                                <td data-label="Actions">
                                    <div style="display:flex;gap:6px;">
                                        <!-- Restore -->
                                        <form method="POST" style="margin:0;">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="restore_id" value="<?php echo $p['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" title="Restore Patient">
                                                <i class="bi bi-arrow-counterclockwise"></i> Restore
                                            </button>
                                        </form>
                                        <!-- Permanent delete -->
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                            title="Permanently Delete"
                                            onclick="confirmPermaDelete(
                                                <?php echo $p['id']; ?>,
                                                '<?php echo htmlspecialchars($p['first_name'].' '.$p['last_name'], ENT_QUOTES); ?>',
                                                <?php echo (int)$p['total_appointments']; ?>,
                                                <?php echo (int)$p['total_bills']; ?>,
                                                <?php echo (int)$p['total_dental_records']; ?>
                                            )">
                                            <i class="bi bi-trash3"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format(($offset+1)); ?>–<?php echo number_format(min($offset+$per_page, $total_count)); ?> of <?php echo number_format($total_count); ?> archived
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="archived.php?<?php echo $filter_qs; ?>page=<?php echo $page-1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++): ?>
                <a href="archived.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg === $page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="archived.php?<?php echo $filter_qs; ?>page=<?php echo $page+1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

    </div>

<!-- Permanent Delete Modal -->
<div class="modal fade" id="permaDeleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="border-bottom:1px solid #fecaca;">
                <h6 class="modal-title" style="display:flex;align-items:center;gap:8px;">
                    <span style="width:32px;height:32px;background:#fee2e2;border-radius:8px;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-trash3-fill" style="color:#dc2626;font-size:0.9rem;"></i>
                    </span>
                    Permanent Delete
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                <p>You are about to <strong>permanently delete</strong> <strong id="permaPatientName"></strong>.</p>
                <div id="permaRecordWarning" style="padding:11px 13px;background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;font-size:0.78rem;color:#9a3412;margin-bottom:12px;line-height:1.6;display:none;">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>This will also permanently erase:</strong>
                    <span id="permaRecordList"></span>
                </div>
                <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;padding:10px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;font-size:0.8rem;">
                    <input type="checkbox" id="permaConfirmCheck" style="margin-top:2px;flex-shrink:0;">
                    <span>I understand this <strong>cannot be undone</strong> and want to permanently delete this patient and all their records.</span>
                </label>
            </div>
            <div class="modal-footer" style="gap:8px;">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" style="margin:0;" id="permaDeleteForm">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="perma_delete_id" id="permaDeleteId">
                    <input type="hidden" name="perma_confirm" value="1">
                    <button type="submit" class="btn btn-sm btn-danger" id="permaDeleteBtn" disabled>
                        <i class="bi bi-trash3"></i> Delete Forever
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>


<script>
function getModal(id){var el=document.getElementById(id);return el?bootstrap.Modal.getOrCreateInstance(el):null;}
function confirmPermaDelete(id, name, appts, bills, dental) {
    document.getElementById('permaDeleteId').value   = id;
    document.getElementById('permaPatientName').textContent = name;
    document.getElementById('permaConfirmCheck').checked = false;
    document.getElementById('permaDeleteBtn').disabled = true;

    var parts = [];
    if (appts  > 0) parts.push(appts  + ' appointment' + (appts  !== 1 ? 's' : ''));
    if (bills  > 0) parts.push(bills  + ' billing record' + (bills  !== 1 ? 's' : ''));
    if (dental > 0) parts.push(dental + ' dental record'  + (dental !== 1 ? 's' : ''));

    var warn = document.getElementById('permaRecordWarning');
    var list = document.getElementById('permaRecordList');
    if (parts.length > 0) {
        list.textContent = ' ' + parts.join(', ') + '.';
        warn.style.display = 'block';
    } else {
        warn.style.display = 'none';
    }
    getModal('permaDeleteModal').show();
}

document.getElementById('permaConfirmCheck').addEventListener('change', function() {
    document.getElementById('permaDeleteBtn').disabled = !this.checked;
});
</script>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
