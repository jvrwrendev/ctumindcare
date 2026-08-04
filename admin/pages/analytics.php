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

$mc_title        = 'Analytics — CTU MindCare';
$active_page     = 'analytics';
$mc_topbar_badge = 'System Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php require_once '../../includes/assets.php'; ?>
</head>
<body>

<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <?php require_once '../../includes/sidebar.php'; ?>

  <!-- ── Main ── -->
  <main class="main">
  <div class="container">

    <!-- Page Header -->
    <div class="page-header mc-reveal">
      <div>
        <div class="greeting-label">Reports</div>
        <div class="greeting-title">Analytics</div>
        <div class="greeting-sub">System-wide mental health and usage statistics</div>
      </div>
      <div class="header-date">
        <strong><?= date('l, F j') ?></strong>
        <?= date('Y') ?> · CTU MindCare
      </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card c-teal mc-reveal" data-mc-delay="1">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="smile"></i></div>
          <span class="stat-trend trend-up">All time</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($total_entries) ?></div>
          <div class="stat-label">Mood Entries</div>
        </div>
      </div>
      <div class="stat-card c-purple mc-reveal" data-mc-delay="2">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="clipboard-list"></i></div>
          <span class="stat-trend trend-up">All time</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($total_assessments) ?></div>
          <div class="stat-label">Assessments</div>
        </div>
      </div>
      <div class="stat-card c-amber mc-reveal" data-mc-delay="3">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="book-open"></i></div>
          <span class="stat-trend trend-up">All time</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($total_journals) ?></div>
          <div class="stat-label">Journal Entries</div>
        </div>
      </div>
      <div class="stat-card c-blue mc-reveal" data-mc-delay="4">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="messages-square"></i></div>
          <span class="stat-trend trend-up">All time</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($total_posts) ?></div>
          <div class="stat-label">Forum Posts</div>
        </div>
      </div>
    </div>

    <!-- Daily Check-ins -->
    <div class="card mc-reveal mb-16">
      <div class="card-header">
        <div class="card-title"><i data-lucide="activity"></i> Daily Check-ins</div>
        <span class="card-link">Mood entries recorded over the last 30 days</span>
      </div>
      <div class="card-body">
        <div class="chart-wrap"><canvas id="ciChart"></canvas></div>
      </div>
    </div>

    <!-- Mood + Registrations -->
    <div class="grid-2">
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header">
          <div class="card-title"><i data-lucide="pie-chart"></i> Mood Distribution</div>
          <span class="card-link">All-time mood level breakdown</span>
        </div>
        <div class="card-body">
          <div class="chart-wrap-sm"><canvas id="moodChart"></canvas></div>
        </div>
      </div>
      <div class="card mc-reveal" data-mc-delay="2">
        <div class="card-header">
          <div class="card-title"><i data-lucide="user-plus"></i> New Registrations</div>
          <span class="card-link">Student sign-ups over the last 6 months</span>
        </div>
        <div class="card-body">
          <div class="chart-wrap-sm"><canvas id="regChart"></canvas></div>
        </div>
      </div>
    </div>

    <!-- Severity Breakdown -->
    <div class="card mc-reveal" data-mc-delay="2">
      <div class="card-header" style="padding-bottom:0">
        <div class="card-title"><i data-lucide="gauge"></i> Assessment Severity Breakdown</div>
        <span class="card-link">PHQ-9, GAD-7 and PSS-10 result distributions</span>
      </div>
      <div class="sev-dist-grid">
        <?php
        $codes  = ['PHQ9','GAD7','PSS10'];
        $cnames = ['PHQ-9 Depression','GAD-7 Anxiety','PSS-10 Stress'];
        $sevs   = ['minimal','mild','moderate','moderately_severe','severe'];
        $slbls  = ['Minimal','Mild','Moderate','Mod. Severe','Severe'];
        $sclrs  = ['var(--chart-teal)','var(--chart-amber)','#E8A020','var(--chart-red)','var(--chart-red)'];
        foreach ($codes as $ci => $code):
          $tot = array_sum($sev_map[$code] ?? []);
        ?>
          <div>
            <div class="sev-dist-title"><?= $cnames[$ci] ?></div>
            <?php if ($tot > 0): ?>
              <div>
                <?php foreach ($sevs as $si => $sev):
                  $cnt = $sev_map[$code][$sev] ?? 0;
                  $pct = $tot > 0 ? round(($cnt/$tot)*100) : 0;
                ?>
                  <div class="sev-dist-row">
                    <div class="sev-dist-name"><?= $slbls[$si] ?></div>
                    <div class="sev-dist-track">
                      <div class="sev-dist-fill" style="width:<?= $pct ?>%;background:<?= $sclrs[$si] ?>"></div>
                    </div>
                    <div class="sev-dist-cnt"><?= $cnt ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <p style="font-size:12px;color:var(--text-3);padding-bottom:20px">No results yet.</p>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

  </div>
  </main>
</div>

<script>
const C = window.mcChartColors ? window.mcChartColors() : {};
const axisTicks = { font: { size: 10, family: "'DM Sans', sans-serif" }, color: C.ticks || '#94A3B8' };
const axisGrid  = { color: C.grid || 'rgba(100,116,139,.15)' };
const chartDefaults = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
};

new Chart(document.getElementById('ciChart').getContext('2d'), {
  type: 'line',
  data: {
    labels: <?= json_encode($ci_labels) ?>,
    datasets: [{
      data: <?= json_encode($ci_data) ?>,
      borderColor: C.teal || '#14B8A6',
      backgroundColor: 'rgba(20,184,166,0.08)',
      borderWidth: 2,
      pointRadius: 2,
      pointBackgroundColor: C.teal || '#14B8A6',
      tension: .4,
      fill: true
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      y: { ticks: axisTicks, grid: axisGrid, border: { display: false }, beginAtZero: true },
      x: { ticks: { ...axisTicks, maxRotation: 45 }, grid: { display: false }, border: { display: false } }
    }
  }
});

new Chart(document.getElementById('moodChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: ['Very Low','Low','Okay','Good','Great'],
    datasets: [{
      data: <?= json_encode($mood_data) ?>,
      backgroundColor: [
        (C.red || '#EF4444') + '66',
        (C.amber || '#F59E0B') + '66',
        (C.gray || '#CBD5E1') + '66',
        (C.teal || '#14B8A6') + '66',
        (C.teal || '#14B8A6') + '99'
      ],
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      y: { ticks: axisTicks, grid: axisGrid, border: { display: false }, beginAtZero: true },
      x: { ticks: axisTicks, grid: { display: false }, border: { display: false } }
    }
  }
});

new Chart(document.getElementById('regChart').getContext('2d'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($reg_labels) ?>,
    datasets: [{
      data: <?= json_encode($reg_data) ?>,
      backgroundColor: (C.purple || '#8B5CF6') + 'A6',
      borderRadius: 6,
      borderSkipped: false
    }]
  },
  options: {
    ...chartDefaults,
    scales: {
      y: { ticks: { ...axisTicks, stepSize: 1 }, grid: axisGrid, border: { display: false }, beginAtZero: true },
      x: { ticks: axisTicks, grid: { display: false }, border: { display: false } }
    }
  }
});
</script>

</body>
</html>
