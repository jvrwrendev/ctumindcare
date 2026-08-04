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

// Mood distribution
$stmt = $db->query("SELECT mood_level, COUNT(*) as cnt FROM mood_entries GROUP BY mood_level ORDER BY mood_level");
$mood_dist = $stmt->fetchAll();
$mood_data   = array_fill(0, 5, 0);
$mood_colors = ['rgba(192,48,48,.7)','rgba(196,122,16,.7)','rgba(184,178,168,.8)','rgba(62,142,126,.7)','rgba(31,78,70,.7)'];
foreach ($mood_dist as $m) $mood_data[$m['mood_level']-1] = (int)$m['cnt'];

// Check-ins last 30 days
$stmt = $db->query("SELECT DATE(recorded_at) as day, COUNT(*) as cnt FROM mood_entries WHERE recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(recorded_at) ORDER BY day ASC");
$ci_rows = $stmt->fetchAll();
$ci_map  = []; foreach ($ci_rows as $r) $ci_map[$r['day']] = (int)$r['cnt'];
$ci_labels = $ci_data = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $ci_labels[] = date('M j', strtotime($date));
    $ci_data[]   = $ci_map[$date] ?? 0;
}

// Registrations per month last 6 months
$stmt = $db->query("SELECT DATE_FORMAT(created_at,'%Y-%m') as month, COUNT(*) as cnt FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) GROUP BY month ORDER BY month");
$reg_rows   = $stmt->fetchAll();
$reg_labels = array_column($reg_rows,'month');
$reg_data   = array_map('intval', array_column($reg_rows,'cnt'));

// Severity breakdown
$stmt = $db->query("SELECT at.code, ar.severity, COUNT(*) as cnt FROM assessment_results ar JOIN assessment_templates at ON ar.template_id = at.id GROUP BY at.code, ar.severity ORDER BY at.code, ar.severity");
$sev_rows = $stmt->fetchAll();
$sev_map  = [];
foreach ($sev_rows as $s) $sev_map[$s['code']][$s['severity']] = (int)$s['cnt'];

// Totals
$total_entries     = (int)$db->query("SELECT COUNT(*) as c FROM mood_entries")->fetch()['c'];
$total_assessments = (int)$db->query("SELECT COUNT(*) as c FROM assessment_results")->fetch()['c'];
$total_journals    = (int)$db->query("SELECT COUNT(*) as c FROM journal_entries")->fetch()['c'];
$total_posts       = (int)$db->query("SELECT COUNT(*) as c FROM forum_posts WHERE is_removed=0")->fetch()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — CTU MindCare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
  --blue:         #2B6CB0;
  --blue-bg:      #EBF4FF;
  --blue-border:  #BEE3F8;
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
  width: 36px; height: 36px;
  border-radius: 9px;
  background: linear-gradient(135deg, var(--sage) 0%, var(--sage-dark) 100%);
  color: #fff;
  font-family: 'Playfair Display', serif;
  font-size: 17px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(62,142,126,.4);
  flex-shrink: 0;
}
.topbar-name { font-family: 'Playfair Display', serif; font-size: 16px; color: #fff; }
.topbar-role {
  font-size: 10px; font-weight: 700; letter-spacing: .08em; text-transform: uppercase;
  color: var(--sage); background: rgba(62,142,126,.15);
  border: 1px solid rgba(62,142,126,.3); border-radius: 100px; padding: 3px 10px;
}
.topbar-user { font-size: 12px; color: rgba(255,255,255,.55); }
.logout-btn {
  font-size: 12px; color: rgba(255,255,255,.5); text-decoration: none;
  padding: 6px 13px; border-radius: var(--radius-sm);
  border: 1px solid rgba(255,255,255,.15); transition: all .15s; font-weight: 500;
}
.logout-btn:hover { color: #fff; border-color: rgba(255,255,255,.4); text-decoration: none; }

/* ── Layout ── */
.layout { display: grid; grid-template-columns: 230px 1fr; min-height: calc(100vh - 62px); }

/* ── Sidebar ── */
.sidebar {
  background: var(--white); border-right: 1px solid var(--border);
  padding: 22px 0 0; position: sticky; top: 62px;
  height: calc(100vh - 62px); overflow-y: auto;
  display: flex; flex-direction: column;
}
.sb-section { padding: 0 12px; margin-bottom: 4px; }
.sb-label {
  font-size: 9.5px; font-weight: 700; color: var(--text-3);
  text-transform: uppercase; letter-spacing: .1em;
  padding: 0 10px; margin-bottom: 6px; display: block;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: var(--radius-sm);
  font-size: 13px; font-weight: 400; color: var(--text-2);
  text-decoration: none; transition: all .13s; margin-bottom: 2px;
}
.nav-item:hover { background: var(--sand); color: var(--text); text-decoration: none; }
.nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 600; }
.nav-icon {
  width: 30px; height: 30px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0; background: var(--sand-2); transition: background .13s;
}
.nav-item.active .nav-icon { background: var(--sage-mid); }
.nav-item.danger { color: var(--red); }
.nav-item.danger .nav-icon { background: var(--red-bg); }
.nav-item.danger:hover { background: var(--red-bg); text-decoration: none; }
.sb-divider { height: 1px; background: var(--border); margin: 10px 12px; }
.sb-footer { margin-top: auto; padding: 14px; }
.sb-user {
  background: var(--sand); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 12px;
  display: flex; align-items: center; gap: 10px;
}
.su-av {
  width: 34px; height: 34px; border-radius: 50%;
  background: linear-gradient(135deg, var(--navy) 0%, var(--navy-2) 100%);
  color: #fff; display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 600; flex-shrink: 0; letter-spacing: .03em;
}
.su-name { font-size: 12px; font-weight: 600; color: var(--text); }
.su-role { font-size: 10px; color: var(--text-3); margin-top: 1px; }
.su-logout { margin-left: auto; font-size: 10px; color: var(--text-3); text-decoration: none; flex-shrink: 0; }
.su-logout:hover { color: var(--red); }

/* ── Main ── */
.main { padding: 30px 32px 60px; }

/* ── Page Header ── */
.page-label { font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--sage); margin-bottom: 5px; }
.page-title { font-family: 'Playfair Display', serif; font-size: 27px; color: var(--text); line-height: 1.15; }
.page-sub   { font-size: 13px; color: var(--text-3); margin-top: 4px; margin-bottom: 26px; }

