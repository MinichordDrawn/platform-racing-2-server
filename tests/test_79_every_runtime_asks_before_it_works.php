<?php
// Every runtime asks before it works.
//
// The gate decides (test 78). This is about where it is attached, which is the
// half that can be quietly wrong: a decision nothing calls is the original
// problem one level up -- checks reporting to a place nothing reads.
//
// There are four runtimes and two shapes between them.
//
//   Short-lived, one process per unit of work: an HTTP request and a cron run.
//   Both already pass through config.php, because `auto_prepend_file` puts it
//   in front of every PHP process in these containers. They refuse and exit,
//   and exiting costs nothing because the process was going to end anyway.
//
//   Long-lived, one process for the life of the container: the game server and
//   the policy daemon. These must **not** exit, and the reason is recovery.
//   Each shares its container with the observer that watches it, so a work
//   process that exits takes its observer down with it; the ring is then a
//   member short, every other member reports it missing, and the halt can
//   never lift. Stopping the work would have made the stop permanent. So they
//   stop working and stay running, and start again on their own when the ring
//   is clear.
//
// And the barrier, which is the other half of the same rule: the work does not
// begin until its observer has written a heartbeat the gate accepts. Without
// it the rule halts every deployment on the way up, because at the first
// moment nothing has written anything.

require_once __DIR__ . '/helper.php';

echo "every runtime asks before it works\n";

// --- the gate loads nothing -----------------------------------------------
//
// It runs in front of the application, so it cannot be part of it. A
// deployment whose database is unreachable must still be able to find out that
// it has been told to stop.

// Comments removed, everywhere this file asks what a piece of code does. A
// check that reads prose is a check a comment can satisfy, and every file here
// is heavily commented -- including about the very things being looked for.
function t79_code($src)
{
    $out = '';
    foreach (token_get_all($src) as $t) {
        if (is_array($t)) {
            if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                $out .= str_repeat("\n", substr_count($t[1], "\n"));
                continue;
            }
            $out .= $t[1];
        } else {
            $out .= $t;
        }
    }
    return $out;
}

$gate = t79_code(file_get_contents(REPO . '/common/observer_gate.php'));

ok(
    preg_match('/^\s*(require|include)(_once)?\b/m', $gate) !== 1,
    'the gate requires nothing at all'
);
foreach (array('env.php', 'pdo_connect', 'PDO', 'S3', 'ROOT_DIR', 'COMMON_DIR', 'CACHE_DIR') as $needle) {
    ok(strpos($gate, $needle) === false, "and does not reach for $needle");
}

// It is also not an observer in disguise: it reads the same bytes from the
// same specification, and shares no line of code with any of the four.
foreach (glob(REPO . '/observers/*/[a-z]*.php') as $file) {
    $src = t79_code(file_get_contents($file));
    $rel = substr($file, strlen(REPO) + 1);
    ok(
        strpos($src, 'observer_gate') === false,
        str_replace('\\', '/', $rel) . ' does not use the gate'
    );
}
ok(strpos($gate, 'pr2obs') === false, 'and the gate does not use any observer');

// --- the short-lived runtimes: config.php ---------------------------------

$config = t79_code(file_get_contents(REPO . '/config.php'));

ok(strpos($config, 'observer_gate.php') !== false, 'config.php loads the gate');
ok(strpos($config, 'observer_gate_enforce()') !== false, 'and calls it');

// Before anything else it does. The gate needs no secret, no database and no
// configuration of the application's, so there is nothing it has to wait for
// -- and a process told to stop should stop before it opens a connection to
// anything.
$gate_at = strpos($config, 'observer_gate_enforce()');
foreach (array(
    "require_once COMMON_DIR . '/env.php'"      => 'the environment',
    'env_require_secrets_changed()'             => 'the boot refusal on unchanged secrets',
    "require_once COMMON_DIR . '/pdo_connect.php'" => 'the database',
    "require_once COMMON_DIR . '/s3_connect.php'"  => 'the object store',
) as $needle => $what) {
    $at = strpos($config, $needle);
    ok($at !== false && $gate_at < $at, "and calls it before $what");
}

// The prepend is what carries it to every request and every cron run, and it
// must still be installed after every build step that runs PHP -- the same
// constraint the boot refusal is under, for the same reason. Test 65 proves
// that ordering; this only checks the prepend is still what points at
// config.php.
$ini = file_get_contents(REPO . '/docker/prepend_file.ini');
ok(strpos($ini, 'auto_prepend_file = /pr2/config.php') !== false,
    'and the prepend still puts config.php in front of every PHP process');

