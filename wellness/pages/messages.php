<?php
// ============================================================
//  CTU MindCare — Counselor Messages (Messenger-style chat)
//  File: wellness/pages/messages.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('wellness_admin');

$user = currentUser();
if (!$user) {
    $user = [
        'id'         => $_SESSION['user_id'],
        'first_name' => $_SESSION['user_name'] ?? 'Counselor',
        'last_name'  => '',
        'email'      => '',
        'role'       => 'wellness_admin',
    ];
}

$db = getDB();

require_once '../../includes/counselor_ai.php';
mcEnsureAiTables($db);
mcTouchPresence($db, (int)$user['id']);

if (!function_exists('clean')) {
    function clean(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── SEND MESSAGE (AJAX / POST) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_text'])) {
    $msg        = trim($_POST['message_text'] ?? '');
    $student_id = (int)($_POST['student_id'] ?? 0);

    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($msg !== '' && $student_id) {
        $stmt   = $db->prepare("
            INSERT INTO counselor_messages (student_id, counselor_id, sender_id, sender_type, message)
            VALUES (?, ?, ?, 'counselor', ?)
        ");
        $result = $stmt->execute([$student_id, $user['id'], $user['id'], $msg]);
        mcTouchPresence($db, (int)$user['id']);

        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode($result
                ? ['status' => 'ok', 'id' => (int)$db->lastInsertId()]
                : ['status' => 'error', 'message' => 'Database error']
            );
            exit;
        }
    }

    if (!$is_ajax) {
        header('Location: messages.php?student=' . $student_id);
        exit;
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Message cannot be empty']);
    exit;
}

// ── POLL MESSAGES (AJAX) ──────────────────────────────────────
if (isset($_GET['poll_messages'])) {
    $student_id = (int)($_GET['student'] ?? 0);
    $since_id   = (int)($_GET['since']   ?? 0);
    header('Content-Type: application/json');
    if (!$student_id) { echo json_encode(['messages' => []]); exit; }
    mcTouchPresence($db, (int)$user['id']);

    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.sent_at, cm.is_read, cm.sender_type,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name,  u.last_name)  AS last_name,
               CASE cm.sender_type
                    WHEN 'ai' THEN 'ai'
                    WHEN 'counselor' THEN 'wellness_admin'
                    ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_type = 'counselor' AND wa.id = cm.sender_id
        LEFT JOIN users u            ON cm.sender_type = 'student'   AND u.id  = cm.sender_id
        WHERE cm.student_id = ? AND cm.counselor_id = ? AND cm.id > ?
        ORDER BY cm.sent_at ASC
    ");
    $stmt->execute([$student_id, $user['id'], $since_id]);
    $msgs = $stmt->fetchAll();

    $db->prepare("
        UPDATE counselor_messages SET is_read = 1
        WHERE student_id = ? AND counselor_id = ? AND sender_type = 'student' AND is_read = 0
    ")->execute([$student_id, $user['id']]);

    echo json_encode(['messages' => $msgs]);
    exit;
}

// ── SIDEBAR COUNTS ────────────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM counselor_messages WHERE counselor_id = ? AND sender_type = 'student' AND is_read = 0");
$stmt->execute([$user['id']]);
$unread_msgs = (int)$stmt->fetch()['cnt'];

$stmt = $db->prepare("SELECT COUNT(DISTINCT ca.id) as cnt FROM crisis_alerts ca JOIN appointments a ON a.student_id = ca.student_id WHERE a.counselor_id = ? AND ca.status = 'open'");
$stmt->execute([$user['id']]);
$open_alerts_count = (int)$stmt->fetch()['cnt'];

$stmt = $db->query("SELECT COUNT(*) as cnt FROM forum_posts WHERE is_flagged = 1 AND is_removed = 0");
$flagged_count = (int)$stmt->fetch()['cnt'];

// ── MY STUDENTS ───────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.email,
        (SELECT me.mood_level FROM mood_entries me WHERE me.user_id = u.id ORDER BY me.recorded_at DESC LIMIT 1) as latest_mood
    FROM users u
    JOIN appointments a ON a.student_id = u.id
    WHERE a.counselor_id = ? AND u.is_active = 1
    GROUP BY u.id
    ORDER BY u.first_name ASC
");
$stmt->execute([$user['id']]);
$my_students = $stmt->fetchAll();

// ── CONVERSATIONS LIST ────────────────────────────────────────
$stmt = $db->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name,
        (SELECT cm2.message  FROM counselor_messages cm2 WHERE cm2.student_id = u.id AND cm2.counselor_id = ? ORDER BY cm2.sent_at DESC LIMIT 1) as last_message,
        (SELECT cm2b.sender_type FROM counselor_messages cm2b WHERE cm2b.student_id = u.id AND cm2b.counselor_id = ? ORDER BY cm2b.sent_at DESC LIMIT 1) as last_sender,
        (SELECT cm3.sent_at  FROM counselor_messages cm3 WHERE cm3.student_id = u.id AND cm3.counselor_id = ? ORDER BY cm3.sent_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*)     FROM counselor_messages cm4 WHERE cm4.student_id = u.id AND cm4.counselor_id = ? AND cm4.sender_type = 'student' AND cm4.is_read = 0) as unread
    FROM users u
    JOIN counselor_messages cm ON cm.student_id = u.id AND cm.counselor_id = ?
    GROUP BY u.id
    ORDER BY last_time DESC
");
$stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$conversations = $stmt->fetchAll();

$convo_ids    = array_column($conversations, 'id');
$all_for_chat = $conversations;
foreach ($my_students as $s) {
    if (!in_array($s['id'], $convo_ids)) {
        $all_for_chat[] = [
            'id'           => $s['id'],
            'first_name'   => $s['first_name'],
            'last_name'    => $s['last_name'],
            'last_message' => null,
            'last_sender'  => null,
            'last_time'    => null,
            'unread'       => 0,
        ];
    }
}

// ── ACTIVE CHAT STUDENT ───────────────────────────────────────
$chat_student_id = (int)($_GET['student'] ?? 0);
if (!$chat_student_id && $all_for_chat) {
    $chat_student_id = (int)$all_for_chat[0]['id'];
}

$chat_student  = null;
$chat_messages = [];
$last_msg_id   = 0;

$mood_emoji  = [1 => '😔', 2 => '😟', 3 => '😐', 4 => '🙂', 5 => '😊'];
$mood_labels = [1 => 'Very Low', 2 => 'Low', 3 => 'Okay', 4 => 'Good', 5 => 'Great'];

if ($chat_student_id) {
    foreach ($my_students as $s) {
        if ((int)$s['id'] === $chat_student_id) { $chat_student = $s; break; }
    }

    if (!$chat_student) {
        $stmt = $db->prepare("
            SELECT u.id, u.first_name, u.last_name, u.email, NULL AS latest_mood
            FROM users u
            JOIN counselor_messages cm ON cm.student_id = u.id
            WHERE u.id = ? AND cm.counselor_id = ?
            LIMIT 1
        ");
        $stmt->execute([$chat_student_id, $user['id']]);
        $chat_student = $stmt->fetch() ?: null;
    }

    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.is_read, cm.sent_at, cm.sender_type,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name,  u.last_name)  AS last_name,
               CASE cm.sender_type
                    WHEN 'ai' THEN 'ai'
                    WHEN 'counselor' THEN 'wellness_admin'
                    ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_type = 'counselor' AND wa.id = cm.sender_id
        LEFT JOIN users u            ON cm.sender_type = 'student'   AND u.id  = cm.sender_id
        WHERE cm.student_id = ? AND cm.counselor_id = ?
        ORDER BY cm.sent_at ASC
        LIMIT 150
    ");
    $stmt->execute([$chat_student_id, $user['id']]);
    $chat_messages = $stmt->fetchAll();
    if ($chat_messages) $last_msg_id = (int)end($chat_messages)['id'];

    $db->prepare("
        UPDATE counselor_messages SET is_read = 1
        WHERE student_id = ? AND counselor_id = ? AND sender_type = 'student' AND is_read = 0
    ")->execute([$chat_student_id, $user['id']]);
}

// ── HELPERS ───────────────────────────────────────────────────
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}

function timeLabel(string $dt): string {
    $ts = strtotime($dt); $diff = time() - $ts;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return date('g:i A', $ts);
    return date('M j', $ts);
}

function initials(array $s): string {
    return strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1));
}

