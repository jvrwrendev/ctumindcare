<?php
require_once '../../includes/auth.php';
requireRole('student');
$user = currentUser();
$db   = getDB();
// Check if already checked in today
$stmt = $db->prepare("SELECT id, mood_level, mood_note FROM mood_entries WHERE user_id = ? AND DATE(recorded_at) = CURDATE()");
$stmt->execute([$user['id']]);
$already_done = $stmt->fetch();
$error = '';
// ── Check last 3 days of mood (for the 3-day low streak banner) ──
$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day, mood_level
    FROM mood_entries
    WHERE user_id = ?
      AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    ORDER BY recorded_at DESC
");
$stmt->execute([$user['id']]);
$recent_3 = $stmt->fetchAll();
// Build map of last 3 days (excluding today)
$low_streak = 0;
$streak_days_checked = 0;
for ($i = 1; $i <= 3; $i++) {
    $check_date = date('Y-m-d', strtotime("-{$i} days"));
    $found = false;
    foreach ($recent_3 as $r) {
        if ($r['day'] === $check_date) {
            $streak_days_checked++;
            if ((int)$r['mood_level'] <= 2) {
                $low_streak++;
            } else {
                break 2; // streak broken
            }
            $found = true;
            break;
        }
    }
    if (!$found) break; // no entry that day = streak broken
}
$show_3day_banner = ($low_streak >= 2); // 2+ previous low days = warn on today's check-in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_done) {
    $level = (int)($_POST['mood_level'] ?? 0);
    $note  = clean($_POST['mood_note'] ?? '');
    if ($level < 1 || $level > 5) {
        $error = 'Please select how you are feeling today.';
    } else {
        $stmt = $db->prepare("INSERT INTO mood_entries (user_id, mood_level, mood_note) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $level, $note ?: null]);
        // ── Auto-risk detection: 3+ low days in a row → suggest assessment ──
        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM mood_entries
            WHERE user_id = ?
              AND mood_level <= ?
              AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$user['id'], MOOD_RISK_LEVEL, MOOD_RISK_DAYS]);
        $risk_count = (int)$stmt->fetch()['cnt'];
        if ($risk_count >= MOOD_RISK_DAYS) {
            $stmt = $db->prepare("
                SELECT id FROM assessment_results
                WHERE user_id = ? AND triggered_by = 'auto_mood'
                AND taken_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$user['id']]);
            if (!$stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'assessment_due', ?)");
                $stmt->execute([
                    $user['id'],
                    "Your mood has been low for {$risk_count} days in a row. We'd like you to take a short wellness assessment so we can better support you."
                ]);
                setFlash('warning', "We noticed your mood has been low recently. Please take a short wellness assessment.");
                header('Location: assessment.php?trigger=auto');
                exit;
            }
        }
        setFlash('success', 'Mood recorded! Keep checking in daily.');
        header('Location: dashboard.php');
        exit;
    }
}
$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day, mood_level
    FROM mood_entries
    WHERE user_id = ? AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    ORDER BY recorded_at ASC
