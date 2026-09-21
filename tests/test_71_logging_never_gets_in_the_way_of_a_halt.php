<?php
// Logging follows the halt, and never gets in its way.
//
// Two rules, and the first is the point. The halt is what stops the system;
// the log only explains it afterwards. Written in the other order, a slow or
// blocked log write delays the stop by however long it takes, and an observer
// that died between the two would have recorded the explanation for a stop
// that never happened -- a story with no effect, which is the worst of both.
//
// The second follows from the first. A failure to log must never prevent or
// delay a halt, so logging errors are swallowed. That is a softening and it is
// the right one: the log is not load-bearing for detection. The store carries
// the state, the ring reads the store, and nothing in the protocol consults a
// log. A log that cannot be written is worth almost nothing; a halt that
// cannot be written because a log was in the way would stop nothing at all
// while appearing to work.
//
// The decisive check here is behavioural: with a log sink that throws on every
// call, the halt file must still be on disk afterwards.
//
// SPEC.md 12.4.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';
require_once REPO . '/observers/web/apply.php';

define('FIXTURES2', __DIR__ . '/fixtures');

echo "logging never gets in the way of a halt\n";

// --- the ordering is visible in the shape of the code ---------------------

$observers = glob(REPO . '/observers/*', GLOB_ONLYDIR);
ok(count($observers) >= 1, 'at least one observer is present');

foreach ($observers as $dir) {
    $name = basename($dir);

    // Exactly one file calls it, and it is the one that writes.
    $callers = array();
    foreach (glob("$dir/*.php") as $f) {
        $src = preg_replace('!/\*.*?\*/!s', '', file_get_contents($f));
        $src = preg_replace('/^\s*\/\/.*$/m', '', $src);
        // A call, not the definition: log.php declares the function and must
        // not be counted as calling it.
        if (preg_match('/(?<!function )\blog_after_halt\s*\(/', $src)) {
            $callers[] = basename($f);
        }
    }
    sort($callers);
    is_same($callers, array('apply.php'), "$name logs only from the file that writes");

    // The logger never closes a stream.
    //
    // This is not style. The first version opened php://stdout for each line
    // and closed it again, which closes the process's own descriptor 1: the
    // first line went out and every line after it was written to a closed
    // stream. Because logging errors are swallowed -- which they must be --
    // the observer ran perfectly and logged nothing, and only a real
    // container's output showed it. A logger that hides its own failure is
    // the one place that softening costs something, so the shape that caused
    // it is asserted against directly.
    $logsrc = file_get_contents("$dir/log.php");
    $code = preg_replace('!/\*.*?\*/!s', '', $logsrc);
    $code = preg_replace('/^\s*\/\/.*$/m', '', $code);
    ok(
        preg_match('/\bfclose\s*\(/', $code) !== 1,
        "$name's logger never closes the stream it writes to"
    );

    // And it is the last thing that file does, so writes added later go above
    // it and the order cannot be inverted by someone inserting one wrongly.
    $apply = file_get_contents("$dir/apply.php");
    $at = strpos($apply, 'log_after_halt(');
    ok($at !== false, "$name has the call");
    if ($at !== false) {
        $tail = substr($apply, $at);
        ok(
            preg_match('/^log_after_halt\s*\([^;]*;\s*\}/s', $tail) === 1,
            "$name logs as the final statement of the applying function"
        );
    }
}

// --- the default sink actually writes -------------------------------------
//
// Every other test here supplies its own sink, so nothing exercised the path a
// real observer uses. It ran for twenty cycles writing a perfect chain and not
// one line of log: `null` meant "use the default" at the call site and "do not
// log" in the function, and because logging errors are swallowed there was
// nothing to see. A sink nobody tests is a sink that does not work.

$captured = tempnam(sys_get_temp_dir(), 'pr2obs-sink');
$handle = fopen($captured, 'wb');
\pr2obs\web\default_sink($handle);

\pr2obs\web\log_line(null, 'probe', array('n' => 1));
\pr2obs\web\log_line(null, 'probe', array('n' => 2));
fflush($handle);

$written = file_get_contents($captured);
$got = array_values(array_filter(explode("\n", $written)));

is_same(count($got), 2, 'the default sink receives every line, not only the first');
$first = json_decode($got[0] ?? '', true);
is_same($first['event'] ?? null, 'probe', 'and the line is the record that was asked for');