$my_initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
$c_initials  = $chat_student ? initials($chat_student) : '';

$mc_title = 'Messages — CTU MindCare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= clean($mc_title) ?></title>
  <?php require_once '../../includes/assets.php'; ?>
</head>
<body class="chat-module-open">

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
  $active_page    = 'messages';
  $sidebar_badges = [
      'messages' => $unread_msgs,
      'alerts'   => $open_alerts_count,
      'forum'    => $flagged_count,
  ];
  require_once '../../includes/sidebar.php';
  ?>

<!-- ── Chat shell ── -->
  <div class="chat-wrap">

    <div class="chat-layout">

      <!-- Student conversations -->
      <div class="conv-pane" id="convPane">
        <div class="cp-header">
          <div class="cp-top">
            <span class="cp-title"><i data-lucide="messages-square"></i> Messages</span>
          </div>
          <div class="peer-search-wrap">
            <i data-lucide="search" class="ps-icon"></i>
            <input class="peer-search-input" type="text" id="studentSearch" placeholder="Search students…" autocomplete="off">
          </div>
        </div>

        <div class="conv-list" id="studentList">
          <?php if (!$all_for_chat): ?>
            <div class="cp-empty">No students assigned yet.</div>
          <?php else: ?>
            <?php foreach ($all_for_chat as $chat_item):
              $is_active = ((int)$chat_item['id'] === $chat_student_id);
              $si        = strtoupper(substr($chat_item['first_name'],0,1).substr($chat_item['last_name'],0,1));
              $unread    = (int)($chat_item['unread'] ?? 0);
              $preview   = '';
              $last_t    = '';
              if (!empty($chat_item['last_message'])) {
                  $ls = (string)($chat_item['last_sender'] ?? '');
                  $prefix = $ls === 'counselor' ? 'You: ' : ($ls === 'ai' ? 'AI: ' : '');
                  $preview = $prefix . mb_substr(strip_tags($chat_item['last_message']), 0, 28) . (mb_strlen($chat_item['last_message']) > 28 ? '…' : '');
                  if (!empty($chat_item['last_time'])) $last_t = timeAgo($chat_item['last_time']);
              }
            ?>
              <a class="convo-item <?= $is_active ? 'active' : '' ?>"
                 data-name="<?= htmlspecialchars(strtolower($chat_item['first_name'].' '.$chat_item['last_name'])) ?>"
                 href="messages.php?student=<?= $chat_item['id'] ?>">
                <div class="c-av">
                  <?= $si ?>
                  <div class="online-ring"></div>
                  <?php if ($unread): ?><div class="unread-dot"></div><?php endif; ?>
                </div>
                <div class="conv-info">
                  <div class="conv-name"><?= clean($chat_item['first_name'].' '.$chat_item['last_name']) ?></div>
                  <div class="conv-preview <?= $unread ? 'unread' : '' ?>">
                    <?= $preview ? clean($preview) : 'Student' ?>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0">
                  <?php if ($last_t): ?><span class="conv-time"><?= $last_t ?></span><?php endif; ?>
                  <?php if ($unread): ?><span class="conv-badge"><?= $unread ?></span><?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Conversation -->
      <div class="chat-pane <?= $chat_student ? 'active-mobile' : '' ?>" id="chatPane">

        <?php if (!$chat_student): ?>
        <div class="empty-chat empty-state">
          <div class="es-icon chat-big"><i data-lucide="messages-square"></i></div>
          <div class="es-title">Select a student</div>
          <div class="es-sub">Choose a student from the list to open or start a conversation.</div>
        </div>

        <?php else: ?>

        <div class="chat-header">
          <button class="ch-back-btn" id="backToConvs" onclick="mobileShowConvs()" title="Back to students"><i data-lucide="arrow-left"></i></button>
          <div class="ch-av-wrap">
            <div class="ch-av"><?= $c_initials ?></div>
            <span class="ch-online"></span>
          </div>
          <div style="min-width:0">
            <div class="chat-head-name"><?= clean($chat_student['first_name'].' '.$chat_student['last_name']) ?></div>
            <div class="chat-head-sub"><span class="online-dot"></span> Student &nbsp;·&nbsp; <?= clean($chat_student['email'] ?? '') ?></div>
            <?php if (!empty($chat_student['latest_mood'])): ?>
              <div class="ch-mood-badge">
                <i data-lucide="smile" style="width:11px;height:11px"></i>
                <?= $mood_emoji[$chat_student['latest_mood']] ?> <?= $mood_labels[$chat_student['latest_mood']] ?>
              </div>
            <?php endif; ?>
          </div>
          <div class="ch-actions">
            <a class="ch-btn" href="dashboard.php?p=progress&prog_student=<?= $chat_student_id ?>"><i data-lucide="trending-up"></i> Progress</a>
            <a class="ch-btn" href="dashboard.php?p=notes"><i data-lucide="file-text"></i> Add note</a>
            <a class="ch-btn" href="dashboard.php?p=appointments#book"><i data-lucide="calendar"></i> Book session</a>
            <div style="display:flex;align-items:center;gap:5px;margin-left:4px">
              <div class="conn-dot" id="connDot"></div>
              <span style="font-size:10.5px;color:var(--text-3)" id="connLabel">Live</span>
            </div>
          </div>
        </div>

        <div class="reconnect-bar" id="connStrip"><i data-lucide="wifi-off"></i> Connection lost — attempting to reconnect…</div>

        <div class="msg-error-bar" id="msgErrorBar">
          <i data-lucide="wifi-off"></i>
          <span class="mer-text">Couldn't send your message.</span>
          <button type="button" class="mer-retry" onclick="retrySend()">Retry</button>
        </div>

        <div class="chat-messages" id="chatMessages">

          <?php if (empty($chat_messages)): ?>
          <div class="no-msgs-state" id="emptyState">
            <div class="no-msgs-icon"><i data-lucide="hand"></i></div>
            <div class="no-msgs-title">Start the conversation</div>
            <div class="no-msgs-sub">Send <?= clean($chat_student['first_name']) ?> a message. They'll see it next time they check their app.</div>
          </div>

          <?php else: ?>
          <div class="sys-msg" id="sysMsg"><i data-lucide="lock"></i> Only you and this student can read this</div>

         <?php
      $prev_date   = '';
      $prev_sender = -1;
      $prev_role   = '';

      foreach ($chat_messages as $i => $msg):
        $is_mine = (($msg['sender_role'] ?? 'wellness_admin') === 'wellness_admin');
        $sender_role = $msg['sender_role'] ?? ($is_mine ? 'wellness_admin' : 'student');
        $is_ai   = $sender_role === 'ai';
        $msg_date    = date('Y-m-d', strtotime($msg['sent_at']));
        $same_sender = ($prev_sender === (int)$msg['sender_id'] && $prev_role === $sender_role);
        $new_group   = !$same_sender;

        if ($msg_date !== $prev_date):
          $dlabel = $msg_date === date('Y-m-d') ? 'Today'
                  : ($msg_date === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday'
                  : date('F j, Y', strtotime($msg_date)));
    ?>
        <div class="date-sep"><span><?= $dlabel ?></span></div>
    <?php
          $prev_date = $msg_date;
        endif;

        $next    = $chat_messages[$i+1] ?? null;
        $is_tail = !$next
            || (int)$next['sender_id']  !== (int)$msg['sender_id']
            || ($next['sender_role'] ?? 'student') !== $sender_role;

        $role_cls = $is_ai ? 'msg-ai'
                  : ($sender_role === 'wellness_admin' ? 'msg-counselor' : 'msg-student');
        $row_cls  = 'msg-row ' . ($is_mine ? 'mine' : 'theirs') . ' ' . $role_cls . ($is_ai ? ' ai' : '');
        $row_cls .= $new_group ? ' new-group' : ' same-sender';
        $row_cls .= $is_tail  ? ' tail-end' : '';

        $av_init = $is_mine ? $my_initials : ($is_ai ? 'AI' : $c_initials);
        $av_cls  = $is_mine ? 'is-mine' : 'is-theirs';

        $prev_sender = (int)$msg['sender_id'];
        $prev_role   = $sender_role;
    ?>
        <div class="<?= $row_cls ?>" data-id="<?= (int)$msg['id'] ?>">
          <div class="bub-av <?= $av_cls ?><?= (!$is_tail) ? ' hidden' : '' ?>"><?= $av_init ?></div>
          <div class="bub-wrap">
            <?php if ($new_group && !$is_mine): ?>
              <div class="msg-sender-name<?= $is_ai ? ' is-ai' : '' ?>"><?= $is_ai ? MC_AI_NAME : clean($msg['first_name'].' '.$msg['last_name']) ?></div>
            <?php endif; ?>
            <div class="bubble"><?= nl2br(clean($msg['message'])) ?></div>
            <?php if ($is_tail): ?>
              <div class="msg-time">
                <?= timeLabel($msg['sent_at']) ?>
                <?php if ($is_mine): ?>
                  <span class="<?= $msg['is_read'] ? 'tick-read' : 'tick-sent' ?>" title="<?= $msg['is_read'] ? 'Read' : 'Sent' ?>"><?= $msg['is_read'] ? '✓✓' : '✓' ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
    <?php endforeach; ?>
          <?php endif; ?>

          <div class="typing-row" id="typingRow">
            <div class="bub-av is-theirs" id="typingAv"><?= $c_initials ?></div>
            <div class="typing-meta">
              <div class="typing-label" id="typingLabel">Student is typing…</div>
              <div class="typing-bubble">
                <div class="t-dot"></div>
                <div class="t-dot"></div>
                <div class="t-dot"></div>
              </div>
            </div>
          </div>

        </div><!-- .chat-messages -->

        <div class="emoji-panel" id="emojiPanel">
          <div class="emoji-grid" id="emojiGrid"></div>
        </div>

        <div class="chat-input-area">
          <div class="input-tools">
            <button class="tool-btn" id="emojiBtn" title="Emoji" type="button"><i data-lucide="smile"></i></button>
            <div style="margin-left:auto;font-size:10px;color:var(--text-3)" id="charCount"></div>
          </div>
          <div class="input-row">
            <textarea
              class="msg-textarea" id="msgInput"
              placeholder="Message <?= clean($chat_student['first_name']) ?>…"
              rows="1" maxlength="2000"
              onkeydown="handleKey(event)"
              oninput="onInput(this)"></textarea>
            <button class="send-btn" id="sendBtn" disabled title="Send (Enter)" type="button" onclick="sendMessage()">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
          </div>
          <div class="input-hint">Press <kbd style="background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px">Enter</kbd> to send &nbsp;·&nbsp; <kbd style="background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px">Shift+Enter</kbd> for new line</div>
        </div>

        <?php endif; ?>
      </div><!-- .chat-pane -->

    </div><!-- .chat-layout -->
  </div><!-- .chat-wrap -->
