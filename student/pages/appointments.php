<?php
// ============================================================
//  CTU MindCare — Student Appointments
//  File: student/pages/appointments.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$error   = '';
$success = '';

// ── Load all wellness counselors ─────────────────────────────
$stmt = $db->prepare("
    SELECT id, first_name, last_name, email
    FROM wellness_admins
    WHERE is_active = 1
    ORDER BY first_name ASC
");
$stmt->execute();
$counselors = $stmt->fetchAll();

// ── Available time slots (9 AM – 5 PM, every 30 min) ─────────
$time_slots = [];
for ($h = 9; $h < 17; $h++) {
    $time_slots[] = sprintf('%02d:00:00', $h);
    $time_slots[] = sprintf('%02d:30:00', $h);
}

// ── CANCEL action ─────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'cancel') {
    $cid = (int)($_GET['id'] ?? 0);
    if ($cid) {
        $stmt = $db->prepare("
            UPDATE appointments SET status = 'cancelled'
            WHERE id = ? AND student_id = ? AND status IN ('pending','confirmed')
        ");
        $stmt->execute([$cid, $user['id']]);
        setFlash('success', 'Appointment cancelled successfully.');
    }
    header('Location: appointments.php');
    exit;
}

// ── BOOK action ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $counselor_id = (int)($_POST['counselor_id'] ?? 0);
    $appt_date    = trim($_POST['appointment_date'] ?? '');
    $appt_time    = trim($_POST['appointment_time'] ?? '');
    $purpose      = trim($_POST['purpose'] ?? '');

    // Validation
    if (!$counselor_id) {
        $error = 'Please select a counselor.';
    } elseif (!$appt_date || strtotime($appt_date) < strtotime('today')) {
        $error = 'Please choose a valid future date.';
    } elseif (!$appt_time) {
        $error = 'Please select a time slot.';
    } elseif (date('N', strtotime($appt_date)) >= 6) {
        $error = 'Please select a weekday (Monday – Friday).';
    } else {
        // Check slot not already taken by this counselor
        $stmt = $db->prepare("
            SELECT id FROM appointments
            WHERE counselor_id = ?
              AND appointment_date = ?
              AND appointment_time = ?
              AND status NOT IN ('cancelled')
        ");
        $stmt->execute([$counselor_id, $appt_date, $appt_time]);
        if ($stmt->fetch()) {
            $error = 'That time slot is already booked. Please choose another.';
        } else {
            // Check student doesn't already have a pending/confirmed appointment that day
            $stmt = $db->prepare("
                SELECT id FROM appointments
                WHERE student_id = ?
                  AND appointment_date = ?
                  AND status NOT IN ('cancelled', 'completed')
            ");
            $stmt->execute([$user['id'], $appt_date]);
            if ($stmt->fetch()) {
                $error = 'You already have an appointment on that day. Please choose a different date.';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO appointments (student_id, counselor_id, appointment_date, appointment_time, purpose, status)
                    VALUES (?, ?, ?, ?, ?, 'pending')
                ");
                $stmt->execute([
                    $user['id'],
                    $counselor_id,
                    $appt_date,
                    $appt_time,
                    $purpose ?: null,
                ]);

                // Notify student
                $c = array_filter($counselors, fn($c) => $c['id'] === $counselor_id);
                $c = array_values($c)[0] ?? null;
                $c_name = $c ? $c['first_name'] . ' ' . $c['last_name'] : 'your counselor';
                $stmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'appointment', ?)");
                $stmt->execute([
                    $user['id'],
                    "Your appointment request with {$c_name} on " . date('F j, Y', strtotime($appt_date)) . " at " . date('g:i A', strtotime($appt_time)) . " has been submitted and is pending confirmation."
                ]);

                setFlash('success', "Appointment booked! You'll be notified once it's confirmed.");
                header('Location: appointments.php');
                exit;
            }
        }
    }
}

// ── Load student's appointments ───────────────────────────────
$stmt = $db->prepare("
    SELECT a.id, a.appointment_date, a.appointment_time, a.purpose, a.status,
           a.counselor_notes, a.created_at,
           u.first_name AS c_first, u.last_name AS c_last, u.email AS c_email
    FROM appointments a
    JOIN wellness_admins u ON a.counselor_id = u.id
    WHERE a.student_id = ?
    ORDER BY a.appointment_date DESC, a.appointment_time DESC
");
$stmt->execute([$user['id']]);
$all_appointments = $stmt->fetchAll();

// FIXED: Split into upcoming vs past with proper timestamp comparison
$today_start = strtotime(date('Y-m-d 00:00:00'));

$upcoming = array_filter($all_appointments, function($a) use ($today_start) {
    $appt_timestamp = strtotime($a['appointment_date']);
    return in_array($a['status'], ['pending', 'confirmed']) && 
           $appt_timestamp >= $today_start;
});

$past = array_filter($all_appointments, function($a) use ($today_start) {
    $appt_timestamp = strtotime($a['appointment_date']);
    return in_array($a['status'], ['completed', 'cancelled']) ||
           (in_array($a['status'], ['pending', 'confirmed']) && 
            $appt_timestamp < $today_start);
});

// ── Get taken slots for JS calendar ──────────────────────────
// Fetch booked slots for next 60 days for all counselors
$stmt = $db->prepare("
    SELECT counselor_id, appointment_date, appointment_time
    FROM appointments
    WHERE appointment_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
      AND status NOT IN ('cancelled')
");
$stmt->execute();
$booked_slots = $stmt->fetchAll();

// Index as [counselor_id][date] = [time, time, ...]
$booked_index = [];
foreach ($booked_slots as $bs) {
    $booked_index[$bs['counselor_id']][$bs['appointment_date']][] = substr($bs['appointment_time'], 0, 5);
}

$flash = getFlash();

// ── Helpers ──────────────────────────────────────────────────
function statusBadge(string $s): array {
    return match($s) {
        'pending'   => ['bg' => '#FDF3DF', 'color' => '#7A4F0A', 'border' => '#FAC775', 'label' => 'Pending'],
        'confirmed' => ['bg' => '#EAF7F2', 'color' => '#2D6B57', 'border' => '#C4E8DC', 'label' => 'Confirmed'],
        'cancelled' => ['bg' => '#F7F3EE', 'color' => '#9A9891', 'border' => '#D9D3C9', 'label' => 'Cancelled'],
        'completed' => ['bg' => '#EEEDFE', 'color' => '#4B3FA0', 'border' => '#CBC6F0', 'label' => 'Completed'],
        default     => ['bg' => '#F7F3EE', 'color' => '#9A9891', 'border' => '#D9D3C9', 'label' => ucfirst($s)],
    };
}
function statusIcon(string $s): string {
    return match($s) {
        'pending'   => '⏳',
        'confirmed' => '✅',
        'cancelled' => '✗',
        'completed' => '🎓',
        default     => '•',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Appointments — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    /* ── Variables ── */
    :root {
      --sage:       #4A9B7F;
      --sage-2:     #3d8a6f;
      --sage-dark:  #2D6B57;
      --sage-mid:   #C4E8DC;
      --sage-light: #EAF7F2;
      --sand:       #F7F3EE;
      --sand-2:     #EDE8E1;
      --sand-3:     #D9D3C9;
      --white:      #FFFFFF;
      --text:       #2C2C2A;
      --text-2:     #5C5B57;
      --text-3:     #9A9891;
      --border:     #E8E4DE;
      --red:        #D64545;
      --amber:      #D4860A;
      --amber-light:#FDF3DF;
      --coral:      #D96B52;
      --coral-light:#FAECE7;
      --lav:        #7B6FC4;
      --lav-light:  #EEEDFE;
      --topbar-h:   58px;
      --sidebar-w:  230px;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      font-family: 'DM Sans', sans-serif;
      font-size: 14px; color: var(--text);
      background: var(--sand); min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }

    /* ── Topbar ── */
    .topbar {
      height: var(--topbar-h); background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; padding: 0 24px;
      position: sticky; top: 0; z-index: 200; gap: 16px;
    }
    .topbar-brand  { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark   { width: 34px; height: 34px; background: var(--sage); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; }
    .topbar-name   { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
    .topbar-school { font-size: 10px; color: var(--text-3); margin-top: 1px; }
    .topbar-right  { display: flex; align-items: center; gap: 12px; }
    .user-chip     { display: flex; align-items: center; gap: 8px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 100px; padding: 5px 14px 5px 6px; }
    .user-av       { width: 26px; height: 26px; border-radius: 50%; background: var(--sage); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; }
    .user-name     { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
    .logout-btn    { font-size: 12px; color: var(--text-3); padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: color .15s, border-color .15s; }
    .logout-btn:hover { color: var(--coral); border-color: var(--coral); }

    /* ── Layout ── */
    .layout { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: calc(100vh - var(--topbar-h)); }

    /* ── Sidebar ── */
    .sidebar {
      background: var(--white); border-right: 1px solid var(--border);
      padding: 20px 0; position: sticky; top: var(--topbar-h);
      height: calc(100vh - var(--topbar-h)); overflow-y: auto;
      display: flex; flex-direction: column;
    }
    .sb-section   { padding: 0 14px; margin-bottom: 6px; }
    .sb-label     { font-size: 9px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; padding: 0 8px; margin-bottom: 4px; display: block; }
    .nav-item     { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--sand); color: var(--text); }
    .nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .nav-icon     { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: var(--sand); }
    .nav-item.active .nav-icon { background: var(--sage-mid); }
    .nav-item.crisis-nav { color: var(--coral); }
    .nav-item.crisis-nav:hover { background: var(--coral-light); }
    .nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
    .sb-divider   { height: 1px; background: var(--border); margin: 10px 14px; }
    .sb-footer    { margin-top: auto; padding: 14px; }
    .sb-user-card { background: var(--sand); border-radius: 10px; padding: 12px; display: flex; align-items: center; gap: 10px; }
    .su-av        { width: 32px; height: 32px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .su-name      { font-size: 12px; font-weight: 500; color: var(--text); }
    .su-id        { font-size: 10px; color: var(--text-3); margin-top: 1px; }

    /* ── Main ── */
    .main { padding: 28px 28px 56px; }

    /* ── Flash ── */
    .flash { padding: 11px 16px; border-radius: 9px; font-size: 13px; margin-bottom: 20px; display: flex; align-items: center; gap: 8px; }
    .flash-success { background: var(--sage-light); border: 1px solid var(--sage-mid); color: var(--sage-dark); }
    .flash-warning { background: var(--amber-light); border: 1px solid #FAC775; color: #412402; }
    .flash-error   { background: #FBEDED; border: 1px solid #F5C6C2; color: #8B2020; }

    /* ── Page header ── */
    .page-header { margin-bottom: 24px; }
    .page-title  { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); margin-bottom: 4px; }
    .page-sub    { font-size: 13px; color: var(--text-3); line-height: 1.6; }

    /* ── Two-column grid ── */
    .page-grid { display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start; }

    /* ── Card base ── */
    .card         { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 22px; }
    .card-title   { font-size: 14px; font-weight: 600; color: var(--text); margin-bottom: 4px; }
    .card-sub     { font-size: 12px; color: var(--text-3); margin-bottom: 18px; }
    .card-divider { height: 1px; background: var(--border); margin: 18px 0; }

    /* ── Error box ── */
    .error-box { display: flex; align-items: flex-start; gap: 10px; background: #FBEDED; border: 1px solid #F5C6C2; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #7A1A1A; margin-bottom: 18px; }

    /* ── Form elements ── */
    .form-group   { margin-bottom: 16px; }
    .form-label   { display: block; font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 6px; letter-spacing: .01em; }
    .form-hint    { font-size: 11px; color: var(--text-3); margin-top: 4px; }

    .form-select, .form-input, .form-textarea {
      width: 100%; padding: 10px 12px;
      background: var(--sand); border: 1.5px solid var(--border);
      border-radius: 9px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; color: var(--text); outline: none;
      transition: border-color .15s, background .15s, box-shadow .15s;
      appearance: none; -webkit-appearance: none;
    }
    .form-select:focus, .form-input:focus, .form-textarea:focus {
      background: var(--white); border-color: var(--sage);
      box-shadow: 0 0 0 3px rgba(74,155,127,.1);
    }
    .form-select option { background: var(--white); }
    .form-textarea { resize: vertical; min-height: 90px; line-height: 1.6; }
    .select-wrap  { position: relative; }
    .select-arrow {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      pointer-events: none; color: var(--text-3); font-size: 11px;
    }

    /* ── Time slot grid ── */
    .time-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-top: 4px; }
    .time-slot-radio { display: none; }
    .time-slot-label {
      display: flex; align-items: center; justify-content: center;
      padding: 9px 6px; border-radius: 8px; border: 1.5px solid var(--border);
      font-size: 12px; font-weight: 500; color: var(--text-2);
      cursor: pointer; transition: all .12s; background: var(--white);
      text-align: center; user-select: none;
    }
    .time-slot-label:hover { border-color: var(--sage); color: var(--sage); background: var(--sage-light); }
    .time-slot-radio:checked + .time-slot-label { background: var(--sage); border-color: var(--sage); color: #fff; }
    .time-slot-label.booked { background: var(--sand-2); border-color: var(--border); color: var(--sand-3); cursor: not-allowed; text-decoration: line-through; }

    /* ── Counselor cards (clickable divs) ── */
    .counselor-grid  { display: flex; flex-direction: column; gap: 8px; margin-bottom: 16px; }
    .counselor-card  { display: flex; align-items: center; gap: 12px; padding: 13px 14px; border-radius: 10px; border: 1.5px solid var(--border); cursor: pointer; transition: all .15s; background: var(--white); user-select: none; }
    .counselor-card:hover   { border-color: var(--sage-mid); background: var(--sage-light); }
    .counselor-card.selected { border-color: var(--sage); background: var(--sage-light); }
    .counselor-av    { width: 38px; height: 38px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 13px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .counselor-name  { font-size: 13px; font-weight: 500; color: var(--text); }
    .counselor-role  { font-size: 11px; color: var(--text-3); margin-top: 1px; }
    .counselor-check { width: 20px; height: 20px; border-radius: 50%; border: 2px solid var(--border); display: flex; align-items: center; justify-content: center; font-size: 11px; color: transparent; transition: all .15s; flex-shrink: 0; }
    .counselor-card.selected .counselor-check { background: var(--sage); border-color: var(--sage); color: #fff; }

    /* ── Next / Back step buttons ── */
    .btn-next { width: 100%; padding: 13px; background: var(--sage); color: #fff; border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500; cursor: pointer; transition: background .15s, transform .1s; }
    .btn-next:hover   { background: var(--sage-2); }
    .btn-next:active  { transform: scale(.99); }
    .btn-next:disabled { background: var(--sand-3); cursor: not-allowed; }
    .btn-back-step { padding: 13px 20px; background: var(--white); color: var(--text-2); border: 1.5px solid var(--border); border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; transition: border-color .15s; white-space: nowrap; }
    .btn-back-step:hover { border-color: var(--sage); color: var(--sage); }

    /* ── Recap bar ── */
    .recap-bar { display: flex; align-items: center; gap: 10px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 9px; padding: 10px 12px; font-size: 12px; color: var(--sage-dark); font-weight: 500; }

    /* ── Submit button ── */
    .btn-book { width: 100%; padding: 13px; background: var(--sage); color: #fff; border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500; cursor: pointer; transition: background .15s, transform .1s; }
    .btn-book:hover  { background: var(--sage-2); }
    .btn-book:active { transform: scale(.99); }
    .btn-book:disabled { background: var(--sand-3); cursor: not-allowed; }

    /* ── Mini calendar ── */
    .mini-cal { margin-top: 4px; }
    .cal-nav  { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .cal-nav-btn { width: 28px; height: 28px; border: 1px solid var(--border); border-radius: 7px; background: var(--white); cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 13px; transition: border-color .12s; }
    .cal-nav-btn:hover { border-color: var(--sage); }
    .cal-month { font-size: 13px; font-weight: 600; color: var(--text); }
    .cal-grid  { display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px; }
    .cal-dow   { font-size: 10px; font-weight: 600; color: var(--text-3); text-align: center; padding: 4px 0; }
    .cal-day   { aspect-ratio: 1; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 12px; cursor: pointer; transition: all .12s; border: 1.5px solid transparent; }
    .cal-day:hover:not(.cal-disabled):not(.cal-empty) { background: var(--sage-light); border-color: var(--sage-mid); }
    .cal-day.cal-today     { font-weight: 600; color: var(--sage); }
    .cal-day.cal-selected  { background: var(--sage); color: #fff; border-color: var(--sage); font-weight: 600; }
    .cal-day.cal-disabled  { color: var(--sand-3); cursor: not-allowed; }
    .cal-day.cal-weekend   { color: var(--sand-3); cursor: not-allowed; }
    .cal-day.cal-empty     { cursor: default; }
    .cal-day.cal-has-appt  { position: relative; }
    .cal-day.cal-has-appt::after { content: ''; position: absolute; bottom: 3px; left: 50%; transform: translateX(-50%); width: 4px; height: 4px; border-radius: 50%; background: var(--sage); }
    .cal-day.cal-selected.cal-has-appt::after { background: rgba(255,255,255,.7); }

    /* ── Upcoming appointments ── */
    .appt-list { display: flex; flex-direction: column; gap: 12px; }
    .appt-card {
      display: flex; align-items: flex-start; gap: 14px;
      padding: 16px; border-radius: 12px; border: 1px solid var(--border);
      background: var(--white); transition: box-shadow .12s;
    }
    .appt-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.06); }
    .appt-date-box { background: var(--sage-light); border-radius: 10px; padding: 10px 12px; text-align: center; flex-shrink: 0; min-width: 52px; }
    .adb-month { font-size: 9px; font-weight: 600; color: var(--sage-dark); text-transform: uppercase; letter-spacing: .06em; }
    .adb-day   { font-size: 24px; font-weight: 700; color: var(--sage-dark); line-height: 1; }
    .adb-dow   { font-size: 9px; color: var(--sage); text-transform: uppercase; margin-top: 2px; }
    .appt-info { flex: 1; min-width: 0; }
    .appt-name { font-size: 14px; font-weight: 500; color: var(--text); margin-bottom: 3px; }
    .appt-time { font-size: 12px; color: var(--text-3); margin-bottom: 6px; }
    .appt-purpose { font-size: 12px; color: var(--text-2); font-style: italic; background: var(--sand); border-radius: 6px; padding: 6px 10px; margin-bottom: 8px; line-height: 1.5; }
    .appt-notes   { font-size: 12px; color: var(--sage-dark); background: var(--sage-light); border-radius: 6px; padding: 6px 10px; margin-bottom: 8px; line-height: 1.5; }
    .appt-actions { display: flex; gap: 8px; }
    .appt-status  { align-self: flex-start; font-size: 11px; font-weight: 500; padding: 4px 10px; border-radius: 20px; border: 1px solid; white-space: nowrap; flex-shrink: 0; }
    .btn-cancel-appt { font-size: 11px; padding: 5px 12px; border-radius: 7px; border: 1px solid #F5C6C2; background: var(--white); color: var(--red); cursor: pointer; font-family: 'DM Sans', sans-serif; transition: background .12s; }
    .btn-cancel-appt:hover { background: #FBEDED; }

    /* Past appointments table */
    .past-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .past-table th { font-size: 10px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: .04em; padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: left; background: var(--sand); }
    .past-table td { padding: 12px; border-bottom: 1px solid var(--sand-2); vertical-align: middle; }
    .past-table tr:last-child td { border-bottom: none; }
    .past-table tr:hover td { background: var(--sand); }

    /* ── Empty state ── */
    .empty-state { text-align: center; padding: 40px 24px; }
    .es-icon  { font-size: 40px; margin-bottom: 10px; }
    .es-title { font-size: 15px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
    .es-sub   { font-size: 12px; color: var(--text-3); }

    /* ── Info tip ── */
    .info-tip { display: flex; gap: 10px; align-items: flex-start; background: var(--lav-light); border: 1px solid #CBC6F0; border-radius: 10px; padding: 12px 14px; font-size: 12px; color: var(--lav); line-height: 1.6; }
    .info-tip-icon { font-size: 16px; flex-shrink: 0; }

    /* Cancel confirm overlay */
    .confirm-overlay { display: none; position: fixed; inset: 0; z-index: 500; background: rgba(0,0,0,.4); backdrop-filter: blur(2px); align-items: center; justify-content: center; }
    .confirm-overlay.show { display: flex; }
    .confirm-box { background: var(--white); border-radius: 16px; padding: 28px; max-width: 380px; width: 90%; box-shadow: 0 20px 60px rgba(0,0,0,.15); }
    .cb-icon  { font-size: 36px; margin-bottom: 10px; }
    .cb-title { font-family: 'DM Serif Display', serif; font-size: 20px; margin-bottom: 6px; }
    .cb-body  { font-size: 13px; color: var(--text-3); margin-bottom: 20px; line-height: 1.6; }
    .cb-detail { font-size: 13px; font-weight: 500; color: var(--text); background: var(--sand); border-radius: 8px; padding: 10px 12px; margin-bottom: 18px; }
    .cb-btns  { display: flex; gap: 10px; }
    .cb-keep  { flex: 1; padding: 11px; background: var(--sand); border: 1.5px solid var(--border); border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; cursor: pointer; }
    .cb-confirm-cancel { flex: 1; padding: 11px; background: var(--red); color: #fff; border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; display: flex; align-items: center; justify-content: center; transition: background .15s; }
    .cb-confirm-cancel:hover { background: #B83030; }

    /* ── Step indicator ── */
    .steps-row       { display: flex; align-items: center; gap: 0; margin-bottom: 22px; }
    .step-item       { display: flex; align-items: center; gap: 8px; flex: 1; }
    .step-item:last-child { flex: none; }
    .step-circle     { width: 28px; height: 28px; border-radius: 50%; border: 2px solid var(--border); background: var(--white); display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; color: var(--text-3); flex-shrink: 0; transition: all .2s; }
    .step-circle.done   { background: var(--sage); border-color: var(--sage); color: #fff; }
    .step-circle.active { background: var(--white); border-color: var(--sage); color: var(--sage); }
    .step-label      { font-size: 11px; color: var(--text-3); white-space: nowrap; transition: color .2s; }
    .step-label.active { color: var(--sage); font-weight: 600; }
    .step-label.done   { color: var(--sage-dark); }
    .step-line       { flex: 1; height: 2px; background: var(--border); margin: 0 6px; transition: background .2s; }
    .step-line.done  { background: var(--sage); }

    /* ── Booking summary card ── */
    .summary-panel {
      display: none;
      background: var(--sage-light); border: 1.5px solid var(--sage-mid);
      border-radius: 12px; padding: 18px; margin-bottom: 16px;
    }
    .summary-panel.show { display: block; }
    .summary-title  { font-size: 13px; font-weight: 600; color: var(--sage-dark); margin-bottom: 12px; display: flex; align-items: center; gap: 6px; }
    .summary-row    { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; font-size: 13px; }
    .summary-row:last-child { margin-bottom: 0; }
    .summary-icon   { font-size: 15px; flex-shrink: 0; width: 20px; text-align: center; }
    .summary-val    { color: var(--text); font-weight: 500; }
    .summary-key    { color: var(--text-3); font-size: 11px; }

    /* ── Form section headers ── */
    .form-section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--text-3); margin-bottom: 10px; margin-top: 4px; padding-bottom: 6px; border-bottom: 1px solid var(--border); }

    @media (max-width: 1100px) { .page-grid { grid-template-columns: 1fr; } }
    @media (max-width: 900px)  { .layout { grid-template-columns: 1fr; }  }
  
/* ── Hamburger button ── */
.hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand,#F7F3EE);border:1px solid var(--border,#E8E4DE);cursor:pointer;padding:0;flex-shrink:0;}
.hamburger span{display:block;width:18px;height:2px;background:var(--text-2,#5C5B57);border-radius:2px;transition:all .25s ease;}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}

/* ── Sidebar overlay ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex;}
  .layout{grid-template-columns:1fr;}
  .sidebar{position:fixed;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px);width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);overflow-y:auto;}
  .sidebar.mobile-open{transform:translateX(0);}
  .main{padding:20px 16px;}
}

</style>
</head>
<body>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
<!-- ── Topbar ── -->
<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
  <div class="topbar-brand">
    <div class="topbar-mark">M</div>
    <div>
      <div class="topbar-name">CTU MindCare</div>
      <div class="topbar-school">CTU Consolacion</div>
    </div>
  </div>
  <div class="topbar-right">
    <a class="user-chip" href="profile.php">
      <div class="user-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
      <span class="user-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php">Sign out</a>
  </div>
</header>

<div class="layout">

  <!-- ── Sidebar ── -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
      <a class="nav-item" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
      <a class="nav-item" href="journal.php"><div class="nav-icon">📓</div> Wellness journal</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Support</span>
      <a class="nav-item active" href="appointments.php"><div class="nav-icon">📅</div> Book appointment</a>
      <a class="nav-item" href="counselor-chat.php"><div class="nav-icon">💬</div> Counselor chat</a>
      <a class="nav-item" href="resources.php"><div class="nav-icon">📚</div> Resource library</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Community</span>
      <a class="nav-item" href="forum.php"><div class="nav-icon">🗣️</div> Peer forum</a>
      <a class="nav-item" href="peer-chat.php"><div class="nav-icon">💭</div> Peer chat</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
    </div>
    <div class="sb-footer">
      <div class="sb-user-card">
        <div class="su-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Main ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <div class="page-header">
      <div class="page-title">Book an Appointment</div>
      <div class="page-sub">Schedule a one-on-one session with a wellness counselor. Sessions are confidential.</div>
    </div>

    <div class="page-grid">

      <!-- ══ LEFT: upcoming + history ═══════════════════════════ -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Upcoming -->
        <div class="card">
          <div class="card-title">Upcoming appointments</div>
          <div class="card-sub"><?= count($upcoming) ?> scheduled</div>

          <?php if (!empty($upcoming)): ?>
            <div class="appt-list">
              <?php foreach ($upcoming as $a):
                $badge = statusBadge($a['status']);
                $is_cancellable = in_array($a['status'], ['pending','confirmed']) && strtotime($a['appointment_date']) > strtotime('today');
              ?>
                <div class="appt-card">
                  <div class="appt-date-box">
                    <div class="adb-month"><?= date('M', strtotime($a['appointment_date'])) ?></div>
                    <div class="adb-day"><?= date('j', strtotime($a['appointment_date'])) ?></div>
                    <div class="adb-dow"><?= date('D', strtotime($a['appointment_date'])) ?></div>
                  </div>
                  <div class="appt-info">
                    <div class="appt-name">
                      <?= statusIcon($a['status']) ?> <?= clean($a['c_first'] . ' ' . $a['c_last']) ?>
                    </div>
                    <div class="appt-time">
                      🕐 <?= date('g:i A', strtotime($a['appointment_time'])) ?>
                      &nbsp;·&nbsp; <?= date('l, F j, Y', strtotime($a['appointment_date'])) ?>
                    </div>
                    <?php if ($a['purpose']): ?>
                      <div class="appt-purpose">"<?= clean($a['purpose']) ?>"</div>
                    <?php endif; ?>
                    <?php if ($a['counselor_notes'] && $a['status'] === 'confirmed'): ?>
                      <div class="appt-notes">📝 Counselor note: <?= clean($a['counselor_notes']) ?></div>
                    <?php endif; ?>
                    <?php if ($is_cancellable): ?>
                      <div class="appt-actions">
                        <button
                          class="btn-cancel-appt"
                          onclick="confirmCancel(<?= $a['id'] ?>, '<?= date('F j, Y', strtotime($a['appointment_date'])) ?> at <?= date('g:i A', strtotime($a['appointment_time'])) ?> with <?= clean($a['c_first'].' '.$a['c_last']) ?>')"
                        >Cancel appointment</button>
                      </div>
                    <?php endif; ?>
                  </div>
                  <div class="appt-status" style="background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;border-color:<?= $badge['border'] ?>">
                    <?= $badge['label'] ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="empty-state">
              <div class="es-icon">📅</div>
              <div class="es-title">No upcoming appointments</div>
              <div class="es-sub">Book a session with a counselor using the form on the right.</div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Past / history -->
        <?php if (!empty($past)): ?>
        <div class="card">
          <div class="card-title">Appointment history</div>
          <div class="card-sub"><?= count($past) ?> past session<?= count($past) !== 1 ? 's' : '' ?></div>
          <div style="overflow-x:auto">
            <table class="past-table">
              <thead>
                <tr>
                  <th>Date & time</th>
                  <th>Counselor</th>
                  <th>Purpose</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($past as $a):
                  $badge = statusBadge($a['status']);
                ?>
                  <tr>
                    <td>
                      <div style="font-weight:500;color:var(--text)"><?= date('M j, Y', strtotime($a['appointment_date'])) ?></div>
                      <div style="font-size:11px;color:var(--text-3)"><?= date('g:i A', strtotime($a['appointment_time'])) ?></div>
                    </td>
                    <td style="font-size:13px;color:var(--text)"><?= clean($a['c_first'].' '.$a['c_last']) ?></td>
                    <td style="font-size:12px;color:var(--text-3);max-width:180px">
                      <?= $a['purpose'] ? clean(mb_substr($a['purpose'], 0, 80)) . (mb_strlen($a['purpose']) > 80 ? '…' : '') : '—' ?>
                    </td>
                    <td>
                      <span style="font-size:11px;font-weight:500;padding:3px 10px;border-radius:20px;border:1px solid;background:<?= $badge['bg'] ?>;color:<?= $badge['color'] ?>;border-color:<?= $badge['border'] ?>">
                        <?= statusIcon($a['status']) ?> <?= $badge['label'] ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>

        <!-- Info tip -->
        <div class="info-tip">
          <div class="info-tip-icon">💜</div>
          <div>
            <strong>All sessions are confidential.</strong> Your counselor will not share session details with anyone outside the wellness team without your consent, except in cases where safety is a concern.
          </div>
        </div>

      </div>

      <!-- ══ RIGHT: booking form ══════════════════════════════════ -->
      <div>
        <div class="card">
          <div class="card-title">Schedule a session</div>
          <div class="card-sub">Available Monday – Friday, 9:00 AM – 5:00 PM</div>

          <?php if ($error): ?>
            <div class="error-box">⚠ <?= clean($error) ?></div>
          <?php endif; ?>

          <?php if (empty($counselors)): ?>
            <div class="empty-state">
              <div class="es-icon">👤</div>
              <div class="es-title">No counselors available</div>
              <div class="es-sub">Please check back later or use Crisis Help for urgent concerns.</div>
            </div>
          <?php else: ?>

          <!-- Step indicator -->
          <div class="steps-row">
            <div class="step-item">
              <div class="step-circle active" id="sc1">1</div>
              <span class="step-label active" id="sl1">Counselor</span>
            </div>
            <div class="step-line" id="sline1"></div>
            <div class="step-item">
              <div class="step-circle" id="sc2">2</div>
              <span class="step-label" id="sl2">Date &amp; Time</span>
            </div>
            <div class="step-line" id="sline2"></div>
            <div class="step-item">
              <div class="step-circle" id="sc3">3</div>
              <span class="step-label" id="sl3">Confirm</span>
            </div>
          </div>

          <form method="POST" action="appointments.php" id="bookForm">
            <!-- hidden fields carried across steps -->
            <input type="hidden" name="counselor_id"       id="hidCounselorId">
            <input type="hidden" name="appointment_date"   id="hidDate">
            <input type="hidden" name="appointment_time"   id="hidTime">
            <input type="hidden" name="purpose"            id="hidPurpose">

            <!-- ══ STEP 1: Choose counselor ══════════════════════ -->
            <div id="step1panel">
              <div class="form-section-title">Who would you like to see?</div>
              <div class="counselor-grid" id="counselorGrid">
                <?php foreach ($counselors as $c):
                  $initials = strtoupper(substr($c['first_name'],0,1).substr($c['last_name'],0,1));
                  $preselected = (isset($_POST['counselor_id']) && (int)$_POST['counselor_id'] === (int)$c['id']);
                ?>
                  <div class="counselor-card <?= $preselected ? 'selected' : '' ?>"
                       data-id="<?= $c['id'] ?>"
                       data-name="<?= clean($c['first_name'].' '.$c['last_name']) ?>"
                       onclick="selectCounselor(this)">
                    <div class="counselor-av"><?= $initials ?></div>
                    <div style="flex:1">
                      <div class="counselor-name"><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                      <div class="counselor-role">Wellness Counselor</div>
                    </div>
                    <div class="counselor-check" id="chk_<?= $c['id'] ?>">✓</div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="btn-next" id="btnNext1" onclick="goStep(2)" disabled>
                Next: Pick a date &amp; time →
              </button>
            </div>

            <!-- ══ STEP 2: Date & time ════════════════════════════ -->
            <div id="step2panel" style="display:none">
              <!-- Selected counselor recap -->
              <div class="recap-bar" id="recapCounselor"></div>

              <div class="form-section-title" style="margin-top:16px">Pick a date</div>
              <div class="mini-cal" id="miniCal"></div>
              <div class="form-hint" id="dateHint" style="margin:6px 0 16px">
                <?php if (!empty($_POST['appointment_date'])): ?>
                  Selected: <strong><?= date('l, F j, Y', strtotime($_POST['appointment_date'])) ?></strong>
                <?php else: ?>
                  Click any weekday to select it.
                <?php endif; ?>
              </div>

              <div class="form-section-title">Pick a time slot</div>
              <div class="time-grid" id="timeGrid">
                <?php foreach ($time_slots as $ts): ?>
                  <div>
                    <input class="time-slot-radio" type="radio" name="_time_vis"
                           id="ts_<?= str_replace(':', '', $ts) ?>" value="<?= $ts ?>"
                           <?= (!empty($_POST['appointment_time']) && $_POST['appointment_time'] === $ts) ? 'checked' : '' ?>
                           onchange="onTimeChange('<?= $ts ?>')">
                    <label class="time-slot-label" for="ts_<?= str_replace(':', '', $ts) ?>">
                      <?= date('g:i A', strtotime($ts)) ?>
                    </label>
                  </div>
                <?php endforeach; ?>
              </div>
              <div class="form-hint" style="margin-top:6px">Greyed-out slots are already taken for this counselor &amp; date.</div>

              <div style="display:flex;gap:10px;margin-top:18px">
                <button type="button" class="btn-back-step" onclick="goStep(1)">← Back</button>
                <button type="button" class="btn-next" id="btnNext2" onclick="goStep(3)" disabled style="flex:1">
                  Next: Review →
                </button>
              </div>
            </div>

            <!-- ══ STEP 3: Purpose + confirm ═════════════════════ -->
            <div id="step3panel" style="display:none">

              <!-- Full summary -->
              <div class="summary-panel show" id="summaryPanel">
                <div class="summary-title">📋 Appointment summary</div>
                <div class="summary-row">
                  <span class="summary-icon">👤</span>
                  <div><div class="summary-key">Counselor</div><div class="summary-val" id="sum-counselor">—</div></div>
                </div>
                <div class="summary-row">
                  <span class="summary-icon">📅</span>
                  <div><div class="summary-key">Date</div><div class="summary-val" id="sum-date">—</div></div>
                </div>
                <div class="summary-row">
                  <span class="summary-icon">🕐</span>
                  <div><div class="summary-key">Time</div><div class="summary-val" id="sum-time">—</div></div>
                </div>
              </div>

              <div class="form-group" style="margin-top:16px">
                <label class="form-label" for="purposeInput">
                  Reason for visit
                  <span style="font-weight:400;color:var(--text-3)">(optional)</span>
                </label>
                <textarea
                  class="form-textarea"
                  id="purposeInput"
                  placeholder="e.g. academic stress, anxiety, general wellness check-in…"
                  maxlength="500"
                ><?= clean($_POST['purpose'] ?? '') ?></textarea>
                <div class="form-hint">Visible only to your counselor. Helps them prepare.</div>
              </div>

              <div class="info-tip" style="margin-bottom:16px">
                <div class="info-tip-icon">🔒</div>
                <div>Your session is <strong>confidential</strong>. Details won't be shared without your consent, except where safety is a concern.</div>
              </div>

              <div style="display:flex;gap:10px">
                <button type="button" class="btn-back-step" onclick="goStep(2)">← Back</button>
                <button type="submit" class="btn-book" id="bookBtn" style="flex:1" onclick="prepareSubmit()">
                  📅 &nbsp;Confirm &amp; book appointment
                </button>
              </div>
            </div>

          </form>

          <?php endif; ?>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- Cancel confirm modal -->
<div class="confirm-overlay" id="confirmOverlay">
  <div class="confirm-box">
    <div class="cb-icon">📅</div>
    <div class="cb-title">Cancel appointment?</div>
    <div class="cb-body">Are you sure you want to cancel this appointment?</div>
    <div class="cb-detail" id="cancelDetail"></div>
    <div class="cb-btns">
      <button class="cb-keep" onclick="hideConfirm()">Keep it</button>
      <a class="cb-confirm-cancel" id="cancelConfirmBtn" href="#">Yes, cancel</a>
    </div>
  </div>
</div>

<script>
// ── State ─────────────────────────────────────────────────────
const bookedIndex = <?= json_encode($booked_index) ?>;
let selCounselorId   = '<?= clean($_POST['counselor_id'] ?? '') ?>';
let selCounselorName = '';
let selDate          = '<?= clean($_POST['appointment_date'] ?? '') ?>';
let selTime          = '<?= clean($_POST['appointment_time'] ?? '') ?>';
let calYear  = <?= date('Y') ?>;
let calMonth = <?= date('n') - 1 ?>;
const todayDate = new Date(<?= date('Y') ?>, <?= date('n') - 1 ?>, <?= date('j') ?>);

// Only include active appointments (not cancelled/completed) for calendar dots
const myApptDates = new Set(<?= json_encode(array_values(array_map(
    fn($a) => $a['appointment_date'],
    array_filter($all_appointments, fn($a) => !in_array($a['status'], ['cancelled', 'completed']))
))) ?>);

// ── Step navigation ───────────────────────────────────────────
function goStep(n) {
  if (n === 2 && !selCounselorId) return;
  if (n === 3 && (!selDate || !selTime)) return;

  document.getElementById('step1panel').style.display = n === 1 ? 'block' : 'none';
  document.getElementById('step2panel').style.display = n === 2 ? 'block' : 'none';
  document.getElementById('step3panel').style.display = n === 3 ? 'block' : 'none';

  // Update step indicator
  for (let i = 1; i <= 3; i++) {
    const circle = document.getElementById('sc' + i);
    const label  = document.getElementById('sl' + i);
    circle.classList.remove('done','active');
    label.classList.remove('done','active');
    if (i < n)      { circle.classList.add('done');   circle.textContent = '✓'; label.classList.add('done'); }
    else if (i === n){ circle.classList.add('active'); circle.textContent = i;   label.classList.add('active'); }
    else             { circle.textContent = i; }
  }
  for (let i = 1; i <= 2; i++) {
    const line = document.getElementById('sline' + i);
    if (line) line.classList.toggle('done', i < n);
  }

  if (n === 2) {
    // Show recap of chosen counselor
    const recap = document.getElementById('recapCounselor');
    if (recap) recap.innerHTML = `<span style="font-size:16px">👤</span> <span>Seeing <strong>${selCounselorName}</strong></span>`;
    renderCalendar();
    updateSlots();
  }

  if (n === 3) {
    // Populate summary
    document.getElementById('sum-counselor').textContent = selCounselorName;
    const d = new Date(selDate + 'T00:00:00');
    document.getElementById('sum-date').textContent =
      d.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
    const [h, m] = selTime.split(':');
    const hr = parseInt(h);
    document.getElementById('sum-time').textContent =
      `${hr % 12 || 12}:${m} ${hr >= 12 ? 'PM' : 'AM'}`;
  }
}

// ── Counselor selection ───────────────────────────────────────
function selectCounselor(card) {
  document.querySelectorAll('.counselor-card').forEach(c => c.classList.remove('selected'));
  card.classList.add('selected');
  selCounselorId   = card.dataset.id;
  selCounselorName = card.dataset.name;
  document.getElementById('hidCounselorId').value = selCounselorId;
  const btn = document.getElementById('btnNext1');
  if (btn) btn.disabled = false;
}

// ── Date selection ────────────────────────────────────────────
function selectDate(dateStr) {
  selDate = dateStr;
  document.getElementById('hidDate').value = dateStr;
  const d = new Date(dateStr + 'T00:00:00');
  document.getElementById('dateHint').innerHTML =
    'Selected: <strong>' + d.toLocaleDateString('en-US',
      { weekday:'long', year:'numeric', month:'long', day:'numeric' }) + '</strong>';
  renderCalendar();
  updateSlots();
  checkStep2Ready();
}

// ── Time selection ────────────────────────────────────────────
function onTimeChange(timeVal) {
  selTime = timeVal;
  document.getElementById('hidTime').value = timeVal;
  checkStep2Ready();
}

function checkStep2Ready() {
  const btn = document.getElementById('btnNext2');
  if (btn) btn.disabled = !(selDate && selTime);
}

// ── Prepare hidden fields before submit ───────────────────────
function prepareSubmit() {
  const purposeInput = document.getElementById('purposeInput');
  if (purposeInput) {
    document.getElementById('hidPurpose').value = purposeInput.value;
  }
}

// ── Calendar ──────────────────────────────────────────────────
function renderCalendar() {
  const cal = document.getElementById('miniCal');
  if (!cal) return;
  const selDateObj = selDate ? new Date(selDate + 'T00:00:00') : null;
  const days   = ['Mo','Tu','We','Th','Fr','Sa','Su'];
  const months = ['January','February','March','April','May','June','July',
                  'August','September','October','November','December'];

  const firstDay = new Date(calYear, calMonth, 1);
  const lastDay  = new Date(calYear, calMonth + 1, 0);
  let startDow   = firstDay.getDay();
  startDow = startDow === 0 ? 6 : startDow - 1;

  let html = `
    <div class="cal-nav">
      <button type="button" class="cal-nav-btn" onclick="calPrev()">&#8249;</button>
      <span class="cal-month">${months[calMonth]} ${calYear}</span>
      <button type="button" class="cal-nav-btn" onclick="calNext()">&#8250;</button>
    </div>
    <div class="cal-grid">
  `;
  days.forEach(d => { html += `<div class="cal-dow">${d}</div>`; });
  for (let i = 0; i < startDow; i++) html += `<div class="cal-day cal-empty"></div>`;

  for (let d = 1; d <= lastDay.getDate(); d++) {
    const thisDate  = new Date(calYear, calMonth, d);
    const dateStr   = `${calYear}-${String(calMonth+1).padStart(2,'0')}-${String(d).padStart(2,'0')}`;
    const isToday   = thisDate.toDateString() === todayDate.toDateString();
    const isPast    = thisDate < todayDate;
    const isWeekend = thisDate.getDay() === 0 || thisDate.getDay() === 6;
    const isSel     = selDateObj && thisDate.toDateString() === selDateObj.toDateString();
    const hasAppt   = myApptDates.has(dateStr);
    const clickable = !isPast && !isWeekend;

    let cls = 'cal-day';
    if (isPast || isWeekend) cls += ' cal-disabled' + (isWeekend ? ' cal-weekend' : '');
    if (isToday) cls += ' cal-today';
    if (isSel)   cls += ' cal-selected';
    if (hasAppt) cls += ' cal-has-appt';

    html += `<div class="${cls}" ${clickable ? `onclick="selectDate('${dateStr}')"` : ''}>${d}</div>`;
  }
  html += '</div>';
  cal.innerHTML = html;
}

function calPrev() {
  if (calMonth === 0) { calMonth = 11; calYear--; } else calMonth--;
  const now = new Date();
  if (calYear < now.getFullYear() || (calYear === now.getFullYear() && calMonth < now.getMonth())) {
    calYear = now.getFullYear(); calMonth = now.getMonth();
  }
  renderCalendar();
}
function calNext() {
  if (calMonth === 11) { calMonth = 0; calYear++; } else calMonth++;
  renderCalendar();
}

// ── Grey out booked time slots ────────────────────────────────
function updateSlots() {
  const booked = (selCounselorId && selDate &&
                  bookedIndex[selCounselorId] && bookedIndex[selCounselorId][selDate])
    ? bookedIndex[selCounselorId][selDate] : [];

  document.querySelectorAll('.time-slot-radio').forEach(input => {
    const label = input.nextElementSibling;
    const tval  = input.value.substring(0, 5);
    const isBooked = booked.includes(tval);
    if (label) label.classList.toggle('booked', isBooked);
    input.disabled = isBooked;
    if (isBooked && input.checked) { input.checked = false; selTime = ''; checkStep2Ready(); }
  });
}

// ── Cancel confirm modal ──────────────────────────────────────
function confirmCancel(id, detail) {
  document.getElementById('cancelDetail').textContent = detail;
  document.getElementById('cancelConfirmBtn').href = `appointments.php?action=cancel&id=${id}`;
  document.getElementById('confirmOverlay').classList.add('show');
}
function hideConfirm() {
  document.getElementById('confirmOverlay').classList.remove('show');
}
const confirmOverlay = document.getElementById('confirmOverlay');
if (confirmOverlay) {
  confirmOverlay.addEventListener('click', e => { if (e.target === confirmOverlay) hideConfirm(); });
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') hideConfirm(); });

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Restore counselor name from preselected card
  const preCard = document.querySelector('.counselor-card.selected');
  if (preCard) {
    selCounselorId   = preCard.dataset.id;
    selCounselorName = preCard.dataset.name;
    const hidCounselor = document.getElementById('hidCounselorId');
    if (hidCounselor) hidCounselor.value = selCounselorId;
    const btnNext1 = document.getElementById('btnNext1');
    if (btnNext1) btnNext1.disabled = false;
  }

  // If returning after POST error, restore to the furthest step
  if (selCounselorId && selDate && selTime) {
    const hidDate = document.getElementById('hidDate');
    const hidTime = document.getElementById('hidTime');
    if (hidDate) hidDate.value = selDate;
    if (hidTime) hidTime.value = selTime;
    // Mark pre-selected time radio
    document.querySelectorAll('.time-slot-radio').forEach(r => {
      if (r.value === selTime) r.checked = true;
    });
    goStep(3);
  } else if (selCounselorId && selDate) {
    const hidDate = document.getElementById('hidDate');
    if (hidDate) hidDate.value = selDate;
    const d = new Date(selDate + 'T00:00:00');
    calYear  = d.getFullYear();
    calMonth = d.getMonth();
    goStep(2);
  } else if (selCounselorId) {
    goStep(1);
  }
});
</script>

<script>
(function(){
  var btn = document.getElementById('hamburgerBtn');
  var sb  = document.querySelector('.sidebar');
  var ov  = document.getElementById('sidebarOverlay');
  if(!btn||!sb||!ov) return;
  function open(){sb.classList.add('mobile-open');ov.classList.add('active');btn.classList.add('open');btn.setAttribute('aria-expanded','true');document.body.style.overflow='hidden';}
  function close(){sb.classList.remove('mobile-open');ov.classList.remove('active');btn.classList.remove('open');btn.setAttribute('aria-expanded','false');document.body.style.overflow='';}
  btn.addEventListener('click',function(){sb.classList.contains('mobile-open')?close():open();});
  ov.addEventListener('click',close);
  sb.querySelectorAll('.nav-item').forEach(function(l){l.addEventListener('click',function(){if(window.innerWidth<=900)close();});});
  window.addEventListener('resize',function(){if(window.innerWidth>900)close();});
})();
</script>
</body>
</html>