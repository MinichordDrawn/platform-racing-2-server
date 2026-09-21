<?php
// The socket server must not print the process password to its log.
//
// client_become_process() prints $PROCESS_PASS, and the value the caller
// submitted, on both the success and the failure branch. That password is the
// whole of the process role: it reaches twenty handlers covering shutdown,
// disconnecting any player, and registering a login with any user id and
// power. Output goes to the container's stdout, so anywhere those logs are
// collected holds the credential.

require_once __DIR__ . '/helper.php';

$GLOBALS['logged'] = [];

function output($str)
{
    $GLOBALS['logged'][] = $str;
}

require_once REPO . '/functions/multi_fns/client/client_misc_fns.php';

// A later change removes this path entirely: the control channel listens on
// its own port and is in process mode by construction, so there is no step a
// player connection takes to acquire the role. Where that has landed, the
// stronger guarantee holds and there is nothing here to log.
if (!function_exists('client_become_process')) {
    echo "client_become_process: the path is gone, so nothing can log the secret
";
    ok(true, 'the path was removed outright');
    t_done();
}

echo "client_become_process: the password is never written to the log\n";

$PROCESS_PASS = 'S3CRET-process-password';
$PROCESS_IP = '10.0.0.1';

class FakeSocket
{
    public $ip = '198.51.100.4';
    public $process = false;
}

// Both branches: a correct value and a wrong one.
$accepted = new FakeSocket();
client_become_process($accepted, $PROCESS_PASS);

$rejected = new FakeSocket();
client_become_process($rejected, 'a-guess');

$log = implode("\n", $GLOBALS['logged']);

is_same($accepted->process, true, 'a correct value still grants the process role');
is_same($rejected->process, false, 'a wrong value still does not');

ok(strpos($log, 'S3CRET-process-password') === false, 'the password is absent from the log');
ok(strpos($log, 'a-guess') === false, 'the submitted value is absent from the log');
ok(strpos($log, '198.51.100.4') !== false, 'the connecting address is still recorded');
ok(strpos($log, 'Succeeded') !== false && strpos($log, 'Failed') !== false, 'the outcome is still recorded');

t_done();
