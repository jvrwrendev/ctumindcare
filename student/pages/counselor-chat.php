<?php
// ============================================================
//  CTU MindCare — Real-Time Messenger Chat (Student)
//  File: student/pages/counselor-chat.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// FIX #11 — Define clean() if not already provided by auth.php.
if (!function_exists('clean')) {
    function clean(string $s): string {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

// ── Load all active counselors ─────────────────────────────────
$stmt = $db->prepare("SELECT id, first_name, last_name, email FROM wellness_admins WHERE is_active = 1 ORDER BY first_name ASC");
$stmt->execute();
$counselors = $stmt->fetchAll();

// ── Active counselor ───────────────────────────────────────────
$counselor_id = (int)($_GET['counselor'] ?? 0);
if (!$counselor_id && $counselors) $counselor_id = (int)$counselors[0]['id'];

$active_counselor = null;
foreach ($counselors as $c) {
    if ((int)$c['id'] === $counselor_id) { $active_counselor = $c; break; }
}

// ── POLL endpoint ──────────────────────────────────────────────
if (isset($_GET['poll'])) {
    $since_id = (int)($_GET['since'] ?? 0);
    $cid      = (int)($_GET['counselor'] ?? 0);
    header('Content-Type: application/json');
    if (!$cid) { echo json_encode(['messages' => []]); exit; }

    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.sent_at, cm.is_read,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name, u.last_name) AS last_name,
               CASE WHEN cm.sender_id = cm.counselor_id THEN 'wellness_admin' ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_id = wa.id AND cm.sender_id = cm.counselor_id
        LEFT JOIN users u ON cm.sender_id = u.id AND cm.sender_id = cm.student_id
        WHERE cm.student_id = ? AND cm.counselor_id = ? AND cm.id > ?
        ORDER BY cm.sent_at ASC
    ");
    $stmt->execute([$user['id'], $cid, $since_id]);
    $new_msgs = $stmt->fetchAll();
    $db->prepare("UPDATE counselor_messages SET is_read = 1 WHERE student_id = ? AND counselor_id = ? AND sender_id != ? AND is_read = 0")->execute([$user['id'], $cid, $user['id']]);
    echo json_encode(['messages' => $new_msgs]);
    exit;
}

// ── SEND MESSAGE (AJAX) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $msg = trim($_POST['message'] ?? '');
    $cid = (int)($_POST['counselor_id'] ?? 0);
    if ($msg !== '' && $cid) {
        $stmt = $db->prepare("INSERT INTO counselor_messages (student_id, counselor_id, sender_id, message) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user['id'], $cid, $user['id'], $msg]);
        $new_id = (int)$db->lastInsertId();
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok', 'message_id' => $new_id]);
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
    $db->prepare("UPDATE counselor_messages SET is_read = 1 WHERE student_id = ? AND counselor_id = ? AND sender_id != ? AND is_read = 0")->execute([$user['id'], $counselor_id, $user['id']]);
    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.is_read, cm.sent_at,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name, u.last_name) AS last_name,
               CASE WHEN cm.sender_id = cm.counselor_id THEN 'wellness_admin' ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_id = wa.id AND cm.sender_id = cm.counselor_id
        LEFT JOIN users u ON cm.sender_id = u.id AND cm.sender_id = cm.student_id
        WHERE cm.student_id = ? AND cm.counselor_id = ?
        ORDER BY cm.sent_at ASC
    ");
    $stmt->execute([$user['id'], $counselor_id]);
    $messages = $stmt->fetchAll();
    if ($messages) $last_id = (int)end($messages)['id'];
}

// ── Unread per counselor ───────────────────────────────────────
$unread_map = [];
$stmt = $db->prepare("SELECT counselor_id, COUNT(*) as cnt FROM counselor_messages WHERE student_id = ? AND sender_id != ? AND is_read = 0 GROUP BY counselor_id");
$stmt->execute([$user['id'], $user['id']]);
foreach ($stmt->fetchAll() as $r) $unread_map[(int)$r['counselor_id']] = (int)$r['cnt'];

$last_msgs = [];
foreach ($counselors as $c) {
    $stmt = $db->prepare("SELECT message, sent_at, sender_id FROM counselor_messages WHERE student_id = ? AND counselor_id = ? ORDER BY sent_at DESC LIMIT 1");
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Messages — CTU MindCare</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=DM+Mono:ital@0;1&display=swap" rel="stylesheet">
<style>
:root {
  --teal:        #1EB8A3;
  --teal-2:      #17A190;
  --teal-dark:   #0E6B60;
  --teal-mid:    #A3E8E1;
  --teal-light:  #E8FAF8;
  --teal-glow:   rgba(30,184,163,.18);

  --bg:          #EFF1F5;
  --surface:     #FFFFFF;
  --panel:       #F5F6FA;
  --border:      #E2E5ED;
  --border-2:    #CDD2DE;

  --text:        #181A22;
  --text-2:      #4A5167;
  --text-3:      #8C94A8;

  --coral:       #F05252;
  --coral-light: #FEF2F2;
  --green:       #22C55E;
  --amber:       #F59E0B;
  --blue:        #3B82F6;

  --mine-from:   #1EB8A3;
  --mine-to:     #0E9F8E;
  --them-bg:     #FFFFFF;

  --topbar-h:    58px;
  --sidebar-w:   220px;
  --clist-w:     300px;
}

*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; overflow: hidden; background: var(--bg); color: var(--text); font-family: 'Sora', system-ui, sans-serif; font-size: 14px; -webkit-font-smoothing: antialiased; }
a { text-decoration: none; color: inherit; }
button { font-family: 'Sora', sans-serif; }

::-webkit-scrollbar { width: 3px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: 8px; }

.topbar {
  position: fixed; top: 0; left: 0; right: 0; z-index: 500;
  height: var(--topbar-h);
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; padding: 0 20px; gap: 14px;
  box-shadow: 0 1px 12px rgba(0,0,0,.05);
}
.topbar-logo {
  width: 34px; height: 34px; border-radius: 10px;
  background: linear-gradient(135deg, var(--teal), var(--teal-dark));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 16px; color: #fff; flex-shrink: 0;
  box-shadow: 0 4px 12px var(--teal-glow);
}
.topbar-brand  { font-weight: 700; font-size: 15px; color: var(--text); }
.topbar-school { font-size: 10px; color: var(--text-3); margin-top: 1px; }
.topbar-right  { margin-left: auto; display: flex; align-items: center; gap: 10px; }
.user-chip {
  display: flex; align-items: center; gap: 8px;
  background: var(--teal-light); border: 1px solid var(--teal-mid);
  border-radius: 50px; padding: 4px 14px 4px 4px; cursor: pointer;
  transition: opacity .15s;
}
.user-chip:hover { opacity: .85; }
.user-av {
  width: 28px; height: 28px; border-radius: 50%;
  background: var(--teal); color: #fff;
  font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
}
.user-nm { font-size: 12px; font-weight: 600; color: var(--teal-dark); }
.btn-logout {
  font-size: 12px; font-weight: 500; color: var(--text-3);
  padding: 7px 14px; border-radius: 8px;
  border: 1px solid var(--border); background: transparent;
  cursor: pointer; transition: color .15s, border-color .15s;
}
.btn-logout:hover { color: var(--coral); border-color: var(--coral); }
.hamburger {
  display: none; flex-direction: column; justify-content: center;
  align-items: center; gap: 5px;
  width: 36px; height: 36px; border-radius: 8px;
  background: var(--panel); border: 1px solid var(--border);
  cursor: pointer; flex-shrink: 0;
}
.hamburger span { display: block; width: 18px; height: 2px; background: var(--text-2); border-radius: 2px; transition: all .25s; }
.hamburger.open span:nth-child(1) { transform: translateY(7px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7px) rotate(-45deg); }
.overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.35); z-index: 299; backdrop-filter: blur(2px); }
.overlay.show { display: block; }

