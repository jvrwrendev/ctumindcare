<?php
require_once '../../includes/auth.php';
requireRole('system_admin');

$user = currentUser();
if (!$user) {
    $user = [
        'id' => $_SESSION['user_id'] ?? null,
        'first_name' => $_SESSION['user_name'] ?? 'Admin',
        'last_name' => '',
        'email' => '',
        'role' => 'system_admin',
        'display_name' => null,
        'profile_photo' => null,
    ];
}
$db   = getDB();

// ── Handle actions ────────────────────────────────────────────
$action = $_GET['action'] ?? '';
$target = (int)($_GET['id'] ?? 0);

if ($action === 'deactivate_student' && $target) {
    $db->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Student account deactivated.');
    header('Location: users.php'); exit;
}
if ($action === 'activate_student' && $target) {
    $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Student account activated.');
    header('Location: users.php'); exit;
}
if ($action === 'deactivate_counselor' && $target) {
    $db->prepare("UPDATE wellness_admins SET is_active = 0 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Counselor account deactivated.');
    header('Location: users.php'); exit;
}
if ($action === 'activate_counselor' && $target) {
    $db->prepare("UPDATE wellness_admins SET is_active = 1 WHERE id = ?")->execute([$target]);
    setFlash('success', 'Counselor account activated.');
    header('Location: users.php'); exit;
}
if ($action === 'resolve_crisis' && $target) {
    $db->prepare("UPDATE crisis_alerts SET status = 'acknowledged', acknowledged_by = ? WHERE id = ?")->execute([$user['id'], $target]);
    setFlash('success', 'Crisis alert acknowledged.');
    header('Location: users.php'); exit;
}

// ── Add counselor ─────────────────────────────────────────────
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_counselor') {
    $fn   = clean($_POST['first_name']     ?? '');
    $ln   = clean($_POST['last_name']      ?? '');
    $em   = clean($_POST['email']          ?? '');
    $spec = clean($_POST['specialization'] ?? '');
    $pw   = $_POST['password'] ?? '';

    if (empty($fn))        $errors[] = 'First name required.';
    if (empty($ln))        $errors[] = 'Last name required.';
    if (empty($em))        $errors[] = 'Email required.';
    if (strlen($pw) < 8)  $errors[] = 'Password must be at least 8 characters.';

    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM wellness_admins WHERE email = ?");
        $check->execute([$em]);
        if ($check->fetch()) {
            $errors[] = 'Email already exists.';
        } else {
            $stmt = $db->prepare("INSERT INTO wellness_admins (first_name, last_name, email, password, specialization) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$fn, $ln, $em, hashPassword($pw), $spec ?: null]);
            setFlash('success', "Counselor account created for {$fn} {$ln}.");
            header('Location: users.php?filter=counselors'); exit;
        }
    }
}

// ── Add system admin ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'add_admin') {
    $fn = clean($_POST['admin_first'] ?? '');
    $ln = clean($_POST['admin_last']  ?? '');
    $em = clean($_POST['admin_email'] ?? '');
    $pw = $_POST['admin_password']    ?? '';

    if (empty($fn))        $errors[] = 'First name required.';
    if (empty($ln))        $errors[] = 'Last name required.';
    if (empty($em))        $errors[] = 'Email required.';
    if (strlen($pw) < 8)  $errors[] = 'Password must be at least 8 characters.';

    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM system_admins WHERE email = ?");
        $check->execute([$em]);
        if ($check->fetch()) {
            $errors[] = 'Admin email already exists.';
        } else {
            $stmt = $db->prepare("INSERT INTO system_admins (first_name, last_name, email, password) VALUES (?, ?, ?, ?)");
            $stmt->execute([$fn, $ln, $em, hashPassword($pw)]);
            setFlash('success', "Admin account created for {$fn} {$ln}.");
            header('Location: users.php?filter=admins'); exit;
        }
    }
}

// ── Data ──────────────────────────────────────────────────────
$filter = $_GET['filter'] ?? 'students';
$search = clean($_GET['q'] ?? '');

$sw = "WHERE 1=1"; $sp = [];
if ($filter === 'inactive_students') { $sw .= " AND is_active = 0"; }
elseif (!in_array($filter, ['counselors','admins','crisis'])) { $sw .= " AND is_active = 1"; }
if ($search && !in_array($filter, ['counselors','admins','crisis'])) {
    $sw .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)";
    $sp  = array_fill(0, 3, "%{$search}%");
}

