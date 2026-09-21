<?php

namespace pr2obs\super;

require_once __DIR__ . '/store.php';

// The procedures of SPEC.md sections 6.1, 8.1, 9 and 10.2, and the store-root
// shape rules of 11.2.
//
// Findings are collected in evaluation order and never sorted. The order is
// part of the specification: `failing` is judged as a set, but the halt names
// the *first* failing assertion, so an implementation that collects findings
// in a different order halts for a different reason than its peers and the
// person reading the halt is told the wrong thing.

class Reader
{
    public $stores;
    public $identity;
    public $params;
    public $basis;      // ['observed' => [identity => hash|null]] or null
    public $memory;      // relative path => hash of what this reader wrote
    public $now;           // unix seconds
    public $others = array();
    // Per subject, how long its heartbeat has been unchanged, in seconds, as
    // of the start of this cycle. Measured by this reader on its own
    // monotonic clock and held in process.
    public $unchanged = array();

    public $findings = array();   // ordered: ['check','subject','detail']

    // Whether the work in this container was running when this cycle looked:
    // true, false, or null if it has not looked or there is no work to look
    // for. It is an answer rather than the absence of a finding, because "the
    // work was not reported" is true both when it is running and when it was
    // not yet due, and only one of those should start the caller's latch.
    public $work_alive = null;

    public $members = array();    // identity => ['verdict','cur','hash','entries','folder_present']
    public $halts_found = array(); // relative path => bytes, in discovery order

    public function __construct(string $stores, string $identity, array $params, ?array $basis, array $memory)
    {
        $this->stores = rtrim(str_replace('\\', '/', $stores), '/');
        $this->identity = $identity;
        $this->params = $params;
        $this->basis = $basis;
        $this->memory = $memory;
        $ts = parse_timestamp($params['now']);
        $this->now = $ts === null ? 0 : $ts;
    }

    public function rel(string $abs): string
    {
        $abs = str_replace('\\', '/', $abs);
        if (strpos($abs, $this->stores . '/') === 0) {
            return substr($abs, strlen($this->stores) + 1);
        }
        return $abs;
    }

    public function fail(string $check, $subject, string $detail = ''): void
    {
        $this->findings[] = array('check' => $check, 'subject' => $subject, 'detail' => $detail);
    }

    // Strict on purpose. An unknown parameter used to become null and then
    // zero, which silently turned off whatever it governed -- the trace
    // due-check, in the case that found this. A missing parameter is a
    // programming error, and an observer running on a value nobody set is an
    // observer that cannot be trusted about what it checked.
    public function param(string $name)
    {
        if (!array_key_exists($name, $this->params)) {
            throw new \RuntimeException("observer: no parameter '$name'");
        }
        return $this->params[$name];
    }

    public function storePath(string $identity, string ...$rest): string
    {
        return join_path($this->stores, $identity, ...$rest);
    }
}


// --- the permitted entries of a store root (SPEC 11.2) --------------------

function store_root_permitted(string $identity): array
{
    $common = array(
        'heartbeat' => 'dir',
        'fault'     => 'file',
        'fault.tmp' => 'file',
        'halt'      => 'file',
        'halt.tmp'  => 'file',
        'halts'     => 'dir',
        // When this observer first began. Written once and never again, which
        // is what makes it a clock rather than an uptime. See
        // observing_since_at().
        'since'     => 'file',
        'since.tmp' => 'file',
    );
    if ($identity === 'super') {
        $common['copy-web'] = 'dir';
        $common['copy-multi'] = 'dir';
        $common['copy-policy'] = 'dir';
    } else {
        // copy/ holds the cycle peer's heartbeat; copy-super/ holds the super
        // observer's. Two writers, two directories, one writer each.
        $common['copy'] = 'dir';
        $common['copy-super'] = 'dir';
    }
    return $common;
}

