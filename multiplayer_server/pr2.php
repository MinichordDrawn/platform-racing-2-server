<?php

namespace pr2\multi;

ini_set('mbstring.func_overload', '0');
ini_set('output_handler', '');
error_reporting(E_ALL | E_STRICT);
@ob_end_flush();
set_time_limit(0);

// env
require_once __DIR__ . '/../config.php';

// ignore travis warnings and define server salt
// phpcs:disable
define('SALT', $COMM_PASS);
// phpcs:enable

// load dependencies
require_once COMMON_DIR . '/multi_queries.php';
require_once SOCKET_DAEMON_FILES;
require_once FNS_DIR . '/common_fns.php';

require_once PR2_FNS . '/loadup_fns.php';
require_once PR2_FNS . '/multi_data_fns.php';
require_once PR2_FNS . '/process_fns.php';
require_once PR2_FNS . '/utils.php';

require_once PR2_FNS . '/artifact_fns.php';
require_once PR2_FNS . '/tournament_fns.php';
require_once PR2_FNS . '/vault_fns.php';

require_once PR2_FNS . '/client/client_misc_fns.php';
require_once PR2_FNS . '/client/ingame.php';
require_once PR2_FNS . '/client/lobby.php';
require_once PR2_FNS . '/client/moderation.php';

require_once PR2_FNS . '/staff/demod.php';
require_once PR2_FNS . '/staff/promote_to_moderator.php';
require_once PR2_FNS . '/staff/server_owner.php';

require_once PR2_ROOT . '/parts/Parts.php';
require_once PR2_ROOT . '/parts/Prizes.php';

require_once PR2_ROOT . '/rooms/Room.php';
require_once PR2_ROOT . '/rooms/LevelListRoom.php';
require_once PR2_ROOT . '/rooms/ChatRoom.php';
require_once PR2_ROOT . '/rooms/Game.php';

require_once PR2_ROOT . '/Artifact.php';
require_once PR2_ROOT . '/CourseBox.php';
require_once PR2_ROOT . '/ChatMessage.php';
require_once PR2_ROOT . '/GuildPoints.php';
require_once PR2_ROOT . '/HappyHour.php';
require_once PR2_ROOT . '/Mutes.php';
require_once PR2_ROOT . '/LoiterDetector.php';
require_once PR2_ROOT . '/Player.php';
require_once COMMON_DIR . '/manage_socket/control_auth.php';
require_once PR2_ROOT . '/PR2SocketServer.php';
require_once PR2_ROOT . '/PR2ControlServer.php';
require_once PR2_ROOT . '/PR2Client.php';
require_once PR2_ROOT . '/PR2ControlClient.php';
require_once PR2_ROOT . '/PR2VirtualClient.php';
require_once PR2_ROOT . '/RaceStats.php';
require_once PR2_ROOT . '/ServerBans.php';
require_once PR2_ROOT . '/TemporaryItems.php';
require_once PR2_ROOT . '/ReplayRecorder.php';

// ensure no data is lost to a server crash
register_shutdown_function('__crashHandler');

// output status to console
output("Initializing startup...");

// Ask the observer network before anything else this process does.
//
// config.php does not ask on this process's behalf: this is one of the two
// entrypoints exempt from the refusal that exits, because exiting here would
// end the container and the observer inside it. The exemption is granted on
// the grounds that this process carries a continuous check of its own -- the
// socket daemon's timer -- which does not fire until the loop is running.
// Everything before that point is unasked unless it is asked here.
//
// This read used to sit eighty-eight lines below the connection, under a
// comment claiming it came first. Nothing between the two touches the database
// -- the prize tables are built in memory and the rooms hold no connection --
// so the only thing the old order bought was a database connection opened by a
// deployment that had been told to stop.
$gate = \observer_gate_settings();
PR2SocketServer::$halted = is_string($gate)
    ? $gate                                  // an environment the gate cannot read
    : \observer_gate_reason($gate);