.shell {
  display: grid;
  grid-template-columns: var(--sidebar-w) var(--clist-w) 1fr;
  height: calc(100vh - var(--topbar-h));
  margin-top: var(--topbar-h);
  overflow: hidden;
}

.sidebar {
  background: var(--surface);
  border-right: 1px solid var(--border);
  padding: 16px 10px;
  display: flex; flex-direction: column;
  overflow-y: auto;
}
.sb-section { margin-bottom: 4px; }
.sb-label {
  font-size: 9px; font-weight: 700; color: var(--text-3);
  text-transform: uppercase; letter-spacing: 1.5px;
  padding: 0 8px; margin-bottom: 5px; display: block;
}
.nav-item {
  display: flex; align-items: center; gap: 10px;
  padding: 9px 10px; border-radius: 10px;
  font-size: 12.5px; font-weight: 500; color: var(--text-2);
  transition: background .12s, color .12s; margin-bottom: 1px;
}
.nav-item:hover { background: var(--panel); color: var(--text); }
.nav-item.active { background: var(--teal-light); color: var(--teal-dark); }
.nav-icon {
  width: 28px; height: 28px; border-radius: 8px; font-size: 13px;
  display: flex; align-items: center; justify-content: center;
  background: var(--panel); flex-shrink: 0;
}
.nav-item.active .nav-icon { background: var(--teal-mid); }
.nav-item.crisis-nav { color: var(--coral); }
.nav-item.crisis-nav:hover { background: var(--coral-light); }
.nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
.sb-div { height: 1px; background: var(--border); margin: 8px 0; }
.sb-footer { margin-top: auto; padding-top: 12px; }
.sb-user {
  background: var(--panel); border-radius: 16px;
  padding: 10px 12px; display: flex; align-items: center; gap: 10px;
  border: 1px solid var(--border);
}
.su-av {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--teal-mid); color: var(--teal-dark);
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.su-name { font-size: 12px; font-weight: 600; color: var(--text); }
.su-id   { font-size: 10px; color: var(--text-3); }

