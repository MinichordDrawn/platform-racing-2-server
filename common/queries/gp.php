<?php

function gp_increment($pdo, $user_id, $guild_id, $gp)
{
    $stmt = $pdo->prepare('
        INSERT INTO gp
           SET user_id = :user_id,
               guild_id = :guild_id,
               gp_today = :gp,
               gp_total = :gp
        ON DUPLICATE KEY UPDATE
               gp_today = gp_today + :gp,
               gp_total = gp_total + :gp
    ');
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':guild_id', $guild_id, PDO::PARAM_INT);
    $stmt->bindValue(':gp', $gp, PDO::PARAM_INT);
    $result = $stmt->execute();

    if ($result === false) {
        throw new Exception('Could not record your earned GP.');
    }

    return $result;
}


function gp_reset($pdo)
{
    $result = $pdo->exec('UPDATE gp SET gp_today = 0');

    if ($result === false) {
        throw new Exception('Could not reset column gp_today.');
    }

    // The rows reached, not the rows changed. See guilds_reset_gp_today, which
    // carries the same statement shape and the same reasoning: no WHERE
    // clause, so this applies to every row, and on a day when nobody earned
    // any points every gp_today is already 0 and `exec` returns zero from an
    // update that did exactly what it should. The observer reads this against
    // a declared minimum and stops the deployment when it is not met.
    $reached = $pdo->query('SELECT COUNT(*) FROM gp')->fetchColumn();

    if ($reached === false) {
        throw new Exception('Could not count the gp rows the reset reached.');
    }

    return (int) $reached;
}
