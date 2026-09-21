<?php
// What the observer ring looks like right now.
//
// An operator's view, not a member. It reads the store tree and prints it. It
// joins no ring, publishes nothing, holds no credential, and writes no file:
// every path it touches is mounted read-only in the container it runs in.
//
// **Why this is a terminal tool and not a page.** A page would be a way in on
// the game's own origin, and every way in is gated, so it would answer 503
// during a halt -- exactly when somebody wants to look at it. The thing that
// watches a deployment has to work when the deployment does not, which is the
// same argument that keeps the ring's log reader outside the package.
//
// **What it is not.** It is not a check and its opinion is not a verdict. The
// ring's own state is the truth: a member's fault file and halt file are what
// stopped the work, and anything below that disagrees with them is this
// script being wrong. Where it cannot tell two things apart it says so rather
// than guessing.
//
// Run it through ring-status.cmd, which handles the container and the TTY.
// Directly, from the docker/ directory:
//
//   C="docker compose -f docker-compose.yml -f docker-compose.local.yml -f docker-compose.verify.yml"
//   $C cp ring-status.php super:/tmp/rs.php && $C exec super php /tmp/rs.php
//
// --watch redraws once a second in place, --interval=N changes that, --once
// prints a single frame and stops, and --no-color drops the colour escapes.
// ring-status.cmd passes --watch when given no arguments of its own.

// The tree to read. `OBSERVER_STORES` is the same name every gate reader takes
// it from, so pointing this at a copy of a tree -- to look at a deployment
// that has since been restarted, or to see what a halt renders like without
// causing one -- needs no argument it does not already understand.
define('STORES', getenv('OBSERVER_STORES') !== false ? getenv('OBSERVER_STORES') : '/stores');

// Display order, and the coordinator goes first.
//
// This is not the ring's own order. The members' `RING_MEMBERS` is a declared
// literal that every cycle takes in identity order, and nothing here changes
// that: reading a store is not participating in it. `super` leads because it
// is the one member with the whole view and the one whose absence stops every
// runtime directly, so it is the row to look at first.
const MEMBERS = array('super', 'web', 'multi', 'policy');

// SPEC 9: who writes into whose copy/ folder. One cycle among the three
// application observers, so each of them validates a different peer.
const COPY_CYCLE = array('web' => 'policy', 'multi' => 'web', 'policy' => 'multi');

$args     = array_slice($argv, 1);
$watch    = false;
$once     = false;
$interval = 1;
$no_color = getenv('NO_COLOR') !== false;

foreach ($args as $a) {
    if ($a === '--watch') {
        $watch = true;
    } elseif ($a === '--once') {
        $once = true;
    } elseif ($a === '--no-color') {
        $no_color = true;
    } elseif (strpos($a, '--interval=') === 0) {
        $watch    = true;
        $interval = max(1, (int) substr($a, 11));
    }
}

// --once wins wherever it appears, including after --watch, so the launcher
// can pass --watch as its default and still be overridden on the command line
// without caring about argument order.
if ($once) {
    $watch = false;
}


// --- painting --------------------------------------------------------------

function c(string $text, string $colour): string
{
    global $no_color;
    if ($no_color) {
        return $text;
    }
    $codes = array(
        'green' => '0;32', 'red' => '1;31', 'amber' => '0;33',
        'grey'  => '0;90', 'bold' => '1',    'cyan'  => '0;36',
    );
    return "\033[" . ($codes[$colour] ?? '0') . 'm' . $text . "\033[0m";
}

// Padding has to ignore the escapes, or every column after a coloured cell
// drifts by the width of the escape sequence.
function pad(string $text, int $width): string
{
    $bare = preg_replace('/\033\[[0-9;]*m/', '', $text);
    return $text . str_repeat(' ', max(0, $width - strlen($bare)));
}

function duration(int $seconds): string
{
    if ($seconds < 60) {
        return $seconds . 's';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
    }
    if ($seconds < 86400) {
        return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
    }
    return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h';
}


// --- reading ---------------------------------------------------------------

