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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Account — CTU MindCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --sage:#4A9B7F;--sage-2:#5BAF91;--sage-light:#EAF7F2;--sage-mid:#C4E8DC;--sage-dark:#2D6B57;
  --sand:#F7F3EE;--sand-2:#EDE8E1;--sand-3:#D9D3C9;--blush:#F5EDE8;
  --text:#2C2C2A;--text-2:#5C5B57;--text-3:#9A9891;--white:#FFFFFF;--coral:#D96B52;
  --lav:#7B6FC4;--lav-2:#6B5FB4;--lav-light:#EEEDFE;
}
body{font-family:'DM Sans',sans-serif;background:var(--sand);min-height:100vh;display:grid;grid-template-columns:1fr 1fr;}

/* ── Left panel ── */
.left-panel{background:var(--sage);padding:48px;display:flex;flex-direction:column;justify-content:space-between;position:relative;overflow:hidden;}
.left-panel::before{content:'';position:absolute;top:-80px;right:-80px;width:320px;height:320px;border-radius:50%;background:rgba(255,255,255,.08);}
.left-panel::after{content:'';position:absolute;bottom:-100px;left:-60px;width:280px;height:280px;border-radius:50%;background:rgba(255,255,255,.06);}
.brand{display:flex;align-items:center;gap:12px;position:relative;z-index:1;}
.brand-mark{width:44px;height:44px;background:rgba(255,255,255,.2);border-radius:12px;display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:22px;color:#fff;border:1px solid rgba(255,255,255,.3);}
.brand-name{font-family:'DM Serif Display',serif;font-size:20px;color:#fff;letter-spacing:.2px;}
.brand-sub{font-size:11px;color:rgba(255,255,255,.65);margin-top:2px}
.hero-text{position:relative;z-index:1;}
.hero-title{font-family:'DM Serif Display',serif;font-size:38px;line-height:1.2;color:#fff;margin-bottom:16px;}
.hero-title em{font-style:italic;color:rgba(255,255,255,.75)}
.hero-body{font-size:14px;color:rgba(255,255,255,.75);line-height:1.7;max-width:340px;}
.quotes{position:relative;z-index:1;}
.quote-card{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:14px;padding:20px 22px;}
.quote-text{font-family:'DM Serif Display',serif;font-style:italic;font-size:15px;color:#fff;line-height:1.6;margin-bottom:12px;}
.quote-author{font-size:11px;color:rgba(255,255,255,.55);}

/* ── Right panel ── */
.right-panel{display:flex;align-items:center;justify-content:center;padding:48px 40px;background:var(--sand);overflow-y:auto;}
.form-box{width:100%;max-width:480px;animation:fadein .5s ease both}
@keyframes fadein{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.form-greeting{font-family:'DM Serif Display',serif;font-size:28px;color:var(--text);margin-bottom:6px;}
.form-sub{font-size:13px;color:var(--text-3);margin-bottom:24px;line-height:1.5}

/* ── Alerts ── */
.alert{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:20px;line-height:1.5;display:flex;align-items:flex-start;gap:8px;}
.alert-error{background:#FBEDED;border:1px solid #F5C6C2;color:#8B2020;}
.alert-success{background:var(--sage-light);border:1px solid var(--sage-mid);color:var(--sage-dark);}
.alert-icon{font-size:14px;flex-shrink:0;margin-top:1px}

/* ── Form fields ── */
.form-group{margin-bottom:18px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-label{display:block;font-size:12px;font-weight:500;color:var(--text-2);margin-bottom:7px;letter-spacing:.2px;}
.form-label-opt{font-size:10px;font-weight:400;color:var(--text-3);text-transform:none;}
.form-input{width:100%;padding:12px 16px;background:var(--white);border:1.5px solid var(--sand-3);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:var(--sage);box-shadow:0 0 0 4px rgba(74,155,127,.10);}
.form-input::placeholder{color:var(--text-3)}
.form-hint{font-size:11px;color:var(--text-3);margin-top:5px;line-height:1.5;}
.input-wrap{position:relative}
.input-wrap .form-input{padding-right:54px}
.pw-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:11px;font-weight:500;color:var(--sage);font-family:'DM Sans',sans-serif;}
.pw-toggle:hover{color:var(--sage-2)}

/* ── Password bar ── */
.pw-bar{height:3px;border-radius:2px;background:var(--sand-3);margin-top:8px;overflow:hidden}
.pw-fill{height:100%;border-radius:2px;width:0%;transition:width .3s,background .3s}
.pw-label{font-size:11px;margin-top:4px;font-weight:500}

/* ── Consent ── */
.consent-wrap{background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:10px;padding:16px;display:flex;gap:12px;align-items:flex-start;margin-bottom:20px;}
.consent-wrap.has-error{background:#FBEDED;border-color:#F5C6C2}
.consent-wrap input[type=checkbox]{margin-top:2px;flex-shrink:0;accent-color:var(--sage);width:15px;height:15px;cursor:pointer}
.consent-text{font-size:12px;color:var(--sage-dark);line-height:1.65}
.consent-wrap.has-error .consent-text{color:#8B2020}

/* ── Submit button ── */
.btn-primary{width:100%;padding:13px;color:#fff;background:var(--sage);border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:background .2s,transform .1s;margin-top:4px;}
.btn-primary:hover{background:var(--sage-2)}
.btn-primary:active{transform:scale(.99)}

/* ── Footer ── */
.form-footer{text-align:center;font-size:13px;color:var(--text-3);margin-top:22px;}
.form-footer a{color:var(--sage);font-weight:500;text-decoration:none;}
.form-footer a:hover{text-decoration:underline}

/* ── Section divider ── */
.section-title{font-size:10px;font-weight:600;color:var(--sage-dark);text-transform:uppercase;letter-spacing:.1em;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid var(--sage-light)}

@media(max-width:768px){body{grid-template-columns:1fr}.left-panel{display:none}.right-panel{padding:28px 20px}.form-box{max-width:100%}.form-row{grid-template-columns:1fr}}
</style>
</head>
<body>

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
  <div class="form-box">
    <div class="form-greeting">Create your account 🎯</div>
    <p class="form-sub">Join CTU MindCare and start your wellness journey today.</p>

    <!-- Error alerts -->
    <?php if (!empty($errors)): ?>
      <div class="alert alert-error">
        <span class="alert-icon">!</span>
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

      <button class="btn-primary" type="submit">Create my account →</button>

    </form>

    <p class="form-footer">
      Already have an account? <a href="login.php">Sign in here</a>
    </p>

  </div>
</div>

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
  const colors = ['','#C03030','#D4860A','#4A9B7F'];
  const labels = ['','Weak','Fair','Strong'];
  const fill = document.getElementById('pw-fill');
  const label = document.getElementById('pw-label');
  fill.style.width = v ? fills[s] : '0%';
  fill.style.background = colors[s] || '';
  label.textContent = v && s ? labels[s] : '';
  label.style.color = colors[s] || '';
}

document.getElementById('cpw')?.addEventListener('input', function () {
  const pw = document.getElementById('pw').value;
  const hint = document.getElementById('match-hint');
  if (!this.value) { hint.textContent = ''; return; }
  if (this.value === pw) {
    hint.textContent = '✓ Passwords match';
    hint.style.color = '#2D6B57';
  } else {
    hint.textContent = '✗ Passwords do not match';
    hint.style.color = '#C03030';
  }
});
</script>

</body>
</html>