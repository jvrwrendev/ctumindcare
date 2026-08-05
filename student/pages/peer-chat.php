<?php
// ============================================================
//  CTU MindCare — Peer Chat
//  File: student/pages/peer-chat.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── POLL endpoint ─────────────────────────────────────────────
if (isset($_GET['poll'])) {
    $conv_id  = (int)($_GET['conv']  ?? 0);
    $since_id = (int)($_GET['since'] ?? 0);
    header('Content-Type: application/json');
    if (!$conv_id) { echo json_encode(['messages' => []]); exit; }

    // Verify user belongs to this conversation
    $stmt = $db->prepare("SELECT id FROM peer_conversations WHERE id = ? AND (user_a = ? OR user_b = ?)");
    $stmt->execute([$conv_id, $user['id'], $user['id']]);
    if (!$stmt->fetch()) { echo json_encode(['messages' => []]); exit; }

    $stmt = $db->prepare("
        SELECT pm.id, pm.sender_id, pm.message, pm.is_read, pm.sent_at,
               u.first_name, u.last_name, u.display_name
        FROM peer_messages pm
        JOIN users u ON pm.sender_id = u.id
        WHERE pm.conversation_id = ? AND pm.id > ?
        ORDER BY pm.sent_at ASC
    ");
    $stmt->execute([$conv_id, $since_id]);
    $new_msgs = $stmt->fetchAll();

    // Mark read
    $db->prepare("
        UPDATE peer_messages SET is_read = 1
        WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$conv_id, $user['id']]);

    echo json_encode(['messages' => $new_msgs]);
    exit;
}

// ── SEND MESSAGE ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg     = trim($_POST['message'] ?? '');
    $conv_id = (int)($_POST['conv_id'] ?? 0);

    if ($msg !== '' && $conv_id) {
        // Verify membership
        $stmt = $db->prepare("SELECT id FROM peer_conversations WHERE id = ? AND (user_a = ? OR user_b = ?)");
        $stmt->execute([$conv_id, $user['id'], $user['id']]);
        if ($stmt->fetch()) {
            $stmt = $db->prepare("INSERT INTO peer_messages (conversation_id, sender_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$conv_id, $user['id'], $msg]);
            $new_id = $db->lastInsertId();

            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
                strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'ok', 'message_id' => (int)$new_id]);
                exit;
            }
        }
    }
    header('Location: peer-chat.php' . ($conv_id ? '?conv=' . $conv_id : ''));
    exit;
}

