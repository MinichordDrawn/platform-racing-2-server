<?php
// The observer writes a chain that its own reader accepts.
//
// Step 2 built the reading side and judged it against fixtures somebody else
// produced. This is the other half: the observer now writes, and what it
// writes is checked with the very procedure it uses to check its peers.
//
// That pairing is the point. A writer and a reader that disagree about a byte
// -- a trailing line feed, a key order, a hash taken over the wrong thing --
// produce an observer that accuses every peer of lying while believing itself
// honest. Running procedure C over this observer's own output is the cheapest
// way to catch that, and it is the same procedure every other member will run
// over the same bytes.
//
// It also exercises the things only a running observer can show: that the
// sequence is durable across a restart, that a restart continues the chain
// rather than resetting it, that the window prunes to five and no further, and
// that a deliberate stop is distinguishable from a death.
//
// Nothing here touches the fixtures. Every run gets a fresh tree in a
// temporary directory.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

echo "the observer writes a chain its own reader accepts\n";

function rm_tree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? rm_tree("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

// The fourteen mount points of SPEC 2, as bare directories. No heartbeat
// folders: a store root without one is how a reader concludes "never ran
// here", and the owner creates its own on first run.
function fresh_tree($root)
{
    rm_tree($root);
    $paths = array(
        'web', 'web/copy', 'web/halts',
        'multi', 'multi/copy', 'multi/halts',
        'policy', 'policy/copy', 'policy/halts',
        'super', 'super/halts',
        'super/copy-web', 'super/copy-multi', 'super/copy-policy',
    );
    foreach ($paths as $p) {
        mkdir("$root/$p", 0777, true);
    }
    return $root;
}

$params = array(
    'window'                      => 4,
    'cadence_seconds'             => 10,
    'stale_slack_cycles'          => 1,
    'staging_stale_after_seconds' => 60,
    'max_heartbeat_bytes'         => 8192,
    'max_fault_bytes'             => 8192,
    'max_halt_bytes'              => 8192,
    'heartbeat_max_age_seconds'   => 30,
    'now'                         => gmdate('Y-m-d\TH:i:s\Z'),
);

function new_state($stores, $params, $booted)
{
    return array(
        'stores' => $stores, 'identity' => 'web', 'params' => $params,
        'basis' => null, 'memory' => array(), 'since' => array(),
        'failing_set' => null, 'booted' => $booted,
        'started' => gmdate('Y-m-d\TH:i:s\Z'),
        'log' => false,
    );
}

function one_cycle(&$state, $stopping = false, $extra = array())
{
    $state['params']['now'] = gmdate('Y-m-d\TH:i:s\Z');
    $plan = \pr2obs\web\run_cycle($extra + array(
        'stores'          => $state['stores'],
        'identity'        => $state['identity'],
        'params'          => $state['params'],
        'basis'           => $state['basis'],
        'memory'          => $state['memory'],
        'booted'          => $state['booted'],
        'cadence_seconds' => $state['params']['cadence_seconds'],
    ));
    if ($stopping) {
        $plan['publish']['stop'] = true;
    }
    \pr2obs\web\apply_cycle($state, $plan);
    return $plan;
}

$stores = fresh_tree(sys_get_temp_dir() . '/pr2obs-write-' . getmypid());
$hb = "$stores/web/heartbeat";

// --- the first cycle -------------------------------------------------------

$state = new_state($stores, $params, true);
$first = one_cycle($state);

ok(is_dir($hb), 'the observer creates its own heartbeat folder');
ok(is_file("$hb/0000000001.hb"), 'and publishes sequence 1');

$one = json_decode(file_get_contents("$hb/0000000001.hb"), true);
is_same($one['sequence'], 1, 'the first sequence is 1');
is_same($one['previous'], null, 'with no previous link');
ok(is_array($one['boot']), 'and a boot record');
is_same($one['boot']['resumed_from'], null, 'resuming from nothing');
is_same($one['stop'], false, 'and not a stop');

// The file's bytes are what every reader hashes, so the shape of them matters.
$bytes = file_get_contents("$hb/0000000001.hb");
ok(strncmp($bytes, '{"kind":"heartbeat",', 20) === 0, 'the marker is the first bytes, exactly');
is_same(substr($bytes, -1), "\n", 'the file ends with one line feed');
is_same(substr($bytes, -2, 1) === "\n", false, 'and only one');

// A cold ring: every peer is absent, which is a fault, which raises a halt.
// This is the intended state of a ring that is not yet complete.
is_same($first['verdicts'], array('multi' => 'absent', 'policy' => 'absent', 'super' => 'absent'),
    'every peer is absent on a cold ring');
ok($first['halt']['writes'] === true, 'which halts, correctly');
ok(is_file("$stores/web/halt"), 'the halt is recorded in its own store');
ok(is_file("$stores/super/halts/web"), 'and delivered to the super store first');
ok(is_file("$stores/multi/halts/web"), 'and to each peer');
ok(is_file("$stores/policy/halts/web"), 'and to the other peer');
ok(!is_file("$stores/web/halts/web"), 'an observer does not deliver a halt to itself');

// --- a problem with a different observer is a halt, not a fault -----------
//
// Every finding on a cold ring is about somebody else: three peers with no
// heartbeat folder. The observer halts for all of them and declares itself
// perfectly well, because the fault file is where it says what is wrong with
// *itself*. If a dead peer made every member faulty, one failure would be
// recorded as three and the state needed to stand down would be written by
// the very condition that should lift it.
ok(
    !is_file("$stores/web/fault"),
    'no fault file, though three peers are missing and all of them halt'
);

// A local assertion is what writes one. The writability probe is a write, so
// the process that is allowed to write performs it and passes the answer in --
// which is also what lets the cycle that found the problem act on it, instead
// of the next cycle inheriting it.
$local_plan = one_cycle($state, false, array('own_store_writable' => false));

ok(is_file("$stores/web/fault"), 'a failing assertion about itself does write one');

$fault = json_decode(file_get_contents("$stores/web/fault"), true);
is_same(
    array_keys($fault),
    array('kind', 'version', 'observer', 'failing'),
    'the fault carries state and no history: no sequence, no timestamps'
);
is_same($fault['observer'], 'web', 'it names its author');

$in_file = array();
foreach ($fault['failing'] as $f) {
    $in_file[] = $f['check'];
}
sort($in_file);
is_same($in_file, array('own-store-writable'),
    'and lists only the local assertion, not the three absent peers');

// The halt still names the first finding overall, local or not, because the
// halt is the stop and it is about the whole system.
ok(
    count($local_plan['failing']) > 1,
    'the failing set is still complete -- the narrowing is the file, not the cycle'
);

// Clearing it again removes the file.
one_cycle($state);
ok(!is_file("$stores/web/fault"), 'and it is removed when the local assertion passes');

// --- the writability probe is invisible to peers --------------------------
//
// The probe writes a file into the observer's own heartbeat folder, which
// every peer reads and holds to a strict set of names. The first version
// called it `.writable.tmp`, and peers duly reported S1 -- an unexpected name,
// a compromise -- on the observer's own housekeeping. Every member halted.
//
// SPEC 13.3 says to probe with a *staging* file, and that is not decoration:
// a staging name is one of the two names the folder permits, so the probe is
// nothing at all to a reader, and one left behind by a crash is a stale
// staging file, which is a fault, which is the designed signal for a writer
// that died mid-publish.

foreach (glob(REPO . '/observers/*', GLOB_ONLYDIR) as $dir) {
    $name = basename($dir);
    $src = file_get_contents("$dir/procedures.php");
    $at = strpos($src, '$probe = ');
    ok($at !== false, "$name has a writability probe");
    if ($at !== false) {
        ok(
            strpos(substr($src, $at, 200), 'heartbeat_name(') !== false,
            "$name probes under a name built the way heartbeat names are built"
        );
    }
}

// And the two functions agree: what the writer names, the reader permits.
ok(
    \pr2obs\web\is_heartbeat_staging_name(\pr2obs\web\heartbeat_name(1) . '.tmp'),
    'a staging name built by the writer is one the reader permits'
);
ok(
    !\pr2obs\web\is_heartbeat_staging_name('.writable.tmp'),
    'and the name that caused the halt is not'
);
// --- several more cycles ---------------------------------------------------

for ($i = 0; $i < 6; $i++) {
    one_cycle($state);
}

$names = array();
foreach (scandir($hb) as $n) {
    if (preg_match('/^[0-9]{10}\.hb$/', $n)) {
        $names[] = $n;
    }
}
sort($names);

// SPEC 6: publishing happens before pruning, so the folder holds WINDOW + 1
// entries for the moment between the two. That transient is deliberate -- it
// guarantees a reader can always find the four most recent, which is what the
// chain test rests on. Once a cycle has finished, the folder holds exactly the
// window, and that is what is observable from outside a cycle.
is_same(count($names), 4, 'a finished cycle leaves exactly the window');
$top = (int) substr(end($names), 0, 10);
is_same($names[0], str_pad((string)($top - 3), 10, '0', STR_PAD_LEFT) . '.hb',
    'with everything below the window pruned');

// --- the reader accepts what the writer produced --------------------------

$R = new \pr2obs\web\Reader($stores, 'web', $params, null, array());
$R->others = array('multi', 'policy', 'super');
$c = \pr2obs\web\procedure_c($R, $hb, 'web');

is_same(count($c['entries']), 4, 'procedure C parses every entry');
$chain_findings = array();
foreach ($R->findings as $f) {
    $chain_findings[] = $f['check'] . ':' . $f['subject'];
}
is_same($chain_findings, array(), 'and finds nothing wrong with the chain it wrote');

// --- the copies match the original byte for byte --------------------------

foreach (array("$stores/multi/copy/heartbeat", "$stores/super/copy-web/heartbeat") as $copy) {
    ok(is_dir($copy), "the copy at $copy exists");
    $same = true;
    $found = 0;
    foreach (scandir($copy) as $n) {
        if (!preg_match('/^[0-9]{10}\.hb$/', $n)) {
            continue;
        }
        $found++;
        if (!is_file("$hb/$n") || file_get_contents("$copy/$n") !== file_get_contents("$hb/$n")) {
            $same = false;
        }
    }
    ok($found > 0 && $same, 'every copy entry is byte-identical to the original');
}

// --- a restart continues the chain, it does not reset it ------------------
//
// This is the rule that keeps a deploy from looking like a rollback. Without
// it, every restart would trip I3 and halt the server.

$before = end($names);
$restarted = new_state($stores, $params, true);
foreach (scandir($hb) as $n) {
    if (preg_match('/^[0-9]{10}\.hb$/', $n)) {
        $restarted['memory']['web/heartbeat/' . $n] = hash('sha256', file_get_contents("$hb/$n"));
    }
}
foreach (array('fault', 'halt') as $f) {
    if (is_file("$stores/web/$f")) {
        $restarted['memory']["web/$f"] = hash('sha256', file_get_contents("$stores/web/$f"));
    }
}
one_cycle($restarted);

$resumed = $top + 1;
$name_of = function ($n) { return str_pad((string)$n, 10, '0', STR_PAD_LEFT) . '.hb'; };
$eight = json_decode(file_get_contents("$hb/" . $name_of($resumed)), true);
is_same($eight['sequence'], $resumed, 'a restarted observer continues the numbering');
ok(is_array($eight['boot']), 'and marks the discontinuity with a boot record');
is_same($eight['boot']['resumed_from'], $top, 'naming the sequence it resumed from');
is_same($eight['previous'], hash('sha256', file_get_contents("$hb/" . $name_of($top))),
    'and links to the heartbeat that was there before it started');

$R2 = new \pr2obs\web\Reader($stores, 'web', $params, null, array());
$R2->others = array('multi', 'policy', 'super');
\pr2obs\web\procedure_c($R2, $hb, 'web');
$after_restart = array();
foreach ($R2->findings as $f) {
    $after_restart[] = $f['check'];
}
is_same($after_restart, array(), 'and the chain across the restart is still well formed');

// --- a clean stop is not a death ------------------------------------------

one_cycle($restarted, true);
$nine = json_decode(file_get_contents("$hb/" . $name_of($resumed + 1)), true);
is_same($nine['stop'], true, 'a deliberate stop is recorded in the final heartbeat');

$R3 = new \pr2obs\web\Reader($stores, 'web', $params, null, array());
$R3->others = array('multi', 'policy', 'super');
\pr2obs\web\procedure_c($R3, $hb, 'web');
$after_stop = array();
foreach ($R3->findings as $f) {
    $after_stop[] = $f['check'];
}
is_same($after_stop, array(), 'and a stop with nothing after it breaks no rule');

rm_tree($stores);

t_done();
