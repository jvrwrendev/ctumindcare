<?php
// ============================================================
//  CTU MindCare — AI Counselor Assistant (shared backend)
//  File: includes/counselor_ai.php
//
//  Provides availability presence + AI reply generation for
//  the Student–Counselor chat. When the assigned counselor is
//  away, an AI stands in: supportive guidance, clear AI
//  identification, crisis detection with flagging, and a
//  persistent transcript so the human counselor can review
//  and continue the conversation seamlessly.
// ============================================================

const MC_AI_NAME        = 'AI Counselor Assistant';
const MC_AI_SENDER_ID   = 0;        // AI messages carry this sender_id
const MC_AI_OFFLINE_SEC = 180;      // counselor idle threshold before AI takes over
const MC_AI_TOUCH_SEC   = 60;       // presence heartbeat throttle

/** Idempotent migrations for the AI counselor feature. */
function mcEnsureAiTables(PDO $db): void
{
    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counselor_messages' AND COLUMN_NAME = 'sender_type'");
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE counselor_messages ADD COLUMN sender_type ENUM('student','counselor','ai') NOT NULL DEFAULT 'student' AFTER sender_id");
        $db->exec("UPDATE counselor_messages SET sender_type = 'counselor' WHERE sender_id = counselor_id");
    }

    // AI messages carry a NULL sender_id (FK to users.id); NULL keeps AI rows
    // out of all `sender_id != ?` unread-count queries for free
    $stmt = $db->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'counselor_messages' AND COLUMN_NAME = 'sender_id'");
    if ($stmt->fetchColumn() === 'NO') {
        $db->exec("ALTER TABLE counselor_messages MODIFY sender_id INT NULL");
    }

    $stmt = $db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wellness_admins' AND COLUMN_NAME = 'last_activity'");
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE wellness_admins ADD COLUMN last_activity DATETIME NULL");
    }

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
}

/** Online state for a wellness_admins row (is_active + last_activity). */
function mcRowOnline(array $row): bool
{
    if ((int)($row['is_active'] ?? 0) !== 1) return false;
    $last = $row['last_activity'] ?? null;
    if ($last === null || $last === '') return true; // legacy: never tracked → assume available
    return strtotime($last) >= time() - MC_AI_OFFLINE_SEC;
}

/** Online state for a counselor by id. */
function mcCounselorOnline(PDO $db, int $counselorId): bool
{
    $stmt = $db->prepare("SELECT is_active, last_activity FROM wellness_admins WHERE id = ?");
    $stmt->execute([$counselorId]);
    $row = $stmt->fetch();
    return $row !== false && mcRowOnline($row);
}

/** Throttled presence heartbeat (at most once per MC_AI_TOUCH_SEC). */
function mcTouchPresence(PDO $db, int $counselorId): void
{
    $stmt = $db->prepare("SELECT last_activity FROM wellness_admins WHERE id = ?");
    $stmt->execute([$counselorId]);
    $last = $stmt->fetchColumn();
    if ($last !== null && $last !== '' && strtotime($last) > time() - MC_AI_TOUCH_SEC) return;
    $db->prepare("UPDATE wellness_admins SET last_activity = NOW() WHERE id = ?")->execute([$counselorId]);
}

// ── Risk assessment (mirrors chatbot/api.php) ──────────────────
const MC_AI_CRISIS = ['suicide', 'kill myself', 'end my life', 'want to die', 'self harm', 'self-harm', 'hurt myself', 'no reason to live', 'take my life', 'going to hurt'];
const MC_AI_MEDIUM = ['overwhelmed', "can't cope", 'cannot cope', 'panic attack', 'can\'t sleep', 'cant sleep', 'insomnia', 'hopeless', 'worthless', 'breakdown', 'breaking down', 'crying all the time', 'can\'t stop crying', 'so stressed', 'stressed out', 'anxious all the time', 'always anxious', 'isolated', 'so lonely', 'empty inside', 'no friends', 'giving up', 'give up', 'failing everything', 'miserable'];

