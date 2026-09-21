<?php
// Every command that is handed packet text reads it against a declaration.
//
// This states the rule rather than naming the commands. Listing the places
// that need a check by hand is how places get missed, so the test walks the
// handler directory, finds every handler that is given the packet, and
// requires each to read it rather than pass it on.
//
// Four commands are settled here that the server alone could not settle. Their
// shapes come from the client: how many fields it writes, and what each one is
// computed from.
//
// One command is not settled and is gone instead. Nothing sends it: it appears
// in no client source and in none of the shipped client builds, while every
// other command here appears in all of them. Its handler joined the sender's
// text onto the command name with no separator between them, so the sender
// chose the name the receiving clients dispatched on. A shape cannot be
// declared for a command nothing sends, and guessing one would be worse than
// either keeping it or removing it, so it is removed.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "every handler reads its packet\n";

$dir = REPO . '/functions/multi_fns/client';
$sources = array();
foreach (glob($dir . '/*.php') as $file) {
    $sources[basename($file)] = file_get_contents($file);
}
ok(count($sources) > 0, 'the handler directory was found');

// --- the rule -------------------------------------------------------------
//
// Every handler that is given the packet reads it rather than passing it on.
// This began as a count of the ones that did not, because it held for the
// commands relaying into a live race and not for the rest of the directory.
// It holds for all of them now, so it is stated as the rule it always was.

// The ways a handler may read its packet. right_room_names is one of them
// because it bounds the whole packet against a list of six literals, which is
// a stricter answer than declaring a field, not a looser one.
$readers = array(
    'packet_fields',
    'packet_temp_id',
    'valid_egg_id',
    'valid_course_id',
    'right_room_names',
    'ChatMessage',
);

$not_reading = array();
$checked = 0;
foreach ($sources as $name => $src) {
    if (!preg_match_all('/function (client_\w+)\(([^)]*)\)/', $src, $m, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($m as $match) {
        $handler = $match[1];

        // A handler that does not take the packet cannot mishandle it. What
        // makes it the packet is its position, not its name: one handler took
        // it under another name, which is how it escaped this count.
        $params = array_filter(array_map('trim', explode(',', $match[2])));
        if (count($params) < 2) {
            continue;
        }

        $start = strpos($src, "function $handler(");
        $end = strpos($src, "\nfunction ", $start);
        $body = $end === false ? substr($src, $start) : substr($src, $start, $end - $start);

        $checked++;

        foreach ($readers as $reader) {
            if (strpos($body, $reader) !== false) {
                continue 2;
            }
        }

        $not_reading[] = $handler;
    }
}

ok($checked > 20, "the rule was applied to every handler that takes the packet ($checked of them)");

sort($not_reading);
is_same(
    $not_reading,
    array(),
    'every handler that takes the packet reads it rather than passing it on'
);

// --- the command nothing sends is gone -----------------------------------

$all = implode("\n", $sources);
ok(strpos($all, 'function client_hit(') === false, 'the command nothing sends has no handler');

$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');
ok(strpos($game, 'function broadcastHit') === false, 'and nothing in the room is left waiting for it');
ok(
    strpos($game, "'hit' . \$data") === false && strpos($game, "'hit'.\$data") === false,
    'no packet name is built by joining the sender text onto a literal'
);

// --- the four shapes taken from the client -------------------------------

function handler_body($all, $name)
{
    $start = strpos($all, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($all, "\nfunction ", $start);
    return $end === false ? substr($all, $start) : substr($all, $start, $end - $start);
}

function declared_fields($body)
{
    if (!preg_match('/packet_fields\(\s*\$data\s*,\s*(?:\$\w+|array\((.*?)\))\s*\)/s', $body, $m)) {
        return -1;
    }
    return isset($m[1]) ? substr_count($m[1], '=>') : -2;
}

// A block activation: which block, and what it was activated with. The last
// field is empty for the blocks that send nothing with it, which is most of
// them, so it is declared as a field that may be empty.
$body = handler_body($all, 'client_activate');
is_same(declared_fields($body), 3, 'a block activation declares three fields');
ok(strpos($body, '?') !== false, 'and says which of them may be empty');
is_same(substr_count($body, "'num'"), 2, 'the two that say which block are numbers');

// A dropped hat: where it fell and how it is turned. Exactly three, because
// the server writes four fields of its own after them and a fourth from the
// sender would move all of those.
$body = handler_body($all, 'client_loose_hat');
is_same(declared_fields($body), 3, 'a dropped hat declares three fields');
is_same(substr_count($body, "'num'"), 3, 'all three are numbers');

// A player variable: which one, and its value. The value is read as what that
// particular variable is.
$body = handler_body($all, 'client_set_var');
ok(strpos($body, 'packet_fields') !== false, 'a player variable reads its packet');
ok(strpos($body, 'player_var_kinds') !== false, 'and reads the value as what that variable is');

// An effect: how many fields depends on which effect, so the name is read
// first and decides the rest.
$body = handler_body($all, 'client_add_effect');
ok(strpos($body, 'effect_field_kinds') !== false, 'an effect reads the fields that effect carries');

// --- the tables ----------------------------------------------------------

ok(function_exists('player_var_kinds'), 'the player variables are a table');
ok(function_exists('effect_field_kinds'), 'the effects are a table');

if (function_exists('player_var_kinds')) {
    $vars = player_var_kinds();

    // Every variable the client sends has to be here, or it is refused in play.
    $sent = array('scaleX', 'state', 'parent', 'item', 'rotMod', 'rot', 'sparkle', 'jet', 'beginRemove');
    foreach ($sent as $var) {
        ok(isset($vars[$var]), "the table has the $var variable the client sends");
    }

    // The three that are legitimately negative must not be whole numbers.
    foreach (array('scaleX', 'rot', 'rotMod') as $signed) {
        ok(isset($vars[$signed]) && $vars[$signed] === 'num', "$signed is a number, because it is legitimately negative");
    }

    // The two that are names are read as names.
    foreach (array('state', 'parent') as $named) {
        ok(isset($vars[$named]) && $vars[$named] === 'word', "$named is a name");
    }
}

if (function_exists('effect_field_kinds')) {
    $effects = effect_field_kinds();

    $counts = array('Teleport' => 3, 'Mine' => 4, 'Slash' => 5, 'Laser' => 6, 'IceWave' => 6);
    foreach ($counts as $effect => $count) {
        ok(isset($effects[$effect]), "the table has the $effect effect");
        if (isset($effects[$effect])) {
            is_same(count($effects[$effect]), $count, "$effect carries $count fields");
        }
    }

    // Every effect names itself in its own first field, so the name that
    // chose the shape is the name that goes out.
    foreach ($effects as $effect => $spec) {
        $first = array_slice($spec, 0, 1);
        is_same(reset($first), 'word', "the $effect effect names itself in a field read as a name");
    }
}

// --- the tail comparison that never matched ------------------------------
//
// Deciding which variable arrived by taking the tail of the packet from a
// fixed offset compares the wrong part of it. With the name read as a field,
// the comparison is against the name.
ok(
    strpos($game, "substr(\$data, 4) === 'item'") === false,
    'which variable arrived is decided by its name, not by an offset into the packet'
);

t_done();
