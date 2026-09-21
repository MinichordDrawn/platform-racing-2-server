<?php

namespace pr2obs\super;

require_once __DIR__ . '/store.php';
require_once __DIR__ . '/procedures.php';

// What this container is, and what it must still be.
//
// Trace freshness says work happened. It says nothing about whether the thing
// doing the work is still the thing that was deployed. The images declare a
// great deal -- an interpreter version, a set of extensions, a document root,
// a process, a port -- and everything declared is assertable.
//
// This file is the one part of an observer that is genuinely its own. The
// machinery is identical in every copy; this is the column from the design's
// members table, and it is different in each container because each container
// is different.
//
// Two kinds of check live here and they answer different questions.
//
//   Declared: the value must equal a constant written below. This catches an
//   image that is not what it should be -- the wrong interpreter, a missing
//   extension, debug mode left on.
//
//   Baselined: the value is captured when the observer starts and must not
//   change while it runs. This catches a running container being modified,
//   which has no benign cause at all.
//
// The baseline is taken from the container it is watching, so a tampered image
// baselines itself and this check stays quiet about it. That limit is real and
// recorded in the design: establishing that the image was the intended image
// needs provenance from outside the deployment, and nothing running inside can
// assert it.

// --- the column -----------------------------------------------------------
//
// The super observer's container holds the observer and nothing else. There is
// no application in it, no web server, no game server, no database client --
// which makes the rule that an observer cannot be stopped by a bug in what it
// watches true here by construction rather than by discipline.
//
// So this column is almost empty, and that is the correct shape rather than an
// omission. There is no work process to assert is alive, because the observer
// is the only process. There is no port, because it listens on nothing. There
// is no env.php, because there is no application to configure. What remains is
// what any container can be held to: its interpreter, its extensions, and the
// code it shipped with.

const CONTAINER_PHP_VERSION = '8.2';

// Nothing beyond what PHP builds in. The observer needs hashing and JSON and
// no more, and an extension this image does not install appearing at runtime
// is caught by the baseline rather than by a declaration.
const CONTAINER_EXTENSIONS = array('pcntl');

const CONTAINER_CODE_PATHS = array(
    '/pr2/observers/super',
);

const CONTAINER_NOT_CODE = array();

const CONTAINER_DOCUMENT_ROOT = '';
const CONTAINER_APACHE_MODULES = array();

// Empty on purpose: the observer is this container's only process, so a
// process check here would assert that the thing doing the asserting exists.
const CONTAINER_PROCESS = '';

// It listens on nothing.
const CONTAINER_PORTS = array();

// There is no application here to configure.
const CONTAINER_ENV_FILE    = '';
const CONTAINER_ENV_EXAMPLE = '';

// Every identifier this file can fail. They are all assertions this observer
// makes about its own world, so they all belong in its fault file.
function container_local_checks(): array
{
    // Only what this container can fail. The others are absent because there
    // is nothing here for them to be about, not because they were forgotten.
    //
    // `extensions-declared` was absent on those grounds and stopped belonging
    // there the moment this image declared an extension. A check an observer
    // does not list is not read as a statement about its own world: it halts
    // the ring without writing a fault file, and it is missing from the
    // heartbeat's account of what ran. The halt is the half that matters and
    // happened either way, so what was lost was the record rather than the
    // stop -- but a list that is right only until something is added to the
    // image is a list that has to be checked rather than reasoned about.
    return array(
        'code-unchanged',
        'php-version',
        'extensions-declared',
        'extensions-unchanged',
    );
}


// --- the walk --------------------------------------------------------------

// A symlink is recorded by its target, never followed. Following would walk
// into the data directories the served tree reaches through symlinks, which
// change constantly through ordinary play -- and would make this check
// impossible to write, which is why they were moved out of the code tree.
function walk_code(string $path, array &$out): void
{
    foreach (CONTAINER_NOT_CODE as $skip) {
        if ($path === $skip) {
            return;
        }
    }
    if (is_link($path)) {
        $out[$path] = 'link:' . readlink($path);
        return;
    }
    if (is_dir($path)) {
        $entries = @scandir($path);
        if ($entries === false) {
            return;
        }
        sort($entries, SORT_STRING);
        foreach ($entries as $e) {
            if ($e !== '.' && $e !== '..') {
                walk_code($path . '/' . $e, $out);
            }
        }
        return;
    }
    if (is_file($path)) {
        $h = @hash_file('sha256', $path);
        $out[$path] = $h === false ? 'unreadable' : $h;
    }
}

function code_manifest(): array
{
    $out = array();
    foreach (CONTAINER_CODE_PATHS as $p) {
        walk_code($p, $out);
    }
    ksort($out, SORT_STRING);
    return $out;
}

