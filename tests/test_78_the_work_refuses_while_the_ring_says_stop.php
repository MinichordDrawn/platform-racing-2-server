<?php
// The work refuses while the ring says stop.
//
// Until now the observer network has been a very careful way of writing files
// nothing reads. This is the step where a halt means something: every runtime
// thread asks the store before it does any work, and stops if the answer is
// no.
//
// The rule is one sentence -- *is my own observer alive, has anything halted,
// is the super observer alive* -- and the whole value of it is that it fails
// closed. So this file is about everything that is not a clean answer: a
// heartbeat that is missing, stale, truncated, signed by the wrong member, or
// says the observer stopped on purpose; a halt anywhere in the tree; a fault
// anywhere in the tree; a store root that is not there at all. Every one of
// them refuses, and the ways of refusing are enumerated rather than sampled,
// because the one path nobody wrote a case for is the one an attacker uses.
//
// The gate shares no code with any observer, for the same reason the four
// observers share none with each other: it is a second reader of the same
// bytes, and two independent readers that agree are evidence, while one reader
// called twice is not. What holds them together is the specification and the
// pairing at the end of this file, which feeds the gate a heartbeat the real
// observer's own writer produced.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/observer_gate.php';

// The observer's writer, used only at the end, to check the two agree about
// bytes. Nothing above that point touches it.
require_once REPO . '/observers/web/apply.php';

echo "the work refuses while the ring says stop\n";

$members = array('web', 'multi', 'policy', 'super');

function t78_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t78_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

// A heartbeat as the specification has it, built by hand so that this file can
// then damage it in each of the ways that matter.
function t78_hb($observer, $seq, $when, $stop = false)
{
    $o = array(
        'kind'            => 'heartbeat',
        'version'         => 1,
        'observer'        => $observer,
        'sequence'        => $seq,
        'timestamp'       => gmdate('Y-m-d\TH:i:s\Z', $when),
        'cadence_seconds' => 6,
        'checks'          => array('own-store-writable'),
        'check_count'     => 1,
        'observed'        => new stdClass(),
        'previous'        => null,
        'boot'            => null,
        'stop'            => $stop,
    );
    return json_encode($o, JSON_UNESCAPED_SLASHES) . "\n";
}

// A ring in good order: four stores, each with a short chain of heartbeats
// written a moment ago, an empty halts directory, and no fault.
function t78_tree($now, $ages = array())
{
    global $members;
    $dir = sys_get_temp_dir() . '/pr2gate-' . getmypid() . '-' . substr(md5(serialize($ages) . $now . mt_rand()), 0, 8);
    t78_rm($dir);
    foreach ($members as $m) {
        mkdir("$dir/$m/heartbeat", 0777, true);
        mkdir("$dir/$m/halts", 0777, true);
        $age = isset($ages[$m]) ? $ages[$m] : 1;
        for ($seq = 1; $seq <= 3; $seq++) {
            // Only the highest is current; the older two are the window, and
            // are deliberately old enough to refuse if the gate ever reads the
            // wrong one.
            $when = $now - $age - (3 - $seq) * 3600;
            file_put_contents(
                sprintf('%s/%s/heartbeat/%010d.hb', $dir, $m, $seq),
                t78_hb($m, $seq, $when)
            );
        }
    }
    return $dir;
}

function t78_settings($dir, $now, $local = 'web')
{
    return array(
        'stores'        => $dir,
        'local'         => $local,
        'local_max_age' => 8,
        'super_max_age' => 20,
        'now'           => $now,
    );
}

$now = 1789000000;   // a fixed moment; nothing here reads the real clock

// --- a ring in good order lets the work run -------------------------------

$dir = t78_tree($now);
is_same(observer_gate_reason(t78_settings($dir, $now)), null,
    'a ring in good order gives the work no reason to stop');

// Every member is a valid local identity, not just the one this test favours.
foreach ($members as $m) {
    is_same(observer_gate_reason(t78_settings($dir, $now, $m)), null,
        "and the same is true reading from $m's host");
}

