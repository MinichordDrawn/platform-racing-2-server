<?php

require_once __DIR__ . '/control_auth.php';


// tests server connectivity
function connect_to_server($address, $server_id)
{
    output("Attempting to connect to server at $address...");
    $result = talk_to_server($address, $server_id, 'check_status`', true);
    return $result;
}


// send a command to a game server's control channel
//
// The channel listens on its own port, which is not published, and never on
// the port players connect to. The key signs the command and never travels;
// the time and the value used once make a copied command useless outside a
// short window and useless twice inside it. The server the command is for is
// signed with it, so a command made for one server is refused by every other.
function talk_to_server($address, $server_id, $process_function, $receive = true, $output = true)
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
    $server_id = (int) $server_id;
    $signature = control_sign($timestamp, $nonce, $server_id, $command, $data);

    $end = chr(0x04);
    $send_str = $signature . '`' . $timestamp . '`' . $nonce . '`' . $server_id
        . '`' . $command . '`' . $data . $end;

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
// Sends a message to the servers named, or to all of them.
//
// Whether to wait for an answer and whether to print what happens are separate
// questions, and this takes them separately. Passing $output into the
// parameter that decides whether to read a reply tied them together: asking
// for silence also gave up the answer, and asking for the answer printed the
// exchange into whatever the caller was writing. A caller that acts on the
// answer needs one without the other.
//
// $receive left unset follows $output, which is what every caller that does
// not care has always got.
function poll_servers($servers, $message, $output = true, $server_ids = array(), $receive = null)
{
    $results = array();
    $query = array();
    $receive = $receive === null ? $output : $receive;

    foreach ($servers as $server) {
        $id = (int) $server->server_id;
        $query = new stdClass();

        if (count($server_ids) == 0 || array_search($id, $server_ids) !== false) {
            $result = (string) talk_to_server($server->address, $id, $message, $receive, $output);
            $result = preg_replace('/[[:cntrl:]]/', '', $result); // remove control characters causing errors
            $query->result = json_decode($result);
            $query->command = $message;
            $query->server_id = $id;
            $results[$id] = $query;
        }
    }

    return $results;
}
