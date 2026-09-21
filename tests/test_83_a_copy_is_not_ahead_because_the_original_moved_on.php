<?php
// A copy is not "ahead of the original" because the original moved on.
//
// I7 is in the compromise set. It is supposed to be undeniable: a copy holding
// an entry the author never published cannot be explained by timing, so it is
// evidence of tampering and it halts everything. The design says so directly:
//
//   a reader compares a copy against its original by reading the copy first
//   -- so a copy can be behind its original and never ahead of it, and an
//   entry ahead has no race to explain it
//
// The implementation reads them the other way round. The original's listing is
// taken during the member pass, at step 4 of the cycle; the copy is read at
// step 5. So the ceiling a copy entry is measured against is older than the
// copy, and an author that publishes between the two -- own store first, copy
// second, which is the order it is required to use -- leaves a perfectly
// honest copy sitting one entry above a stale maximum.
//
// It fired five times in eighty-one thousand cycles on a running ring, and the
// distribution is the giveaway: web 0, multi 0, policy 1, super 4, against two
// copy comparisons per cycle for the first three and six for the super
// observer. Frequency scaling with the number of comparisons is a race. Each
// one halted the whole deployment for a cycle.
//
// The fix does not soften the check. When an entry looks ahead, the original
// is listed again, and the finding stands only if it is still ahead. A forged
// entry stays ahead for ever; a race resolves on the second look. The cost is
// one directory listing, on the exception path only.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/procedures.php';

echo "a copy is not ahead because the original moved on\n";

