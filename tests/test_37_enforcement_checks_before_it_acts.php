<?php
// Enforcement must test authority before it changes anything.
//
// Three paths acted first and tested afterwards.
//
// A kick clears any kick the target already has, and only then asks whether
// the caller is allowed to kick them. A caller who fails that test has still
// cleared the kick, which is an unkick, and the dedicated unkick path refuses
// temp mods where the kick path does not. Warning works the same way with
// mutes.
//
// A demotion writes the target's stored power down to 1 before the branch that
// establishes they were ever a moderator. An account whose stored power is 0
// is written up to 1 by a demotion that then reports they are not a moderator,
// and the write has already happened.

require_once __DIR__ . '/helper.php';

echo "enforcement checks before it acts\n";

$mod = file_get_contents(REPO . '/functions/multi_fns/client/moderation.php');

// --- kicking ------------------------------------------------------------

$kick_gate = strpos($mod, '($kicked->group < 2 || $mod->server_owner)');
$kick_remove = strpos($mod, 'ServerBans::remove');
$kick_add = strpos($mod, 'ServerBans::add');

ok($kick_gate !== false, 'the kick path tests the caller against the target');
ok($kick_remove !== false && $kick_add !== false, 'the kick path both clears and applies');
ok($kick_remove > $kick_gate, 'an existing kick is cleared only after the test, not before it');
ok($kick_add > $kick_gate, 'the new kick is applied after the test');

// --- warning ------------------------------------------------------------

$warn_gate = strpos($mod, '($warned->group < 2 || $mod->server_owner)');
$warn_remove = strpos($mod, 'Mutes::remove');
$warn_add = strpos($mod, 'Mutes::add');

ok($warn_gate !== false, 'the warn path tests the caller against the target');
ok($warn_remove !== false && $warn_add !== false, 'the warn path both clears and applies');
ok($warn_remove > $warn_gate, 'an existing mute is cleared only after the test, not before it');
ok($warn_add > $warn_gate, 'the new mute is applied after the test');

// The unmute path refuses temp mods. Clearing a mute through the warn path
// must not be an easier route to the same thing, which it is not once the
// clearing sits behind the warn test.
ok(
    strpos($mod, '$mod->temp_mod === false') !== false,
    'the dedicated unmute and unkick paths still exclude temp mods'
);

// --- demotion -----------------------------------------------------------

$demod = file_get_contents(REPO . '/functions/multi_fns/staff/demod.php');

$write = strpos($demod, "db_op('user_update_power'");
$branch = strpos($demod, "((int) \$user_row->power >= 2)");
$not_a_mod = strpos($demod, "isn't a moderator");

ok($write !== false, 'the demotion writes the stored power');
ok($branch !== false, 'the demotion branches on what the target actually is');
ok($write > $branch, 'the stored power is written only inside the branch that knows a demotion is warranted');
ok($not_a_mod !== false && $write < $not_a_mod, 'the refusal is still reachable and comes after the write site');

// An account that is not a moderator must leave with the power it arrived
// with. The only write must sit under the test for power of 2 or more.
$after_branch = substr($demod, $branch, $not_a_mod - $branch);
is_same(
    substr_count($after_branch, "db_op('user_update_power'"),
    1,
    'there is exactly one stored power write and it is inside that branch'
);
is_same(
    substr_count($demod, "db_op('user_update_power'"),
    1,
    'the demotion writes the stored power in one place only'
);

t_done();
