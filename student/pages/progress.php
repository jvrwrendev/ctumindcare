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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>My Progress — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    /* ── Variables ── */
    :root {
      --sage:       #4A9B7F;
      --sage-2:     #3d8a6f;
      --sage-dark:  #2D6B57;
      --sage-mid:   #C4E8DC;
      --sage-light: #EAF7F2;
      --sand:       #F7F3EE;
      --sand-2:     #EDE8E1;
      --sand-3:     #D9D3C9;
      --white:      #FFFFFF;
      --text:       #2C2C2A;
      --text-2:     #5C5B57;
      --text-3:     #9A9891;
      --border:     #E8E4DE;
      --red:        #D64545;
      --amber:      #D4860A;
      --amber-light:#FDF3DF;
      --coral:      #D96B52;
      --coral-light:#FAECE7;
      --lav:        #7B6FC4;
      --lav-light:  #EEEDFE;
      --topbar-h:   58px;
      --sidebar-w:  230px;
    }

    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      font-family: 'DM Sans', sans-serif;
      font-size: 14px; color: var(--text);
      background: var(--sand); min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }

    /* ── Topbar ── */
    .topbar {
      height: var(--topbar-h); background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px;
      position: sticky; top: 0; z-index: 200; gap: 16px;
    }
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
    .sidebar {
      background: var(--white); border-right: 1px solid var(--border);
      padding: 20px 0; position: sticky; top: var(--topbar-h);
      height: calc(100vh - var(--topbar-h)); overflow-y: auto;
      display: flex; flex-direction: column;
    }
    .sb-section   { padding: 0 14px; margin-bottom: 6px; }
    .sb-label     { font-size: 9px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; padding: 0 8px; margin-bottom: 4px; display: block; }
    .nav-item     { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--sand); color: var(--text); }
    .nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .nav-icon     { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: var(--sand); }
    .nav-item.active .nav-icon { background: var(--sage-mid); }
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
    .main { padding: 28px 28px 56px; overflow-y: auto; }

    /* ── Flash ── */
    .flash { padding: 11px 16px; border-radius: 9px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
    .flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
    .flash-warning { background: var(--amber-light); border: 1px solid #FAC775; color: #412402; }
    .flash-error   { background: #FBEDED; border: 1px solid #F5C6C2; color: #8B2020; }

    /* ── Page header ── */
    .page-header     { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
    .page-title      { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); margin-bottom: 4px; }
    .page-sub        { font-size: 13px; color: var(--text-3); line-height: 1.6; }

    /* ── Range tabs ── */
    .range-tabs { display: flex; gap: 4px; background: var(--white); border: 1px solid var(--border); border-radius: 10px; padding: 4px; }
    .range-tab  { padding: 6px 14px; border-radius: 7px; font-size: 12px; font-weight: 500; color: var(--text-3); cursor: pointer; text-decoration: none; transition: all .12s; }
    .range-tab:hover  { color: var(--text); background: var(--sand); }
    .range-tab.active { background: var(--sage); color: #fff; }

    /* ── Stat row ── */
    .stat-row   { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px; }
    .stat-card  { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 18px; transition: box-shadow .15s; }
    .stat-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06); }
    .stat-top   { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .stat-icon  { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 17px; }
    .stat-pill  { font-size: 9px; font-weight: 600; padding: 3px 8px; border-radius: 100px; letter-spacing: .3px; text-transform: uppercase; }
    .stat-value { font-family: 'DM Serif Display', serif; font-size: 30px; color: var(--text); line-height: 1; margin-bottom: 4px; }
    .stat-value.md { font-size: 20px; margin-top: 4px; }
    .stat-sub   { font-size: 11px; color: var(--text-3); }
    .pill-green { background: var(--sage-light); color: var(--sage-dark); }
    .pill-amber { background: var(--amber-light); color: #7A4F0A; }
    .pill-lav   { background: var(--lav-light);   color: var(--lav); }
    .pill-gray  { background: var(--sand-2);       color: var(--text-3); }
    .pill-coral { background: var(--coral-light);  color: #8B3420; }

    /* ── Cards ── */
    .card         { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 22px; }
    .card-header  { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
    .card-title   { font-size: 14px; font-weight: 600; color: var(--text); }
    .card-sub     { font-size: 12px; color: var(--text-3); margin-top: 2px; }
    .card-link    { font-size: 12px; color: var(--sage); font-weight: 500; }
    .card-link:hover { text-decoration: underline; }

    /* ── Section grids ── */
    .two-col  { display: grid; grid-template-columns: 1fr 1fr;    gap: 18px; margin-bottom: 18px; }
    .three-col{ display: grid; grid-template-columns: 2fr 1fr;    gap: 18px; margin-bottom: 18px; }
    .full-row { margin-bottom: 18px; }

    /* ── Chart wrappers ── */
    .chart-wrap-tall   { height: 240px; position: relative; }
    .chart-wrap-medium { height: 180px; position: relative; }
    .chart-wrap-small  { height: 140px; position: relative; }

    /* ── Mood distribution bar ── */
    .dist-row      { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
    .dist-row:last-child { margin-bottom: 0; }
    .dist-emoji    { font-size: 18px; width: 24px; text-align: center; flex-shrink: 0; }
    .dist-label    { font-size: 12px; color: var(--text-2); width: 56px; flex-shrink: 0; }
    .dist-track    { flex: 1; height: 10px; background: var(--sand-2); border-radius: 5px; overflow: hidden; }
    .dist-fill     { height: 100%; border-radius: 5px; transition: width .6s ease; }
    .dist-count    { font-size: 11px; color: var(--text-3); width: 30px; text-align: right; flex-shrink: 0; }

    /* ── Assessment history ── */
    .assess-table   { width: 100%; border-collapse: collapse; font-size: 13px; }
    .assess-table th { font-size: 10px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: .5px; padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: left; background: var(--sand); }
    .assess-table td { padding: 12px; border-bottom: 1px solid var(--sand-2); vertical-align: middle; }
    .assess-table tr:last-child td { border-bottom: none; }
    .assess-table tr:hover td { background: var(--sand); }
    .code-pill    { display: inline-block; padding: 2px 8px; border-radius: 5px; font-size: 10px; font-weight: 600; background: var(--lav-light); color: var(--lav); margin-right: 6px; }
    .sev-pill     { display: inline-block; padding: 3px 10px; border-radius: 100px; font-size: 11px; font-weight: 500; }
    .s-minimal    { background: var(--sage-light);  color: var(--sage-dark); }
    .s-mild       { background: var(--amber-light); color: #7A4F0A; }
    .s-moderate   { background: #FEF3C7; color: #92400E; }
    .s-mod-severe { background: #FFEDE0; color: #C0440B; }
    .s-severe     { background: #FBEDED; color: #7A1A1A; }
    .s-gray       { background: var(--sand-2); color: var(--text-3); }

    /* Score bar in table */
    .score-bar-wrap { display: flex; align-items: center; gap: 8px; }
    .score-bar-track { flex: 1; height: 6px; background: var(--sand-2); border-radius: 3px; min-width: 60px; overflow: hidden; }
    .score-bar-fill  { height: 100%; border-radius: 3px; }
    .score-val       { font-size: 12px; font-weight: 600; color: var(--text); white-space: nowrap; }

    /* ── Mood log list ── */
    .mood-log-list { display: flex; flex-direction: column; gap: 8px; }
    .mood-log-item { display: flex; align-items: flex-start; gap: 12px; padding: 12px 14px; border-radius: 10px; border: 1px solid var(--border); background: var(--white); }
    .mli-emoji     { font-size: 22px; flex-shrink: 0; line-height: 1; margin-top: 1px; }
    .mli-body      { flex: 1; }
    .mli-top       { display: flex; align-items: center; gap: 8px; margin-bottom: 3px; }
    .mli-level     { font-size: 12px; font-weight: 600; }
    .mli-date      { font-size: 11px; color: var(--text-3); margin-left: auto; }
    .mli-note      { font-size: 12px; color: var(--text-2); line-height: 1.6; font-style: italic; }

    /* ── Calendar heatmap ── */
    .heatmap-wrap   { overflow-x: auto; padding-bottom: 4px; }
    .heatmap-grid   { display: flex; gap: 3px; }
    .heatmap-col    { display: flex; flex-direction: column; gap: 3px; }
    .heatmap-cell   { width: 14px; height: 14px; border-radius: 3px; flex-shrink: 0; cursor: default; transition: transform .1s; }
    .heatmap-cell:hover { transform: scale(1.3); }
    .hm-label       { font-size: 9px; color: var(--text-3); height: 14px; display: flex; align-items: center; white-space: nowrap; }
    .heatmap-legend { display: flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 11px; color: var(--text-3); }
    .hl-cell        { width: 12px; height: 12px; border-radius: 2px; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 40px 24px; }
    .es-icon     { font-size: 40px; margin-bottom: 10px; }
    .es-title    { font-size: 15px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
    .es-sub      { font-size: 12px; color: var(--text-3); margin-bottom: 16px; }
    .btn-sage    { display: inline-block; padding: 10px 22px; background: var(--sage); color: #fff; border-radius: 9px; font-size: 13px; font-weight: 500; }
    .btn-sage:hover { background: var(--sage-2); }

    /* ── Monthly avg legend ── */
    .month-legend { display: flex; align-items: center; gap: 16px; margin-top: 12px; flex-wrap: wrap; }
    .ml-item      { display: flex; align-items: center; gap: 6px; font-size: 11px; color: var(--text-3); }
    .ml-dot       { width: 10px; height: 10px; border-radius: 50%; }

    /* ── Responsive ── */
    @media (max-width: 1100px) {
      .stat-row    { grid-template-columns: repeat(2, 1fr); }
      .two-col     { grid-template-columns: 1fr; }
      .three-col   { grid-template-columns: 1fr; }
    }
    @media (max-width: 900px) {
      .hamburger   { display: flex; }
      .layout      { grid-template-columns: 1fr; }
      .sidebar     { position: fixed; top: 58px; left: 0; bottom: 0; z-index: 300; transform: translateX(-100%); transition: transform .28s ease; height: calc(100vh - 58px); width: 270px; box-shadow: 4px 0 24px rgba(0,0,0,.12); overflow-y: auto; }
      .sidebar.mobile-open { transform: translateX(0); }
      .main        { padding: 20px 16px; }
      
    }
  
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
  .sidebar{position:fixed!important;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px)!important;width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);display:flex!important;}
  .sidebar.mobile-open{transform:translateX(0);}
  .main,.main.main{padding:20px 16px!important;margin-left:0!important;}
}
@media(max-width:480px){.topbar-school{display:none;}.user-name{display:none;}.logout-btn{display:none;}}

</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- ── Topbar ── -->
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

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
      <a class="nav-item" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
      <a class="nav-item active" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
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

  <!-- ── Main ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Page header + range tabs -->
    <div class="page-header">
      <div>
        <div class="page-title">My Progress</div>
        <div class="page-sub">Track your mood trends, check-in streaks, and assessment history.</div>
      </div>
      <div class="range-tabs">
        <a class="range-tab <?= $range==='7'   ? 'active':'' ?>" href="?range=7">7 days</a>
        <a class="range-tab <?= $range==='30'  ? 'active':'' ?>" href="?range=30">30 days</a>
        <a class="range-tab <?= $range==='90'  ? 'active':'' ?>" href="?range=90">90 days</a>
        <a class="range-tab <?= $range==='all' ? 'active':'' ?>" href="?range=all">All time</a>
      </div>
    </div>

    <!-- ── Stat cards ── -->
    <div class="stat-row">

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--sage-light)">🔥</div>
          <span class="stat-pill pill-amber">Streak</span>
        </div>
        <div class="stat-value"><?= $streak ?></div>
        <div class="stat-sub"><?= $streak === 1 ? 'day' : 'days' ?> in a row</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--lav-light)">📅</div>
          <span class="stat-pill pill-lav"><?= $range_label ?></span>
        </div>
        <div class="stat-value"><?= $total_entries ?></div>
        <div class="stat-sub">check-ins · <?= $check_rate ?>% rate</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:<?= $avg_mood >= 4 ? 'var(--sage-light)' : ($avg_mood >= 3 ? 'var(--sand-2)' : 'var(--amber-light)') ?>">
            <?= $avg_mood ? ($mood_emoji[round($avg_mood)] ?? '😐') : '—' ?>
          </div>
          <span class="stat-pill <?= $avg_mood >= 4 ? 'pill-green' : ($avg_mood >= 3 ? 'pill-gray' : 'pill-amber') ?>">Avg mood</span>
        </div>
        <?php if ($avg_mood): ?>
          <div class="stat-value"><?= $avg_mood ?></div>
          <div class="stat-sub">out of 5 · <?= $mood_labels[round($avg_mood)] ?? '' ?></div>
        <?php else: ?>
          <div class="stat-value md">No data</div>
          <div class="stat-sub">Start checking in</div>
        <?php endif; ?>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--coral-light)">📋</div>
          <span class="stat-pill pill-coral">Assessments</span>
        </div>
        <div class="stat-value"><?= count($all_assessments) ?></div>
        <div class="stat-sub">total taken <?= count($all_assessments) > 0 ? '· <a href="#assess-history" style="color:var(--sage)">view all</a>' : '' ?></div>
      </div>

    </div>

    <!-- ── Mood over time + Distribution ── -->
    <div class="three-col">

      <!-- Mood chart -->
      <div class="card">
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
            <div class="es-icon">😊</div>
            <div class="es-title">No mood entries yet</div>
            <div class="es-sub">Start checking in daily to see your trend here.</div>
            <a class="btn-sage" href="mood-checkin.php">Log today's mood</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- Mood distribution -->
      <div class="card">
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
              <div class="dist-label" style="color:var(--text-2)"><?= $mood_labels[$lv] ?></div>
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
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-3);margin-bottom:8px">
              <span>Good days (4–5)</span>
              <span style="font-weight:600;color:var(--sage-dark)"><?= $mood_stats['good_days'] ?> day<?= $mood_stats['good_days'] != 1 ? 's' : '' ?></span>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-3)">
              <span>Low days (1–2)</span>
              <span style="font-weight:600;color:var(--amber)"><?= $mood_stats['low_days'] ?> day<?= $mood_stats['low_days'] != 1 ? 's' : '' ?></span>
            </div>
          </div>
        <?php endif; ?>
      </div>

    </div>

    <!-- ── 6-month average + Heatmap ── -->
    <div class="two-col">

      <!-- Monthly average trend -->
      <div class="card">
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
            <div class="ml-item"><div class="ml-dot" style="background:var(--sage)"></div> Avg mood level</div>
            <div class="ml-item" style="margin-left:auto;font-size:11px;color:var(--text-3)">Scale: 1 (Very Low) → 5 (Great)</div>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="es-icon">📊</div>
            <div class="es-title">No data yet</div>
            <div class="es-sub">Check in daily for at least a month to see trends here.</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Check-in heatmap (last 12 weeks) -->
      <div class="card">
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
        <div class="heatmap-wrap">
          <div class="heatmap-grid">
            <!-- Day labels column -->
            <div class="heatmap-col" style="gap:3px;margin-right:4px">
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
              <div class="heatmap-col">
                <div class="hm-label" style="height:17px;font-size:9px;color:var(--text-3)"><?= $show_month ? $month_label : '' ?></div>
                <?php foreach ($week as $hm_date):
                    $lv = $hm_map[$hm_date] ?? null;
                    $is_future = $hm_date > date('Y-m-d');
                    $bg = $is_future ? 'transparent' : $hm_colors[$lv];
                    $title = $hm_date . ($lv ? ': ' . $mood_labels[$lv] : ': No entry');
                ?>
                  <div class="heatmap-cell"
                       style="background:<?= $bg ?>;<?= $is_future ? 'opacity:0' : '' ?>"
                       title="<?= $title ?>"></div>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="heatmap-legend">
            <span>No entry</span>
            <div class="hl-cell" style="background:#EDE8E1"></div>
            <div class="hl-cell" style="background:#F5C6C2"></div>
            <div class="hl-cell" style="background:#FAC775"></div>
            <div class="hl-cell" style="background:#D9D3C9"></div>
            <div class="hl-cell" style="background:#9FE1CB"></div>
            <div class="hl-cell" style="background:#1D9E75"></div>
            <span>Great</span>
          </div>
        </div>
      </div>

    </div>

    <!-- ── Assessment trends ── -->
    <?php if ($all_assessments): ?>
    <div class="full-row">
      <div class="card">
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
      <div class="card">
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
            <div class="es-icon">📋</div>
            <div class="es-title">No assessments yet</div>
            <div class="es-sub">Take your first wellness assessment to track your mental health over time.</div>
            <a class="btn-sage" href="assessment.php">Take an assessment</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Recent mood log ── -->
    <div class="full-row">
      <div class="card">
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
              <div class="mood-log-item" style="background:<?= $mc['bg'] ?>;border-color:<?= $mc['border'] ?>20">
                <div class="mli-emoji"><?= $mood_emoji[$lv] ?></div>
                <div class="mli-body">
                  <div class="mli-top">
                    <span class="mli-level" style="color:<?= $mc['text'] ?>"><?= $mood_labels[$lv] ?></span>
                    <span style="font-size:10px;padding:2px 8px;border-radius:20px;background:<?= $mc['border'] ?>22;color:<?= $mc['text'] ?>">Level <?= $lv ?> / 5</span>
                    <span class="mli-date"><?= date('D, M j · g:i A', strtotime($e['recorded_at'])) ?></span>
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
            <div class="es-icon">😊</div>
            <div class="es-title">No mood entries yet</div>
            <div class="es-sub">Daily check-ins help you and your counselor understand your wellness journey.</div>
            <a class="btn-sage" href="mood-checkin.php">Log your first mood</a>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </main>
</div>

<script>
// ── Mood over time chart ──────────────────────────────────────
<?php if ($total_entries > 0): ?>
(function() {
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
            font: { size: 10 }, color: '#9A9891'
          },
          grid: { color: 'rgba(0,0,0,.04)' },
          border: { display: false }
        },
        x: {
          ticks: {
            font: { size: <?= $range_days > 30 ? 9 : 11 ?> },
            color: '#9A9891',
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
  const ctx = document.getElementById('monthlyChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($monthly_labels) ?>,
      datasets: [{
        label: 'Avg mood',
        data: <?= json_encode($monthly_avgs) ?>,
        borderColor: '#4A9B7F',
        backgroundColor: 'rgba(74,155,127,0.08)',
        borderWidth: 2.5,
        pointRadius: 5,
        pointBackgroundColor: '#4A9B7F',
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
})();
<?php endif; ?>

// ── Assessment trend chart ────────────────────────────────────
<?php if (count($all_assessments) > 1 && count($assess_by_code) > 0): ?>
(function() {
  const ctx = document.getElementById('trendChart');
  if (!ctx) return;

  const palette = {
    'PHQ9':  { border: '#D96B52', bg: 'rgba(217,107,82,0.08)'  },
    'GAD7':  { border: '#7B6FC4', bg: 'rgba(123,111,196,0.08)' },
    'PSS10': { border: '#D4860A', bg: 'rgba(212,134,10,0.08)'  },
  };

  const rawData = <?= json_encode($trend_data) ?>;
  const datasets = Object.entries(rawData).map(([code, d]) => ({
    label: code,
    data: d.scores,
    labels: d.labels,
    borderColor:     (palette[code] || {}).border || '#4A9B7F',
    backgroundColor: (palette[code] || {}).bg     || 'rgba(74,155,127,0.08)',
    borderWidth: 2.5,
    pointRadius: 5,
    pointBackgroundColor: (palette[code] || {}).border || '#4A9B7F',
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
          labels: { font: { size: 11 }, color: '#5C5B57', boxWidth: 12, boxHeight: 12 }
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
          ticks: { font: { size: 10 }, color: '#9A9891' },
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
})();
<?php endif; ?>
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