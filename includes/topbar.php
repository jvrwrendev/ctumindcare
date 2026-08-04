<?php
/**
 * CTU MindCare — Shared Topbar
 * ------------------------------------------------------------------
 * Single reusable topbar used by every module page (admin, student,
 * wellness). Renders the hamburger, brand mark, optional role badge,
 * dark-mode toggle, notification bells, user chip and sign-out link.
 * Styled entirely by assets/css/mindcare.css.
 *
 * Optional page-level variables (set BEFORE including this file):
 *   $mc_topbar_badge   string   role badge text (e.g. "Wellness Counselor")
 *   $mc_topbar_bells   array    [['url'=>, 'title'=>, 'count'=>], ...]
 *                               overrides the default student notification bell
 *   $mc_topbar_extra   string   raw HTML injected before the user chip
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$mc_user = $user ?? currentUser();
if (!$mc_user) {
    $mc_user = [
        'id'         => $_SESSION['user_id'] ?? null,
        'first_name' => $_SESSION['user_name'] ?? 'User',
        'last_name'  => '',
        'email'      => '',
        'role'       => $_SESSION['user_role'] ?? 'student',
    ];
}

$mc_role_t = $mc_user['role'] ?? $_SESSION['user_role'] ?? 'student';
$mc_initials_t = strtoupper(
    substr($mc_user['first_name'] ?? 'U', 0, 1) .
    substr($mc_user['last_name'] ?? '', 0, 1)
);
$mc_name_t = trim(($mc_user['first_name'] ?? '') . ' ' . ($mc_user['last_name'] ?? ''));

/* ── Asset paths (works at any depth: /ctumindcare/.../pages/) ─── */
$mc_logout_t = SITE_URL . '/auth/logout.php';

/* ── Notification bells ─────────────────────────────────────────── */
$mc_bells = $mc_topbar_bells ?? null;
if ($mc_bells === null && $mc_role_t === 'student' && !empty($mc_user['id'])) {
    $mc_bells = [];
    try {
        $mc_db = getDB();
        $mc_st = $mc_db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $mc_st->execute([$mc_user['id']]);
        $mc_cnt = (int)$mc_st->fetchColumn();
        if ($mc_cnt > 0) {
            $mc_bells[] = [
                'url'   => 'notifications.php',
                'title' => $mc_cnt . ' unread notification' . ($mc_cnt > 1 ? 's' : ''),
                'count' => $mc_cnt,
            ];
        }
    } catch (Throwable $e) { /* non-fatal */ }
}
$mc_bells = $mc_bells ?? [];
?>
<header class="topbar">
  <button class="mc-hamburger" id="mcHamburger" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
  <div class="topbar-brand">
    <div class="topbar-mark">M</div>
    <div>
      <div class="topbar-name">CTU MindCare</div>
      <div class="topbar-school">CTU Consolacion</div>
    </div>
  </div>
  <?php if (!empty($mc_topbar_badge)): ?>
    <span class="topbar-badge"><?= clean($mc_topbar_badge) ?></span>
  <?php endif; ?>
  <div class="topbar-right">
    <button type="button" class="mc-theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
      <i data-lucide="moon" class="icon-moon"></i>
      <i data-lucide="sun" class="icon-sun"></i>
    </button>
    <?php foreach ($mc_bells as $mc_b): ?>
      <?php if (!empty($mc_b['count'])): ?>
        <a class="notif-btn" href="<?= clean($mc_b['url']) ?>" title="<?= clean($mc_b['title']) ?>">
          <i data-lucide="bell"></i>
          <span class="notif-badge"><?= (int)$mc_b['count'] ?></span>
        </a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?= $mc_topbar_extra ?? '' ?>
    <?php if ($mc_role_t === 'student'): ?>
      <a class="user-chip" href="profile.php" title="Edit profile">
        <div class="user-av"><?= clean($mc_initials_t) ?></div>
        <span class="user-name"><?= clean($mc_name_t) ?></span>
      </a>
    <?php else: ?>
      <div class="user-chip">
        <div class="user-av"><?= clean($mc_initials_t) ?></div>
        <span class="user-name"><?= clean($mc_name_t) ?></span>
      </div>
    <?php endif; ?>
    <a class="logout-btn" href="<?= $mc_logout_t ?>" title="Sign out"><i data-lucide="log-out"></i></a>
  </div>
</header>