// Checks one directory's entries against a permitted map. Shared by the store
// root, a copy root and halts/, because the rule is the same everywhere: write
// access to a directory is write access to whatever a directory can hold.
function check_entries(Reader $R, string $dir, array $permitted): void
{
    $entries = list_dir($dir);
    if ($entries === null) {
        return;
    }
    foreach ($entries as $name) {
        $path = join_path($dir, $name);
        $rel = $R->rel($path);
        $expected = $permitted[$name] ?? null;

        if ($expected === null) {
            // SPEC 3.1 / I6: a heartbeat somewhere its owner does not write
            // takes precedence over the name rule for the same file.
            if (is_file($path)) {
                $bytes = read_bytes($path);
                if ($bytes !== null && begins_as_heartbeat($bytes)) {
                    $R->fail('I6', $rel, 'a heartbeat where its owner does not write');
                    continue;
                }
            }
            if (is_dir($path)) {
                $R->fail('S2', $rel, 'a directory where none is permitted');
            } else {
                $R->fail('S1', $rel, 'a name not permitted at this place');
            }
            continue;
        }

        if ($expected === 'dir' && !is_dir($path)) {
            $bytes = is_file($path) ? read_bytes($path) : null;
            if ($bytes !== null && begins_as_heartbeat($bytes)) {
                $R->fail('I6', $rel, 'a heartbeat where a directory is permitted');
            } else {
                $R->fail('S1', $rel, 'a file where a directory is permitted');
            }
        } elseif ($expected === 'file' && is_dir($path)) {
            $R->fail('S2', $rel, 'a directory where a file is permitted');
        }
    }
}

function check_store_root(Reader $R, string $identity): void
{
    check_entries($R, $R->storePath($identity), store_root_permitted($identity));
}


// --- Procedure C: is a heartbeat folder a well-formed chain? (SPEC 6.1) ---
//
// Returns ['entries' => [sequence => ['name','path','bytes','hash','parsed']],
//          'listed'  => bool]
// Every step that can run does run; a parse failure for one entry does not
// stop the chain rules over the entries that did parse.
function procedure_c(Reader $R, string $folder, string $author): array
{
    // `names` is every heartbeat-named entry the listing showed; `entries`
    // is the subset that could be read and parsed. They differ when an entry
    // is pruned between the listing and the read, which is ordinary at any
    // cycle rate and common at a fast one.
    $out = array('entries' => array(), 'names' => array(), 'listed' => false);

    $names = list_dir($folder);
    if ($names === null) {
        return $out;
    }
    $out['listed'] = true;

    $heartbeats = array();
    $staging = array();

    // C1 names.
    foreach ($names as $name) {
        $path = join_path($folder, $name);
        $rel = $R->rel($path);

        if (is_dir($path)) {
            $R->fail('S2', $rel, 'a subdirectory in a heartbeat folder');
            continue;
        }
        if (is_heartbeat_name($name)) {
            $heartbeats[] = $name;
            $out['names'][] = sequence_of_name($name);
            continue;
        }
        if (is_heartbeat_staging_name($name)) {
            $staging[] = $name;
            continue;
        }
        $bytes = read_bytes($path);
        if ($bytes !== null && begins_as_heartbeat($bytes)) {
            $R->fail('I6', $rel, 'a heartbeat under a name that is not a heartbeat place');
        } else {
            $R->fail('S1', $rel, 'a name not permitted in a heartbeat folder');
        }
    }

    sort($heartbeats, SORT_STRING);
    sort($staging, SORT_STRING);

    // C2 counts.
    $window = $R->param('window');
    if (count($heartbeats) > $window + 1) {
        $R->fail('S3', $R->rel($folder), 'more than WINDOW + 1 heartbeat entries');
    }
    if (count($staging) > 1) {
        $R->fail('S3', $R->rel($folder), 'more than one staging file');
    }

    // C3 staging age. A crash between create and rename is benign, so this is
    // a fault; a fresh staging file is nothing at all.
    foreach ($staging as $name) {
        $path = join_path($folder, $name);
        $mtime = @filemtime($path);
        if ($mtime !== false && $mtime < $R->now - $R->param('staging_stale_after_seconds')) {
            $R->fail('staging-fresh', $R->rel($path), 'a staging file older than the threshold');
        }
    }

    // C4 sizes, before any file is read.
    $readable = array();
    foreach ($heartbeats as $name) {
        $path = join_path($folder, $name);
        $size = @filesize($path);
        if ($size !== false && $size > $R->param('max_heartbeat_bytes')) {
            $R->fail('S4', $R->rel($path), 'a heartbeat larger than the bound');
            continue;
        }
        $readable[] = $name;
    }

    // C5 parse.
    foreach ($readable as $name) {
        $path = join_path($folder, $name);
        $seq = sequence_of_name($name);
        $bytes = read_bytes($path);
        if ($bytes === null) {
            // Gone between the listing and the read. The owner prunes its own
            // folder every cycle, so this is the ordinary race and not a
            // malformed file -- and calling it S5 would be a compromise fired
            // by a benign event, which is the one thing the compromise set
            // must never do. A file that is still there and still cannot be
            // read is a different matter.
            if (!is_file($path)) {
                continue;
            }
            $R->fail('S5', $R->rel($path), 'unreadable');
            continue;
        }
        $parsed = parse_heartbeat($bytes, $author, $seq);
        if ($parsed === null) {
            $R->fail('S5', $R->rel($path), 'does not parse as a heartbeat of this author and sequence');
            continue;
        }
        if ($parsed['check_count'] !== count($parsed['checks'])
            || count(array_unique($parsed['checks'])) !== count($parsed['checks'])
        ) {
            $R->fail('I2', $author, 'check count disagrees with the check set');
        }
        $out['entries'][$seq] = array(
            'name'   => $name,
            'path'   => $path,
            'bytes'  => $bytes,
            'hash'   => hash_bytes($bytes),
            'parsed' => $parsed,
        );
    }

    ksort($out['entries'], SORT_NUMERIC);
    $seqs = array_keys($out['entries']);

    // C6 consecutive.
    for ($i = 1; $i < count($seqs); $i++) {
        if ($seqs[$i] !== $seqs[$i - 1] + 1) {
            $R->fail('I3', $author, 'a gap in the window');
            break;
        }
    }

    // C7 chained.
    foreach ($out['entries'] as $seq => $e) {
        $prev = $out['entries'][$seq - 1] ?? null;
        if ($prev !== null) {
            if ($e['parsed']['previous'] !== $prev['hash']) {
                $R->fail('I3', $author, 'a previous link that does not match its predecessor');
                break;
            }
            continue;
        }
        // The lowest entry present: its predecessor may have been pruned. A
        // non-null value that cannot be checked is accepted; a null above
        // sequence 1 is a chain that restarted without restarting its
        // numbering.
        if ($seq === $seqs[0]) {
            if ($seq === 1 && $e['parsed']['previous'] !== null) {
                $R->fail('I3', $author, 'sequence 1 carries a previous link');
                break;
            }
            if ($seq > 1 && $e['parsed']['previous'] === null) {
                $R->fail('I3', $author, 'a null previous above sequence 1');
                break;
            }
        }
    }

    // C8 boot records.
    foreach ($out['entries'] as $seq => $e) {
        $boot = $e['parsed']['boot'];
        if ($boot === null) {
            continue;
        }
        $expected = $seq > 1 ? $seq - 1 : null;
        if ($boot['resumed_from'] !== $expected) {
            $R->fail('I3', $author, 'a boot record whose resumed_from is not sequence - 1');
            break;
        }
    }

    // C9 stops.
    foreach ($out['entries'] as $seq => $e) {
        if ($e['parsed']['stop'] !== true) {
            continue;
        }
        $next = $out['entries'][$seq + 1] ?? null;
        if ($next !== null && $next['parsed']['boot'] === null) {
            $R->fail('I3', $author, 'a heartbeat following a deliberate stop without a boot record');
            break;
        }
    }

    return $out;
}