function mcAssessRisk(string $msg): array
{
    $lower = mb_strtolower($msg);
    foreach (MC_AI_CRISIS as $p) {
        if (mb_stripos($lower, $p) !== false) {
            return ['level' => 'high', 'reason' => 'Crisis keywords detected in counselor chat: "' . $p . '"'];
        }
    }
    foreach (MC_AI_MEDIUM as $p) {
        if (mb_stripos($lower, $p) !== false) {
            return ['level' => 'medium', 'reason' => 'Distress keywords detected in counselor chat: "' . $p . '"'];
        }
    }
    return ['level' => 'low', 'reason' => null];
}

/** Persist a risk flag (deduped per student per day), visible in wellness ai-logs. */
function mcFlagRisk(PDO $db, int $studentId, string $level, string $reason): void
{
    $stmt = $db->prepare("SELECT id FROM chatbot_risk_flags WHERE student_id = ? AND DATE(created_at) = CURDATE() AND status = 'open' LIMIT 1");
    $stmt->execute([$studentId]);
    if ($stmt->fetch()) return;
    $db->prepare("INSERT INTO chatbot_risk_flags (student_id, risk, reason, message_id, status) VALUES (?, ?, ?, NULL, 'open')")
       ->execute([$studentId, $level, mb_substr('[AI Counselor Chat] ' . $reason, 0, 255)]);
}

/** Insert an AI message into the shared conversation thread (sender_id = NULL). */
function mcInsertAiMessage(PDO $db, int $studentId, int $counselorId, string $reply): int
{
    $stmt = $db->prepare("INSERT INTO counselor_messages (student_id, counselor_id, sender_id, sender_type, message) VALUES (?, ?, NULL, 'ai', ?)");
    $stmt->execute([$studentId, $counselorId, $reply]);
    return (int)$db->lastInsertId();
}

/** OpenAI chat completion; returns text or null on failure. */
function mcChatCompletion(array $messages): ?string
{
    if (empty(OPENAI_API_KEY)) return null;

    $payload = json_encode([
        'model'       => OPENAI_MODEL,
        'messages'    => $messages,
        'max_tokens'  => 400,
        'temperature' => 0.7,
    ]);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer " . OPENAI_API_KEY . "\r\n",
            'content'       => $payload,
            'timeout'       => 40,
            'ignore_errors' => true,
        ],
    ]);

    $raw  = @file_get_contents('https://api.openai.com/v1/chat/completions', false, $ctx);
    $data = json_decode($raw ?: '', true);

    if (is_array($data) && isset($data['choices'][0]['message']['content'])) {
        return trim($data['choices'][0]['message']['content']);
    }
    return null;
}

/**
 * Generate + persist the AI reply for a conversation.
 * Returns ['reply' => string, 'risk' => 'low'|'medium'|'high'] or null
 * if the generation was skipped (non-AI guard). Never fails hard:
 * a graceful fallback message is used when the assistant service is down.
 */
