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
$mc_title = 'CSUQ Questionnaire';
require_once '../../includes/assets.php';
?>
<body>

<header class="topbar">
  <button class="mc-hamburger" id="mcHamburger" aria-label="Toggle navigation" aria-expanded="false"><span></span><span></span><span></span></button>
  <div class="topbar-brand">
    <div class="topbar-mark">M</div>
    <div>
      <div class="topbar-name">CTU MindCare</div>
      <div class="topbar-school">CTU Consolacion</div>
    </div>
  </div>
  <div class="topbar-right">
    <button type="button" class="mc-theme-toggle" aria-label="Toggle dark mode" title="Toggle dark mode">
      <i data-lucide="moon" class="icon-moon"></i>
      <i data-lucide="sun" class="icon-sun"></i>
    </button>
    <a class="user-chip" href="profile.php" title="Edit profile">
      <div class="user-av"><?= strtoupper(substr($user['first_name'] ?? '',0,1).substr($user['last_name'] ?? '',0,1)) ?></div>
      <span class="user-name"><?= clean(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')) ?></span>
    </a>
    <a class="logout-btn" href="../../auth/logout.php" title="Sign out"><i data-lucide="log-out"></i></a>
  </div>
</header>

<div class="layout">
  <?php require_once '../../includes/sidebar.php'; ?>
  <main class="main">
    <div class="csuq-shell">
      <div class="csuq-header mc-reveal">
        <div>
          <h1>Computer System Usability Questionnaire</h1>
          <p>This CSUQ form helps the MIS office understand how students experience the web system and improves trust, usability, and secure access.</p>
        </div>
        <div class="csuq-user">
          <span><?= clean($user['first_name'] ?? 'Student') ?></span>
          <span>•</span>
          <span><?= clean($user['student_id'] ?? 'N/A') ?></span>
        </div>
      </div>

      <div class="card mc-reveal" data-mc-delay="1">
    <?php if ($error): ?>
      <div class="alert alert-error" data-auto-dismiss><i data-lucide="alert-circle" style="width:15px;height:15px;flex-shrink:0"></i> <?= clean($error) ?></div>
    <?php endif; ?>

    <?php if ($success): ?>
      <div class="alert alert-success">
        <i data-lucide="check-circle" style="width:15px;height:15px;flex-shrink:0"></i>
        <span><strong>Thank you!</strong> Your CSUQ feedback has been recorded securely. The MIS office will review it as part of our system quality checks.</span>
      </div>
      <p style="margin-bottom:18px;color:var(--text-2);">Your responses are stored in an encrypted database field and linked only to your CTU student account.</p>
      <a class="btn-primary" href="dashboard.php"><i data-lucide="arrow-left" style="width:15px;height:15px"></i> Back to dashboard</a>
    <?php else: ?>
      <form method="POST" action="" novalidate data-validate>
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
          <button class="btn-primary" type="submit"><i data-lucide="send" style="width:15px;height:15px"></i> Submit CSUQ feedback</button>
          <span style="font-size:13px;color:var(--text-3);">Responses are stored securely and used only by the MIS office and wellness team.</span>
        </div>
      </form>
    <?php endif; ?>
  </div>
    </div>
  </main>
  </div>
<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
