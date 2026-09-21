<?php
// A login must not report success when the game server never took the session.
//
// login.php handed the session to the game server, decoded whatever came back
// without testing that anything had, skipped its three writes when the decode
// produced nothing usable, and then returned success and a login token
// regardless. A player was told they were logged in and given a token for a
// session that did not exist: the game server had no record of them, their
// status and last address were never written, and no recent login was
// recorded.
//
// Every failure on the control path arrives here the same way, as a reply that
// did not come: the server being unreachable, a signature refused, a clock too
// far out, the channel at its connection limit, or the two sides running
// different versions.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/http_data_fns.php';

echo "a login needs a game session before it reports success\n";

function refused_session($reply)
{
    try {
        require_game_session($reply);
        return false;
    } catch (Exception $e) {
        return true;
    }
}

// talk_to_server returns false when it could not connect or nothing came back.
ok(refused_session(false), 'no connection is refused');
ok(refused_session(''), 'an empty reply is refused');
ok(refused_session('   '), 'a blank reply is refused');
ok(refused_session(null), 'a missing reply is refused');

// Something arrived, but it is not an answer.
ok(refused_session('this is not json'), 'an unreadable reply is refused');
ok(refused_session('"a string"'), 'a reply that is not an object is refused');
ok(refused_session('{}'), 'a reply that does not say either way is refused');
ok(refused_session('null'), 'a reply that decodes to nothing is refused');

// The game server turned the session down. It answers this way when it is at
// its player cap.
ok(refused_session('{"success":false}'), 'a refused session is refused');

// A session the game server took.
$ok = require_game_session('{"success":true}');
ok(is_object($ok), 'an accepted session returns the answer');
ok(isset($ok->success) && $ok->success === true, 'the answer says it succeeded');

// Real replies carry control characters, which is why the endpoint stripped
// them before decoding.
$withctrl = "{\"success\":true}\x04";
$ok2 = require_game_session($withctrl);
ok(is_object($ok2) && $ok2->success === true, 'control characters in the reply are tolerated');

// --- the endpoint has to use it ------------------------------------------

$src = file_get_contents(REPO . '/http_server/login.php');

ok(strpos($src, 'require_game_session') !== false, 'the login endpoint requires a session');

// The three writes must no longer sit behind a test that simply skips them.
ok(
    preg_match('/if\s*\(\s*\$result->success\s*\)/', $src) !== 1,
    'the writes are no longer conditional on a reply that may never have arrived'
);

// And the success reply must come after the session is required, not before.
$need_at = strpos($src, 'require_game_session');
$ok_at = strpos($src, '$ret->success = true');
ok($need_at !== false && $ok_at !== false && $need_at < $ok_at, 'success is reported only after a session exists');

// The writes still happen for a session that was taken.
ok(strpos($src, 'user_update_status') !== false, 'the status write is still there');
ok(strpos($src, 'recent_logins_insert') !== false, 'the recent login write is still there');

t_done();