.clist-pane {
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column; overflow: hidden;
}
.clist-head {
  padding: 18px 16px 10px;
  border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.clist-title { font-size: 18px; font-weight: 700; color: var(--text); letter-spacing: -.4px; }
.clist-sub { font-size: 11px; color: var(--text-3); margin-top: 2px; }
.clist-search { position: relative; margin-top: 12px; }
.clist-search input {
  width: 100%; padding: 8px 12px 8px 34px;
  background: var(--panel); border: 1px solid var(--border);
  border-radius: 50px; font-family: 'Sora', sans-serif;
  font-size: 12px; color: var(--text); outline: none;
  transition: border-color .15s, background .15s;
}
.clist-search input:focus { border-color: var(--teal); background: var(--surface); }
.clist-search input::placeholder { color: var(--text-3); }
.search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; pointer-events: none; }
.clist-body { flex: 1; overflow-y: auto; padding: 8px; }
.clist-item {
  display: flex; align-items: center; gap: 11px;
  padding: 11px 10px; border-radius: 14px;
  cursor: pointer; margin-bottom: 3px;
  border: 1.5px solid transparent;
  transition: background .14s, border-color .14s;
}
.clist-item:hover { background: var(--panel); }
.clist-item.active { background: var(--teal-light); border-color: var(--teal-mid); }
.c-av {
  width: 44px; height: 44px; border-radius: 50%;
  background: var(--teal-mid); color: var(--teal-dark);
  font-size: 13px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; position: relative;
}
.clist-item.active .c-av { background: var(--teal); color: #fff; }
.online-ring {
  position: absolute; bottom: 1px; right: 1px;
  width: 11px; height: 11px; border-radius: 50%;
  background: var(--green); border: 2px solid var(--surface);
}
.clist-item.active .online-ring { border-color: var(--teal-light); }
.c-info { flex: 1; min-width: 0; }
.c-name { font-size: 13px; font-weight: 600; color: var(--text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.c-meta { display: flex; justify-content: space-between; align-items: center; }
.c-time { font-size: 10px; color: var(--text-3); white-space: nowrap; flex-shrink: 0; margin-left: 4px; }
.c-preview { font-size: 11px; color: var(--text-3); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }
.c-preview.unread { font-weight: 700; color: var(--text-2); }
.c-badge {
  min-width: 18px; height: 18px; border-radius: 9px;
  background: var(--coral); color: #fff;
  font-size: 10px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  padding: 0 5px; flex-shrink: 0;
  animation: badgePop .3s cubic-bezier(.34,1.56,.64,1);
}
@keyframes badgePop { from { transform: scale(0); } to { transform: scale(1); } }
.clist-empty { text-align: center; padding: 32px 12px; font-size: 12px; color: var(--text-3); }

.chat-pane { display: flex; flex-direction: column; overflow: hidden; background: var(--bg); position: relative; }

.chat-header {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  padding: 12px 20px;
  display: flex; align-items: center; gap: 12px;
  flex-shrink: 0;
  box-shadow: 0 2px 10px rgba(0,0,0,.04);
  z-index: 10;
  animation: slideDown .25s ease-out;
}
@keyframes slideDown { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
.ch-av-wrap { position: relative; flex-shrink: 0; }
.ch-av {
  width: 42px; height: 42px; border-radius: 50%;
  background: linear-gradient(135deg, var(--teal), var(--teal-dark));
  color: #fff; font-size: 14px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 4px 12px var(--teal-glow);
}
.ch-online {
  position: absolute; bottom: 1px; right: 1px;
  width: 12px; height: 12px; border-radius: 50%;
  background: var(--green); border: 2px solid var(--surface);
  animation: pulse 2.5s ease-in-out infinite;
}
@keyframes pulse { 0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,.4); } 50% { box-shadow: 0 0 0 5px rgba(34,197,94,0); } }
.ch-name { font-size: 15px; font-weight: 700; color: var(--text); line-height: 1.2; letter-spacing: -.3px; }
.ch-sub  { font-size: 11px; color: var(--green); margin-top: 1px; font-weight: 500; }
.ch-lock {
  display: inline-flex; align-items: center; gap: 4px;
  font-size: 10px; font-weight: 600; color: var(--teal-dark);
  background: var(--teal-light); border: 1px solid var(--teal-mid);
  border-radius: 50px; padding: 2px 8px; margin-top: 3px;
}
.ch-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; }
.ch-btn {
  padding: 7px 14px; border: 1px solid var(--border);
  border-radius: 50px; background: var(--surface);
  font-family: 'Sora', sans-serif;
  font-size: 11.5px; font-weight: 600; color: var(--text-2);
  cursor: pointer; transition: all .15s;
  display: inline-flex; align-items: center; gap: 5px;
}
.ch-btn:hover { background: var(--panel); border-color: var(--border-2); color: var(--text); }
.ch-btn.danger { color: var(--coral); border-color: #FECACA; }
.ch-btn.danger:hover { background: var(--coral-light); }

.conn-dot {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--green); transition: background .4s; flex-shrink: 0;
}
.conn-dot.offline      { background: var(--text-3); }
.conn-dot.reconnecting { background: var(--amber); animation: blink 1s ease-in-out infinite; }
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.3} }
.conn-label { font-size: 10px; font-weight: 500; color: var(--text-3); }

