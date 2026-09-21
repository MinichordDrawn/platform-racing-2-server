<?php
// One format, read the same way by every reader.
//
// SPEC 3.1 says a timestamp is *exactly* `YYYY-MM-DDTHH:MM:SSZ`, and the
// windows are whole numbers of seconds. Six readers of those fields exist --
// four observers, the gate in front of every PHP runtime, and the gate in the
// PR2Hub proxy -- and the design's argument for having six is stated in two of
// the files in the same words: *two readers that agree are evidence where one
// reader called twice is not.*
//
// They did not agree.
//
// PCRE's `$` matches before a final line feed; RE2's does not. So a value of
// `20` with a newline after it was a whole number to all five PHP readers and
// not a number at all to the Go one, and a timestamp with a newline after it
// was current to five readers and unparseable to the sixth. A deployment could
// therefore satisfy every PHP runtime and refuse the proxy on every path, with
// the two disagreeing about the same bytes -- which is not two readers
// agreeing, it is two readers reading different formats.
//
// `parse_timestamp` was looser still: `createFromFormat` without the reset
// flag accepts trailing data and rolls a date that does not exist over into
// one that does, so a field the specification fixes to one form had a reader
// that accepted several.
//
// The rule here is the one the whole design rests on: the strictest reading
// wins, because a reader that accepts more than the specification allows is a
// reader that will one day disagree with the others about a file. `\z` in
// every anchored validator, in both languages, so that the agreement is
// visible in the source rather than inferred.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/observer_gate.php';
require_once REPO . '/observers/web/store.php';

echo "one format, read the same way by every reader\n";

// --- the windows -----------------------------------------------------------

$base = array(
    'OBSERVER_STORES' => '/stores',
    'OBSERVER_LOCAL'  => 'web',
    'OBSERVER_LOCAL_MAX_AGE_SECONDS' => '8',
    'OBSERVER_SUPER_MAX_AGE_SECONDS' => '20',
);

ok(is_array(observer_gate_settings_from($base)), 'a stated window is read');

foreach (array(
    "20\n"   => 'a window with a line feed after it',
    "20\r\n" => 'a window with a carriage return and a line feed after it',
    "20 "    => 'a window with a space after it',
    "\n20"   => 'a window with a line feed before it',
    "2 0"    => 'a window with a space inside it',
) as $value => $what) {
    foreach (array('OBSERVER_LOCAL_MAX_AGE_SECONDS', 'OBSERVER_SUPER_MAX_AGE_SECONDS') as $name) {
        $env = $base;
        $env[$name] = $value;
        ok(is_string(observer_gate_settings_from($env)), "$what is refused for $name");
    }
}

// --- the timestamp, in the gate --------------------------------------------

ok(observer_gate_time('2026-01-01T00:00:00Z') !== null, 'a timestamp in the one form is read');

foreach (array(
    "2026-01-01T00:00:00Z\n"    => 'a line feed after it',
    "2026-01-01T00:00:00Z\r\n"  => 'a carriage return and a line feed after it',
    "2026-01-01T00:00:00Zjunk"  => 'anything else after it',
    " 2026-01-01T00:00:00Z"     => 'a space before it',
    "2026-13-01T00:00:00Z"      => 'a month that does not exist',
    "2026-02-31T00:00:00Z"      => 'a day that does not exist',
    "2026-01-01T25:00:00Z"      => 'an hour that does not exist',
) as $value => $what) {
    ok(observer_gate_time($value) === null, "the gate refuses a timestamp with $what");
}

// --- the timestamp, in the observers ---------------------------------------
//
// Two functions read this field: one says whether the string is a timestamp,
// the other turns it into a number. Both are readers of the same rule, and a
// disagreement between them is the same defect one level down.

foreach (array(
    "2026-01-01T00:00:00Z\n"   => 'a line feed after it',
    "2026-01-01T00:00:00Zjunk" => 'anything else after it',
    " 2026-01-01T00:00:00Z"    => 'a space before it',
) as $value => $what) {
    ok(\pr2obs\web\is_timestamp($value) === false,
        "is_timestamp refuses a timestamp with $what");
    ok(\pr2obs\web\parse_timestamp($value) === null,
        "parse_timestamp refuses a timestamp with $what");
}

// A date that does not exist is not a date. `createFromFormat` rolls it over
// into one that does, which is a reader inventing a value the writer never
// wrote.
foreach (array(
    '2026-02-31T00:00:00Z' => 'a day that does not exist',
    '2026-13-01T00:00:00Z' => 'a month that does not exist',
    '2026-01-01T25:00:00Z' => 'an hour that does not exist',
) as $value => $what) {
    ok(\pr2obs\web\parse_timestamp($value) === null,
        "parse_timestamp refuses $what rather than rolling it over");
}

ok(\pr2obs\web\parse_timestamp('2026-01-01T00:00:00Z') !== null,
    'and still reads the one form it is given');

// --- the names and the hashes ----------------------------------------------

foreach (array(
    'is_heartbeat_name'         => array('0000000001.hb', "0000000001.hb\n"),
    'is_heartbeat_staging_name' => array('0000000001.hb.tmp', "0000000001.hb.tmp\n"),
    'is_hash'                   => array(str_repeat('a', 64), str_repeat('a', 64) . "\n"),
) as $fn => $pair) {
    list($good, $bad) = $pair;
    $f = "\\pr2obs\\web\\$fn";
    ok($f($good) === true, "$fn reads the form it is given");
    ok($f($bad) === false, "and refuses one with a line feed after it");
}

// --- and the two languages spell it the same way ---------------------------
//
// The behaviour above is the point; this is so that a reader of either file
// can see the agreement without having to know how each engine treats `$`.

$php_files = array_merge(
    array(REPO . '/common/observer_gate.php'),
    glob(REPO . '/observers/*/store.php'),
    glob(REPO . '/observers/*/run.php'),
    glob(REPO . '/observers/*/traces.php'),
    glob(REPO . '/observers/*/container.php')
);

$loose = array();
foreach ($php_files as $file) {
    $src = file_get_contents($file);
    $rel = str_replace('\\', '/', substr($file, strlen(REPO) + 1));
    // An anchored whole-string validator, ending at `$` rather than `\z`. The
    // `/m` patterns are searches through a file's lines and are not this.
    if (preg_match_all('~preg_match\(\s*\'/\^[^\']*\$/\'~', $src, $m)) {
        foreach ($m[0] as $hit) {
            $loose[] = "$rel: $hit";
        }
    }
}
is_same($loose, array(), 'no PHP validator anchors a whole string with $');

$go = file_get_contents(REPO . '/pr2hub_proxy/gate.go');
ok(preg_match('~`\^[^`]*\$`~', $go) !== 1, 'and neither does the Go one');
foreach (array('gateWholeNumber', 'gateTimestamp', 'gateHeartbeatName') as $name) {
    ok(preg_match('/' . $name . '\s*=\s*regexp\.MustCompile\(`[^`]*\\\\z`\)/', $go) === 1,
        "the Go reader anchors $name with \\z");
}

t_done();
