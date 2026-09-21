<?php
// A peer's fault halts this observer. It does not make this observer faulty.
//
// This is the rule that keeps recovery possible at all. If seeing a fault were
// itself a fault, then one member's unreachable database would make every
// member faulty, and each of those faults would be a reason for the next
// member to be faulty in turn. The clear-condition is "no fault at the root of
// any store", so a ring where everyone faults because everyone else faults has
// no state left from which anyone can stand down. It would be a halt that
// nothing could lift, produced by a condition that had already passed.
//
// So: halt on fault, never fault on fault. A peer's fault gives this observer
// a verdict, blocks its recovery and spreads the halt -- and leaves its own
// fault file absent, because that file means "my own assertions are failing"
// and nothing else.
//
// The fixture set pins the decision (verdict-faulted, halt-relay,
// recovery-blocked-by-peer-fault all expect `failing: []`). This pins the
// consequence on disk, which the fixtures cannot: that no fault file appears.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

define('FIX', __DIR__ . '/fixtures');

echo "a peer's fault halts this observer, it does not fault it\n";

function t74_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t74_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

function t74_copy($src, $dst)
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$src/$e") ? t74_copy("$src/$e", "$dst/$e") : copy("$src/$e", "$dst/$e");
    }
}

function t74_run($stores, $manifest, $log = false)
{
    $folder = "$stores/web/heartbeat";
    $highest = null;
    foreach (scandir($folder) as $n) {
        if (preg_match('/^[0-9]{10}\.hb$/', $n) && ($highest === null || $n > $highest)) {
            $highest = $n;
        }
    }
    $o = json_decode(file_get_contents("$folder/$highest"), true);

    $params = $manifest['parameters'];
    $params['cadence_seconds'] = 10;
    $params['heartbeat_max_age_seconds'] = 30;

    $memory = array();
    foreach (scandir($folder) as $n) {
        if (preg_match('/^[0-9]{10}\.hb$/', $n)) {
            $memory["web/heartbeat/$n"] = hash('sha256', file_get_contents("$folder/$n"));
        }
    }
    foreach (array('fault', 'halt') as $f) {
        if (is_file("$stores/web/$f")) {
            $memory["web/$f"] = hash('sha256', file_get_contents("$stores/web/$f"));
        }
    }
    foreach (array('multi', 'policy', 'super') as $other) {
        if (is_file("$stores/$other/halts/web")) {
            $memory["$other/halts/web"] = hash('sha256', file_get_contents("$stores/$other/halts/web"));
        }
    }

    $plan = \pr2obs\web\run_cycle(array(
        'stores'          => $stores,
        'identity'        => 'web',
        'params'          => $params,
        'basis'           => array('observed' => $o['observed']),
        'memory'          => $memory,
        'booted'          => false,
        'cadence_seconds' => 10,
    ));

    $state = array(
        'stores' => $stores, 'identity' => 'web', 'params' => $params,
        'basis' => null, 'memory' => $memory, 'since' => array(),
        'failing_set' => null, 'booted' => false,
        'started' => '2026-09-20T10:00:00Z', 'deferred' => array(),
        'log' => $log,
    );
    \pr2obs\web\apply_cycle($state, $plan);
    return $plan;
}

// --- a peer is faulted, and a halt is already in the ring -----------------

$work = sys_get_temp_dir() . '/pr2obs-t74-' . getmypid();
t74_rm($work);
t74_copy(FIX . '/halt-relay', $work);
$stores = "$work/stores";
$manifest = json_decode(file_get_contents("$work/manifest.json"), true);

ok(is_file("$stores/policy/fault"), 'the fixture has a peer carrying a fault');
ok(!is_file("$stores/web/fault"), 'and this observer has none to begin with');

$plan = t74_run($stores, $manifest);

is_same($plan['verdicts']['policy'], 'faulted', "the peer's fault is seen, as a verdict");
is_same($plan['failing'], array(), 'and produces no failing assertion of this observer');
is_same($plan['halt']['writes'], false, 'so this observer raises no halt of its own');

