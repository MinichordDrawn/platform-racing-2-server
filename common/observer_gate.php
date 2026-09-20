<?php

// What the work reads before it does any work.
//
// The observer network spends the whole build writing files nothing reads.
// This is the file that makes a halt mean something: every runtime thread asks
// the store before it serves, and stops if the answer is no.
//
// The rule is not a list of special cases. **Every runtime thread reads two
// observers before it works: the one on its own host, and the super observer.
// If either is dead, or anything anywhere has halted, execution stops.** That
// is the whole of it.
//
// Three questions, in order of decreasing certainty:
//
//   Is my own observer alive?   Its heartbeat's timestamp against now. The
//                               observer and the work share a host and
//                               therefore a clock, so there is no skew to
//                               allow for and the window sits just above the
//                               cadence.
//
//   Has anything halted?        The presence of a halt, anywhere in the tree.
//                               No clock, no judgement -- a file exists or it
//                               does not. The cheapest of the three and the
//                               most important.
//
//   Is the super observer alive? The same reading, across a clock that is not
//                               this one, so the window is looser. This is a
//                               second line: the local observer already judges
//                               the super observer every cycle by a method
//                               that needs no clock at all. Asking directly is
//                               for the case where the local observer is
//                               itself the thing that is wrong.
//
// **This file loads nothing.** Not env.php, not the database, not the S3
// client, nothing from any observer. It runs before all of them, and it has to
// be able to: a deployment whose database is unreachable must still be able to
// find out that it has been told to stop. It is also why the check can sit at
// the very top of config.php, in front of the boot refusal it stands beside.
//
// **It shares no code with any observer**, for the same reason the four
// observers share none with each other. This is a fifth independent reader of
// the same bytes, written from the same specification, and two readers that
// agree are evidence where one reader called twice is not. The pairing test
// feeds it heartbeats the real observer's writer produced.
//
// **What it does not close.** It binds honest code only: the application
// checks because the application is written to check, and an application that
// has been rewritten not to look carries on. Nothing inside the runtime can
// prevent that. It is the first of three halt paths for exactly that reason,
// and it should not be mistaken for the whole of one.


// The ring, named rather than discovered.
//
// A listing of the store tree that comes back short is indistinguishable from
// a tree with nothing wrong in it, and "the member whose store went missing"
// is precisely the case this exists to catch. So the members are a constant
// here and a missing one is a refusal.
function observer_gate_members()
{
    return array('web', 'multi', 'policy', 'super');
}


// What the gate rests on: the paths it reads that the work cannot write.
//
// A file is evidence only if the thing being judged cannot produce it, and
// most of the store tree fails that test from inside a work container. An
// observer and the work it watches share a container, so they share its
// mounts: every path the local observer must write in order to publish, the
// work can write too. Probed from inside a running web container --
//
//   FORGEABLE  /stores/web/heartbeat, /stores/web/halt, /stores/web/fault
//   refused    /stores/web/halts
//   refused    /stores/{multi,policy,super}/heartbeat
//
// -- and no arrangement of flags changes that while the two are in one
// container. Nor does a signing key: a key the observer can read is a key the
// work can read, because they run as the same user and an unprivileged
// container has no way to separate them.
//
// Two reads survive, and between them they cover both directions:
//
//   `<local>/halts`     where every *other* member relays what it found. Read
//                       only in the container it names, so the work can
//                       neither write a halt away nor delete one. This is the
//                       positive signal, and it arrives for anything any
//                       member detects -- including this container's own
//                       observer dying, which its peers report.
//
//   `super/heartbeat`   read only everywhere but the super observer's own
//                       container, so its staleness cannot be concealed. This
//                       is the absence: an observer that dies stops writing,
//                       and what the work needs expires on its own without
//                       anybody having to notice or deliver anything.
//
// The gate still reads the rest, and that is deliberate rather than sloppy.
// The question it answers is *may I work*, so a read the work can tamper with
// can only ever turn a yes into a no. What turns a no into a yes is this set.
function observer_gate_authoritative($local)
{
    $paths = array();
    if ($local !== '' && $local !== 'super') {
        $paths[] = $local . '/halts';
    }
    $paths[] = 'super/heartbeat';
    return $paths;
}


