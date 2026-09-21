
<?php
// A port is checked before it is handed to a player.
//
// The game server now binds a port the deployment declares, rather than one a
// database row supplies. The row still carries a port, and the row is what the
// web tier hands to clients: the status file the game reads to list servers,
// and the login reply that tells a player where to connect.
//
// So the two can disagree, and nothing noticed. A row carrying anything at all
// was cast to an integer and passed on, which means a client could be sent to
// port 0, or to a port nothing in this deployment ever binds, and told that
// was where the game is. Every client then fails to connect, the servers look
// down to players and fine to the server, and no observer sees a thing: the
// game server is listening happily on the port it was told to bind.
//
// A port that cannot be vouched for is not handed out. The status file omits
// that server rather than advertising somewhere to fail, and the login refuses
// rather than sending a player there.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/common_fns.php';

echo "a port is checked before it is handed out\n";

// --- what counts as usable ------------------------------------------------
//
// Enumerated, because the interesting values are the ones that survive a cast
// to integer and mean nothing: a row holding an empty string, or a number with
// something after it, becomes a plausible-looking small integer.

$usable = array(1, 80, 9160, 65535, '1', '9160', '65535');
foreach ($usable as $v) {
    ok(is_usable_port($v), 'usable: ' . var_export($v, true));
}

$refused = array(
    0, -1, 65536, 99999,
    '0', '-1', '65536',
    '', ' ', 'abc', '9160abc', '0x1f90', '91 60', '9160.0', '+9160',
    null, false, true, array(), 9160.5,
);
foreach ($refused as $v) {
    ok(!is_usable_port($v), 'refused: ' . var_export($v, true));
}

// --- the status file does not advertise one it cannot vouch for -----------

$cron = file_get_contents(REPO . '/functions/cron/cron_fns.php');

ok(
    strpos($cron, 'is_usable_port(') !== false,
    'the status writer checks the port it is about to publish'
);

// Checked before the row becomes a published entry, not after.
$check_at = strpos($cron, 'is_usable_port(');
$assign_at = strpos($cron, '$display->port');
ok(
    $check_at !== false && $assign_at !== false && $check_at < $assign_at,
    'before it puts it in the file'
);

// And the response is to leave that server out, not to publish a corrected
// guess. A server whose row cannot be trusted is a server this deployment
// cannot direct anybody to.
ok(
    preg_match('/is_usable_port\([^)]*\)\s*\)\s*\{[^}]*continue;/s', $cron) === 1,
    'and omits that server rather than publishing a guess'
);

// --- and a login does not send a player somewhere it cannot vouch for -----

$login = file_get_contents(REPO . '/http_server/login.php');

ok(
    strpos($login, 'is_usable_port(') !== false,
    'the login checks the port before handing it to the player'
);
ok(
    preg_match('/is_usable_port\([^)]*\)\s*\)\s*\{[^}]*throw new Exception/s', $login) === 1,
    'and refuses rather than answering with it'
);

// It belongs with the checks already made about the server being reachable,
// which means before the account work rather than after it: a player refused
// for this reason should not first have their password checked.
$port_at  = strpos($login, 'is_usable_port(');
$guest_at = strpos($login, 'guest login');
ok(
    $port_at !== false && $guest_at !== false && $port_at < $guest_at,
    'and does it with the other checks about the server, before the account work'
);

// --- the helper is where both can reach it --------------------------------
//
// One definition, because two would be two things to keep in step, and the
// whole finding is about one fact stated in more than one place.

$common = file_get_contents(REPO . '/functions/common_fns.php');
ok(
    substr_count($common, 'function is_usable_port') === 1,
    'there is one definition of it'
);
foreach (array(
    'functions/cron/cron_fns.php' => 'the cron path',
    'http_server/login.php'       => 'the web path',
) as $file => $what) {
    ok(
        strpos(file_get_contents(REPO . '/' . $file), 'function is_usable_port') === false,
        "and $what uses that one rather than its own"
    );
}

t_done();
