<?php
// A member that stops is still a member that is gone.
//
// The stop record exists so that a deliberate shutdown does not read as a
// death: a stale heartbeat saying "running" is a member that died, and one
// saying "stopped" is a member that left on purpose. That distinction is for
// whoever reads the stores afterwards, and it was allowed to do something it
// was never meant to do -- excuse the member's absence from the ring's
// judgement. A peer marked `retired` failed nobody's assertion unless it was
// the super observer.
//
// What that opens is not a host-level attack. The observer and the work share
// a container and a user (`docker/docker-compose.yml`, `user: www-data` in all
// three application containers), so the work can signal the observer beside
// it, and the observer catches the signal and publishes exactly the record
// that tells every peer to stop caring. A compromised container could
// therefore retire its own observer and go unobserved while all three peers
// reported the ring healthy: no halt, no fault, and the one member positioned
// to notice the compromise gone by its own hand.
//
// The rule that closes it is one line of reasoning. **Retirement changes the
// reason, not the verdict.** A member that has stopped is not verifying the
// stores, which is the same fact about the ring as a member that has died, and
// the ring cares about the fact. The record still says which of the two it
// was, because that is what it is for.
//
// The cost is that stopping an observer on purpose now stops the work it was
// watching, and that is the correct price: an observer shut down deliberately
// leaves a container unobserved, and this design does not let work continue
// unobserved for any reason. The halt lifts by itself when the member comes
// back.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

echo "a member that stops is still a member that is gone\n";

function t84_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t84_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

function t84_hb($observer, $seq, $stop = false, $previous = null)
{
    return \pr2obs\web\heartbeat_bytes(array(
        'observer'        => $observer,
        'sequence'        => $seq,
        'timestamp'       => '2026-09-20T10:00:00Z',
        'cadence_seconds' => 6,
        'checks'          => array('own-store-writable'),
        'observed'        => array(),
        'previous'        => $previous,
        'boot'            => null,
        'stop'            => $stop,
    ));
}

// A store where $who's chain ends in a heartbeat carrying the stop record, and
// every other member is running normally.
function t84_tree($who)
{
    $dir = sys_get_temp_dir() . '/pr2stop-' . getmypid() . '-' . mt_rand();
    foreach (array('web', 'multi', 'policy', 'super') as $m) {
        mkdir("$dir/$m/heartbeat", 0777, true);
        mkdir("$dir/$m/halts", 0777, true);
        $prev = null;
        for ($n = 1; $n <= 4; $n++) {
            $stop = ($m === $who && $n === 4);
            $bytes = t84_hb($m, $n, $stop, $prev);
            file_put_contents(sprintf('%s/%s/heartbeat/%010d.hb', $dir, $m, $n), $bytes);
            $prev = hash('sha256', $bytes);
        }
    }
    return $dir;
}

function t84_read($dir, $who)
{
    $R = new \pr2obs\web\Reader(
        $dir,
        'web',
        array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 3600,
            'now' => '2026-09-20T10:00:01Z',
        ),
        null,
        array()
    );
    $R->others = array('multi', 'policy', 'super');
    $entries = \pr2obs\web\procedure_c($R, "$dir/web/heartbeat", 'web');
    return array($R, \pr2obs\web\procedure_v($R, $who, $entries['entries'], 6));
}

function t84_failing($R, $who)
{
    $out = array();
    foreach ($R->findings as $f) {
        if ($f['subject'] === $who) {
            $out[] = $f['check'];
        }
    }
    sort($out);
    return array_values(array_unique($out));
}

// --- every member, not only the super observer ----------------------------
//
// Enumerated rather than sampled. The old rule named one member by hand, and
// the three it did not name are exactly the three that have work in their
// container for a compromise to start from.

foreach (array('multi', 'policy', 'super') as $who) {
    $dir = t84_tree($who);
    list($R, $v) = t84_read($dir, $who);

    is_same($v['verdict'], 'retired', "$who that has stopped still reads as retired");
    is_same(
        t84_failing($R, $who),
        array('member-fresh'),
        "and a stopped $who is a member that is not verifying the stores"
    );
    t84_rm($dir);
}

// --- the record still says which it was -----------------------------------
//
// The whole point of the stop record is that whoever reads the stores
// afterwards can tell a deliberate shutdown from a death. The verdict carries
// that; the finding carries the consequence. Losing the distinction would be
// the wrong fix.

$dir = t84_tree('multi');
list($R, $v) = t84_read($dir, 'multi');
is_same($v['verdict'], 'retired', 'the verdict distinguishes a stop from a death');
$detail = '';
foreach ($R->findings as $f) {
    if ($f['subject'] === 'multi') {
        $detail = $f['detail'];
    }
}
ok(strpos($detail, 'stopped') !== false, 'and the detail says the member stopped');
t84_rm($dir);

// --- a member that is running is untouched --------------------------------

// A reader with no basis cannot yet say a member is alive -- that is the
// designed answer to "I have nothing to compare against" and it is decided
// after retirement, which needs no basis at all. What matters here is that a
// member which has not stopped is not swept up by the rule above.
$dir = t84_tree('multi');
list($R, $v) = t84_read($dir, 'policy');
ok($v['verdict'] !== 'retired', 'a member still publishing does not read as retired');
is_same(t84_failing($R, 'policy'), array(), 'and fails nothing on this route');
t84_rm($dir);

// --- and the ring acts on it ----------------------------------------------
//
// member-fresh is a statement about another member, so it halts and does not
// fault. A fault would be this observer claiming its own assertions were
// failing, which they are not.

ok(
    !\pr2obs\web\is_local_finding(array('check' => 'member-fresh', 'subject' => 'multi')),
    'a stopped peer is a halt, not a fault of the observer that noticed'
);

// --- the rule is not written against one member by name -------------------
//
// What went wrong was a check that named `super` and left the others to a
// branch that reported nothing. A future reader should not have to notice the
// asymmetry to be safe from it.

$src = file_get_contents(REPO . '/observers/web/procedures.php');
$at = strpos($src, "\$result['verdict'] = 'retired';");
ok($at !== false, 'the retired verdict is still set');
$block = substr($src, $at, 400);
ok(
    preg_match("/\\\$A === 'super'\s*\)\s*\{\s*\\\$R->fail/", $block) !== 1,
    'and no longer reports only when the member is the super observer'
);

t_done();