// Is this observer's store still its own?
//
// The judgement, with the stat left to the caller so that it can be asked
// without a filesystem. Two different failures, and the reason says which:
// an owner that is not this process is a volume that came back wrong, and a
// mode that lets group or other write is a permission that was widened.
//
// Returns null when the directory is this observer's alone, or a short reason.
function own_store_private(int $owner, int $me, int $mode): ?string
{
    if ($owner !== $me) {
        return 'owned by ' . $owner . ' rather than by this observer';
    }
    if (($mode & 0022) !== 0) {
        return 'writable by somebody other than this observer';
    }
    return null;
}


// The same, asked of every path in this observer's own store tree.
//
// The work beside this observer runs as a different user, and that separation
// is made when the image is built: the store belongs to the observer's user,
// so the work can read it and not write it. Nothing noticed if that stopped
// being true -- a volume recreated from an image whose ownership had drifted
// would come back writable by the work, and `own-store-writable` would still
// pass, because the observer could still write it. The question nobody asked
// was whether anyone *else* could.
//
// Every entry, not just the root: the heartbeat folder is where a forgery
// would go, and the halts folder is where this member's peers relay to it.
//
// Returns null if the whole tree is this observer's alone, or the first path
// that is not, with the reason.
function own_store_private_at(string $stores, string $identity): ?string
{
    $root = join_path(rtrim(str_replace(chr(92), '/', $stores), '/'), $identity);
    if (!is_dir($root)) {
        return $identity . ': the store is not there';
    }

    $me = posix_geteuid();
    $stack = array($root);

    while ($stack) {
        $dir = array_pop($stack);

        $owner = @fileowner($dir);
        $perms = @fileperms($dir);
        if ($owner === false || $perms === false) {
            return substr($dir, strlen($root) - strlen($identity)) . ': cannot be examined';
        }

        $why = own_store_private($owner, $me, $perms & 0777);
        if ($why !== null) {
            return substr($dir, strlen($root) - strlen($identity)) . ': ' . $why;
        }

        foreach (@scandir($dir) ?: array() as $e) {
            if ($e !== '.' && $e !== '..' && is_dir($dir . '/' . $e)) {
                $stack[] = $dir . '/' . $e;
            }
        }
    }

    return null;
}


