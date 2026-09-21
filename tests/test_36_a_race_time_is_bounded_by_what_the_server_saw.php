<?php
// A race time reported by the client must be bounded by the one the server
// measured.
//
// The server times every race itself, from the moment it starts the race to
// the moment the finish packet arrives. It then threw that away and recorded
// whatever time the client sent, as long as the number was above zero. A
// client naming a time of one millisecond had that recorded, and it is the
// recorded time that reaches the finish order, the replay and the records.
//
// The client's own time is there for a reason: it is measured on the machine
// actually playing, so a slow connection is not charged for time the player
// did not spend racing. That makes it worth using, not worth trusting. The
// server's measurement includes the delay getting to it, so a genuine time is
// at most what the server saw and not far below it. Outside that, what the
// server measured is used instead.
//
// This is the half of the problem that signing packets cannot reach. Whoever
// runs the client holds its key and can sign whatever they like, so the values
// have to stand up on their own.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a race time is bounded by what the server saw\n";

// A server-measured run of thirty seconds.
$server = 30000;

is_same(accepted_finish_ms(null, $server), $server, 'no client time recorded means the server\'s own');
is_same(accepted_finish_ms('', $server), $server, 'an empty client time means the server\'s own');
is_same(accepted_finish_ms('abc', $server), $server, 'a client time that is not a number means the server\'s own');

is_same(accepted_finish_ms(0, $server), $server, 'a zero is refused');
is_same(accepted_finish_ms(-5000, $server), $server, 'a negative is refused');
is_same(accepted_finish_ms(1, $server), $server, 'a single millisecond is refused');

// Nobody finished faster than the server saw, by more than the connection
// could account for.
is_same(accepted_finish_ms(100, $server), $server, 'a time far below what the server saw is refused');

// Nor slower: the server's clock stopped when the packet arrived, so a claim
// beyond that did not happen.
is_same(accepted_finish_ms($server + 1, $server), $server, 'a time beyond what the server saw is refused');
is_same(accepted_finish_ms(999999, $server), $server, 'a wildly long time is refused');

// What the client's time is actually for: shaving off the delay in getting
// here. A little under the server's figure is exactly the honest case.
is_same(accepted_finish_ms($server, $server), $server, 'a time equal to the server\'s is kept');
is_same(accepted_finish_ms($server - 200, $server), $server - 200, 'a slightly faster time is kept');
is_same(accepted_finish_ms($server - 4999, $server), $server - 4999, 'a time inside the allowance is kept');

// The allowance is explicit and can be set for a deployment.
is_same(race_finish_allowance_ms(), 5000, 'the default allowance is stated');

$GLOBALS['RACE_FINISH_ALLOWANCE_MS'] = 100;
is_same(accepted_finish_ms($server - 200, $server), $server, 'a tighter allowance is applied');
is_same(accepted_finish_ms($server - 50, $server), $server - 50, 'a time inside the tighter allowance is kept');

$GLOBALS['RACE_FINISH_ALLOWANCE_MS'] = 0;
is_same(race_finish_allowance_ms(), 5000, 'a zero allowance falls back to the default');
$GLOBALS['RACE_FINISH_ALLOWANCE_MS'] = -1;
is_same(race_finish_allowance_ms(), 5000, 'a negative allowance falls back to the default');
unset($GLOBALS['RACE_FINISH_ALLOWANCE_MS']);

// A very short genuine race still works, which is the case a blunt minimum
// would have broken.
is_same(accepted_finish_ms(900, 1000), 900, 'a genuinely fast race is kept');

// --- the game has to use it ---------------------------------------------

$src = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

ok(strpos($src, 'accepted_finish_ms') !== false, 'the race finish uses the bound');
ok(
    preg_match('/\$effective_finish_ms\s*=\s*\$local_finish_ms\s*!==\s*null\s*\?/', $src) !== 1,
    'the client\'s time is no longer taken whenever it is present'
);

t_done();
