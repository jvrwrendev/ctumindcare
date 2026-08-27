<?php
// ============================================================
//  CTU MindCare — Counselor availability test runner
//  Run with:  php tests/availability_test.php
//  Part 1 = parser unit tests (no DB needed).
//  Part 2 = live DB smoke test (skipped if MySQL is not running).
// ============================================================

error_reporting(E_ALL);
require_once __DIR__ . '/../includes/counselor_availability.php';

$pass = 0;
$fail = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok   $label\n"; }
    else       { $fail++; echo "  FAIL $label" . ($detail ? "  [$detail]" : '') . "\n"; }
}

echo "== Part 1: Command parser ==" . PHP_EOL;

// ── set_date ──────────────────────────────────────────────────
$c = mcParseAvailabilityCommand('I am available on August 8.');
check('available + month-day -> set_date', is_array($c) && $c['action'] === 'set_date', var_export($c, true));
check('... carries a date', is_array($c) && isset($c['date']) && $c['date'] >= date('Y-m-d'));
check('... no window (full default)', is_array($c) && !isset($c['win']));

$c = mcParseAvailabilityCommand('Set my available date to August 8.');
check('"Set my available date to" -> set_date', is_array($c) && $c['action'] === 'set_date');

$c = mcParseAvailabilityCommand('I am available tomorrow from 9:00 AM to 4:00 PM.');
check('available tomorrow with range -> set_date',
    is_array($c) && $c['action'] === 'set_date'
    && isset($c['win'], $c['date'])
    && $c['win']['start'] === '09:00:00' && $c['win']['end'] === '16:00:00');
check('tomorrow date is +1 day',
    is_array($c) && $c['date'] === (new DateTimeImmutable('+1 day'))->format('Y-m-d'));

// ── set_recurring ─────────────────────────────────────────────
$c = mcParseAvailabilityCommand("I'm available every Monday.");
check('available every monday -> set_recurring', is_array($c) && $c['action'] === 'set_recurring' && $c['dow'] === 1);
check('every monday has no window', is_array($c) && !isset($c['win']));

$c = mcParseAvailabilityCommand("I'm available every Friday from 1:00 PM to 5:00 PM.");
check('every friday with range -> start/end',
    is_array($c) && $c['action'] === 'set_recurring' && $c['dow'] === 5
    && isset($c['win']) && $c['win']['start'] === '13:00:00' && $c['win']['end'] === '16:30:00');

$c = mcParseAvailabilityCommand("Update my available time to 1:00 PM-5:00 PM.");
check('update range-only -> set_recurring on today weekday',
    is_array($c) && $c['action'] === 'set_recurring' && $c['dow'] === (int)date('N')
    && isset($c['win']) && $c['win']['start'] === '13:00:00' && $c['win']['end'] === '16:30:00');

// ── block ─────────────────────────────────────────────────────
$c = mcParseAvailabilityCommand('Block my schedule tomorrow.');
check('block tomorrow', is_array($c) && $c['action'] === 'block'
    && $c['date'] === (new DateTimeImmutable('+1 day'))->format('Y-m-d'));

$c = mcParseAvailabilityCommand('Block my calendar on August 8.');
check('block on a month-day', is_array($c) && $c['action'] === 'block' && isset($c['date']));

// ── show ──────────────────────────────────────────────────────
$c = mcParseAvailabilityCommand('Show my available appointments.');
check('show available appointments', is_array($c) && $c['action'] === 'show');

$c = mcParseAvailabilityCommand('Show my availability.');
check('show my availability', is_array($c) && $c['action'] === 'show');

$c = mcParseAvailabilityCommand('What does my schedule look like this week?');
check('unmatched question -> null', $c === null);

// ── negatives / safety ────────────────────────────────────────
$c = mcParseAvailabilityCommand('Book an appointment.');
check('booking request is NOT an availability command', $c === null);

$c = mcParseAvailabilityCommand('I am available');
check('bare "I am available" is not actionable', $c === null);

$c = mcParseAvailabilityCommand('Can you help me draft session notes?');
check('ordinary staff chat -> null', $c === null);

$c = mcParseAvailabilityCommand('Block'); // block without a date
check('bare "block" -> null', $c === null);

// ── extra date formats ────────────────────────────────────────
$c = mcParseAvailabilityCommand('I am available on 08/15.');
check('numeric m/d date accepted', is_array($c) && $c['action'] === 'set_date' && isset($c['date']));
$c = mcParseAvailabilityCommand('I am available this Friday.');
check('weekday name date accepted', is_array($c) && $c['action'] === 'set_date' && isset($c['date']));

