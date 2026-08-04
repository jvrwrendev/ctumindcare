<?php
// ============================================================
//  CTU MindCare — Counselor Messages (Standalone Page)
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

if (!function_exists('clean')) {
    function clean(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('getFlash')) {
    function getFlash(): ?array { return null; }
}

// ── SEND MESSAGE (AJAX / POST) ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_text'])) {
    $msg        = trim($_POST['message_text'] ?? '');
    $student_id = (int)($_POST['student_id'] ?? 0);

    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
               strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

    if ($msg !== '' && $student_id) {
        $stmt   = $db->prepare("
            INSERT INTO counselor_messages (student_id, counselor_id, sender_id, message)
            VALUES (?, ?, ?, ?)
        ");
        $result = $stmt->execute([$student_id, $user['id'], $user['id'], $msg]);

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

    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.sent_at, cm.is_read,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name,  u.last_name)  AS last_name,
               CASE WHEN cm.sender_id = cm.counselor_id THEN 'wellness_admin' ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_id = wa.id AND cm.sender_id = cm.counselor_id
        LEFT JOIN users u            ON cm.sender_id = u.id  AND cm.sender_id = cm.student_id
        WHERE cm.student_id = ? AND cm.counselor_id = ? AND cm.id > ?
        ORDER BY cm.sent_at ASC
    ");
    $stmt->execute([$student_id, $user['id'], $since_id]);
    $msgs = $stmt->fetchAll();

    $db->prepare("
        UPDATE counselor_messages SET is_read = 1
        WHERE student_id = ? AND counselor_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$student_id, $user['id'], $user['id']]);

    echo json_encode(['messages' => $msgs]);
    exit;
}

// ── SIDEBAR COUNTS ────────────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM counselor_messages WHERE counselor_id = ? AND sender_id != ? AND is_read = 0");
$stmt->execute([$user['id'], $user['id']]);
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
        (SELECT cm3.sent_at  FROM counselor_messages cm3 WHERE cm3.student_id = u.id AND cm3.counselor_id = ? ORDER BY cm3.sent_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*)     FROM counselor_messages cm4 WHERE cm4.student_id = u.id AND cm4.counselor_id = ? AND cm4.sender_id != ? AND cm4.is_read = 0) as unread
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
        SELECT cm.id, cm.sender_id, cm.message, cm.is_read, cm.sent_at,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name,  u.last_name)  AS last_name,
               CASE WHEN cm.sender_id = cm.counselor_id THEN 'wellness_admin' ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_id = wa.id AND cm.sender_id = cm.counselor_id
        LEFT JOIN users u            ON cm.sender_id = u.id  AND cm.sender_id = cm.student_id
        WHERE cm.student_id = ? AND cm.counselor_id = ?
        ORDER BY cm.sent_at ASC
        LIMIT 150
    ");
    $stmt->execute([$chat_student_id, $user['id']]);
    $chat_messages = $stmt->fetchAll();
    if ($chat_messages) $last_msg_id = (int)end($chat_messages)['id'];

    $db->prepare("
        UPDATE counselor_messages SET is_read = 1
        WHERE student_id = ? AND counselor_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$chat_student_id, $user['id'], $user['id']]);
}

// ── HELPERS ───────────────────────────────────────────────────
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}

function initials(array $s): string {
    return strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1));
}

$my_initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
$c_initials  = $chat_student ? initials($chat_student) : '';

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — CTU MindCare Counselor</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
:root {
  --sage:        #4A9B7F;
  --sage-2:      #5BAF91;
  --sage-light:  #EAF7F2;
  --sage-mid:    #C4E8DC;
  --sage-dark:   #2D6B57;
  --sand:        #F7F3EE;
  --sand-2:      #EDE8E1;
  --sand-3:      #D9D3C9;
  --coral:       #D96B52;
  --coral-light: #FAECE7;
  --amber:       #D4860A;
  --amber-light: #FDF3DF;
  --lav:         #7B6FC4;
  --lav-light:   #EEEDFE;
  --blue:        #2460A7;
  --blue-light:  #EBF4FF;
  --text:        #2C2C2A;
  --text-2:      #5C5B57;
  --text-3:      #9A9891;
  --white:       #FFFFFF;
  --border:      #E8E4DE;
  --border-2:    #D6D0C8;

  --topbar-h:    58px;
  --sidebar-w:   230px;
}

html, body {
  height: 100%; overflow: hidden;
  font-family: 'DM Sans', sans-serif;
  font-size: 14px; color: var(--text);
  background: var(--sand);
  -webkit-font-smoothing: antialiased;
}
a { text-decoration: none; color: inherit; }

::-webkit-scrollbar { width: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 8px; }

.topbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 500;
  height: var(--topbar-h);
  background: var(--white);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 24px; gap: 14px;
  box-shadow: 0 1px 8px rgba(0,0,0,.05);
}
.topbar-mark {
  width: 34px; height: 34px; background: var(--sage); border-radius: 9px;
  display: flex; align-items: center; justify-content: center;
  font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; flex-shrink: 0;
}
.topbar-name   { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
.topbar-school { font-size: 10px; color: var(--text-3); margin-top: 1px; }
.topbar-badge  {
  font-size: 11px; font-weight: 500; padding: 3px 10px;
  border-radius: 20px; background: var(--sage-light);
  color: var(--sage-dark); border: 1px solid var(--sage-mid);
}
.topbar-right { margin-left: auto; display: flex; align-items: center; gap: 10px; }
.notif-btn {
  position: relative; width: 36px; height: 36px; border-radius: 9px;
  background: var(--sand); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 16px; text-decoration: none;
}
.notif-badge {
  position: absolute; top: 4px; right: 4px;
  min-width: 16px; height: 16px; border-radius: 8px;
  background: var(--coral); border: 1.5px solid var(--white);
  display: flex; align-items: center; justify-content: center;
  font-size: 9px; font-weight: 600; color: #fff; padding: 0 3px;
}
.user-chip {
  display: flex; align-items: center; gap: 8px;
  background: var(--sage-light); border: 1px solid var(--sage-mid);
  border-radius: 100px; padding: 5px 14px 5px 6px; cursor: pointer;
}
.user-av {
  width: 26px; height: 26px; border-radius: 50%;
  background: var(--sage); display: flex; align-items: center;
  justify-content: center; font-size: 10px; font-weight: 600; color: #fff;
}
.user-name  { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
.logout-btn {
  font-size: 12px; color: var(--text-3); text-decoration: none;
  padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border);
  transition: color .12s, border-color .12s;
}
.logout-btn:hover { color: var(--coral); border-color: var(--coral); }
.hamburger {
  display: none; flex-direction: column; justify-content: center;
  align-items: center; gap: 5px;
  width: 38px; height: 38px; border-radius: 9px;
  background: var(--sand); border: 1px solid var(--border);
  cursor: pointer; flex-shrink: 0;
}
.hamburger span {
  display: block; width: 18px; height: 2px;
  background: var(--text-2); border-radius: 2px; transition: all .25s;
}
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; transform: scaleX(0); }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
.sidebar-overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(0,0,0,.38); z-index: 299; backdrop-filter: blur(2px);
}
.sidebar-overlay.active { display: block; }

.shell {
  display: grid;
  grid-template-columns: var(--sidebar-w) 280px 1fr;
  height: calc(100vh - var(--topbar-h));
  margin-top: var(--topbar-h);
  overflow: hidden;
}

.sidebar {
  background: var(--white);
  border-right: 1px solid var(--border);
  padding: 18px 0;
  overflow-y: auto;
  display: flex; flex-direction: column;
}
.sb-section { padding: 0 12px; margin-bottom: 4px; }
.sb-label {
  font-size: 9px; font-weight: 600; color: var(--text-3);
  text-transform: uppercase; letter-spacing: 1px;
  padding: 0 8px; margin-bottom: 4px; display: block;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 10px; border-radius: 9px;
  font-size: 13px; color: var(--text-2); cursor: pointer;
  text-decoration: none; margin-bottom: 1px; transition: all .12s;
}
.nav-item:hover { background: var(--sand); color: var(--text); }
.nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
.nav-icon {
  width: 26px; height: 26px; border-radius: 7px;
  background: var(--sand); display: flex; align-items: center;
  justify-content: center; font-size: 13px; flex-shrink: 0;
}
.nav-item.active .nav-icon { background: var(--sage-mid); }
.nav-item.crisis-nav { color: var(--coral); }
.nav-item.crisis-nav:hover { background: var(--coral-light); }
.nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
.nav-badge {
  margin-left: auto; font-size: 9px; font-weight: 600;
  padding: 2px 6px; border-radius: 8px;
  background: var(--coral); color: #fff;
}
.sb-div { height: 1px; background: var(--border); margin: 8px 12px; }
.sb-footer { margin-top: auto; padding: 12px; }
.sb-user-card {
  background: var(--sand); border-radius: 10px;
  padding: 10px 12px; display: flex; align-items: center; gap: 10px;
}
.suf-av {
  width: 34px; height: 34px; border-radius: 50%;
  background: var(--sage); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600; flex-shrink: 0;
}
.suf-name { font-size: 12px; font-weight: 500; color: var(--text); }
.suf-role { font-size: 10px; color: var(--text-3); }

.student-pane {
  background: var(--white);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column; overflow: hidden;
}
.sp-head {
  padding: 16px 14px 10px;
  border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.sp-title { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--text); }
.sp-sub   { font-size: 11px; color: var(--text-3); margin-top: 2px; }
.sp-search { position: relative; margin-top: 10px; }
.sp-search-input {
  width: 100%; padding: 8px 12px 8px 32px;
  background: var(--sand); border: 1px solid var(--border);
  border-radius: 50px; font-family: 'DM Sans', sans-serif;
  font-size: 12px; color: var(--text); outline: none;
  transition: border-color .12s;
}
.sp-search-input:focus { border-color: var(--sage); }
.sp-search-input::placeholder { color: var(--text-3); }
.sp-search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 12px; pointer-events: none; }
.sp-list { flex: 1; overflow-y: auto; padding: 6px; }

