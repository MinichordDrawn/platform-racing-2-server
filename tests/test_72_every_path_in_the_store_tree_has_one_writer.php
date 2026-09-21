<?php
// Every path in the store tree has exactly one writer, and the kernel is what
// says so.
//
// This is the guarantee the whole observer network rests on. An observer must
// not be able to forge another's state, and "an observer writes only its own
// folder" enforced by a helper the observer itself calls is no obstacle at all
// to an observer whose code has been rewritten. With one mount per path and a
// read-only flag everywhere else, the write is refused by the kernel and the
// rule is true rather than merely checked.
//
// The matrix below is the specification's, from SPEC 2. It is written out here
// rather than derived from the compose file, because a test that read its
// expectations out of the thing under test would pass whatever that thing
// said.
//
// This file checks that the deployment *asks* for the matrix. Whether it gets
// it is a different question and a different check: docker/verify-stores.sh
// attempts a write at every path in every container and compares what the
// kernel actually did. Both are needed. This one runs anywhere; that one needs
// the containers up.

require_once __DIR__ . '/helper.php';

echo "every path in the store tree has one writer\n";

$members = array('web', 'multi', 'policy', 'super');

// SPEC 2: the copy cycle is fixed. policy writes into web/copy, web into
// multi/copy, multi into policy/copy.
$copy_author = array('web' => 'policy', 'multi' => 'web', 'policy' => 'multi');

// path => the one identity that may write it
$writers = array();

foreach ($members as $m) {
    $writers["/stores/$m"] = array($m);
}
foreach ($copy_author as $store => $author) {
    $writers["/stores/$store/copy"] = array($author);
}
foreach ($members as $m) {
    // halts/ is the one directory with more than one writer, and even there
    // every *file* has one: a writer names its file after itself.
    $writers["/stores/$m/halts"] = array_values(array_diff($members, array($m)));
}
foreach (array('web', 'multi', 'policy') as $a) {
    $writers["/stores/super/copy-$a"] = array($a);
}
// The super observer's own copies, one in each peer. It is the member whose
// unreachability halts everything and the only one that decides the all-clear,
// so leaving its store the one nobody corroborated was the wrong asymmetry.
foreach (array('web', 'multi', 'policy') as $peer) {
    $writers["/stores/$peer/copy-super"] = array('super');
}

is_same(count($writers), 17, 'the tree has seventeen mounted paths');

// --- what the compose file declares ---------------------------------------

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

