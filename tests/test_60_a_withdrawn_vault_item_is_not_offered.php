<?php
// An item this package cannot deliver is not offered for sale.
//
// The guild server is one. Nothing in the package starts a server process:
// no function of that name is defined, and the container image starts exactly
// one process with a fixed identifier, so a second server cannot exist. A row
// written for one keeps the status its schema gives it, the poller reads that
// as down, and every login to it is refused.
//
// Refusing the purchase after the coins have moved and returning them is the
// last of three positions, not the only one. Ahead of it the item is marked
// unavailable with the reason attached, and the endpoint refuses an
// unavailable item before it prices anything or touches the buyer's coins.
//
// The withdrawal is a list in code rather than a column in the database,
// because the column says whether an item is switched on and this says whether
// the package can deliver it at all. Switching the column back on would not
// make a server start.

require_once __DIR__ . '/helper.php';
require_once REPO . '/functions/http_fns/pages/vault/vault_fns.php';

echo "a withdrawn vault item is not offered\n";

ok(function_exists('vault_withdrawn_slugs'), 'the items this package cannot deliver are a list');

if (function_exists('vault_withdrawn_slugs')) {
    $withdrawn = vault_withdrawn_slugs();

    is_same(
        array_keys($withdrawn),
        array('server_1_day', 'server_30_days'),
        'the list is the two guild server items'
    );

    foreach ($withdrawn as $slug => $reason) {
        ok(is_string($reason) && strlen($reason) > 20, "$slug carries a reason a buyer can read");
    }

    // An item that is sold and delivered must not be on it.
    foreach (array('happy_hour', 'king_set', 'rank_rental', 'guild_fred') as $sold) {
        ok(!isset($withdrawn[$sold]), "$sold is not withdrawn");
    }
}

// --- the listing marks it unavailable ------------------------------------

$vault = file_get_contents(REPO . '/functions/http_fns/pages/vault/vault_fns.php');

$start = strpos($vault, 'function describeVault');
ok($start !== false, 'the listing is present');
$end = strpos($vault, "\nfunction ", $start);
$describe = substr($vault, $start, $end - $start);

ok(strpos($describe, 'vault_withdrawn_slugs') !== false, 'the listing asks the list');

// It has to override whatever the per-item branch decided, not sit inside the
// chain where another branch could set it available again.
$chain_at = strpos($describe, "\$slug === 'server_1_day'");
$withdrawn_at = strpos($describe, 'vault_withdrawn_slugs');
ok(
    $chain_at !== false && $withdrawn_at !== false && $withdrawn_at > $chain_at,
    'and asks it after the branch that would otherwise offer the item'
);

ok(
    preg_match('/vault_withdrawn_slugs.*?available\s*=\s*false/s', $describe) === 1,
    'a withdrawn item is not available'
);

// --- the endpoint refuses before it takes anything -----------------------

$endpoint = file_get_contents(REPO . '/http_server/vault/purchase_item.php');

$unavailable_at = strpos($endpoint, '$item->available');
$priced_at = strpos($endpoint, '$price = $quantity * $item->price');
$purchase_at = strpos($endpoint, 'vault_purchase_item(');

ok($unavailable_at !== false, 'the endpoint tests availability');
ok(
    $unavailable_at !== false && $priced_at !== false && $unavailable_at < $priced_at,
    'before it prices anything'
);
ok(
    $unavailable_at !== false && $purchase_at !== false && $unavailable_at < $purchase_at,
    'and before any coins move'
);

// --- the last position is still there ------------------------------------

ok(
    function_exists('vault_server_purchase_delivered'),
    'the refusal that returns the coins is still there behind it'
);
if (function_exists('vault_server_purchase_delivered')) {
    is_same(vault_server_purchase_delivered(1), false, 'and still refuses a server nothing starts');
}

t_done();
