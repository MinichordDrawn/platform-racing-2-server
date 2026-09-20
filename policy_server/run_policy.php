<?php

namespace jiggmin\ps;

ini_set('mbstring.func_overload', '0');
ini_set('output_handler', '');
error_reporting(E_ALL | E_STRICT);
@ob_end_flush();
set_time_limit(0);

require_once SOCKET_DAEMON_FILES;
require_once ROOT_DIR . '/policy_server/server.php';
require_once ROOT_DIR . '/policy_server/server_client.php';

// The port comes from the environment so this can listen where an
// unprivileged process is allowed to bind, with the host publishing 843 and
// mapping it here. A value that is not a usable port stops the server rather
// than being guessed at: a policy server listening somewhere unintended is a
// policy server that answers nobody, and every Flash client fails closed
// against it.
$policy_port = getenv('POLICY_PORT');
if ($policy_port === false
    || !preg_match('/^\d+$/', $policy_port)
    || (int) $policy_port < 1
    || (int) $policy_port > 65535
) {
    throw new \Exception('POLICY_PORT must be set to a port number.');
}

// start the policy server
$daemon = new \chabot\SocketDaemon();
$server = $daemon->createServer('jiggmin\ps\server', 'jiggmin\ps\serverClient', 0, (int) $policy_port);
$daemon->process();
