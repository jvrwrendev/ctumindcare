<?php
// ============================================================
//  CTU MindCare — Edit Profile
//  File: student/pages/profile.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$errors  = [];
$success = '';
$section = $_POST['section'] ?? '';

// ── SAVE PROFILE INFO ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'profile') {
    $first_name   = trim($_POST['first_name']   ?? '');
    $last_name    = trim($_POST['last_name']    ?? '');
    $display_name = trim($_POST['display_name'] ?? '');
    $email        = trim($_POST['email']        ?? '');

    if (strlen($first_name) < 1)    $errors[] = 'First name is required.';
    if (strlen($last_name) < 1)     $errors[] = 'Last name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($display_name) > 50) $errors[] = 'Display name must be 50 characters or fewer.';

    // Check email not taken by another user
    if (empty($errors)) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) $errors[] = 'That email is already in use by another account.';
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, display_name = ?, email = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $first_name,
            $last_name,
            $display_name ?: null,
            $email,
            $user['id'],
        ]);

        // Refresh session name
        $_SESSION['user_name'] = $first_name . ' ' . $last_name;
        $user = currentUser(); // re-fetch
        setFlash('success', 'Profile updated successfully.');
        header('Location: profile.php');
        exit;
    }
}

// ── CHANGE PASSWORD ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'password') {
    $current  = $_POST['current_password']  ?? '';
    $new_pass = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    // Fetch current hash
    $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!verifyPassword($current, $row['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new_pass) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($new_pass !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } elseif (verifyPassword($new_pass, $row['password'])) {
        $errors[] = 'New password must be different from your current password.';
    }

    if (empty($errors)) {
        $hash = hashPassword($new_pass);
        $db->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$hash, $user['id']]);
        setFlash('success', 'Password changed successfully.');
        header('Location: profile.php');
        exit;
    }
}

// ── Account stats ─────────────────────────────────────────────
// Fetch timestamps not included in currentUser()
$stmt = $db->prepare("SELECT created_at, updated_at FROM users WHERE id = ?");
$stmt->execute([$user['id']]);
$user_timestamps = $stmt->fetch();
$user['created_at'] = $user_timestamps['created_at'] ?? null;
$user['updated_at'] = $user_timestamps['updated_at'] ?? null;

$stmt = $db->prepare("SELECT COUNT(DISTINCT DATE(recorded_at)) as mood_days FROM mood_entries WHERE user_id = ?");
$stmt->execute([$user['id']]);
$mood_days = (int)$stmt->fetch()['mood_days'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM assessment_results WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_assessments = (int)$stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM journal_entries WHERE user_id = ?");
$stmt->execute([$user['id']]);
$total_journals = (int)$stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM appointments WHERE student_id = ? AND status != 'cancelled'");
$stmt->execute([$user['id']]);
$total_appointments = (int)$stmt->fetch()['total'];

$stmt = $db->prepare("SELECT COUNT(*) as total FROM forum_posts WHERE user_id = ? AND is_removed = 0");
$stmt->execute([$user['id']]);
$total_posts = (int)$stmt->fetch()['total'];

// Member since
$member_since = date('F Y', strtotime($user['created_at'] ?? 'now'));

$flash = getFlash();
$my_initials = strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1));