// --- the gate writes nothing ----------------------------------------------
//
// It runs in front of every request and every cron process, as the work's own
// user, and the store tree is mounted read-only everywhere it runs. A gate
// that wrote would be refused by the kernel and would take the site down with
// it; more to the point, a reader that modifies what it reads cannot be run
// by four different things at once.

function t78_snapshot($dir)
{
    $out = array();
    $stack = array($dir);
    while ($stack) {
        $d = array_pop($stack);
        foreach (scandir($d) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $p = "$d/$e";
            if (is_dir($p)) {
                $stack[] = $p;
                $out[substr($p, strlen($dir))] = 'dir';
            } else {
                $out[substr($p, strlen($dir))] = hash_file('sha256', $p);
            }
        }
    }
    ksort($out);
    return $out;
}

$before = t78_snapshot($dir);
observer_gate_reason(t78_settings($dir, $now));
is_same(t78_snapshot($dir), $before, 'and the gate left the tree exactly as it found it');

t78_rm($dir);

// --- a halt anywhere stops the work ---------------------------------------
//
// Enumerated over every place a halt can be, because "a halt is present" is
// the cheapest and most important of the three questions and a place nobody
// checked is a halt nobody honours. There are sixteen: each member's own
// record, and each member's slot in the three halts directories that are not
// its own.

$halt_places = array();
foreach ($members as $m) {
    $halt_places[] = "$m/halt";
    foreach ($members as $finder) {
        if ($finder !== $m) {
            $halt_places[] = "$m/halts/$finder";
        }
    }
}
is_same(count($halt_places), 16, 'a halt has sixteen places it can be');

$missed = array();
foreach ($halt_places as $place) {
    $d = t78_tree($now);
    file_put_contents("$d/$place", "{\"kind\":\"halt\",\n");
    if (observer_gate_reason(t78_settings($d, $now)) === null) {
        $missed[] = $place;
    }
    t78_rm($d);
}
is_same($missed, array(), 'a halt in any of them stops the work');

// --- and so does a fault --------------------------------------------------
//
// The design's rule is the presence of a halt. A fault is checked as well, and
// the reason is that it is strictly earlier: a member writes its own fault the
// moment one of its own assertions fails, and the halt that follows takes
// another member a cycle to notice and write. Reading both narrows the window
// in which the work keeps going after something is already known to be wrong,
// and costs one more file_exists on a path the gate is already standing in.
//
// It cannot make a halt unrecoverable, because it is the same condition: the
// ring clears only when no store holds a fault, so anything that clears the
// halt has already cleared this.

$missed = array();
foreach ($members as $m) {
    $d = t78_tree($now);
    file_put_contents("$d/$m/fault", "{\"kind\":\"fault\",\n");
    if (observer_gate_reason(t78_settings($d, $now)) === null) {
        $missed[] = $m;
    }
    t78_rm($d);
}
is_same($missed, array(), "a fault in any member's store stops the work too");

// --- the local observer has to be alive -----------------------------------
//
// The observer and the work share a host and therefore a clock, so this window
// needs no allowance for skew and can sit just above the cadence.

$d = t78_tree($now, array('web' => 8));
is_same(observer_gate_reason(t78_settings($d, $now)), null,
    'a local heartbeat at the edge of its window is still alive');
t78_rm($d);

$d = t78_tree($now, array('web' => 9));
ok(observer_gate_reason(t78_settings($d, $now)) !== null,
    'one second past it, the work stops');
t78_rm($d);

// --- the super observer gets a looser window, and only it ------------------
//
// It is read across a clock that is not this one. The looseness is affordable
// because this is a second line: the local observer already judges the super
// observer every cycle by a method that needs no clock at all, and would have
// halted. The runtime asks directly in case the local observer is the thing
// that is wrong.

$d = t78_tree($now, array('super' => 15));
is_same(observer_gate_reason(t78_settings($d, $now)), null,
    "the super observer is allowed an age the local observer would not be");
t78_rm($d);

$d = t78_tree($now, array('super' => 21));
ok(observer_gate_reason(t78_settings($d, $now)) !== null,
    'past its own window it stops the work as well');
t78_rm($d);