// ── START / GET conversation ──────────────────────────────────
if (isset($_GET['with'])) {
    $other_id = (int)$_GET['with'];
    if ($other_id && $other_id !== (int)$user['id']) {
        // Verify target is an active student
        $stmt = $db->prepare("SELECT id FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$other_id]);
        if ($stmt->fetch()) {
            // Ensure user_a < user_b for the UNIQUE constraint
            $ua = min((int)$user['id'], $other_id);
            $ub = max((int)$user['id'], $other_id);

            $stmt = $db->prepare("SELECT id FROM peer_conversations WHERE user_a = ? AND user_b = ?");
            $stmt->execute([$ua, $ub]);
            $conv = $stmt->fetch();

            if (!$conv) {
                $stmt = $db->prepare("INSERT INTO peer_conversations (user_a, user_b) VALUES (?, ?)");
                $stmt->execute([$ua, $ub]);
                $conv_id = $db->lastInsertId();
            } else {
                $conv_id = $conv['id'];
            }
            header('Location: peer-chat.php?conv=' . $conv_id);
            exit;
        }
    }
    header('Location: peer-chat.php');
    exit;
}

// ── Active conversation ───────────────────────────────────────
$active_conv_id = (int)($_GET['conv'] ?? 0);
$active_conv    = null;
$other_user     = null;
$messages       = [];
$last_id        = 0;

if ($active_conv_id) {
    $stmt = $db->prepare("
        SELECT pc.*, 
               ua.first_name AS a_first, ua.last_name AS a_last, ua.display_name AS a_display,
               ub.first_name AS b_first, ub.last_name AS b_last, ub.display_name AS b_display
        FROM peer_conversations pc
        JOIN users ua ON pc.user_a = ua.id
        JOIN users ub ON pc.user_b = ub.id
        WHERE pc.id = ? AND (pc.user_a = ? OR pc.user_b = ?)
    ");
    $stmt->execute([$active_conv_id, $user['id'], $user['id']]);
    $active_conv = $stmt->fetch();

    if ($active_conv) {
        $is_a = (int)$active_conv['user_a'] === (int)$user['id'];
        $other_user = [
            'id'         => $is_a ? $active_conv['user_b'] : $active_conv['user_a'],
            'first_name' => $is_a ? $active_conv['b_first'] : $active_conv['a_first'],
            'last_name'  => $is_a ? $active_conv['b_last']  : $active_conv['a_last'],
            'display_name'=> $is_a ? $active_conv['b_display']:$active_conv['a_display'],
        ];

        // Mark incoming as read
        $db->prepare("
            UPDATE peer_messages SET is_read = 1
            WHERE conversation_id = ? AND sender_id != ? AND is_read = 0
        ")->execute([$active_conv_id, $user['id']]);

        // Load messages
        $stmt = $db->prepare("
            SELECT pm.id, pm.sender_id, pm.message, pm.is_read, pm.sent_at,
                   u.first_name, u.last_name, u.display_name
            FROM peer_messages pm
            JOIN users u ON pm.sender_id = u.id
            WHERE pm.conversation_id = ?
            ORDER BY pm.sent_at ASC
        ");
        $stmt->execute([$active_conv_id]);
        $messages = $stmt->fetchAll();
        if ($messages) $last_id = (int)end($messages)['id'];
    } else {
        $active_conv_id = 0;
    }
}

// ── Load all conversations for the sidebar ────────────────────
$stmt = $db->prepare("
    SELECT pc.id,
           pc.user_a, pc.user_b,
           ua.first_name AS a_first, ua.last_name AS a_last, ua.display_name AS a_display,
           ub.first_name AS b_first, ub.last_name AS b_last, ub.display_name AS b_display,
           (SELECT message  FROM peer_messages WHERE conversation_id = pc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg,
           (SELECT sender_id FROM peer_messages WHERE conversation_id = pc.id ORDER BY sent_at DESC LIMIT 1) AS last_sender,
           (SELECT sent_at  FROM peer_messages WHERE conversation_id = pc.id ORDER BY sent_at DESC LIMIT 1) AS last_time,
           (SELECT COUNT(*) FROM peer_messages WHERE conversation_id = pc.id AND sender_id != ? AND is_read = 0) AS unread
    FROM peer_conversations pc
    JOIN users ua ON pc.user_a = ua.id
    JOIN users ub ON pc.user_b = ub.id
    WHERE pc.user_a = ? OR pc.user_b = ?
    ORDER BY last_time DESC
");
$stmt->execute([$user['id'], $user['id'], $user['id']]);
$conversations = $stmt->fetchAll();

// ── Search students to start a new chat ──────────────────────
$search_q     = trim($_GET['search'] ?? '');
$search_results = [];
if ($search_q !== '') {
    $stmt = $db->prepare("
        SELECT id, first_name, last_name, display_name, student_id
        FROM users
        WHERE is_active = 1 AND id != ?
          AND (first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ? OR display_name LIKE ?)
        LIMIT 10
    ");
    $like = '%' . $search_q . '%';
    $stmt->execute([$user['id'], $like, $like, $like, $like]);
    $search_results = $stmt->fetchAll();
}

// ── Helpers ──────────────────────────────────────────────────
function peerName(array $u): string {
    $dn = trim($u['display_name'] ?? '');
    return $dn ?: trim($u['first_name'] . ' ' . $u['last_name']);
}
function peerInitials(array $u): string {
    return strtoupper(substr($u['first_name'], 0, 1) . substr($u['last_name'], 0, 1));
}
function timeLabel(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'now';
    if ($diff < 3600)   return floor($diff / 60) . 'm';
    if ($diff < 86400)  return date('g:i A', strtotime($dt));
    if ($diff < 604800) return date('D', strtotime($dt));
    return date('M j', strtotime($dt));
}
function timeFull(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if ($diff < 86400)  return date('g:i A', strtotime($dt));
    if ($diff < 604800) return date('D g:i A', strtotime($dt));
    return date('M j, g:i A', strtotime($dt));
}

$my_initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));
$other_initials = $other_user ? peerInitials($other_user) : '';

$mc_title = 'Peer Chat — CTU MindCare';
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
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php $active_page = 'peer-chat'; ?>
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- ── Chat shell ── -->
  <div class="chat-wrap">

    <div class="chat-layout">

      <!-- Conversations pane -->
      <div class="conv-pane" id="convPane">
        <div class="cp-header">
          <div class="cp-top">
            <span class="cp-title"><i data-lucide="messages-square"></i> Peer Chat</span>
            <button class="btn-new-chat" title="Search a student to chat" onclick="toggleSearch()"><i data-lucide="user-plus"></i></button>
          </div>

          <!-- Search new peer -->
          <div class="peer-search-wrap" id="searchWrap" style="display:none">
            <form method="GET" action="peer-chat.php" id="searchForm">
              <i data-lucide="search" class="ps-icon"></i>
              <input class="peer-search-input" type="text" name="search"
                     id="searchInput"
                     placeholder="Search students by name or ID…"
                     value="<?= clean($search_q) ?>"
                     autocomplete="off"
                     oninput="debounceSearch(this.value)">
            </form>
            <div class="search-results" id="searchResults" style="<?= $search_q ? '' : 'display:none' ?>">
              <?php if ($search_q && empty($search_results)): ?>
                <div class="sr-empty">No students found for "<?= clean($search_q) ?>"</div>
              <?php elseif ($search_results): ?>
                <?php foreach ($search_results as $sr):
                  $sr_init = strtoupper(substr($sr['first_name'],0,1).substr($sr['last_name'],0,1));
                ?>
                  <a class="sr-item" href="peer-chat.php?with=<?= $sr['id'] ?>">
                    <div class="sr-av"><?= $sr_init ?></div>
                    <div style="min-width:0">
                      <div class="sr-name"><?= clean(peerName($sr)) ?></div>
                      <div class="sr-id"><?= clean($sr['student_id'] ?? '') ?></div>
                    </div>
                  </a>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Conversation list -->
        <div class="conv-list">
          <?php if (empty($conversations)): ?>
            <div class="cp-empty">
              No chats yet.<br>
              Click <strong>＋</strong> to find a student and start chatting.
            </div>
          <?php else: ?>
            <?php foreach ($conversations as $c):
              $is_a      = (int)$c['user_a'] === (int)$user['id'];
              $peer      = [
                  'first_name'   => $is_a ? $c['b_first'] : $c['a_first'],
                  'last_name'    => $is_a ? $c['b_last']  : $c['a_last'],
                  'display_name' => $is_a ? $c['b_display']:$c['a_display'],
              ];
              $peer_init = peerInitials($peer);
              $peer_nm   = peerName($peer);
              $is_active = ((int)$c['id'] === $active_conv_id);
              $unread    = (int)$c['unread'];
              $preview   = '';
              if ($c['last_msg']) {
                  $mine_prefix = (int)$c['last_sender'] === (int)$user['id'] ? 'You: ' : '';
                  $preview = $mine_prefix . mb_substr(strip_tags($c['last_msg']), 0, 30) . (mb_strlen($c['last_msg']) > 30 ? '…' : '');
              }
              // Consistent avatar color based on peer ID
              $peer_id  = $is_a ? (int)$c['user_b'] : (int)$c['user_a'];
              $av_colors = ['#C4E8DC','#EEEDFE','#FDF3DF','#FAECE7','#E0F2FE','#F3E8FF'];
              $av_bg     = $av_colors[$peer_id % count($av_colors)];
              $av_fg_map = ['#C4E8DC'=>'#2D6B57','#EEEDFE'=>'#4B3FA0','#FDF3DF'=>'#7A4F0A','#FAECE7'=>'#8B3420','#E0F2FE'=>'#0369A1','#F3E8FF'=>'#6D28D9'];
              $av_fg = $av_fg_map[$av_bg] ?? '#5C5B57';
            ?>
              <a class="convo-item <?= $is_active ? 'active' : '' ?>"
                 href="peer-chat.php?conv=<?= $c['id'] ?>">
                <div class="c-av" style="background:<?= $av_bg ?>;color:<?= $av_fg ?>">
                  <?= $peer_init ?>
                  <?php if ($unread): ?><div class="unread-dot"></div><?php endif; ?>
                </div>
                <div class="conv-info">
                  <div class="conv-name"><?= clean($peer_nm) ?></div>
                  <div class="conv-preview <?= $unread ? 'unread' : '' ?>">
                    <?= $preview ? clean($preview) : 'No messages yet' ?>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:4px;flex-shrink:0">
                  <?php if ($c['last_time']): ?>
                    <span class="conv-time"><?= timeLabel($c['last_time']) ?></span>
                  <?php endif; ?>
                  <?php if ($unread): ?>
                    <span class="conv-badge"><?= $unread ?></span>
                  <?php endif; ?>
                </div>
              </a>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <!-- Chat pane -->
      <div class="chat-pane" id="chatPane">

        <?php if (!$active_conv || !$other_user): ?>
        <!-- No conversation selected -->
        <div class="empty-chat empty-state">
          <div class="es-icon chat-big"><i data-lucide="messages-square"></i></div>
          <div class="es-title">Peer Chat</div>
          <div class="es-sub">
            Connect privately with fellow students for mutual support.
            Select a conversation or start a new one.
          </div>
          <div class="ep-tip" style="margin-top:14px;font-size:12px;color:var(--text-3);background:var(--surface-2);border-radius:10px;padding:10px 14px;line-height:1.6;max-width:300px">
            Click <strong>＋</strong> in the left panel to search for a student and start chatting.
          </div>
        </div>

        <?php else: ?>

        <!-- Safety banner -->
        <div class="safety-banner">
          <i data-lucide="leaf"></i> Be kind and supportive &nbsp;·&nbsp; <i data-lucide="lock"></i> Messages are private &nbsp;·&nbsp;
          <a href="crisis.php"><i data-lucide="phone-call"></i> Crisis help</a>
        </div>

        <!-- Chat header -->
        <div class="chat-header">
          <button class="ch-back-btn" id="backToConvs" onclick="mobileShowConvs()" title="Back to conversations"><i data-lucide="arrow-left"></i></button>
          <?php
            $av_colors = ['#C4E8DC','#EEEDFE','#FDF3DF','#FAECE7','#E0F2FE','#F3E8FF'];
            $av_fg_map = ['#C4E8DC'=>'#2D6B57','#EEEDFE'=>'#4B3FA0','#FDF3DF'=>'#7A4F0A','#FAECE7'=>'#8B3420','#E0F2FE'=>'#0369A1','#F3E8FF'=>'#6D28D9'];
            $h_bg = $av_colors[$other_user['id'] % count($av_colors)];
            $h_fg = $av_fg_map[$h_bg] ?? '#5C5B57';
          ?>
          <div class="ch-av" style="background:<?= $h_bg ?>;color:<?= $h_fg ?>"><?= $other_initials ?></div>
          <div style="min-width:0">
            <div class="chat-head-name"><?= clean(peerName($other_user)) ?></div>
            <div class="chat-head-sub">Student &nbsp;·&nbsp; <i data-lucide="lock"></i> Private conversation</div>
          </div>
          <div class="ch-actions">
            <a class="ch-btn" href="forum.php"><i data-lucide="messages-square"></i> Forum</a>
            <a class="ch-btn ch-crisis" href="crisis.php"><i data-lucide="phone-call"></i> Crisis</a>
          </div>
        </div>

        <!-- Messages -->
        <div class="chat-messages" id="chatMessages">

          <?php if (empty($messages)): ?>
            <div class="no-msgs-state" id="emptyState">
              <div class="no-msgs-icon"><i data-lucide="hand"></i></div>
              <div class="no-msgs-title">Say hello!</div>
              <div class="no-msgs-sub">
                You haven't chatted with <?= clean(peerName($other_user)) ?> yet.
                Send a message to start the conversation.
              </div>
            </div>
          <?php else: ?>
            <div class="sys-msg" id="sysMsg">
              <i data-lucide="messages-square"></i> Private conversation with <?= clean(peerName($other_user)) ?>
            </div>
            <?php
            $prev_date   = '';
            $prev_sender = -1;
            foreach ($messages as $i => $msg):
              $is_mine     = (int)$msg['sender_id'] === (int)$user['id'];
              $msg_date    = date('Y-m-d', strtotime($msg['sent_at']));
              $new_group   = ($prev_sender !== (int)$msg['sender_id']);

              if ($msg_date !== $prev_date):
                $dlabel = $msg_date === date('Y-m-d') ? 'Today'
                        : ($msg_date === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday'
                        : date('F j, Y', strtotime($msg_date)));
            ?>
              <div class="date-sep"><span><?= $dlabel ?></span></div>
            <?php
                $prev_date = $msg_date;
              endif;

              $next      = $messages[$i+1] ?? null;
              $show_time = !$next || (int)$next['sender_id'] !== (int)$msg['sender_id'];
              $row_cls   = 'msg-row ' . ($is_mine ? 'mine' : 'theirs') . ($new_group ? ' new-group' : ' same-sender');
              $av_cls    = $is_mine ? 'is-mine' : 'is-theirs';
              $av_init   = $is_mine ? $my_initials : $other_initials;
              $prev_sender = (int)$msg['sender_id'];
            ?>
              <div class="<?= $row_cls ?>" data-id="<?= (int)$msg['id'] ?>">
                <div class="bub-av <?= $av_cls ?> <?= !$new_group ? 'hidden-av' : '' ?>"
                     <?php if (!$is_mine): ?>style="background:<?= $h_bg ?>;color:<?= $h_fg ?>"<?php endif; ?>>
                  <?= $av_init ?>
                </div>
                <div class="bub-wrap">
                  <div class="bubble"><?= nl2br(clean($msg['message'])) ?></div>
                  <?php if ($show_time): ?>
                    <div class="msg-time"><?= timeFull($msg['sent_at']) ?>
                      <?php if ($is_mine): ?>
                        <span class="<?= $msg['is_read'] ? 'tick-read' : 'tick-sent' ?>" title="<?= $msg['is_read'] ? 'Read' : 'Sent' ?>"><?= $msg['is_read'] ? '✓✓' : '✓' ?></span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>

          <!-- Typing indicator -->
          <div class="typing-row" id="typingRow">
            <div class="bub-av is-theirs" style="background:<?= $h_bg ?? 'var(--surface-3)' ?>;color:<?= $h_fg ?? 'var(--text-2)' ?>"><?= $other_initials ?></div>
            <div class="typing-bubble">
              <div class="t-dot"></div><div class="t-dot"></div><div class="t-dot"></div>
            </div>
          </div>

        </div><!-- .chat-messages -->

        <!-- Input -->
        <div class="chat-input-area">
          <div class="input-row">
            <textarea
              class="msg-textarea"
              id="msgInput"
              placeholder="Message <?= clean(peerName($other_user)) ?>…"
              rows="1"
              maxlength="2000"
              onkeydown="handleKey(event)"
              oninput="onInput(this)"
            ></textarea>
            <button class="send-btn" id="sendBtn" disabled title="Send" onclick="sendMessage()">
              <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
            </button>
          </div>
          <div class="input-hint">Enter to send &nbsp;·&nbsp; Shift+Enter for new line &nbsp;·&nbsp; <i data-lucide="lock" style="width:10px;height:10px;vertical-align:-1px"></i> Private</div>
        </div>

        <?php endif; ?>

      </div><!-- .chat-pane -->

    </div><!-- .chat-layout -->
  </div><!-- .chat-wrap -->
</div><!-- .layout -->

<script>
const CONV_ID    = <?= (int)$active_conv_id ?>;
const MY_ID      = <?= (int)$user['id'] ?>;
const MY_INIT    = '<?= $my_initials ?>';
const OTHER_INIT = '<?= $other_initials ?>';
const OTHER_BG   = '<?= $h_bg ?? 'var(--surface-3)' ?>';
const OTHER_FG   = '<?= $h_fg ?? 'var(--text-2)' ?>';
let   lastId     = <?= $last_id ?>;
let   pollTimer  = null;
let   sending    = false;

// ── Textarea auto-resize ──────────────────────────────────────
function onInput(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(120, el.scrollHeight) + 'px';
  const btn = document.getElementById('sendBtn');
  if (btn) btn.disabled = el.value.trim() === '';
}

// ── Enter key ─────────────────────────────────────────────────
function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    const btn = document.getElementById('sendBtn');
    if (btn && !btn.disabled) sendMessage();
  }
}

// ── Send ──────────────────────────────────────────────────────
async function sendMessage() {
  if (sending || !CONV_ID) return;
  const inp = document.getElementById('msgInput');
  if (!inp) return;
  const txt = inp.value.trim();
  if (!txt) return;

  sending = true;
  const btn = document.getElementById('sendBtn');
  if (btn) btn.disabled = true;

  addBubble({ id: 'tmp_' + Date.now(), sender_id: MY_ID, message: txt, sent_at: new Date().toISOString() }, true);
  inp.value = '';
  inp.style.height = 'auto';
  scrollBottom();

  try {
    const fd = new FormData();
    fd.append('message', txt);
    fd.append('conv_id', CONV_ID);
    const res  = await fetch(location.pathname + '?conv=' + CONV_ID, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd,
    });
    const data = await res.json();
    const tmp  = document.querySelector('[data-id^="tmp_"]');
    if (tmp && data.message_id) {
      tmp.dataset.id = data.message_id;
      lastId = Math.max(lastId, data.message_id);
    }
  } catch(e) { console.error(e); }

  sending = false;
  if (btn) btn.disabled = inp.value.trim() === '';
}

// ── Append bubble ─────────────────────────────────────────────
function addBubble(msg, isMine) {
  const wrap = document.getElementById('chatMessages');
  if (!wrap) return;

  const es = document.getElementById('emptyState');
  if (es) es.remove();
  if (!document.getElementById('sysMsg')) {
    const s = document.createElement('div');
    s.id = 'sysMsg'; s.className = 'sys-msg';
    s.innerHTML = '<i data-lucide="messages-square"></i> Private conversation';
    wrap.insertBefore(s, wrap.firstChild);
  }

  const rows      = wrap.querySelectorAll('.msg-row');
  const lastRow   = rows.length ? rows[rows.length - 1] : null;
  const newGroup  = !lastRow || (isMine ? !lastRow.classList.contains('mine') : !lastRow.classList.contains('theirs'));

  const row = document.createElement('div');
  row.className = 'msg-row ' + (isMine ? 'mine' : 'theirs') + (newGroup ? ' new-group' : ' same-sender');
  row.dataset.id = msg.id;

  const text  = String(msg.message).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
  const avInit= isMine ? MY_INIT : OTHER_INIT;
  const avCls = isMine ? 'is-mine' : 'is-theirs';
  const avStyle = isMine ? '' : `style="background:${OTHER_BG};color:${OTHER_FG}"`;
  const hidAv  = newGroup ? '' : 'hidden-av';
  const readMark = isMine
    ? `<span class="${msg.is_read ? 'tick-read' : 'tick-sent'}" title="${msg.is_read ? 'Read' : 'Sent'}">${msg.is_read ? '✓✓' : '✓'}</span>`
    : '';

  row.innerHTML = `
    <div class="bub-av ${avCls} ${hidAv}" ${avStyle}>${avInit}</div>
    <div class="bub-wrap">
      <div class="bubble">${text}</div>
      <div class="msg-time">${fmtTime(msg.sent_at)} ${readMark}</div>
    </div>
  `;

  const typing = document.getElementById('typingRow');
  wrap.insertBefore(row, typing);
}

// ── Poll every 3s ─────────────────────────────────────────────
function startPoll() {
  if (!CONV_ID) return;
  pollTimer = setInterval(async () => {
    try {
      const res  = await fetch(`peer-chat.php?poll=1&conv=${CONV_ID}&since=${lastId}`);
      const data = await res.json();
      if (data.messages?.length) {
        data.messages.forEach(m => {
          const id = parseInt(m.id, 10);
          if (parseInt(m.sender_id, 10) === MY_ID) {
            const existing = document.querySelector(`[data-id="${id}"]`);
            if (existing) {
              const tick = existing.querySelector('.tick-sent');
              if (tick && m.is_read) {
                tick.className = 'tick-read';
                tick.textContent = '✓✓';
              }
            } else {
              addBubble(m, true);
              scrollBottom();
            }
          } else {
            showTyping();
            setTimeout(() => {
              hideTyping();
              addBubble(m, false);
              scrollBottom();
            }, 500);
          }
          lastId = Math.max(lastId, id);
        });
      }
    } catch(e) { /* silent */ }
  }, 3000);
}

function showTyping() { document.getElementById('typingRow')?.classList.add('show'); scrollBottom(); }
function hideTyping()  { document.getElementById('typingRow')?.classList.remove('show'); }

// ── Scroll ────────────────────────────────────────────────────
function scrollBottom(smooth = true) {
  const el = document.getElementById('chatMessages');
  if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth ? 'smooth' : 'instant' });
}

