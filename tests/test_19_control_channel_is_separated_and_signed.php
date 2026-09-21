<?php
// The control channel must not live on the port players reach, and each
// command on it must be signed.
//
// Today a player connection sends become_process with the password in clear
// text and the same connection flips into process mode, where commands carry
// no hash and no sequence check at all. The twenty process handlers cover
// shutdown, disconnecting any player, registering a login, granting parts and
// setting the campaign.
//
// Separating the listener is the structural half: the control client starts in
// process mode by construction, so there is no become step to reach, and the
// player client has no path into process mode. Signing is the other half: the
// key never travels, and a timestamp and nonce bound replay.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/manage_socket/control_auth.php';

echo "control channel: separated from players, and every command signed\n";

$PROCESS_KEY = 'a key that never goes on the wire';
$GLOBALS['PROCESS_KEY'] = $PROCESS_KEY;

// --- the structural half -------------------------------------------------

$misc = file_get_contents(REPO . '/functions/multi_fns/client/client_misc_fns.php');
ok(
    strpos($misc, 'function client_become_process') === false,
    'a player connection has no way to become the process role'
);

$control = REPO . '/multiplayer_server/PR2ControlClient.php';
ok(is_file($control), 'a separate client class exists for the control channel');
$control_src = is_file($control) ? file_get_contents($control) : '';
ok(
    preg_match('/\$process\s*=\s*true/', $control_src) === 1,
    'the control client is in process mode by construction'
);

$player_src = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');
ok(
    preg_match('/public\s+\$process\s*=\s*false\s*;/', $player_src) === 1,
    'the player client still starts outside process mode'
);

$boot = file_get_contents(REPO . '/multiplayer_server/pr2.php');
ok(
    substr_count($boot, 'createServer') === 2 && strpos($boot, 'PR2ControlClient') !== false,
    'the server listens for control separately from players'
);

// --- the signing half ----------------------------------------------------

// every command is addressed to a server, and this process stands in for it
$GLOBALS['server_id'] = 4;
$to = 4;

$now = time();
$sig = control_sign($now, 'nonce-1', $to, 'shut_down', '');
ok(control_verify($sig, $now, 'nonce-1', $to, 'shut_down', ''), 'a well formed command is accepted');

is_same(control_verify($sig, $now, 'nonce-2', $to, 'set_campaign', ''), false, 'a changed command is refused');
is_same(control_verify($sig, $now, 'nonce-3', $to, 'shut_down', 'extra'), false, 'changed data is refused');
is_same(
    control_verify('0' . substr($sig, 1), $now, 'nonce-4', $to, 'shut_down', ''),
    false,
    'a wrong signature is refused'
);

$old = $now - 600;
is_same(
    control_verify(control_sign($old, 'n5', $to, 'shut_down', ''), $old, 'n5', $to, 'shut_down', ''),
    false,
    'a stale command is refused'
);

$ahead = $now + 600;
is_same(
    control_verify(control_sign($ahead, 'n6', $to, 'shut_down', ''), $ahead, 'n6', $to, 'shut_down', ''),
    false,
    'a command from the future is refused'
);

// the first use of a nonce is accepted above; the second must not be
is_same(control_verify($sig, $now, 'nonce-1', $to, 'shut_down', ''), false, 'a replayed command is refused');

t_done();
