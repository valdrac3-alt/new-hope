<?php
// sidebar.php — Left navigation sidebar shown on every admin page.
// NOTE: Requires auth.php to be included first for $current_user_* variables

// Fallback for variables if auth.php wasn't included (safety check)
$current_user_name = $current_user_name ?? 'User';
$current_user_role = $current_user_role ?? 'staff';

$initials = strtoupper(substr($current_user_name, 0, 1));

// Returns 'active' CSS class if the current URL contains the given path segment
function nav_active($path) {
    return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
}
?>
<!-- Skip-to-content link (accessibility) -->
<a class="skip-link" href="#main-content">Skip to main content</a>
<!-- Screen-reader live region for dynamic announcements -->
<div id="a11y-live-region" aria-live="polite" aria-atomic="true"></div>

<!-- Mobile sidebar backdrop — tap to close -->
<div id="sidebar-backdrop" onclick="closeMobileSidebar()" aria-hidden="true"></div>

<nav id="sidebar" aria-label="Main navigation">

    <div class="sidebar-brand">
    <div class="sidebar-brand-icon" id="brandIconWrap">
        <img id="brandLogoImg" src="" alt="" style="display:none;width:36px;height:36px;border-radius:50%;object-fit:cover;">
        <span id="brandLogoEmoji">🦷</span>
    </div>
    <div class="sidebar-brand-text">
        <span class="brand-name" id="brandNameDisplay">DentalCare.PH</span>
        <span class="brand-sub" id="brandSubDisplay">Clinic System</span>
    </div>
</div>


    <ul class="sidebar-nav" role="list">

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Main</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>dashboard.php" class="<?php echo nav_active('dashboard'); ?>"
               <?php echo nav_active('dashboard') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-house-door-fill" aria-hidden="true"></i><span class="nav-label">Dashboard</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Patients</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/patients/list.php" class="<?php echo nav_active('/patients/list'); ?>"
               <?php echo nav_active('/patients/list') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-people-fill" aria-hidden="true"></i><span class="nav-label">Patient Records</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/treatments/list.php" class="<?php echo nav_active('/treatments/'); ?>"
               <?php echo nav_active('/treatments/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-journal-medical" aria-hidden="true"></i><span class="nav-label">Dental Records</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Appointments</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" class="<?php echo nav_active('/appointments/list'); ?>"
               <?php echo nav_active('/appointments/list') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-calendar-check-fill" aria-hidden="true"></i><span class="nav-label">Appointments</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/appointments/calendar.php" class="<?php echo nav_active('/appointments/calendar'); ?>"
               <?php echo nav_active('/appointments/calendar') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-calendar3" aria-hidden="true"></i><span class="nav-label">Calendar</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/schedule/manage.php" class="<?php echo nav_active('/schedule/'); ?>"
               <?php echo nav_active('/schedule/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-clock-history" aria-hidden="true"></i><span class="nav-label">Schedule</span>
            </a>
        </li>

        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Billing</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/billing/list.php" class="<?php echo nav_active('/billing/'); ?>"
               <?php echo nav_active('/billing/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-receipt" aria-hidden="true"></i><span class="nav-label">Billing</span>
            </a>
        </li>

        <?php if (is_admin()): ?>
        <li role="presentation"><span class="nav-section-label" aria-hidden="true">Admin</span></li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/analytics/dashboard.php" class="<?php echo nav_active('/analytics/dashboard'); ?>"
               <?php echo nav_active('/analytics/dashboard') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-bar-chart-fill" aria-hidden="true"></i><span class="nav-label">Analytics</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/reports/index.php" class="<?php echo nav_active('/reports/'); ?>"
               <?php echo nav_active('/reports/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-file-earmark-bar-graph-fill" aria-hidden="true"></i><span class="nav-label">Reports</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/users/list.php" class="<?php echo nav_active('/users/'); ?>"
               <?php echo nav_active('/users/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-person-gear" aria-hidden="true"></i><span class="nav-label">Users</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/services/list.php" class="<?php echo nav_active('/services/'); ?>"
               <?php echo nav_active('/services/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-grid-fill" aria-hidden="true"></i><span class="nav-label">Services</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/doctors/list.php" class="<?php echo nav_active('/doctors/'); ?>"
               <?php echo nav_active('/doctors/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-person-badge-fill" aria-hidden="true"></i><span class="nav-label">Doctors</span>
            </a>
        </li>
        <li role="presentation">
            <a href="<?php echo BASE_URL; ?>modules/logs/activity.php" class="<?php echo nav_active('/logs/'); ?>"
               <?php echo nav_active('/logs/') ? 'aria-current="page"' : ''; ?>>
                <i class="bi bi-shield-fill-check" aria-hidden="true"></i><span class="nav-label">Audit Logs</span>
            </a>
        </li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div class="user-info">
                <div class="user-name"><?php echo e($current_user_name); ?></div>
                <div class="user-role"><?php echo ucfirst($current_user_role); ?></div>
            </div>
        </div>
    </div>

</nav>
