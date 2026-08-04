<?php
// ============================================================
//  CTU MindCare — Wellness Counselor Dashboard
//  File: wellness/pages/dashboard.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('wellness_admin');

$user = currentUser();
if (!$user) {
    $user = [
        'id'         => $_SESSION['user_id'],
        'first_name' => $_SESSION['user_name'] ?? 'Counselor',
        'last_name'  => '',
        'email'      => '',
        'role'       => 'wellness_admin',
    ];
}

$db = getDB();

// ── Active page (sidebar navigation) ─────────────────────────
$page = $_GET['p'] ?? 'dashboard';

// ── Handle POST actions ───────────────────────────────────────

// Send announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'send_announcement') {
    $msg    = trim($_POST['message'] ?? '');
    $target = $_POST['target'] ?? 'my_students';

    if (!empty($msg) && strlen($msg) <= 500) {
        if ($target === 'my_students') {
            $stmt = $db->prepare("
                SELECT u.id FROM users u
                JOIN appointments a ON a.student_id = u.id
                WHERE a.counselor_id = ? AND u.is_active = 1
                GROUP BY u.id
            ");
            $stmt->execute([$user['id']]);
        } elseif ($target === 'all_students') {
            $stmt = $db->query("SELECT id FROM users WHERE is_active = 1");
        } else {
            $stmt = null;
        }

        if ($stmt) {
            $recipients = $stmt->fetchAll();
            $ins = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'announcement', ?)");
            foreach ($recipients as $r) {
                $ins->execute([$r['id'], $msg]);
            }
        }
        setFlash('success', 'Announcement sent successfully.');
    } else {
        setFlash('error', 'Message is required and must be under 500 characters.');
    }
    header('Location: dashboard.php?p=announcements');
    exit;
}

// Book appointment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'book_appointment') {
    $student_id = (int)($_POST['student_id'] ?? 0);
    $appt_date  = trim($_POST['appointment_date'] ?? '');
    $appt_time  = trim($_POST['appointment_time'] ?? '');
    $purpose    = trim($_POST['purpose'] ?? '');

    if ($student_id && $appt_date && $appt_time) {
        // Check slot not taken
        $stmt = $db->prepare("
            SELECT id FROM appointments
            WHERE counselor_id = ? AND appointment_date = ? AND appointment_time = ?
              AND status NOT IN ('cancelled')
        ");
        $stmt->execute([$user['id'], $appt_date, $appt_time]);
        if ($stmt->fetch()) {
            setFlash('error', 'That time slot is already booked.');
        } else {
            $stmt = $db->prepare("
                INSERT INTO appointments (student_id, counselor_id, appointment_date, appointment_time, purpose, status)
                VALUES (?, ?, ?, ?, ?, 'confirmed')
            ");
            $stmt->execute([$student_id, $user['id'], $appt_date, $appt_time, $purpose ?: null]);

            // Notify student
            $notif = $db->prepare("
                INSERT INTO notifications (user_id, type, message)
                VALUES (?, 'appointment', ?)
            ");
            $notif->execute([$student_id,
                'Your appointment on ' . date('M j, Y', strtotime($appt_date))
                . ' at ' . date('g:i A', strtotime($appt_time)) . ' has been confirmed.'
            ]);
            setFlash('success', 'Appointment booked successfully.');
        }
    } else {
        setFlash('error', 'Please fill in all required fields.');
    }
    header('Location: dashboard.php?p=appointments');
    exit;
}

// Cancel appointment
if (($_GET['action'] ?? '') === 'cancel_appt') {
    $appt_id = (int)($_GET['id'] ?? 0);
    if ($appt_id) {
        $db->prepare("
            UPDATE appointments SET status = 'cancelled'
            WHERE id = ? AND counselor_id = ?
        ")->execute([$appt_id, $user['id']]);
        setFlash('success', 'Appointment cancelled.');
    }
    header('Location: dashboard.php?p=appointments');
    exit;
}

// Confirm appointment
if (($_GET['action'] ?? '') === 'confirm_appt') {
    $appt_id = (int)($_GET['id'] ?? 0);
    if ($appt_id) {
        $db->prepare("
            UPDATE appointments SET status = 'confirmed'
            WHERE id = ? AND counselor_id = ?
        ")->execute([$appt_id, $user['id']]);
        setFlash('success', 'Appointment confirmed.');
    }
    header('Location: dashboard.php?p=appointments');
    exit;
}

// Save session note
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'save_note') {
    $student_id   = (int)($_POST['student_id'] ?? 0);
    $session_type = trim($_POST['session_type'] ?? '');
    $notes_body   = trim($_POST['notes_body'] ?? '');
    $mood_obs     = (int)($_POST['mood_observed'] ?? 0);
    $followup     = trim($_POST['followup_plan'] ?? '');

    if ($student_id && $notes_body) {
        $db->prepare("
            INSERT INTO session_notes (counselor_id, student_id, session_type, notes, mood_observed, followup_plan)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$user['id'], $student_id, $session_type, $notes_body, $mood_obs ?: null, $followup ?: null]);
        setFlash('success', 'Session note saved.');
    } else {
        setFlash('error', 'Student and notes are required.');
    }
    header('Location: dashboard.php?p=notes');
    exit;
}

// Acknowledge crisis alert 
if (($_GET['action'] ?? '') === 'ack_alert') {
    $alert_id = (int)($_GET['id'] ?? 0);
    if ($alert_id) {
        $db->prepare("
            UPDATE crisis_alerts SET status = 'acknowledged', acknowledged_by = ?
            WHERE id = ?
        ")->execute([$user['id'], $alert_id]);
        setFlash('success', 'Alert acknowledged.');
    }
    header('Location: dashboard.php?p=alerts');
    exit;
}

// Resolve crisis alert
if (($_GET['action'] ?? '') === 'resolve_alert') {
    $alert_id = (int)($_GET['id'] ?? 0);
    if ($alert_id) {
        $db->prepare("
            UPDATE crisis_alerts SET status = 'resolved'
            WHERE id = ?
        ")->execute([$alert_id]);
        setFlash('success', 'Alert resolved.');
    }
    header('Location: dashboard.php?p=alerts');
    exit;
}

// Remove forum post
if (($_GET['action'] ?? '') === 'remove_post') {
    $post_id = (int)($_GET['id'] ?? 0);
    if ($post_id) {
        $db->prepare("UPDATE forum_posts SET is_removed = 1 WHERE id = ?")->execute([$post_id]);
        setFlash('success', 'Post removed.');
    }
    header('Location: dashboard.php?p=forum');
    exit;
}

// Log crisis incident
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'log_crisis') {
    $student_id  = (int)($_POST['student_id'] ?? 0);
    $severity    = trim($_POST['severity'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $action_taken = trim($_POST['action_taken'] ?? '');

    if ($student_id && $description) {
        $db->prepare("
            INSERT INTO crisis_alerts (student_id, severity, description, action_taken, status)
            VALUES (?, ?, ?, ?, 'open')
        ")->execute([$student_id, $severity, $description, $action_taken]);
        setFlash('success', 'Crisis incident logged.');
    } else {
        setFlash('error', 'Student and description are required.');
    }
    header('Location: dashboard.php?p=crisis');
    exit;
}

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message_text'])) {
    $msg        = trim($_POST['message_text'] ?? '');
    $student_id = (int)($_POST['student_id'] ?? 0);

    if ($msg !== '' && $student_id) {
        $db->prepare("
            INSERT INTO counselor_messages (student_id, counselor_id, sender_id, message)
            VALUES (?, ?, ?, ?)
        ")->execute([$student_id, $user['id'], $user['id'], $msg]);

        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'ok']);
            exit;
        }
    }
    header('Location: dashboard.php?p=messages&student=' . $student_id);
    exit;
}

// Poll messages (AJAX)
if (isset($_GET['poll_messages'])) {
    $student_id = (int)($_GET['student'] ?? 0);
    $since_id   = (int)($_GET['since'] ?? 0);
    header('Content-Type: application/json');
    if (!$student_id) { echo json_encode(['messages' => []]); exit; }

    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.sent_at,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name, u.last_name) AS last_name,
               CASE WHEN cm.sender_id = cm.counselor_id THEN 'wellness_admin' ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_id = wa.id AND cm.sender_id = cm.counselor_id
        LEFT JOIN users u ON cm.sender_id = u.id AND cm.sender_id = cm.student_id
        WHERE cm.student_id = ? AND cm.counselor_id = ? AND cm.id > ?
        ORDER BY cm.sent_at ASC
    ");
    $stmt->execute([$student_id, $user['id'], $since_id]);
    $msgs = $stmt->fetchAll();

    // Mark read
    $db->prepare("
        UPDATE counselor_messages SET is_read = 1
        WHERE student_id = ? AND counselor_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$student_id, $user['id'], $user['id']]);

    echo json_encode(['messages' => $msgs]);
    exit;
}

// ── Load data for current page ────────────────────────────────

// My assigned students (all students who have/had appointments with me)
$stmt = $db->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name, u.email,
        (SELECT me.mood_level FROM mood_entries me
         WHERE me.user_id = u.id ORDER BY me.recorded_at DESC LIMIT 1) as latest_mood,
        (SELECT me.recorded_at FROM mood_entries me
         WHERE me.user_id = u.id ORDER BY me.recorded_at DESC LIMIT 1) as latest_mood_at,
        (SELECT ar.severity FROM assessment_results ar
         WHERE ar.user_id = u.id ORDER BY ar.taken_at DESC LIMIT 1) as latest_severity,
        (SELECT ar.total_score FROM assessment_results ar
         WHERE ar.user_id = u.id ORDER BY ar.taken_at DESC LIMIT 1) as latest_score,
        (SELECT at.code FROM assessment_results ar
         JOIN assessment_templates at ON ar.template_id = at.id
         WHERE ar.user_id = u.id ORDER BY ar.taken_at DESC LIMIT 1) as assess_code,
        (SELECT COUNT(DISTINCT DATE(me2.recorded_at)) FROM mood_entries me2
         WHERE me2.user_id = u.id
           AND me2.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
           AND me2.mood_level <= 2) as low_mood_days,
        (SELECT COUNT(*) FROM crisis_alerts ca
         WHERE ca.student_id = u.id AND ca.status = 'open') as open_alerts
    FROM users u
    JOIN appointments a ON a.student_id = u.id
    WHERE a.counselor_id = ? AND u.is_active = 1
    GROUP BY u.id
    ORDER BY open_alerts DESC, low_mood_days DESC, u.first_name ASC
");
$stmt->execute([$user['id']]);
$my_students = $stmt->fetchAll();

// Stats
$total_students   = count($my_students);

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM appointments
    WHERE counselor_id = ?
      AND appointment_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
      AND status != 'cancelled'
");
$stmt->execute([$user['id']]);
$week_appts = (int)$stmt->fetch()['cnt'];

// Get open alerts count for this counselor's students
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT ca.id) as cnt 
    FROM crisis_alerts ca
    JOIN appointments a ON a.student_id = ca.student_id
    WHERE a.counselor_id = ? AND ca.status = 'open'
