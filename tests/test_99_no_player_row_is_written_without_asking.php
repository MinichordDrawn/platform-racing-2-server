<?php
// No player row is written without asking the observer network.
//
// The game server's gate is the socket daemon's timer: once a tick, it reads
// the store and sets a flag the work inside that tick honours. Everything that
// happens *inside* a tick is therefore covered, and two things do not happen
// inside a tick.
//
// The first is the crash path. A fatal error anywhere in a tick ends the
// process and PHP runs the shutdown function registered at startup, which
// calls `shutdown_server`, which walks every connected player calling
// `remove()` -- and `remove()` saves. That path is below the last tick, so no
// gate read stands between the error and six database writes per connected
// player. The moment the database misbehaves badly enough to end the process
// is the moment the process writes to it hardest, and a halt landing between
// the throw and the shutdown function is never seen, because there will be no
// further tick.
//
// The second is any disconnect. `remove()` is called from a dozen places, one
// of them the socket layer's own disconnect handling, and a socket can close
// at any moment -- including while the ring says stop, when the read path is
// already discarding everything that arrives.
//
// So the check goes where the writing is. `saveInfo()` is the one function
// that writes a player's row, and it asks before it does. That is the same
// rule the rest of the deployment follows, applied at the only place on this
// path that a tick does not cover.

require_once __DIR__ . '/helper.php';

echo "no player row is written without asking\n";

$player = file_get_contents(REPO . '/multiplayer_server/Player.php');

// --- the writes are where we think they are -------------------------------

$save_at = strpos($player, 'public function saveInfo()');
ok($save_at !== false, 'Player has a saveInfo');

$save_body = substr($player, $save_at, strpos($player, "\n    }", $save_at) - $save_at);
ok(substr_count($save_body, 'db_op(') >= 5,
    'and it is what writes the player row, several times over');

// --- and it asks first ----------------------------------------------------

$gate_at = strpos($save_body, 'observer_gate_reason(');
ok($gate_at !== false, 'saveInfo asks the observer network');

$first_write = strpos($save_body, 'db_op(');
ok($gate_at !== false && $first_write !== false && $gate_at < $first_write,
    'and asks before the first write, not after it');

// A refusal returns rather than carrying on. An environment the gate cannot
// read is a refusal too, which is the fail-closed direction.
ok(preg_match('/observer_gate_settings\(\)/', $save_body) === 1,
    'and reads its settings, so an unreadable environment refuses as well');
ok(preg_match('/if\s*\(\s*\$halted\s*!==\s*null\s*\)\s*\{[\s\S]{0,200}?return false;/', $save_body) === 1,
    'and a halted ring means it writes nothing');

// --- the paths that reach it are still the paths we think ------------------
//
// Named so that a future change which routes around saveInfo, rather than
// through it, fails here rather than silently.

$utils = file_get_contents(REPO . '/functions/multi_fns/utils.php');
ok(strpos($utils, 'function __crashHandler') !== false, 'the crash handler still exists');
ok(preg_match('/function __crashHandler.*?shutdown_server\s*\(/s', $utils) === 1,
    'and still shuts the server down');
ok(preg_match('/function shutdown_server.*?\$player->remove\(\)/s', $utils) === 1,
    'and the shutdown still removes every connected player');

$remove_at = strpos($player, 'public function remove(');
$remove_body = $remove_at === false
    ? ''
    : substr($player, $remove_at, strpos($player, "\n    }", $remove_at) - $remove_at);
ok(strpos($remove_body, '$this->saveInfo()') !== false,
    'and removing a player still goes through saveInfo, which is what asks');

t_done();
