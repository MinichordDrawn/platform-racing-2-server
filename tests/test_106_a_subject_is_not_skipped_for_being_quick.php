<?php
// A subject is not skipped for publishing while it was being read.
//
// Procedure V has to know whether the highest *named* entry in a subject's
// heartbeat folder is the highest one that actually parsed. If it is not, the
// newest publication is unreadable and no verdict should be formed from the
// one behind it, or a member could hide a bad publication behind an older good
// one.
//
// It answered that by listing the folder a second time, after procedure C had
// already listed and parsed it. Two listings are two instants. A subject that
// published in between disagreed with itself through no fault of its own: the
// reader returned `unknown` and published a null observation for a member that
// was doing nothing worse than being quick.
//
// It showed up as `none` flickering in the status view against `super`, which
// is both the fastest publisher and the one every other member reads, so it
// was skipped more often than anyone. Nothing halted and nothing faulted,
// because a subject judged unknown produces no finding by design. It was
// simply a reader declining to look at the member it most needs to watch, a
// few times a minute, for no reason.
//
// Procedure C already returns `names`, every heartbeat name its own listing
// saw, precisely so the parsed set can be compared with the listed set. Using
// that keeps the protection and removes the race, because both sides of the
// comparison now come from one instant.
//
// The protection itself is covered by the fixture set rather than here:
// `shape-heartbeat-unknown-key` has a highest entry that does not parse and
// expects the verdict to be unknown with a null observation. This file guards
// the change that would bring the race back.

require_once __DIR__ . '/helper.php';

echo "a subject is not skipped for being quick\n";

// --- one listing, in every observer ---------------------------------------

foreach (array('web', 'multi', 'policy', 'super') as $who) {
    $src = file_get_contents(REPO . "/observers/$who/procedures.php");

    $at  = strpos($src, 'function procedure_v(');
    ok($at !== false, "$who has procedure_v");
    if ($at === false) {
        continue;
    }

    // To the closing brace at column 0.
    $end  = strpos($src, "\n}", $at);
    $body = substr($src, $at, $end - $at);

    ok(strpos($body, "foreach (\$c['names'] as \$seq)") !== false,
        "$who takes the highest named entry from procedure C's own listing");

    // Scoped to the part that decides the verdict, because the branch above it
    // lists the folder for a different and legitimate reason: telling a
    // missing folder apart from one holding nothing that parsed.
    $from = strpos($body, '$seqs = array_keys(');
    $to   = strpos($body, "\$result['observed'] = ");
    ok($from !== false && $to !== false && $to > $from,
        "$who's verdict section is where it is expected to be");

    if ($from !== false && $to !== false && $to > $from) {
        $verdict = substr($body, $from, $to - $from);

        ok(strpos($verdict, 'list_dir(') === false,
            "$who does not list the heartbeat folder again to decide the verdict");
        ok(strpos($verdict, 'is_heartbeat_name(') === false,
            "$who does not re-walk it to work out what procedure C already returned");
    }

    // The comparison is still made. Removing the race must not remove the
    // reason the comparison exists.
    ok(strpos($body, 'if ($highest_named !== $highest)') !== false,
        "$who still refuses to judge when the newest entry did not parse");
}

// --- and procedure C still reports what it saw ----------------------------
//
// `names` is what the comparison now rests on, so a procedure C that stopped
// filling it would make the check silently vacuous: nothing named, nothing to
// disagree with, every subject judged.

foreach (array('web', 'multi', 'policy', 'super') as $who) {
    $src = file_get_contents(REPO . "/observers/$who/procedures.php");
    ok(strpos($src, "\$out['names'][] = sequence_of_name(\$name);") !== false,
        "$who's procedure C records every heartbeat name its listing showed");
}

// --- the fixture that covers the protection is still there ----------------

$index = json_decode(file_get_contents(REPO . '/tests/fixtures/index.json'), true);
ok(is_array($index), 'the fixture index reads');

$found = false;
foreach (($index ?? array()) as $entry) {
    if (($entry['fixture'] ?? '') === 'shape-heartbeat-unknown-key') {
        $found = true;
        ok(($entry['verdicts']['multi'] ?? null) === 'unknown',
            'and still expects an unparseable newest entry to produce no verdict');
    }
}
ok($found, 'the fixture covering the protection is still in the set');

t_done();
