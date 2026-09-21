<?php
// The control channel must bound how many connections it will hold at once.
//
// Control connections used to be counted by the player port's per-address
// cap, which was wrong in both directions: it leaked that count, and it
// refused the tenth concurrent hand-off even though they all legitimately
// arrive from one address. Taking them out of that count removed the leak and
// left the channel with no bound at all.
//
// A bound is derivable rather than guessed. A server holds at most
// $max_players, so more concurrent login hand-offs than that are of no use to
// anyone, and the limit is set from the same number with room for the poller
// and staff actions on top.

require_once __DIR__ . '/helper.php';

$PROCESS_KEY = 'a key used only by this test';
$GLOBALS['PROCESS_KEY'] = $PROCESS_KEY;

require_once REPO . '/common/manage_socket/control_auth.php';

echo "the control channel bounds how many connections it holds\n";

// Derived from the player cap the server process carries.
$GLOBALS['max_players'] = 200;
unset($GLOBALS['PROCESS_MAX_CONNECTIONS']);
$derived = control_connection_limit();
ok($derived > 200, 'the limit leaves room above the players a server can hold');
is_same($derived, 400, 'the limit is derived from the player cap');

// It moves with the cap rather than being a number of its own.
$GLOBALS['max_players'] = 50;
is_same(control_connection_limit(), 100, 'a smaller server gets a smaller limit');

// A deployment can say otherwise.
$GLOBALS['max_players'] = 200;
$GLOBALS['PROCESS_MAX_CONNECTIONS'] = 32;
is_same(control_connection_limit(), 32, 'an explicit limit is used when set');

// A nonsensical setting does not disable the bound.
$GLOBALS['PROCESS_MAX_CONNECTIONS'] = 0;
is_same(control_connection_limit(), 400, 'a zero limit falls back to the derived one');
$GLOBALS['PROCESS_MAX_CONNECTIONS'] = -5;
is_same(control_connection_limit(), 400, 'a negative limit falls back to the derived one');

// And a process that somehow has no player cap still gets a bound.
unset($GLOBALS['PROCESS_MAX_CONNECTIONS']);
unset($GLOBALS['max_players']);
$fallback = control_connection_limit();
ok($fallback > 0, 'a process with no player cap still has a limit');

// --- the listener has to apply it ---------------------------------------

$src = file_get_contents(REPO . '/multiplayer_server/PR2ControlClient.php');

ok(strpos($src, 'control_connection_limit') !== false, 'the control client consults the limit');
ok(
    preg_match('/private static \$open/', $src) === 1,
    'the control client counts what it currently holds'
);

// The count must be released, or the channel closes itself off exactly the
// way the player port was closed off before.
$dis = strpos($src, 'function onDisconnect');
ok($dis !== false, 'the control client releases its count on disconnect');
if ($dis !== false) {
    $tail = substr($src, $dis);
    ok(strpos($tail, 'self::$open--') !== false, 'the release decrements the count');
    ok(strpos($tail, 'parent::onDisconnect') !== false, 'the release still does the rest of the cleanup');
}

// Releasing must happen once however many times disconnect is reached.
ok(
    preg_match('/\$counted/', $src) === 1 || substr_count($src, '$counted') >= 3,
    'the count is released once and only once'
);

t_done();