</div><!-- .layout -->

<script>
'use strict';

const STUDENT_ID   = <?= (int)$chat_student_id ?>;
const MY_ID        = <?= (int)$user['id'] ?>;
const MY_INIT      = '<?= $my_initials ?>';
const C_INIT       = '<?= $c_initials ?>';
const MAX_LEN      = 2000;

const POST_URL = 'messages.php';

let lastMsgId    = <?= $last_msg_id ?>;
let pollTimer    = null;
let sending      = false;
let online       = true;
let retryDelay   = 2000;
let typingTimers = {};

const msgInput     = document.getElementById('msgInput');
const sendBtn      = document.getElementById('sendBtn');
const chatMessages = document.getElementById('chatMessages');
const typingRow    = document.getElementById('typingRow');
const connDot      = document.getElementById('connDot');
const connLabel    = document.getElementById('connLabel');
const connStrip    = document.getElementById('connStrip');
const emojiBtn     = document.getElementById('emojiBtn');
const emojiPanel   = document.getElementById('emojiPanel');
const emojiGrid    = document.getElementById('emojiGrid');
const charCount    = document.getElementById('charCount');

function onInput(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(140, el.scrollHeight) + 'px';
  if (sendBtn) sendBtn.disabled = el.value.trim().length === 0;
  if (charCount) {
    const len = el.value.length;
    charCount.textContent = len > 1800 ? `${len}/${MAX_LEN}` : '';
    charCount.style.color = len > 1900 ? 'var(--red)' : 'var(--text-3)';
  }
}

