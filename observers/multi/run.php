<?php

namespace pr2obs\multi;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/procedures.php';
require_once __DIR__ . '/traces.php';
require_once __DIR__ . '/cycle.php';
require_once __DIR__ . '/apply.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/container.php';

// The observer process.
//
// A separate process on the same host as the work it watches -- not a tick
// inside the work's own loop. If that loop wedges, a tick inside it goes
// silent with it and reports nothing; a separate process on the same machine
// still dies when the machine does, which preserves substrate dependency,
// while reporting specifically when only the work has failed.
//
// It loads nothing from the application and nothing from any other observer.

// --- configuration ---------------------------------------------------------
//
// Everything SPEC.md lists under "Values to be set" is required from the
// environment and nothing is defaulted. A default here would be this file
// quietly deciding a question the design left open, and the number would then
// live in code where nobody reviewing a deployment would see it. Setting an
// environment variable is setup; guessing is a decision.

function required_int(string $name, int $min, int $max): int
{
    $raw = getenv($name);
    if ($raw === false || $raw === '' || preg_match('/^\d+\z/', $raw) !== 1) {
        fwrite(STDERR, "observer: $name must be set to an integer\n");
        exit(2);
    }
    $v = (int) $raw;
    if ($v < $min || $v > $max) {
        fwrite(STDERR, "observer: $name must be between $min and $max\n");
        exit(2);
    }
    return $v;
}

function required_string(string $name): string
{
    $raw = getenv($name);
    if ($raw === false || $raw === '') {
        fwrite(STDERR, "observer: $name must be set\n");
        exit(2);
    }
    return $raw;
}

$identity = required_string('OBSERVER_IDENTITY');

// The shortest a cycle may take. Not a pace to the cadence -- a floor.
//
// Without one, each observer runs as fast as its own workload allows, and
// those differ by fifty times: the observer with no application in its
// container turns nearly three hundred cycles a second while the one hashing
// a code tree turns six. A reader's recorded hash then falls out of a fast
// subject's four-entry window between two of its own reads, and every member
// reports I1 against every other. The floor equalises them, because every
// cycle is shorter than it.
$min_cycle_ms = required_int('OBSERVER_MIN_CYCLE_MS', 0, 60000);
// How long after this process starts the work in this container is allowed to
// take before its absence is a fault.
//
// It exists because the coupling makes the work wait for the ring, and the
// process and port checks make the ring wait for the work. Without a grace the
// two deadlock on a cold start: the barrier waits for a clear ring, the ring
// is not clear because the work is not running, and the work is not running
// because the barrier is waiting. Every statement true, and nothing starts
// ever again.
//
// It must exceed the time a cold start takes to reach a clear ring and a
// listening process, or it expires while the barrier is still legitimately
// waiting and the deadlock is back. It is a ceiling on how long work that is
// never coming stays unreported, so it should not be larger than it has to be.
$work_grace = required_int('OBSERVER_WORK_START_GRACE_SECONDS', 1, 3600);
$stores   = getenv('OBSERVER_STORES') ?: '/stores';


$params = array(
    // Settled by the design, so it is not configuration.
    'window'                      => 4,
    'cadence_seconds'             => required_int('OBSERVER_CADENCE_SECONDS', 1, 3600),
    'stale_slack_cycles'          => required_int('OBSERVER_STALE_SLACK_CYCLES', 1, 4),
    'staging_stale_after_seconds' => required_int('OBSERVER_STAGING_STALE_AFTER_SECONDS', 1, 86400),
    'max_heartbeat_bytes'         => required_int('OBSERVER_MAX_HEARTBEAT_BYTES', 512, 1048576),
    'max_fault_bytes'             => required_int('OBSERVER_MAX_FAULT_BYTES', 512, 1048576),
    'max_halt_bytes'              => required_int('OBSERVER_MAX_HALT_BYTES', 512, 1048576),
    // The absolute backstop on a member's liveness: the one threshold read
    // against a wall clock rather than against a hash. Generous next to the
    // cadence ceiling, because that is what makes clock skew irrelevant.
    'heartbeat_max_age_seconds'    => required_int('OBSERVER_HEARTBEAT_MAX_AGE_SECONDS', 1, 3600),
    'now'                         => gmdate('Y-m-d\TH:i:s\Z'),
);

