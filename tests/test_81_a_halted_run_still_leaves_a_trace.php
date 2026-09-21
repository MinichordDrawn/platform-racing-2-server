<?php
// A halted run still leaves a trace.
//
// The second deadlock the coupling produced, and the one whose fix is worth
// more than the fix.
//
// A halt stops the work, and cron jobs are work, so a halt stops them. Their
// traces then go stale, and a stale trace is a fault, and a fault is a halt.
// So any halt that outlived a schedule's period made itself permanent: the
// thing that stopped the job was the reason the job's absence was a problem,
// and no sequence of events could clear it. A ninety-second blip and a real
// compromise had the same consequence, and it needed a person with a shell
// either way.
//
// The resolution is not to soften the check and not to exempt cron. It is that
// **the job still runs**. It reads the gate, finds the ring says stop, does no
// work, and writes a trace saying exactly that. The scheduler demonstrably
// ran; the record says it was refused. `trace-fresh` is satisfied by a truthful
// record rather than by a concession, and the traces become a log of every
// period the deployment was stopped, which nothing else keeps.
//
// The same shape settles the first deadlock too. Nothing is prevented from
// starting; everything is prevented from working. A halted web container still
// runs Apache and answers 503. A halted game server still listens and refuses.
// A halted scheduler still runs and records the refusal. Every "is the work
// there" check passes, every "is the work doing its job" check is answered
// honestly, and there is no circularity left anywhere.

require_once __DIR__ . '/helper.php';

$tmp = sys_get_temp_dir() . '/pr2halted-' . getmypid();
define('CACHE_DIR', $tmp);

require_once REPO . '/common/trace.php';
require_once REPO . '/observers/web/procedures.php';
require_once REPO . '/observers/web/traces.php';
require_once REPO . '/observers/web/schedules.php';

echo "a halted run still leaves a trace\n";

function t81_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t81_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

function t81_reader()
{
    $R = new \pr2obs\web\Reader(
        sys_get_temp_dir() . '/pr2halted-nostores',
        'web',
        array(
            'window' => 4, 'cadence_seconds' => 10, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ),
        null,
        array()
    );
    $R->others = array('multi', 'policy', 'super');
    return $R;
}

function t81_checks($R)
{
    $out = array();
    foreach ($R->findings as $f) {
        $out[] = $f['check'] . ':' . $f['subject'];
    }
    sort($out);
    return $out;
}

function t81_daily_only()
{
    foreach (\pr2obs\web\schedules_config() as $s) {
        if ($s['schedule'] === 'daily') {
            return array($s);
        }
    }
    return array();
}

const T81_OBSERVED_LONG = 2000000;

t81_rm($tmp);

// --- the refused run ------------------------------------------------------

$ok = trace_halted('daily', 'policy has halted');
ok($ok === true, 'a refused run can record itself');

$file = "$tmp/traces/daily/0000000001.trace";
ok(is_file($file), 'and lands under its schedule like any other run');

$lines = explode("\n", rtrim(file_get_contents($file), "\n"));
is_same(count($lines), 3, 'a header, the refusal, and a finish');
is_same(json_decode($lines[0], true)['kind'], 'trace', 'it is an ordinary trace header');
is_same(json_decode($lines[1], true)['kind'], 'halted', 'the middle line says the run was refused');
is_same(json_decode($lines[1], true)['reason'], 'policy has halted', 'and why, for a person');
is_same(json_decode($lines[2], true)['kind'], 'finish', 'and it is finished, because it is');

// --- and the observer accepts it -----------------------------------------

$R = t81_reader();
\pr2obs\web\check_traces($R, "$tmp/traces", t81_daily_only(), T81_OBSERVED_LONG);
is_same(t81_checks($R), array(),
    'the observer reports nothing: the scheduler ran, and said what happened');

