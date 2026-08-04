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

$mc_title       = 'Dashboard — CTU MindCare';
$active_page    = 'dashboard';
$mc_topbar_badge = 'System Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once '../../includes/assets.php'; ?>
</head>
<body>

<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <?php require_once '../../includes/sidebar.php'; ?>

  <main class="main">
  <div class="container">

    <!-- Page Header -->
    <div class="page-header mc-reveal">
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
    <div class="section-label mc-reveal">Overview</div>
    <div class="stats-grid">

      <div class="stat-card c-green mc-reveal" data-mc-delay="0">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="users"></i></div>
          <span class="stat-trend trend-up">Active</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($total_students) ?></div>
          <div class="stat-label">Total Students</div>
        </div>
      </div>

      <div class="stat-card c-blue mc-reveal" data-mc-delay="60">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="heart-pulse"></i></div>
          <span class="stat-trend trend-up">Active</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($total_wellness_admins) ?></div>
          <div class="stat-label">Wellness Admins</div>
        </div>
      </div>

      <div class="stat-card c-red mc-reveal" data-mc-delay="120">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="siren"></i></div>
          <span class="stat-trend trend-warn">7-day</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($crisis_alerts) ?></div>
          <div class="stat-label">Crisis Alerts</div>
        </div>
      </div>

      <div class="stat-card c-purple mc-reveal" data-mc-delay="180">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="shield-check"></i></div>
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
      <div class="card mc-reveal" data-mc-delay="120">
        <div class="card-header">
          <div class="card-title">Quick Actions</div>
        </div>
        <div class="card-body">
          <div class="actions-grid">
            <a class="action-item" href="users.php">
              <div class="action-icon"><i data-lucide="user-round"></i></div>
              <div>
                <div class="action-text">Manage Users</div>
                <div class="action-desc">Add, edit, or remove accounts</div>
              </div>
            </a>
            <a class="action-item" href="analytics.php">
              <div class="action-icon"><i data-lucide="chart-line"></i></div>
              <div>
                <div class="action-text">Analytics</div>
                <div class="action-desc">Usage trends & reports</div>
              </div>
            </a>
            <a class="action-item" href="logs.php">
              <div class="action-icon"><i data-lucide="file-text"></i></div>
              <div>
                <div class="action-text">System Logs</div>
                <div class="action-desc">Audit trail & activity</div>
              </div>
            </a>
            <a class="action-item" href="announcements.php">
              <div class="action-icon"><i data-lucide="megaphone"></i></div>
              <div>
                <div class="action-text">Announcements</div>
                <div class="action-desc">Post campus-wide notices</div>
              </div>
            </a>
          </div>
        </div>
      </div>

      <!-- System Status -->
      <div class="mc-reveal" data-mc-delay="180">
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
          <i data-lucide="lightbulb" style="width: 14px; height: 14px; vertical-align: -2px;"></i>
          <strong>Tip:</strong> Monitor system health and user activities regularly.
          Check for open crisis alerts and ensure wellness admins are assigned.
        </div>
      </div>

    </div>

  </div>
  </main>
</div>

</body>
</html>