// The two windows are genuinely separate: a local observer at fifteen seconds
// is still a stop, whatever the super observer is allowed.
$d = t78_tree($now, array('web' => 15));
ok(observer_gate_reason(t78_settings($d, $now)) !== null,
    'and the local window is not quietly the generous one');
t78_rm($d);

// --- a heartbeat from the future is not a fresh heartbeat ------------------
//
// A clock that disagrees by more than the window is a disagreement whichever
// way it runs. Treating a future timestamp as merely fresh would let a wrong
// clock -- or a written file -- hold the gate open indefinitely.
$d = t78_tree($now, array('web' => -60));
ok(observer_gate_reason(t78_settings($d, $now)) !== null,
    'a heartbeat dated a minute from now does not count as alive');
t78_rm($d);

// --- everything that is not a heartbeat -----------------------------------
//
// Each of these replaces the current heartbeat with something the gate must
// refuse. The case that matters most is the last-but-one: a file whose first
// bytes are the heartbeat marker but which is signed by another member, which
// is what a copied or misplaced file looks like.

$damage = array(
    'an empty file'                  => '',
    'a truncated object'             => '{"kind":"heartbeat","version":1,"obse',
    'text that is not JSON'          => "not a heartbeat at all\n",
    'a fault in a heartbeat\'s place' => "{\"kind\":\"fault\",\"version\":1,\"observer\":\"web\",\"failing\":[]}\n",
    'whitespace before the marker'   => ' ' . t78_hb('web', 3, $now - 1),
    'a version from another design'  => str_replace('"version":1', '"version":2', t78_hb('web', 3, $now - 1)),
    'a timestamp that is not one'    => str_replace(gmdate('Y-m-d\TH:i:s\Z', $now - 1), 'yesterday', t78_hb('web', 3, $now - 1)),
    'a heartbeat signed by a peer'   => t78_hb('multi', 3, $now - 1),
    'a deliberate stop'              => t78_hb('web', 3, $now - 1, true),
);

foreach ($damage as $label => $bytes) {
    $d = t78_tree($now);
    file_put_contents("$d/web/heartbeat/0000000003.hb", $bytes);
    ok(observer_gate_reason(t78_settings($d, $now)) !== null, "the work stops on $label");
    t78_rm($d);
}

// An oversized heartbeat is refused on its size, before anything parses it.
$d = t78_tree($now);
file_put_contents("$d/web/heartbeat/0000000003.hb", str_pad(t78_hb('web', 3, $now - 1), 200000, ' '));
ok(observer_gate_reason(t78_settings($d, $now)) !== null, 'and on a heartbeat larger than one can be');
t78_rm($d);

// --- a heartbeat that is not there at all ---------------------------------

$d = t78_tree($now);
foreach (scandir("$d/web/heartbeat") as $e) {
    if ($e !== '.' && $e !== '..') {
        unlink("$d/web/heartbeat/$e");
    }
}
ok(observer_gate_reason(t78_settings($d, $now)) !== null, 'an empty heartbeat folder stops the work');

// A staging file is not a heartbeat. This is the cold start the design warns
// about: the observer has begun its first publication and not finished it, and
// a reader that accepted the staging name would serve on a half-written file.
file_put_contents("$d/web/heartbeat/0000000004.hb.tmp", t78_hb('web', 4, $now));
ok(observer_gate_reason(t78_settings($d, $now)) !== null, 'and a staging file does not count as one');
t78_rm($d);

$d = t78_tree($now);
t78_rm("$d/web/heartbeat");
ok(observer_gate_reason(t78_settings($d, $now)) !== null, 'so does a missing heartbeat folder');
t78_rm($d);

// --- a store that is not there --------------------------------------------
//
// Every member is named explicitly rather than found by listing /stores,
// because a listing that comes back short is indistinguishable from a tree
// with nothing wrong in it. A member the deployment declares and cannot find
// is the case this exists for.

foreach ($members as $m) {
    $d = t78_tree($now);
    t78_rm("$d/$m");
    ok(observer_gate_reason(t78_settings($d, $now)) !== null, "a missing store for $m stops the work");
    t78_rm($d);

    $d = t78_tree($now);
    t78_rm("$d/$m/halts");
    ok(observer_gate_reason(t78_settings($d, $now)) !== null, "so does a missing halts directory in $m");
    t78_rm($d);
}

