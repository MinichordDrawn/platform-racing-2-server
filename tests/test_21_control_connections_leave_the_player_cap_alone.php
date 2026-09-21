<?php
// A control connection must not consume a player's connection allowance.
//
// PR2Client keeps one static count of connections per address and refuses the
// tenth. That count is shared by every listener, because the control client
// inherits it. A control connection that is counted but never uncounted
// therefore spends a player's allowance permanently, and nine refused control
// connections from an address would stop anyone at that address reaching the
// game at all.
//
// These are source assertions rather than live connections: the classes need
// real sockets, and this software is never run here.

require_once __DIR__ . '/helper.php';

echo "control connections leave the player connection cap alone\n";

$client = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');
$control = file_get_contents(REPO . '/multiplayer_server/PR2ControlClient.php');

// The counter lives in onConnect. Isolate that method so the assertions below
// cannot be satisfied by some other part of the file.
$start = strpos($client, 'public function onConnect()');
ok($start !== false, 'onConnect is present');
$end = strpos($client, 'public function onDisconnect()', $start);
ok($end !== false, 'onDisconnect follows it');
$on_connect = substr($client, $start, $end - $start);

ok(
    strpos($on_connect, '$ip_array') !== false,
    'onConnect is the method that keeps the per-address count'
);

// The fix: a connection carrying the process flag never enters the count.
ok(
    preg_match('/if\s*\(\s*!\s*\$this->process\s*\)/', $on_connect) === 1,
    'onConnect skips the per-address count for a control connection'
);

// The flag has to be readable at that moment. It is, because the control
// client declares it as a property default, which PHP applies before any
// constructor body runs.
ok(
    preg_match('/public\s+\$process\s*=\s*true\s*;/', $control) === 1,
    'the control client carries the process flag as a property default'
);

// Belt and braces: the address refusal should also release anything it took.
$ctor_start = strpos($control, 'public function __construct');
$ctor = $ctor_start === false ? '' : substr($control, $ctor_start);
ok(
    strpos($ctor, 'onDisconnect()') !== false,
    'the address refusal releases the connection rather than only closing it'
);

t_done();
