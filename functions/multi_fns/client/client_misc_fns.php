<?php


// check status
function client_check_status($socket)
{
    $socket->write('ok');
}


// close client connection
function client_close($socket)
{
    if (method_exists($socket, 'markIntentionalDisconnect')) {
        $socket->markIntentionalDisconnect();
    }
    $socket->close();
    $socket->onDisconnect();
}


// ping
function client_ping($socket)
{
    $socket->write('ping`' . time());
}


// request a login id
function client_request_login_id($socket)
{
    if (!isset($socket->login_id)) {
        global $login_array;
        $socket->login_id = get_login_id();
        $login_array[$socket->login_id] = $socket;

        // The key goes out before it is held, so that the message carrying it
        // is not itself signed with it. A client has nothing to check that one
        // against, and everything after it is signed.
        $key = new_session_key();
        $socket->write('setSessionKey`' . $key);
        $socket->session_key = $key;

        $socket->write('setLoginID`'.$socket->login_id);
    }
}

// get player info (tries from socket first, then HTTP if not online)
function client_get_player_info($socket, $data)
{
    $me = $socket->getPlayer();
    $target = name_to_player($data);
    if (isset($target)) {
        $obj = $target->getInfo();
        $obj->following = in_array($obj->userId, $me->following_array);
        $obj->friend = in_array($obj->userId, $me->friends_array);
        $obj->ignored = in_array($obj->userId, $me->ignored_array);
        $ret = json_encode($obj);
        $socket->write("playerInfo`$ret");
        return;
    }
    $socket->write('playerInfo`0');
}