// A heartbeat is bounded (SPEC 3.1) and its size is checked before anything
// reads its content, so an oversized file never reaches the parser. The number
// is the one every observer is deployed with.
const OBSERVER_GATE_MAX_HEARTBEAT_BYTES = 8192;

// SPEC 3.1: the marker is the first key, in a fixed spelling, with no
// whitespace before or within it. A file classified by its first bytes cannot
// be mistaken for a file of another kind even if it has lost its tail.
const OBSERVER_GATE_HEARTBEAT_MARKER = '{"kind":"heartbeat",';


// The settings, from an array of environment values.
//
// Returns the settings, or a string saying what is wrong with them. Nothing is
// defaulted except the store root, which is a path rather than a judgement: a
// gate that defaulted its windows would be deciding, in code, how long the
// work may run unobserved, and the number would live where no review of a
// deployment would ever see it. The observer's own configuration refuses on
// the same grounds.
function observer_gate_settings_from(array $env)
{
    $stores = isset($env['OBSERVER_STORES']) && $env['OBSERVER_STORES'] !== ''
        ? $env['OBSERVER_STORES']
        : '/stores';

    $local = isset($env['OBSERVER_LOCAL']) ? $env['OBSERVER_LOCAL'] : '';
    if (!in_array($local, observer_gate_members(), true)) {
        return 'OBSERVER_LOCAL must name the observer watching this host';
    }

    $ages = array();
    foreach (array(
        'local_max_age' => 'OBSERVER_LOCAL_MAX_AGE_SECONDS',
        'super_max_age' => 'OBSERVER_SUPER_MAX_AGE_SECONDS',
    ) as $key => $name) {
        $raw = isset($env[$name]) ? $env[$name] : '';
        if (preg_match('/^\d+$/', (string) $raw) !== 1 || (int) $raw < 1 || (int) $raw > 3600) {
            return "$name must be set to a whole number of seconds between 1 and 3600";
        }
        $ages[$key] = (int) $raw;
    }

    return array(
        'stores'        => $stores,
        'local'         => $local,
        'local_max_age' => $ages['local_max_age'],
        'super_max_age' => $ages['super_max_age'],
        'now'           => time(),
    );
}


// The same, from this process's environment.
function observer_gate_settings()
{
    $env = array();
    foreach (array(
        'OBSERVER_STORES',
        'OBSERVER_LOCAL',
        'OBSERVER_LOCAL_MAX_AGE_SECONDS',
        'OBSERVER_SUPER_MAX_AGE_SECONDS',
    ) as $name) {
        $raw = getenv($name);
        if ($raw !== false) {
            $env[$name] = $raw;
        }
    }
    return observer_gate_settings_from($env);
}


// A timestamp as SPEC 3.1 fixes it: exactly `YYYY-MM-DDTHH:MM:SSZ`, UTC, whole
// seconds. Anything else is not a timestamp, and a reader that guessed at one
// would be deciding freshness from a string it did not understand.
function observer_gate_time($text)
{
    if (!is_string($text)
        || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})Z$/', $text, $m) !== 1
    ) {
        return null;
    }
    $t = gmmktime((int) $m[4], (int) $m[5], (int) $m[6], (int) $m[2], (int) $m[3], (int) $m[1]);
    return $t === false ? null : $t;
}


