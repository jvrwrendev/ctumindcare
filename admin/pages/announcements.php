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

$mc_title        = 'Announcements — CTU MindCare';
$active_page     = 'announcements';
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
        <div class="greeting-label">Broadcast</div>
        <div class="greeting-title">Announcements</div>
        <div class="greeting-sub">Send notifications to students and counselors</div>
      </div>
      <div class="header-date">
        <strong><?= date('l, F j') ?></strong>
        <?= date('Y') ?> · CTU MindCare
      </div>
    </div>

    <div class="grid-2">

      <!-- ── Compose ── -->
      <div class="card mc-reveal">
        <div class="card-header">
          <div class="card-title"><i data-lucide="send"></i> Compose Announcement</div>
          <span class="card-link">Message will be sent as an in-app notification</span>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $e): ?><i data-lucide="alert-triangle" style="width:13px;height:13px;vertical-align:-2px"></i> <?= clean($e) ?><br><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST">
            <div class="form-group">
              <label class="form-label">Send To</label>
              <div class="target-wrap">
                <div class="target-opt">
                  <input type="radio" name="target" id="t_all" value="all" checked>
                  <label class="target-lbl" for="t_all"><i data-lucide="globe"></i> Everyone</label>
                </div>
                <div class="target-opt">
                  <input type="radio" name="target" id="t_students" value="students">
                  <label class="target-lbl" for="t_students"><i data-lucide="graduation-cap"></i> Students</label>
                </div>
                <div class="target-opt">
                  <input type="radio" name="target" id="t_counselors" value="counselors">
                  <label class="target-lbl" for="t_counselors"><i data-lucide="stethoscope"></i> Counselors</label>
                </div>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label" for="msg">Message</label>
              <textarea
                class="form-input form-textarea" id="msg" name="message" maxlength="500"
                placeholder="Write your announcement here…"
                style="min-height:130px"
                oninput="document.getElementById('cc').textContent=this.value.length"><?= clean($_POST['message'] ?? '') ?></textarea>
              <div class="char-count"><span id="cc">0</span> / 500 characters</div>
            </div>
            <button type="submit" class="btn-send" style="width:100%"><i data-lucide="megaphone" style="width:15px;height:15px;vertical-align:-2px"></i> Send Announcement</button>
          </form>
        </div>
      </div>

      <!-- ── History ── -->
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header" style="padding-bottom:4px">
          <div class="card-title"><i data-lucide="history"></i> Recent Announcements</div>
          <span class="card-link">Last 20 broadcasts sent</span>
        </div>
        <?php if ($recent): ?>
          <div class="card-body" style="padding-top:6px">
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
          </div>
        <?php else: ?>
          <div class="empty-state">
            <div class="empty-icon"><i data-lucide="inbox"></i></div>
            <div class="empty-text">No announcements sent yet.</div>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- /grid-2 -->

  </div>
  </main>
</div>

</body>
</html>