$students = [];
if (!in_array($filter, ['counselors','admins','crisis'])) {
    $stmt = $db->prepare("SELECT id, student_id, first_name, last_name, email, is_active, created_at FROM users {$sw} ORDER BY created_at DESC LIMIT 50");
    $stmt->execute($sp);
    $students = $stmt->fetchAll();
}

$counselors = [];
if ($filter === 'counselors') {
    $cw = "WHERE 1=1"; $cp = [];
    if ($search) { $cw .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ?)"; $cp = array_fill(0,3,"%{$search}%"); }
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, specialization, is_active, created_at FROM wellness_admins {$cw} ORDER BY created_at DESC");
    $stmt->execute($cp);
    $counselors = $stmt->fetchAll();
}

$admins = [];
if ($filter === 'admins') {
    $stmt = $db->query("SELECT id, first_name, last_name, email, is_active, created_at FROM system_admins ORDER BY created_at DESC");
    $admins = $stmt->fetchAll();
}

$crisis_list = [];
if ($filter === 'crisis') {
    $stmt = $db->query("
        SELECT ca.*, u.first_name, u.last_name, u.student_id as sid,
               wa.first_name as adm_first, wa.last_name as adm_last
        FROM crisis_alerts ca
        JOIN users u ON ca.student_id = u.id
        LEFT JOIN wellness_admins wa ON ca.acknowledged_by = wa.id
        ORDER BY ca.created_at DESC LIMIT 50
    ");
    $crisis_list = $stmt->fetchAll();
}

$flash = getFlash();

$mc_title        = 'Manage Users — CTU MindCare';
$active_page     = 'users';
$mc_topbar_badge = 'System Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require_once '../../includes/assets.php'; ?>
</head>
<body>

<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <?php require_once '../../includes/sidebar.php'; ?>

  <!-- ── Main ── -->
  <main class="main">
  <div class="container">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>" data-auto-dismiss>
        <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle-2' : 'alert-triangle' ?>"></i>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="page-header mc-reveal">
      <div>
        <div class="greeting-label">User Management</div>
        <div class="greeting-title">Manage Users</div>
        <div class="greeting-sub">View, search, and manage all accounts across all roles</div>
      </div>
      <div class="header-date">
        <strong><?= date('l, F j') ?></strong>
        <?= date('Y') ?> · CTU MindCare
      </div>
    </div>

    <!-- ── Crisis view ── -->
    <?php if ($filter === 'crisis'): ?>

      <div class="card mc-reveal">
        <div class="card-header">
          <div class="card-title"><i data-lucide="siren"></i> All Crisis Alerts</div>
          <a class="back-link" href="users.php"><i data-lucide="arrow-left"></i> Back to Users</a>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Message</th>
                <th>Status</th>
                <th>Date</th>
                <th>Acknowledged By</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($crisis_list as $c): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-green"><?= strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1)) ?></div>
                      <div>
                        <div><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                        <div class="cell-sub"><?= clean($c['sid'] ?? '') ?></div>
                      </div>
                    </div>
                  </td>
                  <td style="max-width:220px">
                    <span class="cell-muted"><?= clean(substr($c['message'] ?? 'No message', 0, 65)) ?>…</span>
                  </td>
                  <td>
                    <span class="crisis-badge cb-<?= $c['status'] ?>"><?= ucfirst($c['status']) ?></span>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y · g:i A', strtotime($c['created_at'])) ?></td>
                  <td>
                    <?php if ($c['adm_first']): ?>
                      <span style="font-size:13px"><?= clean($c['adm_first'].' '.$c['adm_last']) ?></span>
                    <?php else: ?>
                      <span class="cell-muted">—</span>
                    <?php endif; ?>
                    <?php if ($c['status'] === 'open'): ?>
                      <div style="margin-top:5px">
                        <a class="action-link" href="users.php?action=resolve_crisis&id=<?= $c['id'] ?>"><i data-lucide="check-circle-2"></i> Acknowledge</a>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($crisis_list)): ?>
                <tr><td colspan="5">
                  <div class="empty-state">
                    <div class="empty-icon"><i data-lucide="shield-check"></i></div>
                    <div class="empty-text">No crisis alerts found.</div>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php else: ?>

    <!-- ── Users table ── -->
    <div class="card mc-reveal">

      <!-- Toolbar -->
      <div class="mc-toolbar">
        <div class="filter-pills">
          <a class="filter-pill <?= $filter==='students'          ? 'active' : '' ?>" href="users.php?filter=students"><i data-lucide="graduation-cap" style="width:13px;height:13px"></i> Students</a>
          <a class="filter-pill <?= $filter==='inactive_students' ? 'active' : '' ?>" href="users.php?filter=inactive_students"><i data-lucide="user-x" style="width:13px;height:13px"></i> Inactive</a>
          <a class="filter-pill <?= $filter==='counselors'        ? 'active' : '' ?>" href="users.php?filter=counselors"><i data-lucide="stethoscope" style="width:13px;height:13px"></i> Counselors</a>
          <a class="filter-pill <?= $filter==='admins'            ? 'active' : '' ?>" href="users.php?filter=admins"><i data-lucide="shield" style="width:13px;height:13px"></i> Admins</a>
          <a class="filter-pill pill-danger <?= $filter==='crisis'? 'active' : '' ?>" href="users.php?filter=crisis"><i data-lucide="siren" style="width:13px;height:13px"></i> Crisis</a>
        </div>
        <form class="search-form" method="GET">
          <input type="hidden" name="filter" value="<?= clean($filter) ?>">
          <input class="search-input" type="text" name="q" value="<?= clean($search) ?>" placeholder="Search…">
          <button type="submit" class="btn btn-primary"><i data-lucide="search" style="width:13px;height:13px"></i> Search</button>
        </form>
      </div>

      <div class="table-wrap">

        <!-- Students -->
        <?php if (in_array($filter, ['students', 'inactive_students'])): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Student ID</th>
                <th>Email</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($students as $u): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-green"><?= strtoupper(substr($u['first_name'],0,1).substr($u['last_name'],0,1)) ?></div>
                      <div><?= clean($u['first_name'].' '.$u['last_name']) ?></div>
                    </div>
                  </td>
                  <td class="cell-muted"><?= clean($u['student_id'] ?? '—') ?></td>
                  <td class="cell-muted"><?= clean($u['email']) ?></td>
                  <td>
                    <?php if ($u['is_active']): ?>
                      <span class="badge badge-active"><i data-lucide="circle-check" style="width:11px;height:11px"></i> Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive"><i data-lucide="circle-x" style="width:11px;height:11px"></i> Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
                  <td>
                    <?php if ($u['is_active']): ?>
                      <a class="action-link red" href="users.php?action=deactivate_student&id=<?= $u['id'] ?>"
                         onclick="return confirm('Deactivate <?= clean($u['first_name']) ?>?')"><i data-lucide="user-x"></i> Deactivate</a>
                    <?php else: ?>
                      <a class="action-link" href="users.php?action=activate_student&id=<?= $u['id'] ?>"><i data-lucide="user-check"></i> Activate</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($students)): ?>
                <tr><td colspan="6">
                  <div class="empty-state">
                    <div class="empty-icon"><i data-lucide="users"></i></div>
                    <div class="empty-text">No students found.</div>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>

        <!-- Counselors -->
        <?php elseif ($filter === 'counselors'): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Counselor</th>
                <th>Email</th>
                <th>Specialization</th>
                <th>Status</th>
                <th>Joined</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($counselors as $c): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-purple"><?= strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1)) ?></div>
                      <div><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                    </div>
                  </td>
                  <td class="cell-muted"><?= clean($c['email']) ?></td>
                  <td class="cell-muted"><?= clean($c['specialization'] ?? '—') ?></td>
                  <td>
                    <?php if ($c['is_active']): ?>
                      <span class="badge badge-active"><i data-lucide="circle-check" style="width:11px;height:11px"></i> Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive"><i data-lucide="circle-x" style="width:11px;height:11px"></i> Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
                  <td>
                    <?php if ($c['is_active']): ?>
                      <a class="action-link red" href="users.php?action=deactivate_counselor&id=<?= $c['id'] ?>"
                         onclick="return confirm('Deactivate <?= clean($c['first_name']) ?>?')"><i data-lucide="user-x"></i> Deactivate</a>
                    <?php else: ?>
                      <a class="action-link" href="users.php?action=activate_counselor&id=<?= $c['id'] ?>"><i data-lucide="user-check"></i> Activate</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if (empty($counselors)): ?>
                <tr><td colspan="6">
                  <div class="empty-state">
                    <div class="empty-icon"><i data-lucide="stethoscope"></i></div>
                    <div class="empty-text">No counselors found.</div>
                  </div>
                </td></tr>
              <?php endif; ?>
            </tbody>
          </table>

        <!-- Admins -->
        <?php elseif ($filter === 'admins'): ?>
          <table class="data-table">
            <thead>
              <tr>
                <th>Administrator</th>
                <th>Email</th>
                <th>Status</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($admins as $a): ?>
                <tr>
                  <td>
                    <div class="user-cell">
                      <div class="avatar-sm av-navy"><?= strtoupper(substr($a['first_name'],0,1).substr($a['last_name'],0,1)) ?></div>
                      <div>
                        <?= clean($a['first_name'].' '.$a['last_name']) ?>
                        <?php if ($a['id'] === $user['id']): ?>
                          &nbsp;<span class="badge badge-you">You</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </td>
                  <td class="cell-muted"><?= clean($a['email']) ?></td>
                  <td>
                    <?php if ($a['is_active']): ?>
                      <span class="badge badge-active"><i data-lucide="circle-check" style="width:11px;height:11px"></i> Active</span>
                    <?php else: ?>
                      <span class="badge badge-inactive"><i data-lucide="circle-x" style="width:11px;height:11px"></i> Inactive</span>
                    <?php endif; ?>
                  </td>
                  <td class="cell-muted"><?= date('M j, Y', strtotime($a['created_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div><!-- /table-wrap -->
    </div><!-- /card -->

    <!-- ── Add Counselor form ── -->
    <?php if ($filter === 'counselors'): ?>
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header">
          <div class="card-title"><i data-lucide="user-plus"></i> Add New Counselor</div>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $e): ?><i data-lucide="alert-triangle" style="width:13px;height:13px;vertical-align:-2px"></i> <?= clean($e) ?><br><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="form_action" value="add_counselor">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">First name</label>
                <input class="form-input" type="text" name="first_name" placeholder="Maria" required>
              </div>
              <div class="form-group">
                <label class="form-label">Last name</label>
                <input class="form-input" type="text" name="last_name" placeholder="Santos" required>
              </div>
              <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-input" type="email" name="email" placeholder="counselor@ctu.edu.ph" required>
              </div>
              <div class="form-group">
                <label class="form-label">Specialization <span class="form-label-opt">(optional)</span></label>
                <input class="form-input" type="text" name="specialization" placeholder="e.g. Anxiety, Stress Management">
              </div>
              <div class="form-group">
                <label class="form-label">Temporary password</label>
                <input class="form-input" type="password" name="password" placeholder="Min. 8 characters" required>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-primary"><i data-lucide="user-plus" style="width:14px;height:14px"></i> Add Counselor</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <!-- ── Add Admin form ── -->
    <?php if ($filter === 'admins'): ?>
      <div class="card mc-reveal" data-mc-delay="1">
        <div class="card-header">
          <div class="card-title"><i data-lucide="shield-plus"></i> Add New System Admin</div>
        </div>
        <div class="card-body">
          <?php if (!empty($errors)): ?>
            <div class="error-box">
              <?php foreach ($errors as $e): ?><i data-lucide="alert-triangle" style="width:13px;height:13px;vertical-align:-2px"></i> <?= clean($e) ?><br><?php endforeach; ?>
            </div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="form_action" value="add_admin">
            <div class="form-grid">
              <div class="form-group">
                <label class="form-label">First name</label>
                <input class="form-input" type="text" name="admin_first" placeholder="Juan" required>
              </div>
              <div class="form-group">
                <label class="form-label">Last name</label>
                <input class="form-input" type="text" name="admin_last" placeholder="Dela Cruz" required>
              </div>
              <div class="form-group">
                <label class="form-label">Email</label>
                <input class="form-input" type="email" name="admin_email" placeholder="admin@ctu.edu.ph" required>
              </div>
              <div class="form-group">
                <label class="form-label">Password</label>
                <input class="form-input" type="password" name="admin_password" placeholder="Min. 8 characters" required>
              </div>
            </div>
            <div class="form-actions">
              <button type="submit" class="btn btn-dark"><i data-lucide="shield" style="width:14px;height:14px"></i> Add System Admin</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php endif; // end non-crisis view ?>

  </div>
  </main>
</div>

</body>
</html>