function service_blocks($compose)
{
    $services = array();
    $lines = explode("\n", $compose);
    $in = false;
    $current = null;
    foreach ($lines as $line) {
        $line = rtrim($line, "\r");
        if (preg_match('/^services:\s*$/', $line)) {
            $in = true;
            continue;
        }
        if (!$in) {
            continue;
        }
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

$services = service_blocks($compose);

// Mounts of a service, as [container path => 'rw'|'ro'].
function store_mounts($body)
{
    $out = array();
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (strpos($line, '- ') !== 0 || strpos($line, ':/stores') === false) {
            continue;
        }
        $spec = trim(substr($line, 2));
        $parts = explode(':', $spec);
        // <volume>:<path>[:<mode>]
        if (count($parts) < 2) {
            continue;
        }
        $path = $parts[1];
        $mode = isset($parts[2]) ? $parts[2] : 'rw';
        $out[$path] = $mode;
    }
    return $out;
}

// The observers run inside these containers, so these are the containers that
// must see the tree. All four now: the super observer's container holds the
// observer and nothing else, and it writes only its own store and its slot in
// each of the other three halts directories.
$observer_services = array('web', 'multi', 'policy', 'super');

$problems = array();

foreach ($observer_services as $svc) {
    if (!isset($services[$svc])) {
        $problems[] = "service $svc is missing";
        continue;
    }
    $mounts = store_mounts($services[$svc]);

    foreach ($writers as $path => $allowed) {
        $want = in_array($svc, $allowed, true) ? 'rw' : 'ro';
        $have = $mounts[$path] ?? '(not mounted)';
        if ($have !== $want) {
            $problems[] = "$svc: $path should be $want, is $have";
        }
    }

    // Nothing may be mounted under /stores that is not in the matrix. An extra
    // mount is an extra writer, which is the one thing the tree forbids.
    foreach ($mounts as $path => $mode) {
        if (!isset($writers[$path])) {
            $problems[] = "$svc: $path is mounted and is not in the matrix";
        }
    }
}

// The scheduler and the PR2Hub proxy hold no observer and write nothing into
// the tree, but both read it before they work (step 10). A reader is still a
// container with the tree mounted in it, so it is held to the same matrix from
// the other side: every path, read-only, no exceptions. A single writable path
// here would be a second writer of somebody's store, reached from a container
// with no observer in it.
//
// The proxy is a reader because Apache maps /pr2hub/ to it with ProxyPass, so
// mod_proxy answers that route and PHP never runs -- which left it the one way
// into this deployment that the gate in config.php did not cover. It reads the
// tree itself now, from pr2hub_proxy/gate.go.
$reader_services = array('cron', 'pr2hub-proxy');

foreach ($reader_services as $svc) {
    if (!isset($services[$svc])) {
        $problems[] = "service $svc is missing";
        continue;
    }
    $mounts = store_mounts($services[$svc]);

    foreach ($writers as $path => $allowed) {
        $have = $mounts[$path] ?? '(not mounted)';
        if ($have !== 'ro') {
            $problems[] = "$svc: $path should be ro, is $have";
        }
    }
    foreach ($mounts as $path => $mode) {
        if (!isset($writers[$path])) {
            $problems[] = "$svc: $path is mounted and is not in the matrix";
        }
    }
}

// And nothing mounts the tree without appearing in one of those two lists.
//
// Both lists above are declared, which is right -- a listing that comes back
// short is indistinguishable from a deployment with nothing wrong in it. But a
// declared list checks only what it names, so a new container that mounts the
// tree and is in neither list is held to nothing at all. This is the check
// that makes the lists complete rather than merely correct.
$accounted = array_merge($observer_services, $reader_services);
$unaccounted = array();

foreach ($services as $svc => $definition) {
    if (in_array($svc, $accounted, true)) {
        continue;
    }
    if (store_mounts($definition) !== array()) {
        $unaccounted[] = "$svc mounts the store tree and is neither an observer nor a declared reader";
    }
}

is_same($unaccounted, array(), 'every container that mounts the tree is accounted for');

is_same($problems, array(), 'every container declares the matrix exactly');

// --- every volume is declared ---------------------------------------------

$declared = array();
if (preg_match('/^volumes:\s*$(.*)/ms', $compose, $m)) {
    foreach (explode("\n", $m[1]) as $line) {
        if (preg_match('/^  ([a-z0-9][a-z0-9_-]*):\s*$/i', rtrim($line, "\r"), $v)) {
            $declared[] = $v[1];
        }
    }
}

$used = array();
foreach ($observer_services as $svc) {
    foreach (explode("\n", $services[$svc] ?? '') as $line) {
        $line = trim($line);
        if (strpos($line, '- ') === 0 && strpos($line, ':/stores') !== false) {
            $used[] = explode(':', trim(substr($line, 2)))[0];
        }
    }
}
$used = array_values(array_unique($used));

is_same(count($used), 17, 'seventeen named volumes carry the tree');

$undeclared = array_values(array_diff($used, $declared));
is_same($undeclared, array(), 'every volume the matrix uses is declared');

// --- a store volume is never a bind mount ---------------------------------
//
// A bind mount would put the tree on the host filesystem, where the ownership
// rules are the host's and a path could be written from outside every
// container. Named volumes keep the tree inside Docker's own storage.
$binds = array();
foreach ($used as $v) {
    if (strpos($v, '/') !== false || strpos($v, '.') === 0) {
        $binds[] = $v;
    }
}
is_same($binds, array(), 'no part of the tree is a bind mount from the host');

t_done();