// When this observer began observing, as a UTC timestamp, or null when that
// cannot be established.
//
// The trace checks need to know whether a schedule has had time to run at all,
// and the answer has to survive a restart: container uptime is reset by a
// bounce, so a schedule that is never scheduled would be granted a fresh
// period of grace every time the container came back, and the one case those
// checks exist for would never be reported.
//
// It used to be worked out as the published sequence times the cadence. That
// is not a duration. run.php runs the next cycle as soon as the last one ends,
// floored at OBSERVER_MIN_CYCLE_MS and not paced at the cadence, so cycles
// arrive roughly twenty times faster than the cadence and the product ran
// twenty times fast. A deployment five minutes old believed it had been
// observing for an hour and a half, decided the hourly schedule was overdue,
// and halted itself. run.php:323-325 already said a count of cycles is not a
// unit of time; this is the other half of the tree agreeing with it.
//
// A wall-clock start, stored once, counts the time a deployment was down as
// well as the time it was up. That is the right answer for the question being
// asked: a daily job that has never once run in a week is broken whether or
// not the host was switched off for some of it.
function observing_since_at(string $stores, string $identity): ?int
{
    $raw = @file_get_contents(join_path($stores, $identity, 'since'));
    if ($raw === false) {
        return null;
    }
    // Read by the same parser as every other timestamp in the tree, which is
    // strict about the shape and round-trips what it parses. A seventh reader
    // of one format is how the last two of these went wrong.
    return parse_timestamp(trim($raw));
}


// SPEC 13.3: the own store is proved writable by creating and removing a
// staging file in the observer's own heartbeat folder. The publication a few
// steps later would also prove it, but only after the fact -- and a finding
// the cycle can act on is worth more than one the next cycle inherits.
function own_store_writable_at(string $stores, string $identity): bool
{
    $dir = join_path(rtrim(str_replace(chr(92), '/', $stores), '/'), $identity, 'heartbeat');
    if (!is_dir($dir)) {
        return false;
    }
    // The probe writes a *staging* file, which is what SPEC 13.3 says and is
    // not a detail. A heartbeat folder permits exactly two kinds of name, and
    // a probe called anything else is an unexpected name -- which every peer
    // reads as S1, a compromise, fired by the observer's own housekeeping.
    //
    // The name used is the one the next publication will stage under, so what
    // is proved is precisely the write that is about to happen. A fresh
    // staging file is nothing at all to a reader; one left behind by a crash
    // is a stale staging file, which is a fault, which is the designed signal
    // for a writer that died mid-publish.
    $next = 1;
    foreach ((list_dir($dir) ?? array()) as $name) {
        if (is_heartbeat_name($name)) {
            $next = max($next, sequence_of_name($name) + 1);
        }
    }
    $probe = join_path($dir, heartbeat_name($next) . '.tmp');
    $fh = @fopen($probe, 'wb');
    if ($fh === false) {
        return false;
    }
    @fclose($fh);
    return @unlink($probe);
}

// --- Procedure V: the verdict for subject A (SPEC 8.1) --------------------

// How long a subject may go unchanged before it is stale, in seconds.
//
// This was a count of reader cycles taken from the reader's own on-disk
// window. That worked while cycles were paced to the cadence; once a cycle
// starts the moment the previous one ends, a reader's cycles stop being a
// unit of time at all -- it can turn sixty of them in the interval a peer
// takes to write once, and a four-entry window cannot express six seconds.
//
// So it is a duration, measured by this reader on its own monotonic clock
// against the cadence the subject declares. That is still not two hosts'
// clocks compared, which is the thing the design forbids and the reason no
// verdict reads a timestamp: it is one reader timing its own observations.
function stale_after_seconds(Reader $R, int $subject_cadence): float
{
    return $subject_cadence * (1 + $R->param('stale_slack_cycles'));
}

