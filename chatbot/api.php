<?php
// ============================================================
//  CTU MindCare — AI Mental Health Assistant API
//  File: chatbot/api.php
//
//  A 24/7 first-line support system. When no human counselor
//  is online, the AI assists immediately: supportive
//  conversations, trusted guidance, urgency assessment, and
//  appointment scheduling. The AI is NOT a replacement for
//  licensed counselors.
//
//  Role-gated: students and wellness admins only.
// ============================================================
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/counselor_ai.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = currentUser();
$role = $_SESSION['user_role'] ?? '';

if (!in_array($role, ['student', 'wellness_admin'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied.']);
    exit;
}

$db = getDB();
ensureTables($db);

// ── Helpers ────────────────────────────────────────────────────
function ensureTables(PDO $db): void
{
    $db->exec("
        CREATE TABLE IF NOT EXISTS chatbot_messages (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            user_role  VARCHAR(20) NOT NULL,
            sender     ENUM('user','bot') NOT NULL,
            message    TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS chatbot_booking_requests (
            id             INT AUTO_INCREMENT PRIMARY KEY,
            student_id     INT NOT NULL,
            preferred_date DATE NULL,
            preferred_time TIME NULL,
            topic          VARCHAR(255) NULL,
            status         ENUM('waiting','notified','fulfilled','cancelled') DEFAULT 'waiting',
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
            notified_at    DATETIME NULL,
            INDEX (student_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $db->exec("
        CREATE TABLE IF NOT EXISTS chatbot_risk_flags (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            risk       ENUM('medium','high') NOT NULL,
            reason     VARCHAR(255) NULL,
            message_id INT NULL,
            status     ENUM('open','reviewed') DEFAULT 'open',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (student_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Migrate: add risk column to chatbot_messages if missing
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_messages' AND COLUMN_NAME = 'risk'");
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE chatbot_messages ADD COLUMN risk ENUM('low','medium','high') DEFAULT 'low' AFTER sender");
    }
}

function activeCounselors(PDO $db): array
{
    // Online counselors only — is_active is the live auth-state flag
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, specialization FROM wellness_admins WHERE is_active = 1 ORDER BY first_name ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

/** All wellness counselors — booking availability must include offline counselors. */
function allCounselors(PDO $db): array
{
    $stmt = $db->prepare("SELECT id, first_name, last_name, email, specialization FROM wellness_admins ORDER BY first_name ASC");
    $stmt->execute();
    return $stmt->fetchAll();
}

function timeSlots(): array
{
    $slots = [];
    for ($h = 9; $h < 17; $h++) {
        $slots[] = sprintf('%02d:00:00', $h);
        $slots[] = sprintf('%02d:30:00', $h);
    }
    return $slots;
}

function nextWeekdays(int $count = 5): array
{
    $days = [];
    $d = new DateTimeImmutable('tomorrow');
    while (count($days) < $count) {
        if ((int)$d->format('N') <= 5) $days[] = $d->format('Y-m-d');
        $d = $d->modify('+1 day');
    }
    return $days;
}

/** Parse free-text date input into Y-m-d (future weekday only), or null. */
function parseDateInput(string $s): ?string
{
    $s = strtolower(trim($s));
    if ($s === '') return null;

    $ts = strtotime($s);
    if ($ts !== false) {
        if (date('Y-m-d', $ts) < date('Y-m-d')) return null;
        if ((int)date('N', $ts) >= 6) return null;
        return date('Y-m-d', $ts);
    }

    // "15" / "15th" → next occurrence of that day-of-month
    if (preg_match('/^(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)?$/', $s, $m)) {
        $day = (int)$m[1];
        if ($day < 1 || $day > 31) return null;
        $d = new DateTimeImmutable('tomorrow');
        for ($i = 0; $i < 40; $i++) {
            if ((int)$d->format('N') <= 5 && (int)$d->format('j') === $day) {
                return $d->format('Y-m-d');
            }
            $d = $d->modify('+1 day');
        }
    }
    return null;
}

/** Parse free-text time input into an H:i:s slot, or null. */
function parseTimeInput(string $s): ?string
{
    $s = strtolower(trim($s));
    if ($s === 'noon') $s = '12:00 pm';

    if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/', $s, $m)) return null;
    $h    = (int)$m[1];
    $min  = isset($m[2]) && $m[2] !== '' ? (int)$m[2] : 0;
    $ampm = $m[3] ?? '';

    if ($h < 1 || $h > 12 || $min > 59) return null;

    if ($ampm !== '') {
        if ($ampm === 'pm' && $h !== 12) $h += 12;
        if ($ampm === 'am' && $h === 12) $h = 0;
    } else {
        // No am/pm: treat 1–8 as PM (e.g. "2" = 2pm), 9–16 as 24h
        if ($h >= 1 && $h <= 8) $h += 12;
    }

    $target = sprintf('%02d:%02d:00', $h, $min);
    // Snap to the nearest 30-min slot within working hours
    $best = null;
    foreach (timeSlots() as $slot) {
        if ($slot === $target) return $slot;
        if (strtotime($slot) < strtotime($target)) $best = $slot;
    }
    return $best; // nearest earlier slot (e.g. 2:10 pm → 2:00 pm)
}

/** Slots already taken for a counselor on a date. */
function bookedSlots(PDO $db, int $counselorId, string $date): array
{
    $stmt = $db->prepare("SELECT appointment_time FROM appointments WHERE counselor_id = ? AND appointment_date = ? AND status NOT IN ('cancelled')");
    $stmt->execute([$counselorId, $date]);
    return array_column($stmt->fetchAll(), 'appointment_time');
}

/** Slots available across the given counselors on a date. */
function availableSlots(PDO $db, array $counselors, string $date): array
{
    $slots = timeSlots();
    foreach ($counselors as $c) {
        foreach (bookedSlots($db, (int)$c['id'], $date) as $taken) {
            $idx = array_search($taken, $slots, true);
            if ($idx !== false) unset($slots[$idx]);
        }
    }
    $slots = array_values($slots);
    // Drop slots already past (for today only)
    if ($date === date('Y-m-d')) {
        $now = time();
        $slots = array_values(array_filter($slots, fn($s) => strtotime($s) > $now));
    }
    return $slots;
}

function notifyStudent(PDO $db, int $studentId, string $type, string $message): void
{
    $stmt = $db->prepare("INSERT INTO notifications (user_id, type, message) VALUES (?, ?, ?)");
    $stmt->execute([$studentId, $type, $message]);
}

function saveMessage(PDO $db, int $userId, string $role, string $sender, string $message, string $risk = 'low'): int
{
    $stmt = $db->prepare("INSERT INTO chatbot_messages (user_id, user_role, sender, risk, message) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$userId, $role, $sender, $risk, $message]);
    return (int)$db->lastInsertId();
}

// ── Urgency assessment ─────────────────────────────────────────
const CRISIS_PATTERNS = ['suicide', 'kill myself', 'kill myself', 'end my life', 'want to die', 'self harm', 'self-harm', 'hurt myself', 'no reason to live', 'take my life', 'going to hurt'];
const MEDIUM_RISK_PATTERNS = ['overwhelmed', "can't cope", 'cannot cope', 'panic attack', 'can\'t sleep', 'cant sleep', 'insomnia', 'hopeless', 'worthless', 'breakdown', 'breaking down', 'crying all the time', 'can\'t stop crying', 'so stressed', 'stressed out', 'anxious all the time', 'always anxious', 'isolated', 'so lonely', 'empty inside', 'no friends', 'giving up', 'give up', 'failing everything', 'miserable'];

function assessRisk(string $msg): array
{
    $lower = mb_strtolower($msg);
    foreach (CRISIS_PATTERNS as $p) {
        if (mb_stripos($lower, $p) !== false) {
            return ['level' => 'high', 'reason' => 'Crisis keywords detected: "' . $p . '"'];
        }
    }
    foreach (MEDIUM_RISK_PATTERNS as $p) {
        if (mb_stripos($lower, $p) !== false) {
            return ['level' => 'medium', 'reason' => 'Distress keywords detected: "' . $p . '"'];
        }
    }
    return ['level' => 'low', 'reason' => null];
}

function openCrisisAlertExists(PDO $db, int $studentId): bool
{
    $stmt = $db->prepare("SELECT id FROM crisis_alerts WHERE student_id = ? AND status = 'open' LIMIT 1");
    $stmt->execute([$studentId]);
    return (bool)$stmt->fetch();
}

function flagStudent(PDO $db, int $studentId, string $risk, string $reason, ?int $messageId): void
{
    // Dedup: don't spam flags for the same student on the same day
    $stmt = $db->prepare("SELECT id FROM chatbot_risk_flags WHERE student_id = ? AND DATE(created_at) = CURDATE() AND status = 'open' LIMIT 1");
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) return;
    $stmt = $db->prepare("INSERT INTO chatbot_risk_flags (student_id, risk, reason, message_id, status) VALUES (?, ?, ?, ?, 'open')");
    $stmt->execute([$studentId, $risk, $reason, $messageId]);
}

// ══════════════════════════════════════════════════════════════
//  GET — history, availability, notifications, greeting
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mcSweepStaleCounselors($db);
    $stmt = $db->prepare("SELECT id, sender, message FROM chatbot_messages WHERE user_id = ? AND user_role = ? ORDER BY id DESC LIMIT 50");
    $stmt->execute([$user['id'], $role]);
    $rows = array_reverse($stmt->fetchAll());

    $counselorsOnline = count(activeCounselors($db)) > 0;
    $pendingBooking   = false;

    if ($role === 'student') {
        $stmt = $db->prepare("SELECT id FROM chatbot_booking_requests WHERE student_id = ? AND status = 'waiting' LIMIT 1");
        $stmt->execute([$user['id']]);
        $pendingBooking = (bool)$stmt->fetch();

        // Notify when a counselor becomes available (request made while offline)
        if ($counselorsOnline && $pendingBooking) {
            notifyStudent($db, (int)$user['id'], 'general',
                "A wellness counselor is now available. You can book a session through the AI Assistant or the Appointments page. We're here for you!");
            $db->prepare("UPDATE chatbot_booking_requests SET status = 'notified', notified_at = NOW() WHERE student_id = ? AND status = 'waiting'")->execute([$user['id']]);
            $pendingBooking = false;
        }

        $greeting = $counselorsOnline
            ? 'Hi ' . $user['first_name'] . "! A wellness counselor is online right now — for anything urgent, they are ready to help. I'm also here 24/7 as your first-line support, so ask me anything."
            : 'Hi ' . $user['first_name'] . "! No counselor is online at the moment, but you don't have to wait — I'm here 24/7 as your first-line support. I can listen, answer wellness questions, and even help you book a session.";
    } else {
        $greeting = "Welcome back! I'm your AI support assistant for the Wellness Office. Ask me for guidance, drafting help, or wellness resources.";
    }

    echo json_encode([
        'messages'          => $rows,
        'greeting'          => $greeting,
        'counselors_online' => $counselorsOnline,
        'pending_booking'   => $pendingBooking,
    ]);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  POST — message handling
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$msg   = trim((string)($input['message'] ?? $_POST['message'] ?? ''));

if ($msg === '') {
    echo json_encode(['reply' => 'Please type a message so I can help you.']);
    exit;
}
if (mb_strlen($msg) > 1000) {
    echo json_encode(['reply' => 'That message is a bit long — could you shorten it to under 1,000 characters?']);
    exit;
}

// ── Save the user's message (with risk assessment) ────────────
$risk = assessRisk($msg);
$msgId = saveMessage($db, (int)$user['id'], $role, 'user', $msg, $risk['level']);

// ── Crisis path: instant, before anything else ────────────────
if ($risk['level'] === 'high') {
    $reply = "I'm really glad you reached out — what you're feeling matters, and you don't have to go through it alone. Please talk to someone right now:\n\n" .
        "• Hopeline: 02-804-4673\n" .
        "• Text HELLO to 4673\n" .
        "• National Center for Mental Health (24/7): 0917-899-8727\n\n" .
        "You can also open the Crisis Help page: " . SITE_URL . "/student/pages/crisis.php\n\n" .
        "I'm here with you, and I've also alerted the wellness office so a counselor can follow up with you as soon as possible. 💚";

    if ($role === 'student' && !openCrisisAlertExists($db, (int)$user['id'])) {
        $db->prepare("INSERT INTO crisis_alerts (student_id, message) VALUES (?, ?)")
           ->execute([(int)$user['id'], 'AI assistant flagged a crisis-level message: "' . mb_substr($msg, 0, 300) . '"']);
        flagStudent($db, (int)$user['id'], 'high', $risk['reason'], $msgId);
    }

    saveMessage($db, (int)$user['id'], $role, 'bot', $reply, 'low');
    echo json_encode(['reply' => $reply, 'intent' => 'crisis', 'chips' => [
        ['label' => '🆘 Open Crisis Help', 'value' => 'Open the crisis help page', 'url' => SITE_URL . '/student/pages/crisis.php'],
    ]]);
    exit;
}

// ── Students: booking / escalation / general handling ─────────
$reply   = '';
$chips   = [];
$actions = [];
$intent  = 'general';

if ($role === 'student') {
    $flow = handleStudentFlow($db, $user, $msg, $risk);
    $flow['chips']   = $flow['chips'] ?? [];
    $flow['actions'] = $flow['actions'] ?? [];

    // Medium risk: refer onward (override generic reply)
    if ($risk['level'] === 'medium') {
        flagStudent($db, (int)$user['id'], 'medium', $risk['reason'], $msgId);
        if ($flow['intent'] === 'general') {
            $flow['reply'] = $flow['reply'] . "\n\nYou sound like you're carrying a lot right now. I'd really encourage you to talk to a counselor — they can help in ways I can't. I can book you a session right now if you'd like.";
            $flow['chips'] = array_merge([['label' => '📅 Book an appointment', 'value' => 'book an appointment']], array_slice($flow['chips'], 0, 2));
        }
    }

    $reply   = $flow['reply'];
    $chips   = $flow['chips'];
    $actions = $flow['actions'];
    $intent  = $flow['intent'];
} else {
    $reply = generalAI($db, $user, $role, $msg, true);
}

saveMessage($db, (int)$user['id'], $role, 'bot', $reply, 'low');

echo json_encode(['reply' => $reply, 'intent' => $intent, 'chips' => $chips, 'actions' => $actions]);
exit;

// ══════════════════════════════════════════════════════════════
//  Student flow: booking state machine, escalation, general chat
// ══════════════════════════════════════════════════════════════
function handleStudentFlow(PDO $db, array $user, string $msg, array $risk): array
{
    $studentId = (int)$user['id'];
    $lower     = mb_strtolower($msg);
    $state     = $_SESSION['cb_booking'] ?? null;

    // ── Escalation intent: student wants a human ──────────────
    $escalatePatterns = ['talk to a human', 'talk to a person', 'real counselor', 'actual counselor', 'human counselor', 'speak to someone', 'talk to someone in person', 'face to face', 'i want a person', 'need a human'];
    foreach ($escalatePatterns as $p) {
        if (mb_stripos($lower, $p) !== false) {
            unset($_SESSION['cb_booking']);
            $counselors = activeCounselors($db);
            if ($counselors) {
                return [
                    'intent' => 'escalate',
                    'reply'  => "Of course — talking to a person can make a real difference. A counselor is online now: head to the Counselor Chat to message them directly, or book a face-to-face session with me and I'll set it up.",
                    'chips'  => [['label' => '💬 Open counselor chat', 'value' => 'open the counselor chat page', 'url' => SITE_URL . '/student/pages/counselor-chat.php'], ['label' => '📅 Book an appointment', 'value' => 'book an appointment']],
                ];
            }
            return [
                'intent' => 'escalate',
                'reply'  => "I understand — sometimes only a person will do. No counselor is online right now, but I'm here 24/7 in the meantime. I can book you a session with a counselor and you'll be notified the moment one is available.",
                'chips'  => [['label' => '📅 Book an appointment', 'value' => 'book an appointment'], ['label' => '🆘 Crisis help', 'value' => 'open the crisis help page', 'url' => SITE_URL . '/student/pages/crisis.php']],
            ];
        }
    }

    // ── Cancel keywords end any active booking flow ───────────
    $cancelPatterns = ['cancel', 'never mind', 'nevermind', 'forget it', 'stop the booking', 'abort', 'not anymore'];
    foreach ($cancelPatterns as $p) {
        if (mb_stripos($lower, $p) !== false && mb_strlen($msg) < 60) {
            unset($_SESSION['cb_booking']);
            return ['intent' => 'booking', 'reply' => "No problem — I've put the booking aside. I'm here whenever you need me. 💚"];
        }
    }

    // ── Booking intent (start or restart the flow) ────────────
    $bookingPatterns = ['book an appointment', 'book appointment', 'book a session', 'book session', 'schedule an appointment', 'schedule appointment', 'schedule a session', 'appointment with a counselor', 'appointment with counselor', 'see a counselor', 'talk to a counselor', 'book a counseling', 'make an appointment', 'set an appointment', 'reserve a session'];
    $bookingHit = false;
    foreach ($bookingPatterns as $p) {
        if (mb_stripos($lower, $p) !== false) { $bookingHit = true; break; }
    }

    if ($bookingHit) {
        $_SESSION['cb_booking'] = ['step' => 'topic', 'topic' => null, 'date' => null, 'time' => null];
        return [
            'intent' => 'booking',
            'reply'  => "I'd be glad to help you book a counseling session. First — what would you like to talk about? (You can skip this if you'd prefer.)",
            'chips'  => [
                ['label' => 'Academic stress', 'value' => 'Academic stress'],
                ['label' => 'Anxiety', 'value' => 'Anxiety'],
                ['label' => 'Sleep issues', 'value' => 'Sleep issues'],
                ['label' => 'Just checking in', 'value' => 'Just checking in'],
                ['label' => 'Skip — no topic', 'value' => 'skip'],
            ],
        ];
    }

    // No active flow and no booking intent → general AI conversation
    if (!$state) {
        return ['intent' => 'general', 'reply' => generalAI($db, $user, 'student', $msg, false)];
    }

    // ── Continue an active flow ───────────────────────────────
    $step = $state['step'];

    if ($step === 'topic') {
        $topic = trim($msg);
        if (in_array($lower, ['skip', 'skip it', 'none', 'no topic', 'no particular topic', 'no particular topic.', 'nothing specific'], true)) {
            $topic = null;
        } else {
            $topic = mb_substr($topic, 0, 200);
        }
        $state['topic'] = $topic;
        $state['step']  = 'date';
        $_SESSION['cb_booking'] = $state;

        $dateChips = [];
        foreach (nextWeekdays(5) as $d) {
            $dateChips[] = ['label' => date('D, M j', strtotime($d)), 'value' => date('M j', strtotime($d))];
        }
        return [
            'intent' => 'booking',
            'reply'  => 'Thanks! When would you like to come in? Sessions run Monday – Friday, 9:00 AM – 5:00 PM.',
            'chips'  => $dateChips,
        ];
    }

    if ($step === 'date') {
        $date = parseDateInput($msg);
        if (!$date) {
            $dateChips = [];
            foreach (nextWeekdays(5) as $d) {
                $dateChips[] = ['label' => date('D, M j', strtotime($d)), 'value' => date('M j', strtotime($d))];
            }
            return [
                'intent' => 'booking',
                'reply'  => "That date doesn't work for me (sessions are on weekdays only, starting tomorrow). Could you pick one of these?",
                'chips'  => $dateChips,
            ];
        }
        $state['date'] = $date;
        $state['step'] = 'time';
        $_SESSION['cb_booking'] = $state;

        $counselors = allCounselors($db);
        $open = availableSlots($db, $counselors, $date);
        $timeChips = [];
        foreach (array_slice($open, 0, 8) as $slot) {
            $timeChips[] = ['label' => date('g:i A', strtotime($slot)), 'value' => date('g:i A', strtotime($slot))];
        }
        if (empty($timeChips)) {
            return [
                'intent' => 'booking',
                'reply'  => 'Hmm — it looks like every slot on ' . date('F j', strtotime($date)) . ' is already taken. Could you pick another day?',
                'chips'  => dateChips(),
            ];
        }
        return [
            'intent' => 'booking',
            'reply'  => 'Great — ' . date('l, F j', strtotime($date)) . '. What time works best for you?',
            'chips'  => $timeChips,
        ];
    }

    if ($step === 'time') {
        $time = parseTimeInput($msg);
        $date = $state['date'];
        $counselors = allCounselors($db);
        $open = availableSlots($db, $counselors, $date);

        if (!$time || !in_array($time, $open, true)) {
            $timeChips = [];
            foreach (array_slice($open, 0, 8) as $slot) {
                $timeChips[] = ['label' => date('g:i A', strtotime($slot)), 'value' => date('g:i A', strtotime($slot))];
            }
            return [
                'intent' => 'booking',
                'reply'  => "That time isn't available on " . date('F j', strtotime($date)) . '. Here are the open slots:',
                'chips'  => $timeChips,
            ];
        }
        $state['time'] = $time;
        $state['step'] = 'confirm';
        $_SESSION['cb_booking'] = $state;

        $topicLine = $state['topic'] ? ' — "' . mb_substr($state['topic'], 0, 80) . '"' : '';
        return [
            'intent' => 'booking',
            'reply'  => "Here's your session:\n\n" .
                        "• When: " . date('l, F j', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . "\n" .
                        "• Topic" . $topicLine . "\n\n" .
                        "Shall I book it for you?",
            'chips' => [
                ['label' => '✅ Yes, book it', 'value' => 'yes'],
                ['label' => '↩ Cancel', 'value' => 'cancel'],
            ],
        ];
    }

    if ($step === 'confirm') {
        $confirmPatterns = ['yes', 'yeah', 'yep', 'confirm', 'confirm booking', 'book it', 'go ahead', 'sure', 'ok', 'okay', 'please book', 'book it for me', 'yes please'];
        $isConfirm = false;
        foreach ($confirmPatterns as $p) {
            if ($lower === $p || mb_stripos($lower, $p) !== false) { $isConfirm = true; break; }
        }

        if (!$isConfirm) {
            unset($_SESSION['cb_booking']);
            return ['intent' => 'booking', 'reply' => "No problem — I've put the booking aside. I'm here whenever you need me. 💚"];
        }

        $date = $state['date'];
        $time = $state['time'];
        $topic = $state['topic'];
        $counselors = allCounselors($db);
        $studentId = (int)$user['id'];

        if (empty($counselors)) {
            // No counselor available → queue + notify later
            $stmt = $db->prepare("INSERT INTO chatbot_booking_requests (student_id, preferred_date, preferred_time, topic, status) VALUES (?, ?, ?, ?, 'waiting')");
            $stmt->execute([$studentId, $date, $time, $topic]);
            notifyStudent($db, $studentId, 'general',
                "You asked to book a counseling session for " . date('F j', strtotime($date)) . ' at ' . date('g:i A', strtotime($time)) . ", but no counselor is available right now. We'll notify you the moment one is online so you can confirm your session.");
            unset($_SESSION['cb_booking']);
            return [
                'intent' => 'booking',
                'reply'  => "I've noted your request for " . date('F j', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . ". No counselor is online at the moment, but I'll notify you the instant one becomes available so we can lock in your session. 💚",
            ];
        }

        // Pick a counselor with a free slot; fall back to the first active one
        $counselor = null;
        foreach ($counselors as $c) {
            if (!in_array($time, bookedSlots($db, (int)$c['id'], $date), true)) { $counselor = $c; break; }
        }
        if (!$counselor) $counselor = $counselors[0];

        // Guard: student already has an appointment that day
        $stmt = $db->prepare("SELECT id FROM appointments WHERE student_id = ? AND appointment_date = ? AND status NOT IN ('cancelled','completed') LIMIT 1");
        $stmt->execute([$studentId, $date]);
        if ($stmt->fetch()) {
            unset($_SESSION['cb_booking']);
            return [
                'intent' => 'booking',
                'reply'  => 'It looks like you already have an appointment on ' . date('F j', strtotime($date)) . '. You can view or cancel it on the Appointments page.',
                'chips'  => [['label' => '📅 Open appointments', 'value' => 'open appointments', 'url' => SITE_URL . '/student/pages/appointments.php']],
            ];
        }

        // Slot conflict re-check (picked counselor)
        if (in_array($time, bookedSlots($db, (int)$counselor['id'], $date), true)) {
            unset($_SESSION['cb_booking']);
            return [
                'intent' => 'booking',
                'reply'  => 'Just missed it — that slot was taken moments ago. Could we try a different time?',
                'chips'  => [['label' => '📅 Book another time', 'value' => 'book an appointment']],
            ];
        }

        $stmt = $db->prepare("INSERT INTO appointments (student_id, counselor_id, appointment_date, appointment_time, purpose, status) VALUES (?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$studentId, (int)$counselor['id'], $date, $time, $topic]);
        $apptId = (int)$db->lastInsertId();

        notifyStudent($db, $studentId, 'appointment',
            "Your appointment request with {$counselor['first_name']} {$counselor['last_name']} on " . date('F j, Y', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . " has been submitted and is pending confirmation.");

        unset($_SESSION['cb_booking']);
        return [
            'intent' => 'booking',
            'reply'  => "All set! 🎉 Your session with {$counselor['first_name']} {$counselor['last_name']} is booked for " . date('l, F j', strtotime($date)) . " at " . date('g:i A', strtotime($time)) . ". The wellness office will confirm it soon — you'll get a notification when that happens.",
            'chips'  => [['label' => '📅 View appointments', 'value' => 'open appointments', 'url' => SITE_URL . '/student/pages/appointments.php']],
            'actions' => [['label' => 'View appointments', 'url' => SITE_URL . '/student/pages/appointments.php']],
        ];
    }

    unset($_SESSION['cb_booking']);
    return ['intent' => 'general', 'reply' => generalAI($db, $user, 'student', $msg, false)];
}

// ══════════════════════════════════════════════════════════════
//  General AI conversation
// ══════════════════════════════════════════════════════════════
function generalAI(PDO $db, array $user, string $role, string $msg, bool $isStaffPrompt): string
{
    if (empty(AI_API_KEY)) {
        return "The assistant isn't configured yet — please ask the MIS office to add the AI API key in includes/config.php.";
    }

    $system = $isStaffPrompt
        ? "You are \"MindCare Assistant\", the AI support assistant of CTU MindCare, the mental health support system of CTU Consolacion. The user is a wellness counselor/staff member. Help them with drafting session notes, wellness guidance, resource suggestions, and administrative questions. Be warm, professional, and concise (2-5 sentences). You are a supportive companion, not a licensed professional. Never invent medical diagnoses."
        : "You are \"MindCare Assistant\", the AI Mental Health Assistant of CTU MindCare, the mental health support system of CTU Consolacion. You act as a 24/7 first-line support companion for students while human counselors may be unavailable. You are NOT a replacement for licensed counselors.\n\n" .
          "Guidelines:\n" .
          "- Be warm, empathetic, non-judgmental, and concise (2-5 sentences usually).\n" .
          "- Provide trusted, practical guidance on common concerns: stress, anxiety, exam pressure, sleep, relationships, low mood, loneliness.\n" .
          "- Gently assess urgency: for significant distress, encourage the student to see a counselor; offer to book a session (the system can book appointments for them).\n" .
          "- For crisis-level content, immediately encourage the student to call Hopeline 02-804-4673, Text HELLO to 4673, or the National Center for Mental Health 24/7 at 0917-899-8727.\n" .
          "- Encourage use of CTU MindCare features: daily mood check-ins, journaling, counselor chat, and the resource library.\n" .
          "- Never invent medical diagnoses, medications, or guarantees. Be honest about being an AI.\n" .
          "- Respond as you normally would in text; use line breaks sparingly.";

    $history = [
        ['role' => 'system', 'content' => $system],
    ];
    $stmt = $db->prepare("SELECT sender, message FROM chatbot_messages WHERE user_id = ? AND user_role = ? ORDER BY id DESC LIMIT 20");
    $stmt->execute([(int)$user['id'], $role]);
    foreach (array_reverse($stmt->fetchAll()) as $row) {
        $history[] = ['role' => $row['sender'] === 'user' ? 'user' : 'assistant', 'content' => mb_substr($row['message'], 0, 2000)];
    }

    $payload = json_encode([
        'model'       => AI_MODEL,
        'messages'    => $history,
        'max_tokens'  => 500,
        'temperature' => 0.7,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer " . AI_API_KEY . "\r\nHTTP-Referer: " . SITE_URL . "\r\nX-Title: CTU MindCare\r\n",
            'content'       => $payload,
            'timeout'       => 30,
            'ignore_errors' => true,
        ],
    ]);

    $raw  = @file_get_contents(AI_BASE_URL . '/chat/completions', false, $ctx);
    $data = json_decode($raw ?: '', true);

    if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
        return trim($data['choices'][0]['message']['content']);
    }
    if (is_array($data) && isset($data['error']['message'])) {
        return "I ran into a small issue: " . trim($data['error']['message']);
    }
    return "I'm sorry, I had trouble reaching my assistant service. Please try again in a moment.";
}

function dateChips(): array
{
    $chips = [];
    foreach (nextWeekdays(5) as $d) {
        $chips[] = ['label' => date('D, M j', strtotime($d)), 'value' => date('M j', strtotime($d))];
    }
    return $chips;
}