.student-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px; border-radius: 10px;
  cursor: pointer; margin-bottom: 2px;
  border: 1.5px solid transparent;
  transition: background .12s, border-color .12s;
  text-decoration: none;
}
.student-item:hover { background: var(--sand); }
.student-item.active { background: var(--sage-light); border-color: var(--sage-mid); }
.si-av {
  width: 40px; height: 40px; border-radius: 50%;
  background: var(--sage-light); color: var(--sage-dark);
  font-size: 12px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; position: relative;
}
.student-item.active .si-av { background: var(--sage); color: #fff; }
.online-dot {
  position: absolute; bottom: 1px; right: 1px;
  width: 10px; height: 10px; border-radius: 50%;
  background: #22C55E; border: 2px solid var(--white);
}
.student-item.active .online-dot { border-color: var(--sage-light); }
.si-info { flex: 1; min-width: 0; }
.si-name { font-size: 13px; font-weight: 500; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.si-meta { display: flex; justify-content: space-between; align-items: center; }
.si-preview { font-size: 11px; color: var(--text-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.si-preview.bold { font-weight: 600; color: var(--text-2); }
.si-time  { font-size: 10px; color: var(--text-3); white-space: nowrap; flex-shrink: 0; margin-left: 4px; }
.si-badge {
  min-width: 17px; height: 17px; border-radius: 9px;
  background: var(--coral); color: #fff;
  font-size: 10px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  padding: 0 4px; flex-shrink: 0;
}
.sp-empty { text-align: center; padding: 32px 12px; font-size: 12px; color: var(--text-3); }

.chat-pane { display: flex; flex-direction: column; overflow: hidden; background: var(--sand); position: relative; }

.chat-header {
  background: var(--white);
  border-bottom: 1px solid var(--border);
  padding: 12px 20px;
  display: flex; align-items: center; gap: 12px;
  flex-shrink: 0;
  box-shadow: 0 1px 8px rgba(0,0,0,.04);
  z-index: 10;
  animation: slideDown .22s ease-out;
}
@keyframes slideDown { from{opacity:0;transform:translateY(-6px);} to{opacity:1;transform:translateY(0);} }

.ch-av {
  width: 40px; height: 40px; border-radius: 50%;
  background: var(--sage-light); color: var(--sage-dark);
  font-size: 13px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; position: relative;
}
.ch-online {
  position: absolute; bottom: 1px; right: 1px;
  width: 11px; height: 11px; border-radius: 50%;
  background: #22C55E; border: 2px solid var(--white);
  animation: pulse 2.5s ease-in-out infinite;
}
@keyframes pulse { 0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.4);} 50%{box-shadow:0 0 0 5px rgba(34,197,94,0);} }
.ch-name { font-size: 14px; font-weight: 600; color: var(--text); line-height: 1.2; }
.ch-sub  { font-size: 11px; color: var(--text-3); margin-top: 1px; }
.ch-mood-badge {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 500;
  background: var(--sand); border: 1px solid var(--border);
  border-radius: 20px; padding: 2px 8px; margin-top: 3px;
  color: var(--text-2);
}
.ch-actions { margin-left: auto; display: flex; gap: 8px; }
.ch-btn {
  padding: 6px 12px; border: 1px solid var(--border);
  border-radius: 20px; background: var(--white);
  font-family: 'DM Sans', sans-serif;
  font-size: 11px; font-weight: 500; color: var(--text-2);
  cursor: pointer; transition: all .12s;
  display: inline-flex; align-items: center; gap: 5px;
  text-decoration: none;
}
.ch-btn:hover { background: var(--sand); border-color: var(--border-2); color: var(--text); }
.ch-btn.btn-note { border-color: var(--sage-mid); color: var(--sage-dark); }
.ch-btn.btn-note:hover { background: var(--sage-light); }

.conn-strip {
  display: none; padding: 6px 20px;
  background: var(--amber-light); border-bottom: 1px solid #FAC775;
  font-size: 11.5px; font-weight: 500; color: var(--amber);
  flex-shrink: 0;
  animation: slideDown .2s ease-out;
}
.conn-strip.show { display: flex; align-items: center; gap: 8px; }
.conn-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: #22C55E; flex-shrink: 0; margin-left: auto;
  transition: background .4s;
}
.conn-dot.offline      { background: var(--text-3); }
.conn-dot.reconnecting { background: var(--amber); animation: blink 1s ease-in-out infinite; }
@keyframes blink { 0%,100%{opacity:1;} 50%{opacity:.3;} }

.chat-messages {
  flex: 1; overflow-y: auto;
  padding: 20px 20px 10px;
  display: flex; flex-direction: column; gap: 0;
  background-image: radial-gradient(circle, var(--sand-3) 1px, transparent 1px);
  background-size: 26px 26px;
  background-color: var(--sand);
}

.date-sep {
  display: flex; align-items: center; gap: 10px;
  font-size: 11px; font-weight: 600; color: var(--text-3);
  margin: 18px 0 12px;
  position: sticky; top: 0; z-index: 5;
}
.date-sep::before, .date-sep::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.date-sep span {
  background: var(--sand); padding: 2px 10px;
  border-radius: 20px; border: 1px solid var(--border);
  white-space: nowrap; box-shadow: 0 1px 4px rgba(0,0,0,.04);
}

.sys-msg {
  text-align: center; font-size: 11px; font-weight: 500;
  color: var(--text-3); padding: 5px 14px;
  background: var(--white); border-radius: 20px;
  border: 1px solid var(--border);
  margin: 0 auto 18px; width: fit-content; max-width: 320px;
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
}

.msg-row {
  display: flex; align-items: flex-end; gap: 8px;
  max-width: 100%;
  animation: msgIn .2s cubic-bezier(.22,.68,0,1.2) both;
}
@keyframes msgIn { from{opacity:0;transform:translateY(8px) scale(.96);} to{opacity:1;transform:translateY(0) scale(1);} }
.msg-row.mine   { flex-direction: row-reverse; }
.msg-row.theirs { flex-direction: row; }
.msg-row.new-group   { margin-top: 14px; }
.msg-row.same-sender { margin-top: 3px; }

.bub-av {
  width: 28px; height: 28px; border-radius: 50%;
  font-size: 10px; font-weight: 600;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; align-self: flex-end; transition: opacity .12s;
}
.bub-av.is-mine   { background: var(--sage); color: #fff; }
.bub-av.is-theirs { background: var(--sand-2); color: var(--text-2); }
.bub-av.hidden    { visibility: hidden; }

.bub-wrap { display: flex; flex-direction: column; max-width: min(68%, 480px); }
.msg-row.mine   .bub-wrap { align-items: flex-end; }
.msg-row.theirs .bub-wrap { align-items: flex-start; }

.msg-sender-name {
  font-size: 10px; font-weight: 600; color: var(--text-3);
  margin-bottom: 4px; padding: 0 2px;
}

.bubble {
  padding: 9px 14px; font-size: 13px; line-height: 1.6;
  word-break: break-word;
  transition: transform .1s;
}
.bubble:hover { transform: scale(1.01); }

.msg-row.mine .bubble {
  background: var(--sage);
  color: #fff;
  border-radius: 16px 16px 4px 16px;
  box-shadow: 0 3px 10px rgba(74,155,127,.22);
}
.msg-row.theirs .bubble {
  background: var(--white);
  color: var(--text);
  border-radius: 16px 16px 16px 4px;
  border: 1px solid var(--border);
  box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.msg-row.mine.same-sender   .bubble { border-radius: 16px 4px 4px 16px; }
.msg-row.theirs.same-sender .bubble { border-radius: 4px 16px 16px 4px; }
.msg-row.mine.tail-end   .bubble { border-radius: 16px 4px 4px 16px; }
.msg-row.theirs.tail-end .bubble { border-radius: 4px 16px 16px 4px; }

.msg-time {
  font-size: 10px; color: var(--text-3);
  margin-top: 4px; padding: 0 2px;
  display: flex; align-items: center; gap: 4px;
  opacity: 0; transition: opacity .15s;
}
.msg-row:hover .msg-time,
.tail-end .msg-time { opacity: 1; }
.msg-row.mine .msg-time { justify-content: flex-end; }
.tick-read { color: var(--sage); font-size: 11px; }
.tick-sent { color: var(--text-3); font-size: 11px; }

.typing-row {
  display: none; align-items: flex-end; gap: 8px;
  margin-top: 14px;
  animation: msgIn .2s ease-out;
}
.typing-row.show { display: flex; }
.typing-bubble {
  background: var(--white); border: 1px solid var(--border);
  border-radius: 16px 16px 16px 4px;
  padding: 11px 15px;
  display: flex; gap: 5px; align-items: center;
  box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.t-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--sage-mid);
  animation: tdot 1.3s ease-in-out infinite;
}
.t-dot:nth-child(2) { animation-delay: .18s; }
.t-dot:nth-child(3) { animation-delay: .36s; }
@keyframes tdot { 0%,60%,100%{transform:translateY(0);background:var(--sand-3);} 30%{transform:translateY(-6px);background:var(--sage);} }

.no-msgs-state {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  text-align: center; padding: 48px;
}
.nm-icon  { font-size: 40px; margin-bottom: 14px; animation: wave 1.5s ease-in-out 1; }
@keyframes wave { 0%{transform:rotate(0);} 20%{transform:rotate(-14deg);} 40%{transform:rotate(14deg);} 60%{transform:rotate(-8deg);} 80%{transform:rotate(8deg);} 100%{transform:rotate(0);} }
.nm-title { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--text); margin-bottom: 6px; }
.nm-sub   { font-size: 12px; color: var(--text-3); line-height: 1.7; max-width: 240px; }

.no-student-state {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  text-align: center; padding: 48px;
  animation: fadeIn .3s ease-out;
}
@keyframes fadeIn { from{opacity:0;transform:translateY(10px);} to{opacity:1;transform:translateY(0);} }
.ns-icon  { font-size: 40px; margin-bottom: 14px; opacity: .5; }
.ns-title { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--text-2); margin-bottom: 6px; }
.ns-sub   { font-size: 12px; color: var(--text-3); }

