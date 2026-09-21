
<?php
// The gate's exemption names a file, not a filename.
//
// Two long-lived entrypoints are allowed past the boot refusal, because
// refusing them by exiting would end the container and the observer inside it.
// They are allowed past on the grounds that each carries a continuous check of
// its own, so the exemption is safe exactly for those two programs.
//
// It was matched on the base name. Any command-line script called `pr2.php` or
// `run_policy.php`, anywhere in the image, was admitted on the strength of
// what it was called, and what it was admitted to is `config.php`, which loads
// the environment, the credentials and a database connection. A halt did not
// stop it, because the halt is what it was exempt from.
//
// The exemption should name the two programs. A name is a claim about
// identity and a path is the identity, as far as anything inside the container
// can be.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/observer_gate.php';

echo "the gate exemption names a file, not a filename\n";

// --- what is exempt -------------------------------------------------------

$exempt = observer_gate_self_checking();
is_same(
    $exempt,
    array('/pr2/multiplayer_server/pr2.php', '/pr2/policy_server/run_policy.php'),
    'the exemption is two paths'
);

foreach ($exempt as $path) {
    ok(strpos($path, '/') === 0, "$path is absolute, so it names one file in the image");
}

// --- the two entrypoints are admitted -------------------------------------

foreach ($exempt as $path) {
    ok(observer_gate_is_self_checking($path), "$path is admitted");
}

// Windows-style separators are the same file, since the same source is read on
// a developer's machine and in the image.
ok(
    observer_gate_is_self_checking('\\pr2\\multiplayer_server\\pr2.php'),
    'and the same path written with the other separator is the same file'
);

// --- and nothing else is --------------------------------------------------
//
// Enumerated over the ways a script could carry one of those names. The first
// is the one that was admitted before: the right name in the wrong place.

$refused = array(
    '/pr2/http_server/pr2.php'              => 'the same name somewhere else in the image',
    '/pr2/pr2.php'                          => 'the same name at the top of the tree',
    '/tmp/pr2.php'                          => 'the same name outside the tree',
    '/pr2/policy_server/run_policy.php.bak' => 'a name that merely contains one of them',
    '/pr2/multiplayer_server/pr2.phps'      => 'a name that extends one of them',
    'pr2.php'                               => 'the bare name with no path at all',
    '/pr2/common/cron/run.php'              => 'another entrypoint that is not exempt',
    ''                                      => 'nothing at all',
);

foreach ($refused as $path => $what) {
    ok(!observer_gate_is_self_checking($path), "refused: $what");
}

// A value that is not a string cannot be a path, and must not be treated as
// one by accident.
ok(!observer_gate_is_self_checking(null), 'refused: a missing script name');

// --- the exemption is still only for the command line ---------------------
//
// A request is never exempt, whatever the script is called. config.php runs in
// front of every request and a request that reached the application during a
// halt is the thing the gate exists to prevent.

$gate = file_get_contents(REPO . '/common/observer_gate.php');
$at = strpos($gate, 'observer_gate_is_self_checking');
ok($at !== false, 'the enforcement consults the path test');
$enforce = substr($gate, strpos($gate, 'function observer_gate_enforce'));
ok(
    preg_match('/PHP_SAPI\s*===\s*[\x27"]cli[\x27"]/', $enforce) === 1,
    'and still only for a command-line caller'
);

// --- and the two paths are the two the deployment actually runs -----------
//
// The exemption is worth nothing if it names files the deployment does not
// start, and dangerous if the deployment starts them from somewhere else.

$scripts = array(
    '/pr2/multiplayer_server/pr2.php'  => 'docker/multi_server_startup.sh',
    '/pr2/policy_server/run_policy.php' => 'docker/policy_server_startup.sh',
);
foreach ($scripts as $path => $sh) {
    $src = file_get_contents(REPO . '/' . $sh);
    ok(strpos($src, $path) !== false, "$sh starts exactly $path");
}

t_done();