// The point of the whole thing: this is what keeps the halt clearable. Without
// it the same state reports the job missing, which is another halt, which
// stops the job, which is why it is missing.
$R = t81_reader();
\pr2obs\web\check_traces($R, "$tmp/traces", t81_daily_only(), T81_OBSERVED_LONG);
$stale = array();
foreach ($R->findings as $f) {
    if (strpos($f['check'], 'trace-') === 0) {
        $stale[] = $f['check'];
    }
}
is_same($stale, array(), 'so nothing about this schedule keeps the halt in force');

// --- a refused run is not a run that did no work --------------------------
//
// T3 asks whether the declared tasks were completed, and a refused run
// completed none of them. If coverage were measured against it, every halt
// would produce a coverage fault a cycle later -- the same trap in a different
// place. Coverage is measured against the most recent finished run that was
// not refused, because that is the most recent run that says anything about
// what the job does.

$declared = \pr2obs\web\SCHEDULE_DECLARED['daily'];

$trace = trace_begin('daily');
foreach ($declared as $id) {
    trace_task($trace, $id, function () { return 0; });
}
trace_finish($trace);

trace_halted('daily', 'web has halted');

$R = t81_reader();
\pr2obs\web\check_traces($R, "$tmp/traces", t81_daily_only(), T81_OBSERVED_LONG);
is_same(t81_checks($R), array(),
    'a refusal after a good run leaves coverage measured against the good run');

// And a task that really did stop running is still caught through a refusal.
$trace = trace_begin('daily');
foreach ($declared as $id) {
    if ($id === 'tokens_delete_old') {
        continue;
    }
    trace_task($trace, $id, function () { return 0; });
}
trace_finish($trace);
trace_halted('daily', 'web has halted');

$R = t81_reader();
\pr2obs\web\check_traces($R, "$tmp/traces", t81_daily_only(), T81_OBSERVED_LONG);
is_same(t81_checks($R), array('trace-coverage:daily'),
    'a task that stopped running is still reported through a later refusal');

t81_rm($tmp);

// --- the scheduler is what reads the gate ---------------------------------
//
// It cannot be config.php, which is where every other short-lived runtime is
// refused: config.php runs before the job's first line and has no idea which
// schedule it is in front of, so it could not name the trace it would have to
// write. So the schedules are invoked through a wrapper that knows, and the
// wrapper is what decides.

$run = file_get_contents(REPO . '/common/cron/run.php');

ok(strpos($run, 'observer_gate_reason(') !== false, 'the wrapper reads the gate itself');
ok(strpos($run, 'trace_halted(') !== false, 'and records a refusal as a run');
// The require, not the several mentions of it in the prose above it.
ok(
    preg_match('/^\s*require\s+[^;\n]*config\.php/m', $run, $m, PREG_OFFSET_CAPTURE) === 1,
    'and loads the application on the other path'
);
$halted_at = strpos($run, 'trace_halted(');
$config_at = $m[0][1];
ok($halted_at < $config_at, 'the refusal is recorded without the application being loaded');

// The schedule comes from the command line, so it is checked against a fixed
// set rather than used as a path. A schedule argument that reached require()
// would be an argument that chose which file to execute.
ok(
    preg_match('/minute.*hourly.*daily.*weekly/s', $run) === 1,
    'the wrapper names the schedules it will accept'
);

// --- being unable to ask is not the same as being told no -----------------
//
// A recorded refusal means the ring said stop, and the ring is content with
// that for as long as it likes because it knows why. A scheduler that could
// not read its configuration and wrote the same record would be a scheduler
// that never did any work and never would, behind a trace that kept every
// member happy. That is not hypothetical: cron does not pass the container's
// environment to its jobs, so the first deployment of this wrapper refused
// every run for that reason and reported itself in perfect health.
//
// So it writes nothing, the trace goes stale, and the deployment stops.
$settings_at = strpos($run, 'observer_gate_settings()');
$reason_at   = strpos($run, 'observer_gate_reason(');
ok($settings_at !== false && $settings_at < $reason_at,
    'the wrapper reads its configuration before it reads the store');