// --- the long-lived runtimes ----------------------------------------------
//
// Named as pairs so that a hook added without the refusal it is supposed to
// carry out, or a refusal with nothing left to set it, fails here.

$loops = array(
    'the game server' => array(
        'timer'   => array(REPO . '/multiplayer_server/PR2SocketServer.php', 'onTimer'),
        'refuse'  => array(
            REPO . '/multiplayer_server/PR2SocketServer.php'  => 'onAccept',
            REPO . '/multiplayer_server/PR2Client.php'        => 'onRead',
        ),
    ),
    'the policy daemon' => array(
        'timer'   => array(REPO . '/policy_server/server.php', 'onTimer'),
        'refuse'  => array(
            REPO . '/policy_server/server_client.php' => 'onRead',
        ),
    ),
);

// The body of a method, from its declaration to the closing brace at its own
// indentation. Crude, and sufficient: these are ordinary four-space methods.
function t79_method($src, $name)
{
    if (!preg_match('/^(\s*)(?:public |protected |private |static )*function\s+' . preg_quote($name, '/') . '\s*\(/m', $src, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $indent = $m[1][0];
    $from = $m[0][1];
    $end = strpos($src, "\n" . $indent . "}", $from);
    return $end === false ? substr($src, $from) : substr($src, $from, $end - $from);
}

foreach ($loops as $what => $spec) {
    list($file, $method) = $spec['timer'];
    $src = t79_code(file_get_contents($file));
    $body = t79_method($src, $method);

    ok($body !== null, "$what has a $method");
    if ($body === null) {
        continue;
    }

    ok(strpos($body, 'observer_gate_reason(') !== false,
        "$what asks the gate on its own timer");

    // The whole point of the long-lived shape. observer_gate_enforce() exits,
    // and exiting here ends the container and the observer inside it.
    ok(strpos($body, 'observer_gate_enforce') === false,
        "$what does not use the refusal that exits");
    ok(preg_match('/\b(exit|die)\s*[\(;]/', $body) !== 1,
        "$what keeps running when it stops working, so its observer survives");

    foreach ($spec['refuse'] as $rfile => $rmethod) {
        $rbody = t79_method(t79_code(file_get_contents($rfile)), $rmethod);
        $rel = str_replace('\\', '/', substr($rfile, strlen(REPO) + 1));
        ok($rbody !== null, "$rel has a $rmethod");
        if ($rbody === null) {
            continue;
        }
        ok(
            preg_match('/halted/i', $rbody) === 1,
            "$rel::$rmethod refuses while the ring says stop"
        );
    }
}

// The control channel has no hook of its own, and should not have one: it is
// another listener and another kind of client on the same daemon, and both of
// its classes inherit the refusals above. An override would quietly take it
// back out of the gate's reach without touching anything checked so far, so
// the absence of one is what is pinned here.
$control = array(
    'PR2ControlClient' => array('PR2Client', array('onRead')),
    'PR2ControlServer' => array('PR2SocketServer', array('onAccept')),
);
foreach ($control as $class => $spec) {
    list($parent, $methods) = $spec;
    $src = t79_code(file_get_contents(REPO . "/multiplayer_server/$class.php"));
    ok(
        preg_match('/class\s+' . $class . '\s+extends\s+' . $parent . '\b/', $src) === 1,
        "$class is a $parent, so the gate reaches the control channel"
    );
    foreach ($methods as $m) {
        ok(t79_method($src, $m) === null, "and does not override $m out of its reach");
    }
}

// --- the barrier ----------------------------------------------------------
//
// Three containers run work beside an observer. In each, the observer starts
// first, the script then waits for the gate to open, and only then does the
// work begin. The order is the whole of it: an observer started after the work
// would have the work serving unobserved for as long as it took to come up.

$barriers = array(
    'docker/http_server_startup.sh'   => 'apache2-foreground',
    'docker/multi_server_startup.sh'  => 'multiplayer_server/pr2.php',
    'docker/policy_server_startup.sh' => 'policy_server/run_policy.php',
);

foreach ($barriers as $rel => $work) {
    $sh = file_get_contents(REPO . '/' . $rel);

    $observer_at = strpos($sh, '/run.php');
    $barrier_at  = strpos($sh, 'observer_gate_wait.php');
    $work_at     = strpos($sh, $work);

    ok($observer_at !== false, "$rel starts an observer");
    ok($barrier_at !== false, "$rel waits for the gate");
    ok($work_at !== false, "$rel starts its work");

    if ($observer_at !== false && $barrier_at !== false && $work_at !== false) {
        ok($observer_at < $barrier_at, "$rel starts the observer before it waits");
        ok($barrier_at < $work_at, "$rel waits before the work begins");
    }

    // The same reason the observer itself is launched this way: the prepend
    // would put config.php in front of the barrier, and config.php calls the
    // refusal that exits -- so the barrier would refuse to wait, which is the
    // one thing it exists to do.
    ok(
        preg_match('/-d auto_prepend_file= \S*observer_gate_wait\.php/', $sh) === 1,
        "$rel runs the barrier with the prepend disabled"
    );
}

// Anything the work does before the gate opens is work done unobserved. The
// web container warms the site's generated files up at startup, and those runs
// belong after the barrier like everything else.
$sh = file_get_contents(REPO . '/docker/http_server_startup.sh');
$barrier_at = strpos($sh, 'observer_gate_wait.php');
foreach (array('minute.php', 'hourly.php') as $job) {
    $at = strpos($sh, $job);
    ok($at === false || $at > $barrier_at, "the $job warm-up run waits for the gate too");
}

// --- the barrier waits, rather than giving up -----------------------------
//
// A deadline here would be a hole, and a specific one. The observer rides in
// the same container, so a barrier that gave up and exited would stop the
// container, stop the observer, and leave the ring a member short -- turning a
// halt that would have lifted in a second into one that no longer can. Waiting
// keeps the observer alive and heartbeating, which is exactly what lets the
// other members stand down and the work start.

$wait = t79_code(file_get_contents(REPO . '/common/observer_gate_wait.php'));

// It waits for the observer on this host to be alive, and for nothing else.
//
// Waiting for the whole gate deadlocked the deployment, twice, in the first
// twenty seconds it ran. Each observer asserts that the work in its container
// is running, so a barrier that waits for a clear ring waits for a condition
// that cannot become true while it is waiting: the ring is not clear because
// the work is not running, and the work is not running because the barrier is
// waiting. All four containers sat in it raising a halt every six seconds.
//
// So the barrier is what its name says and what the design asked for -- the
// *first-write* barrier -- and nothing more. Its job is to make the observer
// running a precondition of the work starting. Whether the work may then do
// anything is a different question, asked continuously afterwards by code that
// does not have to exit to answer it.
ok(strpos($wait, 'observer_gate_alive(') !== false,
    'the barrier waits for the observer on this host');
ok(strpos($wait, 'observer_gate_reason(') === false,
    'and not for the whole gate, which cannot clear while it waits');
ok(preg_match('/while\s*\(\s*true\s*\)/', $wait) === 1, 'and waits for as long as it takes');
ok(preg_match('/^\s*(require|include)(_once)?\s+.*observer_gate\.php/m', $wait) === 1,
    'loading nothing but the gate');

// Nothing inside the loop ends the process. The barrier does exit before it,
// on an environment it cannot read -- which is right, because no amount of
// waiting clears a misconfiguration -- and that is the only exit it has.
$at = strpos($wait, 'while');
ok(
    $at !== false && preg_match('/\b(exit|die)\b/', substr($wait, $at)) !== 1,
    'and has no way to give up on a ring that is merely still starting'
);

// --- the two that are allowed to start while the ring says stop -----------
//
// config.php refuses by exiting, and for the game server and the policy daemon
// that would end the container and the observer inside it. So they are allowed
// past the boot check, and the rule that lets them past is the reason they do
// not need it: each carries a continuous check of its own, which stops the
// work every tick without stopping the process.
//
// Nothing is prevented from starting. Everything is prevented from working. A
// halted game server still listens and refuses every connection, which is also
// what keeps `process-alive` and `port-answers` true and the halt clearable.
//
// The list is in code and is not configurable, and it is the entrypoint that
// is named rather than a flag, because a flag is a thing an attacker sets.
require_once REPO . '/common/observer_gate.php';

$self_checking = observer_gate_self_checking();
is_same($self_checking,
    array('/pr2/multiplayer_server/pr2.php', '/pr2/policy_server/run_policy.php'),
    'exactly the two long-lived entrypoints are allowed past the boot check');

ok(strpos($gate, 'observer_gate_self_checking()') !== false,
    'and the refusal consults that list');

// Each one is the entrypoint of a startup script, and each has a timer check
// above. Either half without the other would be a runtime that starts halted
// and never notices, or a check nothing reaches.
// Named by path rather than by base name: the exemption is for these two
// programs, not for anything that shares their file names. Test 87 covers the
// refusals; this covers the pairing with the scripts that start them.
$entrypoints = array(
    '/pr2/multiplayer_server/pr2.php'  => 'docker/multi_server_startup.sh',
    '/pr2/policy_server/run_policy.php' => 'docker/policy_server_startup.sh',
);
foreach ($self_checking as $name) {
    ok(isset($entrypoints[$name]), "$name is a known entrypoint");
    if (!isset($entrypoints[$name])) {
        continue;
    }
    $sh = file_get_contents(REPO . '/' . $entrypoints[$name]);
    ok(strpos($sh, $name) !== false, "$name is what {$entrypoints[$name]} execs");
}

// And the exception is narrow: a cron job, a warm-up run, an HTTP request and
// anything else unnamed is still refused by exiting.
ok(
    preg_match('/PHP_SAPI\s*===?\s*[\x27"]cli[\x27"]/', $gate) === 1,
    'the gate still tells a command-line caller from a request'
);

// --- every gated container is configured for it ---------------------------
//
// The gate refuses when its environment is incomplete, which is the right
// direction and a poor way to find out. These are the four containers that run
// gated PHP: three that hold an observer, and the scheduler, whose work is
// watched by the web observer in a different container on the same host.

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

function t79_service($compose, $name)
{
    if (!preg_match('/^  ' . preg_quote($name, '/') . ':\s*$/m', $compose, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $from = $m[0][1] + strlen($m[0][0]);
    $rest = substr($compose, $from);
    $end = preg_match('/^  [a-z0-9][a-z0-9_-]*:\s*$/m', $rest, $n, PREG_OFFSET_CAPTURE) ? $n[0][1] : strlen($rest);
    return substr($rest, 0, $end);
}

$gated = array('web' => 'web', 'cron' => 'web', 'multi' => 'multi', 'policy' => 'policy');

foreach ($gated as $service => $local) {
    $body = t79_service($compose, $service);
    ok($body !== null, "the compose file has a $service service");
    if ($body === null) {
        continue;
    }
    ok(strpos($body, "- OBSERVER_LOCAL=$local") !== false,
        "$service is told its observer is $local");
    foreach (array('OBSERVER_LOCAL_MAX_AGE_SECONDS', 'OBSERVER_SUPER_MAX_AGE_SECONDS') as $name) {
        ok(preg_match('/- ' . $name . '=\d+/', $body) === 1, "$service declares $name");
    }
    // It cannot ask the store anything without being able to read it.
    ok(substr_count($body, ':/stores') === 17, "$service can see the whole store tree");
}

// The two windows are what they say they are: the local one is tight because
// the observer and the work share a clock, and the super one is loose because
// they do not. A deployment that set them the other way round would pass every
// other check in this file.
foreach ($gated as $service => $local) {
    $body = t79_service($compose, $service);
    if ($body === null) {
        continue;
    }
    preg_match('/- OBSERVER_LOCAL_MAX_AGE_SECONDS=(\d+)/', $body, $l);
    preg_match('/- OBSERVER_SUPER_MAX_AGE_SECONDS=(\d+)/', $body, $s);
    preg_match('/- OBSERVER_CADENCE_SECONDS=(\d+)/', t79_service($compose, $local === 'web' ? 'web' : $local), $c);
    ok(isset($l[1], $s[1], $c[1]), "$service's windows and its observer's cadence are all set");
    if (!isset($l[1], $s[1], $c[1])) {
        continue;
    }
    ok((int) $l[1] > (int) $c[1],
        "$service allows its observer longer than the cadence it declares");
    ok((int) $s[1] >= (int) $l[1],
        "$service allows the super observer at least as long, for the skew");
}

// A container whose work exits on a refusal has to come back, or a halt that
// lifts leaves it down. The two long-lived PHP entrypoints are the ones this
// applies to; Apache never exits, because a refused request is refused inside
// a process that was ending anyway.
foreach (array('multi', 'policy') as $service) {
    $body = t79_service($compose, $service);
    ok($body !== null && preg_match('/^\s*restart:\s*always\s*$/m', $body) === 1,
        "$service comes back if its work refuses at boot");
}

t_done();