function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    if (sendBtn && !sendBtn.disabled) sendMessage();
  }
}

function scrollBottom(instant = false) {
  if (!chatMessages) return;
  if (instant) chatMessages.scrollTop = chatMessages.scrollHeight;
  else chatMessages.scrollTo({ top: chatMessages.scrollHeight, behavior: 'smooth' });
}
// Render any lucide icons added to the DOM after the page loaded.
function initIcons() {
  if (window.lucide && typeof window.lucide.createIcons === 'function') {
    try { window.lucide.createIcons(); } catch (e) {}
  }
}
function nearBottom() {
  if (!chatMessages) return true;
  return chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 120;
}

// ── Send (core) ───────────────────────────────────────────────
let lastFailed  = null;
let aiWaitTimer = null;

function showTyping(label, initials) {
  const av = document.getElementById('typingAv');
  const lb = document.getElementById('typingLabel');
  if (av && initials) av.textContent = initials;
  if (lb && label)    lb.textContent = label;
  typingRow?.classList.add('show');
  scrollBottom();
}
function hideTyping() {
  typingRow?.classList.remove('show');
  clearTimeout(aiWaitTimer);
}

function showMsgError(text, withRetry) {
  const bar = document.getElementById('msgErrorBar');
  if (!bar) return;
  const t = bar.querySelector('.mer-text');
  if (t) t.textContent = text;
  bar.dataset.retry = withRetry ? '1' : '';
  bar.classList.add('show');
}
function hideMsgError() {
  const bar = document.getElementById('msgErrorBar');
  if (bar) bar.classList.remove('show');
}

