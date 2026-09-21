<?php

namespace pr2obs\web;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/procedures.php';

// Reading traces (SPEC.md section 14).
//
// A request handler and a cron job cannot report their own liveness: wedged
// and idle look identical from inside. They are not observers. They leave
// traces, and the continuous observer on the same host reads them.
//
// This is the reading side. Emitting traces from the cron jobs is step 5 of
// the build order and is not here.
//
// The validations are in increasing order of what they are worth, and the
// fourth is the one that matters: the trace says the work was done, and the
// post-condition asks the database whether it *is* done. Those are separate
// questions, and a job that runs faithfully every day while its expiry query
// matches nothing is exactly the failure this codebase has produced before --
// present, running, deciding nothing.

// SPEC 14.1: a trace is appended, not staged and renamed, because a start
// with no finish has to be visible. So it is JSON Lines, and a reader ignores
// a final line that does not end in a line feed -- a job that died mid-line --
// and treats the file as ending at the last complete line.
function parse_trace(string $bytes): ?array
{
    $complete = $bytes;
    if ($complete !== '' && substr($complete, -1) !== "\n") {
        $cut = strrpos($complete, "\n");
        $complete = $cut === false ? '' : substr($complete, 0, $cut + 1);
    }
    if ($complete === '') {
        return null;
    }

    $lines = explode("\n", $complete);
    array_pop($lines);   // the empty string after the final line feed

    $head = null;
    $tasks = array();
    $finished = null;
    $halted = null;

    foreach ($lines as $i => $line) {
        $o = json_decode($line, true);
        if (!is_array($o) || !isset($o['kind'])) {
            return null;   // a complete line that does not parse: malformed
        }
        if ($i === 0) {
            if ($o['kind'] !== 'trace' || ($o['version'] ?? null) !== 1
                || !isset($o['schedule'], $o['run'], $o['started'])
            ) {
                return null;
            }
            $head = $o;
            continue;
        }
        if ($o['kind'] === 'task') {
            if (!isset($o['id']) || !array_key_exists('completed', $o)) {
                return null;
            }
            $tasks[] = $o;
            continue;
        }
        // The scheduler ran and the ring told it to stop, so it did no work
        // and said so. A run like this is fresh and finished -- because it is
        // -- and carries no tasks, which is why coverage below skips it.
        if ($o['kind'] === 'halted') {
            if (!isset($o['reason']) || !is_string($o['reason'])) {
                return null;
            }
            $halted = $o['reason'];
            continue;
        }
        if ($o['kind'] === 'finish') {
            if (!isset($o['finished'])) {
                return null;
            }
            $finished = $o['finished'];
            continue;
        }
        return null;
    }

    if ($head === null) {
        return null;   // a first line missing
    }

    return array('head' => $head, 'tasks' => $tasks, 'finished' => $finished, 'halted' => $halted);
}

