<?php
// ============================================================
//  CTU MindCare — Real-Time Messenger Chat (Student)
//  File: student/pages/counselor-chat.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

require_once '../../includes/counselor_ai.php';
mcEnsureAiTables($db);

// FIX #11 — Define clean() if not already provided by auth.php.
if (!function_exists('clean')) {
    function clean(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── Load all counselors (active first) with presence info ─────
mcSweepStaleCounselors($db);
$stmt = $db->prepare("SELECT id, first_name, last_name, email, is_active, last_activity FROM wellness_admins ORDER BY is_active DESC, first_name ASC");
$stmt->execute();
$counselors = $stmt->fetchAll();

$counselor_online = [];
foreach ($counselors as $c) {
    $counselor_online[(int)$c['id']] = mcRowOnline($c);
}
$online_count = count(array_filter($counselor_online, fn($v) => $v));

// ── Active counselor ───────────────────────────────────────────
$counselor_id = (int)($_GET['counselor'] ?? 0);
if (!$counselor_id && $counselors) $counselor_id = (int)$counselors[0]['id'];

$active_counselor = null;
foreach ($counselors as $c) {
    if ((int)$c['id'] === $counselor_id) { $active_counselor = $c; break; }
}

// AI stands in when the selected counselor is unavailable
$ai_mode = $active_counselor ? !($counselor_online[$counselor_id] ?? false) : false;

// ── POLL endpoint ──────────────────────────────────────────────
if (isset($_GET['poll'])) {
    $since_id = (int)($_GET['since'] ?? 0);
    $cid      = (int)($_GET['counselor'] ?? 0);
    header('Content-Type: application/json');
    if (!$cid) { echo json_encode(['messages' => []]); exit; }
    mcSweepStaleCounselors($db);

    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.sent_at, cm.is_read, cm.sender_type,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name, u.last_name) AS last_name,
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
    $stmt->execute([$user['id'], $cid, $since_id]);
    $new_msgs = $stmt->fetchAll();
    $db->prepare("UPDATE counselor_messages SET is_read = 1 WHERE student_id = ? AND counselor_id = ? AND sender_type IN ('counselor','ai') AND is_read = 0")->execute([$user['id'], $cid]);
    echo json_encode([
        'messages' => $new_msgs,
        'ai_mode'  => !mcCounselorOnline($db, $cid),
    ]);
    exit;
}

// ── SEND MESSAGE (AJAX) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message'] ?? '');
    $cid = (int)($_POST['counselor_id'] ?? 0);
    if ($msg !== '' && $cid) {
        $stmt = $db->prepare("INSERT INTO counselor_messages (student_id, counselor_id, sender_id, sender_type, message) VALUES (?, ?, ?, 'student', ?)");
        $stmt->execute([$user['id'], $cid, $user['id'], $msg]);
        $new_id = (int)$db->lastInsertId();

        // AI Counselor Assistant stands in while the counselor is away
        $ai_risk = null;
        if (!mcCounselorOnline($db, $cid)) {
            $ai = mcAiReply($db, (int)$user['id'], $cid, $msg);
            $ai_risk = $ai['risk'] ?? null;
        }

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'message_id' => $new_id, 'ai_risk' => $ai_risk]);
            exit;
        }
    }
    header('Location: counselor-chat.php?counselor=' . $cid);
    exit;
}

// ── Mark incoming as read & load messages ──────────────────────
$messages = [];
$last_id  = 0;
if ($active_counselor) {
    $db->prepare("UPDATE counselor_messages SET is_read = 1 WHERE student_id = ? AND counselor_id = ? AND sender_type IN ('counselor','ai') AND is_read = 0")->execute([$user['id'], $counselor_id]);
    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.is_read, cm.sent_at, cm.sender_type,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name, u.last_name) AS last_name,
               CASE cm.sender_type
                    WHEN 'ai' THEN 'ai'
                    WHEN 'counselor' THEN 'wellness_admin'
                    ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_type = 'counselor' AND wa.id = cm.sender_id
        LEFT JOIN users u            ON cm.sender_type = 'student'   AND u.id  = cm.sender_id
        WHERE cm.student_id = ? AND cm.counselor_id = ?
        ORDER BY cm.sent_at ASC
    ");
    $stmt->execute([$user['id'], $counselor_id]);
    $messages = $stmt->fetchAll();
    if ($messages) $last_id = (int)end($messages)['id'];
}