$mc_title = 'My Profile — CTU MindCare';
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

    <div class="profile-grid">

      <!-- ── LEFT: Profile hero ── -->
      <div class="mc-reveal">
        <div class="profile-hero">
          <div class="hero-top">
            <div class="hero-avatar"><?= $my_initials ?></div>
            <div class="hero-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
            <?php if ($user['student_id']): ?>
              <div class="hero-id"><?= clean($user['student_id']) ?></div>
            <?php endif; ?>
            <div class="hero-role">Student</div>
          </div>
          <div class="hero-body">
            <!-- Activity stats -->
            <div class="hero-stat-row">
              <div class="hero-stat">
                <div class="hs-num"><?= $mood_days ?></div>
                <div class="hs-label">Mood days</div>
              </div>
              <div class="hero-stat">
                <div class="hs-num"><?= $total_journals ?></div>
                <div class="hs-label">Journal entries</div>
              </div>
              <div class="hero-stat">
                <div class="hs-num"><?= $total_assessments ?></div>
                <div class="hs-label">Assessments</div>
              </div>
              <div class="hero-stat">
                <div class="hs-num"><?= $total_posts ?></div>
                <div class="hs-label">Forum posts</div>
              </div>
            </div>

            <!-- Info list -->
            <div class="hero-info">
              <div class="hi-row">
                <span class="hi-icon"><i data-lucide="mail"></i></span>
                <div class="hi-info">
                  <div class="hi-label">Email</div>
                  <div class="hi-val"><?= clean($user['email']) ?></div>
                </div>
              </div>
              <?php if ($user['display_name']): ?>
              <div class="hi-row">
                <span class="hi-icon"><i data-lucide="tag"></i></span>
                <div class="hi-info">
                  <div class="hi-label">Display name (forum)</div>
                  <div class="hi-val"><?= clean($user['display_name']) ?></div>
                </div>
              </div>
              <?php endif; ?>
              <div class="hi-row">
                <span class="hi-icon"><i data-lucide="calendar"></i></span>
                <div class="hi-info">
                  <div class="hi-label">Member since</div>
                  <div class="hi-val"><?= $member_since ?></div>
                </div>
              </div>
              <div class="hi-row">
                <span class="hi-icon"><i data-lucide="clipboard-check"></i></span>
                <div class="hi-info">
                  <div class="hi-label">Appointments attended</div>
                  <div class="hi-val"><?= $total_appointments ?></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- ── RIGHT: Edit forms ── -->
      <div class="right-col">

        <!-- ── Profile info form ── -->
        <div class="card mc-reveal">
          <div class="card-tabs">
            <div class="section-tabs">
              <span class="section-tab active"><i data-lucide="user"></i> Profile information</span>
            </div>
          </div>
          <div class="card-body">

            <?php if ($section === 'profile' && !empty($errors)): ?>
              <ul class="error-list">
                <?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="profileForm">
              <input type="hidden" name="section" value="profile">

              <div class="form-grid">
                <div class="form-group">
                  <label class="form-label" for="first_name">First name <span style="color:var(--red)">*</span></label>
                  <input class="form-input" type="text" id="first_name" name="first_name"
                         value="<?= clean($section === 'profile' ? ($_POST['first_name'] ?? $user['first_name']) : $user['first_name']) ?>"
                         maxlength="50" required autocomplete="given-name">
                </div>
                <div class="form-group">
                  <label class="form-label" for="last_name">Last name <span style="color:var(--red)">*</span></label>
                  <input class="form-input" type="text" id="last_name" name="last_name"
                         value="<?= clean($section === 'profile' ? ($_POST['last_name'] ?? $user['last_name']) : $user['last_name']) ?>"
                         maxlength="50" required autocomplete="family-name">
                </div>
                <div class="form-group full">
                  <label class="form-label" for="email">Email address <span style="color:var(--red)">*</span></label>
                  <input class="form-input" type="email" id="email" name="email"
                         value="<?= clean($section === 'profile' ? ($_POST['email'] ?? $user['email']) : $user['email']) ?>"
                         maxlength="100" required autocomplete="email">
                </div>
                <div class="form-group full">
                  <label class="form-label" for="display_name">
                    Forum display name
                    <span style="font-weight:400;color:var(--text-3)">(optional)</span>
                  </label>
                  <input class="form-input" type="text" id="display_name" name="display_name"
                         value="<?= clean($section === 'profile' ? ($_POST['display_name'] ?? ($user['display_name'] ?? '')) : ($user['display_name'] ?? '')) ?>"
                         maxlength="50" placeholder="How you'll appear in the forum (leave blank to use full name)">
                  <span class="form-hint">Used in forum posts when not posting anonymously. Max 50 characters.</span>
                </div>
                <div class="form-group full">
                  <label class="form-label">Student ID</label>
                  <input class="form-input readonly" type="text"
                         value="<?= clean($user['student_id'] ?? 'N/A') ?>"
                         disabled>
                  <span class="form-hint">Student ID cannot be changed. Contact admin if there's an error.</span>
                </div>
              </div>

              <div class="submit-row">
                <button class="btn btn-primary btn-save" type="submit"><i data-lucide="save"></i> Save changes</button>
                <a class="btn btn-outline" href="profile.php">Cancel</a>
                <span class="save-note">Changes apply immediately after saving.</span>
              </div>
            </form>
          </div>
        </div>

        <!-- ── Change password form ── -->
        <div class="card mc-reveal">
          <div class="card-tabs">
            <div class="section-tabs">
              <span class="section-tab active"><i data-lucide="lock"></i> Change password</span>
            </div>
          </div>
          <div class="card-body">

            <?php if ($section === 'password' && !empty($errors)): ?>
              <ul class="error-list">
                <?php foreach ($errors as $e): ?><li><?= clean($e) ?></li><?php endforeach; ?>
              </ul>
            <?php endif; ?>

            <form method="POST" action="profile.php" id="passwordForm">
              <input type="hidden" name="section" value="password">

              <div class="form-grid">
                <div class="form-group full">
                  <label class="form-label" for="current_password">Current password</label>
                  <input class="form-input" type="password" id="current_password" name="current_password"
                         placeholder="Enter your current password" required autocomplete="current-password">
                </div>
                <div class="form-group">
                  <label class="form-label" for="new_password">New password</label>
                  <input class="form-input" type="password" id="new_password" name="new_password"
                         placeholder="At least 8 characters" required
                         minlength="8" autocomplete="new-password"
                         oninput="checkStrength(this.value)">
                  <div class="pw-strength-bar">
                    <div class="pw-strength-fill" id="strengthFill"></div>
                  </div>
                  <div class="pw-strength-label" id="strengthLabel"></div>
                </div>
                <div class="form-group">
                  <label class="form-label" for="confirm_password">Confirm new password</label>
                  <input class="form-input" type="password" id="confirm_password" name="confirm_password"
                         placeholder="Repeat new password" required
                         autocomplete="new-password"
                         oninput="checkMatch()">
                  <div class="form-hint" id="matchHint" style="margin-top:4px"></div>
                </div>
              </div>

              <div class="submit-row">
                <button class="btn btn-primary btn-save" type="submit"><i data-lucide="shield-check"></i> Update password</button>
                <a class="btn btn-outline" href="profile.php">Cancel</a>
              </div>
            </form>
          </div>
        </div>

        <!-- ── Account info (read-only) ── -->
        <div class="card mc-reveal">
          <div class="card-tabs">
            <div class="section-tabs">
              <span class="section-tab active"><i data-lucide="info"></i> Account information</span>
            </div>
          </div>
          <div class="card-body">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">Account role</label>
                <input class="form-input readonly" type="text" value="Student" disabled>
              </div>
              <div class="form-group">
                <label class="form-label">Account status</label>
                <input class="form-input readonly" type="text" value="Active ✓" disabled style="color:var(--green-dark)">
              </div>
              <div class="form-group">
                <label class="form-label">Member since</label>
                <input class="form-input readonly" type="text" value="<?= $member_since ?>" disabled>
              </div>
              <div class="form-group">
                <label class="form-label">Last updated</label>
                <input class="form-input readonly" type="text"
                       value="<?= $user['updated_at'] ? date('M j, Y', strtotime($user['updated_at'])) : 'N/A' ?>"
                       disabled>
              </div>
            </div>
            <div class="privacy-note">
              <i data-lucide="lock"></i>
              <span><strong>Privacy note:</strong> Your student ID, account role, and creation date are managed by the system admin and cannot be changed here. For account issues, contact your guidance office.</span>
            </div>
          </div>
        </div>

        <!-- ── Danger zone ── -->
        <div class="danger-zone mc-reveal">
          <div class="dz-title"><i data-lucide="alert-triangle"></i> Danger zone</div>
          <div class="dz-sub">
            These actions are permanent and cannot be undone. Please be absolutely sure before proceeding.
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <button class="btn btn-danger"
                    onclick="if(confirm('Sign out from all devices? You will be logged out everywhere.')) window.location.href='../../auth/logout.php?all=1'">
              <i data-lucide="log-out"></i> Sign out everywhere
            </button>
            <button class="btn btn-danger"
                    onclick="alert('To request account deletion, please contact your guidance counselor or system administrator directly.')">
              <i data-lucide="user-x"></i> Request account deletion
            </button>
          </div>
        </div>

      </div>
    </div>

  </main>
