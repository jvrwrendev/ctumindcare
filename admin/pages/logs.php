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

// ── Filters ───────────────────────────────────────────────────
$filter_type  = $_GET['type']   ?? 'all';
$filter_level = $_GET['level']  ?? 'all';
$search       = clean($_GET['q'] ?? '');
$date_from    = clean($_GET['from'] ?? '');
$date_to      = clean($_GET['to']   ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$per_page     = 50;
$offset       = ($page - 1) * $per_page;

// ── Build query ───────────────────────────────────────────────
$where = ["1=1"];
$params = [];

if ($filter_type !== 'all') {
    $where[] = "log_type = ?";
    $params[] = $filter_type;
}
if ($filter_level !== 'all') {
    $where[] = "level = ?";
    $params[] = $filter_level;
}
if ($search) {
    $where[] = "(action LIKE ? OR description LIKE ? OR ip_address LIKE ? OR actor_name LIKE ?)";
    $params = array_merge($params, array_fill(0, 4, "%{$search}%"));
}
if ($date_from) {
    $where[] = "created_at >= ?";
    $params[] = $date_from . ' 00:00:00';
}
if ($date_to) {
    $where[] = "created_at <= ?";
    $params[] = $date_to . ' 23:59:59';
}

$wc = implode(' AND ', $where);

// Count
$count_stmt = $db->prepare("SELECT COUNT(*) as total FROM system_logs WHERE {$wc}");
$count_stmt->execute($params);
$total_logs = $count_stmt->fetch()['total'] ?? 0;
$total_pages = (int)ceil($total_logs / $per_page);

// Fetch logs
$log_stmt = $db->prepare("
    SELECT * FROM system_logs
    WHERE {$wc}
    ORDER BY created_at DESC
    LIMIT {$per_page} OFFSET {$offset}
");
$log_stmt->execute($params);
$logs = $log_stmt->fetchAll();

// ── Summary stats ─────────────────────────────────────────────
$stats_stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(level = 'error')    as errors,
        SUM(level = 'warning')  as warnings,
        SUM(level = 'info')     as infos,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) as today
    FROM system_logs
");
$stats = $stats_stmt->fetch();

// ── Log type labels ───────────────────────────────────────────
function logTypeLabel(string $type): array {
    return match($type) {
        'auth'      => ['label' => 'Auth',      'icon' => '🔐', 'class' => 'type-auth'],
        'user'      => ['label' => 'User',      'icon' => '👤', 'class' => 'type-user'],
        'crisis'    => ['label' => 'Crisis',    'icon' => '🚨', 'class' => 'type-crisis'],
        'session'   => ['label' => 'Session',   'icon' => '🗂️', 'class' => 'type-session'],
        'system'    => ['label' => 'System',    'icon' => '⚙️', 'class' => 'type-system'],
        'data'      => ['label' => 'Data',      'icon' => '🗄️', 'class' => 'type-data'],
        default     => ['label' => ucfirst($type), 'icon' => '📋', 'class' => 'type-default'],
    };
}

function levelClass(string $level): string {
    return match($level) {
        'error'   => 'level-error',
        'warning' => 'level-warning',
        'info'    => 'level-info',
        'debug'   => 'level-debug',
        default   => 'level-info',
    };
}

// ── Export CSV ────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="system_logs_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID','Type','Level','Actor','Action','Description','IP Address','Date']);
    foreach ($logs as $log) {
        fputcsv($out, [
            $log['id'],
            $log['log_type'] ?? '',
            $log['level'] ?? '',
            $log['actor_name'] ?? '',
            $log['action'] ?? '',
            $log['description'] ?? '',
            $log['ip_address'] ?? '',
            $log['created_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>System Logs — CTU MindCare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Playfair+Display:ital,wght@0,600;1,400&display=swap" rel="stylesheet">
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
  --mono:         'Courier New', Courier, monospace;
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

/* ── Page header ── */
.page-header {
  display: flex; align-items: flex-start;
  justify-content: space-between; margin-bottom: 26px; gap: 16px;
}
.page-label { font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--sage); margin-bottom: 5px; }
.page-title { font-family: 'Playfair Display', serif; font-size: 27px; color: var(--text); line-height: 1.15; }
.page-sub   { font-size: 13px; color: var(--text-3); margin-top: 4px; }
.header-actions { display: flex; gap: 10px; align-items: center; flex-shrink: 0; margin-top: 6px; }

/* ── Flash ── */
.flash {
  padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px;
  margin-bottom: 22px; display: flex; align-items: center; gap: 10px; font-weight: 500;
  animation: slideIn .2s ease;
}
@keyframes slideIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
.flash-error   { background: var(--red-bg);   border: 1px solid var(--red-border);   color: var(--red); }

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
.stat-card.c-total::after  { background: linear-gradient(90deg, var(--sage), #6DC5B2); }
.stat-card.c-error::after  { background: linear-gradient(90deg, var(--red), #E07070); }
.stat-card.c-warn::after   { background: linear-gradient(90deg, var(--amber), #E0B86A); }
.stat-card.c-today::after  { background: linear-gradient(90deg, var(--blue), #7BB8F0); }
.stat-card:hover { box-shadow: var(--shadow-md); }
.stat-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.stat-card.c-total .stat-icon { background: var(--sage-light); }
.stat-card.c-error .stat-icon { background: var(--red-bg); }
.stat-card.c-warn  .stat-icon { background: var(--amber-bg); }
.stat-card.c-today .stat-icon { background: var(--blue-bg); }
.stat-value { font-family: 'Playfair Display', serif; font-size: 26px; color: var(--text); line-height: 1; }
.stat-label { font-size: 11px; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; font-weight: 600; margin-top: 3px; }

/* ── Filter card ── */
.filter-card {
  background: var(--white); border: 1px solid var(--border);
  border-radius: var(--radius); margin-bottom: 18px;
  box-shadow: var(--shadow-sm); overflow: hidden;
}
.filter-bar {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 20px; flex-wrap: wrap;
  border-bottom: 1px solid var(--border); background: var(--sand);
}
.filter-group { display: flex; align-items: center; gap: 8px; }
.filter-label { font-size: 11px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; white-space: nowrap; }
.filter-select {
  padding: 6px 12px; background: var(--white); border: 1.5px solid var(--border-2);
  border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
  font-size: 12px; color: var(--text); outline: none; cursor: pointer; transition: border-color .15s;
}
.filter-select:focus { border-color: var(--sage); box-shadow: 0 0 0 3px rgba(62,142,126,.1); }
.filter-divider { width: 1px; height: 20px; background: var(--border-2); }
.search-wrap { display: flex; gap: 8px; flex: 1; min-width: 200px; }
.search-input {
  flex: 1; padding: 7px 14px; background: var(--white);
  border: 1.5px solid var(--border-2); border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif; font-size: 12px; color: var(--text);
  outline: none; transition: all .15s; min-width: 0;
}
.search-input::placeholder { color: var(--text-3); }
.search-input:focus { border-color: var(--sage); box-shadow: 0 0 0 3px rgba(62,142,126,.1); }
.date-wrap { display: flex; align-items: center; gap: 8px; }
.date-input {
  padding: 6px 12px; background: var(--white); border: 1.5px solid var(--border-2);
  border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
  font-size: 12px; color: var(--text); outline: none; transition: border-color .15s;
}
.date-input:focus { border-color: var(--sage); box-shadow: 0 0 0 3px rgba(62,142,126,.1); }
.date-sep { font-size: 11px; color: var(--text-3); font-weight: 600; }
.btn-filter {
  padding: 7px 16px; background: var(--sage); color: #fff; border: none;
  border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
  font-size: 12px; font-weight: 600; cursor: pointer; transition: background .13s; white-space: nowrap;
}
.btn-filter:hover { background: var(--sage-2); }
.btn-reset {
  padding: 7px 14px; background: var(--white); color: var(--text-2);
  border: 1.5px solid var(--border-2); border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 500;
  cursor: pointer; transition: all .13s; text-decoration: none; display: inline-flex; align-items: center;
}
.btn-reset:hover { border-color: var(--border); color: var(--text); text-decoration: none; }

/* ── Result meta ── */
.result-meta {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 20px; font-size: 12px; color: var(--text-3);
  border-bottom: 1px solid var(--border);
}
.result-count strong { color: var(--text-2); font-weight: 600; }

/* ── Export btn ── */
.btn-export {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 14px; background: var(--white);
  border: 1.5px solid var(--border-2); border-radius: var(--radius-sm);
  font-family: 'Sora', sans-serif; font-size: 12px; font-weight: 500;
  color: var(--text-2); cursor: pointer; text-decoration: none; transition: all .13s;
}
.btn-export:hover { border-color: var(--sage); color: var(--sage); text-decoration: none; }

/* ── Log table ── */
.table-wrap { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th {
  font-size: 10px; font-weight: 700; color: var(--text-3);
  text-transform: uppercase; letter-spacing: .07em;
  padding: 10px 18px; text-align: left;
  border-bottom: 1px solid var(--border);
  background: var(--sand); white-space: nowrap;
}
.data-table td {
  padding: 11px 18px; font-size: 13px;
  border-bottom: 1px solid var(--sand-2); vertical-align: top;
}
.data-table tbody tr { transition: background .1s; }
.data-table tbody tr:hover td { background: #FAFAF8; }
.data-table tbody tr:last-child td { border-bottom: none; }

/* ── Log type badge ── */
.type-badge {
  display: inline-flex; align-items: center; gap: 5px;
  padding: 3px 9px; border-radius: 100px;
  font-size: 10.5px; font-weight: 600; white-space: nowrap;
}
.type-auth    { background: var(--lav-bg);    color: var(--lav);   border: 1px solid var(--lav-border); }
.type-user    { background: var(--sage-light); color: var(--sage-dark); border: 1px solid var(--sage-mid); }
.type-crisis  { background: var(--red-bg);    color: var(--red);   border: 1px solid var(--red-border); }
.type-session { background: var(--amber-bg);  color: var(--amber); border: 1px solid var(--amber-border); }
.type-system  { background: #E8EBF0; color: var(--navy); border: 1px solid #C8CDD8; }
.type-data    { background: var(--blue-bg);   color: var(--blue);  border: 1px solid var(--blue-border); }
.type-default { background: var(--sand-2);    color: var(--text-2); border: 1px solid var(--border); }

/* ── Level badge ── */
.level-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 10px; font-weight: 700; padding: 2px 8px;
  border-radius: 100px; text-transform: uppercase; letter-spacing: .05em; white-space: nowrap;
}
.level-badge::before { content: ''; width: 5px; height: 5px; border-radius: 50%; }
.level-error   { background: var(--red-bg);   color: var(--red);   }
.level-error::before { background: var(--red); }
.level-warning { background: var(--amber-bg); color: var(--amber); }
.level-warning::before { background: var(--amber); }
.level-info    { background: var(--blue-bg);  color: var(--blue);  }
.level-info::before { background: var(--blue); }
.level-debug   { background: var(--sand-2);   color: var(--text-3);}
.level-debug::before { background: var(--text-3); }

/* ── Action cell ── */
.action-cell .action-name { font-size: 13px; font-weight: 500; color: var(--text); }
.action-cell .action-desc { font-size: 11.5px; color: var(--text-3); margin-top: 3px; line-height: 1.5; }

/* ── Actor cell ── */
.actor-cell { display: flex; align-items: center; gap: 8px; }
.actor-av {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--sage-light); color: var(--sage-dark);
  display: flex; align-items: center; justify-content: center;
  font-size: 9px; font-weight: 700; flex-shrink: 0; letter-spacing: .03em;
}
.actor-av.av-system { background: #E8EBF0; color: var(--navy); }
.actor-name { font-size: 12px; font-weight: 500; color: var(--text); white-space: nowrap; }
.actor-role { font-size: 10px; color: var(--text-3); }

/* ── IP cell ── */
.ip-cell {
  font-family: var(--mono); font-size: 11.5px;
  color: var(--text-3); white-space: nowrap;
}

/* ── Timestamp ── */
.ts-date { font-size: 12px; color: var(--text-2); white-space: nowrap; }
.ts-time { font-size: 10.5px; color: var(--text-3); margin-top: 2px; }

/* ── Expand row ── */
.log-row { cursor: pointer; }
.log-detail {
  display: none;
  background: #FAFAF8;
  border-top: 1px dashed var(--border);
}
.log-detail.open { display: table-row; }
.log-detail td { padding: 14px 18px 14px 56px; }
.detail-inner {
  background: var(--sand); border: 1px solid var(--border);
  border-radius: var(--radius-sm); padding: 12px 16px;
  font-size: 12.5px; color: var(--text-2); line-height: 1.7;
}
.detail-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 20px; margin-bottom: 10px; }
.detail-item .dl { font-size: 10px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 3px; }
.detail-item .dv { font-size: 12.5px; color: var(--text); }
.detail-full { margin-top: 8px; padding-top: 10px; border-top: 1px solid var(--border); }
.detail-full .dl { font-size: 10px; font-weight: 700; color: var(--text-3); text-transform: uppercase; letter-spacing: .06em; margin-bottom: 4px; }
.detail-full .dv { font-family: var(--mono); font-size: 12px; color: var(--text-2); white-space: pre-wrap; word-break: break-all; }
.expand-arrow { font-size: 10px; color: var(--text-3); transition: transform .15s; flex-shrink: 0; }
.log-row.expanded .expand-arrow { transform: rotate(90deg); }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 60px 24px; }
.empty-icon  { font-size: 36px; margin-bottom: 12px; }
.empty-text  { font-size: 14px; color: var(--text-3); }

/* ── Pagination ── */
.pagination {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 20px; border-top: 1px solid var(--border);
  background: var(--sand);
}
.page-info { font-size: 12px; color: var(--text-3); }
.page-links { display: flex; gap: 4px; }
.page-link {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 7px;
  font-size: 12px; font-weight: 500; text-decoration: none;
  color: var(--text-2); border: 1px solid var(--border);
  background: var(--white); transition: all .13s;
}
.page-link:hover { border-color: var(--sage); color: var(--sage); text-decoration: none; }
.page-link.active { background: var(--sage); color: #fff; border-color: var(--sage); font-weight: 700; }
.page-link.disabled { opacity: .4; pointer-events: none; }
.page-link.wide { width: auto; padding: 0 12px; }

/* ── Responsive ── */
@media (max-width: 1060px) {
  .stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 960px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .main { padding: 22px 18px 48px; }
}
@media (max-width: 680px) {
  .filter-bar { flex-direction: column; align-items: stretch; }
  .filter-divider { display: none; }
  .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
  .page-header { flex-direction: column; align-items: flex-start; }
  .detail-grid { grid-template-columns: 1fr 1fr; }
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
      <a class="nav-item" href="analytics.php">
        <div class="nav-icon">📈</div> Analytics
      </a>
      <a class="nav-item" href="announcements.php">
        <div class="nav-icon">📢</div> Announcements
      </a>
      <a class="nav-item active" href="logs.php">
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

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <div class="page-label">Audit Trail</div>
        <div class="page-title">System Logs</div>
        <div class="page-sub">Track all system activity, authentication events, and user actions</div>
      </div>
      <div class="header-actions">
        <?php
          $export_params = http_build_query(array_merge($_GET, ['export' => 'csv']));
        ?>
        <a class="btn-export" href="logs.php?<?= $export_params ?>">
          ⬇ Export CSV
        </a>
      </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card c-total">
        <div class="stat-icon">📋</div>
        <div>
          <div class="stat-value"><?= number_format($stats['total'] ?? 0) ?></div>
          <div class="stat-label">Total Logs</div>
        </div>
      </div>
      <div class="stat-card c-error">
        <div class="stat-icon">🔴</div>
        <div>
          <div class="stat-value"><?= number_format($stats['errors'] ?? 0) ?></div>
          <div class="stat-label">Errors</div>
        </div>
      </div>
      <div class="stat-card c-warn">
        <div class="stat-icon">⚠️</div>
        <div>
          <div class="stat-value"><?= number_format($stats['warnings'] ?? 0) ?></div>
          <div class="stat-label">Warnings</div>
        </div>
      </div>
      <div class="stat-card c-today">
        <div class="stat-icon">🕐</div>
        <div>
          <div class="stat-value"><?= number_format($stats['today'] ?? 0) ?></div>
          <div class="stat-label">Last 24h</div>
        </div>
      </div>
    </div>

    <!-- Filter Card -->
    <div class="filter-card">
      <form method="GET">
        <div class="filter-bar">
          <!-- Type filter -->
          <div class="filter-group">
            <span class="filter-label">Type</span>
            <select class="filter-select" name="type">
              <option value="all"     <?= $filter_type==='all'     ? 'selected':'' ?>>All types</option>
              <option value="auth"    <?= $filter_type==='auth'    ? 'selected':'' ?>>🔐 Auth</option>
              <option value="user"    <?= $filter_type==='user'    ? 'selected':'' ?>>👤 User</option>
              <option value="crisis"  <?= $filter_type==='crisis'  ? 'selected':'' ?>>🚨 Crisis</option>
              <option value="session" <?= $filter_type==='session' ? 'selected':'' ?>>🗂️ Session</option>
              <option value="system"  <?= $filter_type==='system'  ? 'selected':'' ?>>⚙️ System</option>
              <option value="data"    <?= $filter_type==='data'    ? 'selected':'' ?>>🗄️ Data</option>
            </select>
          </div>

          <div class="filter-divider"></div>

          <!-- Level filter -->
          <div class="filter-group">
            <span class="filter-label">Level</span>
            <select class="filter-select" name="level">
              <option value="all"     <?= $filter_level==='all'     ? 'selected':'' ?>>All levels</option>
              <option value="info"    <?= $filter_level==='info'    ? 'selected':'' ?>>Info</option>
              <option value="warning" <?= $filter_level==='warning' ? 'selected':'' ?>>Warning</option>
              <option value="error"   <?= $filter_level==='error'   ? 'selected':'' ?>>Error</option>
              <option value="debug"   <?= $filter_level==='debug'   ? 'selected':'' ?>>Debug</option>
            </select>
          </div>

          <div class="filter-divider"></div>

          <!-- Date range -->
          <div class="filter-group">
            <span class="filter-label">Date</span>
            <div class="date-wrap">
              <input class="date-input" type="date" name="from" value="<?= clean($date_from) ?>" title="From">
              <span class="date-sep">→</span>
              <input class="date-input" type="date" name="to" value="<?= clean($date_to) ?>" title="To">
            </div>
          </div>

          <div class="filter-divider"></div>

          <!-- Search -->
          <div class="search-wrap">
            <input class="search-input" type="text" name="q" value="<?= clean($search) ?>" placeholder="Search action, description, IP…">
            <button type="submit" class="btn-filter">Filter</button>
            <?php if ($filter_type !== 'all' || $filter_level !== 'all' || $search || $date_from || $date_to): ?>
              <a class="btn-reset" href="logs.php">Clear</a>
            <?php endif; ?>
          </div>
        </div>
      </form>

      <!-- Result meta -->
      <div class="result-meta">
        <span class="result-count">
          Showing <strong><?= number_format(min($per_page, $total_logs - $offset)) ?></strong>
          of <strong><?= number_format($total_logs) ?></strong> entries
          <?php if ($search || $filter_type !== 'all' || $filter_level !== 'all'): ?>
            <span style="color:var(--sage);font-weight:600"> (filtered)</span>
          <?php endif; ?>
        </span>
        <span style="font-size:11px;color:var(--text-3)">Click any row to expand details</span>
      </div>

      <!-- Table -->
      <div class="table-wrap">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:20px"></th>
              <th>Type</th>
              <th>Level</th>
              <th>Actor</th>
              <th>Action / Description</th>
              <th>IP Address</th>
              <th>Timestamp</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($logs)): ?>
              <tr><td colspan="7">
                <div class="empty-state">
                  <div class="empty-icon">🔍</div>
                  <div class="empty-text">No log entries match your filters.</div>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($logs as $i => $log):
                $typeInfo = logTypeLabel($log['log_type'] ?? 'default');
                $lvlClass = levelClass($log['level'] ?? 'info');
                $isSystem = ($log['actor_role'] ?? '') === 'system' || empty($log['actor_name']);
                $initials = $isSystem ? '⚙' : strtoupper(
                  substr(explode(' ', trim($log['actor_name'] ?? 'S'))[0], 0, 1) .
                  substr(explode(' ', trim($log['actor_name'] ?? 'S '))[1] ?? 'Y', 0, 1)
                );
              ?>
                <tr class="log-row" onclick="toggleDetail(<?= $i ?>)" id="row-<?= $i ?>">
                  <td><span class="expand-arrow">▶</span></td>
                  <td>
                    <span class="type-badge <?= $typeInfo['class'] ?>">
                      <?= $typeInfo['icon'] ?> <?= $typeInfo['label'] ?>
                    </span>
                  </td>
                  <td>
                    <span class="level-badge <?= $lvlClass ?>">
                      <?= ucfirst($log['level'] ?? 'info') ?>
                    </span>
                  </td>
                  <td>
                    <div class="actor-cell">
                      <div class="actor-av <?= $isSystem ? 'av-system' : '' ?>"><?= $isSystem ? '⚙' : $initials ?></div>
                      <div>
                        <div class="actor-name"><?= $isSystem ? 'System' : clean($log['actor_name'] ?? 'Unknown') ?></div>
                        <?php if (!empty($log['actor_role'])): ?>
                          <div class="actor-role"><?= clean(ucfirst($log['actor_role'])) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td>
                    <div class="action-cell">
                      <div class="action-name"><?= clean($log['action'] ?? '—') ?></div>
                      <?php if (!empty($log['description'])): ?>
                        <div class="action-desc"><?= clean(mb_substr($log['description'], 0, 80)) ?><?= mb_strlen($log['description'] ?? '') > 80 ? '…' : '' ?></div>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td><span class="ip-cell"><?= clean($log['ip_address'] ?? '—') ?></span></td>
                  <td>
                    <div class="ts-date"><?= date('M j, Y', strtotime($log['created_at'])) ?></div>
                    <div class="ts-time"><?= date('g:i:s A', strtotime($log['created_at'])) ?></div>
                  </td>
                </tr>
                <!-- Expanded detail row -->
                <tr class="log-detail" id="detail-<?= $i ?>">
                  <td colspan="7">
                    <div class="detail-inner">
                      <div class="detail-grid">
                        <div class="detail-item">
                          <div class="dl">Log ID</div>
                          <div class="dv">#<?= $log['id'] ?></div>
                        </div>
                        <div class="detail-item">
                          <div class="dl">Log Type</div>
                          <div class="dv"><?= clean(ucfirst($log['log_type'] ?? '—')) ?></div>
                        </div>
                        <div class="detail-item">
                          <div class="dl">Severity</div>
                          <div class="dv"><?= clean(ucfirst($log['level'] ?? '—')) ?></div>
                        </div>
                        <div class="detail-item">
                          <div class="dl">Actor</div>
                          <div class="dv"><?= clean($log['actor_name'] ?? 'System') ?></div>
                        </div>
                        <div class="detail-item">
                          <div class="dl">Role</div>
                          <div class="dv"><?= clean(ucfirst($log['actor_role'] ?? '—')) ?></div>
                        </div>
                        <div class="detail-item">
                          <div class="dl">IP Address</div>
                          <div class="dv" style="font-family:var(--mono);font-size:12px"><?= clean($log['ip_address'] ?? '—') ?></div>
                        </div>
                        <?php if (!empty($log['user_agent'])): ?>
                          <div class="detail-item" style="grid-column:span 3">
                            <div class="dl">User Agent</div>
                            <div class="dv" style="font-family:var(--mono);font-size:11.5px;word-break:break-all"><?= clean($log['user_agent']) ?></div>
                          </div>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($log['description'])): ?>
                        <div class="detail-full">
                          <div class="dl">Full Description</div>
                          <div class="dv"><?= clean($log['description']) ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($log['metadata'])): ?>
                        <div class="detail-full">
                          <div class="dl">Metadata</div>
                          <div class="dv"><?= clean($log['metadata']) ?></div>
                        </div>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
        <div class="pagination">
          <span class="page-info">
            Page <?= $page ?> of <?= $total_pages ?>
          </span>
          <div class="page-links">
            <?php
              $base = http_build_query(array_merge($_GET, ['page' => 1]));
              $prev_q = http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)]));
              $next_q = http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)]));
              $last_q = http_build_query(array_merge($_GET, ['page' => $total_pages]));
            ?>
            <a class="page-link wide <?= $page === 1 ? 'disabled' : '' ?>" href="logs.php?<?= $base ?>">«</a>
            <a class="page-link wide <?= $page === 1 ? 'disabled' : '' ?>" href="logs.php?<?= $prev_q ?>">‹</a>
            <?php
              $start = max(1, $page - 2);
              $end   = min($total_pages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
                $pq = http_build_query(array_merge($_GET, ['page' => $p]));
            ?>
              <a class="page-link <?= $p === $page ? 'active' : '' ?>" href="logs.php?<?= $pq ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-link wide <?= $page === $total_pages ? 'disabled' : '' ?>" href="logs.php?<?= $next_q ?>">›</a>
            <a class="page-link wide <?= $page === $total_pages ? 'disabled' : '' ?>" href="logs.php?<?= $last_q ?>">»</a>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- /filter-card -->

  </main>
</div>

<script>
function toggleDetail(i) {
  const row    = document.getElementById('row-' + i);
  const detail = document.getElementById('detail-' + i);
  const isOpen = detail.classList.contains('open');
  detail.classList.toggle('open', !isOpen);
  row.classList.toggle('expanded', !isOpen);
}
</script>

</body>
</html>