.chat-messages {
  flex: 1; overflow-y: auto;
  padding: 20px 20px 10px;
  display: flex; flex-direction: column;
  gap: 0;
  scroll-behavior: smooth;
  background-image: radial-gradient(circle, var(--border) 1px, transparent 1px);
  background-size: 28px 28px;
  background-color: var(--bg);
}

.date-sep {
  display: flex; align-items: center; gap: 10px;
  font-size: 11px; font-weight: 600; color: var(--text-3);
  margin: 20px 0 14px;
  position: sticky; top: 0; z-index: 5;
}
.date-sep::before, .date-sep::after { content: ''; flex: 1; height: 1px; background: var(--border); }
.date-sep span {
  background: var(--surface); padding: 3px 12px;
  border-radius: 50px; border: 1px solid var(--border);
  white-space: nowrap; box-shadow: 0 1px 4px rgba(0,0,0,.05);
}

.sys-msg {
  text-align: center; font-size: 11px; font-weight: 500;
  color: var(--text-3); padding: 5px 14px;
  background: var(--surface); border-radius: 50px;
  border: 1px solid var(--border);
  margin: 0 auto 20px; width: fit-content; max-width: 340px;
  box-shadow: 0 1px 4px rgba(0,0,0,.04);
}

.msg-row {
  display: flex; align-items: flex-end; gap: 8px;
  max-width: 100%;
  animation: msgIn .22s cubic-bezier(.22,.68,0,1.2) both;
}
@keyframes msgIn { from { opacity:0; transform:translateY(10px) scale(.95); } to { opacity:1; transform:translateY(0) scale(1); } }
.msg-row.mine   { flex-direction: row-reverse; }
.msg-row.theirs { flex-direction: row; }
.msg-row.new-group   { margin-top: 16px; }
.msg-row.same-sender { margin-top: 3px; }

