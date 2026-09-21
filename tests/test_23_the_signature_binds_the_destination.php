<?php
// A signed command is valid only at the server it was addressed to.
//
// The signature covered the time, the single-use value, the command and its
// data, but not the destination, and every server verifies with the same key.
// A command signed for one server was therefore valid at every other one
// inside the window, and the single-use value could not refuse it because the
// server that would remember it was not the server that received it.
//
// The server each command is for is now signed with it, and a server refuses
// anything addressed elsewhere.

require_once __DIR__ . '/helper.php';

$PROCESS_KEY = 'a key used only by this test';
$GLOBALS['PROCESS_KEY'] = $PROCESS_KEY;

require_once REPO . '/common/manage_socket/control_auth.php';

echo "the control signature binds the server it was made for\n";

// The destination is part of what gets signed.
$t = time();
$n = control_nonce();
ok(
    control_payload($t, $n, 7, 'shut_down', '') !== control_payload($t, $n, 9, 'shut_down', ''),
    'the signed payload distinguishes one destination from another'
);

// This process stands in for server 7.
$GLOBALS['server_id'] = 7;

$n1 = control_nonce();
$sig1 = control_sign($t, $n1, 7, 'shut_down', '');
ok(
    control_verify($sig1, $t, $n1, 7, 'shut_down', ''),
    'a command addressed to this server is accepted'
);

// The same command, collected and presented to a different server.
$GLOBALS['server_id'] = 9;

$n2 = control_nonce();
$sig2 = control_sign($t, $n2, 7, 'shut_down', '');
is_same(
    control_verify($sig2, $t, $n2, 7, 'shut_down', ''),
    false,
    'a command addressed to another server is refused'
);

// And the destination cannot simply be rewritten on the way, because it is
// covered by the signature.
$n3 = control_nonce();
$sig3 = control_sign($t, $n3, 7, 'shut_down', '');
is_same(
    control_verify($sig3, $t, $n3, 9, 'shut_down', ''),
    false,
    'rewriting the destination breaks the signature'
);

// A verifier that does not know which server it is must refuse rather than
// accept whatever it is told.
unset($GLOBALS['server_id']);
$n4 = control_nonce();
$sig4 = control_sign($t, $n4, 0, 'shut_down', '');
is_same(
    control_verify($sig4, $t, $n4, 0, 'shut_down', ''),
    false,
    'a server that does not know its own id refuses every command'
);

// The fields cannot be slid into one another: a character moved out of the
// command and into the data must not sign the same way.
ok(
    control_payload($t, $n, 7, 'shut', '`down') !== control_payload($t, $n, 7, 'shut`', 'down'),
    'the command and the data cannot be slid into one another'
);

// The sending side has to address what it signs.
$send = file_get_contents(REPO . '/common/manage_socket/socket_manage_fns.php');
ok(
    preg_match('/function talk_to_server\(\s*\$address\s*,\s*\$server_id\s*,/', $send) === 1,
    'the sender takes the server it is addressing'
);
ok(
    preg_match('/control_sign\(\s*\$timestamp\s*,\s*\$nonce\s*,\s*\$server_id\s*,/', $send) === 1,
    'the sender signs the destination with the command'
);

// Every call site passes one, so none of them can fall back to an unaddressed
// command.
$callers = array(
    'functions/cron/cron_fns.php',
    'common/manage_socket/activate_hh.php',
    'http_server/admin/restart_servers.php',
    'http_server/admin/update_account.php',
    'http_server/api/award_part.php',
    'http_server/login.php',
    'http_server/mod/purge_tokens.php',
    'http_server/vault/use_super_booster.php',
);
$unaddressed = array();
foreach ($callers as $rel) {
    $src = file_get_contents(REPO . '/' . $rel);
    // a call whose second argument is a quoted string or a bare command name
    // is one that never got a destination
    if (preg_match('/talk_to_server\(\s*[^,]+,\s*[\'"]/', $src) === 1) {
        $unaddressed[] = $rel;
    }
}
is_same($unaddressed, array(), 'no call site sends a command without a destination');

t_done();
