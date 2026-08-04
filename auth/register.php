<?php
require_once '../includes/auth.php';
redirectIfLoggedIn();

$errors = [];
$values = [];
$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['_csrf_token'] ?? '')) {
        $errors[] = 'Invalid form submission. Please refresh the page and try again.';
    }

    $values = [
        'first_name' => clean($_POST['first_name'] ?? ''),
        'last_name'  => clean($_POST['last_name']  ?? ''),
        'student_id' => clean($_POST['student_id'] ?? ''),
        'email'      => clean($_POST['email']      ?? ''),
        'dname'      => clean($_POST['dname']      ?? ''),
    ];
    $password = $_POST['password']         ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($values['first_name'])) $errors[] = 'First name is required.';
    if (empty($values['last_name']))  $errors[] = 'Last name is required.';
    if (empty($values['student_id'])) $errors[] = 'Student ID is required.';
    if (empty($values['email']))      $errors[] = 'Email address is required.';
    if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if (strlen($password) < 8)        $errors[] = 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter.';
    if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number.';
    if ($password !== $confirm)        $errors[] = 'Passwords do not match.';
    if (empty($_POST['consent']))      $errors[] = 'You must agree to the data consent statement.';

    if (empty($errors)) {
        $db    = getDB();
        $check = $db->prepare("SELECT id FROM users WHERE email = ? OR student_id = ?");
        $check->execute([$values['email'], $values['student_id']]);
        if ($check->fetch()) {
            $errors[] = 'An account with that email or student ID already exists.';
        } else {
            $stmt = $db->prepare("
                INSERT INTO users (student_id, first_name, last_name, email, password, display_name, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $values['student_id'],
                $values['first_name'],
                $values['last_name'],
                $values['email'],
                hashPassword($password),
                !empty($values['dname']) ? $values['dname'] : null,
            ]);
            setFlash('success', 'Your registration request has been sent. The MIS office will verify your student information before activation.');
            header('Location: login.php');
            exit;
        }
    }
}
$mc_title = 'Create Account — CTU MindCare';
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
      <div class="brand-sub">Mental Health Support</div>
    </div>
  </div>
  <div class="hero-text">
    <div class="hero-title">Join a <em>safe</em><br>community of<br>support.</div>
    <p class="hero-body">Track your wellness, connect with counselors, and find support from your peers—all in one secure space designed for CTU students.</p>
  </div>
  <div class="quotes">
    <div class="quote-card">
      <div class="quote-text">"Taking care of your mental health is not self-centered, it's essential."</div>
      <div class="quote-author">CTU Consolacion Wellness Office</div>
    </div>
  </div>
</div>