</div>

<script>
// ── Password strength checker ─────────────────────────────────
function checkStrength(val) {
  const fill  = document.getElementById('strengthFill');
  const label = document.getElementById('strengthLabel');
  if (!fill || !label) return;

  let score = 0;
  if (val.length >= 8)              score++;
  if (val.length >= 12)             score++;
  if (/[A-Z]/.test(val))            score++;
  if (/[0-9]/.test(val))            score++;
  if (/[^A-Za-z0-9]/.test(val))     score++;

  const levels = [
    { w: '0%',   color: 'transparent',    text: '' },
    { w: '25%',  color: 'var(--red)',     text: 'Weak' },
    { w: '50%',  color: 'var(--amber)',   text: 'Fair' },
    { w: '75%',  color: 'var(--amber)',   text: 'Good' },
    { w: '90%',  color: 'var(--green)',   text: 'Strong' },
    { w: '100%', color: 'var(--green-dark)', text: 'Very strong ✓' },
  ];
  const lv = val.length === 0 ? 0 : Math.min(score, 5);
  fill.style.width    = levels[lv].w;
  fill.style.background = levels[lv].color;
  label.textContent   = levels[lv].text;
  label.style.color   = levels[lv].color;
}

// ── Password match checker ────────────────────────────────────
function checkMatch() {
  const np   = document.getElementById('new_password')?.value      || '';
  const cp   = document.getElementById('confirm_password')?.value  || '';
  const hint = document.getElementById('matchHint');
  if (!hint || !cp) return;
  if (np === cp) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = 'var(--green-dark)';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = 'var(--red)';
  }
}

// ── Confirm before leaving with unsaved changes ───────────────
let profileDirty   = false;
let passwordDirty  = false;

document.querySelectorAll('#profileForm  input').forEach(el => el.addEventListener('input', () => profileDirty  = true));
document.querySelectorAll('#passwordForm input').forEach(el => el.addEventListener('input', () => passwordDirty = true));

document.querySelectorAll('form').forEach(f => {
  f.addEventListener('submit', () => { profileDirty = false; passwordDirty = false; });
});

window.addEventListener('beforeunload', e => {
  if (profileDirty || passwordDirty) {
    e.preventDefault();
    e.returnValue = '';
  }
});
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
