<?php
// Only a handler may be reached as a handler.
//
// The dispatcher builds the name of the function to call by putting client_ in
// front of the command out of the packet, and calls it if a function by that
// name exists. It resolves against every function the process has defined, not
// against a list of handlers, so any global function whose name begins with
// client_ is reachable from the wire whatever it was written for.
//
// Two helpers added with the packet signing were named that way, so a packet
// could call them. One takes no arguments and would have quietly returned a
// fresh session key to nobody. The other takes four and would have raised an
// ArgumentCountError, which is not an Exception, so before faults were
// contained it would have ended the server process.
//
// They are renamed. The dispatcher's habit of resolving against the whole
// function table is a property of the design and is recorded in the model
// rather than changed here; this test is what keeps the habit from being
// handed anything new to find.

require_once __DIR__ . '/helper.php';

echo "only handlers are reachable as handlers\n";

// Every file that defines a function beginning with client_.
$defining = array();
$roots = array('/functions', '/multiplayer_server', '/common');

foreach ($roots as $root) {
    $dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(REPO . $root));
    foreach ($dir as $file) {
        if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
            continue;
        }
        $src = file_get_contents($file->getPathname());
        if (preg_match_all('/^function\s+(client_[a-zA-Z0-9_]*)\s*\(/m', $src, $m)) {
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(REPO) + 1));
            foreach ($m[1] as $name) {
                $defining[$name] = $rel;
            }
        }
    }
}

ok(count($defining) > 0, 'the handlers were found at all, so the search works');

// Every one of them has to live in the handler directory. A function named
// this way anywhere else is reachable from the wire by accident.
$stray = array();
foreach ($defining as $name => $rel) {
    if (strpos($rel, 'functions/multi_fns/client/') !== 0) {
        $stray[] = "$name in $rel";
    }
}
is_same($stray, array(), 'no function outside the handler directory is named like a handler');

// The two that were named that way must be gone under those names.
$utils = file_get_contents(REPO . '/functions/multi_fns/utils.php');
is_same(
    preg_match('/^function\s+client_/m', $utils),
    0,
    'the signing helpers are no longer named like handlers'
);

// And they must still exist under their own names, so the rename was a rename.
ok(strpos($utils, 'function new_session_key(') !== false, 'the key helper is still there');
ok(strpos($utils, 'function sign_client_packet(') !== false, 'the signing helper is still there');
ok(strpos($utils, 'function sign_server_packet(') !== false, 'the reply signing helper is still there');

// The callers were renamed with them.
$client = file_get_contents(REPO . '/multiplayer_server/PR2Client.php');
$misc = file_get_contents(REPO . '/functions/multi_fns/client/client_misc_fns.php');
ok(strpos($client, 'sign_client_packet') !== false, 'the connection calls the signing helper by its new name');
ok(strpos($client, 'sign_server_packet') !== false, 'and the reply one');
ok(strpos($misc, 'new_session_key') !== false, 'the key is drawn by its new name');
is_same(strpos($client, 'client_packet_signature'), false, 'the old name is gone from the connection');
is_same(strpos($misc, 'client_session_key'), false, 'the old name is gone from the handler');

t_done();
