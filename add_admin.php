<?php
// ============================================================
//  CTU MindCare — Create admin accounts
//  Works with the NEW separate tables structure
//  DELETE this file after use!
// ============================================================
require_once 'includes/config.php';

$result = '';
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = htmlspecialchars(trim($_POST['first_name'] ?? ''));
    $last_name  = htmlspecialchars(trim($_POST['last_name']  ?? ''));
    $email      = htmlspecialchars(trim($_POST['email']      ?? ''));
    $password   = $_POST['password'] ?? '';
    $role       = $_POST['role']     ?? 'system_admin';
    $spec       = htmlspecialchars(trim($_POST['specialization'] ?? ''));

    if (empty($first_name)) $errors[] = 'First name is required.';
    if (empty($last_name))  $errors[] = 'Last name is required.';
    if (empty($email))      $errors[] = 'Email is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

    if (empty($errors)) {
        $db    = getDB();
        $table = $role === 'system_admin' ? 'system_admins' : 'wellness_admins';

        $check = $db->prepare("SELECT id FROM {$table} WHERE email = ?");
        $check->execute([$email]);

        if ($check->fetch()) {
            $errors[] = 'Email already exists in this role.';
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);

            if ($role === 'system_admin') {
                $stmt = $db->prepare("INSERT INTO system_admins (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
                $stmt->execute([$first_name, $last_name, $email, $hash]);
            } else {
                $stmt = $db->prepare("INSERT INTO wellness_admins (first_name, last_name, email, password, specialization) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$first_name, $last_name, $email, $hash, $spec ?: null]);
            }

            $result = 'success';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create Admin — CTU MindCare</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --sage:#4A9B7F;--sage-2:#5BAF91;--sage-light:#EAF7F2;--sage-mid:#C4E8DC;--sage-dark:#2D6B57;
  --sand:#F7F3EE;--sand-3:#D9D3C9;--lav:#7B6FC4;--lav-light:#EEEDFE;
  --text:#2C2C2A;--text-2:#5C5B57;--text-3:#9A9891;--white:#FFFFFF;--coral:#D96B52;
  --red:#8B2020;--red-light:#FBEDED;
}
body{font-family:'Segoe UI',sans-serif;background:var(--sand);display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
.card{background:var(--white);border:1px solid #E8E4DE;border-radius:14px;padding:36px;width:100%;max-width:480px;}
.card-title{font-size:20px;font-weight:700;color:var(--text);margin-bottom:4px;}
.card-sub{font-size:13px;color:var(--text-3);margin-bottom:24px;}
.warn{background:#FAEEDA;border:1px solid #FAC775;border-radius:8px;padding:10px 14px;font-size:12px;color:#412402;margin-bottom:20px;}
.success-box{background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:10px;padding:20px;margin-bottom:20px;}
.success-box h3{font-size:15px;color:var(--sage-dark);margin-bottom:8px;}
.success-box p{font-size:13px;color:#085041;line-height:1.6;}
.success-box a{display:inline-block;margin-top:14px;padding:9px 18px;background:var(--sage);color:#fff;border-radius:7px;text-decoration:none;font-weight:500;font-size:13px;}
.success-box a:hover{background:var(--sage-2);}
.error-box{background:var(--red-light);border:1px solid #F5C6C2;border-radius:8px;padding:10px 14px;font-size:12px;color:var(--red);margin-bottom:16px;}
.error-box ul{padding-left:16px;margin:0;}
.error-box li{padding:2px 0;}
.form-group{margin-bottom:16px;}
label{display:block;font-size:12px;font-weight:500;color:var(--text-2);margin-bottom:7px;}
input,select{width:100%;padding:10px 14px;background:var(--sand);border:1.5px solid var(--sand-3);border-radius:9px;font-size:14px;color:var(--text);outline:none;font-family:inherit;transition:border-color .2s;}
input:focus,select:focus{background:var(--white);border-color:var(--sage);box-shadow:0 0 0 3px rgba(74,155,127,.10);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.role-cards{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:4px;}
.role-card input{display:none;}
.role-card-label{display:block;padding:12px;border-radius:10px;border:1.5px solid var(--sand-3);cursor:pointer;text-align:center;transition:all .15s;background:var(--sand);}
.role-card-label:hover{border-color:var(--sage);}
.role-card input:checked + .role-card-label{border-color:var(--sage);background:var(--sage-light);}
.role-card-icon{font-size:22px;display:block;margin-bottom:4px;}
.role-card-name{font-size:12px;font-weight:500;color:var(--text-2);}
.role-card-desc{font-size:10px;color:var(--text-3);margin-top:2px;}
#spec-group{display:none;}
button{width:100%;padding:12px;background:var(--sage);color:#fff;border:none;border-radius:9px;font-size:14px;font-weight:500;cursor:pointer;font-family:inherit;margin-top:4px;transition:background .2s;}
button:hover{background:var(--sage-2);}
</style>
</head>
<body>
<div class="card">
  <div class="card-title">Create admin account</div>
  <div class="card-sub">CTU MindCare — admin setup tool</div>

  <div class="warn">⚠️ Delete this file immediately after creating your account.</div>

  <?php if ($result === 'success'): ?>
    <div class="success-box">
      <h3>✓ Account created successfully!</h3>
      <p>
        Name: <strong><?= htmlspecialchars($first_name . ' ' . $last_name) ?></strong><br>
        Email: <strong><?= htmlspecialchars($email) ?></strong><br>
        Role: <strong><?= $role === 'system_admin' ? 'System Admin' : 'Wellness Admin (Counselor)' ?></strong>
      </p>
      <a href="auth/login.php">Go to login page →</a>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="error-box">
      <ul>
        <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="POST" action="">
    <div class="form-group">
      <label>Role</label>
      <div class="role-cards">
        <div class="role-card">
          <input type="radio" name="role" id="r_admin" value="system_admin" checked onchange="toggleSpec(false)">
          <label class="role-card-label" for="r_admin">
            <span class="role-card-icon">⚙️</span>
            <span class="role-card-name">System Admin</span>
            <span class="role-card-desc">Full system access</span>
          </label>
        </div>
        <div class="role-card">
          <input type="radio" name="role" id="r_wellness" value="wellness_admin" onchange="toggleSpec(true)">
          <label class="role-card-label" for="r_wellness">
            <span class="role-card-icon">🧑‍⚕️</span>
            <span class="role-card-name">Wellness Admin</span>
            <span class="role-card-desc">Counselor access</span>
          </label>
        </div>
      </div>
    </div>

    <div class="form-row">
      <div class="form-group">
        <label for="fn">First name</label>
        <input id="fn" type="text" name="first_name" placeholder="Juan" value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label for="ln">Last name</label>
        <input id="ln" type="text" name="last_name" placeholder="Dela Cruz" value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
      </div>
    </div>

    <div class="form-group">
      <label for="em">Email address</label>
      <input id="em" type="email" name="email" placeholder="admin@ctu.edu.ph" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
    </div>

    <div class="form-group" id="spec-group">
      <label for="spec">Specialization <span style="font-weight:400;color:var(--text-3)">(optional)</span></label>
      <input id="spec" type="text" name="specialization" placeholder="e.g. General Counseling, Anxiety, Stress" value="<?= htmlspecialchars($_POST['specialization'] ?? '') ?>">
    </div>

    <div class="form-group">
      <label for="pw">Password</label>
      <input id="pw" type="password" name="password" placeholder="Min. 8 characters" required>
    </div>

    <button type="submit">Create account</button>
  </form>
</div>
<script>
function toggleSpec(show) {
  document.getElementById('spec-group').style.display = show ? 'block' : 'none';
}
</script>
</body>
</html>