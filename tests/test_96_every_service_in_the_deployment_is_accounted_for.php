<?php
// Every service in the deployment is accounted for.
//
// Test 93 asks whether every *published port* belongs to something the ring
// gates. That closed the instance it was written for and not the class: a
// service does not need a published port to reach the database, and nothing
// asked what a newly added one would be.
//
// The compose file is the deployment -- there is no other definition of it --
// so the question is answerable here and nowhere else. Every service in it is
// one of three things:
//
//   **gated**    it runs this package's code and asks the observer network
//                before it works, whether through auto_prepend_file, its own
//                timer, or the Go reader in the proxy;
//   **the observer** the super observer's container, which holds no work and
//                is the thing doing the watching;
//   **exempt**   a third-party image with none of this package's code in it,
//                so there is nowhere to put a check -- named here, with the
//                reason, because an exemption nobody wrote down is an
//                exemption nobody reviewed.
//
// A service that is in the compose file and in none of those lists fails this
// test. That is the whole point: the lists are declared, and the check is that
// they are *complete*, which is what makes a declared list worth having.
//
// The second half is the restart policy. Five of the eight services declared
// none, including `super` -- whose death is the one event every remaining
// member halts on and none of them can lift, because what would lift it is the
// container that died. Stopping is safe, so this is not ranked as a gap; it is
// fixed because a deployment that states the policy for two services and
// leaves it to the engine's default for the other six has stated one fact in
// two places and decided it in a third.

require_once __DIR__ . '/helper.php';

echo "every service in the deployment is accounted for\n";

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

// The services, as the file declares them. Volumes and networks are top-level
// keys of their own and are not services, so the scan stops at the first one.
$services = array();
$in_services = false;
foreach (preg_split('/\r?\n/', $compose) as $line) {
    if (preg_match('/^([a-z]+):\s*$/', $line, $m)) {
        $in_services = ($m[1] === 'services');
        continue;
    }
    if ($in_services && preg_match('/^  ([a-zA-Z0-9][a-zA-Z0-9_-]*):\s*$/', $line, $m)) {
        $services[] = $m[1];
    }
}
sort($services, SORT_STRING);

ok(count($services) > 0, 'the compose file declares services');

// --- the three lists, declared ---------------------------------------------

$gated = array('web', 'cron', 'multi', 'policy', 'pr2hub-proxy');
$observer = array('super');
$exempt = array(
    'mysql'     => 'a third-party database image with none of this package\'s code in it',
    'liquibase' => 'a third-party migration runner that exits when it is done',
);

$accounted = array_merge($gated, $observer, array_keys($exempt));
sort($accounted, SORT_STRING);

is_same($services, $accounted, 'every service is gated, the observer, or named exempt');

// --- and the gated ones really do read the gate ----------------------------
//
// Named twice over: the list above says which services are gated, and this
// asks the compose file whether each one is configured to be. A service in the
// list that cannot read the store would be a claim with nothing behind it.

function t96_service($compose, $name)
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

foreach ($gated as $svc) {
    $body = t96_service($compose, $svc);
    ok($body !== null, "the compose file has a $svc service");
    if ($body === null) {
        continue;
    }
    ok(strpos($body, '- OBSERVER_STORES=') !== false, "$svc is told where the store is");
    ok(preg_match('/- OBSERVER_SUPER_MAX_AGE_SECONDS=\d+/', $body) === 1,
        "$svc declares how long the super observer may go quiet");
    ok(substr_count($body, ':/stores') === 17, "$svc can read the whole store tree");
}

// The exempt ones are exempt because there is nowhere to put a check, and the
// evidence for that is that the gate cannot reach them: the gate is carried
// into a container by config.php, so an image that never copies config.php
// cannot run it whatever anyone intends.
//
// `liquibase` is worth saying out loud. It is built from a dockerfile in this
// repository, so it is not third-party in the sense `mysql` is -- but it is a
// Java image running a migration tool, with none of this package's PHP in it,
// and it connects to the database as root. It is therefore the one component
// that reaches customer data ungated and unobserved, and that is deliberate:
// it runs before there is a schema for the ring to be meaningful about, so a
// migration runner that refused while the ring was unclean could never make
// the deployment's first database.
foreach ($exempt as $svc => $why) {
    $body = t96_service($compose, $svc);
    ok($body !== null, "the compose file has a $svc service");
    if ($body === null) {
        continue;
    }

    $reaches_gate = false;
    if (preg_match('/dockerfile:\s*(\S+)/', $body, $m) === 1) {
        $df = REPO . '/' . $m[1];
        $reaches_gate = is_file($df) && strpos(file_get_contents($df), 'config.php') !== false;
    }
    ok(!$reaches_gate, "$svc runs none of this package's PHP, which is why it is exempt: $why");
    ok(substr_count($body, ':/stores') === 0, "and it is not given the store tree");
}

// --- every service states what happens when it stops ------------------------

$one_shot = array('liquibase');

foreach ($services as $svc) {
    $body = t96_service($compose, $svc);
    if ($body === null) {
        continue;
    }
    if (preg_match('/^\s*restart:\s*(\S+)\s*$/m', $body, $m) !== 1) {
        ok(false, "$svc declares a restart policy");
        continue;
    }
    $want = in_array($svc, $one_shot, true) ? "'no'" : 'always';
    ok($m[1] === $want, "$svc declares restart: $want");
}

t_done();