// The backstop has to be longer than the cadence this observer declares, or it
// cannot tell a member that is keeping its cadence from one that has stopped:
// every heartbeat would be older than the limit a moment after it was written,
// and every member would read as stale for ever.
//
// Checked once, here, where both values are set -- not re-derived by every
// reader against every subject. A misconfiguration should stop the thing that
// is misconfigured rather than be discovered separately by everybody else.
if ($params['heartbeat_max_age_seconds'] <= $params['cadence_seconds']) {
    fwrite(STDERR, "observer: OBSERVER_HEARTBEAT_MAX_AGE_SECONDS must exceed OBSERVER_CADENCE_SECONDS\n");
    exit(2);
}

// SPEC 7: if the own store cannot be read, or its heartbeat folder cannot be
// created, the observer does not run. There is no store to write a fault into
// and no heartbeat to make silence legible, so it exits and lets its peers
// find its folder stale or absent -- which is the designed signal for exactly
// this case. Starting anyway would be an observer that cannot observe,
// reporting that all is well.
$own = join_path($stores, $identity);
if (list_dir($own) === null) {
    fwrite(STDERR, "observer: $own cannot be listed; refusing to start\n");
    exit(3);
}
$hb = join_path($own, 'heartbeat');
if (!is_dir($hb) && !@mkdir($hb, 0775, true) && !is_dir($hb)) {
    fwrite(STDERR, "observer: $hb cannot be created; refusing to start\n");
    exit(3);
}

// --- state carried between cycles -----------------------------------------
//
// SPEC 4: basis and memory are in-process state. They are never read back from
// the observer's own store during a cycle, because the whole point of memory
// is to notice when the store disagrees with it.
$state = array(
    'stores'      => $stores,
    'identity'    => $identity,
    'params'      => $params,
    'basis'       => null,       // no basis on the first cycle, by design
    'memory'      => array(),
    'since'       => array(),
    'failing_set' => null,
    'booted'      => true,
    'started'     => gmdate('Y-m-d\TH:i:s\Z'),
    'unchanged'   => array(),
    'last_observed' => array(),
    // Resolved to a real stream here rather than left as null meaning "the
    // default". null is the one value `??` treats as absent, so `$x ?? false`
    // silently turns "use the default" into "do not log" -- which it did, in
    // two separate places, while the observer wrote a perfect chain and said
    // nothing. Passing the stream removes the ambiguity instead of patching
    // each site that could reintroduce it.
    'log'         => default_sink(),
);

// SPEC 7: memory is rebuilt from the observer's own store on start. This is
// trust on boot and it is a stated limit -- a file altered while the observer
// was down is caught by the chain rules and by peers whose basis predates the
// restart, not by I5.
foreach ((list_dir($hb) ?? array()) as $name) {
    if (is_heartbeat_name($name)) {
        $h = hash_file_bytes(join_path($hb, $name));
        if ($h !== null) {
            $state['memory'][$identity . '/heartbeat/' . $name] = $h;
        }
    }
}
foreach (array('fault', 'halt') as $f) {
    $p = join_path($own, $f);
    if (is_file($p)) {
        $h = hash_file_bytes($p);
        if ($h !== null) {
            $state['memory'][$identity . '/' . $f] = $h;
        }
    }
}

// The container as it is at this moment, which is what it must still be
// for as long as this observer runs. Taken from the container it watches, so
// a tampered image baselines itself -- a limit the design records rather than
// hides, because establishing that an image was the intended image needs
// provenance from outside the deployment.
$container_baseline = container_baseline();

log_line($state['log'], 'started', array(
    'observer' => $identity,
    'stores'   => $stores,
    'cadence'  => $params['cadence_seconds'],
));

// --- a deliberate stop is not a death -------------------------------------
//
// An observer shut down on purpose otherwise reads as dead for ever. Its final
// heartbeat records that the stop was deliberate, so a stale heartbeat saying
// "running" is a death and one saying "stopped" is a retirement.
$stopping = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stopping) { $stopping = true; });
    pcntl_signal(SIGINT, function () use (&$stopping) { $stopping = true; });
}