.input-bar {
  background: var(--white);
  border-top: 1px solid var(--border);
  padding: 10px 16px 12px;
  flex-shrink: 0;
}
.input-tools { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
.tool-btn {
  width: 28px; height: 28px; border-radius: 7px;
  background: transparent; border: 1px solid var(--border);
  cursor: pointer; font-size: 13px;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-3); transition: background .12s, color .12s;
}
.tool-btn:hover { background: var(--sand); color: var(--text-2); }
.char-count { margin-left: auto; font-size: 10px; color: var(--text-3); }
.input-row { display: flex; align-items: flex-end; gap: 9px; }
.msg-area {
  flex: 1; padding: 10px 14px;
  background: var(--sand); border: 1.5px solid var(--border);
  border-radius: 20px; font-family: 'DM Sans', sans-serif;
  font-size: 13px; color: var(--text); outline: none;
  resize: none; min-height: 44px; max-height: 130px;
  line-height: 1.55; overflow-y: hidden;
  transition: border-color .15s, background .15s, box-shadow .15s;
}
.msg-area:focus {
  background: var(--white);
  border-color: var(--sage);
  box-shadow: 0 0 0 3px rgba(74,155,127,.1);
}
.msg-area::placeholder { color: var(--text-3); }
.send-btn {
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--sage); border: none;
  cursor: pointer; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: background .12s, transform .1s, box-shadow .12s;
  box-shadow: 0 3px 10px rgba(74,155,127,.25);
}
.send-btn:hover  { background: var(--sage-2); box-shadow: 0 5px 16px rgba(74,155,127,.3); }
.send-btn:active { transform: scale(.88); }
.send-btn:disabled { opacity: .35; cursor: not-allowed; transform: none; box-shadow: none; }
.send-btn svg { width: 17px; height: 17px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.input-hint { font-size: 10px; color: var(--text-3); margin-top: 6px; text-align: center; }

.emoji-panel {
  display: none; position: absolute; bottom: 72px; left: 16px;
  background: var(--white); border: 1px solid var(--border);
  border-radius: 13px; padding: 10px; width: 266px;
  box-shadow: 0 6px 28px rgba(0,0,0,.1);
  z-index: 40;
  animation: popUp .16s cubic-bezier(.22,.68,0,1.2);
}
@keyframes popUp { from{opacity:0;transform:translateY(6px) scale(.96);} to{opacity:1;transform:translateY(0) scale(1);} }
.emoji-panel.show { display: block; }
.emoji-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; }
.em-btn {
  width: 30px; height: 30px; border-radius: 6px;
  background: transparent; border: none; cursor: pointer;
  font-size: 17px; display: flex; align-items: center; justify-content: center;
  transition: background .1s, transform .1s;
}
.em-btn:hover { background: var(--sand); transform: scale(1.18); }

