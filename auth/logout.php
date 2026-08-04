<?php
require_once '../includes/auth.php';
requireLogin();

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