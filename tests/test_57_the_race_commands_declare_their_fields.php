<?php
// The five remaining race commands read the packets they act on.
//
// Each took the packet apart itself, with a floor on the count rather than a
// count, or with a test per field that supplied a value of its own when the
// field was not there. A floor ignores anything past it. A per-field default
// turns a short packet into a finish at the origin rather than into a refusal.
//
// Finishing and reaching an objective both carry a position that the room
// checks against where the finish block is, and a time it measures its own
// against. A finish identifier is signed, because one value of it means the
// player gave up rather than finished.
//
// Declaring the level's rules does not settle them. They are still whatever
// the clients in the race voted for, and this server has no way to read a
// level and say what its rules are. What the declaration settles is the shape:
// six fields, each read as what it is used as, rather than six positions in a
// run of text where a seventh went unnoticed and a fifth missing one left a
// rule unset.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "the race commands declare their fields\n";

$src = file_get_contents(REPO . '/functions/multi_fns/client/ingame.php');
$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

function race_body($src, $name)
{
    $start = strpos($src, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\nfunction ", $start);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

function race_method($src, $name)
{
    $start = strpos($src, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, ' function ', $start + 12);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

function race_declared($body)
{
    if (!preg_match('/packet_fields\(\s*\$data\s*,\s*array\((.*?)\)\s*\)/s', $body, $m)) {
        return -1;
    }
    return substr_count($m[1], '=>');
}

// Counts taken from how the client writes each command.
$commands = array(
    'client_grab_egg' => 1,
    'client_resume_race_state' => 1,
    'client_objective_reached' => 4,
    'client_finish_race' => 4,
    'client_finish_drawing' => 6,
);

foreach ($commands as $handler => $count) {
    $body = race_body($src, $handler);
    ok($body !== '', "$handler is present");
    is_same(race_declared($body), $count, "$handler declares $count field(s)");
}

// --- an egg is still bounded by how many the race has --------------------

$grab = race_body($src, 'client_grab_egg');
ok(strpos($grab, "'uint'") !== false, 'an egg is named by a whole number');
$egg_method = race_method($game, 'grabEgg');
ok(
    strpos($egg_method, 'valid_egg_id') !== false,
    'and the room still checks it names an egg this race has'
);

// --- a finish identifier is signed, an objective one is not --------------

$finish = race_body($src, 'client_finish_race');
ok(
    preg_match("/'finish_id'\s*=>\s*'num'/", $finish) === 1,
    'a finish identifier is signed, because one value of it means giving up'
);

$objective = race_body($src, 'client_objective_reached');
ok(
    preg_match("/'finish_id'\s*=>\s*'uint'/", $objective) === 1,
    'an objective identifier is a whole number, because it is recorded under one'
);

// --- the floors and the per-field defaults are gone ----------------------

foreach (array('objectiveReached', 'remoteFinishRace', 'finishDrawing') as $method) {
    $body = race_method($game, $method);
    ok($body !== '', "$method is present");
    ok(strpos($body, "explode('`'") === false, "$method does not take the packet apart again");
}

$obj_method = race_method($game, 'objectiveReached');
ok(strpos($obj_method, 'count($parts)') === false, 'reaching an objective no longer counts to a floor');

$fin_method = race_method($game, 'remoteFinishRace');
ok(
    strpos($fin_method, 'isset($parts[1])') === false,
    'finishing no longer supplies a position of its own when the field is missing'
);
ok(
    strpos($fin_method, 'verifyFinishPosition') !== false,
    'and still checks the position against where the finish block is'
);

// --- the level rules are a shape, not a verdict --------------------------

$draw = race_body($src, 'client_finish_drawing');
ok(strpos($draw, "'uint'") !== false, 'the counts among the level rules are whole numbers');
ok(strpos($draw, '?') !== false, 'and the list that is empty when there is nothing in it may be empty');

$draw_method = race_method($game, 'finishDrawing');
ok(
    strpos($draw_method, '$arr[5]') === false,
    'the room no longer reads the rules by position'
);

t_done();
