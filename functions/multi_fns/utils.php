<?php


// How many eggs a race has.
//
// The server announces this number to every client at the start of an egg
// race, so it is the number it has committed to and the ceiling on what anyone
// can collect. Announcing and bounding read the same value so they cannot
// drift apart.
function egg_count()
{
    return 10;
}


// Whether a value can be one of this race's eggs.
//
// The identifier goes into the name of a packet the other players receive, so
// a value carrying the field delimiter would let the sender choose that
// packet's shape.
function valid_egg_id($value)
{
    if (!ctype_digit((string) $value)) {
        return false;
    }

    return (int) $value < egg_count();
}


// The most lives a player may hold.
//
// The server does not model heart blocks, so it cannot tell whether a
// particular heart was really collected. This bounds the claim rather than
// verifying it, which is the honest description: unbounded lives are immunity
// in deathmatch, where lives are what decide elimination and elimination order
// is the placement. $MAX_LIVES overrides it, and a value that is not a
// positive number is ignored rather than read as no ceiling.
function max_lives()
{
    global $MAX_LIVES;

    if (isset($MAX_LIVES) && (int) $MAX_LIVES > 0) {
        return (int) $MAX_LIVES;
    }

    return 25;
}


// Whether a ban row has been lifted.
//
// The column is a BIT, which reaches PHP as a raw byte through some drivers
// and as an integer or a digit through others. Casting "\x01" to an integer
// gives zero, so every spelling is handled rather than assumed.
function ban_row_lifted($ban)
{
    $lifted = isset($ban->lifted) ? $ban->lifted : 0;

    if (is_bool($lifted)) {
        return $lifted;
    }

    if (is_int($lifted)) {
        return $lifted !== 0;
    }

    $lifted = (string) $lifted;

    return $lifted !== '' && $lifted !== '0' && $lifted !== "\x00";
}


// Whether a ban row is one that is still in force.
//
// ban_select filters on neither of these, so without this a ban that had been
// lifted or had already run out was as good as a current one.
function ban_row_is_active($ban)
{
    if (!is_object($ban)) {
        return false;
    }

    if (ban_row_lifted($ban)) {
        return false;
    }

    return (int) $ban->expire_time > time();
}


// How much longer a ban row has to run. The endpoint that wrote the row capped
// this by the acting moderator's rank, so it is the figure that was allowed
// rather than the one the packet asked for.
function ban_row_seconds_remaining($ban)
{
    $remaining = (int) $ban->expire_time - time();

    return $remaining > 0 ? $remaining : 0;
}


// Whether a ban row is a social ban. The endpoint settled this to g or s when
// it wrote the row.
function ban_row_is_social($ban)
{
    return (string) $ban->scope === 's';
}


// Whether a value can be a course id, which is to say a level id.
//
// A course id is not only stored. It goes into the name of the startGame
// packet every player in the race receives and the replay records, it keys the
// play totals, and it looks up the campaign and prize tables, so a value
// carrying the field delimiter would let the sender choose the shape of a
// packet other people's clients parse.
//
// Digits only, and above zero. Whether it names a level that exists is a
// different question and not one this server can answer: players race levels
// it holds no catalogue of.
function valid_course_id($value)
{
    return ctype_digit((string) $value) && (int) $value > 0;
}


// How far below its own measurement the server will believe a client's race
// time, in milliseconds.
//
// The server's figure is taken when the finish packet arrives, so it carries
// the delay in getting there. The client's own figure does not, which is why
// it is worth having. This is how much of a difference that can account for.
// $RACE_FINISH_ALLOWANCE_MS overrides it; a value that is not a positive
// number is ignored rather than read as no bound.
function race_finish_allowance_ms()
{
    global $RACE_FINISH_ALLOWANCE_MS;

    if (isset($RACE_FINISH_ALLOWANCE_MS) && (int) $RACE_FINISH_ALLOWANCE_MS > 0) {
        return (int) $RACE_FINISH_ALLOWANCE_MS;
    }

    return 5000;
}


