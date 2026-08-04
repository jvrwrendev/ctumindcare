<?php
// ============================================================
//  CTU MindCare — Crisis Help
//  File: student/pages/crisis.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$error   = '';
$success = '';

// ── SUBMIT CRISIS ALERT ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_alert'])) {
    $message = trim($_POST['message'] ?? '');

    // Check for recent alert (within last 10 minutes) to prevent spam
    $stmt = $db->prepare("
        SELECT id FROM crisis_alerts
        WHERE student_id = ?
          AND status IN ('open','acknowledged')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $recent = $stmt->fetch();

    if ($recent) {
        $success = 'already_sent';
    } else {
        // Insert crisis alert
        $stmt = $db->prepare("
            INSERT INTO crisis_alerts (student_id, message) VALUES (?, ?)
        ");
        $stmt->execute([$user['id'], $message ?: null]);

        // Notify all wellness admins
        $stmt = $db->prepare("
            SELECT id FROM wellness_admins WHERE is_active = 1
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll();

        $notif_stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, message) VALUES (?, 'crisis', ?)
        ");
        $alert_msg = "🚨 CRISIS ALERT: {$user['first_name']} {$user['last_name']} needs immediate help."
                   . ($message ? " Message: \"" . mb_substr($message, 0, 100) . "\"" : '');
        foreach ($admins as $admin) {
            $notif_stmt->execute([$admin['id'], $alert_msg]);
        }

        $success = 'sent';
    }
}

// ── Past alerts by this student ───────────────────────────────
$stmt = $db->prepare("
    SELECT ca.id, ca.message, ca.status, ca.created_at, ca.resolved_at,
           u.first_name AS counselor_first, u.last_name AS counselor_last
    FROM crisis_alerts ca
    LEFT JOIN users u ON ca.acknowledged_by = u.id
    WHERE ca.student_id = ?
    ORDER BY ca.created_at DESC
    LIMIT 5
");
$stmt->execute([$user['id']]);
$past_alerts = $stmt->fetchAll();

// Open/acknowledged alert (active crisis)
$active_alert = null;
foreach ($past_alerts as $a) {
    if (in_array($a['status'], ['open', 'acknowledged'])) {
        $active_alert = $a;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Crisis Help — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    :root {
      --sage:        #4A9B7F; --sage-2:     #3d8a6f; --sage-dark:  #2D6B57;
      --sage-mid:    #C4E8DC; --sage-light: #EAF7F2;
      --sand:        #F7F3EE; --sand-2:     #EDE8E1; --sand-3:     #D9D3C9;
      --white:       #FFFFFF; --text:       #2C2C2A; --text-2:     #5C5B57;
      --text-3:      #9A9891; --border:     #E8E4DE;
      --red:         #D64545; --red-dark:   #B83030; --red-light:  #FBEDED;
      --amber:       #D4860A; --amber-light:#FDF3DF;
      --coral:       #D96B52; --coral-light:#FAECE7;
      --lav:         #7B6FC4; --lav-light:  #EEEDFE;
      --topbar-h:    58px;    --sidebar-w:  230px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text);
      background: var(--sand); min-height: 100vh; -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }

    /* ── Topbar ── */
    .topbar { height: var(--topbar-h); background: var(--white); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 24px; position: sticky; top: 0; z-index: 200; gap: 16px; }
    .topbar-brand  { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark   { width: 34px; height: 34px; background: var(--sage); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; }
    .topbar-name   { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
    .topbar-school { font-size: 10px; color: var(--text-3); margin-top: 1px; }
    .topbar-right  { display: flex; align-items: center; gap: 12px; }
    .user-chip     { display: flex; align-items: center; gap: 8px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 100px; padding: 5px 14px 5px 6px; }
    .user-av       { width: 26px; height: 26px; border-radius: 50%; background: var(--sage); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; }
    .user-name     { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
    .logout-btn    { font-size: 12px; color: var(--text-3); padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: color .15s, border-color .15s; }
    .logout-btn:hover { color: var(--coral); border-color: var(--coral); }

    /* ── Layout ── */
    .layout { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: calc(100vh - var(--topbar-h)); }

    /* ── Sidebar ── */
    .sidebar { background: var(--white); border-right: 1px solid var(--border); padding: 20px 0; position: sticky; top: var(--topbar-h); height: calc(100vh - var(--topbar-h)); overflow-y: auto; display: flex; flex-direction: column; }
    .sb-section   { padding: 0 14px; margin-bottom: 6px; }
    .sb-label     { font-size: 9px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; padding: 0 8px; margin-bottom: 4px; display: block; }
    .nav-item     { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .nav-item:hover  { background: var(--sand); color: var(--text); }
    .nav-item.active { background: var(--coral-light); color: var(--red); font-weight: 500; }
    .nav-icon     { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: var(--sand); }
    .nav-item.active .nav-icon { background: #FBEDED; }
    .nav-item.crisis-nav { color: var(--coral); }
    .nav-item.crisis-nav:hover { background: var(--coral-light); }
    .nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
    .sb-divider   { height: 1px; background: var(--border); margin: 10px 14px; }
    .sb-footer    { margin-top: auto; padding: 14px; }
    .sb-user-card { background: var(--sand); border-radius: 10px; padding: 12px; display: flex; align-items: center; gap: 10px; }
    .su-av        { width: 32px; height: 32px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .su-name      { font-size: 12px; font-weight: 500; color: var(--text); }
    .su-id        { font-size: 10px; color: var(--text-3); margin-top: 1px; }

    /* ── Main ── */
    .main { padding: 0; }

    /* ── Emergency top banner ── */
    .emergency-banner {
      background: linear-gradient(135deg, #B91C1C, #DC2626);
      color: #fff; padding: 14px 28px;
      display: flex; align-items: center; justify-content: space-between;
      gap: 16px; flex-wrap: wrap;
    }
    .eb-left   { display: flex; align-items: center; gap: 12px; }
    .eb-dot    { width: 10px; height: 10px; border-radius: 50%; background: #fff; animation: pulse 1.5s ease-in-out infinite; flex-shrink: 0; }
    @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(1.3)} }
    .eb-text   { font-size: 13px; font-weight: 500; line-height: 1.5; }
    .eb-nums   { font-size: 12px; opacity: .85; margin-top: 2px; }
    .eb-call   { display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; background: #fff; color: var(--red-dark); border-radius: 9px; font-size: 13px; font-weight: 700; transition: transform .1s; flex-shrink: 0; }
    .eb-call:hover { transform: scale(1.02); }

    /* ── Content wrapper ── */
    .content-wrap { padding: 28px 28px 56px; }

    /* ── Section title ── */
    .section-title { font-family: 'DM Serif Display', serif; font-size: 20px; color: var(--text); margin-bottom: 4px; }
    .section-sub   { font-size: 13px; color: var(--text-3); margin-bottom: 20px; line-height: 1.6; }

    /* ── Page grid ── */
    .page-grid { display: grid; grid-template-columns: 1fr 340px; gap: 24px; align-items: start; }

    /* ── Card ── */
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 22px; margin-bottom: 20px; }
    .card:last-child { margin-bottom: 0; }
    .card-title { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
    .card-sub   { font-size: 12px; color: var(--text-3); margin-bottom: 16px; line-height: 1.6; }

    /* ── Alert sent success ── */
    .alert-sent-box {
      background: linear-gradient(135deg, #FFF5F5, #FBEDED);
      border: 1.5px solid #F5C6C2; border-radius: 14px;
      padding: 28px; text-align: center; margin-bottom: 20px;
    }
    .asb-icon  { font-size: 48px; margin-bottom: 12px; }
    .asb-title { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--red); margin-bottom: 8px; }
    .asb-body  { font-size: 13px; color: #7A1A1A; line-height: 1.7; margin-bottom: 16px; }
    .asb-status { font-size: 12px; color: #9A4040; background: rgba(255,255,255,.6); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; line-height: 1.6; }
    .asb-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .btn-outline-red { padding: 10px 20px; background: var(--white); color: var(--red); border: 1.5px solid #F5C6C2; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: all .15s; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
    .btn-outline-red:hover { background: var(--red-light); border-color: var(--red); }

    /* Already-active alert notice */
    .active-alert-box {
      background: var(--amber-light); border: 1.5px solid #FAC775;
      border-radius: 12px; padding: 16px 18px; margin-bottom: 20px;
      display: flex; gap: 12px; align-items: flex-start;
    }
    .aab-icon  { font-size: 22px; flex-shrink: 0; }
    .aab-title { font-size: 13px; font-weight: 600; color: #7A4F0A; margin-bottom: 4px; }
    .aab-body  { font-size: 12px; color: #7A4F0A; line-height: 1.6; }

    /* ── Alert form ── */
    .alert-form-card {
      background: linear-gradient(135deg, #FBEDED, #FFF5F5);
      border: 1.5px solid #F5C6C2; border-radius: 14px; padding: 22px;
      margin-bottom: 20px;
    }
    .af-title   { font-size: 16px; font-weight: 600; color: var(--red); margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
    .af-sub     { font-size: 12px; color: #9A4040; margin-bottom: 16px; line-height: 1.6; }
    .form-label { display: block; font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
    .form-textarea {
      width: 100%; padding: 12px 14px;
      background: rgba(255,255,255,.7); border: 1.5px solid #F5C6C2;
      border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; color: var(--text); outline: none;
      resize: vertical; min-height: 90px; line-height: 1.6;
      transition: border-color .15s, box-shadow .15s, background .15s;
    }
    .form-textarea:focus { background: var(--white); border-color: var(--red); box-shadow: 0 0 0 3px rgba(214,69,69,.08); }
    .form-textarea::placeholder { color: #C09090; }
    .btn-alert  {
      width: 100%; padding: 14px; margin-top: 14px;
      background: var(--red); color: #fff; border: none;
      border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 15px; font-weight: 600; cursor: pointer;
      transition: background .15s, transform .1s;
      display: flex; align-items: center; justify-content: center; gap: 8px;
    }
    .btn-alert:hover  { background: var(--red-dark); }
    .btn-alert:active { transform: scale(.99); }
    .alert-note { font-size: 11px; color: #9A4040; margin-top: 10px; text-align: center; line-height: 1.6; }

    /* ── Hotlines ── */
    .hotlines-grid { display: flex; flex-direction: column; gap: 10px; }
    .hotline-item {
      display: flex; align-items: flex-start; gap: 14px;
      padding: 14px 16px; border-radius: 12px; border: 1px solid var(--border);
      background: var(--white); transition: box-shadow .15s, transform .15s;
    }
    .hotline-item:hover { box-shadow: 0 4px 16px rgba(0,0,0,.07); transform: translateY(-1px); }
    .hotline-item.emergency { background: #FFF5F5; border-color: #F5C6C2; }
    .hi-icon   { font-size: 22px; flex-shrink: 0; margin-top: 2px; }
    .hi-info   { flex: 1; }
    .hi-name   { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 2px; }
    .hi-desc   { font-size: 12px; color: var(--text-3); line-height: 1.5; margin-bottom: 6px; }
    .hi-nums   { display: flex; flex-wrap: wrap; gap: 6px; }
    .hi-num    { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-weight: 600; color: var(--red); background: var(--red-light); padding: 4px 10px; border-radius: 6px; }
    .hi-num:hover { background: #F5C6C2; }
    .hi-num.green { color: var(--sage-dark); background: var(--sage-light); }
    .hi-num.green:hover { background: var(--sage-mid); }
    .hi-num.blue  { color: #0369A1; background: #E0F2FE; }
    .hi-num.blue:hover  { background: #BAE6FD; }

    /* ── Coping strategies ── */
    .coping-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .coping-item { padding: 14px; border-radius: 12px; border: 1px solid var(--border); background: var(--white); }
    .ci-icon  { font-size: 24px; margin-bottom: 8px; }
    .ci-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
    .ci-body  { font-size: 12px; color: var(--text-3); line-height: 1.6; }

    /* ── Breathing exercise ── */
    .breathe-card {
      background: linear-gradient(135deg, var(--sage-light), #D4F0E8);
      border: 1px solid var(--sage-mid); border-radius: 14px;
      padding: 22px; text-align: center; margin-bottom: 20px;
    }
    .breathe-title   { font-size: 14px; font-weight: 600; color: var(--sage-dark); margin-bottom: 4px; }
    .breathe-sub     { font-size: 12px; color: var(--sage); margin-bottom: 20px; }
    .breathe-circle-wrap { display: flex; justify-content: center; margin-bottom: 16px; }
    .breathe-circle {
      width: 120px; height: 120px; border-radius: 50%;
      background: radial-gradient(circle, var(--sage-mid) 0%, var(--sage-light) 70%);
      border: 3px solid var(--sage-mid);
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 600; color: var(--sage-dark);
      transition: transform 4s ease-in-out;
      cursor: pointer; user-select: none;
    }
    .breathe-circle.inhale  { transform: scale(1.4); }
    .breathe-circle.exhale  { transform: scale(1);   }
    .breathe-instruction { font-size: 18px; font-weight: 600; color: var(--sage-dark); margin-bottom: 4px; }
    .breathe-count       { font-size: 13px; color: var(--sage); margin-bottom: 14px; }
    .btn-breathe { padding: 9px 22px; background: var(--sage); color: #fff; border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: background .15s; }
    .btn-breathe:hover { background: var(--sage-2); }

    /* ── Past alerts ── */
    .past-alerts-list { display: flex; flex-direction: column; gap: 10px; }
    .past-alert-item { padding: 12px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--white); }
    .pai-header { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
    .pai-status { font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 10px; }
    .s-open         { background: var(--red-light);  color: var(--red); }
    .s-acknowledged { background: var(--amber-light); color: var(--amber); }
    .s-resolved     { background: var(--sage-light);  color: var(--sage-dark); }
    .pai-time   { font-size: 11px; color: var(--text-3); margin-left: auto; }
    .pai-msg    { font-size: 12px; color: var(--text-2); line-height: 1.5; font-style: italic; }
    .pai-counselor { font-size: 11px; color: var(--sage-dark); margin-top: 6px; }

    /* ── Info note ── */
    .info-note { display: flex; gap: 10px; align-items: flex-start; background: var(--lav-light); border: 1px solid #CBC6F0; border-radius: 10px; padding: 12px 14px; font-size: 12px; color: var(--lav); line-height: 1.6; }

    @media (max-width: 1100px) { .page-grid { grid-template-columns: 1fr; } }
    @media (max-width: 900px)  { .layout { grid-template-columns: 1fr; }  }
    @media (max-width: 640px)  { .coping-grid { grid-template-columns: 1fr; } }
  
/* ── Hamburger button ── */
.hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand,#F7F3EE);border:1px solid var(--border,#E8E4DE);cursor:pointer;padding:0;flex-shrink:0;}
.hamburger span{display:block;width:18px;height:2px;background:var(--text-2,#5C5B57);border-radius:2px;transition:all .25s ease;}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}

/* ── Sidebar overlay ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex;}
  .layout{grid-template-columns:1fr;}
  .sidebar{position:fixed;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px);width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);overflow-y:auto;}
  .sidebar.mobile-open{transform:translateX(0);}
  .main{padding:20px 16px;}
}

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
      <div class="user-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
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
      <a class="nav-item active crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
    </div>
    <div class="sb-footer">
      <div class="sb-user-card">
        <div class="su-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <main class="main">

    <!-- Emergency banner — always visible -->
    <div class="emergency-banner">
      <div class="eb-left">
        <div class="eb-dot"></div>
        <div>
          <div class="eb-text">In immediate danger? Call emergency services right now.</div>
          <div class="eb-nums">PH Emergency: 911 &nbsp;·&nbsp; NBI: 8523-8231 &nbsp;·&nbsp; DOH: 1555</div>
        </div>
      </div>
      <a class="eb-call" href="tel:911">📞 Call 911</a>
    </div>

    <div class="content-wrap">
      <div class="page-grid">

        <!-- ── LEFT: Alert form + Coping + Past alerts ── -->
        <div>

          <?php if ($success === 'sent'): ?>
          <!-- Alert sent confirmation -->
          <div class="alert-sent-box">
            <div class="asb-icon">🆘</div>
            <div class="asb-title">Alert sent to your counselors</div>
            <div class="asb-body">
              Your crisis alert has been sent to the CTU MindCare wellness team.
              A counselor will reach out to you as soon as possible.
              <strong>Please stay safe.</strong>
            </div>
            <div class="asb-status">
              While you wait, you can also call the hotlines on the right, or use the breathing exercise below to help stay calm.
            </div>
            <div class="asb-actions">
              <a class="btn-outline-red" href="counselor-chat.php">💬 Open counselor chat</a>
              <a class="btn-outline-red" href="crisis.php">Send another alert</a>
            </div>
          </div>

          <?php elseif ($success === 'already_sent'): ?>
          <!-- Already has active alert -->
          <div class="active-alert-box">
            <div class="aab-icon">⏳</div>
            <div>
              <div class="aab-title">Alert already active</div>
              <div class="aab-body">
                You already have an open crisis alert. Your counselor has been notified and will respond soon.
                If this is a life-threatening emergency, call <strong>911</strong> immediately.
              </div>
            </div>
          </div>
          <?php endif; ?>

          <?php if ($active_alert && $success !== 'sent'): ?>
          <!-- Active alert status card -->
          <div class="active-alert-box" style="margin-bottom:20px">
            <div class="aab-icon">🔔</div>
            <div>
              <div class="aab-title">
                You have an active crisis alert
                <span style="font-size:10px;background:<?= $active_alert['status'] === 'acknowledged' ? 'var(--amber-light)' : '#FBEDED' ?>;color:<?= $active_alert['status'] === 'acknowledged' ? 'var(--amber)' : 'var(--red)' ?>;padding:2px 8px;border-radius:10px;margin-left:6px;font-weight:600">
                  <?= ucfirst($active_alert['status']) ?>
                </span>
              </div>
              <div class="aab-body">
                Sent <?= date('M j, Y \a\t g:i A', strtotime($active_alert['created_at'])) ?>.
                <?php if ($active_alert['status'] === 'acknowledged'): ?>
                  A counselor has acknowledged your alert and will contact you shortly.
                <?php else: ?>
                  A counselor will respond as soon as possible.
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endif; ?>

          <!-- Alert form -->
          <?php if (!$active_alert || $success === 'sent'): ?>
          <div class="alert-form-card">
            <div class="af-title">🆘 Send a crisis alert</div>
            <div class="af-sub">
              This will immediately notify all CTU MindCare counselors that you need urgent help.
              They will reach out to you as soon as possible.
            </div>
            <form method="POST" action="crisis.php">
              <label class="form-label" for="crisis_message">
                What's happening?
                <span style="font-weight:400;color:#C09090">(optional — brief description helps counselors respond faster)</span>
              </label>
              <textarea
                class="form-textarea"
                id="crisis_message"
                name="message"
                placeholder="e.g. I'm feeling overwhelmed and can't cope right now…"
                maxlength="500"
                rows="3"
              ></textarea>
              <button type="submit" name="send_alert" class="btn-alert">
                🆘 &nbsp;Send crisis alert now
              </button>
              <div class="alert-note">
                Your name and student ID will be shared with the counseling team.
                For life-threatening emergencies, call <strong>911</strong> immediately.
              </div>
            </form>
          </div>
          <?php else: ?>
          <!-- Still show the form even with active alert, just in different state -->
          <div class="alert-form-card" style="opacity:.6;pointer-events:none">
            <div class="af-title">🆘 Send a crisis alert</div>
            <div class="af-sub">Your alert has already been sent. A counselor will contact you soon.</div>
          </div>
          <?php endif; ?>

          <!-- Breathing exercise -->
          <div class="breathe-card">
            <div class="breathe-title">🌬️ 4-7-8 Breathing Exercise</div>
            <div class="breathe-sub">A calming technique to reduce anxiety in moments of crisis</div>
            <div class="breathe-circle-wrap">
              <div class="breathe-circle" id="breatheCircle" onclick="startBreathe()">
                Start
              </div>
            </div>
            <div class="breathe-instruction" id="breatheInstruction">Tap to begin</div>
            <div class="breathe-count" id="breatheCount">Breathe in for 4, hold for 7, breathe out for 8</div>
            <button class="btn-breathe" onclick="startBreathe()" id="breatheBtn">
              ▶ Start exercise
            </button>
          </div>

          <!-- Coping strategies -->
          <div class="card">
            <div class="card-title">Immediate coping strategies</div>
            <div class="card-sub">Things you can do right now while waiting for support.</div>
            <div class="coping-grid">
              <div class="coping-item">
                <div class="ci-icon">👀</div>
                <div class="ci-title">5-4-3-2-1 Grounding</div>
                <div class="ci-body">Name 5 things you see, 4 you can touch, 3 you hear, 2 you smell, 1 you taste.</div>
              </div>
              <div class="coping-item">
                <div class="ci-icon">🧊</div>
                <div class="ci-title">Cold water</div>
                <div class="ci-body">Splash cold water on your face or hold a cold glass. It triggers a calming reflex.</div>
              </div>
              <div class="coping-item">
                <div class="ci-icon">🚶</div>
                <div class="ci-title">Safe space</div>
                <div class="ci-body">Move to a quiet, safe place — a room, a bench outside, somewhere you feel secure.</div>
              </div>
              <div class="coping-item">
                <div class="ci-icon">📞</div>
                <div class="ci-title">Talk to someone</div>
                <div class="ci-body">Call a trusted friend, family member, or use the hotlines on the right.</div>
              </div>
              <div class="coping-item">
                <div class="ci-icon">✍️</div>
                <div class="ci-title">Write it out</div>
                <div class="ci-body">Write down what you're feeling. Getting it out of your head can bring immediate relief.</div>
              </div>
              <div class="coping-item">
                <div class="ci-icon">🎵</div>
                <div class="ci-title">Music or noise</div>
                <div class="ci-body">Put on calming music or a familiar show. External sound can interrupt spiraling thoughts.</div>
              </div>
            </div>
          </div>

          <!-- Past alerts -->
          <?php if ($past_alerts): ?>
          <div class="card" style="margin-top:20px">
            <div class="card-title">Your past crisis alerts</div>
            <div class="card-sub">History of alerts you've sent to the wellness team.</div>
            <div class="past-alerts-list">
              <?php foreach ($past_alerts as $a):
                $s_cls = match($a['status']) {
                    'open'         => 's-open',
                    'acknowledged' => 's-acknowledged',
                    'resolved'     => 's-resolved',
                    default        => 's-open',
                };
              ?>
                <div class="past-alert-item">
                  <div class="pai-header">
                    <span class="pai-status <?= $s_cls ?>"><?= ucfirst($a['status']) ?></span>
                    <span class="pai-time"><?= date('M j, Y · g:i A', strtotime($a['created_at'])) ?></span>
                  </div>
                  <?php if ($a['message']): ?>
                    <div class="pai-msg">"<?= clean(mb_substr($a['message'], 0, 120)) ?><?= mb_strlen($a['message']) > 120 ? '…' : '' ?>"</div>
                  <?php else: ?>
                    <div class="pai-msg" style="font-style:normal;color:var(--text-3)">No message added.</div>
                  <?php endif; ?>
                  <?php if ($a['counselor_first']): ?>
                    <div class="pai-counselor">
                      ✅ Responded by <?= clean($a['counselor_first'].' '.$a['counselor_last']) ?>
                      <?php if ($a['resolved_at']): ?>
                        · Resolved <?= date('M j', strtotime($a['resolved_at'])) ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <!-- ── RIGHT: Hotlines + Quick links ── -->
        <div>

          <!-- National hotlines -->
          <div class="card">
            <div class="card-title">📞 Crisis hotlines</div>
            <div class="card-sub">Available 24/7. You are not alone.</div>
            <div class="hotlines-grid">

              <div class="hotline-item emergency">
                <div class="hi-icon">🚨</div>
                <div class="hi-info">
                  <div class="hi-name">Emergency Services</div>
                  <div class="hi-desc">Life-threatening emergencies, immediate danger</div>
                  <div class="hi-nums">
                    <a class="hi-num" href="tel:911">📞 911</a>
                  </div>
                </div>
              </div>

              <div class="hotline-item">
                <div class="hi-icon">💙</div>
                <div class="hi-info">
                  <div class="hi-name">Hopeline Philippines</div>
                  <div class="hi-desc">24/7 suicide prevention & emotional support</div>
                  <div class="hi-nums">
                    <a class="hi-num" href="tel:028044673">📞 02-804-4673</a>
                    <a class="hi-num" href="tel:09175844673">📱 0917-584-4673</a>
                  </div>
                </div>
              </div>

              <div class="hotline-item">
                <div class="hi-icon">🤝</div>
                <div class="hi-info">
                  <div class="hi-name">In Touch Community Services</div>
                  <div class="hi-desc">Crisis intervention & mental health support</div>
                  <div class="hi-nums">
                    <a class="hi-num" href="tel:09178001123">📱 0917-800-1123</a>
                    <a class="hi-num" href="tel:09228938944">📱 0922-893-8944</a>
                  </div>
                </div>
              </div>

              <div class="hotline-item">
                <div class="hi-icon">🏥</div>
                <div class="hi-info">
                  <div class="hi-name">DOH Mental Health</div>
                  <div class="hi-desc">Department of Health mental wellness hotline</div>
                  <div class="hi-nums">
                    <a class="hi-num blue" href="tel:1555">📞 1555</a>
                  </div>
                </div>
              </div>

              <div class="hotline-item">
                <div class="hi-icon">📲</div>
                <div class="hi-info">
                  <div class="hi-name">Crisis Text Line</div>
                  <div class="hi-desc">Text-based crisis support — free & confidential</div>
                  <div class="hi-nums">
                    <span class="hi-num green">Text HELLO to 4673</span>
                  </div>
                </div>
              </div>

              <div class="hotline-item">
                <div class="hi-icon">🎓</div>
                <div class="hi-info">
                  <div class="hi-name">CTU Guidance Office</div>
                  <div class="hi-desc">On-campus counseling & student welfare services</div>
                  <div class="hi-nums">
                    <a class="hi-num green" href="counselor-chat.php">💬 Chat now</a>
                    <a class="hi-num green" href="appointments.php">📅 Book session</a>
                  </div>
                </div>
              </div>

            </div>
          </div>

          <!-- Quick actions -->
          <div class="card" style="margin-top:16px">
            <div class="card-title">Quick actions</div>
            <div style="display:flex;flex-direction:column;gap:8px;margin-top:4px">
              <a href="counselor-chat.php" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:10px;font-size:13px;font-weight:500;color:var(--sage-dark);transition:background .12s">
                <span style="font-size:18px">💬</span>
                Chat with a counselor now
              </a>
              <a href="appointments.php" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sand);border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--text-2);transition:background .12s">
                <span style="font-size:18px">📅</span>
                Book an appointment
              </a>
              <a href="resources.php?category=crisis" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sand);border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--text-2);transition:background .12s">
                <span style="font-size:18px">📚</span>
                Crisis resources library
              </a>
              <a href="peer-chat.php" style="display:flex;align-items:center;gap:10px;padding:12px 14px;background:var(--sand);border:1px solid var(--border);border-radius:10px;font-size:13px;color:var(--text-2);transition:background .12s">
                <span style="font-size:18px">💭</span>
                Talk to a peer
              </a>
            </div>
          </div>

          <!-- Privacy note -->
          <div class="info-note" style="margin-top:16px">
            <span style="font-size:16px;flex-shrink:0">🔒</span>
            <div>
              <strong>Your privacy matters.</strong> Crisis alerts are only visible to CTU MindCare counselors and wellness admins — not other students, faculty, or staff. Seeking help is a sign of strength.
            </div>
          </div>

        </div>

      </div>
    </div>

  </main>
</div>

<script>
// ── 4-7-8 Breathing exercise ───────────────────────────────────
let breatheTimer  = null;
let breathePhase  = 0;   // 0=idle 1=inhale 2=hold 3=exhale
let breatheCount  = 0;
let breatheCycles = 0;
const MAX_CYCLES  = 4;

const phases = [
  { label: 'Breathe in…', count: 4,  class: 'inhale', btn: 'Breathing in…' },
  { label: 'Hold…',       count: 7,  class: '',        btn: 'Holding…'      },
  { label: 'Breathe out…',count: 8,  class: 'exhale',  btn: 'Breathing out…'},
];

function startBreathe() {
  if (breatheTimer) {
    // Stop
    clearInterval(breatheTimer);
    breatheTimer = null;
    resetBreathe();
    return;
  }
  breatheCycles = 0;
  runPhase(0);
  document.getElementById('breatheBtn').textContent = '⏹ Stop';
}

function runPhase(phaseIdx) {
  const phase  = phases[phaseIdx];
  const circle = document.getElementById('breatheCircle');
  const instr  = document.getElementById('breatheInstruction');
  const cntEl  = document.getElementById('breatheCount');

  circle.className = 'breathe-circle ' + phase.class;
  instr.textContent = phase.label;
  let secs = phase.count;
  cntEl.textContent = secs + 's';

  breatheTimer = setInterval(() => {
    secs--;
    cntEl.textContent = secs > 0 ? secs + 's' : '';
    if (secs <= 0) {
      clearInterval(breatheTimer);
      breatheTimer = null;
      const next = phaseIdx + 1;
      if (next < phases.length) {
        runPhase(next);
      } else {
        breatheCycles++;
        if (breatheCycles >= MAX_CYCLES) {
          // Done
          circle.className = 'breathe-circle';
          instr.textContent = 'Well done! 🌿';
          cntEl.textContent = `Completed ${MAX_CYCLES} cycles. Take a moment to notice how you feel.`;
          document.getElementById('breatheBtn').textContent = '▶ Start again';
          breatheTimer = null;
        } else {
          // Next cycle
          runPhase(0);
        }
      }
    }
  }, 1000);
}

function resetBreathe() {
  const circle = document.getElementById('breatheCircle');
  const instr  = document.getElementById('breatheInstruction');
  const cntEl  = document.getElementById('breatheCount');
  circle.className = 'breathe-circle';
  circle.textContent = 'Start';
  instr.textContent = 'Tap to begin';
  cntEl.textContent = 'Breathe in for 4, hold for 7, breathe out for 8';
  document.getElementById('breatheBtn').textContent = '▶ Start exercise';
  breatheCycles = 0;
}

// Auto-start breathing if alert was just sent (helps with distress)
<?php if ($success === 'sent'): ?>
setTimeout(() => {
  document.getElementById('breatheCircle')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}, 1500);
<?php endif; ?>
</script>

<script>
(function(){
  var btn = document.getElementById('hamburgerBtn');
  var sb  = document.querySelector('.sidebar');
  var ov  = document.getElementById('sidebarOverlay');
  if(!btn||!sb||!ov) return;
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