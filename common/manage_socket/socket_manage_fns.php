<?php

require_once __DIR__ . '/control_auth.php';


// tests server connectivity
function connect_to_server($address)
{
    output("Attempting to connect to server at $address...");
    $result = talk_to_server($address, 'check_status`', true);
    return $result;
}


// send a command to a game server's control channel
//
// The channel listens on its own port, which is not published, and never on
// the port players connect to. The key signs the command and never travels;
// the time and the value used once make a copied command useless outside a
// short window and useless twice inside it.
function talk_to_server($address, $process_function, $receive = true, $output = true)
{
    global $PROCESS_PORT;

    if ($receive === false) {
        $output = false;
    }

    // the caller passes "command`data"; both are signed
    $parts = explode('`', $process_function, 2);
    $command = $parts[0];
    $data = isset($parts[1]) ? $parts[1] : '';

    $timestamp = time();
    $nonce = control_nonce();
    $signature = control_sign($timestamp, $nonce, $command, $data);

    $end = chr(0x04);
    $send_str = $signature . '`' . $timestamp . '`' . $nonce . '`' . $command . '`' . $data . $end;

    // connect to the server
    if ($output === true) {
        output("Attempting to talk to server at $address:$PROCESS_PORT...");
    }
    $reply = true;
    $fsock = $output
        ? @fsockopen($address, $PROCESS_PORT, $errno, $errstr, 5)
        : fsockopen($address, $PROCESS_PORT, $errno, $errstr, 5);
    if ($fsock !== false) {
        if ($output === true) {
            output("Successfully connected! Writing: $command");
        }
        fputs($fsock, $send_str);
        stream_set_timeout($fsock, 5);
        if ($receive === true) {
            $reply = fread($fsock, 99999);
        }
        fclose($fsock);
    } else {
        $reply = false;
        if ($output === true) {
            output("Error $errno: $errstr. Could not connect to $address:$PROCESS_PORT.");
        }
    }

    // skip output if told to do so
    if ($output === false) {
        return $reply;
    }

    // interpret the reply
    if (empty($reply)) {
        output("ERROR: No response received from the server.");
        return false;
    } else {
        output("Server Reply: $reply");
        return $reply;
    }
}


// send a message to every server
// DO NOT OUTPUT ANYTHING FROM THIS FUNCTION FOR TESTING
function poll_servers($servers, $message, $output = true, $server_ids = array())
{
    $results = array();
    $query = array();

    foreach ($servers as $server) {
        $id = (int) $server->server_id;
        $query = new stdClass();

        if (count($server_ids) == 0 || array_search($id, $server_ids) !== false) {
            $result = (string) talk_to_server($server->address, $message, $output);
            $result = preg_replace('/[[:cntrl:]]/', '', $result); // remove control characters causing errors
            $query->result = json_decode($result);
            $query->command = $message;
            $query->server_id = $id;
            $results[$id] = $query;
        }
    }

    return $results;
}
