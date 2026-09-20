<?php

// Signing for the control channel between the web server and a game server.
//
// The key is never sent. Each command carries the time it was made and a value
// used once, so a copied command is useless outside a short window and useless
// twice inside it.

define('CONTROL_WINDOW_SECONDS', 30);


// the string both sides sign, so neither can disagree about what was signed
function control_payload($timestamp, $nonce, $command, $data)
{
    return (int) $timestamp . '`' . $nonce . '`' . $command . '`' . $data;
}


function control_sign($timestamp, $nonce, $command, $data)
{
    global $PROCESS_KEY;
    return hash_hmac('sha256', control_payload($timestamp, $nonce, $command, $data), (string) $PROCESS_KEY);
}


// true only for a command this server signed, made recently, and not seen before
function control_verify($signature, $timestamp, $nonce, $command, $data)
{
    static $seen = array();

    $age = time() - (int) $timestamp;
    if ($age > CONTROL_WINDOW_SECONDS || $age < -CONTROL_WINDOW_SECONDS) {
        return false;
    }

    $expected = control_sign($timestamp, $nonce, $command, $data);
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


// a value used once, for the sending side
function control_nonce()
{
    return bin2hex(random_bytes(16));
}
