<?php
// ============================================================
//  CTU MindCare — Student Progress
//  File: student/pages/progress.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── Date range filter ─────────────────────────────────────────
$range = $_GET['range'] ?? '30';   // 7, 30, 90, 'all'
$range_days = is_numeric($range) ? (int)$range : 365;
$range_label = match($range) {
    '7'   => 'Last 7 days',
    '30'  => 'Last 30 days',
    '90'  => 'Last 90 days',
    'all' => 'All time',
    default => 'Last 30 days',
};

// ── Mood entries for chart ────────────────────────────────────
$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day, mood_level, mood_note
    FROM mood_entries
    WHERE user_id = ?
      AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    ORDER BY recorded_at ASC
");
$stmt->execute([$user['id'], $range_days]);
$mood_rows = $stmt->fetchAll();

// Fill every day in range (nulls for missing days)
$mood_map = [];
foreach ($mood_rows as $r) $mood_map[$r['day']] = $r;

$chart_labels = [];
$chart_data   = [];
$chart_colors = [];
$chart_border = [];

for ($i = $range_days - 1; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-{$i} days"));
    $label = $range_days <= 7  ? date('D',    strtotime($date))
           : ($range_days <= 30 ? date('M j',  strtotime($date))
           :                      date('M j',  strtotime($date)));
    $level = isset($mood_map[$date]) ? (int)$mood_map[$date]['mood_level'] : null;

    $chart_labels[] = $label;
    $chart_data[]   = $level;
    if ($level === null) {
        $chart_colors[] = 'rgba(217,211,201,0.2)';
        $chart_border[] = 'rgba(217,211,201,0.5)';
    } elseif ($level <= 2) {
        $chart_colors[] = 'rgba(212,134,10,0.3)';
        $chart_border[] = '#D4860A';
    } elseif ($level == 3) {
        $chart_colors[] = 'rgba(217,211,201,0.5)';
        $chart_border[] = '#9A9891';
    } else {
        $opacity = 0.25 + ($level * 0.12);
        $chart_colors[] = "rgba(74,155,127,{$opacity})";
        $chart_border[] = '#4A9B7F';
    }
}

// ── Mood summary stats ────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(*)                               AS total_entries,
        AVG(mood_level)                        AS avg_mood,
        MIN(mood_level)                        AS min_mood,
        MAX(mood_level)                        AS max_mood,
        SUM(CASE WHEN mood_level >= 4 THEN 1 ELSE 0 END) AS good_days,
        SUM(CASE WHEN mood_level <= 2 THEN 1 ELSE 0 END) AS low_days
    FROM mood_entries
    WHERE user_id = ?
      AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
");
$stmt->execute([$user['id'], $range_days]);
$mood_stats = $stmt->fetch();

// Streak: consecutive days checked in up to today
$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day
    FROM mood_entries
    WHERE user_id = ?
    GROUP BY DATE(recorded_at)
    ORDER BY day DESC
    LIMIT 60
");
$stmt->execute([$user['id']]);
$streak_days = array_column($stmt->fetchAll(), 'day');
$streak = 0;
for ($i = 0; $i < count($streak_days); $i++) {
    $expected = date('Y-m-d', strtotime("-{$i} days"));
    if (($streak_days[$i] ?? '') === $expected) {
        $streak++;
    } else {
        break;
    }
}