async function doSend(txt, tempId) {
  if (sending) return;
  sending = true;
  if (sendBtn) { sendBtn.disabled = true; sendBtn.classList.add('busy'); }

  const fd = new FormData();
  fd.append('message_text', txt);
  fd.append('student_id', STUDENT_ID);

  try {
    const res = await fetch(POST_URL, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const data = await res.json();

    if (data.status === 'ok' && data.id) {
      const tmp = chatMessages?.querySelector(`[data-id="${tempId}"]`);
      if (tmp) {
        tmp.dataset.id = String(data.id);
        tmp.classList.remove('send-failed');
      }
      lastMsgId = Math.max(lastMsgId, data.id);
      lastFailed = null;
      hideMsgError();
    } else {
      lastFailed = { text: txt, tempId: tempId };
      showMsgError("Couldn't send your message. Please try again.", true);
      const tmp = chatMessages?.querySelector(`[data-id="${tempId}"]`);
      if (tmp) tmp.classList.add('send-failed');
    }
    setOnline(true);
  } catch (err) {
    console.error('[send]', err);
    setOnline(false);
    lastFailed = { text: txt, tempId: tempId };
    showMsgError("You're offline — the message couldn't be sent. Check your connection and retry.", true);
    const tmp = chatMessages?.querySelector(`[data-id="${tempId}"]`);
    if (tmp) tmp.classList.add('send-failed');
  }

  if (sendBtn) {
    sendBtn.classList.remove('busy');
    sendBtn.disabled = !msgInput || msgInput.value.trim() === '';
  }
  sending = false;
}

// ── Send (entry) ──────────────────────────────────────────────
async function sendMessage() {
  if (sending || !msgInput || !sendBtn) return;
  const txt = msgInput.value.trim();
  if (!txt || !STUDENT_ID) return;

  const tempId = 'tmp-' + Date.now();
  appendBubble({
    id: tempId, sender_id: MY_ID, sender_role: 'wellness_admin',
    message: txt, sent_at: new Date().toISOString(), is_read: 0
  }, true);
  msgInput.value = '';
  msgInput.style.height = 'auto';
  scrollBottom();
  document.getElementById('emptyState')?.remove();

  await doSend(txt, tempId);
  msgInput.focus();
}

// ── Retry a failed send ───────────────────────────────────────
function retrySend() {
  if (!lastFailed || sending) return;
  doSend(lastFailed.text, lastFailed.tempId);
}

// ── Polling ───────────────────────────────────────────────────
async function poll() {
  if (!STUDENT_ID) return;
  try {
    const res  = await fetch(`messages.php?poll_messages=1&student=${STUDENT_ID}&since=${lastMsgId}`, { cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (data.messages?.length) {
      const wasNear = nearBottom();
      data.messages.forEach(m => {
        const id = parseInt(m.id, 10);
        lastMsgId = Math.max(lastMsgId, id);

        // Handle temp message replacement (our own message that was waiting for ID)
        const tempMsg = chatMessages?.querySelector(`[data-id^="tmp-"]`);
        if (tempMsg && m.sender_role === 'wellness_admin') {
          tempMsg.dataset.id = String(id);
          tempMsg.classList.remove('send-failed');
          hideMsgError();
          const tickSpan = tempMsg.querySelector('.tick-sent');
          if (tickSpan && m.is_read) {
            tickSpan.className = 'tick-read';
            tickSpan.textContent = '✓✓';
          }
          return;
        }

        // Skip if already rendered (prevents duplicates)
        if (chatMessages?.querySelector(`[data-id="${id}"]`)) return;

        // Show typing indicator for new messages from the student / AI
        if (m.sender_role !== 'wellness_admin') {
          const isAi = m.sender_role === 'ai';
          showTyping(
            isAi ? 'AI Counselor Assistant is typing…' : 'Student is typing…',
            isAi ? 'AI' : C_INIT
          );
          clearTimeout(typingTimers[id]);
          typingTimers[id] = setTimeout(() => {
            hideTyping();
            appendBubble(m, false);
            if (wasNear) scrollBottom();
            updateSidebarPreview(m);
          }, 650);
        } else {
          // Our own message that came back from server — just update if needed
          const existingMsg = chatMessages?.querySelector(`[data-id="${id}"]`);
          if (!existingMsg) {
            appendBubble(m, true);
            if (wasNear) scrollBottom();
          }
        }
      });
    }

    setOnline(true);
    retryDelay = 2000;
  } catch (err) {
    console.error('[poll]', err);
    setOnline(false);
    retryDelay = Math.min(retryDelay * 1.5, 30000);
  }
}

function startPoll() {
  if (!STUDENT_ID) return;
  let pollInterval = 2500;
  let lastActivity = Date.now();

  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) { lastActivity = Date.now(); poll(); }
  });
  document.addEventListener('click',   () => { lastActivity = Date.now(); });
  document.addEventListener('keydown', () => { lastActivity = Date.now(); });

  function tick() {
    const idle = Date.now() - lastActivity;
    pollInterval = !online ? retryDelay : (idle > 60000 ? 8000 : idle > 15000 ? 4000 : 2500);
    poll();
    pollTimer = setTimeout(tick, pollInterval);
  }
  pollTimer = setTimeout(tick, pollInterval);
}

