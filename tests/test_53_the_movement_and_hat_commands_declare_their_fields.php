<?php
// The commands whose fields the server itself settles now declare them.
//
// Four of these can be read straight off what the room method already does
// with the value, without asking the client anything.
//
// Two move a player: the room adds their fields together with the position it
// is tracking, so they are numbers. The relay happened before the parse, so
// the packet went out whatever the fields turned out to be, and the values
// were stored without ever being tested. A position that is not a number is
// then compared against boxes by the squash and the sting, where it decides
// who is hit.
//
// Two name a dropped hat: the room uses the field as a key into its own array
// of hats and compares it against its own counter, so it is a whole number.
// It was neither, which left an array lookup on a value of the sender's
// choosing and a suppressed diagnostic in front of it.
//
// In all four the packet is now built from the fields that were read, so what
// the server checked and what it sends are the same thing.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "the movement and hat commands declare their fields\n";

$ingame = file_get_contents(REPO . '/functions/multi_fns/client/ingame.php');
$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

function handler_body($src, $name)
{
    $start = strpos($src, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\nfunction ", $start);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

function method_body($src, $name)
{
    $start = strpos($src, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, ' function ', $start + 10);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

// --- each handler declares what its command takes -------------------------

$expected = array(
    'client_p' => 2,
    'client_exact_pos' => 2,
    'client_get_hat' => 1,
    'client_hat_to_start' => 1,
);

foreach ($expected as $handler => $count) {
    $body = handler_body($ingame, $handler);
    ok($body !== '', "$handler is present");
    ok(strpos($body, 'packet_fields') !== false, "$handler reads its packet against a declaration");

    // The declaration has to name exactly as many fields as the command takes.
    preg_match('/packet_fields\(\s*\$data\s*,\s*array\((.*?)\)\s*\)/s', $body, $m);
    ok(isset($m[1]), "$handler declares its fields inline");
    $declared = isset($m[1]) ? substr_count($m[1], '=>') : 0;
    is_same($declared, $count, "$handler declares $count field(s)");
}

// --- the kinds match what the room does with the value --------------------

// Movement is arithmetic, so the fields are numbers.
foreach (array('client_p', 'client_exact_pos') as $handler) {
    $body = handler_body($ingame, $handler);
    is_same(substr_count($body, "'num'"), 2, "$handler declares both of its fields as numbers");
    ok(strpos($body, "'uint'") === false, "$handler does not declare a movement as a whole number");
}

// A hat id is a key into the room's own array, so it is a whole number.
foreach (array('client_get_hat', 'client_hat_to_start') as $handler) {
    $body = handler_body($ingame, $handler);
    is_same(substr_count($body, "'uint'"), 1, "$handler declares its hat as a whole number");
}

// --- the room is handed values, not the packet ---------------------------

foreach (array('setPos', 'setExactPos') as $method) {
    $body = method_body($game, $method);
    ok($body !== '', "$method is present");
    ok(strpos($body, "explode('`'") === false, "$method does not take the packet apart again");
    ok(strpos($body, '$data') === false, "$method is handed values rather than the packet");
}

// And it builds what it sends out of those values.
$set_pos = method_body($game, 'setPos');
ok(
    strpos($set_pos, '$moved_x') !== false && strpos($set_pos, '$moved_y') !== false,
    'the movement packet is built from the fields that were read'
);

// The relay must not happen before the fields are known. With the parse moved
// out, there is nothing left in the room that could go out unread.
$exact = method_body($game, 'setExactPos');
ok(
    strpos($exact, 'sendToRoom') !== false,
    'the exact position is still relayed'
);

// --- the suppressed lookup is gone ---------------------------------------

$get_hat = method_body($game, 'getHat');
ok(
    strpos($get_hat, '@$this->loose_hat_array') === false,
    'the hat lookup no longer suppresses its own diagnostic'
);
ok(
    strpos($get_hat, 'isset($this->loose_hat_array[$hat_id])') !== false,
    'the hat lookup asks whether the hat is there'
);

$to_start = method_body($game, 'sendHatToStart');
ok(
    strpos($to_start, '$this->loose_hat_array[$hat_id] == null') === false,
    'returning a hat no longer reads a key it has not checked for'
);
ok(
    strpos($to_start, 'isset($this->loose_hat_array[$hat_id])') !== false,
    'returning a hat asks whether the hat is there'
);

t_done();