// ── time token parsing ────────────────────────────────────────
check('time token 9:00am -> 09:00:00', mcTimeToken('9:00 am') === '09:00:00');
check('time token 4:30pm -> 16:30:00', mcTimeToken('4:30 pm') === '16:30:00');
$r = mcExtractTimeRange('from 9am-4pm');
check('9am-4pm range', is_array($r) && $r['start'] === '09:00:00' && $r['end'] === '16:00:00');

echo PHP_EOL . "== Part 2: Parser done ($pass passed, $fail failed) ==" . PHP_EOL . PHP_EOL;

// ── Part 2: Live DB smoke test ────────────────────────────────
echo "== Part 2: Live DB smoke test ==" . PHP_EOL;
try {
    require_once __DIR__ . '/../includes/config.php';
    $pdo = getDB();
} catch (Throwable $e) {
    $pdo = null;
}

if (!$pdo) {
    echo "SKIP  Part 2: MySQL not reachable (start XAMPP MySQL and rerun to exercise the DB)." . PHP_EOL;
    exit($fail > 0 ? 1 : 0);
}

mockSession('wellness_admin', 999001);
$cid = 999001;

// Ensure table exists
mcEnsureAvailabilityTable($pdo);
$pdo->exec("DELETE FROM counselor_availability WHERE counselor_id = $cid");

try {
    $msg = mcSetDateAvailability($pdo, $cid, (new DateTimeImmutable('+3 days'))->format('Y-m-d'), ['start' => '10:00:00', 'end' => '14:00:00']);
    check('set_date persists', mb_stripos($msg, 'updated successfully') !== false);

    $nextMon = null;
    $d = new DateTimeImmutable('tomorrow');
    for ($i = 0; $i < 8 && $nextMon === null; $d = $d->modify('+1 day')) {
        if ((int)$d->format('N') === 1) $nextMon = $d->format('Y-m-d');
    }
    mcSetRecurringAvailability($pdo, $cid, 1, ['start' => '09:00:00', 'end' => '12:00:00']);
    check('recurring Monday persisted', true);

    $monSlots = mcSlotsForCounselorDate($pdo, $cid, $nextMon);
    check('recurring window yields 9:00-12:00 on Monday',
        $monSlots === ['09:00:00', '09:30:00', '10:00:00', '10:30:00', '11:00:00', '11:30:00', '12:00:00'],
        implode(',', $monSlots));

    $d6 = (new DateTimeImmutable('+3 days'))->format('Y-m-d');
    $winSlots = mcSlotsForCounselorDate($pdo, $cid, $d6);
    check('one-off window yields 10:00-14:00',
        $winSlots === ['10:00:00', '10:30:00', '11:00:00', '11:30:00', '12:00:00', '12:30:00', '13:00:00', '13:30:00', '14:00:00'],
        implode(',', $winSlots));

    mcBlockDate($pdo, $cid, $d6);
    check('date block overrides window', mcSlotsForCounselorDate($pdo, $cid, $d6) === []);
    check('slot-in-window false when blocked', mcSlotInWindow($pdo, $cid, $d6, '10:00:00') === false);

    $sum = mcAvailabilitySummary($pdo, ['id' => $cid]);
    check('summary reports rules + upcoming', count($sum['rules']) >= 2 && count($sum['upcoming']) >= 1);

    // Security guard: student role can NEVER run a command
    mockSession('student', 42);
    check('student role: command rejected', mcHandleCounselorCommand($pdo, ['id' => 42], 'I am available tomorrow') === null);

    // Wellness-admin command execution against own session id
    mockSession('wellness_admin', $cid);
    $r = mcHandleCounselorCommand($pdo, ['id' => $cid], 'I am available tomorrow.');
    check('wellness command executes', is_array($r) && isset($r['reply'], $r['intent']));
} finally {
    $pdo->exec("DELETE FROM counselor_availability WHERE counselor_id = $cid");
    echo "cleanup: removed test rows" . PHP_EOL;
}

echo PHP_EOL . "TOTALS: $pass passed, $fail failed" . PHP_EOL;
exit($fail > 0 ? 1 : 0);

function mockSession(string $role = '', $userId = 0): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        @session_start();
    }
    $_SESSION['user_role'] = $role;
    $_SESSION['user_id']   = $userId;
}