function heartbeat_files(string $dir): array
{
    $names = @scandir($dir);
    if ($names === false) {
        return array();
    }
    $out = array();
    foreach ($names as $n) {
        if (substr($n, -3) === '.hb') {
            $out[] = $n;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

// Every heartbeat in a folder, by the hash of its bytes, because that is what
// a reader records having seen (procedures.php: 'observed'). sha256, to match
// hash_bytes() in store.php.
function hashes_in(string $dir): array
{
    $out = array();
    foreach (heartbeat_files($dir) as $n) {
        $bytes = @file_get_contents($dir . '/' . $n);
        if ($bytes !== false) {
            $out[hash('sha256', $bytes)] = $n;
        }
    }
    return $out;
}

function read_heartbeat(string $dir, string $name): ?array
{
    $bytes = @file_get_contents($dir . '/' . $name);
    if ($bytes === false) {
        return null;
    }
    $parsed = json_decode($bytes, true);
    if (!is_array($parsed)) {
        return null;
    }
    $parsed['_file'] = $name;
    $parsed['_hash'] = hash('sha256', $bytes);
    return $parsed;
}

function latest_heartbeat(string $dir): ?array
{
    $files = heartbeat_files($dir);
    if (count($files) === 0) {
        return null;
    }
    return read_heartbeat($dir, $files[count($files) - 1]);
}

// How long a cycle is actually taking, measured across the window.
//
// There was a column here reporting how long each member had been observing,
// worked out as sequence times cadence. That was wrong twice over. Nothing in
// the tree records when a member started, so there was nothing to check it
// against; and the cadence is a **ceiling, not a pace**. run.php runs the next
// cycle as soon as the last one ends, held only by OBSERVER_MIN_CYCLE_MS, so
// cycles come roughly twenty times faster than the cadence and a count of them
// is not a unit of time. run.php:308-325 says exactly that, and the column was
// invented without reading it. On a deployment one day old it read six days.
//
// What is worth showing is the opposite question: how close a cycle comes to
// the ceiling it must stay under. `cycle-within-cadence` faults when one goes
// over, so a number creeping up on the cadence is the warning that arrives
// before the halt does.
function sequence_of(string $dir): ?int
{
    $files = heartbeat_files($dir);
    if (count($files) === 0) {
        return null;
    }
    return (int) substr($files[count($files) - 1], 0, -3);
}

// Cycles per second, measured between two readings of the store rather than
// from the timestamps inside it.
//
// The timestamps are whole seconds (SPEC 3.1) and a cycle here takes about
// three tenths of one, so the four-entry window spans barely a second and
// dividing by it gives either a wrong answer or no answer at all. Two readings
// a known interval apart do not have that problem.
function rates_between(array $before, array $after, float $seconds): array
{
    $out = array();
    foreach (MEMBERS as $m) {
        $a = $before[$m] ?? null;
        $b = $after[$m] ?? null;
        if ($a === null || $b === null || $b <= $a || $seconds <= 0) {
            $out[$m] = null;
            continue;
        }
        $out[$m] = $seconds / ($b - $a);
    }
    return $out;
}

function sample_sequences(): array
{
    $out = array();
    foreach (MEMBERS as $m) {
        $out[$m] = sequence_of(STORES . '/' . $m . '/heartbeat');
    }
    return $out;
}


// When a member began observing, from the file it writes once and never
// rewrites. This is what makes an age column honest.
//
// There was one here before that multiplied sequence by cadence, which read
// six days on a deployment a day old. It turned out the observers did the same
// arithmetic for their own trace checks and halted every cold start on it. The
// file this reads is the fix for that, and the column is only back because
// there is now something real to read.
function observing_since(string $root): ?int
{
    $raw = @file_get_contents($root . '/since');
    if ($raw === false) {
        return null;
    }
    $t = trim($raw);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z\z/', $t, $m) !== 1) {
        return null;
    }
    $epoch = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
    // Round-tripped, so a date that does not exist is not a date.
    return gmdate('Y-m-d\TH:i:s\Z', $epoch) === $t ? $epoch : null;
}


function slots_in(string $dir): array
{
    $names = @scandir($dir);
    if ($names === false) {
        return array();
    }
    return array_values(array_diff($names, array('.', '..')));
}


// --- one frame, as lines ---------------------------------------------------
//
// Built rather than echoed, so the watch loop can repaint each line where it
// already is instead of clearing the screen and drawing it again. A full clear
// once a second flickers; overwriting does not.

function render_lines(array $rates = array()): array
{
    $now = time();
    $L   = array();

    $state = array();
    foreach (MEMBERS as $m) {
        $root = STORES . '/' . $m;
        $hb   = latest_heartbeat($root . '/heartbeat');

        $fault = null;
        if (is_file($root . '/fault')) {
            $fault = json_decode((string) @file_get_contents($root . '/fault'), true);
        }
        $halt = null;
        if (is_file($root . '/halt')) {
            $halt = json_decode((string) @file_get_contents($root . '/halt'), true);
        }

        $state[$m] = array(
            'hb'     => $hb,
            'fault'  => $fault,
            'halt'   => $halt,
            'slots'  => slots_in($root . '/halts'),
            'hashes' => hashes_in($root . '/heartbeat'),
            'rate'   => $rates[$m] ?? null,
            'since'  => observing_since($root),
        );
    }

    // --- the verdict -------------------------------------------------------
    //
    // Taken from the files rather than computed: a halt in force is a halt in
    // force, whatever anything here thinks of the members' freshness.

    $halted = array();
    foreach (MEMBERS as $m) {
        if ($state[$m]['halt'] !== null) {
            $halted[$m] = $state[$m]['halt'];
        }
    }
    $missing = array();
    foreach (MEMBERS as $m) {
        if ($state[$m]['hb'] === null) {
            $missing[] = $m;
        }
    }

    $L[] = '';
    $L[] = '  ' . c('PLATFORM RACING 2', 'bold') . c('  ·  observer ring', 'grey')
        . str_repeat(' ', 22) . c(gmdate('Y-m-d\TH:i:s\Z', $now), 'grey');
    $L[] = '';

    if (count($halted) > 0) {
        $first = reset($halted);
        $who   = key($halted);
        $since = isset($first['when']) ? strtotime($first['when']) : null;

        $line = '  ' . c(' RING HALTED ', 'red') . '  '
            . c((string) ($first['reason'] ?? 'unknown'), 'bold');
        if (!empty($first['subject'])) {
            $line .= c(' on ' . $first['subject'], 'bold');
        }
        $line .= c(', found by ' . ($first['observer'] ?? $who), 'grey');
        if ($since !== null) {
            $line .= c(', ' . duration($now - $since) . ' ago', 'grey');
        }
        $L[] = $line;
        $L[] = '  ' . c(count($halted) . ' of ' . count(MEMBERS) . ' members holding a halt.'
            . ' Clearing is unanimous, so the work stays stopped until every member reads clean.', 'grey');
    } elseif (count($missing) > 0) {
        $L[] = '  ' . c(' RING INCOMPLETE ', 'amber') . '  '
            . c('no readable heartbeat from: ' . join(', ', $missing), 'bold');
    } else {
        $L[] = '  ' . c(' RING CLEAR ', 'green') . '  '
            . c(count(MEMBERS) . ' members · 12 directed edges · nothing halted', 'grey');
    }
    $L[] = '';

    // --- the members -------------------------------------------------------

    $L[] = '  ' . c(pad('MEMBER', 10) . pad('STATE', 16) . pad('SEQUENCE', 12)
        . pad('LAST BEAT', 12) . pad('CYCLE', 12) . pad('OBSERVING', 14) . 'CHECKS', 'grey');

    foreach (MEMBERS as $m) {
        $s  = $state[$m];
        $hb = $s['hb'];

        if ($hb === null) {
            $L[] = '  ' . pad(c($m, 'bold'), 10) . c('no heartbeat', 'red');
            continue;
        }

        $age     = $now - (int) strtotime($hb['timestamp']);
        $cadence = (int) ($hb['cadence_seconds'] ?? 0);

        // Two cadences of grace before the age is worth colouring. The gate's
        // own windows are what actually decide this; these are for reading.
        $age_colour = ($cadence > 0 && $age > $cadence * 2) ? 'amber' : 'green';
        if ($cadence > 0 && $age > $cadence * 6) {
            $age_colour = 'red';
        }

        if ($s['halt'] !== null) {
            $label = c('halted', 'red');
        } elseif ($s['fault'] !== null) {
            $label = c('fault', 'red');
        } elseif (!empty($hb['stop'])) {
            $label = c('stopped', 'amber');
        } else {
            $label = c('clear', 'green');
        }
        if (!empty($hb['boot'])) {
            $label .= c(' · booted', 'amber');
        }

        // A cycle should sit well under the ceiling. Approaching it is the
        // warning; reaching it is what `cycle-within-cadence` faults on.
        $rate = $s['rate'];
        if ($rate === null) {
            $cycle = c('--', 'grey');
        } elseif ($cadence <= 0) {
            $cycle = c(sprintf('%.1fs', $rate), 'grey');
        } elseif ($rate >= $cadence) {
            $cycle = c(sprintf('%.1fs', $rate), 'red');
        } elseif ($rate > $cadence * 0.7) {
            $cycle = c(sprintf('%.1fs', $rate), 'amber');
        } else {
            $cycle = c(sprintf('%.1fs', $rate), 'green');
        }

        // The measured cycle against the ceiling it must stay under, in one
        // column, because neither number means much without the other.
        $pace = $cycle . c('/' . $cadence . 's', 'grey');

        // Wall-clock since this member first ran, which survives restarts
        // because the file does. Not this process's uptime, and not a count of
        // anything.
        $observing = $s['since'] === null
            ? c('--', 'red')
            : c(duration(max(0, $now - $s['since'])), 'grey');

        $L[] = '  ' . pad(c($m, 'bold'), 10)
            . pad($label, 16)
            . pad((string) $hb['sequence'], 12)
            . pad(c($age . 's ago', $age_colour), 12)
            . pad($pace, 12)
            . pad($observing, 14)
            . c((string) ($hb['check_count'] ?? count($hb['checks'] ?? array())), 'grey');

        if ($s['fault'] !== null && !empty($s['fault']['failing'])) {
            $names = array();
            foreach ($s['fault']['failing'] as $f) {
                $names[] = $f['check'] . (empty($f['subject']) ? '' : ':' . $f['subject']);
            }
            $L[] = '  ' . str_repeat(' ', 10) . c('failing: ' . join(', ', array_unique($names)), 'red');
        }
    }
    $L[] = '';

    // --- the edges ---------------------------------------------------------
    //
    // Each member records, in its own heartbeat, the hash of the heartbeat it
    // read from every other member. That is the edge: not a claim that it
    // looked, but bytes it could not produce without having looked. Here the
    // recorded hash is searched for in the subject's folder as it stands now.
    //
    // `ok` means the reader's evidence is the subject's current publication.
    // `-n` means it is n publications behind, which is ordinary when the two
    // run on different cadences. `past` means the subject has pruned beyond
    // it, which this script cannot tell apart from a disagreement -- the
    // member's own V7 check can, and its fault file above is the answer.

    $L[] = '  ' . c('EDGES', 'grey')
        . c('   who has read whose heartbeat, and how recently. The row is the one doing the reading.', 'grey');
    $L[] = '';

    $head = '  ' . pad('', 10);
    foreach (MEMBERS as $m) {
        $head .= pad(c($m, 'grey'), 10);
    }
    $L[] = $head;

    foreach (MEMBERS as $reader) {
        $row = '  ' . pad(c($reader, 'bold'), 10);
        foreach (MEMBERS as $subject) {
            if ($reader === $subject) {
                $row .= pad(c('·', 'grey'), 10);
                continue;
            }
            $hb       = $state[$reader]['hb'];
            $recorded = $hb['observed'][$subject] ?? null;
            if ($recorded === null) {
                $row .= pad(c('none', 'red'), 10);
                continue;
            }
            $chain = $state[$subject]['hashes'];
            if (!isset($chain[$recorded])) {
                $row .= pad(c('past', 'amber'), 10);
                continue;
            }
            $names  = array_values($chain);
            $behind = count($names) - 1 - array_search($chain[$recorded], $names, true);
            $row .= pad($behind === 0 ? c('ok', 'green') : c('-' . $behind, 'green'), 10);
        }
        $L[] = $row;
    }

    // Plain words. Anyone reading this at three in the morning should not have
    // to work out what a cell means.
    $L[] = '';
    $L[] = '  ' . str_repeat(' ', 8) . pad(c('ok', 'green'), 8)
        . c('read the newest one', 'grey');
    $L[] = '  ' . str_repeat(' ', 8) . pad(c('-1', 'green'), 8)
        . c('read the one before that. Normal: they do not run in step', 'grey');
    $L[] = '  ' . str_repeat(' ', 8) . pad(c('-2', 'green'), 8)
        . c('two behind, and so on', 'grey');
    $L[] = '  ' . str_repeat(' ', 8) . pad(c('past', 'amber'), 8)
        . c('read something so old it has been deleted since', 'grey');
    $L[] = '  ' . str_repeat(' ', 8) . pad(c('none', 'red'), 8)
        . c('has not read it at all. Not normal', 'grey');
    $L[] = '';

    // --- the copies --------------------------------------------------------
    //
    // Every member writes its heartbeat a second time, into a peer's copy/ and
    // into super/copy-<self>/. The reader compares the deposit against the
    // author's own store, so a member publishing one thing to its own store
    // and another to its peer is caught by the peer. The author never makes
    // this comparison, which is the point of it.

    // Ordered the way the members are listed, so the coordinator's three come
    // first and the rest follow their own store.
    $copies = array();
    foreach (MEMBERS as $m) {
        if ($m === 'super') {
            foreach (array('web', 'multi', 'policy') as $author) {
                $copies[] = array(STORES . "/super/copy-$author/heartbeat", $author, "super/copy-$author");
            }
        } else {
            $copies[] = array(STORES . "/$m/copy/heartbeat", COPY_CYCLE[$m], "$m/copy");
            $copies[] = array(STORES . "/$m/copy-super/heartbeat", 'super', "$m/copy-super");
        }
    }

    $rows     = array();
    $matching = 0;

    foreach ($copies as $entry) {
        list($dir, $author, $label) = $entry;
        $files = heartbeat_files($dir);

        if (count($files) === 0) {
            $rows[] = array($label, $author, c('empty', 'red'));
            continue;
        }

        $bytes = @file_get_contents($dir . '/' . $files[count($files) - 1]);
        $here  = $bytes === false ? null : hash('sha256', $bytes);
        $own   = hashes_in(STORES . '/' . $author . '/heartbeat');

        if ($here !== null && isset($own[$here])) {
            $matching++;
            $rows[] = array($label, $author, c('ok', 'green'));
        } elseif ($here === null) {
            $rows[] = array($label, $author, c('unreadable', 'red'));
        } else {
            $rows[] = array($label, $author, c('past', 'amber'));
        }
    }

    $total   = count($copies);
    $summary = $matching === $total
        ? c('all ' . $total . ' match', 'green')
        : c($matching . ' of ' . $total . ' match', 'amber');

    $L[] = '  ' . pad(c('COPIES', 'grey'), 10) . pad($summary, 18)
        . c('each member writes its heartbeat a second time into another member\'s', 'grey');
    $L[] = '  ' . str_repeat(' ', 10)
        . c('folder, and that member checks the copy against the original', 'grey');
    $L[] = '';

    foreach ($rows as $r) {
        $L[] = '  ' . str_repeat(' ', 8) . pad(c($r[0], 'bold'), 22)
            . pad(c('from ' . $r[1], 'grey'), 16) . $r[2];
    }
    $L[] = '';

    // --- halt slots --------------------------------------------------------
    //
    // Only shown when something is in force. A slot is a halt delivered into a
    // member's store by another member, at a path the holder cannot write, so
    // a compromised container cannot delete the news about itself.

    $any = false;
    foreach (MEMBERS as $m) {
        if (count($state[$m]['slots']) > 0) {
            $any = true;
        }
    }
    if ($any) {
        $L[] = '  ' . c('HALT SLOTS', 'grey')
            . c('   delivered into a store by members that cannot be overwritten by its holder', 'grey');
        $L[] = '';
        foreach (MEMBERS as $m) {
            $slots = $state[$m]['slots'];
            $L[] = '  ' . pad(c($m, 'bold'), 10)
                . (count($slots) === 0 ? c('none', 'grey') : c('from ' . join(', ', $slots), 'red'));
        }
        $L[] = '';
    }

    return $L;
}


// --- drawing ---------------------------------------------------------------

// Whether repainting means anything. Piped into a file or through `head`
// there is no cursor to move, so the escapes would be noise in the output
// rather than a picture on a screen, and frames are simply printed one after
// another instead.
function is_screen(): bool
{
    return function_exists('stream_isatty') && @stream_isatty(STDOUT);
}

// Repaint in place: home the cursor, write each line followed by "erase to end
// of line", then erase whatever is left below. Nothing is cleared before it is
// replaced, so there is no frame where the screen is blank.
//
// The last line is written without a newline after it. A newline on the final
// row of a full window scrolls the whole thing by one, and then homing the
// cursor lands a row above where the previous frame started, which draws the
// header twice. That was the duplication.
function repaint(array $lines, bool $first): void
{
    if (!is_screen()) {
        foreach ($lines as $line) {
            echo $line . "\n";
        }
        echo "\n";
        return;
    }

    echo "\033[H";
    $last = count($lines) - 1;
    foreach ($lines as $i => $line) {
        echo $line . "\033[K" . ($i === $last ? '' : "\n");
    }
    echo "\033[J";
}

// The alternate screen buffer, which is what top and less draw into: a screen
// of its own that cannot scroll the real one, restored untouched on exit. It
// is the difference between a live view and a program that eats the terminal
// history of whoever ran it.
function alt_screen(bool $on): void
{
    if (is_screen()) {
        echo $on ? "\033[?1049h" : "\033[?1049l";
    }
}

function cursor(bool $visible): void
{
    if (is_screen()) {
        echo $visible ? "\033[?25h" : "\033[?25l";
    }
}


// --- run -------------------------------------------------------------------

// One reading, a short pause, then the frame. There is no rate without two
// readings, and a second is a small price for a number that is measured rather
// than assumed, which is the whole reason the column that used to be here was
// wrong.
if (!$watch) {
    $before = sample_sequences();
    $t0     = microtime(true);
    usleep(1000000);
    $rates = rates_between($before, sample_sequences(), microtime(true) - $t0);

    foreach (render_lines($rates) as $line) {
        echo $line . "\n";
    }
    exit(0);
}

// The cursor and the real screen both have to come back however this ends,
// including ctrl-c, or whoever ran it is left in a terminal with no cursor and
// no scrollback.
register_shutdown_function(function () {
    cursor(true);
    alt_screen(false);
});

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    $stop = function () {
        cursor(true);
        alt_screen(false);
        exit(0);
    };
    pcntl_signal(SIGINT, $stop);
    pcntl_signal(SIGTERM, $stop);
}

alt_screen(true);
cursor(false);
$first  = true;
$before = sample_sequences();
$t0     = microtime(true);
$rates  = array();
while (true) {
    $lines   = render_lines($rates);
    $lines[] = '  ' . c('watching every ' . $interval . 's, ctrl-c to stop', 'grey');
    repaint($lines, $first);
    $first = false;
    sleep($interval);

    // Each frame measures against the one before it, so the rate shown is the
    // rate over the interval just elapsed rather than an average since start.
    $after  = sample_sequences();
    $t1     = microtime(true);
    $rates  = rates_between($before, $after, $t1 - $t0);
    $before = $after;
    $t0     = $t1;
}
