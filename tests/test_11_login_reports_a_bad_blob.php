<?php
// A login blob that does not decode must be reported, not swallowed.
//
// login.php decrypts the posted blob, json_decode()s it, and writes
// $login->ip straight away. On a blob that does not decode, json_decode()
// returns null and that write raises a PHP Error, which `catch (Exception)`
// does not catch. The die() in the finally block then discards the Error and
// emits the response with no error field set, so the player is told only
// {"success":false} with no reason, and nothing records what happened.
//
// Run in a separate process because the endpoint ends in die().

require_once __DIR__ . '/helper.php';

echo "login: a blob that does not decode is reported to the caller\n";

$harness = __DIR__ . '/stubs/login_harness.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' 2>&1';
$out = [];
$code = 0;
exec($cmd, $out, $code);
$body = trim(implode("\n", $out));

$json = json_decode($body);

ok(strpos($body, 'Fatal error') === false, 'the request does not end in a fatal error');
ok(is_object($json), 'the caller receives a JSON response');
is_same(isset($json->success) ? $json->success : null, false, 'the response reports failure');
ok(
    isset($json->error) && is_string($json->error) && $json->error !== '',
    'the response explains why it failed'
);

t_done();