.bub-av {
  width: 30px; height: 30px; border-radius: 50%;
  font-size: 11px; font-weight: 700;
  display: flex; align-items: center; justify-content: center;
  flex-shrink: 0; align-self: flex-end;
}
.bub-av.is-mine   { background: var(--teal-dark); color: #fff; }
.bub-av.is-theirs { background: var(--teal-mid); color: var(--teal-dark); }
.bub-av.hidden    { visibility: hidden; }

.bub-wrap { display: flex; flex-direction: column; max-width: min(66%, 480px); }
.msg-row.mine   .bub-wrap { align-items: flex-end; }
.msg-row.theirs .bub-wrap { align-items: flex-start; }
.msg-sender-name {
  font-size: 10.5px; font-weight: 700; color: var(--text-3);
  margin-bottom: 4px; padding: 0 4px;
}
.bubble {
  padding: 10px 14px; font-size: 13.5px; line-height: 1.65;
  word-break: break-word; position: relative;
  transition: transform .1s;
}
.bubble:hover { transform: scale(1.01); }
.msg-row.mine .bubble {
  background: linear-gradient(135deg, var(--mine-from), var(--mine-to));
  color: #fff;
  border-radius: 20px 20px 4px 20px;
  box-shadow: 0 3px 12px rgba(14,159,142,.25);
}
.msg-row.theirs .bubble {
  background: var(--them-bg);
  color: var(--text);
  border-radius: 20px 20px 20px 4px;
  border: 1px solid var(--border);
  box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.msg-row.mine.same-sender   .bubble { border-radius: 20px 4px 4px 20px; }
.msg-row.theirs.same-sender .bubble { border-radius: 4px 20px 20px 4px; }
.msg-row.mine.tail-end      .bubble { border-radius: 20px 4px 4px 20px; }
.msg-row.theirs.tail-end    .bubble { border-radius: 4px 20px 20px 4px; }

.msg-time {
  font-size: 10px; color: var(--text-3);
  margin-top: 4px; padding: 0 4px;
  display: flex; align-items: center; gap: 4px;
  opacity: 0; transition: opacity .2s;
}
.msg-row:hover .msg-time { opacity: 1; }
.tail-end .msg-time { opacity: 1; }
.msg-row.mine .msg-time { justify-content: flex-end; }
.tick-read { color: var(--teal); font-size: 11px; }
.tick-sent { color: var(--text-3); font-size: 11px; }

.typing-row {
  display: none; align-items: flex-end; gap: 8px;
  margin-top: 16px;
  animation: msgIn .22s ease-out;
}
.typing-row.show { display: flex; }
.typing-bubble {
  background: var(--them-bg); border: 1px solid var(--border);
  border-radius: 20px 20px 20px 4px;
  padding: 12px 16px;
  display: flex; gap: 5px; align-items: center;
  box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.t-dot {
  width: 7px; height: 7px; border-radius: 50%;
  background: var(--teal-mid);
  animation: bounce 1.4s ease-in-out infinite;
}
.t-dot:nth-child(2) { animation-delay: .2s; }
.t-dot:nth-child(3) { animation-delay: .4s; }
@keyframes bounce { 0%,60%,100%{transform:translateY(0);background:var(--teal-mid);} 30%{transform:translateY(-7px);background:var(--teal);} }

.empty-chat {
  flex: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  text-align: center; padding: 48px;
  animation: fadeIn .4s ease-out;
}
@keyframes fadeIn { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }
.ec-icon {
  width: 80px; height: 80px; border-radius: 50%;
  background: var(--teal-light); border: 2px solid var(--teal-mid);
  display: flex; align-items: center; justify-content: center;
  font-size: 36px; margin-bottom: 20px;
  box-shadow: 0 8px 24px var(--teal-glow);
  animation: float 3s ease-in-out infinite;
}
@keyframes float { 0%,100%{transform:translateY(0);} 50%{transform:translateY(-8px);} }
.ec-title { font-size: 22px; font-weight: 700; color: var(--text); margin-bottom: 8px; letter-spacing: -.4px; }
.ec-sub   { font-size: 13px; color: var(--text-3); line-height: 1.8; max-width: 280px; }
.ec-btn {
  margin-top: 24px; display: inline-flex; align-items: center; gap: 8px;
  padding: 12px 24px; background: linear-gradient(135deg, var(--teal), var(--teal-dark));
  color: #fff; border-radius: 50px;
  font-size: 13px; font-weight: 600;
  box-shadow: 0 6px 20px var(--teal-glow);
  transition: transform .15s, box-shadow .15s;
}
.ec-btn:hover { transform: translateY(-2px); box-shadow: 0 10px 28px var(--teal-glow); }

.no-msgs-state {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 60px 40px; text-align: center;
}
.nm-icon  { font-size: 48px; margin-bottom: 14px; animation: wave 1.5s ease-in-out 1; }
@keyframes wave { 0%{transform:rotate(0deg);} 20%{transform:rotate(-15deg);} 40%{transform:rotate(15deg);} 60%{transform:rotate(-10deg);} 80%{transform:rotate(10deg);} 100%{transform:rotate(0deg);} }
.nm-title { font-size: 17px; font-weight: 700; color: var(--text); margin-bottom: 6px; }
.nm-sub   { font-size: 12px; color: var(--text-3); line-height: 1.7; max-width: 260px; }

.input-bar {
  background: var(--surface);
  border-top: 1px solid var(--border);
  padding: 12px 16px 14px;
  flex-shrink: 0;
}
.input-tools { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
.tool-btn {
  width: 30px; height: 30px; border-radius: 8px;
  background: transparent; border: 1px solid var(--border);
  cursor: pointer; font-size: 14px;
  display: flex; align-items: center; justify-content: center;
  color: var(--text-3); transition: background .15s, color .15s;
}
.tool-btn:hover { background: var(--panel); color: var(--text-2); }
.input-row { display: flex; align-items: flex-end; gap: 10px; }
.msg-area {
  flex: 1; padding: 11px 16px;
  background: var(--panel); border: 1.5px solid var(--border);
  border-radius: 24px; font-family: 'Sora', sans-serif;
  font-size: 13.5px; color: var(--text); outline: none;
  resize: none; min-height: 46px; max-height: 140px;
  line-height: 1.55; overflow-y: hidden;
  transition: border-color .2s, box-shadow .2s, background .2s;
}
.msg-area:focus {
  background: var(--surface);
  border-color: var(--teal);
  box-shadow: 0 0 0 3px rgba(30,184,163,.12);
}
.msg-area::placeholder { color: var(--text-3); }
.send-btn {
  width: 46px; height: 46px; border-radius: 50%;
  background: linear-gradient(135deg, var(--teal), var(--teal-dark));
  border: none; cursor: pointer; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: transform .15s, box-shadow .15s, opacity .15s;
  box-shadow: 0 4px 16px var(--teal-glow);
}
.send-btn:hover   { transform: scale(1.08); box-shadow: 0 8px 24px var(--teal-glow); }
.send-btn:active  { transform: scale(.90); }
.send-btn:disabled { opacity: .35; cursor: not-allowed; transform: none; box-shadow: none; }
.send-btn svg { width: 18px; height: 18px; fill: none; stroke: #fff; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
.input-hint { font-size: 10.5px; color: var(--text-3); margin-top: 7px; text-align: center; }

.reconnect-bar {
  display: none; background: var(--amber); color: #fff;
  font-size: 11.5px; font-weight: 600; text-align: center;
  padding: 6px; flex-shrink: 0;
  animation: slideDown .2s ease-out;
}
.reconnect-bar.show { display: block; }

.emoji-panel {
  display: none; position: absolute; bottom: 70px; left: 16px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 10px; width: 280px;
  box-shadow: 0 8px 32px rgba(0,0,0,.12);
  z-index: 50;
  animation: popUp .18s cubic-bezier(.22,.68,0,1.2);
}
@keyframes popUp { from{opacity:0;transform:translateY(8px) scale(.95);} to{opacity:1;transform:translateY(0) scale(1);} }
.emoji-panel.show { display: block; }
.emoji-grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 2px; }
.em-btn {
  width: 32px; height: 32px; border-radius: 6px;
  background: transparent; border: none; cursor: pointer;
  font-size: 18px; display: flex; align-items: center; justify-content: center;
  transition: background .1s, transform .1s;
}
.em-btn:hover { background: var(--panel); transform: scale(1.2); }

@media (max-width: 1024px) {
  .shell { grid-template-columns: var(--clist-w) 1fr; }
  .hamburger { display: flex; }
  .sidebar {
    position: fixed; top: var(--topbar-h); left: 0; bottom: 0; z-index: 300;
    transform: translateX(-100%); transition: transform .28s ease;
    width: 240px; box-shadow: 4px 0 24px rgba(0,0,0,.12);
    height: calc(100vh - var(--topbar-h));
  }
  .sidebar.open { transform: translateX(0); }
}
@media (max-width: 680px) {
  .shell { grid-template-columns: 1fr; }
  .clist-pane { display: none; }
  .ch-actions { display: none; }
}
</style>
</head>
<body>

<div class="overlay" id="overlay"></div>

<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" aria-label="Menu" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
  <div class="topbar-logo">M</div>
  <div>
    <div class="topbar-brand">CTU MindCare</div>
    <div class="topbar-school">CTU Consolacion</div>
  </div>
  <div class="topbar-right">
    <div style="display:flex;align-items:center;gap:5px">
      <div class="conn-dot" id="connDot"></div>
      <span class="conn-label" id="connLabel">Live</span>
    </div>
    <a class="user-chip" href="profile.php">
      <div class="user-av"><?= $my_initials ?></div>
      <span class="user-nm"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </a>
    <a class="btn-logout" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="shell">

  <aside class="sidebar" id="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div>Dashboard</a>
      <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div>Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div>Assessments</a>
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div>My progress</a>
      <a class="nav-item" href="journal.php"><div class="nav-icon">📓</div>Journal</a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-section">
      <span class="sb-label">Support</span>
      <a class="nav-item" href="appointments.php"><div class="nav-icon">📅</div>Book appointment</a>
      <a class="nav-item active" href="counselor-chat.php"><div class="nav-icon">💬</div>Counselor chat</a>
      <a class="nav-item" href="resources.php"><div class="nav-icon">📚</div>Resources</a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-section">
      <span class="sb-label">Community</span>
      <a class="nav-item" href="forum.php"><div class="nav-icon">🗣️</div>Peer forum</a>
      <a class="nav-item" href="peer-chat.php"><div class="nav-icon">💭</div>Peer chat</a>
    </div>
    <div class="sb-div"></div>
    <div class="sb-section">
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div>Crisis help</a>
    </div>
    <div class="sb-footer">
      <div class="sb-user">
        <div class="su-av"><?= $my_initials ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean((string)$student_display_id) ?></div>
        </div>
      </div>
    </div>
  </aside>

  <div class="clist-pane">
    <div class="clist-head">
      <div class="clist-title">Messages</div>
      <div class="clist-sub"><?= count($counselors) ?> counselor<?= count($counselors) !== 1 ? 's' : '' ?> available</div>
      <div class="clist-search">
        <span class="search-icon">🔍</span>
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
          $unread = $unread_map[(int)$c['id']] ?? 0;
          $last_m = $last_msgs[(int)$c['id']] ?? null;
          $preview = '';
          $last_t  = '';
          if ($last_m) {
              $prefix  = (int)$last_m['sender_id'] === (int)$user['id'] ? 'You: ' : '';
              $preview = $prefix . mb_substr(strip_tags($last_m['message']), 0, 28) . (mb_strlen($last_m['message']) > 28 ? '…' : '');
              $last_t  = timeLabel($last_m['sent_at']);
          }
        ?>
          <a class="clist-item <?= $is_act ? 'active' : '' ?>"
             data-name="<?= htmlspecialchars(strtolower($c['first_name'].' '.$c['last_name'])) ?>"
             href="counselor-chat.php?counselor=<?= $c['id'] ?>">
            <div class="c-av"><?= $ci ?><div class="online-ring"></div></div>
            <div class="c-info">
              <div class="c-meta">
                <div class="c-name"><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                <?php if ($last_t): ?><div class="c-time"><?= $last_t ?></div><?php endif; ?>
              </div>
              <div class="c-preview <?= $unread ? 'unread' : '' ?>">
                <?= $preview ? clean($preview) : 'Wellness Counselor' ?>
              </div>
            </div>
            <?php if ($unread): ?><div class="c-badge"><?= $unread ?></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div class="chat-pane" id="chatPane">

    <?php if (!$active_counselor): ?>
    <div class="empty-chat">
      <div class="ec-icon">💬</div>
      <div class="ec-title">Select a counselor</div>
      <div class="ec-sub">Choose a wellness counselor from the list to start a private, secure conversation.</div>
      <a class="ec-btn" href="appointments.php">📅 Book an appointment</a>
    </div>

    <?php else: ?>

    <div class="chat-header">
      <div class="ch-av-wrap">
        <div class="ch-av"><?= $c_initials ?></div>
        <div class="ch-online"></div>
      </div>
      <div>
        <div class="ch-name"><?= clean($active_counselor['first_name'].' '.$active_counselor['last_name']) ?></div>
        <div class="ch-sub">● Active now</div>
        <div class="ch-lock">🔒 Private &amp; confidential</div>
      </div>
      <div class="ch-actions">
        <a class="ch-btn" href="appointments.php">📅 Book session</a>
        <a class="ch-btn danger" href="crisis.php">🆘 Crisis</a>
      </div>
    </div>

    <div class="reconnect-bar" id="reconnectBar">⚠️ Connection lost — attempting to reconnect…</div>

    <div class="chat-messages" id="chatMessages">

      <?php if (empty($messages)): ?>
      <div class="no-msgs-state" id="emptyState">
        <div class="nm-icon">👋</div>
        <div class="nm-title">Say hello!</div>
        <div class="nm-sub">Start a conversation with <?= clean($active_counselor['first_name']) ?>. Everything you share is private and confidential.</div>
      </div>

      <?php else: ?>
      <div class="sys-msg" id="sysMsg">🔒 Only you and your counselor can read this</div>

     <?php
  $prev_date   = '';
  $prev_sender = -1;
  $prev_role   = '';

  foreach ($messages as $i => $msg):
    // FIX: Compare sender ID directly
    $is_mine = ((int)$msg['sender_id'] === (int)$user['id']);
    $sender_role = $msg['sender_role'] ?? ($is_mine ? 'student' : 'wellness_admin');
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

    $row_cls  = 'msg-row ' . ($is_mine ? 'mine' : 'theirs');
    $row_cls .= $new_group ? ' new-group' : ' same-sender';
    $row_cls .= $is_tail  ? ' tail-end' : '';

    $av_init = $is_mine ? $my_initials : $c_initials;
    $av_cls  = $is_mine ? 'is-mine' : 'is-theirs';

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
        <div class="bub-av is-theirs"><?= $c_initials ?></div>
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

    <div class="input-bar">
      <div class="input-tools">
        <button class="tool-btn" id="emojiBtn" title="Emoji" type="button">😊</button>
        <div style="margin-left:auto;font-size:10px;color:var(--text-3)" id="charCount"></div>
      </div>
      <div class="input-row">
        <textarea
          class="msg-area" id="msgInput"
          placeholder="Message <?= clean($active_counselor['first_name']) ?>…"
          rows="1" maxlength="2000"
          onkeydown="handleKey(event)"
          oninput="onInput(this)"></textarea>
        <button class="send-btn" id="sendBtn" disabled title="Send (Enter)" type="button" onclick="sendMessage()">
          <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        </button>
      </div>
      <div class="input-hint">Press <kbd style="background:var(--panel);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px">Enter</kbd> to send &nbsp;·&nbsp; <kbd style="background:var(--panel);border:1px solid var(--border);border-radius:4px;padding:1px 5px;font-size:10px">Shift+Enter</kbd> for new line</div>
    </div>

    <?php endif; ?>
  </div><!-- .chat-pane -->
</div><!-- .shell -->

<script>
'use strict';

const COUNSELOR_ID = <?= (int)$counselor_id ?>;
const MY_ID        = <?= (int)$user['id'] ?>;
const MY_INIT      = '<?= $my_initials ?>';
const C_INIT       = '<?= $c_initials ?>';
const MAX_LEN      = 2000;

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

    if (data.messages?.length) {
      const wasNear = nearBottom();
      data.messages.forEach(m => {
        const id = parseInt(m.id, 10);
        lastId = Math.max(lastId, id);

        // Handle temp message replacement (our own message that was waiting for ID)
        const tempMsg = chatMessages?.querySelector(`[data-id^="tmp-"]`);
        if (tempMsg && parseInt(m.sender_id, 10) === MY_ID) {
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
        if (parseInt(m.sender_id, 10) !== MY_ID) {
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

// ── Append bubble ─────────────────────────────────────────────
function appendBubble(msg, isMine) {
  if (!chatMessages) return;
  const id = String(msg.id);
  if (id && !id.startsWith('tmp') && chatMessages.querySelector(`[data-id="${id}"]`)) return;

  if (!document.getElementById('sysMsg')) {
    const s = document.createElement('div');
    s.id = 'sysMsg'; s.className = 'sys-msg';
    s.textContent = '🔒 Only you and your counselor can read this';
    chatMessages.insertBefore(s, typingRow);
  }
  document.getElementById('emptyState')?.remove();

  const rows    = [...chatMessages.querySelectorAll('.msg-row')];
  const lastRow = rows.length ? rows[rows.length - 1] : null;
  const lastIsMine = lastRow?.classList.contains('mine');
  const newGroup   = !lastRow || (isMine ? !lastIsMine : lastIsMine);

  if (!newGroup && lastRow) {
    lastRow.classList.remove('tail-end');
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
  row.className = `msg-row ${isMine ? 'mine' : 'theirs'} ${newGroup ? 'new-group' : 'same-sender'} tail-end`;

  const avCls  = isMine ? 'is-mine' : 'is-theirs';
  const avInit = isMine ? MY_INIT : C_INIT;
  const text   = escHtml(msg.message).replace(/\n/g, '<br>');
  const time   = fmtTime(msg.sent_at);
  
  // FIX: Show counselor name when message is from counselor (not isMine)
  let senderHtml = '';
  if (!isMine && newGroup && msg.sender_role === 'wellness_admin') {
    const senderName = msg.first_name && msg.last_name 
      ? `${msg.first_name} ${msg.last_name}`
      : 'Counselor';
    senderHtml = `<div class="msg-sender-name">${escHtml(senderName)}</div>`;
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

// ── Mobile sidebar ─────────────────────────────────────────────
(function() {
  const btn = document.getElementById('hamburgerBtn');
  const sb  = document.getElementById('sidebar');
  const ov  = document.getElementById('overlay');
  if (!btn || !sb || !ov) return;
  function open()  { sb.classList.add('open'); ov.classList.add('show'); btn.classList.add('open'); btn.setAttribute('aria-expanded','true'); document.body.style.overflow='hidden'; }
  function close() { sb.classList.remove('open'); ov.classList.remove('show'); btn.classList.remove('open'); btn.setAttribute('aria-expanded','false'); document.body.style.overflow=''; }
  btn.addEventListener('click', () => sb.classList.contains('open') ? close() : open());
  ov.addEventListener('click', close);
  sb.querySelectorAll('.nav-item').forEach(l => l.addEventListener('click', () => { if (window.innerWidth <= 1024) close(); }));
  window.addEventListener('resize', () => { if (window.innerWidth > 1024) close(); });
})();

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

</body>
</html>