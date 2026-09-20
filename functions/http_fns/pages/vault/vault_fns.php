<?php


// populate descriptions for vault items
function describeVault($pdo, $user, $items_to_get = 'all')
{
    // gather user info
    if (is_int($user)) {
        $user = user_select_expanded($pdo, $user);
    }
    $server = server_select($pdo, $user->server_id);
    $guild = $user->guild != 0 ? guild_select($pdo, $user->guild) : false;

    // get requested items
    $vault_info = file_get_contents(CACHE_DIR . '/vault.json');
    if (!$vault_info) {
        regenerate_vault_items($pdo, false);
        $vault_info = file_get_contents(CACHE_DIR . '/vault.json');
        if (!$vault_info) {
            throw new Exception('Could not retrieve vault info.');
        }
    }

    // populate array
    $vault_info = json_decode($vault_info);
    if (!isset($vault_info->listings) || !is_object($vault_info->listings)) {
        regenerate_vault_items($pdo, false);
        $vault_info = json_decode(file_get_contents(CACHE_DIR . '/vault.json'));
        if (!isset($vault_info->listings) || !is_object($vault_info->listings)) {
            throw new Exception('Could not retrieve vault info.');
        }
    }
    $items = $items_to_get === 'all' ? $vault_info->listings : new stdClass();
    if ($items_to_get !== 'all') {
        foreach ($items_to_get as $slug) {
            if (isset($vault_info->listings->$slug)) {
                $items->$slug = $vault_info->listings->$slug;
                continue;
            }
            throw new Exception('Invalid item specified.');
        }
    }

    // check item availablity
    $listings = [];
    foreach ($items as $slug => $item) {
        $item->max_quantity = (int) $item->max_quantity; // quick typecast
        $item->available = false;
        if ($slug === 'stats_boost') {
            $item->available = $server->tournament == 0 && !apcu_exists("sb-$user->user_id-" . round(time() / 86400));
        } elseif ($slug === 'happy_hour') {
            $item->available = $server->tournament == 0;
        } elseif ($slug === 'rank_rental') {
            $item->rented_tokens = rank_token_rentals_count($pdo, $user->user_id, $user->guild);
            $rt_lang = $item->rented_tokens > 0 ? 'another' : 'a';
            $item->available = $item->rented_tokens < 21;
            $item->price = 50 + (20 * $item->rented_tokens);
            $item->description = "You and your guild gain $rt_lang rank token for a week.";
        } elseif ($slug === 'king_set') {
            $item->available = array_search(28, explode(',', $user->head_array)) === false;
        } elseif ($slug === 'queen_set') {
            $item->available = array_search(29, explode(',', $user->head_array)) === false;
        } elseif ($slug === 'djinn_set') {
            $item->available = array_search(35, explode(',', $user->head_array)) === false;
        } elseif ($slug === 'server_1_day' || $slug === 'server_30_days') {
            if ($guild && $guild->owner_id == $user->user_id) {
                $item->available = true;
            } else {
                $item->faq .= "\n\n<b>Why can't I create a private server?</b>\nThis option is for guild owners only!";
            }
        } elseif ($slug == 'epic_everything') {
            $item->available = array_search('*', explode(',', $user->epic_heads)) === false;
        } else {
            $item->available = true;
        }

        $listings[] = $item;
    }

    // tell the world
    $ret = new stdClass();
    $ret->info = $vault_info->info;
    $ret->info->retrieved = time();
    $ret->listings = $listings;
    return $ret;
}


// The items whose only delivery is the command sent to the game servers.
//
// Every other item in the vault writes what was bought to the database before
// the command is built, and the command refreshes a session that is already
// running; an account that never receives it still holds the goods. These four
// write nothing. What they buy is added to one process's memory and is there
// only while that process runs, so a command that arrives nowhere is an item
// that exists nowhere.
//
// This list decides which purchases may be refused, and refusing returns the
// coins. Adding an item here that writes its goods first would return coins for
// something the account keeps.
function vault_command_only_slugs()
{
    return array('guild_fred', 'guild_ghost', 'guild_artifact', 'happy_hour');
}


