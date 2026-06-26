<?php
// Book Appointment — redirected to the New Appointment drawer on the appointments list.
// The drawer handles both walk-ins (today) and advance bookings (future date).
require_once '../../includes/config.php';
header('Location: ' . BASE_URL . 'modules/appointments/list.php?walkin=1');
exit();