// The consequence the fixtures cannot express: nothing on disk.
ok(
    !is_file("$stores/web/fault"),
    'no fault file is written -- that file means "my own assertions are failing"'
);
ok(
    !is_file("$stores/web/halt"),
    'and no own halt either -- that file means "I found a violation"'
);

// What it does instead: carries the halt onward, so the stop reaches members
// the finder could not reach.
foreach (array('multi', 'policy', 'super') as $peer) {
    ok(is_file("$stores/$peer/halts/web"), "the halt is relayed into $peer");
}
is_same(
    file_get_contents("$stores/multi/halts/web"),
    file_get_contents("$stores/web/halts/multi"),
    'relayed as found, so the finder\'s name and reason survive'
);

// --- and it blocks recovery while the fault stands ------------------------

is_same($plan['clears'], false, 'recovery is blocked while any member carries a fault');

// --- when the fault clears, this observer recovers ------------------------
//
// The point of not faulting on a fault: there is state left to recover from.

// Each scenario gets its own copy of the fixture and exactly one cycle. A
// fixture is a snapshot of one moment; running three cycles against it would
// mean reading peers that never advance, which correctly -- and irrelevantly
// -- reports them stale.
function t74_scenario($name, $remove, $seed = array())
{
    $dir = sys_get_temp_dir() . "/pr2obs-t74-$name-" . getmypid();
    t74_rm($dir);
    t74_copy(FIX . '/halt-relay', $dir);
    foreach ($remove as $rel) {
        @unlink("$dir/stores/$rel");
    }
    foreach ($seed as $rel => $bytes) {
        @mkdir(dirname("$dir/stores/$rel"), 0777, true);
        file_put_contents("$dir/stores/$rel", $bytes);
    }
    $m = json_decode(file_get_contents("$dir/manifest.json"), true);
    $plan = t74_run("$dir/stores", $m);
    return array($plan, $dir);
}

// The copy cycle is fixed: policy writes into web/copy. So the copy of
// policy's fault lives in *web's* store, not policy's.
//
// Clearing an original before its copy is the one case in this design where an
// observer raises a finding on account of another member's fault state. It is
// not a fault about the peer's condition: it is this observer's own assertion
// that a copy it reads is current with its original, which is the same
// category as "the heartbeat I watch advances". An author clears its own
// copies in the cycle it clears its fault, so in a running ring this is at
// most a sub-cycle transient.
list($mid, $mid_dir) = t74_scenario('midway', array('policy/fault'));
$mid_checks = array();
foreach ($mid['failing'] as $f) {
    $mid_checks[] = $f['check'] . ':' . $f['subject'];
}
is_same($mid_checks, array('copy-current:policy'),
    'an original cleared before its copy is a copy-current fault, not a peer fault');
t74_rm($mid_dir);

// Seed the halts this observer would have delivered on an earlier cycle, so
// that clearing has something to undo. Without them the clear is correct and
// proves nothing.
$earlier = file_get_contents("$stores/web/halts/multi");
list($plan2, $clear_dir) = t74_scenario(
    'cleared',
    array('policy/fault', 'web/copy/fault'),
    array(
        'web/halt'          => $earlier,
        'multi/halts/web'   => $earlier,
        'policy/halts/web'  => $earlier,
        'super/halts/web'   => $earlier,
    )
);
$work = $clear_dir;
$stores = "$clear_dir/stores";

is_same($plan2['verdicts']['policy'], 'alive', 'once the peer clears, it reads as alive');
is_same($plan2['failing'], array(), 'this observer still has nothing failing');

ok(is_array($plan2['clears']), 'and now it clears rather than relaying');
if (is_array($plan2['clears'])) {
    ok(count($plan2['clears']['removes']) > 0, 'removing the halts it had delivered');
    foreach ($plan2['clears']['removes'] as $rel) {
        $p = $work . '/' . $rel;
        ok(!is_file($p), "removed on disk: $rel");
    }
}

t74_rm($work);

t_done();
