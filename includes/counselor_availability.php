<?php
// ============================================================
//  CTU MindCare — Counselor availability + AI command handling
//  File: includes/counselor_availability.php
//
//  Deterministic natural-language commands from counselors are
//  parsed here (never via raw LLM output) and persisted to the
//  counselor_availability table. All writes are bound to the
//  *session* counselor, never a counselor id supplied in a
//  message, and the role is re-verified on every call.
// ============================================================

const MC_AVAIL_DEFAULT_START = '09:00:00';
const MC_AVAIL_DEFAULT_END   = '16:30:00';

/** Create the availability table if missing (self-healing startup). */
function mcEnsureAvailabilityTable(PDO $db): void
{
    static $ensured = false;
    if ($ensured) return;
    $db->exec("
        CREATE TABLE IF NOT EXISTS counselor_availability (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            counselor_id  INT NOT NULL,
            date          DATE NULL,
            day_of_week   TINYINT NULL,
            is_recurring  TINYINT(1) NOT NULL DEFAULT 0,
            is_available  TINYINT(1) NOT NULL DEFAULT 1,
            start_time    TIME NULL,
            end_time      TIME NULL,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX (counselor_id, date),
            INDEX (counselor_id, day_of_week)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $ensured = true;
}

/** 30-minute working-hours grid, matching the student booking grid. */
function mcTimeSlots(): array
{
    $slots = [];
    for ($h = 9; $h < 17; $h++) {
        $slots[] = sprintf('%02d:00:00', $h);
        $slots[] = sprintf('%02d:30:00', $h);
    }
    return $slots;
}

/**
 * Parse an HH:MM[am|pm] token into a grid slot snapped to the
 * nearest earlier 30-min slot; null if invalid.
 */
function mcTimeToken(string $s): ?string
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
    $best   = null;
    foreach (mcTimeSlots() as $slot) {
        if ($slot === $target) return $slot;
        if (strtotime($slot) < strtotime($target)) $best = $slot;
    }
    return $best; // nearest earlier slot (e.g. 2:10 pm → 2:00 pm)
}

/** Extract a "from X to Y" / "X – Y" time range; null if absent/invalid. */
function mcExtractTimeRange(string $msg): ?array
{
    $patterns = [
        '/from\s+(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:to|until|till|en|–|—|-)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i',
        '/(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:to|until|till|–|—|-)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)/i',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $msg, $m)) {
            $s = mcTimeToken($m[1]);
            $e = mcTimeToken($m[2]);
            if ($s && $e && strtotime($e) > strtotime($s)) return ['start' => $s, 'end' => $e];
        }
    }
    return null;
}

const MC_AVAIL_MONTHS = [
    'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
    'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
    'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
    'jul' => 7, 'aug' => 8, 'sep' => 9, 'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
];

const MC_AVAIL_WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];

/** Next occurrence of a calendar (month, day), rolling to future year if needed. */
function mcNextOccurrence(int $mon, int $day): ?string
{
    if ($day < 1 || $day > 31) return null;
    $year = (int)date('Y');
    for ($i = 0; $i < 2; $i++) {
        if (checkdate($mon, $day, $year)) {
            $candidate = sprintf('%04d-%02d-%02d', $year, $mon, $day);
            if ($candidate >= date('Y-m-d')) return $candidate;
        }
        $year++;
    }
    return null;
}

/** Next strictly-future date falling on the given ISO weekday (1=Mon..7=Sun). */
function mcNextDayOfWeek(int $dow): string
{
    $d = new DateTimeImmutable('now');
    for ($i = 0; $i < 8; $i++) {
        if ((int)$d->format('N') === $dow && $d->format('Y-m-d') > date('Y-m-d')) {
            return $d->format('Y-m-d');
        }
        $d = $d->modify('+1 day');
    }
    return $d->format('Y-m-d');
}

