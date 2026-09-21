<?php
// The migration runner writes its configuration somewhere it is allowed to.
//
// init-liquibase.sh built its properties file at `/liquibase.properties`, the
// filesystem root, which only root can write. That worked for exactly as long
// as the container ran as root. It is also where Liquibase looks by default,
// so the path was never passed explicitly and the dependency was invisible.
//
// Two more things were wrong in the same ten lines and neither needed a
// non-root user to matter. It appended rather than truncated, so a container
// that ran twice accumulated duplicate keys. And it wrote the database
// password into a file with whatever permissions the default umask gave it.
//
// The schema is the one thing in this deployment that cannot be recreated by
// restarting something, so the container that creates it failing silently is
// worse than most failures here.

require_once __DIR__ . '/helper.php';

echo "the migration runner writes where it can\n";

$script = file_get_contents(REPO . '/docker/init-liquibase.sh');

// --- nothing is written to the filesystem root ----------------------------

// A redirection to a path with exactly one slash is a write to `/`.
$root_writes = array();
if (preg_match_all('/>>?\s*(\/[^\/\s"\']+)\s*$/m', $script, $m, PREG_SET_ORDER)) {
    foreach ($m as $hit) {
        $root_writes[] = $hit[1];
    }
}
is_same($root_writes, array(), 'nothing is written to the filesystem root');

// --- the configuration is rewritten, not appended -------------------------

ok(
    preg_match('/>>\s*["\']?\$\{?PROPS/', $script) !== 1,
    'the properties file is not appended to'
);

// --- the path is passed, not inferred from the working directory ----------

ok(
    strpos($script, '--defaultsFile') !== false,
    'the properties path is passed explicitly rather than found by chance'
);

// --- the credential file is not readable by everyone ----------------------

ok(
    preg_match('/umask\s+0?77/', $script) === 1,
    'the file holding the database password is created private'
);

// --- and the command reaches the tool -------------------------------------

ok(
    preg_match('/liquibase[^\n]*"\$@"/', $script) === 1,
    'every argument is passed through, quoted'
);

// --- a failure stops the script -------------------------------------------
//
// Without this, wait-for-it timing out still runs liquibase, which then fails
// with a confusing error about missing options rather than about the
// database being unreachable.
ok(
    preg_match('/^set -[eu]+/m', $script) === 1,
    'a failing step stops the script'
);

t_done();