function setOnline(isOnline) {
  if (online === isOnline) return;
  online = isOnline;
  if (connDot)   connDot.className = 'conn-dot' + (isOnline ? '' : ' offline');
  if (connLabel) connLabel.textContent = isOnline ? 'Live' : 'Offline';
  if (connStrip) connStrip.classList.toggle('show', !isOnline);
}

// ── Append bubble ─────────────────────────────────────────────
function appendBubble(msg, isMine) {
  if (!chatMessages) return;
  const id = String(msg.id);
  if (id && !id.startsWith('tmp') && chatMessages.querySelector(`[data-id="${id}"]`)) return;

  if (!document.getElementById('sysMsg')) {
    const s = document.createElement('div');
    s.id = 'sysMsg'; s.className = 'sys-msg';
    s.innerHTML = '<i data-lucide="lock"></i> Only you and this student can read this';
    chatMessages.insertBefore(s, typingRow);
    initIcons();
  }
  document.getElementById('emptyState')?.remove();

  const rows    = [...chatMessages.querySelectorAll('.msg-row')];
  const lastRow = rows.length ? rows[rows.length - 1] : null;
  const lastIsMine = lastRow?.classList.contains('mine');
  const newGroup   = !lastRow || (isMine ? !lastIsMine : lastIsMine);

  if (!newGroup && lastRow) {
    lastRow.classList.remove('tail-end');
    lastRow.classList.add('same-sender');
    lastRow.querySelector('.bub-av')?.classList.add('hidden');
    lastRow.querySelector('.msg-time')?.remove();
    const prevBub = lastRow.querySelector('.bubble');
    if (prevBub) {
      prevBub.style.borderRadius = isMine
        ? '20px 4px 4px 20px'
        : '4px 20px 20px 4px';
    }
  }

  const row = document.createElement('div');
  row.dataset.id = id;
  const roleCls = msg.sender_role === 'ai' ? 'msg-ai'
                : (msg.sender_role === 'wellness_admin' ? 'msg-counselor' : 'msg-student');
  row.className = `msg-row ${isMine ? 'mine' : 'theirs'} ${roleCls}${msg.sender_role === 'ai' ? ' ai' : ''} ${newGroup ? 'new-group' : 'same-sender'} tail-end`;

  const avCls  = isMine ? 'is-mine' : 'is-theirs';
  const avInit = isMine ? MY_INIT : (msg.sender_role === 'ai' ? 'AI' : C_INIT);
  const text   = escHtml(msg.message).replace(/\n/g, '<br>');
  const time   = fmtTime(msg.sent_at);

  // Show student / AI name when message is not from the counselor
  let senderHtml = '';
  if (!isMine && newGroup && msg.sender_role !== 'wellness_admin') {
    let senderName, aiCls = '';
    if (msg.sender_role === 'ai') {
      senderName = 'AI Counselor Assistant';
      aiCls = ' is-ai';
    } else {
      senderName = msg.first_name && msg.last_name
        ? `${msg.first_name} ${msg.last_name}`
        : 'Student';
    }
    senderHtml = `<div class="msg-sender-name${aiCls}">${escHtml(senderName)}</div>`;
  }

  const readMark = isMine
    ? `<span class="${msg.is_read ? 'tick-read' : 'tick-sent'}" title="${msg.is_read ? 'Read' : 'Sent'}">${msg.is_read ? '✓✓' : '✓'}</span>`
    : '';

  row.innerHTML = `
    <div class="bub-av ${avCls}">${avInit}</div>
    <div class="bub-wrap">
      ${senderHtml}
      <div class="bubble">${text}</div>
      <div class="msg-time">${time} ${readMark}</div>
    </div>`;
  chatMessages.insertBefore(row, typingRow);
}