// Is this member's observer alive?
//
// Returns null if it is, or a short reason if it is not. Everything that is
// not a well-formed, current heartbeat of this member's own is a reason,
// including the ones that look like accidents: there is no reading of a
// truncated or misplaced heartbeat under which the work should keep going.
function observer_gate_alive($stores, $who, $max_age, $now)
{
    $dir = $stores . '/' . $who . '/heartbeat';

    $names = @scandir($dir);
    if ($names === false) {
        return "$who has no heartbeat folder";
    }

    // The current heartbeat is the highest sequence. The zero-padded names
    // make lexical order numeric order (SPEC 3.2), and a staging name is not
    // a heartbeat: accepting one would mean serving on a publication that has
    // not finished.
    $latest = null;
    foreach ($names as $n) {
        if (preg_match('/^\d{10}\.hb$/', $n) === 1 && ($latest === null || $n > $latest)) {
            $latest = $n;
        }
    }
    if ($latest === null) {
        return "$who has never written a heartbeat";
    }

    $path = $dir . '/' . $latest;
    $size = @filesize($path);
    if ($size === false) {
        return "$who's heartbeat cannot be read";
    }
    if ($size > OBSERVER_GATE_MAX_HEARTBEAT_BYTES) {
        return "$who's heartbeat is larger than a heartbeat can be";
    }

    $bytes = @file_get_contents($path);
    if ($bytes === false) {
        return "$who's heartbeat cannot be read";
    }

    // SPEC 3.1, classification before parsing.
    if (strncmp($bytes, OBSERVER_GATE_HEARTBEAT_MARKER, strlen(OBSERVER_GATE_HEARTBEAT_MARKER)) !== 0) {
        return "$who's current heartbeat does not begin as a heartbeat must";
    }

    $hb = json_decode($bytes, true);
    if (!is_array($hb)) {
        return "$who's heartbeat does not parse";
    }

    foreach (array('version' => 'integer', 'observer' => 'string', 'timestamp' => 'string', 'stop' => 'boolean') as $key => $type) {
        if (!array_key_exists($key, $hb) || gettype($hb[$key]) !== $type) {
            return "$who's heartbeat has no usable $key";
        }
    }
    if ($hb['version'] !== 1) {
        return "$who's heartbeat is of a format this deployment does not read";
    }
    if ($hb['observer'] !== $who) {
        // A heartbeat in one member's folder signed by another is a copied or
        // misplaced file, and the store it was found in has no current
        // heartbeat of its own.
        return "the heartbeat in $who's folder was not written by $who";
    }
    if ($hb['stop'] === true) {
        // A deliberate stop is still a stop. Nothing is watching this host.
        return "$who has stopped";
    }

    $written = observer_gate_time($hb['timestamp']);
    if ($written === null) {
        return "$who's heartbeat is not dated";
    }

    $age = $now - $written;
    if ($age > $max_age) {
        return "$who's heartbeat is " . $age . " seconds old";
    }
    // A clock that disagrees by more than the window disagrees whichever way
    // it runs. Treating a future timestamp as merely fresh would let a wrong
    // clock, or a written file, hold the gate open indefinitely.
    if ($age < -$max_age) {
        return "$who's heartbeat is dated " . (-$age) . " seconds from now";
    }

    return null;
}


