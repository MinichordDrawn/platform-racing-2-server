
<?php
// The player port is declared, not looked up.
//
// The game server bound whatever port a database row told it to. The row was
// read by `configure_server`, inside the startup loadup, which assigned the
// global the listener is created with. The hard-coded value above it was a
// stale default that never took effect, because the row always overwrote it.
//
// That was found by deferring the loadup during a halt, which is a correct
// thing to do and which left the server binding the stale default. The
// observer beside it reported that nothing was listening on the port its
// column declares, which was true, and the ring halted on a server that was
// listening perfectly well somewhere else.
//
// The deployment states this port in three places it controls: the compose
// file publishes it, the image exposes it, and the observer's column asserts
// something answers on it. The actual bind came from a fourth place none of
// those can see. A row edited by anything with write access to that table
// moves the listener, and the only component that notices is the observer,
// which reports it as the port being dead.
//
// So the port comes from the environment, checked the way the policy server
// checks its own, and the row no longer decides where anything listens.

require_once __DIR__ . '/helper.php';

echo "the player port is declared, not looked up\n";

function t91_code($src)
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

$pr2    = t91_code(file_get_contents(REPO . '/multiplayer_server/pr2.php'));
$loadup = t91_code(file_get_contents(REPO . '/functions/multi_fns/loadup_fns.php'));

// --- the loadup no longer decides where anything listens ------------------

ok(
    preg_match('/\$port\s*=\s*\(int\)\s*\$server->port/', $loadup) !== 1,
    'the startup loadup does not assign the port from the row'
);
ok(
    preg_match('/global\s+[^;]*\$port\b/', $loadup) !== 1,
    'and does not reach for that global at all'
);

// --- it comes from the environment, checked before it is used -------------

ok(
    strpos($pr2, "getenv('PLAYER_PORT')") !== false,
    'the game server reads its port from the environment'
);

// Checked the way the policy server checks its own: a value that is not a
// usable port stops the server rather than being guessed at.
ok(
    preg_match('/PLAYER_PORT/', $pr2) === 1 || substr_count($pr2, 'PLAYER_PORT') >= 1,
    'and names it'
);
ok(
    preg_match('/preg_match\([\x27"]\/\^\\\\d\+\$\/[\x27"]/', $pr2) === 1,
    'validates it as digits'
);
ok(
    preg_match('/65535/', $pr2) === 1,
    'and as a port number'
);
ok(
    preg_match('/throw new \\\\?Exception/', $pr2) === 1,
    'and refuses to start otherwise, rather than falling back'
);

// The check comes before the listener is created.
$read_at = strpos($pr2, "getenv('PLAYER_PORT')");
$bind_at = strpos($pr2, 'createServer(');
ok($read_at !== false && $bind_at !== false && $read_at < $bind_at,
    'and settles the port before it binds anything');

// --- and no stale default is left to be bound -----------------------------
//
// The value that was actually bound when the loadup was deferred. A default
// that is only ever correct because something else overwrites it is not a
// default, it is a trap.
ok(
    preg_match('/\$port\s*=\s*9159\s*;/', $pr2) !== 1,
    'the stale hard-coded port is gone'
);

// --- the three declarations agree -----------------------------------------
//
// The compose file publishes it, the observer's column asserts something
// answers on it, and the environment tells the server where to bind. These are
// three statements of one fact, and this is what keeps them from drifting.

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');
preg_match('/- PLAYER_PORT=(\d+)/', $compose, $env);
ok(isset($env[1]), 'the compose file declares the port to the game server');

$container = file_get_contents(REPO . '/observers/multi/container.php');
preg_match("/'player'\s*=>\s*(\d+)/", $container, $col);
ok(isset($col[1]), "the observer's column names a player port");

preg_match('/- "(\d+):(\d+)"/', t91_service($compose, 'multi'), $pub);
ok(isset($pub[2]), 'and the compose file publishes one');

if (isset($env[1], $col[1], $pub[2])) {
    is_same($col[1], $env[1], 'the observer watches the port the server is told to bind');
    is_same($pub[2], $env[1], 'and the deployment publishes that same port');
}

function t91_service($compose, $name)
{
    if (!preg_match('/^  ' . preg_quote($name, '/') . ':\s*$/m', $compose, $m, PREG_OFFSET_CAPTURE)) {
        return '';
    }
    $from = $m[0][1] + strlen($m[0][0]);
    $rest = substr($compose, $from);
    $end = preg_match('/^  [a-z0-9][a-z0-9_-]*:\s*$/m', $rest, $n, PREG_OFFSET_CAPTURE)
        ? $n[0][1] : strlen($rest);
    return substr($rest, 0, $end);
}

t_done();
