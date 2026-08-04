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

// ── Get statistics ────────────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE is_active = 1");
$stmt->execute();
$total_students = $stmt->fetch()['count'] ?? 0;

$stmt = $db->prepare("SELECT COUNT(*) as count FROM wellness_admins WHERE is_active = 1");
$stmt->execute();
$total_wellness_admins = $stmt->fetch()['count'] ?? 0;

$stmt = $db->prepare("
    SELECT COUNT(*) as count FROM crisis_alerts 
    WHERE status IN ('open', 'acknowledged') 
    AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$stmt->execute();
$crisis_alerts = $stmt->fetch()['count'] ?? 0;

$stmt = $db->prepare("SELECT COUNT(*) as count FROM system_admins WHERE is_active = 1");
$stmt->execute();
$system_admins = $stmt->fetch()['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Admin Dashboard — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
  <style>
    :root {
      --sage:         #3E8E7E;
      --sage-hover:   #347A6C;
      --sage-light:   #EBF5F2;
      --sage-mid:     #B8DED7;
      --sage-dark:    #1F4E46;
      --sand:         #F5F2EE;
      --sand-2:       #EDE8E1;
      --white:        #FFFFFF;
      --text:         #1E1E1C;
      --text-2:       #5A5855;
      --text-3:       #9C9890;
      --border:       #E4DED6;
      --border-2:     #D6D0C8;
      --red:          #C94040;
      --red-bg:       #FDF0F0;
      --red-border:   #F0C8C8;
      --amber:        #C47A10;
      --amber-bg:     #FDF5E6;
      --lav:          #6E63B8;
      --lav-bg:       #F0EFF9;
      --lav-border:   #CCC8ED;
      --radius:       14px;
      --shadow-sm:    0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
      --shadow-md:    0 4px 16px rgba(0,0,0,.08), 0 1px 3px rgba(0,0,0,.04);
      --shadow-lg:    0 8px 32px rgba(0,0,0,.1), 0 2px 8px rgba(0,0,0,.05);
    }

    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: 'Sora', sans-serif;
      background: var(--sand);
      color: var(--text);
      min-height: 100vh;
    }

    /* ── Topbar ── */
    .topbar {
      height: 62px;
      background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      padding: 0 28px;
      position: sticky;
      top: 0;
      z-index: 200;
      gap: 16px;
      box-shadow: var(--shadow-sm);
    }

    .topbar-brand {
      display: flex;
      align-items: center;
      gap: 11px;
      text-decoration: none;
    }

    .topbar-mark {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: linear-gradient(135deg, var(--sage) 0%, var(--sage-dark) 100%);
      color: #fff;
      font-size: 17px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 2px 8px rgba(62,142,126,.35);
      letter-spacing: -.5px;
    }

    .topbar-name {
      font-family: 'Playfair Display', serif;
      font-size: 15px;
      color: var(--text);
      letter-spacing: .01em;
    }

    .topbar-divider {
      width: 1px;
      height: 22px;
      background: var(--border-2);
      margin: 0 4px;
    }

    .topbar-badge {
      font-size: 11px;
      font-weight: 600;
      color: var(--sage);
      background: var(--sage-light);
      border: 1px solid var(--sage-mid);
      border-radius: 100px;
      padding: 3px 10px;
      letter-spacing: .03em;
      text-transform: uppercase;
    }

    .topbar-right {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .user-chip {
      display: flex;
      align-items: center;
      gap: 9px;
      background: var(--sand);
      border: 1px solid var(--border);
      border-radius: 100px;
      padding: 5px 14px 5px 5px;
      cursor: pointer;
      text-decoration: none;
      color: var(--text);
      transition: all .15s;
    }
    .user-chip:hover {
      background: var(--sage-light);
      border-color: var(--sage-mid);
    }

    .user-av {
      width: 30px;
      height: 30px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--sage) 0%, var(--sage-dark) 100%);
      color: #fff;
      font-size: 11px;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      letter-spacing: .03em;
    }

    .user-name {
      font-size: 13px;
      font-weight: 500;
      color: var(--text);
    }

    .logout-btn {
      padding: 8px 16px;
      background: var(--red-bg);
      border: 1px solid var(--red-border);
      border-radius: 8px;
      color: var(--red);
      font-family: 'Sora', sans-serif;
      font-size: 12px;
      font-weight: 500;
      cursor: pointer;
      text-decoration: none;
      transition: all .15s;
    }
    .logout-btn:hover {
      background: #fbe4e4;
      border-color: #e8a8a8;
    }

    /* ── Layout ── */
    .container {
      max-width: 1180px;
      margin: 0 auto;
      padding: 36px 28px 60px;
    }

    /* ── Page Header ── */
    .page-header {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      margin-bottom: 32px;
      gap: 16px;
    }

    .greeting-label {
      font-size: 11px;
      font-weight: 600;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--sage);
      margin-bottom: 6px;
    }

    .greeting-title {
      font-family: 'Playfair Display', serif;
      font-size: 30px;
      color: var(--text);
      line-height: 1.2;
    }

    .greeting-sub {
      font-size: 13.5px;
      color: var(--text-3);
      margin-top: 5px;
      font-weight: 400;
    }

    .header-date {
      text-align: right;
      font-size: 12px;
      color: var(--text-3);
      line-height: 1.7;
      flex-shrink: 0;
    }

    .header-date strong {
      display: block;
      font-size: 14px;
      color: var(--text-2);
      font-weight: 600;
    }

    /* ── Section Labels ── */
    .section-label {
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .1em;
      text-transform: uppercase;
      color: var(--text-3);
      margin-bottom: 14px;
    }

    /* ── Stat Cards ── */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 14px;
      margin-bottom: 32px;
    }

    .stat-card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 20px 20px 18px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      transition: box-shadow .15s, transform .15s;
      position: relative;
      overflow: hidden;
    }
    .stat-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      border-radius: var(--radius) var(--radius) 0 0;
    }
    .stat-card.c-green::before  { background: linear-gradient(90deg, var(--sage), #6DC5B2); }
    .stat-card.c-blue::before   { background: linear-gradient(90deg, #4A8FD4, #7BB8F0); }
    .stat-card.c-red::before    { background: linear-gradient(90deg, var(--red), #E07070); }
    .stat-card.c-purple::before { background: linear-gradient(90deg, var(--lav), #9B90D8); }

    .stat-card:hover {
      box-shadow: var(--shadow-md);
      transform: translateY(-2px);
    }

    .stat-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .stat-icon-wrap {
      width: 42px;
      height: 42px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
    }
    .stat-card.c-green  .stat-icon-wrap { background: var(--sage-light); }
    .stat-card.c-blue   .stat-icon-wrap { background: #EBF3FD; }
    .stat-card.c-red    .stat-icon-wrap { background: var(--red-bg); }
    .stat-card.c-purple .stat-icon-wrap { background: var(--lav-bg); }

    .stat-trend {
      font-size: 11px;
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 100px;
    }
    .trend-up   { background: var(--sage-light); color: var(--sage); }
    .trend-warn { background: var(--red-bg); color: var(--red); }
    .trend-neu  { background: var(--sand-2); color: var(--text-3); }

    .stat-value {
      font-family: 'Playfair Display', serif;
      font-size: 34px;
      color: var(--text);
      line-height: 1;
    }

    .stat-label {
      font-size: 12px;
      color: var(--text-3);
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: .05em;
    }

    /* ── Content Grid ── */
    .content-grid {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
      align-items: start;
    }

    /* ── Card ── */
    .card {
      background: var(--white);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }

    .card-header {
      padding: 18px 22px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .card-title {
      font-family: 'Playfair Display', serif;
      font-size: 17px;
      color: var(--text);
    }

    .card-body { padding: 20px 22px; }

    /* ── Quick Actions ── */
    .actions-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }

    .action-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 16px;
      background: var(--sand);
      border: 1px solid var(--border);
      border-radius: 10px;
      text-decoration: none;
      color: var(--text);
      transition: all .15s;
    }
    .action-item:hover {
      background: var(--sage-light);
      border-color: var(--sage-mid);
      box-shadow: var(--shadow-sm);
      transform: translateY(-1px);
    }

    .action-icon {
      width: 36px;
      height: 36px;
      border-radius: 9px;
      background: var(--white);
      border: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 17px;
      flex-shrink: 0;
      transition: border-color .15s;
    }
    .action-item:hover .action-icon {
      border-color: var(--sage-mid);
    }

    .action-text { font-size: 13px; font-weight: 500; }
    .action-desc { font-size: 11.5px; color: var(--text-3); margin-top: 1px; }

    /* ── System Status Panel ── */
    .status-list { display: flex; flex-direction: column; gap: 0; }

    .status-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 13px 0;
      border-bottom: 1px solid var(--border);
    }
    .status-item:last-child { border-bottom: none; }

    .status-dot {
      width: 9px;
      height: 9px;
      border-radius: 50%;
      flex-shrink: 0;
    }
    .dot-green  { background: #3DB87A; box-shadow: 0 0 0 3px rgba(61,184,122,.15); }
    .dot-amber  { background: var(--amber); box-shadow: 0 0 0 3px rgba(196,122,16,.15); }
    .dot-gray   { background: var(--text-3); }

    .status-name { font-size: 13px; font-weight: 500; flex: 1; }
    .status-val  { font-size: 12px; color: var(--text-3); }

    /* ── Info Box ── */
    .info-box {
      background: var(--lav-bg);
      border: 1px solid var(--lav-border);
      border-radius: 10px;
      padding: 14px 16px;
      font-size: 13px;
      color: var(--lav);
      line-height: 1.65;
    }
    .info-box strong { font-weight: 600; }

    /* ── Responsive ── */
    @media (max-width: 1060px) {
      .stats-grid { grid-template-columns: repeat(2, 1fr); }
      .content-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .container { padding: 22px 16px 48px; }
      .topbar { padding: 0 16px; }
      .stats-grid { grid-template-columns: 1fr 1fr; gap: 10px; }
      .actions-grid { grid-template-columns: 1fr; }
      .page-header { flex-direction: column; align-items: flex-start; }
      .header-date { text-align: left; }
      .greeting-title { font-size: 24px; }
    }
    @media (max-width: 420px) {
      .stats-grid { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- ── Topbar ── -->
<header class="topbar">
  <a class="topbar-brand" href="#">
    <div class="topbar-mark">M</div>
    <div class="topbar-name">CTU MindCare</div>
  </a>
  <div class="topbar-divider"></div>
  <span class="topbar-badge">System Admin</span>

  <div class="topbar-right">
    <a class="user-chip" href="profile.php">
      <div class="user-av"><?= strtoupper(substr($user['first_name'] ?? '',0,1).substr($user['last_name'] ?? '',0,1)) ?></div>
      <span class="user-name"><?= clean(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<!-- ── Main ── -->
<div class="container">

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <div class="greeting-label">Dashboard</div>
      <div class="greeting-title">Welcome back, <?= clean($user['first_name'] ?? '') ?></div>
      <div class="greeting-sub">Here's a snapshot of your system today.</div>
    </div>
    <div class="header-date">
      <strong><?= date('l, F j') ?></strong>
      <?= date('Y') ?> · CTU MindCare
    </div>
  </div>

  <!-- Stats -->
  <div class="section-label">Overview</div>
  <div class="stats-grid">

    <div class="stat-card c-green">
      <div class="stat-top">
        <div class="stat-icon-wrap">👥</div>
        <span class="stat-trend trend-up">Active</span>
      </div>
      <div>
        <div class="stat-value"><?= number_format($total_students) ?></div>
        <div class="stat-label">Total Students</div>
      </div>
    </div>

    <div class="stat-card c-blue">
      <div class="stat-top">
        <div class="stat-icon-wrap">🏥</div>
        <span class="stat-trend trend-up">Active</span>
      </div>
      <div>
        <div class="stat-value"><?= number_format($total_wellness_admins) ?></div>
        <div class="stat-label">Wellness Admins</div>
      </div>
    </div>

    <div class="stat-card c-red">
      <div class="stat-top">
        <div class="stat-icon-wrap">🚨</div>
        <span class="stat-trend trend-warn">7-day</span>
      </div>
      <div>
        <div class="stat-value"><?= number_format($crisis_alerts) ?></div>
        <div class="stat-label">Crisis Alerts</div>
      </div>
    </div>

    <div class="stat-card c-purple">
      <div class="stat-top">
        <div class="stat-icon-wrap">🛡️</div>
        <span class="stat-trend trend-neu">Active</span>
      </div>
      <div>
        <div class="stat-value"><?= number_format($system_admins) ?></div>
        <div class="stat-label">System Admins</div>
      </div>
    </div>

  </div>

  <!-- Content -->
  <div class="content-grid">

    <!-- Quick Actions -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">Quick Actions</div>
      </div>
      <div class="card-body">
        <div class="actions-grid">
          <a class="action-item" href="users.php">
            <div class="action-icon">👤</div>
            <div>
              <div class="action-text">Manage Users</div>
              <div class="action-desc">Add, edit, or remove accounts</div>
            </div>
          </a>
          <a class="action-item" href="sessions.php">
            <div class="action-icon">🗂️</div>
            <div>
              <div class="action-text">View Sessions</div>
              <div class="action-desc">Active counseling sessions</div>
            </div>
          </a>
          <a class="action-item" href="logs.php">
            <div class="action-icon">📋</div>
            <div>
              <div class="action-text">System Logs</div>
              <div class="action-desc">Audit trail & activity</div>
            </div>
          </a>
          <a class="action-item" href="reports.php">
            <div class="action-icon">📊</div>
            <div>
              <div class="action-text">Reports</div>
              <div class="action-desc">Analytics & exports</div>
            </div>
          </a>
        </div>
      </div>
    </div>

    <!-- System Status -->
    <div>
      <div class="card">
        <div class="card-header">
          <div class="card-title">System Status</div>
        </div>
        <div class="card-body" style="padding-top: 4px; padding-bottom: 4px;">
          <div class="status-list">
            <div class="status-item">
              <span class="status-dot dot-green"></span>
              <span class="status-name">Database</span>
              <span class="status-val">Operational</span>
            </div>
            <div class="status-item">
              <span class="status-dot dot-green"></span>
              <span class="status-name">Auth Service</span>
              <span class="status-val">Operational</span>
            </div>
            <div class="status-item">
              <span class="status-dot dot-green"></span>
              <span class="status-name">Notifications</span>
              <span class="status-val">Operational</span>
            </div>
            <div class="status-item">
              <span class="status-dot dot-amber"></span>
              <span class="status-name">Crisis Alerts</span>
              <span class="status-val"><?= $crisis_alerts ?> open</span>
            </div>
          </div>
        </div>
      </div>

      <div style="height: 14px;"></div>

      <div class="info-box">
        💡 <strong>Tip:</strong> Monitor system health and user activities regularly. 
        Check for open crisis alerts and ensure wellness admins are assigned.
      </div>
    </div>

  </div>
</div>

</body>
</html>