// `false` is the only value that means silence.
\pr2obs\web\log_line(false, 'probe', array('n' => 3));
fflush($handle);
is_same(
    count(array_values(array_filter(explode("\n", file_get_contents($captured))))),
    2,
    'and false, and only false, turns logging off'
);

// And the same through the applying function, which is where the second
// instance of the collision lived: `$state['log'] ?? false` reads null as
// false, so a state that meant "default" logged nothing at all.
ftruncate($handle, 0);
rewind($handle);
\pr2obs\web\log_after_halt(null, 'web', 7, array(
    'failing' => array(array('check' => 'I5', 'subject' => 'web/fault')),
    'halt'    => array('writes' => true, 'reason' => 'I5', 'subject' => 'web/fault'),
    'relay'   => array('writes' => array()),
    'clears'  => false,
));
fflush($handle);
$lines_out = array_values(array_filter(explode("\n", file_get_contents($captured))));
ok(count($lines_out) >= 2, 'a null sink still reaches the default through the applying function');

fclose($handle);
@unlink($captured);

// --- with a log that throws, the halt is still written --------------------

function copy_tree($src, $dst)
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        if (is_dir("$src/$e")) {
            copy_tree("$src/$e", "$dst/$e");
        } else {
            copy("$src/$e", "$dst/$e");
        }
    }
}

function remove_tree($dir)
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? remove_tree("$dir/$e") : @unlink("$dir/$e");
    }
    @rmdir($dir);
}

$source = FIXTURES2 . '/inv5-own-file-altered';
ok(is_dir($source), 'the halt fixture is present');

if (is_dir($source)) {
    $manifest = json_decode(file_get_contents("$source/manifest.json"), true);

    // Two independent runs, so neither can be affected by the other's writes.
    // Fixtures are never written to; each run gets its own copy.
    $runs = array();
    foreach (array('collecting', 'throwing') as $which) {
        $work = sys_get_temp_dir() . "/pr2obs-$which-" . getmypid();
        remove_tree($work);
        copy_tree($source, $work);
        $runs[$which] = $work;
    }

    $lines = array();
    $sinks = array(
        'collecting' => function ($line) use (&$lines) { $lines[] = json_decode($line, true); },
        'throwing'   => function ($line) { throw new RuntimeException('the log is unavailable'); },
    );

    $results = array();
    foreach ($runs as $which => $work) {
        $stores = "$work/stores";

        $memory = array();
        foreach ($manifest['memory'] as $path => $hash) {
            $memory[preg_replace('#^stores/#', '', $path)] = $hash;
        }

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
    $params['heartbeat_max_age_seconds'] = 30;

        $plan = \pr2obs\web\run_cycle(array(
            'stores'   => $stores,
            'identity' => 'web',
            'params'   => $params,
            'basis'    => array('observed' => $o['observed']),
            'memory'   => $memory,
            'booted'   => false,
        ));

        $state = array(
            'stores' => $stores, 'identity' => 'web', 'params' => $params,
            'basis' => null, 'memory' => $memory, 'since' => array(),
            'failing_set' => null, 'booted' => false,
            'started' => '2026-09-20T10:00:00Z', 'deferred' => array(),
            'log' => $sinks[$which],
        );

        $threw = false;
        try {
            \pr2obs\web\apply_cycle($state, $plan);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $results[$which] = array(
            'threw'     => $threw,
            'reason'    => $plan['halt']['reason'] ?? null,
            'halt_file' => is_file("$stores/web/halt"),
            'halt_body' => is_file("$stores/web/halt") ? file_get_contents("$stores/web/halt") : null,
        );
    }

    is_same($results['collecting']['reason'], 'I5', 'the cycle raises the halt it should');
    ok($results['collecting']['halt_file'], 'the halt file is written');

    $events = array();
    foreach ($lines as $l) {
        $events[] = $l['event'];
    }
    ok(in_array('halt-raised', $events, true), 'and the halt is recorded in the log');
    ok(in_array('failing', $events, true), 'along with the failing set');

    // The one that matters.
    ok(!$results['throwing']['threw'], 'a log sink that throws does not propagate out');
    ok($results['throwing']['halt_file'], 'and the halt file is written anyway');
    is_same(
        $results['throwing']['halt_body'],
        $results['collecting']['halt_body'],
        'with exactly the same content as when the log worked'
    );

    foreach ($runs as $work) {
        remove_tree($work);
    }
}

t_done();
