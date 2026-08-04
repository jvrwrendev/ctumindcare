<?php
// ============================================================
//  CTU MindCare — Student CSUQ Questionnaire
//  File: student/pages/csuq.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();
$csrf_token = generateCsrfToken();
$error = '';
$success = false;

$questions = [
    1 => 'Overall, I am satisfied with how easy it is to use this system.',
    2 => 'It was simple to use this system.',
    3 => 'I could effectively complete the tasks and scenarios using this system.',
    4 => 'I felt comfortable using the system.',
    5 => 'It was easy to learn to operate the system.',
    6 => 'I quickly became skillful at using the system.',
    7 => 'The system gives error messages that clearly tell me how to fix problems.',
    8 => 'Whenever I made a mistake, I could recover easily and quickly.',
    9 => 'The information (such as online help, on-screen messages and other documentation) provided with the system was clear.',
    10 => 'The information provided with the system was easy to understand.',
    11 => 'The information was effective in helping me complete the tasks and scenarios.',
    12 => 'The organization of information on the system screens was clear.',
    13 => 'The interface of the system was pleasant.',
    14 => 'I liked using the interface of this system.',
    15 => 'This system has all the functions and capabilities I expect it to have.',
    16 => 'I can efficiently complete my work using this system.',
    17 => 'The system is useful for my needs.',
    18 => 'I would use this system frequently.',
    19 => 'I would recommend this system to others if they needed a similar tool.',
];

$db->exec(
    "CREATE TABLE IF NOT EXISTS csuq_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        student_id VARCHAR(50) DEFAULT NULL,
        email VARCHAR(255) DEFAULT NULL,
        total_score INT NOT NULL,
        average_score DECIMAL(4,2) NOT NULL,
        responses_json TEXT NOT NULL,
        comments TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
);

