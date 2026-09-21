<?php
// The observer notices if its store stops being its own.
//
// The work and the observer were given different users so that the work could
// not write the files its own observer publishes. That separation is made when
// the image is built -- the store directories belong to the observer's user --
// and it is proved by a script somebody runs by hand.
//
// Nothing in the running deployment noticed if it broke. A store volume
// recreated by an image whose ownership had drifted would come back owned by
// the work's user, the work could forge heartbeats again, and every check
// would stay green: `own-store-writable` asks whether the observer *can* write
// its store, which would still be true. Nobody asked whether anyone else can.
//
// That is the same shape this work keeps finding, one level up: a fact
// established in one place and checked nowhere. Enforcement and detection are
// different things, and "there are several layers" is a statement about the
// first.
//
// So the observer now asks, every cycle, whether its own store is still its
// own: owned by the user this process is, and not writable by group or other.
// It is the cheapest possible check -- a stat of a directory it is already
// standing in -- and it turns a property of the build into a property of the
// running system.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/procedures.php';

echo "the observer notices if its store stops being its own\n";

// --- the decision, on its own ---------------------------------------------
//
// Separated from the filesystem so it can be asked here rather than only in a
// container: the stat is the caller's job, the judgement is this.

$me = 10002;

ok(\pr2obs\web\own_store_private($me, $me, 0755) === null,
    'a directory this observer owns, that only it can write, is its own');

ok(is_string(\pr2obs\web\own_store_private(33, $me, 0755)),
    'one owned by somebody else is not');

ok(is_string(\pr2obs\web\own_store_private($me, $me, 0775)),
    'nor one the group can write');

ok(is_string(\pr2obs\web\own_store_private($me, $me, 0757)),
    'nor one anybody can write');

ok(\pr2obs\web\own_store_private($me, $me, 0700) === null,
    'and tighter than needed is still its own');

// The reason has to say which of the two it was, because they are different
// failures: the wrong owner is a volume that came back wrong, and a loose mode
// is a permission that was widened.
$owner_reason = \pr2obs\web\own_store_private(33, $me, 0755);
$mode_reason  = \pr2obs\web\own_store_private($me, $me, 0777);
ok($owner_reason !== $mode_reason, 'and says which of the two went wrong');

// --- it is asked of the real tree, before the cycle -----------------------

$run = file_get_contents(REPO . '/observers/web/run.php');

$at    = strpos($run, 'own_store_private_at(');
$cycle = strpos($run, 'run_cycle(array(');
ok($at !== false, 'the observer asks it of its own store tree');
ok($at !== false && $cycle !== false && $at < $cycle,
    'and asks before the cycle, so the cycle can act on it');

// --- and every observer raises it, and says it ran it ---------------------

foreach (glob(REPO . '/observers/*/cycle.php') as $file) {
    $name = basename(dirname($file));
    $src  = file_get_contents($file);
    ok(strpos($src, "'own-store-private'") !== false, "$name can raise it");
    ok(strpos($src, '$checks[] = \'own-store-private\'') !== false,
        "and $name says in its heartbeat that it ran it");
}

foreach (glob(REPO . '/observers/*/apply.php') as $file) {
    $name = basename(dirname($file));
    ok(strpos(file_get_contents($file), "'own-store-private'") !== false,
        "$name counts it as a finding about itself");
}

// --- the extension it needs is declared -----------------------------------
//
// Asking who this process is needs posix, which was loaded in every image and
// declared by none of them. A check that rests on an extension nobody declared
// is a check that disappears quietly the day the extension does.

foreach (glob(REPO . '/observers/*/container.php') as $file) {
    $name = basename(dirname($file));
    $src  = file_get_contents($file);
    ok(preg_match("/CONTAINER_EXTENSIONS = array\([^)]*'posix'/", $src) === 1,
        "$name declares posix among the extensions it needs");
}

t_done();
