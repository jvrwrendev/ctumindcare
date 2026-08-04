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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ✅ DO NOT clean here (store raw, clean on output)
    $message = $_POST['message'] ?? '';
    $target  = $_POST['target']  ?? 'all';

    if (empty(trim($message))) {
        $errors[] = 'Message is required.';
    }

    if (strlen($message) > 500) {
        $errors[] = 'Message must be 500 characters or fewer.';
    }

    if (empty($errors)) {

        // ✅ USE ONLY ONE TABLE (users)
        if ($target === 'students') {

            $stmt = $db->prepare("
                SELECT id FROM users 
                WHERE role = 'student' AND is_active = 1
            ");
            $stmt->execute();
            $recipients = $stmt->fetchAll();

        } elseif ($target === 'counselors') {

            $stmt = $db->prepare("
                SELECT id FROM users 
                WHERE role = 'wellness_admin' AND is_active = 1
            ");
            $stmt->execute();
            $recipients = $stmt->fetchAll();

        } else {

            $stmt = $db->prepare("
                SELECT id FROM users 
                WHERE role IN ('student','wellness_admin') 
                AND is_active = 1
            ");
            $stmt->execute();
            $recipients = $stmt->fetchAll();
        }

        // ✅ INSERT NOTIFICATIONS (FIXED)
        $stmtInsert = $db->prepare("
            INSERT INTO notifications (user_id, type, message, is_read, created_at)
            VALUES (?, 'general', ?, 0, NOW())
        ");

        foreach ($recipients as $r) {
            $stmtInsert->execute([
                $r['id'],
                $message
            ]);
        }

        $count = count($recipients);

        setFlash('success', "Announcement sent to {$count} users.");
        header('Location: announcements.php');
        exit;
    }
}

// ✅ FIXED: remove grouping by created_at (was causing issues)
$stmt = $db->query("
    SELECT message, COUNT(*) as total_sent, SUM(is_read) as total_read, MAX(created_at) as created_at
    FROM notifications 
    WHERE type = 'general'
    GROUP BY message
    ORDER BY created_at DESC 
    LIMIT 20
");

$recent = $stmt->fetchAll();
$flash  = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Announcements — CTU MindCare</title>
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
  display: flex; align-items: center; gap: 10px;
  text-decoration: none; margin-right: auto;
}
.topbar-mark {
  width: 36px; height: 36px; border-radius: 9px;
  background: linear-gradient(135deg, var(--sage) 0%, var(--sage-dark) 100%);
  color: #fff; font-family: 'Playfair Display', serif; font-size: 17px;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(62,142,126,.4); flex-shrink: 0;
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

/* ── Page Header ── */
.page-label { font-size: 10.5px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--sage); margin-bottom: 5px; }
.page-title { font-family: 'Playfair Display', serif; font-size: 27px; color: var(--text); line-height: 1.15; }
.page-sub   { font-size: 13px; color: var(--text-3); margin-top: 4px; margin-bottom: 26px; }

/* ── Flash ── */
.flash {
  padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13px;
  margin-bottom: 22px; display: flex; align-items: center; gap: 10px; font-weight: 500;
  animation: slideIn .2s ease;
}
@keyframes slideIn { from { opacity:0; transform: translateY(-6px); } to { opacity:1; transform: translateY(0); } }
.flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
.flash-error   { background: var(--red-bg);   border: 1px solid var(--red-border);   color: var(--red); }

/* ── Grid ── */
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }

/* ── Cards ── */
.card {
  background: var(--white); border: 1px solid var(--border);
  border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden;
}
.card-header { padding: 20px 22px 0; }
.card-title {
  font-size: 13px; font-weight: 700; color: var(--text);
  text-transform: uppercase; letter-spacing: .06em;
}
.card-sub { font-size: 11px; color: var(--text-3); margin-top: 3px; }
.card-body { padding: 18px 22px 22px; }

/* ── Form ── */
.form-group { margin-bottom: 16px; }
.form-label {
  font-size: 11px; font-weight: 700; color: var(--text-3);
  text-transform: uppercase; letter-spacing: .06em;
  margin-bottom: 8px; display: block;
}
.form-textarea {
  width: 100%; padding: 11px 14px;
  background: var(--sand); border: 1.5px solid var(--border-2);
  border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
  font-size: 13px; color: var(--text); outline: none;
  resize: vertical; min-height: 130px; transition: all .15s;
}
.form-textarea:focus {
  background: var(--white); border-color: var(--sage);
  box-shadow: 0 0 0 3px rgba(62,142,126,.1);
}
.form-textarea::placeholder { color: var(--text-3); }
.char-count { font-size: 11px; color: var(--text-3); text-align: right; margin-top: 5px; }
.char-count span { font-weight: 600; }

/* ── Target selector ── */
.target-wrap { display: flex; gap: 8px; }
.target-opt { flex: 1; }
.target-opt input { display: none; }
.target-lbl {
  display: block; text-align: center; padding: 9px 10px;
  border-radius: var(--radius-sm); font-size: 12px; font-weight: 500;
  border: 1.5px solid var(--border-2); cursor: pointer;
  background: var(--sand); color: var(--text-2); transition: all .15s;
}
.target-lbl:hover { border-color: var(--sage); color: var(--sage-dark); }
.target-opt input:checked + .target-lbl {
  border-color: var(--sage); background: var(--sage-light);
  color: var(--sage-dark); font-weight: 600;
}

/* ── Error box ── */
.error-box {
  background: var(--red-bg); border: 1px solid var(--red-border);
  border-radius: var(--radius-sm); padding: 10px 14px;
  margin-bottom: 16px; font-size: 12px; color: var(--red); line-height: 1.7;
}

/* ── Send button ── */
.btn-send {
  width: 100%; padding: 12px; background: var(--sage); color: #fff;
  border: none; border-radius: var(--radius-sm); font-family: 'Sora', sans-serif;
  font-size: 13px; font-weight: 600; cursor: pointer; transition: background .15s;
  letter-spacing: .02em;
}
.btn-send:hover { background: var(--sage-2); }

/* ── Announcement history items ── */
.ann-list { padding: 0 22px; }
.ann-item {
  padding: 15px 0;
  border-bottom: 1px solid var(--sand-2);
}
.ann-item:last-child { border-bottom: none; padding-bottom: 20px; }
.ann-meta {
  display: flex; align-items: center;
  justify-content: space-between; margin-bottom: 8px;
  flex-wrap: wrap; gap: 6px;
}
.ann-meta-left { display: flex; align-items: center; gap: 8px; }
.ann-date { font-size: 11px; color: var(--text-3); }
.ann-target {
  font-size: 10px; padding: 2px 9px; border-radius: 100px;
  font-weight: 700; letter-spacing: .04em;
}
.target-students  { background: var(--sage-light);  color: var(--sage-dark); border: 1px solid var(--sage-mid); }
.target-counselors{ background: var(--lav-bg);       color: var(--lav);       border: 1px solid var(--lav-border); }
.target-all       { background: var(--blue-bg);      color: var(--blue);      border: 1px solid var(--blue-border); }
.ann-stats { font-size: 11px; color: var(--text-3); font-weight: 500; }
.ann-msg {
  font-size: 13px; color: var(--text-2); line-height: 1.6; margin-bottom: 9px;
}
.read-bar-wrap { display: flex; align-items: center; gap: 10px; }
.read-bar-bg {
  flex: 1; height: 5px; background: var(--sand-2);
  border-radius: 3px; overflow: hidden;
}
.read-bar-fill { height: 100%; background: var(--sage); border-radius: 3px; transition: width .3s; }
.read-pct { font-size: 10px; color: var(--text-3); font-weight: 600; white-space: nowrap; }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 48px 24px; }
.empty-icon  { font-size: 32px; margin-bottom: 10px; }
.empty-text  { font-size: 13px; color: var(--text-3); }

/* ── Responsive ── */
@media (max-width: 960px) {
  .layout { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .main { padding: 22px 18px 48px; }
}
@media (max-width: 680px) {
  .grid-2 { grid-template-columns: 1fr; }
  .target-wrap { flex-wrap: wrap; }
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
      <a class="nav-item active" href="announcements.php">
        <div class="nav-icon">📢</div> Announcements
      </a>
      <a class="nav-item" href="logs.php">
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
    <div class="page-label">Broadcast</div>
    <div class="page-title">Announcements</div>
    <div class="page-sub">Send notifications to students and counselors</div>

    <div class="grid-2">

      <!-- ── Compose ── -->
      <div class="card">
        <div class="card-header">
          <div class="card-title">Compose Announcement</div>
          <div class="card-sub">Message will be sent as an in-app notification</div>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $e): ?>⚠ <?= clean($e) ?><br><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Send To</label>
              <div class="target-wrap">
                <div class="target-opt">
                  <input type="radio" name="target" id="t_all" value="all" checked>
                  <label class="target-lbl" for="t_all">🌐 Everyone</label>
                </div>
                <div class="target-opt">
                  <input type="radio" name="target" id="t_students" value="students">
                  <label class="target-lbl" for="t_students">🎓 Students</label>
                </div>
                <div class="target-opt">
                  <input type="radio" name="target" id="t_counselors" value="counselors">
                  <label class="target-lbl" for="t_counselors">🩺 Counselors</label>
                </div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="msg">Message</label>
              <textarea
                class="form-textarea" id="msg" name="message" maxlength="500"
                placeholder="Write your announcement here…"
                oninput="document.getElementById('cc').textContent=this.value.length"><?= clean($_POST['message'] ?? '') ?></textarea>
              <div class="char-count"><span id="cc">0</span> / 500 characters</div>
            </div>
            <button type="submit" class="btn-send">📢 Send Announcement</button>
          </form>
        </div>
      </div>

      <!-- ── History ── -->
      <div class="card">
        <div class="card-header" style="padding-bottom:4px">
          <div class="card-title">Recent Announcements</div>
          <div class="card-sub">Last 20 broadcasts sent</div>
        </div>
        <?php if ($recent): ?>
          <div class="ann-list">
            <?php foreach ($recent as $a):
              $pct = $a['total_sent'] > 0 ? round(($a['total_read'] / $a['total_sent']) * 100) : 0;
              $role = $a['target_role'] ?? 'all';
              $target_class = $role === 'student' ? 'target-students' : ($role === 'wellness_admin' ? 'target-counselors' : 'target-all');
              $target_label = $role === 'student' ? 'Students' : ($role === 'wellness_admin' ? 'Counselors' : 'All');
            ?>
              <div class="ann-item">
                <div class="ann-meta">
                  <div class="ann-meta-left">
                    <span class="ann-date"><?= date('M j, Y · g:i A', strtotime($a['created_at'])) ?></span>
                    <span class="ann-target <?= $target_class ?>"><?= $target_label ?></span>
                  </div>
                  <span class="ann-stats"><?= (int)$a['total_read'] ?> / <?= (int)$a['total_sent'] ?> read</span>
                </div>
                <div class="ann-msg"><?= clean($a['message']) ?></div>
                <div class="read-bar-wrap">
                  <div class="read-bar-bg">
                    <div class="read-bar-fill" style="width:<?= $pct ?>%"></div>
                  </div>
                  <span class="read-pct"><?= $pct ?>%</span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon">📭</div>
            <div class="empty-text">No announcements sent yet.</div>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /grid-2 -->

  </main>
</div>

</body>
</html>