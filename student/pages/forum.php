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
        'stress'     => 'zap',
        'anxiety'    => 'waves',
        'depression' => 'cloud-rain',
        'tips'       => 'lightbulb',
        'academics'  => 'book-open',
        'general'    => 'message-circle',
        default      => 'message-circle',
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
$mc_title = 'Peer Forum — CTU MindCare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= clean($mc_title) ?></title>
  <?php require_once '../../includes/assets.php'; ?>
</head>
<body>
<!-- ── Topbar ── -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- ── Main ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash-bar mc-reveal">
        <div class="flash flash-<?= $flash['type'] ?>">
          <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : 'alert-triangle' ?>"></i>
          <?= clean($flash['message']) ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($view === 'new'): ?>
    <!-- ══ NEW POST FORM ══════════════════════════════════════ -->
    <div class="new-post-wrap mc-reveal">
      <a class="back-link" href="forum.php">
        <i data-lucide="arrow-left"></i> Back to forum
      </a>
      <div class="np-card">
        <div class="np-title">Create a new post</div>
        <div class="np-sub">Share something with the community. You can post anonymously.</div>

        <?php if ($error): ?><div class="error-box"><i data-lucide="alert-circle"></i> <?= clean($error) ?></div><?php endif; ?>

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
            <i data-lucide="shield-user"></i>
            Post anonymously (your name won't be shown)
          </label>
          <div class="anon-note <?= isset($_POST['anonymous']) ? 'show' : '' ?>" id="anonNote">
            <i data-lucide="shield-check"></i>
            <span>Your identity is hidden from other students. CTU MindCare staff can still see who you are for safety purposes.</span>
          </div>

          <div class="np-footer">
            <button class="btn btn-primary" type="submit"><i data-lucide="send"></i> Publish post</button>
            <a class="btn btn-outline" href="forum.php">Cancel</a>
          </div>
        </form>
      </div>
    </div>

    <?php elseif ($view === 'post' && $post): ?>
    <!-- ══ SINGLE POST VIEW ══════════════════════════════════ -->
    <div class="post-view mc-reveal">

      <a class="back-link" href="forum.php<?= $cat_filter !== 'all' ? '?cat='.$cat_filter : '' ?>">
        <i data-lucide="arrow-left"></i> Back to forum
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
            <i data-lucide="<?= catIcon($post['category']) ?>"></i> <?= ucfirst($post['category']) ?>
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
          <span class="meta-dot"></span>
          <span><?= timeAgo($post['created_at']) ?></span>
          <span class="meta-dot"></span>
          <span><?= (int)$post['reply_count'] ?> <?= $post['reply_count'] == 1 ? 'reply' : 'replies' ?></span>
          <?php if ($is_mine): ?>
            <span class="meta-dot"></span>
            <span class="s-confirmed">Your post</span>
          <?php endif; ?>
        </div>
        <div class="pf-divider"></div>
        <div class="pf-body"><?= nl2br(clean($post['body'])) ?></div>
        <div class="pf-actions">
          <button class="pf-react-btn <?= $post['user_reacted'] ? 'reacted' : '' ?>"
                  id="reactBtn"
                  onclick="toggleReact(<?= $post['id'] ?>, this)">
            <span><i data-lucide="<?= $post['user_reacted'] ? 'heart' : 'heart' ?>"></i></span>
            <span id="reactCount"><?= (int)$post['react_count'] ?></span>
            <span><?= $post['user_reacted'] ? 'Relating' : 'I relate' ?></span>
          </button>
          <a class="pf-flag-btn" href="#reply-form"><i data-lucide="message-circle"></i> Reply</a>
          <?php if (!$is_mine && !$post['is_flagged']): ?>
            <button class="pf-flag-btn"
                    onclick="if(confirm('Report this post?'))location.href='forum.php?flag=<?= $post['id'] ?>'">
              <i data-lucide="flag"></i> Report
            </button>
          <?php endif; ?>
          <?php if ($is_mine): ?>
            <button class="pf-delete-btn" onclick="showDeleteModal(<?= $post['id'] ?>, '<?= addslashes(clean($post['title'])) ?>')">
              <i data-lucide="trash-2"></i> Delete post
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
                  <span class="ri-name"><?= clean($r_name) ?><?= $r_mine ? ' <span style="font-size:10px;color:var(--primary);font-weight:400">(you)</span>' : '' ?></span>
                  <span class="ri-time"><?= timeAgo($r['created_at']) ?></span>
                  <?php if (!$r_mine): ?>
                    <button class="ri-flag"
                            onclick="if(confirm('Report this reply?'))location.href='forum.php?flag_reply=<?= $r['id'] ?>'">
                      <i data-lucide="flag"></i>
                    </button>
                  <?php endif; ?>
                  <?php if ($r_mine): ?>
                    <button class="ri-flag ri-delete"
                            onclick="if(confirm('Delete your reply? This cannot be undone.'))location.href='forum.php?delete_reply=<?= $r['id'] ?>'">
                      <i data-lucide="trash-2"></i>
                    </button>
                  <?php endif; ?>
                </div>
                <div class="ri-body"><?= nl2br(clean($r['body'])) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="empty-state compact">
            <div class="ec-icon"><i data-lucide="message-circle"></i></div>
            <div class="ec-title">No replies yet</div>
            <div class="ec-sub">Be the first to respond — your support could mean a lot to someone.</div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Reply form -->
      <div class="reply-form-card" id="reply-form">
        <?php if ($error): ?><div class="error-box" style="margin-bottom:14px"><i data-lucide="alert-circle"></i> <?= clean($error) ?></div><?php endif; ?>
        <div class="rf-title"><i data-lucide="message-circle"></i> Write a reply</div>
        <form method="POST" action="forum.php?view=post&id=<?= $post['id'] ?>">
          <input type="hidden" name="action"  value="reply">
          <input type="hidden" name="post_id" value="<?= $post['id'] ?>">
          <textarea class="form-textarea" name="body" rows="4"
                    placeholder="Share your thoughts or support…"
                    maxlength="2000"><?= clean($_POST['body'] ?? '') ?></textarea>
          <label class="anon-toggle" style="margin-top:10px" for="reply_anon">
            <input type="checkbox" id="reply_anon" name="anonymous"
                   <?= isset($_POST['anonymous']) ? 'checked' : '' ?>>
            <i data-lucide="shield-user"></i>
            Reply anonymously
          </label>
          <div class="reply-form-foot">
            <span style="font-size:11px;color:var(--text-3);display:inline-flex;align-items:center;gap:5px">
              <i data-lucide="lock" style="width:12px;height:12px"></i> Be kind — this is a safe space
            </span>
            <button class="btn btn-primary" type="submit"><i data-lucide="send"></i> Post reply</button>
          </div>
        </form>
      </div>

    </div>

    <?php else: ?>
    <!-- ══ POST LIST ══════════════════════════════════════════ -->

    <div class="page-header mc-reveal">
      <div>
        <div class="page-title">Peer Forum</div>
        <div class="page-sub">A safe, supportive space to share and connect with fellow students.</div>
      </div>
      <a class="btn btn-primary" href="forum.php?view=new"><i data-lucide="pen-line"></i> New post</a>
    </div>

    <div class="forum-grid">

      <!-- Left: posts -->
      <div>
        <!-- Search -->
        <form method="GET" action="forum.php" style="margin-bottom:16px">
          <?php if ($cat_filter !== 'all'): ?><input type="hidden" name="cat" value="<?= clean($cat_filter) ?>"><?php endif; ?>
          <div class="search-wrap">
            <i data-lucide="search" class="search-icon-abs"></i>
            <input class="search-input" type="text" name="q"
                   placeholder="Search posts…"
                   value="<?= clean($search) ?>" autocomplete="off">
          </div>
        </form>

        <?php if ($search): ?>
          <div class="search-active-banner mc-reveal" data-mc-delay="1">
            <i data-lucide="search"></i>
            <span>
              <?= count($posts) ?> result<?= count($posts) !== 1 ? 's' : '' ?> for "<strong><?= clean($search) ?></strong>"
              — <a href="forum.php<?= $cat_filter !== 'all' ? '?cat='.$cat_filter : '' ?>" style="color:var(--primary);font-weight:600">clear</a>
            </span>
          </div>
        <?php endif; ?>

        <?php if ($posts): ?>
          <div class="post-list">
            <?php foreach ($posts as $p):
              $cc = catColor($p['category']);
              $av_init = $p['is_anonymous'] ? '?' : strtoupper(substr($p['first_name'],0,1).substr($p['last_name'],0,1));
            ?>
              <a class="post-card mc-reveal" href="forum.php?view=post&id=<?= $p['id'] ?>">
                <div class="post-card-top">
                  <span class="post-cat-badge"
                        style="background:<?= $cc['bg'] ?>;color:<?= $cc['text'] ?>;border-color:<?= $cc['border'] ?>">
                    <i data-lucide="<?= catIcon($p['category']) ?>"></i> <?= ucfirst($p['category']) ?>
                  </span>
                  <div class="post-stats" onclick="event.stopPropagation()">
                    <span class="stat-btn <?= $p['user_reacted'] ? 'reacted' : '' ?>"
                          onclick="toggleReact(<?= $p['id'] ?>, this); event.preventDefault()">
                      <i data-lucide="heart"></i> <span class="react-num"><?= (int)$p['react_count'] ?></span>
                    </span>
                    <span class="stat-btn">
                      <i data-lucide="message-circle"></i> <?= (int)$p['reply_count'] ?>
                    </span>
                  </div>
                </div>
                <div class="post-title"><?= clean($p['title']) ?></div>
                <div class="post-excerpt"><?= clean(excerpt($p['body'])) ?></div>
                <div class="post-meta" style="margin-top:12px">
                  <div class="post-author">
                    <div class="post-av <?= $p['is_anonymous'] ? 'anon' : '' ?>"><?= $av_init ?></div>
                    <?= clean(postAuthor($p)) ?>
                  </div>
                  <span class="meta-dot"></span>
                  <span><?= timeAgo($p['created_at']) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

        <?php else: ?>
          <div class="empty-state mc-reveal">
            <?php if ($search): ?>
              <div class="es-icon"><i data-lucide="search"></i></div>
              <div class="es-title">No posts found</div>
              <div class="es-sub">Nothing matched "<?= clean($search) ?>". Try different keywords.</div>
              <a class="es-btn btn btn-primary" href="forum.php">Browse all posts</a>
            <?php else: ?>
              <div class="es-icon"><i data-lucide="messages-square"></i></div>
              <div class="es-title">No posts yet<?= $cat_filter !== 'all' ? ' in this category' : '' ?></div>
              <div class="es-sub">Be the first to start a conversation. Your post can be anonymous.</div>
              <a class="es-btn btn btn-primary" href="forum.php?view=new"><i data-lucide="pen-line"></i> Write the first post</a>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right: filter sidebar -->
      <div class="forum-sidebar">

        <!-- Category filter -->
        <div class="card mc-reveal">
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
                  <i data-lucide="<?= $slug === 'all' ? 'layers' : catIcon($slug) ?>"></i>
                  <?= $label ?>
                </div>
                <span class="cat-count"><?= $cnt ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Community guidelines -->
        <div class="card mc-reveal" data-mc-delay="1">
          <div class="card-title">Community guidelines</div>
          <div class="guideline-list">
            <div class="guideline-item"><i data-lucide="heart"></i> Be kind and supportive — everyone is going through something.</div>
            <div class="guideline-item"><i data-lucide="lock"></i> Respect privacy — what's shared here stays here.</div>
            <div class="guideline-item"><i data-lucide="shield-x"></i> No bullying, hate speech, or harmful advice.</div>
            <div class="guideline-item"><i data-lucide="eye-off"></i> Post anonymously if you prefer — it's safe.</div>
            <div class="guideline-item"><i data-lucide="flag"></i> Report posts that feel harmful or unsafe.</div>
            <div class="guideline-item"><i data-lucide="phone-call"></i> In crisis? <a href="crisis.php">Use Crisis Help immediately.</a></div>
          </div>
        </div>

        <!-- Start post CTA -->
        <div class="card forum-cta-card mc-reveal" data-mc-delay="2">
          <div style="font-size:13px;font-weight:600;color:var(--primary);margin-bottom:6px">Have something to share?</div>
          <div style="font-size:12px;color:var(--text-2);line-height:1.6;margin-bottom:14px">You can post anonymously. Your voice matters here.</div>
          <a class="btn btn-primary" href="forum.php?view=new" style="width:100%;justify-content:center"><i data-lucide="pen-line"></i> New post</a>
        </div>

      </div>
    </div>

    <?php endif; // end view switch ?>

  </main>
</div>

<!-- ── Delete post confirmation modal ── -->
<div class="delete-overlay" id="deleteOverlay">
  <div class="delete-box">
    <div class="db-icon"><i data-lucide="trash-2"></i></div>
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

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