ok(
    preg_match('/is_string\(\$settings\)[^}]*exit\(/s', $run) === 1,
    'and a configuration it cannot read stops the run without recording a refusal'
);
ok(
    preg_match('/is_string\(\$settings\)[^}]*trace_halted/s', $run) !== 1,
    'so a misconfigured scheduler is reported by its silence, not excused by a record'
);

// --- and the configuration actually reaches the jobs ----------------------
//
// cron builds a minimal environment for every job rather than passing the
// container's, which is why the above was not hypothetical. A crontab may
// carry NAME=value lines that apply to its own entries, so the startup script
// puts them there.

$cron_sh = file_get_contents(REPO . '/docker/cron_startup.sh');
foreach (array(
    'OBSERVER_STORES',
    'OBSERVER_LOCAL',
    'OBSERVER_LOCAL_MAX_AGE_SECONDS',
    'OBSERVER_SUPER_MAX_AGE_SECONDS',
) as $name) {
    ok(strpos($cron_sh, $name) !== false, "cron carries $name to its jobs");
}

// A value with a newline in it would be a value that adds a crontab entry, and
// one with a space would change the entry after it. The check is on the way
// in, and it stops the container rather than trying to make the value safe.
ok(
    preg_match('/case .*value.*in/s', $cron_sh) === 1
    && strpos($cron_sh, 'exit 2') !== false,
    'and refuses to start on a value it cannot put in a crontab'
);

// --- and it is what cron invokes ------------------------------------------

$crontab = file_get_contents(REPO . '/docker/minute-cron');
$schedules = array('minute', 'hourly', 'daily', 'weekly');

foreach ($schedules as $s) {
    ok(
        preg_match('/cron\/run\.php ' . $s . '\b/', $crontab) === 1,
        "cron runs $s through the wrapper"
    );
    ok(
        preg_match('/cron\/' . $s . '\.php/', $crontab) !== 1,
        "and not $s.php directly, which would go through config.php and vanish"
    );
}

// The prepend has to be off, for the same reason it is off for the observer
// and for the barrier: config.php is the thing that refuses, and a wrapper
// behind it would be refused before it could write the record.
ok(
    substr_count($crontab, '-d auto_prepend_file= /pr2/common/cron/run.php') === 4,
    'every schedule runs the wrapper with the prepend disabled'
);

// The warm-up runs in the web container are the same jobs and go the same way.
$sh = file_get_contents(REPO . '/docker/http_server_startup.sh');
foreach (array('minute', 'hourly') as $s) {
    ok(
        preg_match('/run\.php ' . $s . '\b/', $sh) === 1,
        "the $s warm-up run goes through the wrapper too"
    );
}

// --- the parser knows what a refusal looks like ---------------------------
//
// Traces are read by a strict parser, deliberately: a line it does not
// recognise makes the whole run malformed, which is a fault. So the kind has
// to be known to it, and a malformed one still has to be rejected.

ok(\pr2obs\web\parse_trace(
    '{"kind":"trace","version":1,"schedule":"daily","run":1,"started":"2026-09-20T10:00:00Z"}' . "\n"
    . '{"kind":"halted","reason":"policy has halted"}' . "\n"
    . '{"kind":"finish","finished":"2026-09-20T10:00:00Z"}' . "\n"
) !== null, 'a refusal parses');

ok(\pr2obs\web\parse_trace(
    '{"kind":"trace","version":1,"schedule":"daily","run":1,"started":"2026-09-20T10:00:00Z"}' . "\n"
    . '{"kind":"halted"}' . "\n"
) === null, 'a refusal with no reason does not');

ok(\pr2obs\web\parse_trace(
    '{"kind":"trace","version":1,"schedule":"daily","run":1,"started":"2026-09-20T10:00:00Z"}' . "\n"
    . '{"kind":"refused","reason":"x"}' . "\n"
) === null, 'and neither does a line the reader has never heard of');

t_done();
