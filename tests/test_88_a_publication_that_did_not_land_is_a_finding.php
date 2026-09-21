
<?php
// A publication that did not land is a finding, not a log line.
//
// Every file an observer publishes is written to a staging name, renamed, and
// then read back, and the hash it computes is the hash of what came back. That
// re-read is not decoration: the re-read hash and the intended hash differ
// exactly when something went wrong, and the re-read hash is the one every
// other reader will compute.
//
// What happened on a difference was one line to a stream nothing reads, and a
// comment saying the next cycle's writability probe would turn it into a
// finding. It would not. The probe establishes that the store can be written,
// which it can, and says nothing about whether the bytes that came back are
// the bytes that went in. So an observer that published one thing and read
// back another carried on, chained the next heartbeat onto what it read, and
// nothing ever stopped.
//
// It is hard to think of a benign cause. The write succeeded, the rename
// succeeded, and the content is not what was written: that is the store
// disagreeing with the observer about what the observer just did, which is the
// condition the whole memory mechanism exists to detect one cycle later. There
// is no reading of it under which the right response is to continue quietly.
//
// The specification already said so, in two places: the writer's assertion
// `own-store-writable` fails when the re-read bytes differ from what was
// written, and the same identifier covers a store that cannot be written at
// all. The implementation logged instead. This is not a gap in the design; it
// is code that did not do what the design said, which is why it uses the
// identifier already specified rather than inventing a second one for a
// condition that has one.
//
// The observer still uses the re-read hash, because that is what peers will
// compute and holding the intended one would help nobody.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

echo "a publication that did not land is a finding\n";

// --- the identifier is one of this observer's own -------------------------

ok(
    in_array('own-store-writable', \pr2obs\web\local_check_identifiers(), true),
    'own-store-writable is a check this observer makes about its own world'
);
ok(
    \pr2obs\web\is_local_finding(array('check' => 'own-store-writable', 'subject' => null)),
    'so it is written into the fault file rather than halting anonymously'
);

// --- and it is published as a check that ran ------------------------------
//
// The heartbeat's list of checks is the observer's account of what it did. A
// check that can fail and is never mentioned is an account with a hole in it.

$cycle = file_get_contents(REPO . '/observers/web/cycle.php');
ok(
    strpos($cycle, "'own-store-writable'") !== false,
    'and the heartbeat already said it ran, because the identifier was always there'
);

// --- the difference is detected rather than logged ------------------------

$apply = file_get_contents(REPO . '/observers/web/apply.php');

$at = strpos($apply, 'publish-differs');
ok($at !== false, 'the moment is still recorded in the log');

// The comment that excused it is gone, because what it claimed was not true.
ok(
    strpos($apply, 'writability probe is what turns this into a finding') === false,
    'and no longer claims another check will turn it into a finding'
);

// The re-read hash is still what goes into memory. Using the intended hash
// would make this observer the only reader in the ring computing a value
// nobody else can see.
ok(
    preg_match('/\$state\[.memory.\]\[\$hb_rel\]\s*=\s*\$hash;/', $apply) === 1,
    'the hash the observer keeps is still the one every other reader will compute'
);

// --- it reaches the decision that halts -----------------------------------
//
// This is the half the first fix missed, and it missed it by one function
// boundary.
//
// `run_cycle` decides the halt from the findings it collected, and
// `apply_cycle` runs afterwards to carry that decision out. The finding was
// appended inside `apply_cycle`, to a `$plan` that arrives **by value**, after
// the halt had already been decided from a different array. The result was a
// fault file -- enough to stop the six gate readers -- with no halt file, no
// peer told, the clear branch still running in the same cycle, and the whole
// thing gone by the next one. A stop that lifts itself is not a stop.
//
// A publication is by its nature discovered after the cycle that planned it,
// so it cannot reach that cycle's decision at all. What it can do is reach the
// next one, through the one input the design already routes this way: the
// writability answer, which `run.php` obtains before the cycle precisely so
// that the cycle can act on it rather than the next one inheriting it.

