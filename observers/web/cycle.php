<?php

namespace pr2obs\web;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/procedures.php';
require_once __DIR__ . '/traces.php';
require_once __DIR__ . '/log.php';
require_once __DIR__ . '/container.php';

// One cycle, in the order SPEC.md section 13 fixes.
//
// The cycle *decides* and returns what it would do; writing is a separate
// step. That is not only for the fixtures: a cycle that computes a plan and
// then applies it can be read, logged and compared, and the decision cannot
// be smeared across the writes.

// Which copy directory each author writes, from the fixed cycle of SPEC 2:
// policy writes into web/copy, web into multi/copy, multi into policy/copy.
function copy_author_of(string $store): string
{
    $cycle = array('web' => 'policy', 'multi' => 'web', 'policy' => 'multi');
    return $cycle[$store];
}

// SPEC 9: an application observer runs K over its own copy/. The super
// observer runs it over all six -- its three copy-*/ and the three copy/ in
// the application stores. That is the whole-view comparison no peer can make.
function copies_for(Reader $R): array
{
    $out = array();
    if ($R->identity === 'super') {
        foreach (array('web', 'multi', 'policy') as $author) {
            $out[] = array('dir' => $R->storePath('super', 'copy-' . $author), 'author' => $author);
        }
        foreach (array('web', 'multi', 'policy') as $store) {
            $out[] = array('dir' => $R->storePath($store, 'copy'), 'author' => copy_author_of($store));
            $out[] = array('dir' => $R->storePath($store, 'copy-super'), 'author' => 'super');
        }
    } else {
        // Its own copy/, written by its cycle peer, and its own copy-super/,
        // written by the super observer. Each application observer is one of
        // three independent validators of the super observer's store.
        $out[] = array(
            'dir'    => $R->storePath($R->identity, 'copy'),
            'author' => copy_author_of($R->identity),
        );
        $out[] = array(
            'dir'    => $R->storePath($R->identity, 'copy-super'),
            'author' => 'super',
        );
    }
    // In identity order of author, then by path, so two copies of the same
    // author are still visited in one fixed order.
    usort($out, function ($a, $b) {
        return $a['author'] === $b['author']
            ? strcmp($a['dir'], $b['dir'])
            : strcmp($a['author'], $b['author']);
    });
    return $out;
}

// Parse the reader's own heartbeat folder without recording findings. The own
// folder is not chain-checked: SPEC 13 step 3 gives the own store I5 and
// procedure H, and step 4 is the *other* members. I5 is what covers this
// folder, and running C over it as well would report one alteration twice.
function own_entries(Reader $R): array
{
    $folder = $R->storePath($R->identity, 'heartbeat');
    $names = list_dir($folder);
    $out = array();
    foreach (($names ?? array()) as $name) {
        if (!is_heartbeat_name($name)) {
            continue;
        }
        $path = join_path($folder, $name);
        $bytes = read_bytes($path);
        if ($bytes === null) {
            continue;
        }
        $parsed = parse_heartbeat($bytes, $R->identity, sequence_of_name($name));
        if ($parsed === null) {
            continue;
        }
        $out[sequence_of_name($name)] = array(
            'name' => $name, 'path' => $path, 'bytes' => $bytes,
            'hash' => hash_bytes($bytes), 'parsed' => $parsed,
        );
    }
    ksort($out, SORT_NUMERIC);
    return $out;
}

function highest_own_name_sequence(Reader $R): int
{
    $names = list_dir($R->storePath($R->identity, 'heartbeat'));
    $highest = 0;
    foreach (($names ?? array()) as $name) {
        if (is_heartbeat_name($name)) {
            $highest = max($highest, sequence_of_name($name));
        }
    }
    return $highest;
}

