<?php

namespace pr2obs\web;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/procedures.php';
require_once __DIR__ . '/log.php';

// Applying a cycle's decision (SPEC.md sections 5, 12 and 13 steps 8-11).
//
// run_cycle() decides and writes nothing. This does the writing, in the order
// the specification fixes, and logs last.
//
// The order is not housekeeping. The own store is written before the copies
// because the own store is the record and the copies are the redundancy, and
// because a reader compares a copy against its original by reading the copy
// first -- so a copy can be behind its original and never ahead of it, and an
// entry ahead has no race to explain it.

// SPEC 5: write to a staging name in the same directory, then rename. Readers
// never see a half-written file and no reader needs retry logic.
//
// Returns the hash of the file as re-read from disk, or null if the write
// failed. The re-read is not paranoia: the re-read hash and the intended hash
// differ exactly when something went wrong, and the re-read hash is the one
// every other reader will compute.
function publish_atomic(string $path, string $bytes): ?string
{
    $staging = $path . '.tmp';

    $fh = @fopen($staging, 'wb');
    if ($fh === false) {
        return null;
    }
    $written = @fwrite($fh, $bytes);
    @fclose($fh);
    if ($written !== strlen($bytes)) {
        @unlink($staging);
        return null;
    }
    if (!@rename($staging, $path)) {
        @unlink($staging);
        return null;
    }

    $back = read_bytes($path);
    return $back === null ? null : hash_bytes($back);
}

// SPEC 3.1/3.2: one JSON object, the marker as the first key with no
// whitespace before or inside it, and exactly one trailing line feed. The
// fixtures' recorded hashes include that line feed, so a writer that omits it
// computes a different value from every reader.
function heartbeat_bytes(array $fields): string
{
    $object = array(
        'kind'            => KIND_HEARTBEAT,
        'version'         => 1,
        'observer'        => $fields['observer'],
        'sequence'        => $fields['sequence'],
        'timestamp'       => $fields['timestamp'],
        'cadence_seconds' => $fields['cadence_seconds'],
        'checks'          => array_values($fields['checks']),
        'check_count'     => count($fields['checks']),
        'observed'        => (object) $fields['observed'],
        'previous'        => $fields['previous'],
        'boot'            => $fields['boot'],
        'stop'            => $fields['stop'],
    );
    return json_encode($object, JSON_UNESCAPED_SLASHES) . "\n";
}

function fault_bytes(string $observer, int $sequence, string $now, array $failing, array $since): string
{
    $entries = array();
    $earliest = $now;
    foreach ($failing as $f) {
        $key = $f['check'] . '|' . ($f['subject'] ?? '');
        $began = $since[$key] ?? $now;
        if ($began < $earliest) {
            $earliest = $began;
        }
        $entries[] = array(
            'check'   => $f['check'],
            'subject' => $f['subject'],
            'since'   => $began,
            'detail'  => (string) ($f['detail'] ?? ''),
        );
    }
    return json_encode(array(
        'kind'     => KIND_FAULT,
        'version'  => 1,
        'observer' => $observer,
        'sequence' => $sequence,
        'since'    => $earliest,
        'failing'  => $entries,
    ), JSON_UNESCAPED_SLASHES) . "\n";
}

function halt_bytes(string $observer, array $halt, int $sequence, string $now): string
{
    return json_encode(array(
        'kind'     => KIND_HALT,
        'version'  => 1,
        'observer' => $observer,
        'reason'   => $halt['reason'],
        'subject'  => $halt['subject'],
        'sequence' => $sequence,
        'when'     => $now,
        'detail'   => (string) ($halt['detail'] ?? ''),
    ), JSON_UNESCAPED_SLASHES) . "\n";
}

// Where this observer writes its copies: the one peer whose copy/ it owns, and
// its own directory in the super store.
function copy_targets(string $stores, string $identity): array
{
    $out = array();
    foreach (array('web', 'multi', 'policy') as $store) {
        if (copy_author_of($store) === $identity) {
            $out[] = join_path($stores, $store, 'copy');
        }
    }
    $out[] = join_path($stores, 'super', 'copy-' . $identity);
    return $out;
}

