<?php
// A guild server purchase does not claim a server is starting.
//
// Buying a guild server writes a row and tells the buyer the server is
// starting and will be ready in about two minutes. The call that would start
// the process is commented out at both places it appears, and no function of
// that name is defined anywhere in the package, so nothing starts it. The row
// keeps the status its schema gives it, the poller reads that as down, and the
// login test refuses every login to it. The buyer has paid and waits for
// something that cannot arrive.
//
// Extending a server that is already running is a different case and a real
// one: the row's expiry is updated, which is durable, and the running process
// is told. That still works and is not refused.
//
// The function that does this also discarded whatever went wrong. It caught
// every exception, threw the message away, and returned from inside a finally
// block, which discards anything raised that the catch did not take. The
// caller reads a status of nothing as failure and returns the coins, so the
// refund held; what was lost was any record of why.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/pages/vault/vault_fns.php';

echo "a guild server purchase says what happened\n";

// --- which outcomes are a delivery ---------------------------------------

ok(function_exists('vault_server_purchase_delivered'), 'the outcomes that deliver something are settled by a function');

if (function_exists('vault_server_purchase_delivered')) {
    // 0: nothing was written at all.
    is_same(vault_server_purchase_delivered(0), false, 'a purchase that wrote nothing delivered nothing');

    // 1: a row exists for a server that nothing starts.
    is_same(vault_server_purchase_delivered(1), false, 'a server nothing starts is not a delivery');

    // 2: a running server's life was extended, which is durable and real.
    is_same(vault_server_purchase_delivered(2), true, 'extending a running server is a delivery');

    // Anything else is not a yes.
    is_same(vault_server_purchase_delivered(3), false, 'an outcome this server does not have is not a delivery');
    is_same(vault_server_purchase_delivered(-1), false, 'a negative outcome is not a delivery');
    is_same(vault_server_purchase_delivered('2'), true, 'the outcome is read as a number');
    is_same(vault_server_purchase_delivered(null), false, 'no outcome is not a delivery');
}

// --- the purchase acts on it ---------------------------------------------

$vault = file_get_contents(REPO . '/functions/http_fns/pages/vault/vault_fns.php');

$start = strpos($vault, 'function vault_purchase_item');
$end = strpos($vault, "\nfunction ", $start);
$purchase = substr($vault, $start, $end - $start);

ok(
    strpos($purchase, 'vault_server_purchase_delivered') !== false,
    'the purchase asks whether the outcome delivered anything'
);

// The claim that a server is starting must not be made, because nothing does.
ok(
    strpos($purchase, 'starting up') === false,
    'the purchase does not claim a server is starting'
);
ok(
    strpos($purchase, 'ready in about 2 minutes') === false,
    'and does not say when it will be ready'
);

// Extending a running server still reports what it did.
ok(
    strpos($purchase, 'has been extended') !== false || strpos($purchase, 'life of your private server') !== false,
    'extending a running server still tells the buyer what happened'
);

// --- the reason a purchase failed is recorded ----------------------------

$start = strpos($vault, 'function create_server');
ok($start !== false, 'the server creation is present');
$create = substr($vault, $start);
$next = strpos($create, "\nfunction ", 1);
$create = $next === false ? $create : substr($create, 0, $next);

ok(
    strpos($create, 'unset($e)') === false,
    'the reason a server could not be made is not thrown away'
);
ok(
    strpos($create, 'error_log') !== false,
    'and is written where a reason can be read'
);

// A return inside finally discards anything raised that the catch did not
// take, including a fault that is not an exception.
ok(
    preg_match('/finally\s*\{\s*return/', $create) !== 1,
    'the outcome is not returned from inside a finally block'
);

// The call that would start a process is not present as a live call. It is
// not defined anywhere, so a live call would be a fault rather than a start.
ok(
    preg_match('/^\s*start_server\s*\(/m', $create) !== 1,
    'nothing calls a function this package does not define'
);

// --- and it is still absent from the package -----------------------------

$defined = false;
$dir = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(REPO));
foreach ($dir as $file) {
    if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    if (preg_match('/function\s+start_server\s*\(/', file_get_contents($file->getPathname()))) {
        $defined = true;
        break;
    }
}
is_same($defined, false, 'the package still defines no way to start a server, which is why this is refused');

t_done();