// SPEC 11.1 I5. The owner compares its store against what it remembers
// writing. This is the only check on the owner's own files, and it is exactly
// the thing a compromised owner would lie about -- which the design records
// as an accepted limit rather than hiding.
function invariant_5(Reader $R): void
{
    foreach ($R->memory as $rel => $hash) {
        $path = join_path($R->stores, $rel);
        if (!is_file($path)) {
            $R->fail('I5', $rel, 'a file this observer published is missing');
            continue;
        }
        if (hash_file_bytes($path) !== $hash) {
            $R->fail('I5', $rel, 'a file this observer published differs from what it wrote');
        }
    }
    // A fault or halt present in the own store that memory does not know about
    // was written by somebody else.
    foreach (array('fault', 'halt') as $name) {
        $rel = $R->identity . '/' . $name;
        $path = join_path($R->stores, $rel);
        if (is_file($path) && !array_key_exists($rel, $R->memory)) {
            $R->fail('I5', $rel, 'a file in the own store that this observer did not write');
        }
    }
}

// SPEC 10.1: a halt is in force when any store holds a root `halt`, or any
// non-staging file inside any `halts/`. Existence, not content. The walk order
// is fixed because the bytes a relayer copies are the first halt it finds.
function halts_in_force(Reader $R, array $members): array
{
    $found = array();
    $walk = function (string $store) use ($R, &$found) {
        $halt = $R->storePath($store, 'halt');
        if (is_file($halt)) {
            $found[] = $R->rel($halt);
        }
        $names = list_dir($R->storePath($store, 'halts'));
        foreach (identity_order($names ?? array()) as $name) {
            if (substr($name, -4) === '.tmp') {
                continue;
            }
            $p = $R->storePath($store, 'halts', $name);
            if (is_file($p)) {
                $found[] = $R->rel($p);
            }
        }
    };

    $walk($R->identity);
    foreach (identity_order(array_values(array_diff($members, array($R->identity)))) as $other) {
        $walk($other);
    }
    return $found;
}

function any_fault_present(Reader $R, array $members): bool
{
    foreach ($members as $m) {
        if (is_file($R->storePath($m, 'fault'))) {
            return true;
        }
    }
    return false;
}

/**
 * Run one cycle and report what it concluded and what it would write.
 *
 * $config: stores, identity, params, basis (array|null), memory (rel => hash),
 *          cadence_seconds (optional, defaults to the observer's own last
 *          declared cadence), booted (bool).
 */