// Whether any server answered that it took delivery.
//
// Each handler that delivers a purchase answers, so an entry carrying that
// answer is one server having acted on it. An entry with no answer is a server
// that could not be reached, did not reply in time, or refused the command;
// none of those is a delivery, and the three are not distinguishable from here.
function vault_delivery_reached_a_server($results)
{
    if (!is_array($results)) {
        return false;
    }

    foreach ($results as $entry) {
        if (!is_object($entry) || !isset($entry->result) || !is_object($entry->result)) {
            continue;
        }
        if (isset($entry->result->status) && $entry->result->status === 'ok') {
            return true;
        }
    }

    return false;
}


// Whether an outcome from create_server delivered anything the buyer can use.
//
// Nothing wrote at all, and a row for a server nothing starts, are both
// nothing: this package defines no way to start a server process, so a row
// written for one that is not already running keeps the status its schema
// gives it, the poller reads that as down, and every login to it is refused.
//
// Extending a server that is already running is the one outcome that delivers:
// the expiry on the row moves, which is durable, and the running process is
// told.
function vault_server_purchase_delivered($status_code)
{
    return (int) $status_code === 2;
}


function vault_purchase_item($pdo, $user, $item, $price, $quantity = 1)
{
    global $coins_deducted;

    $slug = $item->slug;
    $user_id = (int) $user->user_id;
    $guild_id = (int) $user->guild;

    // do the purchase
    $order_id = (int) vault_purchase_insert($pdo, $user_id, $guild_id, $slug, $price, $quantity);
    if ($order_id <= 0) {
        throw new Exception('Unable to complete vault purchase.');
    }

    // deduct the coins from the buyer's account
    user_update_coins($pdo, $user_id, 0 - $price);
    $coins_deducted = $price;

    // communication w/ server
    $command = "unlock_perk`$slug`$user_id`$guild_id`$user->name`$quantity";
    $reply = '';
    $target_servers = [];

    // handle items
    if ($slug === 'guild_fred') {
        $reply = 'Fred smiles on you!';
    } elseif ($slug === 'guild_ghost') {
        $reply = 'Ninja mode: engage!';
    } elseif ($slug === 'guild_artifact') {
        $reply = 'Ultimate power, courtesy of Fred!';
    } elseif ($slug === 'happy_hour') {
        $target_servers = [$user->server_id];
        $reply = 'These will be the happiest ' . ($quantity > 1 ? $quantity . ' hours' : 'hour') . ' ever!';
    } elseif ($slug === 'king_set') {
        unlock_set($pdo, $user_id, [28, 26, 24]);
        $command = "unlock_set_king`$user_id";
        $reply = 'The Wise King set has been added your account!';
    } elseif ($slug === 'queen_set') {
        unlock_set($pdo, $user_id, [29, 27, 25]);
        $command = "unlock_set_queen`$user_id";
        $reply = 'The Wise Queen set has been added your account!';
    } elseif ($slug === 'djinn_set') {
        unlock_set($pdo, $user_id, [35, 35, 35]);
        $command = "unlock_set_djinn`$user_id";
        $reply = 'The Frost Djinn set has been added your account!';
    } elseif ($slug === 'epic_everything') {
        unlock_set($pdo, $user_id, 'epic_everything');
        $command = "unlock_epic_everything`$user_id";
        $reply = 'All Epic Upgrades are yours!';
    } elseif ($slug === 'server_1_day' || $slug === 'server_30_days') {
        $command = '';
        $days = $quantity * ((int) explode('_', $slug)[1]);
        $result = create_server($pdo, $guild_id, $days);

        // Only one of the outcomes hands the buyer something. This package
        // defines nothing that starts a server process, so a row written for a
        // server that is not already running is a server that never answers,
        // and every login to it is refused. Raising here returns the coins.
        if (!vault_server_purchase_delivered($result->status_code)) {
            throw new Exception(
                'A private server could not be started for your guild, so the purchase did not go '
                . 'through and your coins have been returned. Please contact a member of the PR2 '
                . 'staff team, who can set one up for you.'
            );
        }

        $reply = 'The life of your private server has been extended! Long live your guild!'
            ."\n\n(New expiration time: ";

        $command = "extend_server_life`$guild_id`$result->new_time";
        $reply .= date('F j, Y \a\t g:ia T', $result->new_time) . ')';
    } elseif ($slug === 'rank_rental') {
        rank_token_rental_insert($pdo, $user_id, $guild_id, $quantity);

        $obj = new stdClass();
        $obj->user_id = $user_id;
        $obj->guild_id = $guild_id;
        $obj->quantity = $quantity;
        $data = json_encode($obj);

        $command = "unlock_rank_token_rental`$data";
        $reply = 'You just got ' . ($quantity === 1 ? 'a rank token' : "$quantity rank tokens") . '!';
    } else {
        throw new Exception("Item not found: " . strip_tags($slug, '<br>'));
    }

    // Send the item command to the servers, and wait for an answer rather than
    // sending it into the dark. The servers are asked without printing the
    // exchange, because this runs inside a response the buyer receives.
    if (!empty($command)) {
        $targets = isset($target_servers) ? $target_servers : [];
        $delivery = poll_servers(servers_select($pdo), $command, false, $targets, true);

        // For an item the command alone delivers, a command nothing took is an
        // item nothing holds. The order is not completed and the buyer is not
        // told it was: raising here returns the coins the endpoint deducted.
        // The order row stays as it was inserted, incomplete, which is the
        // record that this happened.
        if (in_array($slug, vault_command_only_slugs(), true)
            && !vault_delivery_reached_a_server($delivery)
        ) {
            throw new Exception(
                'No game server took delivery of this item, so the purchase did not go through '
                . 'and your coins have been returned. Please try again in a moment.'
            );
        }
    }

    // get active purchase (to calculate start time)
    $active_purchase = vault_purchase_select_active($pdo, $slug, $user_id, $guild_id);
    $start_time = !empty($active_purchase) ? $active_purchase->start_time + ($quantity * 3600) : time();

    // complete
    vault_purchase_complete($pdo, $order_id, $start_time);
    send_confirmation_pm($pdo, $user_id, $order_id, $item->title, $price, $quantity);
    return $reply;
}