// The race time to record, given what the client reported and what the server
// measured.
//
// Signing a packet says who sent it, not whether what it says is true: whoever
// runs the client holds its key. So the time has to stand up on its own. A
// genuine one is at most what the server saw, since the server's clock ran
// until the packet arrived, and not further below it than the connection could
// account for. Anything else, and what the server measured is used, which
// loses a little accuracy for that finish and nothing else.
function accepted_finish_ms($local_finish_ms, $server_finish_ms)
{
    $server_finish_ms = (int) $server_finish_ms;

    if (!is_numeric($local_finish_ms)) {
        return $server_finish_ms;
    }

    $claimed = (int) $local_finish_ms;

    if ($claimed <= 0 || $claimed > $server_finish_ms) {
        return $server_finish_ms;
    }

    if ($claimed < $server_finish_ms - race_finish_allowance_ms()) {
        return $server_finish_ms;
    }

    return $claimed;
}


// A key for one connection, used to sign the packets it sends.
//
// Drawn per session rather than shared, so that holding one connection's key
// says nothing about any other. Whoever runs the client holds its own key, so
// this cannot stop a player forging their own packets; checking the values
// themselves, server side, is what does that.
function new_session_key()
{
    return bin2hex(random_bytes(32));
}


// The signature a client packet carries.
//
// Covers the sequence number, the command and the data. Each field is given
// its length first, so that moving a character out of one field and into the
// next cannot produce the same string to sign. A separator alone would not do
// that, because the separator can appear inside the data.
function sign_client_packet($session_key, $send_num, $call, $data)
{
    $payload = (int) $send_num
        . '`' . strlen((string) $call) . ':' . $call
        . '`' . strlen((string) $data) . ':' . $data;

    return hash_hmac('sha256', $payload, (string) $session_key);
}


// The signature the server puts on what it sends back.
//
// The same shape in the other direction, so a client can tell a message from
// its own server from anything else that reaches the connection. It was a
// three character digest of a constant compiled into the client, which told a
// client nothing it did not already hold.
function sign_server_packet($session_key, $send_num, $body)
{
    $payload = (int) $send_num . '`' . strlen((string) $body) . ':' . $body;

    return hash_hmac('sha256', $payload, (string) $session_key);
}


// An id for a connection that is waiting to be logged in.
//
// This is the only thing tying a socket connection to the login that arrives
// for it over the control channel, so it has to be unguessable. Handed out in
// sequence, anyone could take one, know what the next ones would be, and name
// somebody else's pending id in their own login so that their account was
// registered onto a connection that was not theirs. The blob carrying the id
// is encrypted with a fixed key and carries nothing that authenticates it, so
// its contents do not stand in the way of that.
//
// Kept inside the signed 32 bit range because the id passes through the client
// on its way back.
function get_login_id()
{
    global $login_array;

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $id = random_int(1, 2147483647);

        if (!isset($login_array[$id])) {
            return $id;
        }
    }

    throw new Exception('Could not allocate a login id.');
}


// takes an id and returns a player
function id_to_player($id, $throw_exception = true)
{
    global $player_array;

    $player = @$player_array[$id];
    if (!isset($player) && $throw_exception === true) {
        throw new Exception('Could not find a player with that ID.');
    }

    return $player;
}


// takes a name and returns a player
function name_to_player($name)
{
    global $player_array;

    $return_player = null;
    foreach ($player_array as $player) {
        if (strtolower($player->name) === strtolower($name)) {
            $return_player = $player;
            break;
        }
    }

    return $return_player;
}


// get an existing chat room, or make a new one
function get_chat_room($chat_room_name)
{
    global $chat_room_array;

    if (isset($chat_room_array[$chat_room_name])) {
        return $chat_room_array[$chat_room_name];
    } else {
        $chat_room = new \pr2\multi\ChatRoom($chat_room_name);
        return $chat_room;
    }
}


