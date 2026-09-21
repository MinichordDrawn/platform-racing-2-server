<?php
// The gate rests on nothing the work can forge.
//
// Step 10 gave the work three questions: is my observer alive, has anything
// halted, is the super observer alive. All three are answered by reading
// files, and a file is only evidence if the thing being judged cannot write
// it. Probing a running deployment from inside the web container:
//
//   FORGEABLE  /stores/web/heartbeat
//   FORGEABLE  /stores/web/halt
//   FORGEABLE  /stores/web/fault
//   refused    /stores/web/halts
//   refused    /stores/multi/heartbeat
//   refused    /stores/policy/heartbeat
//   refused    /stores/super/heartbeat
//
// The reason is structural and it is not a mount that was set wrongly: **an
// observer and the work it watches share a container, so they share its
// mounts.** Every path the local observer must write, the work can write. No
// arrangement of flags fixes that while the two are in one container, and no
// signing key fixes it either -- a key readable by the observer is readable by
// the work, because they run as the same user and nothing in an unprivileged
// container can separate them.
//
// What fixes it is already built. The other three members relay their halts
// into `/stores/<self>/halts/`, which is read-only to the container it names,
// and the super observer's store is read-only to everyone but the super
// observer. So:
//
//   something was detected -> a halt file appears in a directory this
//                             container cannot write or delete
//   the super observer died -> a heartbeat this container cannot refresh
//                             goes stale
//
// One positive signal and one absence, both out of reach. Everything else the
// gate reads is this container's own store, and stays in the gate because
// reading it can only ever produce more stops -- but it is not what the gate
// rests on, and this file is what says so.
//
// The check is mechanical rather than a comment: every path the gate treats as
// authoritative is compared against the mount matrix, in every container that
// runs the gate. A future change that made one of them writable would fail
// here rather than quietly turning the evidence into a formality.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/observer_gate.php';

echo "the gate rests on nothing the work can forge\n";

// --- the gate names what it rests on --------------------------------------

$authoritative = observer_gate_authoritative('web');
is_same(
    $authoritative,
    array('web/halts', 'super/heartbeat'),
    'the gate names the two reads it rests on'
);

// It is relative to whoever is asking: each container's own halts directory.
foreach (array('multi', 'policy') as $who) {
    is_same(
        observer_gate_authoritative($who),
        array("$who/halts", 'super/heartbeat'),
        "and for $who it is $who's own halts directory"
    );
}

// The scheduler has no observer in its container and is named for the one that
// watches its work from the next container along. It could in fact trust the
// whole tree -- it writes nowhere in it -- so the set below is narrower than it
// has to be, which is the safe direction to be wrong in.
is_same(
    observer_gate_authoritative('web'),
    array('web/halts', 'super/heartbeat'),
    'and the scheduler rests on the same two as the container it is named for'
);

// --- and every one of them is read-only where the gate runs ---------------
//
// This is the part that cannot be argued, only checked. The compose file is
// read for what each container asks for; docker/verify-stores.sh asks the
// kernel what it got. Both are needed and this is the one that runs anywhere.

$compose = file_get_contents(REPO . '/docker/docker-compose.yml');

