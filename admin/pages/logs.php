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
        'auth'      => ['label' => 'Auth',      'class' => 'type-auth'],
        'user'      => ['label' => 'User',      'class' => 'type-user'],
        'crisis'    => ['label' => 'Crisis',    'class' => 'type-crisis'],
        'session'   => ['label' => 'Session',   'class' => 'type-session'],
        'system'    => ['label' => 'System',    'class' => 'type-system'],
        'data'      => ['label' => 'Data',      'class' => 'type-data'],
        default     => ['label' => ucfirst($type), 'class' => 'type-default'],
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

$mc_title        = 'System Logs — CTU MindCare';
$active_page     = 'logs';
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

  <!-- ── Main ── -->
  <main class="main">
  <div class="container">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>" data-auto-dismiss>
        <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>"></i>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header mc-reveal">
      <div>
        <div class="greeting-label">Audit Trail</div>
        <div class="greeting-title">System Logs</div>
        <div class="greeting-sub">Track all system activity, authentication events, and user actions</div>
      </div>
      <div class="header-actions">
        <?php
          $export_params = http_build_query(array_merge($_GET, ['export' => 'csv']));
        ?>
        <a class="btn btn-outline" href="logs.php?<?= $export_params ?>">
          <i data-lucide="download"></i> Export CSV
        </a>
      </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
      <div class="stat-card c-teal mc-reveal" data-mc-delay="1">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="file-text"></i></div>
          <span class="stat-trend trend-up">Total</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($stats['total'] ?? 0) ?></div>
          <div class="stat-label">Log Entries</div>
        </div>
      </div>
      <div class="stat-card c-red mc-reveal" data-mc-delay="2">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="circle-alert"></i></div>
          <span class="stat-trend trend-warn">Errors</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($stats['errors'] ?? 0) ?></div>
          <div class="stat-label">Error Level</div>
        </div>
      </div>
      <div class="stat-card c-amber mc-reveal" data-mc-delay="3">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="triangle-alert"></i></div>
          <span class="stat-trend trend-warn">Warnings</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($stats['warnings'] ?? 0) ?></div>
          <div class="stat-label">Warning Level</div>
        </div>
      </div>
      <div class="stat-card c-blue mc-reveal" data-mc-delay="4">
        <div class="stat-top">
          <div class="stat-icon-wrap"><i data-lucide="clock"></i></div>
          <span class="stat-trend trend-up">Recent</span>
        </div>
        <div>
          <div class="stat-value"><?= number_format($stats['today'] ?? 0) ?></div>
          <div class="stat-label">Last 24 Hours</div>
        </div>
      </div>
    </div>

    <!-- Filter Card -->
    <div class="card mc-reveal" data-mc-delay="2">
      <form method="GET">
        <div class="lg-filter">
          <!-- Type filter -->
          <div class="filter-group">
            <span class="filter-label">Type</span>
            <select class="form-select" name="type" style="padding:7px 12px;font-size:12px">
              <option value="all"     <?= $filter_type==='all'     ? 'selected':'' ?>>All types</option>
              <option value="auth"    <?= $filter_type==='auth'    ? 'selected':'' ?>>Auth</option>
              <option value="user"    <?= $filter_type==='user'    ? 'selected':'' ?>>User</option>
              <option value="crisis"  <?= $filter_type==='crisis'  ? 'selected':'' ?>>Crisis</option>
              <option value="session" <?= $filter_type==='session' ? 'selected':'' ?>>Session</option>
              <option value="system"  <?= $filter_type==='system'  ? 'selected':'' ?>>System</option>
              <option value="data"    <?= $filter_type==='data'    ? 'selected':'' ?>>Data</option>
            </select>
          </div>

          <div class="filter-divider"></div>

          <!-- Level filter -->
          <div class="filter-group">
            <span class="filter-label">Level</span>
            <select class="form-select" name="level" style="padding:7px 12px;font-size:12px">
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
              <span class="date-sep">to</span>
              <input class="date-input" type="date" name="to" value="<?= clean($date_to) ?>" title="To">
            </div>
          </div>

          <div class="filter-divider"></div>

          <!-- Search -->
          <div class="search-wrap">
            <input class="search-input" type="text" name="q" value="<?= clean($search) ?>" placeholder="Search action, description, IP…">
            <button type="submit" class="btn btn-primary"><i data-lucide="filter" style="width:13px;height:13px"></i> Filter</button>
            <?php if ($filter_type !== 'all' || $filter_level !== 'all' || $search || $date_from || $date_to): ?>
              <a class="btn btn-outline" href="logs.php"><i data-lucide="x" style="width:13px;height:13px"></i> Clear</a>
            <?php endif; ?>
          </div>
        </div>
      </form>

      <!-- Result meta -->
      <div class="mc-toolbar" style="border-bottom:1px solid var(--border)">
        <span class="result-count" style="font-size:12px;color:var(--text-3)">
          Showing <strong style="color:var(--text-2)"><?= number_format(min($per_page, max(0, $total_logs - $offset))) ?></strong>
          of <strong style="color:var(--text-2)"><?= number_format($total_logs) ?></strong> entries
          <?php if ($search || $filter_type !== 'all' || $filter_level !== 'all'): ?>
            <span style="color:var(--primary);font-weight:600"> (filtered)</span>
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
                  <div class="empty-icon"><i data-lucide="search-x"></i></div>
                  <div class="empty-text">No log entries match your filters.</div>
                </div>
              </td></tr>
            <?php else: ?>
              <?php foreach ($logs as $i => $log):
                $typeInfo = logTypeLabel($log['log_type'] ?? 'default');
                $lvlClass = levelClass($log['level'] ?? 'info');
                $isSystem = ($log['actor_role'] ?? '') === 'system' || empty($log['actor_name']);
                $initials = strtoupper(
                  substr(explode(' ', trim($log['actor_name'] ?? 'S'))[0], 0, 1) .
                  substr(explode(' ', trim($log['actor_name'] ?? 'S '))[1] ?? 'Y', 0, 1)
                );
              ?>
                <tr class="lg-row" onclick="toggleDetail(<?= $i ?>)" id="row-<?= $i ?>">
                  <td><span class="lg-arrow"><i data-lucide="chevron-right" style="width:13px;height:13px"></i></span></td>
                  <td>
                    <span class="type-badge <?= $typeInfo['class'] ?>">
                      <?= $typeInfo['label'] ?>
                    </span>
                  </td>
                  <td>
                    <span class="level-badge <?= $lvlClass ?>">
                      <?= ucfirst($log['level'] ?? 'info') ?>
                    </span>
                  </td>
                  <td>
                    <div class="actor-cell">
                      <div class="actor-av <?= $isSystem ? 'av-system' : 'av-green' ?>"><?= $isSystem ? '<i data-lucide="settings" style="width:12px;height:12px"></i>' : $initials ?></div>
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
                <tr class="lg-detail" id="detail-<?= $i ?>">
                  <td colspan="7">
                    <div class="lg-inner">
                      <div class="lg-grid">
                        <div>
                          <div class="lg-lbl">Log ID</div>
                          <div class="lg-val">#<?= $log['id'] ?></div>
                        </div>
                        <div>
                          <div class="lg-lbl">Log Type</div>
                          <div class="lg-val"><?= clean(ucfirst($log['log_type'] ?? '—')) ?></div>
                        </div>
                        <div>
                          <div class="lg-lbl">Severity</div>
                          <div class="lg-val"><?= clean(ucfirst($log['level'] ?? '—')) ?></div>
                        </div>
                        <div>
                          <div class="lg-lbl">Actor</div>
                          <div class="lg-val"><?= clean($log['actor_name'] ?? 'System') ?></div>
                        </div>
                        <div>
                          <div class="lg-lbl">Role</div>
                          <div class="lg-val"><?= clean(ucfirst($log['actor_role'] ?? '—')) ?></div>
                        </div>
                        <div>
                          <div class="lg-lbl">IP Address</div>
                          <div class="lg-val" style="font-family:ui-monospace,Consolas,monospace;font-size:12px"><?= clean($log['ip_address'] ?? '—') ?></div>
                        </div>
                        <?php if (!empty($log['user_agent'])): ?>
                          <div style="grid-column:span 3">
                            <div class="lg-lbl">User Agent</div>
                            <div class="lg-val" style="font-family:ui-monospace,Consolas,monospace;font-size:11.5px"><?= clean($log['user_agent']) ?></div>
                          </div>
                        <?php endif; ?>
                      </div>
                      <?php if (!empty($log['description'])): ?>
                        <div class="lg-full">
                          <div class="lg-lbl">Full Description</div>
                          <div class="lg-val"><?= clean($log['description']) ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($log['metadata'])): ?>
                        <div class="lg-full">
                          <div class="lg-lbl">Metadata</div>
                          <div class="lg-val"><?= clean($log['metadata']) ?></div>
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
        <div class="mc-toolbar" style="border-bottom:none;border-top:1px solid var(--border)">
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
            <a class="page-link <?= $page === 1 ? 'disabled' : '' ?>" href="logs.php?<?= $base ?>" aria-label="First">«</a>
            <a class="page-link <?= $page === 1 ? 'disabled' : '' ?>" href="logs.php?<?= $prev_q ?>" aria-label="Previous">‹</a>
            <?php
              $start = max(1, $page - 2);
              $end   = min($total_pages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
                $pq = http_build_query(array_merge($_GET, ['page' => $p]));
            ?>
              <a class="page-link <?= $p === $page ? 'active' : '' ?>" href="logs.php?<?= $pq ?>"><?= $p ?></a>
            <?php endfor; ?>
            <a class="page-link <?= $page === $total_pages ? 'disabled' : '' ?>" href="logs.php?<?= $next_q ?>" aria-label="Next">›</a>
            <a class="page-link <?= $page === $total_pages ? 'disabled' : '' ?>" href="logs.php?<?= $last_q ?>" aria-label="Last">»</a>
          </div>
        </div>
      <?php endif; ?>

    </div><!-- /card -->

  </div>
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
