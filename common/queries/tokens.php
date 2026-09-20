<?php


function token_delete($pdo, $token, $ret_count = false)
{
    $stmt = $pdo->prepare('
        DELETE FROM tokens
         WHERE token = :token
    ');
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $result = $stmt->execute();

    if ($result === false) {
        throw new Exception('Could not delete your login token from the database.');
    }

    return $ret_count === true ? $stmt->rowCount() > 0 : $result;
}


function token_insert($pdo, $user_id, $token)
{
    $stmt = $pdo->prepare('
        INSERT INTO tokens
           SET user_id = :user_id,
               token = :token,
               time = NOW()
        ON DUPLICATE KEY UPDATE
               token = :token,
               time = NOW()
    ');
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindValue(':token', $token, PDO::PARAM_STR);
    $result = $stmt->execute();

    if ($result === false) {
        throw new Exception('Could not create a login token for you.');
    }

    return $result;
}


// How long a login token stays good for.
//
// A month: the age tokens_delete_old removes rows at, and the longest life the
// login cookie is ever given. $TOKEN_MAX_AGE_SECONDS overrides it, and a value
// that is not a positive number is ignored rather than read as no limit.
function token_max_age()
{
    global $TOKEN_MAX_AGE_SECONDS;

    if (isset($TOKEN_MAX_AGE_SECONDS) && (int) $TOKEN_MAX_AGE_SECONDS > 0) {
        return (int) $TOKEN_MAX_AGE_SECONDS;
    }

    return 2592000;
}


// True unless the row says, readably, that it was made recently enough. A row
// whose age cannot be read has not been shown to be good, so it is not.
function token_is_expired($token)
{
    if (!isset($token->time) || trim((string) $token->time) === '') {
        return true;
    }

    $made = strtotime((string) $token->time);

    if ($made === false) {
        return true;
    }

    return (time() - $made) > token_max_age();
}


function token_select($pdo, $token_id)
{
    $stmt = $pdo->prepare('
        SELECT user_id, token, time
          FROM tokens
         WHERE token = :token_id
         LIMIT 1
    ');
    $stmt->bindValue(':token_id', $token_id, PDO::PARAM_STR);
    $result = $stmt->execute();

    if ($result === false) {
        throw new Exception('Could not perform query token_select.');
    }

    $token = $stmt->fetch(PDO::FETCH_OBJ);

    if (empty($token)) {
        throw new Exception('Could not find a valid login token. Please log in again.');
    }

    // Checked here rather than left to the clean-up, so a token stops working
    // once it is old enough even though its row is still present, and so every
    // request that authenticates with one is covered by the one test.
    if (token_is_expired($token)) {
        throw new Exception('Your login has expired. Please log in again.');
    }

    return $token;
}


function tokens_delete_by_user($pdo, $user_id)
{
    $stmt = $pdo->prepare('
        DELETE FROM tokens
        WHERE user_id = :user_id
    ');
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $result = $stmt->execute();

    if ($result === false) {
        throw new Exception('Could not delete this user\'s login tokens.');
    }

    return $result;
}


function tokens_delete_old($pdo)
{
    $result = $pdo->exec('
        DELETE FROM tokens
        WHERE Date(time) < DATE_SUB(NOW(), INTERVAL 1 MONTH)
    ');

    if ($result === false) {
        throw new Exception('Could not delete expired login tokens.');
    }

    return $result;
}
