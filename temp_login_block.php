  <?php
  require_once '../includes/auth.php';
  redirectIfLoggedIn();

  $error = '';
  $flash = getFlash();
  $csrf_token = generateCsrfToken();
  $selected_role = clean($_POST['role'] ?? 'student');

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (!validateCsrfToken($_POST['_csrf_token'] ?? '')) {
          $error = 'Invalid form submission. Please refresh and try again.';
      } else {
          $email    = clean($_POST['email'] ?? '');
          $password = $_POST['password']    ?? '';
          $role_try = $_POST['role']        ?? 'student';

          if (empty($email) || empty($password)) {
              $error = 'Please enter your email and password.';
          } else {
          $db   = getDB();
          $user = null;
          $role = '';

          if ($role_try === 'system_admin') {
              $stmt = $db->prepare("SELECT id, first_name, last_name, email, password, is_active FROM system_admins WHERE email = ?");
              $stmt->execute([$email]);
              $user = $stmt->fetch();
              $role = 'system_admin';

          } elseif ($role_try === 'wellness_admin') {
              $stmt = $db->prepare("SELECT id, first_name, last_name, email, password, is_active FROM wellness_admins WHERE email = ?");
              $stmt->execute([$email]);
              $user = $stmt->fetch();
              $role = 'wellness_admin';

          } else {
              $stmt = $db->prepare("SELECT id, first_name, last_name, email, password, is_active FROM users WHERE email = ?");
              $stmt->execute([$email]);
              $user = $stmt->fetch();
              $role = 'student';
          }

          if (!$user || !verifyPassword($password, $user['password'])) {
              $error = 'Incorrect email or password. Please try again.';
          } elseif (!$user['is_active']) {
              $error = 'Your account is not active yet. The MIS office must approve it before you can sign in.';
          } else {
              session_regenerate_id(true);
              $_SESSION['user_id']   = $user['id'];
              $_SESSION['user_role'] = $role;
              $_SESSION['user_name'] = $user['first_name'];

              $table = match($role) {
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