<?php
// Things a player collects during a race must be bounded by what the race
// actually contains.
//
// Eggs: the egg count is the entire placement key in egg mode, and placement
// decides the prize, the guild points and the exp the other racers get. The
// handler incremented it with no upper bound, no test that the named egg
// exists, and no test that it had not already been taken, so one player could
// hold any number. The server announces how many eggs a race has, so the
// ceiling is one it set itself. The identifier also went straight into the
// name of a packet the other players receive, so it has to be an egg number
// and nothing else.
//
// Lives: in deathmatch, lives are what stand between a player and elimination,
// and elimination order is the placement. The handler incremented with no
// ceiling at all. The server does not model heart blocks, so it cannot say
// whether a particular heart was really collected; the ceiling bounds the
// claim rather than verifying it, and that limit is stated rather than
// implied.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "collected things are bounded\n";

// --- eggs ---------------------------------------------------------------

is_same(egg_count(), 10, 'the race has the number of eggs the server announces');

ok(valid_egg_id('0'), 'the first egg is a valid identifier');
ok(valid_egg_id('9'), 'the last egg is a valid identifier');
is_same(valid_egg_id('10'), false, 'an egg beyond the ones announced is refused');
is_same(valid_egg_id('99'), false, 'an egg well beyond them is refused');
is_same(valid_egg_id('-1'), false, 'a negative egg is refused');
is_same(valid_egg_id(''), false, 'no egg is refused');
is_same(valid_egg_id('3.5'), false, 'a fractional egg is refused');
is_same(valid_egg_id('2`addEggs'), false, 'an identifier carrying the field delimiter is refused');
is_same(valid_egg_id('abc'), false, 'a word is refused');

// --- lives --------------------------------------------------------------

is_same(max_lives(), 25, 'the ceiling on lives is stated');

$GLOBALS['MAX_LIVES'] = 5;
is_same(max_lives(), 5, 'a deployment can set the ceiling');
$GLOBALS['MAX_LIVES'] = 0;
is_same(max_lives(), 25, 'a zero ceiling falls back rather than stopping hearts working');
$GLOBALS['MAX_LIVES'] = -3;
is_same(max_lives(), 25, 'a negative ceiling falls back');
unset($GLOBALS['MAX_LIVES']);

ok(max_lives() > 3, 'the ceiling is above the number a race starts with, so hearts still do something');

// --- the game has to apply them -----------------------------------------

$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

ok(strpos($game, 'valid_egg_id') !== false, 'the egg identifier is checked');
ok(strpos($game, 'egg_count()') !== false, 'the egg total comes from one place');
ok(
    preg_match("/sendToAll\('addEggs`'\s*\.\s*egg_count\(\)\)/", $game) === 1
    || preg_match("/addEggs`\"\s*\.\s*egg_count\(\)/", $game) === 1
    || strpos($game, "'addEggs`' . egg_count()") !== false,
    'the number announced and the number allowed are the same number'
);
ok(strpos($game, 'eggs_taken') !== false, 'the same egg cannot be taken twice');

$ingame = file_get_contents(REPO . '/functions/multi_fns/client/ingame.php');
ok(strpos($ingame, 'max_lives()') !== false, 'the heart handler applies the ceiling');

// The increment itself stays; what matters is that the ceiling is tested
// before it, and that reaching the ceiling stops there rather than carrying on
// to broadcast a heart that was not granted.
$heart_at = strpos($ingame, 'function client_heart');
$heart_body = substr($ingame, $heart_at, 800);
$ceiling_at = strpos($heart_body, 'max_lives()');
$increment_at = strpos($heart_body, '$player->lives++');
$broadcast_at = strpos($heart_body, 'broadcastHeart');
ok($ceiling_at !== false && $increment_at !== false, 'the handler both tests and increments');
ok($ceiling_at < $increment_at, 'the ceiling is tested before lives are added to');
ok($ceiling_at < $broadcast_at, 'a heart that is not granted is not broadcast either');

// The stored egg count must be capped too, not just the identifier.
$grab_at = strpos($game, 'function grabEgg');
$grab_body = substr($game, $grab_at, 1400);
ok(strpos($grab_body, 'egg_count()') !== false, 'the running total is held to the number announced');

t_done();