function procedure_v(Reader $R, string $A, array $own_entries, int $own_cadence): array
{
    $result = array('verdict' => 'unknown', 'observed' => null, 'cur' => null,
                    'entries' => array(), 'names' => array());

    // V1 readable.
    if (list_dir($R->storePath($A)) === null) {
        $R->fail('store-readable', $A, 'the store cannot be listed');
        return $result;
    }

    $folder = $R->storePath($A, 'heartbeat');
    $folder_exists = is_dir($folder);
    $c = $folder_exists ? procedure_c($R, $folder, $A) : array('entries' => array(), 'names' => array(), 'listed' => false);
    $result['entries'] = $c['entries'];
    $result['names'] = $c['names'];

    // V2 present.
    if (!$folder_exists || count($c['entries']) === 0) {
        // A folder present but holding no parseable entry is still "present"
        // for the purposes of V2 only when it holds no .hb entry at all; a
        // folder whose entries failed to parse is handled by V3.
        $raw = $folder_exists ? list_dir($folder) : null;
        $has_hb_name = false;
        foreach (($raw ?? array()) as $n) {
            if (is_heartbeat_name($n)) {
                $has_hb_name = true;
                break;
            }
        }
        if (!$folder_exists || !$has_hb_name) {
            $result['verdict'] = 'absent';
            $known = $R->basis !== null && ($R->basis['observed'][$A] ?? null) !== null;
            if ($known) {
                // The compromise is recorded before the fault, so that when
                // both fire the halt names the compromise.
                if (!$folder_exists) {
                    $R->fail('I4', $A, 'a folder that existed is absent');
                } else {
                    $R->fail('I1', $A, 'the recorded hash is nowhere in an empty chain');
                }
            }
            $R->fail('member-present', $A, 'no heartbeat folder, or no entry in it');
            return $result;
        }
    }

    // V3 chain.
    if (count($c['entries']) === 0) {
        return $result;   // highest did not parse: unknown, observed null
    }
    $seqs = array_keys($c['entries']);
    $highest = $seqs[count($seqs) - 1];

    // The highest *named* entry must be the highest parsed one, or the
    // highest did not parse.
    //
    // From procedure C's own listing, not a fresh one. Listing again asks the
    // question of a later instant than the one the entries were read at, so a
    // subject that published in between disagreed with itself and the reader
    // declined to judge it -- publishing a null observation for a member that
    // was doing nothing worse than being quick. The fastest member was skipped
    // most often, which is the wrong way round.
    //
    // One listing keeps what this is for: an entry that is named but did not
    // parse still shows up, because `names` holds every heartbeat name the
    // listing saw and `entries` only those that could be read and parsed.
    $highest_named = 0;
    foreach ($c['names'] as $seq) {
        $highest_named = max($highest_named, $seq);
    }
    if ($highest_named !== $highest) {
        return $result;   // unknown, observed null
    }

    $cur = $c['entries'][$highest];
    $result['cur'] = $cur;
    $result['observed'] = $cur['hash'];

    // V4 retired.
    //
    // Retirement changes the reason, not the verdict. The stop record exists
    // so that whoever reads the stores afterwards can tell a deliberate
    // shutdown from a death, and the verdict below still carries that. What it
    // must not do is excuse the member's absence: a member that has stopped is
    // not verifying the stores, which is the same fact about the ring as a
    // member that has died, and the ring acts on the fact.
    //
    // This once reported only when the subject was the super observer, and the
    // three members it did not name are exactly the three with work in their
    // container. An observer and the work beside it share a container and a
    // user, so the work can signal the observer, and the observer answers a
    // signal by publishing the one record that told every peer to stop caring.
    // A compromised container could retire its own observer and go unobserved
    // while every peer reported the ring healthy.
    //
    // The cost is that stopping an observer deliberately stops the work it was
    // watching. That is the right price: it leaves a container unobserved, and
    // nothing here may run unobserved for any reason. The halt lifts by itself
    // when the member returns.
    if ($cur['parsed']['stop'] === true) {
        $result['verdict'] = 'retired';
        $R->fail('member-fresh', $A, 'the member is stopped and is not verifying the stores');
        return $result;
    }

    // V4b: the absolute backstop.
    //
    // The one place a verdict reads a timestamp. Everything else measures
    // liveness by whether a hash changed, precisely so that no verdict depends
    // on two machines agreeing about the time -- but that mechanism has a gap,
    // and this closes it.
    //
    // The gap: a reader that has just started holds no basis, so it cannot
    // tell a live member from one that died an hour ago. Every hash looks new
    // to it. A timestamp needs no basis.
    //
    // It is safe to read one here because it is a backstop with a generous
    // threshold, not the primary test. Against a six-second ceiling, eight
    // seconds leaves a second or two of clock skew making no difference. A
    // heartbeat older than that is not skew, it is a member that has stopped.
    $written = parse_timestamp($cur['parsed']['timestamp']);
    if ($written === null || ($R->now - $written) > $R->param('heartbeat_max_age_seconds')) {
        $result['verdict'] = 'stale';
        $R->fail('member-fresh', $A, sprintf(
            'its heartbeat is %s seconds old, against a maximum of %d',
            $written === null ? 'an unreadable number of' : (string) ($R->now - $written),
            $R->param('heartbeat_max_age_seconds')));
        return $result;
    }

    $H = $R->basis === null ? null : ($R->basis['observed'][$A] ?? null);

    // V5 no basis.
    if ($H === null) {
        $result['verdict'] = is_file($R->storePath($A, 'fault')) ? 'faulted' : 'unknown';
        return $result;
    }

    if ($H === $cur['hash']) {
        // V6 unchanged: how long has it been unchanged, by this reader's own
        // clock, against the cadence this subject declares it will keep?
        $for = $R->unchanged[$A] ?? 0.0;
        $limit = stale_after_seconds($R, $cur['parsed']['cadence_seconds']);
        if ($for >= $limit) {
            $result['verdict'] = 'stale';
            $R->fail('member-fresh', $A,
                sprintf('unchanged for %.1fs against a declared cadence of %ds',
                        $for, $cur['parsed']['cadence_seconds']));
            return $result;
        }
    } else {
        // V7 advanced: the recorded hash must be somewhere in the subject's
        // chain. Outside it is either a lie or a member more than a window
        // behind, and the design gives no benefit of the doubt to either.
        $found = false;
        foreach ($c['entries'] as $e) {
            if ($e['hash'] === $H) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $seqs = array_keys($c['entries']);
            $R->fail('I1', $A, sprintf(
                'V7: recorded %s for %s; its chain holds %s..%s (%d entries), current %s',
                substr($H, 0, 12), $A,
                count($seqs) ? $seqs[0] : '-',
                count($seqs) ? $seqs[count($seqs)-1] : '-',
                count($seqs), substr($cur['hash'], 0, 12)));
            $result['verdict'] = 'unknown';
            return $result;
        }
    }

    // V8 faulted or alive.
    $fault_path = $R->storePath($A, 'fault');
    if (is_file($fault_path)) {
        $result['verdict'] = 'faulted';
        $bytes = read_bytes($fault_path);
        if ($bytes !== null && begins_as_heartbeat($bytes)) {
            $R->fail('I6', $R->rel($fault_path), 'a heartbeat where its owner does not write');
        } elseif ($bytes === null || parse_fault($bytes, $A) === null) {
            $R->fail('S5', $R->rel($fault_path), 'does not parse as a fault of this author');
        }
    } else {
        $result['verdict'] = 'alive';
    }
    return $result;
}


