<?php
// A secret still holding the value published in the repository must stop the
// server from starting.
//
// All three dockerfiles copy env.example.php over env.php, so an image built
// from this tree runs on whatever that file sets. Every key, salt and password
// in it is published, so anyone who can read the repository holds them. The
// control channel key, the award API key, the login and level ciphers and the
// database password are all in that position.
//
// Nothing in the tree ever tested that any of them had been changed.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/env_check.php';

echo "a secret left at its published value stops the server\n";

// Start from the published file: everything is unchanged, so everything is
// named.
$shipped = env_shipped_values();
foreach ($shipped as $name => $value) {
    $GLOBALS[$name] = $value;
}

$unchanged = env_unchanged_secrets();

ok(in_array('PROCESS_KEY', $unchanged), 'the control channel key is named');
ok(in_array('PR2_HUB_API_KEY', $unchanged), 'the award API key is named');
ok(in_array('COMM_PASS', $unchanged), 'the socket salt is named');
ok(in_array('DB_PASS', $unchanged), 'the database password is named');
ok(in_array('LOGIN_KEY', $unchanged), 'the login cipher key is named');
ok(in_array('LEVEL_PASS_SALT', $unchanged), 'the level pass salt is named');
ok(in_array('URL_KEY', $unchanged), 'the url cipher key is named');
ok(in_array('ACCOUNT_CHANGE_KEY', $unchanged), 'the account change cipher key is named');
ok(in_array('S3_SECRET', $unchanged), 'the storage secret is named');
ok(in_array('PAYPAL_SECRET', $unchanged), 'the payment secret is named');

// Things that are settings rather than secrets are not named, or every
// deployment is stopped for no reason.
ok(!in_array('DB_PORT', $unchanged), 'a port is not treated as a secret');
ok(!in_array('TRUSTED_REFS', $unchanged), 'the referrer list is not treated as a secret');
ok(!in_array('DEBUG_MODE', $unchanged), 'the debug flag is not treated as a secret');
ok(!in_array('ALLOWED_CLIENT_VERSIONS', $unchanged), 'the client version list is not treated as a secret');

// The payment values are chosen by the example file's own sandbox switch, so
// both of its branches have to count as unchanged.
$GLOBALS['PAYPAL_SECRET'] = 'sandbox secret';
ok(in_array('PAYPAL_SECRET', env_unchanged_secrets()), 'either branch of the payment switch counts');

// A secret that is simply missing is not a pass.
unset($GLOBALS['PROCESS_KEY']);
ok(in_array('PROCESS_KEY', env_unchanged_secrets()), 'a secret that is not set at all is named');

// Change them all and nothing is named.
foreach (env_unchanged_secrets() as $name) {
    $GLOBALS[$name] = 'a real value chosen for this deployment ' . $name;
}
is_same(env_unchanged_secrets(), array(), 'nothing is named once every secret has been changed');

// And the check refuses rather than only reporting.
$threw = false;
$told = '';
$GLOBALS['PROCESS_KEY'] = $shipped['PROCESS_KEY'];
try {
    env_require_secrets_changed();
} catch (Throwable $e) {
    $threw = true;
    $told = $e->getMessage();
}
ok($threw, 'a published secret stops the server rather than being noted');
ok(strpos($told, 'PROCESS_KEY') === false, 'the refusal does not name the secret to whoever asked');

// A check that cannot run is not a check that passed: without the published
// file there is nothing to compare against.
$src = file_get_contents(REPO . '/common/env_check.php');
ok(strpos($src, 'is_readable') !== false, 'a missing published file is itself a refusal');

// It has to actually run at startup, for every entry point.
$config = file_get_contents(REPO . '/config.php');
ok(strpos($config, 'env_check.php') !== false, 'the check is loaded at startup');
ok(strpos($config, 'env_require_secrets_changed') !== false, 'the check is called at startup');
$env_at = strpos($config, "/env.php'");
$check_at = strpos($config, 'env_require_secrets_changed');
ok($env_at !== false && $check_at > $env_at, 'the check runs after the configuration is loaded');

t_done();