function t82_service($compose, $name)
{
    if (!preg_match('/^  ' . preg_quote($name, '/') . ':\s*$/m', $compose, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $from = $m[0][1] + strlen($m[0][0]);
    $rest = substr($compose, $from);
    $end = preg_match('/^  [a-z0-9][a-z0-9_-]*:\s*$/m', $rest, $n, PREG_OFFSET_CAPTURE)
        ? $n[0][1] : strlen($rest);
    return substr($rest, 0, $end);
}

// [container path => 'rw'|'ro']
function t82_mounts($body)
{
    $out = array();
    foreach (explode("\n", $body) as $line) {
        $line = trim($line);
        if (strpos($line, '- ') !== 0 || strpos($line, ':/stores') === false) {
            continue;
        }
        $parts = explode(':', trim(substr($line, 2)));
        if (count($parts) < 2) {
            continue;
        }
        $out[$parts[1]] = isset($parts[2]) ? $parts[2] : 'rw';
    }
    return $out;
}

// The containers that run gated work, and which observer watches each.
$gated = array('web' => 'web', 'cron' => 'web', 'multi' => 'multi', 'policy' => 'policy');

// A path is covered by the mount that owns it or by the nearest mount above
// it, which is how the kernel decides: /stores/web/halts has a mount of its
// own, /stores/super/heartbeat does not and inherits /stores/super.
function t82_mode($mounts, $path)
{
    $probe = '/stores/' . $path;
    while ($probe !== '' && $probe !== '/') {
        if (isset($mounts[$probe])) {
            return $mounts[$probe];
        }
        $probe = substr($probe, 0, (int) strrpos($probe, '/'));
    }
    return '(not mounted)';
}

$problems = array();

foreach ($gated as $service => $local) {
    $body = t82_service($compose, $service);
    if ($body === null) {
        $problems[] = "service $service is missing";
        continue;
    }
    $mounts = t82_mounts($body);

    foreach (observer_gate_authoritative($local) as $path) {
        $mode = t82_mode($mounts, $path);
        if ($mode !== 'ro') {
            $problems[] = "$service: /stores/$path is $mode, so the work could write it";
        }
    }
}

is_same($problems, array(),
    'every container that runs the gate is refused a write at every path the gate rests on');

// --- the observer that shares the container cannot help with this ---------
//
// Stated as a check rather than as prose, because it is the thing most likely
// to be forgotten when somebody adds a fourth question to the gate: any path
// the local observer writes is a path the work writes.

$matrix_problems = array();
foreach (array('web' => 'web', 'multi' => 'multi', 'policy' => 'policy') as $service => $observer) {
    $mounts = t82_mounts(t82_service($compose, $service));
    // The observer's own store root is writable in its container, by
    // necessity -- that is where it publishes. So it is also writable by the
    // work, and nothing in it can be evidence about the work.
    if (($mounts["/stores/$observer"] ?? null) !== 'rw') {
        $matrix_problems[] = "$service does not write its own store, which cannot be right";
    }
    foreach (observer_gate_authoritative($observer) as $path) {
        if (strpos($path, "$observer/") === 0 && $path !== "$observer/halts") {
            $matrix_problems[] = "$service rests on $path, inside the store it writes";
        }
    }
}
is_same($matrix_problems, array(),
    'and nothing the gate rests on lives in the store its own observer publishes');

// --- permission requires the authoritative reads to succeed ---------------
//
// The property that matters, and the one an ordering change could quietly
// lose: the gate cannot say yes without having read both of them and found
// them clean. A path that is missing, unreadable or stale is a stop, not a
// question skipped -- otherwise deleting the evidence would be as good as
// satisfying it, and deleting evidence is the one thing a compromised
// container is well placed to do.

function t82_tree($now)
{
    $dir = sys_get_temp_dir() . '/pr2gate82-' . getmypid() . '-' . mt_rand();
    foreach (array('web', 'multi', 'policy', 'super') as $m) {
        mkdir("$dir/$m/heartbeat", 0777, true);
        mkdir("$dir/$m/halts", 0777, true);
        $o = array(
            'kind' => 'heartbeat', 'version' => 1, 'observer' => $m, 'sequence' => 1,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $now - 1), 'cadence_seconds' => 6,
            'checks' => array('own-store-writable'), 'check_count' => 1,
            'observed' => new stdClass(), 'previous' => null, 'boot' => null, 'stop' => false,
        );
        file_put_contents("$dir/$m/heartbeat/0000000001.hb", json_encode($o, JSON_UNESCAPED_SLASHES) . "\n");
    }
    return $dir;
}

function t82_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t82_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

$now = 1789000000;
$settings = function ($dir) use ($now) {
    return array(
        'stores' => $dir, 'local' => 'web',
        'local_max_age' => 8, 'super_max_age' => 20, 'now' => $now,
    );
};

$dir = t82_tree($now);
is_same(observer_gate_reason($settings($dir)), null, 'a clean tree gives permission');
t82_rm($dir);

// Each authoritative path, removed, with everything else in good order.
foreach (array(
    'web/halts'       => 'the halts directory addressed to this container',
    'super/heartbeat' => "the super observer's heartbeat folder",
) as $path => $what) {
    $dir = t82_tree($now);
    t82_rm("$dir/$path");
    ok(observer_gate_reason($settings($dir)) !== null,
        "with $what gone, there is no permission");
    t82_rm($dir);
}

// Emptied rather than removed, which is what deleting the contents looks like.
$dir = t82_tree($now);
unlink("$dir/super/heartbeat/0000000001.hb");
ok(observer_gate_reason($settings($dir)) !== null,
    'and an emptied super heartbeat folder is a stop, not a question skipped');
t82_rm($dir);

// The other direction: a halt delivered here is honoured even though this
// container could not have put it there.
$dir = t82_tree($now);
file_put_contents("$dir/web/halts/multi", "{\"kind\":\"halt\",\n");
ok(observer_gate_reason($settings($dir)) !== null,
    'a halt relayed here by another member stops the work');
t82_rm($dir);

// --- what the gate still reads, and why that is fine ----------------------
//
// It reads everything: its own halt and fault, every member's, every halts
// directory. Half of that a compromised container could erase. It stays
// because erasing it cannot produce a *permission* -- the gate answers "may I
// work", and every forgeable read can only ever turn a yes into a no. The
// evidence that turns a no into a yes is the authoritative set above.

$gate = file_get_contents(REPO . '/common/observer_gate.php');
ok(
    strpos($gate, 'observer_gate_authoritative') !== false,
    'the gate names its authoritative set in its own source'
);
ok(
    preg_match('/halts/', $gate) === 1 || strpos($gate, "'/halts'") !== false,
    'and still scans the halts directories it cannot vouch for'
);

t_done();
