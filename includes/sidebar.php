<?php
/**
 * CTU MindCare — Shared Sidebar
 * ------------------------------------------------------------------
 * Single reusable sidebar component used by EVERY module page
 * (admin, student, wellness). Renders the overlay, asset links and
 * the sidebar rail with Lucide icons, role-based navigation,
 * active-page highlighting, badges and the user card.
 *
 * Optional page-level variables (set BEFORE including this file):
 *   $active_page    string   force-active menu key (auto-detected otherwise)
 *   $sidebar_badges array    ['key' => count] badge counts (only > 0 shown)
 *   $mc_topbar_h    int      topbar height in px (defaults per role)
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
        'student_id' => null,
        'email'      => '',
        'role'       => $_SESSION['user_role'] ?? 'student',
    ];
}

$mc_role = $mc_user['role'] ?? '';
if (!in_array($mc_role, ['system_admin', 'student', 'wellness_admin'], true)) {
    $mc_path = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($mc_path, '/admin/') !== false)        $mc_role = 'system_admin';
    elseif (strpos($mc_path, '/wellness/') !== false) $mc_role = 'wellness_admin';
    else                                              $mc_role = 'student';
}

/* Lucide icon names (Framer Motion / Lucide CDN) */
$mc_menus = [
    'system_admin' => [
        'sections' => [
            [
                'label' => 'Navigation',
                'items' => [
                    ['dashboard.php',                    'layout-dashboard', 'Dashboard',      'dashboard'],
                    ['users.php',                        'users',            'Manage Users',   'users'],
                    ['analytics.php',                    'chart-line',       'Analytics',      'analytics'],
                    ['announcements.php',                'megaphone',        'Announcements',  'announcements'],
                    ['logs.php',                         'file-text',        'System Logs',    'logs'],
                ],
            ],
            [
                'label' => 'Monitor',
                'items' => [
                    ['users.php?filter=crisis',          'siren',            'Crisis Alerts',  'crisis', 'danger'],
                ],
            ],
        ],
        'role_label' => 'System Administrator',
        'profile'    => null,
    ],
    'student' => [
        'sections' => [
            [
                'label' => 'My Wellness',
                'items' => [
                    ['dashboard.php',                    'layout-dashboard', 'Dashboard',       'dashboard'],
                    ['mood-checkin.php',                 'smile',            'Daily check-in',  'mood-checkin'],
                    ['assessment.php',                   'clipboard-list',   'Assessments',     'assessment'],
                    ['csuq.php',                         'list-checks',      'CSUQ survey',     'csuq'],
                    ['progress.php',                     'trending-up',      'My progress',     'progress'],
                    ['journal.php',                      'book-open',        'Wellness journal','journal'],
                ],
            ],
            [
                'label' => 'Support',
                'items' => [
                    ['appointments.php',                 'calendar',         'Book appointment','appointments'],
                    ['counselor-chat.php',               'messages-square',  'Counselor chat',  'counselor-chat'],
                    ['resources.php',                    'library',          'Resource library','resources'],
                    ['../../chatbot/index.php',          'bot',              'AI Assistant',    'chatbot'],
                ],
            ],
            [
                'label' => 'Community',
                'items' => [
                    ['forum.php',                        'messages-square',  'Peer forum',      'forum'],
                    ['peer-chat.php',                    'message-circle',   'Peer chat',       'peer-chat'],
                ],
            ],
            [
                'label' => null,
                'items' => [
                    ['crisis.php',                       'heart-pulse',      'Crisis help',     'crisis', 'danger'],
                ],
            ],
        ],
        'role_label' => 'Student',
        'profile'    => 'profile.php',
    ],
    'wellness_admin' => [
        'sections' => [
            [
                'label' => 'Workspace',
                'items' => [
                    ['dashboard.php?p=dashboard',        'layout-dashboard', 'Dashboard',       'dashboard'],
                    ['dashboard.php?p=students',         'users',            'My students',     'students'],
                    ['dashboard.php?p=appointments',     'calendar',         'Appointments',    'appointments'],
                    ['messages.php',                     'messages-square',  'Messages',        'messages', null, 'messages'],
                    ['../../chatbot/index.php',          'bot',              'AI Assistant',    'chatbot'],
                    ['ai-logs.php',                      'shield',           'AI support logs', 'ai-logs', null, 'ai-logs'],
                ],
            ],
            [
                'label' => 'Clinical',
                'items' => [
                    ['dashboard.php?p=alerts',           'siren',            'Crisis alerts',   'alerts', null, 'alerts'],
                    ['dashboard.php?p=notes',            'file-text',        'Session notes',   'notes'],
                    ['dashboard.php?p=assessments',      'clipboard-list',   'Assessments',     'assessments'],
                    ['dashboard.php?p=progress',         'chart-line',       'Student progress','progress'],
                ],
            ],
            [
                'label' => 'Community',
                'items' => [
                    ['dashboard.php?p=forum',            'flag',             'Forum moderation','forum', null, 'forum'],
                    ['dashboard.php?p=resources',        'library',          'Resource library','resources'],
                    ['dashboard.php?p=announcements',    'megaphone',        'Announcements',   'announcements'],
                ],
            ],
            [
                'label' => null,
                'items' => [
                    ['dashboard.php?p=crisis',           'life-buoy',        'Crisis protocols','crisis', 'danger'],
                ],
            ],
        ],
        'role_label' => 'Wellness Counselor',
        'profile'    => null,
    ],
];

if (!isset($mc_menus[$mc_role])) {
    return;
}
$mc_menu  = $mc_menus[$mc_role];
$mc_badges = $sidebar_badges ?? [];