.flash {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px; border-radius: 9px; font-size: 13px;
  margin: 0 14px 10px; flex-shrink: 0;
}
.flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
.flash-error   { background: #FBEDED; border: 1px solid #F5C6C2; color: #8B2020; }

@media (max-width: 1080px) {
  .shell { grid-template-columns: var(--sidebar-w) 240px 1fr; }
}
@media (max-width: 900px) {
  .shell { grid-template-columns: 280px 1fr; }
  .hamburger { display: flex; }
  .sidebar {
    position: fixed; top: var(--topbar-h); left: 0; bottom: 0;
    z-index: 300; transform: translateX(-100%); transition: transform .28s ease;
    width: 250px; box-shadow: 4px 0 24px rgba(0,0,0,.12);
    height: calc(100vh - var(--topbar-h));
  }
  .sidebar.mobile-open { transform: translateX(0); }
}
@media (max-width: 640px) {
  .shell { grid-template-columns: 1fr; }
  .student-pane { display: none; }
  .ch-actions { display: none; }
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
  <div class="topbar-mark">M</div>
  <div>
    <div class="topbar-name">CTU MindCare</div>
    <div class="topbar-school">CTU Consolacion</div>
  </div>
  <span class="topbar-badge">Wellness Counselor</span>
  <div class="topbar-right">
    <?php if ($unread_msgs > 0): ?>
      <a class="notif-btn" href="messages.php" title="<?= $unread_msgs ?> unread messages">
        💬<span class="notif-badge"><?= $unread_msgs ?></span>
      </a>
    <?php endif; ?>
    <?php if ($open_alerts_count > 0): ?>
      <a class="notif-btn" href="dashboard.php?p=alerts" title="<?= $open_alerts_count ?> open alerts">
        🚨<span class="notif-badge"><?= $open_alerts_count ?></span>
      </a>
    <?php endif; ?>
    <div class="user-chip">
      <div class="user-av"><?= $my_initials ?></div>
      <span class="user-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </div>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="shell">

  <aside class="sidebar" id="sidebar">
    <div class="sb-section">
      <span class="sb-label">Workspace</span>
      <a class="nav-item" href="dashboard.php?p=dashboard"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item" href="dashboard.php?p=students"><div class="nav-icon">👥</div> My students</a>
      <a class="nav-item" href="dashboard.php?p=appointments"><div class="nav-icon">📅</div> Appointments</a>
      <a class="nav-item active" href="messages.php"><div class="nav-icon">💬</div> Messages
        <?php if ($unread_msgs > 0): ?><span class="nav-badge"><?= $unread_msgs ?></span><?php endif; ?>
      </a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-section">
      <span class="sb-label">Clinical</span>
      <a class="nav-item" href="dashboard.php?p=alerts"><div class="nav-icon">⚠️</div> Crisis alerts
        <?php if ($open_alerts_count > 0): ?><span class="nav-badge"><?= $open_alerts_count ?></span><?php endif; ?>
      </a>
      <a class="nav-item" href="dashboard.php?p=notes"><div class="nav-icon">📋</div> Session notes</a>
      <a class="nav-item" href="dashboard.php?p=assessments"><div class="nav-icon">📊</div> Assessments</a>
      <a class="nav-item" href="dashboard.php?p=progress"><div class="nav-icon">📈</div> Student progress</a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-section">
      <span class="sb-label">Community</span>
      <a class="nav-item" href="dashboard.php?p=forum"><div class="nav-icon">🗣️</div> Forum moderation
        <?php if ($flagged_count > 0): ?><span class="nav-badge"><?= $flagged_count ?></span><?php endif; ?>
      </a>
      <a class="nav-item" href="dashboard.php?p=resources"><div class="nav-icon">📚</div> Resource library</a>
      <a class="nav-item" href="dashboard.php?p=announcements"><div class="nav-icon">📢</div> Announcements</a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-section">
      <a class="nav-item crisis-nav" href="dashboard.php?p=crisis"><div class="nav-icon">🆘</div> Crisis protocols</a>
    </div>
    <div class="sb-footer">
      <div class="sb-user-card">
        <div class="suf-av"><?= $my_initials ?></div>
        <div>
          <div class="suf-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="suf-role">Wellness Counselor</div>
        </div>
      </div>
    </div>
  </aside>

  <!-- STUDENT LIST PANE -->
  <div class="student-pane">
    <div class="sp-head">
      <div class="sp-title">Messages</div>
      <div class="sp-sub"><?= count($my_students) ?> student<?= count($my_students) !== 1 ? 's' : '' ?></div>
      <div class="sp-search">
        <span class="sp-search-icon">🔍</span>
        <input class="sp-search-input" type="text" id="studentSearch" placeholder="Search students…" autocomplete="off">
      </div>
    </div>
    <div class="sp-list" id="studentList">
      <?php if (!$all_for_chat): ?>
        <div class="sp-empty">No students assigned yet.</div>
      <?php else: ?>
        <?php foreach ($all_for_chat as $chat_item):
          $is_active = ((int)$chat_item['id'] === $chat_student_id);
          $si        = strtoupper(substr($chat_item['first_name'],0,1).substr($chat_item['last_name'],0,1));
          $unread    = (int)($chat_item['unread'] ?? 0);
          $preview   = '';
          $last_t    = '';
          if (!empty($chat_item['last_message'])) {
              $preview = 'You: ' . mb_substr(strip_tags($chat_item['last_message']), 0, 28);
              if (mb_strlen($chat_item['last_message']) > 28) $preview .= '…';
              if (!empty($chat_item['last_time'])) $last_t = timeAgo($chat_item['last_time']);
          }
        ?>
          <a class="student-item <?= $is_active ? 'active' : '' ?>"
             data-name="<?= htmlspecialchars(strtolower($chat_item['first_name'].' '.$chat_item['last_name'])) ?>"
             href="messages.php?student=<?= $chat_item['id'] ?>">
            <div class="si-av"><?= $si ?><div class="online-dot"></div></div>
            <div class="si-info">
              <div class="si-meta">
                <div class="si-name"><?= clean($chat_item['first_name'].' '.$chat_item['last_name']) ?></div>
                <?php if ($last_t): ?><div class="si-time"><?= $last_t ?></div><?php endif; ?>
              </div>
              <div class="si-preview <?= $unread ? 'bold' : '' ?>">
                <?= $preview ? clean($preview) : 'Student' ?>
              </div>
            </div>
            <?php if ($unread): ?><div class="si-badge"><?= $unread ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- CHAT PANE -->
  <div class="chat-pane" id="chatPane">

    <?php if (!$chat_student): ?>
    <div class="no-student-state">
      <div class="ns-icon">💬</div>
      <div class="ns-title">Select a student</div>
      <div class="ns-sub">Choose a student from the list to open or start a conversation.</div>
    </div>

    <?php else: ?>

    <?php if ($flash && isset($flash['type'], $flash['message'])): ?>
      <div class="flash flash-<?= clean($flash['type']) ?>"><?= $flash['type']==='success'?'✓':'⚠' ?> <?= clean($flash['message']) ?></div>
    <?php endif; ?>

    <div class="chat-header">
      <div class="ch-av" style="position:relative">
        <?= $c_initials ?>
        <div class="ch-online"></div>
      </div>
      <div>
        <div class="ch-name"><?= clean($chat_student['first_name'].' '.$chat_student['last_name']) ?></div>
        <div class="ch-sub"><?= clean($chat_student['email'] ?? 'Student') ?></div>
        <?php if (!empty($chat_student['latest_mood'])): ?>
          <div class="ch-mood-badge">
            <?= $mood_emoji[$chat_student['latest_mood']] ?> <?= $mood_labels[$chat_student['latest_mood']] ?>
          </div>
        <?php endif; ?>
      </div>
      <div class="ch-actions">
        <a class="ch-btn" href="dashboard.php?p=progress&prog_student=<?= $chat_student_id ?>">📈 Progress</a>
        <a class="ch-btn btn-note" href="dashboard.php?p=notes">📋 Add note</a>
        <a class="ch-btn" href="dashboard.php?p=appointments#book">📅 Book session</a>
        <div style="display:flex;align-items:center;gap:5px;margin-left:4px">
          <div class="conn-dot" id="connDot"></div>
          <span style="font-size:10px;color:var(--text-3)" id="connLabel">Live</span>
        </div>
      </div>
    </div>

    <div class="conn-strip" id="connStrip">⚠️ Connection lost — attempting to reconnect…</div>

    <div class="chat-messages" id="chatMessages">

      <?php if (!$chat_messages): ?>
      <div class="no-msgs-state" id="emptyState">
        <div class="nm-icon">👋</div>
        <div class="nm-title">Start the conversation</div>
        <div class="nm-sub">Send <?= clean($chat_student['first_name']) ?> a message. They'll see it next time they check their app.</div>
      </div>

      <?php else: ?>
      <div class="sys-msg" id="sysMsg">🔒 This conversation is private and confidential</div>

      <?php
  $prev_date   = '';
  $prev_sender = -1;
  $prev_role   = '';

  foreach ($chat_messages as $i => $msg):
    // FIX: Compare sender ID directly, not role string
    $is_mine = ((int)$msg['sender_id'] === (int)$user['id']);
    $sender_role = $msg['sender_role'] ?? ($is_mine ? 'wellness_admin' : 'student');
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

    $row_cls  = 'msg-row ' . ($is_mine ? 'mine' : 'theirs');
    $row_cls .= $new_group ? ' new-group' : ' same-sender';
    $row_cls .= $is_tail  ? ' tail-end' : '';

    $av_cls  = $is_mine ? 'is-mine' : 'is-theirs';
    $av_init = $is_mine ? $my_initials : $c_initials;

    $prev_sender = (int)$msg['sender_id'];
    $prev_role   = $sender_role;
?>
  <div class="<?= $row_cls ?>" data-id="<?= (int)$msg['id'] ?>">
    <div class="bub-av <?= $av_cls ?><?= (!$is_tail) ? ' hidden' : '' ?>"><?= $av_init ?></div>
    <div class="bub-wrap">
      <?php if ($new_group && !$is_mine): ?>
        <div class="msg-sender-name"><?= clean($msg['first_name'].' '.$msg['last_name']) ?></div>
      <?php endif; ?>
      <div class="bubble"><?= nl2br(clean($msg['message'])) ?></div>
      <?php if ($is_tail): ?>
        <div class="msg-time">
          <?= timeAgo($msg['sent_at']) ?>
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
        <div class="bub-av is-theirs"><?= $c_initials ?></div>
        <div class="typing-bubble">
          <div class="t-dot"></div><div class="t-dot"></div><div class="t-dot"></div>
        </div>
      </div>

    </div><!-- .chat-messages -->

    <div class="emoji-panel" id="emojiPanel">
      <div class="emoji-grid" id="emojiGrid"></div>
    </div>

    <div class="input-bar">
      <div class="input-tools">
        <button class="tool-btn" id="emojiBtn" type="button" title="Emoji">😊</button>
        <div class="char-count" id="charCount"></div>
      </div>
      <div class="input-row">
        <textarea
          class="msg-area" id="msgInput"
          placeholder="Message <?= clean($chat_student['first_name']) ?>…"
          rows="1" maxlength="2000"
          onkeydown="handleKey(event)"
          oninput="onInput(this)"></textarea>
        <button class="send-btn" id="sendBtn" disabled type="button" onclick="sendMessage()">
          <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </div>
      <div class="input-hint">Enter to send &nbsp;·&nbsp; Shift+Enter for new line</div>
    </div>

    <?php endif; ?>
  </div><!-- .chat-pane -->
</div><!-- .shell -->

<script>
'use strict';

const STUDENT_ID   = <?= (int)$chat_student_id ?>;
const COUNSELOR_ID = <?= (int)$user['id'] ?>;
const MY_INIT      = '<?= $my_initials ?>';
const C_INIT       = '<?= $c_initials ?>';
const MAX_LEN      = 2000;

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
  el.style.height = Math.min(130, el.scrollHeight) + 'px';
  if (sendBtn) sendBtn.disabled = el.value.trim().length === 0;
  if (charCount) {
    const len = el.value.length;
    charCount.textContent = len > 1800 ? `${len}/${MAX_LEN}` : '';
    charCount.style.color = len > 1900 ? 'var(--coral)' : 'var(--text-3)';
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
function nearBottom() {
  if (!chatMessages) return true;
  return chatMessages.scrollHeight - chatMessages.scrollTop - chatMessages.clientHeight < 120;
}

// ── Send ─────────────────────────────────────────────────────
async function sendMessage() {
  if (sending || !msgInput || !sendBtn) return;
  const txt = msgInput.value.trim();
  if (!txt || !STUDENT_ID) return;

  sending = true;
  sendBtn.disabled = true;

  const tempId = 'tmp-' + Date.now();
  appendBubble({
    id: tempId, sender_id: COUNSELOR_ID, sender_role: 'wellness_admin',
    message: txt, sent_at: new Date().toISOString(), is_read: 0
  }, true);
  msgInput.value = '';
  msgInput.style.height = 'auto';
  scrollBottom();
  document.getElementById('emptyState')?.remove();

  try {
    const fd = new FormData();
    fd.append('message_text', txt);
    fd.append('student_id', STUDENT_ID);
    const res  = await fetch('messages.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: fd
    });
    const data = await res.json();

    if (data.status === 'ok' && data.id) {
      const tmp = document.querySelector(`[data-id="${tempId}"]`);
      if (tmp) {
        tmp.dataset.id = String(data.id);
        lastMsgId = Math.max(lastMsgId, data.id);
      }
    } else {
      const tmp = document.querySelector(`[data-id="${tempId}"]`);
      if (tmp) tmp.style.opacity = '.5';
    }
    setOnline(true);
  } catch (err) {
    console.error('[send]', err);
    setOnline(false);
    const tmp = document.querySelector(`[data-id="${tempId}"]`);
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
        if (tempMsg && parseInt(m.sender_id, 10) === COUNSELOR_ID) {
          tempMsg.dataset.id = String(id);
          const tickSpan = tempMsg.querySelector('.tick-sent');
          if (tickSpan && m.is_read) {
            tickSpan.className = 'tick-read';
            tickSpan.textContent = '✓✓';
          }
          return;
        }

        // Skip if already rendered
        if (chatMessages?.querySelector(`[data-id="${id}"]`)) return;

        // Show typing indicator for new messages from student
        if (parseInt(m.sender_id, 10) !== COUNSELOR_ID) {
          showTyping();
          clearTimeout(typingTimers[id]);
          typingTimers[id] = setTimeout(() => {
            hideTyping();
            appendBubble(m, false); // false = not mine, from student
            if (wasNear) scrollBottom();
            updateSidebarPreview(m);
          }, 650);
        } else {
          // This is our own message that came back from server
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
  let interval = 2500;
  let lastActivity = Date.now();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { lastActivity = Date.now(); poll(); } });
  ['click','keydown'].forEach(e => document.addEventListener(e, () => { lastActivity = Date.now(); }));
  function tick() {
    const idle = Date.now() - lastActivity;
    interval = !online ? retryDelay : (idle > 60000 ? 7000 : idle > 15000 ? 4000 : 2500);
    poll();
    pollTimer = setTimeout(tick, interval);
  }
  pollTimer = setTimeout(tick, interval);
}

function showTyping() { typingRow?.classList.add('show'); scrollBottom(); }
function hideTyping() { typingRow?.classList.remove('show'); }

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
    s.textContent = '🔒 This conversation is private and confidential';
    chatMessages.insertBefore(s, typingRow);
  }
  document.getElementById('emptyState')?.remove();

  const rows    = [...chatMessages.querySelectorAll('.msg-row')];
  const lastRow = rows.length ? rows[rows.length - 1] : null;
  const lastIsMe = lastRow?.classList.contains('mine');
  const newGroup = !lastRow || (isMine ? !lastIsMe : lastIsMe);

  if (!newGroup && lastRow) {
    lastRow.classList.remove('tail-end');
    lastRow.querySelector('.bub-av')?.classList.add('hidden');
    lastRow.querySelector('.msg-time')?.remove();
    const prevBub = lastRow.querySelector('.bubble');
    if (prevBub) {
      if (isMine) {
        prevBub.style.borderRadius = '16px 4px 4px 16px';
      } else {
        prevBub.style.borderRadius = '4px 16px 16px 4px';
      }
    }
  }

  const row = document.createElement('div');
  row.dataset.id = id;
  row.className = `msg-row ${isMine ? 'mine' : 'theirs'} ${newGroup ? 'new-group' : 'same-sender'} tail-end`;

  const avCls  = isMine ? 'is-mine' : 'is-theirs';
  const avInit = isMine ? MY_INIT : C_INIT;
  const text   = escHtml(msg.message).replace(/\n/g, '<br>');
  const time   = fmtTime(msg.sent_at);
  
  // FIX: Show student name when message is from student (not isMine)
  let senderHtml = '';
  if (!isMine && newGroup && msg.sender_role === 'student') {
    const senderName = msg.first_name && msg.last_name 
      ? `${msg.first_name} ${msg.last_name}`
      : 'Student';
    senderHtml = `<div class="msg-sender-name">${escHtml(senderName)}</div>`;
  }
  
  const readMark = isMine
    ? `<span class="${msg.is_read ? 'tick-read' : 'tick-sent'}" title="${msg.is_read ? 'Read' : 'Sent'}">${msg.is_read ? '✓✓' : '✓'}</span>` : '';

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
  const item = document.querySelector(`.student-item[href*="student=${STUDENT_ID}"]`);
  if (!item) return;
  const previewEl = item.querySelector('.si-preview');
  const timeEl    = item.querySelector('.si-time');
  if (previewEl) {
    previewEl.textContent = msg.message.slice(0, 28) + (msg.message.length > 28 ? '…' : '');
    previewEl.classList.add('bold');
  }
  if (timeEl) timeEl.textContent = 'Just now';
  const list = item.parentElement;
  if (list && list.firstChild !== item) list.insertBefore(item, list.firstChild);
}

