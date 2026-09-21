<?php
// A fault while handling one packet must end that connection, not the server.
//
// The packet dispatcher caught Exception. A PHP Error is not an Exception, so
// anything raising one left the packet loop entirely and ended the process,
// taking every connected player with it. The server holds up to 200.
//
// One handler did exactly that on every call: client_award_kong_outfit called
// Player::awardKongOutfit, a method that does not exist anywhere in the tree.
// An undefined method raises Error, so any session could end the server with a
// single packet carrying no data at all.
//
// The handler is also redundant. The Kong parts are awarded on login, gated on
// a flag the login carries, and awardKongParts is the real method. Had the
// handler named it correctly it would have been worse than a crash: a player
// could have granted themselves the set on demand, with the login gate
// bypassed. So it goes rather than being repointed.

require_once __DIR__ . '/helper.php';

echo "a fault in one handler stays local\n";

$dispatch = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');

// The dispatcher has to catch more than Exception. It must keep catching
// Exception separately, though: handlers throw those deliberately to refuse a
// packet, and a refusal should not cost the player their connection.
ok(strpos($dispatch, 'catch (\\Exception') !== false, 'a refused packet is still caught on its own');
ok(strpos($dispatch, 'catch (\\Throwable') !== false, 'a fault is caught as well');

$refusal_at = strpos($dispatch, 'catch (\\Exception');
$fault_at = strpos($dispatch, 'catch (\\Throwable');
ok($refusal_at < $fault_at, 'the narrower catch comes first, or it would never be reached');

$refusal_body = substr($dispatch, $refusal_at, $fault_at - $refusal_at);
ok(
    strpos($refusal_body, 'close()') === false,
    'a refusal does not close the connection, which is what it did before'
);

// And it has to end that one connection rather than carry on with it in an
// unknown state.
$catch_at = strpos($dispatch, 'catch (\\Throwable');
ok($catch_at !== false, 'the catch is present');
if ($catch_at !== false) {
    $body = substr($dispatch, $catch_at, 900);
    ok(strpos($body, 'close()') !== false, 'the connection is closed');
    ok(strpos($body, 'onDisconnect()') !== false, 'the connection is released');
    ok(strpos($body, 'output') !== false, 'the fault is recorded');
}

// --- the handler that reached it ----------------------------------------

$lobby = file_get_contents(REPO . '/functions/multi_fns/client/lobby.php');

ok(strpos($lobby, 'awardKongOutfit') === false, 'the call to the method that does not exist is gone');
ok(strpos($lobby, 'client_award_kong_outfit') === false, 'the handler is gone rather than repointed');

// The award itself still happens where it is gated.
$player = file_get_contents(REPO . '/multiplayer_server/Player.php');
ok(strpos($player, 'function awardKongParts') !== false, 'the real method is untouched');
ok(strpos($player, '$login->login->award_kong') !== false, 'the login still awards the set when it should');

// Nothing else in the tree calls a method the class does not define, for the
// two names involved here.
$calls = array();
foreach (glob(REPO . '/functions/multi_fns/client/*.php') as $file) {
    $src = file_get_contents($file);
    if (preg_match_all('/\$player->([a-zA-Z_][a-zA-Z0-9_]*)\(/', $src, $m)) {
        foreach ($m[1] as $method) {
            if (strpos($player, 'function ' . $method . '(') === false) {
                $calls[] = basename($file) . ': ' . $method;
            }
        }
    }
}
is_same($calls, array(), 'no client handler calls a Player method that is not defined');

t_done();
