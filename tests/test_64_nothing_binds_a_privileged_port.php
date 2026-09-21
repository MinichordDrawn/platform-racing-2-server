<?php
// Nothing inside a container binds a port that needs privilege to bind.
//
// A process cannot bind below port 1024 without being root or holding
// CAP_NET_BIND_SERVICE, so the two services that listen low -- Apache on 80
// and the Flash policy server on 843 -- are the reason the containers run as
// root today. Both ports are an artefact of what the client dials, not of
// what the process needs: the host can publish 80 and 843 while the process
// inside listens somewhere unprivileged.
//
// So the containers listen high and the published port mapping preserves
// every address the client already uses. Nothing needs a capability, and the
// user the process runs as stops being forced by the port number.
//
// The policy port is read from the environment rather than written into the
// source, and a value that is not a usable port stops the server rather than
// being guessed at. A policy server listening somewhere unintended is a
// policy server that answers nobody, and finding that out at boot is much
// cheaper than finding it out from a player.

require_once __DIR__ . '/helper.php';

echo "nothing binds a privileged port\n";

// --- the policy port is configuration, not source -------------------------

$policy = file_get_contents(REPO . '/policy_server/run_policy.php');

ok(
    preg_match('/createServer\([^)]*,\s*843\s*\)/', $policy) !== 1,
    'the policy port is not written into the source'
);
ok(
    strpos($policy, 'POLICY_PORT') !== false,
    'the policy port comes from the environment'
);

// An unusable value stops the server rather than being replaced by a guess.
ok(
    preg_match('/throw new \\\\?(Exception|RuntimeException)/', $policy) === 1,
    'an unusable policy port stops the server'
);

$policy_docker = file_get_contents(REPO . '/docker/policy_server.dockerfile');
ok(
    preg_match('/ENV POLICY_PORT=(\d+)/', $policy_docker, $m) === 1,
    'the image declares the port it listens on'
);
if (isset($m[1])) {
    ok((int) $m[1] >= 1024, 'and it is a port an unprivileged process can bind');
}

// --- apache listens high --------------------------------------------------

$web_docker = file_get_contents(REPO . '/docker/http_server.dockerfile');

ok(
    preg_match('/ENV APACHE_LISTEN_PORT=(\d+)/', $web_docker, $m) === 1,
    'the web image declares the port Apache listens on'
);
if (isset($m[1])) {
    ok((int) $m[1] >= 1024, 'and it is a port an unprivileged process can bind');
}

// The listener and the virtual host both have to move, or Apache listens on
// one port and serves on another. Both are written from the same declared
// value so they cannot drift apart.
ok(
    strpos($web_docker, 'Listen ${APACHE_LISTEN_PORT}') !== false,
    'the listener uses the declared port'
);
ok(
    strpos($web_docker, 'VirtualHost *:${APACHE_LISTEN_PORT}') !== false,
    'and so does the virtual host'
);

// --- every container port published is unprivileged -----------------------

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

$low_container_ports = array();
foreach (explode("\n", $compose) as $line) {
    $line = trim(rtrim($line, "\r"));
    // - "80:8080"  /  - 843:8843  /  - "8081:8080"
    if (preg_match('/^-\s*"?(\d+):(\d+)"?\s*$/', $line, $p)) {
        if ((int) $p[2] < 1024) {
            $low_container_ports[] = $p[1] . ':' . $p[2];
        }
    }
}
is_same($low_container_ports, array(), 'no container is asked to listen below 1024');

// --- and the addresses the client uses are unchanged ----------------------

$published = array();
foreach (explode("\n", $compose) as $line) {
    $line = trim(rtrim($line, "\r"));
    if (preg_match('/^-\s*"?(\d+):(\d+)"?\s*$/', $line, $p)) {
        $published[] = (int) $p[1];
    }
}

foreach (array(80, 843, 9160) as $expected) {
    ok(
        in_array($expected, $published, true),
        "port $expected is still published, so the client dials what it always did"
    );
}

t_done();
