<?php
// The first observer implementation, judged against the fixture set.
//
// This is step 2 of the build order. One observer's own copy of everything:
// writing its heartbeat, hashing what it actually wrote, reading peers,
// walking the chain, and classifying a member as absent, alive, stale,
// retired, faulted or unknown.
//
// The fixtures are the contract, not this code. Where the two disagree the
// specification decides, and where a fixture contradicts the specification the
// fixture is wrong and should be reported rather than accommodated.
//
// There is no partial credit. A benign case wrongly classified as a compromise
// halts a live game, so the benign fixtures matter exactly as much as the
// invariant ones -- arguably more, since a missed compromise is a risk and a
// false one is an outage.
//
// The fixtures are read in place. They are never written to, and the
// implementation reports what it would write rather than writing it, which is
// also how the observer is built: the cycle decides, and applying the decision
// is a separate step.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/cycle.php';

define('FIXTURES', __DIR__ . '/fixtures');

echo "the first observer reads the fixture set\n";

ok(is_dir(FIXTURES), 'the fixture set is present');
if (!is_dir(FIXTURES)) {
    t_done();
}

// --- building the reader's starting state from a manifest -----------------

// SPEC 4: basis is what the reader observed last cycle, held in process. A
// reader that has been running took it from the heartbeat it published last;
// a reader that has just started has none, and every liveness verdict is
// unknown. It must not rebuild a basis from its own store -- that heartbeat
// may be hours old, and a subject that advanced since would fail I1 on a
// perfectly benign event.
function basis_from($stores, $identity, $booted)
{
    if ($booted) {
        return null;
    }
    $folder = "$stores/$identity/heartbeat";
    if (!is_dir($folder)) {
        return null;
    }
    $highest = null;
    foreach (scandir($folder) as $name) {
        if (preg_match('/^[0-9]{10}\.hb$/', $name) && ($highest === null || $name > $highest)) {
            $highest = $name;
        }
    }
    if ($highest === null) {
        return null;
    }
    $o = json_decode(file_get_contents("$folder/$highest"), true);
    return is_array($o) && isset($o['observed']) ? array('observed' => $o['observed']) : null;
}

// SPEC 7: on start, memory is rebuilt from the observer's own store -- trust
// on boot, and a stated limit. A reader that has been running also remembers
// the slots it wrote in the other stores' halts/.
function memory_from($stores, $identity, $booted, $others)
{
    $memory = array();
    $add = function ($rel) use (&$memory, $stores) {
        $p = "$stores/$rel";
        if (is_file($p)) {
            $memory[$rel] = hash('sha256', file_get_contents($p));
        }
    };

    $folder = "$stores/$identity/heartbeat";
    if (is_dir($folder)) {
        foreach (scandir($folder) as $name) {
            if (preg_match('/^[0-9]{10}\.hb$/', $name)) {
                $add("$identity/heartbeat/$name");
            }
        }
    }
    $add("$identity/fault");
    $add("$identity/halt");

    if (!$booted) {
        foreach ($others as $other) {
            $add("$other/halts/$identity");
        }
    }
    return $memory;
}

