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
        $player->game_room->looseHat($player, $data);
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
    if (isset($player->game_room)) {
        $player->game_room->setVar($player, $data);
    }
}


// add an effect
function client_add_effect($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->sendToRoom('addEffect`'.$data, $player->user_id);
    }
}


// use a lightning item
function client_zap($socket)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->sendToAll("zap`$player->temp_id", $player->user_id);
    }
}


// hit a block
function client_hit($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->broadcastHit($player, $data);
    }
}


// touch a block
function client_activate($socket, $data)
{
    $player = $socket->getPlayer();
    if (isset($player->game_room)) {
        $player->game_room->broadcastActivate($player, $data);
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
