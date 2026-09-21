<?php
// The boot check must not run while the image is being built.
//
// `prepend_file.ini` sets `auto_prepend_file = /pr2/config.php`, so config.php
// runs in front of every PHP process in the container. That is exactly what
// it is for at runtime, and it is how the refusal on unchanged secrets
// reaches every request and every cron process.
//
// It also reaches every PHP process during the *build*, and two build steps
// are PHP programs: pecl and composer. At build time `env.php` is the shipped
// example by construction -- the image copies it there itself -- so the
// secrets are unchanged by definition and the check throws. The web image
// could not be built at all.
//
// The check is right and the message is right. What was wrong is where it
// ran. The prepend is a runtime concern, so it is installed after every step
// that runs PHP, and the build no longer passes through it.
//
// This is enumerable rather than a list of the two steps that broke: it finds
// every RUN that invokes PHP and requires the prepend to come after all of
// them. A build step added tomorrow that shells out to composer fails this
// without anyone editing this file.

require_once __DIR__ . '/helper.php';

echo "the boot check does not run during the build\n";

// Things that are PHP programs, whatever they look like on the command line.
$php_invocations = array('php ', 'php\\', 'pecl ', 'pear ', 'composer');

$dockerfiles = array(
    'docker/http_server.dockerfile',
    'docker/multi_server.dockerfile',
    'docker/policy_server.dockerfile',
);

foreach ($dockerfiles as $rel) {
    $path = REPO . '/' . $rel;
    ok(file_exists($path), "$rel exists");
    if (!file_exists($path)) {
        continue;
    }

    $lines = explode("\n", file_get_contents($path));

    // Logical lines: a Dockerfile instruction can be continued with a
    // trailing backslash, and the PHP call is often on a continuation.
    $instructions = array();   // line number => full text
    $current = null;
    $start = 0;
    foreach ($lines as $i => $raw) {
        $line = rtrim(rtrim($raw, "\r"));
        if ($current === null) {
            if (preg_match('/^\s*(RUN|COPY|ENV|FROM|CMD|ENTRYPOINT|USER|WORKDIR|VOLUME|EXPOSE)\b/i', $line)) {
                $current = $line;
                $start = $i + 1;
            } else {
                continue;
            }
        } else {
            $current .= ' ' . trim($line);
        }

        if (substr(rtrim($current), -1) === '\\') {
            $current = rtrim(rtrim($current), '\\');
            continue;
        }

        $instructions[$start] = $current;
        $current = null;
    }

    // Where the prepend is installed.
    $prepend_at = null;
    foreach ($instructions as $line_no => $text) {
        if (stripos($text, 'prepend_file.ini') !== false) {
            $prepend_at = $line_no;
            break;
        }
    }

    if ($prepend_at === null) {
        ok(true, "$rel installs no prepend, so the build cannot pass through it");
        continue;
    }

    // Every RUN that invokes PHP has to come before it -- or disable it.
    //
    // The rule exists because config.php carries the boot refusal on unchanged
    // secrets, and at build time env.php is the shipped example by
    // construction, so a PHP process started after the prepend is installed
    // refuses and the image cannot be built. `-d auto_prepend_file=` makes
    // that impossible rather than unlikely: it is the same exemption every
    // observer and the barrier are started with, and it is what lets the
    // container baseline be taken as the last step of the build, which is
    // where it has to be taken to describe the tree the image ships.
    //
    // The exemption is the explicit flag and nothing else. A RUN that merely
    // looks harmless still counts.
    $after = array();
    foreach ($instructions as $line_no => $text) {
        if (!preg_match('/^\s*RUN\b/i', $text)) {
            continue;
        }
        if (strpos($text, '-d auto_prepend_file=') !== false) {
            continue;
        }
        foreach ($php_invocations as $needle) {
            if (stripos($text, $needle) !== false) {
                if ($line_no > $prepend_at) {
                    $after[] = "line $line_no: " . substr(trim($text), 0, 60);
                }
                break;
            }
        }
    }

    is_same($after, array(), "$rel runs no PHP after the prepend is installed");
}

// --- and the check itself is untouched ------------------------------------
//
// The fix must be to where the check runs, never to whether it runs. A build
// made to pass by softening the refusal would be worse than a build that
// fails.

$config = file_get_contents(REPO . '/config.php');
ok(
    strpos($config, 'env_require_secrets_changed()') !== false,
    'the refusal on unchanged secrets is still called'
);

$env_check = file_get_contents(REPO . '/common/env_check.php');
ok(
    preg_match('/\bthrow\b/', $env_check) === 1,
    'and still throws rather than warning'
);
ok(
    stripos($env_check, 'getenv') === false || stripos($env_check, 'SKIP') === false,
    'and has no way to be switched off from the environment'
);

t_done();