");
$stmt->execute([$user['id']]);
$open_alerts_count = (int)$stmt->fetch()['cnt'];

$stmt = $db->prepare("
    SELECT COUNT(*) as cnt FROM counselor_messages
    WHERE counselor_id = ? AND sender_id != ? AND is_read = 0
");
$stmt->execute([$user['id'], $user['id']]);
$unread_msgs = (int)$stmt->fetch()['cnt'];

// Open AI risk flags (for sidebar badge)
$open_ai_flags = (int)$db->query("SELECT COUNT(*) FROM chatbot_risk_flags WHERE status = 'open'")->fetchColumn();

// Cohort mood trend — last 7 days
$stmt = $db->prepare("
    SELECT DATE(me.recorded_at) as day, ROUND(AVG(me.mood_level), 2) as avg_mood
    FROM mood_entries me
    JOIN appointments a ON a.student_id = me.user_id
    WHERE a.counselor_id = ? AND me.recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(me.recorded_at)
    ORDER BY day ASC
");
$stmt->execute([$user['id']]);
$mood_rows = $stmt->fetchAll();
$mood_map  = [];
foreach ($mood_rows as $r) $mood_map[$r['day']] = (float)$r['avg_mood'];

$chart_labels = [];
$chart_data   = [];
for ($i = 6; $i >= 0; $i--) {
    $date           = date('Y-m-d', strtotime("-{$i} days"));
    $chart_labels[] = date('D', strtotime($date));
    $chart_data[]   = $mood_map[$date] ?? null;
}

// Appointments (today + upcoming)
$stmt = $db->prepare("
    SELECT a.id, a.appointment_date, a.appointment_time, a.status, a.purpose,
           u.id as student_id, u.first_name, u.last_name
    FROM appointments a
    JOIN users u ON a.student_id = u.id
    WHERE a.counselor_id = ? AND a.appointment_date >= CURDATE() AND a.status != 'cancelled'
    ORDER BY a.appointment_date ASC, a.appointment_time ASC
    LIMIT 20
");
$stmt->execute([$user['id']]);
$upcoming_appts = $stmt->fetchAll();
$today_appts    = array_filter($upcoming_appts, fn($a) => $a['appointment_date'] === date('Y-m-d'));
$pending_appts  = array_filter($upcoming_appts, fn($a) => $a['status'] === 'pending');

// Completed appointments
$stmt = $db->prepare("
    SELECT a.id, a.appointment_date, a.appointment_time, a.status,
           u.first_name, u.last_name
    FROM appointments a
    JOIN users u ON a.student_id = u.id
    WHERE a.counselor_id = ? AND a.appointment_date < CURDATE()
      AND a.status IN ('confirmed','completed')
    ORDER BY a.appointment_date DESC LIMIT 10
");
$stmt->execute([$user['id']]);
$past_appts = $stmt->fetchAll();

// Crisis alerts - get alerts for this counselor's students
$stmt = $db->prepare("
    SELECT ca.*, u.first_name, u.last_name
    FROM crisis_alerts ca
    JOIN users u ON ca.student_id = u.id
    JOIN appointments a ON a.student_id = u.id
    WHERE a.counselor_id = ?
    ORDER BY ca.created_at DESC LIMIT 30
");
$stmt->execute([$user['id']]);
$all_alerts  = $stmt->fetchAll();
$open_cals   = array_filter($all_alerts, fn($a) => $a['status'] === 'open');
$ack_cals    = array_filter($all_alerts, fn($a) => $a['status'] === 'acknowledged');
$res_cals    = array_filter($all_alerts, fn($a) => $a['status'] === 'resolved');

// Session notes
$stmt = $db->prepare("
    SELECT sn.*, u.first_name, u.last_name
    FROM session_notes sn
    JOIN users u ON sn.student_id = u.id
    WHERE sn.counselor_id = ?
    ORDER BY sn.created_at DESC LIMIT 10
");
$stmt->execute([$user['id']]);
$session_notes = $stmt->fetchAll();

// Assessment results for my students
$my_student_ids = array_column($my_students, 'id');
$assess_results = [];
if ($my_student_ids) {
    $in = implode(',', array_fill(0, count($my_student_ids), '?'));
    $stmt = $db->prepare("
        SELECT ar.total_score, ar.severity, ar.taken_at,
               at.code, at.title,
               u.first_name, u.last_name, u.id as student_id
        FROM assessment_results ar
        JOIN assessment_templates at ON ar.template_id = at.id
        JOIN users u ON ar.user_id = u.id
        WHERE ar.user_id IN ($in)
        ORDER BY ar.taken_at DESC
        LIMIT 30
    ");
    $stmt->execute($my_student_ids);
    $assess_results = $stmt->fetchAll();
}

// Forum posts — flagged and recent
$stmt = $db->prepare("
    SELECT fp.id, fp.title, fp.created_at, fp.is_flagged,
           (SELECT COUNT(*) FROM forum_replies WHERE post_id = fp.id) as reply_count,
           (SELECT COUNT(*) FROM forum_reactions WHERE post_id = fp.id) as react_count
    FROM forum_posts fp
    WHERE fp.is_removed = 0
    ORDER BY fp.is_flagged DESC, fp.created_at DESC
    LIMIT 20
");
$stmt->execute();
$forum_posts   = $stmt->fetchAll();
$flagged_posts = array_filter($forum_posts, fn($p) => $p['is_flagged']);

// Resources
$stmt = $db->query("SELECT * FROM resources WHERE is_published = 1 ORDER BY created_at DESC LIMIT 20");
$resources = $stmt->fetchAll();

// Announcements sent by this counselor
$stmt = $db->prepare("
    SELECT n.message, n.created_at, COUNT(*) as sent_to
    FROM notifications n
    WHERE n.type = 'announcement'
      AND n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY n.message, n.created_at
    ORDER BY n.created_at DESC LIMIT 10
");
$stmt->execute();
$announcements = $stmt->fetchAll();

// Messages — students I've conversed with
$stmt = $db->prepare("
    SELECT DISTINCT u.id, u.first_name, u.last_name,
        (SELECT cm2.message FROM counselor_messages cm2
         WHERE cm2.student_id = u.id AND cm2.counselor_id = ?
         ORDER BY cm2.sent_at DESC LIMIT 1) as last_message,
        (SELECT cm3.sent_at FROM counselor_messages cm3
         WHERE cm3.student_id = u.id AND cm3.counselor_id = ?
         ORDER BY cm3.sent_at DESC LIMIT 1) as last_time,
        (SELECT COUNT(*) FROM counselor_messages cm4
         WHERE cm4.student_id = u.id AND cm4.counselor_id = ?
           AND cm4.sender_id != ? AND cm4.is_read = 0) as unread
    FROM users u
    JOIN counselor_messages cm ON cm.student_id = u.id AND cm.counselor_id = ?
    GROUP BY u.id
    ORDER BY last_time DESC
");
$stmt->execute([$user['id'], $user['id'], $user['id'], $user['id'], $user['id']]);
$conversations = $stmt->fetchAll();

// Active chat student
$chat_student_id = (int)($_GET['student'] ?? 0);
$chat_student    = null;
$chat_messages   = [];
if ($chat_student_id) {
    foreach ($my_students as $s) {
        if ((int)$s['id'] === $chat_student_id) { $chat_student = $s; break; }
    }
    $stmt = $db->prepare("
        SELECT cm.id, cm.sender_id, cm.message, cm.sent_at,
               COALESCE(wa.first_name, u.first_name) AS first_name,
               COALESCE(wa.last_name, u.last_name) AS last_name,
               CASE WHEN cm.sender_id = cm.counselor_id THEN 'wellness_admin' ELSE 'student' END AS sender_role
        FROM counselor_messages cm
        LEFT JOIN wellness_admins wa ON cm.sender_id = wa.id AND cm.sender_id = cm.counselor_id
        LEFT JOIN users u ON cm.sender_id = u.id AND cm.sender_id = cm.student_id
        WHERE cm.student_id = ? AND cm.counselor_id = ?
        ORDER BY cm.sent_at ASC LIMIT 100
    ");
    $stmt->execute([$chat_student_id, $user['id']]);
    $chat_messages = $stmt->fetchAll();

    // Mark unread as read
    $db->prepare("
        UPDATE counselor_messages SET is_read = 1
        WHERE student_id = ? AND counselor_id = ? AND sender_id != ? AND is_read = 0
    ")->execute([$chat_student_id, $user['id'], $user['id']]);
}

// Progress: selected student
$prog_student_id = (int)($_GET['prog_student'] ?? 0);
$prog_student    = null;
$prog_mood_data  = [];
$prog_assess     = [];
if ($prog_student_id) {
    foreach ($my_students as $s) {
        if ((int)$s['id'] === $prog_student_id) { $prog_student = $s; break; }
    }
    if ($prog_student) {
        $stmt = $db->prepare("
            SELECT DATE(recorded_at) as day, mood_level
            FROM mood_entries WHERE user_id = ?
              AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
            ORDER BY recorded_at ASC
        ");
        $stmt->execute([$prog_student_id]);
        $pmrows = $stmt->fetchAll();
        $pmap   = [];
        foreach ($pmrows as $r) $pmap[$r['day']] = (int)$r['mood_level'];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-{$i} days"));
            $prog_mood_data[] = ['label' => date('M j', strtotime($d)), 'val' => $pmap[$d] ?? null];
        }

        $stmt = $db->prepare("
            SELECT ar.total_score, ar.severity, ar.taken_at, at.code, at.title
            FROM assessment_results ar
            JOIN assessment_templates at ON ar.template_id = at.id
            WHERE ar.user_id = ? ORDER BY ar.taken_at DESC LIMIT 5
        ");
        $stmt->execute([$prog_student_id]);
        $prog_assess = $stmt->fetchAll();
    }
}

// ── Helpers ───────────────────────────────────────────────────
$hour     = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

$mood_emoji  = [1 => '😔', 2 => '😟', 3 => '😐', 4 => '🙂', 5 => '😊'];
$mood_labels = [1 => 'Very Low', 2 => 'Low', 3 => 'Okay', 4 => 'Good', 5 => 'Great'];

function sevBadge(string $sev): string {
    $map = [
        'minimal'           => 'badge-green',
        'mild'              => 'badge-amber',
        'moderate'          => 'badge-amber',
        'moderately_severe' => 'badge-red',
        'severe'            => 'badge-red',
    ];
    return '<span class="badge ' . ($map[$sev] ?? 'badge-gray') . '">' . ucfirst(str_replace('_', ' ', $sev)) . '</span>';
}

function riskLevel(array $s): string {
    if ($s['open_alerts'] > 0) return 'high';
    if (!empty($s['latest_severity']) && in_array($s['latest_severity'], ['severe', 'moderately_severe'])) return 'high';
    if ($s['low_mood_days'] >= 2) return 'mod';
    if (!empty($s['latest_severity']) && $s['latest_severity'] === 'moderate') return 'mod';
    return 'low';
}

function initials(array $s): string {
    return strtoupper(substr($s['first_name'], 0, 1) . substr($s['last_name'], 0, 1));
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return 'Just now';
    if ($diff < 3600)  return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    return round($diff / 86400) . 'd ago';
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Counselor Dashboard — CTU MindCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --sage:#4A9B7F;--sage-2:#5BAF91;--sage-light:#EAF7F2;--sage-mid:#C4E8DC;--sage-dark:#2D6B57;
  --sand:#F7F3EE;--sand-2:#EDE8E1;--sand-3:#D9D3C9;
  --lav:#7B6FC4;--lav-light:#EEEDFE;
  --amber:#D4860A;--amber-light:#FDF3DF;
  --coral:#D96B52;--coral-light:#FAECE7;
  --blue:#2460A7;--blue-light:#EBF4FF;
  --text:#2C2C2A;--text-2:#5C5B57;--text-3:#9A9891;
  --white:#FFFFFF;--border:#E8E4DE;--border-2:#D6D0C8;
}
body{font-family:'DM Sans',sans-serif;background:var(--sand);color:var(--text);min-height:100vh}

/* ── Topbar ── */
.topbar{height:58px;background:var(--white);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:200;gap:14px}
.topbar-mark{width:34px;height:34px;background:var(--sage);border-radius:9px;display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:17px;color:#fff;flex-shrink:0}
.topbar-name{font-family:'DM Serif Display',serif;font-size:17px;color:var(--text)}
.topbar-school{font-size:10px;color:var(--text-3);margin-top:1px}
.topbar-badge{font-size:11px;font-weight:500;padding:3px 10px;border-radius:20px;background:var(--sage-light);color:var(--sage-dark);border:1px solid var(--sage-mid)}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.notif-btn{position:relative;width:36px;height:36px;border-radius:9px;background:var(--sand);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:16px;text-decoration:none}
.notif-badge{position:absolute;top:4px;right:4px;min-width:16px;height:16px;border-radius:8px;background:var(--coral);border:1.5px solid var(--white);display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:600;color:#fff;padding:0 3px}
.user-chip{display:flex;align-items:center;gap:8px;background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:100px;padding:5px 14px 5px 6px;cursor:pointer}
.user-av{width:26px;height:26px;border-radius:50%;background:var(--sage);display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;color:#fff}
.user-name{font-size:12px;font-weight:500;color:var(--sage-dark)}
.logout-btn{font-size:12px;color:var(--text-3);text-decoration:none;padding:6px 12px;border-radius:7px;border:1px solid var(--border)}
.logout-btn:hover{color:var(--coral);border-color:var(--coral)}

/* ── Layout ── */
.layout{display:grid;grid-template-columns:var(--mc-sidebar-w,240px) 1fr;min-height:calc(100vh - 58px)}

/* ── Main ── */
.main{padding:26px 30px;overflow-y:auto}

/* ── Flash ── */
.flash{padding:11px 16px;border-radius:9px;font-size:13px;margin-bottom:18px;display:flex;align-items:center;gap:8px}
.flash-success{background:var(--sage-light);border:1px solid var(--sage-mid);color:var(--sage-dark)}
.flash-error{background:#FBEDED;border:1px solid #F5C6C2;color:#8B2020}
.flash-warning{background:var(--amber-light);border:1px solid #FAC775;color:#412402}

/* ── Crisis banner ── */
.crisis-banner{background:linear-gradient(135deg,#FFF3EF,var(--coral-light));border:1px solid #F0C4B4;border-radius:12px;padding:12px 18px;display:flex;align-items:center;justify-content:space-between;margin-bottom:22px;gap:12px}
.cb-dot{width:8px;height:8px;border-radius:50%;background:var(--coral);flex-shrink:0;animation:blink 2s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.cb-title{font-size:12px;font-weight:600;color:#8B3420;margin-bottom:1px}
.cb-text{font-size:11px;color:#B05040}
.btn-crisis{background:var(--coral);color:#fff;border:none;border-radius:8px;padding:7px 14px;font-family:'DM Sans',sans-serif;font-size:12px;font-weight:500;cursor:pointer;white-space:nowrap;text-decoration:none;display:inline-block}
.btn-crisis:hover{background:#C25A42}

/* ── Page header ── */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;gap:14px}
.page-title{font-family:'DM Serif Display',serif;font-size:24px;color:var(--text);margin-bottom:3px}
.page-sub{font-size:13px;color:var(--text-3)}

/* ── Stat cards ── */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:16px;position:relative;overflow:hidden;transition:box-shadow .15s}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:12px 12px 0 0}
.stat-card.c-sage::before{background:var(--sage)}
.stat-card.c-blue::before{background:var(--blue)}
.stat-card.c-coral::before{background:var(--coral)}
.stat-card.c-lav::before{background:var(--lav)}
.stat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.06)}
.stat-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:17px}
.stat-value{font-family:'DM Serif Display',serif;font-size:30px;color:var(--text);line-height:1}
.stat-label{font-size:11px;color:var(--text-3);margin-top:3px;text-transform:uppercase;letter-spacing:.05em;font-weight:500}
.badge{font-size:10px;font-weight:500;padding:3px 8px;border-radius:10px;display:inline-block}
.badge-green{background:var(--sage-light);color:var(--sage-dark)}
.badge-amber{background:var(--amber-light);color:var(--amber)}
.badge-red{background:#FBEDED;color:#8B2020}
.badge-lav{background:var(--lav-light);color:var(--lav)}
.badge-blue{background:var(--blue-light);color:var(--blue)}
.badge-gray{background:var(--sand-2);color:var(--text-3)}

/* ── Card ── */
.card{background:var(--white);border:1px solid var(--border);border-radius:13px;padding:20px}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.card-title{font-size:14px;font-weight:600;color:var(--text)}
.card-link{font-size:12px;color:var(--sage);text-decoration:none;font-weight:500}
.card-link:hover{text-decoration:underline}

/* ── Main grid ── */
.main-grid{display:grid;grid-template-columns:1fr 310px;gap:16px}
.col-stack{display:flex;flex-direction:column;gap:16px}

/* ── Student list ── */
.stu-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--sand-2);cursor:pointer;text-decoration:none;transition:background .1s;border-radius:0}
.stu-row:last-child{border-bottom:none}
.stu-row:hover{background:var(--sand);margin:0 -12px;padding:10px 12px;border-radius:8px;border-color:transparent}
.risk-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.r-high{background:var(--coral)}.r-mod{background:var(--amber)}.r-low{background:var(--sage)}.r-none{background:var(--sand-3)}
.s-av{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;flex-shrink:0}
.s-name{font-size:13px;font-weight:500;color:var(--text);margin-bottom:1px}
.s-id{font-size:11px;color:var(--text-3)}
.s-meta{margin-left:auto;text-align:right}
.s-mood{font-size:12px;color:var(--text-2)}
.s-date{font-size:10px;color:var(--text-3);margin-top:1px}

/* ── Appointment item ── */
.appt-item{display:flex;align-items:flex-start;gap:12px;padding:11px 0;border-bottom:1px solid var(--sand-2)}
.appt-item:last-child{border-bottom:none}
.adate-box{background:var(--sage-light);border-radius:9px;padding:7px 10px;text-align:center;flex-shrink:0;min-width:46px}
.adb-month{font-size:9px;font-weight:600;color:var(--sage-dark);text-transform:uppercase}
.adb-day{font-size:19px;font-weight:700;color:var(--sage-dark);line-height:1}
.ainfo{flex:1}
.a-name{font-size:13px;font-weight:500;color:var(--text);margin-bottom:2px}
.a-time{font-size:11px;color:var(--text-3)}
.appt-actions{display:flex;gap:6px;margin-top:6px}
.btn-xs{font-size:11px;padding:3px 8px;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--text-2);cursor:pointer;font-family:'DM Sans',sans-serif;text-decoration:none;display:inline-block}
.btn-xs:hover{background:var(--sand)}
.btn-xs.confirm{border-color:var(--sage);color:var(--sage-dark)}
.btn-xs.cancel{border-color:var(--coral);color:var(--coral)}
.btn-xs.danger{background:#FBEDED;border-color:#F5C6C2;color:#8B2020}

/* ── Alert items ── */
.alert-card{border-radius:11px;padding:14px 16px;margin-bottom:10px}
.alert-card.high{background:linear-gradient(135deg,#FFF3EF,var(--coral-light));border:1px solid #F0C4B4}
.alert-card.mod{background:linear-gradient(135deg,#FFFBF0,var(--amber-light));border:1px solid #FAC775}
.alert-card.resolved{background:var(--sand);border:1px solid var(--border);opacity:.7}
.alert-title{font-size:13px;font-weight:600;margin-bottom:3px}
.alert-card.high .alert-title{color:#8B2020}
.alert-card.mod .alert-title{color:#633806}
.alert-sub{font-size:12px;color:var(--text-2);line-height:1.5;margin-bottom:6px}
.alert-time{font-size:10px;color:var(--text-3)}
.alert-actions{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}

/* ── Chat ── */
.chat-layout{display:grid;grid-template-columns:240px 1fr;gap:14px;height:calc(100vh - 200px);min-height:400px}
.convo-list{background:var(--white);border:1px solid var(--border);border-radius:13px;padding:12px;overflow-y:auto}
.convo-item{display:flex;align-items:center;gap:9px;padding:9px 8px;border-radius:8px;cursor:pointer;text-decoration:none;margin-bottom:2px}
.convo-item:hover{background:var(--sand)}
.convo-item.active{background:var(--sage-light)}
.ci-nm{font-size:12px;font-weight:500;color:var(--text);margin-bottom:1px}
.ci-last{font-size:11px;color:var(--text-3);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px}
.chat-panel{background:var(--white);border:1px solid var(--border);border-radius:13px;display:flex;flex-direction:column;overflow:hidden}
.chat-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px}
.chat-head-name{font-size:14px;font-weight:600;color:var(--text)}
.chat-head-sub{font-size:11px;color:var(--text-3)}
.msg-list{flex:1;overflow-y:auto;padding:16px 18px;display:flex;flex-direction:column;gap:12px}
.msg-row{display:flex;align-items:flex-end;gap:8px}
.msg-row.mine{flex-direction:row-reverse}
.msg-av{width:28px;height:28px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:600;flex-shrink:0}
.msg-bubble{max-width:70%;padding:9px 13px;border-radius:13px;font-size:13px;line-height:1.5}
.msg-row:not(.mine) .msg-bubble{background:var(--sand-2);border-radius:0 13px 13px 13px;color:var(--text)}
.msg-row.mine .msg-bubble{background:var(--sage);color:#fff;border-radius:13px 0 13px 13px}
.msg-nm{font-size:10px;color:var(--text-3);margin-bottom:3px}
.msg-time{font-size:10px;color:var(--text-3);margin-top:3px}
.chat-input{padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:8px;align-items:flex-end}
.chat-input textarea{flex:1;padding:10px 14px;border-radius:14px;border:1px solid var(--border);background:var(--sand);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;resize:none;min-height:42px;max-height:120px;line-height:1.5}
.chat-input textarea:focus{outline:none;border-color:var(--sage)}
.btn-send{background:var(--sage);color:#fff;border:none;border-radius:12px;padding:10px 18px;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;cursor:pointer;min-height:42px}
.btn-send:hover{background:var(--sage-2)}

/* ── Tabs ── */
.tab-bar{display:flex;gap:4px;border-bottom:1px solid var(--border);margin-bottom:18px}
.tab{padding:8px 16px;font-size:13px;color:var(--text-2);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .12s}
.tab.active{color:var(--sage-dark);border-bottom-color:var(--sage);font-weight:500}
.tab:hover:not(.active){color:var(--text)}

/* ── Forms ── */
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:12px;font-weight:500;color:var(--text-2);margin-bottom:5px}
.form-input,.form-select,.form-textarea{width:100%;padding:9px 12px;border-radius:9px;border:1px solid var(--border);background:var(--sand);font-size:13px;color:var(--text);font-family:'DM Sans',sans-serif;transition:border-color .12s}
.form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:var(--sage)}
.form-textarea{resize:vertical;line-height:1.5}
.btn{padding:9px 18px;border-radius:9px;border:none;font-family:'DM Sans',sans-serif;font-size:13px;font-weight:500;cursor:pointer;transition:all .12s;text-decoration:none;display:inline-block}
.btn-sage{background:var(--sage);color:#fff}
.btn-sage:hover{background:var(--sage-2)}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text-2)}
.btn-outline:hover{background:var(--sand)}
.btn-danger{background:#FBEDED;border:1px solid #F5C6C2;color:#8B2020}
.btn-danger:hover{background:#F8DADA}

/* ── Notes ── */
.note-item{background:var(--sand);border-radius:10px;padding:12px 14px;margin-bottom:9px;border-left:3px solid var(--sage)}
.note-item.crisis-note{border-left-color:var(--coral)}
.note-student{font-size:12px;font-weight:600;color:var(--sage-dark);margin-bottom:3px}
.note-meta{font-size:10px;color:var(--text-3);margin-bottom:6px}
.note-body{font-size:12px;color:var(--text-2);line-height:1.6}

/* ── Assessment table ── */
.assess-table{width:100%;border-collapse:collapse}
.assess-table th{font-size:10px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;padding:9px 0;border-bottom:1px solid var(--border);text-align:left}
.assess-table td{padding:11px 0;font-size:13px;border-bottom:1px solid var(--sand-2)}
.assess-table tr:last-child td{border-bottom:none}
.code-pill{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;background:var(--lav-light);color:var(--lav);margin-right:6px}

/* ── Forum ── */
.forum-post{background:var(--sand);border-radius:10px;padding:13px 15px;margin-bottom:9px}
.forum-post.flagged{background:var(--coral-light);border:1px solid #F0C4B4}
.forum-title{font-size:13px;font-weight:500;color:var(--text);margin-bottom:4px}
.forum-meta{font-size:11px;color:var(--text-3)}
.forum-actions{display:flex;gap:8px;margin-top:9px}

/* ── Resources ── */
.res-item{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--sand-2)}
.res-item:last-child{border-bottom:none}
.res-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.res-name{font-size:13px;font-weight:500;color:var(--text);margin-bottom:2px}
.res-type{font-size:11px;color:var(--text-3)}

/* ── Announcement ── */
.ann-item{background:var(--sand);border-radius:9px;padding:11px 14px;margin-bottom:8px;border-left:3px solid var(--sage)}
.ann-body{font-size:12px;color:var(--text-2);line-height:1.5;margin-bottom:4px}
.ann-meta{font-size:10px;color:var(--text-3)}

/* ── Progress chart ── */
.chart-wrap{position:relative;height:180px}
.prog-bar-wrap{height:5px;background:var(--sand-2);border-radius:3px;overflow:hidden;margin-top:5px}
.prog-bar{height:100%;border-radius:3px;background:var(--sage);transition:width .4s}

/* ── Empty state ── */
.empty-state{text-align:center;padding:32px 16px;color:var(--text-3)}
.empty-icon{font-size:28px;margin-bottom:8px}
.empty-text{font-size:13px}

/* ── Responsive ── */
@media(max-width:1100px){.stat-grid{grid-template-columns:1fr 1fr}.main-grid{grid-template-columns:1fr}.chat-layout{grid-template-columns:1fr}}
@media(max-width:900px){.layout{grid-template-columns:1fr}.main{padding:18px 16px}}
@media(max-width:640px){.stat-grid{grid-template-columns:1fr 1fr}.page-header{flex-direction:column}.chat-layout{grid-template-columns:1fr;height:auto}}
</style>
</head>
<body>

<?php
$mc_topbar_badge = 'Wellness Counselor';
$mc_topbar_bells = [
    ['url' => 'messages.php', 'title' => $unread_msgs . ' unread message' . ($unread_msgs > 1 ? 's' : ''), 'icon' => '💬', 'count' => $unread_msgs],
    ['url' => 'dashboard.php?p=alerts', 'title' => $open_alerts_count . ' open alert' . ($open_alerts_count > 1 ? 's' : ''), 'icon' => '🚨', 'count' => $open_alerts_count],
];
require_once '../../includes/topbar.php';
?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php
  $mc_active_map = [
      'dashboard' => 'dashboard', 'students' => 'students', 'appointments' => 'appointments',
      'messages'  => 'messages',  'ai-logs'   => 'ai-logs',    'alerts'        => 'alerts',
      'notes'     => 'notes',     'assessments' => 'assessments', 'progress'    => 'progress',
      'forum'     => 'forum',     'resources' => 'resources',  'announcements' => 'announcements',
      'crisis'    => 'crisis',
  ];
  $active_page   = $mc_active_map[$page] ?? 'dashboard';
  $sidebar_badges = [
      'messages' => $unread_msgs,
      'ai-logs'  => $open_ai_flags,
      'alerts'   => $open_alerts_count,
      'forum'    => count($flagged_posts),
  ];
  require_once '../../includes/sidebar.php';
  ?>

  <!-- ── Main Content ── -->
  <main class="main">

    <!-- Flash message -->
    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type']==='success'?'✓':'⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════ -->
    <?php if ($page === 'dashboard'): ?>
    <!-- ── DASHBOARD ──────────────────────────────────────────── -->

    <?php if ($open_alerts_count > 0): ?>
    <div class="crisis-banner">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="cb-dot"></div>
        <div>
          <div class="cb-title"><?= $open_alerts_count ?> student<?= $open_alerts_count>1?'s':'' ?> need immediate attention</div>
          <div class="cb-text">
            <?php
            $alertNames = array_slice(array_map(fn($a) => clean($a['first_name'].', '.$a['last_name']), $open_cals), 0, 3);
            echo implode(' · ', $alertNames);
            ?>
          </div>
        </div>
      </div>
      <a class="btn-crisis" href="dashboard.php?p=alerts">View alerts →</a>
    </div>
    <?php endif; ?>

    <div class="page-header">
      <div>
        <div class="page-title"><?= $greeting ?>, <?= clean($user['first_name']) ?> 👋</div>
        <div class="page-sub"><?= date('l, F j, Y') ?> &nbsp;·&nbsp; <?= $total_students ?> students under your care</div>
      </div>
      <a class="btn btn-sage" href="dashboard.php?p=appointments#book">+ Book appointment</a>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
      <div class="stat-card c-sage">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--sage-light)">👥</div>
          <span class="badge badge-green">Active</span>
        </div>
        <div class="stat-value"><?= $total_students ?></div>
        <div class="stat-label">Students assigned</div>
      </div>
      <div class="stat-card c-blue">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--blue-light)">📅</div>
          <span class="badge badge-blue">±7 days</span>
        </div>
        <div class="stat-value"><?= $week_appts ?></div>
        <div class="stat-label">Appointments</div>
      </div>
      <div class="stat-card c-coral">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--coral-light)">⚠️</div>
          <span class="badge badge-<?= $open_alerts_count>0?'red':'gray' ?>"><?= $open_alerts_count>0?'Urgent':'None' ?></span>
        </div>
        <div class="stat-value"><?= $open_alerts_count ?></div>
        <div class="stat-label">Crisis alerts</div>
      </div>
      <div class="stat-card c-lav">
        <div class="stat-top">
          <div class="stat-icon" style="background:var(--lav-light)">💬</div>
          <span class="badge badge-<?= $unread_msgs>0?'red':'gray' ?>"><?= $unread_msgs>0?'Unread':'None' ?></span>
        </div>
        <div class="stat-value"><?= $unread_msgs ?></div>
        <div class="stat-label">Unread messages</div>
      </div>
    </div>

    <div class="main-grid">
      <div class="col-stack">

        <!-- Priority students -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Students needing attention</span>
            <a class="card-link" href="dashboard.php?p=students">See all →</a>
          </div>
          <?php
          $priority = array_filter($my_students, fn($s) => riskLevel($s) !== 'low');
          if ($priority): ?>
            <?php foreach (array_slice($priority, 0, 6) as $s): ?>
            <?php $risk = riskLevel($s); ?>
            <a class="stu-row" href="dashboard.php?p=progress&prog_student=<?= $s['id'] ?>">
              <span class="risk-dot r-<?= $risk ?>"></span>
              <div class="s-av" style="background:<?= $risk==='high'?'#FAECE7':($risk==='mod'?'#FDF3DF':'#EAF7F2') ?>;color:<?= $risk==='high'?'var(--coral)':($risk==='mod'?'var(--amber)':'var(--sage)') ?>">
                <?= initials($s) ?>
              </div>
              <div>
                <div class="s-name"><?= clean($s['first_name'].' '.$s['last_name']) ?></div>
                <div class="s-id">
                  <?php if ($s['open_alerts']>0): ?><span class="badge badge-red">⚠ Alert</span><?php endif; ?>
                  <?php if ($s['low_mood_days']>=2): ?><span class="badge badge-amber">Low mood <?= $s['low_mood_days'] ?>d</span><?php endif; ?>
                  <?php if (!empty($s['latest_severity'])): ?><?= sevBadge($s['latest_severity']) ?><?php endif; ?>
                </div>
              </div>
              <div class="s-meta">
                <div class="s-mood">
                  <?= !empty($s['latest_mood']) ? ($mood_emoji[$s['latest_mood']].' '.$mood_labels[$s['latest_mood']]) : '— No mood' ?>
                </div>
                <div class="s-date">
                  <?= !empty($s['latest_mood_at']) ? timeAgo($s['latest_mood_at']) : 'Never' ?>
                </div>
              </div>
            </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="empty-state">
              <div class="empty-icon">✅</div>
              <div class="empty-text">All students are doing well!</div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Mood trend chart -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Cohort mood trend — last 7 days</span>
          </div>
          <div class="chart-wrap">
            <canvas id="moodChart" role="img" aria-label="Bar chart showing average daily mood of assigned students over the past 7 days">Cohort mood data for the last 7 days.</canvas>
          </div>
        </div>

      </div>

      <div class="col-stack">

        <!-- Today's appointments -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Today's appointments</span>
            <a class="card-link" href="dashboard.php?p=appointments">Manage →</a>
          </div>
          <?php if ($today_appts): foreach ($today_appts as $a): ?>
            <div class="appt-item">
              <div class="adate-box">
                <div class="adb-month"><?= date('M', strtotime($a['appointment_date'])) ?></div>
                <div class="adb-day"><?= date('j', strtotime($a['appointment_date'])) ?></div>
              </div>
              <div class="ainfo">
                <div class="a-name"><?= clean($a['first_name'].' '.$a['last_name']) ?></div>
                <div class="a-time"><?= date('g:i A', strtotime($a['appointment_time'])) ?> · <?= clean($a['purpose'] ?: 'Counseling session') ?></div>
              </div>
              <span class="badge badge-<?= $a['status']==='confirmed'?'green':'amber' ?>"><?= ucfirst($a['status']) ?></span>
            </div>
          <?php endforeach; else: ?>
            <div class="empty-state">
              <div class="empty-icon">📅</div>
              <div class="empty-text">No appointments today</div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Recent alerts -->
        <div class="card">
          <div class="card-header">
            <span class="card-title">Recent alerts</span>
            <a class="card-link" href="dashboard.php?p=alerts">All →</a>
          </div>
          <?php if ($open_cals): foreach (array_slice($open_cals, 0, 3) as $a): ?>
            <div class="alert-card <?= $a['severity']==='high'?'high':'mod' ?>" style="margin-bottom:8px">
              <div class="alert-title">🚨 <?= clean($a['first_name'].' '.$a['last_name']) ?></div>
              <div class="alert-sub"><?= clean(substr($a['description'] ?? $a['trigger'] ?? '', 0, 100)) ?></div>
              <div class="alert-time"><?= timeAgo($a['created_at']) ?></div>
            </div>
          <?php endforeach; else: ?>
            <div class="empty-state">
              <div class="empty-icon">✅</div>
              <div class="empty-text">No open alerts</div>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>

    <!-- Chart script -->
    <script>
    (function(){
      const ctx = document.getElementById('moodChart');
      if (!ctx) return;
      new Chart(ctx, {
        type: 'bar',
        data: {
          labels: <?= json_encode($chart_labels) ?>,
          datasets: [{
            data: <?= json_encode($chart_data) ?>,
            backgroundColor: 'rgba(74,155,127,0.22)',
            borderColor: '#4A9B7F',
            borderWidth: 1.5,
            borderRadius: 6,
            borderSkipped: false,
          }]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: { legend: { display: false },
            tooltip: { callbacks: { label: c => c.raw ? ' Avg mood: ' + c.raw.toFixed(1) + '/5' : ' No data' } }
          },
          scales: {
            y: { min: 0, max: 5, ticks: { stepSize: 1, font: { size: 10 }, color: '#9A9891' }, grid: { color: 'rgba(0,0,0,.04)' }, border: { display: false } },
            x: { ticks: { font: { size: 11 }, color: '#9A9891' }, grid: { display: false }, border: { display: false } }
          }
        }
      });
    })();
    </script>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'students'): ?>
    <!-- ── STUDENTS ───────────────────────────────────────────── -->

    <div class="page-header">
      <div>
        <div class="page-title">My students</div>
        <div class="page-sub"><?= $total_students ?> students assigned to you</div>
      </div>
      <select class="form-select" style="width:180px" onchange="filterStudents(this.value)">
        <option value="all">All students</option>
        <option value="high">High risk</option>
        <option value="mod">Moderate risk</option>
        <option value="low">Low / normal</option>
      </select>
    </div>

    <div class="card">
      <div id="student-list">
        <?php foreach ($my_students as $s): ?>
        <?php $risk = riskLevel($s); ?>
        <a class="stu-row" href="dashboard.php?p=progress&prog_student=<?= $s['id'] ?>"
           data-risk="<?= $risk ?>" style="display:flex">
          <span class="risk-dot r-<?= $risk ?>"></span>
          <div class="s-av" style="background:<?= $risk==='high'?'#FAECE7':($risk==='mod'?'#FDF3DF':'#EAF7F2') ?>;color:<?= $risk==='high'?'var(--coral)':($risk==='mod'?'var(--amber)':'var(--sage)') ?>">
            <?= initials($s) ?>
          </div>
          <div style="flex:1">
            <div class="s-name"><?= clean($s['first_name'].' '.$s['last_name']) ?></div>
            <div class="s-id" style="margin-top:3px">
              <?php if ($s['open_alerts']>0): ?><span class="badge badge-red">⚠ Alert open</span> <?php endif; ?>
              <?php if (!empty($s['latest_severity'])): ?><?= sevBadge($s['latest_severity']) ?><?php endif; ?>
              <?php if (!empty($s['assess_code'])): ?><span class="badge badge-lav"><?= clean($s['assess_code']) ?>: <?= $s['latest_score'] ?></span><?php endif; ?>
            </div>
          </div>
          <div class="s-meta">
            <div class="s-mood"><?= !empty($s['latest_mood']) ? ($mood_emoji[$s['latest_mood']].' '.$mood_labels[$s['latest_mood']]) : '— No data' ?></div>
            <div class="s-date"><?= !empty($s['latest_mood_at']) ? timeAgo($s['latest_mood_at']) : 'No check-in' ?></div>
          </div>
        </a>
        <?php endforeach; ?>
        <?php if (!$my_students): ?>
          <div class="empty-state"><div class="empty-icon">👥</div><div class="empty-text">No students assigned yet.</div></div>
        <?php endif; ?>
      </div>
    </div>
    <script>
    function filterStudents(v){
      document.querySelectorAll('#student-list .stu-row').forEach(r => {
        r.style.display = (v==='all'||r.dataset.risk===v) ? 'flex' : 'none';
      });
    }
    </script>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'appointments'): ?>
    <!-- ── APPOINTMENTS ───────────────────────────────────────── -->

    <div class="page-header">
      <div>
        <div class="page-title">Appointments</div>
        <div class="page-sub">Manage your counseling schedule</div>
      </div>
    </div>

    <!-- Book new appointment form -->
    <div class="card" id="book" style="margin-bottom:18px">
      <div class="card-header"><span class="card-title">Book new appointment</span></div>
      <form method="POST" action="dashboard.php?p=appointments">
        <input type="hidden" name="form_action" value="book_appointment">
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px">
          <div class="form-group">
            <label class="form-label">Student *</label>
            <select name="student_id" class="form-select" required>
              <option value="">Select student...</option>
              <?php foreach ($my_students as $s): ?>
                <option value="<?= $s['id'] ?>"><?= clean($s['first_name'].' '.$s['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Date *</label>
            <input type="date" name="appointment_date" class="form-input" min="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Time slot *</label>
            <select name="appointment_time" class="form-select" required>
              <option value="">Select time...</option>
              <?php
              for ($h = 9; $h < 17; $h++) {
                  foreach (['00','30'] as $m) {
                      $t = sprintf('%02d:%s:00', $h, $m);
                      echo '<option value="'.$t.'">'.date('g:i A', strtotime($t)).'</option>';
                  }
              }
              ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Purpose / notes</label>
          <input type="text" name="purpose" class="form-input" placeholder="e.g. Individual counseling, follow-up, crisis response...">
        </div>
        <button type="submit" class="btn btn-sage">Book appointment</button>
      </form>
    </div>

    <!-- Tabs -->
    <div class="tab-bar">
      <div class="tab active" onclick="switchTab(this,'tab-upcoming')">Upcoming (<?= count($upcoming_appts) ?>)</div>
      <div class="tab" onclick="switchTab(this,'tab-pending')">Pending (<?= count($pending_appts) ?>)</div>
      <div class="tab" onclick="switchTab(this,'tab-past')">Completed</div>
    </div>

    <div id="tab-upcoming" class="card">
      <?php if ($upcoming_appts): foreach ($upcoming_appts as $a): ?>
        <div class="appt-item">
          <div class="adate-box">
            <div class="adb-month"><?= date('M', strtotime($a['appointment_date'])) ?></div>
            <div class="adb-day"><?= date('j', strtotime($a['appointment_date'])) ?></div>
          </div>
          <div class="ainfo">
            <div class="a-name"><?= clean($a['first_name'].' '.$a['last_name']) ?></div>
            <div class="a-time"><?= date('g:i A', strtotime($a['appointment_time'])) ?> · <?= clean($a['purpose'] ?: 'Counseling session') ?></div>
            <div class="appt-actions">
              <?php if ($a['status']==='pending'): ?>
                <a class="btn-xs confirm" href="dashboard.php?action=confirm_appt&id=<?= $a['id'] ?>&p=appointments">Confirm</a>
              <?php endif; ?>
              <a class="btn-xs" href="dashboard.php?p=messages&student=<?= $a['student_id'] ?>">Message student</a>
              <a class="btn-xs cancel" href="dashboard.php?action=cancel_appt&id=<?= $a['id'] ?>&p=appointments"
                 onclick="return confirm('Cancel this appointment?')">Cancel</a>
            </div>
          </div>
          <span class="badge badge-<?= $a['status']==='confirmed'?'green':'amber' ?>"><?= ucfirst($a['status']) ?></span>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-icon">📅</div><div class="empty-text">No upcoming appointments. Use the form above to book one.</div></div>
      <?php endif; ?>
    </div>

    <div id="tab-pending" class="card" style="display:none">
      <?php if ($pending_appts): foreach ($pending_appts as $a): ?>
        <div class="appt-item">
          <div class="adate-box">
            <div class="adb-month"><?= date('M', strtotime($a['appointment_date'])) ?></div>
            <div class="adb-day"><?= date('j', strtotime($a['appointment_date'])) ?></div>
          </div>
          <div class="ainfo">
            <div class="a-name"><?= clean($a['first_name'].' '.$a['last_name']) ?></div>
            <div class="a-time"><?= date('g:i A', strtotime($a['appointment_time'])) ?></div>
            <div class="appt-actions">
              <a class="btn-xs confirm" href="dashboard.php?action=confirm_appt&id=<?= $a['id'] ?>&p=appointments">Confirm</a>
              <a class="btn-xs cancel" href="dashboard.php?action=cancel_appt&id=<?= $a['id'] ?>&p=appointments"
                 onclick="return confirm('Cancel this appointment?')">Cancel</a>
            </div>
          </div>
          <span class="badge badge-amber">Pending</span>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-icon">✅</div><div class="empty-text">No pending appointments</div></div>
      <?php endif; ?>
    </div>

    <div id="tab-past" class="card" style="display:none">
      <?php if ($past_appts): foreach ($past_appts as $a): ?>
        <div class="appt-item">
          <div class="adate-box">
            <div class="adb-month"><?= date('M', strtotime($a['appointment_date'])) ?></div>
            <div class="adb-day"><?= date('j', strtotime($a['appointment_date'])) ?></div>
          </div>
          <div class="ainfo">
            <div class="a-name"><?= clean($a['first_name'].' '.$a['last_name']) ?></div>
            <div class="a-time"><?= date('g:i A', strtotime($a['appointment_time'])) ?></div>
          </div>
          <span class="badge badge-gray">Done</span>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-icon">📋</div><div class="empty-text">No completed appointments yet</div></div>
      <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'messages'): ?>
    <!-- ── MESSAGES ───────────────────────────────────────────── -->

    <div class="page-header">
      <div>
        <div class="page-title">Messages</div>
        <div class="page-sub">Chat with your students</div>
      </div>
    </div>

    <div class="chat-layout">
      <!-- Conversations list -->
      <div class="convo-list">
        <div style="font-size:11px;font-weight:600;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;margin-bottom:10px">Conversations</div>
        <?php
        // Build full list: conversations + students not yet messaged
        $convo_ids = array_column($conversations, 'id');
        $all_for_chat = $conversations;
        foreach ($my_students as $s) {
            if (!in_array($s['id'], $convo_ids)) {
                $all_for_chat[] = ['id'=>$s['id'],'first_name'=>$s['first_name'],'last_name'=>$s['last_name'],'last_message'=>null,'unread'=>0];
            }
        }
        if ($all_for_chat): foreach ($all_for_chat as $c): ?>
          <a class="convo-item <?= (int)($c['id'])===$chat_student_id?'active':'' ?>"
             href="dashboard.php?p=messages&student=<?= $c['id'] ?>">
            <div class="s-av" style="width:32px;height:32px;font-size:11px;background:var(--sage-light);color:var(--sage-dark)">
              <?= strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1)) ?>
            </div>
            <div style="flex:1;min-width:0">
              <div class="ci-nm"><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
              <div class="ci-last"><?= !empty($c['last_message']) ? clean(substr($c['last_message'],0,40)) : 'No messages yet' ?></div>
            </div>
            <?php if (!empty($c['unread']) && $c['unread']>0): ?>
              <span style="background:var(--coral);color:#fff;font-size:9px;padding:2px 5px;border-radius:8px;flex-shrink:0"><?= $c['unread'] ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; else: ?>
          <div class="empty-state"><div class="empty-text">No students yet</div></div>
        <?php endif; ?>
      </div>

      <!-- Chat panel -->
      <div class="chat-panel">
        <?php if ($chat_student): ?>
          <div class="chat-head">
            <div class="s-av" style="width:36px;height:36px;background:var(--sage-light);color:var(--sage-dark)">
              <?= initials($chat_student) ?>
            </div>
            <div>
              <div class="chat-head-name"><?= clean($chat_student['first_name'].' '.$chat_student['last_name']) ?></div>
              <div class="chat-head-sub">Student · <?= !empty($chat_student['latest_mood']) ? ($mood_emoji[$chat_student['latest_mood']].' '.$mood_labels[$chat_student['latest_mood']]) : 'No mood data' ?></div>
            </div>
          </div>
          <div class="msg-list" id="msg-list">
            <?php foreach ($chat_messages as $m): ?>
            <?php $sender_role = $m['sender_role'] ?? ((int)$m['sender_id'] === (int)$user['id'] ? 'wellness_admin' : 'student');
                  $isMe = $sender_role === 'wellness_admin'; ?>
            <div class="msg-row <?= $isMe ? 'mine' : '' ?>" data-id="<?= $m['id'] ?>">
              <div class="msg-av" style="background:<?= $isMe ? 'var(--sage)' : 'var(--sand-2)' ?>;color:<?= $isMe ? '#fff' : 'var(--text)' ?>">
                <?= strtoupper(substr($m['first_name'],0,1).substr($m['last_name'],0,1)) ?>
              </div>
              <div>
                <div class="msg-nm"><?= $isMe ? 'You' : clean($m['first_name']) ?></div>
                <div class="msg-bubble"><?= nl2br(clean($m['message'])) ?></div>
                <div class="msg-time"><?= timeAgo($m['sent_at']) ?></div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php if (!$chat_messages): ?>
              <div class="empty-state"><div class="empty-icon">💬</div><div class="empty-text">No messages yet. Start the conversation.</div></div>
            <?php endif; ?>
          </div>
          <div class="chat-input">
            <textarea id="msg-input" placeholder="Type a message..." rows="1" onkeydown="handleChatKey(event)" oninput="resizeChatInput(this)"></textarea>
            <button class="btn-send" onclick="sendMsg()">Send</button>
          </div>
        <?php else: ?>
          <div style="display:flex;align-items:center;justify-content:center;height:100%">
            <div class="empty-state"><div class="empty-icon">💬</div><div class="empty-text">Select a student to start chatting</div></div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($chat_student): ?>
    <script>
    const STUDENT_ID = <?= $chat_student_id ?>;
    let lastMsgId = <?= $chat_messages ? end($chat_messages)['id'] : 0 ?>;
    const counselorId = <?= $user['id'] ?>;

    // Scroll to bottom
    const ml = document.getElementById('msg-list');
    if (ml) ml.scrollTop = ml.scrollHeight;

    function resizeChatInput(el) {
      el.style.height = 'auto';
      el.style.height = Math.min(120, el.scrollHeight) + 'px';
    }

    function handleChatKey(e) {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        sendMsg();
      }
    }

    function sendMsg() {
      const inp = document.getElementById('msg-input');
      if (!inp || !inp.value.trim()) return;
      const msg = inp.value.trim();
      inp.value = '';
      resizeChatInput(inp);

      fetch('dashboard.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body: 'message_text=' + encodeURIComponent(msg) + '&student_id=' + STUDENT_ID
      }).then(() => pollMessages());

      // Optimistic UI
      const div = document.createElement('div');
      div.className = 'msg-row mine';
      const escaped = msg.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
      div.innerHTML = `<div class="msg-av" style="background:var(--sage);color:#fff"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div><div class="msg-nm">You</div><div class="msg-bubble">${escaped}</div><div class="msg-time">Just now</div></div>`;
      ml.appendChild(div);
      ml.scrollTop = ml.scrollHeight;
    }

    function pollMessages() {
      fetch(`dashboard.php?poll_messages=1&student=${STUDENT_ID}&since=${lastMsgId}`)
        .then(r => r.json())
        .then(data => {
          if (data.messages && data.messages.length) {
            data.messages.forEach(m => {
              lastMsgId = Math.max(lastMsgId, m.id);
              const senderRole = m.sender_role || (m.sender_id == counselorId ? 'wellness_admin' : 'student');
              if (senderRole === 'wellness_admin') return; // own message already shown
              if (ml.querySelector(`[data-id="${m.id}"]`)) return;
              const div = document.createElement('div');
              div.className = 'msg-row';
              div.dataset.id = m.id;
              const initials = ((m.first_name||'')[0] + (m.last_name||'')[0]).toUpperCase();
              const safeText = (m.message || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
              div.innerHTML = `<div class="msg-av" style="background:var(--sand-2);color:var(--text)">${initials}</div>
                <div><div class="msg-nm">${m.first_name}</div><div class="msg-bubble">${safeText}</div><div class="msg-time">Just now</div></div>`;
              ml.appendChild(div);
              ml.scrollTop = ml.scrollHeight;
            });
          }
        }).catch(() => {});
    }

    setInterval(pollMessages, 4000);
    </script>
    <?php endif; ?>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'alerts'): ?>
    <!-- ── ALERTS ─────────────────────────────────────────────── -->

    <div class="page-header">
      <div>
        <div class="page-title">Crisis alerts</div>
        <div class="page-sub"><?= $open_alerts_count ?> open alerts</div>
      </div>
    </div>

    <div class="tab-bar">
      <div class="tab active" onclick="switchTab(this,'al-open')">Open (<?= count($open_cals) ?>)</div>
      <div class="tab" onclick="switchTab(this,'al-ack')">Acknowledged (<?= count($ack_cals) ?>)</div>
      <div class="tab" onclick="switchTab(this,'al-res')">Resolved</div>
    </div>

    <div id="al-open">
      <?php if ($open_cals): foreach ($open_cals as $a): ?>
        <div class="alert-card <?= $a['severity']==='high'?'high':'mod' ?>">
          <div class="alert-title">
            <?= $a['severity']==='high'?'🚨':'⚠️' ?>
            <?= clean($a['first_name'].' '.$a['last_name']) ?>
            <span class="badge badge-<?= $a['severity']==='high'?'red':'amber' ?>" style="margin-left:6px"><?= ucfirst($a['severity']) ?></span>
          </div>
          <div class="alert-sub"><?= clean($a['description'] ?? '') ?></div>
          <div class="alert-time">Reported <?= timeAgo($a['created_at']) ?></div>
          <div class="alert-actions">
            <a class="btn btn-outline btn-xs" href="dashboard.php?action=ack_alert&id=<?= $a['id'] ?>&p=alerts">Acknowledge</a>
            <a class="btn btn-sage btn-xs" href="dashboard.php?p=messages&student=<?= $a['student_id'] ?>">Contact student</a>
            <a class="btn btn-outline btn-xs" href="dashboard.php?p=appointments#book">Schedule session</a>
            <a class="btn btn-danger btn-xs" href="dashboard.php?action=resolve_alert&id=<?= $a['id'] ?>&p=alerts"
               onclick="return confirm('Mark as resolved?')">Resolve</a>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-icon">✅</div><div class="empty-text">No open alerts. All students are safe.</div></div>
      <?php endif; ?>
    </div>

    <div id="al-ack" style="display:none">
      <?php if ($ack_cals): foreach ($ack_cals as $a): ?>
        <div class="alert-card mod">
          <div class="alert-title">✔ <?= clean($a['first_name'].' '.$a['last_name']) ?></div>
          <div class="alert-sub"><?= clean($a['description'] ?? '') ?></div>
          <div class="alert-time">Acknowledged · <?= timeAgo($a['created_at']) ?></div>
          <div class="alert-actions">
            <a class="btn btn-danger btn-xs" href="dashboard.php?action=resolve_alert&id=<?= $a['id'] ?>&p=alerts"
               onclick="return confirm('Resolve this alert?')">Mark resolved</a>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-text">No acknowledged alerts</div></div>
      <?php endif; ?>
    </div>

    <div id="al-res" style="display:none">
      <?php if ($res_cals): foreach ($res_cals as $a): ?>
        <div class="alert-card resolved">
          <div class="alert-title">✅ <?= clean($a['first_name'].' '.$a['last_name']) ?></div>
          <div class="alert-sub"><?= clean($a['description'] ?? '') ?></div>
          <div class="alert-time">Resolved · <?= timeAgo($a['created_at']) ?></div>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-text">No resolved alerts</div></div>
      <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'notes'): ?>
    <!-- ── SESSION NOTES ──────────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Session notes</div>
    </div>

    <div class="main-grid">
      <!-- Note form -->
      <div class="card">
        <div class="card-header"><span class="card-title">New session note</span></div>
        <form method="POST" action="dashboard.php?p=notes">
          <input type="hidden" name="form_action" value="save_note">
          <div class="form-group">
            <label class="form-label">Student *</label>
            <select name="student_id" class="form-select" required>
              <option value="">Select student...</option>
              <?php foreach ($my_students as $s): ?>
                <option value="<?= $s['id'] ?>"><?= clean($s['first_name'].' '.$s['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Session type</label>
            <select name="session_type" class="form-select">
              <option value="">Select type...</option>
              <option>Individual counseling</option>
              <option>Follow-up</option>
              <option>Crisis response</option>
              <option>Assessment review</option>
              <option>Psychoeducation</option>
              <option>Referral</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Session notes (confidential) *</label>
            <textarea name="notes_body" class="form-textarea" rows="5" placeholder="Observations, interventions, student response..." required></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Mood observed during session</label>
            <select name="mood_observed" class="form-select">
              <option value="">Not recorded</option>
              <?php foreach ($mood_labels as $lv => $lb): ?>
                <option value="<?= $lv ?>"><?= $lv ?> – <?= $lb ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Follow-up plan</label>
            <textarea name="followup_plan" class="form-textarea" rows="2" placeholder="Next steps, homework, referrals..."></textarea>
          </div>
          <button type="submit" class="btn btn-sage">Save note</button>
        </form>
      </div>

      <!-- Recent notes -->
      <div class="card">
        <div class="card-header"><span class="card-title">Recent notes</span></div>
        <?php if ($session_notes): foreach ($session_notes as $n): ?>
          <div class="note-item <?= ($n['session_type']??'')==='Crisis response'?'crisis-note':'' ?>">
            <div class="note-student"><?= clean($n['first_name'].' '.$n['last_name']) ?></div>
            <div class="note-meta"><?= clean($n['session_type'] ?? 'Session') ?> · <?= date('M j, Y', strtotime($n['created_at'])) ?></div>
            <div class="note-body"><?= clean(substr($n['notes'], 0, 200)) ?><?= strlen($n['notes'])>200?'…':'' ?></div>
            <?php if (!empty($n['followup_plan'])): ?>
              <div style="font-size:11px;color:var(--text-3);margin-top:5px">↗ <?= clean(substr($n['followup_plan'],0,80)) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; else: ?>
          <div class="empty-state"><div class="empty-icon">📋</div><div class="empty-text">No session notes yet</div></div>
        <?php endif; ?>
      </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'assessments'): ?>
    <!-- ── ASSESSMENTS ────────────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Assessment results</div>
      <div class="page-sub">PHQ-9, GAD-7, PSS-10 results for your students</div>
    </div>

    <div class="card">
      <?php if ($assess_results): ?>
        <table class="assess-table">
          <thead>
            <tr>
              <th>Student</th>
              <th>Assessment</th>
              <th>Score</th>
              <th>Severity</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($assess_results as $a): ?>
              <tr>
                <td><strong><?= clean($a['first_name'].' '.$a['last_name']) ?></strong></td>
                <td><span class="code-pill"><?= clean($a['code']) ?></span><?= clean($a['title']) ?></td>
                <td><strong><?= $a['total_score'] ?></strong></td>
                <td><?= sevBadge($a['severity']) ?></td>
                <td style="color:var(--text-3);font-size:12px"><?= date('M j, Y', strtotime($a['taken_at'])) ?></td>
                <td>
                  <a class="btn-xs" href="dashboard.php?p=messages&student=<?= $a['student_id'] ?>">Message</a>
                  <a class="btn-xs" href="dashboard.php?p=progress&prog_student=<?= $a['student_id'] ?>">Progress</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty-state"><div class="empty-icon">📊</div><div class="empty-text">No assessment results yet for your students.</div></div>
      <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'progress'): ?>
    <!-- ── PROGRESS ───────────────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Student progress</div>
    </div>

    <div class="main-grid">
      <!-- Student picker -->
      <div>
        <div class="card" style="margin-bottom:16px">
          <div class="card-header"><span class="card-title">Select student</span></div>
          <?php foreach ($my_students as $s): ?>
            <?php $risk = riskLevel($s); $active = (int)$s['id']===$prog_student_id; ?>
            <a class="stu-row" href="dashboard.php?p=progress&prog_student=<?= $s['id'] ?>"
               style="display:flex;background:<?= $active?'var(--sage-light)':'transparent' ?>">
              <span class="risk-dot r-<?= $risk ?>"></span>
              <div class="s-av" style="background:<?= $active?'var(--sage-mid)':'var(--sand-2)' ?>;color:<?= $active?'var(--sage-dark)':'var(--text-2)' ?>">
                <?= initials($s) ?>
              </div>
              <div>
                <div class="s-name"><?= clean($s['first_name'].' '.$s['last_name']) ?></div>
                <div style="font-size:10px;color:var(--text-3)">
                  <?= !empty($s['assess_code']) ? $s['assess_code'].': '.$s['latest_score'] : 'No assessments' ?>
                </div>
              </div>
              <div class="s-meta">
                <div class="s-mood"><?= !empty($s['latest_mood'])?$mood_emoji[$s['latest_mood']]:'—' ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Progress detail -->
      <div>
        <?php if ($prog_student && $prog_mood_data): ?>
          <div class="card">
            <div class="card-header">
              <span class="card-title"><?= clean($prog_student['first_name'].' '.$prog_student['last_name']) ?></span>
              <div>
                <?php if (!empty($prog_student['latest_severity'])): ?><?= sevBadge($prog_student['latest_severity']) ?><?php endif; ?>
                <?php if (!empty($prog_student['assess_code'])): ?>
                  <span class="badge badge-lav"><?= clean($prog_student['assess_code']) ?>: <?= $prog_student['latest_score'] ?></span>
                <?php endif; ?>
              </div>
            </div>

            <!-- Mood stats -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px">
              <div style="background:var(--sand);border-radius:9px;padding:12px">
                <div style="font-size:11px;color:var(--text-3);margin-bottom:4px">Latest mood</div>
                <div style="font-size:20px">
                  <?= !empty($prog_student['latest_mood']) ? ($mood_emoji[$prog_student['latest_mood']].' '.$mood_labels[$prog_student['latest_mood']]) : '— No data' ?>
                </div>
              </div>
              <div style="background:var(--sand);border-radius:9px;padding:12px">
                <div style="font-size:11px;color:var(--text-3);margin-bottom:4px">Low mood days (30d)</div>
                <div style="font-size:20px;font-weight:600;color:<?= $prog_student['low_mood_days']>=2?'var(--coral)':'var(--sage)' ?>">
                  <?= $prog_student['low_mood_days'] ?> <span style="font-size:12px;color:var(--text-3)">days</span>
                </div>
              </div>
            </div>

            <!-- Mood chart -->
            <div style="font-size:12px;font-weight:500;color:var(--text-2);margin-bottom:8px">Mood — last 30 days</div>
            <div class="chart-wrap" style="height:140px">
              <canvas id="progChart" role="img" aria-label="Student mood chart over 30 days">Mood data for the last 30 days.</canvas>
            </div>

            <!-- Assessment history -->
            <?php if ($prog_assess): ?>
              <div style="margin-top:16px">
                <div style="font-size:12px;font-weight:500;color:var(--text-2);margin-bottom:8px">Assessment history</div>
                <?php foreach ($prog_assess as $a): ?>
                  <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--sand-2)">
                    <span class="code-pill"><?= clean($a['code']) ?></span>
                    <span style="font-size:13px;font-weight:500"><?= $a['total_score'] ?></span>
                    <?= sevBadge($a['severity']) ?>
                    <span style="font-size:11px;color:var(--text-3);margin-left:auto"><?= date('M j, Y', strtotime($a['taken_at'])) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <div style="display:flex;gap:8px;margin-top:16px">
              <a class="btn btn-sage" href="dashboard.php?p=messages&student=<?= $prog_student_id ?>">Message student</a>
              <a class="btn btn-outline" href="dashboard.php?p=notes">Add session note</a>
              <a class="btn btn-outline" href="dashboard.php?p=appointments#book">Book session</a>
            </div>
          </div>

          <script>
          (function(){
            const ctx = document.getElementById('progChart');
            if (!ctx) return;
            const labels = <?= json_encode(array_column($prog_mood_data,'label')) ?>;
            const data   = <?= json_encode(array_column($prog_mood_data,'val')) ?>;
            new Chart(ctx, {
              type: 'line',
              data: {
                labels,
                datasets: [{
                  data,
                  borderColor: '#4A9B7F',
                  backgroundColor: 'rgba(74,155,127,0.1)',
                  borderWidth: 2,
                  pointRadius: 3,
                  pointBackgroundColor: '#4A9B7F',
                  spanGaps: true,
                  tension: 0.4,
                  fill: true,
                }]
              },
              options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                  y: { min: 0, max: 5, ticks: { stepSize: 1, font: { size: 10 }, color: '#9A9891' }, grid: { color: 'rgba(0,0,0,.04)' }, border: { display: false } },
                  x: { ticks: { font: { size: 9 }, color: '#9A9891', maxTicksLimit: 10 }, grid: { display: false }, border: { display: false } }
                }
              }
            });
          })();
          </script>

        <?php elseif ($prog_student): ?>
          <div class="card">
            <div class="empty-state"><div class="empty-icon">📈</div><div class="empty-text">No mood data yet for this student.</div></div>
          </div>
        <?php else: ?>
          <div class="card">
            <div class="empty-state"><div class="empty-icon">👤</div><div class="empty-text">Select a student from the list to view their progress.</div></div>
          </div>
        <?php endif; ?>
      </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'forum'): ?>
    <!-- ── FORUM MODERATION ───────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Forum moderation</div>
      <div class="page-sub"><?= count($flagged_posts) ?> flagged posts</div>
    </div>

    <div class="tab-bar">
      <div class="tab active" onclick="switchTab(this,'fm-flagged')">Flagged (<?= count($flagged_posts) ?>)</div>
      <div class="tab" onclick="switchTab(this,'fm-recent')">All recent posts</div>
    </div>

    <div id="fm-flagged">
      <?php if ($flagged_posts): foreach ($flagged_posts as $p): ?>
        <div class="forum-post flagged">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <span class="badge badge-red">🚩 Flagged</span>
            <span style="font-size:10px;color:var(--text-3)"><?= date('M j, Y g:i A', strtotime($p['created_at'])) ?></span>
          </div>
          <div class="forum-title"><?= clean($p['title']) ?></div>
          <div class="forum-meta"><?= $p['reply_count'] ?> replies · <?= $p['react_count'] ?> reactions</div>
          <div class="forum-actions">
            <a class="btn btn-outline btn-xs" href="dashboard.php?action=remove_post&id=<?= $p['id'] ?>&p=forum"
               onclick="return confirm('Remove this post?')" class="btn-danger">Remove post</a>
            <span class="btn btn-outline btn-xs" style="cursor:default">Keep post</span>
          </div>
        </div>
      <?php endforeach; else: ?>
        <div class="empty-state"><div class="empty-icon">✅</div><div class="empty-text">No flagged posts. Forum is clean.</div></div>
      <?php endif; ?>
    </div>

    <div id="fm-recent" style="display:none">
      <?php foreach ($forum_posts as $p): ?>
        <div class="forum-post <?= $p['is_flagged']?'flagged':'' ?>">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
            <?php if ($p['is_flagged']): ?><span class="badge badge-red">🚩 Flagged</span><?php else: ?><span class="badge badge-gray">Post</span><?php endif; ?>
            <span style="font-size:10px;color:var(--text-3)"><?= date('M j, g:i A', strtotime($p['created_at'])) ?></span>
          </div>
          <div class="forum-title"><?= clean($p['title']) ?></div>
          <div class="forum-meta"><?= $p['reply_count'] ?> replies · <?= $p['react_count'] ?> reactions</div>
          <?php if (!$p['is_flagged']): ?>
            <div class="forum-actions">
              <a class="btn btn-danger btn-xs" href="dashboard.php?action=remove_post&id=<?= $p['id'] ?>&p=forum"
                 onclick="return confirm('Remove this post?')">Remove</a>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'resources'): ?>
    <!-- ── RESOURCES ─────────────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Resource library</div>
    </div>

    <div class="card">
      <?php if ($resources): ?>
        <?php foreach ($resources as $r): ?>
          <div class="res-item">
            <div class="res-icon" style="background:var(--sage-light)">📄</div>
            <div style="flex:1">
              <div class="res-name"><?= clean($r['title'] ?? '') ?></div>
              <div class="res-type"><?= clean($r['type'] ?? '') ?> · <?= clean($r['category'] ?? '') ?></div>
            </div>
            <a class="btn-xs" href="<?= clean($r['url'] ?? '#') ?>" target="_blank" rel="noopener">Open</a>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📚</div>
          <div class="empty-text">No resources in the library yet. Ask your system admin to add some.</div>
        </div>
      <?php endif; ?>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'announcements'): ?>
    <!-- ── ANNOUNCEMENTS ─────────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Announcements</div>
    </div>

    <div class="main-grid">
      <!-- Send form -->
      <div class="card">
        <div class="card-header"><span class="card-title">Send announcement</span></div>
        <form method="POST" action="dashboard.php?p=announcements">
          <input type="hidden" name="form_action" value="send_announcement">
          <div class="form-group">
            <label class="form-label">Send to</label>
            <select name="target" class="form-select">
              <option value="my_students">My students only</option>
              <option value="all_students">All students</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Message *</label>
            <textarea name="message" class="form-textarea" rows="4" maxlength="500"
              placeholder="Write your announcement..." required></textarea>
          </div>
          <button type="submit" class="btn btn-sage">Send announcement</button>
        </form>
      </div>

      <!-- Recent announcements -->
      <div class="card">
        <div class="card-header"><span class="card-title">Recent announcements</span></div>
        <?php if ($announcements): foreach ($announcements as $a): ?>
          <div class="ann-item">
            <div class="ann-body"><?= clean($a['message']) ?></div>
            <div class="ann-meta">Sent to <?= $a['sent_to'] ?> user<?= $a['sent_to']>1?'s':'' ?> · <?= date('M j, Y', strtotime($a['created_at'])) ?></div>
          </div>
        <?php endforeach; else: ?>
          <div class="empty-state"><div class="empty-icon">📢</div><div class="empty-text">No announcements sent yet.</div></div>
        <?php endif; ?>
      </div>
    </div>


    <!-- ══════════════════════════════════════════════════════════ -->
    <?php elseif ($page === 'crisis'): ?>
    <!-- ── CRISIS PROTOCOLS ───────────────────────────────────── -->

    <div class="page-header">
      <div class="page-title">Crisis protocols</div>
    </div>

    <div class="main-grid">
      <div class="col-stack">
        <!-- Emergency hotlines -->
        <div class="card">
          <div class="card-header"><span class="card-title">🚨 Emergency contacts</span></div>
          <div class="ann-item" style="border-left-color:var(--coral);background:var(--coral-light)">
            <div class="ann-body" style="color:#8B2020;font-weight:500">Hopeline Philippines</div>
            <div class="ann-meta">📞 02-804-4673 · Available 24/7</div>
          </div>
          <div class="ann-item" style="border-left-color:var(--coral);background:var(--coral-light);margin-top:8px">
            <div class="ann-body" style="color:#8B2020;font-weight:500">In Touch Community Services</div>
            <div class="ann-meta">📱 0917-800-1123 · Multilingual support</div>
          </div>
          <div class="ann-item" style="border-left-color:var(--amber);background:var(--amber-light);margin-top:8px">
            <div class="ann-body" style="color:#633806;font-weight:500">Crisis Text Line</div>
            <div class="ann-meta">💬 Text HELLO to 4673</div>
          </div>
          <div class="ann-item" style="border-left-color:var(--sage);background:var(--sage-light);margin-top:8px">
            <div class="ann-body" style="color:var(--sage-dark);font-weight:500">CTU Emergency Hotline</div>
            <div class="ann-meta">📞 (032) 123-4567 · During school hours</div>
          </div>
        </div>

        <!-- Checklist -->
        <div class="card">
          <div class="card-header"><span class="card-title">Response checklist</span></div>
          <?php
          $checklist = [
              'Assess immediate safety risk of the student',
              'Contact student directly by phone or in-person',
              'Notify department head / supervisor',
              'Log crisis incident in the system',
              'Contact emergency contact / guardian if needed',
              'Arrange follow-up session within 24 hours',
              'Complete formal incident report',
              'Refer to appropriate mental health professional',
          ];
          foreach ($checklist as $i => $item): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--sand-2);cursor:pointer;font-size:13px;color:var(--text-2)">
              <input type="checkbox" id="chk<?= $i ?>" onchange="this.closest('label').style.opacity=this.checked?.5:1;this.closest('label').style.textDecoration=this.checked?'line-through':'none'">
              <?= $item ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Log crisis incident -->
      <div class="card">
        <div class="card-header"><span class="card-title">Log crisis incident</span></div>
        <form method="POST" action="dashboard.php?p=crisis">
          <input type="hidden" name="form_action" value="log_crisis">
          <div class="form-group">
            <label class="form-label">Student *</label>
            <select name="student_id" class="form-select" required>
              <option value="">Select student...</option>
              <?php foreach ($my_students as $s): ?>
                <option value="<?= $s['id'] ?>"><?= clean($s['first_name'].' '.$s['last_name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Severity *</label>
            <select name="severity" class="form-select" required>
              <option value="">Select severity...</option>
              <option value="low">Low — monitor closely</option>
              <option value="moderate">Moderate — intervene promptly</option>
              <option value="high">High — immediate action required</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Incident description *</label>
            <textarea name="description" class="form-textarea" rows="4"
              placeholder="Describe the situation, triggers, and student's state..." required></textarea>
          </div>
          <div class="form-group">
            <label class="form-label">Action taken</label>
            <select name="action_taken" class="form-select">
              <option value="">Select action...</option>
              <option>Referred student to hotline</option>
              <option>Emergency contact notified</option>
              <option>Hospital referral arranged</option>
              <option>Scheduled emergency session</option>
              <option>Campus security notified</option>
              <option>Guided student through de-escalation</option>
            </select>
          </div>
          <button type="submit" class="btn btn-sage">Log incident</button>
          <p style="font-size:11px;color:var(--text-3);margin-top:10px">
            This will create a crisis alert visible to system administrators.
          </p>
        </form>
      </div>
    </div>

    <?php endif; ?>

  </main>
</div>

<!-- Tab switching script -->
<script>
function switchTab(el, targetId) {
  const parent = el.closest('.main') || document.body;
  parent.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  // Find all sibling tab-content divs
  const allTabContents = document.querySelectorAll('[id^="tab-"],[id^="al-"],[id^="fm-"]');
  // Get just same-group siblings by looking at the tab bar siblings
  const tabBar = el.closest('.tab-bar');
  if (tabBar) {
    tabBar.querySelectorAll('.tab').forEach(t => {
      const tid = t.getAttribute('onclick')?.match(/'([^']+)'/)?.[1];
      if (tid) {
        const el2 = document.getElementById(tid);
        if (el2) el2.style.display = 'none';
      }
    });
  }
  const target = document.getElementById(targetId);
  if (target) target.style.display = '';
}
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>