/**
 * Write what the cycle decided, then log.
 *
 * $state is the daemon's own record and is updated in place: memory, basis,
 * the since-times of failing assertions, and any finding deferred to the next
 * cycle.
 */
function apply_cycle(array &$state, array $plan): void
{
    $stores   = $state['stores'];
    $identity = $state['identity'];
    $window   = $state['params']['window'];
    $now      = gmdate('Y-m-d\TH:i:s\Z');

    $deferred = array();

    // --- 8. the heartbeat -------------------------------------------------
    //
    // First, and before anything that could take time. A heartbeat is the
    // registration and the liveness signal at once, and an observer that waits
    // for its checks to pass before registering is silent exactly when it is
    // most needed.
    $seq = $plan['publish']['sequence'];
    $hb_dir = join_path($stores, $identity, 'heartbeat');
    if (!is_dir($hb_dir)) {
        @mkdir($hb_dir, 0775, true);
    }
    $hb_path = join_path($hb_dir, heartbeat_name($seq));
    $hb_rel  = $identity . '/heartbeat/' . heartbeat_name($seq);

    $bytes = heartbeat_bytes(array(
        'observer'        => $identity,
        'sequence'        => $seq,
        'timestamp'       => $now,
        'cadence_seconds' => $state['params']['cadence_seconds'],
        'checks'          => $plan['publish']['checks'],
        'observed'        => $plan['publish']['observed'],
        'previous'        => $plan['publish']['previous'],
        'boot'            => $plan['publish']['boot'] === null
            ? null
            : array('started' => $state['started'], 'resumed_from' => $plan['publish']['boot']['resumed_from']),
        'stop'            => $plan['publish']['stop'],
    ));

    $hash = publish_atomic($hb_path, $bytes);
    if ($hash === null) {
        $deferred[] = array('check' => 'own-store-writable', 'subject' => null,
                            'detail' => 'the heartbeat could not be published');
    } else {
        $state['memory'][$hb_rel] = $hash;
        if ($hash !== hash_bytes($bytes)) {
            // SPEC 5: use the re-read hash regardless, because that is what
            // every other reader will compute -- but say that the write did
            // not land as intended.
            $deferred[] = array('check' => 'own-store-writable', 'subject' => null,
                                'detail' => 'the published bytes differ from those written');
        }
    }

    // --- 9. the fault -----------------------------------------------------
    //
    // Published when the set of failing check/subject pairs changes, not every
    // cycle. A file that only changes when the condition changes has stable
    // bytes, which is what makes its copies comparable.
    $fault_path = join_path($stores, $identity, 'fault');
    $fault_rel  = $identity . '/fault';

    $set = array();
    foreach ($plan['failing'] as $f) {
        $set[] = $f['check'] . '|' . ($f['subject'] ?? '');
    }
    sort($set, SORT_STRING);

    foreach ($set as $key) {
        if (!isset($state['since'][$key])) {
            $state['since'][$key] = $now;
        }
    }
    foreach (array_keys($state['since']) as $key) {
        if (!in_array($key, $set, true)) {
            unset($state['since'][$key]);
        }
    }

    if (count($set) === 0) {
        if (is_file($fault_path)) {
            @unlink($fault_path);
        }
        unset($state['memory'][$fault_rel]);
    } elseif ($set !== ($state['failing_set'] ?? null) || !is_file($fault_path)) {
        $fb = fault_bytes($identity, $seq, $now, $plan['findings'], $state['since']);
        $fh = publish_atomic($fault_path, $fb);
        if ($fh !== null) {
            $state['memory'][$fault_rel] = $fh;
        }
    }
    $state['failing_set'] = count($set) === 0 ? null : $set;

    // --- 10. copies out, then pruning -------------------------------------
    foreach (copy_targets($stores, $identity) as $dir) {
        $chb = join_path($dir, 'heartbeat');
        if (!is_dir($chb)) {
            @mkdir($chb, 0775, true);
        }
        publish_atomic(join_path($chb, heartbeat_name($seq)), $bytes);

        $cf = join_path($dir, 'fault');
        if (count($set) === 0) {
            if (is_file($cf)) {
                @unlink($cf);
            }
        } elseif (is_file($fault_path)) {
            $fb = read_bytes($fault_path);
            if ($fb !== null) {
                publish_atomic($cf, $fb);
            }
        }
    }

    // Pruning happens after the copies are written, so that every reader can
    // find the four most recent entries at every instant. The folder briefly
    // holds five; that transient is deliberate.
    prune_to_window($state, $hb_dir, $identity . '/heartbeat', $seq, $window);
    foreach (copy_targets($stores, $identity) as $dir) {
        prune_to_window($state, join_path($dir, 'heartbeat'), null, $seq, $window);
    }

    // --- 11. halts --------------------------------------------------------
    $others = $plan['others'];

    if (!empty($plan['halt']['writes'])) {
        $hb2 = halt_bytes($identity, $plan['halt'], $seq, $now);

        // 12.1, in order, skipping any file that already exists: a halt is
        // never overwritten.
        $own = join_path($stores, $identity, 'halt');
        if (!is_file($own)) {
            $h = publish_atomic($own, $hb2);
            if ($h !== null) {
                $state['memory'][$identity . '/halt'] = $h;
            }
        }
        $order = array();
        if ($identity !== 'super' && in_array('super', $others, true)) {
            $order[] = 'super';
        }
        foreach (identity_order($others) as $o) {
            if ($o !== 'super') {
                $order[] = $o;
            }
        }
        foreach ($order as $o) {
            $slot = join_path($stores, $o, 'halts', $identity);
            if (!is_file($slot)) {
                $h = publish_atomic($slot, $hb2);
                if ($h !== null) {
                    $state['memory'][$o . '/halts/' . $identity] = $h;
                }
            }
        }
    } elseif (is_array($plan['clears'])) {
        foreach ($plan['clears']['removes'] as $rel) {
            $path = join_path($stores, preg_replace('#^stores/#', '', $rel));
            @unlink($path);
            unset($state['memory'][preg_replace('#^stores/#', '', $rel)]);
        }
    } elseif (!empty($plan['relay']['writes'])) {
        // 12.2: the bytes of the first halt found, copied as found, so the
        // finder's name and reason survive for whoever opens it.
        $src = join_path($stores, preg_replace('#^stores/#', '', $plan['relay']['bytes_of']));
        $bytes_of = read_bytes($src);
        if ($bytes_of !== null) {
            foreach ($plan['relay']['writes'] as $o) {
                $slot = join_path($stores, $o, 'halts', $identity);
                if (!is_file($slot)) {
                    $h = publish_atomic($slot, $bytes_of);
                    if ($h !== null) {
                        $state['memory'][$o . '/halts/' . $identity] = $h;
                    }
                }
            }
        }
    }

    // --- what the next cycle starts from ----------------------------------
    $state['basis']    = array('observed' => $plan['publish']['observed']);
    $state['booted']   = false;
    $state['deferred'] = $deferred;

    // --- the log, and it is last on purpose -------------------------------
    //
    // Logging always follows the halt and never precedes it. The halt is what
    // stops the system; the log only explains it afterwards. Written the other
    // way round, a slow or blocked log write delays the stop, and an observer
    // that died between the two would have recorded the explanation for a stop
    // that never happened.
    log_after_halt($state['log'] ?? false, $identity, $seq, $plan);
}

// The owner prunes its own entries beyond the window, and only those. The
// folder itself is never removed by anyone: its persistence is what makes
// silence legible, because a stale heartbeat means death and no folder means
// never ran here.
function prune_to_window(array &$state, string $folder, ?string $rel_prefix, int $highest, int $window): void
{
    $names = list_dir($folder);
    if ($names === null) {
        return;
    }
    $floor = $highest - $window + 1;
    foreach ($names as $name) {
        if (!is_heartbeat_name($name)) {
            continue;
        }
        if (sequence_of_name($name) >= $floor) {
            continue;
        }
        @unlink(join_path($folder, $name));
        if ($rel_prefix !== null) {
            unset($state['memory'][$rel_prefix . '/' . $name]);
        }
    }
}
