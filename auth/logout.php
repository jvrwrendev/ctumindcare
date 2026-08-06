<?php
require_once '../includes/auth.php';
requireLogin();

// Counselors: a clean logout marks them offline immediately, so the AI can
// stand in and the student chat list reflects the true state right away.
if (($_SESSION['user_role'] ?? '') === 'wellness_admin') {
    try {
        $db = getDB();
        $db->prepare("UPDATE wellness_admins SET is_active = 0, last_activity = NULL WHERE id = ?")->execute([(int)$_SESSION['user_id']]);
    } catch (Throwable $e) {
        error_log('[CTU MindCare logout] ' . $e->getMessage());
    }
}

// Destroy the session
$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();

// Redirect to login page
setFlash('success', 'You have been logged out successfully.');
header('Location: ' . SITE_URL . '/auth/login.php');
exit;
?>