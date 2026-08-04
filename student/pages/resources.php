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
        'article' => 'file-text',
        'video'   => 'video',
        'tip'     => 'lightbulb',
        'hotline' => 'phone',
        default   => 'link',
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

$mc_title = 'Resource Library — CTU MindCare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    <!-- Page header + search -->
    <div class="page-header mc-reveal">
      <div>
        <div class="page-title">Resource Library</div>
        <div class="page-sub">Articles, videos, tips, and hotlines curated by your wellness team.</div>
      </div>
      <form class="search-form" method="GET" action="resources.php">
        <?php if ($filter_type !== 'all'):     ?><input type="hidden" name="type"     value="<?= clean($filter_type) ?>"><?php endif; ?>
        <?php if ($filter_category !== 'all'): ?><input type="hidden" name="category" value="<?= clean($filter_category) ?>"><?php endif; ?>
        <div class="search-wrap">
          <i data-lucide="search" class="search-icon"></i>
          <input class="search-input" type="text" name="q"
                 placeholder="Search resources…"
                 value="<?= clean($search) ?>" autocomplete="off">
        </div>
        <button class="search-btn" type="submit"><i data-lucide="search" style="width:13px;height:13px"></i> Search</button>
        <?php if ($search || $filter_type !== 'all' || $filter_category !== 'all'): ?>
          <a class="clear-link" href="resources.php"><i data-lucide="x" style="width:12px;height:12px"></i> Clear</a>
        <?php endif; ?>
      </form>
    </div>

    <!-- Hotlines (always visible unless filtered away) -->
    <?php if ($hotlines && $filter_type === 'all' && !$search): ?>
    <div class="hotlines-section mc-reveal">
      <div class="section-title">
        Emergency &amp; Crisis Hotlines
        <span style="font-size:10.5px;font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-3)">— always available</span>
      </div>
      <div class="hotlines-grid">
        <?php foreach ($hotlines as $h): ?>
          <div class="hotline-card">
            <div class="hotline-icon"><i data-lucide="phone"></i></div>
            <div style="flex:1">
              <div class="hotline-name"><?= clean($h['title']) ?></div>
              <?php if ($h['description']): ?>
                <div class="hotline-desc"><?= clean(truncate($h['description'], 80)) ?></div>
              <?php endif; ?>
              <?php if ($h['url']): ?>
                <a class="hotline-link" href="<?= clean($h['url']) ?>" target="_blank" rel="noopener">
                  Call / Visit <i data-lucide="arrow-right" style="width:12px;height:12px"></i>
                </a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <!-- Always-present local tip -->
        <div class="hotline-card crisis">
          <div class="hotline-icon"><i data-lucide="siren"></i></div>
          <div style="flex:1">
            <div class="hotline-name">CTU MindCare Crisis Help</div>
            <div class="hotline-desc">Reach out to your on-campus wellness team directly.</div>
            <a class="hotline-link" href="crisis.php">Open Crisis Help <i data-lucide="arrow-right" style="width:12px;height:12px"></i></a>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active search notice -->
    <?php if ($search): ?>
      <div class="search-active-banner mc-reveal">
        <i data-lucide="search"></i>
        <span>Showing results for "<strong><?= clean($search) ?></strong>"</span>
        <a href="resources.php?<?= $filter_type !== 'all' ? 'type='.clean($filter_type) : '' ?><?= $filter_category !== 'all' ? '&category='.clean($filter_category) : '' ?>"
           style="margin-left:auto;font-size:11px;color:var(--purple-dark);font-weight:500">
          Clear search
        </a>
      </div>
    <?php endif; ?>

    <!-- Filter row: type -->
    <div class="filter-row mc-reveal">
      <span class="filter-label">Type</span>
      <?php
      $type_labels = ['all'=>'All','article'=>'Articles','video'=>'Videos','tip'=>'Tips','hotline'=>'Hotlines'];
      $type_icons  = ['all'=>'library','article'=>'file-text','video'=>'video','tip'=>'lightbulb','hotline'=>'phone'];
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
          <i data-lucide="<?= $type_icons[$t] ?>" style="width:12px;height:12px"></i> <?= $label ?>
          <span class="chip-num"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>

      <div class="filter-sep"></div>
      <span class="filter-label">Topic</span>

      <?php
      $cat_labels = ['all'=>'All','stress'=>'Stress','anxiety'=>'Anxiety','depression'=>'Depression','general'=>'General','crisis'=>'Crisis'];
      $cat_icons  = ['all'=>'layers','stress'=>'zap','anxiety'=>'heart-crack','depression'=>'cloud-rain','general'=>'sparkles','crisis'=>'siren'];
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
          <i data-lucide="<?= $cat_icons[$cat] ?>" style="width:12px;height:12px"></i> <?= $clabel ?>
          <span class="chip-num"><?= $cnt ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Results count -->
    <div class="results-count mc-reveal">
      <strong><?= count($resources) ?></strong>
      <?= count($resources) === 1 ? 'resource' : 'resources' ?>
      <?php if ($filter_type !== 'all' || $filter_category !== 'all' || $search): ?>
        found
        <?php if ($filter_type !== 'all'): ?> · <?= ucfirst($filter_type) ?><?php endif; ?>
        <?php if ($filter_category !== 'all'): ?> · <?= ucfirst($filter_category) ?><?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Resource grid -->
    <?php if ($resources): ?>
      <div class="resource-grid">
        <?php foreach ($resources as $r):
          $tc = typeColor($r['type']);
          $cc = catColor($r['category']);
          $link = $r['url'] ?: ($r['file_path'] ? '../../' . ltrim($r['file_path'], '/') : null);
        ?>
          <div class="res-card mc-reveal">
            <div class="res-card-top">
              <div class="res-type-row">
                <span class="res-type-badge"
                      style="background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;border-color:<?= $tc['border'] ?>">
                  <i data-lucide="<?= typeIcon($r['type']) ?>" style="width:11px;height:11px"></i> <?= ucfirst($r['type']) ?>
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
                  <?php if ($r['type'] === 'video'):   echo '<i data-lucide="play"></i> Watch'; ?>
                  <?php elseif ($r['type'] === 'article'): echo '<i data-lucide="book-open"></i> Read'; ?>
                  <?php elseif ($r['type'] === 'hotline'): echo '<i data-lucide="phone"></i> Contact'; ?>
                  <?php else: echo '<i data-lucide="lightbulb"></i> View'; ?>
                  <?php endif; ?>
                </a>
              <?php else: ?>
                <span class="res-link no-link"><i data-lucide="link-2-off"></i> No link</span>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php else: ?>
      <!-- Empty state -->
      <div class="empty-state mc-reveal">
        <?php if ($search): ?>
          <div class="es-icon"><i data-lucide="search"></i></div>
          <div class="es-title">No results found</div>
          <div class="es-sub">
            No resources match "<?= clean($search) ?>".
            Try a different keyword or clear the filters.
          </div>
          <a class="btn btn-primary" href="resources.php"><i data-lucide="library"></i> Browse all resources</a>
        <?php elseif ($filter_type !== 'all' || $filter_category !== 'all'): ?>
          <div class="es-icon"><i data-lucide="folder-open"></i></div>
          <div class="es-title">Nothing here yet</div>
          <div class="es-sub">
            No <?= $filter_type !== 'all' ? strtolower($filter_type) . 's' : 'resources' ?>
            <?= $filter_category !== 'all' ? 'in the ' . strtolower($filter_category) . ' category' : '' ?>
            have been added yet.
          </div>
          <a class="btn btn-primary" href="resources.php"><i data-lucide="layers"></i> View all resources</a>
        <?php else: ?>
          <div class="es-icon"><i data-lucide="book-open"></i></div>
          <div class="es-title">No resources yet</div>
          <div class="es-sub">
            Your wellness team hasn't added any resources yet.
            Check back soon, or reach out to your counselor directly.
          </div>
          <a class="btn btn-primary" href="counselor-chat.php"><i data-lucide="message-circle"></i> Chat with a counselor</a>
          <div class="add-tip" style="max-width:480px;margin:24px auto 0;text-align:left">
            <i data-lucide="lightbulb" style="width:18px;height:18px;flex-shrink:0;color:var(--green-dark)"></i>
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


<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
