<?php
// ============================================================
//  CTU MindCare — Student Appointments
//  File: student/pages/appointments.php
// ============================================================
require_once '../../includes/auth.php';
require_once '../../includes/counselor_availability.php';
requireRole('student');

$user = currentUser();
$db   = getDB();

$error   = '';
$success = '';

// ── Load all wellness counselors (offline ones remain bookable) ─
$stmt = $db->prepare("
    SELECT id, first_name, last_name, email
    FROM wellness_admins
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
        } elseif (!mcSlotInWindow($db, $counselor_id, $appt_date, $appt_time)) {
            $error = "That time slot is outside this counselor's availability. Please choose an available slot.";
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

// ── Per-counselor availability windows for the calendar ───────
$window_index = mcAvailabilityHorizon($db, $counselors, 60);

$flash = getFlash();

// ── Helpers ──────────────────────────────────────────────────
function statusBadge(string $s): array {
    return match($s) {
        'pending'   => ['cls' => 'badge-pending',   'label' => 'Pending'],
        'confirmed' => ['cls' => 'badge-confirmed', 'label' => 'Confirmed'],
        'cancelled' => ['cls' => 'badge-cancelled', 'label' => 'Cancelled'],
        'completed' => ['cls' => 'badge-completed', 'label' => 'Completed'],
        default     => ['cls' => 'badge-gray',      'label' => ucfirst($s)],
    };
}
function statusIcon(string $s): string {
    return match($s) {
        'pending'   => 'clock',
        'confirmed' => 'check-circle',
        'cancelled' => 'x-circle',
        'completed' => 'graduation-cap',
        default     => 'circle',
    };
}
$mc_title = 'Appointments';
require_once '../../includes/assets.php';
?>
<body>

<!-- ── Topbar ── -->
<?php require_once '../../includes/topbar.php'; ?>

<div class="layout">

  <!-- ── Sidebar ── -->
  <?php require_once '../../includes/sidebar.php'; ?>

<!-- ── Main ── -->
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

    <div class="page-header mc-reveal">
      <div class="page-title">Book an Appointment</div>
      <div class="page-sub">Schedule a one-on-one session with a wellness counselor. Sessions are confidential.</div>
    </div>

    <div class="page-grid">

      <!-- ══ LEFT: upcoming + history ═══════════════════════════ -->
      <div style="display:flex;flex-direction:column;gap:20px">

        <!-- Upcoming -->
        <div class="card mc-reveal" data-mc-delay="1">
          <div class="card-title"><i data-lucide="calendar"></i> Upcoming appointments</div>
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
                      <i data-lucide="<?= statusIcon($a['status']) ?>" style="width:14px;height:14px;color:var(--text-3)"></i>
                      <?= clean($a['c_first'] . ' ' . $a['c_last']) ?>
                    </div>
                    <div class="appt-time">
                      <i data-lucide="clock" style="width:12px;height:12px"></i>
                      <?= date('g:i A', strtotime($a['appointment_time'])) ?>
                      &nbsp;·&nbsp; <?= date('l, F j, Y', strtotime($a['appointment_date'])) ?>
                    </div>
                    <?php if ($a['purpose']): ?>
                      <div class="appt-purpose">"<?= clean($a['purpose']) ?>"</div>
                    <?php endif; ?>
                    <?php if ($a['counselor_notes'] && $a['status'] === 'confirmed'): ?>
                      <div class="appt-notes"><i data-lucide="sticky-note" style="width:12px;height:12px"></i> Counselor note: <?= clean($a['counselor_notes']) ?></div>
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
                  <span class="appt-status <?= $badge['cls'] ?>">
                    <i data-lucide="<?= statusIcon($a['status']) ?>" style="width:11px;height:11px"></i>
                    <?= $badge['label'] ?>
                  </span>
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
        <div class="card mc-reveal" data-mc-delay="2">
          <div class="card-title"><i data-lucide="history"></i> Appointment history</div>
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
                      <span class="badge <?= $badge['cls'] ?>">
                        <i data-lucide="<?= statusIcon($a['status']) ?>"></i> <?= $badge['label'] ?>
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
        <div class="info-tip mc-reveal" data-mc-delay="3">
          <div class="info-tip-icon">💜</div>
          <div>
            <strong>All sessions are confidential.</strong> Your counselor will not share session details with anyone outside the wellness team without your consent, except in cases where safety is a concern.
          </div>
        </div>

      </div>

      <!-- ══ RIGHT: booking form ══════════════════════════════════ -->
      <div class="mc-reveal" data-mc-delay="1">
        <div class="card" style="position:sticky;top:76px">
          <div class="card-title"><i data-lucide="calendar-plus"></i> Schedule a session</div>
          <div class="card-sub">Available Monday – Friday, 9:00 AM – 5:00 PM</div>

          <?php if ($error): ?>
            <div class="alert alert-error" data-auto-dismiss><i data-lucide="alert-circle" style="width:15px;height:15px;flex-shrink:0"></i> <?= clean($error) ?></div>
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

          <form method="POST" action="appointments.php" id="bookForm" data-validate>
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
                  <div class="counselor-card compact <?= $preselected ? 'selected' : '' ?>"
                       data-id="<?= $c['id'] ?>"
                       data-name="<?= clean($c['first_name'].' '.$c['last_name']) ?>"
                       onclick="selectCounselor(this)">
                    <div class="counselor-av"><?= $initials ?></div>
                    <div style="flex:1">
                      <div class="counselor-name"><?= clean($c['first_name'].' '.$c['last_name']) ?></div>
                      <div class="counselor-role">Wellness Counselor</div>
                    </div>
                    <div class="counselor-check" id="chk_<?= $c['id'] ?>"><i data-lucide="check" style="width:11px;height:11px"></i></div>
                  </div>
                <?php endforeach; ?>
              </div>
              <button type="button" class="btn-next" id="btnNext1" onclick="goStep(2)" disabled>
                Next: Pick a date &amp; time <i data-lucide="arrow-right" style="width:15px;height:15px"></i>
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
              <div class="form-hint" style="margin-top:6px">Greyed-out slots are unavailable for this counselor &amp; date (already booked or outside their set availability).</div>

              <div style="display:flex;gap:10px;margin-top:18px">
                <button type="button" class="btn-back-step" onclick="goStep(1)"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</button>
                <button type="button" class="btn-next" id="btnNext2" onclick="goStep(3)" disabled style="flex:1">
                  Next: Review <i data-lucide="arrow-right" style="width:15px;height:15px"></i>
                </button>
              </div>
            </div>

            <!-- ══ STEP 3: Purpose + confirm ═════════════════════ -->
            <div id="step3panel" style="display:none">

              <!-- Full summary -->
              <div class="summary-panel show" id="summaryPanel">
                <div class="summary-title"><i data-lucide="clipboard-check" style="width:15px;height:15px"></i> Appointment summary</div>
                <div class="summary-row">
                  <span class="summary-icon"><i data-lucide="user"></i></span>
                  <div><div class="summary-key">Counselor</div><div class="summary-val" id="sum-counselor">—</div></div>
                </div>
                <div class="summary-row">
                  <span class="summary-icon"><i data-lucide="calendar"></i></span>
                  <div><div class="summary-key">Date</div><div class="summary-val" id="sum-date">—</div></div>
                </div>
                <div class="summary-row">
                  <span class="summary-icon"><i data-lucide="clock"></i></span>
                  <div><div class="summary-key">Time</div><div class="summary-val" id="sum-time">—</div></div>
                </div>
              </div>

              <div class="form-group" style="margin-top:16px">
                <label class="form-label" for="purposeInput">
                  Reason for visit
                  <span class="form-label-opt">(optional)</span>
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
                <button type="button" class="btn-back-step" onclick="goStep(2)"><i data-lucide="arrow-left" style="width:14px;height:14px"></i> Back</button>
                <button type="submit" class="btn-book" id="bookBtn" style="flex:1" onclick="prepareSubmit()">
                  <i data-lucide="calendar-check" style="width:15px;height:15px"></i> &nbsp;Confirm &amp; book appointment
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
const windowIndex = <?= json_encode($window_index) ?>;
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
    if (recap) recap.innerHTML = `<i data-lucide="user" style="width:15px;height:15px;flex-shrink:0"></i> <span>Seeing <strong>${selCounselorName}</strong></span>`;
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

// ── Grey out booked / unavailable time slots ─────────────────
function updateSlots() {
  const booked = (selCounselorId && selDate &&
                  bookedIndex[selCounselorId] && bookedIndex[selCounselorId][selDate])
    ? bookedIndex[selCounselorId][selDate] : [];

  const win = (selCounselorId && selDate &&
               windowIndex[selCounselorId] && windowIndex[selCounselorId][selDate])
    ? windowIndex[selCounselorId][selDate] : null;

  document.querySelectorAll('.time-slot-radio').forEach(input => {
    const label = input.nextElementSibling;
    const tval  = input.value.substring(0, 5);
    const unavailable = (win === 'blocked') ||
                        (win && win !== 'blocked' && (tval < win.start || tval > win.end));
    const isUnavailable = booked.includes(tval) || unavailable;
    if (label) label.classList.toggle('booked', isUnavailable);
    input.disabled = isUnavailable;
    if (isUnavailable && input.checked) { input.checked = false; selTime = ''; checkStep2Ready(); }
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

<?php require_once '../../includes/chatbot_widget.php'; ?>
</body>
</html>