// --- Procedure K: copies (SPEC 9) -----------------------------------------
//
// The copy is read completely before the author's own folder is re-listed.
// The author writes its own store first and its copies second, so a copy read
// in that order can be behind its original and never ahead of it, and an
// entry ahead has no race to explain it.
function procedure_k(Reader $R, string $dir, string $author): void
{
    // K1 readable.
    if (list_dir($dir) === null) {
        $R->fail('copy-readable', $author, 'the copy directory cannot be listed');
        return;
    }

    // K2 root shape.
    check_entries($R, $dir, array('heartbeat' => 'dir', 'fault' => 'file', 'fault.tmp' => 'file'));

    $fault_copy_path = join_path($dir, 'fault');
    $fault_copy_oversized = false;
    if (is_file($fault_copy_path)) {
        $size = @filesize($fault_copy_path);
        if ($size !== false && $size > $R->param('max_fault_bytes')) {
            $R->fail('S4', $R->rel($fault_copy_path), 'a fault copy larger than the bound');
            $fault_copy_oversized = true;
        }
    }
    $staging = join_path($dir, 'fault.tmp');
    if (is_file($staging)) {
        $mtime = @filemtime($staging);
        if ($mtime !== false && $mtime < $R->now - $R->param('staging_stale_after_seconds')) {
            $R->fail('staging-fresh', $R->rel($staging), 'a staging file older than the threshold');
        }
    }

    // K3 heartbeat shape.
    $copy_folder = join_path($dir, 'heartbeat');
    $copy = is_dir($copy_folder)
        ? procedure_c($R, $copy_folder, $author)
        : array('entries' => array(), 'names' => array(), 'listed' => false);

    // K4 original.
    $orig_folder = $R->storePath($author, 'heartbeat');
    if (!is_dir($orig_folder)) {
        return;
    }
    $orig = $R->members[$author]['entries'] ?? null;
    $orig_names = $R->members[$author]['names'] ?? null;
    if ($orig === null) {
        $read = procedure_c($R, $orig_folder, $author);
        $orig = $read['entries'];
        $orig_names = $read['names'];
    }
    if (count($orig) === 0) {
        return;   // the author's own verdict has already recorded the problem
    }
    // Bounded by what the listing showed, not by what could be read. An entry
    // pruned between the two would otherwise lower the ceiling and make a
    // perfectly good copy look like a record the author never published --
    // a compromise fired by the author doing its own housekeeping.
    $orig_names = $orig_names === null || count($orig_names) === 0 ? array_keys($orig) : $orig_names;
    sort($orig_names, SORT_NUMERIC);
    $min = $orig_names[0];
    $max = $orig_names[count($orig_names) - 1];

    // K5 each copy entry.
    $any_in_window = false;
    $any_behind = false;
    // The ceiling above, re-read, and only if something looks above it. See
    // below for why; null until it is needed, so the ordinary case pays for no
    // listing at all.
    $fresh_max = null;
    foreach ($copy['entries'] as $n => $e) {
        if ($n > $max) {
            // An entry above the ceiling, checked again against a ceiling
            // taken now.
            //
            // The ceiling comes from the member pass, at step 4 of the cycle,
            // and the copy is read at step 5 -- so the original was listed
            // *before* the copy, not after it. An author publishes to its own
            // store first and to the copy second, which is the order it is
            // required to use, so an author that advances between those two
            // steps leaves an honest copy sitting one entry above a ceiling
            // that is simply out of date.
            //
            // The design says an entry ahead has no race to explain it. There
            // is one, and it fired five times in eighty-one thousand cycles on
            // a running ring -- most often at the observer that makes the most
            // copy comparisons, which is what a race looks like and what
            // tampering does not. Each one halted the deployment for a cycle,
            // and I7 is in the compromise set, so it must be decidable.
            //
            // Looking again settles it without weakening anything: a forged
            // entry is above every ceiling for ever, and a race is above only
            // the stale one.
            if ($fresh_max === null) {
                $fresh_max = $max;
                foreach ((list_dir($orig_folder) ?? array()) as $name) {
                    if (is_heartbeat_name($name)) {
                        $fresh_max = max($fresh_max, sequence_of_name($name));
                    }
                }
            }
            if ($n > $fresh_max) {
                $R->fail('I7', $author, 'a copy entry ahead of the original');
            }
            continue;
        }
        if (isset($orig[$n])) {
            $any_in_window = true;
            if ($e['bytes'] !== $orig[$n]['bytes']) {
                $R->fail('I7', $author, 'a copy entry differing from the original');
            }
            continue;
        }
        if ($n < $min) {
            $any_behind = true;
        }
    }

    // K6 behind.
    if (!$any_in_window || $any_behind) {
        $R->fail('copy-current', $author, 'the copy holds no entry in the author window, or an unpruned old one');
    }

    // K7 the fault copy.
    $orig_fault = $R->storePath($author, 'fault');
    $orig_present = is_file($orig_fault);
    $copy_present = is_file($fault_copy_path) && !$fault_copy_oversized;

    if (!$copy_present && !$orig_present) {
        return;
    }
    if ($copy_present) {
        $bytes = read_bytes($fault_copy_path);
        if ($bytes !== null && begins_as_heartbeat($bytes)) {
            $R->fail('I6', $R->rel($fault_copy_path), 'a heartbeat where its owner does not write');
            return;
        }
        $parsed = $bytes === null ? null : parse_fault($bytes);
        if ($parsed === null) {
            $R->fail('S5', $R->rel($fault_copy_path), 'does not parse as a fault');
            return;
        }
        if ($parsed['observer'] !== $author) {
            $R->fail('I7', $author, 'a fault copy naming another author');
            return;
        }
        // A fault has no chain, so "a record the author never published" is
        // decidable only by its author. The sequence field that once made a
        // second form of this decidable has been removed: it was history, and
        // it was the only thing keeping an awkward test alive.
        if (!$orig_present) {
            $R->fail('copy-current', $author, 'a fault copy where the author has none');
            return;
        }
        $orig_bytes = read_bytes($orig_fault);
        if ($orig_bytes !== $bytes) {
            $R->fail('copy-current', $author, 'a fault copy differing from the author fault');
        }
        return;
    }
    // copy absent, original present
    $R->fail('copy-current', $author, 'the author has a fault and the copy does not');
}


