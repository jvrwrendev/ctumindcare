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
$mc_title = 'Assessments';
require_once '../../includes/assets.php';
?>
<body>
<!-- ── Topbar ── -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- ── Main content ── -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash-<?= $flash['type'] ?>" data-auto-dismiss>
        <?php
        $flash_icon = $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'warning' ? 'alert-triangle' : 'alert-circle');
        ?>
        <i data-lucide="<?= $flash_icon ?>"></i>
        <?= clean($flash['message']) ?>
      </div>
    <?php endif; ?>

    <?php if ($trigger === 'auto'): ?>
      <div class="alert-banner mc-reveal">
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
    <div class="page-header mc-reveal">
      <div class="page-title">Assessment complete</div>
      <div class="page-sub">Here are your results for the <?= clean($rtpl['title']) ?>.</div>
    </div>

    <div class="result-wrap">
      <!-- Score hero -->
      <div class="result-hero mc-reveal" data-mc-delay="1" style="background:<?= severityBg($rs) ?>;border:1px solid <?= severityBorder($rs) ?>">
        <div class="result-emoji"><?= $emoji_map[$rs] ?? '📋' ?></div>
        <div class="result-score" style="color:<?= severityColor($rs) ?>"><?= $rsc ?></div>
        <div class="result-maxscore">out of <?= $rtpl['max_score'] ?></div>
        <div class="result-sev" style="color:<?= severityColor($rs) ?>"><?= severityLabel($rs) ?></div>
        <div class="result-tpl"><?= clean($rtpl['title']) ?></div>
      </div>

      <!-- Advice -->
      <div class="advice-card mc-reveal" data-mc-delay="2" style="background:<?= severityBg($rs) ?>;border:1px solid <?= severityBorder($rs) ?>">
        <div class="advice-icon">💡</div>
        <div>
          <div class="advice-title" style="color:<?= severityColor($rs) ?>">What this means</div>
          <div class="advice-body" style="color:<?= severityColor($rs) ?>"><?= severityAdvice($rtpl['code'], $rs) ?></div>
        </div>
      </div>

      <!-- Score ranges -->
      <?php $ranges = severityRanges($rtpl['code']); if ($ranges): ?>
      <div class="ranges-card mc-reveal" data-mc-delay="3">
        <div class="ranges-title">Score interpretation guide</div>
        <div class="ranges-list">
          <?php foreach ($ranges as $r): ?>
            <div class="range-row <?= $r['sev'] === $rs ? 'current' : '' ?>">
              <div class="range-dot" style="background:<?= severityColor($r['sev']) ?>"></div>
              <div class="range-label"><?= $r['label'] ?></div>
              <div class="range-score"><?= $r['range'] ?></div>
              <?php if ($r['sev'] === $rs): ?>
                <div style="font-size:10px;font-weight:600;color:<?= severityColor($rs) ?>"><i data-lucide="arrow-left" style="width:10px;height:10px"></i> Your result</div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- CTA buttons -->
      <div class="result-actions mc-reveal" data-mc-delay="4">
        <?php if (in_array($rs, ['moderate','moderately_severe','severe'])): ?>
          <a class="btn-primary" href="appointments.php"><i data-lucide="calendar" style="width:15px;height:15px"></i> Book a counseling session</a>
        <?php endif; ?>
        <a class="btn-outline-btn" href="assessment.php">Take another assessment</a>
        <a class="btn-outline-btn" href="dashboard.php">Back to dashboard</a>
        <?php if (in_array($rs, ['moderately_severe','severe'])): ?>
          <a class="btn-outline-red" href="crisis.php"><i data-lucide="phone-call" style="width:14px;height:14px"></i> Crisis help</a>
        <?php endif; ?>
      </div>
    </div>

    <?php else: ?>
    <!-- ══ TAKE ASSESSMENT VIEW ══════════════════════════════════ -->

    <div class="page-header mc-reveal">
      <div class="page-title">Wellness assessments</div>
      <div class="page-sub">
        These standardized tools help track your mental wellness.<br>
        Your results are confidential and shared only with your assigned counselor.
      </div>
    </div>

    <div class="assess-grid">

      <!-- Left: Template picker -->
      <div class="mc-reveal" data-mc-delay="1">
        <div class="card">
          <div class="card-title"><i data-lucide="clipboard-list"></i> Choose an assessment</div>

          <?php
          $tpl_icons = ['PHQ9'=>'😔','GAD7'=>'😰','PSS10'=>'🧠'];
          foreach ($templates as $t):
            $is_active = ($t['id'] === $selected_id);
          ?>
            <a class="tpl-item <?= $is_active ? 'active' : '' ?>"
               href="assessment.php?id=<?= $t['id'] ?>">
              <div class="tpl-icon"><?= $tpl_icons[$t['code']] ?? '📋' ?></div>
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
          <div class="card-title"><i data-lucide="history"></i> Recent results</div>
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
              +<?= count($past_results)-4 ?> more — <a href="progress.php" class="mc-link">view all</a>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Right: Form -->
      <div class="mc-reveal" data-mc-delay="2">
        <?php if ($template && $questions): ?>

          <?php if ($error): ?>
            <div class="alert alert-error" data-auto-dismiss><i data-lucide="alert-circle" style="width:15px;height:15px;flex-shrink:0"></i> <?= clean($error) ?></div>
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
              <span class="afc-badge"><?= $template['total_items'] ?> questions</span>
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
                  <div class="alert alert-error" style="margin-top:16px">
                    <i data-lucide="alert-triangle" style="width:15px;height:15px;flex-shrink:0"></i>
                    <span><strong>Note on question 9:</strong> If you're having thoughts of self-harm or suicide, please reach out to a counselor immediately or visit <a href="crisis.php" class="mc-link">Crisis Help</a>.</span>
                  </div>
                <?php endif; ?>

              </div><!-- .afc-body -->

              <div class="form-footer">
                <div class="ff-note">
                  All answers are <strong>confidential</strong>. Take your time.
                </div>
                <button type="submit" name="submit_assessment" class="btn-submit" id="submitBtn" disabled>
                  <i data-lucide="check" style="width:15px;height:15px"></i> Submit assessment
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
    <div class="card history-card mc-reveal">
      <div class="card-title"><i data-lucide="history"></i> Assessment history</div>
      <div style="overflow-x:auto">
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

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
