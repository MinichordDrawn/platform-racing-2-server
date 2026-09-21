<?php

namespace pr2obs\multi;

// Reading the store: bytes, hashes, classification, strict parsing.
//
// This observer shares no code with any other observer and loads nothing from
// the application. Everything it needs is in this directory, copied rather
// than imported, because a helper that four observers load is one edit away
// from making all four lie at once.
//
// SPEC.md sections 3 and 4.

// The ring, named rather than discovered.
//
// Membership used to be whatever directories were under the store root. The
// store root is owned by the user the work runs as, so a container could add a
// position to its own observer's ring by creating a directory, and could
// remove one by removing a directory. The second is the dangerous half: a
// position that appears has no heartbeat folder and reads as a member that
// never ran, which is loud, while a position that disappears simply shortens
// the list and every remaining member goes on agreeing with itself about a
// ring that no longer holds it. A member can leave without anybody's assertion
// failing, which is the retirement gap by another route.
//
// The deployment already stated this once, in the gate every runtime reads. A
// fact stated in one place and discovered in another is a fact the two can
// disagree about, and only the discovered one can be made to lie from inside a
// container.
const RING_MEMBERS = array('web', 'multi', 'policy', 'super');

const KIND_HEARTBEAT = 'heartbeat';
const KIND_FAULT     = 'fault';
const KIND_HALT      = 'halt';

// SPEC 3.1: the marker is the first key, in a fixed spelling, so a file can be
// classified from its first bytes without a parser and a truncated file still
// cannot be read as another kind.
function marker(string $kind): string
{
    return '{"kind":"' . $kind . '",';
}

// SPEC 1: identity order is ASCII order. Several procedures produce a *first*
// finding, and every implementation has to walk in the same order or the
// finding differs.
function identity_order(array $identities): array
{
    sort($identities, SORT_STRING);
    return $identities;
}

function join_path(string ...$parts): string
{
    $out = rtrim($parts[0], '/');
    for ($i = 1; $i < count($parts); $i++) {
        $out .= '/' . trim($parts[$i], '/');
    }
    return $out;
}

// Directory entries, names only, excluding . and .., sorted by name. Returns
// null when the directory cannot be listed, which callers distinguish from
// an empty directory.
function list_dir(string $path): ?array
{
    if (!is_dir($path)) {
        return null;
    }
    $entries = @scandir($path);
    if ($entries === false) {
        return null;
    }
    $out = array();
    foreach ($entries as $e) {
        if ($e !== '.' && $e !== '..') {
            $out[] = $e;
        }
    }
    sort($out, SORT_STRING);
    return $out;
}

function read_bytes(string $path): ?string
{
    $bytes = @file_get_contents($path);
    return $bytes === false ? null : $bytes;
}

// SPEC 3.5: SHA-256 over the whole file, as bytes on disk. Never over a
// re-serialisation, never over the buffer the writer intended, never with a
// trailing line feed trimmed. An implementation that does otherwise computes
// different values from every other and accuses every peer of lying.
function hash_bytes(string $bytes): string
{
    return hash('sha256', $bytes);
}

function hash_file_bytes(string $path): ?string
{
    $bytes = read_bytes($path);
    return $bytes === null ? null : hash_bytes($bytes);
}

// SPEC 3.1: classification comes before parsing. This reads only the prefix.
function kind_of_bytes(string $bytes): ?string
{
    foreach (array(KIND_HEARTBEAT, KIND_FAULT, KIND_HALT) as $kind) {
        $m = marker($kind);
        if (strncmp($bytes, $m, strlen($m)) === 0) {
            return $kind;
        }
    }
    return null;
}

function begins_as_heartbeat(string $bytes): bool
{
    return kind_of_bytes($bytes) === KIND_HEARTBEAT;
}

// SPEC 3.1: strict keys. A missing key, an added key, a key of the wrong type
// or a kind that does not match the file's place makes the file malformed,
// which is S5. Tolerance is a place for two implementations to differ, and
// four strict parsers agree where four lenient ones would not.
const KEYS_HEARTBEAT = array(
    'kind', 'version', 'observer', 'sequence', 'timestamp', 'cadence_seconds',
    'checks', 'check_count', 'observed', 'previous', 'boot', 'stop',
);
// The fault is a state file, not an account. Presence is the verdict; the
// failing set is the state. `sequence`, `since` and per-entry `detail` were
// history, and history is the log's job -- dropping them also removes the one
// field that existed only to make a chainless file's copy decidable.
const KEYS_FAULT = array('kind', 'version', 'observer', 'failing');
const KEYS_HALT  = array('kind', 'version', 'observer', 'reason', 'subject', 'sequence', 'when', 'detail');

function is_timestamp($v): bool
{
    return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $v) === 1;
}

function is_hash($v): bool
{
    return is_string($v) && preg_match('/^[0-9a-f]{64}\z/', $v) === 1;
}

// SPEC 3.1: the writer ends the file with exactly one line feed; a reader
// accepts the object with or without one and rejects anything else after it.
function decode_object(string $bytes)
{
    $trimmed = $bytes;
    if (substr($trimmed, -1) === "\n") {
        $trimmed = substr($trimmed, 0, -1);
    }
    if ($trimmed === '' || substr($trimmed, -1) !== '}') {
        return null;
    }
    $value = json_decode($trimmed, true);
    if (!is_array($value) || $value === null) {
        return null;
    }
    // A JSON array decodes to a PHP array too; require an object.
    if (array_key_exists(0, $value) && !array_key_exists('kind', $value)) {
        return null;
    }
    return $value;
}

