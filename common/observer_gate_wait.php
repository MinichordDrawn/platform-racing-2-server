<?php

// The first-write barrier: the work does not begin until the observer on this
// host has written a heartbeat.
//
// At the first moment of a deployment nothing has written anything, so a rule
// that says "stop unless an observer is alive" stops the system on the way up.
// There were two ways out of that -- let the runtimes tolerate an absent
// heartbeat for a while, or make the observer's first heartbeat a precondition
// of serving -- and this is the second. It is the stricter one: it makes the
// observer running a thing the work waits for rather than a thing that catches
// up afterwards.
//
// **It waits for that heartbeat and for nothing else.** An earlier version
// waited for the whole gate -- the ring clear, no halt anywhere -- and
// deadlocked the deployment inside twenty seconds, twice. Each observer
// asserts that the work in its container is running, so waiting for a clear
// ring is waiting for a condition that cannot become true while you wait:
//
//   the barrier waits for the ring to be clear
//   the ring is not clear, because the work is not running
//   the work is not running, because the barrier is waiting
//
// Every statement true, and nothing starts ever again. So this is what its
// name says and no more. Whether the work may then *do* anything is a
// different question, asked continuously afterwards -- per request in
// config.php, per tick in the two long-lived servers, per run in the scheduler
// -- by code that does not have to exit to answer it.
//
// **It has no deadline.** The observer rides in the same container, so a
// barrier that gave up and exited would stop the container, stop the observer
// with it, and produce the very halt it was waiting on. A deployment that sits
// here is one whose observer is not running, and it says so, once a minute, on
// the container's log.
//
// It is run with `-d auto_prepend_file=`, for the same reason the observer is:
// the prepend would put config.php in front of this process, and config.php
// calls the refusal that exits -- so the barrier would refuse to wait, which
// is the one thing it is for.

require __DIR__ . '/observer_gate.php';

$settings = observer_gate_settings();

// A misconfigured environment is not a condition that waiting clears, so it is
// the one thing here that does stop.
if (is_string($settings)) {
    fwrite(STDERR, "observer gate: $settings\n");
    exit(2);
}

$said = null;
$waited = 0;

while (true) {
    $reason = observer_gate_alive(
        $settings['stores'],
        $settings['local'],
        $settings['local_max_age'],
        time()
    );

    if ($reason === null) {
        if ($said !== null) {
            fwrite(STDERR, "observer gate: open after {$waited}s. Starting.\n");
        }
        break;
    }

    // Said once when it changes, and once a minute after that. A barrier that
    // printed every second would bury the reason it is waiting in the reason
    // it is waiting.
    if ($reason !== $said || $waited % 60 === 0) {
        fwrite(STDERR, "observer gate: waiting ({$waited}s). $reason\n");
        $said = $reason;
    }

    sleep(1);
    $waited++;
}