// --- Procedure H: the owner validates its own halts/ (SPEC 10.2) ----------

function procedure_h(Reader $R): void
{
    $dir = $R->storePath($R->identity, 'halts');

    // H1 readable.
    $names = list_dir($dir);
    if ($names === null) {
        $R->fail('halts-readable', null, 'the halts directory cannot be listed');
        return;
    }

    $permitted = array();
    foreach ($R->others as $w) {
        $permitted[$w] = 'file';
        $permitted[$w . '.tmp'] = 'file';
    }
    // H2 names (I6 takes precedence over S1 for the same file).
    check_entries($R, $dir, $permitted);

    foreach ($names as $name) {
        $path = join_path($dir, $name);
        if (!is_file($path) || !isset($permitted[$name])) {
            continue;
        }
        // H4 staging age.
        if (substr($name, -4) === '.tmp') {
            $mtime = @filemtime($path);
            if ($mtime !== false && $mtime < $R->now - $R->param('staging_stale_after_seconds')) {
                $R->fail('staging-fresh', $R->rel($path), 'a staging file older than the threshold');
            }
            continue;
        }
        // H5 sizes, before the file is read.
        $size = @filesize($path);
        if ($size !== false && $size > $R->param('max_halt_bytes')) {
            $R->fail('S4', $R->rel($path), 'a halt larger than the bound');
            continue;
        }
        // H6 parse. It is in force whether or not it parses.
        //
        // SPEC 3.1: classification comes before parsing. A heartbeat under a
        // permitted halt name is in a place its owner does not write its own,
        // which is I6 and takes precedence over the shape rules for the same
        // file. Parsing first would report it as a malformed halt and lose
        // what it actually is.
        $bytes = read_bytes($path);
        if ($bytes !== null && begins_as_heartbeat($bytes)) {
            $R->fail('I6', $R->rel($path), 'a heartbeat where its owner does not write');
            continue;
        }
        if ($bytes === null || parse_halt($bytes) === null) {
            $R->fail('S5', $R->rel($path), 'does not parse as a halt');
        }
    }
}


