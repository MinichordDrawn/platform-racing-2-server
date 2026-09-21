<?php
// A crash must not broadcast the server's internals to everyone online.
//
// __crashHandler() builds a string holding the error message, the source file
// path and the line number, and passes it to shutdown_server(), which writes
// it to every connected player. The detail belongs in the server log, which is
// where output() already goes; the players only need to know it is restarting.

require_once __DIR__ . '/helper.php';

$GLOBALS['logged'] = [];

function output($str)
{
    $GLOBALS['logged'][] = $str;
}

require_once REPO . '/functions/multi_fns/utils.php';

echo "crash handler: players are told to expect a restart, not where it broke\n";

class RecordingPlayer
{
    public $written = [];

    public function write($msg)
    {
        $this->written[] = $msg;
    }

    public function remove()
    {
    }
}

// Give error_get_last() something to report, as a real crash would.
@file_get_contents(__DIR__ . '/no-such-file-here');

$player = new RecordingPlayer();
$player_array = ['7' => $player];

__crashHandler(true);

$to_players = implode("\n", $player->written);
$to_log = implode("\n", $GLOBALS['logged']);

ok($to_players !== '', 'players are still told something');
ok(stripos($to_players, 'restart') !== false, 'players are told the server is restarting');
ok(strpos($to_players, 'no-such-file-here') === false, 'players are not told the source file');
ok(strpos($to_players, 'utils.php') === false, 'players are not told any server path');

ok(strpos($to_log, 'no-such-file-here') !== false, 'the log keeps the failing file');

t_done();