// accept bans from other servers or PR2 Hub
function apply_bans($bans)
{
    global $player_array;

    foreach ($bans as $ban) {
        foreach ($player_array as $player) {
            if ($player->ip === $ban->ip || (int) $player->user_id === (int) $ban->user_id) {
                if ($ban->expire_time > time() && $ban->lifted == 0) { // active ban
                    if ($ban->scope === 'g') { // remove if game ban
                        $player->remove();
                    } elseif ($player->sban_exp_time < $ban->expire_time) {
                        $player->sban_id = $ban->ban_id;
                        $player->sban_exp_time = $ban->expire_time;
                    }
                } elseif ($ban->lifted == 1 || $ban->expire_time <= time()) { // expire lifted social ban
                    $player->sban_id = $player->sban_exp_time = 0;
                }
            }
        }
    }
}


// remove expired social bans
function socialBansRemoveExpired()
{
    global $player_array;

    $time = time();
    foreach ($player_array as $player) {
        if ($player->sban_exp_time > 0 || $player->sban_id > 0) {
            if ($player->sban_exp_time - $time <= 0) {
                $player->sban_id = $player->sban_exp_time = 0;
            }
        }
    }
}


// send new pm notifications out
function pm_notify($pms)
{
    global $player_array;

    foreach ($pms as $pm) {
        if (isset($player_array[$pm->to_user_id])) {
            $player = $player_array[$pm->to_user_id];
            $player->write('pmNotify`' . $pm->message_id);
        }
    }
}


// place the artifact
function place_artifact($artifact)
{
    \pr2\multi\Artifact::$level_id = (int) $artifact->level_id;
    \pr2\multi\Artifact::$x = (int) $artifact->x;
    \pr2\multi\Artifact::$y = (int) $artifact->y;
    \pr2\multi\Artifact::$rot = (int) $artifact->rot;
    \pr2\multi\Artifact::$set_time = (int) $artifact->set_time;
    \pr2\multi\Artifact::$first_finder = (int) $artifact->first_finder;
    \pr2\multi\Artifact::$bubbles_winner = (int) $artifact->bubbles_winner;
}


// get the plays we've been holding
function drain_plays()
{
    global $play_count_array;

    $cup = array();
    foreach ($play_count_array as $course => $plays) {
        // normalize externally-facing ids like "8p_123" to integer ids
        if (is_string($course) && strpos($course, '8p_') === 0) {
            $course_id = (int) substr($course, 3);
        } else {
            $course_id = (int) $course;
        }

        $plays = (int) $plays;
        if ($course_id > 0 && $plays > 0) {
            if (isset($cup[$course_id])) {
                $cup[$course_id] += $plays;
            } else {
                $cup[$course_id] = $plays;
            }
        }
    }

    $play_count_array = array();

    return $cup;
}


// get server population
function get_population()
{
    global $player_array;
    return count(array_filter($player_array, function ($player) {
        return isset($player) && $player->isConnected();
    }));
}


// get server status
function get_status()
{
    global $player_array, $max_players;
    $population = count(array_filter($player_array, function ($player) {
        return isset($player) && $player->isConnected();
    }));
    return $population >= $max_players ? 'full' : 'open';
}


// send to all online players
function sendToAll_players($str)
{
    global $player_array;

    foreach ($player_array as $player) {
        if ($player->isConnected()) {
            $player->write($str);
        }
    }
}


// send to all members of a guild
function send_to_guild($guild_id, $str)
{
    global $player_array;

    foreach ($player_array as $player) {
        if ($player->isConnected() && (int) $player->guild_id === $guild_id) {
            $player->write($str);
        }
    }
}


// vault: start a perk
function start_perk($slug, $user_id, $guild_id, $expire_time = 0, $start_time = 0)
{
    $seconds_elapsed = !empty($start_time) && time() > $start_time ? time() - $start_time : 0;
    $seconds_duration = $expire_time - ($seconds_elapsed > 0 ? $start_time + $seconds_elapsed : time());
    if ($seconds_duration <= 0) {
        return;
    }

    if ($slug === 'guild_fred') {
        assign_guild_part('body', 29, $user_id, $guild_id, $seconds_duration);
    } elseif ($slug === 'guild_ghost') {
        assign_guild_part('head', 31, $user_id, $guild_id, $seconds_duration);
        assign_guild_part('body', 30, $user_id, $guild_id, $seconds_duration);
        assign_guild_part('feet', 27, $user_id, $guild_id, $seconds_duration);
    } elseif ($slug === 'guild_artifact') {
        assign_guild_part('hat', 14, $user_id, $guild_id, $seconds_duration);
        assign_guild_part('eHat', 14, $user_id, $guild_id, $seconds_duration);
    } elseif ($slug === 'happy_hour') {
        \pr2\multi\HappyHour::activate($seconds_duration);
    }
}


