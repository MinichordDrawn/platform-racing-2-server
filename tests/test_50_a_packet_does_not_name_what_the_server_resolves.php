<?php
// What a packet names is a value, not a name the server looks up.
//
// Two handlers took a name straight out of a packet and used it to find
// something.
//
// set_right_room resolved a global whose name it built from the packet:
// whatever the sender wrote, plus "_room". Which globals that reaches is not a
// decision the handler makes, it is whatever happens to be in scope and happen
// to end that way. The bound is a fact about the rest of the program rather
// than a rule about the packet, so it changes whenever something else is
// named, and nothing says so.
//
// sting read the target's id as $data[0]. On a string that is the first
// character, not the first field. A race hands out ids counting from zero and
// holds more than ten players, so every id above nine picked out a different
// player: id 12 stung player 1.
//
// Both are now settled the same way. The value is parsed out of the packet and
// bounded before anything is looked up, and the lookup is against a table the
// handler holds rather than a name the packet supplies.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a packet does not name what the server resolves\n";

// --- the id a packet carries ---------------------------------------------

ok(function_exists('packet_temp_id'), 'reading an id out of a packet is a function');

if (function_exists('packet_temp_id')) {
    is_same(packet_temp_id('7'), 7, 'a single digit id is read');
    is_same(packet_temp_id('0'), 0, 'zero is an id, and is read');

    // The defect. Reading a character gives 1 for both of these.
    is_same(packet_temp_id('12'), 12, 'a two digit id is read whole');
    is_same(packet_temp_id('19'), 19, 'the largest two digit id is read whole');

    // The first field, not the first character and not the whole packet.
    is_same(packet_temp_id('12`34'), 12, 'the first field is read, not the rest');
    is_same(packet_temp_id('3`'), 3, 'a trailing separator does not change the id');

    // Anything that is not an id is not one.
    is_same(packet_temp_id(''), null, 'an empty packet names no id');
    is_same(packet_temp_id('`'), null, 'a packet with an empty first field names no id');
    is_same(packet_temp_id('abc'), null, 'a word is not an id');
    is_same(packet_temp_id('-1'), null, 'a negative number is not an id');
    is_same(packet_temp_id('1.0'), null, 'a decimal is not an id');
    is_same(packet_temp_id(' 1'), null, 'a padded number is not an id');
    is_same(packet_temp_id('1e0'), null, 'an exponent is not an id');
}

// --- the rooms a client may ask for --------------------------------------

ok(function_exists('right_room_names'), 'the rooms a client may name are a table');

if (function_exists('right_room_names')) {
    $rooms = right_room_names();

    is_same(
        array_keys($rooms),
        array('campaign', 'best', 'best_week', 'newest', 'search'),
        'the table names the five rooms a client may ask for'
    );

    // Each has to be a room this server actually makes, or the table is
    // describing something that is not there.
    $boot = file_get_contents(REPO . '/multiplayer_server/pr2.php');
    foreach ($rooms as $asked => $global) {
        ok(
            strpos($boot, '$' . $global . ' = new LevelListRoom(') !== false,
            "asking for $asked reaches a room this server makes"
        );
    }

    // Leaving is not a room.
    ok(!isset($rooms['none']), 'leaving is not one of the rooms in the table');
}

// --- the handlers ---------------------------------------------------------

$lobby = file_get_contents(REPO . '/functions/multi_fns/client/lobby.php');
$ingame = file_get_contents(REPO . '/functions/multi_fns/client/ingame.php');

// A name built out of a packet must not be resolved into a variable at all.
ok(
    strpos($lobby, '${$data') === false,
    'the room handler does not build a variable name out of the packet'
);
ok(
    preg_match('/\$\{\$/', $lobby) !== 1,
    'the room handler resolves no variable variable'
);

ok(
    strpos($lobby, 'right_room_names') !== false,
    'the room handler asks the table'
);

// What comes back has to be a room, not whatever was under that name.
ok(
    strpos($lobby, 'instanceof \\pr2\\multi\\LevelListRoom') !== false,
    'the room handler checks that what it found is a room'
);

// A name that is not in the table is refused rather than ignored. Isolate the
// handler, because other handlers in this file refuse things too.
$rr_start = strpos($lobby, 'function client_set_right_room');
$rr_end = strpos($lobby, 'function client_set_chat_room');
$right_room = $rr_start === false || $rr_end === false
    ? ''
    : substr($lobby, $rr_start, $rr_end - $rr_start);

ok($right_room !== '', 'the room handler can be isolated');
ok(
    preg_match('/throw new \\\\?Exception/', $right_room) === 1,
    'a room this server does not have is refused'
);

// The id handlers read a field, not a character.
ok(
    strpos($ingame, '$data[0]') === false,
    'no handler reads the first character of a packet as an id'
);
ok(
    substr_count($ingame, 'packet_temp_id($data)') === 2,
    'both the sting and the squash read the id the same way'
);

// --- the room does not parse for itself ----------------------------------

$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

$start = strpos($game, 'public function squash');
$end = strpos($game, 'private function isStillPlaying', $start);
$both = substr($game, $start, $end - $start);

ok(
    strpos($both, "explode('`'") === false,
    'the room is handed an id rather than parsing the packet again'
);
ok(
    substr_count($both, '$target_id === null') === 2,
    'both refuse an id that was not there'
);

// And the lookup itself compares like for like.
$start = strpos($game, 'private function idToPlayer');
ok($start !== false, 'the lookup is present');
$end = strpos($game, ' function ', $start + 20);
$lookup = $end === false ? substr($game, $start) : substr($game, $start, $end - $start);
ok(
    strpos($lookup, '===') !== false,
    'a temp id is compared exactly, not loosely'
);
ok(
    preg_match('/temp_id\s*==(?!=)/', $lookup) !== 1,
    'the loose comparison is gone rather than joined'
);

t_done();
