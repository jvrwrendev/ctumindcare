<?php
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

// Check if already checked in today
$stmt = $db->prepare("SELECT id, mood_level, mood_note FROM mood_entries WHERE user_id = ? AND DATE(recorded_at) = CURDATE()");
$stmt->execute([$user['id']]);
$already_done = $stmt->fetch();

$error = '';

// ── Check last 3 days of mood (for the 3-day low streak banner) ──────────
$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day, mood_level
    FROM mood_entries
    WHERE user_id = ?
      AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 3 DAY)
    ORDER BY recorded_at DESC
");
$stmt->execute([$user['id']]);
$recent_3 = $stmt->fetchAll();

// Build map of last 3 days (excluding today)
$low_streak = 0;
$streak_days_checked = 0;
for ($i = 1; $i <= 3; $i++) {
    $check_date = date('Y-m-d', strtotime("-{$i} days"));
    $found = false;
    foreach ($recent_3 as $r) {
        if ($r['day'] === $check_date) {
            $streak_days_checked++;
            if ((int)$r['mood_level'] <= 2) {
                $low_streak++;
            } else {
                break 2; // streak broken
            }
            $found = true;
            break;
        }
    }
    if (!$found) break; // no entry that day = streak broken
}
$show_3day_banner = ($low_streak >= 2); // 2+ previous low days = warn on today's check-in

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$already_done) {
    $level = (int)($_POST['mood_level'] ?? 0);
    $note  = clean($_POST['mood_note'] ?? '');

    if ($level < 1 || $level > 5) {
        $error = 'Please select how you are feeling today.';
    } else {
        $stmt = $db->prepare("INSERT INTO mood_entries (user_id, mood_level, mood_note) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $level, $note ?: null]);

        $stmt = $db->prepare("
            SELECT COUNT(*) as cnt
            FROM mood_entries
            WHERE user_id = ?
              AND mood_level <= ?
              AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
        ");
        $stmt->execute([$user['id'], MOOD_RISK_LEVEL, MOOD_RISK_DAYS]);
        $risk_count = (int)$stmt->fetch()['cnt'];

        if ($risk_count >= MOOD_RISK_DAYS) {
            $stmt = $db->prepare("
                SELECT id FROM assessment_results
                WHERE user_id = ? AND triggered_by = 'auto_mood'
                AND taken_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ");
            $stmt->execute([$user['id']]);

            if (!$stmt->fetch()) {
                $stmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'assessment_due', ?)");
                $stmt->execute([
                    $user['id'],
                    "Your mood has been low for {$risk_count} days in a row. We'd like you to take a short wellness assessment so we can better support you."
                ]);
                setFlash('warning', "We noticed your mood has been low recently. Please take a short wellness assessment.");
                header('Location: assessment.php?trigger=auto');
                exit;
            }
        }

        setFlash('success', 'Mood recorded! Keep checking in daily.');
        header('Location: dashboard.php');
        exit;
    }
}

$stmt = $db->prepare("
    SELECT DATE(recorded_at) as day, mood_level
    FROM mood_entries
    WHERE user_id = ? AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    ORDER BY recorded_at ASC
");
$stmt->execute([$user['id']]);
$week_rows = $stmt->fetchAll();

$week_map = [];
foreach ($week_rows as $r) $week_map[$r['day']] = (int)$r['mood_level'];

$mood_labels = [1=>'Very Low', 2=>'Low', 3=>'Okay', 4=>'Good', 5=>'Great'];
$mood_emoji  = [1=>'😔', 2=>'😟', 3=>'😐', 4=>'🙂', 5=>'😊'];

$flash = getFlash();

// ── Motivational quotes pool ──────────────────────────────────────────────
$quotes_low = [
    ["quote" => "You don't have to be positive all the time. It's perfectly okay to feel sad, angry, annoyed, frustrated, scared, or anxious.", "author" => "Lori Deschene"],
    ["quote" => "Even the darkest night will end and the sun will rise.", "author" => "Victor Hugo"],
    ["quote" => "You are allowed to be both a masterpiece and a work in progress simultaneously.", "author" => "Sophia Bush"],
    ["quote" => "What you are going through is hard. But you have gotten through hard things before.", "author" => "Unknown"],
    ["quote" => "Be gentle with yourself. You are a child of the universe, no less than the trees and the stars.", "author" => "Max Ehrmann"],
    ["quote" => "It's okay to not be okay — as long as you don't give up.", "author" => "Unknown"],
    ["quote" => "Tough times never last, but tough people do.", "author" => "Robert H. Schuller"],
    ["quote" => "You are stronger than you know, braver than you believe, and more loved than you can imagine.", "author" => "Unknown"],
    ["quote" => "The sun is a daily reminder that we too can rise again from the darkness.", "author" => "S. Ajna"],
    ["quote" => "Healing is not linear. Some days will be harder than others, and that is okay.", "author" => "Unknown"],
];

// 3-day streak specific quotes (more supportive)
$quotes_streak = [
    ["quote" => "Sometimes the bravest thing you can do is ask for help. You don't have to carry this alone.", "author" => "CTU MindCare"],
    ["quote" => "Three days of struggle doesn't mean a lifetime of pain. Reach out — someone is here for you.", "author" => "CTU MindCare"],
    ["quote" => "It takes courage to keep showing up when things are hard. You showed up today — that matters.", "author" => "CTU MindCare"],
    ["quote" => "Your feelings are valid. Your pain is real. And so is the support waiting for you.", "author" => "CTU MindCare"],
    ["quote" => "You've been strong for so long. Let someone be strong for you now.", "author" => "Unknown"],
];

// Pick a deterministic quote based on day of year so it feels "fresh" daily
$day_index = (int)date('z'); // 0-365
$quote_low    = $quotes_low[$day_index % count($quotes_low)];
$quote_streak = $quotes_streak[$day_index % count($quotes_streak)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Daily Mood Check-In — CTU MindCare</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
  <style>
    /* ── CSS Variables ── */
    :root {
      --sage:        #4A9B7F;
      --sage-2:      #3d8a6f;
      --sage-dark:   #2D6B57;
      --sage-mid:    #C4E8DC;
      --sage-light:  #EAF7F2;
      --sand:        #F9F7F4;
      --sand-2:      #F3EFE9;
      --sand-3:      #D9D3C9;
      --white:       #FFFFFF;
      --text:        #1A1A1A;
      --text-2:      #4A4A4A;
      --text-3:      #8A8A8A;
      --border:      #E8E3DC;
      --red:         #E24B4A;
      --amber:       #D4860A;
      --lav:         #7B6FC4;
      --lav-light:   #EEEDFE;
      --coral:       #D96B52;
      --coral-light: #FAECE7;
      --sidebar-w:   240px;
      --topbar-h:    56px;
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      height: 100%;
      font-family: 'DM Sans', sans-serif;
      font-size: 14px;
      color: var(--text);
      background: var(--sand);
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }
    a:hover { text-decoration: underline; }
    button, input, textarea, select { font-family: inherit; }

    /* ── Topbar ── */
    .topbar {
      position: fixed; top: 0; left: 0; right: 0; z-index: 100;
      height: var(--topbar-h);
      background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 20px; gap: 12px;
    }
    .topbar-brand { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark {
      width: 32px; height: 32px; border-radius: 8px;
      background: var(--sage); color: #fff;
      font-weight: 700; font-size: 16px;
      display: flex; align-items: center; justify-content: center;
    }
    .topbar-name  { font-weight: 600; font-size: 14px; color: var(--text); line-height: 1.2; }
    .topbar-school{ font-size: 11px; color: var(--text-3); }
    .topbar-right { display: flex; align-items: center; gap: 12px; }
    .user-chip {
      display: flex; align-items: center; gap: 8px;
      background: var(--sand); border: 1px solid var(--border);
      border-radius: 20px; padding: 4px 12px 4px 4px;
      text-decoration: none; transition: background .15s;
    }
    .user-chip:hover { background: var(--sage-light); text-decoration: none; }
    .user-av {
      width: 26px; height: 26px; border-radius: 50%;
      background: var(--sage-mid); color: var(--sage-dark);
      font-size: 10px; font-weight: 700;
      display: flex; align-items: center; justify-content: center;
    }
    .user-name { font-size: 13px; font-weight: 500; color: var(--text); }
    .logout-btn {
      font-size: 12px; color: var(--text-3);
      padding: 6px 12px; border: 1px solid var(--border);
      border-radius: 6px; background: var(--white);
      transition: border-color .15s, color .15s;
    }
    .logout-btn:hover { border-color: var(--sage); color: var(--sage); text-decoration: none; }

    /* ── Layout ── */
    .layout {
      display: flex;
      padding-top: var(--topbar-h);
      min-height: 100vh;
    }

    /* ── Sidebar ── */
    .sidebar {
      width: var(--sidebar-w); flex-shrink: 0;
      position: fixed; top: var(--topbar-h); left: 0; bottom: 0;
      background: var(--white); border-right: 1px solid var(--border);
      overflow-y: auto; display: flex; flex-direction: column; padding: 16px 0 0;
    }
    .sb-section  { padding: 0 10px 8px; }
    .sb-label    { display: block; font-size: 10px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: var(--text-3); padding: 0 8px; margin-bottom: 4px; }
    .sb-divider  { height: 1px; background: var(--border); margin: 8px 16px; }
    .nav-item {
      display: flex; align-items: center; gap: 9px;
      padding: 8px 10px; border-radius: 8px;
      font-size: 13px; font-weight: 400; color: var(--text-2);
      cursor: pointer; transition: background .15s, color .15s;
      text-decoration: none;
    }
    .nav-item:hover      { background: var(--sand); color: var(--text); text-decoration: none; }
    .nav-item.active     { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .nav-icon            { width: 20px; text-align: center; font-size: 15px; }
    .crisis-nav          { color: var(--red) !important; }
    .crisis-nav:hover    { background: #FBEDED !important; }
    .sb-footer           { margin-top: auto; padding: 12px 10px; border-top: 1px solid var(--border); }
    .sb-user             { display: flex; align-items: center; gap: 8px; }
    .su-av { width: 30px; height: 30px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 11px; font-weight: 700; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .su-name  { font-size: 12px; font-weight: 500; color: var(--text); }
    .su-id    { font-size: 11px; color: var(--text-3); }
    .su-logout{ font-size: 11px; color: var(--text-3); margin-left: auto; }
    .su-logout:hover { color: var(--red); text-decoration: none; }

    /* ── Main ── */
    .main {
      margin-left: var(--sidebar-w); flex: 1;
      padding: 32px 24px;
      min-height: calc(100vh - var(--topbar-h));
    }
    .checkin-wrap { max-width: 620px; margin: 0 auto; }
    .page-title   { font-family: 'DM Serif Display', serif; font-size: 24px; color: var(--text); margin-bottom: 4px; }
    .page-sub     { font-size: 13px; color: var(--text-3); margin-bottom: 28px; line-height: 1.5; }

    /* ── Card ── */
    .card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; padding: 24px; }

    /* ── Flash ── */
    .flash { display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 13px; font-weight: 500; margin-bottom: 20px; }
    .flash-success { background: var(--sage-light); color: var(--sage-dark); border: 1px solid var(--sage-mid); }
    .flash-warning { background: #FDF3DF; color: #7A4F0A; border: 1px solid #FAC775; }
    .flash-error   { background: #FBEDED; color: #7A1A1A; border: 1px solid #F5C6C2; }
    .alert { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: 10px; font-size: 13px; margin-bottom: 16px; }
    .alert-error { background: #FBEDED; color: #7A1A1A; border: 1px solid #F5C6C2; }

    /* ── 3-Day Low Streak Banner ── */
    .streak-banner {
      background: linear-gradient(135deg, #FDF3DF, #FEF9EE);
      border: 1.5px solid #FAC775;
      border-radius: 14px;
      padding: 16px 18px;
      margin-bottom: 22px;
      display: flex; align-items: flex-start; gap: 14px;
    }
    .streak-icon { font-size: 28px; flex-shrink: 0; margin-top: 2px; }
    .streak-title { font-size: 14px; font-weight: 600; color: #7A4F0A; margin-bottom: 4px; }
    .streak-body  { font-size: 13px; color: #7A4F0A; line-height: 1.6; }
    .streak-actions { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
    .streak-btn {
      padding: 7px 16px; border-radius: 8px; font-size: 12px; font-weight: 500;
      text-decoration: none; transition: all .15s; display: inline-flex; align-items: center; gap: 5px;
    }
    .streak-btn-primary { background: #D4860A; color: #fff; border: none; cursor: pointer; font-family: inherit; }
    .streak-btn-primary:hover { background: #B86E00; text-decoration: none; color: #fff; }
    .streak-btn-outline { background: var(--white); color: #7A4F0A; border: 1.5px solid #FAC775; }
    .streak-btn-outline:hover { background: #FDF3DF; text-decoration: none; }

    /* ── Mood grid ── */
    .mood-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin: 20px 0 28px; }
    .mood-option { display: none; }
    .mood-label {
      display: flex; flex-direction: column; align-items: center; gap: 8px;
      padding: 20px 8px; border-radius: 14px;
      border: 2px solid var(--sand-3); cursor: pointer;
      transition: all .2s; background: var(--white);
      user-select: none; position: relative;
    }
    .mood-label:hover { border-color: var(--sage); background: var(--sage-light); transform: translateY(-2px); }
    .mood-option:checked + .mood-label { border-width: 2px; transform: translateY(-2px); }
    #mood_1:checked + .mood-label { border-color: #E24B4A; background: #FBEDED; }
    #mood_2:checked + .mood-label { border-color: #D4860A; background: #FDF3DF; }
    #mood_3:checked + .mood-label { border-color: var(--sand-3); background: var(--sand-2); }
    #mood_4:checked + .mood-label { border-color: var(--sage); background: var(--sage-light); }
    #mood_5:checked + .mood-label { border-color: var(--sage-dark); background: var(--sage-mid); }
    .mood-emoji  { font-size: 36px; line-height: 1; }
    .mood-text   { font-size: 12px; font-weight: 500; color: var(--text-2); }
    .mood-num    { font-size: 10px; color: var(--text-3); }

    /* ── Note textarea ── */
    .form-group { margin-top: 20px; }
    .form-label { display: block; font-size: 13px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
    .note-area {
      width: 100%; padding: 12px 14px;
      background: var(--sand); border: 1.5px solid var(--sand-3);
      border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 14px; color: var(--text); outline: none;
      resize: vertical; min-height: 100px;
      transition: border-color .2s, box-shadow .2s, background .2s;
    }
    .note-area:focus { background: var(--white); border-color: var(--sage); box-shadow: 0 0 0 4px rgba(74,155,127,.10); }
    .note-area::placeholder { color: var(--text-3); }
    .char-count { font-size: 11px; color: var(--text-3); text-align: right; margin-top: 4px; }

    /* ── Submit row ── */
    .submit-row { display: flex; gap: 12px; margin-top: 24px; }
    .btn-back {
      padding: 13px 24px; background: var(--white); color: var(--text-2);
      border: 1.5px solid var(--sand-3); border-radius: 10px;
      font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500;
      cursor: pointer; text-decoration: none; display: inline-flex;
      align-items: center; transition: border-color .15s;
    }
    .btn-back:hover { border-color: var(--sage); color: var(--sage); text-decoration: none; }
    .btn-save {
      flex: 1; padding: 13px; background: var(--sage); color: #fff;
      border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 14px; font-weight: 500; cursor: pointer;
      transition: background .2s, transform .1s;
    }
    .btn-save:hover  { background: var(--sage-2); }
    .btn-save:active { transform: scale(.99); }
    .btn-save:disabled { background: var(--sand-3); cursor: not-allowed; }

    /* ── Already done box ── */
    .done-box     { text-align: center; padding: 48px 24px; background: var(--sage-light); border-radius: 16px; border: 1px solid var(--sage-mid); }
    .done-emoji   { font-size: 52px; margin-bottom: 16px; }
    .done-title   { font-family: 'DM Serif Display', serif; font-size: 22px; color: var(--sage-dark); margin-bottom: 8px; }
    .done-sub     { font-size: 14px; color: var(--sage); margin-bottom: 24px; }
    .done-note    { background: rgba(255,255,255,.6); border-radius: 10px; padding: 14px 16px; font-size: 13px; color: var(--text-2); font-style: italic; margin-bottom: 24px; text-align: left; line-height: 1.6; }
    .done-actions { display: flex; gap: 10px; justify-content: center; flex-wrap: wrap; }
    .btn-outline {
      padding: 10px 20px; background: var(--white); color: var(--sage-dark);
      border: 1.5px solid var(--sage); border-radius: 9px;
      font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
      cursor: pointer; text-decoration: none; transition: background .15s;
    }
    .btn-outline:hover { background: var(--sage-light); text-decoration: none; }
    .btn-solid {
      padding: 10px 20px; background: var(--sage); color: #fff;
      border: none; border-radius: 9px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; font-weight: 500; cursor: pointer;
      text-decoration: none; transition: background .15s; display: inline-block;
    }
    .btn-solid:hover { background: var(--sage-2); text-decoration: none; }

    /* ── Week strip ── */
    .week-strip     { display: flex; gap: 8px; margin-top: 28px; }
    .week-day       { flex: 1; text-align: center; }
    .week-bar-wrap  { height: 52px; display: flex; align-items: flex-end; justify-content: center; margin-bottom: 4px; }
    .week-bar       { width: 100%; border-radius: 4px 4px 0 0; min-height: 4px; transition: height .3s; }
    .week-day-label { font-size: 10px; color: var(--text-3); }
    .week-day-emoji { font-size: 12px; }

    /* ── Tip card ── */
    .tip-card  { background: var(--white); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; margin-top: 16px; display: flex; gap: 12px; align-items: flex-start; }
    .tip-icon  { font-size: 20px; flex-shrink: 0; margin-top: 1px; }
    .tip-title { font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 3px; }
    .tip-body  { font-size: 12px; color: var(--text-2); line-height: 1.6; }

    /* ══════════════════════════════════════════════════════════
       MOTIVATIONAL QUOTE POPUP
    ══════════════════════════════════════════════════════════ */
    .quote-overlay {
      display: none;
      position: fixed; inset: 0; z-index: 500;
      background: rgba(0,0,0,.45);
      backdrop-filter: blur(4px);
      align-items: center; justify-content: center;
      padding: 20px;
    }
    .quote-overlay.show { display: flex; }

    .quote-modal {
      background: var(--white);
      border-radius: 20px;
      padding: 36px 32px 28px;
      max-width: 440px; width: 100%;
      box-shadow: 0 24px 64px rgba(0,0,0,.18);
      text-align: center;
      animation: qpop .35s cubic-bezier(.34,1.56,.64,1) both;
      position: relative;
    }
    @keyframes qpop {
      from { opacity: 0; transform: scale(.85) translateY(20px); }
      to   { opacity: 1; transform: scale(1) translateY(0); }
    }

    /* Streak variant — warm amber tones */
    .quote-modal.streak-variant {
      background: linear-gradient(160deg, #FFFBF0 0%, #FFF3D6 100%);
      border: 1.5px solid #FAC775;
    }
    /* Low mood variant — soft lavender */
    .quote-modal.low-variant {
      background: linear-gradient(160deg, #F8F7FF 0%, #EEEDFE 100%);
      border: 1.5px solid #CBC6F0;
    }

    .qm-emoji  { font-size: 52px; margin-bottom: 16px; display: block; animation: bounce 1s ease-in-out; }
    @keyframes bounce { 0%,100%{transform:translateY(0)} 40%{transform:translateY(-10px)} 70%{transform:translateY(-5px)} }

    .qm-label  { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; margin-bottom: 12px; display: block; }
    .streak-variant .qm-label { color: #D4860A; }
    .low-variant    .qm-label { color: var(--lav); }

    .qm-quote {
      font-family: 'DM Serif Display', serif;
      font-style: italic;
      font-size: 19px;
      line-height: 1.55;
      margin-bottom: 12px;
      color: var(--text);
    }
    .qm-author {
      font-size: 12px;
      color: var(--text-3);
      margin-bottom: 24px;
    }
    .qm-author::before { content: '— '; }

    /* Progress dots showing days */
    .qm-days {
      display: flex; align-items: center; justify-content: center;
      gap: 8px; margin-bottom: 22px;
    }
    .qm-day-dot {
      width: 32px; height: 32px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center;
      font-size: 16px; border: 2px solid transparent;
    }
    .qm-day-dot.low     { background: #FBEDED; border-color: #F5C6C2; }
    .qm-day-dot.okay    { background: var(--sage-light); border-color: var(--sage-mid); }
    .qm-day-dot.missing { background: var(--sand-2); border-color: var(--border); color: var(--text-3); font-size: 12px; }
    .qm-day-dot.today   { border-color: var(--lav); background: var(--lav-light); }

    .qm-day-label { font-size: 9px; color: var(--text-3); margin-top: 4px; text-align: center; }
    .qm-days-col  { display: flex; flex-direction: column; align-items: center; }

    /* CTA buttons */
    .qm-actions { display: flex; flex-direction: column; gap: 8px; }
    .qm-btn-primary {
      width: 100%; padding: 13px;
      background: var(--sage); color: #fff;
      border: none; border-radius: 10px;
      font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 500;
      cursor: pointer; transition: background .15s;
      text-decoration: none; display: block;
    }
    .qm-btn-primary:hover { background: var(--sage-2); text-decoration: none; color: #fff; }
    .streak-variant .qm-btn-primary { background: #D4860A; }
    .streak-variant .qm-btn-primary:hover { background: #B86E00; }

    .qm-btn-secondary {
      width: 100%; padding: 12px;
      background: transparent; color: var(--text-3);
      border: 1.5px solid var(--border); border-radius: 10px;
      font-family: 'DM Sans', sans-serif; font-size: 13px;
      cursor: pointer; transition: all .15s;
    }
    .qm-btn-secondary:hover { border-color: var(--sage); color: var(--sage); }

    .qm-close {
      position: absolute; top: 14px; right: 16px;
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--sand-2); border: none; cursor: pointer;
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; color: var(--text-3); transition: background .12s;
    }
    .qm-close:hover { background: var(--sand-3); }

    /* ── Hamburger ── */
    .hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand);border:1px solid var(--border);cursor:pointer;padding:0;flex-shrink:0;}
    .hamburger span{display:block;width:18px;height:2px;background:var(--text-2);border-radius:2px;transition:all .25s ease;}
    .hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
    .hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
    .hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}
    .sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
    .sidebar-overlay.active{display:block;}

    @media(max-width:900px){
      .hamburger{display:flex!important;}
      .sidebar{position:fixed!important;top:56px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 56px)!important;width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);display:flex!important;}
      .sidebar.mobile-open{transform:translateX(0);}
      .main{padding:20px 16px!important;margin-left:0!important;}
      .mood-label{padding:14px 6px;}
      .mood-emoji{font-size:28px;}
    }
    @media(max-width:480px){
      .topbar{padding:0 10px;gap:8px;}
      .topbar-school{display:none;}
      .topbar-name{font-size:14px;}
      .logout-btn{display:none;}
      .user-chip{padding:5px 10px 5px 5px;}
      .user-name{max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
      .mood-grid{gap:8px;}
      .mood-label{padding:12px 4px;border-radius:10px;}
      .mood-text{font-size:10px;}
      .mood-num{display:none;}
      .submit-row{flex-direction:column;}
      .btn-back{text-align:center;justify-content:center;}
      .quote-modal{padding:28px 20px 22px;}
      .qm-quote{font-size:17px;}
    }
  </style>
</head>
<body>

<!-- ══ MOTIVATIONAL QUOTE POPUP ══════════════════════════════════════════ -->
<?php
// Determine which popup to show based on today's selection & streak
// We pass both to JS and let it pick based on what mood was selected
?>
<div class="quote-overlay" id="quoteOverlay">

  <!-- Low mood popup (mood 1 or 2, no streak) -->
  <div class="quote-modal low-variant" id="quoteLow">
    <button class="qm-close" onclick="closeQuote()" title="Close">✕</button>
    <span class="qm-emoji">💙</span>
    <span class="qm-label">A thought for you</span>
    <p class="qm-quote">"<?= htmlspecialchars($quote_low['quote']) ?>"</p>
    <p class="qm-author"><?= htmlspecialchars($quote_low['author']) ?></p>
    <div class="qm-actions">
      <button class="qm-btn-primary" onclick="closeQuote()">
        Thank you, continue →
      </button>
      <a class="qm-btn-secondary" href="resources.php" onclick="closeQuote()">
        💚 Browse wellness resources
      </a>
    </div>
  </div>

  <!-- 3-day low streak popup -->
  <div class="quote-modal streak-variant" id="quoteStreak" style="display:none">
    <button class="qm-close" onclick="closeQuote()" title="Close">✕</button>
    <span class="qm-emoji">🌤️</span>
    <span class="qm-label">We've noticed something</span>

    <!-- 3-day mood dots -->
    <div class="qm-days" id="moodDots">
      <?php
      // Show last 3 days + today
      for ($i = 2; $i >= 0; $i--):
        $check_date = date('Y-m-d', strtotime("-{$i} days"));
        $day_label  = $i === 0 ? 'Today' : date('D', strtotime($check_date));
        $found_lv   = null;
        foreach ($recent_3 as $r) {
            if ($r['day'] === $check_date) { $found_lv = (int)$r['mood_level']; break; }
        }
        $dot_class = 'missing';
        $dot_icon  = '?';
        if ($found_lv !== null) {
            $dot_class = ($found_lv <= 2) ? 'low' : 'okay';
            $dot_icon  = $mood_emoji[$found_lv] ?? '😐';
        }
        if ($i === 0) { $dot_class = 'today'; $dot_icon = '?'; }
      ?>
        <div class="qm-days-col">
          <div class="qm-day-dot <?= $dot_class ?>"><?= $dot_icon ?></div>
          <div class="qm-day-label"><?= $day_label ?></div>
        </div>
      <?php endfor; ?>
    </div>

    <p class="qm-quote">"<?= htmlspecialchars($quote_streak['quote']) ?>"</p>
    <p class="qm-author"><?= htmlspecialchars($quote_streak['author']) ?></p>
    <div class="qm-actions">
      <a class="qm-btn-primary" href="counselor-chat.php" style="text-align:center">
        💬 Chat with a counselor
      </a>
      <a class="qm-btn-secondary" href="appointments.php" onclick="closeQuote()">
        📅 Book an appointment
      </a>
      <button class="qm-btn-secondary" onclick="closeQuote()" style="border:none;color:var(--text-3);font-size:12px;padding:6px">
        I'm okay, continue →
      </button>
    </div>
  </div>

</div>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Topbar -->
<header class="topbar">
  <button class="hamburger" id="hamburgerBtn" aria-label="Toggle navigation" aria-expanded="false">
    <span></span><span></span><span></span>
  </button>
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

  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sb-section">
      <span class="sb-label">My Wellness</span>
      <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
      <a class="nav-item active" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
      <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
      <a class="nav-item" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
      <a class="nav-item" href="progress.php"><div class="nav-icon">📈</div> My progress</a>
      <a class="nav-item" href="journal.php"><div class="nav-icon">📓</div> Wellness journal</a>
    </div>
    <div class="sb-divider"></div>
    <div class="sb-section">
      <span class="sb-label">Support</span>
      <a class="nav-item" href="appointments.php"><div class="nav-icon">📅</div> Book appointment</a>
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
      <div class="sb-user">
        <div class="su-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
        <a class="su-logout" href="../../auth/logout.php">Sign out</a>
      </div>
    </div>
  </aside>

  <!-- Main content -->
  <main class="main">
    <div class="checkin-wrap">

      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] ?>">
          <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
        </div>
      <?php endif; ?>

      <?php if ($already_done): ?>
      <!-- ── Already checked in ── -->
      <div class="done-box">
        <div class="done-emoji"><?= $mood_emoji[$already_done['mood_level']] ?></div>
        <div class="done-title">You've checked in today!</div>
        <div class="done-sub">
          Feeling <?= $mood_labels[$already_done['mood_level']] ?> — Level <?= $already_done['mood_level'] ?> / 5
        </div>
        <?php if ($already_done['mood_note']): ?>
          <div class="done-note">"<?= clean($already_done['mood_note']) ?>"</div>
        <?php endif; ?>
        <div class="done-actions">
          <a class="btn-outline" href="dashboard.php">Back to dashboard</a>
          <a class="btn-solid" href="progress.php">View my progress</a>
        </div>
      </div>

      <div style="margin-top:28px">
        <div style="font-size:13px;font-weight:500;color:var(--text);margin-bottom:12px">Your mood this week</div>
        <div class="week-strip">
          <?php
          $bar_colors = [null=>'var(--sand-3)',1=>'#F5C6C2',2=>'#FAC775',3=>'var(--sand-3)',4=>'#9FE1CB',5=>'#1D9E75'];
          $heights    = [null=>4, 1=>10, 2=>20, 3=>30, 4=>42, 5=>52];
          for ($i = 6; $i >= 0; $i--):
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $label = date('D', strtotime($date));
            $lv    = $week_map[$date] ?? null;
          ?>
            <div class="week-day">
              <div class="week-bar-wrap">
                <div class="week-bar" style="height:<?= $heights[$lv] ?>px;background:<?= $bar_colors[$lv] ?>"></div>
              </div>
              <div class="week-day-emoji"><?= $lv ? $mood_emoji[$lv] : '·' ?></div>
              <div class="week-day-label"><?= $label ?></div>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <?php else: ?>
      <!-- ── Check-in form ── -->

      <div class="page-title">Daily mood check-in</div>
      <p class="page-sub">
        Take a moment to check in with yourself.<br>
        How are you feeling right now, <?= clean($user['first_name']) ?>?
      </p>

      <?php if ($show_3day_banner): ?>
      <!-- 3-day low streak notice at top of form -->
      <div class="streak-banner">
        <div class="streak-icon">💛</div>
        <div>
          <div class="streak-title">We've been thinking of you</div>
          <div class="streak-body">
            Your mood has been low for the past <?= $low_streak ?> day<?= $low_streak > 1 ? 's' : '' ?>.
            It's okay to not be okay — but you don't have to go through this alone.
          </div>
          <div class="streak-actions">
            <a class="streak-btn streak-btn-primary" href="counselor-chat.php">💬 Talk to a counselor</a>
            <a class="streak-btn streak-btn-outline" href="crisis.php">🆘 Crisis help</a>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="alert alert-error"><span style="font-weight:700">!</span> <?= clean($error) ?></div>
      <?php endif; ?>

      <div class="card">
        <form method="POST" action="" id="checkinForm">

          <div style="font-size:13px;font-weight:500;color:var(--text);margin-bottom:4px">
            Select your mood level
          </div>
          <div style="font-size:12px;color:var(--text-3);margin-bottom:4px">
            1 = Very Low &nbsp;·&nbsp; 5 = Great
          </div>

          <div class="mood-grid">
            <?php
            $moods = [
              1 => ['emoji' => '😔', 'label' => 'Very Low'],
              2 => ['emoji' => '😟', 'label' => 'Low'],
              3 => ['emoji' => '😐', 'label' => 'Okay'],
              4 => ['emoji' => '🙂', 'label' => 'Good'],
              5 => ['emoji' => '😊', 'label' => 'Great'],
            ];
            foreach ($moods as $val => $m):
            ?>
              <div>
                <input class="mood-option" type="radio" name="mood_level"
                       id="mood_<?= $val ?>" value="<?= $val ?>"
                       <?= (isset($_POST['mood_level']) && (int)$_POST['mood_level'] === $val) ? 'checked' : '' ?>
                       required>
                <label class="mood-label" for="mood_<?= $val ?>">
                  <span class="mood-emoji"><?= $m['emoji'] ?></span>
                  <span class="mood-text"><?= $m['label'] ?></span>
                  <span class="mood-num"><?= $val ?> / 5</span>
                </label>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="form-group">
            <label class="form-label" for="mood_note">
              Add a note <span style="font-weight:400;color:var(--text-3)">(optional)</span>
            </label>
            <textarea class="note-area" id="mood_note" name="mood_note"
                      maxlength="500"
                      placeholder="What's on your mind today? How are things going?..."
                      oninput="updateCount(this)"
            ><?= clean($_POST['mood_note'] ?? '') ?></textarea>
            <div class="char-count"><span id="charCount">0</span> / 500</div>
          </div>

          <div class="submit-row">
            <a class="btn-back" href="dashboard.php">← Back</a>
            <button type="submit" class="btn-save" id="saveBtn" disabled>
              Save today's check-in
            </button>
          </div>

        </form>
      </div>

      <div class="tip-card">
        <div class="tip-icon">💡</div>
        <div>
          <div class="tip-title">Why daily check-ins matter</div>
          <div class="tip-body">
            Tracking your mood helps you and your counselor spot patterns over time.
            If your mood stays low for several days, the system will gently suggest
            a wellness assessment to make sure you're getting the right support.
          </div>
        </div>
      </div>

      <?php
      $has_this_week = count(array_filter($week_map)) > 0;
      if ($has_this_week):
      ?>
      <div style="margin-top:28px">
        <div style="font-size:13px;font-weight:500;color:var(--text);margin-bottom:12px">Your mood so far this week</div>
        <div class="week-strip">
          <?php
          $bar_colors = [null=>'var(--sand-3)',1=>'#F5C6C2',2=>'#FAC775',3=>'var(--sand-3)',4=>'#9FE1CB',5=>'#1D9E75'];
          $heights    = [null=>4, 1=>10, 2=>20, 3=>30, 4=>42, 5=>52];
          for ($i = 6; $i >= 0; $i--):
            $date  = date('Y-m-d', strtotime("-{$i} days"));
            $label = date('D', strtotime($date));
            $lv    = $week_map[$date] ?? null;
            $today = ($i === 0);
          ?>
            <div class="week-day">
              <div class="week-bar-wrap">
                <div class="week-bar" style="height:<?= $heights[$lv] ?>px;background:<?= $today ? 'var(--sand-3)' : $bar_colors[$lv] ?>;opacity:<?= $today ? '.4' : '1' ?>"></div>
              </div>
              <div class="week-day-emoji"><?= ($lv && !$today) ? $mood_emoji[$lv] : '·' ?></div>
              <div class="week-day-label" style="<?= $today ? 'color:var(--sage);font-weight:500' : '' ?>"><?= $today ? 'Today' : $label ?></div>
            </div>
          <?php endfor; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php endif; ?>
    </div>
  </main>
</div>

<script>
// ── PHP data passed to JS ─────────────────────────────────────
const SHOW_STREAK  = <?= $show_3day_banner ? 'true' : 'false' ?>;
const LOW_STREAK   = <?= (int)$low_streak ?>;

// ── Mood radio change → enable save + maybe show popup ───────
const radios  = document.querySelectorAll('input[name="mood_level"]');
const saveBtn = document.getElementById('saveBtn');
let   popupShown = false;

radios.forEach(function(r) {
  r.addEventListener('change', function() {
    saveBtn.disabled = false;
    const level = parseInt(this.value);

    // Show popup if low mood selected (1 or 2) and not already shown
    if (level <= 2 && !popupShown) {
      popupShown = true;
      setTimeout(function() {
        showQuotePopup(level);
      }, 300); // small delay feels more natural
    }
  });
});

// Restore if returning from POST with checked value
if (document.querySelector('input[name="mood_level"]:checked')) {
  saveBtn.disabled = false;
}

// ── Show the right popup variant ─────────────────────────────
function showQuotePopup(level) {
  const overlay  = document.getElementById('quoteOverlay');
  const lowModal = document.getElementById('quoteLow');
  const strModal = document.getElementById('quoteStreak');

  // If streak of 2+ low days AND today is also low → show streak popup
  if (SHOW_STREAK && LOW_STREAK >= 2) {
    lowModal.style.display = 'none';
    strModal.style.display = 'block';
    // Update "today" dot with the emoji they just selected
    const emojis = ['', '😔', '😟', '😐', '🙂', '😊'];
    const todayDot = strModal.querySelector('.qm-day-dot.today');
    if (todayDot) {
      todayDot.textContent = emojis[level] || '😔';
      todayDot.classList.remove('today');
      todayDot.classList.add('low');
    }
  } else {
    // Regular low mood popup
    strModal.style.display = 'none';
    lowModal.style.display = 'block';
  }

  overlay.classList.add('show');
  document.body.style.overflow = 'hidden';
}

function closeQuote() {
  document.getElementById('quoteOverlay').classList.remove('show');
  document.body.style.overflow = '';
}

// Close on overlay background click
document.getElementById('quoteOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeQuote();
});

// Close on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeQuote();
});

// ── Char count ────────────────────────────────────────────────
function updateCount(el) {
  const c = document.getElementById('charCount');
  if (c) c.textContent = el.value.length;
}
const noteArea = document.getElementById('mood_note');
if (noteArea && noteArea.value) updateCount(noteArea);

// ── Hamburger ────────────────────────────────────────────────
(function() {
  var btn = document.getElementById('hamburgerBtn');
  var sb  = document.querySelector('.sidebar');
  var ov  = document.getElementById('sidebarOverlay');
  if (!btn || !sb || !ov) return;
  function open()  { sb.classList.add('mobile-open');    ov.classList.add('active');    btn.classList.add('open');    btn.setAttribute('aria-expanded','true');  document.body.style.overflow = 'hidden'; }
  function close() { sb.classList.remove('mobile-open'); ov.classList.remove('active'); btn.classList.remove('open'); btn.setAttribute('aria-expanded','false'); document.body.style.overflow = '';       }
  btn.addEventListener('click', function() { sb.classList.contains('mobile-open') ? close() : open(); });
  ov.addEventListener('click', close);
  sb.querySelectorAll('.nav-item').forEach(function(l) { l.addEventListener('click', function() { if (window.innerWidth <= 900) close(); }); });
  window.addEventListener('resize', function() { if (window.innerWidth > 900) close(); });
})();
</script>
</body>
</html>