/* ── Active page detection ─────────────────────────────────────────── */
$mc_active = !empty($active_page) ? $active_page : null;
if ($mc_active === null) {
    $mc_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $mc_get_p  = $_GET['p'] ?? null;

    // Pass 1: links carrying ?p= or ?filter= (exact query match)
    foreach ($mc_menu['sections'] as $mc_sec) {
        foreach ($mc_sec['items'] as $mc_item) {
            $mc_href = $mc_item[0];
            $mc_qpos = strpos($mc_href, '?');
            if ($mc_qpos === false) continue;
            $mc_file = basename(substr($mc_href, 0, $mc_qpos));
            if ($mc_file !== $mc_script) continue;
            parse_str(substr($mc_href, $mc_qpos + 1), $mc_hq);
            if (isset($mc_hq['p']) && $mc_hq['p'] === $mc_get_p) {
                $mc_active = $mc_item[3];
                break 2;
            }
            if (isset($mc_hq['filter']) && ($_GET['filter'] ?? null) === $mc_hq['filter']) {
                $mc_active = $mc_item[3];
                break 2;
            }
        }
    }
    // Pass 2: plain file-name match
    if ($mc_active === null) {
        foreach ($mc_menu['sections'] as $mc_sec) {
            foreach ($mc_sec['items'] as $mc_item) {
                $mc_href = $mc_item[0];
                $mc_qpos = strpos($mc_href, '?');
                $mc_file = basename($mc_qpos === false ? $mc_href : substr($mc_href, 0, $mc_qpos));
                if ($mc_file === $mc_script) {
                    $mc_active = $mc_item[3];
                    break 2;
                }
            }
        }
    }
}

/* ── Asset paths (works at any depth: /ctumindcare/.../pages/) ─────── */
$mc_logout = SITE_URL . '/auth/logout.php';

/* ── Topbar height per module ──────────────────────────────────────── */
$mc_topbar_h = isset($mc_topbar_h) ? (int)$mc_topbar_h : 60;

/* ── User card data ────────────────────────────────────────────────── */
$mc_name = trim(($mc_user['first_name'] ?? '') . ' ' . ($mc_user['last_name'] ?? ''));
$mc_initials = strtoupper(substr($mc_user['first_name'] ?? 'U', 0, 1) . substr($mc_user['last_name'] ?? '', 0, 1));
$mc_sub = $mc_role === 'student'
    ? clean($mc_user['student_id'] ?? 'Student')
    : $mc_menu['role_label'];
?>
<div class="mc-overlay" id="mcOverlay"></div>

<link rel="stylesheet" href="<?= SITE_URL ?>/assets/css/mindcare.css">
<script defer src="<?= SITE_URL ?>/assets/sidebar.js"></script>
<style>
  :root { --mc-topbar-h: <?= $mc_topbar_h ?>px; }
</style>

<aside class="mc-sidebar" id="mcSidebar">
  <?php foreach ($mc_menu['sections'] as $mc_sec): ?>
    <?php if (!empty($mc_sec['label'])): ?>
      <div class="mc-section">
        <span class="mc-section-label"><?= clean($mc_sec['label']) ?></span>
        <?php foreach ($mc_sec['items'] as $mc_item): ?>
          <a class="mc-nav-item<?= $mc_active === $mc_item[3] ? ' active' : '' ?><?= !empty($mc_item[4]) ? ' mc-danger' : '' ?>"
             href="<?= clean($mc_item[0]) ?>">
            <span class="mc-nav-icon"><i data-lucide="<?= clean($mc_item[1]) ?>"></i></span>
            <span class="mc-nav-label"><?= clean($mc_item[2]) ?></span>
            <?php if (!empty($mc_item[5]) && !empty($mc_badges[$mc_item[5]]) && $mc_badges[$mc_item[5]] > 0): ?>
              <span class="mc-badge"><?= (int)$mc_badges[$mc_item[5]] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="mc-divider"></div>
      <div class="mc-section">
        <?php foreach ($mc_sec['items'] as $mc_item): ?>
          <a class="mc-nav-item<?= $mc_active === $mc_item[3] ? ' active' : '' ?><?= !empty($mc_item[4]) ? ' mc-danger' : '' ?>"
             href="<?= clean($mc_item[0]) ?>">
            <span class="mc-nav-icon"><i data-lucide="<?= clean($mc_item[1]) ?>"></i></span>
            <span class="mc-nav-label"><?= clean($mc_item[2]) ?></span>
            <?php if (!empty($mc_item[5]) && !empty($mc_badges[$mc_item[5]]) && $mc_badges[$mc_item[5]] > 0): ?>
              <span class="mc-badge"><?= (int)$mc_badges[$mc_item[5]] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>

  <div class="mc-sidebar-footer">
    <?php if (!empty($mc_menu['profile'])): ?>
      <a class="mc-user-card" href="<?= clean($mc_menu['profile']) ?>">
        <span class="mc-av"><?= clean($mc_initials) ?></span>
        <span class="mc-user-info">
          <span class="mc-user-name"><?= clean($mc_name) ?></span>
          <span class="mc-user-sub"><?= $mc_sub ?></span>
        </span>
        <span class="mc-logout" title="Edit profile"><i data-lucide="pencil"></i></span>
      </a>
    <?php else: ?>
      <div class="mc-user-card">
        <span class="mc-av"><?= clean($mc_initials) ?></span>
        <span class="mc-user-info">
          <span class="mc-user-name"><?= clean($mc_name) ?></span>
          <span class="mc-user-sub"><?= $mc_sub ?></span>
        </span>
        <a class="mc-logout" href="<?= $mc_logout ?>" title="Sign out"><i data-lucide="log-out"></i></a>
      </div>
    <?php endif; ?>
  </div>
</aside>
