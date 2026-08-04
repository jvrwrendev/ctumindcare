<?php
// ============================================================
//  CTU MindCare — AI Assistant (full page)
//  File: chatbot/index.php
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$user = currentUser();
$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['student', 'wellness_admin'], true)) {
    header('Location: ' . SITE_URL . '/auth/login.php');
    exit;
}

$back = $role === 'wellness_admin'
    ? SITE_URL . '/wellness/pages/dashboard.php'
    : SITE_URL . '/student/pages/dashboard.php';

$counselors_online = false;
if ($role === 'student') {
    $db = getDB();
    $counselors_online = (int)$db->query("SELECT COUNT(*) FROM wellness_admins WHERE is_active = 1")->fetchColumn() > 0;
}

$chatbot_full = true;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>AI Assistant — CTU MindCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= SITE_URL ?>/chatbot/chat.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:'DM Sans',sans-serif;background:var(--bg);}
:root{
  --bg:#F4F7FB;--surface:#FFFFFF;--surface-2:#F8FAFC;--surface-3:#F1F5F9;
  --primary:#2563EB;--primary-hover:#1D4ED8;--primary-strong:#1E40AF;--primary-soft:#EFF6FF;--primary-mid:#BFDBFE;
  --teal:#0D9488;--teal-hover:#0F766E;--teal-strong:#115E59;--teal-soft:#F0FDFA;--teal-mid:#99F6E4;
  --green:#10B981;--green-dark:#047857;--amber:#F59E0B;
  --red:#EF4444;--red-mid:#FECACA;
  --text:#0F172A;--text-2:#475569;--text-3:#94A3B8;
  --border:#E2E8F0;--border-2:#CBD5E1;
  --font:'DM Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  --font-serif:'DM Serif Display',Georgia,serif;
  color-scheme:light;
}
@media (prefers-color-scheme: dark) {
  :root{
    --bg:#0B1220;--surface:#111A2E;--surface-2:#16213A;--surface-3:#1B2944;
    --primary:#60A5FA;--primary-hover:#93C5FD;--primary-strong:#BFDBFE;--primary-soft:#16233C;--primary-mid:#1E3A5F;
    --teal:#2DD4BF;--teal-hover:#5EEAD4;--teal-strong:#99F6E4;--teal-soft:#0E2A29;--teal-mid:#134E4A;
    --green:#34D399;--green-dark:#6EE7B7;--amber:#FBBF24;
    --red:#F87171;--red-mid:#7F1D1D;
    --text:#E2E8F0;--text-2:#CBD5E1;--text-3:#64748B;
    --border:#1E293B;--border-2:#334155;
    color-scheme:dark;
  }
}
.cb-page-brand{display:flex;align-items:center;gap:11px;text-decoration:none;}
.cb-page-brand-mark{
  width:40px;height:40px;border-radius:11px;
  background:linear-gradient(135deg,var(--primary),var(--teal));
  display:flex;align-items:center;justify-content:center;
  font-family:var(--font-serif);font-size:19px;color:#fff;
  border:1px solid rgba(255,255,255,.3);
  box-shadow:0 4px 12px rgba(37,99,235,.25);
}
.cb-page-brand-name{font-family:var(--font-serif);font-size:17px;color:var(--text);letter-spacing:.2px;}
.cb-page-brand-sub{font-size:10.5px;color:var(--text-3);margin-top:1px;}
.cb-full-note{
  width:100%;max-width:780px;margin-bottom:14px;font-size:12.5px;line-height:1.6;color:var(--text-2);
  background:var(--teal-soft);border:1px solid var(--teal-mid);border-radius:12px;padding:12px 16px;
}
.cb-full-note strong{color:var(--text);}
.cb-status-pill{
  display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:600;
  padding:8px 14px;border-radius:100px;background:var(--surface);border:1.5px solid var(--border-2);
  color:var(--text-2);box-shadow:0 2px 6px rgba(15,23,42,.05);
}
.cb-status-pill .dot{width:8px;height:8px;border-radius:50%;}
.cb-status-pill.online .dot{background:var(--green);box-shadow:0 0 0 3px rgba(16,185,129,.22);}
.cb-status-pill.ai .dot{background:var(--amber);box-shadow:0 0 0 3px rgba(245,158,11,.20);}
.cb-status-pill.office .dot{background:var(--primary);box-shadow:0 0 0 3px rgba(37,99,235,.20);}
.cb-user-chip{display:inline-flex;align-items:center;gap:9px;background:var(--surface);border:1.5px solid var(--border-2);border-radius:100px;padding:6px 16px 6px 7px;box-shadow:0 2px 6px rgba(15,23,42,.05);}
.cb-user-av{width:28px;height:28px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--teal));color:#fff;font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center;}
.cb-user-name{font-size:12.5px;font-weight:600;color:var(--text);}
.cb-user-role{font-size:10px;color:var(--text-3);margin-top:1px;}
.cb-full-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.cb-full-footer{
  width:100%;max-width:780px;margin-top:16px;text-align:center;
  font-size:11.5px;color:var(--text-3);line-height:1.7;
}
.cb-full-footer strong{color:var(--text-2);font-weight:600;}
@media(max-width:640px){
  .cb-full-header{flex-direction:column;align-items:stretch;gap:12px;}
  .cb-full-actions{justify-content:flex-start;flex-wrap:wrap;gap:8px;}
  .cb-page-brand-name{font-size:15px;}
  .cb-page-brand-sub{font-size:10px;}
  .cb-back{padding:8px 12px;}
}
</style>
</head>
<body>