$apply = file_get_contents(REPO . '/observers/web/apply.php');
$run   = file_get_contents(REPO . '/observers/web/run.php');
$cycle = file_get_contents(REPO . '/observers/web/cycle.php');

// The latch, set where the difference is found.
ok(
    preg_match('/\$state\[.publish_differed.\]\s*=\s*true\s*;/', $apply) === 1,
    'a publication that did not land is recorded in the state the observer keeps'
);
// And cleared by one that does land, or the observer could never start again.
ok(
    preg_match('/\$state\[.publish_differed.\]\s*=\s*false\s*;/', $apply) === 1,
    'and that record is cleared by a publication that does land'
);

// Not the old shape. `$plan` is by value here, and the halt is already decided.
ok(
    preg_match('/\$plan\[.findings.\]\[\]/', $apply) !== 1,
    'nothing is appended to the plan after the plan has been acted on'
);

// The next cycle reads it, before the cycle rather than during it.
$latch_at = strpos($run, 'publish_differed');
$cycle_at = strpos($run, 'run_cycle(array(');
ok($latch_at !== false, 'the next cycle reads that record');
ok(
    $latch_at !== false && $cycle_at !== false && $latch_at < $cycle_at,
    'and reads it before the cycle, so the cycle can act on it'
);
ok(
    preg_match(
        '/\$state\[.publish_differed.\]\s*\)\s*\)\s*\{\s*\$writable\s*=\s*false\s*;/',
        $run
    ) === 1,
    'and folds it into the writability answer the cycle already asks for'
);

// The fault file has to say which of the two happened, because one identifier
// covers both and a detail naming the wrong one is a false account.
ok(
    strpos($cycle, 'own_store_writable_detail') !== false,
    'the account says which of the two conditions failed'
);
ok(
    strpos($run, 'read back differently') !== false,
    'and the read-back case has words of its own'
);

// --- and it is a finding in the plan, which is what halts -----------------
//
// The behavioural half. Whatever else is wrong with the tree, the identifier
// appears among the findings `run_cycle` returns exactly when the writability
// answer is false -- and that array is what the halt is chosen from.

function t88_tree()
{
    $dir = sys_get_temp_dir() . '/pr2pub-' . getmypid() . '-' . mt_rand();
    foreach (array('web', 'multi', 'policy', 'super') as $m) {
        mkdir("$dir/$m/heartbeat", 0777, true);
        mkdir("$dir/$m/halts", 0777, true);
    }
    return $dir;
}

function t88_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t88_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

function t88_plan($writable)
{
    $dir = t88_tree();
    $plan = \pr2obs\web\run_cycle(array(
        'stores'   => $dir,
        'identity' => 'web',
        'params'   => array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ),
        'basis'   => null,
        'memory'  => array(),
        'booted'  => false,
        'cadence_seconds' => 6,
        'own_store_writable' => $writable,
        'own_store_writable_detail' => 'a published file read back differently from what was written',
    ));
    t88_rm($dir);

    $checks = array();
    foreach ($plan['findings'] as $f) {
        $checks[] = $f['check'];
    }
    return array($checks, $plan);
}

list($checks_bad, $plan_bad) = t88_plan(false);
list($checks_ok, $plan_ok)   = t88_plan(true);

ok(
    in_array('own-store-writable', $checks_bad, true),
    'a false writability answer is a finding in the plan the halt is chosen from'
);
ok(!in_array('own-store-writable', $checks_ok, true), 'and a true one is not');
ok($plan_bad['halt']['writes'] === true, 'and that plan halts');

// --- every observer, not only the one that was edited ---------------------

foreach (glob(REPO . '/observers/*/apply.php') as $file) {
    $name = basename(dirname($file));
    $src  = file_get_contents($file);
    ok(
        preg_match('/\$state\[.publish_differed.\]\s*=\s*true\s*;/', $src) === 1
        && preg_match('/\$plan\[.findings.\]\[\]/', $src) !== 1,
        "$name records it the same way"
    );
}

t_done();
