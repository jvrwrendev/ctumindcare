<?php
// ============================================================
//  CTU MindCare — Edit Profile
//  File: student/pages/profile.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$errors  = [];
$success = '';
$section = $_POST['section'] ?? '';

// ── SAVE PROFILE INFO ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'profile') {
    $first_name   = trim($_POST['first_name']   ?? '');
    $last_name    = trim($_POST['last_name']    ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $email        = trim($_POST['email']        ?? '');

    if (strlen($first_name) < 1)    $errors[] = 'First name is required.';
    if (strlen($last_name) < 1)     $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($display_name) > 50) $errors[] = 'Display name must be 50 characters or fewer.';

    // Check email not taken by another user
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) $errors[] = 'That email is already in use by another account.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, display_name = ?, email = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $first_name,
            $last_name,
            $display_name ?: null,
            $email,
            $user['id'],
        ]);

        // Refresh session name
        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
        $user = currentUser(); // re-fetch
        setFlash('success', 'Profile updated successfully.');
        header('Location: profile.php');
        exit;
    }
}

// ── CHANGE PASSWORD ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'password') {
    $current  = $_POST['current_password']  ?? '';
    $new_pass = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    // Fetch current hash
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!verifyPassword($current, $row['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new_pass) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } elseif (verifyPassword($new_pass, $row['password'])) {
        $errors[] = 'New password must be different from your current password.';
    }

    if (empty($errors)) {
        $hash = hashPassword($new_pass);
        $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$hash, $user['id']]);
        setFlash('success', 'Password changed successfully.');
        header('Location: profile.php');
        exit;
    }
}

