<?php
// ============================================================
//  CTU MindCare — Student Assessments
//  File: student/pages/assessment.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$trigger = $_GET['trigger'] ?? '';   // 'auto' = redirected from mood check-in

// ── Load all active assessment templates ─────────────────────
$stmt = $db->prepare("SELECT * FROM assessment_templates WHERE is_active = 1 ORDER BY id ASC");
$stmt->execute();
$templates = $stmt->fetchAll();

// ── Which template is selected? ──────────────────────────────
$selected_id = (int)($_GET['id'] ?? ($_POST['template_id'] ?? 0));
if (!$selected_id && $templates) $selected_id = $templates[0]['id'];

// ── Load selected template + its questions ───────────────────
$template = null;
$questions = [];
if ($selected_id) {
    $stmt = $db->prepare("SELECT * FROM assessment_templates WHERE id = ? AND is_active = 1");
    $stmt->execute([$selected_id]);
    $template = $stmt->fetch();

    if ($template) {
        $stmt = $db->prepare("SELECT * FROM assessment_questions WHERE template_id = ? ORDER BY question_no ASC");
        $stmt->execute([$selected_id]);
        $questions = $stmt->fetchAll();
    }
}

// ── Past results for this student ────────────────────────────
$stmt = $db->prepare("
    SELECT ar.id, ar.total_score, ar.severity, ar.triggered_by, ar.taken_at,
           at.code, at.title, at.max_score
    FROM assessment_results ar
    JOIN assessment_templates at ON ar.template_id = at.id
    WHERE ar.user_id = ?
    ORDER BY ar.taken_at DESC
    LIMIT 10
");
$stmt->execute([$user['id']]);
$past_results = $stmt->fetchAll();

// ── Scoring helpers ───────────────────────────────────────────
function scorePHQ9(int $score): string {
    if ($score <= 4)  return 'minimal';
    if ($score <= 9)  return 'mild';
    if ($score <= 14) return 'moderate';
    if ($score <= 19) return 'moderately_severe';
    return 'severe';
}
function scoreGAD7(int $score): string {
    if ($score <= 4)  return 'minimal';
    if ($score <= 9)  return 'mild';
    if ($score <= 14) return 'moderate';
    return 'severe';
}
function scorePSS10(int $score): string {
    if ($score <= 13) return 'minimal';
    if ($score <= 26) return 'mild';
    if ($score <= 33) return 'moderate';
    return 'severe';
}
function calcSeverity(string $code, int $score): string {
    return match($code) {
        'PHQ9'  => scorePHQ9($score),
        'GAD7'  => scoreGAD7($score),
        'PSS10' => scorePSS10($score),
        default => 'minimal',
    };
}

// ── Handle form submission ────────────────────────────────────
$result_data = null;
$error       = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_assessment'])) {
    $tid     = (int)($_POST['template_id'] ?? 0);
    $trigger_by = ($_POST['trigger_source'] ?? '') === 'auto' ? 'auto_mood' : 'manual';

    // Re-fetch template and questions
    $stmt = $db->prepare("SELECT * FROM assessment_templates WHERE id = ? AND is_active = 1");
    $stmt->execute([$tid]);
    $tpl = $stmt->fetch();

    $stmt = $db->prepare("SELECT * FROM assessment_questions WHERE template_id = ? ORDER BY question_no ASC");
    $stmt->execute([$tid]);
    $qs = $stmt->fetchAll();

    if (!$tpl || !$qs) {
        $error = 'Invalid assessment. Please try again.';
    } else {
        $answers = $_POST['answers'] ?? [];
        $all_answered = true;
        $total = 0;

        foreach ($qs as $q) {
            $qid = $q['id'];
            if (!isset($answers[$qid]) || $answers[$qid] === '') {
                $all_answered = false;
                break;
            }
            $val = (int)$answers[$qid];
            // PSS-10: questions 4,5,7,8 are reverse-scored
            if ($tpl['code'] === 'PSS10' && in_array($q['question_no'], [4,5,7,8])) {
                $val = 4 - $val;
            }
            $total += $val;
        }

        if (!$all_answered) {
            $error = 'Please answer all questions before submitting.';
            $selected_id = $tid;
            $template = $tpl;
            $questions = $qs;
        } else {
            $severity = calcSeverity($tpl['code'], $total);

            // Save result
            $stmt = $db->prepare("INSERT INTO assessment_results (user_id, template_id, total_score, severity, triggered_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$user['id'], $tid, $total, $severity, $trigger_by]);
            $result_id = $db->lastInsertId();

            // Save individual answers
            $stmt = $db->prepare("INSERT INTO assessment_answers (result_id, question_id, answer_value) VALUES (?, ?, ?)");
            foreach ($qs as $q) {
                $raw_val = (int)($answers[$q['id']] ?? 0);
                $stmt->execute([$result_id, $q['id'], $raw_val]);
            }

            // If moderately_severe or severe → notify counselor via notification
            if (in_array($severity, ['moderately_severe', 'severe'])) {
                // Notify the student
                $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, 'general', ?)");
                $notif_stmt->execute([
                    $user['id'],
                    "Your {$tpl['title']} result shows a {$severity} level. A counselor will reach out soon. You can also book an appointment anytime."
                ]);
            }

            // Mark notification as read if triggered by auto mood
            if ($trigger_by === 'auto_mood') {
                $db->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND type = 'assessment_due' AND is_read = 0")
                   ->execute([$user['id']]);
            }

            $result_data = [
                'template'  => $tpl,
                'score'     => $total,
                'severity'  => $severity,
                'result_id' => $result_id,
            ];
        }
    }
}

