<?php
// The example environment is what every image runs.
//
// All three dockerfiles copy common/env.example.php over common/env.php, so
// whatever that file sets is the shipped default. It sets $DEBUG_MODE = true,
// against its own comment, and pdo_connect() throws the raw driver message
// when debug is on. That message carries the host, port and database name, and
// the endpoints echo it back to the caller.

require_once __DIR__ . '/helper.php';
require_once REPO . '/common/pdo_connect.php';

echo "environment: the shipped default does not expose database details\n";

// 1. What the example ships. It only assigns variables, so loading it is safe.
require REPO . '/common/env.example.php';
is_same($DEBUG_MODE, false, 'the example environment ships with debug off');

// 2. Why it matters: the same failure, read under each setting.
$DB_ADDRESS = 'db.internal.example';
$DB_USER = 'pr2';
$DB_PASS = 'hunter2';
$DB_NAME = 'pr2_live';
$DB_PORT = 3306;

$DEBUG_MODE = false;
$redacted = null;
try {
    pdo_connect();
} catch (Exception $e) {
    $redacted = $e->getMessage();
}

$DEBUG_MODE = true;
$verbose = null;
try {
    pdo_connect();
} catch (Exception $e) {
    $verbose = $e->getMessage();
}

ok($redacted !== null && $verbose !== null, 'a failing connection raises under both settings');
is_same($redacted, 'Could not connect to the database.', 'with debug off the message is generic');
ok($redacted !== $verbose, 'with debug on a different, driver-supplied message is raised');

t_done();
