<?php
// ============================================================
//  CTU MindCare — AI Assistant Support Logs (Counselor Review)
//  File: wellness/pages/ai-logs.php
//  Confidential — for Wellness Office use only.
// ============================================================
require_once '../../includes/auth.php';
requireRole('wellness_admin');

$user = currentUser();
$db   = getDB();

require_once '../../includes/counselor_ai.php';
mcEnsureAiTables($db);
mcTouchPresence($db, (int)$user['id']);

$flash = getFlash();

// ── Mark risk flag as reviewed ────────────────────────────────
if (isset($_GET['flag_reviewed']) && ($fid = (int)$_GET['flag_reviewed'])) {
    $db->prepare("UPDATE chatbot_risk_flags SET status = 'reviewed' WHERE id = ?")->execute([$fid]);
    setFlash('success', 'Risk flag marked as reviewed.');
    header('Location: ai-logs.php' . (isset($_GET['view']) ? '?view=' . (int)$_GET['view'] : ''));
    exit;
}

// ── Stats ─────────────────────────────────────────────────────
$total_students = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM chatbot_messages WHERE user_role = 'student'")->fetchColumn();
$total_messages = (int)$db->query("SELECT COUNT(*) FROM chatbot_messages WHERE user_role = 'student'")->fetchColumn();
$open_flags     = (int)$db->query("SELECT COUNT(*) FROM chatbot_risk_flags WHERE status = 'open'")->fetchColumn();
$waiting_requests = (int)$db->query("SELECT COUNT(*) FROM chatbot_booking_requests WHERE status = 'waiting'")->fetchColumn();

// ── Topbar bell counts ────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM counselor_messages
    WHERE counselor_id = ? AND sender_type = 'student' AND is_read = 0
");
$stmt->execute([$user['id']]);
$unread_msgs = (int)$stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT COUNT(DISTINCT ca.id) as cnt
    FROM crisis_alerts ca
    JOIN appointments a ON a.student_id = ca.student_id
    WHERE a.counselor_id = ? AND ca.status = 'open'
");
$stmt->execute([$user['id']]);
$open_alerts_count = (int)$stmt->fetch()['cnt'];

$mc_title = 'AI Support Logs — CTU MindCare';

