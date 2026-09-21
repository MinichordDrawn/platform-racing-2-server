<?php
// An objective must count once, and only while the sender is still racing.
//
// Objectives reached are recorded in an array keyed by the objective's id, and
// the count of that array decides the objective mode placement, which decides
// the prize, the guild points and the exp everyone else receives. A duplicate
// guard tested whether the key was already present.
//
// The key was the id exactly as it arrived in the packet. is_numeric accepts
// several spellings of one number, and PHP keeps most of those as separate
// array keys, so the same objective could be handed in repeatedly under
// different spellings. Each one grew the count, and the count is not otherwise
// bounded by the number of objectives the race has.
//
// There was also no test that the sender's own race was still running, so
// objectives could be added after finishing, and each addition changed the
// order the next finisher was placed into.

require_once __DIR__ . '/helper.php';

echo "an objective counts once\n";

// Why the key matters. These are all the same objective as far as the range
// test is concerned, because is_numeric accepts every one of them.
$spellings = array('1', '01', '1.0', ' 1', '1e0');

foreach ($spellings as $spelling) {
    ok(is_numeric($spelling), "the range test accepts the spelling " . var_export($spelling, true));
}

$as_written = array();
foreach ($spellings as $spelling) {
    $as_written[$spelling] = 1;
}
ok(count($as_written) > 1, 'keyed as written, one objective becomes several entries');

$as_numbers = array();
foreach ($spellings as $spelling) {
    $as_numbers[(int) $spelling] = 1;
}
is_same(count($as_numbers), 1, 'keyed as whole numbers, they are one entry');

// --- the game has to key them as numbers --------------------------------

$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

$at = strpos($game, 'function objectiveReached');
ok($at !== false, 'the objective handler is present');
$body = substr($game, $at, 1400);

// The id was made a whole number inside the room, after the packet had been
// taken apart there. It is read as one before the room is called at all now,
// which is the same property established earlier and by declaration rather
// than by correction.
$handler = file_get_contents(REPO . '/functions/multi_fns/client/ingame.php');
$at_h = strpos($handler, 'function client_objective_reached');
$h_body = substr($handler, $at_h, 700);

ok(
    preg_match("/'finish_id'\s*=>\s*'uint'/", $h_body) === 1,
    'the objective id is read as a whole number before the room is called'
);
ok(
    strpos($body, 'objectives_reached[$finish_id]') !== false,
    'and it is what the duplicate test keys on'
);
ok(
    preg_match('/\$finish_id\s*=\s*\(int\)\s*\$finish_id/', $body) !== 1,
    'the room no longer corrects an id it was handed already read'
);

ok(strpos($body, 'already been reached') !== false, 'the duplicate test is still there');

// A packet with too few fields must not be split into nulls.
ok(
    preg_match('/list\(\s*\$finish_id\s*,\s*\$x\s*,\s*\$y\s*,\s*\$canon_ms\s*\)\s*=\s*explode/', $body) !== 1,
    'the packet is no longer split straight into four names without counting them'
);
// The room counted the fields and refused fewer than four. The handler
// declares the four the client sends and the packet is read against that, so
// the count is exact rather than a floor, and the room is handed values.
ok(
    strpos($body, 'count($parts)') === false,
    'the room no longer counts fields it was not handed'
);
ok(
    preg_match('/function objectiveReached\(\$player, \$finish_id, \$x, \$y, \$canon_ms\)/', $body) === 1,
    'the room takes the four values rather than the packet'
);

// And a finished racer stops adding to the count everyone else is sorted by.
ok(strpos($body, 'finished_race') !== false, 'the handler checks whether the sender is still racing');
$finished_at = strpos($body, 'finished_race');
$record_at = strpos($body, 'objectives_reached[$finish_id] = 1');
ok(
    $record_at !== false && $finished_at < $record_at,
    'that check comes before the objective is recorded'
);

t_done();
