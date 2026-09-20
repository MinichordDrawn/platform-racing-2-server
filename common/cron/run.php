<?php

// The scheduler's entry point: run a schedule, or record that the ring
// refused it.
//
// Every other short-lived runtime is refused in config.php, which the prepend
// puts in front of every PHP process. That cannot work here, and the reason is
// specific rather than awkward: config.php runs before the job's first line
// and has no idea which schedule it is standing in front of, so it could not
// name the trace it would have to write. It would refuse silently, and a job
// that is refused silently is indistinguishable from a job that has stopped
// running.
//
// That distinction is the whole problem. A halt stops the work, cron jobs are
// work, so a halt stops them; their traces go stale, a stale trace is a fault,
// and a fault is a halt. Any halt that outlived a schedule's period made
// itself permanent -- the thing that stopped the job was the reason the job's
// absence was a problem. A ninety-second blip cost exactly as much as a
// compromise, and both needed a person with a shell.
//
// So the job still runs. It reads the gate, and if the ring says stop it does
// no work and writes a trace saying so. The scheduler demonstrably ran; the
// record says it was refused; `trace-fresh` is satisfied by a fact rather than
// by a concession. As a side effect the traces become a log of every period
// the deployment was stopped, which nothing else keeps.
//
// Run with `-d auto_prepend_file=`, for the same reason as the observer and
// the barrier: config.php is the thing that refuses, and a wrapper standing
// behind it would be refused before it could write the record.

// The schedules, named rather than taken from the command line. An argument
// that reached require() would be an argument that chose which file to
// execute, and this one arrives from a crontab -- which is a file in the
// image, and would be a file an attacker who could write to the image could
// edit.
$schedules = array('minute', 'hourly', 'daily', 'weekly');

$schedule = isset($argv[1]) ? $argv[1] : '';
if (!in_array($schedule, $schedules, true)) {
    fwrite(STDERR, "cron: the first argument must name a schedule\n");
    exit(2);
}

require __DIR__ . '/../observer_gate.php';

// An environment the gate cannot read is not a refusal and must not be
// recorded as one.
//
// A recorded refusal says "the ring told me to stop", and the ring is content
// with that for as long as it likes, because it knows why. A misconfigured
// scheduler that wrote the same record would be a scheduler that never did any
// work and never would, behind a trace that kept every member happy -- and
// this is not hypothetical: cron does not pass the container's environment to
// the jobs it runs, so the first deployment of this wrapper refused every run
// for exactly this reason and reported itself in perfect health.
//
// So it leaves no record. The trace goes stale, the observer fails
// `trace-fresh`, and the deployment stops. Being unable to tell whether you
// should be working is not a reason to say you were told not to.
$settings = observer_gate_settings();
if (is_string($settings)) {
    fwrite(STDERR, "cron: $schedule cannot read the gate: $settings\n");
    exit(2);
}

$reason = observer_gate_reason($settings);

if ($reason !== null) {
    // Where the application writes. config.php is what normally defines this
    // and it is exactly what cannot be loaded here, since it is the thing that
    // refuses -- so the one constant needed is repeated, from the same
    // directory, and a test holds the two expressions to the same value.
    define('CACHE_DIR', dirname(__DIR__, 2) . '/shared');
    require __DIR__ . '/../trace.php';

    if (!trace_halted($schedule, $reason)) {
        // The trace could not be written, so nothing records that this run
        // happened and the observer will find the schedule stale. That is the
        // right outcome rather than a missed one: a scheduler that cannot
        // write is a scheduler nothing can vouch for.
        fwrite(STDERR, "cron: $schedule was refused and could not record it: $reason\n");
        exit(1);
    }

    fwrite(STDERR, "cron: $schedule was refused. $reason\n");
    exit(0);
}

// The ordinary path. config.php asks the gate again, and if it has closed in
// the meantime this process ends here -- before a trace was opened, so it
// leaves no run that started and never finished. The schedule simply does not
// run this period, and the next one records the refusal.
require dirname(__DIR__, 2) . '/config.php';
require __DIR__ . '/' . $schedule . '.php';