// ── Emoji picker ──────────────────────────────────────────────
const EMOJIS = ['😊','😁','🥰','😌','😔','😟','😢','🥹','🤗','🙏','💚','💙','❤️','✨','🌸','☀️','🎉','💪','📝','🔥','👍','👀','📅','⏰','📞','💬','🆘','🏃','🧘','🌟'];
if (emojiBtn && emojiPanel && emojiGrid) {
  EMOJIS.forEach(em => {
    const btn = document.createElement('button');
    btn.className = 'em-btn'; btn.type = 'button'; btn.textContent = em;
    btn.onclick = () => {
      if (msgInput) {
        const pos = msgInput.selectionStart;
        msgInput.value = msgInput.value.slice(0, pos) + em + msgInput.value.slice(pos);
        msgInput.setSelectionRange(pos + em.length, pos + em.length);
        msgInput.focus(); onInput(msgInput);
      }
      emojiPanel.classList.remove('show');
    };
    emojiGrid.appendChild(btn);
  });
  emojiBtn.addEventListener('click', e => { e.stopPropagation(); emojiPanel.classList.toggle('show'); });
  document.addEventListener('click', e => { if (!emojiPanel.contains(e.target) && e.target !== emojiBtn) emojiPanel.classList.remove('show'); });
}

// ── Student search ─────────────────────────────────────────────
const searchEl = document.getElementById('studentSearch');
if (searchEl) {
  searchEl.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.student-item').forEach(el => {
      el.style.display = (!q || (el.dataset.name || '').includes(q)) ? '' : 'none';
    });
  });
}