// ── Severity label/color helpers ─────────────────────────────
function severityLabel(string $s): string {
    return match($s) {
        'minimal'           => 'Minimal',
        'mild'              => 'Mild',
        'moderate'          => 'Moderate',
        'moderately_severe' => 'Moderately Severe',
        'severe'            => 'Severe',
        default             => ucfirst($s),
    };
}
function severityColor(string $s): string {
    return match($s) {
        'minimal'           => '#2D6B57',
        'mild'              => '#7A4F0A',
        'moderate'          => '#D4860A',
        'moderately_severe' => '#C0440B',
        'severe'            => '#B91C1C',
        default             => '#5C5B57',
    };
}
function severityBg(string $s): string {
    return match($s) {
        'minimal'           => '#EAF7F2',
        'mild'              => '#FDF3DF',
        'moderate'          => '#FEF3C7',
        'moderately_severe' => '#FFEDE0',
        'severe'            => '#FBEDED',
        default             => '#F7F3EE',
    };
}
function severityBorder(string $s): string {
    return match($s) {
        'minimal'           => '#C4E8DC',
        'mild'              => '#FAC775',
        'moderate'          => '#FCD34D',
        'moderately_severe' => '#FDBA74',
        'severe'            => '#F5C6C2',
        default             => '#D9D3C9',
    };
}

// Severity range descriptions per template
function severityRanges(string $code): array {
    return match($code) {
        'PHQ9'  => [
            ['label'=>'Minimal',           'range'=>'0–4',   'sev'=>'minimal'],
            ['label'=>'Mild',              'range'=>'5–9',   'sev'=>'mild'],
            ['label'=>'Moderate',          'range'=>'10–14', 'sev'=>'moderate'],
            ['label'=>'Moderately Severe', 'range'=>'15–19', 'sev'=>'moderately_severe'],
            ['label'=>'Severe',            'range'=>'20–27', 'sev'=>'severe'],
        ],
        'GAD7'  => [
            ['label'=>'Minimal',  'range'=>'0–4',  'sev'=>'minimal'],
            ['label'=>'Mild',     'range'=>'5–9',  'sev'=>'mild'],
            ['label'=>'Moderate', 'range'=>'10–14','sev'=>'moderate'],
            ['label'=>'Severe',   'range'=>'15–21','sev'=>'severe'],
        ],
        'PSS10' => [
            ['label'=>'Low Stress',      'range'=>'0–13',  'sev'=>'minimal'],
            ['label'=>'Moderate Stress', 'range'=>'14–26', 'sev'=>'mild'],
            ['label'=>'High Stress',     'range'=>'27–33', 'sev'=>'moderate'],
            ['label'=>'Very High',       'range'=>'34–40', 'sev'=>'severe'],
        ],
        default => [],
    };
}

