<?php
// ============================================================
//  CTU MindCare — Wellness Journal
//  File: student/pages/journal.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// ── Actions ──────────────────────────────────────────────────
$action = $_GET['action'] ?? 'list';   // list | new | edit | view | delete
$entry_id = (int)($_GET['id'] ?? 0);

$error   = '';
$success = '';

// ── DELETE ────────────────────────────────────────────────────
if ($action === 'delete' && $entry_id) {
    $stmt = $db->prepare("DELETE FROM journal_entries WHERE id = ? AND user_id = ?");
    $stmt->execute([$entry_id, $user['id']]);
    setFlash('success', 'Journal entry deleted.');
    header('Location: journal.php');
    exit;
}

// ── SAVE (new or edit) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body  = trim($_POST['body']  ?? '');
    $pid   = (int)($_POST['entry_id'] ?? 0);

    if (strlen($body) < 3) {
        $error = 'Please write something before saving.';
    } else {
        if ($pid) {
            // Update — verify ownership
            $stmt = $db->prepare("UPDATE journal_entries SET title = ?, body = ? WHERE id = ? AND user_id = ?");
            $stmt->execute([$title ?: null, $body, $pid, $user['id']]);
            setFlash('success', 'Entry updated successfully.');
            header('Location: journal.php?action=view&id=' . $pid);
        } else {
            // Insert
            $stmt = $db->prepare("INSERT INTO journal_entries (user_id, title, body) VALUES (?, ?, ?)");
            $stmt->execute([$user['id'], $title ?: null, $body]);
            $new_id = $db->lastInsertId();
            setFlash('success', 'Entry saved to your journal.');
            header('Location: journal.php?action=view&id=' . $new_id);
        }
        exit;
    }
}

// ── FETCH single entry (view/edit) ───────────────────────────
$entry = null;
if (in_array($action, ['view', 'edit']) && $entry_id) {
    $stmt = $db->prepare("SELECT * FROM journal_entries WHERE id = ? AND user_id = ?");
    $stmt->execute([$entry_id, $user['id']]);
    $entry = $stmt->fetch();
    if (!$entry) {
        header('Location: journal.php');
        exit;
    }
}

