<?php
// ============================================================
//  CTU MindCare — Peer Forum
//  File: student/pages/forum.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$view     = $_GET['view']   ?? 'list';   // list | post | new
$post_id  = (int)($_GET['id'] ?? 0);
$cat_filter = $_GET['cat'] ?? 'all';
$search   = trim($_GET['q'] ?? '');

$valid_cats = ['all','stress','anxiety','depression','tips','academics','general'];
if (!in_array($cat_filter, $valid_cats)) $cat_filter = 'all';

$error = '';

// ── REACT (toggle) ────────────────────────────────────────────
if (isset($_GET['react']) && ($pid = (int)$_GET['react'])) {
    // Check if already reacted
    $stmt = $db->prepare("SELECT id FROM forum_reactions WHERE post_id = ? AND user_id = ?");
    $stmt->execute([$pid, $user['id']]);
    if ($stmt->fetch()) {
        $db->prepare("DELETE FROM forum_reactions WHERE post_id = ? AND user_id = ?")->execute([$pid, $user['id']]);
    } else {
        $db->prepare("INSERT INTO forum_reactions (post_id, user_id) VALUES (?, ?)")->execute([$pid, $user['id']]);
    }
    // AJAX
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM forum_reactions WHERE post_id = ?");
        $stmt->execute([$pid]);
        header('Content-Type: application/json');
        echo json_encode(['count' => (int)$stmt->fetchColumn()]);
        exit;
    }
    header('Location: forum.php?view=post&id=' . $pid);
    exit;
}

// ── FLAG POST ─────────────────────────────────────────────────
if (isset($_GET['flag']) && ($pid = (int)$_GET['flag'])) {
    $db->prepare("UPDATE forum_posts SET is_flagged = 1 WHERE id = ? AND is_flagged = 0")->execute([$pid]);
    setFlash('success', 'Post reported. Our team will review it.');
    header('Location: forum.php?view=post&id=' . $pid);
    exit;
}

// ── FLAG REPLY ────────────────────────────────────────────────
if (isset($_GET['flag_reply']) && ($rid = (int)$_GET['flag_reply'])) {
    $stmt = $db->prepare("SELECT post_id FROM forum_replies WHERE id = ?");
    $stmt->execute([$rid]);
    $r = $stmt->fetch();
    $db->prepare("UPDATE forum_replies SET is_flagged = 1 WHERE id = ?")->execute([$rid]);
    setFlash('success', 'Reply reported. Our team will review it.');
    header('Location: forum.php?view=post&id=' . ($r['post_id'] ?? 0));
    exit;
}

// ── DELETE POST ───────────────────────────────────────────────
if (isset($_GET['delete_post']) && ($pid = (int)$_GET['delete_post'])) {
    // Verify ownership before deleting
    $stmt = $db->prepare("SELECT id FROM forum_posts WHERE id = ? AND user_id = ?");
    $stmt->execute([$pid, $user['id']]);
    if ($stmt->fetch()) {
        $db->prepare("UPDATE forum_posts SET is_removed = 1 WHERE id = ? AND user_id = ?")
           ->execute([$pid, $user['id']]);
        setFlash('success', 'Your post has been deleted.');
    }
    header('Location: forum.php');
    exit;
}

// ── DELETE REPLY ──────────────────────────────────────────────
if (isset($_GET['delete_reply']) && ($rid = (int)$_GET['delete_reply'])) {
    // Get post_id first for redirect
    $stmt = $db->prepare("SELECT post_id FROM forum_replies WHERE id = ? AND user_id = ?");
    $stmt->execute([$rid, $user['id']]);
    $r = $stmt->fetch();
    if ($r) {
        $db->prepare("DELETE FROM forum_replies WHERE id = ? AND user_id = ?")
           ->execute([$rid, $user['id']]);
        setFlash('success', 'Your reply has been deleted.');
        header('Location: forum.php?view=post&id=' . $r['post_id'] . '#replies');
        exit;
    }
    header('Location: forum.php');
    exit;
}

// ── SUBMIT NEW POST ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'new_post') {
    $title     = trim($_POST['title']     ?? '');
    $body      = trim($_POST['body']      ?? '');
    $category  = trim($_POST['category']  ?? '');
    $anon      = isset($_POST['anonymous']) ? 1 : 0;
    $valid_post_cats = ['stress','anxiety','depression','tips','academics','general'];

    if (strlen($title) < 3)                              $error = 'Title must be at least 3 characters.';
    elseif (strlen($body) < 10)                          $error = 'Post body must be at least 10 characters.';
    elseif (!in_array($category, $valid_post_cats))      $error = 'Please select a valid category.';
    else {
        $stmt = $db->prepare("INSERT INTO forum_posts (user_id, category, title, body, is_anonymous) VALUES (?,?,?,?,?)");
        $stmt->execute([$user['id'], $category, $title, $body, $anon]);
        $new_pid = $db->lastInsertId();
        setFlash('success', 'Your post has been published!');
        header('Location: forum.php?view=post&id=' . $new_pid);
        exit;
    }
}

