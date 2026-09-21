<?php
// Where a player is must be what the server has been tracking, not what the
// packet that depends on it says.
//
// Squash: the server keeps each player's position from their movement packets.
// The squash handler overwrote the attacker's own position with coordinates
// out of the squash packet, and then worked out whether the target was inside
// a box around the attacker. The box was therefore measured against a position
// the sender named in the same packet that asked for the squash, which is no
// test at all. The box itself is a real bound; it was just being applied to a
// number the sender chose.
//
// Resume: a connection dropping does not destroy the player. The object
// survives the grace window, so the server still holds where they were. The
// resume payload's own coordinates were written over that and then broadcast
// to everyone in the race, which is a way to arrive anywhere. The ordinary
// movement packets resync the position immediately afterwards, so there is
// nothing to take from the payload.
//
// The resume payload's race match also passed when the fields it compares were
// simply left out.

require_once __DIR__ . '/helper.php';

echo "positions come from what the server tracked\n";

$game = file_get_contents(REPO . '/multiplayer_server/rooms/Game.php');

// --- squash -------------------------------------------------------------

$squash_at = strpos($game, 'public function squash(');
ok($squash_at !== false, 'the squash handler is present');
$squash = substr($game, $squash_at, 1200);

ok(
    preg_match('/\$player->pos_x\s*=/', $squash) !== 1,
    'the attacker\'s own position is not written from the squash packet'
);
ok(
    preg_match('/\$player->pos_y\s*=/', $squash) !== 1,
    'nor the other coordinate'
);

// The check it protects has to still be there.
ok(strpos($squash, 'wearingHat') !== false, 'the hat requirement still stands');
ok(strpos($squash, '$player->pos_y + 105') !== false, 'the box is still measured');
ok(strpos($squash, 'idToPlayer') !== false, 'the target is still resolved');

// --- the resume race match ----------------------------------------------

$match_at = strpos($game, 'function resumePayloadMatchesRace');
ok($match_at !== false, 'the race match is present');
$match = substr($game, $match_at, 900);

// Leaving a field out must not be a way to pass the comparison.
ok(
    preg_match('/if\s*\(\s*isset\(\$payload->course_id\)\s*&&/', $match) !== 1,
    'the course is compared whether or not the payload mentions it'
);
ok(
    preg_match('/if\s*\(\s*isset\(\$payload->level_version\)\s*&&/', $match) !== 1,
    'the level version is compared whether or not the payload mentions it'
);
ok(strpos($match, '!isset($payload->course_id)') !== false, 'a payload with no course is refused');
ok(strpos($match, '!isset($payload->level_version)') !== false, 'a payload with no level version is refused');

// A payload that is not an object at all must not pass either.
ok(
    preg_match('/!\(\$payload instanceof \\\\stdClass\)\s*\)\s*\{\s*return false/', $match) === 1,
    'something that is not a payload is refused rather than accepted'
);

// --- the resume position ------------------------------------------------

$apply_at = strpos($game, 'function applyLocalResumeState');
ok($apply_at !== false, 'the resume state application is present');
$apply = substr($game, $apply_at, 1200);

ok(
    preg_match('/\$player->pos_x\s*=\s*\(int\)\s*\$local->x/', $apply) !== 1,
    'the resuming player does not bring their own position with them'
);
ok(
    preg_match('/\$player->pos_y\s*=\s*\(int\)\s*\$local->y/', $apply) !== 1,
    'nor the other coordinate'
);

t_done();