// vault: assign a part bought for a guild
function assign_guild_part($type, $part_id, $user_id, $guild_id, $seconds_duration)
{
    global $player_array;

    \pr2\multi\TemporaryItems::add($type, $part_id, $user_id, $guild_id, $seconds_duration);

    foreach ($player_array as $player) {
        if ($player->guild_id === $guild_id) {
            $player->setPart($type, $part_id);
            $player->sendCustomizeInfo();
        }
    }
}


// get ban priors (for lazy mods)
function get_priors($mod, $name)
{
    global $guild_id;

    $safe_name = htmlspecialchars($name, ENT_QUOTES);

    // sanity: make sure they're online and a staff member
    $not_staff = $guild_id !== 183;
    if (!isset($mod) || $mod->group < 2 || $mod->temp_mod === true || ($mod->server_owner === true && $not_staff)) {
        $mod->write("message`Error: You lack the power to view priors for $safe_name.");
        return false;
    }

    // get player info for mod
    $power = db_op('user_select_power', array($mod->user_id, true));
    if ($power < 2) {
        $mod->write("message`Error: You lack the power to view priors for $safe_name.");
        return false;
    }

    // get user info
    $user = db_op('user_select_by_name', array($name, true));
    $user_id = (int) $user->user_id;
    if ($user_id === 0) {
        $mod->write("message`Error: Could not find a user with that name.");
        return false;
    }

    // make user vars
    $ip = $user->ip;
    $power = (int) $user->power;

    // initialize return string var
    $url_name = htmlspecialchars(urlencode($user->name), ENT_QUOTES);
    $user_group = get_group_info($user);
    $u_link = urlify("https://pr2hub.com/mod/player_info.php?name=$url_name", $user->name, "#$user_group->color");
    $str = "<b>Ban Data for $u_link</b><br><br>";

    // check if the user is currently banned
    $str .= "Currently Banned: ";
    $is_banned = 'No';
    $row = db_op('check_if_banned', array(0, $ip, 'b', false));
    if ($row !== false) {
        $ban_id = $row->ban_id;
        $reason = htmlspecialchars($row->reason, ENT_QUOTES);
        $ban_end_date = date("F j, Y, g:i a", $row->expire_time);
        if ($row->ip_ban == 1 && $row->account_ban == 1 && $row->banned_name == $user->name) {
            $ban_type = 'account and IP are';
        } elseif ($row->ip_ban == 1) {
            $ban_type = 'IP is';
        } elseif ($row->account_ban == 1) {
            $ban_type = 'account is';
        }
        $scope = $row->scope === 'g' ? '' : ' socially';
        $ban_link = urlify("https://pr2hub.com/bans/show_record.php?ban_id=$ban_id", 'Yes');
        $str .= "$ban_link, this $ban_type$scope banned until $ban_end_date. Reason: $reason<br><br>";
    } else {
        $str .= "$is_banned<br><br>";
    }

    // get account bans of target user
    $account_bans = db_op('bans_select_by_user_id', array($user_id));
    $account_ban_count = (int) count($account_bans);
    $str .= "This account has been banned $account_ban_count times.<br><br>";

    // make account bans list
    if ($account_ban_count !== 0) {
        $str .= '<ul>';
        foreach ($account_bans as $ban) {
            $str .= '<li>';
            $ban_id = (int) $ban->ban_id;
            $date = date("M j, Y g:i A", $ban->time);
            $mod_name = htmlspecialchars($ban->mod_name, ENT_QUOTES);
            $banned_name = htmlspecialchars($ban->banned_name, ENT_QUOTES);
            $banned_ip = htmlspecialchars(urlencode($ban->banned_ip), ENT_QUOTES);
            $duration = format_duration($ban->expire_time - $ban->time);
            $reason = htmlspecialchars($ban->reason, ENT_QUOTES);
            $lifted = (bool) $ban->lifted;
            $acc_ban = (bool) $ban->account_ban;
            $ip_ban = (bool) $ban->ip_ban;
            $scope = $ban->scope === 'g' ? '' : ' socially';

            // var init
            $nameip_str = '';
            $lifted_str = '';
            $reason = is_empty($reason) ? 'No reason was given.' : $reason;

            // make name/ip str
            $nameip_str = $acc_ban ? $nameip_str . $banned_name : $nameip_str;
            $nameip_str = $ip_ban && !$mod->trial_mod ? $nameip_str . ' [' . $banned_ip . ']' : $nameip_str;
            $nameip_str = trim($nameip_str);

            // check if lifted
            if ($lifted) {
                $lifted_datetime = date('M j, Y \a\t g:i A', $ban->lifted_time);
                $lifted_by = htmlspecialchars($ban->lifted_by, ENT_QUOTES);
                $lifted_reason = htmlspecialchars($ban->lifted_reason, ENT_QUOTES);
                $lifted_str = "<b>^ LIFTED</b> on $lifted_datetime by $lifted_by. Reason: $lifted_reason";
            }

            // craft ban string
            $date_url = urlify("https://pr2hub.com/bans/show_record.php?ban_id=$ban_id", $date);
            $ban_str = "$date_url: $mod_name$scope banned $nameip_str for $duration. Reason: $reason";

            // add to the output string
            $str = $lifted ? $str . $ban_str . '<br>' . $lifted_str : $str . $ban_str;

            // move to the next ban
            $str .= '</li>';
        }

        // end this group of bans
        $str .= '</ul><br>';
    }

    // get IP bans of target user's IP
    $ip_bans = db_op('bans_select_by_ip', array($ip));
    $ip_ban_count = (int) count($ip_bans);
    $ip_link = urlify("https://pr2hub.com/mod/ip_info.php?ip=$ip", $ip);
    $ip_lang = 'IP' . (!$mod->trial_mod ? " ($ip_link)" : '');
    $str .= "This $ip_lang has been banned $ip_ban_count times.<br><br>";

    // make account bans list
    if ($ip_ban_count !== 0) {
        $str .= '<ul>';
        foreach ($ip_bans as $ban) {
            $str .= '<li>';
            $ban_id = (int) $ban->ban_id;
            $date = date("M j, Y g:i A", $ban->time);
            $mod_name = htmlspecialchars($ban->mod_name, ENT_QUOTES);
            $banned_name = htmlspecialchars($ban->banned_name, ENT_QUOTES);
            $banned_ip = htmlspecialchars(urlencode($ban->banned_ip), ENT_QUOTES);
            $duration = format_duration($ban->expire_time - $ban->time);
            $reason = htmlspecialchars($ban->reason, ENT_QUOTES);
            $lifted = (bool) $ban->lifted;
            $acc_ban = (bool) $ban->account_ban;
            $ip_ban = (bool) $ban->ip_ban;
            $scope = $ban->scope === 'g' ? '' : ' socially';

            // var init
            $nameip_str = '';
            $lifted_str = '';
            $reason = is_empty($reason) ? 'No reason was given.' : $reason;

            // make name/ip str
            $nameip_str = $acc_ban ? $nameip_str . $banned_name : $nameip_str;
            $nameip_str = $ip_ban && !$mod->trial_mod ? $nameip_str . ' [' . $banned_ip . ']' : $nameip_str;
            $nameip_str = is_empty($nameip_str) && $mod->trial_mod ? '<i>an IP</i>' : trim($nameip_str);

            // check if lifted
            if ($lifted) {
                $lifted_datetime = date('M j, Y \a\t g:i A', $ban->lifted_time);
                $lifted_by = htmlspecialchars($ban->lifted_by, ENT_QUOTES);
                $lifted_reason = htmlspecialchars($ban->lifted_reason, ENT_QUOTES);
                $lifted_str = "<b>^ LIFTED</b> on $lifted_datetime by $lifted_by. Reason: $lifted_reason";
            }

            // craft ban string
            $date_url = urlify("https://pr2hub.com/bans/show_record.php?ban_id=$ban_id", $date);
            $ban_str = "$date_url: $mod_name$scope banned $nameip_str for $duration. Reason: $reason";

            // add to the output string
            $str = $lifted ? $str . $ban_str . '<br>' . $lifted_str : $str . $ban_str;

            // move to the next ban
            $str .= '</li>';
        }

        // end this group of bans
        $str .= '</ul>';
    }

    // tell the mod
    $mod->write("message`$str");
    return true;
}


