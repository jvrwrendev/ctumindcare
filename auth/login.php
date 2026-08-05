<?php
require_once '../includes/auth.php';
redirectIfLoggedIn();

$error = '';
$flash = getFlash();
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please refresh and try again.';
    } else {
        $email    = clean($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            $db    = getDB();
            $user  = null;
            $role  = null;
            $tables = [
                'student'        => 'users',
                'wellness_admin' => 'wellness_admins',
                'system_admin'   => 'system_admins',
            ];

            foreach ($tables as $candidateRole => $table) {
                $stmt = $db->prepare("
                    SELECT id, first_name, last_name, email, password, is_active
                    FROM {$table} WHERE email = ?
                ");
                $stmt->execute([$email]);
                if ($found = $stmt->fetch()) {
                    $user = $found;
                    $role = $candidateRole;
                    break;
                }
            }

            if (!$user || !verifyPassword($password, $user['password'])) {
                $error = 'Incorrect email or password. Please try again.';
            } elseif (!$user['is_active']) {
                $error = $role === 'student'
                    ? 'Your account is not active yet. The MIS office must approve it before you can sign in.'
                    : 'Your account is not active yet. Please contact the system administrator.';
            } else {
                session_regenerate_id(true);
                $_SESSION['user_id']   = $user['id'];
                $_SESSION['user_role'] = $role;
                $_SESSION['user_name'] = $user['first_name'];

                $table = match ($role) {
                    'system_admin'   => 'system_admins',
                    'wellness_admin' => 'wellness_admins',
                    default          => 'users',
                };
                $db->prepare("UPDATE {$table} SET updated_at = NOW() WHERE id = ?")->execute([$user['id']]);

                setFlash('success', 'Welcome back, ' . $user['first_name'] . '!');
                redirectIfLoggedIn();
            }
        }
    }
}
$mc_title = 'Sign In — CTU MindCare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require_once '../includes/assets.php'; ?>
</head>
<body class="auth-body">

<!-- ── Left panel ── -->
<div class="left-panel">
  <div class="brand">
    <div class="brand-mark">M</div>
    <div>
      <div class="brand-name">CTU MindCare</div>
      <div class="brand-sub">Mental Health Support System</div>
    </div>
  </div>
  <div class="hero-text">
    <div class="hero-title">Your mental<br>health <em>matters</em><br>here.</div>
    <p class="hero-body">A safe, supportive space for CTU Consolacion students to track their wellness, connect with counselors, and find the support they deserve.</p>
  </div>
  <div class="pill-wrap">
    <div class="mood-pill"><div class="pill-dot" style="background:#388E75"></div> Feeling good today</div>
    <div class="mood-pill"><div class="pill-dot" style="background:#FDE68A"></div> A bit stressed</div>
    <div class="mood-pill"><div class="pill-dot" style="background:#C4B5FD"></div> Anxious but okay</div>
    <div class="mood-pill"><div class="pill-dot" style="background:#FCA5A5"></div> Need support</div>
  </div>
  <div class="quotes">
    <div class="quote-card">
      <div class="quote-text">"You don't have to face this alone. Reaching out is a sign of strength."</div>
      <div class="quote-author">CTU Consolacion Wellness Office</div>
    </div>
  </div>
</div>

<!-- ── Right panel ── -->
<div class="right-panel">
  <div class="form-box">

    <div class="auth-icon-wrap">
      <div class="auth-icon">
        <i data-lucide="lock"></i>
      </div>
    </div>

    <div class="form-greeting">Welcome back</div>
    <p class="form-sub">Sign in to your account and continue where you left off.</p>

    <!-- Flash -->
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
        <span class="alert-icon"><i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>"></i></span>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Error -->
    <?php if ($error): ?>
      <div class="alert alert-error">
        <span class="alert-icon"><i data-lucide="alert-triangle"></i></span>
        <?= clean($error) ?>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" novalidate data-validate>
      <input type="hidden" name="_csrf_token" value="<?= $csrf_token ?>">

      <div class="form-group">
        <label class="form-label" for="emailInput">Email address</label>
        <div class="input-wrap">
          <input
            class="form-input <?= $error ? 'input-error' : '' ?>"
            type="email"
            name="email"
            id="emailInput"
            placeholder="yourname@ctu.edu.ph"
            value="<?= clean($_POST['email'] ?? '') ?>"
            autocomplete="email"
            autofocus
            required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="pw">
          <span>Password</span>
          <a href="forgot-password.php">Forgot password?</a>
        </label>
        <div class="input-wrap">
          <input
            class="form-input <?= $error ? 'input-error' : '' ?>"
            type="password"
            id="pw"
            name="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            required>
          <button type="button" class="pw-toggle" onclick="togglePw()">Show</button>
        </div>
      </div>

      <button type="submit" class="btn btn-primary" id="loginBtn" style="width:100%;margin-top:4px">
        <i data-lucide="log-in"></i> Sign In
      </button>

    </form>

    <!-- Crisis bar -->
    <div class="crisis-bar">
      <div class="crisis-pulse"></div>
      <div class="crisis-text">
        <strong>In crisis right now?</strong><br>
        Hopeline: 02-804-4673 &nbsp;·&nbsp; Text HELLO to 4673
      </div>
    </div>

    <!-- Register link -->
    <p class="form-footer">
      Don't have an account? <a href="../index.php">Register here</a>
    </p>

  </div>
</div>

<!-- Floating dark-mode toggle -->
<button type="button" class="mc-theme-float mc-theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
  <i data-lucide="moon" class="icon-moon"></i>
  <i data-lucide="sun" class="icon-sun"></i>
</button>

<script>
function togglePw() {
  const i = document.getElementById('pw');
  const b = document.querySelector('.pw-toggle');
  i.type        = i.type === 'password' ? 'text' : 'password';
  b.textContent = i.type === 'password' ? 'Show' : 'Hide';
}
</script>
</body>
</html>