// The four the gate names are the four the store tree has.
is_same(observer_gate_members(), $members, 'the gate names every member of the ring');

// --- the environment decides nothing by accident ---------------------------
//
// Same rule as the observer's own configuration: a value that is not set is
// not a value to guess at. A gate that defaulted its windows would be deciding
// how long the work may run unobserved, in code, where no deployment review
// would ever see the number.

$required = array(
    'OBSERVER_LOCAL',
    'OBSERVER_LOCAL_MAX_AGE_SECONDS',
    'OBSERVER_SUPER_MAX_AGE_SECONDS',
);
$gate = file_get_contents(REPO . '/common/observer_gate.php');
foreach ($required as $name) {
    ok(strpos($gate, $name) !== false, "$name is read from the environment");
}

$env = array(
    'OBSERVER_STORES'                 => '/stores',
    'OBSERVER_LOCAL'                  => 'web',
    'OBSERVER_LOCAL_MAX_AGE_SECONDS'  => '8',
    'OBSERVER_SUPER_MAX_AGE_SECONDS'  => '20',
);
is_same(observer_gate_settings_from($env)['local'], 'web', 'a complete environment is accepted');

foreach ($required as $name) {
    $short = $env;
    unset($short[$name]);
    ok(is_string(observer_gate_settings_from($short)),
        "an environment with no $name is refused, not defaulted");
}
foreach (array('OBSERVER_LOCAL_MAX_AGE_SECONDS', 'OBSERVER_SUPER_MAX_AGE_SECONDS') as $name) {
    $bad = $env;
    $bad[$name] = '0';
    ok(is_string(observer_gate_settings_from($bad)), "a $name of zero is refused");
    $bad[$name] = 'soon';
    ok(is_string(observer_gate_settings_from($bad)), "a $name that is not a number is refused");
}
$bad = $env;
$bad['OBSERVER_LOCAL'] = 'somebody';
ok(is_string(observer_gate_settings_from($bad)), 'a local observer that is not a member is refused');

// --- the pairing ----------------------------------------------------------
//
// Everything above feeds the gate bytes this file wrote. This feeds it bytes
// the observer's own writer produced, which is the only thing that can catch
// the two drifting apart. It is the same shape as the trace pairing: two
// pieces of code that share nothing, held together by a specification, run
// against each other where it is cheap to find out that they disagree.

$d = t78_tree($now);
foreach ($members as $m) {
    $bytes = \pr2obs\web\heartbeat_bytes(array(
        'observer'        => $m,
        'sequence'        => 4,
        'timestamp'       => gmdate('Y-m-d\TH:i:s\Z', $now - 1),
        'cadence_seconds' => 6,
        'checks'          => array('own-store-writable', 'member-fresh:multi'),
        'observed'        => array('multi' => str_repeat('a', 64)),
        'previous'        => str_repeat('b', 64),
        'boot'            => null,
        'stop'            => false,
    ));
    file_put_contents(sprintf('%s/%s/heartbeat/%010d.hb', $d, $m, 4), $bytes);
}
is_same(observer_gate_reason(t78_settings($d, $now)), null,
    'the gate accepts a heartbeat the observer itself wrote');

// And the stop the observer writes on a deliberate shutdown is one the gate
// honours, rather than a field only the observers read.
$bytes = \pr2obs\web\heartbeat_bytes(array(
    'observer'        => 'web',
    'sequence'        => 5,
    'timestamp'       => gmdate('Y-m-d\TH:i:s\Z', $now),
    'cadence_seconds' => 6,
    'checks'          => array('own-store-writable'),
    'observed'        => array(),
    'previous'        => str_repeat('b', 64),
    'boot'            => null,
    'stop'            => true,
));
file_put_contents(sprintf('%s/web/heartbeat/%010d.hb', $d, 5), $bytes);
ok(observer_gate_reason(t78_settings($d, $now)) !== null,
    'and stops the work when that heartbeat says the observer stopped');
t78_rm($d);

t_done();
