<?php
// API: mark notifications as read, get unread count.

require_once '../includes/config.php';
require_once '../includes/db.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');


$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? ($_GET['action'] ?? '');

// Mark a notification as read
if ($action === 'mark_read') {
    $id = intval($body['id'] ?? 0);
    if ($id) {
        // Scope update to the current user's own notifications (or broadcast ones).
        // Without this, any logged-in user could mark any other user's notification as read.
        $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE id = ? AND (user_id = ? OR user_id IS NULL)");
        $stmt->execute([$id, $current_user_id]);
        $stmt = null;
        // Bust the session cache so the next page load reflects the updated count
        $cache_key = 'notif_cache_' . $current_user_id;
        unset($_SESSION[$cache_key . '_time'], $_SESSION[$cache_key . '_count'], $_SESSION[$cache_key . '_recent']);
        echo json_encode(['status' => 'success']);
    } else {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'message' => 'Invalid ID']);
    }
    exit();
}

// Mark all as read for current user
if ($action === 'mark_all_read') {
    $stmt = $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE user_id = ? OR user_id IS NULL");
    $stmt->execute([$current_user_id]);
    $stmt = null;
    // Bust the session cache so the next page load reflects the updated count
    $cache_key = 'notif_cache_' . $current_user_id;
    unset($_SESSION[$cache_key . '_time'], $_SESSION[$cache_key . '_count'], $_SESSION[$cache_key . '_recent']);
    echo json_encode(['status' => 'success']);
    exit();
}

// Get unread count (for badge in sidebar)
if ($action === 'get_count') {
    $stmt = $conn->prepare("SELECT COUNT(*) as c FROM notifications WHERE (user_id = ? OR user_id IS NULL) AND is_read = FALSE");
    if (!$stmt->execute([$current_user_id])) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Query failed.']);
        exit();
    }
    $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['c'];
    $stmt = null;
    echo json_encode(['status' => 'success', 'count' => $count]);
    exit();
}

http_response_code(400);
echo json_encode(['status' => 'error', 'message' => 'Unknown action.']);
?>