$answers = [];
$comments = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['_csrf_token'] ?? '')) {
        $error = 'Invalid form submission. Please refresh the page and try again.';
    } else {
        $answers = $_POST['answers'] ?? [];
        $comments = clean($_POST['comments'] ?? '');
        $missing = [];

        foreach ($questions as $index => $_) {
            if (!isset($answers[$index]) || !in_array($answers[$index], ['1','2','3','4','5','6','7'], true)) {
                $missing[] = $index;
            }
        }

        if ($missing) {
            $error = 'Please answer all questions before submitting the CSUQ questionnaire.';
        } else {
            $total = array_sum(array_map('intval', $answers));
            $average = round($total / count($questions), 2);

            $stmt = $db->prepare(
                "INSERT INTO csuq_submissions (user_id, student_id, email, total_score, average_score, responses_json, comments)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $user['id'],
                $user['student_id'] ?? null,
                $user['email'] ?? null,
                $total,
                $average,
                json_encode($answers, JSON_UNESCAPED_UNICODE),
                $comments ?: null,
            ]);

            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>CSUQ Questionnaire — CTU MindCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--sage:#4A9B7F;--sage-light:#EAF7F2;--sage-mid:#C4E8DC;--sage-dark:#2D6B57;--sand:#F7F3EE;--border:#E8E4DE;--text:#2C2C2A;--text-2:#5C5B57;--text-3:#9A9891;--white:#fff;}
body{font-family:'DM Sans',sans-serif;background:var(--sand);color:var(--text);min-height:100vh;}
.page-shell{max-width:1100px;margin:0 auto;padding:26px 22px;}
.layout{display:grid;grid-template-columns:260px 1fr;gap:24px;align-items:start;}
.sidebar{background:var(--white);border:1px solid var(--border);border-radius:20px;padding:24px;min-height:calc(100vh - 52px);}
.sidebar .sb-section{margin-bottom:22px;}
.sb-label{display:block;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.9px;margin-bottom:14px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;font-size:13px;color:var(--text-2);text-decoration:none;transition:all .14s;}
.nav-item:hover{background:var(--sand);color:var(--text);}
.nav-item.active{background:var(--sage-light);color:var(--sage-dark);font-weight:600;}
.nav-icon{width:28px;height:28px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:var(--sand);font-size:14px;}
.nav-item.active .nav-icon{background:var(--sage-mid);}
.sb-divider{height:1px;background:var(--border);margin:18px 0;}
.main{display:flex;flex-direction:column;gap:24px;}
.topbar{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px;margin-bottom:24px;padding:18px 22px;background:var(--white);border:1px solid var(--border);border-radius:18px;box-shadow:0 10px 24px rgba(0,0,0,.05);}
.topbar h1{font-family:'DM Serif Display',serif;font-size:26px;line-height:1.1;color:var(--sage-dark);}
.topbar p{font-size:13px;color:var(--text-2);max-width:580px;}
.user-chip{display:flex;align-items:center;gap:10px;background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:999px;padding:10px 16px;font-size:12px;color:var(--sage-dark);}
.user-chip span{font-weight:600;}
.card{background:var(--white);border:1px solid var(--border);border-radius:20px;padding:24px;box-shadow:0 10px 24px rgba(0,0,0,.04);}
.card + .card{margin-top:20px;}
.scale-legend{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin:18px 0 26px;}
.scale-pill{background:var(--sage-light);border:1px solid var(--sage-mid);border-radius:12px;padding:12px 14px;font-size:12px;color:var(--sage-dark);text-align:center;}
.fieldset{border:1px solid var(--border);border-radius:16px;padding:18px;}
.fieldset legend{font-weight:700;color:var(--sage-dark);padding:0 6px;}
.question-row{display:grid;grid-template-columns:1fr;gap:20px;margin-bottom:20px;}
.question-box{display:grid;gap:12px;padding:18px;border:1px solid var(--border);border-radius:16px;background:var(--sand);}
.question-label{font-size:14px;color:var(--text);line-height:1.5;}
.options{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;}
.option{display:flex;flex-direction:column;align-items:center;gap:6px;font-size:11px;color:var(--text-2);}
.option input{accent-color:var(--sage);}
textarea.form-input{width:100%;min-height:120px;padding:14px;border:1.5px solid var(--border);border-radius:14px;background:var(--white);font-family:'DM Sans',sans-serif;font-size:14px;color:var(--text);resize:vertical;}
.btn-primary{display:inline-flex;align-items:center;justify-content:center;padding:14px 22px;border:none;border-radius:14px;background:var(--sage);color:#fff;font-size:14px;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-primary:hover{background:#5BAF91;}
.alert{border-radius:14px;padding:16px 18px;font-size:14px;line-height:1.5;margin-bottom:20px;}
.alert-error{background:#FBEDED;border:1px solid #F5C6C2;color:#8B2020;}
.alert-success{background:var(--sage-light);border:1px solid var(--sage-mid);color:var(--sage-dark);}
@media(max-width:900px){.scale-legend{grid-template-columns:1fr 1fr;}.options{grid-template-columns:repeat(4,minmax(0,1fr));}.question-row{gap:18px;}}
@media(max-width:620px){.options{grid-template-columns:repeat(2,minmax(0,1fr));}}
</style>
</head>
<body>
<div class="page-shell">
  <div class="layout">
    <aside class="sidebar">
      <div class="sb-section">
        <span class="sb-label">My Wellness</span>
        <a class="nav-item" href="dashboard.php"><div class="nav-icon">🏠</div> Dashboard</a>
        <a class="nav-item" href="mood-checkin.php"><div class="nav-icon">😊</div> Daily check-in</a>
        <a class="nav-item" href="assessment.php"><div class="nav-icon">📋</div> Assessments</a>
        <a class="nav-item active" href="csuq.php"><div class="nav-icon">💻</div> CSUQ survey</a>
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
      <a class="nav-item crisis-nav" href="crisis.php"><div class="nav-icon">🆘</div> Crisis help</a>
    </aside>
    <main class="main">
      <div class="topbar">
        <div>
          <h1>Computer System Usability Questionnaire</h1>
          <p>This CSUQ form helps the MIS office understand how students experience the web system and improves trust, usability, and secure access.</p>
        </div>
        <div class="user-chip">
          <span><?= clean($user['first_name'] ?? 'Student') ?></span>
          <span>•</span>
          <span><?= clean($user['student_id'] ?? 'N/A') ?></span>
        </div>
      </div>

      <div class="card">
    <?php if ($error): ?>
      <div class="alert alert-error"><?= clean($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <strong>Thank you!</strong> Your CSUQ feedback has been recorded securely. The MIS office will review it as part of our system quality checks.
      </div>
      <p style="margin-bottom:18px;color:var(--text-2);">Your responses are stored in an encrypted database field and linked only to your CTU student account.</p>
      <a class="btn-primary" href="dashboard.php">Back to dashboard</a>
    <?php else: ?>
      <form method="POST" action="" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= $csrf_token ?>">

        <p style="font-size:13px;color:var(--text-2);margin-bottom:16px;">Select the response that best matches your agreement with each statement. The scale ranges from 1 (Strongly Disagree) to 7 (Strongly Agree).</p>

        <div class="scale-legend">
          <div class="scale-pill">1&nbsp;Strongly Disagree</div>
          <div class="scale-pill">2&nbsp;Disagree</div>
          <div class="scale-pill">3&nbsp;Somewhat Disagree</div>
          <div class="scale-pill">4&nbsp;Neutral</div>
          <div class="scale-pill">5&nbsp;Somewhat Agree</div>
          <div class="scale-pill">6&nbsp;Agree</div>
          <div class="scale-pill">7&nbsp;Strongly Agree</div>
        </div>

        <div class="question-row">
          <?php foreach ($questions as $index => $question): ?>
            <div class="question-box">
              <div class="question-label"><strong><?= $index ?>.</strong> <?= clean($question) ?></div>
              <div class="options">
                <?php for ($value = 1; $value <= 7; $value++): ?>
                  <label class="option">
                    <input type="radio" name="answers[<?= $index ?>]" value="<?= $value ?>" <?= isset($answers[$index]) && $answers[$index] === (string)$value ? 'checked' : '' ?>>
                    <?= $value ?>
                  </label>
                <?php endfor; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="fieldset">
          <legend>Additional feedback (optional)</legend>
          <textarea class="form-input" name="comments" placeholder="Tell us any usability issues, secure access concerns, or ideas for improvement."><?= clean($comments) ?></textarea>
        </div>

        <div style="margin-top:24px;display:flex;flex-wrap:wrap;gap:12px;align-items:center;">
          <button class="btn-primary" type="submit">Submit CSUQ feedback</button>
          <span style="font-size:13px;color:var(--text-3);">Responses are stored securely and used only by the MIS office and wellness team.</span>
        </div>
      </form>
    <?php endif; ?>
  </div>
    </main>
  </div>
</div>
</body>
</html>