/** Extract a concrete future date from free text, or null. */
function mcExtractDateToken(string $msg): ?string
{
    $lower = strtolower(trim($msg));
    if ($lower === '') return null;

    if (preg_match('/\btomorrow\b/', $lower)) return (new DateTimeImmutable('+1 day'))->format('Y-m-d');
    if (preg_match('/\btoday\b/', $lower))   return date('Y-m-d');

    // "August 8" / "aug 8th"
    if (preg_match('/([a-z]+)\s+(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)?/', $lower, $m)) {
        $name = preg_replace('/[!\?\.]/', '', $m[1]);
        if (isset(MC_AVAIL_MONTHS[$name])) {
            $d = mcNextOccurrence(MC_AVAIL_MONTHS[$name], (int)$m[2]);
            if ($d) return $d;
        }
    }

    // "08/15" or "8-15"
    if (preg_match('/(\d{1,2})[\/\-](\d{1,2})\b/', $lower, $m)) {
        $d = mcNextOccurrence((int)$m[1], (int)$m[2]);
        if ($d) return $d;
    }

    // weekday name
    if (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $lower, $m)) {
        return mcNextDayOfWeek(MC_AVAIL_WEEKDAYS[$m[1]]);
    }

    // bare day-of-month "15" / "15th"
    if (preg_match('/(?:^|\s)(?:the\s+)?(\d{1,2})(?:st|nd|rd|th)?(?:\s|$)/', $lower, $m)) {
        $day = (int)$m[1];
        if ($day >= 1 && $day <= 31) {
            $d = new DateTimeImmutable('+1 day');
            for ($i = 0; $i < 40; $i++) {
                if ((int)$d->format('j') === $day) return $d->format('Y-m-d');
                $d = $d->modify('+1 day');
            }
        }
    }
    return null;
}

// ── Effective window / slots ─────────────────────────────────────

/**
 * Effective window for a counselor on a date.
 * 'window' => ['kind'=>'window','start'=>TIME,'end'=>TIME]  (default grid when unconfigured)
 * 'blocked' => ['kind'=>'blocked']
 */
