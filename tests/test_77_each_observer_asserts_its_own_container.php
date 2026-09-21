<?php
// Each observer asserts the shape of its own container.
//
// Trace freshness says work happened. It says nothing about whether the thing
// doing the work is still the thing that was deployed. The images declare an
// interpreter, a set of extensions, a document root, a process, a port -- and
// everything declared is assertable.
//
// Two kinds of assertion, answering different questions. Declared values must
// equal a constant in the observer, which catches an image that is not what it
// should be. Baselined values are captured when the observer starts and must
// not change while it runs, which catches a running container being modified
// -- and that has no benign cause at all.
//
// The baseline is taken from the container it is watching, so a tampered image
// baselines itself and this check stays quiet about it. That limit is real,
// recorded in the design, and not something a process inside the deployment
// can close.

require_once __DIR__ . '/helper.php';

echo "each observer asserts its own container\n";

$root = REPO . '/observers';
$observers = array();
foreach (scandir($root) as $name) {
    if ($name !== '.' && $name !== '..' && is_dir("$root/$name")) {
        $observers[] = $name;
    }
}
sort($observers, SORT_STRING);

// --- the baseline is always supplied -------------------------------------
//
// The cycle runs the container checks only when a baseline is given, because
// the fixture set deliberately does not cover local checks -- a fixture is a
// store on disk with no container around it. That makes forgetting to supply
// one a silent loss of every check in this file, so it is asserted where the
// process is started.

foreach ($observers as $observer) {
    $run = file_get_contents("$root/$observer/run.php");
    ok(
        strpos($run, 'container_baseline()') !== false,
        "$observer computes a container baseline when it starts"
    );
    ok(
        preg_match("/'container_baseline'\s*=>/", $run) === 1,
        "$observer passes it into every cycle"
    );

    $cycle = file_get_contents("$root/$observer/cycle.php");
    ok(
        preg_match('/check_container\s*\(/', $cycle) === 1,
        "$observer's cycle runs the container checks"
    );
}

// --- what each container declares ----------------------------------------
//
// These are read from the observers rather than restated, because the point is
// that each one carries its own column. What is asserted is that the columns
// say what the images say.

// `pcntl` is in every one of them, and in the super observer's image too,
// which is otherwise the one image that installs no extensions at all. The
// observer's termination handler cannot be installed without it, and where it
// was missing the handler was never installed, a signal killed the process
// outright, and the mechanism was dead code that said nothing about being
// dead. Declaring it is what makes that self-reporting: `extensions-declared`
// fails when a declared extension is not loaded. The super observer is covered
// by test 85 rather than here, because this table assumes a work process and
// that container has none.
$expected = array(
    'web'    => array('php' => '8.2', 'ext' => array('pdo_mysql', 'apcu', 'pcntl'),   'proc' => 'apache2'),
    'multi'  => array('php' => '8.2', 'ext' => array('pdo_mysql', 'sockets', 'pcntl'), 'proc' => 'pr2.php'),
    'policy' => array('php' => '8.2', 'ext' => array('pdo_mysql', 'sockets', 'pcntl'), 'proc' => 'run_policy.php'),
);

foreach ($expected as $observer => $want) {
    $path = "$root/$observer/container.php";
    ok(is_file($path), "$observer has a column");
    if (!is_file($path)) {
        continue;
    }
    $src = file_get_contents($path);

    preg_match("/const CONTAINER_PHP_VERSION = '([^']+)'/", $src, $m);
    is_same($m[1] ?? null, $want['php'], "$observer declares the interpreter its image installs");

    preg_match('/const CONTAINER_EXTENSIONS = array\(([^)]*)\)/', $src, $m);
    $ext = array();
    if (isset($m[1])) {
        preg_match_all("/'([^']+)'/", $m[1], $mm);
        $ext = $mm[1];
    }
    sort($ext);
    $w = $want['ext'];
    sort($w);
    is_same($ext, $w, "$observer declares the extensions its image installs");

    preg_match("/const CONTAINER_PROCESS = '([^']+)'/", $src, $m);
    is_same($m[1] ?? null, $want['proc'], "$observer watches for its own process");

    // The dockerfile is the other half of each of those claims.
    $dockerfile = REPO . '/docker/' . ($observer === 'web' ? 'http_server' : $observer . '_server') . '.dockerfile';
    $df = file_get_contents($dockerfile);
    ok(
        strpos($df, 'php:' . $want['php']) !== false,
        "and the $observer image really is built on PHP " . $want['php']
    );
    foreach ($want['ext'] as $e) {
        ok(
            strpos($df, $e) !== false,
            "and really installs $e"
        );
    }
}

// --- the checks detect what they are for ---------------------------------
//
// The code paths are constants, so this cannot point the walk at a fixture
// tree. What it can do is give the comparison a baseline that disagrees with
// what is on disk, which is exactly the shape of a running container that has
// been modified.

require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

function t77_reader()
{
    $R = new \pr2obs\web\Reader(
        sys_get_temp_dir() . '/pr2obs-t77',
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

function t77_checks($R, $only)
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

$extensions = get_loaded_extensions();
sort($extensions, SORT_STRING);

// A baseline that matches what is on disk: nothing to report about the code.
$R = t77_reader();
\pr2obs\web\check_container($R, array(
    'code'       => \pr2obs\web\code_manifest(),
    'extensions' => $extensions,
));
is_same(t77_checks($R, array('code-unchanged', 'extensions-unchanged')), array(),
    'a container that has not changed reports neither');

// A file the baseline knows about and disk does not: removed while running.
$R = t77_reader();
\pr2obs\web\check_container($R, array(
    'code'       => array('/pr2/common/env_check.php' => str_repeat('a', 64)),
    'extensions' => $extensions,
));
is_same(t77_checks($R, array('code-unchanged')), array('code-unchanged'),
    'code that differs from the baseline is reported');

// An extension in the baseline that is no longer loaded, or the reverse.
$R = t77_reader();
\pr2obs\web\check_container($R, array(
    'code'       => \pr2obs\web\code_manifest(),
    'extensions' => array_merge($extensions, array('zzz_not_a_real_extension')),
));
is_same(t77_checks($R, array('extensions-unchanged')), array('extensions-unchanged'),
    'an extension set that changed while running is reported');

// --- and all of it belongs in the fault file -----------------------------
//
// These are assertions this observer makes about its own world, which is
// exactly what the fault file is for. A problem with a different observer
// halts without one; a problem with this container is this container's fault.

foreach (\pr2obs\web\container_local_checks() as $id) {
    ok(
        \pr2obs\web\is_local_finding(array('check' => $id, 'subject' => null)),
        "$id counts as a local finding, so it reaches the fault file"
    );
}

t_done();