// ── Unread per counselor ───────────────────────────────────────
$unread_map = [];
$stmt = $db->prepare("SELECT counselor_id, COUNT(*) as cnt FROM counselor_messages WHERE student_id = ? AND sender_type = 'counselor' AND is_read = 0 GROUP BY counselor_id");
$stmt->execute([$user['id']]);
foreach ($stmt->fetchAll() as $r) $unread_map[(int)$r['counselor_id']] = (int)$r['cnt'];

$last_msgs = [];
foreach ($counselors as $c) {
    $stmt = $db->prepare("SELECT message, sent_at, sender_id, sender_type FROM counselor_messages WHERE student_id = ? AND counselor_id = ? ORDER BY sent_at DESC LIMIT 1");
    $stmt->execute([$user['id'], $c['id']]);
    $last_msgs[(int)$c['id']] = $stmt->fetch();
}

function timeLabel(string $dt): string {
    $ts = strtotime($dt); $diff = time() - $ts;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return date('g:i A', $ts);
    return date('M j', $ts);
}

$c_initials  = $active_counselor
    ? strtoupper(substr($active_counselor['first_name'],0,1).substr($active_counselor['last_name'],0,1))
    : '?';
$my_initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

$student_display_id = $user['student_id'] ?? ($user['school_id'] ?? 'Student');

$mc_title = 'Counselor Chat — CTU MindCare';
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
<!-- ── Topbar ── -->
<?php
$mc_topbar_extra = '<div style="display:flex;align-items:center;gap:5px">
  <div class="conn-dot" id="connDot"></div>
  <span class="conn-label" id="connLabel">Live</span>
