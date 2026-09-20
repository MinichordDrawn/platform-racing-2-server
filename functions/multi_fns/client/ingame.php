<?php


// let special accounts cancel a prize
function client_cancel_prize($socket)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $is_prizer = \pr2\multi\PR2SocketServer::$prizer_id === $player->user_id;
        if ($player->special_user === true || $player->group === 3 || $is_prizer) {
            $player->game_room->cancelPrize($player);
        }
    }
}


// lose hat
function client_loose_hat($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // Where the hat fell and how it is turned. Exactly three, because the
        // room writes four fields of its own after these, and a fourth from
        // here would move every one of them.
        $fields = packet_fields($data, array('x' => 'num', 'y' => 'num', 'rot' => 'num'));
        $player->game_room->looseHat($player, $fields['x'], $fields['y'], $fields['rot']);
    }
}


// pick up a lost hat
function client_get_hat($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // The room keeps its loose hats in an array keyed by the number it
        // gave each one, and this is a key into it.
        $fields = packet_fields($data, array('hat_id' => 'uint'));
        $player->game_room->getHat($player, $fields['hat_id']);
    }
}


// send hat back to the start (hat attack)
function client_hat_to_start($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // A key into the room's loose hats, and compared against the number it
        // will hand out next.
        $fields = packet_fields($data, array('hat_id' => 'uint'));
        $player->game_room->sendHatToStart($fields['hat_id']);
    }
}


// set pos
function client_p($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // Added to the position the room is tracking, so both are numbers.
        $fields = packet_fields($data, array('moved_x' => 'num', 'moved_y' => 'num'));
        $player->game_room->setPos($player, $fields['moved_x'], $fields['moved_y']);
    }
}


// set exact pos
function client_exact_pos($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // Kept as the player's position, which the squash and the sting then
        // measure their boxes against, so both are numbers.
        $fields = packet_fields($data, array('pos_x' => 'num', 'pos_y' => 'num'));
        $player->game_room->setExactPos($player, $fields['pos_x'], $fields['pos_y']);
    }
}


// squash another player
function client_squash($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->squash($player, packet_temp_id($data));
    }
}


// hit by jellyfish hat sting
function client_sting($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->sting($player, packet_temp_id($data));
    }
}


// set variable
function client_set_var($socket, $data)
{
    $player = $socket->getPlayer();
    if (!isset($player->game_room)) {
        return;
    }

    // Which variable, then its value read as whatever that variable is.
    $kinds = player_var_kinds();
    $named = packet_fields($data, array('name' => 'word', 'value' => 'text'));

    if (!isset($kinds[$named['name']])) {
        throw new Exception('No such player variable.');
    }

    $fields = packet_fields($data, array('name' => 'word', 'value' => $kinds[$named['name']]));

    $player->game_room->setVar($player, $fields['name'], $fields['value']);
}


// add an effect
function client_add_effect($socket, $data)
{
    $player = $socket->getPlayer();
    if (!isset($player->game_room)) {
        return;
    }

    // How many fields an effect carries depends on which effect it is, so the
    // name is read first and chooses the shape the rest is read against.
    $kinds = effect_field_kinds();
    $name = packet_field_value('effect', 'word', strstr($data . '`', '`', true));

    if (!isset($kinds[$name])) {
        throw new Exception('No such effect.');
    }

    $fields = packet_fields($data, $kinds[$name]);

    $player->game_room->addEffect($player, $fields);
}


// use a lightning item
function client_zap($socket)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->sendToAll("zap`$player->temp_id", $player->user_id);
    }
}


// There was a handler here for a command that joined the sender's text onto
// the command name with no separator between them, so the sender chose the
// name the receiving clients dispatched on, and nothing examined it. No client
// sends that command: it appears in no client source and in none of the
// shipped client builds, while every other command here appears in all of
// them. A shape cannot be declared for a command nothing sends, so the handler
// is gone rather than guessed at.


// touch a block
function client_activate($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // Which block, and what it was activated with. Block coordinates are
        // signed. The last field is empty for most kinds of block, which send
        // nothing with it, so it is declared as one that may be empty.
        $fields = packet_fields($data, array('seg_x' => 'num', 'seg_y' => 'num', 'with' => 'text?'));
        $player->game_room->broadcastActivate($player, $fields['seg_x'], $fields['seg_y'], $fields['with']);
    }
}


// bump a heart block
function client_heart($socket)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        // The server does not model heart blocks, so it cannot tell whether
        // this one was really collected. The ceiling bounds the claim rather
        // than checking it: without one, lives are immunity in deathmatch,
        // where they decide elimination and elimination order is the placement.
        if ($player->lives >= max_lives()) {
            return;
        }

        $player->lives++;
        $player->game_room->broadcastHeart($player);
    }
}


// finish drawing
function client_finish_drawing($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->finishDrawing($player, $data);
    }
}


// finish race
function client_finish_race($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->remoteFinishRace($player, $data);
    }
}


// quit race (forfeit)
function client_quit_race($socket)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->quitRace($player);
    }
}


// grab egg
function client_grab_egg($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->grabEgg($player, $data);
    }
}


// record single finish in objective mode
function client_objective_reached($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->objectiveReached($player, $data);
    }
}


function client_check_hat_countdown($socket)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->checkHatCountdown($player);
    }
}


function client_resume_race_state($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room) && $player->game_room instanceof \pr2\multi\Game) {
        $player->game_room->resumeRaceState($player, $data);
    }
}
