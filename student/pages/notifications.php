<?php
// ============================================================
//  CTU MindCare — Notifications
//  File: student/pages/notifications.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── MARK ALL READ ─────────────────────────────────────────────
if (isset($_GET['mark_all_read'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
       ->execute([$user['id']]);
    setFlash('success', 'All notifications marked as read.');
    header('Location: notifications.php');
    exit;
}

// ── MARK ONE READ & redirect ──────────────────────────────────
if (isset($_GET['read']) && ($nid = (int)$_GET['read'])) {
    $db->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")
       ->execute([$nid, $user['id']]);
    // Redirect to relevant page based on type
    $stmt = $db->prepare("SELECT type FROM notifications WHERE id = ? AND user_id = ?");
    $stmt->execute([$nid, $user['id']]);
    $n = $stmt->fetch();
    $redirect = match($n['type'] ?? '') {
        'appointment'    => 'appointments.php',
        'assessment_due' => 'assessment.php',
        'crisis'         => 'crisis.php',
        'mood_reminder'  => 'mood-checkin.php',
        default          => 'notifications.php',
    };
    header('Location: ' . $redirect);
    exit;
}

// ── DELETE ONE ────────────────────────────────────────────────
if (isset($_GET['delete']) && ($nid = (int)$_GET['delete'])) {
    $db->prepare("DELETE FROM notifications WHERE id = ? AND user_id = ?")
       ->execute([$nid, $user['id']]);
    header('Location: notifications.php');
    exit;
}

// ── DELETE ALL READ ───────────────────────────────────────────
if (isset($_GET['clear_read'])) {
    $db->prepare("DELETE FROM notifications WHERE user_id = ? AND is_read = 1")
       ->execute([$user['id']]);
    setFlash('success', 'Read notifications cleared.');
    header('Location: notifications.php');
    exit;
}

// ── Filter ────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'all';  // all | unread | appointment | assessment_due | crisis | mood_reminder | general
$valid_filters = ['all','unread','appointment','assessment_due','crisis','mood_reminder','general'];
if (!in_array($filter, $valid_filters)) $filter = 'all';

$where  = ['user_id = ?'];
$params = [$user['id']];

if ($filter === 'unread') {
    $where[]  = 'is_read = 0';
} elseif ($filter !== 'all') {
    $where[]  = 'type = ?';
    $params[] = $filter;
}

$stmt = $db->prepare("
    SELECT id, type, message, is_read, created_at
    FROM notifications
    WHERE " . implode(' AND ', $where) . "
    ORDER BY created_at DESC
    LIMIT 100
");
$stmt->execute($params);
$notifications = $stmt->fetchAll();

// ── Counts ────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        COUNT(*)                                                           AS total,
        SUM(is_read = 0)                                                   AS unread,
        SUM(type = 'appointment')                                          AS appt,
        SUM(type = 'assessment_due')                                       AS assess,
        SUM(type = 'crisis')                                               AS crisis,
        SUM(type = 'mood_reminder')                                        AS mood,
        SUM(type = 'general')                                              AS general
    FROM notifications
    WHERE user_id = ?
");
$stmt->execute([$user['id']]);
$counts = $stmt->fetch();

// ── Helpers ──────────────────────────────────────────────────
function notifIcon(string $t): string {
    return match($t) {
        'appointment'    => 'calendar',
        'assessment_due' => 'clipboard-list',
        'crisis'         => 'alert-triangle',
        'mood_reminder'  => 'smile',
        'general'        => 'message-circle',
        default          => 'bell',
    };
}
function notifColor(string $t): array {
    return match($t) {
        'appointment'    => ['cls'=>'t-appointment','dot'=>'var(--teal)'],
        'assessment_due' => ['cls'=>'t-assessment_due','dot'=>'var(--purple)'],
        'crisis'         => ['cls'=>'t-crisis','dot'=>'var(--red)'],
        'mood_reminder'  => ['cls'=>'t-mood_reminder','dot'=>'var(--amber)'],
        'general'        => ['cls'=>'t-general','dot'=>'var(--text-3)'],
        default          => ['cls'=>'t-general','dot'=>'var(--text-3)'],
    };
}
function notifLink(string $t, int $id): string {
    return 'notifications.php?read=' . $id;
}
function notifTypeLabel(string $t): string {
    return match($t) {
        'appointment'    => 'Appointment',
        'assessment_due' => 'Assessment',
        'crisis'         => 'Crisis',
        'mood_reminder'  => 'Mood reminder',
        'general'        => 'General',
        default          => 'Notification',
    };
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff/60) . 'm ago';
    if ($diff < 86400)   return floor($diff/3600) . 'h ago';
    if ($diff < 604800)  return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($dt));
}
function fullDate(string $dt): string {
    return date('F j, Y \a\t g:i A', strtotime($dt));
}

$flash = getFlash();
$mc_title = 'Notifications';
require_once '../../includes/assets.php';
?>
<body>
<!-- Topbar -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- Sidebar -->
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- Main -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>" data-auto-dismiss>
        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0"></i>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header mc-reveal">
      <div>
        <div class="page-title">
          Notifications
          <?php if ((int)($counts['unread'] ?? 0) > 0): ?>
            <span class="unread-badge"><?= (int)$counts['unread'] ?> new</span>
          <?php endif; ?>
        </div>
        <div class="page-sub">Stay up to date with your wellness activity.</div>
      </div>
      <div class="header-actions">
        <?php if ((int)($counts['unread'] ?? 0) > 0): ?>
          <a class="btn-mark-all" href="notifications.php?mark_all_read=1">
            <i data-lucide="check-check" style="width:14px;height:14px"></i> Mark all as read
          </a>
        <?php endif; ?>
        <?php if ((int)($counts['total'] ?? 0) > (int)($counts['unread'] ?? 0)): ?>
          <a class="btn-clear-read" href="notifications.php?clear_read=1"
             onclick="return confirm('Delete all read notifications?')">
            <i data-lucide="trash-2" style="width:14px;height:14px"></i> Clear read
          </a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Stats row -->
    <div class="stats-row mc-reveal" data-mc-delay="1">
      <div class="stat-card c-blue">
        <div class="sc-num"><?= (int)($counts['total'] ?? 0) ?></div>
        <div class="sc-label">Total</div>
      </div>
      <div class="stat-card c-red">
        <div class="sc-num"><?= (int)($counts['unread'] ?? 0) ?></div>
        <div class="sc-label">Unread</div>
      </div>
      <div class="stat-card c-teal">
        <div class="sc-num"><?= (int)($counts['appt'] ?? 0) ?></div>
        <div class="sc-label">Appointments</div>
      </div>
      <div class="stat-card c-purple">
        <div class="sc-num"><?= (int)($counts['assess'] ?? 0) ?></div>
        <div class="sc-label">Assessments</div>
      </div>
      <div class="stat-card c-amber">
        <div class="sc-num"><?= (int)($counts['crisis'] ?? 0) ?></div>
        <div class="sc-label">Crisis alerts</div>
      </div>
    </div>

    <!-- Filter chips -->
    <div class="filter-row mc-reveal" data-mc-delay="2">
      <?php
      $chips = [
          'all'            => ['label' => 'All',          'icon' => 'bell',        'count' => $counts['total']   ?? 0],
          'unread'         => ['label' => 'Unread',       'icon' => 'circle',      'count' => $counts['unread']  ?? 0],
          'appointment'    => ['label' => 'Appointments', 'icon' => 'calendar',    'count' => $counts['appt']    ?? 0],
          'assessment_due' => ['label' => 'Assessments',  'icon' => 'clipboard-list', 'count' => $counts['assess']  ?? 0],
          'mood_reminder'  => ['label' => 'Mood',         'icon' => 'smile',       'count' => $counts['mood']    ?? 0],
          'crisis'         => ['label' => 'Crisis',       'icon' => 'alert-triangle', 'count' => $counts['crisis']  ?? 0],
          'general'        => ['label' => 'General',      'icon' => 'message-circle', 'count' => $counts['general'] ?? 0],
      ];
      foreach ($chips as $slug => $chip):
        $active = ($filter === $slug);
      ?>
        <a class="filter-chip <?= $active ? 'active' : '' ?>"
           href="notifications.php?filter=<?= $slug ?>">
          <i data-lucide="<?= $chip['icon'] ?>" style="width:13px;height:13px"></i> <?= $chip['label'] ?>
          <span class="chip-num"><?= (int)$chip['count'] ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Notifications list -->
    <?php if (empty($notifications)): ?>
      <div class="empty-state mc-reveal" data-mc-delay="2">
        <div class="es-icon">
          <?= $filter === 'unread' ? '✅' : '🔔' ?>
        </div>
        <div class="es-title">
          <?= $filter === 'unread' ? 'All caught up!' : 'No notifications' ?>
        </div>
        <div class="es-sub">
          <?php if ($filter === 'unread'): ?>
            You have no unread notifications. Great job staying on top of things!
          <?php elseif ($filter !== 'all'): ?>
            No <?= notifTypeLabel($filter) ?> notifications yet.
            <a href="notifications.php" class="mc-link">View all notifications</a>
          <?php else: ?>
            You don't have any notifications yet. They'll appear here when there's something to know about.
          <?php endif; ?>
        </div>
      </div>

    <?php else: ?>

      <div class="notif-list mc-reveal" data-mc-delay="2">
        <?php
        $prev_date = '';
        foreach ($notifications as $n):
          $n_date = date('Y-m-d', strtotime($n['created_at']));
          $colors = notifColor($n['type']);
          $is_unread = !(bool)$n['is_read'];

          // Date group separator
          if ($n_date !== $prev_date):
            $today     = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $dlabel = $n_date === $today     ? 'Today'
                    : ($n_date === $yesterday ? 'Yesterday'
                    : date('F j, Y', strtotime($n_date)));
        ?>
          <div class="date-group-label"><?= $dlabel ?></div>
        <?php
            $prev_date = $n_date;
          endif;

          // Action URL based on type
          $action_url = match($n['type']) {
              'appointment'    => 'appointments.php',
              'assessment_due' => 'assessment.php',
              'crisis'         => 'crisis.php',
              'mood_reminder'  => 'mood-checkin.php',
              default          => null,
          };
          $action_label = match($n['type']) {
              'appointment'    => 'View appointments',
              'assessment_due' => 'Take assessment',
              'crisis'         => 'Crisis page',
              'mood_reminder'  => 'Check in now',
              default          => null,
          };
          $action_icon = match($n['type']) {
              'appointment'    => 'calendar',
              'assessment_due' => 'clipboard-list',
              'crisis'         => 'alert-triangle',
              'mood_reminder'  => 'smile',
              default          => null,
          };
        ?>
          <div class="notif-item <?= $is_unread ? 'unread' : '' ?> <?= $n['type'] ?>"
               onclick="markRead(<?= $n['id'] ?>)">

            <!-- Unread indicator dot -->
            <div class="ni-dot" style="background:<?= $is_unread ? $colors['dot'] : 'transparent' ?>"></div>

            <!-- Icon -->
            <div class="ni-icon <?= $colors['cls'] ?>">
              <i data-lucide="<?= notifIcon($n['type']) ?>"></i>
            </div>

            <!-- Body -->
            <div class="ni-body">
              <div class="ni-top">
                <span class="ni-type <?= $colors['cls'] ?>">
                  <?= notifTypeLabel($n['type']) ?>
                </span>
                <span class="ni-time" title="<?= fullDate($n['created_at']) ?>">
                  <?= timeAgo($n['created_at']) ?>
                </span>
              </div>
              <div class="ni-msg <?= $is_unread ? 'unread' : '' ?>">
                <?= clean($n['message']) ?>
              </div>
              <div class="ni-actions" onclick="event.stopPropagation()">
                <?php if ($is_unread): ?>
                  <a class="ni-btn" href="notifications.php?read=<?= $n['id'] ?>">
                    <i data-lucide="check" style="width:11px;height:11px"></i> Mark read
                  </a>
                <?php endif; ?>
                <?php if ($action_url && $action_label): ?>
                  <a class="ni-btn primary"
                     href="notifications.php?read=<?= $n['id'] ?>">
                    <i data-lucide="<?= $action_icon ?>" style="width:11px;height:11px"></i> <?= $action_label ?>
                  </a>
                <?php endif; ?>
                <a class="ni-btn danger"
                   href="notifications.php?delete=<?= $n['id'] ?>"
                   onclick="return confirm('Delete this notification?')">
                  <i data-lucide="trash-2" style="width:11px;height:11px"></i>
                </a>
              </div>
            </div>

          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>

  </main>
</div>

<script>
// Mark read on click of the card itself, then redirect to action URL if available
const actionUrls = {
  <?php foreach ($notifications as $n):
    $url = match($n['type']) {
        'appointment'    => 'appointments.php',
        'assessment_due' => 'assessment.php',
        'crisis'         => 'crisis.php',
        'mood_reminder'  => 'mood-checkin.php',
        default          => null,
    };
    if ($url): ?>
  <?= (int)$n['id'] ?>: '<?= $url ?>',
  <?php endif; endforeach; ?>
};

function markRead(id) {
  // If already read, and there's an action URL, go there directly
  const target = actionUrls[id];
  if (target) {
    window.location.href = 'notifications.php?read=' + id;
  } else {
    // Just mark read silently via fetch then update UI
    fetch('notifications.php?read=' + id).catch(() => {});
    const item = document.querySelector(`.notif-item[onclick="markRead(${id})"]`);
    if (item) {
      item.classList.remove('unread');
      const dot = item.querySelector('.ni-dot');
      if (dot) dot.style.background = 'transparent';
      const msg = item.querySelector('.ni-msg');
      if (msg) msg.classList.remove('unread');
      // Remove mark-read button
      const markBtn = item.querySelector('.ni-btn:not(.primary):not(.danger)');
      if (markBtn) markBtn.remove();
    }
  }
}

// Auto-refresh unread badge in topbar every 30 seconds
setInterval(async () => {
  try {
    const res  = await fetch('notifications.php?filter=unread&_ajax=1');
    // We just use this to keep session alive; full refresh happens on nav
  } catch(e) {}
}, 30000);
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
