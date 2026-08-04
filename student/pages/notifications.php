<?php
// ============================================================
//  CTU MindCare — Notifications
//  File: student/pages/notifications.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── MARK ALL READ ─────────────────────────────────────────────
if (isset($_GET['mark_all_read'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
       ->execute([$user['id']]);
    setFlash('success', 'All notifications marked as read.');
    header('Location: notifications.php');
    exit;
}

// ── MARK ONE READ & redirect ──────────────────────────────────
if (isset($_GET['read']) && ($nid = (int)$_GET['read'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
       ->execute([$nid, $user['id']]);
    // Redirect to relevant page based on type
    $stmt = $db->prepare("SELECT type FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$nid, $user['id']]);
    $n = $stmt->fetch();
    $redirect = match($n['type'] ?? '') {
        'appointment'    => 'appointments.php',
        'assessment_due' => 'assessment.php',
        'crisis'         => 'crisis.php',
        'mood_reminder'  => 'mood-checkin.php',
        default          => 'notifications.php',
    };
    header('Location: ' . $redirect);
    exit;
}

// ── DELETE ONE ────────────────────────────────────────────────
if (isset($_GET['delete']) && ($nid = (int)$_GET['delete'])) {
    $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")
       ->execute([$nid, $user['id']]);
    header('Location: notifications.php');
    exit;
}

// ── DELETE ALL READ ───────────────────────────────────────────
if (isset($_GET['clear_read'])) {
    $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")
       ->execute([$user['id']]);
    setFlash('success', 'Read notifications cleared.');
    header('Location: notifications.php');
    exit;
}

// ── Filter ────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';  // all | unread | appointment | assessment_due | crisis | mood_reminder | general
$valid_filters = ['all','unread','appointment','assessment_due','crisis','mood_reminder','general'];
if (!in_array($filter, $valid_filters)) $filter = 'all';

$where  = ['user_id = ?'];
$params = [$user['id']];

if ($filter === 'unread') {
    $where[]  = 'is_read = 0';
} elseif ($filter !== 'all') {
    $where[]  = 'type = ?';
    $params[] = $filter;
}

$stmt = $db->prepare("
    SELECT id, type, message, is_read, created_at
    FROM notifications
    WHERE " . implode(' AND ', $where) . "
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// ── Counts ────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(*)                                                           AS total,
        SUM(is_read = 0)                                                   AS unread,
        SUM(type = 'appointment')                                          AS appt,
        SUM(type = 'assessment_due')                                       AS assess,
        SUM(type = 'crisis')                                               AS crisis,
        SUM(type = 'mood_reminder')                                        AS mood,
        SUM(type = 'general')                                              AS general
    FROM notifications
    WHERE user_id = ?
");
$stmt->execute([$user['id']]);
$counts = $stmt->fetch();

// ── Helpers ──────────────────────────────────────────────────
function notifIcon(string $t): string {
    return match($t) {
        'appointment'    => '📅',
        'assessment_due' => '📋',
        'crisis'         => '🆘',
        'mood_reminder'  => '😊',
        'general'        => '💬',
        default          => '🔔',
    };
}
function notifColor(string $t): array {
    return match($t) {
        'appointment'    => ['bg'=>'#EAF7F2','border'=>'#C4E8DC','text'=>'#2D6B57','dot'=>'#4A9B7F'],
        'assessment_due' => ['bg'=>'#EEEDFE','border'=>'#CBC6F0','text'=>'#4B3FA0','dot'=>'#7B6FC4'],
        'crisis'         => ['bg'=>'#FBEDED','border'=>'#F5C6C2','text'=>'#7A1A1A','dot'=>'#D64545'],
        'mood_reminder'  => ['bg'=>'#FDF3DF','border'=>'#FAC775','text'=>'#7A4F0A','dot'=>'#D4860A'],
        'general'        => ['bg'=>'#F7F3EE','border'=>'#D9D3C9','text'=>'#5C5B57','dot'=>'#9A9891'],
        default          => ['bg'=>'#F7F3EE','border'=>'#D9D3C9','text'=>'#5C5B57','dot'=>'#9A9891'],
    };
}
function notifLink(string $t, int $id): string {
    return 'notifications.php?read=' . $id;
}
function notifTypeLabel(string $t): string {
    return match($t) {
        'appointment'    => 'Appointment',
        'assessment_due' => 'Assessment',
        'crisis'         => 'Crisis',
        'mood_reminder'  => 'Mood reminder',
        'general'        => 'General',
        default          => 'Notification',
    };
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($dt));
}
function fullDate(string $dt): string {
    return date('F j, Y \a\t g:i A', strtotime($dt));
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Notifications — CTU MindCare</title>
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
      font-family: 'DM Sans', sans-serif; font-size:14px; color:var(--text);
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
    .sb-user-card { background:var(--sand); border-radius:10px; padding:12px; display:flex; align-items:center; gap:10px; }
    .su-av  { width:32px; height:32px; border-radius:50%; background:var(--sage-mid); color:var(--sage-dark); font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .su-name{ font-size:12px; font-weight:500; color:var(--text); }
    .su-id  { font-size:10px; color:var(--text-3); margin-top:1px; }
    .notif-nav-badge { min-width:18px; height:18px; background:var(--coral); color:#fff; border-radius:9px; font-size:10px; font-weight:600; display:inline-flex; align-items:center; justify-content:center; padding:0 4px; margin-left:auto; }

    /* ── Main ── */
    .main { padding:28px 28px 56px; }

    /* ── Flash ── */
    .flash { padding:11px 16px; border-radius:9px; font-size:13px; margin-bottom:20px; display:flex; align-items:center; gap:8px; }
    .flash-success { background:var(--sage-light); border:1px solid var(--sage-mid); color:var(--sage-dark); }

    /* ── Page header ── */
    .page-header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:24px; gap:16px; flex-wrap:wrap; }
    .page-title  { font-family:'DM Serif Display',serif; font-size:26px; color:var(--text); margin-bottom:4px; display:flex; align-items:center; gap:10px; }
    .unread-badge { font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; background:var(--coral); color:#fff; padding:3px 10px; border-radius:20px; }
    .page-sub    { font-size:13px; color:var(--text-3); line-height:1.6; }
    .header-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .btn-mark-all { padding:8px 16px; background:var(--sage-light); color:var(--sage-dark); border:1px solid var(--sage-mid); border-radius:9px; font-family:'DM Sans',sans-serif; font-size:12px; font-weight:500; cursor:pointer; transition:background .15s; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
    .btn-mark-all:hover { background:var(--sage-mid); }
    .btn-clear-read { padding:8px 16px; background:var(--white); color:var(--text-3); border:1px solid var(--border); border-radius:9px; font-family:'DM Sans',sans-serif; font-size:12px; cursor:pointer; transition:all .15s; text-decoration:none; display:inline-flex; align-items:center; gap:5px; }
    .btn-clear-read:hover { border-color:var(--red); color:var(--red); }

    /* ── Filter chips ── */
    .filter-row { display:flex; gap:6px; margin-bottom:20px; flex-wrap:wrap; }
    .filter-chip { display:inline-flex; align-items:center; gap:5px; padding:6px 14px; border-radius:20px; font-size:12px; font-weight:500; border:1.5px solid var(--border); background:var(--white); color:var(--text-2); text-decoration:none; transition:all .12s; white-space:nowrap; }
    .filter-chip:hover  { border-color:var(--sage); color:var(--sage); background:var(--sage-light); }
    .filter-chip.active { background:var(--sage); border-color:var(--sage); color:#fff; }
    .chip-num { font-size:10px; opacity:.8; }

    /* ── Notifications list ── */
    .notif-list { display:flex; flex-direction:column; gap:6px; max-width:720px; }

    /* Date group header */
    .date-group-label { font-size:11px; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:.06em; padding:16px 0 6px; border-bottom:1px solid var(--border); margin-bottom:8px; }
    .date-group-label:first-child { padding-top:0; }

    /* Notification item */
    .notif-item {
      display:flex; align-items:flex-start; gap:14px;
      padding:14px 16px; border-radius:12px;
      border:1px solid var(--border); background:var(--white);
      position:relative; transition:box-shadow .15s, transform .1s;
      cursor:pointer;
    }
    .notif-item:hover { box-shadow:0 4px 16px rgba(0,0,0,.06); transform:translateY(-1px); }
    .notif-item.unread { background:var(--white); border-color:var(--sage-mid); }
    .notif-item.unread::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:12px 0 0 12px; background:var(--sage); }
    .notif-item.crisis  { border-color:#F5C6C2; }
    .notif-item.crisis.unread::before { background:var(--red); }
    .notif-item.assessment_due.unread::before { background:var(--lav); }
    .notif-item.appointment.unread::before { background:var(--sage); }
    .notif-item.mood_reminder.unread::before { background:var(--amber); }

    /* Icon */
    .ni-icon { width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }

    /* Body */
    .ni-body  { flex:1; min-width:0; }
    .ni-top   { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; margin-bottom:4px; }
    .ni-type  { font-size:10px; font-weight:600; text-transform:uppercase; letter-spacing:.05em; }
    .ni-time  { font-size:11px; color:var(--text-3); white-space:nowrap; flex-shrink:0; }
    .ni-msg   { font-size:13px; color:var(--text-2); line-height:1.6; }
    .ni-msg.unread { color:var(--text); font-weight:500; }
    .ni-actions { display:flex; gap:8px; margin-top:8px; }
    .ni-btn { font-size:11px; padding:4px 12px; border-radius:7px; border:1px solid var(--border); background:var(--white); color:var(--text-2); cursor:pointer; font-family:'DM Sans',sans-serif; transition:all .12s; text-decoration:none; display:inline-flex; align-items:center; gap:4px; }
    .ni-btn:hover { border-color:var(--sage); color:var(--sage); }
    .ni-btn.primary { background:var(--sage); color:#fff; border-color:var(--sage); }
    .ni-btn.primary:hover { background:var(--sage-2); }
    .ni-btn.danger  { color:var(--red); border-color:#F5C6C2; }
    .ni-btn.danger:hover  { background:#FBEDED; }

    /* Unread dot */
    .ni-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:6px; }

    /* ── Empty state ── */
    .empty-state { text-align:center; padding:64px 24px; max-width:480px; margin:0 auto; }
    .es-icon  { font-size:52px; margin-bottom:16px; }
    .es-title { font-family:'DM Serif Display',serif; font-size:22px; color:var(--text); margin-bottom:8px; }
    .es-sub   { font-size:13px; color:var(--text-3); line-height:1.7; }

    /* ── Stats row ── */
    .stats-row { display:grid; grid-template-columns:repeat(auto-fill,minmax(120px,1fr)); gap:10px; margin-bottom:24px; }
    .stat-card { background:var(--white); border:1px solid var(--border); border-radius:12px; padding:14px 16px; text-align:center; }
    .sc-num    { font-family:'DM Serif Display',serif; font-size:26px; color:var(--text); line-height:1; }
    .sc-label  { font-size:11px; color:var(--text-3); margin-top:4px; }

    @media (max-width:900px) { .layout { grid-template-columns:1fr; }  }
  
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
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
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

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        ✓ <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header">
      <div>
        <div class="page-title">
          Notifications
          <?php if ((int)($counts['unread'] ?? 0) > 0): ?>
            <span class="unread-badge"><?= (int)$counts['unread'] ?> new</span>
          <?php endif; ?>
        </div>
        <div class="page-sub">Stay up to date with your wellness activity.</div>
      </div>
      <div class="header-actions">
        <?php if ((int)($counts['unread'] ?? 0) > 0): ?>
          <a class="btn-mark-all" href="notifications.php?mark_all_read=1">
            ✓ Mark all as read
          </a>
        <?php endif; ?>
        <?php if ((int)($counts['total'] ?? 0) > (int)($counts['unread'] ?? 0)): ?>
          <a class="btn-clear-read" href="notifications.php?clear_read=1"
             onclick="return confirm('Delete all read notifications?')">
            🗑 Clear read
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats row -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="sc-num"><?= (int)($counts['total'] ?? 0) ?></div>
        <div class="sc-label">Total</div>
      </div>
      <div class="stat-card">
        <div class="sc-num" style="color:var(--coral)"><?= (int)($counts['unread'] ?? 0) ?></div>
        <div class="sc-label">Unread</div>
      </div>
      <div class="stat-card">
        <div class="sc-num" style="color:var(--sage-dark)"><?= (int)($counts['appt'] ?? 0) ?></div>
        <div class="sc-label">Appointments</div>
      </div>
      <div class="stat-card">
        <div class="sc-num" style="color:var(--lav)"><?= (int)($counts['assess'] ?? 0) ?></div>
        <div class="sc-label">Assessments</div>
      </div>
      <div class="stat-card">
        <div class="sc-num" style="color:var(--red)"><?= (int)($counts['crisis'] ?? 0) ?></div>
        <div class="sc-label">Crisis alerts</div>
      </div>
    </div>

    <!-- Filter chips -->
    <div class="filter-row">
      <?php
      $chips = [
          'all'            => ['label' => 'All',          'icon' => '🔔', 'count' => $counts['total']   ?? 0],
          'unread'         => ['label' => 'Unread',       'icon' => '🔴', 'count' => $counts['unread']  ?? 0],
          'appointment'    => ['label' => 'Appointments', 'icon' => '📅', 'count' => $counts['appt']    ?? 0],
          'assessment_due' => ['label' => 'Assessments',  'icon' => '📋', 'count' => $counts['assess']  ?? 0],
          'mood_reminder'  => ['label' => 'Mood',         'icon' => '😊', 'count' => $counts['mood']    ?? 0],
          'crisis'         => ['label' => 'Crisis',       'icon' => '🆘', 'count' => $counts['crisis']  ?? 0],
          'general'        => ['label' => 'General',      'icon' => '💬', 'count' => $counts['general'] ?? 0],
      ];
      foreach ($chips as $slug => $chip):
        $active = ($filter === $slug);
      ?>
        <a class="filter-chip <?= $active ? 'active' : '' ?>"
           href="notifications.php?filter=<?= $slug ?>">
          <?= $chip['icon'] ?> <?= $chip['label'] ?>
          <span class="chip-num"><?= (int)$chip['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Notifications list -->
    <?php if (empty($notifications)): ?>
      <div class="empty-state">
        <div class="es-icon">
          <?= $filter === 'unread' ? '✅' : '🔔' ?>
        </div>
        <div class="es-title">
          <?= $filter === 'unread' ? 'All caught up!' : 'No notifications' ?>
        </div>
        <div class="es-sub">
          <?php if ($filter === 'unread'): ?>
            You have no unread notifications. Great job staying on top of things!
          <?php elseif ($filter !== 'all'): ?>
            No <?= notifTypeLabel($filter) ?> notifications yet.
            <a href="notifications.php" style="color:var(--sage)">View all notifications</a>
          <?php else: ?>
            You don't have any notifications yet. They'll appear here when there's something to know about.
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>

      <div class="notif-list">
        <?php
        $prev_date = '';
        foreach ($notifications as $n):
          $n_date = date('Y-m-d', strtotime($n['created_at']));
          $colors = notifColor($n['type']);
          $is_unread = !(bool)$n['is_read'];

          // Date group separator
          if ($n_date !== $prev_date):
            $today     = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $dlabel = $n_date === $today     ? 'Today'
                    : ($n_date === $yesterday ? 'Yesterday'
                    : date('F j, Y', strtotime($n_date)));
        ?>
          <div class="date-group-label"><?= $dlabel ?></div>
        <?php
            $prev_date = $n_date;
          endif;

          // Action URL based on type
          $action_url = match($n['type']) {
              'appointment'    => 'appointments.php',
              'assessment_due' => 'assessment.php',
              'crisis'         => 'crisis.php',
              'mood_reminder'  => 'mood-checkin.php',
              default          => null,
          };
          $action_label = match($n['type']) {
              'appointment'    => '📅 View appointments',
              'assessment_due' => '📋 Take assessment',
              'crisis'         => '🆘 Crisis page',
              'mood_reminder'  => '😊 Check in now',
              default          => null,
          };
        ?>
          <div class="notif-item <?= $is_unread ? 'unread' : '' ?> <?= $n['type'] ?>"
               onclick="markRead(<?= $n['id'] ?>)">

            <!-- Unread indicator dot -->
            <div class="ni-dot" style="background:<?= $is_unread ? $colors['dot'] : 'transparent' ?>"></div>

            <!-- Icon -->
            <div class="ni-icon" style="background:<?= $colors['bg'] ?>">
              <?= notifIcon($n['type']) ?>
            </div>

            <!-- Body -->
            <div class="ni-body">
              <div class="ni-top">
                <span class="ni-type" style="color:<?= $colors['text'] ?>">
                  <?= notifTypeLabel($n['type']) ?>
                </span>
                <span class="ni-time" title="<?= fullDate($n['created_at']) ?>">
                  <?= timeAgo($n['created_at']) ?>
                </span>
              </div>
              <div class="ni-msg <?= $is_unread ? 'unread' : '' ?>">
                <?= clean($n['message']) ?>
              </div>
              <div class="ni-actions" onclick="event.stopPropagation()">
                <?php if ($is_unread): ?>
                  <a class="ni-btn" href="notifications.php?read=<?= $n['id'] ?>">
                    ✓ Mark read
                  </a>
                <?php endif; ?>
                <?php if ($action_url && $action_label): ?>
                  <a class="ni-btn primary"
                     href="notifications.php?read=<?= $n['id'] ?>">
                    <?= $action_label ?>
                  </a>
                <?php endif; ?>
                <a class="ni-btn danger"
                   href="notifications.php?delete=<?= $n['id'] ?>"
                   onclick="return confirm('Delete this notification?')">
                  🗑
                </a>
              </div>
            </div>

          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>

  </main>
</div>

<script>
// Mark read on click of the card itself, then redirect to action URL if available
const actionUrls = {
  <?php foreach ($notifications as $n):
    $url = match($n['type']) {
        'appointment'    => 'appointments.php',
        'assessment_due' => 'assessment.php',
        'crisis'         => 'crisis.php',
        'mood_reminder'  => 'mood-checkin.php',
        default          => null,
    };
    if ($url): ?>
  <?= (int)$n['id'] ?>: '<?= $url ?>',
  <?php endif; endforeach; ?>
};

function markRead(id) {
  // If already read, and there's an action URL, go there directly
  const target = actionUrls[id];
  if (target) {
    window.location.href = 'notifications.php?read=' + id;
  } else {
    // Just mark read silently via fetch then update UI
    fetch('notifications.php?read=' + id).catch(() => {});
    const item = document.querySelector(`.notif-item[onclick="markRead(${id})"]`);
    if (item) {
      item.classList.remove('unread');
      const dot = item.querySelector('.ni-dot');
      if (dot) dot.style.background = 'transparent';
      const msg = item.querySelector('.ni-msg');
      if (msg) msg.classList.remove('unread');
      // Remove mark-read button
      const markBtn = item.querySelector('.ni-btn:not(.primary):not(.danger)');
      if (markBtn) markBtn.remove();
    }
  }
}

// Auto-refresh unread badge in topbar every 30 seconds
setInterval(async () => {
  try {
    const res  = await fetch('notifications.php?filter=unread&_ajax=1');
    // We just use this to keep session alive; full refresh happens on nav
  } catch(e) {}
}, 30000);
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