function severityAdvice(string $code, string $sev): string {
    $map = [
        'PHQ9' => [
            'minimal'           => 'Your depression symptoms are minimal. Keep up your daily routines, mood check-ins, and self-care habits.',
            'mild'              => 'Mild symptoms detected. Journaling, exercise, and reaching out to friends can be very helpful.',
            'moderate'          => 'Moderate symptoms noted. We recommend booking a counseling session to talk things through.',
            'moderately_severe' => 'Your results suggest significant depression symptoms. Please book an appointment with a counselor soon.',
            'severe'            => 'Severe symptoms detected. Please reach out to a counselor immediately or use the Crisis Help resource.',
        ],
        'GAD7' => [
            'minimal'           => 'Anxiety levels are low. Breathing exercises and regular sleep can help maintain this.',
            'mild'              => 'Mild anxiety present. Try mindfulness techniques and limit caffeine intake.',
            'moderate'          => 'Moderate anxiety detected. Consider speaking with a counselor for coping strategies.',
            'severe'            => 'Severe anxiety symptoms. Please reach out to a counselor or crisis support right away.',
        ],
        'PSS10' => [
            'minimal'           => 'Your stress levels are well-managed. Keep practicing your coping strategies.',
            'mild'              => 'Moderate stress noted. Time management and regular breaks may help.',
            'moderate'          => 'High stress detected. We recommend connecting with a counselor or using relaxation resources.',
            'severe'            => 'Very high stress levels. Immediate support is available — please book a counseling session.',
        ],
    ];
    return $map[$code][$sev] ?? 'Thank you for completing this assessment. Please consult a counselor for interpretation.';
}

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Assessments — CTU MindCare</title>
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

    /* ── Reset ── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body {
      font-family: 'DM Sans', sans-serif;
      font-size: 14px;
      color: var(--text);
      background: var(--sand);
      min-height: 100vh;
      -webkit-font-smoothing: antialiased;
    }
    a { text-decoration: none; color: inherit; }

    /* ── Topbar ── */
    .topbar {
      height: var(--topbar-h);
      background: var(--white);
      border-bottom: 1px solid var(--border);
      display: flex; align-items: center;
      padding: 0 24px; position: sticky; top: 0; z-index: 200; gap: 16px;
    }
    .topbar-brand   { display: flex; align-items: center; gap: 10px; margin-right: auto; }
    .topbar-mark    { width: 34px; height: 34px; background: var(--sage); border-radius: 9px; display: flex; align-items: center; justify-content: center; font-family: 'DM Serif Display', serif; font-size: 17px; color: #fff; }
    .topbar-name    { font-family: 'DM Serif Display', serif; font-size: 17px; color: var(--text); }
    .topbar-school  { font-size: 10px; color: var(--text-3); margin-top: 1px; }
    .topbar-right   { display: flex; align-items: center; gap: 12px; }
    .user-chip      { display: flex; align-items: center; gap: 8px; background: var(--sage-light); border: 1px solid var(--sage-mid); border-radius: 100px; padding: 5px 14px 5px 6px; }
    .user-av        { width: 26px; height: 26px; border-radius: 50%; background: var(--sage); display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 600; color: #fff; }
    .user-name      { font-size: 12px; font-weight: 500; color: var(--sage-dark); }
    .logout-btn     { font-size: 12px; color: var(--text-3); padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border); background: var(--white); transition: color .15s, border-color .15s; }
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
    .sb-section    { padding: 0 14px; margin-bottom: 6px; }
    .sb-label      { font-size: 9px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: 1px; padding: 0 8px; margin-bottom: 4px; display: block; }
    .nav-item      { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 9px; font-size: 13px; color: var(--text-2); text-decoration: none; transition: all .12s; margin-bottom: 1px; }
    .nav-item:hover { background: var(--sand); color: var(--text); }
    .nav-item.active { background: var(--sage-light); color: var(--sage-dark); font-weight: 500; }
    .nav-icon      { width: 28px; height: 28px; border-radius: 7px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; background: var(--sand); }
    .nav-item.active .nav-icon { background: var(--sage-mid); }
    .nav-item.crisis-nav { color: var(--coral); }
    .nav-item.crisis-nav:hover { background: var(--coral-light); }
    .nav-item.crisis-nav .nav-icon { background: var(--coral-light); }
    .sb-divider    { height: 1px; background: var(--border); margin: 10px 14px; }
    .sb-footer     { margin-top: auto; padding: 14px; }
    .sb-user-card  { background: var(--sand); border-radius: 10px; padding: 12px; display: flex; align-items: center; gap: 10px; }
    .su-av         { width: 32px; height: 32px; border-radius: 50%; background: var(--sage-mid); color: var(--sage-dark); font-size: 12px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .su-name       { font-size: 12px; font-weight: 500; color: var(--text); }
    .su-id         { font-size: 10px; color: var(--text-3); margin-top: 1px; }

    /* ── Main ── */
    .main { padding: 28px 28px 48px; }
    .page-header   { margin-bottom: 24px; }
    .page-title    { font-family: 'DM Serif Display', serif; font-size: 26px; color: var(--text); margin-bottom: 4px; }
    .page-sub      { font-size: 13px; color: var(--text-3); line-height: 1.6; }

    /* ── Flash / Alert ── */
    .flash {
      display: flex; align-items: flex-start; gap: 10px;
      padding: 12px 16px; border-radius: 10px;
      font-size: 13px; font-weight: 500; margin-bottom: 20px;
    }
    .flash-success { background: var(--sage-light); color: var(--sage-dark); border: 1px solid var(--sage-mid); }
    .flash-warning { background: var(--amber-light); color: #7A4F0A; border: 1px solid #FAC775; }
    .flash-error   { background: #FBEDED; color: #7A1A1A; border: 1px solid #F5C6C2; }
    .alert-banner  {
      display: flex; align-items: flex-start; gap: 12px;
      background: var(--amber-light); border: 1px solid #FAC775;
      border-radius: 12px; padding: 14px 16px; margin-bottom: 24px;
    }
    .ab-icon { font-size: 20px; flex-shrink: 0; }
    .ab-title { font-size: 13px; font-weight: 600; color: #7A4F0A; margin-bottom: 2px; }
    .ab-body  { font-size: 12px; color: #7A4F0A; line-height: 1.6; }

    /* ── Two-column grid ── */
    .assess-grid { display: grid; grid-template-columns: 300px 1fr; gap: 20px; align-items: start; }

    /* ── Template selector card ── */
    .card {
      background: var(--white); border: 1px solid var(--border);
      border-radius: 14px; padding: 20px;
    }
    .card-title   { font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 14px; }
    .tpl-item {
      display: flex; align-items: flex-start; gap: 12px;
      padding: 12px; border-radius: 10px; cursor: pointer;
      border: 1.5px solid transparent; transition: all .15s;
      margin-bottom: 8px; text-decoration: none; background: var(--sand);
    }
    .tpl-item:hover { border-color: var(--sage-mid); background: var(--sage-light); }
    .tpl-item.active { border-color: var(--sage); background: var(--sage-light); }
    .tpl-icon  { width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .tpl-name  { font-size: 13px; font-weight: 500; color: var(--text); line-height: 1.3; }
    .tpl-desc  { font-size: 11px; color: var(--text-3); margin-top: 2px; line-height: 1.5; }
    .tpl-meta  { display: flex; gap: 8px; margin-top: 6px; }
    .tpl-pill  { font-size: 10px; padding: 2px 8px; border-radius: 20px; background: rgba(74,155,127,.12); color: var(--sage-dark); font-weight: 500; }

    /* ── Assessment form card ── */
    .assess-form-card { background: var(--white); border: 1px solid var(--border); border-radius: 14px; overflow: hidden; }
    .afc-header       { padding: 20px 24px; border-bottom: 1px solid var(--border); display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .afc-title        { font-family: 'DM Serif Display', serif; font-size: 20px; color: var(--text); margin-bottom: 4px; }
    .afc-sub          { font-size: 12px; color: var(--text-3); line-height: 1.6; max-width: 480px; }
    .afc-badge        { flex-shrink: 0; font-size: 11px; font-weight: 600; padding: 4px 12px; border-radius: 20px; white-space: nowrap; }
    .afc-body         { padding: 24px; }

    /* ── Answer scale header ── */
    .scale-header {
      display: grid;
      grid-template-columns: 1fr repeat(4, 60px);
      gap: 0; padding: 8px 12px; margin-bottom: 4px;
      font-size: 10px; font-weight: 600; color: var(--text-3);
      text-transform: uppercase; letter-spacing: .04em;
    }
    .scale-header span { text-align: center; }
    .scale-header span:first-child { text-align: left; }

    /* ── Question rows ── */
    .q-row {
      display: grid;
      grid-template-columns: 1fr repeat(4, 60px);
      gap: 0; align-items: center;
      padding: 12px; border-radius: 10px;
      margin-bottom: 4px; transition: background .12s;
    }
    .q-row:hover { background: var(--sand); }
    .q-row.answered { background: var(--sage-light); }
    .q-num  { font-size: 10px; color: var(--text-3); margin-right: 4px; display: inline; }
    .q-text { font-size: 13px; color: var(--text); line-height: 1.5; }
    .q-radio-cell { display: flex; justify-content: center; }
    .q-radio {
      width: 20px; height: 20px; border-radius: 50%;
      border: 2px solid var(--sand-3); cursor: pointer;
      appearance: none; -webkit-appearance: none;
      transition: all .15s; background: var(--white);
    }
    .q-radio:checked { background: var(--sage); border-color: var(--sage); }
    .q-radio:hover   { border-color: var(--sage); }

    /* Divider between questions */
    .q-divider { height: 1px; background: var(--border); margin: 4px 12px; }

    /* ── Submit row ── */
    .form-footer {
      padding: 16px 24px; border-top: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between; gap: 16px;
    }
    .ff-note  { font-size: 12px; color: var(--text-3); }
    .ff-note strong { color: var(--text); }
    .btn-submit {
      padding: 12px 28px; background: var(--sage); color: #fff;
      border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 14px; font-weight: 500; cursor: pointer;
      transition: background .2s, transform .1s;
    }
    .btn-submit:hover   { background: var(--sage-2); }
    .btn-submit:active  { transform: scale(.99); }
    .btn-submit:disabled { background: var(--sand-3); cursor: not-allowed; }

    /* ── Error box ── */
    .error-box {
      display: flex; align-items: center; gap: 10px;
      background: #FBEDED; border: 1px solid #F5C6C2;
      border-radius: 10px; padding: 12px 16px;
      font-size: 13px; color: #7A1A1A; margin-bottom: 16px;
    }

    /* ── Result card ── */
    .result-wrap   { max-width: 640px; }
    .result-hero   { text-align: center; padding: 36px 24px 28px; border-radius: 16px; margin-bottom: 20px; }
    .result-emoji  { font-size: 56px; margin-bottom: 12px; }
    .result-score  { font-family: 'DM Serif Display', serif; font-size: 52px; line-height: 1; margin-bottom: 4px; }
    .result-maxscore { font-size: 14px; color: var(--text-3); margin-bottom: 12px; }
    .result-sev    { font-size: 14px; font-weight: 600; }
    .result-tpl    { font-size: 13px; color: var(--text-2); margin-top: 4px; }

    .advice-card   { padding: 16px 18px; border-radius: 12px; margin-bottom: 16px; display: flex; gap: 12px; align-items: flex-start; }
    .advice-icon   { font-size: 20px; flex-shrink: 0; }
    .advice-title  { font-size: 13px; font-weight: 600; margin-bottom: 4px; }
    .advice-body   { font-size: 13px; line-height: 1.7; }

    /* Score ranges table */
    .ranges-card   { padding: 16px 18px; border-radius: 12px; background: var(--sand); border: 1px solid var(--border); margin-bottom: 16px; }
    .ranges-title  { font-size: 12px; font-weight: 600; color: var(--text); margin-bottom: 12px; }
    .ranges-list   { display: flex; flex-direction: column; gap: 6px; }
    .range-row     { display: flex; align-items: center; gap: 10px; }
    .range-dot     { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .range-label   { font-size: 12px; color: var(--text); flex: 1; }
    .range-score   { font-size: 11px; color: var(--text-3); }
    .range-row.current .range-label { font-weight: 600; }

    .result-actions { display: flex; gap: 12px; flex-wrap: wrap; }
    .btn-primary {
      padding: 12px 24px; background: var(--sage); color: #fff;
      border: none; border-radius: 10px; font-family: 'DM Sans', sans-serif;
      font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none;
      display: inline-flex; align-items: center; transition: background .15s;
    }
    .btn-primary:hover { background: var(--sage-2); }
    .btn-outline-btn {
      padding: 12px 24px; background: var(--white); color: var(--text);
      border: 1.5px solid var(--border); border-radius: 10px;
      font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
      cursor: pointer; text-decoration: none; display: inline-flex; align-items: center;
      transition: border-color .15s;
    }
    .btn-outline-btn:hover { border-color: var(--sage); color: var(--sage); }

    /* ── Past results table ── */
    .history-card  { margin-top: 28px; }
    .history-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .history-table th {
      padding: 8px 12px; text-align: left; font-size: 10px; font-weight: 600;
      text-transform: uppercase; letter-spacing: .04em; color: var(--text-3);
      border-bottom: 1px solid var(--border); background: var(--sand);
    }
    .history-table td { padding: 12px; border-bottom: 1px solid var(--border); }
    .history-table tr:last-child td { border-bottom: none; }
    .history-table tr:hover td { background: var(--sand); }
    .code-pill   { display: inline-block; font-size: 10px; font-weight: 600; padding: 2px 8px; border-radius: 6px; background: var(--lav-light); color: var(--lav); margin-right: 6px; }
    .sev-pill    { display: inline-block; font-size: 11px; font-weight: 500; padding: 3px 10px; border-radius: 20px; }
    .s-minimal   { background: var(--sage-light);  color: var(--sage-dark); }
    .s-mild      { background: var(--amber-light); color: #7A4F0A; }
    .s-moderate  { background: #FEF3C7; color: #92400E; }
    .s-moderately_severe { background: #FFEDE0; color: #C0440B; }
    .s-severe    { background: #FBEDED; color: #7A1A1A; }

    /* ── Empty state ── */
    .empty-state {
      text-align: center; padding: 48px 24px;
      border: 2px dashed var(--sand-3); border-radius: 14px;
    }
    .es-icon  { font-size: 44px; margin-bottom: 12px; }
    .es-title { font-family: 'DM Serif Display', serif; font-size: 18px; color: var(--text); margin-bottom: 6px; }
    .es-sub   { font-size: 13px; color: var(--text-3); }

    /* ── Progress bar ── */
    .progress-wrap  { margin-bottom: 20px; }
    .progress-label { display: flex; justify-content: space-between; font-size: 11px; color: var(--text-3); margin-bottom: 4px; }
    .progress-track { height: 4px; background: var(--sand-3); border-radius: 2px; overflow: hidden; }
    .progress-fill  { height: 100%; background: var(--sage); border-radius: 2px; transition: width .4s; }
  
/* ── Hamburger button ── */
.hamburger{display:none;flex-direction:column;justify-content:center;align-items:center;gap:5px;width:38px;height:38px;border-radius:9px;background:var(--sand,#F7F3EE);border:1px solid var(--border,#E8E4DE);cursor:pointer;padding:0;flex-shrink:0;}
.hamburger span{display:block;width:18px;height:2px;background:var(--text-2,#5C5B57);border-radius:2px;transition:all .25s ease;}
.hamburger.open span:nth-child(1){transform:translateY(7px) rotate(45deg);}
.hamburger.open span:nth-child(2){opacity:0;transform:scaleX(0);}
.hamburger.open span:nth-child(3){transform:translateY(-7px) rotate(-45deg);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.38);z-index:299;backdrop-filter:blur(2px);}
.sidebar-overlay.active{display:block;}

@media(max-width:900px){
  .hamburger{display:flex!important;}
  .layout{grid-template-columns:1fr!important;}
  .sidebar{position:fixed!important;top:58px;left:0;bottom:0;z-index:300;transform:translateX(-100%);transition:transform .28s ease;height:calc(100vh - 58px)!important;width:270px;box-shadow:4px 0 24px rgba(0,0,0,.12);display:flex!important;overflow-y:auto;}
  .sidebar.mobile-open{transform:translateX(0);}
  .main,.main.main{padding:20px 16px!important;margin-left:0!important;}
}
@media(max-width:480px){.topbar-school{display:none;}.user-name{display:none;}.logout-btn{display:none;}}

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
      <a class="nav-item active" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
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
      <div class="sb-user-card">
        <div class="su-av"><?= strtoupper(substr($user['first_name'],0,1).substr($user['last_name'],0,1)) ?></div>
        <div>
          <div class="su-name"><?= clean($user['first_name'].' '.$user['last_name']) ?></div>
          <div class="su-id"><?= clean($user['student_id'] ?? 'Student') ?></div>
        </div>
      </div>
    </div>
  </aside>

  <!-- ── Main content ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>">
        <?= $flash['type'] === 'success' ? '✓' : '⚠' ?> <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if ($trigger === 'auto'): ?>
      <div class="alert-banner">
        <div class="ab-icon">💛</div>
        <div>
          <div class="ab-title">We've noticed your mood has been low recently</div>
          <div class="ab-body">Taking a short wellness assessment helps us understand how you're feeling so we can offer the right support. It only takes a few minutes.</div>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($result_data): ?>
    <!-- ══ RESULTS VIEW ══════════════════════════════════════════ -->
    <?php
      $rs   = $result_data['severity'];
      $rsc  = $result_data['score'];
      $rtpl = $result_data['template'];
      $emoji_map = ['minimal'=>'😊','mild'=>'🙂','moderate'=>'😐','moderately_severe'=>'😟','severe'=>'😔'];
    ?>
    <div class="page-header">
      <div class="page-title">Assessment complete</div>
      <div class="page-sub">Here are your results for the <?= clean($rtpl['title']) ?>.</div>
    </div>

    <div class="result-wrap">
      <!-- Score hero -->
      <div class="result-hero" style="background:<?= severityBg($rs) ?>;border:1px solid <?= severityBorder($rs) ?>">
        <div class="result-emoji"><?= $emoji_map[$rs] ?? '📋' ?></div>
        <div class="result-score" style="color:<?= severityColor($rs) ?>"><?= $rsc ?></div>
        <div class="result-maxscore">out of <?= $rtpl['max_score'] ?></div>
        <div class="result-sev" style="color:<?= severityColor($rs) ?>"><?= severityLabel($rs) ?></div>
        <div class="result-tpl"><?= clean($rtpl['title']) ?></div>
      </div>

      <!-- Advice -->
      <div class="advice-card" style="background:<?= severityBg($rs) ?>;border:1px solid <?= severityBorder($rs) ?>">
        <div class="advice-icon">💡</div>
        <div>
          <div class="advice-title" style="color:<?= severityColor($rs) ?>">What this means</div>
          <div class="advice-body" style="color:<?= severityColor($rs) ?>"><?= severityAdvice($rtpl['code'], $rs) ?></div>
        </div>
      </div>

      <!-- Score ranges -->
      <?php $ranges = severityRanges($rtpl['code']); if ($ranges): ?>
      <div class="ranges-card">
        <div class="ranges-title">Score interpretation guide</div>
        <div class="ranges-list">
          <?php foreach ($ranges as $r): ?>
            <div class="range-row <?= $r['sev'] === $rs ? 'current' : '' ?>">
              <div class="range-dot" style="background:<?= severityColor($r['sev']) ?>"></div>
              <div class="range-label"><?= $r['label'] ?></div>
              <div class="range-score"><?= $r['range'] ?></div>
              <?php if ($r['sev'] === $rs): ?>
                <div style="font-size:10px;font-weight:600;color:<?= severityColor($rs) ?>">← Your result</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- CTA buttons -->
      <div class="result-actions">
        <?php if (in_array($rs, ['moderate','moderately_severe','severe'])): ?>
          <a class="btn-primary" href="appointments.php">📅 Book a counseling session</a>
        <?php endif; ?>
        <a class="btn-outline-btn" href="assessment.php">Take another assessment</a>
        <a class="btn-outline-btn" href="dashboard.php">Back to dashboard</a>
        <?php if (in_array($rs, ['moderately_severe','severe'])): ?>
          <a class="btn-outline-btn" href="crisis.php" style="color:var(--red);border-color:var(--red)">🆘 Crisis help</a>
        <?php endif; ?>
      </div>
    </div>

    <?php else: ?>
    <!-- ══ TAKE ASSESSMENT VIEW ══════════════════════════════════ -->

    <div class="page-header">
      <div class="page-title">Wellness assessments</div>
      <div class="page-sub">
        These standardized tools help track your mental wellness.<br>
        Your results are confidential and shared only with your assigned counselor.
      </div>
    </div>

    <div class="assess-grid">

      <!-- Left: Template picker -->
      <div>
        <div class="card">
          <div class="card-title">Choose an assessment</div>

          <?php
          $tpl_icons = ['PHQ9'=>'😔','GAD7'=>'😰','PSS10'=>'🧠'];
          $tpl_colors= ['PHQ9'=>'var(--coral-light)','GAD7'=>'var(--lav-light)','PSS10'=>'var(--amber-light)'];
          foreach ($templates as $t):
            $is_active = ($t['id'] === $selected_id);
          ?>
            <a class="tpl-item <?= $is_active ? 'active' : '' ?>"
               href="assessment.php?id=<?= $t['id'] ?>">
              <div class="tpl-icon" style="background:<?= $tpl_colors[$t['code']] ?? 'var(--sand-2)' ?>">
                <?= $tpl_icons[$t['code']] ?? '📋' ?>
              </div>
              <div>
                <div class="tpl-name"><?= clean($t['title']) ?></div>
                <div class="tpl-desc"><?= clean($t['description']) ?></div>
                <div class="tpl-meta">
                  <span class="tpl-pill"><?= $t['total_items'] ?> questions</span>
                  <span class="tpl-pill">Max <?= $t['max_score'] ?></span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- Past results mini -->
        <?php if ($past_results): ?>
        <div class="card" style="margin-top:16px">
          <div class="card-title">Recent results</div>
          <?php foreach (array_slice($past_results, 0, 4) as $pr): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
              <span class="code-pill"><?= clean($pr['code']) ?></span>
              <div style="flex:1">
                <div style="font-size:12px;font-weight:500;color:var(--text)"><?= clean($pr['title']) ?></div>
                <div style="font-size:11px;color:var(--text-3)"><?= date('M j, Y', strtotime($pr['taken_at'])) ?></div>
              </div>
              <span class="sev-pill s-<?= $pr['severity'] ?>"><?= severityLabel($pr['severity']) ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (count($past_results) > 4): ?>
            <div style="font-size:11px;color:var(--text-3);margin-top:10px;text-align:center">
              +<?= count($past_results)-4 ?> more — <a href="progress.php" style="color:var(--sage)">view all</a>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Right: Form -->
      <div>
        <?php if ($template && $questions): ?>

          <?php if ($error): ?>
            <div class="error-box">⚠ <?= clean($error) ?></div>
          <?php endif; ?>

          <div class="assess-form-card">
            <div class="afc-header">
              <div>
                <div class="afc-title"><?= clean($template['title']) ?></div>
                <div class="afc-sub">
                  <?= clean($template['description']) ?>
                  Over the <strong>last 2 weeks</strong>, how often have you been bothered by the following?
                </div>
              </div>
              <div class="afc-badge" style="background:<?= severityBg('minimal') ?>;color:<?= severityColor('minimal') ?>">
                <?= $template['total_items'] ?> questions
              </div>
            </div>

            <form method="POST" action="assessment.php" id="assessForm">
              <input type="hidden" name="template_id" value="<?= $template['id'] ?>">
              <input type="hidden" name="trigger_source" value="<?= clean($trigger) ?>">

              <div class="afc-body">

                <!-- Progress bar -->
                <div class="progress-wrap">
                  <div class="progress-label">
                    <span>Progress</span>
                    <span id="progressLabel">0 / <?= count($questions) ?> answered</span>
                  </div>
                  <div class="progress-track">
                    <div class="progress-fill" id="progressFill" style="width:0%"></div>
                  </div>
                </div>

                <!-- Answer scale header -->
                <div class="scale-header">
                  <span>Question</span>
                  <span>Not<br>at all</span>
                  <span>Several<br>days</span>
                  <span>More than<br>half</span>
                  <span>Nearly<br>every day</span>
                </div>

                <!-- Questions -->
                <?php foreach ($questions as $i => $q): ?>
                  <?php if ($i > 0): ?><div class="q-divider"></div><?php endif; ?>
                  <div class="q-row" id="qrow_<?= $q['id'] ?>">
                    <div class="q-text">
                      <span class="q-num"><?= $q['question_no'] ?>.</span>
                      <?= clean($q['question_text']) ?>
                    </div>
                    <?php for ($v = 0; $v <= 3; $v++): ?>
                      <div class="q-radio-cell">
                        <input
                          class="q-radio"
                          type="radio"
                          name="answers[<?= $q['id'] ?>]"
                          value="<?= $v ?>"
                          id="q<?= $q['id'] ?>_<?= $v ?>"
                          onchange="onAnswer(<?= $q['id'] ?>)"
                          <?= (isset($_POST['answers'][$q['id']]) && (int)$_POST['answers'][$q['id']] === $v) ? 'checked' : '' ?>
                        >
                      </div>
                    <?php endfor; ?>
                  </div>
                <?php endforeach; ?>

                <!-- PHQ-9 Q9 crisis note -->
                <?php if ($template['code'] === 'PHQ9'): ?>
                  <div style="margin-top:16px;padding:12px 14px;background:#FBEDED;border:1px solid #F5C6C2;border-radius:10px;font-size:12px;color:#7A1A1A;line-height:1.6">
                    <strong>Note on question 9:</strong> If you're having thoughts of self-harm or suicide, please reach out to a counselor immediately or visit <a href="crisis.php" style="color:var(--red);font-weight:500">Crisis Help</a>.
                  </div>
                <?php endif; ?>

              </div><!-- .afc-body -->

              <div class="form-footer">
                <div class="ff-note">
                  All answers are <strong>confidential</strong>. Take your time.
                </div>
                <button type="submit" name="submit_assessment" class="btn-submit" id="submitBtn" disabled>
                  Submit assessment
                </button>
              </div>
            </form>
          </div><!-- .assess-form-card -->

        <?php else: ?>
          <div class="empty-state">
            <div class="es-icon">📋</div>
            <div class="es-title">Select an assessment</div>
            <div class="es-sub">Choose one of the assessments on the left to get started.</div>
          </div>
        <?php endif; ?>
      </div>

    </div><!-- .assess-grid -->

    <!-- Past results full table -->
    <?php if ($past_results): ?>
    <div class="card history-card">
      <div class="card-title">Assessment history</div>
      <table class="history-table">
        <thead>
          <tr>
            <th>Assessment</th>
            <th>Score</th>
            <th>Severity</th>
            <th>Triggered by</th>
            <th>Date</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($past_results as $pr): ?>
            <tr>
              <td>
                <span class="code-pill"><?= clean($pr['code']) ?></span>
                <?= clean($pr['title']) ?>
              </td>
              <td style="font-weight:600;color:var(--text)"><?= $pr['total_score'] ?> <span style="font-weight:400;color:var(--text-3)">/ <?= $pr['max_score'] ?></span></td>
              <td><span class="sev-pill s-<?= $pr['severity'] ?>"><?= severityLabel($pr['severity']) ?></span></td>
              <td style="font-size:12px;color:var(--text-3)"><?= $pr['triggered_by'] === 'auto_mood' ? '🔄 Auto (mood)' : '✋ Manual' ?></td>
              <td style="font-size:12px;color:var(--text-3)"><?= date('M j, Y · g:i A', strtotime($pr['taken_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php endif; // end results / form toggle ?>

  </main>
</div>

<script>
const totalQs = <?= count($questions) ?>;
const answered = new Set();

function onAnswer(qid) {
  answered.add(qid);
  // Highlight the row
  const row = document.getElementById('qrow_' + qid);
  if (row) row.classList.add('answered');
  // Update progress
  const pct = totalQs > 0 ? (answered.size / totalQs * 100) : 0;
  const fill  = document.getElementById('progressFill');
  const label = document.getElementById('progressLabel');
  if (fill)  fill.style.width = pct + '%';
  if (label) label.textContent = answered.size + ' / ' + totalQs + ' answered';
  // Enable submit when all answered
  const btn = document.getElementById('submitBtn');
  if (btn) btn.disabled = answered.size < totalQs;
}

// Re-init on page reload with POST data
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.q-radio:checked').forEach(r => {
    const name = r.getAttribute('name');        // answers[qid]
    const match = name.match(/\[(\d+)\]/);
    if (match) onAnswer(parseInt(match[1]));
  });
});
</script>

<script>
(function(){
  var btn=document.getElementById('hamburgerBtn');
  var sb=document.querySelector('.sidebar');
  var ov=document.getElementById('sidebarOverlay');
  if(!btn||!sb||!ov)return;
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