// ── Account stats ─────────────────────────────────────────────
// Fetch timestamps not included in currentUser()
$stmt = $db->prepare("SELECT created_at, updated_at FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_timestamps = $stmt->fetch();
$user['created_at'] = $user_timestamps['created_at'] ?? null;
$user['updated_at'] = $user_timestamps['updated_at'] ?? null;

$stmt = $db->prepare("SELECT COUNT(DISTINCT DATE(recorded_at)) as mood_days FROM mood_entries WHERE user_id = ?");
$stmt->execute([$user['id']]);
$mood_days = (int)$stmt->fetch()['mood_days'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM assessment_results WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_assessments = (int)$stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM journal_entries WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_journals = (int)$stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM appointments WHERE student_id = ? AND status != 'cancelled'");
$stmt->execute([$user['id']]);
$total_appointments = (int)$stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM forum_posts WHERE user_id = ? AND is_removed = 0");
$stmt->execute([$user['id']]);
$total_posts = (int)$stmt->fetch()['total'];

// Member since
$member_since = date('F Y', strtotime($user['created_at'] ?? 'now'));

$flash = getFlash();
$my_initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Profile — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    :root {
      --sage:       #4A9B7F; --sage-2:    #3d8a6f; --sage-dark: #2D6B57;
      --sage-mid:   #C4E8DC; --sage-light:#EAF7F2;
      --sand:       #F7F3EE; --sand-2:    #EDE8E1; --sand-3:    #D9D3C9;
      --white:      #FFFFFF; --text:      #2C2C2A; --text-2:    #5C5B57;
      --text-3:     #9A9891; --border:    #E8E4DE;
      --red:        #D64545; --amber:     #D4860A; --amber-light:#FDF3DF;
      --coral:      #D96B52; --coral-light:#FAECE7;
      --lav:        #7B6FC4; --lav-light: #EEEDFE;
      --topbar-h:   58px;    --sidebar-w: 230px;
    }
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    html, body {
      font-family:'DM Sans',sans-serif; font-size:14px; color:var(--text);
      background:var(--sand); min-height:100vh; -webkit-font-smoothing:antialiased;
    }
    a { text-decoration:none; color:inherit; }

    /* ── Topbar ── */
    .topbar { height:var(--topbar-h); background:var(--white); border-bottom:1px solid var(--border); display:flex; align-items:center; padding:0 24px; position:sticky; top:0; z-index:200; gap:16px; }
    .topbar-brand  { display:flex; align-items:center; gap:10px; margin-right:auto; }
    .topbar-mark   { width:34px; height:34px; background:var(--sage); border-radius:9px; display:flex; align-items:center; justify-content:center; font-family:'DM Serif Display',serif; font-size:17px; color:#fff; }
    .topbar-name   { font-family:'DM Serif Display',serif; font-size:17px; color:var(--text); }
    .topbar-school { font-size:10px; color:var(--text-3); margin-top:1px; }
    .topbar-right  { display:flex; align-items:center; gap:12px; }
    .user-chip     { display:flex; align-items:center; gap:8px; background:var(--sage-light); border:1px solid var(--sage-mid); border-radius:100px; padding:5px 14px 5px 6px; }
    .user-av       { width:26px; height:26px; border-radius:50%; background:var(--sage); display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:600; color:#fff; }
    .user-name     { font-size:12px; font-weight:500; color:var(--sage-dark); }
    .logout-btn    { font-size:12px; color:var(--text-3); padding:6px 12px; border-radius:7px; border:1px solid var(--border); background:var(--white); transition:color .15s,border-color .15s; }
    .logout-btn:hover { color:var(--coral); border-color:var(--coral); }

    /* ── Layout ── */
    .layout { display:grid; grid-template-columns:var(--sidebar-w) 1fr; min-height:calc(100vh - var(--topbar-h)); }

    /* ── Sidebar ── */
    .sidebar { background:var(--white); border-right:1px solid var(--border); padding:20px 0; position:sticky; top:var(--topbar-h); height:calc(100vh - var(--topbar-h)); overflow-y:auto; display:flex; flex-direction:column; }
    .sb-section { padding:0 14px; margin-bottom:6px; }
    .sb-label   { font-size:9px; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:1px; padding:0 8px; margin-bottom:4px; display:block; }
    .nav-item   { display:flex; align-items:center; gap:10px; padding:9px 10px; border-radius:9px; font-size:13px; color:var(--text-2); text-decoration:none; transition:all .12s; margin-bottom:1px; }
    .nav-item:hover  { background:var(--sand); color:var(--text); }
    .nav-item.active { background:var(--sage-light); color:var(--sage-dark); font-weight:500; }
    .nav-icon   { width:28px; height:28px; border-radius:7px; display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; background:var(--sand); }
    .nav-item.active .nav-icon { background:var(--sage-mid); }
    .nav-item.crisis-nav { color:var(--coral); }
    .nav-item.crisis-nav:hover { background:var(--coral-light); }
    .nav-item.crisis-nav .nav-icon { background:var(--coral-light); }
    .sb-divider { height:1px; background:var(--border); margin:10px 14px; }
    .sb-footer  { margin-top:auto; padding:14px; }
    .sb-user-card { background:var(--sage-light); border:1px solid var(--sage-mid); border-radius:10px; padding:12px; display:flex; align-items:center; gap:10px; cursor:pointer; transition:background .12s; }
    .sb-user-card:hover { background:var(--sage-mid); }
    .su-av  { width:32px; height:32px; border-radius:50%; background:var(--sage); color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .su-name{ font-size:12px; font-weight:500; color:var(--sage-dark); }
    .su-id  { font-size:10px; color:var(--sage); margin-top:1px; }
    .su-edit{ font-size:10px; color:var(--sage); margin-left:auto; }

    /* ── Main ── */
    .main { padding:28px 28px 56px; }

    /* ── Flash ── */
    .flash { padding:11px 16px; border-radius:9px; font-size:13px; margin-bottom:20px; display:flex; align-items:center; gap:8px; }
    .flash-success { background:var(--sage-light); border:1px solid var(--sage-mid); color:var(--sage-dark); }
    .flash-error   { background:#FBEDED; border:1px solid #F5C6C2; color:#7A1A1A; }

    /* ── Content grid ── */
    .profile-grid { display:grid; grid-template-columns:280px 1fr; gap:24px; align-items:start; }

    /* ── Profile hero card ── */
    .profile-hero { background:var(--white); border:1px solid var(--border); border-radius:16px; overflow:hidden; }
    .hero-top {
      background:linear-gradient(135deg, var(--sage-light), var(--sage-mid));
      padding:28px 24px; text-align:center;
    }
    .hero-avatar {
      width:80px; height:80px; border-radius:50%;
      background:var(--sage); color:#fff;
      font-family:'DM Serif Display',serif; font-size:30px;
      display:flex; align-items:center; justify-content:center;
      margin:0 auto 14px; border:4px solid var(--white);
      box-shadow:0 4px 16px rgba(0,0,0,.1);
    }
    .hero-name   { font-family:'DM Serif Display',serif; font-size:20px; color:var(--sage-dark); margin-bottom:4px; }
    .hero-id     { font-size:12px; color:var(--sage); }
    .hero-role   { display:inline-block; margin-top:8px; font-size:11px; font-weight:600; background:var(--sage); color:#fff; padding:3px 12px; border-radius:20px; }
    .hero-body   { padding:18px 20px; }
    .hero-stat-row { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:16px; }
    .hero-stat   { text-align:center; padding:10px 8px; background:var(--sand); border-radius:10px; }
    .hs-num      { font-family:'DM Serif Display',serif; font-size:22px; color:var(--text); line-height:1; }
    .hs-label    { font-size:10px; color:var(--text-3); margin-top:3px; }
    .hero-info   { display:flex; flex-direction:column; gap:8px; }
    .hi-row      { display:flex; align-items:flex-start; gap:8px; font-size:12px; color:var(--text-2); }
    .hi-icon     { font-size:14px; flex-shrink:0; width:18px; text-align:center; }
    .hi-val      { flex:1; word-break:break-all; }
    .hi-label    { font-size:10px; color:var(--text-3); }

    /* ── Right column ── */
    .right-col { display:flex; flex-direction:column; gap:20px; }

    /* ── Card ── */
    .card { background:var(--white); border:1px solid var(--border); border-radius:14px; overflow:hidden; }
    .card-header { padding:18px 22px 0; border-bottom:1px solid var(--border); margin-bottom:0; display:flex; align-items:center; justify-content:space-between; padding-bottom:14px; }
    .card-title { font-size:15px; font-weight:600; color:var(--text); display:flex; align-items:center; gap:8px; }
    .card-sub   { font-size:12px; color:var(--text-3); margin-top:2px; }
    .card-body  { padding:22px; }

    /* ── Form elements ── */
    .form-grid   { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
    .form-group  { display:flex; flex-direction:column; gap:6px; }
    .form-group.full { grid-column:1/-1; }
    .form-label  { font-size:12px; font-weight:600; color:var(--text); }
    .form-hint   { font-size:11px; color:var(--text-3); }
    .form-input  {
      padding:10px 12px; background:var(--sand);
      border:1.5px solid var(--border); border-radius:9px;
      font-family:'DM Sans',sans-serif; font-size:13px; color:var(--text);
      outline:none; transition:border-color .15s, background .15s, box-shadow .15s;
    }
    .form-input:focus { background:var(--white); border-color:var(--sage); box-shadow:0 0 0 3px rgba(74,155,127,.08); }
    .form-input::placeholder { color:var(--text-3); }
    .form-input.readonly { background:var(--sand-2); color:var(--text-3); cursor:not-allowed; }
    .form-input:disabled { background:var(--sand-2); color:var(--text-3); cursor:not-allowed; }

    /* Password strength */
    .pw-strength-bar { height:4px; border-radius:2px; background:var(--sand-3); margin-top:6px; overflow:hidden; }
    .pw-strength-fill { height:100%; border-radius:2px; transition:width .3s, background .3s; width:0; }
    .pw-strength-label { font-size:11px; margin-top:4px; }

    /* ── Error list ── */
    .error-list { background:#FBEDED; border:1px solid #F5C6C2; border-radius:10px; padding:12px 16px; margin-bottom:18px; }
    .error-list li { font-size:13px; color:#7A1A1A; padding:2px 0; padding-left:14px; position:relative; list-style:none; }
    .error-list li::before { content:'✕'; position:absolute; left:0; font-size:10px; top:3px; }

    /* ── Submit row ── */
    .submit-row { display:flex; align-items:center; gap:12px; margin-top:20px; padding-top:18px; border-top:1px solid var(--border); flex-wrap:wrap; }
    .btn-save   { padding:11px 28px; background:var(--sage); color:#fff; border:none; border-radius:10px; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:500; cursor:pointer; transition:background .15s, transform .1s; }
    .btn-save:hover  { background:var(--sage-2); }
    .btn-save:active { transform:scale(.99); }
    .btn-cancel { padding:11px 20px; background:var(--white); color:var(--text-2); border:1.5px solid var(--border); border-radius:10px; font-family:'DM Sans',sans-serif; font-size:13px; cursor:pointer; transition:border-color .15s; text-decoration:none; }
    .btn-cancel:hover { border-color:var(--sage); color:var(--sage); }
    .save-note  { font-size:11px; color:var(--text-3); margin-left:auto; }

    /* ── Danger zone ── */
    .danger-zone { background:#FBEDED; border:1px solid #F5C6C2; border-radius:14px; padding:20px 22px; }
    .dz-title   { font-size:14px; font-weight:600; color:var(--red); margin-bottom:4px; display:flex; align-items:center; gap:8px; }
    .dz-sub     { font-size:12px; color:#9A4040; margin-bottom:16px; line-height:1.6; }
    .btn-danger { padding:10px 20px; background:var(--white); color:var(--red); border:1.5px solid #F5C6C2; border-radius:9px; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:500; cursor:pointer; transition:all .15s; }
    .btn-danger:hover { background:#FBEDED; border-color:var(--red); }

    /* ── Section tab nav ── */
    .section-tabs { display:flex; gap:4px; border-bottom:2px solid var(--border); padding:0 22px; background:var(--sand); }
    .section-tab  { padding:12px 16px; font-size:13px; font-weight:500; color:var(--text-3); cursor:pointer; border-bottom:2px solid transparent; margin-bottom:-2px; transition:color .15s, border-color .15s; text-decoration:none; }
    .section-tab:hover  { color:var(--text); }
    .section-tab.active { color:var(--sage-dark); border-color:var(--sage); }

    @media (max-width:1100px) { .profile-grid { grid-template-columns:1fr; } .form-grid { grid-template-columns:1fr; } }
    @media (max-width:900px)  { .layout { grid-template-columns:1fr; }  }
  
/* ── Hamburger button ── */
.hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand,#F7F3EE);border:1px solid var(--border,#E8E4DE);cursor:pointer;padding:0;flex-shrink:0;}
.hamburger span{display:block;width:18px;height:2px;background:var(--text-2,#5C5B57);border-radius:2px;transition:all .25s ease;}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex!important;}
  .layout{grid-template-columns:1fr!important;}
  .sidebar{position:fixed!important;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px)!important;width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);display:flex!important;overflow-y:auto;}
  .sidebar.mobile-open{transform:translateX(0);}
  .main,.main.main{padding:20px 16px!important;margin-left:0!important;}
}
@media(max-width:480px){.topbar-school{display:none;}.user-name{display:none;}.logout-btn{display:none;}}

</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- Topbar -->
<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
  <div class="topbar-brand">
    <div class="topbar-mark">M</div>
    <div>
      <div class="topbar-name">CTU MindCare</div>
      <div class="topbar-school">CTU Consolacion</div>
    </div>
  </div>
  <div class="topbar-right">
    <a class="user-chip" href="profile.php">
      <div class="user-av"><?= $my_initials ?></div>
      <span class="user-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="layout">

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
      <a class="nav-item" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
      <a class="nav-item" href="journal.php"><div class="nav-icon">📓</div> Wellness journal</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Support</span>
      <a class="nav-item" href="appointments.php"><div class="nav-icon">📅</div> Book appointment</a>
      <a class="nav-item" href="counselor-chat.php"><div class="nav-icon">💬</div> Counselor chat</a>
      <a class="nav-item" href="resources.php"><div class="nav-icon">📚</div> Resource library</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Community</span>
      <a class="nav-item" href="forum.php"><div class="nav-icon">🗣️</div> Peer forum</a>
      <a class="nav-item" href="peer-chat.php"><div class="nav-icon">💭</div> Peer chat</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
    </div>
    <div class="sb-footer">
      <a class="sb-user-card" href="profile.php">
        <div class="su-av"><?= $my_initials ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
        <div class="su-edit">✏ Edit</div>
      </a>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="profile-grid">

      <!-- ── LEFT: Profile hero ── -->
      <div>
        <div class="profile-hero">
          <div class="hero-top">
            <div class="hero-avatar"><?= $my_initials ?></div>
            <div class="hero-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
            <?php if ($user['student_id']): ?>
              <div class="hero-id"><?= clean($user['student_id']) ?></div>
            <?php endif; ?>
            <div class="hero-role">Student</div>
          </div>
          <div class="hero-body">
            <!-- Activity stats -->
            <div class="hero-stat-row">
              <div class="hero-stat">
                <div class="hs-num"><?= $mood_days ?></div>
                <div class="hs-label">Mood days</div>
              </div>
              <div class="hero-stat">
                <div class="hs-num"><?= $total_journals ?></div>
                <div class="hs-label">Journal entries</div>
              </div>
              <div class="hero-stat">
                <div class="hs-num"><?= $total_assessments ?></div>
                <div class="hs-label">Assessments</div>
              </div>
              <div class="hero-stat">
                <div class="hs-num"><?= $total_posts ?></div>
                <div class="hs-label">Forum posts</div>
              </div>
            </div>

            <!-- Info list -->
            <div class="hero-info">
              <div class="hi-row">
                <span class="hi-icon">📧</span>
                <div>
                  <div class="hi-label">Email</div>
                  <div class="hi-val"><?= clean($user['email']) ?></div>
                </div>
              </div>
              <?php if ($user['display_name']): ?>
              <div class="hi-row">
                <span class="hi-icon">🏷</span>
                <div>
                  <div class="hi-label">Display name (forum)</div>
                  <div class="hi-val"><?= clean($user['display_name']) ?></div>
                </div>
              </div>
              <?php endif; ?>
              <div class="hi-row">
                <span class="hi-icon">📅</span>
                <div>
                  <div class="hi-label">Member since</div>
                  <div class="hi-val"><?= $member_since ?></div>
                </div>
              </div>
              <div class="hi-row">
                <span class="hi-icon">📋</span>
                <div>
                  <div class="hi-label">Appointments attended</div>
                  <div class="hi-val"><?= $total_appointments ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── RIGHT: Edit forms ── -->
      <div class="right-col">

        <!-- ── Profile info form ── -->
        <div class="card">
          <div class="section-tabs">
            <span class="section-tab active">👤 Profile information</span>
          </div>
          <div class="card-body">

            <?php if ($section === 'profile' && !empty($errors)): ?>
              <ul class="error-list">
                <?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="profileForm">
              <input type="hidden" name="section" value="profile">

              <div class="form-grid">
                <div class="form-group">
                  <label class="form-label" for="first_name">First name <span style="color:var(--red)">*</span></label>
                  <input class="form-input" type="text" id="first_name" name="first_name"
                         value="<?= clean($section === 'profile' ? ($_POST['first_name'] ?? $user['first_name']) : $user['first_name']) ?>"
                         maxlength="50" required autocomplete="given-name">
                </div>
                <div class="form-group">
                  <label class="form-label" for="last_name">Last name <span style="color:var(--red)">*</span></label>
                  <input class="form-input" type="text" id="last_name" name="last_name"
                         value="<?= clean($section === 'profile' ? ($_POST['last_name'] ?? $user['last_name']) : $user['last_name']) ?>"
                         maxlength="50" required autocomplete="family-name">
                </div>
                <div class="form-group full">
                  <label class="form-label" for="email">Email address <span style="color:var(--red)">*</span></label>
                  <input class="form-input" type="email" id="email" name="email"
                         value="<?= clean($section === 'profile' ? ($_POST['email'] ?? $user['email']) : $user['email']) ?>"
                         maxlength="100" required autocomplete="email">
                </div>
                <div class="form-group full">
                  <label class="form-label" for="display_name">
                    Forum display name
                    <span style="font-weight:400;color:var(--text-3)">(optional)</span>
                  </label>
                  <input class="form-input" type="text" id="display_name" name="display_name"
                         value="<?= clean($section === 'profile' ? ($_POST['display_name'] ?? ($user['display_name'] ?? '')) : ($user['display_name'] ?? '')) ?>"
                         maxlength="50" placeholder="How you'll appear in the forum (leave blank to use full name)">
                  <span class="form-hint">Used in forum posts when not posting anonymously. Max 50 characters.</span>
                </div>
                <div class="form-group full">
                  <label class="form-label">Student ID</label>
                  <input class="form-input readonly" type="text"
                         value="<?= clean($user['student_id'] ?? 'N/A') ?>"
                         disabled>
                  <span class="form-hint">Student ID cannot be changed. Contact admin if there's an error.</span>
                </div>
              </div>

              <div class="submit-row">
                <button class="btn-save" type="submit">💾 Save changes</button>
                <a class="btn-cancel" href="profile.php">Cancel</a>
                <span class="save-note">Changes apply immediately after saving.</span>
              </div>
            </form>
          </div>
        </div>

        <!-- ── Change password form ── -->
        <div class="card">
          <div class="section-tabs">
            <span class="section-tab active">🔒 Change password</span>
          </div>
          <div class="card-body">

            <?php if ($section === 'password' && !empty($errors)): ?>
              <ul class="error-list">
                <?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="passwordForm">
              <input type="hidden" name="section" value="password">

              <div class="form-grid">
                <div class="form-group full">
                  <label class="form-label" for="current_password">Current password</label>
                  <input class="form-input" type="password" id="current_password" name="current_password"
                         placeholder="Enter your current password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                  <label class="form-label" for="new_password">New password</label>
                  <input class="form-input" type="password" id="new_password" name="new_password"
                         placeholder="At least 8 characters" required
                         minlength="8" autocomplete="new-password"
                         oninput="checkStrength(this.value)">
                  <div class="pw-strength-bar">
                    <div class="pw-strength-fill" id="strengthFill"></div>
                  </div>
                  <div class="pw-strength-label" id="strengthLabel" style="color:var(--text-3)"></div>
                </div>
                <div class="form-group">
                  <label class="form-label" for="confirm_password">Confirm new password</label>
                  <input class="form-input" type="password" id="confirm_password" name="confirm_password"
                         placeholder="Repeat new password" required
                         autocomplete="new-password"
                         oninput="checkMatch()">
                  <div class="form-hint" id="matchHint" style="margin-top:4px"></div>
                </div>
              </div>

              <div class="submit-row">
                <button class="btn-save" type="submit">🔒 Update password</button>
                <a class="btn-cancel" href="profile.php">Cancel</a>
              </div>
            </form>
          </div>
        </div>

        <!-- ── Account info (read-only) ── -->
        <div class="card">
          <div class="section-tabs">
            <span class="section-tab active">ℹ️ Account information</span>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Account role</label>
                <input class="form-input readonly" type="text" value="Student" disabled>
              </div>
              <div class="form-group">
                <label class="form-label">Account status</label>
                <input class="form-input readonly" type="text" value="Active ✓" disabled style="color:var(--sage-dark)">
              </div>
              <div class="form-group">
                <label class="form-label">Member since</label>
                <input class="form-input readonly" type="text" value="<?= $member_since ?>" disabled>
              </div>
              <div class="form-group">
                <label class="form-label">Last updated</label>
                <input class="form-input readonly" type="text"
                       value="<?= $user['updated_at'] ? date('M j, Y', strtotime($user['updated_at'])) : 'N/A' ?>"
                       disabled>
              </div>
            </div>
            <div style="margin-top:16px;padding:12px 14px;background:var(--lav-light);border:1px solid #CBC6F0;border-radius:10px;font-size:12px;color:var(--lav);line-height:1.7">
              🔒 <strong>Privacy note:</strong> Your student ID, account role, and creation date are managed by the system admin and cannot be changed here. For account issues, contact your guidance office.
            </div>
          </div>
        </div>

        <!-- ── Danger zone ── -->
        <div class="danger-zone">
          <div class="dz-title">⚠️ Danger zone</div>
          <div class="dz-sub">
            These actions are permanent and cannot be undone. Please be absolutely sure before proceeding.
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn-danger"
                    onclick="if(confirm('Sign out from all devices? You will be logged out everywhere.')) window.location.href='../../auth/logout.php?all=1'">
              🚪 Sign out everywhere
            </button>
            <button class="btn-danger"
                    onclick="alert('To request account deletion, please contact your guidance counselor or system administrator directly.')">
              🗑 Request account deletion
            </button>
          </div>
        </div>

      </div>
    </div>

  </main>
</div>

<script>
// ── Password strength checker ─────────────────────────────────
function checkStrength(val) {
  const fill  = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  if (!fill || !label) return;

  let score = 0;
  if (val.length >= 8)              score++;
  if (val.length >= 12)             score++;
  if (/[A-Z]/.test(val))            score++;
  if (/[0-9]/.test(val))            score++;
  if (/[^A-Za-z0-9]/.test(val))     score++;

  const levels = [
    { w: '0%',   color: 'transparent',    text: '' },
    { w: '25%',  color: '#D64545',        text: 'Weak' },
    { w: '50%',  color: '#D4860A',        text: 'Fair' },
    { w: '75%',  color: '#D4860A',        text: 'Good' },
    { w: '90%',  color: '#4A9B7F',        text: 'Strong' },
    { w: '100%', color: '#2D6B57',        text: 'Very strong ✓' },
  ];
  const lv = val.length === 0 ? 0 : Math.min(score, 5);
  fill.style.width    = levels[lv].w;
  fill.style.background = levels[lv].color;
  label.textContent   = levels[lv].text;
  label.style.color   = levels[lv].color;
}

// ── Password match checker ────────────────────────────────────
function checkMatch() {
  const np   = document.getElementById('new_password')?.value      || '';
  const cp   = document.getElementById('confirm_password')?.value  || '';
  const hint = document.getElementById('matchHint');
  if (!hint || !cp) return;
  if (np === cp) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = 'var(--sage-dark)';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = 'var(--red)';
  }
}

// ── Confirm before leaving with unsaved changes ───────────────
let profileDirty   = false;
let passwordDirty  = false;

document.querySelectorAll('#profileForm  input').forEach(el => el.addEventListener('input', () => profileDirty  = true));
document.querySelectorAll('#passwordForm input').forEach(el => el.addEventListener('input', () => passwordDirty = true));

document.querySelectorAll('form').forEach(f => {
  f.addEventListener('submit', () => { profileDirty = false; passwordDirty = false; });
});

window.addEventListener('beforeunload', e => {
  if (profileDirty || passwordDirty) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>

<script>
(function(){
  var btn=document.getElementById('hamburgerBtn');
  var sb=document.querySelector('.sidebar');
  var ov=document.getElementById('sidebarOverlay');
  if(!btn||!sb||!ov)return;
  function open(){sb.classList.add('mobile-open');ov.classList.add('active');btn.classList.add('open');btn.setAttribute('aria-expanded','true');document.body.style.overflow='hidden';}
  function close(){sb.classList.remove('mobile-open');ov.classList.remove('active');btn.classList.remove('open');btn.setAttribute('aria-expanded','false');document.body.style.overflow='';}
  btn.addEventListener('click',function(){sb.classList.contains('mobile-open')?close():open();});
  ov.addEventListener('click',close);
  sb.querySelectorAll('.nav-item').forEach(function(l){l.addEventListener('click',function(){if(window.innerWidth<=900)close();});});
  window.addEventListener('resize',function(){if(window.innerWidth>900)close();});
})();
</script>
</body>
</html>