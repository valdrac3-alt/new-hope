<?php
// List all system users. Toggle active/inactive status.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'User Management';

// Handle toggle active — fully prepared
if (isset($_GET['toggle']) && isset($_GET['uid'])) {
    $uid = secure_int($_GET['uid'] ?? 0);
    if ($uid > 0 && $uid !== $current_user_id) {
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$uid]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($user) {
            $new_status = $user['is_active'] ? 'FALSE' : 'TRUE';
            $stmt2 = $conn->prepare("UPDATE users SET is_active = CAST(? AS boolean) WHERE id = ?");
            $stmt2->execute([$new_status, $uid]);
            $stmt2->closeCursor();
            $label = $new_status ? 'Activated User' : 'Deactivated User';
            log_action($conn, $current_user_id, $current_user_name, $label, 'users', $uid);
        }
    }
    header('Location: list.php');
    exit();
}

$users = $conn->query("SELECT * FROM users ORDER BY role ASC, full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
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
                <h5>User Management</h5>
            </div>
            <div class="page-header-actions">
                <a href="add.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-plus"></i> Add User
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="mobile-card-table-wrap">
<table class="table table-hover mb-0 mobile-card-table">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td data-label="Full Name"><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td data-label="Username"><?php echo htmlspecialchars($u['username']); ?></td>
                            <td data-label="Role">
                                <span class="badge bg-<?php echo $u['role'] === 'admin' ? 'danger' : 'secondary'; ?>">
                                    <?php echo ucfirst($u['role']); ?>
                                </span>
                            </td>
                            <td data-label="Email"><?php echo htmlspecialchars($u['email'] ?? '—'); ?></td>
                            <td data-label="Phone"><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                            <td data-label="Status">
                                <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td data-label="Created"><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                            <td data-label="Actions">
                                <a href="edit.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <?php if ($u['id'] !== $current_user_id): ?>
                                    <a href="list.php?toggle=1&uid=<?php echo $u['id']; ?>"
                                       class="btn btn-sm btn-outline-<?php echo $u['is_active'] ? 'warning' : 'success'; ?>"
                                       onclick="return confirm('<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?> this user?')">
                                        <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
