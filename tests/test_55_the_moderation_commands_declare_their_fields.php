<?php
// The moderation commands read the packets they act on.
//
// Every one of these takes a player's name and puts it into messages that go
// to the whole chat room, into the kick and mute registers, and into the
// moderator action log. The name was taken as the whole of the packet, or one
// piece of it, and never examined.
//
// A name is escaped for display before it goes into those messages, which
// deals with markup and does nothing about the field separator. The separator
// is what the receiving clients split on, so a name carrying one adds fields
// to a message the server wrote about a moderator's action.
//
// Declaring the fields refuses that without needing a rule about names: an
// added separator is an added field, and these commands know how many they
// take. The count for each comes from the client, which writes them.
//
// Four of them are also reached from chat commands, which build the same
// packet out of what was typed, so the same declaration covers both ways in.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "the moderation commands declare their fields\n";

$src = file_get_contents(REPO . '/functions/multi_fns/client/moderation.php');

function mod_body($src, $name)
{
    $start = strpos($src, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\nfunction ", $start);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

function mod_declared($body)
{
    if (!preg_match('/packet_fields\(\s*\$data\s*,\s*array\((.*?)\)\s*\)/s', $body, $m)) {
        return -1;
    }
    return substr_count($m[1], '=>');
}

// Field counts, each taken from how the client writes the command.
$commands = array(
    'client_view_priors' => 1,
    'client_kick' => 1,
    'client_unkick' => 1,
    'client_unmute' => 1,
    'client_demote_moderator' => 1,
    'client_warn' => 2,
    'client_promote_to_moderator' => 2,
    'client_ban' => 5,
);

foreach ($commands as $handler => $count) {
    $body = mod_body($src, $handler);
    ok($body !== '', "$handler is present");
    is_same(mod_declared($body), $count, "$handler declares $count field(s)");
}

// --- the name is a field, whatever else the command takes ----------------

foreach (array_keys($commands) as $handler) {
    $body = mod_body($src, $handler);
    ok(
        preg_match("/'(?:name|banned_name)'\s*=>\s*'text'/", $body) === 1,
        "$handler reads the name as a field of its own"
    );
}

// --- a handler must not take the packet under another name ---------------
//
// One of these took it as a parameter called something else, which is how it
// escaped notice. Every handler names the packet the same way now.
ok(
    preg_match('/function client_\w+\(\$socket,\s*\$(?!data)\w+\)/', $src) !== 1,
    'no handler in this file takes the packet under another name'
);

// --- the numbers are whole numbers ---------------------------------------

$warn = mod_body($src, 'client_warn');
ok(strpos($warn, "'uint'") !== false, 'the warning number is a whole number');

$ban = mod_body($src, 'client_ban');
is_same(substr_count($ban, "'uint'"), 2, 'the ban length and the ban id are whole numbers');

// The ban is still read from the row rather than from the packet.
ok(strpos($ban, 'ban_row_is_active') !== false, 'the ban is still read from the row');
ok(
    strpos($ban, 'ban_row_seconds_remaining') !== false,
    'the length announced is still the one the row records'
);

// --- a promotion has to be to a kind of moderator that exists -------------
//
// The kind was put straight into the message without being checked, and the
// reign it names was left unset for anything other than the three kinds, so a
// fourth kind produced a message about a moderator that does not exist.

ok(function_exists('moderator_kinds'), 'the kinds of moderator are a table');

if (function_exists('moderator_kinds')) {
    $kinds = moderator_kinds();
    is_same(
        array_keys($kinds),
        array('temporary', 'trial', 'permanent'),
        'the table names the three kinds the client offers'
    );
    foreach ($kinds as $kind => $reign) {
        ok(is_string($reign) && $reign !== '', "the $kind kind says how long it reigns");
    }
}

$promote = mod_body($src, 'client_promote_to_moderator');
ok(strpos($promote, 'moderator_kinds') !== false, 'a promotion asks the table');
ok(
    preg_match('/throw new Exception/', $promote) === 1,
    'a promotion to a kind that does not exist is refused'
);
ok(
    preg_match('/switch\s*\(\s*\$type\s*\)/', $promote) !== 1,
    'the reign is read from the table rather than chosen by a switch that may fall through'
);

t_done();