// ── Assessment history (all) ──────────────────────────────────
$stmt = $db->prepare("
    SELECT ar.id, ar.total_score, ar.severity, ar.triggered_by, ar.taken_at,
           at.code, at.title, at.max_score
    FROM assessment_results ar
    JOIN assessment_templates at ON ar.template_id = at.id
    WHERE ar.user_id = ?
    ORDER BY ar.taken_at DESC
");
$stmt->execute([$user['id']]);
$all_assessments = $stmt->fetchAll();

// Group by template code for trend lines
$assess_by_code = [];
foreach ($all_assessments as $a) {
    $assess_by_code[$a['code']][] = $a;
}

// ── Assessment trend chart data (per template) ────────────────
$trend_data = [];
foreach ($assess_by_code as $code => $rows) {
    $rows_asc = array_reverse($rows);
    $trend_data[$code] = [
        'labels' => array_map(fn($r) => date('M j', strtotime($r['taken_at'])), $rows_asc),
        'scores' => array_map(fn($r) => (int)$r['total_score'], $rows_asc),
        'max'    => $rows_asc[0]['max_score'] ?? 27,
    ];
}

// ── Mood distribution (count per level) ──────────────────────
$stmt = $db->prepare("
    SELECT mood_level, COUNT(*) as cnt
    FROM mood_entries
    WHERE user_id = ?
      AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
    GROUP BY mood_level
    ORDER BY mood_level
");
$stmt->execute([$user['id'], $range_days]);
$dist_rows = $stmt->fetchAll();
$dist = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
foreach ($dist_rows as $d) $dist[(int)$d['mood_level']] = (int)$d['cnt'];

// ── Monthly mood average (last 6 months) ─────────────────────
$stmt = $db->prepare("
    SELECT DATE_FORMAT(recorded_at, '%Y-%m') as month,
           AVG(mood_level) as avg_level,
           COUNT(*) as entries
    FROM mood_entries
    WHERE user_id = ?
      AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(recorded_at, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$user['id']]);
$monthly = $stmt->fetchAll();

$monthly_labels = array_map(fn($m) => date('M Y', strtotime($m['month'].'-01')), $monthly);
$monthly_avgs   = array_map(fn($m) => round((float)$m['avg_level'], 2), $monthly);

// ── Recent mood entries with notes ───────────────────────────
$stmt = $db->prepare("
    SELECT mood_level, mood_note, recorded_at
    FROM mood_entries
    WHERE user_id = ?
    ORDER BY recorded_at DESC
    LIMIT 20
");
$stmt->execute([$user['id']]);
$recent_entries = $stmt->fetchAll();

// ── Helpers ──────────────────────────────────────────────────
$mood_labels = [1=>'Very Low', 2=>'Low', 3=>'Okay', 4=>'Good', 5=>'Great'];
$mood_emoji  = [1=>'😔', 2=>'😟', 3=>'😐', 4=>'🙂', 5=>'😊'];
$mood_color  = [
    1 => ['bg'=>'#FBEDED', 'border'=>'#E24B4A', 'text'=>'#7A1A1A'],
    2 => ['bg'=>'#FDF3DF', 'border'=>'#D4860A', 'text'=>'#7A4F0A'],
    3 => ['bg'=>'#F7F3EE', 'border'=>'#D9D3C9', 'text'=>'#5C5B57'],
    4 => ['bg'=>'#EAF7F2', 'border'=>'#4A9B7F', 'text'=>'#2D6B57'],
    5 => ['bg'=>'#C4E8DC', 'border'=>'#2D6B57', 'text'=>'#1A4035'],
];

function severityLabel(string $s): string {
    return match($s) {
        'minimal'           => 'Minimal',
        'mild'              => 'Mild',
        'moderate'          => 'Moderate',
        'moderately_severe' => 'Moderately Severe',
        'severe'            => 'Severe',
        default             => ucfirst($s),
    };
}
function severityClass(string $s): string {
    return match($s) {
        'minimal'           => 's-minimal',
        'mild'              => 's-mild',
        'moderate'          => 's-moderate',
        'moderately_severe' => 's-mod-severe',
        'severe'            => 's-severe',
        default             => 's-gray',
    };
}

$total_entries = (int)($mood_stats['total_entries'] ?? 0);
$avg_mood      = $total_entries > 0 ? round((float)$mood_stats['avg_mood'], 1) : null;
$check_rate    = $range_days > 0 ? round($total_entries / $range_days * 100) : 0;

$flash = getFlash();

$mc_title = 'My Progress — CTU MindCare';
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
<!-- ── Topbar ── -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php require_once '../../includes/sidebar.php'; ?>

  <!-- ── Main ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash-bar mc-reveal">
        <div class="flash flash-<?= $flash['type'] ?>">
          <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : 'alert-triangle' ?>"></i>
          <?= clean($flash['message']) ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Page header + range tabs -->
    <div class="page-header mc-reveal">
      <div>
        <div class="page-title">My Progress</div>
        <div class="page-sub">Track your mood trends, check-in streaks, and assessment history.</div>
      </div>
      <div class="header-actions">
        <div class="range-tabs">
          <a class="range-tab <?= $range==='7'   ? 'active':'' ?>" href="?range=7">7 days</a>
          <a class="range-tab <?= $range==='30'  ? 'active':'' ?>" href="?range=30">30 days</a>
          <a class="range-tab <?= $range==='90'  ? 'active':'' ?>" href="?range=90">90 days</a>
          <a class="range-tab <?= $range==='all' ? 'active':'' ?>" href="?range=all">All time</a>
        </div>
      </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="stat-row">
      <div class="stat-card c-amber mc-reveal">
        <div class="stat-top">
          <div class="stat-icon"><i data-lucide="flame"></i></div>
          <span class="stat-pill pill-amber">Streak</span>
        </div>
        <div class="stat-value"><?= $streak ?></div>
        <div class="stat-sub"><?= $streak === 1 ? 'day' : 'days' ?> in a row</div>
      </div>

      <div class="stat-card c-blue mc-reveal" data-mc-delay="1">
        <div class="stat-top">
          <div class="stat-icon"><i data-lucide="calendar-days"></i></div>
          <span class="stat-pill pill-lav"><?= $range_label ?></span>
        </div>
        <div class="stat-value"><?= $total_entries ?></div>
        <div class="stat-sub">check-ins · <?= $check_rate ?>% rate</div>
      </div>

      <div class="stat-card <?= $avg_mood !== null && $avg_mood >= 4 ? 'c-green' : ($avg_mood !== null && $avg_mood >= 3 ? 'c-blue' : 'c-amber') ?> mc-reveal" data-mc-delay="2">
        <div class="stat-top">
          <div class="stat-icon"><i data-lucide="smile"></i></div>
          <span class="stat-pill <?= $avg_mood !== null && $avg_mood >= 4 ? 'pill-green' : ($avg_mood !== null && $avg_mood >= 3 ? 'pill-gray' : 'pill-amber') ?>">Avg mood</span>
        </div>
        <?php if ($avg_mood): ?>
          <div class="stat-value"><?= $avg_mood ?></div>
          <div class="stat-sub">out of 5 · <?= $mood_labels[round($avg_mood)] ?? '' ?></div>
        <?php else: ?>
          <div class="stat-value sm">No data</div>
          <div class="stat-sub">Start checking in</div>
        <?php endif; ?>
      </div>

      <div class="stat-card c-purple mc-reveal" data-mc-delay="3">
        <div class="stat-top">
          <div class="stat-icon"><i data-lucide="clipboard-list"></i></div>
          <span class="stat-pill pill-coral">Assessments</span>
        </div>
        <div class="stat-value"><?= count($all_assessments) ?></div>
        <div class="stat-sub">total taken <?= count($all_assessments) > 0 ? '· <a href="#assess-history" class="mc-link">view all</a>' : '' ?></div>
      </div>
    </div>

    <!-- ── Mood over time + Distribution ── -->
    <div class="grid-21">

      <!-- Mood chart -->
      <div class="card mc-reveal">
        <div class="card-header">
          <div>
            <div class="card-title">Mood over time</div>
            <div class="card-sub"><?= $range_label ?> · <?= $total_entries ?> entries</div>
          </div>
          <a class="card-link" href="mood-checkin.php">Check in today →</a>
        </div>
        <?php if ($total_entries > 0): ?>
          <div class="chart-wrap-tall">
            <canvas id="moodChart"></canvas>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="es-icon"><i data-lucide="smile"></i></div>
            <div class="es-title">No mood entries yet</div>
            <div class="es-sub">Start checking in daily to see your trend here.</div>
            <a class="btn btn-primary" href="mood-checkin.php">Log today's mood</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- Mood distribution -->
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header">
          <div>
            <div class="card-title">Mood distribution</div>
            <div class="card-sub"><?= $range_label ?></div>
          </div>
        </div>
        <?php
        $dist_colors = [
            1 => ['fill'=>'#E24B4A', 'light'=>'#FBEDED'],
            2 => ['fill'=>'#D4860A', 'light'=>'#FDF3DF'],
            3 => ['fill'=>'#9A9891', 'light'=>'#F7F3EE'],
            4 => ['fill'=>'#4A9B7F', 'light'=>'#EAF7F2'],
            5 => ['fill'=>'#2D6B57', 'light'=>'#C4E8DC'],
        ];
        $dist_max = max(array_values($dist)) ?: 1;
        ?>
        <div style="margin-bottom:4px">
          <?php for ($lv = 5; $lv >= 1; $lv--): ?>
            <div class="dist-row">
              <div class="dist-emoji"><?= $mood_emoji[$lv] ?></div>
              <div class="dist-label"><?= $mood_labels[$lv] ?></div>
              <div class="dist-track">
                <div class="dist-fill" style="width:<?= $dist_max > 0 ? round($dist[$lv]/$dist_max*100) : 0 ?>%;background:<?= $dist_colors[$lv]['fill'] ?>"></div>
              </div>
              <div class="dist-count"><?= $dist[$lv] ?></div>
            </div>
          <?php endfor; ?>
        </div>

        <?php if ($total_entries > 0): ?>
          <!-- Good vs low day ratio -->
          <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border)">
            <div class="ratio-row">
              <span>Good days (4–5)</span>
              <span class="ratio-val good"><?= $mood_stats['good_days'] ?> day<?= $mood_stats['good_days'] != 1 ? 's' : '' ?></span>
            </div>
            <div class="ratio-row">
              <span>Low days (1–2)</span>
              <span class="ratio-val low"><?= $mood_stats['low_days'] ?> day<?= $mood_stats['low_days'] != 1 ? 's' : '' ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- ── 6-month average + Heatmap ── -->
    <div class="two-col">

      <!-- Monthly average trend -->
      <div class="card mc-reveal">
        <div class="card-header">
          <div>
            <div class="card-title">Monthly mood average</div>
            <div class="card-sub">Last 6 months</div>
          </div>
        </div>
        <?php if (count($monthly) > 0): ?>
          <div class="chart-wrap-medium">
            <canvas id="monthlyChart"></canvas>
          </div>
          <div class="month-legend">
            <div class="ml-item"><div class="ml-dot" style="background:var(--chart-teal)"></div> Avg mood level</div>
            <div class="ml-item" style="margin-left:auto;font-size:11px;color:var(--text-3)">Scale: 1 (Very Low) → 5 (Great)</div>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="es-icon"><i data-lucide="bar-chart-3"></i></div>
            <div class="es-title">No data yet</div>
            <div class="es-sub">Check in daily for at least a month to see trends here.</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Check-in heatmap (last 12 weeks) -->
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header">
          <div>
            <div class="card-title">Check-in heatmap</div>
            <div class="card-sub">Last 12 weeks · darker = higher mood</div>
          </div>
        </div>
        <?php
        // Build 12 weeks × 7 days grid
        $hm_days = 84;
        $hm_map  = [];
        $stmt = $db->prepare("
            SELECT DATE(recorded_at) as day, mood_level
            FROM mood_entries
            WHERE user_id = ? AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        ");
        $stmt->execute([$user['id'], $hm_days]);
        foreach ($stmt->fetchAll() as $r) $hm_map[$r['day']] = (int)$r['mood_level'];

        $hm_colors = [
            null => '#EDE8E1',
            1    => '#F5C6C2',
            2    => '#FAC775',
            3    => '#D9D3C9',
            4    => '#9FE1CB',
            5    => '#1D9E75',
        ];
        // Start from the beginning of this week going back 12 full weeks
        $today_dow = (int)date('N'); // 1=Mon ... 7=Sun
        ?>
        <div class="hm-wrap">
          <div class="hm-track">
            <!-- Day labels column -->
            <div class="hm-col" style="gap:3px;margin-right:4px">
              <div class="hm-label" style="height:17px"></div><!-- spacer for month row -->
              <?php foreach(['M','T','W','T','F','S','S'] as $dl): ?>
                <div class="hm-label"><?= $dl ?></div>
              <?php endforeach; ?>
            </div>
            <?php
            // Build weeks: 12 columns, each 7 days
            $weeks = [];
            // Find the most recent Sunday
            $offset_to_sun = ($today_dow % 7); // days since last Sunday
            for ($w = 11; $w >= 0; $w--) {
                $week = [];
                for ($d = 0; $d <= 6; $d++) {
                    $days_back = $offset_to_sun + $w * 7 + (6 - $d);
                    $date = date('Y-m-d', strtotime("-{$days_back} days"));
                    $week[] = $date;
                }
                $weeks[] = $week;
            }
            foreach ($weeks as $wi => $week):
                $month_label = date('M', strtotime($week[0]));
                $show_month  = ($wi === 0 || date('m', strtotime($week[0])) !== date('m', strtotime($weeks[max(0,$wi-1)][0])));
            ?>
              <div class="hm-col">
                <div class="hm-label" style="height:17px;font-size:9px;color:var(--text-3)"><?= $show_month ? $month_label : '' ?></div>
                <?php foreach ($week as $hm_date):
                    $lv = $hm_map[$hm_date] ?? null;
                    $is_future = $hm_date > date('Y-m-d');
                    $bg = $is_future ? 'transparent' : $hm_colors[$lv];
                    $title = $hm_date . ($lv ? ': ' . $mood_labels[$lv] : ': No entry');
                ?>
                  <div class="hm-cell"
                       style="background:<?= $bg ?>;<?= $is_future ? 'opacity:0' : '' ?>"
                       title="<?= $title ?>"></div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="heatmap-legend">
            <span>No entry</span>
            <span class="hl-cell" style="background:#EDE8E1;width:12px;height:12px;border-radius:3px"></span>
            <span class="hl-cell" style="background:#F5C6C2;width:12px;height:12px;border-radius:3px"></span>
            <span class="hl-cell" style="background:#FAC775;width:12px;height:12px;border-radius:3px"></span>
            <span class="hl-cell" style="background:#D9D3C9;width:12px;height:12px;border-radius:3px"></span>
            <span class="hl-cell" style="background:#9FE1CB;width:12px;height:12px;border-radius:3px"></span>
            <span class="hl-cell" style="background:#1D9E75;width:12px;height:12px;border-radius:3px"></span>
            <span>Great</span>
          </div>
        </div>
      </div>

    </div>

    <!-- ── Assessment trends ── -->
    <?php if ($all_assessments): ?>
    <div class="full-row">
      <div class="card mc-reveal">
        <div class="card-header">
          <div>
            <div class="card-title">Assessment score trends</div>
            <div class="card-sub">Score history per tool — lower is better for PHQ-9 & GAD-7</div>
          </div>
          <a class="card-link" href="assessment.php">Take an assessment →</a>
        </div>
        <?php if (count($assess_by_code) > 0 && array_sum(array_map('count', $assess_by_code)) > 1): ?>
          <div class="chart-wrap-medium">
            <canvas id="trendChart"></canvas>
          </div>
        <?php else: ?>
          <p style="font-size:13px;color:var(--text-3)">Take more assessments over time to see your trend lines here.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ── Assessment history table ── -->
    <div class="full-row" id="assess-history">
      <div class="card mc-reveal">
        <div class="card-header">
          <div>
            <div class="card-title">Assessment history</div>
            <div class="card-sub"><?= count($all_assessments) ?> total result<?= count($all_assessments) !== 1 ? 's' : '' ?></div>
          </div>
          <a class="card-link" href="assessment.php">Take new assessment →</a>
        </div>
        <?php if ($all_assessments): ?>
          <div style="overflow-x:auto">
            <table class="assess-table">
              <thead>
                <tr>
                  <th>Assessment</th>
                  <th>Score</th>
                  <th>Severity</th>
                  <th>Triggered by</th>
                  <th>Date</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($all_assessments as $a): ?>
                  <?php
                  $pct = $a['max_score'] > 0 ? round($a['total_score'] / $a['max_score'] * 100) : 0;
                  $bar_color = match($a['severity']) {
                      'minimal'           => '#4A9B7F',
                      'mild'              => '#D4860A',
                      'moderate'          => '#E8A020',
                      'moderately_severe' => '#D96B52',
                      'severe'            => '#D64545',
                      default             => '#9A9891',
                  };
                  ?>
                  <tr>
                    <td>
                      <span class="code-pill"><?= clean($a['code']) ?></span>
                      <span style="color:var(--text)"><?= clean($a['title']) ?></span>
                    </td>
                    <td>
                      <div class="score-bar-wrap">
                        <div class="score-bar-track">
                          <div class="score-bar-fill" style="width:<?= $pct ?>%;background:<?= $bar_color ?>"></div>
                        </div>
                        <span class="score-val"><?= $a['total_score'] ?><span style="font-weight:400;color:var(--text-3)"> / <?= $a['max_score'] ?></span></span>
                      </div>
                    </td>
                    <td>
                      <span class="sev-pill <?= severityClass($a['severity']) ?>">
                        <?= severityLabel($a['severity']) ?>
                      </span>
                    </td>
                    <td style="font-size:12px;color:var(--text-3)">
                      <?= $a['triggered_by'] === 'auto_mood' ? '🔄 Auto' : '✋ Manual' ?>
                    </td>
                    <td style="font-size:12px;color:var(--text-3)">
                      <?= date('M j, Y', strtotime($a['taken_at'])) ?><br>
                      <span style="font-size:10px"><?= date('g:i A', strtotime($a['taken_at'])) ?></span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="es-icon"><i data-lucide="clipboard-list"></i></div>
            <div class="es-title">No assessments yet</div>
            <div class="es-sub">Take your first wellness assessment to track your mental health over time.</div>
            <a class="btn btn-primary" href="assessment.php">Take an assessment</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Recent mood log ── -->
    <div class="full-row">
      <div class="card mc-reveal">
        <div class="card-header">
          <div>
            <div class="card-title">Recent mood entries</div>
            <div class="card-sub">Your last <?= count($recent_entries) ?> check-ins</div>
          </div>
          <a class="card-link" href="mood-checkin.php">Check in today →</a>
        </div>
        <?php if ($recent_entries): ?>
          <div class="mood-log-list">
            <?php foreach ($recent_entries as $e):
              $lv = (int)$e['mood_level'];
              $mc = $mood_color[$lv];
            ?>
              <div class="mood-log-item">
                <span class="ml-dot" style="width:9px;height:9px;background:<?= $mc['border'] ?>;flex-shrink:0"></span>
                <div class="mli-emoji"><?= $mood_emoji[$lv] ?></div>
                <div class="mli-body">
                  <div class="mli-top">
                    <span class="mli-level" style="color:<?= $mc['text'] ?>"><?= $mood_labels[$lv] ?></span>
                    <span class="level-badge" style="background:<?= $mc['border'] ?>22;color:<?= $mc['text'] ?>">Level <?= $lv ?> / 5</span>
                    <span class="mli-date" style="margin-left:auto"><?= date('D, M j · g:i A', strtotime($e['recorded_at'])) ?></span>
                  </div>
                  <?php if ($e['mood_note']): ?>
                    <div class="mli-note">"<?= clean($e['mood_note']) ?>"</div>
                  <?php else: ?>
                    <div class="mli-note" style="color:var(--text-3)">No note added.</div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="es-icon"><i data-lucide="smile"></i></div>
            <div class="es-title">No mood entries yet</div>
            <div class="es-sub">Daily check-ins help you and your counselor understand your wellness journey.</div>
            <a class="btn btn-primary" href="mood-checkin.php">Log your first mood</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div>

<script>
// ── Themed chart palette ──────────────────────────────────────
function mcChartPalette() {
  if (window.mcChartColors) return window.mcChartColors();
  return { teal: '#4A9B7F', blue: '#4A90D9', amber: '#D4860A', red: '#D96B52',
           purple: '#7B6FC4', grid: 'rgba(0,0,0,.04)', ticks: '#9A9891' };
}
function mcRgba(hex, a) {
  hex = hex || '#4A9B7F';
  var h = hex.replace('#', '');
  if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
  var n = parseInt(h, 16);
  return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
}

// ── Mood over time chart ──────────────────────────────────────
<?php if ($total_entries > 0): ?>
(function() {
  const C = mcChartPalette();
  const ctx = document.getElementById('moodChart');
  if (!ctx) return;
  const moodNames = ['','Very Low','Low','Okay','Good','Great'];
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($chart_labels) ?>,
      datasets: [{
        data:            <?= json_encode($chart_data) ?>,
        backgroundColor: <?= json_encode($chart_colors) ?>,
        borderColor:     <?= json_encode($chart_border) ?>,
        borderWidth: 1.5,
        borderRadius: 5,
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
            label: ctx => ctx.raw ? ' ' + moodNames[ctx.raw] + ' (' + ctx.raw + '/5)' : ' No entry',
          }
        }
      },
      scales: {
        y: {
          min: 0, max: 5,
          ticks: {
            stepSize: 1,
            callback: v => v === 0 ? '' : moodNames[v],
            font: { size: 10 }, color: C.ticks
          },
          grid: { color: C.grid },
          border: { display: false }
        },
        x: {
          ticks: {
            font: { size: <?= $range_days > 30 ? 9 : 11 ?> },
            color: C.ticks,
            maxRotation: <?= $range_days > 30 ? 45 : 0 ?>,
            autoSkip: true,
            maxTicksLimit: <?= $range_days > 30 ? 20 : $range_days ?>
          },
          grid: { display: false },
          border: { display: false }
        }
      }
    }
  });
})();
<?php endif; ?>