");
$stmt->execute([$user['id']]);
$week_rows = $stmt->fetchAll();
$week_map = [];
foreach ($week_rows as $r) $week_map[$r['day']] = (int)$r['mood_level'];
$mood_labels = [1=>'Very Low', 2=>'Low', 3=>'Okay', 4=>'Good', 5=>'Great'];
$mood_emoji  = [1=>'😔', 2=>'😟', 3=>'😐', 4=>'🙂', 5=>'😊'];
$flash = getFlash();
// ── Motivational quotes pool ───────────────────────────────────
$quotes_low = [
    ["quote" => "You don't have to be positive all the time. It's perfectly okay to feel sad, angry, annoyed, frustrated, scared, or anxious.", "author" => "Lori Deschene"],
    ["quote" => "Even the darkest night will end and the sun will rise.", "author" => "Victor Hugo"],
    ["quote" => "You are allowed to be both a masterpiece and a work in progress simultaneously.", "author" => "Sophia Bush"],
    ["quote" => "What you are going through is hard. But you have gotten through hard things before.", "author" => "Unknown"],
    ["quote" => "Be gentle with yourself. You are a child of the universe, no less than the trees and the stars.", "author" => "Max Ehrmann"],
    ["quote" => "It's okay to not be okay — as long as you don't give up.", "author" => "Unknown"],
    ["quote" => "Tough times never last, but tough people do.", "author" => "Robert H. Schuller"],
    ["quote" => "You are stronger than you know, braver than you believe, and more loved than you can imagine.", "author" => "Unknown"],
    ["quote" => "The sun is a daily reminder that we too can rise again from the darkness.", "author" => "S. Ajna"],
    ["quote" => "Healing is not linear. Some days will be harder than others, and that is okay.", "author" => "Unknown"],
];
// 3-day streak specific quotes (more supportive)
$quotes_streak = [
    ["quote" => "Sometimes the bravest thing you can do is ask for help. You don't have to carry this alone.", "author" => "CTU MindCare"],
    ["quote" => "Three days of struggle doesn't mean a lifetime of pain. Reach out — someone is here for you.", "author" => "CTU MindCare"],
    ["quote" => "It takes courage to keep showing up when things are hard. You showed up today — that matters.", "author" => "CTU MindCare"],
    ["quote" => "Your feelings are valid. Your pain is real. And so is the support waiting for you.", "author" => "CTU MindCare"],
    ["quote" => "You've been strong for so long. Let someone be strong for you now.", "author" => "Unknown"],
];
// Pick a deterministic quote based on day of year so it feels "fresh" daily
$day_index = (int)date('z'); // 0-365
$quote_low    = $quotes_low[$day_index % count($quotes_low)];
$quote_streak = $quotes_streak[$day_index % count($quotes_streak)];
$mc_title = 'Daily Mood Check-In';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once '../../includes/assets.php'; ?>
</head>
<body>
<!-- ── MOTIVATIONAL QUOTE POPUP ─────────────────────────────── -->
<div class="quote-overlay" id="quoteOverlay">
  <!-- Low mood popup (mood 1 or 2, no streak) -->
  <div class="quote-modal low-variant" id="quoteLow">
    <button class="qm-close" onclick="closeQuote()" title="Close"><i data-lucide="x"></i></button>
    <span class="qm-emoji">💙</span>
    <span class="qm-label">A thought for you</span>
    <p class="qm-quote">"<?= htmlspecialchars($quote_low['quote']) ?>"</p>
    <p class="qm-author"><?= htmlspecialchars($quote_low['author']) ?></p>
    <div class="qm-actions">
      <button class="qm-btn-primary" onclick="closeQuote()">
        Thank you, continue <i data-lucide="arrow-right" style="width:15px;height:15px"></i>
      </button>
      <a class="qm-btn-secondary" href="resources.php" onclick="closeQuote()">
        <i data-lucide="heart" style="width:15px;height:15px"></i> Browse wellness resources
      </a>
    </div>
  </div>
  <!-- 3-day low streak popup -->
  <div class="quote-modal streak-variant" id="quoteStreak" style="display:none">
    <button class="qm-close" onclick="closeQuote()" title="Close"><i data-lucide="x"></i></button>
    <span class="qm-emoji">🌤️</span>
    <span class="qm-label">We've noticed something</span>
    <!-- 3-day mood dots -->
    <div class="qm-days" id="moodDots">
      <?php
      // Show last 3 days + today
      for ($i = 2; $i >= 0; $i--):
        $check_date = date('Y-m-d', strtotime("-{$i} days"));
        $day_label  = $i === 0 ? 'Today' : date('D', strtotime($check_date));
        $found_lv   = null;
        foreach ($recent_3 as $r) {
            if ($r['day'] === $check_date) { $found_lv = (int)$r['mood_level']; break; }
        }
        $dot_class = 'missing';
        $dot_icon  = '?';
        if ($found_lv !== null) {
            $dot_class = ($found_lv <= 2) ? 'low' : 'okay';
            $dot_icon  = $mood_emoji[$found_lv] ?? '😐';
        }
        if ($i === 0) { $dot_class = 'today'; $dot_icon = '?'; }
      ?>
        <div class="qm-days-col">
          <div class="qm-day-dot <?= $dot_class ?>"><?= $dot_icon ?></div>
          <div class="qm-day-label"><?= $day_label ?></div>
        </div>
      <?php endfor; ?>
    </div>
    <p class="qm-quote">"<?= htmlspecialchars($quote_streak['quote']) ?>"</p>
    <p class="qm-author"><?= htmlspecialchars($quote_streak['author']) ?></p>
    <div class="qm-actions">
      <a class="qm-btn-primary" href="counselor-chat.php" style="text-align:center">
        <i data-lucide="messages-square" style="width:15px;height:15px"></i> Chat with a counselor
      </a>
      <a class="qm-btn-secondary" href="appointments.php" onclick="closeQuote()">
        <i data-lucide="calendar" style="width:15px;height:15px"></i> Book an appointment
      </a>
      <button class="qm-btn-secondary" onclick="closeQuote()" style="border:none;color:var(--text-3);font-size:12px;padding:6px">
        I'm okay, continue <i data-lucide="arrow-right" style="width:14px;height:14px"></i>
      </button>
    </div>
  </div>