// ── Time ──────────────────────────────────────────────────────
function fmtTime(iso) {
  const d = new Date(iso), diff = (Date.now() - d) / 1000;
  if (diff < 60)    return 'just now';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
  return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

// ── Search toggle ─────────────────────────────────────────────
function toggleSearch() {
  const wrap = document.getElementById('searchWrap');
  const inp  = document.getElementById('searchInput');
  if (!wrap) return;
  if (wrap.style.display === 'none') {
    wrap.style.display = 'block';
    setTimeout(() => inp?.focus(), 50);
  } else {
    wrap.style.display = 'none';
    const res = document.getElementById('searchResults');
    if (res) res.style.display = 'none';
  }
}

// ── Debounced AJAX search ─────────────────────────────────────
let searchTimer = null;
function debounceSearch(val) {
  clearTimeout(searchTimer);
  const results = document.getElementById('searchResults');
  if (!results) return;
  if (!val.trim()) { results.style.display = 'none'; return; }
  searchTimer = setTimeout(async () => {
    try {
      const res  = await fetch(`peer-chat.php?search=${encodeURIComponent(val)}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const html = await res.text();
      // Extract just the results div contents
      const parser = new DOMParser();
      const doc    = parser.parseFromString(html, 'text/html');
      const inner  = doc.getElementById('searchResults');
      if (inner) {
        results.innerHTML = inner.innerHTML;
        results.style.display = inner.innerHTML.trim() ? 'block' : 'none';
      }
    } catch(e) { /* silent */ }
  }, 300);
}

// ── Close search results on outside click ─────────────────────
document.addEventListener('click', e => {
  const wrap = document.getElementById('searchWrap');
  if (wrap && !wrap.contains(e.target) && !e.target.closest('.btn-new-chat')) {
    const res = document.getElementById('searchResults');
    if (res) res.style.display = 'none';
  }
});

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

// Make conv items trigger mobile panel switch
document.querySelectorAll('.convo-item').forEach(function(item) {
  item.addEventListener('click', function() {
    if (window.innerWidth <= 640) mobileShowChat();
  });
});

// When a search result is clicked, it navigates — that's fine
// but if it stays on same page (same conv), show chat panel
document.querySelectorAll('.sr-item').forEach(function(item) {
  item.addEventListener('click', function() {
    if (window.innerWidth <= 640) {
      setTimeout(mobileShowChat, 100);
    }
  });
});

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  scrollBottom(false);
  const inp = document.getElementById('msgInput');
  if (inp) inp.focus();
  if (CONV_ID) startPoll();

  // On mobile: if a conversation is active, show the chat panel
  if (CONV_ID && window.innerWidth <= 640) {
    mobileShowChat();
  }

  // Show search if search was active
  <?php if ($search_q): ?>
  document.getElementById('searchWrap').style.display = 'block';
  const sr = document.getElementById('searchResults');
  if (sr) sr.style.display = 'block';
  <?php endif; ?>
});
window.addEventListener('beforeunload', () => { if (pollTimer) clearInterval(pollTimer); });
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