// checks the status of a private server
function privateServerCheckStatus()
{
    global $is_ps, $server_expire_time;

    if ($is_ps && $server_expire_time <= time()) {
        db_op('servers_deactivate_expired');
        shutdown_server(null, true, 'This private server has expired. Thanks for playing!');
    }
}


// perform an operation on the db via a query fn (try reconnecting and retrying on failure)
function db_op($fn, $data = array())
{
    global $pdo, $reconnect_attempted;

    try {
        // sanity: does the fn exist?
        if (!function_exists($fn)) {
            throw new Exception("Function \"$fn\" does not exist.");
        }

        // build params and call fn
        $params = array($pdo);
        foreach ($data as $var) {
            array_push($params, $var);
        }
        $result = call_user_func_array($fn, $params);

        // got here? means it did what it was supposed to do
        $reconnect_attempted = false;
        return $result;
    } catch (Exception $e) {
        $error = $e->getMessage();
        output("DB_OP: Query \"$fn\" failed. Error: $error");
        if (!$reconnect_attempted) {
            $reconnect_attempted = true;
            output('DB_OP: Renewing database connection...');
            $pdo = null;
            $pdo = pdo_connect();
            output('DB_OP: New connection succeeded!');
            return db_op($fn, $data);
        } else {
            throw new Exception($error . " Params: " . json_encode($params));
        }
    }
}


