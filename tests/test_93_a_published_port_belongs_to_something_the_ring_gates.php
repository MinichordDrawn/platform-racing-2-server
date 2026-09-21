
<?php
// A published port belongs to something the ring gates.
//
// The deployment publishes four ports. Three of them are the game: the web
// tier, the game server and the policy server, each in a container that holds
// an observer and reads the gate before it works. The fourth was a database
// console, published on 8081, with a restart policy, no user, no dropped
// capabilities, and the database's root password beside it in the same file.
//
// Nothing gated it and nothing observed it, because nothing could: it runs a
// third-party image with none of this package's code in it, so there is no
// place to put a check. A halt stopped every way into the data except that
// one. The database itself publishes nothing and is reachable only on the
// compose network, which this bridged straight to the outside.
//
// So the rule is the one the design already implies. **A service that
// publishes a port is a way in, and every way in is gated.** A component that
// cannot be gated cannot be published, and a database console is a
// development convenience, which belongs where development conveniences
// belong and not in the file a deployment is built from.

require_once __DIR__ . '/helper.php';

echo "a published port belongs to something the ring gates\n";

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

// Services, as blocks, so a key is read against the service that owns it.
function t93_services($compose)
{
    $out = array();
    $lines = preg_split('/\r?\n/', $compose);
    $in = false;
    $current = null;
    foreach ($lines as $line) {
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
            $out[$current] = '';
            continue;
        }
        if ($current !== null) {
            $out[$current] .= $line . "\n";
        }
    }
    return $out;
}

$services = t93_services($compose);
ok(count($services) > 0, 'the compose file parses into services');

// --- every published port is gated ----------------------------------------
//
// Gated means the container is told which observer watches its host, which is
// what the gate reads. A container with no such setting runs no check of ours,
// whatever is inside it.

$publishing = array();
foreach ($services as $name => $body) {
    if (preg_match('/^\s*ports:\s*$/m', $body)) {
        $publishing[] = $name;
    }
}
sort($publishing, SORT_STRING);

$ungated = array();
foreach ($publishing as $name) {
    if (strpos($services[$name], 'OBSERVER_LOCAL=') === false) {
        $ungated[] = $name;
    }
}

is_same($ungated, array(),
    'every service that publishes a port reads the gate before it works');

// Named, so the failure says which ports exist rather than only that one is
// wrong.
is_same($publishing, array('multi', 'policy', 'web'),
    'and the published ports are the three that serve the game');

// --- the database console is not in the file a deployment builds from -----

ok(
    !isset($services['adminer']),
    'the database console is not a service of the shipped deployment'
);

// It is kept, because it is useful, in the file that exists for exactly that.
$dev = REPO . '/docker/docker-compose.dev.yml';
ok(is_file($dev), 'it is kept in a development override instead');
if (is_file($dev)) {
    $d = file_get_contents($dev);
    ok(strpos($d, 'adminer') !== false, 'which is where it now lives');
    ok(
        strpos($d, 'development') !== false || strpos($d, 'Development') !== false,
        'and which says what it is for'
    );
}

// --- the database is reachable from nowhere it is published ---------------
//
// mysql publishes nothing, so it is reachable only on the compose network.
// That is the property the console undid, and it is worth asserting rather
// than assuming, because the next convenience added will be added the same
// way.

ok(isset($services['mysql']), 'the database is still a service');
if (isset($services['mysql'])) {
    ok(
        preg_match('/^\s*ports:\s*$/m', $services['mysql']) !== 1,
        'and publishes no port of its own'
    );
}

// --- and the credential that reaches all of it is not a literal -----------
//
// The application's own database password is already covered: it is an
// env.example value and the boot check refuses to start on it. The root
// password is not, and it is what the migration runner uses, so a deployment
// that changed the first and not the second still has a known root credential
// on the network the console used to bridge.

foreach (array('mysql', 'liquibase') as $name) {
    if (!isset($services[$name])) {
        continue;
    }
    ok(
        preg_match('/(MYSQL_ROOT_PASSWORD|LIQUIBASE_PASSWORD):\s*["\x27]?root["\x27]?\s*$/m', $services[$name]) !== 1,
        "$name does not carry a known root password as a literal"
    );
    ok(
        preg_match('/(MYSQL_ROOT_PASSWORD|LIQUIBASE_PASSWORD):\s*\$\{[A-Z_]+:\?/', $services[$name]) === 1,
        "$name requires it from the environment and refuses without one"
    );
}

t_done();