</div>';
require_once '../../includes/topbar.php';
?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php $active_page = 'counselor-chat'; ?>
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- ── Chat shell ── -->
  <div class="chat-wrap">

    <div class="chat-layout">

      <!-- Counselor list -->
      <div class="clist-pane<?= $active_counselor ? ' hidden-mobile' : '' ?>">
        <div class="clist-head">
          <div class="clist-title">Messages</div>
          <div class="clist-sub"><?= $online_count ?> counselor<?= $online_count !== 1 ? 's' : '' ?> available<?= count($counselors) > $online_count ? ' · AI stands in while away' : '' ?></div>
          <div class="clist-search" style="margin-top:12px">
            <i data-lucide="search"></i>
            <input type="text" id="counselorSearch" placeholder="Search counselors…" autocomplete="off">
          </div>
        </div>
        <div class="clist-body" id="counselorList">
          <?php if (!$counselors): ?>
            <div class="clist-empty">No counselors available right now.</div>
          <?php else: ?>
            <?php foreach ($counselors as $c):
              $ci     = strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1));
              $is_act = ((int)$c['id'] === $counselor_id);
              $is_on  = $counselor_online[(int)$c['id']] ?? false;
              $unread = $unread_map[(int)$c['id']] ?? 0;
              $last_m = $last_msgs[(int)$c['id']] ?? null;
              $preview = '';
              $last_t  = '';
              if ($last_m) {
                  $prefix  = $last_m['sender_type'] === 'student' ? 'You: '
                           : ($last_m['sender_type'] === 'ai' ? 'AI: ' : '');
                  $preview = $prefix . mb_substr(strip_tags($last_m['message']), 0, 28) . (mb_strlen($last_m['message']) > 28 ? '…' : '');
                  $last_t  = timeLabel($last_m['sent_at']);
              }
            ?>
              <a class="clist-item <?= $is_act ? 'active' : '' ?>"
                 data-name="<?= htmlspecialchars(strtolower($c['first_name'].' '.$c['last_name'])) ?>"
                 href="counselor-chat.php?counselor=<?= $c['id'] ?>">
                <div class="c-av"><?= $ci ?><div class="online-ring <?= $is_on ? '' : 'away' ?>"></div></div>
                <div class="c-info">
                  <div class="c-meta">
                    <div class="c-name"><?= clean($c['first_name'].' '.$c['last_name']) ?><?php if (!$is_on): ?> <span class="c-away-tag">Away</span><?php endif; ?></div>
                    <?php if ($last_t): ?><span class="c-time"><?= $last_t ?></span><?php endif; ?>
                  </div>
                  <div class="c-preview <?= $unread ? 'unread' : '' ?>">
                    <?= $preview ? clean($preview) : ($is_on ? 'Wellness Counselor' : 'Away — AI is standing in') ?>
                  </div>
                </div>
                <?php if ($unread): ?><div class="c-count-badge"><?= $unread ?></div><?php endif; ?>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Conversation -->
      <div class="chat-pane <?= $active_counselor ? 'active-mobile' : '' ?>" id="chatPane">

        <?php if (!$active_counselor): ?>
        <div class="empty-chat empty-state">
          <div class="es-icon chat-big"><i data-lucide="messages-square"></i></div>
          <div class="es-title">Select a counselor</div>
          <div class="es-sub">Choose a wellness counselor from the list to start a private, secure conversation.</div>
          <a class="es-btn btn btn-primary" href="appointments.php"><i data-lucide="calendar"></i> Book an appointment</a>
        </div>

        <?php else: ?>

        <div class="chat-header">
          <button class="ch-back-btn" onclick="mobileBackToList()" title="Back to counselors"><i data-lucide="arrow-left"></i></button>
          <div class="ch-av-wrap">
            <div class="ch-av"><?= $c_initials ?></div>
            <span class="ch-online <?= $ai_mode ? 'away' : '' ?>"></span>
          </div>
          <div style="min-width:0">
            <div class="chat-head-name"><?= clean($active_counselor['first_name'].' '.$active_counselor['last_name']) ?></div>
            <div class="chat-head-sub<?= $ai_mode ? ' ai-head-sub' : '' ?>" id="headSub">
              <?php if ($ai_mode): ?>
                <span class="ai-dot"></span> AI Counselor Assistant · <?= clean($active_counselor['first_name']) ?> is away
              <?php else: ?>
                <span class="online-dot"></span> Active now
              <?php endif; ?>
            </div>
            <div class="ch-lock"><i data-lucide="lock"></i> Private &amp; confidential</div>
          </div>
          <div class="ch-actions">
            <a class="ch-btn" href="appointments.php"><i data-lucide="calendar"></i> Book session</a>
            <a class="ch-btn danger" href="crisis.php"><i data-lucide="phone-call"></i> Crisis</a>
          </div>
        </div>

        <div class="ai-note" id="aiNote"<?= $ai_mode ? '' : ' style="display:none"' ?>>
          <i data-lucide="bot"></i>
          <span><b><?= clean($active_counselor['first_name']) ?></b> is away right now, so the <b>AI Counselor Assistant</b> is replying in their place. This conversation is saved — <?= clean($active_counselor['first_name']) ?> will see it and continue when they're back.</span>
        </div>

        <div class="reconnect-bar" id="reconnectBar"><i data-lucide="wifi-off"></i> Connection lost — attempting to reconnect…</div>
        <div class="chat-messages" id="chatMessages">

          <?php if (empty($messages)): ?>
          <div class="no-msgs-state" id="emptyState">
            <div class="no-msgs-icon"><i data-lucide="hand"></i></div>
            <div class="no-msgs-title">Say hello!</div>
            <div class="no-msgs-sub">Start a conversation with <?= clean($active_counselor['first_name']) ?>. Everything you share is private and confidential.</div>
          </div>

          <?php else: ?>
          <div class="sys-msg" id="sysMsg"><i data-lucide="lock"></i> Only you and your counselor can read this</div>

         <?php
      $prev_date   = '';
      $prev_sender = -1;
      $prev_role   = '';

      foreach ($messages as $i => $msg):
        // FIX: Compare sender ID directly
        $is_mine = (($msg['sender_role'] ?? 'student') === 'student');
        $sender_role = $msg['sender_role'] ?? ($is_mine ? 'student' : 'wellness_admin');
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

        $next    = $messages[$i+1] ?? null;
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
                  <span class="<?= $msg['is_read'] ? 'tick-read' : 'tick-sent' ?>"><?= $msg['is_read'] ? '✓✓' : '✓' ?></span>
                <?php endif; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
    <?php endforeach; ?>
          <?php endif; ?>

          <div class="typing-row" id="typingRow">
            <div class="bub-av is-theirs"><?= $ai_mode ? 'AI' : $c_initials ?></div>
            <div class="typing-bubble">
              <div class="t-dot"></div>
              <div class="t-dot"></div>
              <div class="t-dot"></div>
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
              placeholder="Message <?= clean($active_counselor['first_name']) ?>…"
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

