<?php
// No container keeps power it does not need.
//
// Two guarantees rest on this and neither is visible in the compose file
// today, because the file says nothing at all: no user, no dropped
// capabilities, no security options.
//
// The first is that a read-only mount means something. A read-only bind is a
// kernel refusal only while the container cannot remount it, and remounting
// needs CAP_SYS_ADMIN. A privileged container, or one that keeps the default
// capability set, can simply remount the mount read-write. Every ownership
// rule that says one writer per path then drops back to being a convention
// that the writer chooses to honour.
//
// The second is that root inside a container is root with the default
// capability set, which is a great deal of power for software whose job is
// to serve level files. Dropping to a named user and dropping every
// capability costs nothing and is not undoable from inside.
//
// The rule is enumerable rather than a list. A service this repository builds
// is first-party and must be held to all of it; a service that names a
// third-party image is not ours to configure the same way. A new first-party
// service added tomorrow is caught with nobody editing this file.

require_once __DIR__ . '/helper.php';

echo "no container keeps power it does not need\n";

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

// --- split the file into service blocks -----------------------------------

function compose_services($compose)
{
    $services = array();
    $lines = explode("\n", $compose);
    $in_services = false;
    $current = null;

    foreach ($lines as $line) {
        $line = rtrim($line, "\r");

        if (preg_match('/^services:\s*$/', $line)) {
            $in_services = true;
            continue;
        }
        if (!$in_services) {
            continue;
        }
        // A top-level key ends the services section.
        if (preg_match('/^[a-z]/i', $line)) {
            break;
        }
        if (preg_match('/^  ([a-z0-9][a-z0-9_-]*):\s*$/i', $line, $m)) {
            $current = $m[1];
            $services[$current] = '';
            continue;
        }
        if ($current !== null) {
            $services[$current] .= $line . "\n";
        }
    }

    return $services;
}

$services = compose_services($compose);

ok(count($services) >= 6, 'the compose file parses into services');

// A service this repository builds from its own Dockerfile is ours.
$first_party = array();
$third_party = array();
foreach ($services as $name => $body) {
    if (preg_match('/^\s+build:/m', $body)) {
        $first_party[$name] = $body;
    } else {
        $third_party[$name] = $body;
    }
}

ok(count($first_party) >= 4, 'the services this repository builds are found');

// --- nothing is privileged ------------------------------------------------

$privileged = array();
foreach ($services as $name => $body) {
    if (preg_match('/^\s+privileged:\s*true/m', $body)) {
        $privileged[] = $name;
    }
}
is_same($privileged, array(), 'no service is privileged');

// --- every service refuses privilege escalation ---------------------------

$missing_nnp = array();
foreach ($services as $name => $body) {
    if (!preg_match('/no-new-privileges:\s*(true|"true")/', $body)) {
        $missing_nnp[] = $name;
    }
}
is_same($missing_nnp, array(), 'every service refuses new privileges');

// --- everything we build drops every capability ---------------------------

$missing_drop = array();
foreach ($first_party as $name => $body) {
    if (!preg_match('/cap_drop:\s*\n\s*-\s*ALL\b/', $body)) {
        $missing_drop[] = $name;
    }
}
is_same($missing_drop, array(), 'every service we build drops all capabilities');

// A capability added back has to say what it is for, on the line above it, so
// that the set cannot quietly grow.
$unexplained = array();
foreach ($first_party as $name => $body) {
    if (!preg_match('/cap_add:\s*\n((?:\s*(?:#[^\n]*|-\s*[A-Z_]+)\n)+)/', $body, $m)) {
        continue;
    }
    foreach (explode("\n", trim($m[1])) as $i => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        // Each added capability wants a comment somewhere in the block.
        if (!preg_match('/#/', $m[1])) {
            $unexplained[] = "$name: $line";
        }
    }
}
is_same($unexplained, array(), 'a capability added back says what it is for');

// --- everything we build runs as a named non-root user --------------------

// One service is allowed a root PID 1, and only with its reason written
// beside it: the scheduler has to start as root in order to drop to the
// unprivileged user that actually runs the jobs.
$root_pid1 = array();
$no_user = array();

foreach ($first_party as $name => $body) {
    $has_user = preg_match('/^\s+user:\s*["\']?([^"\'\s]+)/m', $body, $m);

    if (!$has_user) {
        $no_user[] = $name;
        continue;
    }

    $user = $m[1];
    if ($user === 'root' || $user === '0' || strpos($user, '0:') === 0) {
        $root_pid1[] = $name;
        // Allowed only where the reason is recorded in the file.
        ok(
            preg_match('/#[^\n]*(drop|unprivileged|scheduler)[^\n]*/i', $body) === 1,
            "$name runs as root only with its reason written down"
        );
    }
}

is_same($no_user, array(), 'every service we build names the user it runs as');

// --- declaring root is not the same as keeping it -------------------------
//
// Three services that hold both an observer and the work it watches now
// declare root, and the reason is the opposite of what the declaration looks
// like: a process can only stop being one user and become another if it
// starts with the privilege to do so. They use it once, at the top of the
// entrypoint, to put the observer on its own user and the work on another --
// which is what stops the work writing the files its own observer publishes,
// or signalling it.
//
// So the property is not what compose declares. It is what survives the
// start, and that is decided by the last line of the entrypoint: `exec` as a
// non-root user replaces the shell, and no root process is left.
//
// `cron` is the one that genuinely keeps root, because cron must remain root
// to go on spawning jobs as somebody else. It holds no observer and no
// listener.

$entrypoints = array(
    'web'    => 'docker/http_server_startup.sh',
    'multi'  => 'docker/multi_server_startup.sh',
    'policy' => 'docker/policy_server_startup.sh',
    'cron'   => 'docker/cron_startup.sh',
);

$keeps_root = array();
foreach ($root_pid1 as $name) {
    ok(isset($entrypoints[$name]), "$name's entrypoint is known to this test");
    if (!isset($entrypoints[$name])) {
        continue;
    }

    $sh = file_get_contents(REPO . '/' . $entrypoints[$name]);
    $last = '';
    foreach (preg_split('/?
/', $sh) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && strpos($line, '#!') !== 0) {
            $last = $line;
        }
    }

    if (preg_match('/^exec setpriv --reuid=(?!root)[A-Za-z0-9_-]+/', $last) !== 1) {
        $keeps_root[] = $name;
    }
}

is_same($keeps_root, array('cron'),
    'the only service that keeps a root process is the one that has to spawn jobs as others');

// --- and nothing that listens keeps a root process ------------------------
//
// The assertion that matters, and the one the change above had to preserve.
// A service with a published port is reachable, and a reachable service must
// have no root process left in it.
$root_and_listening = array();
foreach ($first_party as $name => $body) {
    $listens = preg_match('/^\s+ports:/m', $body) === 1;
    if ($listens && in_array($name, $keeps_root, true)) {
        $root_and_listening[] = $name;
    }
}
is_same($root_and_listening, array(), 'no service that listens keeps a root process');

t_done();