function escHtml(s) {
  return String(s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmtTime(iso) {
  const d = new Date(iso); const diff = (Date.now() - d) / 1000;
  if (diff < 60)    return 'Just now';
  if (diff < 3600)  return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400) return d.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' });
  if (diff < 604800) return d.toLocaleDateString('en-PH', { weekday: 'short', hour: 'numeric', minute: '2-digit' });
  return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}

// ── Mobile sidebar ─────────────────────────────────────────────
(function() {
  const btn = document.getElementById('hamburgerBtn');
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('sidebarOverlay');
  if (!btn || !sb || !ov) return;
  function open()  { sb.classList.add('mobile-open'); ov.classList.add('active'); btn.classList.add('open'); btn.setAttribute('aria-expanded','true'); document.body.style.overflow='hidden'; }
  function close() { sb.classList.remove('mobile-open'); ov.classList.remove('active'); btn.classList.remove('open'); btn.setAttribute('aria-expanded','false'); document.body.style.overflow=''; }
  btn.addEventListener('click', () => sb.classList.contains('mobile-open') ? close() : open());
  ov.addEventListener('click', close);
  sb.querySelectorAll('.nav-item').forEach(l => l.addEventListener('click', () => { if (window.innerWidth <= 900) close(); }));
  window.addEventListener('resize', () => { if (window.innerWidth > 900) close(); });
})();

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  scrollBottom(true);
  msgInput?.focus();
  if (STUDENT_ID) startPoll();
});
window.addEventListener('online',  () => { setOnline(true); if (STUDENT_ID) poll(); });
window.addEventListener('offline', () => setOnline(false));
window.addEventListener('beforeunload', () => { if (pollTimer) clearTimeout(pollTimer); });
</script>

</body>
</html>