// an account's power as the database holds it, never as a caller asserts it
function verified_power($user_id)
{
    $user = db_op('user_select_name_active_power', array((int) $user_id, true));
    return $user === false ? 0 : (int) $user->power;
}


// close socket to new connections and unbind port
// DO NOT CALL WITHOUT SHUTDOWN_SERVER OR RESTART_SERVER
function kill_socket()
{
    global $server;

    output("Closing socket and unbinding port...");
    if (!$server) {
        output("No port bound; no server socket to close.");
    } else {
        $server->__destruct();
        output("Socket closed.");
    }
}


// graceful shutdown
function shutdown_server($socket = null, $die = true, $msg = 'The server is restarting, hold on a sec...')
{
    global $player_array;

    // kill socket
    kill_socket();

    // disconnect everyone
    output('Disconnecting all players...');
    foreach ($player_array as $player) {
        $player->write("message`$msg");
        $player->remove();
    }

    // tell the world
    output('All players disconnected. Shutting down...');
    output('The shutdown was successful.');
    if (!is_null($socket)) {
        $socket->write('The shutdown was successful.');
    }

    // socketDaemon shutdown
    if ($die === true) {
        die();
    }
}


// not so graceful shutdown
function __crashHandler($force = false)
{
    // this function gets called every time the script ends so we want to make sure it's a crash
    $error = error_get_last();
    if ($error['type'] !== E_ERROR && !$force) {
        return;
    }

    // handle crash
    output("--- SERVER IS CRASHING ---");
    output("Saving data...");
    if ($error) {
        output("Error: [{$error['type']}] {$error['message']} in {$error['file']} on line {$error['line']}");
    }
    shutdown_server(
        null,
        false,
        'The server is restarting (due to an error), please rejoin in a moment.'
    );
    output("Data successfully saved.");
}