// The decision. Returns null if the work may proceed, or a short reason if it
// may not.
//
// It reads and it decides; it writes nothing and it repairs nothing, which is
// the same division the observers keep. The store tree is mounted read-only
// wherever this runs, so a gate that wrote would be refused by the kernel --
// but the reason it does not write is that five different things call it and a
// reader that modified what it read could not be one of them.
function observer_gate_reason(array $settings)
{
    $stores = $settings['stores'];
    $now    = $settings['now'];
    $local  = $settings['local'];

    // --- what the gate rests on -------------------------------------------
    //
    // These two are first because they are the only reads whose answer the
    // work could not have written itself. See observer_gate_authoritative().

    // Anything any member found, relayed into a directory this container is
    // refused a write at. A compromised container cannot delete these.
    if ($local !== '' && $local !== 'super') {
        $halts = @scandir($stores . '/' . $local . '/halts');
        if ($halts === false) {
            return "the halts directory of $local cannot be read";
        }
        foreach ($halts as $n) {
            if ($n !== '.' && $n !== '..') {
                return "a halt found by $n has been delivered to $local";
            }
        }
    }

    // The absence half: an observer that dies or is stopped simply stops
    // writing, and what the work needs expires without anyone noticing or
    // delivering anything. The work cannot refresh this.
    $reason = observer_gate_alive($stores, 'super', $settings['super_max_age'], $now);
    if ($reason !== null) {
        return $reason;
    }

    // --- and everything else ----------------------------------------------
    //
    // Read because reading it can only produce more stops, never fewer: the
    // question is *may I work*, so a forgeable read turns a yes into a no and
    // never the other way. It stops the honest cases faster than waiting for a
    // peer to notice, and it is not what any of this rests on.

    // The observer sharing this container. Forgeable by the work, and kept
    // because in the honest case it is the quickest signal there is -- the
    // observer and the work share a clock, so the window can sit just above
    // the cadence with no allowance for skew.
    if ($local !== '') {
        $reason = observer_gate_alive($stores, $local, $settings['local_max_age'], $now);
        if ($reason !== null) {
            return $reason;
        }
    }

    foreach (observer_gate_members() as $m) {
        $root = $stores . '/' . $m;

        if (!is_dir($root)) {
            return "the store of $m is not there";
        }
        if (file_exists($root . '/halt')) {
            return "$m has halted";
        }
        // A fault is read as well as a halt. It is strictly earlier: a member
        // writes its own fault the moment one of its own assertions fails, and
        // the halt that follows takes another member a cycle to find and
        // write. Reading both narrows the window in which the work carries on
        // after something is already known to be wrong, and costs one more
        // existence check on a path this loop is already standing in. It
        // cannot make a halt unrecoverable, because it is the same condition:
        // the ring stands down only when no store holds a fault, so whatever
        // lifts the halt has already lifted this.
        if (file_exists($root . '/fault')) {
            return "$m is faulted";
        }

        $halts = @scandir($root . '/halts');
        if ($halts === false) {
            return "the halts directory of $m cannot be read";
        }
        foreach ($halts as $n) {
            if ($n !== '.' && $n !== '..') {
                return "a halt found by $n has been delivered to $m";
            }
        }
    }

    return null;
}


// How a refusal reaches whoever asked.
//
// Whoever asked is told nothing about the deployment; whoever runs it gets the
// reason in the log. The same division the boot refusal on unchanged secrets
// keeps, and for the same reason: a stopped server should not explain to a
// stranger which part of it stopped.
function observer_gate_refuse($reason)
{
    error_log('observer gate: refusing to work. ' . $reason);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "observer gate: refusing to work. $reason\n");
        exit(3);
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 30');
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "The server is not running.\n";
    exit;
}


// The command-line entrypoints that are allowed to start while the ring says
// stop.
//
// They are allowed past for the same reason they do not need the boot check:
// each is a process that lives as long as its container and carries a
// continuous check of its own, which stops the work every tick without
// stopping the process. Refusing them here by exiting would end the container
// and the observer inside it, leaving the ring a member short -- a halt every
// remaining member raises and none of them can lift. Stopping the work that
// way would make every stop permanent.
//
// **Nothing is prevented from starting. Everything is prevented from working.**
// A halted game server still listens and refuses every connection, which is
// also what keeps its observer's `process-alive` and `port-answers` true, and
// is therefore what keeps the halt clearable at all.
//
// The entrypoint is named, in code, and there is no way to add to the list
// from outside: a switch that let a process past this would be a switch an
// attacker sets, and this design refuses that shape everywhere else.
function observer_gate_self_checking()
{
    return array('pr2.php', 'run_policy.php');
}


// The whole check, for a caller that starts, does its work and ends: an HTTP
// request, a cron process, a warm-up run.
//
// The scheduler does not use this either, and for a different reason: it needs
// to record that it was refused, and this function runs in front of the job
// without knowing which schedule it is. See common/cron/run.php.
function observer_gate_enforce()
{
    if (PHP_SAPI === 'cli'
        && in_array(basename($_SERVER['SCRIPT_FILENAME'] ?? ''), observer_gate_self_checking(), true)
    ) {
        return;
    }

    $settings = observer_gate_settings();
    if (is_string($settings)) {
        observer_gate_refuse($settings);
        return;
    }

    $reason = observer_gate_reason($settings);
    if ($reason !== null) {
        observer_gate_refuse($reason);
    }
}