function keys_exactly(array $value, array $expected): bool
{
    $actual = array_keys($value);
    sort($actual, SORT_STRING);
    $want = $expected;
    sort($want, SORT_STRING);
    return $actual === $want;
}

// Returns the parsed heartbeat, or null if malformed under SPEC 3.2.
// $expected_observer and $expected_sequence come from the file's place.
function parse_heartbeat(string $bytes, string $expected_observer, int $expected_sequence): ?array
{
    $o = decode_object($bytes);
    if ($o === null || !keys_exactly($o, KEYS_HEARTBEAT)) {
        return null;
    }
    if ($o['kind'] !== KIND_HEARTBEAT || $o['version'] !== 1) {
        return null;
    }
    if (!is_string($o['observer']) || $o['observer'] !== $expected_observer) {
        return null;
    }
    if (!is_int($o['sequence']) || $o['sequence'] !== $expected_sequence) {
        return null;
    }
    if (!is_timestamp($o['timestamp'])) {
        return null;
    }
    if (!is_int($o['cadence_seconds']) || $o['cadence_seconds'] < 1) {
        return null;
    }
    if (!is_array($o['checks'])) {
        return null;
    }
    foreach ($o['checks'] as $c) {
        if (!is_string($c)) {
            return null;
        }
    }
    if (!is_int($o['check_count'])) {
        return null;
    }
    if (!is_array($o['observed'])) {
        return null;
    }
    foreach ($o['observed'] as $k => $v) {
        if (!is_string($k) || !($v === null || is_hash($v))) {
            return null;
        }
    }
    if (!($o['previous'] === null || is_hash($o['previous']))) {
        return null;
    }
    // previous may only be null at sequence 1; C7 decides that, not the parser,
    // because a null above 1 is a chain fact (I3) rather than a malformed file.
    if (!($o['boot'] === null || is_array($o['boot']))) {
        return null;
    }
    if (is_array($o['boot'])) {
        if (!keys_exactly($o['boot'], array('started', 'resumed_from'))) {
            return null;
        }
        if (!is_timestamp($o['boot']['started'])) {
            return null;
        }
        if (!($o['boot']['resumed_from'] === null || is_int($o['boot']['resumed_from']))) {
            return null;
        }
    }
    if (!is_bool($o['stop'])) {
        return null;
    }
    return $o;
}

function parse_fault(string $bytes, ?string $expected_observer = null): ?array
{
    $o = decode_object($bytes);
    if ($o === null || !keys_exactly($o, KEYS_FAULT)) {
        return null;
    }
    if ($o['kind'] !== KIND_FAULT || $o['version'] !== 1) {
        return null;
    }
    if (!is_string($o['observer'])) {
        return null;
    }
    if ($expected_observer !== null && $o['observer'] !== $expected_observer) {
        // K7 treats a wrong author as I7 rather than S5, so the caller asks
        // without an expectation and compares itself.
        return null;
    }
    if (!is_array($o['failing']) || count($o['failing']) === 0) {
        return null;
    }
    foreach ($o['failing'] as $f) {
        if (!is_array($f) || !keys_exactly($f, array('check', 'subject'))) {
            return null;
        }
        if (!is_string($f['check']) || !($f['subject'] === null || is_string($f['subject']))) {
            return null;
        }
    }
    return $o;
}

function parse_halt(string $bytes): ?array
{
    $o = decode_object($bytes);
    if ($o === null || !keys_exactly($o, KEYS_HALT)) {
        return null;
    }
    if ($o['kind'] !== KIND_HALT || $o['version'] !== 1) {
        return null;
    }
    if (!is_string($o['observer']) || !is_string($o['reason'])) {
        return null;
    }
    if (!($o['subject'] === null || is_string($o['subject']))) {
        return null;
    }
    if (!is_int($o['sequence']) || !is_timestamp($o['when']) || !is_string($o['detail'])) {
        return null;
    }
    return $o;
}

// SPEC 3.2: heartbeat names are ten zero-padded digits so lexical order equals
// numeric order and a listing is the chain in order.
function heartbeat_name(int $sequence): string
{
    return str_pad((string) $sequence, 10, '0', STR_PAD_LEFT) . '.hb';
}

function is_heartbeat_name(string $name): bool
{
    return preg_match('/^[0-9]{10}\.hb\z/', $name) === 1;
}

function is_heartbeat_staging_name(string $name): bool
{
    return preg_match('/^[0-9]{10}\.hb\.tmp\z/', $name) === 1;
}

function sequence_of_name(string $name): int
{
    return (int) substr($name, 0, 10);
}

// SPEC 3.1 fixes one form, so this reads one form and invents nothing.
//
// `createFromFormat` on its own is not a reader of that rule: without the
// reset flag it fills the unmentioned fields from the current time, it accepts
// trailing data, and it rolls a date that does not exist over into one that
// does -- so a file saying the thirty-first of February parsed, as the third
// of March. A reader that accepts more than the specification allows is a
// reader that will one day disagree with the other five about a file.
//
// The shape check comes first, and the round trip is what refuses the
// roll-over: a value that formats back to something other than what was read
// was not the value that was written.
function parse_timestamp(string $ts): ?int
{
    if (!is_timestamp($ts)) {
        return null;
    }
    $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $ts, new \DateTimeZone('UTC'));
    if ($dt === false || $dt->format('Y-m-d\TH:i:s\Z') !== $ts) {
        return null;
    }
    return $dt->getTimestamp();
}
