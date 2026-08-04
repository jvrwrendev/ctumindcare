<?php
// ============================================================
//  CTU MindCare — Student Dashboard (Design + PHP merged)
//  File: student/pages/dashboard.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
if (!$user) {
    // If session is valid but user not found, use fallback with session data
    $user = [
        'id' => $_SESSION['user_id'],
        'first_name' => $_SESSION['user_name'] ?? 'Student',
        'last_name' => '',
        'email' => '',
        'role' => 'student',
        'display_name' => null,
        'profile_photo' => null,
        'student_id' => null,
    ];
}

$db   = getDB();

// ── Today's mood entry ───────────────────────────────────────
$stmt = $db->prepare("SELECT mood_level, mood_note FROM mood_entries WHERE user_id = ? AND DATE(recorded_at) = CURDATE()");
$stmt->execute([$user['id']]);
$today_entry = $stmt->fetch();

// ── Mood for the last 7 days ─────────────────────────────────
$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day, mood_level
    FROM mood_entries
    WHERE user_id = ? AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    ORDER BY recorded_at ASC
");
$stmt->execute([$user['id']]);
$week_rows = $stmt->fetchAll();

// Build a day-indexed array for the chart
$week_map = [];
foreach ($week_rows as $r) $week_map[$r['day']] = (int)$r['mood_level'];

$chart_labels = [];
$chart_data   = [];
$chart_colors = [];
$chart_border = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-{$i} days"));
    $label = date('D', strtotime($date));
    $level = $week_map[$date] ?? null;
    $chart_labels[] = $label;
    $chart_data[]   = $level;
    if ($level === null) {
        $chart_colors[] = 'rgba(217,211,201,0.3)';
        $chart_border[] = '#D9D3C9';
    } elseif ($level <= 2) {
        $chart_colors[] = 'rgba(212,134,10,0.3)';
        $chart_border[] = '#D4860A';
    } else {
        $opacity = 0.2 + ($level * 0.15);
        $chart_colors[] = "rgba(74,155,127,{$opacity})";
        $chart_border[] = '#4A9B7F';
    }
}

// ── Check-in streak (distinct days in last 30 days) ──────────
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT DATE(recorded_at)) as streak
    FROM mood_entries
    WHERE user_id = ? AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
");
$stmt->execute([$user['id']]);
$streak = (int)$stmt->fetch()['streak'];