<!-- ── Right panel ── -->
<div class="right-panel">
  <div class="form-box" style="max-width:480px">
    <div class="form-greeting">Create your account</div>
    <p class="form-sub">Join CTU MindCare and start your wellness journey today.</p>

    <!-- Error alerts -->
    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <span class="alert-icon"><i data-lucide="alert-triangle"></i></span>
        <div>
          <strong style="display:block;margin-bottom:4px">Please fix these issues:</strong>
          <div><?php echo implode('<br>', array_map('clean', $errors)); ?></div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf_token" value="<?= $csrf_token ?>">

      <div class="section-title">Personal Info</div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="fname">First name</label>
          <input class="form-input" id="fname" type="text" name="first_name"
            placeholder="Juan" value="<?= $values['first_name'] ?? '' ?>" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label" for="lname">Last name</label>
          <input class="form-input" id="lname" type="text" name="last_name"
            placeholder="Dela Cruz" value="<?= $values['last_name'] ?? '' ?>" required>
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="sid">Student ID</label>
        <input class="form-input" id="sid" type="text" name="student_id"
          placeholder="CTU-2024-00001" value="<?= $values['student_id'] ?? '' ?>" required>
      </div>

      <div class="section-title" style="margin-top:20px">Account Details</div>

      <div class="form-group">
        <label class="form-label" for="email">Email address</label>
        <input class="form-input" id="email" type="email" name="email"
          placeholder="yourname@ctu.edu.ph" value="<?= $values['email'] ?? '' ?>" required>
      </div>

      <div class="form-group">
        <label class="form-label" for="dname">
          Display name <span class="form-label-opt">(optional)</span>
        </label>
        <input class="form-input" id="dname" type="text" name="dname"
          placeholder="How peers will see you in forums"
          value="<?= $values['dname'] ?? '' ?>">
        <div class="form-hint">Leave blank for an auto-generated anonymous name</div>
      </div>

      <div class="section-title" style="margin-top:20px">Password</div>

      <div class="form-group">
        <label class="form-label" for="pw">Password</label>
        <div class="input-wrap">
          <input class="form-input" id="pw" type="password" name="password"
            placeholder="Min. 8 chars, 1 uppercase, 1 number"
            oninput="checkPw(this.value)" required>
          <button type="button" class="pw-toggle" onclick="togglePw('pw', this)">Show</button>
        </div>
        <div class="pw-bar"><div class="pw-fill" id="pw-fill"></div></div>
        <div class="pw-label" id="pw-label"></div>
      </div>

      <div class="form-group">
        <label class="form-label" for="cpw">Confirm password</label>
        <div class="input-wrap">
          <input class="form-input" id="cpw" type="password" name="confirm_password"
            placeholder="Repeat your password" required>
          <button type="button" class="pw-toggle" onclick="togglePw('cpw', this)">Show</button>
        </div>
        <div class="form-hint" id="match-hint"></div>
      </div>

      <div class="consent-wrap <?= in_array('You must agree to the data consent statement.', $errors) ? 'has-error' : '' ?>">
        <input type="checkbox" id="consent" name="consent" <?= !empty($_POST['consent']) ? 'checked' : '' ?>>
        <label class="consent-text" for="consent">
          I agree that my mood check-ins and assessment results may be reviewed by CTU wellness counselors to provide appropriate support. My data will not be shared outside the wellness office without my consent.
        </label>
      </div>

      <button class="btn btn-primary" type="submit" style="width:100%;margin-top:4px">
        <i data-lucide="user-plus"></i> Create my account
      </button>

    </form>

    <p class="form-footer">
      Already have an account? <a href="login.php">Sign in here</a>
    </p>

  </div>
</div>

<!-- Floating dark-mode toggle -->
<button type="button" class="mc-theme-float mc-theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
  <i data-lucide="moon" class="icon-moon"></i>
  <i data-lucide="sun" class="icon-sun"></i>
</button>

<script>
function togglePw(id, btn) {
  const i = document.getElementById(id);
  i.type = i.type === 'password' ? 'text' : 'password';
  btn.textContent = i.type === 'password' ? 'Show' : 'Hide';
}

function checkPw(v) {
  let s = 0;
  if (v.length >= 8) s++;
  if (/[A-Z]/.test(v)) s++;
  if (/[0-9]/.test(v)) s++;
  const fills = ['0%','30%','60%','100%'];
  const fill = document.getElementById('pw-fill');
  const label = document.getElementById('pw-label');
  fill.style.width = v ? fills[s] : '0%';
  fill.style.background = s === 0 ? '' : (s < 3 ? 'var(--amber)' : 'var(--teal)');
  label.textContent = v && s ? (s < 3 ? 'Fair' : 'Strong') : '';
  label.style.color = s === 0 ? '' : (s < 3 ? 'var(--amber)' : 'var(--teal)');
}

document.getElementById('cpw')?.addEventListener('input', function () {
  const pw = document.getElementById('pw').value;
  const hint = document.getElementById('match-hint');
  if (!this.value) { hint.textContent = ''; return; }
  if (this.value === pw) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = 'var(--teal)';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = 'var(--red)';
  }
});
</script>

</body>
</html>