function mcAiReply(PDO $db, int $studentId, int $counselorId, string $msg): ?array
{
    // Guard: never stack a second AI reply on top of one that's already pending
    $stmt = $db->prepare("SELECT sender_type FROM counselor_messages WHERE student_id = ? AND counselor_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$studentId, $counselorId]);
    $last = $stmt->fetchColumn();
    if ($last === 'ai') return null;

    $counselor = '';
    $stmt = $db->prepare("SELECT first_name, last_name FROM wellness_admins WHERE id = ?");
    $stmt->execute([$counselorId]);
    $cRow = $stmt->fetch();
    if ($cRow) $counselor = trim($cRow['first_name'] . ' ' . $cRow['last_name']);

    $student = '';
    $stmt = $db->prepare("SELECT first_name FROM users WHERE id = ?");
    $stmt->execute([$studentId]);
    $sRow = $stmt->fetch();
    if ($sRow) $student = trim($sRow['first_name']);

    $risk = mcAssessRisk($msg);

    // ── Crisis path: instant, before anything else ────────────
    if ($risk['level'] === 'high') {
        $reply = "I'm really glad you reached out — what you're feeling matters, and you don't have to go through it alone. Please talk to someone right now:\n\n" .
            "• Hopeline: 02-804-4673\n" .
            "• Text HELLO to 4673\n" .
            "• National Center for Mental Health (24/7): 0917-899-8727\n\n" .
            "You can also open the Crisis Help page: " . SITE_URL . "/student/pages/crisis.php\n\n" .
            "I'm here with you, and I've also alerted the wellness office so a counselor can follow up with you as soon as possible. 💚";

        mcFlagRisk($db, $studentId, 'high', $risk['reason']);
        $db->prepare("INSERT INTO crisis_alerts (student_id, message) VALUES (?, ?)")
           ->execute([$studentId, 'AI Counselor Assistant flagged a crisis-level message: "' . mb_substr($msg, 0, 300) . '"']);
        mcInsertAiMessage($db, $studentId, $counselorId, $reply);
        return ['reply' => $reply, 'risk' => 'high'];
    }

    // ── Normal path: AI counselor persona with chat history ───
    $system = "You are \"AI Counselor Assistant\" of CTU MindCare, the mental health support system of CTU Consolacion. " .
        "You are standing in for the student's assigned counselor" . ($counselor !== '' ? ', ' . $counselor : '') .
        ", who is currently unavailable." . ($student !== '' ? ' The student is ' . $student . '.' : '') . "\n\n" .
        "IDENTITY RULES (never break):\n" .
        "- You are an AI. Always answer as the AI Counselor Assistant and make it clear you are standing in for the counselor, who will see this conversation later.\n" .
        "- You are NOT a licensed counselor, psychologist, or doctor. Never claim to be one, never give medical or psychiatric diagnoses, never prescribe anything, and never guarantee outcomes.\n" .
        "- You are a supportive, non-judgmental listener.\n\n" .
        "GUIDELINES:\n" .
        "- Be warm, empathetic, and concise (2-5 sentences usually). Write naturally.\n" .
        "- Provide practical, trusted guidance on common student concerns: stress, anxiety, academic pressure, exams, time management, relationships, sleep, low mood, loneliness, campus services, and wellness habits.\n" .
        "- For significant distress, gently encourage talking to the wellness office when the counselor returns, and mention the Appointments page for booking a session.\n" .
        "- For crisis-level content, immediately encourage calling Hopeline 02-804-4673, texting HELLO to 4673, or the National Center for Mental Health 24/7 at 0917-899-8727, and opening the Crisis Help page.\n" .
        "- Encourage CTU MindCare features when relevant: daily mood check-ins, journaling, and the resource library.\n" .
        "- Never invent medical facts, medications, or the human counselor's words. Be honest that you are an AI.";

    // History = prior messages (the just-inserted student message is dropped
    // from the tail, since it is appended once below)
    $history = [];
    $stmt = $db->prepare("SELECT sender_type, message FROM counselor_messages WHERE student_id = ? AND counselor_id = ? ORDER BY id DESC LIMIT 12");
    $stmt->execute([$studentId, $counselorId]);
    $rows = array_reverse($stmt->fetchAll());
    if ($rows && end($rows)['sender_type'] === 'student') {
        array_pop($rows);
    }
    foreach ($rows as $r) {
        $history[] = [
            'role'    => $r['sender_type'] === 'student' ? 'user' : 'assistant',
            'content' => mb_substr($r['message'], 0, 1500),
        ];
    }
    $history[] = ['role' => 'user', 'content' => mb_substr($msg, 0, 1500)];

    $reply = mcChatCompletion(array_merge([['role' => 'system', 'content' => $system]], $history));

    if ($reply === null) {
        $reply = "Thanks for your message" . ($student !== '' ? ', ' . $student : '') .
            ". Your counselor isn't available right now, and my assistant service is having a moment — but everything you wrote is saved and " .
            ($counselor !== '' ? $counselor . ' ' : 'your counselor ') . "will see it and reply as soon as they're back. " .
            "If you need urgent help, please open the Crisis Help page or call Hopeline at 02-804-4673.";
    }

    if ($risk['level'] === 'medium') {
        mcFlagRisk($db, $studentId, 'medium', $risk['reason']);
        $reply .= "\n\nYou sound like you're carrying a lot right now. I'd really encourage you to talk to your counselor when they're back — they can help in ways I can't. You can also book a session on the Appointments page whenever you're ready.";
    }

    mcInsertAiMessage($db, $studentId, $counselorId, $reply);
    return ['reply' => $reply, 'risk' => $risk['level']];
}
