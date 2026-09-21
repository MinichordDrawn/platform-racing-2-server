<?php
// The id that binds a socket connection to its login must not be guessable.
//
// A connection asks the game server for a login id and is told one, and the
// HTTP login then names that id so the game server knows which waiting
// connection the login belongs to. Nothing else ties the two together.
//
// The ids were handed out by a counter: 1, 2, 3. Anyone could obtain one, know
// what the next ones would be, and name somebody else's pending id in their
// own login, so that their account was registered onto a connection that was
// not theirs. The login blob that carries the id is encrypted with a fixed key
// and carries no authentication, so its contents are not a barrier to that.
//
// A miss also has to be refused rather than half handled: the id was looked up
// and the result used before anything checked it had been found.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "login ids are unguessable\n";

$GLOBALS['login_array'] = array();

$first = get_login_id();
$second = get_login_id();

ok(is_int($first), 'an id is a whole number');
ok($first >= 1, 'an id is positive');
ok($first <= 2147483647, 'an id fits in the signed 32 bit range it travels through');
ok($second !== $first + 1, 'the next id does not simply follow the last');

// Over many draws they must all differ and be spread out, not walk upwards.
$seen = array();
$ascending = 0;
$previous = null;
for ($i = 0; $i < 500; $i++) {
    $id = get_login_id();
    $seen[$id] = true;
    if ($previous !== null && $id > $previous) {
        $ascending++;
    }
    $previous = $id;
}
is_same(count($seen), 500, 'five hundred draws give five hundred different ids');
ok($ascending > 150 && $ascending < 350, 'the ids do not climb, they scatter');

// An id already in use is never handed out again.
$GLOBALS['login_array'] = array();
$taken = get_login_id();
$GLOBALS['login_array'][$taken] = 'a waiting connection';
$clashes = 0;
for ($i = 0; $i < 200; $i++) {
    if (get_login_id() === $taken) {
        $clashes++;
    }
}
is_same($clashes, 0, 'an id already waiting is never handed out again');

// --- an id that matches nothing is refused -------------------------------

$src = file_get_contents(REPO . '/functions/multi_fns/process_fns.php');

$lookup = strpos($src, '$login_array[$login_id]');
$guard = strpos($src, 'if (!isset($socket))');
$ban_check = strpos($src, 'ServerBans::remainingTime');

ok($guard !== false, 'a login id that matches no connection is checked for');
ok($guard !== false && $lookup !== false && $guard > $lookup, 'the check follows the lookup');
ok(
    $guard !== false && $ban_check !== false && $guard < $ban_check,
    'the check comes before the connection is used'
);

// And the caller is told, rather than left waiting for a reply that never
// comes.
if ($guard !== false) {
    $after = substr($src, $guard, 400);
    ok(strpos($after, 'success') !== false, 'the caller is told the login was not registered');
    ok(strpos($after, 'write') !== false, 'the answer is actually sent');
}

t_done();
