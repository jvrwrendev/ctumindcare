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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sign In — CTU MindCare</title>
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
.pill-wrap{position:absolute;right:48px;top:50%;transform:translateY(-50%);display:flex;flex-direction:column;gap:10px;z-index:1;}
.mood-pill{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);border-radius:100px;padding:8px 16px;display:flex;align-items:center;gap:8px;font-size:12px;color:#fff;animation:float 4s ease-in-out infinite;}
.mood-pill:nth-child(2){animation-delay:.8s}.mood-pill:nth-child(3){animation-delay:1.6s}.mood-pill:nth-child(4){animation-delay:2.4s}
.pill-dot{width:8px;height:8px;border-radius:50%}
@keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(-6px)}}

/* ── Right panel ── */
.right-panel{display:flex;align-items:center;justify-content:center;padding:48px 40px;background:var(--sand);}
.form-box{width:100%;max-width:400px;animation:fadein .5s ease both}
@keyframes fadein{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}

/* ── Auth icon ── */
.auth-icon-wrap{display:flex;justify-content:center;margin-bottom:22px;}
.auth-icon{
  width:68px;height:68px;border-radius:50%;
  background:linear-gradient(135deg,var(--sage),var(--sage-2));
  box-shadow:0 10px 26px rgba(74,155,127,.32), inset 0 1px 0 rgba(255,255,255,.35);
  display:flex;align-items:center;justify-content:center;
}
.auth-icon svg{width:30px;height:30px;fill:none;stroke:#fff;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;}
.form-greeting{font-family:'DM Serif Display',serif;font-size:28px;color:var(--text);margin-bottom:6px;text-align:center;}
.form-sub{font-size:13px;color:var(--text-3);margin-bottom:24px;line-height:1.5;text-align:center;}

/* ── Alerts ── */
.alert{padding:11px 14px;border-radius:9px;font-size:13px;margin-bottom:20px;line-height:1.5;display:flex;align-items:flex-start;gap:8px;}
.alert-error  {background:#FBEDED;border:1px solid #F5C6C2;color:#8B2020;}
.alert-success{background:var(--sage-light);border:1px solid var(--sage-mid);color:var(--sage-dark);}
.alert-icon{font-size:14px;flex-shrink:0;margin-top:1px}

/* ── Form fields ── */
.form-group{margin-bottom:18px}
.form-label{display:flex;justify-content:space-between;align-items:center;font-size:12px;font-weight:500;color:var(--text-2);margin-bottom:7px;letter-spacing:.2px;}
.form-label a{color:var(--sage);font-weight:400;font-size:11px;text-decoration:none;}
.form-label a:hover{text-decoration:underline}
.input-wrap{position:relative}
.form-input{width:100%;padding:12px 16px;background:var(--white);border:1.5px solid var(--sand-3);border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);outline:none;transition:border-color .2s,box-shadow .2s;}
.form-input:focus{border-color:var(--sage);box-shadow:0 0 0 4px rgba(74,155,127,.10);}
.form-input::placeholder{color:var(--text-3)}
.form-input.input-error{border-color:#E24B4A;box-shadow:0 0 0 3px rgba(226,75,74,.08);}
.pw-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:11px;font-weight:500;color:var(--sage);font-family:'DM Sans',sans-serif;}
.pw-toggle:hover{color:var(--sage-2)}

/* ── Submit button ── */
.btn-primary{width:100%;padding:13px;color:#fff;border:none;border-radius:10px;font-family:'DM Sans',sans-serif;font-size:14px;font-weight:500;cursor:pointer;transition:background .2s,transform .1s,box-shadow .2s;margin-top:4px;background:var(--sage);}
.btn-primary:hover{background:var(--sage-2);box-shadow:0 6px 18px rgba(74,155,127,.25);}
.btn-primary:active{transform:scale(.99)}

/* ── Crisis bar ── */
.crisis-bar{margin-top:24px;padding:12px 16px;background:linear-gradient(135deg,var(--blush),#fdf0eb);border-radius:10px;border:1px solid #F0C4B4;display:flex;align-items:center;gap:10px;}
.crisis-pulse{width:8px;height:8px;border-radius:50%;background:var(--coral);flex-shrink:0;animation:pulse 1.8s ease-in-out infinite;}
@keyframes pulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.5;transform:scale(.85)}}
.crisis-text{font-size:11px;color:#7A3520;line-height:1.5}
.crisis-text strong{font-weight:600}

/* ── Footer ── */
.form-footer{text-align:center;font-size:13px;color:var(--text-3);margin-top:22px;}
.form-footer a{color:var(--sage);font-weight:500;text-decoration:none;}
.form-footer a:hover{text-decoration:underline}

@media(max-width:768px){body{grid-template-columns:1fr}.left-panel{display:none}.right-panel{padding:32px 20px}}
</style>
</head>
<body>

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
    <div class="mood-pill"><div class="pill-dot" style="background:#A8E6CF"></div> Feeling good today</div>
    <div class="mood-pill"><div class="pill-dot" style="background:#FFD3B6"></div> A bit stressed</div>
    <div class="mood-pill"><div class="pill-dot" style="background:#C9B8F0"></div> Anxious but okay</div>
    <div class="mood-pill"><div class="pill-dot" style="background:#FFAAA5"></div> Need support</div>
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
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <rect x="3" y="11" width="18" height="11" rx="2.5"/>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          <circle cx="12" cy="16.5" r="1.4" fill="#fff" stroke="none"/>
        </svg>
      </div>
    </div>

    <div class="form-greeting">Welcome back</div>
    <p class="form-sub">Sign in to your account and continue where you left off.</p>

    <!-- Flash -->
    <?php if ($flash): ?>
      <div class="alert alert-<?= $flash['type'] ?>">
        <span class="alert-icon"><?= $flash['type'] === 'success' ? '✓' : '!' ?></span>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- Error -->
    <?php if ($error): ?>
      <div class="alert alert-error">
        <span class="alert-icon">!</span>
        <?= clean($error) ?>
      </div>
    <?php endif; ?>

    <!-- Form -->
    <form method="POST" action="" novalidate>
      <input type="hidden" name="_csrf_token" value="<?= $csrf_token ?>">

      <div class="form-group">
        <div class="form-label"><span>Email address</span></div>
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
        <div class="form-label">
          <span>Password</span>
          <a href="forgot-password.php">Forgot password?</a>
        </div>
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

      <button type="submit" class="btn-primary" id="loginBtn">
        Sign In
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
