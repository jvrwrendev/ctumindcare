<?php
require_once '../../includes/auth.php';
requireRole('system_admin');

$user = currentUser();
if (!$user) {
    $user = [
        'id' => $_SESSION['user_id'] ?? null,
        'first_name' => $_SESSION['user_name'] ?? 'Admin',
        'last_name' => '',
        'email' => '',
        'role' => 'system_admin',
        'display_name' => null,
        'profile_photo' => null,
    ];
}
$db   = getDB();

// ── Handle actions ────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$target = (int)($_GET['id'] ?? 0);

if ($action === 'deactivate_student' && $target) {
    $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Student account deactivated.');
    header('Location: users.php'); exit;
}
if ($action === 'activate_student' && $target) {
    $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Student account activated.');
    header('Location: users.php'); exit;
}
if ($action === 'deactivate_counselor' && $target) {
    $db->prepare("UPDATE wellness_admins SET is_active = 0 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Counselor account deactivated.');
    header('Location: users.php'); exit;
}
if ($action === 'activate_counselor' && $target) {
    $db->prepare("UPDATE wellness_admins SET is_active = 1 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Counselor account activated.');
    header('Location: users.php'); exit;
}
if ($action === 'resolve_crisis' && $target) {
    $db->prepare("UPDATE crisis_alerts SET status = 'acknowledged', acknowledged_by = ? WHERE id = ?")->execute([$user['id'], $target]);
    setFlash('success', 'Crisis alert acknowledged.');
    header('Location: users.php'); exit;
}

// ── Add counselor ─────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_counselor') {
    $fn   = clean($_POST['first_name']     ?? '');
    $ln   = clean($_POST['last_name']      ?? '');
    $em   = clean($_POST['email']          ?? '');
    $spec = clean($_POST['specialization'] ?? '');
    $pw   = $_POST['password'] ?? '';

    if (empty($fn))        $errors[] = 'First name required.';
    if (empty($ln))        $errors[] = 'Last name required.';
    if (empty($em))        $errors[] = 'Email required.';
    if (strlen($pw) < 8)  $errors[] = 'Password must be at least 8 characters.';

    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM wellness_admins WHERE email = ?");
        $check->execute([$em]);
        if ($check->fetch()) {
            $errors[] = 'Email already exists.';
        } else {
            $stmt = $db->prepare("INSERT INTO wellness_admins (first_name, last_name, email, password, specialization) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$fn, $ln, $em, hashPassword($pw), $spec ?: null]);
            setFlash('success', "Counselor account created for {$fn} {$ln}.");
            header('Location: users.php?filter=counselors'); exit;
        }
    }
}

// ── Add system admin ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_admin') {
    $fn = clean($_POST['admin_first'] ?? '');
    $ln = clean($_POST['admin_last']  ?? '');
    $em = clean($_POST['admin_email'] ?? '');
    $pw = $_POST['admin_password']    ?? '';

    if (empty($fn))        $errors[] = 'First name required.';
    if (empty($ln))        $errors[] = 'Last name required.';
    if (empty($em))        $errors[] = 'Email required.';
    if (strlen($pw) < 8)  $errors[] = 'Password must be at least 8 characters.';

    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM system_admins WHERE email = ?");
        $check->execute([$em]);
        if ($check->fetch()) {
            $errors[] = 'Admin email already exists.';
        } else {
            $stmt = $db->prepare("INSERT INTO system_admins (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fn, $ln, $em, hashPassword($pw)]);
            setFlash('success', "Admin account created for {$fn} {$ln}.");
            header('Location: users.php?filter=admins'); exit;
        }
    }
}

// ── Data ──────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'students';
$search = clean($_GET['q'] ?? '');

$sw = "WHERE 1=1"; $sp = [];
if ($filter === 'inactive_students') { $sw .= " AND is_active = 0"; }
elseif (!in_array($filter, ['counselors','admins','crisis'])) { $sw .= " AND is_active = 1"; }
if ($search && !in_array($filter, ['counselors','admins','crisis'])) {
    $sw .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $sp  = array_fill(0, 3, "%{$search}%");
}