</div>
<!-- Topbar -->
<?php require_once '../../includes/topbar.php'; ?>
<div class="layout">
  <?php $mc_topbar_h = 56; ?>
  <?php require_once '../../includes/sidebar.php'; ?>
<!-- Main content -->
  <main class="main">
    <div class="checkin-wrap">
      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>" data-auto-dismiss>
          <?php
          $flash_icon = $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'warning' ? 'alert-triangle' : 'alert-circle');
          ?>
          <i data-lucide="<?= $flash_icon ?>"></i>
          <?= clean($flash['message']) ?>
        </div>
      <?php endif; ?>
      <?php if ($already_done): ?>
      <!-- ── Already checked in ── -->
      <div class="done-box mc-reveal">
        <div class="done-emoji"><?= $mood_emoji[$already_done['mood_level']] ?></div>
        <div class="done-title">You've checked in today!</div>
        <div class="done-sub">
          Feeling <?= $mood_labels[$already_done['mood_level']] ?> — Level <?= $already_done['mood_level'] ?> / 5
        </div>
        <?php if ($already_done['mood_note']): ?>
          <div class="done-note">"<?= clean($already_done['mood_note']) ?>"</div>
        <?php endif; ?>
        <div class="done-actions">
          <a class="btn-outline" href="dashboard.php">Back to dashboard</a>
          <a class="btn-solid" href="progress.php">View my progress</a>
        </div>
      </div>
      <div style="margin-top:28px" class="mc-reveal" data-mc-delay="1">
        <div class="card-title">Your mood this week</div>
        <div class="week-strip">
          <?php
          $bar_colors = [null=>'var(--border-2)',1=>'#F5C6C2',2=>'#FAC775',3=>'var(--border-2)',4=>'#9FE1CB',5=>'#1D9E75'];
          $heights    = [null=>4, 1=>10, 2=>20, 3=>30, 4=>42, 5=>52];
          for ($i = 6; $i >= 0; $i--):
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $label = date('D', strtotime($date));
            $lv    = $week_map[$date] ?? null;
          ?>
            <div class="week-day">
              <div class="week-bar-wrap">
                <div class="week-bar" style="height:<?= $heights[$lv] ?>px;background:<?= $bar_colors[$lv] ?>"></div>
              </div>
              <div class="week-day-emoji"><?= $lv ? $mood_emoji[$lv] : '·' ?></div>
              <div class="week-day-label"><?= $label ?></div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
      <?php else: ?>
      <!-- ── Check-in form ── -->
      <div class="page-title mc-reveal">Daily mood check-in</div>
      <p class="page-sub mc-reveal" data-mc-delay="1">
        Take a moment to check in with yourself.<br>
        How are you feeling right now, <?= clean($user['first_name']) ?>?
      </p>
      <?php if ($show_3day_banner): ?>
      <!-- 3-day low streak notice at top of form -->
      <div class="streak-banner mc-reveal" data-mc-delay="2">
        <div class="streak-icon">💛</div>
        <div>
          <div class="streak-title">We've been thinking of you</div>
          <div class="streak-body">
            Your mood has been low for the past <?= $low_streak ?> day<?= $low_streak > 1 ? 's' : '' ?>.
            It's okay to not be okay — but you don't have to go through this alone.
          </div>
          <div class="streak-actions">
            <a class="streak-btn streak-btn-primary" href="counselor-chat.php"><i data-lucide="messages-square" style="width:14px;height:14px"></i> Talk to a counselor</a>
            <a class="streak-btn streak-btn-outline" href="crisis.php"><i data-lucide="phone-call" style="width:14px;height:14px"></i> Crisis help</a>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-error" data-auto-dismiss><i data-lucide="alert-circle" style="width:15px;height:15px;flex-shrink:0"></i> <?= clean($error) ?></div>
      <?php endif; ?>
      <div class="card mc-reveal" data-mc-delay="3">
        <form method="POST" action="" id="checkinForm" data-validate>
          <div class="form-label checkin-heading">
            Select your mood level
          </div>
          <div class="form-hint">
            1 = Very Low &nbsp;·&nbsp; 5 = Great
          </div>
          <div class="mood-grid">
            <?php
            $moods = [
              1 => ['emoji' => '😔', 'label' => 'Very Low'],
              2 => ['emoji' => '😟', 'label' => 'Low'],
              3 => ['emoji' => '😐', 'label' => 'Okay'],
              4 => ['emoji' => '🙂', 'label' => 'Good'],
              5 => ['emoji' => '😊', 'label' => 'Great'],
            ];
            foreach ($moods as $val => $m):
            ?>
              <div>
                <input class="mood-option" type="radio" name="mood_level"
                       id="mood_<?= $val ?>" value="<?= $val ?>"
                       <?= (isset($_POST['mood_level']) && (int)$_POST['mood_level'] === $val) ? 'checked' : '' ?>
                       required>
                <label class="mood-label" for="mood_<?= $val ?>">
                  <span class="mood-emoji"><?= $m['emoji'] ?></span>
                  <span class="mood-text"><?= $m['label'] ?></span>
                  <span class="mood-num"><?= $val ?> / 5</span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="form-group">
            <label class="form-label" for="mood_note">
              Add a note <span class="form-label-opt">(optional)</span>
            </label>
            <textarea class="note-area" id="mood_note" name="mood_note"
                      maxlength="500"
                      placeholder="What's on your mind today? How are things going?..."
                      oninput="updateCount(this)"
            ><?= clean($_POST['mood_note'] ?? '') ?></textarea>
            <div class="char-count"><span id="charCount">0</span> / 500</div>
          </div>
          <div class="submit-row">
            <a class="btn-back" href="dashboard.php"><i data-lucide="arrow-left" style="width:15px;height:15px"></i> Back</a>
            <button type="submit" class="btn-save" id="saveBtn" disabled>
              Save today's check-in
            </button>
          </div>
        </form>
      </div>
      <div class="tip-card mc-reveal" data-mc-delay="4">
        <div class="tip-icon">💡</div>
        <div>
          <div class="tip-title">Why daily check-ins matter</div>
          <div class="tip-body">
            Tracking your mood helps you and your counselor spot patterns over time.
            If your mood stays low for several days, the system will gently suggest
            a wellness assessment to make sure you're getting the right support.
          </div>
        </div>
      </div>
      <?php
      $has_this_week = count(array_filter($week_map)) > 0;
      if ($has_this_week):
      ?>
      <div style="margin-top:28px" class="mc-reveal" data-mc-delay="5">
        <div class="card-title">Your mood so far this week</div>
        <div class="week-strip">
          <?php
          $bar_colors = [null=>'var(--border-2)',1=>'#F5C6C2',2=>'#FAC775',3=>'var(--border-2)',4=>'#9FE1CB',5=>'#1D9E75'];
          $heights    = [null=>4, 1=>10, 2=>20, 3=>30, 4=>42, 5=>52];
          for ($i = 6; $i >= 0; $i--):
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $label = date('D', strtotime($date));
            $lv    = $week_map[$date] ?? null;
            $today = ($i === 0);
          ?>
            <div class="week-day">
              <div class="week-bar-wrap">
                <div class="week-bar" style="height:<?= $heights[$lv] ?>px;background:<?= $today ? 'var(--border-2)' : $bar_colors[$lv] ?>;opacity:<?= $today ? '.4' : '1' ?>"></div>
              </div>
              <div class="week-day-emoji"><?= ($lv && !$today) ? $mood_emoji[$lv] : '·' ?></div>
              <div class="week-day-label" style="<?= $today ? 'color:var(--primary);font-weight:500' : '' ?>"><?= $today ? 'Today' : $label ?></div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</div>