function trace_runs(string $dir): array
{
    $names = list_dir($dir);
    $out = array();
    foreach (($names ?? array()) as $name) {
        if (preg_match('/^[0-9]{10}\.trace\z/', $name) !== 1) {
            continue;
        }
        $out[(int) substr($name, 0, 10)] = join_path($dir, $name);
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

/**
 * $schedules: one entry per schedule present, each with schedule,
 * period_seconds, margin_seconds, deadline_seconds, declared[].
 */
function check_traces(Reader $R, string $traces_root, array $schedules, int $observed_seconds): void
{
    foreach ($schedules as $s) {
        $name = $s['schedule'];
        $runs = trace_runs(join_path($traces_root, $name));

        // T1 freshness. This is the check that would have caught the daily
        // and weekly jobs never being scheduled at all, which nothing noticed
        // for as long as that was true.
        //
        // Never run yet is not the same as stale. A deployment that came up
        // ten minutes ago has no daily trace and nothing is wrong: the daily
        // job is not due. Reporting it would halt every fresh deployment for a
        // day, and every new one for a week waiting on the weekly job -- and
        // the schedules that cannot simply be run at startup to fix it are
        // exactly those two, because daily resets players' counters and weekly
        // optimises every table.
        //
        // The clock is this observer's own sequence, not the container's
        // uptime. Uptime is reset by a restart, so a schedule that is never
        // scheduled at all would be granted a fresh period of grace every time
        // the container bounced, and would never be reported -- which is the
        // one case this check exists for. The sequence is durable across
        // restarts by design, so it measures how long this deployment has
        // genuinely been observed and cannot be reset by bouncing anything.
        if (count($runs) === 0) {
            $due_after = $s['period_seconds'] + $s['margin_seconds'];
            if ($observed_seconds < $due_after) {
                continue;   // not due yet: unknown, and unknown is not a fault
            }
            $R->fail('trace-fresh', $name, 'no run has ever been recorded, and one is overdue');
            continue;
        }

        $parsed = array();
        $malformed = false;
        foreach ($runs as $n => $path) {
            $bytes = read_bytes($path);
            $t = $bytes === null ? null : parse_trace($bytes);
            if ($t === null) {
                $malformed = true;
                continue;
            }
            if (($t['head']['run'] ?? null) !== $n) {
                $malformed = true;
                continue;
            }
            $parsed[$n] = $t;
        }

        if (count($parsed) === 0) {
            // A job that writes garbage is a job not running correctly. It is
            // a fault rather than a compromise, because a trace is written by
            // the application and not by a member of the ring.
            $R->fail('trace-complete', $name, 'no run parses');
            continue;
        }

        $latest_n = array_key_last($parsed);
        $latest = $parsed[$latest_n];
        $started = parse_timestamp($latest['head']['started']);

        if ($started === null
            || $started < $R->now - ($s['period_seconds'] + $s['margin_seconds'])
        ) {
            $R->fail('trace-fresh', $name, 'the most recent run is older than its period plus margin');
            continue;
        }

        // T2 completion. Freshness alone cannot tell a job that died mid-run
        // from one that never started, and the two want different responses.
        if ($latest['finished'] === null) {
            if ($R->now - $started > $s['deadline_seconds']) {
                $R->fail('trace-complete', $name, 'a run started and did not finish within its deadline');
                continue;
            }
            // Within its deadline and unfinished is in progress, and produces
            // nothing at all.
        }
        if ($malformed) {
            $R->fail('trace-complete', $name, 'a run does not parse');
            continue;
        }

        // T3 coverage. A truncated run shows up as missing tasks rather than
        // as a silence, which is precisely the failure mode these sequences
        // have: daily.php runs nine tasks with no try around them, so a raise
        // part way through silently skips the rest.
        //
        // A refused run is skipped here. It completed no tasks because it did
        // none, so measuring the declared set against it would report every
        // halt as nine tasks that had quietly stopped running -- the same trap
        // this whole arrangement exists to get out of, one check further
        // along. The most recent finished run that was not refused is the most
        // recent run that says anything about what the job actually does, so
        // that is the one coverage is measured against.
        $done = null;
        foreach (array_reverse($parsed, true) as $t) {
            if ($t['finished'] !== null && $t['halted'] === null) {
                $done = $t;
                break;
            }
        }
        if ($done !== null) {
            $completed = array();
            foreach ($done['tasks'] as $task) {
                if ($task['completed'] === true) {
                    $completed[] = $task['id'];
                }
            }
            $have = array_values(array_unique($completed));
            $want = array_values(array_unique($s['declared']));
            sort($have, SORT_STRING);
            sort($want, SORT_STRING);
            if ($have !== $want) {
                $R->fail('trace-coverage', $name, 'the completed task set is not the declared set');
            }
        }
    }
}

// T4 post-conditions. Asked of the database, not of the trace: the trace says
// the work was done and this asks whether it *is* done. The query belongs to
// the observer; what is judged here is the classification of the answer.
function check_postconditions(Reader $R, array $answers): void
{
    foreach ($answers as $task => $count) {
        if ((int) $count !== 0) {
            $R->fail('postcondition', $task, "the question that must answer zero answered $count");
        }
    }
}
