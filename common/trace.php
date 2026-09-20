<?php

// Traces: what scheduled work leaves behind so an observer can tell whether it
// ran (SPEC.md section 14).
//
// A cron job cannot report its own liveness. Wedged and idle look identical
// from inside, so it is not an observer -- it leaves a record, and the
// observer on the same host reads it.
//
// Per task, not per job. These jobs are sequences: daily.php runs nine tasks
// one after another, so a raise part way through silently skips the rest. A
// trace saying only "the daily job ran" would be true and useless, because the
// interesting failure is exactly the one that truncates it.
//
// This is written by the application, never by an observer. Observers read
// traces and write none, which is what keeps the single-writer rule intact
// when it meets cron.


// How many runs of each schedule are kept.
//
// The design records that it states no retention rule, and one is needed: the
// minute job alone would leave 1,440 files a day. The observer needs the most
// recent run and the most recent *finished* run, so anything above two is
// enough for the checks; ten leaves a little history for a person and bounds
// the tree at forty files. The writer prunes its own schedule's folder, which
// keeps the single-writer rule -- nobody prunes anybody else's.
const TRACE_KEEP_RUNS = 10;


function trace_dir(string $schedule): string
{
    return CACHE_DIR . '/traces/' . $schedule;
}


// SPEC 14.1: ten zero-padded decimal digits, durable in the same way as a
// heartbeat sequence -- read the folder, take the highest present plus one.
function trace_next_run(string $dir): int
{
    $highest = 0;
    if (is_dir($dir)) {
        foreach (scandir($dir) as $name) {
            if (preg_match('/^([0-9]{10})\.trace$/', $name, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }
    }
    return $highest + 1;
}


// Begin a run. Returns a handle, or null if the trace could not be started.
//
// A trace that cannot be written does not stop the job. That looks like the
// softening this codebase refuses, and it is not, for one specific reason: the
// absence is itself detected. An observer that finds no fresh trace fails
// `trace-fresh` and halts the system. Stopping the job here as well would take
// a detectable condition and turn it into two failures instead of one, and
// would let a full disk stop work that was otherwise fine to do.
function trace_begin(string $schedule)
{
    $dir = trace_dir($schedule);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return null;
    }

    $run = trace_next_run($dir);
    $path = $dir . '/' . str_pad((string) $run, 10, '0', STR_PAD_LEFT) . '.trace';

    // Appended, not staged and renamed. A start with no finish has to be
    // visible while the job is still running, which a file that only appears
    // when it is complete could never show.
    $fh = @fopen($path, 'ab');
    if ($fh === false) {
        return null;
    }

    $trace = array('schedule' => $schedule, 'run' => $run, 'path' => $path, 'fh' => $fh);

    trace_write($trace, array(
        'kind'     => 'trace',
        'version'  => 1,
        'schedule' => $schedule,
        'run'      => $run,
        'started'  => gmdate('Y-m-d\TH:i:s\Z'),
    ));

    trace_prune($dir, $run);

    return $trace;
}


function trace_write($trace, array $line): void
{
    if ($trace === null) {
        return;
    }
    $encoded = json_encode($line, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }
    @fwrite($trace['fh'], $encoded . "\n");
    @fflush($trace['fh']);
}


// Run one task and record what happened to it.
//
// The task's own exception is recorded and then rethrown, unchanged. This does
// not make a failing job continue -- it makes a failing job say which step it
// failed at before it stops, which is the difference between a trace that is
// useless and one that is worth reading. Whether these sequences should carry
// on past a failure is a separate question and not one a tracing change should
// decide quietly.
function trace_task($trace, string $id, callable $work)
{
    try {
        $result = $work();
    } catch (\Throwable $e) {
        trace_write($trace, array(
            'kind'      => 'task',
            'id'        => $id,
            'completed' => false,
            'count'     => null,
        ));
        throw $e;
    }

    trace_write($trace, array(
        'kind'      => 'task',
        'id'        => $id,
        'completed' => true,
        'count'     => is_int($result) ? $result : null,
    ));

    return $result;
}


// The finish line is written only when every task has completed. Its absence
// past the schedule's deadline is what tells an observer the run died part of
// the way through.
function trace_finish($trace): void
{
    if ($trace === null) {
        return;
    }
    trace_write($trace, array(
        'kind'     => 'finish',
        'finished' => gmdate('Y-m-d\TH:i:s\Z'),
    ));
    @fclose($trace['fh']);
}


function trace_prune(string $dir, int $highest): void
{
    $floor = $highest - TRACE_KEEP_RUNS + 1;
    if ($floor < 1) {
        return;
    }
    foreach (scandir($dir) as $name) {
        if (preg_match('/^([0-9]{10})\.trace$/', $name, $m) && (int) $m[1] < $floor) {
            @unlink($dir . '/' . $name);
        }
    }
}
