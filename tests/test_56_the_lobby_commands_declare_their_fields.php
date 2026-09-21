<?php
// The lobby commands read the packets they act on.
//
// Six of them name a player to follow, befriend or ignore. One asks about a
// player. One names a chat room. One carries a whole appearance.
//
// A name or a room name taken as the whole of the packet is whatever the
// sender wrote, separators included, and these go into packets other people
// receive: the room list every player is shown, and the reply about a player.
// Declaring one field refuses an added separator, because an added separator
// is an added field.
//
// The appearance is the longest packet any command here carries. The room
// took it apart into fifteen names in one assignment, which quietly leaves
// every name unset if fewer arrive, and ignores any that arrive past the
// fifteenth. The three stats are whole numbers, which is what the room clamps
// them to immediately afterwards. The colours are signed, because one of them
// means "leave this one alone".
//
// Three of the removals also read a player that may not have been found, and
// suppressed the diagnostic rather than testing for it.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "the lobby commands declare their fields\n";

$lobby = file_get_contents(REPO . '/functions/multi_fns/client/lobby.php');
$misc = file_get_contents(REPO . '/functions/multi_fns/client/client_misc_fns.php');
$all = $lobby . "\n" . $misc;

function lobby_body($src, $name)
{
    $start = strpos($src, "function $name(");
    if ($start === false) {
        return '';
    }
    $end = strpos($src, "\nfunction ", $start);
    return $end === false ? substr($src, $start) : substr($src, $start, $end - $start);
}

function lobby_declared($body)
{
    if (!preg_match('/packet_fields\(\s*\$data\s*,\s*array\((.*?)\)\s*\)/s', $body, $m)) {
        return -1;
    }
    return substr_count($m[1], '=>');
}

// --- one field, a name ----------------------------------------------------

$named = array(
    'client_follow_user',
    'client_unfollow_user',
    'client_add_friend',
    'client_remove_friend',
    'client_ignore_user',
    'client_unignore_user',
    'client_get_player_info',
);

foreach ($named as $handler) {
    $body = lobby_body($all, $handler);
    ok($body !== '', "$handler is present");
    is_same(lobby_declared($body), 1, "$handler declares one field");
    ok(
        preg_match("/'name'\s*=>\s*'text'/", $body) === 1,
        "$handler reads that field as a name"
    );
}

// --- one field, a room ----------------------------------------------------

$body = lobby_body($all, 'client_set_chat_room');
is_same(lobby_declared($body), 1, 'the chat room declares one field');
ok(strpos($body, 'is_obscene') !== false, 'and still refuses an unclean room name');

// --- fifteen fields, an appearance ---------------------------------------

$body = lobby_body($all, 'client_set_customize_info');
is_same(lobby_declared($body), 15, 'an appearance declares fifteen fields');

// The three stats are whole numbers. The room clamps them to between nothing
// and a hundred straight afterwards, so that range is not a guess.
is_same(substr_count($body, "'uint'"), 3, 'the three stats are whole numbers');

// The colours are signed, because one value means leave this colour alone.
ok(substr_count($body, "'num'") >= 8, 'the colours are signed numbers');

// --- a removal reads the player it found ---------------------------------
//
// These tested a variable that is always set instead of the one they had just
// looked up, then read a property off it with the diagnostic suppressed.

foreach (array('client_unfollow_user', 'client_remove_friend', 'client_unignore_user') as $handler) {
    $body = lobby_body($all, $handler);
    ok(strpos($body, '@array_search') === false, "$handler does not suppress its own diagnostic");
    ok(
        preg_match('/if\s*\(\s*isset\s*\(\s*\$player\s*\)\s*\)/', $body) !== 1,
        "$handler does not test a variable that is always set"
    );
}

t_done();
