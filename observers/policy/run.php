<?php

namespace pr2obs\policy;

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
    if ($raw === false || $raw === '' || preg_match('/^\d+$/', $raw) !== 1) {
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
    'now'                         => gmdate('Y-m-d\TH:i:s\Z'),
);

// SPEC 8: the staleness tolerance is counted in the reader's own cycles from
// its own on-disk window, so it can never exceed the window.
if ($params['stale_slack_cycles'] > $params['window']) {
    fwrite(STDERR, "observer: OBSERVER_STALE_SLACK_CYCLES cannot exceed the window\n");
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
    'deferred'    => array(),
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

while (true) {
    $began = hrtime(true);
    $state['params']['now'] = gmdate('Y-m-d\TH:i:s\Z');

    $plan = run_cycle(array(
        'stores'          => $state['stores'],
        'identity'        => $state['identity'],
        'params'          => $state['params'],
        'basis'           => $state['basis'],
        'memory'          => $state['memory'],
        'booted'          => $state['booted'],
        'cadence_seconds' => $state['params']['cadence_seconds'],
        'deferred'        => $state['deferred'],
        'container_baseline' => $container_baseline,
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

    // SPEC 13.12: an observer times its own cycle against the cadence it
    // declares. Overrunning is reported in the *next* cycle, since this one
    // has already published. It does not check less in order to keep up: an
    // observer that cannot keep its cadence is not verifying the system, and a
    // system that is not being verified should not be serving.
    $elapsed = (hrtime(true) - $began) / 1e9;
    if ($elapsed > $state['params']['cadence_seconds']) {
        $state['deferred'][] = array(
            'check'   => 'cycle-within-cadence',
            'subject' => null,
            'detail'  => sprintf('the cycle took %.2fs against a cadence of %ds',
                                 $elapsed, $state['params']['cadence_seconds']),
        );
    }

    // No catch-up burst: an overrunning cycle is followed immediately by the
    // next one.
    $remaining = $state['params']['cadence_seconds'] - $elapsed;
    if ($remaining > 0) {
        usleep((int) ($remaining * 1000000));
    }
}