/* ── Stat cards ── */
.stats-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 24px;
}
.stat-card {
  background: var(--white); border: 1px solid var(--border);
  border-radius: var(--radius); padding: 16px 18px;
  display: flex; align-items: center; gap: 14px;
  box-shadow: var(--shadow-sm); transition: box-shadow .15s;
  position: relative; overflow: hidden;
}
.stat-card::after {
  content: ''; position: absolute; bottom: 0; left: 0; right: 0;
  height: 3px; border-radius: 0 0 var(--radius) var(--radius);
}
.stat-card.c-mood::after    { background: linear-gradient(90deg, var(--sage), #6DC5B2); }
.stat-card.c-assess::after  { background: linear-gradient(90deg, var(--lav), #A89FE0); }
.stat-card.c-journal::after { background: linear-gradient(90deg, var(--amber), #E0B86A); }
.stat-card.c-forum::after   { background: linear-gradient(90deg, var(--blue), #7BB8F0); }
.stat-card:hover { box-shadow: var(--shadow-md); }
.stat-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.stat-card.c-mood    .stat-icon { background: var(--sage-light); }
.stat-card.c-assess  .stat-icon { background: var(--lav-bg); }
.stat-card.c-journal .stat-icon { background: var(--amber-bg); }
.stat-card.c-forum   .stat-icon { background: var(--blue-bg); }
.stat-value { font-family: 'Playfair Display', serif; font-size: 26px; color: var(--text); line-height: 1; }
.stat-label { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; margin-top: 3px; }

/* ── Cards ── */
.card {
  background: var(--white); border: 1px solid var(--border);
  border-radius: var(--radius); box-shadow: var(--shadow-sm);
  overflow: hidden;
}
.card-header {
  padding: 16px 20px 0;
}
.card-title {
  font-size: 13px; font-weight: 700; color: var(--text);
  text-transform: uppercase; letter-spacing: .06em;
}
.card-sub {
  font-size: 11px; color: var(--text-3); margin-top: 3px;
}
.card-body { padding: 16px 20px 20px; }

/* ── Chart wrappers ── */
.chart-wrap { height: 210px; position: relative; }
.chart-sm   { height: 170px; position: relative; }

/* ── 2-col grid ── */
.grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 16px;
}

/* ── Severity section ── */
.sev-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 24px;
  padding: 16px 20px 20px;
}
.sev-label {
  font-size: 12px; font-weight: 700; color: var(--text);
  margin-bottom: 14px; padding-bottom: 8px;
  border-bottom: 1px solid var(--border);
}
.sev-row { display: flex; flex-direction: column; gap: 9px; }
.sev-item { display: flex; align-items: center; gap: 10px; }
.sev-name {
  font-size: 11.5px; color: var(--text-2);
  width: 100px; flex-shrink: 0;
}
.sev-bg {
  flex: 1; height: 7px; background: var(--sand-2);
  border-radius: 4px; overflow: hidden;
}
.sev-fill { height: 100%; border-radius: 4px; }
.sev-cnt { font-size: 11px; color: var(--text-3); width: 28px; text-align: right; font-weight: 600; }

/* ── Spacing helpers ── */
.mb-16 { margin-bottom: 16px; }

/* ── Responsive ── */
@media (max-width: 1060px) {
  .stats-row { grid-template-columns: repeat(2, 1fr); }
  .sev-grid  { grid-template-columns: 1fr; }
}
@media (max-width: 960px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .main { padding: 22px 18px 48px; }
}
@media (max-width: 680px) {
  .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
  .grid-2    { grid-template-columns: 1fr; }
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
      <a class="nav-item" href="users.php">
        <div class="nav-icon">👥</div> Manage Users
      </a>
      <a class="nav-item active" href="analytics.php">
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

    <!-- Page Header -->
    <div class="page-label">Reports</div>
    <div class="page-title">Analytics</div>
    <div class="page-sub">System-wide mental health and usage statistics</div>

    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card c-mood">
        <div class="stat-icon">😊</div>
        <div>
          <div class="stat-value"><?= number_format($total_entries) ?></div>
          <div class="stat-label">Mood Entries</div>
        </div>
      </div>
      <div class="stat-card c-assess">
        <div class="stat-icon">📝</div>
        <div>
          <div class="stat-value"><?= number_format($total_assessments) ?></div>
          <div class="stat-label">Assessments</div>
        </div>
      </div>
      <div class="stat-card c-journal">
        <div class="stat-icon">📓</div>
        <div>
          <div class="stat-value"><?= number_format($total_journals) ?></div>
          <div class="stat-label">Journal Entries</div>
        </div>
      </div>
      <div class="stat-card c-forum">
        <div class="stat-icon">💬</div>
        <div>
          <div class="stat-value"><?= number_format($total_posts) ?></div>
          <div class="stat-label">Forum Posts</div>
        </div>
      </div>
    </div>

    <!-- Daily Check-ins -->
    <div class="card mb-16">
      <div class="card-header">
        <div class="card-title">Daily Check-ins</div>
        <div class="card-sub">Mood entries recorded over the last 30 days</div>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="ciChart"></canvas></div>
      </div>
    </div>

    <!-- Mood + Registrations -->
    <div class="grid-2">
      <div class="card">
        <div class="card-header">
          <div class="card-title">Mood Distribution</div>
          <div class="card-sub">All-time mood level breakdown</div>
        </div>
        <div class="card-body">
          <div class="chart-sm"><canvas id="moodChart"></canvas></div>
        </div>
      </div>
      <div class="card">
        <div class="card-header">
          <div class="card-title">New Registrations</div>
          <div class="card-sub">Student sign-ups over the last 6 months</div>
        </div>
        <div class="card-body">
          <div class="chart-sm"><canvas id="regChart"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Severity Breakdown -->
    <div class="card">
      <div class="card-header" style="padding-bottom:0">
        <div class="card-title">Assessment Severity Breakdown</div>
        <div class="card-sub">PHQ-9, GAD-7 and PSS-10 result distributions</div>
      </div>
      <div class="sev-grid">
        <?php
        $codes  = ['PHQ9','GAD7','PSS10'];
        $cnames = ['PHQ-9 Depression','GAD-7 Anxiety','PSS-10 Stress'];
        $sevs   = ['minimal','mild','moderate','moderately_severe','severe'];
        $slbls  = ['Minimal','Mild','Moderate','Mod. Severe','Severe'];
        $sclrs  = ['#3E8E7E','#C47A10','#E8A020','#D96B52','#C03030'];
        foreach ($codes as $ci => $code):
          $tot = array_sum($sev_map[$code] ?? []);
        ?>
          <div>
            <div class="sev-label"><?= $cnames[$ci] ?></div>
            <div class="sev-row">
              <?php foreach ($sevs as $si => $sev):
                $cnt = $sev_map[$code][$sev] ?? 0;
                $pct = $tot > 0 ? round(($cnt/$tot)*100) : 0;
              ?>
                <div class="sev-item">
                  <div class="sev-name"><?= $slbls[$si] ?></div>
                  <div class="sev-bg">
                    <div class="sev-fill" style="width:<?= $pct ?>%;background:<?= $sclrs[$si] ?>"></div>
                  </div>
                  <div class="sev-cnt"><?= $cnt ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </main>
</div>

<script>
const chartDefaults = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
};
const axisStyle = {
  ticks: { font: { size: 10, family: "'Sora', sans-serif" }, color: '#9C9890' },
  grid:  { color: 'rgba(0,0,0,.04)' },
  border: { display: false }
};

new Chart(document.getElementById('ciChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: <?= json_encode($ci_labels) ?>,
    datasets: [{
      data: <?= json_encode($ci_data) ?>,
      borderColor: '#3E8E7E',
      backgroundColor: 'rgba(62,142,126,0.07)',
      borderWidth: 2,
      pointRadius: 2,
      pointBackgroundColor: '#3E8E7E',
      tension: .4,
      fill: true
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      y: { ...axisStyle, beginAtZero: true },
      x: { ...axisStyle, ticks: { ...axisStyle.ticks, maxRotation: 45 }, grid: { display: false } }
    }
  }
});

new Chart(document.getElementById('moodChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: ['Very Low','Low','Okay','Good','Great'],
    datasets: [{
      data: <?= json_encode($mood_data) ?>,
      backgroundColor: <?= json_encode($mood_colors) ?>,
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      y: { ...axisStyle, beginAtZero: true },
      x: { ...axisStyle, grid: { display: false } }
    }
  }
});

new Chart(document.getElementById('regChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($reg_labels) ?>,
    datasets: [{
      data: <?= json_encode($reg_data) ?>,
      backgroundColor: 'rgba(110,99,184,0.65)',
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      y: { ...axisStyle, beginAtZero: true, ticks: { ...axisStyle.ticks, stepSize: 1 } },
      x: { ...axisStyle, grid: { display: false } }
    }
  }
});
</script>

</body>
</html>