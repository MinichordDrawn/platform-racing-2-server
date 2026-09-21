
<?php
// A heartbeat declares the checks that ran.
//
// Every cycle publishes a list of the checks it made. It is the observer's own
// account of what it did, it is the only record of that, and nothing verifies
// it: a reader compares the list against its own length and stops there. So
// the list is worth exactly as much as the care taken writing it, and it was
// wrong in both directions at once.
//
// Declared and dead. `multi` and `policy` each claimed `document-root` and
// `apache-modules`, which are guarded behind constants those containers leave
// empty. Neither can fail there, so both containers published two checks they
// never made, every cycle.
//
// Made and undeclared. Every observer raises `cycle-within-cadence` and
// `staging-fresh`, and no observer listed either. The one that reads traces
// raises three more it did not mention.
//
// The rule is one line: **a column declares a check exactly when the constant
// that check reads is not empty**, and the cycle publishes everything it can
// raise. The first half is what this file computes rather than lists, so a
// container that gains a port or loses a document root is covered without
// anyone editing a table here.

require_once __DIR__ . '/helper.php';

echo "a heartbeat declares the checks that ran\n";

foreach (array('web', 'multi', 'policy', 'super') as $o) {
    require_once REPO . "/observers/$o/cycle.php";
    require_once REPO . "/observers/$o/apply.php";
}
// The declared task sets, which only the observer on the scheduler's host
// carries. Loaded explicitly because nothing in the cycle pulls it in: the
// process does, and this file is not the process.
require_once REPO . '/observers/web/schedules.php';

// Each column check, and the constant whose emptiness decides whether it can
// fire at all. Read from container.php's own guards.
$gated_by = array(
    'extensions-declared' => 'CONTAINER_EXTENSIONS',
    'document-root'       => 'CONTAINER_DOCUMENT_ROOT',
    'apache-modules'      => 'CONTAINER_APACHE_MODULES',
    'env-not-example'     => 'CONTAINER_ENV_FILE',
    'debug-mode-off'      => 'CONTAINER_ENV_FILE',
    'paypal-live'         => 'CONTAINER_ENV_FILE',
    'process-alive'       => 'CONTAINER_PROCESS',
    'port-answers'        => 'CONTAINER_PORTS',
);

// Checks every container makes, whatever is in it.
$always = array('code-unchanged', 'php-version', 'extensions-unchanged');

function t89_const($observer, $name)
{
    $full = "pr2obs\\$observer\\$name";
    return defined($full) ? constant($full) : null;
}

// --- a column declares what it can fail, and only that --------------------

foreach (array('web', 'multi', 'policy', 'super') as $o) {
    $declared = call_user_func("pr2obs\\$o\\container_local_checks");
    sort($declared, SORT_STRING);

    $expected = $always;
    foreach ($gated_by as $check => $const) {
        $v = t89_const($o, $const);
        $live = is_array($v) ? count($v) > 0 : ($v !== '' && $v !== null);
        if ($live) {
            $expected[] = $check;
        }
    }
    $expected = array_values(array_unique($expected));
    sort($expected, SORT_STRING);

    is_same($declared, $expected, "$o declares exactly the column checks it can fail");
}

// Stated separately so the failure names the case rather than a set
// difference: these two were the ones being claimed and never made.
foreach (array('multi', 'policy') as $o) {
    $declared = call_user_func("pr2obs\\$o\\container_local_checks");
    foreach (array('document-root', 'apache-modules') as $dead) {
        ok(
            !in_array($dead, $declared, true),
            "$o does not claim $dead, which its constants guard off"
        );
    }
}

// --- and the cycle publishes everything it raises -------------------------

function t89_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t89_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

function t89_tree()
{
    $dir = sys_get_temp_dir() . '/pr2checks-' . getmypid() . '-' . mt_rand();
    foreach (array('web', 'multi', 'policy', 'super') as $m) {
        mkdir("$dir/$m/heartbeat", 0777, true);
        mkdir("$dir/$m/halts", 0777, true);
    }
    return $dir;
}

function t89_published($observer, $dir, $extra = array())
{
    $plan = call_user_func("pr2obs\\$observer\\run_cycle", array_merge(array(
        'stores'          => $dir,
        'identity'        => $observer,
        'params'          => array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ),
        'basis'           => null,
        'memory'          => array(),
        'booted'          => true,
        'cadence_seconds' => 6,
    ), $extra));
    return $plan['publish']['checks'];
}

// Raised by the machinery in every observer, so published by every observer.
$dir = t89_tree();
foreach (array('web', 'multi', 'policy', 'super') as $o) {
    $checks = t89_published($o, $dir);
    foreach (array('cycle-within-cadence', 'staging-fresh') as $c) {
        ok(in_array($c, $checks, true), "$o publishes $c, which it raises");
    }
    // And the account still agrees with its own count, which is the one thing
    // a reader does verify.
    ok(count($checks) === count(array_unique($checks)), "$o publishes no check twice");
}
t89_rm($dir);

// The observer that reads traces publishes the trace checks, named by the
// schedule they are about, and the ones that do not read traces do not.
$dir = t89_tree();
$traces_root = sys_get_temp_dir() . '/pr2checks-traces-' . getmypid();
$schedules = \pr2obs\web\schedules_config();
$web = t89_published('web', $dir, array(
    'traces_root' => $traces_root,
    'traces'      => $schedules,
));
foreach ($schedules as $s) {
    foreach (array('trace-fresh', 'trace-complete', 'trace-coverage') as $c) {
        ok(
            in_array($c . ':' . $s['schedule'], $web, true),
            "web publishes $c for the {$s['schedule']} schedule"
        );
    }
}

$super = t89_published('super', $dir);
$trace_claims = array();
foreach ($super as $c) {
    if (strpos($c, 'trace-') === 0) {
        $trace_claims[] = $c;
    }
}
is_same($trace_claims, array(), 'and an observer with no traces to read claims none');

// The post-condition check reads a database this deployment does not give it,
// so no observer may claim it.
foreach (array('web', 'multi', 'policy', 'super') as $o) {
    $checks = t89_published($o, $dir);
    ok(!in_array('postcondition', $checks, true), "$o claims no post-condition it cannot ask");
}
t89_rm($dir);
t89_rm($traces_root);

t_done();