// --- I1(b): another member's account (SPEC 11.1) --------------------------
//
// Only accounts from members whose verdict is alive or faulted are judged. An
// account from a stale, retired, absent or unknown member is not current, and
// the reader already holds the fault that explains it -- judging it would fire
// a compromise on a death.
function invariant_1b(Reader $R): void
{
    foreach (identity_order($R->others) as $B) {
        $m = $R->members[$B] ?? null;
        if ($m === null || $m['cur'] === null) {
            continue;
        }
        if ($m['verdict'] !== 'alive' && $m['verdict'] !== 'faulted') {
            continue;
        }
        foreach ($m['cur']['parsed']['observed'] as $C => $claimed) {
            if ($C === $B || $claimed === null) {
                continue;
            }
            $subject = $R->members[$C] ?? null;
            if ($C === $R->identity) {
                continue;   // the reader's own chain is judged by I5
            }
            if ($subject === null || count($subject['entries']) === 0) {
                continue;   // no chain to judge the claim against
            }
            $found = false;
            foreach ($subject['entries'] as $e) {
                if ($e['hash'] === $claimed) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $seqs = array_keys($subject['entries']);
                $R->fail('I1', $B, sprintf(
                    'I1(b): %s (seq %s) records %s for %s; %s chain holds %s..%s',
                    $B, $m['cur']['parsed']['sequence'], substr($claimed, 0, 12), $C, $C,
                    count($seqs) ? $seqs[0] : '-',
                    count($seqs) ? $seqs[count($seqs)-1] : '-'));
            }
        }
    }
}