// connect to the db
//
// `$pdo` is the global every query in this server goes through, so opening it
// is touching the database in the only sense that matters. While the ring says
// stop it is not opened at all, and the first clear tick opens it alongside the
// loadup it deferred.
$reconnect_attempted = false;
$pdo = null;
if (PR2SocketServer::$halted === null) {
    try {
        $pdo = pdo_connect();
    } catch (Exception $e) {
        // Not a reason to exit, and it used to be one.
        //
        // Exiting here ends this container and the observer living in it. The
        // ring is then a member short, every remaining member raises a halt
        // for the member that vanished, and none of them can lift it, because
        // what would lift it is the process that just exited. A database that
        // was briefly unreachable therefore turned into a deployment that
        // stayed stopped -- the failure mode the exemption for this entrypoint
        // exists to avoid, reached by a different route.
        //
        // So it does what a halt does: stays running, leaves the loadup undone
        // and lets the first tick that can connect do it. The startup path and
        // the recovery path stay the same path.
        output('--- DATABASE UNREACHABLE --- ' . $e->getMessage());
        $pdo = null;
    }
}

// server info
$server_id = (int) $argv[1];
$verbose = $argc > 2 && strtolower($argv[2]) === 'true';

// Where the player port is. Declared, not looked up.
//
// This used to be a hard-coded 9159 that never took effect, because the
// startup loadup overwrote it from the server's own database row, and the row
// said 9160. So the port the listener bound came from a table, and the three
// places the deployment states it -- the compose file's publish, the image's
// expose, and the observer's column asserting something answers there -- were
// all describing a value none of them could see or set.
//
// Anything able to write that row moved the listener, and the only component
// that noticed was the observer, which reported the declared port as dead.
// That is how this was found: deferring the loadup during a halt left the
// stale default bound, and the ring halted on a server listening perfectly
// well somewhere else.
//
// Checked the way the policy server checks its own, and for the same reason: a
// server listening somewhere unintended is a server that answers nobody, and
// every client fails closed against it.
$player_port = getenv('PLAYER_PORT');
if ($player_port === false
    || !preg_match('/^\d+$/', $player_port)
    || (int) $player_port < 1
    || (int) $player_port > 65535
) {
    throw new \Exception('PLAYER_PORT must be set to a port number.');
}
$port = (int) $player_port;
$server_name = 'bob';
$is_ps = false;
$guild_id = 0;
$guild_owner = 0;
$server_expire_time = 0;
$max_players = 200;

// prizes/random hh hour
Prizes::init();

// important arrays
$login_array = array();
$game_array = array();
$player_array = array();
$socket_array = array();
$chat_room_array = array();
$campaign_array = array();
$level_prize_array = array();
$play_count_array = array();

// rooms
$campaign_room = new LevelListRoom('campaign');
$best_room = new LevelListRoom('best');
$best_week_room = new LevelListRoom('best_week');
$newest_room = new LevelListRoom('newest');
$search_room = new LevelListRoom('search');

// load in startup info
output('Requesting loadup information...');
$uptime = time();

// What the answer read at the top of this file means for the loadup.
//
// The window this closes is three to four seconds and it was not idle. The
// loadup reads six tables and writes a row saying this server is up and open,
// which is the row the web tier reads to decide where to send players, so a
// halted deployment advertised a game server as available. Then both ports
// bound and admitted whatever arrived.
//
// The process still starts and still binds, because the observer beside it
// asserts that this process is alive and these ports answer, and a server that
// refused to bind would fault its own container and stop the ring rather than
// itself. What it does not do while halted is touch the database or serve
// anybody.
if (PR2SocketServer::$halted === null && $pdo !== null) {
    begin_loadup($server_id);
    PR2SocketServer::$loaded = true;
} else {
    // Deferred rather than skipped. A server that never loaded cannot serve
    // when the halt lifts, so the first clear tick does the work this one
    // postponed, and the startup path and the recovery path stay the same
    // path.
    output('--- STOPPED BY THE OBSERVER NETWORK --- ' . PR2SocketServer::$halted);
    output('--- loadup deferred until the ring is clear ---');
}

// start the socket server
output("Starting PR2 server $server_name (ID: #$server_id) on port $port...");
$daemon = new \chabot\SocketDaemon();
$server = $daemon->createServer('\pr2\multi\PR2SocketServer', '\pr2\multi\PR2Client', 0, $port);

// the control channel, on its own port, which is not published to players
output("Starting the control channel on port $PROCESS_PORT...");
$control = $daemon->createServer('\pr2\multi\PR2ControlServer', '\pr2\multi\PR2ControlClient', 0, $PROCESS_PORT);
output("Success! Server started" . ($verbose ? ' (in verbose mode)' : '') . ' on ' . date('r', $uptime));
$daemon->process();
