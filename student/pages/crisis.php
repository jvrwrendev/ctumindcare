<?php
// ============================================================
//  CTU MindCare — Crisis Help
//  File: student/pages/crisis.php
// ============================================================
require_once '../../includes/auth.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$error   = '';
$success = '';

// ── SUBMIT CRISIS ALERT ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_alert'])) {
    $message = trim($_POST['message'] ?? '');

    // Check for recent alert (within last 10 minutes) to prevent spam
    $stmt = $db->prepare("
        SELECT id FROM crisis_alerts
        WHERE student_id = ?
          AND status IN ('open','acknowledged')
          AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        LIMIT 1
    ");
    $stmt->execute([$user['id']]);
    $recent = $stmt->fetch();

    if ($recent) {
        $success = 'already_sent';
    } else {
        // Insert crisis alert
        $stmt = $db->prepare("
            INSERT INTO crisis_alerts (student_id, message) VALUES (?, ?)
        ");
        $stmt->execute([$user['id'], $message ?: null]);

        // Notify all wellness admins
        $stmt = $db->prepare("
            SELECT id FROM wellness_admins WHERE is_active = 1
        ");
        $stmt->execute();
        $admins = $stmt->fetchAll();

        $notif_stmt = $db->prepare("
            INSERT INTO notifications (user_id, type, message) VALUES (?, 'crisis', ?)
        ");
        $alert_msg = "🚨 CRISIS ALERT: {$user['first_name']} {$user['last_name']} needs immediate help."
                   . ($message ? " Message: \"" . mb_substr($message, 0, 100) . "\"" : '');
        foreach ($admins as $admin) {
            $notif_stmt->execute([$admin['id'], $alert_msg]);
        }

        $success = 'sent';
    }
}

// ── Past alerts by this student ───────────────────────────────
$stmt = $db->prepare("
    SELECT ca.id, ca.message, ca.status, ca.created_at, ca.resolved_at,
           u.first_name AS counselor_first, u.last_name AS counselor_last
    FROM crisis_alerts ca
    LEFT JOIN users u ON ca.acknowledged_by = u.id
    WHERE ca.student_id = ?
    ORDER BY ca.created_at DESC
    LIMIT 5
");
$stmt->execute([$user['id']]);
$past_alerts = $stmt->fetchAll();

// Open/acknowledged alert (active crisis)
$active_alert = null;
foreach ($past_alerts as $a) {
    if (in_array($a['status'], ['open', 'acknowledged'])) {
        $active_alert = $a;
        break;
    }
}

$mc_title = 'Crisis Help — CTU MindCare';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <?php require_once '../../includes/assets.php'; ?>
</head>
<body>

<!-- Topbar -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- Sidebar -->
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- Main -->
  <main class="main">

    <?php if ($flash): ?>
      <div class="flash-bar mc-reveal">
        <div class="flash flash-<?= $flash['type'] ?>">
          <i data-lucide="<?= $flash['type'] === 'success' ? 'check-circle' : 'alert-triangle' ?>"></i>
          <?= clean($flash['message']) ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="page-header mc-reveal">
      <div>
        <div class="page-title">Crisis Help</div>
        <div class="page-sub">Immediate support when you need it most. You are not alone.</div>
      </div>
    </div>

    <!-- Emergency banner — always visible -->
    <div class="emergency-banner mc-reveal">
      <span class="crisis-pulse"></span>
      <i data-lucide="siren"></i>
      <div style="flex:1;min-width:240px">
        <div style="font-size:13.5px;font-weight:600;color:var(--red-dark)">In immediate danger? Call emergency services right now.</div>
        <div class="crisis-text">PH Emergency: <strong>911</strong> &nbsp;·&nbsp; NBI: <strong>8523-8231</strong> &nbsp;·&nbsp; DOH: <strong>1555</strong></div>
      </div>
      <a class="btn btn-crisis" href="tel:911"><i data-lucide="phone"></i> Call 911</a>
    </div>

    <div class="page-grid">

      <!-- ── LEFT: Alert form + Coping + Past alerts ── -->
      <div>

        <?php if ($success === 'sent'): ?>
        <!-- Alert sent confirmation -->
        <div class="alert-sent-box crisis mc-reveal">
          <div class="asb-title"><i data-lucide="siren" style="width:18px;height:18px"></i> Alert sent to your counselors</div>
          <div class="asb-body">
            Your crisis alert has been sent to the CTU MindCare wellness team.
            A counselor will reach out to you as soon as possible.
            <strong>Please stay safe.</strong>
          </div>
          <div class="asb-status">
            While you wait, you can also call the hotlines on the right, or use the breathing exercise below to help stay calm.
          </div>
          <div class="asb-actions">
            <a class="btn btn-crisis" href="counselor-chat.php"><i data-lucide="message-circle"></i> Open counselor chat</a>
            <a class="btn btn-danger" href="crisis.php"><i data-lucide="rotate-ccw"></i> Send another alert</a>
          </div>
        </div>

        <?php elseif ($success === 'already_sent'): ?>
        <!-- Already has active alert -->
        <div class="active-alert-box mc-reveal">
          <div class="aab-icon"><i data-lucide="hourglass"></i></div>
          <div style="flex:1">
            <div class="alert-title">Alert already active</div>
            <div class="alert-sub">
              You already have an open crisis alert. Your counselor has been notified and will respond soon.
              If this is a life-threatening emergency, call <strong>911</strong> immediately.
            </div>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($active_alert && $success !== 'sent'): ?>
        <!-- Active alert status card -->
        <div class="active-alert-box mc-reveal">
          <div class="aab-icon"><i data-lucide="bell"></i></div>
          <div style="flex:1">
            <div class="alert-title">
              You have an active crisis alert
              <span class="sev-pill <?= $active_alert['status'] === 'acknowledged' ? 's-acknowledged' : 's-open' ?>" style="margin-left:6px">
                <?= ucfirst($active_alert['status']) ?>
              </span>
            </div>
            <div class="alert-sub">
              Sent <?= date('M j, Y \a\t g:i A', strtotime($active_alert['created_at'])) ?>.
              <?php if ($active_alert['status'] === 'acknowledged'): ?>
                A counselor has acknowledged your alert and will contact you shortly.
              <?php else: ?>
                A counselor will respond as soon as possible.
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- Alert form -->
        <?php if (!$active_alert || $success === 'sent'): ?>
        <div class="alert-form-card crisis mc-reveal">
          <div class="af-title"><i data-lucide="siren"></i> Send a crisis alert</div>
          <div class="af-sub">
            This will immediately notify all CTU MindCare counselors that you need urgent help.
            They will reach out to you as soon as possible.
          </div>
          <form method="POST" action="crisis.php">
            <label class="form-label" for="crisis_message">
              What's happening?
              <span style="font-weight:400;color:var(--text-3)">(optional — brief description helps counselors respond faster)</span>
            </label>
            <textarea
              class="form-textarea"
              id="crisis_message"
              name="message"
              placeholder="e.g. I'm feeling overwhelmed and can't cope right now…"
              maxlength="500"
              rows="3"
            ></textarea>
            <button type="submit" name="send_alert" class="btn btn-alert crisis">
              <i data-lucide="siren"></i> Send crisis alert now
            </button>
            <div class="alert-note">
              Your name and student ID will be shared with the counseling team.
              For life-threatening emergencies, call <strong>911</strong> immediately.
            </div>
          </form>
        </div>
        <?php else: ?>
        <!-- Still show the form even with active alert, just in different state -->
        <div class="alert-form-card crisis mc-reveal" style="opacity:.6;pointer-events:none">
          <div class="af-title"><i data-lucide="siren"></i> Send a crisis alert</div>
          <div class="af-sub">Your alert has already been sent. A counselor will contact you soon.</div>
        </div>
        <?php endif; ?>

        <!-- Breathing exercise -->
        <div class="card breathe-card mc-reveal">
          <div class="breathe-title"><i data-lucide="wind" style="width:17px;height:17px;vertical-align:-2px"></i> 4-7-8 Breathing Exercise</div>
          <div class="breathe-sub">A calming technique to reduce anxiety in moments of crisis</div>
          <div class="breathe-circle-wrap">
            <div class="breathe-circle" id="breatheCircle"></div>
            <div class="breathe-count" id="breatheCount">4</div>
          </div>
          <div class="breathe-instruction" id="breatheInstruction">Tap start to begin</div>
          <button class="btn btn-primary" onclick="startBreathe()" id="breatheBtn">
            <i data-lucide="play"></i> Start exercise
          </button>
        </div>

        <!-- Coping strategies -->
        <div class="card mc-reveal">
          <div class="card-title">Immediate coping strategies</div>
          <div class="card-sub">Things you can do right now while waiting for support.</div>
          <div class="coping-grid">
            <div class="coping-item">
              <div class="ci-icon"><i data-lucide="eye"></i></div>
              <div class="ci-title">5-4-3-2-1 Grounding</div>
              <div class="ci-body">Name 5 things you see, 4 you can touch, 3 you hear, 2 you smell, 1 you taste.</div>
            </div>
            <div class="coping-item">
              <div class="ci-icon"><i data-lucide="snowflake"></i></div>
              <div class="ci-title">Cold water</div>
              <div class="ci-body">Splash cold water on your face or hold a cold glass. It triggers a calming reflex.</div>
            </div>
            <div class="coping-item">
              <div class="ci-icon"><i data-lucide="footprints"></i></div>
              <div class="ci-title">Safe space</div>
              <div class="ci-body">Move to a quiet, safe place — a room, a bench outside, somewhere you feel secure.</div>
            </div>
            <div class="coping-item">
              <div class="ci-icon"><i data-lucide="phone"></i></div>
              <div class="ci-title">Talk to someone</div>
              <div class="ci-body">Call a trusted friend, family member, or use the hotlines on the right.</div>
            </div>
            <div class="coping-item">
              <div class="ci-icon"><i data-lucide="pen-line"></i></div>
              <div class="ci-title">Write it out</div>
              <div class="ci-body">Write down what you're feeling. Getting it out of your head can bring immediate relief.</div>
            </div>
            <div class="coping-item">
              <div class="ci-icon"><i data-lucide="music"></i></div>
              <div class="ci-title">Music or noise</div>
              <div class="ci-body">Put on calming music or a familiar show. External sound can interrupt spiraling thoughts.</div>
            </div>
          </div>
        </div>

        <!-- Past alerts -->
        <?php if ($past_alerts): ?>
        <div class="card mc-reveal">
          <div class="card-title">Your past crisis alerts</div>
          <div class="card-sub">History of alerts you've sent to the wellness team.</div>
          <div class="past-alerts-list">
            <?php foreach ($past_alerts as $a):
              $s_cls = match($a['status']) {
                  'open'         => 's-open',
                  'acknowledged' => 's-acknowledged',
                  'resolved'     => 's-resolved',
                  default        => 's-open',
              };
            ?>
              <div class="past-alert-item">
                <span class="sev-pill <?= $s_cls ?>"><?= ucfirst($a['status']) ?></span>
                <div style="flex:1;min-width:0">
                  <?php if ($a['message']): ?>
                    <div class="pai-msg">"<?= clean(mb_substr($a['message'], 0, 120)) ?><?= mb_strlen($a['message']) > 120 ? '…' : '' ?>"</div>
                  <?php else: ?>
                    <div class="pai-msg" style="font-style:normal;color:var(--text-3)">No message added.</div>
                  <?php endif; ?>
                  <div class="pai-time"><?= date('M j, Y · g:i A', strtotime($a['created_at'])) ?></div>
                  <?php if ($a['counselor_first']): ?>
                    <div class="pai-counselor">
                      <i data-lucide="check-circle"></i>
                      Responded by <?= clean($a['counselor_first'].' '.$a['counselor_last']) ?>
                      <?php if ($a['resolved_at']): ?>
                        · Resolved <?= date('M j', strtotime($a['resolved_at'])) ?>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

      </div>

      <!-- ── RIGHT: Hotlines + Quick links ── -->
      <div>

        <!-- National hotlines -->
        <div class="card mc-reveal">
          <div class="card-title">Crisis hotlines</div>
          <div class="card-sub">Available 24/7. You are not alone.</div>
          <div style="display:flex;flex-direction:column;gap:10px">

            <div class="hotline-item emergency">
              <div class="hi-icon"><i data-lucide="siren"></i></div>
              <div class="hi-info">
                <div class="hi-name">Emergency Services</div>
                <div class="hi-desc">Life-threatening emergencies, immediate danger</div>
                <div class="hi-nums">
                  <a class="hi-num" href="tel:911"><i data-lucide="phone"></i> 911</a>
                </div>
              </div>
            </div>

            <div class="hotline-item">
              <div class="hi-icon"><i data-lucide="heart"></i></div>
              <div class="hi-info">
                <div class="hi-name">Hopeline Philippines</div>
                <div class="hi-desc">24/7 suicide prevention &amp; emotional support</div>
                <div class="hi-nums">
                  <a class="hi-num" href="tel:028044673"><i data-lucide="phone"></i> 02-804-4673</a>
                  <a class="hi-num" href="tel:09175844673"><i data-lucide="smartphone"></i> 0917-584-4673</a>
                </div>
              </div>
            </div>

            <div class="hotline-item">
              <div class="hi-icon"><i data-lucide="handshake"></i></div>
              <div class="hi-info">
                <div class="hi-name">In Touch Community Services</div>
                <div class="hi-desc">Crisis intervention &amp; mental health support</div>
                <div class="hi-nums">
                  <a class="hi-num" href="tel:09178001123"><i data-lucide="smartphone"></i> 0917-800-1123</a>
                  <a class="hi-num" href="tel:09228938944"><i data-lucide="smartphone"></i> 0922-893-8944</a>
                </div>
              </div>
            </div>

            <div class="hotline-item">
              <div class="hi-icon"><i data-lucide="hospital"></i></div>
              <div class="hi-info">
                <div class="hi-name">DOH Mental Health</div>
                <div class="hi-desc">Department of Health mental wellness hotline</div>
                <div class="hi-nums">
                  <a class="hi-num blue" href="tel:1555"><i data-lucide="phone"></i> 1555</a>
                </div>
              </div>
            </div>

            <div class="hotline-item">
              <div class="hi-icon"><i data-lucide="smartphone"></i></div>
              <div class="hi-info">
                <div class="hi-name">Crisis Text Line</div>
                <div class="hi-desc">Text-based crisis support — free &amp; confidential</div>
                <div class="hi-nums">
                  <span class="hi-num green"><i data-lucide="message-square"></i> Text HELLO to 4673</span>
                </div>
              </div>
            </div>

            <div class="hotline-item">
              <div class="hi-icon"><i data-lucide="graduation-cap"></i></div>
              <div class="hi-info">
                <div class="hi-name">CTU Guidance Office</div>
                <div class="hi-desc">On-campus counseling &amp; student welfare services</div>
                <div class="hi-nums">
                  <a class="hi-num green" href="counselor-chat.php"><i data-lucide="message-circle"></i> Chat now</a>
                  <a class="hi-num green" href="appointments.php"><i data-lucide="calendar"></i> Book session</a>
                </div>
              </div>
            </div>

          </div>
        </div>

        <!-- Quick actions -->
        <div class="card mc-reveal" style="margin-top:16px">
          <div class="card-title">Quick actions</div>
          <div class="card-sub">Ways to reach support right now.</div>
          <div class="actions-grid">
            <a class="quick-item" href="counselor-chat.php">
              <span class="qi-icon"><i data-lucide="message-circle"></i></span>
              <span class="qi-label">Chat with a counselor now</span>
            </a>
            <a class="quick-item" href="appointments.php">
              <span class="qi-icon"><i data-lucide="calendar"></i></span>
              <span class="qi-label">Book an appointment</span>
            </a>
            <a class="quick-item" href="resources.php?category=crisis">
              <span class="qi-icon"><i data-lucide="book-open"></i></span>
              <span class="qi-label">Crisis resources library</span>
            </a>
            <a class="quick-item" href="peer-chat.php">
              <span class="qi-icon"><i data-lucide="messages-square"></i></span>
              <span class="qi-label">Talk to a peer</span>
            </a>
          </div>
        </div>

        <!-- Privacy note -->
        <div class="info-note mc-reveal" style="margin-top:16px">
          <i data-lucide="lock" style="width:15px;height:15px;flex-shrink:0;margin-top:2px"></i>
          <div>
            <strong>Your privacy matters.</strong> Crisis alerts are only visible to CTU MindCare counselors and wellness admins — not other students, faculty, or staff. Seeking help is a sign of strength.
          </div>
        </div>

      </div>

    </div>

  </main>
</div>

<script>
// ── 4-7-8 Breathing exercise ───────────────────────────────────
let breatheTimer  = null;
let breatheCount  = 0;
let breatheCycles = 0;
const MAX_CYCLES  = 4;

const phases = [
  { label: 'Breathe in…', count: 4,  cls: '',      btn: 'Breathing in…' },
  { label: 'Hold…',       count: 7,  cls: '',      btn: 'Holding…'      },
  { label: 'Breathe out…',count: 8,  cls: 'small', btn: 'Breathing out…'},
];

function startBreathe() {
  if (breatheTimer) {
    // Stop
    clearInterval(breatheTimer);
    breatheTimer = null;
    resetBreathe();
    return;
  }
  breatheCycles = 0;
  runPhase(0);
  document.getElementById('breatheBtn').innerHTML = '<i data-lucide="square"></i> Stop';
}

function runPhase(phaseIdx) {
  const phase  = phases[phaseIdx];
  const circle = document.getElementById('breatheCircle');
  const instr  = document.getElementById('breatheInstruction');
  const cntEl  = document.getElementById('breatheCount');

  circle.className = 'breathe-circle' + (phase.cls ? ' ' + phase.cls : '');
  instr.textContent = phase.label;
  let secs = phase.count;
  cntEl.textContent = secs + 's';

  breatheTimer = setInterval(() => {
    secs--;
    cntEl.textContent = secs > 0 ? secs + 's' : '';
    if (secs <= 0) {
      clearInterval(breatheTimer);
      breatheTimer = null;
      const next = phaseIdx + 1;
      if (next < phases.length) {
        runPhase(next);
      } else {
        breatheCycles++;
        if (breatheCycles >= MAX_CYCLES) {
          // Done
          circle.className = 'breathe-circle';
          instr.textContent = 'Well done!';
          cntEl.textContent = `Completed ${MAX_CYCLES} cycles. Take a moment to notice how you feel.`;
          document.getElementById('breatheBtn').innerHTML = '<i data-lucide="play"></i> Start again';
          breatheTimer = null;
        } else {
          // Next cycle
          runPhase(0);
        }
      }
    }
  }, 1000);
}

function resetBreathe() {
  const circle = document.getElementById('breatheCircle');
  const instr  = document.getElementById('breatheInstruction');
  const cntEl  = document.getElementById('breatheCount');
  circle.className = 'breathe-circle';
  cntEl.textContent = '4';
  instr.textContent = 'Tap start to begin';
  document.getElementById('breatheBtn').innerHTML = '<i data-lucide="play"></i> Start exercise';
  breatheCycles = 0;
}

// Auto-start breathing if alert was just sent (helps with distress)
<?php if ($success === 'sent'): ?>
setTimeout(() => {
  document.getElementById('breatheCircle')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}, 1500);
<?php endif; ?>
</script>

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
