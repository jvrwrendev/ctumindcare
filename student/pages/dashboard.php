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
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-{$i} days"));
    $label = date('D', strtotime($date));
    $level = $week_map[$date] ?? null;
    $chart_labels[] = $label;
    $chart_data[]   = $level;
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
$mc_title = 'Dashboard — CTU MindCare';
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

<!-- Topbar -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- Sidebar -->
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- Main content -->
  <main class="main">

    <!-- Crisis banner -->
    <div class="crisis-banner mc-reveal">
      <div class="cb-left">
        <div class="cb-dot"></div>
        <div>
          <div class="cb-title">Need immediate help?</div>
          <div class="cb-text">Hopeline: 02-804-4673 &nbsp;·&nbsp; In Touch: 0917-800-1123 &nbsp;·&nbsp; Text HELLO to 4673</div>
        </div>
      </div>
      <a class="btn btn-crisis btn-xs" href="crisis.php"><i data-lucide="heart-pulse"></i> Get help now</a>
    </div>

    <!-- Flash message -->
    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>" data-auto-dismiss>
        <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>"></i>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Greeting row -->
    <div class="greeting-row">
      <div class="mc-reveal">
        <div class="greeting-title"><?= $greeting ?>, <?= clean($user['first_name']) ?> 👋</div>
        <div class="greeting-date"><?= date('l, F j, Y') ?> &nbsp;·&nbsp; Week <?= date('W') ?> of the semester</div>
      </div>
      <?php if (!$today_entry): ?>
        <a class="checkin-cta" href="mood-checkin.php">
          <div class="cta-emoji">😊</div>
          <div>
            <div class="cta-text">Haven't checked in today</div>
            <div class="cta-label">Log your mood now</div>
          </div>
          <i data-lucide="arrow-right" style="width:15px;height:15px;color:#fff;margin-left:4px"></i>
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
      <div class="stat-card mc-reveal" data-mc-delay="1">
        <div class="stat-top">
          <div class="stat-icon"><?= $today_entry ? $mood_emoji[$today_entry['mood_level']] : '<i data-lucide="smile"></i>' ?></div>
          <span class="stat-pill badge-<?= $today_entry ? 'green' : 'gray' ?>">Today</span>
        </div>
        <div class="stat-value <?= $today_entry ? '' : 'sm' ?>"><?= $today_entry ? $mood_labels[$today_entry['mood_level']] : 'Not checked in' ?></div>
        <div class="stat-sub"><?= $today_entry ? 'Level '.$today_entry['mood_level'].' / 5' : 'Tap to check in' ?></div>
      </div>

      <div class="stat-card mc-reveal" data-mc-delay="2">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--amber-soft);color:var(--amber-dark)"><i data-lucide="flame"></i></div>
          <span class="stat-pill badge-amber">Streak</span>
        </div>
        <div class="stat-value"><?= $streak ?></div>
        <div class="stat-sub"><?= $streak === 1 ? 'day' : 'days' ?> checked in (30 days)</div>
      </div>

      <div class="stat-card mc-reveal" data-mc-delay="3">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--purple-soft);color:var(--purple)"><i data-lucide="clipboard-list"></i></div>
          <span class="stat-pill badge-purple"><?= $latest ? $latest['code'] : 'None' ?></span>
        </div>
        <?php if ($latest): ?>
          <div class="stat-value"><?= $latest['total_score'] ?></div>
          <div class="stat-sub"><?= ucfirst(str_replace('_',' ',$latest['severity'])) ?> severity</div>
        <?php else: ?>
          <div class="stat-value sm">No assessment yet</div>
          <div class="stat-sub">Take one now</div>
        <?php endif; ?>
      </div>

      <div class="stat-card mc-reveal" data-mc-delay="4">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--teal-soft);color:var(--teal)"><i data-lucide="calendar"></i></div>
          <span class="stat-pill badge-teal">Next</span>
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
      <div class="col-stack">

        <!-- Mood chart -->
        <div class="card mc-reveal">
          <div class="card-header">
            <div class="card-title"><i data-lucide="smile"></i> Mood this week</div>
            <a class="card-link" href="progress.php">View full history <i data-lucide="arrow-right"></i></a>
          </div>
          <div class="card-body">
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
        </div>

        <!-- Assessment results table -->
        <div class="card mc-reveal" data-mc-delay="1">
          <div class="card-header">
            <div class="card-title"><i data-lucide="clipboard-list"></i> Assessment results</div>
            <a class="card-link" href="assessment.php">Take assessment <i data-lucide="arrow-right"></i></a>
          </div>
          <div class="table-wrap">
          <?php if ($recent_assessments): ?>
            <table class="assess-table">
              <thead>
                <tr><th>Assessment</th><th>Score</th><th>Severity</th><th>Date</th></tr>
              </thead>
              <tbody>
                <?php foreach ($recent_assessments as $a): ?>
                  <tr>
                    <td><span class="code-pill"><?= clean($a['code']) ?></span><?= clean($a['title']) ?></td>
                    <td><strong><?= $a['total_score'] ?></strong></td>
                    <td><span class="sev-pill <?= severityBadge($a['severity']) ?>"><?= ucfirst(str_replace('_',' ',$a['severity'])) ?></span></td>
                    <td class="cell-muted"><?= date('M j, Y', strtotime($a['taken_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php else: ?>
            <div class="empty-state">
              <div class="empty-icon"><i data-lucide="clipboard-list"></i></div>
              <div class="empty-text">No assessments yet</div>
              <div class="empty-text" style="color:var(--text-3)">Take your first one to see personalized insights.</div>
              <a class="btn btn-primary btn-xs es-btn" href="assessment.php">Take assessment</a>
            </div>
          <?php endif; ?>
          </div>
        </div>

      </div>

      <!-- Right column -->
      <div class="right-col">

        <!-- Quick actions -->
        <div class="card mc-reveal" data-mc-delay="1">
          <div class="card-header">
            <div class="card-title"><i data-lucide="zap"></i> Quick actions</div>
          </div>
          <div class="card-body">
            <div class="quick-grid">
              <a class="quick-item" href="mood-checkin.php"><div class="qi-icon"><i data-lucide="smile"></i></div><div class="qi-label">Check in</div></a>
              <a class="quick-item" href="assessment.php"><div class="qi-icon"><i data-lucide="clipboard-list"></i></div><div class="qi-label">Assessment</div></a>
              <a class="quick-item" href="csuq.php"><div class="qi-icon"><i data-lucide="laptop"></i></div><div class="qi-label">CSUQ survey</div></a>
              <a class="quick-item" href="appointments.php"><div class="qi-icon"><i data-lucide="calendar"></i></div><div class="qi-label">Book session</div></a>
              <a class="quick-item" href="forum.php"><div class="qi-icon"><i data-lucide="messages-square"></i></div><div class="qi-label">Peer forum</div></a>
            </div>
          </div>
        </div>

        <!-- Appointments -->
        <div class="card mc-reveal" data-mc-delay="2">
          <div class="card-header">
            <div class="card-title"><i data-lucide="calendar"></i> Appointments</div>
            <a class="card-link" href="appointments.php">Book <i data-lucide="arrow-right"></i></a>
          </div>
          <div class="card-body">
          <?php if ($appointments): ?>
            <?php foreach ($appointments as $ap): ?>
              <div class="appt-item">
                <div class="appt-date-box">
                  <div class="adb-month"><?= date('M', strtotime($ap['appointment_date'])) ?></div>
                  <div class="adb-day"><?= date('j', strtotime($ap['appointment_date'])) ?></div>
                  <div class="adb-dow"><?= date('D', strtotime($ap['appointment_date'])) ?></div>
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
            <p style="font-size:13px;color:var(--text-3)">No upcoming appointments. <a href="appointments.php" style="color:var(--primary)">Book a session.</a></p>
          <?php endif; ?>
          </div>
        </div>

        <!-- Recommended resources (static for now) -->
        <div class="card mc-reveal" data-mc-delay="3">
          <div class="card-header">
            <div class="card-title"><i data-lucide="library"></i> Recommended for you</div>
            <a class="card-link" href="resources.php">See all <i data-lucide="arrow-right"></i></a>
          </div>
          <div class="card-body">
            <div class="res-list">
              <a class="res-item" href="resources.php">
                <div class="res-icon" style="background:var(--teal-soft);color:var(--teal)"><i data-lucide="wind"></i></div>
                <div><div class="res-title">5-minute breathing for exam stress</div><div class="res-type">Article · 3 min read</div></div>
              </a>
              <a class="res-item" href="resources.php">
                <div class="res-icon" style="background:var(--purple-soft);color:var(--purple)"><i data-lucide="music"></i></div>
                <div><div class="res-title">Guided sleep meditation</div><div class="res-type">Video · 12 minutes</div></div>
              </a>
              <a class="res-item" href="resources.php">
                <div class="res-icon" style="background:var(--amber-soft);color:var(--amber-dark)"><i data-lucide="pen-line"></i></div>
                <div><div class="res-title">How to handle academic pressure</div><div class="res-type">Tip · Quick read</div></div>
              </a>
            </div>
          </div>
        </div>

      </div>
    </div>

    <!-- Bottom row -->
    <div class="bottom-row">
      <div class="card mc-reveal">
        <div class="card-header">
          <div class="card-title"><i data-lucide="book-open"></i> Wellness journal</div>
          <a class="card-link" href="journal.php">Open journal <i data-lucide="arrow-right"></i></a>
        </div>
        <div class="card-body">
          <a class="journal-area" href="journal.php">
            Write today's journal entry...<br><br>
            <span style="font-size:11px;font-style:normal;color:var(--text-3)">Your journal is private. Only you can see it.</span>
          </a>
        </div>
      </div>
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header">
          <div class="card-title"><i data-lucide="messages-square"></i> Recent forum activity</div>
          <a class="card-link" href="forum.php">Go to forum <i data-lucide="arrow-right"></i></a>
        </div>
        <div class="card-body">
        <?php if ($forum_posts): ?>
          <?php foreach ($forum_posts as $fp): ?>
            <a class="forum-item" href="forum.php" style="display:block;padding:10px 12px;background:var(--surface-2);border-radius:9px;margin-bottom:8px;text-decoration:none">
              <div class="fi-title" style="font-size:12.5px;font-weight:500;color:var(--text);margin-bottom:3px"><?= clean($fp['title']) ?></div>
              <div class="fi-meta" style="font-size:10.5px;color:var(--text-3)">
                <?= $fp['react_count'] ?> relate &nbsp;·&nbsp;
                <?= $fp['reply_count'] ?> replies &nbsp;·&nbsp;
                <?= date('M j', strtotime($fp['created_at'])) ?>
              </div>
            </a>
          <?php endforeach; ?>
        <?php else: ?>
          <p style="font-size:13px;color:var(--text-3)">No posts yet. <a href="forum.php" style="color:var(--primary)">Be the first to post.</a></p>
        <?php endif; ?>
        </div>
      </div>
    </div>

  </main>
</div>

<script>
const colors = window.mcChartColors ? window.mcChartColors() : {};
const levelColors = function (lv) {
  if (lv === null || lv === undefined) return { bg: colors.gray || 'rgba(148,163,184,.25)', border: colors.gray || '#94A3B8' };
  if (lv <= 2) return { bg: colors.amber || 'rgba(245,158,11,.25)', border: colors.amber || '#F59E0B' };
  return { bg: colors.teal || 'rgba(20,184,166,.4)', border: colors.teal || '#14B8A6' };
};
const rawData = <?= json_encode($chart_data) ?>;
const chartData = rawData.map(function (lv) { return lv === null ? 0 : lv; });
const ctx = document.getElementById('moodChart').getContext('2d');
new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      data: chartData,
      backgroundColor: rawData.map(function (lv) { return levelColors(lv).bg; }),
      borderColor: rawData.map(function (lv) { return levelColors(lv).border; }),
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
          label: function (c) {
            const labels = ['','Very Low','Low','Okay','Good','Great'];
            const lv = rawData[c.dataIndex];
            return lv ? ' ' + labels[lv] : ' No entry';
          }
        }
      }
    },
    scales: {
      y: {
        min: 0, max: 5,
        ticks: { stepSize: 1, font: { size: 10 }, color: colors.ticks || '#94A3B8' },
        grid: { color: colors.grid || 'rgba(100,116,139,.15)' },
        border: { display: false }
      },
      x: {
        ticks: { font: { size: 11 }, color: colors.ticks || '#94A3B8' },
        grid: { display: false },
        border: { display: false }
      }
    }
  }
});
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
