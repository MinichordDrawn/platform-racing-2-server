<?php
// Player packets must carry a signature the server can check, made with a key
// belonging to that one session.
//
// Packets carried three hex characters of an md5, which the server computed
// and then did not compare: the comparison is a comment. It could not have
// been turned on as it stood, because the two sides never salted the same way
// - the client salts with a value baked into it and keyed by server id, the
// server with a single constant - so every packet would have been rejected.
// Twelve bits would not have been worth much even reconciled.
//
// The replacement is a full digest over the sequence number, the command and
// its data, keyed per session with a value the server draws when the
// connection asks for its login id.
//
// What this can and cannot do is worth being exact about. Whoever runs the
// client holds its key, so this cannot stop a player forging their own
// packets; nothing on this channel can, and that is what server side checking
// of the values themselves is for. It does stop one player forging another's,
// stops a packet being replayed into a different session, and stops anyone who
// can reach the connection without holding the key.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/multi_fns/utils.php';

echo "player packets are signed with a per session key\n";

// --- the key ------------------------------------------------------------

$a = new_session_key();
$b = new_session_key();

ok(strlen($a) === 64, 'a session key is a full length value');
ok(ctype_xdigit($a), 'a session key is hexadecimal');
ok($a !== $b, 'each session gets its own key');

$keys = array();
for ($i = 0; $i < 200; $i++) {
    $keys[new_session_key()] = true;
}
is_same(count($keys), 200, 'keys do not repeat');

// --- the signature ------------------------------------------------------

$sig = sign_client_packet($a, 5, 'chat', 'hello');

ok(strlen($sig) === 64, 'the signature is a full digest, not three characters');
is_same(sign_client_packet($a, 5, 'chat', 'hello'), $sig, 'the same packet signs the same way');

ok(sign_client_packet($a, 6, 'chat', 'hello') !== $sig, 'a different sequence number signs differently');
ok(sign_client_packet($a, 5, 'quit_race', 'hello') !== $sig, 'a different command signs differently');
ok(sign_client_packet($a, 5, 'chat', 'goodbye') !== $sig, 'different data signs differently');
ok(sign_client_packet($b, 5, 'chat', 'hello') !== $sig, 'another session cannot sign this packet');

// The parts must not run together, or moving a character from one field to the
// next would sign the same.
ok(
    sign_client_packet($a, 5, 'chat', 'x`y') !== sign_client_packet($a, 5, 'chat`x', 'y'),
    'the fields cannot be slid into one another'
);

// --- the other direction ------------------------------------------------

// What the server sends back is signed the same way, so a client can tell a
// message from its own server from anything else reaching the connection. It
// used to be three characters of a digest of a constant compiled into the
// client, which told the client nothing it did not already hold.
$out = sign_server_packet($a, 3, 'setStatus`racing');

ok(strlen($out) === 64, 'the server signs with a full digest too');
is_same(sign_server_packet($a, 3, 'setStatus`racing'), $out, 'the same message signs the same way');
ok(sign_server_packet($a, 4, 'setStatus`racing') !== $out, 'a different sequence number signs differently');
ok(sign_server_packet($b, 3, 'setStatus`racing') !== $out, 'another session cannot sign it');
ok(
    sign_server_packet($a, 3, 'a') !== sign_server_packet($a, 3, 'a' . "\0"),
    'the body is covered exactly'
);

// The shared constant is gone from the player channel in both directions.
$client_src = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');
ok(strpos($client_src, 'SALT') === false, 'the shared salt no longer signs anything on this channel');
ok(strpos($client_src, 'sign_server_packet') !== false, 'the server signs what it sends with the session key');

// The message carrying the key must not be signed with the key it carries: a
// client has nothing to check that one against yet. So the key is sent before
// the connection holds it.
$issue = file_get_contents(REPO . '/functions/multi_fns/client/client_misc_fns.php');
$sent_at = strpos($issue, "write('setSessionKey");
$held_at = strpos($issue, 'session_key = $key');
ok($sent_at !== false && $held_at !== false, 'the key is both sent and held');
ok($sent_at < $held_at, 'the key is sent before the connection holds it, so that message goes out unsigned');

// --- the connection has to apply it -------------------------------------

$src = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');

ok(strpos($src, 'sign_client_packet') !== false, 'the connection checks the signature');
ok(strpos($src, 'hash_equals') !== false, 'the comparison is constant time');
ok(strpos($src, '$session_key') !== false, 'the connection holds a key for its session');

// The old dead check must be gone rather than left sitting there commented.
ok(strpos($src, "doesn't match. Recieved") === false, 'the commented out check is gone');
ok(
    preg_match('/substr\(\s*\$local_hash\s*,\s*0\s*,\s*3\s*\)/', $src) !== 1,
    'the three character digest is gone'
);

// Before a key exists only the request that obtains one may be sent.
ok(
    strpos($src, 'request_login_id') !== false,
    'the connection names the one command allowed before a key exists'
);

t_done();