// ── Open risk flags + waiting requests (always visible) ───────
$open_flags_list = $db->query("
    SELECT f.id, f.risk, f.reason, f.created_at, u.first_name, u.last_name, u.student_id
    FROM chatbot_risk_flags f
    JOIN users u ON u.id = f.student_id
    WHERE f.status = 'open'
    ORDER BY f.created_at DESC
    LIMIT 50
")->fetchAll();

$waiting_list = $db->query("
    SELECT b.id, b.preferred_date, b.preferred_time, b.topic, b.created_at,
           u.first_name, u.last_name, u.student_id, u.id AS student_id_raw
    FROM chatbot_booking_requests b
    JOIN users u ON u.id = b.student_id
    WHERE b.status = 'waiting'
    ORDER BY b.created_at DESC
    LIMIT 50
")->fetchAll();

// ── Conversation list ─────────────────────────────────────────
$conversations = $db->query("
    SELECT cm.user_id,
           u.first_name, u.last_name, u.student_id,
           COUNT(cm.id)                                        AS msg_count,
           SUM(cm.sender = 'user')                             AS user_msgs,
           MAX(cm.created_at)                                  AS last_at,
           MAX(CASE WHEN cm.risk = 'high' THEN 2 WHEN cm.risk = 'medium' THEN 1 ELSE 0 END) AS risk_score,
           (SELECT COUNT(*) FROM chatbot_risk_flags f WHERE f.student_id = cm.user_id AND f.status = 'open') AS open_flag_count
    FROM chatbot_messages cm
    JOIN users u ON u.id = cm.user_id
    WHERE cm.user_role = 'student'
    GROUP BY cm.user_id, u.first_name, u.last_name, u.student_id
    ORDER BY risk_score DESC, last_at DESC
    LIMIT 100
")->fetchAll();

// ── Transcript view ───────────────────────────────────────────
$view_user = (int)($_GET['view'] ?? 0);
$transcript = null;
$view_student = null;
if ($view_user) {
    $stmt = $db->prepare("SELECT id, first_name, last_name, student_id FROM users WHERE id = ?");
    $stmt->execute([$view_user]);
    $view_student = $stmt->fetch() ?: null;

    if ($view_student) {
        $stmt = $db->prepare("SELECT id, sender, message, risk, created_at FROM chatbot_messages WHERE user_id = ? AND user_role = 'student' ORDER BY id ASC");
        $stmt->execute([$view_user]);
        $transcript = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT id, risk, reason, status, created_at FROM chatbot_risk_flags WHERE student_id = ? ORDER BY created_at DESC");
        $stmt->execute([$view_user]);
        $view_flags = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT * FROM chatbot_booking_requests WHERE student_id = ? ORDER BY created_at DESC");
        $stmt->execute([$view_user]);
        $view_requests = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT id, appointment_date, appointment_time, purpose, status FROM appointments WHERE student_id = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$view_user]);
        $view_appts = $stmt->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require_once '../../includes/assets.php'; ?>
</head>
<body>

<?php
$mc_topbar_badge = 'Wellness Counselor';
$mc_topbar_bells = [
    ['url' => 'messages.php', 'title' => $unread_msgs . ' unread message' . ($unread_msgs > 1 ? 's' : ''), 'icon' => '💬', 'count' => $unread_msgs],
    ['url' => 'dashboard.php?p=alerts', 'title' => $open_alerts_count . ' open alert' . ($open_alerts_count > 1 ? 's' : ''), 'icon' => '🚨', 'count' => $open_alerts_count],
];
require_once '../../includes/topbar.php';
?>

<div class="layout">

  <?php
  $active_page    = 'ai-logs';
  $sidebar_badges = [
      'messages' => $unread_msgs,
      'ai-logs'  => $open_flags,
      'alerts'   => $open_alerts_count,
  ];
  require_once '../../includes/sidebar.php';
  ?>

  <main class="main ai-logs-page">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if ($view_student && $transcript !== null): ?>

      <!-- ══ TRANSCRIPT VIEW ══════════════════════════════════ -->
      <a class="back-link" href="ai-logs.php">← Back to all conversations</a>

      <div class="page-header">
        <div class="page-title">Conversation with <?= clean($view_student['first_name'].' '.$view_student['last_name']) ?></div>
        <div class="page-sub">
          Student ID: <?= clean($view_student['student_id'] ?? '—') ?> ·
          <?= count($transcript) ?> messages ·
          Last activity <?= $transcript ? date('M j, g:i A', strtotime(end($transcript)['created_at'])) : '—' ?>
        </div>
      </div>

      <?php if ($view_flags): ?>
      <div class="card">
        <div class="card-title">🛡️ Risk flags</div>
        <div class="card-sub">Raised automatically by the AI assistant's urgency assessment.</div>
        <?php foreach ($view_flags as $flag): ?>
          <div class="flag-item <?= $flag['risk'] ?>">
            <span class="fi-badge <?= $flag['risk'] ?>"><?= $flag['risk'] ?></span>
            <div class="fi-info">
              <div class="fi-reason"><?= clean($flag['reason'] ?? 'No reason recorded') ?></div>
            </div>
            <div class="fi-time"><?= date('M j, g:i A', strtotime($flag['created_at'])) ?></div>
            <div class="fi-actions">
              <span class="risk-badge <?= $flag['status'] === 'reviewed' ? 'risk-low' : 'risk-high' ?>">
                <?= $flag['status'] === 'reviewed' ? '✓ Reviewed' : 'Open' ?>
              </span>
              <?php if ($flag['status'] === 'open'): ?>
                <a class="btn sage small" href="ai-logs.php?flag_reviewed=<?= $flag['id'] ?>&view=<?= $view_user ?>">Mark reviewed</a>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($view_requests): ?>
      <div class="card">
        <div class="card-title">⏳ Booking requests (no counselor was online)</div>
        <div class="card-sub">Follow up with this student to confirm their session.</div>
        <?php foreach ($view_requests as $req): ?>
          <div class="flag-item">
            <span class="fi-badge <?= $req['status'] === 'waiting' ? 'medium' : 'low' ?>"><?= $req['status'] ?></span>
            <div class="fi-info">
              <div class="fi-student">
                <?= $req['preferred_date'] ? date('D, M j', strtotime($req['preferred_date'])) . ' at ' . date('g:i A', strtotime($req['preferred_time'])) : 'No preferred date' ?>
              </div>
              <?php if ($req['topic']): ?><div class="fi-reason">Topic: <?= clean($req['topic']) ?></div><?php endif; ?>
            </div>
            <div class="fi-time">Requested <?= date('M j, g:i A', strtotime($req['created_at'])) ?></div>
            <div class="fi-actions">
              <a class="btn lav small" href="dashboard.php?p=messages&student=<?= $view_user ?>">💬 Message student</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($view_appts): ?>
      <div class="card">
        <div class="card-title">📅 Recent appointments</div>
        <?php foreach ($view_appts as $appt): ?>
          <div class="flag-item">
            <div class="fi-info">
              <div class="fi-student"><?= date('D, M j', strtotime($appt['appointment_date'])) ?> at <?= date('g:i A', strtotime($appt['appointment_time'])) ?></div>
              <?php if ($appt['purpose']): ?><div class="fi-reason"><?= clean($appt['purpose']) ?></div><?php endif; ?>
            </div>
            <span class="risk-badge <?= $appt['status'] === 'confirmed' ? 'risk-low' : ($appt['status'] === 'pending' ? 'risk-medium' : 'risk-high') ?>"><?= $appt['status'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-title">💬 AI conversation transcript</div>
        <div class="card-sub">Confidential — for wellness office review only.</div>
        <div class="chat-view">
          <?php foreach ($transcript as $m): ?>
            <div class="cb-msg <?= $m['sender'] === 'user' ? 'cb-msg-user' : 'cb-msg-bot' ?>">
              <div><?= nl2br(clean($m['message'])) ?></div>
              <div class="meta">
                <?= $m['sender'] === 'user' ? 'Student' : 'AI Assistant' ?> · <?= date('M j, g:i A', strtotime($m['created_at'])) ?>
                <span class="risk-tag <?= $m['risk'] ?>"><?= $m['risk'] ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

    <?php else: ?>

      <!-- ══ OVERVIEW ═════════════════════════════════════════ -->
      <div class="page-header">
        <div class="page-title">AI Support Logs</div>
        <div class="page-sub">
          Review conversations students have had with the AI Mental Health Assistant. The AI is a 24/7 first-line
          support: it flags urgent concerns, queues bookings when no counselor is online, and never replaces you.
        </div>
      </div>

      <div class="stats-row">
        <div class="stat-card"><div class="sc-num"><?= $total_students ?></div><div class="sc-label">Students using AI</div></div>
        <div class="stat-card"><div class="sc-num"><?= $total_messages ?></div><div class="sc-label">Total messages</div></div>
        <div class="stat-card"><div class="sc-num" style="color:var(--red)"><?= $open_flags ?></div><div class="sc-label">Open risk flags</div></div>
        <div class="stat-card"><div class="sc-num" style="color:var(--amber)"><?= $waiting_requests ?></div><div class="sc-label">Waiting bookings</div></div>
      </div>

      <?php if ($open_flags_list): ?>
      <div class="card">
        <div class="card-title">🛡️ Open risk flags <span class="risk-badge risk-high"><?= count($open_flags_list) ?></span></div>
        <div class="card-sub">Urgency concerns raised by the AI. Review and follow up with the student.</div>
        <?php foreach ($open_flags_list as $flag): ?>
          <div class="flag-item <?= $flag['risk'] ?>">
            <span class="fi-badge <?= $flag['risk'] ?>"><?= $flag['risk'] ?></span>
            <div class="fi-info">
              <div class="fi-student"><?= clean($flag['first_name'].' '.$flag['last_name']) ?> <span style="color:var(--text-3);font-weight:400">· <?= clean($flag['student_id'] ?? '—') ?></span></div>
              <div class="fi-reason"><?= clean($flag['reason'] ?? '') ?></div>
            </div>
            <div class="fi-time"><?= date('M j, g:i A', strtotime($flag['created_at'])) ?></div>
            <div class="fi-actions">
              <a class="btn small" href="ai-logs.php?view=<?= $flag['student_id'] ?>">View transcript</a>
              <a class="btn sage small" href="ai-logs.php?flag_reviewed=<?= $flag['id'] ?>">Mark reviewed</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($waiting_list): ?>
      <div class="card">
        <div class="card-title">⏳ Students waiting for a counselor</div>
        <div class="card-sub">These students tried to book while no counselor was online. They'll be notified you're available.</div>
        <?php foreach ($waiting_list as $req): ?>
          <div class="flag-item">
            <div class="fi-info">
              <div class="fi-student"><?= clean($req['first_name'].' '.$req['last_name']) ?> <span style="color:var(--text-3);font-weight:400">· <?= clean($req['student_id'] ?? '—') ?></span></div>
              <div class="fi-reason">
                <?= $req['preferred_date'] ? 'Wanted: ' . date('D, M j', strtotime($req['preferred_date'])) . ' at ' . date('g:i A', strtotime($req['preferred_time'])) : 'No preferred time' ?>
                <?= $req['topic'] ? ' · ' . clean(mb_substr($req['topic'], 0, 60)) : '' ?>
              </div>
            </div>
            <div class="fi-time"><?= date('M j, g:i A', strtotime($req['created_at'])) ?></div>
            <div class="fi-actions">
              <a class="btn lav small" href="dashboard.php?p=messages&student=<?= (int)$req['student_id_raw'] ?>">💬 Message</a>
              <a class="btn small" href="ai-logs.php?view=<?= (int)$req['student_id_raw'] ?>">View</a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="card">
        <div class="card-title">💬 Student conversations</div>
        <div class="card-sub">All AI assistant conversations, most urgent first.</div>

        <?php if (empty($conversations)): ?>
          <div class="empty-state">
            <div class="es-icon">🤖</div>
            <div class="es-title">No AI conversations yet</div>
            <div class="es-sub">When students chat with the AI Assistant, conversations will appear here for your review.</div>
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Student</th>
                  <th>Messages</th>
                  <th>Last activity</th>
                  <th>Risk</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($conversations as $c): ?>
                  <tr>
                    <td>
                      <div class="t-name"><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                      <div class="t-meta"><?= clean($c['student_id'] ?? '—') ?></div>
                    </td>
                    <td><?= (int)$c['msg_count'] ?> <span style="color:var(--text-3);font-size:11px">(<?= (int)$c['user_msgs'] ?> from student)</span></td>
                    <td><?= date('M j, g:i A', strtotime($c['last_at'])) ?></td>
                    <td>
                      <?php if ((int)$c['risk_score'] === 2): ?>
                        <span class="risk-badge risk-high">High</span>
                      <?php elseif ((int)$c['risk_score'] === 1): ?>
                        <span class="risk-badge risk-medium">Medium</span>
                      <?php else: ?>
                        <span class="risk-badge risk-low">Low</span>
                      <?php endif; ?>
                      <?php if ((int)$c['open_flag_count'] > 0): ?>
                        <span class="risk-badge risk-high" style="margin-left:4px"><?= (int)$c['open_flag_count'] ?> flag</span>
                      <?php endif; ?>
                    </td>
                    <td><a class="btn sage small" href="ai-logs.php?view=<?= (int)$c['user_id'] ?>">View transcript</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <div class="flash flash-warning" style="margin-bottom:0">
        🔒 <span><strong>Confidential.</strong> AI conversations are private to each student and the wellness office.
        Conversations are retained for counselor review only and are never shared outside the wellness team without consent,
        except where safety is a concern.</span>
      </div>

    <?php endif; ?>

  </main>
</div>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