function t83_rm($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? t83_rm("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

function t83_hb($observer, $seq)
{
    $o = array(
        'kind' => 'heartbeat', 'version' => 1, 'observer' => $observer,
        'sequence' => $seq, 'timestamp' => '2026-09-20T10:00:00Z',
        'cadence_seconds' => 6, 'checks' => array('own-store-writable'),
        'check_count' => 1, 'observed' => new stdClass(),
        'previous' => $seq > 1 ? str_repeat('a', 64) : null,
        'boot' => null, 'stop' => false,
    );
    return json_encode($o, JSON_UNESCAPED_SLASHES) . "\n";
}

// A store where the super observer has published 1..$upto, and its copy in
// web's store holds 1..$copy_upto.
function t83_tree($upto, $copy_upto)
{
    $dir = sys_get_temp_dir() . '/pr2i7-' . getmypid() . '-' . mt_rand();
    mkdir("$dir/super/heartbeat", 0777, true);
    mkdir("$dir/web/copy-super/heartbeat", 0777, true);
    for ($n = 1; $n <= $upto; $n++) {
        file_put_contents(sprintf('%s/super/heartbeat/%010d.hb', $dir, $n), t83_hb('super', $n));
    }
    for ($n = 1; $n <= $copy_upto; $n++) {
        file_put_contents(sprintf('%s/web/copy-super/heartbeat/%010d.hb', $dir, $n), t83_hb('super', $n));
    }
    return $dir;
}

function t83_reader($dir)
{
    $R = new \pr2obs\web\Reader(
        $dir,
        'web',
        array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => '2026-09-20T10:00:00Z',
        ),
        null,
        array()
    );
    $R->others = array('multi', 'policy', 'super');
    return $R;
}

function t83_i7($R)
{
    $out = array();
    foreach ($R->findings as $f) {
        if ($f['check'] === 'I7') {
            $out[] = $f['detail'];
        }
    }
    return $out;
}

// The cached listing from the member pass, as the cycle leaves it: the entries
// and names the reader saw when it read the original, earlier in this cycle.
function t83_prime(&$R, $dir, $names)
{
    $entries = array();
    foreach ($names as $n) {
        $path = sprintf('%s/super/heartbeat/%010d.hb', $dir, $n);
        $bytes = file_get_contents($path);
        $entries[$n] = array(
            'name' => sprintf('%010d.hb', $n), 'path' => $path, 'bytes' => $bytes,
            'hash' => hash('sha256', $bytes),
            'parsed' => json_decode($bytes, true),
        );
    }
    $R->members['super'] = array(
        'verdict' => 'alive', 'cur' => null, 'hash' => null,
        'entries' => $entries, 'names' => $names, 'folder_present' => true,
    );
}

// --- the race -------------------------------------------------------------
//
// The reader listed the original when it held 1..4. By the time it reads the
// copy, the author has published 5 -- to its own store first, then to the
// copy, which is the order it is required to use. So entry 5 is on disk in
// both places, and only the reader's cached ceiling is out of date.

$dir = t83_tree(5, 5);
$R = t83_reader($dir);
t83_prime($R, $dir, array(1, 2, 3, 4));
\pr2obs\web\procedure_k($R, "$dir/web/copy-super", 'super');
is_same(t83_i7($R), array(),
    'an entry the author has published is not a copy ahead of its original');
t83_rm($dir);

// The same, several entries on: a reader whose cached listing is badly out of
// date still accuses nobody, because every entry is really there.
$dir = t83_tree(9, 9);
$R = t83_reader($dir);
t83_prime($R, $dir, array(1, 2, 3, 4));
\pr2obs\web\procedure_k($R, "$dir/web/copy-super", 'super');
is_same(t83_i7($R), array(),
    'and neither does one that is several cycles behind');
t83_rm($dir);

// --- and the check still catches what it is for ---------------------------
//
// The copy holds an entry that is nowhere in the author's store, on disk, at
// any point. Nothing the author did can explain that, so it stands.

$dir = t83_tree(4, 5);
$R = t83_reader($dir);
t83_prime($R, $dir, array(1, 2, 3, 4));
\pr2obs\web\procedure_k($R, "$dir/web/copy-super", 'super');
is_same(t83_i7($R), array('a copy entry ahead of the original'),
    'an entry the author never published is still reported');
t83_rm($dir);

// Two of them, both reported, so the re-listing does not swallow the rest.
$dir = t83_tree(4, 6);
$R = t83_reader($dir);
t83_prime($R, $dir, array(1, 2, 3, 4));
\pr2obs\web\procedure_k($R, "$dir/web/copy-super", 'super');
is_same(count(t83_i7($R)), 2, 'and every such entry is reported, not just the first');
t83_rm($dir);

// A copy that merely differs is a different finding and is untouched by any of
// this: the entry is within the original's range, so nothing is re-listed.
//
// The difference has to be a well-formed heartbeat that says something else,
// not damaged bytes. Anything after the closing brace makes the file malformed
// and it is reported as a shape violation instead, which is a different
// finding about a different thing.
$dir = t83_tree(4, 4);
file_put_contents(
    sprintf('%s/web/copy-super/heartbeat/%010d.hb', $dir, 3),
    str_replace('2026-09-20T10:00:00Z', '2026-09-20T10:00:01Z', t83_hb('super', 3))
);
$R = t83_reader($dir);
t83_prime($R, $dir, array(1, 2, 3, 4));
\pr2obs\web\procedure_k($R, "$dir/web/copy-super", 'super');
is_same(t83_i7($R), array('a copy entry differing from the original'),
    'a copy that disagrees with its original is reported as before');
t83_rm($dir);

// --- the re-listing is only on the exception path -------------------------
//
// It costs a directory listing, and the ordinary case must not pay it. This is
// what keeps a check that runs six times a cycle from becoming the thing that
// makes a cycle slow -- which, in this design, is its own kind of outage.

$src = file_get_contents(REPO . '/observers/web/procedures.php');
$at = strpos($src, "'a copy entry ahead of the original'");
ok($at !== false, 'the finding is still there');

// The guard that admits anything to this path at all, and the re-listing,
// both sit between the loop and the finding -- so the ordinary entry never
// reaches either.
$guard_at = strpos($src, 'if ($n > $max)');
$relist_at = strpos($src, 'if ($fresh_max === null)');
ok($guard_at !== false && $guard_at < $at,
    'and is still reached only when an entry is above the ceiling');
ok($relist_at !== false && $guard_at < $relist_at && $relist_at < $at,
    'with the second listing inside that guard, so a clean cycle never takes it');

t_done();
