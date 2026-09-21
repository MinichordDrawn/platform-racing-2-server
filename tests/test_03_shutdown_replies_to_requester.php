<?php
// shutdown_server() must reply to the socket that asked for the shutdown.
//
// The function takes $socket as its first parameter, but then declares
// `global $player_array, $socket;`. The global declaration rebinds $socket to
// the global scope, discarding the argument. No global $socket exists in
// multiplayer_server/pr2.php, so the reply at the end of the function is
// unreachable and the requester is never told the shutdown succeeded.

require_once __DIR__ . '/helper.php';

// output() lives in functions/common_fns.php; stand in for it here.
// kill_socket() is defined in utils.php and is inert with no bound $server.
function output($str)
{
    return true;
}

require_once REPO . '/functions/multi_fns/utils.php';

echo "shutdown_server: the requesting socket is told the shutdown succeeded\n";

// No global $socket is defined here, matching multiplayer_server/pr2.php.
$player_array = [];

class RecordingSocket
{
    public $written = [];

    public function write($msg)
    {
        $this->written[] = $msg;
    }
}

$requester = new RecordingSocket();

// $die = false so the function returns instead of ending the process.
shutdown_server($requester, false);

ok(count($requester->written) > 0, 'the requesting socket receives a reply');
is_same(
    in_array('The shutdown was successful.', $requester->written, true),
    true,
    'the reply states the shutdown succeeded'
);

t_done();
