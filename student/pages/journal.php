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

$mc_title = 'Wellness Journal — CTU MindCare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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

    <div class="journal-shell">

      <?php if ($action === 'new' || ($action === 'edit' && $entry)): ?>
      <!-- ── EDITOR ── -->
      <div class="entry-pane mc-reveal">
        <div class="editor-header">
          <a class="editor-back" href="journal.php<?= $entry ? '?action=view&id='.$entry['id'] : '' ?>">
            <i data-lucide="arrow-left"></i> <?= $entry ? 'Cancel' : 'Back' ?>
          </a>
          <span class="editor-date"><?= date('l, F j, Y') ?></span>
        </div>

        <?php if ($error): ?>
          <div class="error-box"><i data-lucide="alert-triangle"></i> <?= clean($error) ?></div>
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
              <i data-lucide="lightbulb"></i> <span id="promptText"><?= clean($today_prompt) ?></span>
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
                <span>&middot; Last saved <?= timeAgo($entry['updated_at']) ?></span>
              <?php endif; ?>
              <span class="lock-hint"><i data-lucide="lock"></i> Only visible to you</span>
            </div>
            <div class="ef-right">
              <a class="btn btn-outline" href="journal.php<?= $entry ? '?action=view&id='.$entry['id'] : '' ?>">Cancel</a>
              <button type="submit" class="btn btn-primary" id="saveBtn">
                <i data-lucide="save"></i> <?= $entry ? 'Update entry' : 'Save entry' ?>
              </button>
            </div>
          </div>
        </form>
      </div>

      <?php elseif ($action === 'view' && $entry): ?>
      <!-- ── VIEW ENTRY ── -->
      <div class="view-wrap mc-reveal">
        <div class="view-nav">
          <a class="editor-back" href="journal.php"><i data-lucide="arrow-left"></i> All entries</a>
          <?php
          // Prev / next navigation
          $ids = array_column($entries, 'id');
          $curr_pos = array_search((int)$entry['id'], $ids);
          $prev_id  = $curr_pos > 0 ? $ids[$curr_pos - 1] : null;
          $next_id  = ($curr_pos !== false && $curr_pos < count($ids) - 1) ? $ids[$curr_pos + 1] : null;
          ?>
          <div class="view-nav-btns">
            <?php if ($prev_id): ?>
              <a class="editor-back" href="journal.php?action=view&id=<?= $prev_id ?>" title="Newer entry"><i data-lucide="chevron-up"></i></a>
            <?php endif; ?>
            <?php if ($next_id): ?>
              <a class="editor-back" href="journal.php?action=view&id=<?= $next_id ?>" title="Older entry"><i data-lucide="chevron-down"></i></a>
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
          <span class="j-pill"><i data-lucide="book-open"></i> <?= $wc ?> words</span>
          <span class="j-pill"><i data-lucide="clock"></i> <?= $read_mins ?> min read</span>
          <?php if (strtotime($entry['updated_at']) > strtotime($entry['created_at']) + 5): ?>
            <span class="j-pill"><i data-lucide="pencil"></i> Edited <?= timeAgo($entry['updated_at']) ?></span>
          <?php endif; ?>
          <span class="j-pill"><i data-lucide="lock"></i> Private</span>
        </div>

        <div class="view-divider"></div>

        <div class="view-body"><?= nl2br(clean($entry['body'])) ?></div>

        <div class="view-actions">
          <a class="btn btn-primary" href="journal.php?action=edit&id=<?= $entry['id'] ?>"><i data-lucide="pencil"></i> Edit entry</a>
          <a class="btn btn-outline" href="journal.php?action=new"><i data-lucide="plus"></i> New entry</a>
          <button class="btn btn-danger" onclick="confirmDelete(<?= $entry['id'] ?>)"><i data-lucide="trash-2"></i> Delete</button>
        </div>
      </div>

      <?php else: ?>
      <!-- ── LIST / WELCOME ── -->
      <div class="page-header mc-reveal">
        <div>
          <div class="page-title">Wellness Journal</div>
          <div class="page-sub">A private space to reflect, process your thoughts, and track your emotional journey.</div>
        </div>
        <div class="header-actions">
          <form method="GET" action="journal.php" class="search-wrap">
            <i data-lucide="search" class="search-icon"></i>
            <input
              class="search-input"
              type="text"
              name="q"
              placeholder="Search entries…"
              value="<?= clean($search) ?>"
              autocomplete="off"
            >
          </form>
          <a class="btn btn-primary" href="journal.php?action=new"><i data-lucide="pencil"></i> New entry</a>
        </div>
      </div>

      <!-- Stats strip -->
      <div class="pane-stats mc-reveal" data-mc-delay="1">
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

      <?php if (empty($entries) && !$search): ?>
        <!-- Empty / welcome state -->
        <div class="empty-state mc-reveal" data-mc-delay="2">
          <div class="welcome-icon"><i data-lucide="book-heart"></i></div>
          <div class="es-title">Your Wellness Journal</div>
          <div class="es-sub">
            A private space to reflect, process your thoughts, and track your emotional journey.
            Only you can see your entries — write freely and honestly.
          </div>
          <a class="btn btn-primary" href="journal.php?action=new"><i data-lucide="pencil"></i> Write your first entry</a>
          <div class="prompt-card">
            <i data-lucide="lightbulb"></i>
            <div>
              <div class="pc-label">Today's writing prompt</div>
              <div class="pc-prompt">"<?= clean($today_prompt) ?>"</div>
            </div>
          </div>
        </div>
      <?php elseif (empty($entries) && $search): ?>
        <div class="empty-state mc-reveal" data-mc-delay="2">
          <div class="es-icon"><i data-lucide="search"></i></div>
          <div class="es-title">No matching entries</div>
          <div class="es-sub">Nothing found for "<?= clean($search) ?>". Try a different search term.</div>
          <a class="btn btn-outline" href="journal.php"><i data-lucide="x"></i> Clear search</a>
        </div>
      <?php else: ?>
        <div class="entry-list mc-reveal" data-mc-delay="2">
          <?php
          // Group by month
          $grouped = [];
          foreach ($entries as $e) {
              $month = date('F Y', strtotime($e['created_at']));
              $grouped[$month][] = $e;
          }
          foreach ($grouped as $month => $month_entries):
          ?>
            <div class="date-group-label"><?= $month ?></div>
            <?php foreach ($month_entries as $e):
              $is_active = (in_array($action, ['view','edit']) && $entry_id === (int)$e['id']);
              $e_title   = $e['title'] ?: 'Untitled entry';
            ?>
              <a class="entry-item <?= $is_active ? 'active' : '' ?>"
                 href="journal.php?action=view&id=<?= $e['id'] ?>">
                <span class="ei-date">
                  <span class="ei-dot"></span>
                  <span><?= date('M j', strtotime($e['created_at'])) ?></span>
                  <span><?= date('g:i A', strtotime($e['created_at'])) ?></span>
                </span>
                <span class="ei-body">
                  <span class="ei-title">
                    <?= clean($e_title) ?>
                    <?php if (strtotime($e['updated_at']) > strtotime($e['created_at']) + 5): ?>
                      <span class="ei-edited">· edited</span>
                    <?php endif; ?>
                  </span>
                  <span class="ei-excerpt"><?= clean(excerpt($e['body'])) ?></span>
                </span>
              </a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php endif; ?>

    </div><!-- .journal-shell -->
  </main>
</div>

<!-- ── Delete confirm modal ── -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <div class="cb-icon"><i data-lucide="trash-2"></i></div>
    <div class="cb-title">Delete this entry?</div>
    <div class="cb-body">This action cannot be undone. Your journal entry will be permanently deleted.</div>
    <div class="cb-btns">
      <button class="btn btn-outline" onclick="hideConfirm()">Keep it</button>
      <a class="cb-confirm-cancel" id="confirmDeleteBtn" href="#">Yes, delete</a>
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

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
