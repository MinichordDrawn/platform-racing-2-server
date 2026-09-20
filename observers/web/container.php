<?php

namespace pr2obs\web;

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

const CONTAINER_PHP_VERSION = '8.2';

// Installed by the image, over and above what PHP builds in.
const CONTAINER_EXTENSIONS = array('pdo_mysql', 'apcu', 'pcntl');

// The code this image ships. Everything under these paths is fixed at build
// time and must not change while the container runs.
const CONTAINER_CODE_PATHS = array(
    '/pr2/config.php',
    '/pr2/common',
    '/pr2/functions',
    '/pr2/http_server',
    '/pr2/vend',
    '/pr2/observers/web',
);

// Mounted in rather than shipped, so not part of the image's code. The
// clients directory holds the Flash binaries and is read-only; the data
// directories under the served tree are symlinks, which the walk records by
// their target rather than following.
const CONTAINER_NOT_CODE = array(
    '/pr2/http_server/clients',
);

const CONTAINER_DOCUMENT_ROOT = '/pr2/http_server';

const CONTAINER_APACHE_MODULES = array('proxy', 'proxy_http', 'proxy_wstunnel', 'env');

// A fragment of the command line of the process this container exists to run.
const CONTAINER_PROCESS = 'apache2';

// Ports this container should answer on, inside itself.
const CONTAINER_PORTS = array('http' => 8080);

const CONTAINER_ENV_FILE    = '/pr2/common/env.php';
const CONTAINER_ENV_EXAMPLE = '/pr2/common/env.example.php';

// Every identifier this file can fail. They are all assertions this observer
// makes about its own world, so they all belong in its fault file.
function container_local_checks(): array
{
    return array(
        'code-unchanged',
        'php-version',
        'extensions-declared',
        'extensions-unchanged',
        'document-root',
        'apache-modules',
        'env-not-example',
        'debug-mode-off',
        'paypal-live',
        'process-alive',
        'port-answers',
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

// Captured once, when the observer starts.
function container_baseline(): array
{
    $extensions = get_loaded_extensions();
    sort($extensions, SORT_STRING);
    return array(
        'code'       => code_manifest(),
        'extensions' => $extensions,
    );
}


// --- the checks ------------------------------------------------------------

function process_running(string $fragment): bool
{
    $procs = @scandir('/proc');
    if ($procs === false) {
        return false;
    }
    foreach ($procs as $pid) {
        if (preg_match('/^\d+$/', $pid) !== 1) {
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

function check_container(Reader $R, array $baseline, bool $work_due = true): void
{
    // --- baselined: a running container does not change -------------------

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
        $R->fail('extensions-unchanged', null, 'the loaded extension set changed while running');
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