function unlock_set($pdo, $user_id, $part_ids)
{
    if ($part_ids === 'epic_everything') { // epic_everything
        award_part($pdo, $user_id, 'eHat', '*');
        award_part($pdo, $user_id, 'eHead', '*');
        award_part($pdo, $user_id, 'eBody', '*');
        award_part($pdo, $user_id, 'eFeet', '*');
    } else {
        award_part($pdo, $user_id, 'head', $part_ids[0]);
        award_part($pdo, $user_id, 'body', $part_ids[1]);
        award_part($pdo, $user_id, 'feet', $part_ids[2]);
        award_part($pdo, $user_id, 'eHead', $part_ids[0]);
        award_part($pdo, $user_id, 'eBody', $part_ids[1]);
        award_part($pdo, $user_id, 'eFeet', $part_ids[2]);
    }
}


function create_server($pdo, $guild_id, $days_of_life)
{
    // existing server info
    $existing_server = server_select_by_guild_id($pdo, $guild_id);
    $port = servers_select_highest_port($pdo) + 1;

    // guild info
    $guild = guild_select($pdo, $guild_id);
    $guild_id = (int) $guild->guild_id;
    $server_name = $guild->guild_name;

    $ret = new stdClass();
    $ret->status_code = 0;
    try {
        // ...time after time
        $life_secs = 86400 * $days_of_life;
        $life_from_now = time() + $life_secs;

        if (!$existing_server) { // server doesn't exist in the db
            global $SERVER_IP;

            // The row is written. Nothing starts a process for it: this
            // package defines no function that does, so the row keeps the
            // status its schema gives it, the poller reads that as down, and
            // every login to it is refused. The caller refuses the purchase on
            // this outcome for that reason, and the buyer keeps their coins.
            $server_id = server_insert($pdo, $life_from_now, $server_name, $SERVER_IP, $port, $guild_id);

            // return data
            $ret->new_time = $life_from_now;
            $ret->status_code = 1;
        } else { // server exists and is either active or inactive
            // get server info
            $server_id = (int) $existing_server->server_id;
            $active = (bool) (int) $existing_server->active;

            // do expiration time calculations
            $life_from_expiry = $existing_server->expire_time + $life_secs;
            $life_from_expiry = $life_from_expiry < $life_from_now ? $life_from_now : $life_from_expiry;

            // update info (and activate server if applicable)
            server_update_expire_time($pdo, $life_from_expiry, $server_id);
            // A row that is not active needs a process started for it, and
            // nothing here starts one, so this outcome is refused by the
            // caller in the same way a new row is.

            // return data
            $ret->new_time = $life_from_expiry;
            $ret->status_code = $active ? 2 : 1; // if server was inactive, return the new server message to user
        }
    } catch (Exception $e) {
        // The outcome stays at nothing, which the caller reads as a failure
        // and returns the coins for. The reason it failed goes to the log
        // rather than to the buyer, who cannot act on it, and rather than
        // nowhere, which is where it went when this discarded the exception.
        error_log('create_server failed for guild ' . (int) $guild_id . ': ' . $e->getMessage());
    }

    // Returned here rather than from inside a finally block. A return in
    // finally discards anything raised that the catch did not take, a fault
    // that is not an exception included, so a failure could not be seen at all.
    return $ret;
}