function updateSidebarPreview(msg) {
  const item = document.querySelector(`.convo-item[href*="student=${STUDENT_ID}"]`);
  if (!item) return;
  const previewEl = item.querySelector('.conv-preview');
  const timeEl    = item.querySelector('.conv-time');
  if (previewEl) {
    const isAi = msg.sender_id === null || parseInt(msg.sender_id, 10) === 0;
    const prefix = isAi ? 'AI: ' : '';
    previewEl.textContent = prefix + msg.message.slice(0, 28) + (msg.message.length > 28 ? '…' : '');
    previewEl.classList.add('unread');
  }
  if (timeEl) timeEl.textContent = 'just now';
  const list = item.parentElement;
  if (list && list.firstChild !== item) list.insertBefore(item, list.firstChild);
}

// ── Emoji picker ──────────────────────────────────────────────
const EMOJIS = ['😊','😁','😄','😂','🥰','😍','🥹','😌','🙏','👍','❤️','💙','💚','✨','🌸','🌺','☀️','🎉','🔥','💪','🤗','😢','😔','😥','🫂','🌟','💬','📚','🏃','🧘'];

if (emojiBtn && emojiPanel && emojiGrid) {
  EMOJIS.forEach(em => {
    const btn = document.createElement('button');
    btn.className = 'em-btn'; btn.type = 'button';
    btn.textContent = em;
    btn.onclick = () => {
      if (msgInput) {
        const pos = msgInput.selectionStart;
        msgInput.value = msgInput.value.slice(0, pos) + em + msgInput.value.slice(pos);
        msgInput.setSelectionRange(pos + em.length, pos + em.length);
        msgInput.focus();
        onInput(msgInput);
      }
      emojiPanel.classList.remove('show');
    };
    emojiGrid.appendChild(btn);
  });
  emojiBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    emojiPanel.classList.toggle('show');
  });
  document.addEventListener('click', (e) => {
    if (!emojiPanel.contains(e.target) && e.target !== emojiBtn) {
      emojiPanel.classList.remove('show');
    }
  });
}

