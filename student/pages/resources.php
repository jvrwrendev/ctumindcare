<?php
// ============================================================
//  CTU MindCare — Resource Library
//  File: student/pages/resources.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── Filters from GET ─────────────────────────────────────────
$filter_type     = $_GET['type']     ?? 'all';
$filter_category = $_GET['category'] ?? 'all';
$search          = trim($_GET['q']   ?? '');

// Whitelist
$valid_types      = ['all','article','video','tip','hotline'];
$valid_categories = ['all','stress','anxiety','depression','general','crisis'];
if (!in_array($filter_type, $valid_types))           $filter_type     = 'all';
if (!in_array($filter_category, $valid_categories))  $filter_category = 'all';

// ── Build query ───────────────────────────────────────────────
$where  = ['r.is_published = 1'];
$params = [];

if ($filter_type !== 'all') {
    $where[]  = 'r.type = ?';
    $params[] = $filter_type;
}
if ($filter_category !== 'all') {
    $where[]  = 'r.category = ?';
    $params[] = $filter_category;
}
if ($search !== '') {
    $where[]  = '(r.title LIKE ? OR r.description LIKE ?)';
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT r.id, r.title, r.description, r.type, r.category,
           r.url, r.file_path, r.created_at,
           u.first_name AS uploader_first, u.last_name AS uploader_last
    FROM resources r
    JOIN users u ON r.uploaded_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY r.created_at DESC
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$resources = $stmt->fetchAll();

// ── Count per type and category (for filter badges) ───────────
$stmt = $db->prepare("
    SELECT type, category, COUNT(*) as cnt
    FROM resources
    WHERE is_published = 1
    GROUP BY type, category
");
$stmt->execute();
$counts_raw = $stmt->fetchAll();

$type_counts = ['all' => 0, 'article' => 0, 'video' => 0, 'tip' => 0, 'hotline' => 0];
$cat_counts  = ['all' => 0, 'stress' => 0, 'anxiety' => 0, 'depression' => 0, 'general' => 0, 'crisis' => 0];
foreach ($counts_raw as $row) {
    $type_counts[$row['type']] = ($type_counts[$row['type']] ?? 0) + (int)$row['cnt'];
    $cat_counts[$row['category']] = ($cat_counts[$row['category']] ?? 0) + (int)$row['cnt'];
    $type_counts['all'] += (int)$row['cnt'];
    $cat_counts['all']  += (int)$row['cnt'];
}
// Deduplicate "all" (it was summed per row)
$stmt2 = $db->prepare("SELECT COUNT(*) FROM resources WHERE is_published = 1");
$stmt2->execute();
$type_counts['all'] = (int)$stmt2->fetchColumn();
$cat_counts['all']  = $type_counts['all'];

// ── Featured / pinned (hotlines always shown at top) ──────────
$stmt = $db->prepare("
    SELECT id, title, description, url, category
    FROM resources
    WHERE is_published = 1 AND type = 'hotline'
    ORDER BY created_at ASC
    LIMIT 6
");
$stmt->execute();
$hotlines = $stmt->fetchAll();

// ── Helpers ──────────────────────────────────────────────────
function typeIcon(string $t): string {
    return match($t) {
        'article' => '📄',
        'video'   => '🎬',
        'tip'     => '💡',
        'hotline' => '📞',
        default   => '📌',
    };
}
function typeColor(string $t): array {
    return match($t) {
        'article' => ['bg' => '#EEEDFE', 'text' => '#4B3FA0', 'border' => '#CBC6F0'],
        'video'   => ['bg' => '#FAECE7', 'text' => '#8B3420', 'border' => '#F5C6B0'],
        'tip'     => ['bg' => '#FDF3DF', 'text' => '#7A4F0A', 'border' => '#FAC775'],
        'hotline' => ['bg' => '#FBEDED', 'text' => '#7A1A1A', 'border' => '#F5C6C2'],
        default   => ['bg' => '#F7F3EE', 'text' => '#5C5B57', 'border' => '#D9D3C9'],
    };
}
function catColor(string $c): array {
    return match($c) {
        'stress'     => ['bg' => '#FDF3DF', 'text' => '#7A4F0A'],
        'anxiety'    => ['bg' => '#EEEDFE', 'text' => '#4B3FA0'],
        'depression' => ['bg' => '#FBEDED', 'text' => '#7A1A1A'],
        'general'    => ['bg' => '#EAF7F2', 'text' => '#2D6B57'],
        'crisis'     => ['bg' => '#FFEDE0', 'text' => '#C0440B'],
        default      => ['bg' => '#F7F3EE', 'text' => '#5C5B57'],
    };
}
function truncate(string $text, int $len = 110): string {
    $text = strip_tags($text);
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Resource Library — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
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
    .main { padding: 28px 28px 56px; }

    /* ── Flash ── */
    .flash { padding: 11px 16px; border-radius: 9px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
    .flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
    .flash-warning { background: var(--amber-light); border: 1px solid #FAC775; color: #412402; }

    /* ── Page header ── */
    .page-header { margin-bottom: 24px; display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .page-title  { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); margin-bottom: 4px; }
    .page-sub    { font-size: 13px; color: var(--text-3); line-height: 1.6; }

    /* ── Search bar ── */
    .search-form  { display: flex; gap: 8px; }
    .search-wrap  { position: relative; }
    .search-icon  { position: absolute; left: 11px; top: 50%; transform: translateY(-50%); font-size: 14px; pointer-events: none; color: var(--text-3); }
    .search-input {
      padding: 9px 12px 9px 34px;
      background: var(--white); border: 1.5px solid var(--border);
      border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; color: var(--text); outline: none; width: 240px;
      transition: border-color .15s, box-shadow .15s;
    }
    .search-input:focus { border-color: var(--sage); box-shadow: 0 0 0 3px rgba(74,155,127,.08); }
    .search-input::placeholder { color: var(--text-3); }
    .search-btn {
      padding: 9px 16px; background: var(--sage); color: #fff; border: none;
      border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px;
      font-weight: 500; cursor: pointer; transition: background .15s;
    }
    .search-btn:hover { background: var(--sage-2); }
    .clear-link { padding: 9px 12px; font-size: 12px; color: var(--text-3); border: 1px solid var(--border); border-radius: 10px; background: var(--white); transition: color .15s; }
    .clear-link:hover { color: var(--red); border-color: var(--red); }

    /* ── Hotlines banner ── */
    .hotlines-section { margin-bottom: 24px; }
    .section-title    { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .hotlines-grid    { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; }
    .hotline-card {
      background: linear-gradient(135deg, #FBEDED, #FFF5F5);
      border: 1px solid #F5C6C2; border-radius: 12px;
      padding: 14px 16px; display: flex; flex-direction: column; gap: 6px;
      transition: box-shadow .15s, transform .15s;
    }
    .hotline-card:hover { box-shadow: 0 4px 16px rgba(212,68,68,.1); transform: translateY(-1px); }
    .hotline-icon  { font-size: 20px; }
    .hotline-name  { font-size: 13px; font-weight: 600; color: #7A1A1A; line-height: 1.3; }
    .hotline-desc  { font-size: 11px; color: #9A4040; line-height: 1.5; }
    .hotline-link  { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 500; color: var(--red); margin-top: 4px; }
    .hotline-link:hover { text-decoration: underline; }

    /* ── Filter row ── */
    .filter-row { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .filter-label { font-size: 11px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: .04em; margin-right: 4px; }
    .filter-chip {
      padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 500;
      border: 1.5px solid var(--border); background: var(--white); color: var(--text-2);
      cursor: pointer; text-decoration: none; transition: all .12s; white-space: nowrap;
      display: inline-flex; align-items: center; gap: 5px;
    }
    .filter-chip:hover  { border-color: var(--sage); color: var(--sage); background: var(--sage-light); }
    .filter-chip.active { background: var(--sage); border-color: var(--sage); color: #fff; }
    .chip-count { font-size: 10px; opacity: .75; }
    .filter-sep { width: 1px; height: 20px; background: var(--border); margin: 0 4px; }

    /* ── Results summary ── */
    .results-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .results-count { font-size: 13px; color: var(--text-3); }
    .results-count strong { color: var(--text); }

    /* ── Resource grid ── */
    .resource-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; }

    /* Resource card */
    .res-card {
      background: var(--white); border: 1px solid var(--border); border-radius: 14px;
      overflow: hidden; display: flex; flex-direction: column;
      transition: box-shadow .15s, transform .15s;
    }
    .res-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.08); transform: translateY(-2px); }
    .res-card-top   { padding: 18px 18px 14px; flex: 1; }
    .res-type-row   { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .res-type-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px; border-radius: 20px; border: 1px solid;
      font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em;
    }
    .res-cat-badge  { font-size: 10px; font-weight: 500; padding: 3px 9px; border-radius: 20px; }
    .res-title      { font-size: 14px; font-weight: 600; color: var(--text); line-height: 1.4; margin-bottom: 8px; }
    .res-desc       { font-size: 12px; color: var(--text-3); line-height: 1.65; }
    .res-card-foot  {
      padding: 12px 18px; border-top: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
      background: var(--sand);
    }
    .res-meta       { font-size: 11px; color: var(--text-3); }
    .res-link       {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 6px 14px; border-radius: 8px; font-size: 12px; font-weight: 500;
      transition: all .12s;
    }
    .res-link.article { background: var(--lav-light); color: var(--lav); }
    .res-link.article:hover { background: #d8d6fc; }
    .res-link.video   { background: var(--coral-light); color: var(--coral); }
    .res-link.video:hover { background: #f5d5cc; }
    .res-link.tip     { background: var(--amber-light); color: var(--amber); }
    .res-link.tip:hover { background: #fce5b0; }
    .res-link.hotline { background: #FBEDED; color: var(--red); }
    .res-link.hotline:hover { background: #f5c6c2; }
    .res-link.no-link { background: var(--sand-2); color: var(--text-3); cursor: default; }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 60px 24px; }
    .es-icon  { font-size: 48px; margin-bottom: 14px; }
    .es-title { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--text); margin-bottom: 8px; }
    .es-sub   { font-size: 13px; color: var(--text-3); line-height: 1.7; margin-bottom: 20px; }
    .es-btn   { display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; background: var(--sage); color: #fff; border-radius: 9px; font-size: 13px; font-weight: 500; }
    .es-btn:hover { background: var(--sage-2); }

    /* ── Search highlight ── */
    .search-active-banner {
      display: flex; align-items: center; gap: 10px;
      background: var(--lav-light); border: 1px solid #CBC6F0;
      border-radius: 10px; padding: 10px 14px;
      font-size: 13px; color: var(--lav); margin-bottom: 16px;
    }

    /* ── No-resources tip ── */
    .add-tip {
      background: var(--sage-light); border: 1px solid var(--sage-mid);
      border-radius: 12px; padding: 14px 18px; margin-top: 24px;
      font-size: 12px; color: var(--sage-dark); line-height: 1.7;
      display: flex; gap: 10px; align-items: flex-start;
    }

    @media (max-width: 1100px) { .resource-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); } }
    @media (max-width: 900px)  { .layout { grid-template-columns: 1fr; }  }
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

/* ── Sidebar overlay ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex;}
  .layout{grid-template-columns:1fr;}
  .sidebar{position:fixed;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px);width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);}
  .sidebar.mobile-open{transform:translateX(0);}
  .main{padding:20px 16px;}
}

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
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
      <a class="nav-item" href="journal.php"><div class="nav-icon">📓</div> Wellness journal</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Support</span>
      <a class="nav-item" href="appointments.php"><div class="nav-icon">📅</div> Book appointment</a>
      <a class="nav-item" href="counselor-chat.php"><div class="nav-icon">💬</div> Counselor chat</a>
      <a class="nav-item active" href="resources.php"><div class="nav-icon">📚</div> Resource library</a>
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

    <!-- Page header + search -->
    <div class="page-header">
      <div>
        <div class="page-title">Resource Library</div>
        <div class="page-sub">Articles, videos, tips, and hotlines curated by your wellness team.</div>
      </div>
      <form class="search-form" method="GET" action="resources.php">
        <?php if ($filter_type !== 'all'):     ?><input type="hidden" name="type"     value="<?= clean($filter_type) ?>"><?php endif; ?>
        <?php if ($filter_category !== 'all'): ?><input type="hidden" name="category" value="<?= clean($filter_category) ?>"><?php endif; ?>
        <div class="search-wrap">
          <span class="search-icon">🔍</span>
          <input class="search-input" type="text" name="q"
                 placeholder="Search resources…"
                 value="<?= clean($search) ?>" autocomplete="off">
        </div>
        <button class="search-btn" type="submit">Search</button>
        <?php if ($search || $filter_type !== 'all' || $filter_category !== 'all'): ?>
          <a class="clear-link" href="resources.php">Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Hotlines (always visible unless filtered away) -->
    <?php if ($hotlines && $filter_type === 'all' && !$search): ?>
    <div class="hotlines-section">
      <div class="section-title">
        📞 Emergency &amp; Crisis Hotlines
        <span style="font-size:11px;font-weight:400;color:var(--text-3)">Always available</span>
      </div>
      <div class="hotlines-grid">
        <?php foreach ($hotlines as $h): ?>
          <div class="hotline-card">
            <div class="hotline-icon">📞</div>
            <div class="hotline-name"><?= clean($h['title']) ?></div>
            <?php if ($h['description']): ?>
              <div class="hotline-desc"><?= clean(truncate($h['description'], 80)) ?></div>
            <?php endif; ?>
            <?php if ($h['url']): ?>
              <a class="hotline-link" href="<?= clean($h['url']) ?>" target="_blank" rel="noopener">
                Call / Visit →
              </a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <!-- Always-present local tip -->
        <div class="hotline-card">
          <div class="hotline-icon">🆘</div>
          <div class="hotline-name">CTU MindCare Crisis Help</div>
          <div class="hotline-desc">Reach out to your on-campus wellness team directly.</div>
          <a class="hotline-link" href="crisis.php">Open Crisis Help →</a>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active search notice -->
    <?php if ($search): ?>
      <div class="search-active-banner">
        🔍 Showing results for "<strong><?= clean($search) ?></strong>"
        <a href="resources.php?<?= $filter_type !== 'all' ? 'type='.clean($filter_type) : '' ?><?= $filter_category !== 'all' ? '&category='.clean($filter_category) : '' ?>"
           style="margin-left:auto;font-size:11px;color:var(--lav);font-weight:500">
          Clear search
        </a>
      </div>
    <?php endif; ?>

    <!-- Filter row: type -->
    <div class="filter-row">
      <span class="filter-label">Type</span>
      <?php
      $type_labels = ['all'=>'All','article'=>'Articles','video'=>'Videos','tip'=>'Tips','hotline'=>'Hotlines'];
      $type_icons  = ['all'=>'📚','article'=>'📄','video'=>'🎬','tip'=>'💡','hotline'=>'📞'];
      foreach ($type_labels as $t => $label):
        $is_act = ($filter_type === $t);
        $cnt    = $type_counts[$t] ?? 0;
        $url    = '?' . http_build_query(array_filter([
            'type'     => $t === 'all' ? null : $t,
            'category' => $filter_category === 'all' ? null : $filter_category,
            'q'        => $search ?: null,
        ]));
      ?>
        <a class="filter-chip <?= $is_act ? 'active' : '' ?>" href="<?= $url ?>">
          <?= $type_icons[$t] ?> <?= $label ?>
          <span class="chip-count"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>

      <div class="filter-sep"></div>
      <span class="filter-label">Topic</span>

      <?php
      $cat_labels = ['all'=>'All','stress'=>'Stress','anxiety'=>'Anxiety','depression'=>'Depression','general'=>'General','crisis'=>'Crisis'];
      $cat_icons  = ['all'=>'🌿','stress'=>'😤','anxiety'=>'😰','depression'=>'😔','general'=>'✨','crisis'=>'🆘'];
      foreach ($cat_labels as $cat => $clabel):
        $is_act = ($filter_category === $cat);
        $cnt    = $cat_counts[$cat] ?? 0;
        $url    = '?' . http_build_query(array_filter([
            'type'     => $filter_type === 'all' ? null : $filter_type,
            'category' => $cat === 'all' ? null : $cat,
            'q'        => $search ?: null,
        ]));
      ?>
        <a class="filter-chip <?= $is_act ? 'active' : '' ?>" href="<?= $url ?>">
          <?= $cat_icons[$cat] ?> <?= $clabel ?>
          <span class="chip-count"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Results count -->
    <div class="results-row">
      <div class="results-count">
        <strong><?= count($resources) ?></strong>
        <?= count($resources) === 1 ? 'resource' : 'resources' ?>
        <?php if ($filter_type !== 'all' || $filter_category !== 'all' || $search): ?>
          found
          <?php if ($filter_type !== 'all'): ?> · <?= ucfirst($filter_type) ?><?php endif; ?>
          <?php if ($filter_category !== 'all'): ?> · <?= ucfirst($filter_category) ?><?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- Resource grid -->
    <?php if ($resources): ?>
      <div class="resource-grid">
        <?php foreach ($resources as $r):
          $tc = typeColor($r['type']);
          $cc = catColor($r['category']);
          $link = $r['url'] ?: ($r['file_path'] ? '../../' . ltrim($r['file_path'], '/') : null);
        ?>
          <div class="res-card">
            <div class="res-card-top">
              <div class="res-type-row">
                <span class="res-type-badge"
                      style="background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;border-color:<?= $tc['border'] ?>">
                  <?= typeIcon($r['type']) ?> <?= ucfirst($r['type']) ?>
                </span>
                <span class="res-cat-badge"
                      style="background:<?= $cc['bg'] ?>;color:<?= $cc['text'] ?>">
                  <?= ucfirst($r['category']) ?>
                </span>
              </div>
              <div class="res-title"><?= clean($r['title']) ?></div>
              <?php if ($r['description']): ?>
                <div class="res-desc"><?= clean(truncate($r['description'])) ?></div>
              <?php endif; ?>
            </div>
            <div class="res-card-foot">
              <div class="res-meta">
                Added <?= date('M j, Y', strtotime($r['created_at'])) ?>
                &nbsp;·&nbsp; <?= clean($r['uploader_first']) ?>
              </div>
              <?php if ($link): ?>
                <a class="res-link <?= $r['type'] ?>"
                   href="<?= clean($link) ?>"
                   target="_blank" rel="noopener noreferrer">
                  <?php if ($r['type'] === 'video'):   echo '▶ Watch'; ?>
                  <?php elseif ($r['type'] === 'article'): echo '📖 Read'; ?>
                  <?php elseif ($r['type'] === 'hotline'): echo '📞 Contact'; ?>
                  <?php else: echo '💡 View'; ?>
                  <?php endif; ?>
                </a>
              <?php else: ?>
                <span class="res-link no-link">No link</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <!-- Empty state -->
      <div class="empty-state">
        <?php if ($search): ?>
          <div class="es-icon">🔍</div>
          <div class="es-title">No results found</div>
          <div class="es-sub">
            No resources match "<?= clean($search) ?>".
            Try a different keyword or clear the filters.
          </div>
          <a class="es-btn" href="resources.php">Browse all resources</a>
        <?php elseif ($filter_type !== 'all' || $filter_category !== 'all'): ?>
          <div class="es-icon">📂</div>
          <div class="es-title">Nothing here yet</div>
          <div class="es-sub">
            No <?= $filter_type !== 'all' ? strtolower($filter_type) . 's' : 'resources' ?>
            <?= $filter_category !== 'all' ? 'in the ' . strtolower($filter_category) . ' category' : '' ?>
            have been added yet.
          </div>
          <a class="es-btn" href="resources.php">View all resources</a>
        <?php else: ?>
          <div class="es-icon">📚</div>
          <div class="es-title">No resources yet</div>
          <div class="es-sub">
            Your wellness team hasn't added any resources yet.
            Check back soon, or reach out to your counselor directly.
          </div>
          <a class="es-btn" href="counselor-chat.php">💬 Chat with a counselor</a>
          <div class="add-tip" style="max-width:480px;margin:24px auto 0;text-align:left">
            <span style="font-size:18px;flex-shrink:0">💡</span>
            <div>
              <strong>Tip for admins:</strong> Resources are managed in the Wellness Admin panel.
              Log in as a <code>wellness_admin</code> to upload articles, videos, tips, and hotlines that students can access here.
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  </main>
</div>


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