// ── LIST all entries ──────────────────────────────────────────
$search = trim($_GET['q'] ?? '');
if ($search) {
    $stmt = $db->prepare("
        SELECT id, title, body, created_at, updated_at
        FROM journal_entries
        WHERE user_id = ?
          AND (title LIKE ? OR body LIKE ?)
        ORDER BY updated_at DESC
    ");
    $like = '%' . $search . '%';
    $stmt->execute([$user['id'], $like, $like]);
} else {
    $stmt = $db->prepare("
        SELECT id, title, body, created_at, updated_at
        FROM journal_entries
        WHERE user_id = ?
        ORDER BY updated_at DESC
    ");
    $stmt->execute([$user['id']]);
}
$entries = $stmt->fetchAll();

// ── Stats ─────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT COUNT(*) as total FROM journal_entries WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_entries = (int)$stmt->fetch()['total'];

// Entries this month
$stmt = $db->prepare("
    SELECT COUNT(*) as cnt
    FROM journal_entries
    WHERE user_id = ? AND DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')
");
$stmt->execute([$user['id']]);
$this_month = (int)$stmt->fetch()['cnt'];

// Streak: distinct days with at least one entry, consecutive going back from today
$stmt = $db->prepare("
    SELECT DISTINCT DATE(created_at) as day
    FROM journal_entries
    WHERE user_id = ?
    ORDER BY day DESC
    LIMIT 60
");
$stmt->execute([$user['id']]);
$j_days = array_column($stmt->fetchAll(), 'day');
$j_streak = 0;
for ($i = 0; $i < count($j_days); $i++) {
    $expected = date('Y-m-d', strtotime("-{$i} days"));
    if (($j_days[$i] ?? '') === $expected) $j_streak++;
    else break;
}

// Today's entry (if any)
$stmt = $db->prepare("
    SELECT id FROM journal_entries
    WHERE user_id = ? AND DATE(created_at) = CURDATE()
    ORDER BY created_at DESC LIMIT 1
");
$stmt->execute([$user['id']]);
$today_entry_id = $stmt->fetchColumn();

// ── Prompts pool ──────────────────────────────────────────────
$prompts = [
    "What's been on your mind today?",
    "Describe one moment that made you smile recently.",
    "What are three things you're grateful for right now?",
    "What's something challenging you're working through?",
    "How have you been taking care of yourself this week?",
    "Write about a goal you're working toward.",
    "What emotion am I feeling most strongly today, and why?",
    "Who is someone that has supported me lately?",
    "What would I tell a friend going through what I'm going through?",
    "What's one small win I had today, no matter how tiny?",
    "If I could change one thing about this week, what would it be?",
    "What's draining my energy right now, and what's giving me energy?",
];
$today_prompt = $prompts[date('z') % count($prompts)]; // rotate by day of year

$flash = getFlash();

// ── Helpers ──────────────────────────────────────────────────
function excerpt(string $text, int $len = 120): string {
    $text = strip_tags($text);
    return mb_strlen($text) > $len ? mb_substr($text, 0, $len) . '…' : $text;
}
function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wellness Journal — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    /* ── Variables ── */
    :root {
      --sage:       #4A9B7F;
      --sage-2:     #3d8a6f;
      --sage-dark:  #2D6B57;
      --sage-mid:   #C4E8DC;
      --sage-light: #EAF7F2;
      --sand:       #F7F3EE;
      --sand-2:     #EDE8E1;
      --sand-3:     #D9D3C9;
      --white:      #FFFFFF;
      --text:       #2C2C2A;
      --text-2:     #5C5B57;
      --text-3:     #9A9891;
      --border:     #E8E4DE;
      --red:        #D64545;
      --amber:      #D4860A;
      --amber-light:#FDF3DF;
      --coral:      #D96B52;
      --coral-light:#FAECE7;
      --lav:        #7B6FC4;
      --lav-light:  #EEEDFE;
      --topbar-h:   58px;
      --sidebar-w:  230px;
    }

    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      font-family: 'DM Sans', sans-serif;
      font-size: 14px; color: var(--text);
      background: var(--sand); min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }

    /* ── Topbar ── */
    .topbar {
      height: var(--topbar-h); background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px;
      position: sticky; top: 0; z-index: 200; gap: 16px;
    }
    .topbar-brand  { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark   { width: 34px; height: 34px; background: var(--sage); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; }
    .topbar-name   { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
    .topbar-school { font-size: 10px; color: var(--text-3); margin-top: 1px; }
    .topbar-right  { display: flex; align-items: center; gap: 12px; }
    .user-chip     { display: flex; align-items: center; gap: 8px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 100px; padding: 5px 14px 5px 6px; }
    .user-av       { width: 26px; height: 26px; border-radius: 50%; background: var(--sage); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; }
    .user-name     { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
    .logout-btn    { font-size: 12px; color: var(--text-3); padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: color .15s, border-color .15s; }
    .logout-btn:hover { color: var(--coral); border-color: var(--coral); }

    /* ── Layout ── */
    .layout { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: calc(100vh - var(--topbar-h)); }

    /* ── Sidebar ── */
    .sidebar {
      background: var(--white); border-right: 1px solid var(--border);
      padding: 20px 0; position: sticky; top: var(--topbar-h);
      height: calc(100vh - var(--topbar-h)); overflow-y: auto;
      display: flex; flex-direction: column;
    }
    .sb-section   { padding: 0 14px; margin-bottom: 6px; }
    .sb-label     { font-size: 9px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; padding: 0 8px; margin-bottom: 4px; display: block; }
    .nav-item     { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--sand); color: var(--text); }
    .nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .nav-icon     { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: var(--sand); }
    .nav-item.active .nav-icon { background: var(--sage-mid); }
    .nav-item.crisis-nav { color: var(--coral); }
    .nav-item.crisis-nav:hover { background: var(--coral-light); }
    .nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
    .sb-divider   { height: 1px; background: var(--border); margin: 10px 14px; }
    .sb-footer    { margin-top: auto; padding: 14px; }
    .sb-user-card { background: var(--sand); border-radius: 10px; padding: 12px; display: flex; align-items: center; gap: 10px; }
    .su-av        { width: 32px; height: 32px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .su-name      { font-size: 12px; font-weight: 500; color: var(--text); }
    .su-id        { font-size: 10px; color: var(--text-3); margin-top: 1px; }

    /* ── Main ── */
    .main { padding: 0; display: flex; flex-direction: column; min-height: calc(100vh - var(--topbar-h)); }

    /* ── Flash ── */
    .flash-bar {
      padding: 11px 28px; font-size: 13px; display: flex; align-items: center; gap: 8px;
      border-bottom: 1px solid transparent;
    }
    .flash-success { background: var(--sage-light); border-color: var(--sage-mid); color: var(--sage-dark); }
    .flash-warning { background: var(--amber-light); border-color: #FAC775; color: #412402; }
    .flash-error   { background: #FBEDED; border-color: #F5C6C2; color: #8B2020; }

    /* ── Journal shell (two-pane) ── */
    .journal-shell {
      display: grid;
      grid-template-columns: 300px 1fr;
      flex: 1;
      overflow: hidden;
    }

    /* ── Left pane: entry list ── */
    .entry-pane {
      border-right: 1px solid var(--border);
      display: flex; flex-direction: column;
      background: var(--white);
      height: calc(100vh - var(--topbar-h) <?= $flash ? '- 42px' : '' ?>);
      position: sticky;
      top: var(--topbar-h);
    }
    .ep-header {
      padding: 16px 16px 12px;
      border-bottom: 1px solid var(--border);
      flex-shrink: 0;
    }
    .ep-top {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;
    }
    .ep-title { font-size: 13px; font-weight: 600; color: var(--text); }
    .ep-count { font-size: 11px; color: var(--text-3); }
    .btn-new-entry {
      display: flex; align-items: center; gap: 6px;
      padding: 7px 14px; background: var(--sage); color: #fff;
      border: none; border-radius: 8px; font-family: 'DM Sans', sans-serif;
      font-size: 12px; font-weight: 500; cursor: pointer;
      text-decoration: none; transition: background .15s; white-space: nowrap;
    }
    .btn-new-entry:hover { background: var(--sage-2); }

    /* Search */
    .search-wrap { position: relative; }
    .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; color: var(--text-3); pointer-events: none; }
    .search-input {
      width: 100%; padding: 8px 10px 8px 32px;
      background: var(--sand); border: 1.5px solid var(--border);
      border-radius: 8px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; color: var(--text); outline: none;
      transition: border-color .15s, background .15s;
    }
    .search-input:focus { background: var(--white); border-color: var(--sage); }
    .search-input::placeholder { color: var(--text-3); }

    /* Entry list */
    .entry-list { flex: 1; overflow-y: auto; padding: 8px; }
    .entry-item {
      padding: 12px; border-radius: 10px; cursor: pointer;
      border: 1.5px solid transparent; margin-bottom: 4px;
      transition: all .12s; text-decoration: none; display: block;
    }
    .entry-item:hover  { background: var(--sand); border-color: var(--border); }
    .entry-item.active { background: var(--sage-light); border-color: var(--sage-mid); }
    .ei-date    { font-size: 10px; color: var(--text-3); margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between; }
    .ei-title   { font-size: 13px; font-weight: 500; color: var(--text); margin-bottom: 4px; line-height: 1.3; }
    .ei-excerpt { font-size: 12px; color: var(--text-3); line-height: 1.5; }
    .ei-dot     { width: 6px; height: 6px; border-radius: 50%; background: var(--sage); flex-shrink: 0; }

    /* Stats strip at bottom of pane */
    .pane-stats {
      padding: 10px 14px; border-top: 1px solid var(--border);
      display: flex; gap: 16px; flex-shrink: 0;
    }
    .ps-item    { text-align: center; flex: 1; }
    .ps-val     { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--text); }
    .ps-label   { font-size: 10px; color: var(--text-3); margin-top: 1px; }

    /* ── Right pane: content ── */
    .content-pane {
      display: flex; flex-direction: column;
      overflow-y: auto;
      background: var(--sand);
    }

    /* ── Editor ── */
    .editor-wrap    { max-width: 720px; margin: 0 auto; width: 100%; padding: 32px 32px 64px; }
    .editor-header  { margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .editor-back    { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-3); padding: 6px 10px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: all .12s; }
    .editor-back:hover { border-color: var(--sage); color: var(--sage); }
    .editor-date    { font-size: 12px; color: var(--text-3); margin-left: auto; }

    .title-input {
      width: 100%; background: transparent; border: none; outline: none;
      font-family: 'DM Serif Display', serif; font-size: 28px; color: var(--text);
      padding: 0 0 8px; border-bottom: 2px solid var(--border);
      margin-bottom: 20px; transition: border-color .15s;
    }
    .title-input:focus { border-color: var(--sage); }
    .title-input::placeholder { color: var(--sand-3); }

    .prompt-pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--lav-light); border: 1px solid #CBC6F0;
      border-radius: 20px; padding: 5px 12px;
      font-size: 12px; color: var(--lav); margin-bottom: 14px;
      cursor: pointer; transition: background .12s;
    }
    .prompt-pill:hover { background: #e0defe; }

    .body-textarea {
      width: 100%; min-height: 420px;
      background: var(--white); border: 1.5px solid var(--border);
      border-radius: 12px; padding: 20px;
      font-family: 'DM Sans', sans-serif; font-size: 15px;
      color: var(--text); outline: none; resize: vertical;
      line-height: 1.8; transition: border-color .15s, box-shadow .15s;
    }
    .body-textarea:focus {
      border-color: var(--sage);
      box-shadow: 0 0 0 4px rgba(74,155,127,.08);
    }
    .body-textarea::placeholder { color: var(--sand-3); }

    .editor-footer {
      display: flex; align-items: center; justify-content: space-between;
      margin-top: 14px; flex-wrap: wrap; gap: 10px;
    }
    .ef-meta   { font-size: 11px; color: var(--text-3); }
    .ef-right  { display: flex; gap: 10px; align-items: center; }
    .btn-save  {
      padding: 11px 28px; background: var(--sage); color: #fff;
      border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; font-weight: 500; cursor: pointer;
      transition: background .15s, transform .1s;
    }
    .btn-save:hover  { background: var(--sage-2); }
    .btn-save:active { transform: scale(.99); }
    .btn-cancel { padding: 11px 20px; background: var(--white); color: var(--text-2); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; transition: border-color .15s; }
    .btn-cancel:hover { border-color: var(--sage); color: var(--sage); }

    /* Error box */
    .error-box { display: flex; align-items: center; gap: 10px; background: #FBEDED; border: 1px solid #F5C6C2; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #7A1A1A; margin-bottom: 16px; }

    /* ── View entry ── */
    .view-wrap       { max-width: 720px; margin: 0 auto; width: 100%; padding: 32px 32px 64px; }
    .view-header     { margin-bottom: 8px; }
    .view-nav        { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
    .view-date       { font-size: 12px; color: var(--text-3); margin-bottom: 8px; }
    .view-title      { font-family: 'DM Serif Display', serif; font-size: 30px; color: var(--text); line-height: 1.2; margin-bottom: 6px; }
    .view-meta-row   { display: flex; align-items: center; gap: 12px; margin-bottom: 28px; flex-wrap: wrap; }
    .view-pill       { font-size: 11px; padding: 3px 10px; border-radius: 20px; background: var(--sand-2); color: var(--text-3); }
    .view-divider    { height: 1px; background: var(--border); margin-bottom: 28px; }
    .view-body       { font-size: 15px; line-height: 1.9; color: var(--text-2); white-space: pre-wrap; word-break: break-word; }
    .view-actions    { display: flex; gap: 10px; margin-top: 36px; padding-top: 20px; border-top: 1px solid var(--border); }
    .btn-edit        { padding: 10px 22px; background: var(--sage); color: #fff; border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background .15s; }
    .btn-edit:hover  { background: var(--sage-2); }
    .btn-delete      { padding: 10px 22px; background: var(--white); color: var(--red); border: 1.5px solid #F5C6C2; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
    .btn-delete:hover { background: #FBEDED; border-color: var(--red); }
    .btn-outline-sm  { padding: 10px 18px; background: var(--white); color: var(--text-2); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: border-color .15s; }
    .btn-outline-sm:hover { border-color: var(--sage); color: var(--sage); }

    /* ── Empty / Welcome ── */
    .welcome-wrap  { max-width: 540px; margin: 80px auto; padding: 0 32px; text-align: center; }
    .welcome-icon  { font-size: 56px; margin-bottom: 16px; }
    .welcome-title { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); margin-bottom: 8px; }
    .welcome-sub   { font-size: 14px; color: var(--text-3); line-height: 1.7; margin-bottom: 28px; }
    .btn-start     { display: inline-flex; align-items: center; gap: 8px; padding: 13px 28px; background: var(--sage); color: #fff; border-radius: 10px; font-size: 14px; font-weight: 500; transition: background .15s; }
    .btn-start:hover { background: var(--sage-2); }

    .prompt-card {
      background: var(--white); border: 1px solid var(--border); border-radius: 12px;
      padding: 16px 18px; margin-top: 24px; text-align: left;
    }
    .pc-label  { font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); margin-bottom: 8px; }
    .pc-prompt { font-size: 14px; color: var(--text-2); line-height: 1.6; font-style: italic; }

    /* ── No search results ── */
    .no-results { padding: 32px 16px; text-align: center; }
    .nr-icon    { font-size: 32px; margin-bottom: 8px; }
    .nr-text    { font-size: 13px; color: var(--text-3); }

    /* Confirm overlay */
    .confirm-overlay {
      display: none; position: fixed; inset: 0; z-index: 500;
      background: rgba(0,0,0,.4); backdrop-filter: blur(2px);
      align-items: center; justify-content: center;
    }
    .confirm-overlay.show { display: flex; }
    .confirm-box {
      background: var(--white); border-radius: 16px; padding: 28px 28px 24px;
      max-width: 380px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.15);
    }
    .cb-icon  { font-size: 36px; margin-bottom: 10px; }
    .cb-title { font-family: 'DM Serif Display', serif; font-size: 20px; margin-bottom: 6px; }
    .cb-body  { font-size: 13px; color: var(--text-3); margin-bottom: 20px; line-height: 1.6; }
    .cb-btns  { display: flex; gap: 10px; }
    .cb-cancel { flex: 1; padding: 11px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; }
    .cb-confirm { flex: 1; padding: 11px; background: var(--red); color: #fff; border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: background .15s; }
    .cb-confirm:hover { background: #B83030; }

    /* word count */
    .word-count { font-size: 11px; color: var(--text-3); }

    @media (max-width: 900px) {
      .hamburger       { display: flex; }
      .layout          { grid-template-columns: 1fr; }
      .sidebar         { position: fixed; top: 58px; left: 0; bottom: 0; z-index: 300; transform: translateX(-100%); transition: transform .28s ease; height: calc(100vh - 58px); width: 270px; box-shadow: 4px 0 24px rgba(0,0,0,.12); overflow-y: auto; }
      .sidebar.mobile-open { transform: translateX(0); }
      .main            { padding: 20px 16px; }
      
      .journal-shell   { grid-template-columns: 1fr; }
      .entry-pane      { display: none; }
    }
  
/* ── Hamburger button ── */
.hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand,#F7F3EE);border:1px solid var(--border,#E8E4DE);cursor:pointer;padding:0;flex-shrink:0;}
.hamburger span{display:block;width:18px;height:2px;background:var(--text-2,#5C5B57);border-radius:2px;transition:all .25s ease;}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex!important;}
  .layout{grid-template-columns:1fr!important;}
  .sidebar{position:fixed!important;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px)!important;width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);display:flex!important;}
  .sidebar.mobile-open{transform:translateX(0);}
  .main,.main.main{padding:20px 16px!important;margin-left:0!important;}
}
@media(max-width:480px){.topbar-school{display:none;}.user-name{display:none;}.logout-btn{display:none;}}

</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- ── Topbar ── -->
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
      <div class="user-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
      <span class="user-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="layout">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
      <a class="nav-item" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
      <a class="nav-item active" href="journal.php"><div class="nav-icon">📓</div> Wellness journal</a>
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
      <a class="nav-item" href="peer-chat.php"><div class="nav-icon">💭</div> Peer chat</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
    </div>
    <div class="sb-footer">
      <div class="sb-user-card">
        <div class="su-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash-bar flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="journal-shell">

      <!-- ══ LEFT PANE: entry list ════════════════════════════ -->
      <div class="entry-pane">
        <div class="ep-header">
          <div class="ep-top">
            <div>
              <span class="ep-title">My Journal</span>
              <span class="ep-count" style="margin-left:6px"><?= $total_entries ?> <?= $total_entries === 1 ? 'entry' : 'entries' ?></span>
            </div>
            <a class="btn-new-entry" href="journal.php?action=new">
              ✏ New
            </a>
          </div>
          <!-- Search -->
          <form method="GET" action="journal.php">
            <div class="search-wrap">
              <span class="search-icon">🔍</span>
              <input
                class="search-input"
                type="text"
                name="q"
                placeholder="Search entries…"
                value="<?= clean($search) ?>"
                autocomplete="off"
              >
            </div>
          </form>
        </div>

        <div class="entry-list">
          <?php if (empty($entries) && !$search): ?>
            <div class="no-results" style="padding-top:48px">
              <div class="nr-icon">📓</div>
              <div class="nr-text">No entries yet.<br>Write your first one!</div>
            </div>
          <?php elseif (empty($entries) && $search): ?>
            <div class="no-results">
              <div class="nr-icon">🔍</div>
              <div class="nr-text">No entries matching<br>"<?= clean($search) ?>"</div>
            </div>
          <?php else: ?>
            <?php
            // Group by month
            $grouped = [];
            foreach ($entries as $e) {
                $month = date('F Y', strtotime($e['created_at']));
                $grouped[$month][] = $e;
            }
            foreach ($grouped as $month => $month_entries):
            ?>
              <div style="font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);padding:10px 8px 4px"><?= $month ?></div>
              <?php foreach ($month_entries as $e):
                $is_active = (in_array($action, ['view','edit']) && $entry_id === (int)$e['id']);
                $e_title   = $e['title'] ?: 'Untitled entry';
              ?>
                <a class="entry-item <?= $is_active ? 'active' : '' ?>"
                   href="journal.php?action=view&id=<?= $e['id'] ?>">
                  <div class="ei-date">
                    <span><?= date('D, M j · g:i A', strtotime($e['created_at'])) ?></span>
                    <?php if (strtotime($e['updated_at']) > strtotime($e['created_at']) + 5): ?>
                      <span style="font-size:9px;color:var(--text-3)">edited</span>
                    <?php endif; ?>
                  </div>
                  <div class="ei-title"><?= clean($e_title) ?></div>
                  <div class="ei-excerpt"><?= clean(excerpt($e['body'])) ?></div>
                </a>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Stats strip -->
        <div class="pane-stats">
          <div class="ps-item">
            <div class="ps-val"><?= $total_entries ?></div>
            <div class="ps-label">Total</div>
          </div>
          <div class="ps-item">
            <div class="ps-val"><?= $this_month ?></div>
            <div class="ps-label">This month</div>
          </div>
          <div class="ps-item">
            <div class="ps-val"><?= $j_streak ?></div>
            <div class="ps-label">Day streak</div>
          </div>
        </div>
      </div>
      <!-- end LEFT PANE -->

      <!-- ══ RIGHT PANE ════════════════════════════════════════ -->
      <div class="content-pane">

        <?php if ($action === 'new' || ($action === 'edit' && $entry)): ?>
        <!-- ── EDITOR ── -->
        <div class="editor-wrap">
          <div class="editor-header">
            <a class="editor-back" href="journal.php<?= $entry ? '?action=view&id='.$entry['id'] : '' ?>">
              ← <?= $entry ? 'Cancel' : 'Back' ?>
            </a>
            <span class="editor-date"><?= date('l, F j, Y') ?></span>
          </div>

          <?php if ($error): ?>
            <div class="error-box">⚠ <?= clean($error) ?></div>
          <?php endif; ?>

          <form method="POST" action="journal.php" id="editorForm">
            <input type="hidden" name="entry_id" value="<?= $entry ? $entry['id'] : 0 ?>">

            <input
              class="title-input"
              type="text"
              name="title"
              id="titleInput"
              placeholder="Give this entry a title…"
              value="<?= clean($entry['title'] ?? ($_POST['title'] ?? '')) ?>"
              maxlength="200"
              autocomplete="off"
            >

            <?php if ($action === 'new'): ?>
              <div class="prompt-pill" id="promptPill" title="Click to use this prompt" onclick="usePrompt()">
                💡 <span id="promptText"><?= clean($today_prompt) ?></span>
              </div>
            <?php endif; ?>

            <textarea
              class="body-textarea"
              name="body"
              id="bodyTextarea"
              placeholder="Start writing…"
              oninput="updateWordCount(this)"
            ><?= clean($entry['body'] ?? ($_POST['body'] ?? '')) ?></textarea>

            <div class="editor-footer">
              <div class="ef-meta">
                <span class="word-count" id="wordCount">0 words</span>
                <?php if ($entry): ?>
                  &nbsp;·&nbsp; Last saved <?= timeAgo($entry['updated_at']) ?>
                <?php endif; ?>
                &nbsp;·&nbsp; <span style="color:var(--sage-dark);font-size:11px">🔒 Only visible to you</span>
              </div>
              <div class="ef-right">
                <a class="btn-cancel" href="journal.php<?= $entry ? '?action=view&id='.$entry['id'] : '' ?>">Cancel</a>
                <button type="submit" class="btn-save" id="saveBtn">
                  <?= $entry ? '💾 Update entry' : '💾 Save entry' ?>
                </button>
              </div>
            </div>
          </form>
        </div>

        <?php elseif ($action === 'view' && $entry): ?>
        <!-- ── VIEW ENTRY ── -->
        <div class="view-wrap">
          <div class="view-nav">
            <a class="editor-back" href="journal.php">← All entries</a>
            <?php
            // Prev / next navigation
            $ids = array_column($entries, 'id');
            $curr_pos = array_search((int)$entry['id'], $ids);
            $prev_id  = $curr_pos > 0 ? $ids[$curr_pos - 1] : null;
            $next_id  = ($curr_pos !== false && $curr_pos < count($ids) - 1) ? $ids[$curr_pos + 1] : null;
            ?>
            <div style="display:flex;gap:4px;margin-left:auto">
              <?php if ($prev_id): ?>
                <a class="editor-back" href="journal.php?action=view&id=<?= $prev_id ?>" title="Newer entry">↑</a>
              <?php endif; ?>
              <?php if ($next_id): ?>
                <a class="editor-back" href="journal.php?action=view&id=<?= $next_id ?>" title="Older entry">↓</a>
              <?php endif; ?>
            </div>
          </div>

          <div class="view-date"><?= date('l, F j, Y · g:i A', strtotime($entry['created_at'])) ?></div>
          <div class="view-title"><?= $entry['title'] ? clean($entry['title']) : 'Untitled entry' ?></div>

          <div class="view-meta-row">
            <?php
            $wc = str_word_count(strip_tags($entry['body']));
            $read_mins = max(1, ceil($wc / 200));
            ?>
            <span class="view-pill">📖 <?= $wc ?> words</span>
            <span class="view-pill">⏱ <?= $read_mins ?> min read</span>
            <?php if (strtotime($entry['updated_at']) > strtotime($entry['created_at']) + 5): ?>
              <span class="view-pill">✏ Edited <?= timeAgo($entry['updated_at']) ?></span>
            <?php endif; ?>
            <span class="view-pill">🔒 Private</span>
          </div>

          <div class="view-divider"></div>

          <div class="view-body"><?= nl2br(clean($entry['body'])) ?></div>

          <div class="view-actions">
            <a class="btn-edit" href="journal.php?action=edit&id=<?= $entry['id'] ?>">✏ Edit entry</a>
            <a class="btn-outline-sm" href="journal.php?action=new">+ New entry</a>
            <button class="btn-delete" onclick="confirmDelete(<?= $entry['id'] ?>)">🗑 Delete</button>
          </div>
        </div>

        <?php else: ?>
        <!-- ── WELCOME / EMPTY STATE ── -->
        <div class="welcome-wrap">
          <?php if ($total_entries === 0): ?>
            <div class="welcome-icon">📓</div>
            <div class="welcome-title">Your Wellness Journal</div>
            <div class="welcome-sub">
              A private space to reflect, process your thoughts, and track your emotional journey.
              Only you can see your entries — write freely and honestly.
            </div>
            <a class="btn-start" href="journal.php?action=new">✏ &nbsp;Write your first entry</a>
            <div class="prompt-card">
              <div class="pc-label">Today's writing prompt</div>
              <div class="pc-prompt">"<?= clean($today_prompt) ?>"</div>
            </div>
          <?php else: ?>
            <div class="welcome-icon">👈</div>
            <div class="welcome-title">Select an entry</div>
            <div class="welcome-sub">
              Choose an entry from the list to read it, or write something new.
            </div>
            <?php if ($today_entry_id): ?>
              <a class="btn-outline-sm" href="journal.php?action=view&id=<?= $today_entry_id ?>" style="margin-right:8px">📖 Today's entry</a>
            <?php else: ?>
              <a class="btn-start" href="journal.php?action=new">✏ &nbsp;Write today's entry</a>
              <div class="prompt-card">
                <div class="pc-label">Today's writing prompt</div>
                <div class="pc-prompt">"<?= clean($today_prompt) ?>"</div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php endif; ?>

      </div>
      <!-- end RIGHT PANE -->

    </div><!-- .journal-shell -->
  </main>
</div>

<!-- ── Delete confirm modal ── -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <div class="cb-icon">🗑</div>
    <div class="cb-title">Delete this entry?</div>
    <div class="cb-body">This action cannot be undone. Your journal entry will be permanently deleted.</div>
    <div class="cb-btns">
      <button class="cb-cancel" onclick="hideConfirm()">Keep it</button>
      <a class="cb-confirm" id="confirmDeleteBtn" href="#">Yes, delete</a>
    </div>
  </div>
</div>

<script>
// ── Word count ────────────────────────────────────────────────
function updateWordCount(el) {
  const words = el.value.trim() ? el.value.trim().split(/\s+/).length : 0;
  const wc = document.getElementById('wordCount');
  if (wc) wc.textContent = words + (words === 1 ? ' word' : ' words');
}

// Init word count
const bodyEl = document.getElementById('bodyTextarea');
if (bodyEl && bodyEl.value.trim()) updateWordCount(bodyEl);

// ── Use today's prompt ────────────────────────────────────────
function usePrompt() {
  const text   = document.getElementById('promptText')?.textContent || '';
  const textarea = document.getElementById('bodyTextarea');
  if (!textarea) return;
  if (!textarea.value.trim()) {
    textarea.value = text + '\n\n';
  } else {
    textarea.value += '\n\n' + text + '\n';
  }
  textarea.focus();
  // Move cursor to end
  textarea.setSelectionRange(textarea.value.length, textarea.value.length);
  updateWordCount(textarea);
  // Visually dismiss pill
  const pill = document.getElementById('promptPill');
  if (pill) { pill.style.opacity = '.4'; pill.style.pointerEvents = 'none'; }
}

// ── Auto-resize textarea ──────────────────────────────────────
if (bodyEl) {
  bodyEl.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.max(420, this.scrollHeight) + 'px';
  });
  // Init height
  if (bodyEl.value) {
    bodyEl.style.height = 'auto';
    bodyEl.style.height = Math.max(420, bodyEl.scrollHeight) + 'px';
  }
}

// ── Auto-save draft (localStorage) ───────────────────────────
const DRAFT_KEY = 'mindcare_journal_draft_<?= $user['id'] ?>';
const titleEl   = document.getElementById('titleInput');
const saveBtn   = document.getElementById('saveBtn');

<?php if ($action === 'new'): ?>
// Restore draft
const draft = localStorage.getItem(DRAFT_KEY);
if (draft) {
  try {
    const d = JSON.parse(draft);
    if (titleEl && !titleEl.value && d.title) titleEl.value = d.title;
    if (bodyEl  && !bodyEl.value  && d.body)  {
      bodyEl.value = d.body;
      updateWordCount(bodyEl);
      bodyEl.style.height = 'auto';
      bodyEl.style.height = Math.max(420, bodyEl.scrollHeight) + 'px';
    }
  } catch(e) {}
}

// Save draft on input
function saveDraft() {
  const data = {
    title: titleEl ? titleEl.value : '',
    body:  bodyEl  ? bodyEl.value  : '',
    ts:    Date.now()
  };
  localStorage.setItem(DRAFT_KEY, JSON.stringify(data));
}
if (titleEl) titleEl.addEventListener('input', saveDraft);
if (bodyEl)  bodyEl.addEventListener('input',  saveDraft);

// Clear draft on submit
const form = document.getElementById('editorForm');
if (form) form.addEventListener('submit', () => localStorage.removeItem(DRAFT_KEY));
<?php endif; ?>

// ── Delete confirmation ───────────────────────────────────────
function confirmDelete(id) {
  document.getElementById('confirmDeleteBtn').href = 'journal.php?action=delete&id=' + id;
  document.getElementById('confirmOverlay').classList.add('show');
}
function hideConfirm() {
  document.getElementById('confirmOverlay').classList.remove('show');
}
document.getElementById('confirmOverlay')?.addEventListener('click', function(e) {
  if (e.target === this) hideConfirm();
});

// ── Keyboard shortcuts ────────────────────────────────────────
document.addEventListener('keydown', function(e) {
  // Ctrl/Cmd + S = save
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    e.preventDefault();
    const f = document.getElementById('editorForm');
    if (f) f.submit();
  }
  // Escape = close modal
  if (e.key === 'Escape') hideConfirm();
});
</script>

<script>
(function(){
  var btn=document.getElementById('hamburgerBtn');
  var sb=document.querySelector('.sidebar');
  var ov=document.getElementById('sidebarOverlay');
  if(!btn||!sb||!ov)return;
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