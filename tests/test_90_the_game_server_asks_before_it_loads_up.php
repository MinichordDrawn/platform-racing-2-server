
<?php
// The game server asks before it loads up.
//
// The rule this design settled on is that nothing is prevented from starting
// and everything is prevented from working. The game server had a gap of one
// timer interval at the front of that, and the timer is the socket daemon's,
// which is three to four seconds rather than the two its comment claims.
//
// In that window it connected to the database, read six tables, and **wrote a
// row saying it was up and open** (`configure_server`, via `server_update_status`).
// That write is the worst of it: the web tier reads it to decide where to send
// players, so a halted deployment advertised a game server as available. Then
// it bound both ports and admitted whatever arrived, until the first tick.
//
// So the gate is asked once, explicitly, before any of it. The process still
// starts and still binds, because the observer beside it asserts that the
// process is alive and the ports answer, and a game server that refused to
// bind would fault its own container. What it does not do is touch the
// database or admit anybody.
//
// Loading up is then deferred rather than skipped. A server that never loaded
// cannot serve when the halt lifts, so the first clear tick does the work the
// halt postponed. That keeps the startup path and the recovery path the same
// path, which is the only way the second one gets exercised.

require_once __DIR__ . '/helper.php';

echo "the game server asks before it loads up\n";

function t90_code($src)
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

$pr2 = t90_code(file_get_contents(REPO . '/multiplayer_server/pr2.php'));

// --- the gate is asked before the database is touched ---------------------

$gate_at   = strpos($pr2, 'observer_gate_reason(');
$loadup_at = strpos($pr2, 'begin_loadup(');
$pdo_at    = strpos($pr2, 'pdo_connect()');

ok($gate_at !== false, 'the game server asks the gate at startup');
ok($loadup_at !== false, 'and still has a loadup to do');
ok($gate_at !== false && $loadup_at !== false && $gate_at < $loadup_at,
    'and asks before it loads up');

// The heading above was the claim and this is the check it never had. The
// offset was computed here and compared with nothing, so a gate read eighty-
// eight lines below the connection satisfied every assertion in this file
// while the comment beside it said it asked first. `$pdo` is the global every
// database call in this server goes through, so establishing it is touching
// the database in the only sense that matters here.
ok($pdo_at !== false, 'and it connects to the database somewhere');
ok($gate_at !== false && $pdo_at !== false && $gate_at < $pdo_at,
    'and asks before it connects, which is what this section is called');

// Guarded, not merely preceded.
ok(
    preg_match('/PR2SocketServer::\$halted\s*===\s*null\s*\)\s*\{\s*try\s*\{\s*\$pdo\s*=\s*pdo_connect\(\)/s', $pr2) === 1,
    'and connects only when the gate is open');

// A database it cannot reach is not a reason to exit either, and it was one.
// Exiting ends this container and the observer in it, so the ring loses a
// member and raises a halt that the exited process is the only thing that
// could lift. A brief outage became a deployment that stayed stopped.
ok(
    preg_match('/\b(exit|die)\s*[\(;]/', $pr2) !== 1,
    'and nothing in this file exits, whatever it finds'
);
ok(
    preg_match('/if\s*\(\s*PR2SocketServer::\$halted\s*===\s*null\s*&&\s*\$pdo\s*!==\s*null\s*\)/', $pr2) === 1,
    'and the loadup waits for a connection as well as for the gate'
);

// A deferred loadup has to establish the connection it deferred, or the first
// clear tick would run every query through a null.
$sock = t90_code(file_get_contents(REPO . '/multiplayer_server/PR2SocketServer.php'));
$connect_in_timer = strpos($sock, 'pdo_connect()');
$load_in_timer    = strpos($sock, 'begin_loadup(');
ok($connect_in_timer !== false, 'the deferred path connects for itself');
ok($connect_in_timer !== false && $load_in_timer !== false && $connect_in_timer < $load_in_timer,
    'and does so before the loadup it deferred');

// --- and nothing loads up while the ring says stop ------------------------
//
// The call is guarded, not merely preceded. A gate consulted and then ignored
// would pass the ordering check above and change nothing.

ok(
    preg_match('/if\s*\(\s*PR2SocketServer::\$halted\s*===\s*null\s*(?:&&[^)]*)?\)\s*\{\s*begin_loadup/s', $pr2) === 1,
    'the loadup happens only when the gate is open'
);

// --- but the process still starts and still binds -------------------------
//
// Refusing to bind would take down the two checks its own observer makes about
// this container, and a work process that faults its observer to avoid working
// has stopped the whole ring rather than itself.

$bind_at = strpos($pr2, 'createServer(');
ok($bind_at !== false, 'it still creates its listeners');
ok(
    $gate_at !== false && $bind_at !== false && $gate_at < $bind_at,
    'after asking, so a halted server is a listening server that refuses'
);
ok(
    preg_match('/\b(exit|die)\s*[\(;]/', substr($pr2, $gate_at, $bind_at - $gate_at)) !== 1,
    'and does not exit on a halt, which would end the observer beside it'
);

// --- the work the halt postponed is done when it lifts --------------------

$server = t90_code(file_get_contents(REPO . '/multiplayer_server/PR2SocketServer.php'));

ok(
    strpos($server, 'loaded') !== false,
    'the server tracks whether it has loaded up'
);
ok(
    strpos($server, 'begin_loadup') !== false,
    'and does it from the timer when the gate opens'
);

// It must be after the gate check in the timer, not before: loading up during
// a halt is the thing being prevented.
$timer = strpos($server, 'function onTimer');
$halt_in_timer = strpos($server, 'observer_gate_reason(', $timer);
$load_in_timer = strpos($server, 'begin_loadup', $timer);
ok($halt_in_timer !== false && $load_in_timer !== false && $halt_in_timer < $load_in_timer,
    'and only after the timer has established the ring is clear');

// --- the sweeps still wait for the loadup ---------------------------------
//
// They read state the loadup builds. Running them against an unloaded server
// would be the gap moved rather than closed.

ok(
    preg_match('/begin_loadup[^;]*;[\s\S]{0,400}?TemporaryItems::removeExpired/', $server) === 1,
    'and the periodic sweeps run after it, not before'
);

t_done();
