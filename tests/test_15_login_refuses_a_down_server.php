<?php
// Players must not be sent to a server the poller has marked down.
//
// login.php checked only servers.active, which an operator sets, and never
// servers.status, which the minute cron sets to 'down' when the server does
// not answer. A row can therefore be active and down at once, and the login
// path kept admitting players to it. The status column also defaults to
// 'down', so a newly created server row is down until a poll says otherwise.

require_once __DIR__ . '/helper.php';

echo "login: a server marked down is refused\n";

$harness = __DIR__ . '/stubs/login_harness_down.php';
$out = [];
$code = 0;
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' 2>&1', $out, $code);
$body = trim(implode("\n", $out));
$json = json_decode($body);

ok(strpos($body, 'Fatal error') === false, 'the request does not end in a fatal error');
ok(is_object($json), 'the caller receives a JSON response');
is_same(isset($json->success) ? $json->success : null, false, 'the login is refused');
ok(
    isset($json->error) && stripos($json->error, 'unavailable') !== false,
    'the player is told the server is unavailable'
);

t_done();
