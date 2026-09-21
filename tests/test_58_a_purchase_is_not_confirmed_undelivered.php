<?php
// A purchase is not confirmed when nothing took delivery of it.
//
// Coins are bought with money, so an order that takes coins and delivers
// nothing costs a player something real. The delivery is a command sent to the
// game servers. It was sent with the error operator in front of it and its
// result discarded, and the order was then marked complete and a confirmation
// message sent to the buyer, whatever had happened to it. The connection
// failing, the reply never arriving and the command being refused all look
// identical from there: nothing.
//
// Two things make this fixable rather than merely detectable. Every handler
// that delivers a purchase answers, so there is an acknowledgement to read.
// And the endpoint already refunds the coins when the purchase raises, so
// refusing to complete an order gives the buyer their coins back.
//
// It is only safe to refuse for the items whose sole delivery is that command.
// Six of the ten write what was bought to the database before the command is
// sent, and the command only refreshes a running session; raising for those
// would refund coins for goods the account already holds. The four that do not
// write anything are delivered into one process's memory and are lost if the
// command does not arrive.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/pages/vault/vault_fns.php';

echo "a purchase is not confirmed when nothing took delivery\n";

// --- which items the command alone delivers ------------------------------

ok(function_exists('vault_command_only_slugs'), 'the items delivered only by the command are a list');

if (function_exists('vault_command_only_slugs')) {
    $only = vault_command_only_slugs();
    sort($only);
    is_same(
        $only,
        array('guild_artifact', 'guild_fred', 'guild_ghost', 'happy_hour'),
        'the list is the four items that write nothing before the command'
    );
}

// --- reading the acknowledgement -----------------------------------------

ok(function_exists('vault_delivery_reached_a_server'), 'an acknowledgement can be read');

if (function_exists('vault_delivery_reached_a_server')) {
    $ok = new stdClass();
    $ok->result = json_decode('{"status":"ok"}');

    $unreachable = new stdClass();
    $unreachable->result = null; // a connection that did not happen

    $garbled = new stdClass();
    $garbled->result = json_decode('"not an object"');

    $wrong = new stdClass();
    $wrong->result = json_decode('{"status":"nope"}');

    is_same(vault_delivery_reached_a_server(array()), false, 'no server was asked, so none took it');
    is_same(vault_delivery_reached_a_server(array(1 => $unreachable)), false, 'one unreachable server is no delivery');
    is_same(
        vault_delivery_reached_a_server(array(1 => $unreachable, 2 => $unreachable)),
        false,
        'several unreachable servers are no delivery'
    );
    is_same(vault_delivery_reached_a_server(array(1 => $garbled)), false, 'an answer that is not an answer is no delivery');
    is_same(vault_delivery_reached_a_server(array(1 => $wrong)), false, 'an answer that does not say ok is no delivery');
    is_same(vault_delivery_reached_a_server(array(1 => $ok)), true, 'one server acknowledging is a delivery');
    is_same(
        vault_delivery_reached_a_server(array(1 => $unreachable, 2 => $ok)),
        true,
        'one acknowledgement among failures is a delivery'
    );

    // A missing property must not be read as an acknowledgement.
    $empty = new stdClass();
    is_same(vault_delivery_reached_a_server(array(1 => $empty)), false, 'an entry with no answer is no delivery');
}

// --- the poll can ask for the answer without printing it -----------------
//
// The poll passed its third argument into the parameter that decides whether
// to read a reply, so asking it to stay quiet also told it not to listen, and
// asking it to listen made it print to the response.

$poll = file_get_contents(REPO . '/common/manage_socket/socket_manage_fns.php');

$start = strpos($poll, 'function poll_servers');
ok($start !== false, 'the poll is present');
$body = substr($poll, $start, 900);

$sig = substr($body, 0, strpos($body, "\n{"));
ok(
    strpos($sig, '$receive') !== false,
    'the poll takes whether to wait for an answer as its own argument'
);
ok(
    strpos($sig, '$output') !== false && strpos($sig, '$output') < strpos($sig, '$receive'),
    'and keeps it separate from whether to print'
);
ok(
    preg_match('/talk_to_server\(\s*\$server->address\s*,\s*\$id\s*,\s*\$message\s*,\s*\$[a-z_]+\s*,\s*\$output\s*\)/', $body) === 1,
    'and passes waiting and printing to the parameters that mean them'
);

// --- the purchase requires the delivery it depends on --------------------

$vault = file_get_contents(REPO . '/functions/http_fns/pages/vault/vault_fns.php');

$start = strpos($vault, 'function vault_purchase_item');
ok($start !== false, 'the purchase is present');
$end = strpos($vault, "\nfunction ", $start);
$purchase = substr($vault, $start, $end - $start);

ok(
    strpos($purchase, 'vault_command_only_slugs') !== false,
    'the purchase knows which items the command alone delivers'
);
ok(
    strpos($purchase, 'vault_delivery_reached_a_server') !== false,
    'and reads whether a server took it'
);
ok(
    preg_match('/throw new Exception\([^)]*[Dd]eliver/', $purchase) === 1,
    'and raises when none did, which is what returns the coins'
);

// The error operator must not sit in front of the delivery any more: it hid
// the failure this reads.
ok(
    strpos($purchase, '@poll_servers') === false,
    'the delivery no longer suppresses its own diagnostic'
);

// The order is completed and the buyer told only after the delivery is settled.
$asked_at = strpos($purchase, 'vault_delivery_reached_a_server');
$completed_at = strpos($purchase, 'vault_purchase_complete');
$told_at = strpos($purchase, 'send_confirmation_pm');
ok($asked_at !== false && $completed_at !== false && $asked_at < $completed_at, 'the order is completed after that');
ok($asked_at !== false && $told_at !== false && $asked_at < $told_at, 'the buyer is told after that');

// --- the free-goods trap -------------------------------------------------
//
// Refusing an item whose goods are already written would refund coins for
// something the account keeps. Every slug in the list must be one whose branch
// writes nothing before the command.

if (function_exists('vault_command_only_slugs')) {
    $writers = array('unlock_set(', 'rank_token_rental_insert(', 'create_server(');
    foreach (vault_command_only_slugs() as $slug) {
        $at = strpos($purchase, "'$slug'");
        ok($at !== false, "the purchase handles $slug");
        if ($at === false) {
            continue;
        }
        // the branch runs from this slug's test to the next elseif
        $next = strpos($purchase, '} elseif', $at);
        $branch = $next === false ? substr($purchase, $at, 400) : substr($purchase, $at, $next - $at);
        $writes = false;
        foreach ($writers as $w) {
            if (strpos($branch, $w) !== false) {
                $writes = true;
            }
        }
        is_same($writes, false, "$slug writes nothing before the command, so refusing it returns no goods");
    }
}

// And the refund the raise depends on is still there.
$endpoint = file_get_contents(REPO . '/http_server/vault/purchase_item.php');
ok(
    strpos($endpoint, 'user_update_coins($pdo, $user_id, $coins_deducted)') !== false,
    'the endpoint still returns the coins when the purchase raises'
);

t_done();