function run_cycle(array $config): array
{
    $began = hrtime(true);

    $R = new Reader(
        $config['stores'],
        $config['identity'],
        $config['params'],
        $config['basis'] ?? null,
        $config['memory'] ?? array()
    );

    // 2. The ring is what the deployment declares it to be, not what happens
    // to be on disk. See RING_MEMBERS. A store root that is missing is a
    // member whose store is missing, which the reads below report; a directory
    // that is not a member is not a member whatever it is named.
    //
    // This replaced enumerating the store roots, and the argument for
    // enumerating was not empty: it let a member appear with no announcement,
    // which is automatic integration, and it made a stranger impossible on the
    // grounds that a stranger has nowhere to write. The second half was wrong
    // -- the store root is writable by the user the work runs as, so a
    // container could add a position to its own ring, and remove one -- and
    // removing one was silent, which is a member leaving with nobody's
    // assertion failing.
    //
    // The first half was right and is the cost. Membership is now stated in
    // this list, in the gate, in the compose file and in the images, and a
    // member cannot join by being deployed. That is a real loss of automatic
    // integration, taken deliberately, because a set that cannot be edited
    // from inside a container is worth more here than one that updates itself.
    $members = identity_order(RING_MEMBERS);
    $R->others = array_values(array_diff($members, array($R->identity)));
    $R->unchanged = $config['unchanged'] ?? array();

    $own = own_entries($R);

    // The highest sequence this observer has published, which is both the
    // basis for the next one and -- times the cadence -- how long this
    // deployment has been observed. Needed before the trace checks, which ask
    // whether a schedule has had time to run at all.
    $highest = highest_own_name_sequence($R);

    $own_cadence = $config['cadence_seconds'] ?? 10;
    if (count($own) > 0) {
        $last = $own[array_key_last($own)];
        $own_cadence = $last['parsed']['cadence_seconds'];
    }

    // 3. Own store.
    //
    // SPEC 13.3: prove the store is writable by creating and removing a
    // staging file, before anything depends on it. Asking now rather than
    // finding out at publication is the whole point -- a finding this cycle
    // can act on is worth more than one the next cycle inherits.
    // The probe itself is a write, so it is done by the process that is
    // allowed to write -- run.php, just before this cycle -- and its result
    // passed in. A fixture is a read-only snapshot and cannot express it,
    // which the fixture set says outright. Absent means not probed.
    if (array_key_exists('own_store_writable', $config) && $config['own_store_writable'] === false) {
        $R->fail('own-store-writable', null, 'the heartbeat folder cannot be written');
    }
    check_store_root($R, $R->identity);
    invariant_5($R);
    procedure_h($R);

    // 4. Each other member, in identity order.
    foreach (identity_order($R->others) as $A) {
        check_store_root($R, $A);
        $R->members[$A] = procedure_v($R, $A, $own, $own_cadence);
    }

    // 5. Copies.
    foreach (copies_for($R) as $c) {
        procedure_k($R, $c['dir'], $c['author']);
    }

    // 6. Third-party accounts.
    invariant_1b($R);

    // 7. Local checks, then traces, then the post-conditions asked
    //    independently of them. The local checks are this observer's own
    //    column: what its container is, and what it must still be.
    //
    //    Run only when a baseline is supplied. The fixture set deliberately
    //    does not cover local checks -- it says so -- because they are
    //    per-observer content with identifiers each observer chooses, and a
    //    fixture is a store on disk with no container around it. run.php
    //    always supplies one, and a test asserts that it does.
    //
    //    work_due answers "should the work in this container have started by
    //    now". The caller owns that clock, because it is the caller that knows
    //    when this process started and whether it has seen the work alive
    //    since. A caller that says nothing -- every fixture, and every test
    //    that runs a cycle against a store on disk -- gets the assertion,
    //    because the default has to be the strict one.
    if (isset($config['container_baseline'])) {
        check_container($R, $config['container_baseline'], $config['work_due'] ?? true);
    }

    if (!empty($config['traces'])) {
        // How long this deployment has actually been observed: the
        // sequence about to be published, times the cadence. Durable
        // across restarts, which container uptime is not.
        $observed_seconds = $highest * (int) $R->param('cadence_seconds');
        check_traces($R, $config['traces_root'], $config['traces'], $observed_seconds);
    }
    if (!empty($config['db'])) {
        check_postconditions($R, $config['db']);
    }

    // 7b. This observer's own cycle, timed against the ceiling it declares.
    //
    // Measured over the work rather than the whole cycle, so that an overrun
    // is known before the heartbeat is published and can be acted on now.
    // Reporting it next cycle would delay it by exactly as long as the overrun
    // that caused it -- longest when it matters most.
    $work = (hrtime(true) - $began) / 1e9;
    if ($work > $R->param('cadence_seconds')) {
        $R->fail('cycle-within-cadence', null,
            sprintf('the cycle took %.2fs against a maximum of %ds',
                    $work, $R->param('cadence_seconds')));
    }

    // --- what the cycle concluded ----------------------------------------

    $verdicts = array();
    $observed = array();
    foreach (identity_order($R->others) as $A) {
        $verdicts[$A] = $R->members[$A]['verdict'];
        $observed[$A] = $R->members[$A]['observed'];
    }

    $in_force = halts_in_force($R, $members);

    // 8. The heartbeat this cycle would publish.
    $previous = null;
    if ($highest > 0) {
        $rel = $R->identity . '/heartbeat/' . heartbeat_name($highest);
        $previous = $R->memory[$rel] ?? hash_file_bytes(join_path($R->stores, $rel));
    }

    $checks = array();
    foreach (identity_order($R->others) as $A) {
        $checks[] = 'store-readable:' . $A;
        $checks[] = 'member-present:' . $A;
        $checks[] = 'member-fresh:' . $A;
    }
    $checks[] = 'own-store-writable';
    $checks[] = 'halts-readable';
    foreach (copies_for($R) as $c) {
        $checks[] = 'copy-readable:' . $c['author'];
        $checks[] = 'copy-current:' . $c['author'];
    }
    foreach (array('I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7', 'S1', 'S2', 'S3', 'S4', 'S5') as $id) {
        $checks[] = $id;
    }
    // This observer's own column: what its container is and must still be.
    foreach (container_local_checks() as $id) {
        $checks[] = $id;
    }
    $checks = array_values(array_unique($checks));

    $publish = array(
        'sequence'    => $highest + 1,
        'previous'    => $previous,
        'observed'    => $observed,
        'boot'        => !empty($config['booted'])
            ? array('resumed_from' => $highest > 0 ? $highest : null)
            : null,
        'stop'        => false,
        'checks'      => $checks,
        'check_count' => count($checks),
    );

    // 11. Halts. Raising, clearing and relaying are exclusive, in that order.
    $failing = array();
    foreach ($R->findings as $f) {
        $failing[] = array('check' => $f['check'], 'subject' => $f['subject']);
    }

    $halt = array('writes' => false);
    $clears = false;
    $relay = array('writes' => array(), 'leaves' => array());

    if (count($R->findings) > 0) {
        // The halt names the first failing assertion in evaluation order. Every
        // failing assertion is in the fault file; the halt is the stop, the
        // fault is the account.
        $first = $R->findings[0];
        $halt = array(
            'writes'  => true,
            'reason'  => $first['check'],
            'subject' => $first['subject'],
        );
    } else {
        // SPEC 12.3: the clear-condition. An application observer also needs
        // the super observer to have stood down; only its standing down lets a
        // halt lift, which is the one asymmetry recovery keeps.
        $clear = !any_fault_present($R, $members);
        if ($R->identity !== 'super' && $clear) {
            $clear = !is_file($R->storePath('super', 'halt'));
        }

        if ($clear) {
            $removes = array();
            if ($R->identity === 'super') {
                foreach (array('web', 'policy', 'multi') as $store) {
                    $p = $R->storePath($store, 'halts', 'super');
                    if (is_file($p)) {
                        $removes[] = 'stores/' . $R->rel($p);
                    }
                }
            } else {
                $peers = array_values(array_diff($R->others, array('super')));
                $peers = array_reverse(identity_order($peers));
                foreach ($peers as $peer) {
                    $p = $R->storePath($peer, 'halts', $R->identity);
                    if (is_file($p)) {
                        $removes[] = 'stores/' . $R->rel($p);
                    }
                }
                $p = $R->storePath('super', 'halts', $R->identity);
                if (is_file($p)) {
                    $removes[] = 'stores/' . $R->rel($p);
                }
            }
            $own_halt = $R->storePath($R->identity, 'halt');
            if (is_file($own_halt)) {
                $removes[] = 'stores/' . $R->rel($own_halt);
            }
            $clears = array('removes' => $removes);
        } elseif (count($in_force) > 0) {
            // SPEC 12.2: relay by receipt. A slot is written where absent and
            // left alone where present, which is the idempotence that keeps a
            // halt from echoing round the ring for ever.
            foreach (identity_order($R->others) as $other) {
                $p = $R->storePath($other, 'halts', $R->identity);
                if (is_file($p)) {
                    $relay['leaves'][] = $other;
                } else {
                    $relay['writes'][] = $other;
                }
            }
            $relay['bytes_of'] = 'stores/' . $in_force[0];
            $relay['own_halt_written'] = false;
        }
    }

    // This function decides and writes nothing. apply.php does the writing, in
    // the order SPEC 13 fixes, and logs last -- because the halt is what stops
    // the system and the log only explains it afterwards.
    return array(
        'verdicts'             => $verdicts,
        'failing'              => $failing,
        'halt_in_force_before' => count($in_force) > 0,
        'halt'                 => $halt,
        'publish'              => $publish,
        'relay'                => $relay,
        'clears'               => $clears,
        'findings'             => $R->findings,
        'others'               => identity_order($R->others),
        // Whether the work was running when this cycle looked. The caller
        // latches it: once the work has been seen, its disappearance is a
        // fault however new the container is.
        'work_alive'           => $R->work_alive,
    );
}
