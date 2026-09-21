<?php
// A server at its player cap must answer that it did not take the session.
//
// The capacity test ran inside the Player constructor, after the object had
// been built and after the socket had been pointed at it. On a full server it
// wrote a refusal and called remove(). But the caller already held the object,
// and its verdict was whether that variable held anything, so the refusal
// changed nothing the caller could see: the login was reported as successful,
// the connection was told loginSuccessful and given a rank, and the HTTP side
// issued a login token. The player was in none of this server's arrays, so
// nothing that walks them reached the account at all.
//
// The decision is now a function of the population, the account's power and
// the cap, which is settled before a Player exists. Two positions read it: the
// caller, which refuses and says so, and the constructor, which throws because
// reaching it means the first one did not run.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "a full server refuses the login rather than reporting success\n";

// --- the decision itself -------------------------------------------------

ok(function_exists('server_has_room'), 'the capacity decision is a function that can be asked');

if (function_exists('server_has_room')) {
    $cap = 200;

    // A guest is held back ten places short, so the last of them go to accounts.
    is_same(server_has_room(189, 0, $cap), true, 'a guest is admitted below the guest bound');
    is_same(server_has_room(190, 0, $cap), true, 'a guest is admitted at the guest bound');
    is_same(server_has_room(191, 0, $cap), false, 'a guest is refused past the guest bound');

    // A member has the whole cap.
    is_same(server_has_room(191, 1, $cap), true, 'a member is admitted where a guest is not');
    is_same(server_has_room(200, 1, $cap), true, 'a member is admitted at the cap');
    is_same(server_has_room(201, 1, $cap), false, 'a member is refused past the cap');

    // Staff are admitted whatever the population, which is how a full server
    // can still be moderated.
    is_same(server_has_room(201, 2, $cap), true, 'a moderator is admitted on a full server');
    is_same(server_has_room(5000, 3, $cap), true, 'an administrator is admitted on a full server');

    // The values reaching it are a count and a stored power, and neither is
    // guaranteed to arrive as an integer.
    is_same(server_has_room('201', '1', '200'), false, 'a member is refused past the cap as strings');
    is_same(server_has_room('190', '0', '200'), true, 'a guest is admitted at the bound as strings');
}

// --- the caller settles it before it builds anything ----------------------

$proc = file_get_contents(REPO . '/functions/multi_fns/process_fns.php');

$start = strpos($proc, 'function process_register_login');
ok($start !== false, 'the login registration is present');
$body = substr($proc, $start);
$end = strpos($body, "\nfunction ");
$body = $end === false ? $body : substr($body, 0, $end);

ok(strpos($body, 'server_has_room') !== false, 'the registration asks whether there is room');

// The cap has to be in scope for it to be asked about.
ok(
    preg_match('/global [^;]*\$max_players/', $body) === 1,
    'the registration has the cap in scope'
);

// The test has to come before the object, or the object is what gets tested.
$asked_at = strpos($body, 'server_has_room');
$built_at = strrpos($body, 'new \\pr2\\multi\\Player');
ok(
    $asked_at !== false && $built_at !== false && $asked_at < $built_at,
    'the room is settled before a player is built'
);

// A refused login has to be reported as refused. The verdict reads $player, so
// the refusing branch must leave it alone. Isolate that branch: it runs from
// the question to the else that follows it.
$branch_start = strpos($body, 'server_has_room');
$branch_end = strpos($body, '} else {', $branch_start);
$branch = $branch_start === false || $branch_end === false
    ? ''
    : substr($body, $branch_start, $branch_end - $branch_start);

ok($branch !== '', 'the refusing branch can be isolated');
ok(
    $branch !== '' && strpos($branch, '$player =') === false,
    'the refusing branch sets no player'
);

ok(
    strpos($body, 'this server is full') !== false,
    'the refusing branch says why'
);

// --- the constructor is the second position -------------------------------

$player = file_get_contents(REPO . '/multiplayer_server/Player.php');

$ctor = strpos($player, 'public function __construct');
ok($ctor !== false, 'the constructor is present');
$ctor_end = strpos($player, 'public function getInfo', $ctor);
$ctor_body = substr($player, $ctor, $ctor_end - $ctor);

ok(strpos($ctor_body, 'server_has_room') !== false, 'the constructor asks the same question');

ok(
    preg_match('/if\s*\(\s*!\s*server_has_room\(.*?\)\s*\{\s*throw new \\\\Exception/s', $ctor_body) === 1,
    'the constructor throws rather than carrying on'
);

// And it must not do anything else with that answer. A refusal that also
// carried on would be the shape this replaces.
$ctor_ask = strpos($ctor_body, 'server_has_room');
$ctor_after = substr($ctor_body, $ctor_ask, 400);
ok(
    strpos($ctor_after, 'remove()') === false,
    'the constructor does not try to unpick a player it declined to make'
);

// It has to refuse before it hands the socket a reference to a player that is
// not going to be registered.
$asked_at = strpos($ctor_body, 'server_has_room');
$linked_at = strpos($ctor_body, '$socket->player = $this');
ok(
    $asked_at !== false && $linked_at !== false && $asked_at < $linked_at,
    'the constructor refuses before the socket points at the player'
);

// The old shape must be gone: a refusal that only wrote a message and removed
// itself, and a registration that happened in the other half of that branch.
is_same(
    strpos($ctor_body, 'Sorry, this server is full'),
    false,
    'the constructor no longer writes a refusal it cannot enforce'
);

ok(
    preg_match('/\$player_array\[\$this->user_id\]\s*=\s*\$this\s*;/', $ctor_body) === 1,
    'a constructed player is registered'
);

ok(
    preg_match('/\}\s*else\s*\{\s*\/\/ add to the player array/', $ctor_body) !== 1,
    'registration is not the other half of a capacity branch'
);

// The session bootstrap ran behind a test of whether registration had happened.
// With registration unconditional that test decides nothing, and a gate that
// decides nothing reads as protection that is not there.
ok(
    preg_match('/if\s*\(\s*isset\s*\(\s*\$player_array\[\$this->user_id\]\s*\)\s*\)/', $ctor_body) !== 1,
    'the bootstrap no longer sits behind a test that is always true'
);

t_done();