<div class="cb-full-page">

  <div class="cb-full-header">
    <a class="cb-page-brand" href="<?= $back ?>">
      <div class="cb-page-brand-mark">M</div>
      <div>
        <div class="cb-page-brand-name">CTU MindCare</div>
        <div class="cb-page-brand-sub">AI Mental Health Assistant</div>
      </div>
    </a>
    <div class="cb-full-actions">
      <?php if ($role === 'student'): ?>
        <span class="cb-status-pill <?= $counselors_online ? 'online' : 'ai' ?>">
          <span class="dot"></span>
          <?= $counselors_online ? 'Counselor online — human help available' : 'AI 24/7 mode — no counselor online right now' ?>
        </span>
      <?php else: ?>
        <span class="cb-status-pill office"><span class="dot"></span> Wellness Office console</span>
      <?php endif; ?>
      <span class="cb-user-chip">
        <span class="cb-user-av"><?= strtoupper(substr($user['first_name'] ?? 'U',0,1).substr($user['last_name'] ?? 'S',0,1)) ?></span>
        <span>
          <span class="cb-user-name"><?= clean(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')) ?></span>
          <div class="cb-user-role"><?= $role === 'student' ? 'Student' : 'Counselor' ?></div>
        </span>
      </span>
      <a class="cb-back" href="<?= $back ?>">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5"/><path d="M12 19l-7-7 7-7"/></svg>
        Dashboard
      </a>
    </div>
  </div>

  <div class="cb-full-note">
    🤖 <strong>I'm your 24/7 first-line support.</strong> I can listen, answer wellness questions, assess urgency,
    and help you book a session with a human counselor. I'm an AI — <strong>not a replacement for licensed counselors.</strong>
    In a crisis, call <strong>Hopeline 02-804-4673</strong> or text <strong>HELLO</strong> to 4673.
  </div>

  <?php require_once __DIR__ . '/../includes/chatbot_widget.php'; ?>

  <div class="cb-full-footer">
    <strong>In an emergency, call 911 or go to the nearest hospital.</strong><br>
    Hopeline: 02-804-4673 · Text HELLO to 4673 · NCMH 24/7: 0917-899-8727
  </div>

</div>

</body>
</html>
