<?php

namespace pr2obs\web;

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
    public string $stores;
    public string $identity;
    public array $params;
    public ?array $basis;      // ['observed' => [identity => hash|null]] or null
    public array $memory;      // relative path => hash of what this reader wrote
    public int $now;           // unix seconds
    public array $others = array();

    public array $findings = array();   // ordered: ['check','subject','detail']
    public array $members = array();    // identity => ['verdict','cur','hash','entries','folder_present']
    public array $halts_found = array(); // relative path => bytes, in discovery order

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

    public function param(string $name)
    {
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
    );
    if ($identity === 'super') {
        $common['copy-web'] = 'dir';
        $common['copy-multi'] = 'dir';
        $common['copy-policy'] = 'dir';
    } else {
        $common['copy'] = 'dir';
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
    $out = array('entries' => array(), 'listed' => false);

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


// --- Procedure V: the verdict for subject A (SPEC 8.1) --------------------

function stale_after(Reader $R, int $subject_cadence, int $own_cadence): int
{
    $n = (int) ceil($subject_cadence / max(1, $own_cadence)) + $R->param('stale_slack_cycles');
    return max(1, min($n, $R->param('window')));
}

function procedure_v(Reader $R, string $A, array $own_entries, int $own_cadence): array
{
    $result = array('verdict' => 'unknown', 'observed' => null, 'cur' => null, 'entries' => array());

    // V1 readable.
    if (list_dir($R->storePath($A)) === null) {
        $R->fail('store-readable', $A, 'the store cannot be listed');
        return $result;
    }

    $folder = $R->storePath($A, 'heartbeat');
    $folder_exists = is_dir($folder);
    $c = $folder_exists ? procedure_c($R, $folder, $A) : array('entries' => array(), 'listed' => false);
    $result['entries'] = $c['entries'];

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
    $raw = list_dir($folder) ?? array();
    $highest_named = 0;
    foreach ($raw as $n) {
        if (is_heartbeat_name($n)) {
            $highest_named = max($highest_named, sequence_of_name($n));
        }
    }
    if ($highest_named !== $highest) {
        return $result;   // unknown, observed null
    }

    $cur = $c['entries'][$highest];
    $result['cur'] = $cur;
    $result['observed'] = $cur['hash'];

    // V4 retired.
    if ($cur['parsed']['stop'] === true) {
        $result['verdict'] = 'retired';
        if ($A === 'super') {
            $R->fail('member-fresh', $A, 'the super observer is stopped and is not verifying the stores');
        }
        return $result;
    }

    $H = $R->basis === null ? null : ($R->basis['observed'][$A] ?? null);

    // V5 no basis.
    if ($H === null) {
        $result['verdict'] = is_file($R->storePath($A, 'fault')) ? 'faulted' : 'unknown';
        return $result;
    }

    if ($H === $cur['hash']) {
        // V6 unchanged: count consecutive reader cycles, from its own window
        // downwards, that recorded this same hash.
        $k = 0;
        $own = $own_entries;
        krsort($own, SORT_NUMERIC);
        foreach ($own as $e) {
            $seen = $e['parsed']['observed'][$A] ?? null;
            if ($seen === $cur['hash']) {
                $k++;
            } else {
                break;
            }
        }
        $limit = stale_after($R, $cur['parsed']['cadence_seconds'], $own_cadence);
        if ($k >= $limit) {
            $result['verdict'] = 'stale';
            $R->fail('member-fresh', $A, "unchanged for $k reader cycles");
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
            $R->fail('I1', $A, 'the recorded hash is in no entry of the subject chain');
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
        : array('entries' => array(), 'listed' => false);

    // K4 original.
    $orig_folder = $R->storePath($author, 'heartbeat');
    if (!is_dir($orig_folder)) {
        return;
    }
    $orig = $R->members[$author]['entries'] ?? null;
    if ($orig === null) {
        $orig = procedure_c($R, $orig_folder, $author)['entries'];
    }
    if (count($orig) === 0) {
        return;   // the author's own verdict has already recorded the problem
    }
    $orig_seqs = array_keys($orig);
    $min = $orig_seqs[0];
    $max = $orig_seqs[count($orig_seqs) - 1];

    // K5 each copy entry.
    $any_in_window = false;
    $any_behind = false;
    foreach ($copy['entries'] as $n => $e) {
        if ($n > $max) {
            $R->fail('I7', $author, 'a copy entry ahead of the original');
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
        if ($parsed['sequence'] > $max) {
            $R->fail('I7', $author, 'a fault copy carrying a sequence the author has not reached');
            return;
        }
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
                $R->fail('I1', $B, "records a state of $C that $C never published");
            }
        }
    }
}
