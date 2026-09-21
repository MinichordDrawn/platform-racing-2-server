<?php
// How long this deployment has been observed, measured rather than inferred.
//
// The trace checks ask whether a schedule has had time to run at all, so that
// a deployment ten minutes old is not faulted for having no daily trace. That
// needs a clock, and the clock has to survive a restart: container uptime is
// reset by a bounce, so a schedule that is never scheduled would be granted a
// fresh period of grace every time the container came back, and the one case
// the check exists for would never be reported.
//
// The clock used to be the published sequence times the cadence. That is not a
// duration. run.php starts the next cycle as soon as the last one ends, held
// only by OBSERVER_MIN_CYCLE_MS, and says so itself: "with cycles unpaced, a
// count of them is no longer a unit of time". With a 6s cadence and a 300ms
// floor the product ran about twenty times fast.
//
// What that cost: a deployment five minutes old believed it had been observing
// for an hour and a half, decided the hourly schedule was overdue, and halted
// the whole ring. Every cold start did it. Found by building a status view that
// reported "observing for 6 days" on a deployment one day old, and by somebody
// noticing that could not be true.
//
// The fix is a timestamp written into the store once, on the first cycle that
// finds it absent, and never rewritten. Never rewritten is the whole of it: the
// moment it is refreshed on each start it is container uptime again.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/store.php';
require_once REPO . '/observers/web/procedures.php';

echo "how long this deployment has been observed\n";

$tmp = sys_get_temp_dir() . '/pr2_t105_' . getmypid();
@mkdir($tmp . '/web', 0777, true);

// --- the file is read, and read strictly ----------------------------------

ok(\pr2obs\web\observing_since_at($tmp, 'web') === null,
    'a store with no record of when it began says so, rather than guessing');

file_put_contents($tmp . '/web/since', "2026-09-21T11:00:00Z\n");
is_same(\pr2obs\web\observing_since_at($tmp, 'web'), 1789988400,
    'a well-formed timestamp is read');

// Trailing whitespace is tolerated because the writer appends a newline; the
// shape itself is not negotiable.
file_put_contents($tmp . '/web/since', '2026-09-21T11:00:00Z');
is_same(\pr2obs\web\observing_since_at($tmp, 'web'), 1789988400,
    'with or without the newline the writer adds');

foreach (array(
    '21/09/2026 11:00:00'  => 'a different format',
    '2026-09-21 11:00:00'  => 'a missing T and Z',
    '2026-02-31T00:00:00Z' => 'a date that does not exist',
    'now'                  => 'a word',
    ''                     => 'an empty file',
) as $bad => $why) {
    file_put_contents($tmp . '/web/since', $bad);
    ok(\pr2obs\web\observing_since_at($tmp, 'web') === null, "$why is not a start time");
}

@unlink($tmp . '/web/since');
@rmdir($tmp . '/web');
@rmdir($tmp);

// --- the store is allowed to hold it --------------------------------------
//
// A file in the store root that the shape check does not permit is a
// structural violation, so adding one without permitting it would halt every
// member on its own bookkeeping.

foreach (array('web', 'multi', 'policy', 'super') as $who) {
    $permitted = \pr2obs\web\store_root_permitted($who);
    ok(($permitted['since'] ?? null) === 'file', "$who's store may hold it");
    ok(($permitted['since.tmp'] ?? null) === 'file', "and the temporary file it is written through");
}

// --- it is written once, and never again ----------------------------------

foreach (array('web', 'multi', 'policy', 'super') as $who) {
    $run = file_get_contents(REPO . "/observers/$who/run.php");

    ok(strpos($run, "is_file(join_path(\$stores, \$identity, 'since'))") !== false,
        "$who writes it only when it is not already there");
    ok(strpos($run, '$observing_since = observing_since_at($stores, $identity)') !== false,
        "and reads it back for the cycle");
    ok(strpos($run, "'observing_since' => \$observing_since") !== false,
        "and hands it to the cycle");
}

// --- and the cycle uses the clock rather than the counter -----------------

foreach (array('web', 'multi', 'policy', 'super') as $who) {
    $cycle = file_get_contents(REPO . "/observers/$who/cycle.php");

    ok(preg_match('/\$highest\s*\*\s*\(int\)\s*\$R->param\(\'cadence_seconds\'\)/', $cycle) !== 1,
        "$who no longer multiplies its cycle count by its cadence");
    ok(strpos($cycle, "\$observed_seconds = max(0, time() - \$since)") !== false,
        "and takes the elapsed time instead");
    ok(strpos($cycle, "\$checks[] = 'observing-since'") !== false,
        "and publishes that it asked");

    $apply = file_get_contents(REPO . "/observers/$who/apply.php");
    ok(strpos($apply, "'observing-since'") !== false,
        "and $who counts it as a finding about itself");
}

t_done();