<script>
// ── PHP data passed to JS ────────────────────────────────────
const SHOW_STREAK  = <?= $show_3day_banner ? 'true' : 'false' ?>;
const LOW_STREAK   = <?= (int)$low_streak ?>;
// ── Mood radio change → enable save + maybe show popup ───────
const radios  = document.querySelectorAll('input[name="mood_level"]');
const saveBtn = document.getElementById('saveBtn');
let   popupShown = false;
radios.forEach(function(r) {
  r.addEventListener('change', function() {
    saveBtn.disabled = false;
    const level = parseInt(this.value);
    // Show popup if low mood selected (1 or 2) and not already shown
    if (level <= 2 && !popupShown) {
      popupShown = true;
      setTimeout(function() {
        showQuotePopup(level);
      }, 300); // small delay feels more natural
    }
  });
});
// Restore if returning from POST with checked value
if (document.querySelector('input[name="mood_level"]:checked')) {
  saveBtn.disabled = false;
}
// ── Show the right popup variant ─────────────────────────────
function showQuotePopup(level) {
  const overlay  = document.getElementById('quoteOverlay');
  const lowModal = document.getElementById('quoteLow');
  const strModal = document.getElementById('quoteStreak');
  // If streak of 2+ low days AND today is also low → show streak popup
  if (SHOW_STREAK && LOW_STREAK >= 2) {
    lowModal.style.display = 'none';
    strModal.style.display = 'block';
    // Update "today" dot with the emoji they just selected
    const emojis = ['', '😔', '😟', '😐', '🙂', '😊'];
    const todayDot = strModal.querySelector('.qm-day-dot.today');
    if (todayDot) {
      todayDot.textContent = emojis[level] || '😔';
      todayDot.classList.remove('today');
      todayDot.classList.add('low');
    }
  } else {
    // Regular low mood popup
    strModal.style.display = 'none';
    lowModal.style.display = 'block';
  }
  overlay.classList.add('show');
  document.body.style.overflow = 'hidden';
}
function closeQuote() {
  document.getElementById('quoteOverlay').classList.remove('show');
  document.body.style.overflow = '';
}
// Close on overlay background click
document.getElementById('quoteOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeQuote();
});
// Close on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeQuote();
});
// ── Char count ───────────────────────────────────────────────
function updateCount(el) {
  const c = document.getElementById('charCount');
  if (c) c.textContent = el.value.length;
}
const noteArea = document.getElementById('mood_note');
if (noteArea && noteArea.value) updateCount(noteArea);
</script>
<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