// ── Monthly average chart ─────────────────────────────────────
<?php if (count($monthly) > 0): ?>
(function() {
  const C = mcChartPalette();
  const ctx = document.getElementById('monthlyChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($monthly_labels) ?>,
      datasets: [{
        label: 'Avg mood',
        data: <?= json_encode($monthly_avgs) ?>,
        borderColor: C.teal,
        backgroundColor: mcRgba(C.teal, 0.08),
        borderWidth: 2.5,
        pointRadius: 5,
        pointBackgroundColor: C.teal,
        pointBorderColor: '#fff',
        pointBorderWidth: 2,
        tension: 0.35,
        fill: true,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ' Average: ' + ctx.raw + ' / 5'
          }
        }
      },
      scales: {
        y: {
          min: 1, max: 5,
          ticks: { stepSize: 1, font: { size: 10 }, color: C.ticks },
          grid: { color: C.grid },
          border: { display: false }
        },
        x: {
          ticks: { font: { size: 11 }, color: C.ticks },
          grid: { display: false },
          border: { display: false }
        }
      }
    }
  });
})();
<?php endif; ?>

// ── Assessment trend chart ────────────────────────────────────
<?php if (count($all_assessments) > 1 && count($assess_by_code) > 0): ?>
(function() {
  const C = mcChartPalette();
  const ctx = document.getElementById('trendChart');
  if (!ctx) return;

  const palette = {
    'PHQ9':  { border: C.red,    bg: mcRgba(C.red, 0.08)    },
    'GAD7':  { border: C.purple, bg: mcRgba(C.purple, 0.08) },
    'PSS10': { border: C.amber,  bg: mcRgba(C.amber, 0.08)  },
  };

  const rawData = <?= json_encode($trend_data) ?>;
  const datasets = Object.entries(rawData).map(([code, d]) => ({
    label: code,
    data: d.scores,
    labels: d.labels,
    borderColor:     (palette[code] || {}).border || C.blue,
    backgroundColor: (palette[code] || {}).bg     || mcRgba(C.blue, 0.08),
    borderWidth: 2.5,
    pointRadius: 5,
    pointBackgroundColor: (palette[code] || {}).border || C.blue,
    pointBorderColor: '#fff',
    pointBorderWidth: 2,
    tension: 0.3,
    fill: false,
  }));

  // Use first dataset's labels as x-axis (most common case: one template)
  const allLabels = datasets.length > 0 ? datasets[0].labels : [];

  new Chart(ctx, {
    type: 'line',
    data: { labels: allLabels, datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top',
          labels: { font: { size: 11 }, color: C.ticks, boxWidth: 12, boxHeight: 12 }
        },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.raw
          }
        }
      },
      scales: {
        y: {
          min: 0,
          ticks: { font: { size: 10 }, color: C.ticks },
          grid: { color: C.grid },
          border: { display: false }
        },
        x: {
          ticks: { font: { size: 11 }, color: C.ticks },
          grid: { display: false },
          border: { display: false }
        }
      }
    }
  });
})();
<?php endif; ?>
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
