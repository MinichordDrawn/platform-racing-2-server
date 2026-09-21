<?php
// The work cannot write what the observer publishes.
//
// Three containers hold an observer and the work it watches, and they ran as
// one user. That is the whole of S19, and it was not theoretical: probed in a
// running container, a PHP request could create, overwrite or delete any file
// its own observer publishes --
//
//   /stores/web/heartbeat   WRITABLE
//   /stores/web             WRITABLE      (so halt and fault too)
//   /stores/web/halts       refused       (its peers relay there)
//   /stores/multi/heartbeat refused
//
// -- and could read the current heartbeat first to see exactly what shape to
// forge. Every peer test is a property of the files, so bytes written by the
// work in the right shape are bytes no reader can tell from the observer's.
// Signing does not help while the two share a user: a key the observer can
// read is a key the work can read.
//
// The same user also meant the work could signal the observer beside it. That
// is the other half, and the more useful one to an attacker: end your own
// observer, rewrite the store, and let an honest restarted observer rebuild
// its memory from what it finds.
//
// Both close by giving the observer a user of its own. A process may write a
// file only if it owns it or the mode allows, and may signal another only with
// the same real or effective uid -- so `read yes, write no` is exactly what
// a different uid and mode 0755 produce.
//
// **No root process survives this.** The container starts as root solely so
// that it can drop: the observer is launched under the observer's user, and
// the final line `exec`s the work under its own, which replaces the shell.
// What is left is two processes and neither is root. That is the difference
// between this and `cron`, where root persists because cron must keep spawning
// jobs.

require_once __DIR__ . '/helper.php';

echo "the work cannot write what the observer publishes\n";

const T101_UID  = '10002';
const T101_USER = 'pr2obs';

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

function t101_service($compose, $name)
{
    if (!preg_match('/^  ' . preg_quote($name, '/') . ':\s*$/m', $compose, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $from = $m[0][1] + strlen($m[0][0]);
    $rest = substr($compose, $from);
    $end = preg_match('/^  [a-zA-Z0-9][a-zA-Z0-9_-]*:\s*$/m', $rest, $n, PREG_OFFSET_CAPTURE)
        ? $n[0][1] : strlen($rest);
    return substr($rest, 0, $end);
}

// --- the three that hold work start as root, only to drop -----------------

$holds_work = array(
    'web'    => array('http_server', 'http_server_startup'),
    'multi'  => array('multi_server', 'multi_server_startup'),
    'policy' => array('policy_server', 'policy_server_startup'),
);

foreach ($holds_work as $svc => $files) {
    $body = t101_service($compose, $svc);
    ok($body !== null, "the compose file has a $svc service");
    if ($body === null) {
        continue;
    }

    ok(preg_match('/^\s*user:\s*"0:0"\s*$/m', $body) === 1,
        "$svc starts as root, so that it can drop");
    foreach (array('SETUID', 'SETGID') as $cap) {
        ok(preg_match('/^\s*- ' . $cap . '\s*$/m', $body) === 1,
            "and keeps $cap, which is what dropping needs");
    }
    // Nothing more than those two. CHOWN is not needed: a named volume takes
    // its ownership from the image that first mounts it.
    preg_match_all('/^\s*- ([A-Z_]+)\s*$/m', $body, $caps);
    $kept = array_values(array_diff($caps[1], array('ALL')));
    sort($kept, SORT_STRING);
    is_same($kept, array('SETGID', 'SETUID'), "and keeps nothing else, in $svc");
}

// --- the observer has a user of its own, in every image -------------------

foreach (array('http_server', 'multi_server', 'policy_server', 'super_observer') as $image) {
    $df = file_get_contents(REPO . "/docker/$image.dockerfile");

    ok(strpos($df, T101_USER) !== false, "$image.dockerfile creates the observer's user");
    ok(strpos($df, T101_UID) !== false, "and pins its id, so every container agrees on it");
    ok(preg_match('/chown -R ' . T101_USER . ':' . T101_USER . ' \/stores/', $df) === 1,
        "and the store tree belongs to it rather than to the work");
    ok(preg_match('/chown -R www-data:www-data \/stores/', $df) !== 1,
        "and no longer to the work");
}

// --- and each process is started as the right one -------------------------

foreach ($holds_work as $svc => $files) {
    $sh = file_get_contents(REPO . '/docker/' . $files[1] . '.sh');

    ok(preg_match('/setpriv --reuid=' . T101_USER . ' --regid=' . T101_USER . ' --clear-groups [^\n]*observers\/' . $svc . '\/run\.php/', $sh) === 1,
        "$svc starts its observer as " . T101_USER);

    // Every other line that runs something runs it as the work's user.
    $lines = preg_split('/\r?\n/', $sh);
    $unowned = array();
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, 'setpriv') === false) {
            $unowned[] = substr($line, 0, 50);
        }
    }
    is_same($unowned, array(), "$svc starts nothing without saying who it runs as");

    // The last thing it does replaces the shell, so no root is left behind.
    $last = '';
    foreach ($lines as $line) {
        if (trim($line) !== '' && trim($line)[0] !== '#') {
            $last = trim($line);
        }
    }
    ok(strpos($last, 'exec setpriv --reuid=www-data') === 0,
        "and the last thing $svc does is exec the work as www-data, leaving no root process");
}

// --- super needs none of this ---------------------------------------------
//
// It holds an observer and no work, so there are not two parties in it to
// separate. It runs as the observer's user directly and drops nothing.

$body = t101_service($compose, 'super');
ok($body !== null, 'the compose file has a super service');
if ($body !== null) {
    ok(preg_match('/^\s*user:\s*"' . T101_UID . ':' . T101_UID . '"\s*$/m', $body) === 1,
        'super runs as the observer user directly');
    ok(preg_match('/^\s*- SETUID\s*$/m', $body) !== 1,
        'and keeps no capability to change user, because it never changes user');
}

t_done();
