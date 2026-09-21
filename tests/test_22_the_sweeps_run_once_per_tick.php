<?php
// The periodic sweeps must run once per tick, not once per listener.
//
// The daemon calls onTimer() on every server it holds. Adding the control
// listener as a second PR2SocketServer therefore ran every sweep twice: the
// temporary-item, ban, mute and social-ban expiries, the reconnect tick, the
// private-server expiry check and the loiter check. Most are wall-clock tests
// and survive being repeated, but the loiter counter is not: it subtracts one
// and adds two per call, so running it twice doubles the rate it climbs and
// halves the time it takes to decay.
//
// The control listener needs no sweeps of its own, so it gets a server class
// without them.

require_once __DIR__ . '/helper.php';

echo "the periodic sweeps run once per tick\n";

$boot = file_get_contents(REPO . '/multiplayer_server/pr2.php');

// Find both createServer calls and read the server class each one is given.
preg_match_all('/createServer\(\s*\'([^\']+)\'\s*,\s*\'([^\']+)\'/', $boot, $m, PREG_SET_ORDER);
is_same(count($m), 2, 'the boot file creates exactly two listeners');

if (count($m) === 2) {
    $player_server = $m[0][1];
    $player_client = $m[0][2];
    $control_server = $m[1][1];
    $control_client = $m[1][2];

    is_same(
        $player_server,
        '\\pr2\\multi\\PR2SocketServer',
        'the player listener keeps the server class that owns the sweeps'
    );
    is_same($player_client, '\\pr2\\multi\\PR2Client', 'the player listener keeps its client class');
    is_same($control_client, '\\pr2\\multi\\PR2ControlClient', 'the control listener keeps its client class');

    ok(
        $control_server !== '\\pr2\\multi\\PR2SocketServer',
        'the control listener does not reuse the server class that owns the sweeps'
    );
}

// The sweeps themselves are unchanged and still belong to the player listener.
$socket_server = file_get_contents(REPO . '/multiplayer_server/PR2SocketServer.php');
ok(strpos($socket_server, 'LoiterDetector::check()') !== false, 'the loiter sweep still belongs to the player listener');

// The control listener's own class must not repeat them.
$control_path = REPO . '/multiplayer_server/PR2ControlServer.php';
ok(file_exists($control_path), 'the control listener has a server class of its own');
if (file_exists($control_path)) {
    $control_src = file_get_contents($control_path);
    ok(
        strpos($control_src, 'LoiterDetector') === false,
        'the control listener runs no loiter sweep'
    );
    ok(
        strpos($control_src, 'TemporaryItems') === false
        && strpos($control_src, 'tickAllReconnects') === false,
        'the control listener runs none of the other sweeps either'
    );
}

t_done();
