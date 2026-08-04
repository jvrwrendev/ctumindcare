<?php
// ============================================================
//  CTU MindCare — AI Mental Health Assistant floating widget
//  Include before </body> on student / wellness pages:
//    require_once '../../includes/chatbot_widget.php';
//  Optional: set $chatbot_full = true for full-page mode.
// ============================================================
$cb_role = $_SESSION['user_role'] ?? '';
if (!in_array($cb_role, ['student', 'wellness_admin'], true)) {
    return;
}
$cb_full  = !empty($chatbot_full);
$cb_name  = clean($_SESSION['user_name'] ?? '');
$cb_online = false;

// ── Students: notify when a counselor becomes available ───────
if ($cb_role === 'student') {
    try {
        $db = getDB();
        $cb_online = (int)$db->query("SELECT COUNT(*) FROM wellness_admins WHERE is_active = 1")->fetchColumn() > 0;
        if ($cb_online) {
            $stmt = $db->prepare("SELECT id FROM chatbot_booking_requests WHERE student_id = ? AND status = 'waiting' LIMIT 1");
            $stmt->execute([(int)$_SESSION['user_id']]);
            if ($waiting = $stmt->fetch()) {
                $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'general', ?)")
                   ->execute([(int)$_SESSION['user_id'], "A wellness counselor is now available. You can book a session through the AI Assistant or the Appointments page. We're here for you!"]);
                $db->prepare("UPDATE chatbot_booking_requests SET status = 'notified', notified_at = NOW() WHERE id = ?")->execute([(int)$waiting['id']]);
            }
        }
    } catch (Throwable $e) {
        // Never break the page over a background notification check
    }
}
?>
<link rel="stylesheet" href="<?= SITE_URL ?>/chatbot/chat.css">
<div class="cb-root<?= $cb_full ? ' cb-full' : '' ?>" data-api-url="<?= SITE_URL ?>/chatbot/api.php" data-user-name="<?= $cb_name ?>">
  <button class="cb-fab" id="cbFab" aria-label="Open MindCare Assistant">
    <svg class="cb-fab-open" viewBox="0 0 24 24" aria-hidden="true">
      <path d="M21 12a8 8 0 0 1-8 8H4l1.7-1.9A8 8 0 1 1 21 12z"/>
      <path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/>
    </svg>
    <svg class="cb-fab-close" viewBox="0 0 24 24" aria-hidden="true">
      <path d="M6 6l12 12M18 6L6 18"/>
    </svg>
  </button>
  <div class="cb-panel" id="cbPanel">
    <div class="cb-header">
      <div class="cb-avatar">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 8v4l3 3"/>
          <circle cx="12" cy="12" r="9"/>
        </svg>
      </div>
      <div>
        <div class="cb-header-title">MindCare Assistant</div>
        <div class="cb-header-sub">
          <span class="cb-status-dot <?= $cb_role === 'student' ? ($cb_online ? 'is-online' : 'is-ai') : 'is-online' ?>"></span>
          <?= $cb_role === 'wellness_admin'
              ? 'Wellness Office console'
              : ($cb_online ? 'Counselor online — human support available' : 'AI-first mode · 24/7 availability') ?>
        </div>
      </div>
    </div>
    <div class="cb-messages" id="cbMessages"></div>
    <form class="cb-input-form" id="cbForm" autocomplete="off">
      <input class="cb-input" id="cbInput" type="text" maxlength="1000" placeholder="Ask me anything…">
      <button class="cb-send" id="cbSend" type="submit" aria-label="Send">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M22 2L11 13"/>
          <path d="M22 2L15 22l-4-9-9-4z"/>
        </svg>
      </button>
    </form>
    <div class="cb-disclaimer">
      AI assistant — <strong>not a replacement</strong> for licensed counselors. For urgent help call Hopeline 02-804-4673.
    </div>
    <?php if (!$cb_full): ?>
    <button class="cb-close" id="cbClose" type="button" aria-label="Close assistant">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
    </button>
    <?php endif; ?>
  </div>
</div>
<script src="<?= SITE_URL ?>/chatbot/chat.js" defer></script>