function mcEffectiveWindow(PDO $db, int $cid, string $date): array
{
    mcEnsureAvailabilityTable($db);
    // 1. date-specific rule wins (blocking or window)
    $stmt = $db->prepare("SELECT is_available, start_time, end_time FROM counselor_availability WHERE counselor_id = ? AND date = ? ORDER BY is_available ASC LIMIT 1");
    $stmt->execute([$cid, $date]);
    $row = $stmt->fetch();
    if ($row) {
        if ((int)$row['is_available'] === 0) return ['kind' => 'blocked'];
        return [
            'kind'  => 'window',
            'start' => $row['start_time'] ?: MC_AVAIL_DEFAULT_START,
            'end'   => $row['end_time']   ?: MC_AVAIL_DEFAULT_END,
        ];
    }
    // 2. recurring rule for that weekday
    $dow = (int)date('N', strtotime($date));
    $stmt = $db->prepare("SELECT is_available, start_time, end_time FROM counselor_availability WHERE counselor_id = ? AND is_recurring = 1 AND day_of_week = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$cid, $dow]);
    $row = $stmt->fetch();
    if ($row) {
        if ((int)$row['is_available'] === 0) return ['kind' => 'blocked'];
        return [
            'kind'  => 'window',
            'start' => $row['start_time'] ?: MC_AVAIL_DEFAULT_START,
            'end'   => $row['end_time']   ?: MC_AVAIL_DEFAULT_END,
        ];
    }
    // 3. unconfigured → default grid (preserves legacy behavior)
    return ['kind' => 'window', 'start' => MC_AVAIL_DEFAULT_START, 'end' => MC_AVAIL_DEFAULT_END];
}

/** Is the given 30-min slot inside the counselor's window that date? */
function mcSlotInWindow(PDO $db, int $cid, string $date, string $time): bool
{
    $w = mcEffectiveWindow($db, $cid, $date);
    if ($w['kind'] === 'blocked') return false;
    $t = strtotime($time);
    return $t >= strtotime($w['start']) && $t <= strtotime($w['end']);
}

/** Open 30-min slots for a single counselor/date (window ∩ grid − booked). */
function mcSlotsForCounselorDate(PDO $db, int $cid, string $date): array
{
    $w     = mcEffectiveWindow($db, $cid, $date);
    $slots = array_values(array_filter(mcTimeSlots(), function ($slot) use ($w) {
        if ($w['kind'] === 'blocked') return false;
        $t = strtotime($slot);
        return $t >= strtotime($w['start']) && $t <= strtotime($w['end']);
    }));

    $stmt = $db->prepare("SELECT appointment_time FROM appointments WHERE counselor_id = ? AND appointment_date = ? AND status NOT IN ('cancelled')");
    $stmt->execute([$cid, $date]);
    foreach (array_column($stmt->fetchAll(), 'appointment_time') as $taken) {
        $idx = array_search($taken, $slots, true);
        if ($idx !== false) unset($slots[$idx]);
    }
    $slots = array_values($slots);
    if ($date === date('Y-m-d')) {
        $now   = time();
        $slots = array_values(array_filter($slots, fn($s) => strtotime($s) > $now));
    }
    return $slots;
}

// ── Persistence ────────────────────────────────────────────────

/** Set availability for a specific date (upsert). Returns confirmation text. */
function mcSetDateAvailability(PDO $db, int $cid, string $date, ?array $win): string
{
    mcEnsureAvailabilityTable($db);
    $start = $win ? $win['start'] : MC_AVAIL_DEFAULT_START;
    $end   = $win ? $win['end']   : MC_AVAIL_DEFAULT_END;

    $stmt = $db->prepare("DELETE FROM counselor_availability WHERE counselor_id = ? AND date = ?");
    $stmt->execute([$cid, $date]);

    $stmt = $db->prepare("INSERT INTO counselor_availability (counselor_id, date, is_recurring, is_available, start_time, end_time) VALUES (?, ?, 0, 1, ?, ?)");
    $stmt->execute([$cid, $date, $start, $end]);

    $label = date('F j, Y', strtotime($date));
    return "Your availability has been updated successfully — you are now available on {$label}, " . date('g:i A', strtotime($start)) . " to " . date('g:i A', strtotime($end)) . ".";
}

/** Set a recurring weekly availability rule (upsert). Returns confirmation. */
function mcSetRecurringAvailability(PDO $db, int $cid, int $dow, ?array $range): string
{
    mcEnsureAvailabilityTable($db);
    $start = $range['start'] ?? MC_AVAIL_DEFAULT_START;
    $end   = $range['end']   ?? MC_AVAIL_DEFAULT_END;
    $names = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    $weekday = $names[$dow - 1] ?? 'weekday';

    $stmt = $db->prepare("DELETE FROM counselor_availability WHERE counselor_id = ? AND is_recurring = 1 AND day_of_week = ?");
    $stmt->execute([$cid, $dow]);

    $stmt = $db->prepare("INSERT INTO counselor_availability (counselor_id, day_of_week, is_recurring, is_available, start_time, end_time) VALUES (?, ?, 1, 1, ?, ?)");
    $stmt->execute([$cid, $dow, $start, $end]);

    return "Your availability has been updated successfully — you are now available every {$weekday}, " . date('g:i A', strtotime($start)) . " to " . date('g:i A', strtotime($end)) . ".";
}

/** Block a specific date. Returns confirmation. */
function mcBlockDate(PDO $db, int $cid, string $date): string
{
    mcEnsureAvailabilityTable($db);

    $stmt = $db->prepare("DELETE FROM counselor_availability WHERE counselor_id = ? AND date = ?");
    $stmt->execute([$cid, $date]);

    $stmt = $db->prepare("INSERT INTO counselor_availability (counselor_id, date, is_recurring, is_available) VALUES (?, ?, 0, 0)");
    $stmt->execute([$cid, $date]);
    return "Your schedule is now blocked for " . date('F j, Y', strtotime($date)) . ".";
}

/** Delete one of the counselor's own rules. */
function mcDeleteAvailabilityRule(PDO $db, int $cid, int $ruleId): bool
{
    $stmt = $db->prepare("DELETE FROM counselor_availability WHERE id = ? AND counselor_id = ?");
    $stmt->execute([$ruleId, $cid]);
    return $stmt->rowCount() > 0;
}

// ── Summary / display ──────────────────────────────────────────

/**
 * Efficient per-counselor effective availability for a horizon of days,
 * built from batched queries (used by the student booking page).
 * Result: [counselorId][date] = ['start'=>H:i,'end'=>H:i] | 'blocked' | absent(=default).
 */
function mcAvailabilityHorizon(PDO $db, array $counselors, int $days = 60): array
{
    mcEnsureAvailabilityTable($db);
    $result = [];
    $from   = new DateTimeImmutable('today');
    $to     = $from->modify(sprintf('+%d days', $days));

    foreach ($counselors as $c) {
        $cid = (int)$c['id'];

        $stmt = $db->prepare("SELECT date, is_available, start_time, end_time FROM counselor_availability WHERE counselor_id = ? AND date IS NOT NULL AND date BETWEEN ? AND ?");
        $stmt->execute([$cid, $from->format('Y-m-d'), $to->format('Y-m-d')]);
        $dateRules = [];
        foreach ($stmt->fetchAll() as $r) $dateRules[$r['date']] = $r;

        $stmt = $db->prepare("SELECT day_of_week, is_available, start_time, end_time FROM counselor_availability WHERE counselor_id = ? AND is_recurring = 1");
        $stmt->execute([$cid]);
        $recRules = [];
        foreach ($stmt->fetchAll() as $r) $recRules[(int)$r['day_of_week']] = $r;

        $d = new DateTimeImmutable('today');
        for ($i = 0; $i <= $days; $i++) {
            $date = $d->format('Y-m-d');
            $w    = null;
            if (isset($dateRules[$date])) {
                $w = $dateRules[$date];
            } elseif (isset($recRules[(int)$d->format('N')])) {
                $w = $recRules[(int)$d->format('N')];
            }
            if ($w !== null && (int)$w['is_available'] === 0) {
                $result[$cid][$date] = 'blocked';
            } elseif ($w !== null) {
                $start = $w['start_time'] ?: MC_AVAIL_DEFAULT_START;
                $end   = $w['end_time']   ?: MC_AVAIL_DEFAULT_END;
                if ($start !== MC_AVAIL_DEFAULT_START || $end !== MC_AVAIL_DEFAULT_END) {
                    $result[$cid][$date] = ['start' => substr($start, 0, 5), 'end' => substr($end, 0, 5)];
                }
            }
            $d = $d->modify('+1 day');
        }
    }
    return $result;
}

/** Rules + upcoming derived slots for a counselor (dashboard + "show" command). */
function mcAvailabilitySummary(PDO $db, array $counselor): array
{
    $cid = (int)$counselor['id'];
    mcEnsureAvailabilityTable($db);

    $rules  = [];
    $stmt   = $db->prepare("SELECT * FROM counselor_availability WHERE counselor_id = ? ORDER BY is_recurring ASC, date ASC, day_of_week ASC");
    $stmt->execute([$cid]);
    foreach ($stmt->fetchAll() as $r) {
        if ($r['date']) {
            $r['label'] = date('F j, Y', strtotime($r['date']));
        } elseif ($r['day_of_week']) {
            $names      = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            $r['label'] = 'Every ' . ucfirst($names[(int)$r['day_of_week'] - 1] ?? 'day');
        } else {
            $r['label'] = 'Untitled rule';
        }
        $r['start_label'] = $r['start_time'] ? date('g:i A', strtotime($r['start_time'])) : '9:00 AM';
        $r['end_label']   = $r['end_time']   ? date('g:i A', strtotime($r['end_time']))   : '4:30 PM';
        $rules[] = $r;
    }

    $upcoming = [];
    $d = new DateTimeImmutable('tomorrow');
    for ($i = 0; $i < 14; $i++) {
        $date = $d->format('Y-m-d');
        if ((int)$d->format('N') <= 5) {
            $slots = mcSlotsForCounselorDate($db, $cid, $date);
            if ($slots) {
                $upcoming[] = [
                    'date'  => $date,
                    'label' => $d->format('D, F j'),
                    'slots' => $slots,
                ];
            }
        }
        $d = $d->modify('+1 day');
    }

    return ['rules' => $rules, 'upcoming' => $upcoming];
}

// ── Command parsing + execution ────────────────────────────────

/**
 * Parse a counselor's natural-language availability command.
 * Returns a structured action array or null if not a command.
 *
 * action 'set_date'    → ['date', 'win'?]
 * action 'set_recurring': ['dow', 'win'?]
 * action 'block'       : ['date']
 * action 'show'        : []
 */
function mcParseAvailabilityCommand(string $msg): ?array
{
    $lower = mb_strtolower(trim($msg));
    if ($lower === '') return null;

    // ── Any-model protection: drop booking/chat phrases that could
    //    otherwise collide with availability wording.
    foreach (['book a session', 'book an appointment', 'book an appt'] as $p) {
        if (mb_stripos($lower, $p) !== false) return null;
    }

    // 1. Show / summary
    if (mb_stripos($lower, 'show my') !== false
        && preg_match('/(available|schedule|appointments?|times?|availability)/', $lower)) {
        return ['action' => 'show'];
    }

    // 2. Block
    if (mb_stripos($lower, 'block') !== false) {
        $date = mcExtractDateToken($lower);
        if ($date !== null) return ['action' => 'block', 'date' => $date];
        return null; // "block" without a date is not actionable
    }

    // Available/availability/set/update
    if (mb_stripos($lower, 'available') === false && mb_stripos($lower, 'availability') === false) {
        return null;
    }

    $win = mcExtractTimeRange($lower);

    // 3. Recurring weekday rule ("available every monday")
    if (preg_match('/\bevery\s+(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/', $lower, $m)) {
        return ['action' => 'set_recurring', 'dow' => MC_AVAIL_WEEKDAYS[$m[1]], 'win' => $win];
    }

    // 4. Concrete date
    $date = mcExtractDateToken($lower);
    if ($date !== null) return ['action' => 'set_date', 'date' => $date, 'win' => $win];

    // 5. Window only ("update my available time to 1:00 PM–5:00 PM")
    //    → anchored to today's weekday as the standing rule.
    if ($win) {
        return ['action' => 'set_recurring', 'dow' => (int)date('N'), 'win' => $win];
    }

    return null;
}

/**
 * Run an availability command for the session counselor.
 * Returns ['reply'=>string, 'intent'=>'availability'] or null to
 * signal "not a command" (caller falls back to the general AI).
 */
function mcHandleCounselorCommand(PDO $db, array $user, string $msg): ?array
{
    // Defense in depth: only wellness admins, from the session.
    if (($_SESSION['user_role'] ?? '') !== 'wellness_admin') return null;

    $cid = (int)($user['id'] ?? $_SESSION['user_id'] ?? 0);
    if ($cid <= 0) return null;

    $cmd = mcParseAvailabilityCommand($msg);
    if ($cmd === null) return null;

    try {
        switch ($cmd['action']) {
            case 'show':
                $sum = mcAvailabilitySummary($db, ['id' => $cid]);
                if (!$sum['upcoming']) {
                    return ['reply' => "You don't have any available sessions set in the next two weeks. Just tell me when you're available — e.g. \"I am available on August 8\" — and I'll add it for you."];
                }
                $lines = ["Here's when you're available:"];
                foreach ($sum['upcoming'] as $u) {
                    $first = date('g:i A', strtotime($u['slots'][0]));
                    $last  = date('g:i A', strtotime($u['slots'][count($u['slots']) - 1]));
                    $lines[] = '• ' . $u['date'] . ' (' . date('l', strtotime($u['date'])) . '): ' . $first . ' – ' . $last . ' — ' . count($u['slots']) . ' slots open';
                }
                return ['reply' => implode("\n", array_slice($lines, 0, 8)), 'intent' => 'availability'];

            case 'set_date':
                return ['reply' => mcSetDateAvailability($db, $cid, $cmd['date'], $cmd['win']), 'intent' => 'availability'];

            case 'set_recurring':
                return ['reply' => mcSetRecurringAvailability($db, $cid, $cmd['dow'], $cmd['win']), 'intent' => 'availability'];

            case 'block':
                return ['reply' => mcBlockDate($db, $cid, $cmd['date']), 'intent' => 'availability'];
        }
    } catch (Throwable $e) {
        return ['reply' => "I ran into a problem updating your availability. Please try again in a moment.", 'intent' => 'availability'];
    }
    return null;
}