// What the deployment supplies rather than the image.
//
// `common/env.php` is the shipped example at build time by construction -- the
// boot refusal exists precisely because a real deployment must replace it --
// so it can never appear in an image-time manifest with the value it will have
// when it runs. It is therefore the one path still baselined from the
// container, at observer start, the way everything used to be.
//
// Stated as a list so that what is held to the weaker standard is visible
// rather than implied. The weaker standard is not nothing: it still catches
// the file changing under a running container. What it cannot do is establish
// what the file was before this observer started.
// Empty here, and that is this container's own fact rather than a copy of
// the others'. This image ships only `observers/super/`, so there is no
// `/pr2/common/env.php` in the manifest it takes and nothing for the list
// to name. Naming it anyway stated something untrue of this image.
const CONTAINER_SUPPLIED_AT_DEPLOYMENT = array();

// Where the build leaves the manifest of the image it produced.
//
// Deliberately outside every container's CONTAINER_CODE_PATHS, so the manifest
// is not part of what it describes.
const CONTAINER_BASELINE_FILE = '/pr2/.container-baseline';

// Read from the image, not captured from the container.
//
// This used to walk the container's own tree once, at observer start, and call
// the result the baseline. So a tree altered before the observer started --
// or on the boot after a bounce -- baselined as intended, and every later
// cycle agreed with it. What `code-unchanged` actually asserted was "nothing
// changed while I was watching", which is a much smaller claim than its name,
// and the gap between the image being built and the observer starting was
// where an alteration was free.
//
// The manifest is now taken when the image is built, from the code that went
// into it, by the same walk this file does. The build runs as root and leaves
// the file read-only, so the unprivileged user the work runs as cannot rewrite
// it to match a tree it has altered.
//
// **What this still does not establish.** That the image was the intended
// image. A tampered image ships a manifest of its own tampered contents, and
// nothing inside a deployment can tell the difference -- that needs provenance
// from outside it. The claim here is narrower and now true: the tree this
// container is running is the tree its image was built from.
//
// Returns null when there is no usable manifest, which the caller treats as a
// finding. Falling back to walking the tree would be the original defect,
// reached by deleting one file.
function container_baseline(): ?array
{
    $raw = @file_get_contents(CONTAINER_BASELINE_FILE);
    if ($raw === false) {
        return null;
    }

    $baseline = json_decode($raw, true);
    if (!is_array($baseline)
        || !isset($baseline['code'], $baseline['extensions'])
        || !is_array($baseline['code'])
        || !is_array($baseline['extensions'])
    ) {
        return null;
    }

    // The deployment-supplied paths, baselined from the container because
    // there is nowhere else to get them. See CONTAINER_SUPPLIED_AT_DEPLOYMENT.
    foreach (CONTAINER_SUPPLIED_AT_DEPLOYMENT as $path) {
        if (!isset($baseline['code'][$path])) {
            continue;
        }
        $hash = @hash_file('sha256', $path);
        $baseline['code'][$path] = $hash === false ? 'unreadable' : $hash;
    }

    return $baseline;
}


// --- the checks ------------------------------------------------------------

function process_running(string $fragment): bool
{
    $procs = @scandir('/proc');
    if ($procs === false) {
        return false;
    }
    foreach ($procs as $pid) {
        if (preg_match('/^\d+\z/', $pid) !== 1) {
            continue;
        }
        $cmd = @file_get_contents("/proc/$pid/cmdline");
        if ($cmd !== false && strpos(str_replace("\0", ' ', $cmd), $fragment) !== false) {
            return true;
        }
    }
    return false;
}

function port_answers(int $port): bool
{
    // A short timeout on purpose. This runs every cycle, and a port check
    // that waits is a cycle that overruns its cadence -- which is itself a
    // halt. On loopback a listening port answers immediately and a closed one
    // refuses immediately; only a filtered one waits, and that is the case
    // worth failing fast on.
    $fh = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
    if ($fh === false) {
        return false;
    }
    fclose($fh);
    return true;
}