$students = [];
if (!in_array($filter, ['counselors','admins','crisis'])) {
    $stmt = $db->prepare("SELECT id, student_id, first_name, last_name, email, is_active, created_at FROM users {$sw} ORDER BY created_at DESC LIMIT 50");
    $stmt->execute($sp);
    $students = $stmt->fetchAll();
}

$counselors = [];
if ($filter === 'counselors') {
    $cw = "WHERE 1=1"; $cp = [];
    if ($search) { $cw .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)"; $cp = array_fill(0,3,"%{$search}%"); }
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, specialization, is_active, created_at FROM wellness_admins {$cw} ORDER BY created_at DESC");
    $stmt->execute($cp);
    $counselors = $stmt->fetchAll();
}

$admins = [];
if ($filter === 'admins') {
    $stmt = $db->query("SELECT id, first_name, last_name, email, is_active, created_at FROM system_admins ORDER BY created_at DESC");
    $admins = $stmt->fetchAll();
}

$crisis_list = [];
if ($filter === 'crisis') {
    $stmt = $db->query("
        SELECT ca.*, u.first_name, u.last_name, u.student_id as sid,
               wa.first_name as adm_first, wa.last_name as adm_last
        FROM crisis_alerts ca
        JOIN users u ON ca.student_id = u.id
        LEFT JOIN wellness_admins wa ON ca.acknowledged_by = wa.id
        ORDER BY ca.created_at DESC LIMIT 50
    ");
    $crisis_list = $stmt->fetchAll();
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Users — CTU MindCare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --sage:         #3E8E7E;
  --sage-2:       #347A6C;
  --sage-dark:    #1F4E46;
  --sage-light:   #EBF5F2;
  --sage-mid:     #B8DED7;
  --sand:         #F5F2EE;
  --sand-2:       #EDE8E1;
  --sand-3:       #D8D2C9;
  --white:        #FFFFFF;
  --text:         #1E1E1C;
  --text-2:       #5A5855;
  --text-3:       #9C9890;
  --border:       #E4DED6;
  --border-2:     #D6D0C8;
  --red:          #C03030;
  --red-bg:       #FDF0F0;
  --red-border:   #EFBDBD;
  --amber:        #C47A10;
  --amber-bg:     #FDF5E6;
  --amber-border: #F0D08A;
  --lav:          #6E63B8;
  --lav-bg:       #F0EFF9;
  --lav-border:   #C8C3ED;
  --navy:         #263147;
  --navy-2:       #1C2537;
  --radius:       13px;
  --radius-sm:    9px;
  --shadow-sm:    0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
  --shadow-md:    0 4px 16px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
}

body {
  font-family: 'Sora', sans-serif;
  background: var(--sand);
  color: var(--text);
  min-height: 100vh;
  font-size: 14px;
}

/* ── Topbar ── */
.topbar {
  height: 62px;
  background: var(--navy);
  display: flex;
  align-items: center;
  padding: 0 24px;
  position: sticky;
  top: 0;
  z-index: 300;
  gap: 14px;
  box-shadow: 0 1px 0 rgba(255,255,255,.06), 0 2px 12px rgba(0,0,0,.25);
}

.topbar-brand {
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;
  margin-right: auto;
}

.topbar-mark {
  width: 36px;
  height: 36px;
  border-radius: 9px;
  background: linear-gradient(135deg, var(--sage) 0%, var(--sage-dark) 100%);
  color: #fff;
  font-family: 'Playfair Display', serif;
  font-size: 17px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 8px rgba(62,142,126,.4);
  flex-shrink: 0;
}

.topbar-name {
  font-family: 'Playfair Display', serif;
  font-size: 16px;
  color: #fff;
  letter-spacing: .01em;
}

.topbar-role {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .08em;
  text-transform: uppercase;
  color: var(--sage);
  background: rgba(62,142,126,.15);
  border: 1px solid rgba(62,142,126,.3);
  border-radius: 100px;
  padding: 3px 10px;
}

.topbar-user {
  font-size: 12px;
  color: rgba(255,255,255,.55);
  font-weight: 400;
}

.logout-btn {
  font-size: 12px;
  color: rgba(255,255,255,.5);
  text-decoration: none;
  padding: 6px 13px;
  border-radius: var(--radius-sm);
  border: 1px solid rgba(255,255,255,.15);
  transition: all .15s;
  font-weight: 500;
}
.logout-btn:hover { color: #fff; border-color: rgba(255,255,255,.4); text-decoration: none; }

/* ── Layout ── */
.layout {
  display: grid;
  grid-template-columns: 230px 1fr;
  min-height: calc(100vh - 62px);
}

/* ── Sidebar ── */
.sidebar {
  background: var(--white);
  border-right: 1px solid var(--border);
  padding: 22px 0 0;
  position: sticky;
  top: 62px;
  height: calc(100vh - 62px);
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.sb-section { padding: 0 12px; margin-bottom: 4px; }

.sb-label {
  font-size: 9.5px;
  font-weight: 700;
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: .1em;
  padding: 0 10px;
  margin-bottom: 6px;
  display: block;
}

.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border-radius: var(--radius-sm);
  font-size: 13px;
  font-weight: 400;
  color: var(--text-2);
  text-decoration: none;
  transition: all .13s;
  margin-bottom: 2px;
}
.nav-item:hover { background: var(--sand); color: var(--text); text-decoration: none; }
.nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 600; }

.nav-icon {
  width: 30px;
  height: 30px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  flex-shrink: 0;
  background: var(--sand-2);
  transition: background .13s;
}
.nav-item.active .nav-icon { background: var(--sage-mid); }

.nav-item.danger { color: var(--red); }
.nav-item.danger .nav-icon { background: var(--red-bg); }
.nav-item.danger:hover { background: var(--red-bg); color: var(--red); text-decoration: none; }

.sb-divider { height: 1px; background: var(--border); margin: 10px 12px; }

.sb-footer { margin-top: auto; padding: 14px; }

.sb-user {
  background: var(--sand);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 12px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.su-av {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 600;
  flex-shrink: 0;
  letter-spacing: .03em;
}

.su-name { font-size: 12px; font-weight: 600; color: var(--text); }
.su-role { font-size: 10px; color: var(--text-3); margin-top: 1px; }
.su-logout { margin-left: auto; font-size: 10px; color: var(--text-3); text-decoration: none; flex-shrink: 0; }
.su-logout:hover { color: var(--red); }

/* ── Main ── */
.main { padding: 30px 32px 60px; }

.page-header { margin-bottom: 26px; }
.page-label { font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--sage); margin-bottom: 5px; }
.page-title { font-family: 'Playfair Display', serif; font-size: 27px; color: var(--text); line-height: 1.15; }
.page-sub   { font-size: 13px; color: var(--text-3); margin-top: 4px; }

/* ── Flash ── */
.flash {
  padding: 12px 16px;
  border-radius: var(--radius-sm);
  font-size: 13px;
  margin-bottom: 22px;
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 500;
  animation: slideIn .2s ease;
}
@keyframes slideIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
.flash-warning { background: var(--amber-bg); border: 1px solid var(--amber-border); color: #5A3300; }
.flash-error   { background: var(--red-bg);   border: 1px solid var(--red-border);   color: var(--red); }

/* ── Cards ── */
.card {
  background: var(--white);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  margin-bottom: 18px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 22px;
  border-bottom: 1px solid var(--border);
  gap: 12px;
  background: var(--white);
}

.card-title {
  font-size: 14px;
  font-weight: 600;
  color: var(--text);
}

.card-body { padding: 20px 22px; }

/* ── Toolbar ── */
.toolbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 14px 22px;
  border-bottom: 1px solid var(--border);
  background: var(--sand);
}

.filter-pills { display: flex; gap: 6px; flex-wrap: wrap; flex: 1; }

.filter-pill {
  padding: 5px 14px;
  border-radius: 100px;
  font-size: 12px;
  font-weight: 500;
  text-decoration: none;
  border: 1.5px solid var(--border);
  color: var(--text-2);
  background: var(--white);
  transition: all .13s;
  white-space: nowrap;
}
.filter-pill:hover { border-color: var(--sage); color: var(--sage); text-decoration: none; }
.filter-pill.active { background: var(--sage); color: #fff; border-color: var(--sage); box-shadow: 0 2px 6px rgba(62,142,126,.25); }
.filter-pill.pill-danger { color: var(--red); border-color: var(--red-border); }
.filter-pill.pill-danger:hover { background: var(--red-bg); text-decoration: none; }
.filter-pill.pill-danger.active { background: var(--red); color: #fff; border-color: var(--red); box-shadow: 0 2px 6px rgba(192,48,48,.25); }

.search-form { display: flex; gap: 8px; }

.search-input {
  padding: 7px 14px;
  background: var(--white);
  border: 1.5px solid var(--border-2);
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 12px;
  color: var(--text);
  outline: none;
  width: 200px;
  transition: border-color .15s, box-shadow .15s;
}
.search-input::placeholder { color: var(--text-3); }
.search-input:focus { border-color: var(--sage); box-shadow: 0 0 0 3px rgba(62,142,126,.1); }

.btn-search {
  padding: 7px 16px;
  background: var(--sage);
  color: #fff;
  border: none;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: background .13s;
  white-space: nowrap;
}
.btn-search:hover { background: var(--sage-2); }

/* ── Table ── */
.table-wrap { overflow-x: auto; }

.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th {
  font-size: 10px;
  font-weight: 700;
  color: var(--text-3);
  text-transform: uppercase;
  letter-spacing: .07em;
  padding: 10px 22px;
  text-align: left;
  border-bottom: 1px solid var(--border);
  background: var(--sand);
  white-space: nowrap;
}

.data-table td {
  padding: 12px 22px;
  font-size: 13px;
  border-bottom: 1px solid var(--sand-2);
  color: var(--text);
  vertical-align: middle;
}

.data-table tbody tr:last-child td { border-bottom: none; }

.data-table tbody tr {
  transition: background .1s;
}
.data-table tbody tr:hover td { background: #FAFAF8; }

.cell-muted { color: var(--text-3); font-size: 12px; }
.cell-sub   { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }

.avatar-sm {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  font-weight: 700;
  flex-shrink: 0;
  letter-spacing: .03em;
}
.av-green  { background: var(--sage-light); color: var(--sage-dark); }
.av-purple { background: var(--lav-bg); color: var(--lav); }
.av-navy   { background: #E8EBF0; color: var(--navy); }

.user-cell { display: flex; align-items: center; gap: 10px; }

/* ── Status badges ── */
.badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 100px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
}
.badge::before { content: ''; width: 6px; height: 6px; border-radius: 50%; }
.badge-active   { background: var(--sage-light); color: var(--sage-dark); }
.badge-active::before { background: var(--sage); }
.badge-inactive { background: var(--red-bg); color: var(--red); }
.badge-inactive::before { background: var(--red); }
.badge-you      { background: var(--sage-light); color: var(--sage-dark); font-size: 10px; padding: 2px 7px; }

/* ── Crisis badges ── */
.crisis-badge { display: inline-block; padding: 3px 9px; border-radius: 100px; font-size: 10.5px; font-weight: 600; }
.cb-open         { background: var(--red-bg);   color: var(--red);   border: 1px solid var(--red-border); }
.cb-acknowledged { background: var(--amber-bg); color: var(--amber); border: 1px solid var(--amber-border); }
.cb-resolved     { background: var(--sage-light); color: var(--sage-dark); border: 1px solid var(--sage-mid); }

/* ── Action links ── */
.action-link {
  font-size: 12px;
  font-weight: 500;
  color: var(--sage);
  text-decoration: none;
  padding: 4px 10px;
  border-radius: 6px;
  border: 1px solid var(--sage-mid);
  background: var(--sage-light);
  transition: all .13s;
  display: inline-block;
  white-space: nowrap;
}
.action-link:hover { background: var(--sage-mid); text-decoration: none; }
.action-link.red { color: var(--red); border-color: var(--red-border); background: var(--red-bg); }
.action-link.red:hover { background: #f8dada; text-decoration: none; }

/* ── Empty state ── */
.empty-state {
  text-align: center;
  padding: 48px 24px;
  color: var(--text-3);
}
.empty-state .empty-icon { font-size: 32px; margin-bottom: 10px; }
.empty-state .empty-text { font-size: 13px; }

/* ── Forms ── */
.form-section { margin-bottom: 0; }

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.form-group { display: flex; flex-direction: column; gap: 6px; }

.form-label {
  font-size: 12px;
  font-weight: 600;
  color: var(--text-2);
}
.form-label .opt { font-weight: 400; color: var(--text-3); }

.form-input {
  padding: 10px 14px;
  background: var(--sand);
  border: 1.5px solid var(--border-2);
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 13px;
  color: var(--text);
  outline: none;
  transition: all .15s;
}
.form-input::placeholder { color: var(--text-3); }
.form-input:focus { background: var(--white); border-color: var(--sage); box-shadow: 0 0 0 3px rgba(62,142,126,.1); }

.form-actions { margin-top: 18px; display: flex; align-items: center; gap: 12px; }

.btn {
  padding: 10px 20px;
  border: none;
  border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all .13s;
}
.btn-primary { background: var(--sage); color: #fff; }
.btn-primary:hover { background: var(--sage-2); box-shadow: 0 3px 8px rgba(62,142,126,.3); }
.btn-dark { background: var(--navy); color: #fff; }
.btn-dark:hover { background: var(--navy-2); box-shadow: 0 3px 8px rgba(38,49,71,.3); }

/* ── Error box ── */
.error-box {
  background: var(--red-bg);
  border: 1.5px solid var(--red-border);
  border-radius: var(--radius-sm);
  padding: 12px 16px;
  margin-bottom: 18px;
  font-size: 13px;
  color: var(--red);
  line-height: 1.7;
}

/* ── Back link ── */
.back-link {
  font-size: 12px;
  color: var(--sage);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-weight: 500;
}
.back-link:hover { text-decoration: underline; }

/* ── Responsive ── */
@media (max-width: 960px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .form-grid { grid-template-columns: 1fr; }
  .main { padding: 22px 18px 48px; }
}
@media (max-width: 600px) {
  .filter-pills { gap: 4px; }
  .toolbar { padding: 12px 16px; }
  .data-table th, .data-table td { padding: 10px 14px; }
  .search-input { width: 140px; }
}
</style>
</head>
<body>

<!-- ── Topbar ── -->
<header class="topbar">
  <a class="topbar-brand" href="dashboard.php">
    <div class="topbar-mark">M</div>
    <span class="topbar-name">CTU MindCare</span>
  </a>
  <span class="topbar-role">System Admin</span>
  <div style="display:flex;align-items:center;gap:12px;margin-left:auto;">
    <span class="topbar-user">👋 <?= clean(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?></span>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="layout">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">Navigation</span>
      <a class="nav-item" href="dashboard.php">
        <div class="nav-icon">📊</div> Dashboard
      </a>
      <a class="nav-item active" href="users.php">
        <div class="nav-icon">👥</div> Manage Users
      </a>
      <a class="nav-item" href="analytics.php">
        <div class="nav-icon">📈</div> Analytics
      </a>
      <a class="nav-item" href="announcements.php">
        <div class="nav-icon">📢</div> Announcements
      </a>
      <a class="nav-item" href="logs.php">
        <div class="nav-icon">📋</div> System Logs
      </a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Monitor</span>
      <a class="nav-item danger" href="users.php?filter=crisis">
        <div class="nav-icon">🚨</div> Crisis Alerts
      </a>
    </div>
    <div class="sb-footer">
      <div class="sb-user">
        <div class="su-av"><?= strtoupper(substr($user['first_name'] ?? '',0,1).substr($user['last_name'] ?? '',0,1)) ?></div>
        <div>
          <div class="su-name"><?= clean(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?></div>
          <div class="su-role">System Administrator</div>
        </div>
        <a class="su-logout" href="../../auth/logout.php">Sign out</a>
      </div>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="page-header">
      <div class="page-label">User Management</div>
      <div class="page-title">Manage Users</div>
      <div class="page-sub">View, search, and manage all accounts across all roles</div>
    </div>

    <!-- ── Crisis view ── -->
    <?php if ($filter === 'crisis'): ?>

      <div class="card">
        <div class="card-header">
          <div class="card-title">All Crisis Alerts</div>
          <a class="back-link" href="users.php">← Back to Users</a>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Message</th>
                <th>Status</th>
                <th>Date</th>
                <th>Acknowledged By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($crisis_list as $c): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-green"><?= strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1)) ?></div>
                      <div>
                        <div><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                        <div class="cell-sub"><?= clean($c['sid'] ?? '') ?></div>
                      </div>
                    </div>
                  </td>
                  <td style="max-width:220px">
                    <span class="cell-muted"><?= clean(substr($c['message'] ?? 'No message', 0, 65)) ?>…</span>
                  </td>
                  <td>
                    <span class="crisis-badge cb-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y · g:i A', strtotime($c['created_at'])) ?></td>
                  <td>
                    <?php if ($c['adm_first']): ?>
                      <span style="font-size:13px"><?= clean($c['adm_first'].' '.$c['adm_last']) ?></span>
                    <?php else: ?>
                      <span class="cell-muted">—</span>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'open'): ?>
                      <div style="margin-top:5px">
                        <a class="action-link" href="users.php?action=resolve_crisis&id=<?= $c['id'] ?>">Acknowledge</a>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($crisis_list)): ?>
                <tr><td colspan="5">
                  <div class="empty-state">
                    <div class="empty-icon">✅</div>
                    <div class="empty-text">No crisis alerts found.</div>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php else: ?>

    <!-- ── Users table ── -->
    <div class="card">

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="filter-pills">
          <a class="filter-pill <?= $filter==='students'          ? 'active' : '' ?>" href="users.php?filter=students">Students</a>
          <a class="filter-pill <?= $filter==='inactive_students' ? 'active' : '' ?>" href="users.php?filter=inactive_students">Inactive</a>
          <a class="filter-pill <?= $filter==='counselors'        ? 'active' : '' ?>" href="users.php?filter=counselors">Counselors</a>
          <a class="filter-pill <?= $filter==='admins'            ? 'active' : '' ?>" href="users.php?filter=admins">Admins</a>
          <a class="filter-pill pill-danger <?= $filter==='crisis'? 'active' : '' ?>" href="users.php?filter=crisis">🚨 Crisis</a>
        </div>
        <form class="search-form" method="GET">
          <input type="hidden" name="filter" value="<?= clean($filter) ?>">
          <input class="search-input" type="text" name="q" value="<?= clean($search) ?>" placeholder="Search…">
          <button type="submit" class="btn-search">Search</button>
        </form>
      </div>

      <div class="table-wrap">

        <!-- Students -->
        <?php if (in_array($filter, ['students', 'inactive_students'])): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th>Email</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $u): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-green"><?= strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)) ?></div>
                      <div><?= clean($u['first_name'].' '.$u['last_name']) ?></div>
                    </div>
                  </td>
                  <td class="cell-muted"><?= clean($u['student_id'] ?? '—') ?></td>
                  <td class="cell-muted"><?= clean($u['email']) ?></td>
                  <td>
                    <?php if ($u['is_active']): ?>
                      <span class="badge badge-active">Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                  <td>
                    <?php if ($u['is_active']): ?>
                      <a class="action-link red" href="users.php?action=deactivate_student&id=<?= $u['id'] ?>"
                         onclick="return confirm('Deactivate <?= clean($u['first_name']) ?>?')">Deactivate</a>
                    <?php else: ?>
                      <a class="action-link" href="users.php?action=activate_student&id=<?= $u['id'] ?>">Activate</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($students)): ?>
                <tr><td colspan="6">
                  <div class="empty-state">
                    <div class="empty-icon">👥</div>
                    <div class="empty-text">No students found.</div>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>

        <!-- Counselors -->
        <?php elseif ($filter === 'counselors'): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Counselor</th>
                <th>Email</th>
                <th>Specialization</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($counselors as $c): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-purple"><?= strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1)) ?></div>
                      <div><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                    </div>
                  </td>
                  <td class="cell-muted"><?= clean($c['email']) ?></td>
                  <td class="cell-muted"><?= clean($c['specialization'] ?? '—') ?></td>
                  <td>
                    <?php if ($c['is_active']): ?>
                      <span class="badge badge-active">Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                  <td>
                    <?php if ($c['is_active']): ?>
                      <a class="action-link red" href="users.php?action=deactivate_counselor&id=<?= $c['id'] ?>"
                         onclick="return confirm('Deactivate <?= clean($c['first_name']) ?>?')">Deactivate</a>
                    <?php else: ?>
                      <a class="action-link" href="users.php?action=activate_counselor&id=<?= $c['id'] ?>">Activate</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($counselors)): ?>
                <tr><td colspan="6">
                  <div class="empty-state">
                    <div class="empty-icon">🏥</div>
                    <div class="empty-text">No counselors found.</div>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>

        <!-- Admins -->
        <?php elseif ($filter === 'admins'): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Administrator</th>
                <th>Email</th>
                <th>Status</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($admins as $a): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-navy"><?= strtoupper(substr($a['first_name'],0,1).substr($a['last_name'],0,1)) ?></div>
                      <div>
                        <?= clean($a['first_name'].' '.$a['last_name']) ?>
                        <?php if ($a['id'] === $user['id']): ?>
                          &nbsp;<span class="badge badge-you">You</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td class="cell-muted"><?= clean($a['email']) ?></td>
                  <td>
                    <?php if ($a['is_active']): ?>
                      <span class="badge badge-active">Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive">Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div><!-- /table-wrap -->
    </div><!-- /card -->

    <!-- ── Add Counselor form ── -->
    <?php if ($filter === 'counselors'): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Add New Counselor</div>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $e): ?>⚠ <?= clean($e) ?><br><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="form_action" value="add_counselor">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">First name</label>
                <input class="form-input" type="text" name="first_name" placeholder="Maria" required>
              </div>
              <div class="form-group">
                <label class="form-label">Last name</label>
                <input class="form-input" type="text" name="last_name" placeholder="Santos" required>
              </div>
              <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-input" type="email" name="email" placeholder="counselor@ctu.edu.ph" required>
              </div>
              <div class="form-group">
                <label class="form-label">Specialization <span class="opt">(optional)</span></label>
                <input class="form-input" type="text" name="specialization" placeholder="e.g. Anxiety, Stress Management">
              </div>
              <div class="form-group">
                <label class="form-label">Temporary password</label>
                <input class="form-input" type="password" name="password" placeholder="Min. 8 characters" required>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary">Add Counselor</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- ── Add Admin form ── -->
    <?php if ($filter === 'admins'): ?>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Add New System Admin</div>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $e): ?>⚠ <?= clean($e) ?><br><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="form_action" value="add_admin">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">First name</label>
                <input class="form-input" type="text" name="admin_first" placeholder="Juan" required>
              </div>
              <div class="form-group">
                <label class="form-label">Last name</label>
                <input class="form-input" type="text" name="admin_last" placeholder="Dela Cruz" required>
              </div>
              <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-input" type="email" name="admin_email" placeholder="admin@ctu.edu.ph" required>
              </div>
              <div class="form-group">
                <label class="form-label">Password</label>
                <input class="form-input" type="password" name="admin_password" placeholder="Min. 8 characters" required>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-dark">Add System Admin</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php endif; // end non-crisis view ?>

  </main>
</div>

</body>
</html>