// ── SUBMIT REPLY ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reply') {
    $reply_body = trim($_POST['body'] ?? '');
    $reply_pid  = (int)($_POST['post_id'] ?? 0);
    $anon       = isset($_POST['anonymous']) ? 1 : 0;

    if (strlen($reply_body) < 2) {
        $error = 'Reply cannot be empty.';
    } else {
        $stmt = $db->prepare("INSERT INTO forum_replies (post_id, user_id, body, is_anonymous) VALUES (?,?,?,?)");
        $stmt->execute([$reply_pid, $user['id'], $reply_body, $anon]);
        setFlash('success', 'Reply posted!');
        header('Location: forum.php?view=post&id=' . $reply_pid . '#replies');
        exit;
    }
}

// ── LOAD SINGLE POST ──────────────────────────────────────────
$post    = null;
$replies = [];
if ($view === 'post' && $post_id) {
    $stmt = $db->prepare("
        SELECT fp.*, u.first_name, u.last_name, u.display_name,
               (SELECT COUNT(*) FROM forum_reactions WHERE post_id = fp.id) AS react_count,
               (SELECT COUNT(*) FROM forum_reactions WHERE post_id = fp.id AND user_id = ?) AS user_reacted,
               (SELECT COUNT(*) FROM forum_replies WHERE post_id = fp.id) AS reply_count
        FROM forum_posts fp
        JOIN users u ON fp.user_id = u.id
        WHERE fp.id = ? AND fp.is_removed = 0
    ");
    $stmt->execute([$user['id'], $post_id]);
    $post = $stmt->fetch();

    if (!$post) { header('Location: forum.php'); exit; }

    $stmt = $db->prepare("
        SELECT fr.*, u.first_name, u.last_name, u.display_name
        FROM forum_replies fr
        JOIN users u ON fr.user_id = u.id
        WHERE fr.post_id = ? AND fr.is_flagged = 0
        ORDER BY fr.created_at ASC
    ");
    $stmt->execute([$post_id]);
    $replies = $stmt->fetchAll();
}

// ── LOAD POST LIST ────────────────────────────────────────────
$posts = [];
if ($view === 'list') {
    $where  = ['fp.is_removed = 0'];
    $params = [$user['id']];

    if ($cat_filter !== 'all') { $where[] = 'fp.category = ?'; $params[] = $cat_filter; }
    if ($search !== '')        { $where[] = '(fp.title LIKE ? OR fp.body LIKE ?)'; $like = '%'.$search.'%'; $params[] = $like; $params[] = $like; }

    $stmt = $db->prepare("
        SELECT fp.id, fp.title, fp.body, fp.category, fp.is_anonymous, fp.created_at,
               u.first_name, u.last_name, u.display_name,
               (SELECT COUNT(*) FROM forum_reactions  WHERE post_id = fp.id) AS react_count,
               (SELECT COUNT(*) FROM forum_reactions  WHERE post_id = fp.id AND user_id = ?) AS user_reacted,
               (SELECT COUNT(*) FROM forum_replies    WHERE post_id = fp.id) AS reply_count
        FROM forum_posts fp
        JOIN users u ON fp.user_id = u.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY fp.created_at DESC
        LIMIT 40
    ");
    $stmt->execute($params);
    $posts = $stmt->fetchAll();
}

// ── Category counts ───────────────────────────────────────────
$stmt = $db->prepare("SELECT category, COUNT(*) as cnt FROM forum_posts WHERE is_removed = 0 GROUP BY category");
$stmt->execute();
$cat_counts = ['all' => 0];
foreach ($stmt->fetchAll() as $r) {
    $cat_counts[$r['category']] = (int)$r['cnt'];
    $cat_counts['all'] += (int)$r['cnt'];
}

// ── Helpers ──────────────────────────────────────────────────
function catColor(string $c): array {
    return match($c) {
        'stress'     => ['bg'=>'#FDF3DF','text'=>'#7A4F0A','border'=>'#FAC775'],
        'anxiety'    => ['bg'=>'#EEEDFE','text'=>'#4B3FA0','border'=>'#CBC6F0'],
        'depression' => ['bg'=>'#FBEDED','text'=>'#7A1A1A','border'=>'#F5C6C2'],
        'tips'       => ['bg'=>'#EAF7F2','text'=>'#2D6B57','border'=>'#C4E8DC'],
        'academics'  => ['bg'=>'#E0F2FE','text'=>'#0369A1','border'=>'#BAE6FD'],
        'general'    => ['bg'=>'#F7F3EE','text'=>'#5C5B57','border'=>'#D9D3C9'],
        default      => ['bg'=>'#F7F3EE','text'=>'#5C5B57','border'=>'#D9D3C9'],
    };
}
function catIcon(string $c): string {
    return match($c) {
        'stress'     => '😤',
        'anxiety'    => '😰',
        'depression' => '😔',
        'tips'       => '💡',
        'academics'  => '📚',
        'general'    => '💬',
        default      => '💬',
    };
}
function postAuthor(array $p): string {
    if ($p['is_anonymous']) return 'Anonymous';
    $name = trim(($p['display_name'] ?? '') ?: ($p['first_name'] . ' ' . $p['last_name']));
    return $name ?: 'Student';
}
function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff/60) . 'm ago';
    if ($diff < 86400)  return floor($diff/3600) . 'h ago';
    if ($diff < 604800) return floor($diff/86400) . 'd ago';
    return date('M j, Y', strtotime($dt));
}
function excerpt(string $t, int $len = 130): string {
    $t = strip_tags($t);
    return mb_strlen($t) > $len ? mb_substr($t,0,$len).'…' : $t;
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Peer Forum — CTU MindCare</title>
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
      --red:        #D64545; --amber:     #D4860A; --amber-light:#FDF3DF;
      --coral:      #D96B52; --coral-light:#FAECE7;
      --lav:        #7B6FC4; --lav-light: #EEEDFE;
      --blue-light: #E0F2FE; --blue:      #0369A1;
      --topbar-h:   58px;    --sidebar-w: 230px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { font-family: 'DM Sans', sans-serif; font-size: 14px; color: var(--text); background: var(--sand); min-height: 100vh; -webkit-font-smoothing: antialiased; }
    a { text-decoration: none; color: inherit; }

    /* Topbar */
    .topbar { height: var(--topbar-h); background: var(--white); border-bottom: 1px solid var(--border); display: flex; align-items: center; padding: 0 24px; position: sticky; top: 0; z-index: 200; gap: 16px; }
    .topbar-brand { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark  { width: 34px; height: 34px; background: var(--sage); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; }
    .topbar-name  { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
    .topbar-school{ font-size: 10px; color: var(--text-3); margin-top: 1px; }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .user-chip    { display: flex; align-items: center; gap: 8px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 100px; padding: 5px 14px 5px 6px; }
    .user-av      { width: 26px; height: 26px; border-radius: 50%; background: var(--sage); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; }
    .user-name    { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
    .logout-btn   { font-size: 12px; color: var(--text-3); padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: color .15s, border-color .15s; }
    .logout-btn:hover { color: var(--coral); border-color: var(--coral); }

    /* Layout */
    .layout { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: calc(100vh - var(--topbar-h)); }

    /* Sidebar */
    .sidebar { background: var(--white); border-right: 1px solid var(--border); padding: 20px 0; position: sticky; top: var(--topbar-h); height: calc(100vh - var(--topbar-h)); overflow-y: auto; display: flex; flex-direction: column; }
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

    /* Main */
    .main { padding: 28px 28px 56px; }

    /* Flash */
    .flash { padding: 11px 16px; border-radius: 9px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
    .flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
    .flash-warning { background: var(--amber-light); border: 1px solid #FAC775; color: #412402; }

    /* Page header */
    .page-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 24px; gap: 16px; flex-wrap: wrap; }
    .page-title  { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); margin-bottom: 4px; }
    .page-sub    { font-size: 13px; color: var(--text-3); line-height: 1.6; }
    .btn-new-post { display: inline-flex; align-items: center; gap: 7px; padding: 10px 20px; background: var(--sage); color: #fff; border-radius: 10px; font-size: 13px; font-weight: 500; transition: background .15s; flex-shrink: 0; }
    .btn-new-post:hover { background: var(--sage-2); }

    /* Forum two-col */
    .forum-grid { display: grid; grid-template-columns: 1fr 260px; gap: 20px; align-items: start; }

    /* Filter sidebar (right col) */
    .forum-sidebar { display: flex; flex-direction: column; gap: 16px; }
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 18px; }
    .card-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 12px; }

    /* Search */
    .search-wrap { position: relative; }
    .search-icon-abs { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); font-size: 13px; color: var(--text-3); pointer-events: none; }
    .search-input { width: 100%; padding: 9px 10px 9px 32px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--text); outline: none; transition: border-color .15s; }
    .search-input:focus { border-color: var(--sage); background: var(--white); }
    .search-input::placeholder { color: var(--text-3); }

    /* Category filter list */
    .cat-list { display: flex; flex-direction: column; gap: 2px; }
    .cat-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 10px; border-radius: 8px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: background .12s; cursor: pointer; }
    .cat-item:hover  { background: var(--sand); }
    .cat-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .cat-item-left   { display: flex; align-items: center; gap: 8px; }
    .cat-count { font-size: 11px; color: var(--text-3); background: var(--sand-2); padding: 1px 7px; border-radius: 10px; }
    .cat-item.active .cat-count { background: var(--sage-mid); color: var(--sage-dark); }

    /* Guidelines card */
    .guideline-list { display: flex; flex-direction: column; gap: 8px; }
    .guideline-item { display: flex; align-items: flex-start; gap: 8px; font-size: 12px; color: var(--text-2); line-height: 1.5; }
    .guideline-icon { font-size: 14px; flex-shrink: 0; margin-top: 1px; }

    /* Post list */
    .post-list { display: flex; flex-direction: column; gap: 12px; }

    /* Post card */
    .post-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 18px 20px; transition: box-shadow .15s, transform .15s; cursor: pointer; display: block; text-decoration: none; }
    .post-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,.08); transform: translateY(-1px); border-color: var(--sage-mid); }
    .post-card-top   { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
    .post-cat-badge  { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 600; padding: 3px 9px; border-radius: 20px; border: 1px solid; text-transform: uppercase; letter-spacing: .04em; flex-shrink: 0; }
    .post-title      { font-size: 15px; font-weight: 600; color: var(--text); line-height: 1.4; margin-bottom: 6px; }
    .post-excerpt    { font-size: 13px; color: var(--text-3); line-height: 1.6; margin-bottom: 12px; }
    .post-meta       { display: flex; align-items: center; gap: 12px; font-size: 12px; color: var(--text-3); flex-wrap: wrap; }
    .post-author     { display: flex; align-items: center; gap: 6px; }
    .post-av         { width: 22px; height: 22px; border-radius: 50%; background: var(--sand-2); color: var(--text-3); font-size: 9px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .post-av.anon    { background: var(--sand-3); }
    .meta-dot        { color: var(--sand-3); }
    .post-stats      { display: flex; align-items: center; gap: 10px; margin-left: auto; }
    .stat-btn        { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--text-3); padding: 4px 8px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); cursor: pointer; transition: all .12s; font-family: inherit; }
    .stat-btn:hover  { border-color: var(--sage-mid); color: var(--sage); }
    .stat-btn.reacted { background: var(--coral-light); border-color: #F5C6B0; color: var(--coral); }

    /* Empty state */
    .empty-state { text-align: center; padding: 56px 24px; }
    .es-icon  { font-size: 48px; margin-bottom: 14px; }
    .es-title { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--text); margin-bottom: 8px; }
    .es-sub   { font-size: 13px; color: var(--text-3); line-height: 1.7; margin-bottom: 20px; }
    .es-btn   { display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; background: var(--sage); color: #fff; border-radius: 9px; font-size: 13px; font-weight: 500; }
    .es-btn:hover { background: var(--sage-2); }

    /* ── SINGLE POST VIEW ── */
    .post-view { max-width: 760px; }
    .back-link { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-3); padding: 6px 10px; border: 1px solid var(--border); border-radius: 7px; background: var(--white); transition: all .12s; margin-bottom: 20px; }
    .back-link:hover { border-color: var(--sage); color: var(--sage); }

    .post-full { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 28px; margin-bottom: 20px; }
    .pf-cat   { margin-bottom: 14px; }
    .pf-title { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); line-height: 1.3; margin-bottom: 12px; }
    .pf-meta  { display: flex; align-items: center; gap: 12px; font-size: 12px; color: var(--text-3); margin-bottom: 20px; flex-wrap: wrap; }
    .pf-divider { height: 1px; background: var(--border); margin: 20px 0; }
    .pf-body  { font-size: 14px; line-height: 1.9; color: var(--text-2); white-space: pre-wrap; word-break: break-word; }
    .pf-actions { display: flex; align-items: center; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border); }
    .pf-react-btn { display: flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 9px; border: 1.5px solid var(--border); background: var(--white); font-family: inherit; font-size: 13px; cursor: pointer; transition: all .15s; }
    .pf-react-btn:hover  { border-color: var(--coral); color: var(--coral); background: var(--coral-light); }
    .pf-react-btn.reacted { background: var(--coral-light); border-color: #F5C6B0; color: var(--coral); }
    .pf-flag-btn  { font-size: 11px; color: var(--text-3); padding: 8px 14px; border: 1px solid var(--border); border-radius: 9px; background: var(--white); cursor: pointer; font-family: inherit; transition: all .15s; margin-left: auto; }
    .pf-flag-btn:hover { color: var(--red); border-color: #F5C6C2; }

    /* Replies */
    .replies-section { margin-bottom: 20px; }
    .replies-title   { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
    .reply-count-badge { font-size: 11px; background: var(--sand-2); color: var(--text-3); padding: 2px 8px; border-radius: 10px; }
    .reply-list  { display: flex; flex-direction: column; gap: 2px; }
    .reply-item  { padding: 14px 16px; border-radius: 10px; background: var(--sand); border: 1px solid var(--border); }
    .reply-item:first-child { border-top-left-radius: 14px; border-top-right-radius: 14px; }
    .reply-item:last-child  { border-bottom-left-radius: 14px; border-bottom-right-radius: 14px; }
    .ri-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .ri-av      { width: 28px; height: 28px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ri-av.anon { background: var(--sand-3); color: var(--text-3); }
    .ri-name    { font-size: 12px; font-weight: 500; color: var(--text); }
    .ri-time    { font-size: 11px; color: var(--text-3); margin-left: auto; }
    .ri-flag    { font-size: 10px; color: var(--text-3); padding: 2px 8px; border: 1px solid var(--border); border-radius: 5px; background: var(--white); cursor: pointer; font-family: inherit; transition: color .12s; }
    .ri-flag:hover { color: var(--red); }
    .ri-body    { font-size: 13px; color: var(--text-2); line-height: 1.7; white-space: pre-wrap; word-break: break-word; }

    /* Reply form */
    .reply-form-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 20px; }
    .rf-title { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 12px; }
    .form-textarea { width: 100%; padding: 12px 14px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--text); outline: none; resize: vertical; min-height: 100px; line-height: 1.6; transition: border-color .15s, box-shadow .15s, background .15s; }
    .form-textarea:focus { background: var(--white); border-color: var(--sage); box-shadow: 0 0 0 3px rgba(74,155,127,.08); }
    .form-textarea::placeholder { color: var(--text-3); }
    .anon-toggle { display: flex; align-items: center; gap: 8px; margin-top: 10px; font-size: 12px; color: var(--text-2); cursor: pointer; user-select: none; }
    .anon-toggle input { width: 14px; height: 14px; accent-color: var(--sage); cursor: pointer; }
    .reply-form-foot { display: flex; align-items: center; justify-content: space-between; margin-top: 14px; flex-wrap: wrap; gap: 8px; }
    .btn-reply-submit { padding: 10px 24px; background: var(--sage); color: #fff; border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: background .15s; }
    .btn-reply-submit:hover { background: var(--sage-2); }

    /* ── NEW POST FORM ── */
    .new-post-wrap { max-width: 680px; }
    .np-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 28px; }
    .np-title { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--text); margin-bottom: 4px; }
    .np-sub   { font-size: 13px; color: var(--text-3); margin-bottom: 24px; }
    .form-group   { margin-bottom: 18px; }
    .form-label   { display: block; font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
    .form-input   { width: 100%; padding: 10px 12px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--text); outline: none; transition: border-color .15s, background .15s; }
    .form-input:focus { background: var(--white); border-color: var(--sage); }
    .form-input::placeholder { color: var(--text-3); }
    .form-select { width: 100%; padding: 10px 12px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; color: var(--text); outline: none; appearance: none; -webkit-appearance: none; transition: border-color .15s; }
    .form-select:focus { background: var(--white); border-color: var(--sage); }
    .char-count   { font-size: 11px; color: var(--text-3); text-align: right; margin-top: 4px; }
    .error-box    { display: flex; align-items: center; gap: 10px; background: #FBEDED; border: 1px solid #F5C6C2; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #7A1A1A; margin-bottom: 18px; }
    .np-footer    { display: flex; gap: 10px; align-items: center; margin-top: 8px; flex-wrap: wrap; }
    .btn-post-submit { padding: 12px 28px; background: var(--sage); color: #fff; border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500; cursor: pointer; transition: background .15s; }
    .btn-post-submit:hover { background: var(--sage-2); }
    .btn-cancel { padding: 12px 20px; background: var(--white); color: var(--text-2); border: 1.5px solid var(--border); border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; transition: border-color .15s; text-decoration: none; display: inline-flex; align-items: center; }
    .btn-cancel:hover { border-color: var(--sage); color: var(--sage); }

    /* Anon note */
    .anon-note { background: var(--lav-light); border: 1px solid #CBC6F0; border-radius: 9px; padding: 10px 14px; font-size: 12px; color: var(--lav); line-height: 1.6; margin-top: 4px; display: none; }
    .anon-note.show { display: block; }

    /* Delete post button */
    .pf-delete-btn { font-size: 12px; color: var(--red); padding: 8px 14px; border: 1.5px solid #F5C6C2; border-radius: 9px; background: var(--white); cursor: pointer; font-family: inherit; transition: all .15s; display: inline-flex; align-items: center; gap: 5px; }
    .pf-delete-btn:hover { background: #FBEDED; border-color: var(--red); }

    /* Delete reply button */
    .ri-delete { color: var(--red) !important; }
    .ri-delete:hover { background: #FBEDED !important; border-color: #F5C6C2 !important; color: var(--red) !important; }

    /* Delete confirm modal */
    .delete-overlay { display: none; position: fixed; inset: 0; z-index: 500; background: rgba(0,0,0,.45); backdrop-filter: blur(3px); align-items: center; justify-content: center; }
    .delete-overlay.show { display: flex; }
    .delete-box { background: var(--white); border-radius: 18px; padding: 32px 28px 26px; max-width: 400px; width: 90%; box-shadow: 0 24px 64px rgba(0,0,0,.18); text-align: center; }
    .db-icon    { font-size: 40px; margin-bottom: 12px; }
    .db-title   { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--text); margin-bottom: 8px; }
    .db-body    { font-size: 13px; color: var(--text-3); line-height: 1.6; margin-bottom: 14px; }
    .db-post-title { font-size: 13px; font-weight: 500; color: var(--text); background: var(--sand); border-radius: 8px; padding: 10px 14px; margin-bottom: 20px; word-break: break-word; text-align: left; }
    .db-btns    { display: flex; gap: 10px; }
    .db-keep    { flex: 1; padding: 12px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; transition: background .12s; }
    .db-keep:hover { background: var(--sand-2); }
    .db-confirm { flex: 1; padding: 12px; background: var(--red); color: #fff; border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; transition: background .15s; }
    .db-confirm:hover { background: #B83030; }

    @media (max-width: 1100px) { .forum-grid { grid-template-columns: 1fr; } .forum-sidebar { display: none; } }
    @media (max-width: 900px)  { .layout { grid-template-columns: 1fr; }  }
  
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
  .sidebar{position:fixed;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px);width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);overflow-y:auto;}
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
      <div class="user-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
      <span class="user-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="layout">

  <!-- App sidebar -->
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
      <a class="nav-item active" href="forum.php"><div class="nav-icon">🗣️</div> Peer forum</a>
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

  <!-- Main -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if ($view === 'new'): ?>
    <!-- ══ NEW POST FORM ══════════════════════════════════════ -->
    <div class="new-post-wrap">
      <a class="back-link" href="forum.php">← Back to forum</a>
      <div class="np-card">
        <div class="np-title">Create a new post</div>
        <div class="np-sub">Share something with the community. You can post anonymously.</div>

        <?php if ($error): ?><div class="error-box">⚠ <?= clean($error) ?></div><?php endif; ?>

        <form method="POST" action="forum.php?view=new">
          <input type="hidden" name="action" value="new_post">

          <div class="form-group">
            <label class="form-label" for="post_title">Post title</label>
            <input class="form-input" type="text" id="post_title" name="title"
                   placeholder="What's on your mind? Give it a title…"
                   value="<?= clean($_POST['title'] ?? '') ?>"
                   maxlength="200" required>
          </div>

          <div class="form-group">
            <label class="form-label" for="post_category">Category</label>
            <select class="form-select" id="post_category" name="category" required>
              <option value="">Select a category…</option>
              <?php foreach (['stress'=>'😤 Stress','anxiety'=>'😰 Anxiety','depression'=>'😔 Depression','tips'=>'💡 Tips & Advice','academics'=>'📚 Academics','general'=>'💬 General'] as $v => $l): ?>
                <option value="<?= $v ?>" <?= (($_POST['category'] ?? '') === $v) ? 'selected' : '' ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label class="form-label" for="post_body">Post content</label>
            <textarea class="form-textarea" id="post_body" name="body"
                      placeholder="Share your thoughts, ask a question, or offer advice…"
                      maxlength="3000" rows="8"
                      oninput="document.getElementById('bodyCount').textContent=this.value.length"
            ><?= clean($_POST['body'] ?? '') ?></textarea>
            <div class="char-count"><span id="bodyCount"><?= mb_strlen($_POST['body'] ?? '') ?></span> / 3000</div>
          </div>

          <label class="anon-toggle" for="post_anon">
            <input type="checkbox" id="post_anon" name="anonymous"
                   <?= isset($_POST['anonymous']) ? 'checked' : '' ?>
                   onchange="toggleAnonNote(this)">
            Post anonymously (your name won't be shown)
          </label>
          <div class="anon-note <?= isset($_POST['anonymous']) ? 'show' : '' ?>" id="anonNote">
            💜 Your identity is hidden from other students. CTU MindCare staff can still see who you are for safety purposes.
          </div>

          <div class="np-footer" style="margin-top:20px">
            <button class="btn-post-submit" type="submit">🗣 Publish post</button>
            <a class="btn-cancel" href="forum.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($view === 'post' && $post): ?>
    <!-- ══ SINGLE POST VIEW ══════════════════════════════════ -->
    <div class="post-view">

      <a class="back-link" href="forum.php<?= $cat_filter !== 'all' ? '?cat='.$cat_filter : '' ?>">
        ← Back to forum
      </a>

      <!-- Post -->
      <div class="post-full">
        <?php
          $cc  = catColor($post['category']);
          $is_mine = (int)$post['user_id'] === (int)$user['id'];
        ?>
        <div class="pf-cat">
          <span class="post-cat-badge"
                style="background:<?= $cc['bg'] ?>;color:<?= $cc['text'] ?>;border-color:<?= $cc['border'] ?>">
            <?= catIcon($post['category']) ?> <?= ucfirst($post['category']) ?>
          </span>
        </div>
        <div class="pf-title"><?= clean($post['title']) ?></div>
        <div class="pf-meta">
          <?php
            $av_init = $post['is_anonymous'] ? '?' : strtoupper(substr($post['first_name'],0,1).substr($post['last_name'],0,1));
          ?>
          <div style="display:flex;align-items:center;gap:6px">
            <div class="post-av <?= $post['is_anonymous'] ? 'anon' : '' ?>"><?= $av_init ?></div>
            <span><?= clean(postAuthor($post)) ?></span>
          </div>
          <span class="meta-dot">·</span>
          <span><?= timeAgo($post['created_at']) ?></span>
          <span class="meta-dot">·</span>
          <span><?= (int)$post['reply_count'] ?> <?= $post['reply_count'] == 1 ? 'reply' : 'replies' ?></span>
          <?php if ($is_mine): ?>
            <span class="meta-dot">·</span>
            <span style="color:var(--sage-dark);font-size:11px;background:var(--sage-light);padding:2px 8px;border-radius:10px">Your post</span>
          <?php endif; ?>
        </div>
        <div class="pf-divider"></div>
        <div class="pf-body"><?= nl2br(clean($post['body'])) ?></div>
        <div class="pf-actions">
          <button class="pf-react-btn <?= $post['user_reacted'] ? 'reacted' : '' ?>"
                  id="reactBtn"
                  onclick="toggleReact(<?= $post['id'] ?>, this)">
            <span>🤍</span>
            <span id="reactCount"><?= (int)$post['react_count'] ?></span>
            <span><?= $post['user_reacted'] ? 'Relating' : 'I relate' ?></span>
          </button>
          <a class="pf-flag-btn" href="#reply-form">💬 Reply</a>
          <?php if (!$is_mine && !$post['is_flagged']): ?>
            <button class="pf-flag-btn"
                    onclick="if(confirm('Report this post?'))location.href='forum.php?flag=<?= $post['id'] ?>'">
              🚩 Report
            </button>
          <?php endif; ?>
          <?php if ($is_mine): ?>
            <button class="pf-delete-btn" onclick="showDeleteModal(<?= $post['id'] ?>, '<?= addslashes(clean($post['title'])) ?>')">
              🗑 Delete post
            </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Replies -->
      <div class="replies-section" id="replies">
        <div class="replies-title">
          Replies
          <span class="reply-count-badge"><?= count($replies) ?></span>
        </div>

        <?php if ($replies): ?>
          <div class="reply-list">
            <?php foreach ($replies as $r):
              $r_init = $r['is_anonymous'] ? '?' : strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1));
              $r_name = postAuthor($r);
              $r_mine = (int)$r['user_id'] === (int)$user['id'];
            ?>
              <div class="reply-item">
                <div class="ri-header">
                  <div class="ri-av <?= $r['is_anonymous'] ? 'anon' : '' ?>"><?= $r_init ?></div>
                  <span class="ri-name"><?= clean($r_name) ?><?= $r_mine ? ' <span style="font-size:10px;color:var(--sage);font-weight:400">(you)</span>' : '' ?></span>
                  <span class="ri-time"><?= timeAgo($r['created_at']) ?></span>
                  <?php if (!$r_mine): ?>
                    <button class="ri-flag"
                            onclick="if(confirm('Report this reply?'))location.href='forum.php?flag_reply=<?= $r['id'] ?>'">
                      🚩
                    </button>
                  <?php endif; ?>
                  <?php if ($r_mine): ?>
                    <button class="ri-flag ri-delete"
                            onclick="if(confirm('Delete your reply? This cannot be undone.'))location.href='forum.php?delete_reply=<?= $r['id'] ?>'">
                      🗑
                    </button>
                  <?php endif; ?>
                </div>
                <div class="ri-body"><?= nl2br(clean($r['body'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div style="text-align:center;padding:28px;font-size:13px;color:var(--text-3)">
            No replies yet — be the first to respond! 👇
          </div>
        <?php endif; ?>
      </div>

      <!-- Reply form -->
      <div class="reply-form-card" id="reply-form">
        <?php if ($error): ?><div class="error-box" style="margin-bottom:14px">⚠ <?= clean($error) ?></div><?php endif; ?>
        <div class="rf-title">Write a reply</div>
        <form method="POST" action="forum.php?view=post&id=<?= $post['id'] ?>">
          <input type="hidden" name="action"  value="reply">
          <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
          <textarea class="form-textarea" name="body" rows="4"
                    placeholder="Share your thoughts or support…"
                    maxlength="2000"><?= clean($_POST['body'] ?? '') ?></textarea>
          <label class="anon-toggle" style="margin-top:10px" for="reply_anon">
            <input type="checkbox" id="reply_anon" name="anonymous"
                   <?= isset($_POST['anonymous']) ? 'checked' : '' ?>>
            Reply anonymously
          </label>
          <div class="reply-form-foot">
            <span style="font-size:11px;color:var(--text-3)">🔒 Be kind — this is a safe space</span>
            <button class="btn-reply-submit" type="submit">Post reply →</button>
          </div>
        </form>
      </div>

    </div>

    <?php else: ?>
    <!-- ══ POST LIST ══════════════════════════════════════════ -->

    <div class="page-header">
      <div>
        <div class="page-title">Peer Forum</div>
        <div class="page-sub">A safe, supportive space to share and connect with fellow students.</div>
      </div>
      <a class="btn-new-post" href="forum.php?view=new">✏ New post</a>
    </div>

    <div class="forum-grid">

      <!-- Left: posts -->
      <div>
        <!-- Search -->
        <form method="GET" action="forum.php" style="margin-bottom:16px">
          <?php if ($cat_filter !== 'all'): ?><input type="hidden" name="cat" value="<?= clean($cat_filter) ?>"><?php endif; ?>
          <div class="search-wrap">
            <span class="search-icon-abs">🔍</span>
            <input class="search-input" type="text" name="q"
                   placeholder="Search posts…"
                   value="<?= clean($search) ?>" autocomplete="off">
          </div>
        </form>

        <?php if ($search): ?>
          <div style="font-size:13px;color:var(--text-3);margin-bottom:12px">
            <?= count($posts) ?> result<?= count($posts) !== 1 ? 's' : '' ?> for "<strong style="color:var(--text)"><?= clean($search) ?></strong>"
            — <a href="forum.php<?= $cat_filter !== 'all' ? '?cat='.$cat_filter : '' ?>" style="color:var(--sage)">clear</a>
          </div>
        <?php endif; ?>

        <?php if ($posts): ?>
          <div class="post-list">
            <?php foreach ($posts as $p):
              $cc = catColor($p['category']);
              $av_init = $p['is_anonymous'] ? '?' : strtoupper(substr($p['first_name'],0,1).substr($p['last_name'],0,1));
            ?>
              <a class="post-card" href="forum.php?view=post&id=<?= $p['id'] ?>">
                <div class="post-card-top">
                  <span class="post-cat-badge"
                        style="background:<?= $cc['bg'] ?>;color:<?= $cc['text'] ?>;border-color:<?= $cc['border'] ?>">
                    <?= catIcon($p['category']) ?> <?= ucfirst($p['category']) ?>
                  </span>
                  <div class="post-stats" onclick="event.stopPropagation()">
                    <span class="stat-btn <?= $p['user_reacted'] ? 'reacted' : '' ?>"
                          onclick="toggleReact(<?= $p['id'] ?>, this); event.preventDefault()">
                      🤍 <span class="react-num"><?= (int)$p['react_count'] ?></span>
                    </span>
                    <span class="stat-btn">
                      💬 <?= (int)$p['reply_count'] ?>
                    </span>
                  </div>
                </div>
                <div class="post-title"><?= clean($p['title']) ?></div>
                <div class="post-excerpt"><?= clean(excerpt($p['body'])) ?></div>
                <div class="post-meta">
                  <div class="post-author">
                    <div class="post-av <?= $p['is_anonymous'] ? 'anon' : '' ?>"><?= $av_init ?></div>
                    <?= clean(postAuthor($p)) ?>
                  </div>
                  <span class="meta-dot">·</span>
                  <span><?= timeAgo($p['created_at']) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

        <?php else: ?>
          <div class="empty-state">
            <?php if ($search): ?>
              <div class="es-icon">🔍</div>
              <div class="es-title">No posts found</div>
              <div class="es-sub">Nothing matched "<?= clean($search) ?>". Try different keywords.</div>
              <a class="es-btn" href="forum.php">Browse all posts</a>
            <?php else: ?>
              <div class="es-icon">🗣️</div>
              <div class="es-title">No posts yet<?= $cat_filter !== 'all' ? ' in this category' : '' ?></div>
              <div class="es-sub">Be the first to start a conversation. Your post can be anonymous.</div>
              <a class="es-btn" href="forum.php?view=new">✏ Write the first post</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right: filter sidebar -->
      <div class="forum-sidebar">

        <!-- Category filter -->
        <div class="card">
          <div class="card-title">Browse by topic</div>
          <div class="cat-list">
            <?php
            $all_cats = ['all'=>'All posts','stress'=>'Stress','anxiety'=>'Anxiety','depression'=>'Depression','tips'=>'Tips & Advice','academics'=>'Academics','general'=>'General'];
            foreach ($all_cats as $slug => $label):
              $is_act = ($cat_filter === $slug);
              $cnt    = $cat_counts[$slug] ?? 0;
              $url    = 'forum.php?' . ($slug === 'all' ? '' : 'cat='.$slug) . ($search ? '&q='.urlencode($search) : '');
            ?>
              <a class="cat-item <?= $is_act ? 'active' : '' ?>" href="<?= $url ?>">
                <div class="cat-item-left">
                  <?= $slug === 'all' ? '🌿' : catIcon($slug) ?>
                  <?= $label ?>
                </div>
                <span class="cat-count"><?= $cnt ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Community guidelines -->
        <div class="card">
          <div class="card-title">Community guidelines</div>
          <div class="guideline-list">
            <div class="guideline-item"><span class="guideline-icon">💜</span> Be kind and supportive — everyone is going through something.</div>
            <div class="guideline-item"><span class="guideline-icon">🔒</span> Respect privacy — what's shared here stays here.</div>
            <div class="guideline-item"><span class="guideline-icon">🚫</span> No bullying, hate speech, or harmful advice.</div>
            <div class="guideline-item"><span class="guideline-icon">🙈</span> Post anonymously if you prefer — it's safe.</div>
            <div class="guideline-item"><span class="guideline-icon">🚩</span> Report posts that feel harmful or unsafe.</div>
            <div class="guideline-item"><span class="guideline-icon">🆘</span> In crisis? <a href="crisis.php" style="color:var(--coral);font-weight:500">Use Crisis Help immediately.</a></div>
          </div>
        </div>

        <!-- Start post CTA -->
        <div class="card" style="background:var(--sage-light);border-color:var(--sage-mid)">
          <div style="font-size:13px;font-weight:600;color:var(--sage-dark);margin-bottom:6px">Have something to share?</div>
          <div style="font-size:12px;color:var(--sage);line-height:1.6;margin-bottom:14px">You can post anonymously. Your voice matters here.</div>
          <a class="btn-new-post" href="forum.php?view=new" style="width:100%;justify-content:center">✏ New post</a>
        </div>

      </div>
    </div>

    <?php endif; // end view switch ?>

  </main>
</div>

<!-- ── Delete post confirmation modal ── -->
<div class="delete-overlay" id="deleteOverlay">
  <div class="delete-box">
    <div class="db-icon">🗑</div>
    <div class="db-title">Delete this post?</div>
    <div class="db-body">This will permanently remove your post and all its replies. This action cannot be undone.</div>
    <div class="db-post-title" id="dbPostTitle"></div>
    <div class="db-btns">
      <button class="db-keep" onclick="hideDeleteModal()">Keep it</button>
      <a class="db-confirm" id="dbConfirmBtn" href="#">Yes, delete it</a>
    </div>
  </div>
</div>

<script>
// ── React toggle ──────────────────────────────────────────────
async function toggleReact(postId, btn) {
  try {
    const res  = await fetch(`forum.php?react=${postId}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const data = await res.json();
    const countEl = btn.querySelector('.react-num') || document.getElementById('reactCount');
    if (countEl) countEl.textContent = data.count;
    btn.classList.toggle('reacted');
    const label = btn.querySelectorAll('span')[2];
    if (label) label.textContent = btn.classList.contains('reacted') ? 'Relating' : 'I relate';
  } catch(e) { console.error(e); }
}

// ── Anonymous post note ───────────────────────────────────────
function toggleAnonNote(cb) {
  const note = document.getElementById('anonNote');
  if (note) note.classList.toggle('show', cb.checked);
}

// ── Auto-focus reply textarea if #reply-form anchor ──────────
if (location.hash === '#reply-form') {
  const ta = document.querySelector('#reply-form textarea');
  if (ta) { ta.focus(); ta.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
}

// ── Delete post modal ─────────────────────────────────────────
function showDeleteModal(postId, postTitle) {
  document.getElementById('dbPostTitle').textContent = postTitle;
  document.getElementById('dbConfirmBtn').href = `forum.php?delete_post=${postId}`;
  document.getElementById('deleteOverlay').classList.add('show');
}
function hideDeleteModal() {
  document.getElementById('deleteOverlay').classList.remove('show');
}
document.getElementById('deleteOverlay')?.addEventListener('click', function(e) {
  if (e.target === this) hideDeleteModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') hideDeleteModal(); });
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