// ── Latest assessment results (max 3) ───────────────────────
$stmt = $db->prepare("
    SELECT ar.total_score, ar.severity, ar.taken_at, at.code, at.title
    FROM assessment_results ar
    JOIN assessment_templates at ON ar.template_id = at.id
    WHERE ar.user_id = ?
    ORDER BY ar.taken_at DESC LIMIT 3
");
$stmt->execute([$user['id']]);
$recent_assessments = $stmt->fetchAll();
$latest = $recent_assessments[0] ?? null;

// ── Upcoming appointments ────────────────────────────────────
$stmt = $db->prepare("
    SELECT a.appointment_date, a.appointment_time, a.status,
           u.first_name as c_first, u.last_name as c_last
    FROM appointments a
    JOIN users u ON a.counselor_id = u.id
    WHERE a.student_id = ? AND a.appointment_date >= CURDATE()
      AND a.status != 'cancelled'
    ORDER BY a.appointment_date ASC, a.appointment_time ASC LIMIT 3
");
$stmt->execute([$user['id']]);
$appointments = $stmt->fetchAll();
$next_appt = $appointments[0] ?? null;

// ── Unread notification count ────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt->execute([$user['id']]);
$notif_count = (int)$stmt->fetch()['cnt'];

// ── Recent forum posts (for the sidebar card) ────────────────
$stmt = $db->prepare("
    SELECT title, created_at,
           (SELECT COUNT(*) FROM forum_replies WHERE post_id = fp.id) as reply_count,
           (SELECT COUNT(*) FROM forum_reactions WHERE post_id = fp.id) as react_count
    FROM forum_posts fp
    WHERE is_removed = 0
    ORDER BY created_at DESC LIMIT 2
");
$stmt->execute();
$forum_posts = $stmt->fetchAll();

// ── Helpers ──────────────────────────────────────────────────
$mood_labels = [1 => 'Very Low', 2 => 'Low', 3 => 'Okay', 4 => 'Good', 5 => 'Great'];
$mood_emoji  = [1 => '😔', 2 => '😟', 3 => '😐', 4 => '🙂', 5 => '😊'];
$hour        = (int)date('H');
$greeting    = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

function severityBadge(string $sev): string {
    return match($sev) {
        'minimal'           => 'badge-green',
        'mild'              => 'badge-amber',
        'moderate'          => 'badge-amber',
        'moderately_severe' => 'badge-red',
        'severe'            => 'badge-red',
        default             => 'badge-gray',
    };
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — CTU MindCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --sage:#4A9B7F;--sage-2:#5BAF91;--sage-light:#EAF7F2;--sage-mid:#C4E8DC;--sage-dark:#2D6B57;
  --sand:#F7F3EE;--sand-2:#EDE8E1;--sand-3:#D9D3C9;
  --lav:#7B6FC4;--lav-light:#EEEDFE;
  --amber:#D4860A;--amber-light:#FDF3DF;
  --coral:#D96B52;--coral-light:#FAECE7;
  --text:#2C2C2A;--text-2:#5C5B57;--text-3:#9A9891;--white:#FFFFFF;--border:#E8E4DE;
}
body{font-family:'DM Sans',sans-serif;background:var(--sand);min-height:100vh;color:var(--text);}

/* Topbar */
.topbar{height:58px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:200;gap:16px;}
.topbar-brand{display:flex;align-items:center;gap:10px;margin-right:auto;}
.topbar-mark{width:34px;height:34px;background:var(--sage);border-radius:9px;display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:17px;color:#fff;}
.topbar-name{font-family:'DM Serif Display',serif;font-size:17px;color:var(--text);}
.topbar-school{font-size:10px;color:var(--text-3);margin-top:1px}
.topbar-right{display:flex;align-items:center;gap:12px}
.notif-btn{position:relative;width:36px;height:36px;border-radius:9px;background:var(--sand);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;text-decoration:none;}
.notif-badge{position:absolute;top:5px;right:5px;min-width:16px;height:16px;border-radius:8px;background:var(--coral);border:1.5px solid var(--white);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;color:#fff;padding:0 3px;}
.user-chip{display:flex;align-items:center;gap:8px;background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:100px;padding:5px 14px 5px 6px;cursor:pointer;text-decoration:none;}
.user-av{width:26px;height:26px;border-radius:50%;background:var(--sage);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;color:#fff;}
.user-name{font-size:12px;font-weight:500;color:var(--sage-dark)}
.logout-btn{font-size:12px;color:var(--text-3);text-decoration:none;padding:6px 12px;border-radius:7px;border:1px solid var(--border);}
.logout-btn:hover{color:var(--coral);border-color:var(--coral)}

/* Layout */
.layout{display:grid;grid-template-columns:230px 1fr;min-height:calc(100vh - 58px)}

/* Sidebar */
.sidebar{background:var(--white);border-right:1px solid var(--border);padding:20px 0;position:sticky;top:58px;height:calc(100vh - 58px);overflow-y:auto;display:flex;flex-direction:column;}
.sb-section{padding:0 14px;margin-bottom:6px}
.sb-label{font-size:9px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:1px;padding:0 8px;margin-bottom:4px;display:block;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 10px;border-radius:9px;font-size:13px;color:var(--text-2);cursor:pointer;text-decoration:none;transition:all .12s;margin-bottom:1px;}
.nav-item:hover{background:var(--sand);color:var(--text)}
.nav-item.active{background:var(--sage-light);color:var(--sage-dark);font-weight:500}
.nav-icon{width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;background:var(--sand);}
.nav-item.active .nav-icon{background:var(--sage-mid)}
.nav-item.crisis-nav{color:var(--coral)}
.nav-item.crisis-nav:hover{background:var(--coral-light)}
.nav-item.crisis-nav .nav-icon{background:var(--coral-light)}
.sb-divider{height:1px;background:var(--border);margin:10px 14px}
.sb-footer{margin-top:auto;padding:14px;}
.sb-user-card{background:var(--sand);border-radius:10px;padding:12px;display:flex;align-items:center;gap:10px;}
.suf-av{width:34px;height:34px;border-radius:50%;background:var(--sage);color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;flex-shrink:0;}
.suf-name{font-size:12px;font-weight:500;color:var(--text)}
.suf-id{font-size:10px;color:var(--text-3)}
.suf-logout{margin-left:auto;font-size:10px;color:var(--text-3);text-decoration:none;}
.suf-logout:hover{color:var(--coral)}

/* Main */
.main{padding:28px 32px;overflow-y:auto}

/* Crisis banner */
.crisis-banner{background:linear-gradient(135deg,#FFF3EF,#FAECE7);border:1px solid #F0C4B4;border-radius:12px;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;gap:12px;}
.cb-left{display:flex;align-items:center;gap:10px}
.cb-dot{width:8px;height:8px;border-radius:50%;background:var(--coral);flex-shrink:0;animation:blink 2s ease-in-out infinite;}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.cb-title{font-size:12px;font-weight:600;color:#8B3420;margin-bottom:1px}
.cb-text{font-size:11px;color:#B05040}
.btn-crisis{background:var(--coral);color:#fff;border:none;border-radius:8px;padding:7px 16px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-block;}
.btn-crisis:hover{background:#C25A42}

/* Flash */
.flash{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.flash-success{background:var(--sage-light);border:1px solid var(--sage-mid);color:var(--sage-dark);}
.flash-warning{background:var(--amber-light);border:1px solid #FAC775;color:#412402;}
.flash-error{background:#FBEDED;border:1px solid #F5C6C2;color:#8B2020;}

/* Greeting */
.greeting-row{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;gap:16px;}
.greeting-title{font-family:'DM Serif Display',serif;font-size:26px;color:var(--text);margin-bottom:4px;}
.greeting-date{font-size:13px;color:var(--text-3)}
.checkin-cta{background:var(--sage);border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0;text-decoration:none;}
.checkin-cta:hover{background:var(--sage-2)}
.cta-emoji{font-size:28px}
.cta-text{font-size:12px;color:rgba(255,255,255,.8);margin-bottom:3px}
.cta-label{font-size:13px;font-weight:600;color:#fff;}
.checked-box{background:var(--sage-light);border-radius:12px;padding:14px 20px;display:flex;align-items:center;gap:12px;flex-shrink:0;}
.checked-emoji{font-size:28px}
.checked-label{font-size:13px;font-weight:600;color:var(--sage-dark);}
.checked-sub{font-size:11px;color:var(--sage);margin-top:2px}

/* Stat cards */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:24px}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:18px;display:flex;flex-direction:column;gap:8px;transition:box-shadow .15s;}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.06)}
.stat-top{display:flex;align-items:center;justify-content:space-between}
.stat-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.stat-pill{font-size:9px;font-weight:600;padding:3px 8px;border-radius:100px;letter-spacing:.3px;text-transform:uppercase;}
.stat-value{font-family:'DM Serif Display',serif;font-size:28px;color:var(--text);}
.stat-value.sm{font-size:16px;margin-top:4px}
.stat-sub{font-size:11px;color:var(--text-3)}
.badge-green{background:var(--sage-light);color:var(--sage-dark)}
.badge-amber{background:var(--amber-light);color:var(--amber)}
.badge-red{background:#FBEDED;color:#8B2020}
.badge-lav{background:var(--lav-light);color:var(--lav)}
.badge-gray{background:var(--sand-2);color:var(--text-3)}

/* Two-column grid */
.main-grid{display:grid;grid-template-columns:1fr 320px;gap:18px;margin-bottom:18px}
.card{background:var(--white);border:1px solid var(--border);border-radius:14px;padding:22px;}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;}
.card-title{font-size:14px;font-weight:600;color:var(--text);}
.card-link{font-size:12px;color:var(--sage);text-decoration:none;font-weight:500;}
.card-link:hover{text-decoration:underline}

/* Chart */
.chart-wrap{height:180px;position:relative}
.mood-emojis{display:flex;justify-content:space-around;padding:8px 0 0;}
.med-emoji{font-size:14px;text-align:center}
.med-label{font-size:9px;color:var(--text-3);margin-top:2px;text-align:center}

/* Assessment table */
.assess-table{width:100%;border-collapse:collapse}
.assess-table th{font-size:10px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;padding:8px 0;border-bottom:1px solid var(--border);text-align:left;}
.assess-table td{padding:12px 0;font-size:13px;border-bottom:1px solid var(--sand-2);}
.assess-table tr:last-child td{border-bottom:none}
.code-pill{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;background:var(--lav-light);color:var(--lav);margin-right:6px;}
.sev-pill{display:inline-block;padding:3px 9px;border-radius:100px;font-size:10px;font-weight:500;}

/* Right column */
.right-col{display:flex;flex-direction:column;gap:18px}
.quick-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.quick-item{background:var(--sand);border:1px solid var(--border);border-radius:10px;padding:14px;display:flex;flex-direction:column;align-items:center;gap:6px;cursor:pointer;text-decoration:none;transition:all .12s;text-align:center;}
.quick-item:hover{background:var(--white);border-color:var(--sage-mid);transform:translateY(-1px);}
.qi-icon{font-size:22px}.qi-label{font-size:11px;font-weight:500;color:var(--text-2)}

.appt-item{display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--sand-2);}
.appt-item:last-child{border-bottom:none}
.appt-date-box{background:var(--sage-light);border-radius:9px;padding:8px 10px;text-align:center;flex-shrink:0;min-width:48px;}
.adb-month{font-size:9px;font-weight:600;color:var(--sage-dark);text-transform:uppercase}
.adb-day{font-size:20px;font-weight:700;color:var(--sage-dark);line-height:1}
.appt-info{flex:1}
.appt-name{font-size:13px;font-weight:500;color:var(--text);margin-bottom:2px}
.appt-time{font-size:11px;color:var(--text-3)}
.appt-status{font-size:10px;font-weight:500;padding:3px 9px;border-radius:100px;}
.s-confirmed{background:var(--sage-light);color:var(--sage-dark)}
.s-pending{background:var(--amber-light);color:var(--amber)}

/* Resource list */
.res-list{display:flex;flex-direction:column;gap:8px}
.res-item{display:flex;align-items:center;gap:12px;padding:10px 12px;background:var(--sand);border-radius:9px;text-decoration:none;transition:background .12s;}
.res-item:hover{background:var(--sand-2)}
.res-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.res-title{font-size:12px;font-weight:500;color:var(--text);margin-bottom:2px}
.res-type{font-size:10px;color:var(--text-3)}

/* Bottom row */
.bottom-row{display:grid;grid-template-columns:1fr 1fr;gap:18px}
.journal-area{background:linear-gradient(135deg,var(--sand),var(--sand-2));border-radius:10px;padding:16px;min-height:90px;font-size:13px;color:var(--text-3);font-style:italic;cursor:pointer;border:1px dashed var(--sand-3);transition:border-color .15s;display:block;text-decoration:none;}
.journal-area:hover{border-color:var(--sage-mid)}
.forum-item{padding:10px 12px;background:var(--sand);border-radius:9px;margin-bottom:8px;text-decoration:none;display:block;}
.forum-item:hover{background:var(--sand-2)}
.fi-title{font-size:12px;font-weight:500;color:var(--text);margin-bottom:3px}
.fi-meta{font-size:10px;color:var(--text-3)}

@media(max-width:1100px){.stat-row{grid-template-columns:1fr 1fr}}
@media(max-width:900px){.layout{grid-template-columns:1fr}.main-grid{grid-template-columns:1fr}.bottom-row{grid-template-columns:1fr}}

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
    <?php if ($notif_count > 0): ?>
      <a class="notif-btn" href="notifications.php" title="<?= $notif_count ?> unread notifications">
        🔔
        <span class="notif-badge"><?= $notif_count ?></span>
      </a>
    <?php endif; ?>
    <a class="user-chip" href="profile.php" title="Edit profile">
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
      <a class="nav-item active" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
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
      <a class="sb-user-card" href="profile.php" style="text-decoration:none;display:flex;align-items:center;gap:10px;background:var(--sand);border-radius:10px;padding:12px;transition:background .12s;cursor:pointer;" onmouseover="this.style.background='var(--sage-light)'" onmouseout="this.style.background='var(--sand)'">
        <div class="suf-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div>
          <div class="suf-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="suf-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
        <span style="margin-left:auto;font-size:11px;color:var(--sage);font-weight:500">✏ Edit</span>
      </a>
    </div>
  </aside>

  <!-- Main content -->
  <main class="main">

    <!-- Crisis banner -->
    <div class="crisis-banner">
      <div class="cb-left">
        <div class="cb-dot"></div>
        <div>
          <div class="cb-title">Need immediate help?</div>
          <div class="cb-text">Hopeline: 02-804-4673 &nbsp;·&nbsp; In Touch: 0917-800-1123 &nbsp;·&nbsp; Text HELLO to 4673</div>
        </div>
      </div>
      <a class="btn-crisis" href="crisis.php">Get help now</a>
    </div>

    <!-- Flash message -->
    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Greeting row -->
    <div class="greeting-row">
      <div>
        <div class="greeting-title"><?= $greeting ?>, <?= clean($user['first_name']) ?> 👋</div>
        <div class="greeting-date"><?= date('l, F j, Y') ?> &nbsp;·&nbsp; Week <?= date('W') ?> of the semester</div>
      </div>
      <?php if (!$today_entry): ?>
        <a class="checkin-cta" href="mood-checkin.php">
          <div class="cta-emoji">😊</div>
          <div>
            <div class="cta-text">Haven't checked in today</div>
            <div class="cta-label">Log your mood now →</div>
          </div>
        </a>
      <?php else: ?>
        <div class="checked-box">
          <div class="checked-emoji"><?= $mood_emoji[$today_entry['mood_level']] ?></div>
          <div>
            <div class="checked-label">Checked in — <?= $mood_labels[$today_entry['mood_level']] ?></div>
            <div class="checked-sub">Level <?= $today_entry['mood_level'] ?> / 5 today</div>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Stat cards -->
    <div class="stat-row">
      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--sage-light)"><?= $today_entry ? $mood_emoji[$today_entry['mood_level']] : '—' ?></div>
          <span class="stat-pill badge-<?= $today_entry ? 'green' : 'gray' ?>">Today</span>
        </div>
        <div class="stat-value <?= $today_entry ? '' : 'sm' ?>"><?= $today_entry ? $mood_labels[$today_entry['mood_level']] : 'Not checked in' ?></div>
        <div class="stat-sub"><?= $today_entry ? 'Level '.$today_entry['mood_level'].' / 5' : 'Tap to check in' ?></div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:#FDF3DF">🔥</div>
          <span class="stat-pill badge-amber">Streak</span>
        </div>
        <div class="stat-value"><?= $streak ?></div>
        <div class="stat-sub"><?= $streak === 1 ? 'day' : 'days' ?> checked in (30 days)</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--lav-light)">📋</div>
          <span class="stat-pill badge-lav"><?= $latest ? $latest['code'] : 'None' ?></span>
        </div>
        <?php if ($latest): ?>
          <div class="stat-value"><?= $latest['total_score'] ?></div>
          <div class="stat-sub"><?= ucfirst(str_replace('_',' ',$latest['severity'])) ?> severity</div>
        <?php else: ?>
          <div class="stat-value sm">No assessment yet</div>
          <div class="stat-sub">Take one now</div>
        <?php endif; ?>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--sage-light)">📅</div>
          <span class="stat-pill badge-green">Next</span>
        </div>
        <?php if ($next_appt): ?>
          <div class="stat-value sm"><?= date('D, M j', strtotime($next_appt['appointment_date'])) ?></div>
          <div class="stat-sub">with <?= clean($next_appt['c_first'].' '.$next_appt['c_last']) ?></div>
        <?php else: ?>
          <div class="stat-value sm">No upcoming</div>
          <div class="stat-sub">Book a session</div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Main two-col grid -->
    <div class="main-grid">

      <!-- Left column -->
      <div style="display:flex;flex-direction:column;gap:18px">

        <!-- Mood chart -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Mood this week</div>
            <a class="card-link" href="progress.php">View full history</a>
          </div>
          <div class="chart-wrap">
            <canvas id="moodChart"></canvas>
          </div>
          <div class="mood-emojis">
            <?php
            $emoji_map = [null=>'—',1=>'😔',2=>'😟',3=>'😐',4=>'🙂',5=>'😊'];
            $day_names = [];
            for ($i=6;$i>=0;$i--) $day_names[] = date('D',strtotime("-{$i} days"));
            foreach ($day_names as $di => $dn):
                $lv = $chart_data[$di];
            ?>
              <div>
                <div class="med-emoji"><?= $emoji_map[$lv] ?></div>
                <div class="med-label"><?= $dn ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Assessment results table -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Assessment results</div>
            <a class="card-link" href="assessment.php">Take assessment</a>
          </div>
          <?php if ($recent_assessments): ?>
            <table class="assess-table">
              <thead>
                <tr><th>Assessment</th><th>Score</th><th>Severity</th><th>Date</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recent_assessments as $a): ?>
                  <tr>
                    <td><span class="code-pill"><?= clean($a['code']) ?></span><?= clean($a['title']) ?></td>
                    <td><?= $a['total_score'] ?></td>
                    <td><span class="sev-pill <?= severityBadge($a['severity']) ?>"><?= ucfirst(str_replace('_',' ',$a['severity'])) ?></span></td>
                    <td style="color:var(--text-3);font-size:12px"><?= date('M j, Y', strtotime($a['taken_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <p style="font-size:13px;color:var(--text-3)">No assessments taken yet. <a href="assessment.php" style="color:var(--sage)">Take your first one.</a></p>
          <?php endif; ?>
        </div>

      </div>

      <!-- Right column -->
      <div class="right-col">

        <!-- Quick actions -->
        <div class="card">
          <div class="card-title" style="margin-bottom:14px">Quick actions</div>
          <div class="quick-grid">
            <a class="quick-item" href="mood-checkin.php"><div class="qi-icon">😊</div><div class="qi-label">Check in</div></a>
            <a class="quick-item" href="assessment.php"><div class="qi-icon">📋</div><div class="qi-label">Assessment</div></a>
            <a class="quick-item" href="csuq.php"><div class="qi-icon">💻</div><div class="qi-label">CSUQ survey</div></a>
            <a class="quick-item" href="appointments.php"><div class="qi-icon">📅</div><div class="qi-label">Book session</div></a>
            <a class="quick-item" href="forum.php"><div class="qi-icon">🗣️</div><div class="qi-label">Peer forum</div></a>
          </div>
        </div>

        <!-- Appointments -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Appointments</div>
            <a class="card-link" href="appointments.php">Book</a>
          </div>
          <?php if ($appointments): ?>
            <?php foreach ($appointments as $ap): ?>
              <div class="appt-item">
                <div class="appt-date-box">
                  <div class="adb-month"><?= date('M', strtotime($ap['appointment_date'])) ?></div>
                  <div class="adb-day"><?= date('j', strtotime($ap['appointment_date'])) ?></div>
                </div>
                <div class="appt-info">
                  <div class="appt-name"><?= clean($ap['c_first'].' '.$ap['c_last']) ?></div>
                  <div class="appt-time"><?= date('g:i A', strtotime($ap['appointment_time'])) ?> · Counseling session</div>
                </div>
                <span class="appt-status <?= $ap['status'] === 'confirmed' ? 's-confirmed' : 's-pending' ?>">
                  <?= ucfirst($ap['status']) ?>
                </span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <p style="font-size:13px;color:var(--text-3)">No upcoming appointments. <a href="appointments.php" style="color:var(--sage)">Book a session.</a></p>
          <?php endif; ?>
        </div>

        <!-- Recommended resources (static for now) -->
        <div class="card">
          <div class="card-header">
            <div class="card-title">Recommended for you</div>
            <a class="card-link" href="resources.php">See all</a>
          </div>
          <div class="res-list">
            <a class="res-item" href="resources.php">
              <div class="res-icon" style="background:var(--sage-light)">🧘</div>
              <div><div class="res-title">5-minute breathing for exam stress</div><div class="res-type">Article · 3 min read</div></div>
            </a>
            <a class="res-item" href="resources.php">
              <div class="res-icon" style="background:var(--lav-light)">🎧</div>
              <div><div class="res-title">Guided sleep meditation</div><div class="res-type">Video · 12 minutes</div></div>
            </a>
            <a class="res-item" href="resources.php">
              <div class="res-icon" style="background:var(--amber-light)">📝</div>
              <div><div class="res-title">How to handle academic pressure</div><div class="res-type">Tip · Quick read</div></div>
            </a>
          </div>
        </div>

      </div>
    </div>

    <!-- Bottom row -->
    <div class="bottom-row">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Wellness journal</div>
          <a class="card-link" href="journal.php">Open journal</a>
        </div>
        <a class="journal-area" href="journal.php">
          Write today's journal entry...<br><br>
          <span style="font-size:11px;font-style:normal;color:var(--text-3)">Your journal is private. Only you can see it.</span>
        </a>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="card-title">Recent forum activity</div>
          <a class="card-link" href="forum.php">Go to forum</a>
        </div>
        <?php if ($forum_posts): ?>
          <?php foreach ($forum_posts as $fp): ?>
            <a class="forum-item" href="forum.php">
              <div class="fi-title"><?= clean($fp['title']) ?></div>
              <div class="fi-meta">
                <?= $fp['react_count'] ?> relate &nbsp;·&nbsp;
                <?= $fp['reply_count'] ?> replies &nbsp;·&nbsp;
                <?= date('M j', strtotime($fp['created_at'])) ?>
              </div>
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="font-size:13px;color:var(--text-3)">No posts yet. <a href="forum.php" style="color:var(--sage)">Be the first to post.</a></p>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div>

<script>
const ctx = document.getElementById('moodChart').getContext('2d');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      data: <?= json_encode($chart_data) ?>,
      backgroundColor: <?= json_encode($chart_colors) ?>,
      borderColor: <?= json_encode($chart_border) ?>,
      borderWidth: 1.5,
      borderRadius: 6,
      borderSkipped: false,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => {
            const labels = ['','Very Low','Low','Okay','Good','Great'];
            return ctx.raw ? ' ' + labels[ctx.raw] : ' No entry';
          }
        }
      }
    },
    scales: {
      y: {
        min: 0, max: 5,
        ticks: { stepSize: 1, font: { size: 10 }, color: '#9A9891' },
        grid: { color: 'rgba(0,0,0,.04)' },
        border: { display: false }
      },
      x: {
        ticks: { font: { size: 11 }, color: '#9A9891' },
        grid: { display: false },
        border: { display: false }
      }
    }
  }
});
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