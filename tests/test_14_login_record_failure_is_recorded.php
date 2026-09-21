<?php
// A failed login record must be recorded, and must not fail the login.
//
// recent_logins_insert() returned false on failure and both callers ignore it,
// so a login nobody recorded left no trace. Under the explicit error mode a
// failing statement now throws, which inside login.php's try block would fail
// the whole login, so the function has to absorb it and report it instead.

require_once __DIR__ . '/helper.php';
require_once __DIR__ . '/helper_log.php';
require_once REPO . '/common/queries/recent_logins.php';

echo "recent_logins_insert: a failed record is logged, not raised\n";

// A database with no recent_logins table: the insert cannot succeed.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$log = capture_log_start();
$threw = false;
$result = null;
try {
    $result = recent_logins_insert($pdo, 7, '203.0.113.7', 'GB');
} catch (Throwable $t) {
    $threw = true;
}
$written = capture_log_read($log);

is_same($threw, false, 'the failure does not propagate and break the login');
is_same($result, false, 'the caller is told it did not record');
ok($written !== '', 'the failure is written to the log');

@unlink($log);
t_done();