function send_confirmation_pm($pdo, $user_id, $order_id, $title, $price, $quantity)
{
    $cam_link = urlify('https://jiggmin2.com/cam', 'Contact a Mod forum');
    $jv_link = urlify('https://jiggmin2.com/forums', 'Jiggmin\'s Village');
    $pm = 'Howdy! This PM is to confirm your recent Vault of Magics order.'
        ."\n\nOrder ID: $order_id"
        ."\nItem: $title"
        ."\nQuantity: $quantity"
        ."\nCoins Spent: $price"
        ."\n\nThis is an automatically generated PM, so please don't reply. "
        ."If you encounter any problems with your order, please contact us using the $cam_link on $jv_link."
        ."\n\nThanks for your support!\n\n- Jiggmin";
    message_insert($pdo, $user_id, 1, $pm, '0');
}


// regenerates vault items (intended to be run from CLI when there are new changes to vault items)
function regenerate_vault_items($pdo, $verbose = null)
{
    require_once QUERIES_DIR . '/vault_items.php';
    if ($verbose === null) {
        $verbose = PHP_SAPI === 'cli';
    }
    if ($verbose) {
        output('Regenerating vault items...');
    }

    // select items
    $items = vault_items_select($pdo);

    // populate
    $items_out = new stdClass();
    $items_out->info = new stdClass();
    $items_out->listings = new stdClass();
    foreach ($items as $item) {
        $items_out->listings->{$item->slug} = format_vault_item($item);
    }
    $items_out->info->updated = time();

    // save to file
    $file_link = CACHE_DIR . '/vault.json';
    $bytes_written = file_put_contents($file_link, json_encode($items_out, JSON_PRETTY_PRINT));
    if ($bytes_written === false) {
        throw new Exception('Could not save regenerated vault info.');
    }
    if ($verbose) {
        output("Vault items regenerated and saved to $file_link.\n");
    }
}