const COUNSELOR_ID = <?= (int)$counselor_id ?>;
const MY_ID        = <?= (int)$user['id'] ?>;
const MY_INIT      = '<?= $my_initials ?>';
const C_INIT       = '<?= $c_initials ?>';
const C_NAME       = <?= json_encode($active_counselor['first_name'] ?? '', JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const MAX_LEN      = 2000;

let AI_MODE = <?= $ai_mode ? 'true' : 'false' ?>;

const POST_URL = 'counselor-chat.php';

let lastId       = <?= $last_id ?>;
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
const reconnBar    = document.getElementById('reconnectBar');
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

// ── Send ──────────────────────────────────────────────────────
async function sendMessage() {
  if (sending || !msgInput || !sendBtn) return;
  const txt = msgInput.value.trim();
  if (!txt || !COUNSELOR_ID) return;

  sending = true;
  sendBtn.disabled = true;

  const tempId = 'tmp-' + Date.now();
  appendBubble({
    id: tempId, sender_id: MY_ID, sender_role: 'student',
    message: txt, sent_at: new Date().toISOString(), is_read: 0
  }, true);
  msgInput.value = '';
  msgInput.style.height = 'auto';
  scrollBottom();
  document.getElementById('emptyState')?.remove();

  try {
    const fd = new FormData();
    fd.append('message', txt);
    fd.append('counselor_id', COUNSELOR_ID);
    const res  = await fetch(POST_URL + '?counselor=' + COUNSELOR_ID, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const data = await res.json();

    if (data.status === 'ok' && data.message_id) {
      const tmp = chatMessages?.querySelector(`[data-id="${tempId}"]`);
      if (tmp) {
        tmp.dataset.id = String(data.message_id);
        lastId = Math.max(lastId, data.message_id);
      }
    } else {
      const tmp = chatMessages?.querySelector(`[data-id="${tempId}"]`);
      if (tmp) tmp.style.opacity = '.5';
    }
    setOnline(true);
  } catch(err) {
    console.error('[send]', err);
    setOnline(false);
    const tmp = chatMessages?.querySelector(`[data-id="${tempId}"]`);
    if (tmp) tmp.style.opacity = '.5';
  }

  sending = false;
  if (msgInput) {
    msgInput.focus();
    if (sendBtn) sendBtn.disabled = msgInput.value.trim() === '';
  }
}

// ── Polling ───────────────────────────────────────────────────
async function poll() {
  if (!COUNSELOR_ID) return;
  try {
    const res  = await fetch(`counselor-chat.php?poll=1&counselor=${COUNSELOR_ID}&since=${lastId}`, { cache: 'no-store' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();

    if (typeof data.ai_mode === 'boolean' && data.ai_mode !== AI_MODE) {
      AI_MODE = data.ai_mode;
      setAiMode(AI_MODE);
    }

    if (data.messages?.length) {
      const wasNear = nearBottom();
      data.messages.forEach(m => {
        const id = parseInt(m.id, 10);
        lastId = Math.max(lastId, id);

        // Handle temp message replacement (our own message that was waiting for ID)
        const tempMsg = chatMessages?.querySelector(`[data-id^="tmp-"]`);
        if (tempMsg && m.sender_role === 'student') {
          tempMsg.dataset.id = String(id);
          const tickSpan = tempMsg.querySelector('.tick-sent');
          if (tickSpan && m.is_read) {
            tickSpan.className = 'tick-read';
            tickSpan.textContent = '✓✓';
          }
          return;
        }

        // Skip if already rendered (prevents duplicates)
        if (chatMessages?.querySelector(`[data-id="${id}"]`)) return;

        // Show typing indicator for new messages from counselor
        if (m.sender_role !== 'student') {
          showTyping();
          clearTimeout(typingTimers[id]);
          typingTimers[id] = setTimeout(() => {
            hideTyping();
            appendBubble(m, false); // false = not mine, from counselor
            if (wasNear) scrollBottom();
          }, 700);
        } else {
          // This is our own message that came back from server - just update if needed
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
  } catch(err) {
    console.error('[poll]', err);
    setOnline(false);
    retryDelay = Math.min(retryDelay * 1.5, 30000);
  }
}

function startPoll() {
  if (!COUNSELOR_ID) return;
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

function showTyping() { typingRow?.classList.add('show'); scrollBottom(); }
function hideTyping()  { typingRow?.classList.remove('show'); }

function setOnline(isOnline) {
  if (online === isOnline) return;
  online = isOnline;
  if (connDot)   connDot.className = 'conn-dot' + (isOnline ? '' : ' offline');
  if (connLabel) connLabel.textContent = isOnline ? 'Live' : 'Offline';
  if (reconnBar) reconnBar.classList.toggle('show', !isOnline);
}

// ── AI ⇄ human handoff indicator ──────────────────────────────
function setAiMode(mode) {
  const headSub = document.getElementById('headSub');
  if (headSub) {
    headSub.classList.toggle('ai-head-sub', mode);
    headSub.innerHTML = mode
      ? `<span class="ai-dot"></span> AI Counselor Assistant · ${C_NAME} is away`
      : '<span class="online-dot"></span> Active now';
  }
  const dot = document.querySelector('.ch-av-wrap .ch-online');
  if (dot) dot.classList.toggle('away', mode);
  const note = document.getElementById('aiNote');
  if (note) note.style.display = mode ? '' : 'none';
}

// ── Append bubble ─────────────────────────────────────────────
function appendBubble(msg, isMine) {
  if (!chatMessages) return;
  const id = String(msg.id);
  if (id && !id.startsWith('tmp') && chatMessages.querySelector(`[data-id="${id}"]`)) return;

  if (!document.getElementById('sysMsg')) {
    const s = document.createElement('div');
    s.id = 'sysMsg'; s.className = 'sys-msg';
    s.innerHTML = '<i data-lucide="lock"></i> Only you and your counselor can read this';
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

  // FIX: Show sender name for counselor / AI messages
  let senderHtml = '';
  if (!isMine && newGroup && msg.sender_role !== 'student') {
    let senderName, aiCls = '';
    if (msg.sender_role === 'ai') {
      senderName = 'AI Counselor Assistant';
      aiCls = ' is-ai';
    } else {
      senderName = msg.first_name && msg.last_name
        ? `${msg.first_name} ${msg.last_name}`
        : 'Counselor';
    }
    senderHtml = `<div class="msg-sender-name${aiCls}">${escHtml(senderName)}</div>`;
  }

  const readMark = isMine ? `<span class="${msg.is_read ? 'tick-read' : 'tick-sent'}">${msg.is_read ? '✓✓' : '✓'}</span>` : '';

  row.innerHTML = `
    <div class="bub-av ${avCls}">${avInit}</div>
    <div class="bub-wrap">
      ${senderHtml}
      <div class="bubble">${text}</div>
      <div class="msg-time">${time} ${readMark}</div>
    </div>`;
  chatMessages.insertBefore(row, typingRow);
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

// ── Counselor search ───────────────────────────────────────────
const searchEl = document.getElementById('counselorSearch');
if (searchEl) {
  searchEl.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.clist-item').forEach(el => {
      el.style.display = (!q || (el.dataset.name || '').includes(q)) ? '' : 'none';
    });
  });
}

// ── Mobile panel switching ────────────────────────────────────
function mobileBackToList() {
  if (window.innerWidth > 640) return;
  document.querySelector('.chat-wrap .clist-pane')?.classList.remove('hidden-mobile');
  document.querySelector('.chat-wrap .chat-pane')?.classList.remove('active-mobile');
}

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
  if (COUNSELOR_ID) startPoll();
});
window.addEventListener('online',  () => { setOnline(true); if (COUNSELOR_ID) poll(); });
window.addEventListener('offline', () => setOnline(false));
window.addEventListener('beforeunload', () => { if (pollTimer) clearTimeout(pollTimer); });
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
