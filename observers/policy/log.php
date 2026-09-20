<?php

namespace pr2obs\policy;

// The log (SPEC.md 12.4).
//
// Every file this observer writes into a store is state: a fault says an
// assertion is failing now, a halt says the system is stopped now, and
// recovery removes both. Nothing in the store is a history, and nothing should
// be added to it to become one -- a halt file that outlived its condition
// would assert something untrue and stop a healthy system. So the record that
// survives recovery is here.
//
// TWO RULES, AND THE FIRST ONE IS THE POINT.
//
// 1. Logging always follows the halt, never precedes it. The halt is what
//    stops the system; the log only explains it afterwards. In the other
//    order, a slow or blocked log write delays the stop by however long it
//    takes, and an observer that dies between the two has recorded the
//    explanation for a stop that never happened -- a story with no effect.
//    The function below is named for that order so a caller cannot read it
//    and place the call wrongly by accident.
//
// 2. A failure to log never prevents or delays a halt. Logging errors are
//    swallowed. That is a softening and it is the right one: the log is not
//    load-bearing for detection. The store carries the state, the ring reads
//    the store, and nothing in the protocol consults a log. A log that cannot
//    be written is worth almost nothing; a halt that cannot be written because
//    a log was in the way would stop nothing while appearing to work.
//
// The format is not specified anywhere and does not need to be. A log is the
// one thing here no other member reads, so four implementations writing it
// differently cannot disagree about anything. This one writes JSON Lines to
// standard output, where the container's ordinary collection takes it.

// Standard output, opened once and never closed.
//
// The first version of this opened php://stdout per line and closed it again,
// which closes the process's own file descriptor 1: the first line went out
// and every line after it was written to a closed stream. Rule 2 swallowed the
// error, so the observer ran perfectly and logged nothing at all, and only
// looking at a real container's output showed it. A logger that hides its own
// failure is the one place that softening costs something.
function default_sink($set = null)
{
    static $handle = null;
    if ($set !== null) {
        $handle = $set;          // only for exercising this path in a test
        return $handle;
    }
    if ($handle === null) {
        $handle = fopen('php://stdout', 'wb');
    }
    return $handle;
}

// `false` means do not log. `null` means use the default sink.
//
// These were the same branch once, and the observer ran for twenty cycles
// writing a perfect chain and not one line of log, because run.php passes null
// to mean "standard output" and this function read it as "off". Rule 2 swallows
// logging errors, so there was no error to see; the only way to find it was to
// look at a real container's output and notice the silence.
function log_line($sink, string $event, array $fields): void
{
    if ($sink === false) {
        return;
    }

    try {
        $line = json_encode(
            array_merge(array('event' => $event), $fields),
            JSON_UNESCAPED_SLASHES
        );
        if ($line === false) {
            return;
        }
        $line .= "\n";

        if (is_callable($sink)) {
            $sink($line);
            return;
        }
        if (is_resource($sink)) {
            @fwrite($sink, $line);
            return;
        }
        @fwrite(default_sink(), $line);
    } catch (\Throwable $e) {
        // Rule 2. Nothing about a log is worth propagating into a cycle whose
        // job is to stop the system.
        return;
    }
}

/**
 * Record what this cycle decided and did.
 *
 * The name is the contract: this is called *after* the halts of section 12
 * have been written, relayed or cleared, never before and never instead. A
 * caller that moves it earlier has inverted the priority the whole design
 * rests on.
 */
function log_after_halt($sink, string $identity, int $sequence, array $result): void
{
    if ($sink === false) {
        return;
    }

    $base = array('observer' => $identity, 'sequence' => $sequence);

    if (count($result['failing']) > 0) {
        log_line($sink, 'failing', $base + array('set' => $result['failing']));
    }

    if (!empty($result['halt']['writes'])) {
        log_line($sink, 'halt-raised', $base + array(
            'reason'  => $result['halt']['reason'],
            'subject' => $result['halt']['subject'],
        ));
    }

    if (!empty($result['relay']['writes'])) {
        log_line($sink, 'halt-relayed', $base + array(
            'to'       => $result['relay']['writes'],
            'bytes_of' => $result['relay']['bytes_of'] ?? null,
        ));
    }

    if (is_array($result['clears']) && count($result['clears']['removes']) > 0) {
        log_line($sink, 'cleared', $base + array('removed' => $result['clears']['removes']));
    }
}
