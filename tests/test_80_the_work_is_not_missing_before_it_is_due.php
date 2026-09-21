<?php
// The work is not missing before it is due.
//
// This exists because of a deadlock that a running deployment produced within
// twenty seconds of the coupling being built, and that no test here would have
// found.
//
// Each observer asserts that the work in its container is running --
// `process-alive`, and `port-answers` for the ports that container should
// answer on. Those are local assertions, so a failure is a fault, and a fault
// anywhere stops the work. Step 10 then added the barrier: the work does not
// start until the gate opens, and the gate does not open while anything is
// faulted.
//
//   The barrier waits for the ring to be clear.
//   The ring is not clear, because the work is not running.
//   The work is not running, because the barrier is waiting.
//
// Three statements, each correct, and nothing starts ever again. All four
// containers sat in it, raising a halt every six seconds, until the volumes
// were removed.
//
// The fix is the one this design already made for traces, where *never run
// yet* had to be told apart from *stopped*: the work is not asserted until it
// is due. Due means either this observer has seen it alive at some point since
// it started -- after which its disappearance is a fault however new the
// container is -- or a declared grace has passed since this observer started,
// after which work that has never appeared is work that is not coming.
//
// The clock is this observer process's own uptime, and here that is the right
// one rather than the wrong one: the grace exists to cover the gap between a
// container starting and its work being admitted, and that gap begins again on
// every restart. The durable sequence the trace check uses would grant the
// grace once in the deployment's life and never again.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

echo "the work is not missing before it is due\n";

// The checks that are about the work rather than about the container. These
// are the ones the barrier's own condition depends on, and therefore the ones
// that cannot be asserted while the barrier is what is holding the work back.
$about_the_work = array('port-answers', 'process-alive');   // sorted, as the helper returns them

function t80_reader()
{
    $R = new \pr2obs\web\Reader(
        sys_get_temp_dir() . '/pr2obs-t80',
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

function t80_checks($R, $only)
{
    $out = array();
    foreach ($R->findings as $f) {
        if (in_array($f['check'], $only, true)) {
            $out[] = $f['check'];
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

// A baseline that agrees with disk, so nothing in this file is reported for
// any reason other than the work. The process and the ports are genuinely
// absent here -- this is not a container -- which is exactly the state a
// container is in while its barrier is waiting.
function t80_baseline()
{
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    return array('code' => \pr2obs\web\code_manifest(), 'extensions' => $extensions);
}

$baseline = t80_baseline();

// --- before it is due -----------------------------------------------------

$R = t80_reader();
\pr2obs\web\check_container($R, $baseline, false);
is_same(t80_checks($R, $about_the_work), array(),
    'a container whose work has not started yet reports nothing about the work');

// And nothing else is suppressed along with it. The container's own shape is
// asserted from the first cycle, because it is true from the first cycle --
// the image is what it is before anything in it runs.
$R = t80_reader();
\pr2obs\web\check_container($R, array(
    'code'       => array('/pr2/common/env_check.php' => str_repeat('a', 64)),
    'extensions' => array('zzz_not_a_real_extension'),
), false);
is_same(
    t80_checks($R, array('code-unchanged', 'extensions-unchanged')),
    array('code-unchanged', 'extensions-unchanged'),
    'while the container itself is still asserted from the first cycle'
);

// --- once it is due -------------------------------------------------------

$R = t80_reader();
\pr2obs\web\check_container($R, $baseline, true);
is_same(t80_checks($R, $about_the_work), $about_the_work,
    'once the work is due, work that is not there is reported');

// The default is the strict one. Every other caller of this function -- the
// fixtures, test 77 -- gets the assertion, and only a caller that has a reason
// to suppress it has to say so.
$R = t80_reader();
\pr2obs\web\check_container($R, $baseline);
is_same(t80_checks($R, $about_the_work), $about_the_work,
    'and a caller that says nothing gets the assertion');

// --- what the cycle saw ---------------------------------------------------
//
// The latch needs an answer, not an absence of findings: "the work was not
// reported" is true both when it is running and when it was not yet due, and
// only one of those should start the latch.

$R = t80_reader();
\pr2obs\web\check_container($R, $baseline, false);
is_same($R->work_alive, false, 'the cycle records that it did not see the work');

$R = t80_reader();
is_same($R->work_alive, null, 'and records nothing before it has looked');

// --- the cycle passes it through ------------------------------------------

$cycle = file_get_contents(REPO . '/observers/web/cycle.php');
ok(preg_match('/check_container\s*\(\s*\$R\s*,[^)]*work_due/', $cycle) === 1,
    'the cycle tells the container check whether the work is due');
ok(strpos($cycle, "'work_alive'") !== false,
    'and reports back what it saw, so the caller can latch it');

// A cycle told nothing still asserts. The fixture set supplies no such key and
// must keep behaving as it always did.
ok(preg_match("/work_due'\]\s*\?\?\s*true/", $cycle) === 1,
    'a cycle that is told nothing assumes the work is due');

// --- the process reads the grace and latches ------------------------------

foreach (glob(REPO . '/observers/*/run.php') as $file) {
    $src = file_get_contents($file);
    $name = basename(dirname($file));

    ok(strpos($src, 'OBSERVER_WORK_START_GRACE_SECONDS') !== false,
        "$name requires the grace from its environment");
    ok(strpos($src, "'work_due'") !== false, "$name tells its cycle whether the work is due");
    ok(preg_match('/work_seen/', $src) === 1, "$name latches having seen the work");
}

// --- and every gated container declares it --------------------------------

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');
foreach (array('web', 'multi', 'policy', 'super') as $svc) {
    ok(
        substr_count($compose, "- OBSERVER_WORK_START_GRACE_SECONDS=") === 4,
        'every observer declares a work-start grace'
    );
    break;
}

// The constraint the design puts on it: it must be longer than a cold start
// takes to reach a clear ring and a listening process, or the grace expires
// while the barrier is still legitimately waiting and the deadlock is back.
// Nothing here can measure that, so what is checked is that it is set well
// clear of the one number in the same file that bounds a cycle.
preg_match_all('/- OBSERVER_WORK_START_GRACE_SECONDS=(\d+)/', $compose, $g);
preg_match_all('/- OBSERVER_CADENCE_SECONDS=(\d+)/', $compose, $c);
ok(count($g[1]) === 4 && count($c[1]) === 4, 'all four are set');
foreach ($g[1] as $i => $grace) {
    ok((int) $grace >= 10 * (int) $c[1][$i],
        "the grace is many cycles rather than one or two ($grace against {$c[1][$i]})");
}

t_done();