// ── Student search ────────────────────────────────────────────
const searchEl = document.getElementById('studentSearch');
if (searchEl) {
  searchEl.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.convo-item').forEach(el => {
      el.style.display = (!q || (el.dataset.name || '').includes(q)) ? '' : 'none';
    });
  });
}

// ── Mobile panel switching ────────────────────────────────────
function mobileShowChat() {
  if (window.innerWidth > 640) return;
  document.querySelector('.chat-wrap .conv-pane')?.classList.add('hidden-mobile');
  document.querySelector('.chat-wrap .chat-pane')?.classList.add('active-mobile');
}
function mobileShowConvs() {
  document.querySelector('.chat-wrap .conv-pane')?.classList.remove('hidden-mobile');
  document.querySelector('.chat-wrap .chat-pane')?.classList.remove('active-mobile');
}

document.querySelectorAll('.convo-item').forEach(function(item) {
  item.addEventListener('click', function() {
    if (window.innerWidth <= 640) mobileShowChat();
  });
});

function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;');
}
function fmtTime(iso) {
  const d = new Date(iso); const diff = (Date.now() - d) / 1000;
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return d.toLocaleTimeString('en-PH', { hour:'numeric', minute:'2-digit' });
  if (diff < 604800) return d.toLocaleDateString('en-PH', { weekday:'short', hour:'numeric', minute:'2-digit' });
  return d.toLocaleDateString('en-PH', { month:'short', day:'numeric' });
}

document.addEventListener('DOMContentLoaded', () => {
  scrollBottom(true);
  msgInput?.focus();
  if (STUDENT_ID) startPoll();

  if (STUDENT_ID && window.innerWidth <= 640) {
    mobileShowChat();
  }
});
window.addEventListener('online',  () => { setOnline(true); if (STUDENT_ID) poll(); });
window.addEventListener('offline', () => setOnline(false));
window.addEventListener('beforeunload', () => { if (pollTimer) clearTimeout(pollTimer); });
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