function check_container(Reader $R, ?array $baseline, bool $work_due = true): void
{
    // --- baselined: a running container does not change -------------------

    if ($baseline === null) {
        // No manifest, no claim. Walking the tree instead would answer the
        // question with the tree being asked about, which is the thing this
        // stopped doing.
        $R->fail('code-unchanged', null, 'the baseline shipped with this image is missing or unreadable');
    } else {
        $now = code_manifest();
        if ($now !== $baseline['code']) {
            $changed = array();
            foreach ($now as $path => $hash) {
                if (!isset($baseline['code'][$path])) {
                    $changed[] = "added $path";
                } elseif ($baseline['code'][$path] !== $hash) {
                    $changed[] = "changed $path";
                }
            }
            foreach ($baseline['code'] as $path => $hash) {
                if (!isset($now[$path])) {
                    $changed[] = "removed $path";
                }
            }
            $R->fail('code-unchanged', null, implode('; ', array_slice($changed, 0, 5)));
        }

        $extensions = get_loaded_extensions();
        sort($extensions, SORT_STRING);
        if ($extensions !== $baseline['extensions']) {
            $R->fail('extensions-unchanged', null, 'the loaded extension set is not the one the image was built with');
        }
    }

    // --- declared: the image is what it should be -------------------------

    if (strpos(PHP_VERSION, CONTAINER_PHP_VERSION . '.') !== 0) {
        $R->fail('php-version', null, 'running PHP ' . PHP_VERSION
            . ', the image installs ' . CONTAINER_PHP_VERSION);
    }

    foreach (CONTAINER_EXTENSIONS as $ext) {
        if (!extension_loaded($ext)) {
            $R->fail('extensions-declared', $ext, 'an extension the image installs is not loaded');
        }
    }

    if (CONTAINER_DOCUMENT_ROOT !== '') {
        $declared = getenv('APACHE_DOCUMENT_ROOT');
        if ($declared !== CONTAINER_DOCUMENT_ROOT || !is_dir(CONTAINER_DOCUMENT_ROOT)) {
            $R->fail('document-root', null, 'the document root is not where the image puts it');
        }
    }

    foreach (CONTAINER_APACHE_MODULES as $module) {
        if (!is_file("/etc/apache2/mods-enabled/$module.load")) {
            $R->fail('apache-modules', $module, 'a module the image enables is not enabled');
        }
    }

    // A container with no application in it has no configuration to check, and
    // says so with an empty constant rather than by a check that quietly finds
    // no file and reports nothing.
    if (CONTAINER_ENV_FILE !== '') {

    // The images ship env.example.php as env.php, so a deployment that has not
    // replaced it is running on secrets anyone who can read the repository
    // holds. The boot check refuses to start on that, but it fires once; this
    // asks every cycle, so a configuration replaced after boot is caught
    // rather than waiting for the next restart to be noticed.
    if (is_file(CONTAINER_ENV_FILE) && is_file(CONTAINER_ENV_EXAMPLE)) {
        if (@hash_file('sha256', CONTAINER_ENV_FILE) === @hash_file('sha256', CONTAINER_ENV_EXAMPLE)) {
            $R->fail('env-not-example', null, 'the configuration is still the one published in the repository');
        }
    }

    // Read for two booleans and not retained. The observer never loads this
    // file -- it reads it as data, the way it reads any other file.
    $env = @file_get_contents(CONTAINER_ENV_FILE);
    if ($env !== false) {
        if (preg_match('/^\$DEBUG_MODE\s*=\s*false\s*;/m', $env) !== 1) {
            $R->fail('debug-mode-off', null, 'debug mode is not off');
        }
        if (preg_match('/^\$PAYPAL_SANDBOX\s*=\s*false\s*;/m', $env) !== 1) {
            $R->fail('paypal-live', null, 'the payment sandbox flag is not off');
        }
        unset($env);
    }

    }

    // --- the work is actually there ---------------------------------------
    //
    // Empty constants mean there is none: the super observer's container runs
    // the observer and nothing else, so a process check there would assert
    // that the thing doing the asserting exists.
    //
    // $work_due is the answer to "should the work have started by now", and it
    // is not a softening. Without it these two assertions deadlock the
    // deployment they are meant to protect, because the coupling makes the
    // work wait for the ring and these make the ring wait for the work:
    //
    //   the barrier waits for the ring to be clear
    //   the ring is not clear, because the work is not running
    //   the work is not running, because the barrier is waiting
    //
    // Each statement true, and nothing starts ever again. It is the same
    // distinction the trace check had to make between a schedule that has
    // never run and one that has stopped, and it is resolved the same way:
    // not yet due is quiet, overdue is a fault. The caller owns the clock --
    // see run.php -- and everything else in this file is asserted from the
    // first cycle, because the image is what it is before anything in it runs.

    if (CONTAINER_PROCESS !== '') {
        $R->work_alive = process_running(CONTAINER_PROCESS);
        if (!$R->work_alive && $work_due) {
            $R->fail('process-alive', null, CONTAINER_PROCESS . ' is not running');
        }
    }

    foreach (CONTAINER_PORTS as $name => $port) {
        if (!port_answers($port) && $work_due) {
            $R->fail('port-answers', $name, "nothing is listening on $port");
        }
    }
}
