<?php
// The ring is declared, not discovered.
//
// Membership was whatever directories happened to be under the store root.
// The store root is owned by the user the work runs as, so the work could
// create a directory there and its own observer would enumerate the result as
// a member of the ring. It could also remove one.
//
// Both directions are the same mistake, and the second is the one that
// matters. A position that appears is loud: it has no heartbeat folder, so it
// reads as a member that never ran, and the ring halts. A position that
// disappears is silent: discovery simply returns a shorter list, every
// remaining member agrees with itself, and nobody is watching the member that
// is no longer there. That is the retirement gap again by another route -- a
// member leaving the ring without anybody's assertion failing.
//
// The same deployment already stated its membership once, explicitly, in the
// gate that every runtime reads. Stating it in one place and discovering it in
// another means the two can disagree, and only the discovered one can be made
// to lie by something inside a container.
//
// So the ring names its members, the way the gate does. A directory that is
// not a member is not a member whatever it is called, and a member whose store
// is missing is a member whose store is missing rather than a member who was
// never there.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

echo "the ring is declared, not discovered\n";

function t86_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t86_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

// --- every observer names the same ring -----------------------------------
//
// Identical in all four, because it is one fact about the deployment rather
// than four opinions about it.

$declared = array();
foreach (glob(REPO . '/observers/*/store.php') as $file) {
    $name = basename(dirname($file));
    $src = str_replace("\r\n", "\n", file_get_contents($file));
    ok(
        preg_match('/const RING_MEMBERS = array\(([^)]*)\)/', $src, $m) === 1,
        "$name names the ring"
    );
    if (!isset($m[1])) {
        continue;
    }
    preg_match_all("/'([^']+)'/", $m[1], $mm);
    $members = $mm[1];
    sort($members, SORT_STRING);
    $declared[$name] = $members;
}

is_same(count($declared), 4, 'all four observers name it');
is_same(
    count(array_unique(array_map('serialize', $declared))),
    1,
    'and every one of them names the same ring'
);
is_same(
    reset($declared),
    array('multi', 'policy', 'super', 'web'),
    'which is the four members this deployment runs'
);

// --- and it is the same ring the gate reads -------------------------------
//
// The two are written in different files for different readers, and a
// deployment where they disagree is a deployment where one of them is wrong
// about who is watching.

require_once REPO . '/common/observer_gate.php';
$gate = observer_gate_members();
sort($gate, SORT_STRING);
is_same($gate, reset($declared), 'the gate and the ring agree about who is in it');

// --- a directory is not a member ------------------------------------------

function t86_tree($extra = null, $remove = null)
{
    $dir = sys_get_temp_dir() . '/pr2ring-' . getmypid() . '-' . mt_rand();
    foreach (array('web', 'multi', 'policy', 'super') as $m) {
        if ($m === $remove) {
            continue;
        }
        mkdir("$dir/$m/heartbeat", 0777, true);
        mkdir("$dir/$m/halts", 0777, true);
    }
    if ($extra !== null) {
        mkdir("$dir/$extra/heartbeat", 0777, true);
        mkdir("$dir/$extra/halts", 0777, true);
    }
    return $dir;
}

function t86_others($dir)
{
    $plan = \pr2obs\web\run_cycle(array(
        'stores'          => $dir,
        'identity'        => 'web',
        'params'          => array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ),
        'basis'           => null,
        'memory'          => array(),
        'booted'          => true,
        'cadence_seconds' => 6,
    ));
    $others = $plan['others'];
    sort($others, SORT_STRING);
    return $others;
}

$dir = t86_tree('phantom');
is_same(
    t86_others($dir),
    array('multi', 'policy', 'super'),
    'a directory placed under the store root is not a member of the ring'
);
t86_rm($dir);

// The name matters as little as the directory: something calling itself by a
// member's name in a place the deployment did not put it is still not that
// member, because the member list is not read from the tree at all.
$dir = t86_tree('super2');
is_same(
    t86_others($dir),
    array('multi', 'policy', 'super'),
    'and neither is one named to look like a member'
);
t86_rm($dir);

// --- a member whose store is gone is still a member ------------------------
//
// The silent direction, and the reason this change is worth making. Under
// discovery this produced a shorter list and no finding at all: the member
// left the ring and every remaining member agreed with itself about a ring
// that no longer held it.

$dir = t86_tree(null, 'policy');
is_same(
    t86_others($dir),
    array('multi', 'policy', 'super'),
    'a member whose store root is missing is still in the ring'
);

$plan = \pr2obs\web\run_cycle(array(
    'stores'          => $dir,
    'identity'        => 'web',
    'params'          => array(
        'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
        'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
        'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
        'heartbeat_max_age_seconds' => 30,
        'now' => gmdate('Y-m-d\TH:i:s\Z'),
    ),
    'basis'           => null,
    'memory'          => array(),
    'booted'          => true,
    'cadence_seconds' => 6,
));
$about_policy = array();
foreach ($plan['findings'] as $f) {
    if ($f['subject'] === 'policy') {
        $about_policy[] = $f['check'];
    }
}
ok(count($about_policy) > 0, 'and its absence is reported rather than enumerated away');
t86_rm($dir);

// --- nothing lists the store root any more --------------------------------
//
// Stated as a check because the old behaviour was one call, and one call
// putting it back would restore every case above at once.

$cycle = str_replace("\r\n", "\n", file_get_contents(REPO . '/observers/web/cycle.php'));
ok(
    preg_match('/list_dir\(\s*\$R->stores\s*\)/', $cycle) !== 1,
    'the cycle does not build its membership by listing the store root'
);

t_done();