function member_dirs($stores)
{
    $out = array();
    foreach (scandir($stores) as $name) {
        if ($name !== '.' && $name !== '..' && is_dir("$stores/$name")) {
            $out[] = $name;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

// --- comparing a result with the manifest's expectations ------------------

function set_of_failing($list)
{
    $out = array();
    foreach ($list as $f) {
        $out[] = $f['check'] . '|' . (($f['subject'] === null) ? '~null~' : $f['subject']);
    }
    sort($out, SORT_STRING);
    return $out;
}

function compare($expect, $got, &$problems)
{
    if (isset($expect['verdicts'])) {
        foreach ($expect['verdicts'] as $who => $want) {
            $have = $got['verdicts'][$who] ?? '(missing)';
            if ($have !== $want) {
                $problems[] = "verdict $who: expected $want, got $have";
            }
        }
    }

    if (isset($expect['failing'])) {
        $want = set_of_failing($expect['failing']);
        $have = set_of_failing($got['failing']);
        if ($want !== $have) {
            $problems[] = 'failing: expected [' . implode(', ', $want) . '] got [' . implode(', ', $have) . ']';
        }
    }

    if (array_key_exists('halt_in_force_before', $expect)) {
        if ($expect['halt_in_force_before'] !== $got['halt_in_force_before']) {
            $problems[] = 'halt_in_force_before: expected '
                . var_export($expect['halt_in_force_before'], true)
                . ', got ' . var_export($got['halt_in_force_before'], true);
        }
    }

    if (isset($expect['halt'])) {
        $e = $expect['halt'];
        $g = $got['halt'];
        if (($e['writes'] ?? null) !== ($g['writes'] ?? null)) {
            $problems[] = 'halt.writes: expected ' . var_export($e['writes'] ?? null, true)
                . ', got ' . var_export($g['writes'] ?? null, true);
        } elseif (!empty($e['writes'])) {
            foreach (array('reason', 'subject') as $k) {
                if (array_key_exists($k, $e) && ($e[$k] !== ($g[$k] ?? null))) {
                    $problems[] = "halt.$k: expected " . var_export($e[$k], true)
                        . ', got ' . var_export($g[$k] ?? null, true);
                }
            }
        }
    }

    if (isset($expect['publish'])) {
        foreach (array('sequence', 'previous', 'observed', 'boot', 'stop') as $k) {
            if (!array_key_exists($k, $expect['publish'])) {
                continue;
            }
            $want = $expect['publish'][$k];
            $have = $got['publish'][$k] ?? null;
            if ($k === 'boot' && is_array($want) && is_array($have)) {
                $have = array('resumed_from' => $have['resumed_from'] ?? null);
                $want = array('resumed_from' => $want['resumed_from'] ?? null);
            }
            if ($want !== $have) {
                $problems[] = "publish.$k: expected " . json_encode($want) . ', got ' . json_encode($have);
            }
        }
        // Always true of an honest writer, and the cheapest possible check.
        if (count($got['publish']['checks']) !== $got['publish']['check_count']) {
            $problems[] = 'publish: check_count disagrees with checks';
        }
    }

    if (isset($expect['relay'])) {
        foreach (array('writes', 'leaves', 'bytes_of', 'own_halt_written') as $k) {
            if (!array_key_exists($k, $expect['relay'])) {
                continue;
            }
            $want = $expect['relay'][$k];
            $have = $got['relay'][$k] ?? null;
            if (is_array($want)) {
                $w = $want; $h = is_array($have) ? $have : array();
                sort($w); sort($h);
                if ($w !== $h) {
                    $problems[] = "relay.$k: expected " . json_encode($want) . ', got ' . json_encode($have);
                }
            } elseif ($want !== $have) {
                $problems[] = "relay.$k: expected " . json_encode($want) . ', got ' . json_encode($have);
            }
        }
    }

    if (array_key_exists('clears', $expect)) {
        $want = $expect['clears'];
        $have = $got['clears'];
        if ($want === false) {
            if ($have !== false) {
                $problems[] = 'clears: expected false, got ' . json_encode($have);
            }
        } elseif (!is_array($have)) {
            $problems[] = 'clears: expected ' . json_encode($want) . ', got ' . json_encode($have);
        } elseif (($want['removes'] ?? null) !== ($have['removes'] ?? null)) {
            $problems[] = 'clears.removes: expected ' . json_encode($want['removes'] ?? null)
                . ', got ' . json_encode($have['removes'] ?? null);
        }
    }
}

// --- run every fixture ----------------------------------------------------

$fixtures = array();
foreach (scandir(FIXTURES) as $name) {
    if ($name !== '.' && $name !== '..' && is_dir(FIXTURES . "/$name")) {
        $fixtures[] = $name;
    }
}
sort($fixtures, SORT_STRING);

ok(count($fixtures) >= 70, 'the fixture set is complete (' . count($fixtures) . ' fixtures)');

$passed = 0;
$failed = array();

foreach ($fixtures as $name) {
    $dir = FIXTURES . "/$name";
    $manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
    if (!is_array($manifest)) {
        $failed[$name] = array('manifest does not parse');
        continue;
    }

    // The declared directories, created rather than assumed.
    //
    // An empty directory is part of a store tree: "this member has a halts
    // folder and it is empty" is a different fact from "this member has no
    // halts folder", and fixtures turn on exactly that distinction. git stores
    // no empty directory, so every one of them is absent in a fresh clone and
    // the fixture quietly becomes a different fixture -- one fixture lost its
    // entire `stores` tree and still reported a complete set.
    //
    // The manifest has listed them all along and nothing read the list.
    foreach ($manifest['directories'] ?? array() as $rel) {
        $path = "$dir/$rel";
        if (!is_dir($path) && !@mkdir($path, 0777, true)) {
            $failed[$name] = array("could not create the declared directory $rel");
            continue 2;
        }
    }

    // The declared mtimes, applied rather than assumed.
    //
    // Five fixtures turn on how old a staging file is, and that age was read
    // from the filesystem -- so the fixture meant one thing in the directory
    // it was written in and something else after any copy that did not carry
    // timestamps across. git is exactly such a copy: a clone gives every file
    // the time of the checkout, which would have made these fixtures fail for
    // no reason, or worse, pass for the wrong one.
    //
    // The manifest has stated these mtimes all along and nothing read them.
    // One fact, stated in the fixture and decided by the filesystem -- the
    // same shape this suite keeps finding in the code it tests. Applying them
    // is idempotent: the file ends each run with exactly the declared value.
    foreach ($manifest['mtimes'] ?? array() as $rel => $when) {
        $at = strtotime($when);
        $path = "$dir/$rel";
        if ($at === false || !is_file($path) || !@touch($path, $at)) {
            $failed[$name] = array("could not apply the declared mtime for $rel");
            continue 2;
        }
    }

    $stores = "$dir/stores";
    $identity = $manifest['reader'];
    $booted = !empty($manifest['reader_booted']);
    $others = array_values(array_diff(member_dirs($stores), array($identity)));

    $memory = memory_from($stores, $identity, $booted, $others);
    if (isset($manifest['memory'])) {
        // Manifest paths are fixture-relative; subjects are store-relative.
        $memory = array();
        foreach ($manifest['memory'] as $path => $hash) {
            $memory[preg_replace('#^stores/#', '', $path)] = $hash;
        }
    }

    // The manifests list the values a *reading* needs. The cadence is not one
    // of them -- no fixture exercises timing -- but the cycle uses it to work
    // out how long this deployment has been observed, which is what decides
    // whether a schedule with no runs is overdue or simply not due yet. It was
    // silently absent until the reader was made strict about unknown
    // parameters, and an absent one became zero.
    $params = $manifest['parameters'];
    $params['cadence_seconds'] = 10;
    // The absolute backstop. The fixtures' heartbeats declare a ten-second
    // cadence, from before the deployment settled on six, and their chains are
    // hash-linked so the value cannot be changed without regenerating all
    // seventy-four. A backstop must exceed the declared cadence to mean
    // anything, so the harness gives it room: no fixture exercises the clock,
    // which FIXTURES.md says outright.
    $params['heartbeat_max_age_seconds'] = 30;

    // How long each subject has gone unchanged, as this reader measured it.
    // In-process state, like the basis and the memory: once cycles stopped
    // being paced to the cadence, a count of them stopped being a unit of
    // time, so staleness became a duration. Only the V6 fixtures carry it.
    $unchanged = $manifest['unchanged'] ?? array();

    // When this reader began observing, which the trace checks consult to ask
    // whether a schedule has had time to run at all. run.php reads it from a
    // file in the store; a fixture is a read-only snapshot whose store does
    // not carry one, so the harness supplies it here alongside the other
    // in-process state.
    //
    // The default is a year before the fixture's `now`, so a schedule that has
    // never run is overdue -- which is what the arithmetic this replaced
    // produced for these fixtures, their sequences being large. A fixture that
    // wants the other branch, where nothing is due yet, declares its own.
    $observing_since = isset($manifest['observing_since'])
        ? \pr2obs\web\parse_timestamp($manifest['observing_since'])
        : \pr2obs\web\parse_timestamp($params['now']) - 31536000;

    $result = \pr2obs\web\run_cycle(array(
        'stores'      => $stores,
        'identity'    => $identity,
        'params'      => $params,
        'basis'       => basis_from($stores, $identity, $booted),
        'memory'      => $memory,
        'booted'      => $booted,
        'unchanged'   => $unchanged,
        'observing_since' => $observing_since,
        'traces_root' => "$dir/traces",
        'traces'      => $manifest['traces'] ?? array(),
        'db'          => $manifest['db'] ?? array(),
    ));

    $problems = array();
    compare($manifest['expect'], $result, $problems);

    if (count($problems) === 0) {
        $passed++;
    } else {
        $failed[$name] = $problems;
    }
}

echo "\n  $passed of " . count($fixtures) . " fixtures pass\n\n";

if (count($failed) > 0) {
    $shown = 0;
    foreach ($failed as $name => $problems) {
        if ($shown++ >= 12) {
            echo "  ... and " . (count($failed) - 12) . " more\n";
            break;
        }
        echo "  $name\n";
        foreach (array_slice($problems, 0, 3) as $p) {
            echo "      $p\n";
        }
    }
    echo "\n";
}

ok(count($failed) === 0, 'every fixture passes');

// And no fixture depends on a directory it has not declared.
//
// The two must agree exactly. A directory present but undeclared vanishes in a
// clone and nothing puts it back; a directory declared but not present is a
// fixture describing a tree it does not have.
$mismatched = array();
foreach ($fixtures as $name) {
    $dir = FIXTURES . "/$name";
    $manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
    $declared = $manifest['directories'] ?? array();
    sort($declared, SORT_STRING);

    // A declared path implies its parents: the list names `stores/web/halts`
    // and not the `stores` above it, and `traces/daily` puts a `traces`
    // alongside the store tree.
    $allowed = array();
    foreach ($declared as $rel) {
        $parts = explode('/', $rel);
        for ($i = 1; $i <= count($parts); $i++) {
            $allowed[implode('/', array_slice($parts, 0, $i))] = true;
        }
    }

    $present = array();
    $stack = array($dir);
    while ($stack) {
        $at = array_pop($stack);
        foreach (@scandir($at) ?: array() as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            if (is_dir("$at/$e")) {
                $stack[] = "$at/$e";
                $present[str_replace('\\', '/', substr("$at/$e", strlen($dir) + 1))] = true;
            }
        }
    }

    foreach ($declared as $rel) {
        if (!isset($present[$rel])) {
            $mismatched[] = "$name: declares $rel and does not have it";
        }
    }
    foreach (array_keys($present) as $rel) {
        if (!isset($allowed[$rel])) {
            $mismatched[] = "$name: has $rel and does not declare it";
        }
    }
}
is_same($mismatched, array(), 'every fixture declares exactly the directories it has');

// And no fixture depends on an mtime it has not declared.
//
// A staging file's age is the whole of what several of these fixtures test, so
// one carrying no declared mtime would be asserting something about whenever
// it happened to be written to disk. This is what stops a new fixture
// reintroducing that quietly.
$undeclared = array();
foreach ($fixtures as $name) {
    $dir = FIXTURES . "/$name";
    $manifest = json_decode(file_get_contents("$dir/manifest.json"), true);
    $declared = array_keys($manifest['mtimes'] ?? array());

    $stack = array("$dir/stores");
    while ($stack) {
        $at = array_pop($stack);
        foreach (@scandir($at) ?: array() as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $path = "$at/$e";
            if (is_dir($path)) {
                $stack[] = $path;
            } elseif (substr($e, -4) === '.tmp') {
                $rel = substr($path, strlen($dir) + 1);
                if (!in_array(str_replace('\\', '/', $rel), $declared, true)) {
                    $undeclared[] = "$name: $rel";
                }
            }
        }
    }
}
is_same($undeclared, array(), 'every staging file in every fixture declares its mtime');

t_done();
