<?php

// Signing for the control channel between the web server and a game server.
//
// The key is never sent. Each command carries the time it was made and a value
// used once, so a copied command is useless outside a short window and useless
// twice inside it.

define('CONTROL_WINDOW_SECONDS', 30);


// The string both sides sign, so neither can disagree about what was signed.
//
// The server the command is for is part of it. Every server verifies with the
// same key, so without this a command made for one server would be valid at
// all of them, and the record of values already used could not help: the
// server holding that record is not the server the copy was presented to.
function control_payload($timestamp, $nonce, $server_id, $command, $data)
{
    // The command and the data are given their lengths first, so that moving a
    // character out of one and into the other cannot produce the same string to
    // sign. A separator alone would not do that, because the separator can
    // appear inside the data.
    return (int) $timestamp
        . '`' . $nonce
        . '`' . (int) $server_id
        . '`' . strlen((string) $command) . ':' . $command
        . '`' . strlen((string) $data) . ':' . $data;
}


function control_sign($timestamp, $nonce, $server_id, $command, $data)
{
    global $PROCESS_KEY;
    return hash_hmac(
        'sha256',
        control_payload($timestamp, $nonce, $server_id, $command, $data),
        (string) $PROCESS_KEY
    );
}


// true only for a command addressed to this server, signed with the key, made
// recently, and not seen before
function control_verify($signature, $timestamp, $nonce, $target_id, $command, $data)
{
    global $server_id;

    static $seen = array();

    // A process that does not know which server it is cannot tell whether a
    // command was meant for it, so it refuses rather than assuming.
    if (!isset($server_id)) {
        return false;
    }

    if ((int) $target_id !== (int) $server_id) {
        return false;
    }

    $age = time() - (int) $timestamp;
    if ($age > CONTROL_WINDOW_SECONDS || $age < -CONTROL_WINDOW_SECONDS) {
        return false;
    }

    $expected = control_sign($timestamp, $nonce, $target_id, $command, $data);
    if (!hash_equals($expected, (string) $signature)) {
        return false;
    }

    // drop anything too old to be replayed, then refuse a repeat
    foreach ($seen as $key => $seen_at) {
        if (time() - $seen_at > CONTROL_WINDOW_SECONDS) {
            unset($seen[$key]);
        }
    }
    if (isset($seen[$nonce])) {
        return false;
    }
    $seen[$nonce] = time();

    return true;
}


// Addresses the control channel will answer from.
//
// The port is not published, so this is a second line rather than the first
// one: it means a port mapping added later does not by itself put the process
// handlers on the internet. $PROCESS_ALLOWED_PREFIXES overrides the default.
function control_address_allowed($address)
{
    global $PROCESS_ALLOWED_PREFIXES;

    $address = (string) $address;
    if ($address === '') {
        return false;
    }

    $prefixes = !empty($PROCESS_ALLOWED_PREFIXES) && is_array($PROCESS_ALLOWED_PREFIXES)
        ? $PROCESS_ALLOWED_PREFIXES
        : array('127.', '::1', '10.', '192.168.', '172.16.', '172.17.', '172.18.', '172.19.',
                '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.',
                '172.27.', '172.28.', '172.29.', '172.30.', '172.31.');

    foreach ($prefixes as $prefix) {
        if (strpos($address, $prefix) === 0) {
            return true;
        }
    }

    return false;
}


// How many control connections a game server will hold at once.
//
// These all arrive from the web tier and the poller, which share one address,
// so a per-address cap is the wrong shape: the right bound is on the channel
// as a whole. The number follows from the server's own capacity. One hand-off
// is made per login, a server holds at most $max_players, so more concurrent
// hand-offs than that serve nobody; the cap is set at twice that, which leaves
// a whole server's worth of room for reconnections after a restart while the
// poller and staff actions are also in flight.
//
// $PROCESS_MAX_CONNECTIONS overrides it. A value that is not a positive number
// is ignored rather than taken as no limit.
function control_connection_limit()
{
    global $max_players, $PROCESS_MAX_CONNECTIONS;

    if (isset($PROCESS_MAX_CONNECTIONS) && (int) $PROCESS_MAX_CONNECTIONS > 0) {
        return (int) $PROCESS_MAX_CONNECTIONS;
    }

    // a process that never set a capacity still gets a bound
    $capacity = isset($max_players) && (int) $max_players > 0 ? (int) $max_players : 100;

    return $capacity * 2;
}


// a value used once, for the sending side
function control_nonce()
{
    return bin2hex(random_bytes(16));
}
