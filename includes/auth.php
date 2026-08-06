<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', 1);
    session_name('ctumindcare_session');
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])
        && isset($_SESSION['user_role']);
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    $db   = getDB();
    $role = $_SESSION['user_role'];

    if ($role === 'student') {
        $stmt = $db->prepare("
            SELECT id, student_id, first_name, last_name, email,
                   'student' as role, display_name as display_name, NULL as profile_photo
            FROM users WHERE id = ? AND is_active = 1
        ");
    } elseif ($role === 'wellness_admin') {
        // is_active is the counselor's live online flag (set on login, cleared on
        // logout/stale sweep) — the session itself is the access gate.
        $stmt = $db->prepare("
            SELECT id, NULL as student_id, first_name, last_name, email,
                   'wellness_admin' as role, NULL as display_name,
                   NULL as profile_photo, NULL as specialization
            FROM wellness_admins WHERE id = ?
        ");
    } elseif ($role === 'system_admin') {
        $stmt = $db->prepare("
            SELECT id, NULL as student_id, first_name, last_name, email,
                   'system_admin' as role, NULL as display_name, NULL as profile_photo
            FROM system_admins WHERE id = ? AND is_active = 1
        ");
    } else {
        return null;
    }

    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    return $result ?: null;
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . SITE_URL . '/auth/login.php');
        exit;
    }
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['user_role'], $roles, true)) {
        header('Location: ' . SITE_URL . '/auth/unauthorized.php');
        exit;
    }
}

function redirectIfLoggedIn(): void {
    if (!isLoggedIn()) return;
    switch ($_SESSION['user_role']) {
        case 'student':        header('Location: ' . SITE_URL . '/student/pages/dashboard.php');  break;
        case 'wellness_admin': header('Location: ' . SITE_URL . '/wellness/pages/dashboard.php'); break;
        case 'system_admin':   header('Location: ' . SITE_URL . '/admin/pages/dashboard.php');    break;
    }
    exit;
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function clean(?string $value = null): string {
    return htmlspecialchars(trim($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function generateCsrfToken(): string {
    if (!isset($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf_token'];
}

function validateCsrfToken(?string $token): bool {
    return !empty($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}