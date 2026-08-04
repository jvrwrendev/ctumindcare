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
        SELECT pm.id, pm.sender_id, pm.message, pm.sent_at,
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Peer Chat — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    :root {
      --sage:       #4A9B7F; --sage-2:    #3d8a6f; --sage-dark: #2D6B57;
      --sage-mid:   #C4E8DC; --sage-light:#EAF7F2;
      --sand:       #F7F3EE; --sand-2:    #EDE8E1; --sand-3:    #D9D3C9;
      --white:      #FFFFFF; --text:      #2C2C2A; --text-2:    #5C5B57;
      --text-3:     #9A9891; --border:    #E8E4DE;
      --coral:      #D96B52; --coral-light:#FAECE7;
      --lav:        #7B6FC4; --lav-light: #EEEDFE;
      --topbar-h:   58px;    --sidebar-w: 230px;  --cpane-w: 280px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      height: 100%; overflow: hidden;
      font-family: 'DM Sans', sans-serif; font-size: 14px;
      color: var(--text); background: var(--sand);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }

    /* ── Topbar ── */
    .topbar {
      height: var(--topbar-h); background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px;
      position: fixed; top: 0; left: 0; right: 0; z-index: 300; gap: 16px;
    }
    .topbar-brand  { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark   { width: 34px; height: 34px; background: var(--sage); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; }
    .topbar-name   { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
    .topbar-school { font-size: 10px; color: var(--text-3); margin-top: 1px; }
    .topbar-right  { display: flex; align-items: center; gap: 12px; }
    .user-chip     { display: flex; align-items: center; gap: 8px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 100px; padding: 5px 14px 5px 6px; }
    .user-av-sm    { width: 26px; height: 26px; border-radius: 50%; background: var(--sage); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; }
    .user-name     { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
    .logout-btn    { font-size: 12px; color: var(--text-3); padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: color .15s, border-color .15s; }
    .logout-btn:hover { color: var(--coral); border-color: var(--coral); }

    /* ── Shell ── */
    .shell {
      display: grid;
      grid-template-columns: var(--sidebar-w) var(--cpane-w) 1fr;
      height: calc(100vh - var(--topbar-h));
      margin-top: var(--topbar-h);
      overflow: hidden;
    }

    /* ── Nav sidebar ── */
    .sidebar {
      background: var(--white); border-right: 1px solid var(--border);
      padding: 20px 0; overflow-y: auto;
      display: flex; flex-direction: column;
    }
    .sb-section { padding: 0 14px; margin-bottom: 6px; }
    .sb-label   { font-size: 9px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; padding: 0 8px; margin-bottom: 4px; display: block; }
    .nav-item   { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .nav-item:hover  { background: var(--sand); color: var(--text); }
    .nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .nav-icon   { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: var(--sand); }
    .nav-item.active .nav-icon { background: var(--sage-mid); }
    .nav-item.crisis-nav { color: var(--coral); }
    .nav-item.crisis-nav:hover { background: var(--coral-light); }
    .nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
    .sb-divider { height: 1px; background: var(--border); margin: 10px 14px; }
    .sb-footer  { margin-top: auto; padding: 14px; }
    .sb-user-card { background: var(--sand); border-radius: 10px; padding: 12px; display: flex; align-items: center; gap: 10px; }
    .su-av  { width: 32px; height: 32px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .su-name{ font-size: 12px; font-weight: 500; color: var(--text); }
    .su-id  { font-size: 10px; color: var(--text-3); margin-top: 1px; }

    /* ── Conversations pane ── */
    .conv-pane {
      background: var(--white); border-right: 1px solid var(--border);
      display: flex; flex-direction: column; overflow: hidden;
    }
    .cp-header  { padding: 14px 14px 10px; border-bottom: 1px solid var(--border); flex-shrink: 0; }
    .cp-top     { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .cp-title   { font-size: 13px; font-weight: 600; color: var(--text); }
    .btn-new-chat {
      width: 28px; height: 28px; border-radius: 7px; background: var(--sage);
      border: none; color: #fff; font-size: 17px; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      transition: background .15s; flex-shrink: 0;
    }
    .btn-new-chat:hover { background: var(--sage-2); }

    /* Search new peer */
    .peer-search-wrap { position: relative; }
    .ps-icon { position: absolute; left: 9px; top: 50%; transform: translateY(-50%); font-size: 12px; color: var(--text-3); pointer-events: none; }
    .peer-search-input {
      width: 100%; padding: 7px 9px 7px 28px;
      background: var(--sand); border: 1.5px solid var(--border);
      border-radius: 8px; font-family: 'DM Sans', sans-serif;
      font-size: 12px; color: var(--text); outline: none;
      transition: border-color .15s, background .15s;
    }
    .peer-search-input:focus { background: var(--white); border-color: var(--sage); }
    .peer-search-input::placeholder { color: var(--text-3); }

    /* Search results dropdown */
    .search-results {
      position: absolute; left: 0; right: 0; top: calc(100% + 4px);
      background: var(--white); border: 1px solid var(--border);
      border-radius: 10px; box-shadow: 0 8px 24px rgba(0,0,0,.1);
      z-index: 50; overflow: hidden; max-height: 240px; overflow-y: auto;
    }
    .sr-item {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 12px; cursor: pointer; transition: background .1s;
      text-decoration: none;
    }
    .sr-item:hover { background: var(--sage-light); }
    .sr-av   { width: 32px; height: 32px; border-radius: 50%; background: var(--lav-light); color: var(--lav); font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .sr-name { font-size: 13px; font-weight: 500; color: var(--text); }
    .sr-id   { font-size: 11px; color: var(--text-3); }
    .sr-empty { padding: 14px 12px; font-size: 12px; color: var(--text-3); text-align: center; }

    /* Conversations list */
    .conv-list { flex: 1; overflow-y: auto; }
    .conv-item {
      display: flex; align-items: center; gap: 10px;
      padding: 11px 14px; cursor: pointer;
      transition: background .12s; border-bottom: 1px solid var(--border);
      text-decoration: none;
    }
    .conv-item:hover  { background: var(--sand); }
    .conv-item.active { background: var(--sage-light); }
    .conv-av   { width: 40px; height: 40px; border-radius: 50%; background: var(--lav-light); color: var(--lav); font-size: 14px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; position: relative; }
    .conv-av-colors {
      --colors: #C4E8DC #EEEDFE #FDF3DF #FAECE7 #E0F2FE #F3E8FF;
    }
    .unread-dot { position: absolute; top: 0; right: 0; width: 10px; height: 10px; border-radius: 50%; background: var(--coral); border: 2px solid var(--white); }
    .conv-info  { flex: 1; min-width: 0; }
    .conv-name  { font-size: 13px; font-weight: 500; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .conv-preview { font-size: 11px; color: var(--text-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
    .conv-preview.unread { font-weight: 600; color: var(--text-2); }
    .conv-time  { font-size: 10px; color: var(--text-3); flex-shrink: 0; }
    .conv-badge { min-width: 18px; height: 18px; border-radius: 9px; background: var(--coral); color: #fff; font-size: 10px; font-weight: 600; display: flex; align-items: center; justify-content: center; padding: 0 4px; flex-shrink: 0; }
    .cp-empty   { padding: 32px 16px; text-align: center; font-size: 12px; color: var(--text-3); line-height: 1.7; }

    /* ── Chat pane ── */
    .chat-pane { display: flex; flex-direction: column; overflow: hidden; background: var(--sand); }

    .ch-back-btn { display: none; width: 34px; height: 34px; border-radius: 8px; border: 1px solid var(--border); background: var(--white); align-items: center; justify-content: center; font-size: 18px; cursor: pointer; flex-shrink: 0; transition: background .12s; color: var(--text-2); }
    .ch-back-btn:hover { background: var(--sand); }

    /* Chat header */
    .chat-header { background: var(--white); border-bottom: 1px solid var(--border); padding: 13px 20px; display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
    .ch-av   { width: 38px; height: 38px; border-radius: 50%; background: var(--lav-light); color: var(--lav); font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ch-name { font-size: 14px; font-weight: 600; color: var(--text); line-height: 1.2; }
    .ch-sub  { font-size: 11px; color: var(--text-3); margin-top: 2px; }
    .ch-actions { margin-left: auto; display: flex; gap: 8px; }
    .ch-btn  { padding: 6px 13px; border: 1px solid var(--border); border-radius: 8px; background: var(--white); font-family: 'DM Sans', sans-serif; font-size: 12px; color: var(--text-2); cursor: pointer; transition: all .12s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
    .ch-btn:hover { border-color: var(--sage); color: var(--sage); }

    /* Messages */
    .chat-messages { flex: 1; overflow-y: auto; padding: 20px; display: flex; flex-direction: column; gap: 2px; scroll-behavior: smooth; }

    .date-sep { display: flex; align-items: center; gap: 10px; font-size: 11px; color: var(--text-3); margin: 12px 0 8px; flex-shrink: 0; }
    .date-sep::before, .date-sep::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    .sys-msg { text-align: center; font-size: 11px; color: var(--text-3); padding: 5px 14px; background: var(--sand-2); border-radius: 20px; margin: 6px auto 14px; max-width: 320px; flex-shrink: 0; }

    .msg-row { display: flex; align-items: flex-end; gap: 8px; flex-shrink: 0; }
    .msg-row.mine   { flex-direction: row-reverse; }
    .msg-row + .msg-row { margin-top: 2px; }
    .msg-row.new-group  { margin-top: 10px; }

    .bub-av { width: 28px; height: 28px; border-radius: 50%; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .bub-av.is-mine   { background: var(--sage); color: #fff; }
    .bub-av.is-theirs { background: var(--lav-light); color: var(--lav); }
    .bub-av.hidden-av { visibility: hidden; }

    .bub-wrap { display: flex; flex-direction: column; max-width: 65%; }
    .msg-row.mine .bub-wrap { align-items: flex-end; }

    .bubble { padding: 10px 14px; border-radius: 18px; font-size: 13px; line-height: 1.65; word-break: break-word; }
    .msg-row.mine   .bubble { background: var(--sage); color: #fff; border-bottom-right-radius: 5px; }
    .msg-row.theirs .bubble { background: var(--white); color: var(--text); border: 1px solid var(--border); border-bottom-left-radius: 5px; }
    .msg-row.mine.new-group   .bubble { border-top-right-radius: 18px; }
    .msg-row.theirs.new-group .bubble { border-top-left-radius: 18px; }

    .msg-time { font-size: 10px; color: var(--text-3); margin-top: 3px; padding: 0 2px; }
    .msg-row.mine .msg-time { text-align: right; }

    /* Typing dots */
    .typing-row { display: none; flex-direction: row; align-items: flex-end; gap: 8px; margin-top: 10px; flex-shrink: 0; }
    .typing-row.show { display: flex; }
    .typing-dots { background: var(--white); border: 1px solid var(--border); padding: 11px 14px; border-radius: 18px; border-bottom-left-radius: 5px; display: flex; gap: 4px; align-items: center; }
    .t-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--sand-3); animation: tdot 1.2s ease-in-out infinite; }
    .t-dot:nth-child(2) { animation-delay: .18s; }
    .t-dot:nth-child(3) { animation-delay: .36s; }
    @keyframes tdot { 0%,60%,100% { transform:translateY(0);background:var(--sand-3); } 30% { transform:translateY(-5px);background:var(--lav); } }

    /* Input area */
    .chat-input-area { background: var(--white); border-top: 1px solid var(--border); padding: 13px 16px; flex-shrink: 0; }
    .input-row { display: flex; align-items: flex-end; gap: 10px; }
    .msg-textarea {
      flex: 1; padding: 10px 14px;
      background: var(--sand); border: 1.5px solid var(--border);
      border-radius: 22px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; color: var(--text); outline: none;
      resize: none; min-height: 42px; max-height: 120px;
      line-height: 1.5; overflow-y: hidden;
      transition: border-color .15s, box-shadow .15s, background .15s;
    }
    .msg-textarea:focus { background: var(--white); border-color: var(--sage); box-shadow: 0 0 0 3px rgba(74,155,127,.08); }
    .msg-textarea::placeholder { color: var(--text-3); }
    .send-btn {
      width: 42px; height: 42px; border-radius: 50%; background: var(--sage);
      border: none; cursor: pointer; font-size: 17px; color: #fff;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; transition: background .15s, transform .1s;
    }
    .send-btn:hover  { background: var(--sage-2); }
    .send-btn:active { transform: scale(.92); }
    .send-btn:disabled { background: var(--sand-3); cursor: not-allowed; }
    .input-hint { font-size: 11px; color: var(--text-3); margin-top: 6px; }

    /* Empty states */
    .empty-pane { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 40px; }
    .ep-icon    { font-size: 52px; margin-bottom: 16px; }
    .ep-title   { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--text); margin-bottom: 8px; }
    .ep-sub     { font-size: 13px; color: var(--text-3); line-height: 1.7; max-width: 300px; }
    .ep-tip     { margin-top: 14px; font-size: 12px; color: var(--text-3); background: var(--sand-2); border-radius: 10px; padding: 10px 14px; line-height: 1.6; max-width: 300px; }

    .no-msgs      { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 40px; }
    .no-msgs-icon { font-size: 44px; margin-bottom: 12px; }
    .no-msgs-title{ font-size: 15px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
    .no-msgs-sub  { font-size: 13px; color: var(--text-3); line-height: 1.6; max-width: 260px; }

    /* Safety banner */
    .safety-banner { background: var(--sage-light); border-bottom: 1px solid var(--sage-mid); padding: 8px 20px; font-size: 11px; color: var(--sage-dark); display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

    @media (max-width: 900px) {
      .shell { grid-template-columns: var(--cpane-w) 1fr; }
    }
    @media (max-width: 900px) {
      .hamburger   { display: flex; }
      .layout      { grid-template-columns: 1fr; }
      .sidebar     { position: fixed; top: 58px; left: 0; bottom: 0; z-index: 300; transform: translateX(-100%); transition: transform .28s ease; height: calc(100vh - 58px); width: 270px; box-shadow: 4px 0 24px rgba(0,0,0,.12); overflow-y: auto; }
      .sidebar.mobile-open { transform: translateX(0); }
      .main        { padding: 20px 16px; }
    }

    /* ── Full mobile chat experience ── */
    @media (max-width: 600px) {
      .shell { grid-template-columns: 1fr; position: relative; }

      /* Conv-pane: full screen, shown by default */
      .conv-pane {
        position: fixed; inset: 58px 0 0 0; z-index: 10;
        width: 100%; transition: transform .3s ease; transform: translateX(0);
      }
      .conv-pane.hidden-mobile { transform: translateX(-100%); pointer-events: none; }

      /* Chat-pane: starts off-screen to the right, non-interactive */
      .chat-pane {
        position: fixed; inset: 58px 0 0 0; z-index: 10;
        width: 100%; transform: translateX(100%); transition: transform .3s ease;
        pointer-events: none;
      }
      .chat-pane.active-mobile { transform: translateX(0); pointer-events: all; }

      /* Back button visible on mobile */
      .ch-back-btn { display: flex !important; }

      /* Hide non-essential header buttons */
      .ch-actions .ch-btn:not(.ch-crisis) { display: none; }

      /* Prevent iOS zoom on input focus */
      .msg-textarea { font-size: 16px; }
      .send-btn { width: 46px; height: 46px; font-size: 20px; }

      .conv-item { padding: 14px; }
      .safety-banner { font-size: 10px; padding: 6px 14px; }
    }
  
/* ── Hamburger button ── */
.hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand,#F7F3EE);border:1px solid var(--border,#E8E4DE);cursor:pointer;padding:0;flex-shrink:0;}
.hamburger span{display:block;width:18px;height:2px;background:var(--text-2,#5C5B57);border-radius:2px;transition:all .25s ease;}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}

/* ── Sidebar overlay ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex;}
  .layout{grid-template-columns:1fr;}
  .sidebar{position:fixed;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px);width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);}
  .sidebar.mobile-open{transform:translateX(0);}
  .main{padding:20px 16px;}
}

</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- Topbar -->
<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
  <div class="topbar-brand">
    <div class="topbar-mark">M</div>
    <div>
      <div class="topbar-name">CTU MindCare</div>
      <div class="topbar-school">CTU Consolacion</div>
    </div>
  </div>
  <div class="topbar-right">
    <a class="user-chip" href="profile.php">
      <div class="user-av-sm"><?= $my_initials ?></div>
      <span class="user-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="shell">

  <!-- ── Nav sidebar ── -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
      <a class="nav-item" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
      <a class="nav-item" href="journal.php"><div class="nav-icon">📓</div> Wellness journal</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Support</span>
      <a class="nav-item" href="appointments.php"><div class="nav-icon">📅</div> Book appointment</a>
      <a class="nav-item" href="counselor-chat.php"><div class="nav-icon">💬</div> Counselor chat</a>
      <a class="nav-item" href="resources.php"><div class="nav-icon">📚</div> Resource library</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Community</span>
      <a class="nav-item" href="forum.php"><div class="nav-icon">🗣️</div> Peer forum</a>
      <a class="nav-item active" href="peer-chat.php"><div class="nav-icon">💭</div> Peer chat</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
    </div>
    <div class="sb-footer">
      <div class="sb-user-card">
        <div class="su-av"><?= $my_initials ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Conversations pane ── -->
  <div class="conv-pane">
    <div class="cp-header">
      <div class="cp-top">
        <span class="cp-title">💭 Peer Chat</span>
        <button class="btn-new-chat" title="Search a student to chat" onclick="toggleSearch()">＋</button>
      </div>

      <!-- Search new peer -->
      <div class="peer-search-wrap" id="searchWrap" style="display:none">
        <form method="GET" action="peer-chat.php" id="searchForm">
          <span class="ps-icon">🔍</span>
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
                <div>
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
          <a class="conv-item <?= $is_active ? 'active' : '' ?>"
             href="peer-chat.php?conv=<?= $c['id'] ?>">
            <div class="conv-av" style="background:<?= $av_bg ?>;color:<?= $av_fg ?>">
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

  <!-- ── Chat pane ── -->
  <div class="chat-pane">

    <?php if (!$active_conv || !$other_user): ?>
    <!-- No conversation selected -->
    <div class="empty-pane">
      <div class="ep-icon">💭</div>
      <div class="ep-title">Peer Chat</div>
      <div class="ep-sub">
        Connect privately with fellow students for mutual support.
        Select a conversation or start a new one.
      </div>
      <div class="ep-tip">
        🔍 Click <strong>＋</strong> in the left panel to search for a student and start chatting.
      </div>
    </div>

    <?php else: ?>

    <!-- Safety banner -->
    <div class="safety-banner">
      🌿 Be kind and supportive &nbsp;·&nbsp; 🔒 Messages are private &nbsp;·&nbsp;
      <a href="crisis.php" style="color:var(--coral);font-weight:500;margin-left:4px">🆘 Crisis help</a>
    </div>

    <!-- Chat header -->
    <div class="chat-header">
      <button class="ch-back-btn" id="backToConvs" onclick="mobileShowConvs()" title="Back to conversations">&#8592;</button>
      <?php
        $av_colors = ['#C4E8DC','#EEEDFE','#FDF3DF','#FAECE7','#E0F2FE','#F3E8FF'];
        $av_fg_map = ['#C4E8DC'=>'#2D6B57','#EEEDFE'=>'#4B3FA0','#FDF3DF'=>'#7A4F0A','#FAECE7'=>'#8B3420','#E0F2FE'=>'#0369A1','#F3E8FF'=>'#6D28D9'];
        $h_bg = $av_colors[$other_user['id'] % count($av_colors)];
        $h_fg = $av_fg_map[$h_bg] ?? '#5C5B57';
      ?>
      <div class="ch-av" style="background:<?= $h_bg ?>;color:<?= $h_fg ?>"><?= $other_initials ?></div>
      <div>
        <div class="ch-name"><?= clean(peerName($other_user)) ?></div>
        <div class="ch-sub">Student &nbsp;·&nbsp; 🔒 Private conversation</div>
      </div>
      <div class="ch-actions">
        <a class="ch-btn" href="forum.php">🗣 Forum</a>
        <a class="ch-btn ch-crisis" href="crisis.php" style="color:var(--coral);border-color:#F5C6C2">🆘 Crisis</a>
      </div>
    </div>

    <!-- Messages -->
    <div class="chat-messages" id="chatMessages">

      <?php if (empty($messages)): ?>
        <div class="no-msgs" id="emptyState">
          <div class="no-msgs-icon">👋</div>
          <div class="no-msgs-title">Say hello!</div>
          <div class="no-msgs-sub">
            You haven't chatted with <?= clean(peerName($other_user)) ?> yet.
            Send a message to start the conversation.
          </div>
        </div>
      <?php else: ?>
        <div class="sys-msg" id="sysMsg">
          💭 Private conversation with <?= clean(peerName($other_user)) ?>
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
          <div class="date-sep"><?= $dlabel ?></div>
        <?php
            $prev_date = $msg_date;
          endif;

          $next      = $messages[$i+1] ?? null;
          $show_time = !$next || (int)$next['sender_id'] !== (int)$msg['sender_id'];
          $row_cls   = 'msg-row ' . ($is_mine ? 'mine' : 'theirs') . ($new_group ? ' new-group' : '');
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
                <div class="msg-time"><?= timeFull($msg['sent_at']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Typing indicator -->
      <div class="typing-row" id="typingRow">
        <div class="bub-av is-theirs" style="background:<?= $h_bg ?? 'var(--lav-light)' ?>;color:<?= $h_fg ?? 'var(--lav)' ?>"><?= $other_initials ?></div>
        <div class="typing-dots">
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
        <button class="send-btn" id="sendBtn" disabled title="Send" onclick="sendMessage()">➤</button>
      </div>
      <div class="input-hint">Enter to send &nbsp;·&nbsp; Shift+Enter for new line &nbsp;·&nbsp; 🔒 Private</div>
    </div>

    <?php endif; ?>

  </div><!-- .chat-pane -->
</div><!-- .shell -->

<script>
const CONV_ID    = <?= (int)$active_conv_id ?>;
const MY_ID      = <?= (int)$user['id'] ?>;
const MY_INIT    = '<?= $my_initials ?>';
const OTHER_INIT = '<?= $other_initials ?>';
const OTHER_BG   = '<?= $h_bg ?? 'var(--lav-light)' ?>';
const OTHER_FG   = '<?= $h_fg ?? 'var(--lav)' ?>';
let   lastId     = <?= $last_id ?>;
let   pollTimer  = null;
let   sending    = false;

// ── Textarea auto-resize ──────────────────────────────────────
function onInput(el) {
  el.style.height = 'auto';
  el.style.height = Math.min(120, el.scrollHeight) + 'px';
  document.getElementById('sendBtn').disabled = el.value.trim() === '';
}

// ── Enter key ─────────────────────────────────────────────────
function handleKey(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    if (!document.getElementById('sendBtn').disabled) sendMessage();
  }
}

// ── Send ──────────────────────────────────────────────────────
async function sendMessage() {
  if (sending || !CONV_ID) return;
  const inp = document.getElementById('msgInput');
  const txt = inp.value.trim();
  if (!txt) return;

  sending = true;
  document.getElementById('sendBtn').disabled = true;

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
  document.getElementById('sendBtn').disabled = inp.value.trim() === '';
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
    s.textContent = '💭 Private conversation';
    wrap.insertBefore(s, wrap.firstChild);
  }

  const rows      = wrap.querySelectorAll('.msg-row');
  const lastRow   = rows.length ? rows[rows.length - 1] : null;
  const newGroup  = !lastRow || (isMine ? !lastRow.classList.contains('mine') : !lastRow.classList.contains('theirs'));

  const row = document.createElement('div');
  row.className = 'msg-row ' + (isMine ? 'mine' : 'theirs') + (newGroup ? ' new-group' : '');
  row.dataset.id = msg.id;

  const text  = String(msg.message).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
  const avInit= isMine ? MY_INIT : OTHER_INIT;
  const avCls = isMine ? 'is-mine' : 'is-theirs';
  const avStyle = isMine ? '' : `style="background:${OTHER_BG};color:${OTHER_FG}"`;
  const hidAv  = newGroup ? '' : 'hidden-av';

  row.innerHTML = `
    <div class="bub-av ${avCls} ${hidAv}" ${avStyle}>${avInit}</div>
    <div class="bub-wrap">
      <div class="bubble">${text}</div>
      <div class="msg-time">${fmtTime(msg.sent_at)}</div>
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
          if (parseInt(m.sender_id) !== MY_ID) {
            showTyping();
            setTimeout(() => {
              hideTyping();
              addBubble(m, false);
              scrollBottom();
            }, 500);
          }
          lastId = Math.max(lastId, parseInt(m.id));
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
  if (wrap.style.display === 'none') {
    wrap.style.display = 'block';
    setTimeout(() => inp?.focus(), 50);
  } else {
    wrap.style.display = 'none';
    document.getElementById('searchResults').style.display = 'none';
  }
}

// ── Debounced AJAX search ─────────────────────────────────────
let searchTimer = null;
function debounceSearch(val) {
  clearTimeout(searchTimer);
  const results = document.getElementById('searchResults');
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
    document.getElementById('searchResults').style.display = 'none';
  }
});

// ── Mobile panel switching ────────────────────────────────────
function mobileShowChat() {
  if (window.innerWidth > 600) return;
  document.querySelector('.conv-pane')?.classList.add('hidden-mobile');
  document.querySelector('.chat-pane')?.classList.add('active-mobile');
}
function mobileShowConvs() {
  document.querySelector('.conv-pane')?.classList.remove('hidden-mobile');
  document.querySelector('.chat-pane')?.classList.remove('active-mobile');
}

// Make conv items trigger mobile panel switch
document.querySelectorAll('.conv-item').forEach(function(item) {
  item.addEventListener('click', function() {
    if (window.innerWidth <= 600) mobileShowChat();
  });
});

// When a search result is clicked, it navigates — that's fine
// but if it stays on same page (same conv), show chat panel
document.querySelectorAll('.sr-item').forEach(function(item) {
  item.addEventListener('click', function() {
    if (window.innerWidth <= 600) {
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
  if (CONV_ID && window.innerWidth <= 600) {
    mobileShowChat();
  }

  // Show search if search was active
  <?php if ($search_q): ?>
  document.getElementById('searchWrap').style.display = 'block';
  document.getElementById('searchResults').style.display = 'block';
  <?php endif; ?>
});
window.addEventListener('beforeunload', () => { if (pollTimer) clearInterval(pollTimer); });
</script>

<script>
(function(){
  var btn = document.getElementById('hamburgerBtn');
  var sb  = document.querySelector('.sidebar');
  var ov  = document.getElementById('sidebarOverlay');
  if(!btn||!sb||!ov) return;
  function open(){sb.classList.add('mobile-open');ov.classList.add('active');btn.classList.add('open');btn.setAttribute('aria-expanded','true');document.body.style.overflow='hidden';}
  function close(){sb.classList.remove('mobile-open');ov.classList.remove('active');btn.classList.remove('open');btn.setAttribute('aria-expanded','false');document.body.style.overflow='';}
  btn.addEventListener('click',function(){sb.classList.contains('mobile-open')?close():open();});
  ov.addEventListener('click',close);
  sb.querySelectorAll('.nav-item').forEach(function(l){l.addEventListener('click',function(){if(window.innerWidth<=900)close();});});
  window.addEventListener('resize',function(){if(window.innerWidth>900)close();});
})();
</script>
</body>
</html>