// --- the loop --------------------------------------------------------------

// The work has been seen running at least once since this process started.
//
// A latch rather than a per-cycle answer, and the asymmetry is the point:
// before the work has ever appeared, its absence is explained by the barrier
// that is holding it back, and after it has appeared once, nothing explains
// its absence. So a work process that starts and then dies inside the grace is
// reported immediately rather than waiting the grace out.
//
// It resets when this process does, which is correct: the grace covers the gap
// between a container starting and its work being admitted, and that gap
// begins again on every restart. This is the opposite of the clock the trace
// check uses, deliberately -- there, the durable sequence is what stops a
// schedule being granted a fresh period of grace every time the container
// bounced.
$work_seen = false;
$process_started = time();

while (true) {
    $began = hrtime(true);
    $state['params']['now'] = gmdate('Y-m-d\TH:i:s\Z');

    // SPEC 13.3, done here because it is a write. Asking before the cycle
    // rather than discovering it at publication is what lets this cycle act
    // on the answer instead of the next one inheriting it.
    $writable = own_store_writable_at($stores, $identity);

    $plan = run_cycle(array(
        'stores'          => $state['stores'],
        'identity'        => $state['identity'],
        'params'          => $state['params'],
        'basis'           => $state['basis'],
        'memory'          => $state['memory'],
        'booted'          => $state['booted'],
        'cadence_seconds' => $state['params']['cadence_seconds'],
        'unchanged'       => $state['unchanged'] ?? array(),
        'own_store_writable' => $writable,
        'container_baseline' => $container_baseline,
        'work_due'        => $work_seen || (time() - $process_started) >= $work_grace,
        // No scheduled work runs on this host, so there are no traces to
        // read. The trace validations are present in this observer and stay
        // unused: a copy that quietly diverged from the others would be the
        // beginning of four implementations that are no longer the same one.
        'traces_root'     => '',
        'traces'          => array(),
        // T4 asks the database whether the work the trace claims was done
        // actually is done. That needs a credential and belongs with the
        // other local checks in step 8; the classification it feeds is
        // already implemented and exercised by the fixtures.
        'db'              => array(),
    ));

    if ($plan['work_alive'] === true && !$work_seen) {
        $work_seen = true;
        log_line($state['log'], 'work-seen', array(
            'observer' => $identity,
            'after'    => time() - $process_started,
        ));
    }

    if ($stopping) {
        $plan['publish']['stop'] = true;
    }

    apply_cycle($state, $plan);

    if ($stopping) {
        log_line($state['log'], 'stopped', array(
            'observer' => $identity,
            'sequence' => $plan['publish']['sequence'],
        ));
        exit(0);
    }

    // The next cycle begins as soon as this one ends, unless it finished
    // faster than the floor, in which case it waits for it.
    //
    // The cadence remains a ceiling rather than a pace: the longest a cycle
    // may take and still count as verifying the system. The cycle checks that
    // itself, before it publishes, so an overrun is acted on by the cycle
    // that overran rather than inherited by the next one.
    $elapsed = (hrtime(true) - $began) / 1e9;

    $floor = $min_cycle_ms / 1000;
    if ($elapsed < $floor) {
        usleep((int) (($floor - $elapsed) * 1000000));
        // Measured again, because what the next block needs is the wall-clock
        // interval between one observation and the next, not the work.
        $elapsed = (hrtime(true) - $began) / 1e9;
    }

    // How long each subject has gone unchanged, by this observer's own
    // monotonic clock. With cycles unpaced, a count of them is no longer a
    // unit of time -- this reader can turn many in the interval a peer takes
    // to write once -- so staleness is measured in seconds and accumulated
    // here.
    $before = $state['last_observed'] ?? array();
    $after  = $plan['publish']['observed'];
    $unchanged = array();
    foreach ($after as $who => $hash) {
        $unchanged[$who] = ($hash !== null && isset($before[$who]) && $before[$who] === $hash)
            ? ($state['unchanged'][$who] ?? 0.0) + $elapsed
            : 0.0;
    }
    $state['unchanged']    = $unchanged;
    $state['last_observed'] = $after;
}
